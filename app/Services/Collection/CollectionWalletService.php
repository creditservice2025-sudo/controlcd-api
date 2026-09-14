<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCompanyConfig;
use App\Models\Collection\CollectionWallet;
use App\Models\Collection\CollectionLedger;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CollectionWalletService
{
    use ApiResponse;

    /**
     * Movimientos del ledger que NO cuentan para el balance ni para la lista
     * del dashboard: transferencias entre cajas (mueven plata de sitio, no la
     * crean ni la destruyen) y gastos (tienen su propio bloque en la pantalla).
     *
     * Es una lista de EXCLUSIONES y no de inclusiones a propósito. Antes eran
     * tres action_type permitidos —loan_issue, payment, capital_injection— y
     * cualquier tipo nuevo quedaba fuera del cálculo sin que nadie se enterara:
     * así se colaron `loan_cancellation` y `payment_reversal`, y un crédito
     * anulado seguía descontando de la caja porque la devolución no se sumaba.
     * Con exclusiones, un action_type nuevo entra al balance por defecto, que
     * es el comportamiento seguro para un cálculo de dinero.
     *
     * Una sola definición para las dos consultas: la lista de movimientos tiene
     * que cuadrar con el balance, y tenerlo escrito dos veces era justo lo que
     * permitía que se separaran.
     */
    public const EXCLUDED_FROM_DASHBOARD = [
        'expense',
        'transfer_out',
        'transfer_out_adjustment',
        'transfer_out_reversal',
    ];

    public function __construct(private readonly CollectionPartitionService $partitionService)
    {
    }

    /**
     * Record a financial movement in the ledger and update the wallet balance.
     */
    public function recordMovement(array $data)
    {
        $companyId = $data['company_id'];
        $this->partitionService->ensurePartitions($companyId);
        
        return DB::connection('collection_pgsql')->transaction(function () use ($data, $companyId) {
            
            $currency = strtoupper($data['currency'] ?? 'COP');
            $countryCode = strtoupper($data['country_code'] ?? 'CO');
            $amount = (float) $data['amount'];
            $type = $data['type']; // credit (sum), debit (subtract)
            $actionType = $data['action_type']; // payment, expense, injection, etc.
            
            $wallet = $this->getOrCreateWallet($companyId, $currency, $countryCode);
            
            $balanceBefore = (float) $wallet->balance;
            $balanceAfter = $type === 'credit' ? $balanceBefore + $amount : $balanceBefore - $amount;
            
            // Update Wallet
            $wallet->update([
                'balance' => $balanceAfter,
                'updated_at' => Carbon::now()
            ]);
            
            // Create Ledger Entry — id generado por la secuencia de PostgreSQL.
            return CollectionLedger::create([
                'company_id' => $companyId,
                'wallet_id' => $wallet->id,
                'type' => $type,
                'action_type' => $actionType,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'description' => $data['description'] ?? null,
                'user_id' => Auth::id(),
                'created_at' => Carbon::now(),
                // Jornada de caja del movimiento, anclada a la zona del PAIS
                // del wallet -- no a la del servidor. `created_at` dice el
                // instante; `business_date` dice a que dia contable pertenece,
                // que es por lo que agrupa el corte diario. Sin esto, un
                // movimiento de la noche cae en la jornada siguiente.
                'business_date' => Carbon::now(
                    \App\Helpers\TimezoneHelper::timezoneForCountryCode($countryCode)
                        ?: (config('app.timezone') ?: 'UTC')
                )->toDateString(),
            ]);
        });
    }

    /**
     * Helper to get or create a wallet for a specific currency/country context.
     */
    public function getOrCreateWallet(int $companyId, string $currency, string $countryCode)
    {
        $wallet = CollectionWallet::where('company_id', $companyId)
            ->where('currency', $currency)
            ->where('country_code', $countryCode)
            ->first();

        if (!$wallet) {
            $wallet = CollectionWallet::create([
                'company_id' => $companyId,
                'currency' => $currency,
                'country_code' => $countryCode,
                'balance' => 0
            ]);
        }

        return $wallet;
    }

    /**
     * Admin tool to inject initial capital or refills.
     */
    public function injectCapital(array $data)
    {
        return $this->recordMovement([
            'company_id' => $data['company_id'],
            'currency' => $data['currency'],
            'country_code' => $data['country_code'],
            'amount' => $data['amount'],
            'type' => 'credit',
            'action_type' => 'capital_injection',
            'description' => $data['description'] ?? 'Inyección de capital administrativa',
            'reference_type' => 'manual_injection',
        ]);
    }

    public function getBalances(int $companyId)
    {
        $config = CollectionCompanyConfig::where('company_id', $companyId)->first();
        $pairs = $config ? $config->getCurrencyPairs() : [['currency' => 'COP', 'country_code' => 'CO']];

        foreach ($pairs as $pair) {
            $this->getOrCreateWallet($companyId, $pair['currency'], $pair['country_code']);
        }

        $currencies = array_column($pairs, 'currency');
        $countryCodes = array_column($pairs, 'country_code');

        return CollectionWallet::where('company_id', $companyId)
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as $pair) {
                    $q->orWhere(function ($sub) use ($pair) {
                        $sub->where('currency', $pair['currency'])
                            ->where('country_code', $pair['country_code']);
                    });
                }
            })
            ->orderBy('country_code', 'asc')
            ->get();
    }

    public function getLedgerMovements(int $companyId, array $filters = [])
    {
        $query = CollectionLedger::with('wallet')
            ->where('company_id', $companyId)
            // Mismo criterio que el balance del dashboard, para que la lista
            // cuadre con el número de arriba: entra todo salvo transferencias
            // y gastos. Incluye las anulaciones y reversas, que antes faltaban
            // y hacían que un crédito anulado se viera descontado para siempre.
            ->whereNotIn('action_type', self::EXCLUDED_FROM_DASHBOARD);

        if (!empty($filters['action_type'])) {
            $query->where('action_type', $filters['action_type']);
        }

        if (!empty($filters['wallet_id'])) {
            $query->where('wallet_id', $filters['wallet_id']);
        } elseif (!empty($filters['country_code'])) {
            $query->whereHas('wallet', function($q) use ($filters) {
                $q->where('country_code', $filters['country_code']);
            });
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $paginated = $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);

        // Enriquecer movimientos con datos del cliente/credito cuando aplique.
        $creditIds = $paginated->getCollection()
            ->where('reference_type', 'credit')
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values();

        if ($creditIds->isNotEmpty()) {
            $credits = DB::connection('collection_pgsql')
                ->table('collection_credits')
                ->join('collection_clients', function ($join) {
                    $join->on('collection_credits.client_id', '=', 'collection_clients.id')
                         ->on('collection_credits.company_id', '=', 'collection_clients.company_id');
                })
                ->whereIn('collection_credits.id', $creditIds)
                ->select(
                    'collection_credits.id as credit_id',
                    'collection_clients.name as client_name',
                    'collection_clients.dni as client_dni'
                )
                ->get()
                ->keyBy('credit_id');

            $paginated->getCollection()->transform(function ($entry) use ($credits) {
                if ($entry->reference_type === 'credit' && isset($credits[$entry->reference_id])) {
                    $c = $credits[$entry->reference_id];
                    $entry->client_name = $c->client_name;
                    $entry->client_dni = $c->client_dni;
                    $entry->credit_ref = "CR-{$entry->reference_id}";
                }
                return $entry;
            });
        }

        return $paginated;
    }
}
