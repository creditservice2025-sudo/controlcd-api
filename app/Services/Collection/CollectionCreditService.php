<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionClient;
use App\Models\Collection\CollectionCredit;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CollectionCreditService
{
    use ApiResponse;

    private const CONNECTION = 'collection_pgsql';

    public function create(array $payload)
    {
        $companyId = $this->resolveCompanyId($payload['company_id'] ?? null);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        $clientId = (int) ($payload['client_id'] ?? 0);
        if ($clientId <= 0) {
            return $this->errorResponse('Cliente inválido para Collection', 422);
        }

        $clientExists = CollectionClient::query()
            ->where('company_id', $companyId)
            ->where('id', $clientId)
            ->exists();

        if (!$clientExists) {
            return $this->errorNotFoundResponse('Cliente Collection no encontrado');
        }

        return DB::connection(self::CONNECTION)->transaction(function () use ($payload, $companyId, $clientId) {
            $this->ensureCreditPartition($companyId);

            // id generado por la secuencia de PostgreSQL.
            $creditValue = (float) ($payload['credit_value'] ?? 0);
            $interestRate = (float) ($payload['interest_rate'] ?? 0);

            // Unico modo soportado: credito abierto con interes mensual.
            // Se genera 1 cuota inicial; las siguientes se crean al pagar.
            $installments = 1;
            $frequency = 'Mensual';

            $firstInstallmentDate = $payload['first_installment_date'] ?? null;
            if (!$firstInstallmentDate) {
                $firstInstallmentDate = Carbon::now()->toDateString();
            }

            $metadata = [
                'transfer_bank_name' => $payload['transfer_bank_name'] ?? null,
                'transfer_reference_number' => $payload['transfer_reference_number'] ?? null,
                'transfer_voucher_photo' => $payload['transfer_voucher_photo'] ?? null,
                'transfer_support_photo' => $payload['transfer_support_photo'] ?? null,
                'excluded_days' => $payload['excluded_days'] ?? [],
                'installment_distribution_mode' => 'monthly_interest_open',
                'is_open_ended' => true,
                'created_by' => Auth::id(),
                'created_ip' => request()->ip(),
            ];

            $currency = $payload['currency'] ?? 'COP';
            $countryCode = $payload['country_code'] ?? 'CO';

            $credit = CollectionCredit::query()->create([
                'company_id' => $companyId,
                'client_id' => $clientId,
                'amount' => $creditValue,
                'interest_rate' => $interestRate,
                'total_installments' => $installments,
                'payment_frequency' => $frequency,
                'first_installment_date' => $firstInstallmentDate,
                'status' => 'active',
                'business_date' => Carbon::now()->toDateString(),
                'metadata' => $metadata,
                'currency' => $currency,
                'country_code' => $countryCode,
            ]);

            // Sync with Centralized Wallet (Outflow: Issuing a loan)
            app(\App\Services\Collection\CollectionWalletService::class)->recordMovement([
                'company_id' => $companyId,
                'currency' => $currency,
                'country_code' => $countryCode,
                'amount' => $creditValue,
                'type' => 'debit',
                'action_type' => 'loan_issue',
                'reference_type' => 'credit',
                'reference_id' => $credit->id,
                'description' => "Colocación de crédito #{$credit->id}",
            ]);

            $this->generateInstallments($credit, $payload['excluded_days'] ?? []);

            return $this->successCreatedResponse([
                'success' => true,
                'message' => 'Crédito Collection creado',
                'data' => [
                    'id' => $credit->id,
                    'client_id' => $credit->client_id,
                ],
            ]);
        });
    }

    private function resolveCompanyId($requestedCompanyId): ?int
    {
        if (!empty($requestedCompanyId)) {
            return (int) $requestedCompanyId;
        }

        $user = Auth::user();
        if (!$user) {
            return null;
        }

        if ($user->company && !empty($user->company->id)) {
            return (int) $user->company->id;
        }

        if ($user->seller && !empty($user->seller->company_id)) {
            return (int) $user->seller->company_id;
        }

        return null;
    }

    private function ensureCreditPartition(int $companyId): void
    {
        if ($companyId <= 0) {
            return;
        }

        $partitionTable = sprintf('collection_credits_company_%d', $companyId);
        DB::connection(self::CONNECTION)->statement(
            "CREATE TABLE IF NOT EXISTS {$partitionTable} PARTITION OF collection_credits FOR VALUES IN ({$companyId})"
        );

        $instPartitionTable = sprintf('collection_installments_company_%d', $companyId);
        DB::connection(self::CONNECTION)->statement(
            "CREATE TABLE IF NOT EXISTS {$instPartitionTable} PARTITION OF collection_installments FOR VALUES IN ({$companyId})"
        );
    }

    public function generateInstallments(CollectionCredit $credit, array $excludedDays = []): void
    {
        $this->ensureCreditPartition($credit->company_id);

        $principal = (float) $credit->amount;
        $interestRate = (float) $credit->interest_rate;

        $meta = is_array($credit->metadata) ? $credit->metadata : [];
        $mode = $meta['installment_distribution_mode'] ?? 'monthly_interest_open';

        // Modo unico actual: credito abierto.
        // Genera 1 sola cuota de interes; las siguientes se crean al pagar.
        if ($mode === 'monthly_interest_open') {
            $interest = round(($principal * $interestRate) / 100, 2);
            $firstDate = Carbon::parse($credit->first_installment_date);
            DB::connection(self::CONNECTION)->table('collection_installments')->insert([
                'company_id' => $credit->company_id,
                'credit_id' => $credit->id,
                'installment_number' => 1,
                'due_date' => $firstDate->toDateString(),
                'amount' => $interest,
                'principal_amount' => 0,
                'interest_amount' => $interest,
                'paid_amount' => 0,
                'principal_paid' => 0,
                'interest_paid' => 0,
                'status' => 'pendiente',
                'recorded_at' => Carbon::now(),
            ]);
            return;
        }

        // Fallback para creditos legacy con modos anteriores (capital_interest / interest_only).
        // No aplica a creditos nuevos, pero se deja por compatibilidad con datos existentes.
        $count = (int) $credit->total_installments;
        if ($count <= 0) return;

        $totalInterest = ($principal * $interestRate) / 100;
        $principalPerInstallment = $principal / $count;
        $interestPerInstallment = $totalInterest / $count;

        $currentDate = Carbon::parse($credit->first_installment_date);
        $frequency = $credit->payment_frequency ?? 'Diaria';

        // Normalize excluded days to lowercase for comparison
        $excludedDays = array_map('strtolower', $excludedDays);

        for ($i = 1; $i <= $count; $i++) {
            // If it's an excluded day, skip to the next valid day
            while (in_array(strtolower($currentDate->locale('en')->dayName), $excludedDays)) {
                $currentDate->addDay();
            }

            // id generado por la secuencia de PostgreSQL.
            $currentPrincipal = $principalPerInstallment;
            $currentInterest = $interestPerInstallment;

            if ($mode === 'interest_only') {
                $currentInterest = ($principal * $interestRate) / 100;
                if ($i < $count) {
                    $currentPrincipal = 0;
                } else {
                    $currentPrincipal = $principal;
                }
            }

            DB::connection(self::CONNECTION)->table('collection_installments')->insert([
                'company_id' => $credit->company_id,
                'credit_id' => $credit->id,
                'installment_number' => $i,
                'due_date' => $currentDate->toDateString(),
                'amount' => round($currentPrincipal + $currentInterest, 2),
                'principal_amount' => round($currentPrincipal, 2),
                'interest_amount' => round($currentInterest, 2),
                'paid_amount' => 0,
                'principal_paid' => 0,
                'interest_paid' => 0,
                'status' => 'pendiente',
                'recorded_at' => Carbon::now(),
            ]);

            // Advance for next installment
            switch ($frequency) {
                case 'Diaria': $currentDate->addDay(); break;
                case 'Semanal': $currentDate->addWeek(); break;
                case 'Quincenal': $currentDate->addDays(15); break;
                case 'Mensual': $currentDate->addMonth(); break;
                default: $currentDate->addDay(); break;
            }
        }
    }

    /**
     * Liquida un credito abierto: paga el interes pendiente + todo el capital restante en
     * una sola operacion, marca la cuota actual como pagada y cierra el credito.
     */
    public function settle(int $companyId, int $creditId, array $payload)
    {
        $credit = CollectionCredit::query()
            ->where('company_id', $companyId)
            ->where('id', $creditId)
            ->first();

        if (!$credit) {
            return $this->errorNotFoundResponse('Credito no encontrado');
        }

        if ($credit->status !== 'active') {
            return $this->errorResponse('El credito no esta activo (status=' . $credit->status . ')', 422);
        }

        // Capital pendiente
        $paidPrincipal = (float) DB::connection(self::CONNECTION)
            ->table('collection_installments')
            ->where('company_id', $companyId)
            ->where('credit_id', $creditId)
            ->sum('principal_paid');
        $remainingPrincipal = round((float) $credit->amount - $paidPrincipal, 2);

        // Cuota pendiente actual (en credito abierto solo hay 1 abierta)
        $pendingInstallment = \App\Models\Collection\CollectionInstallment::query()
            ->where('company_id', $companyId)
            ->where('credit_id', $creditId)
            ->whereIn('status', ['pendiente', 'parcial'])
            ->orderBy('installment_number')
            ->first();

        $pendingInterest = 0.0;
        if ($pendingInstallment) {
            $pendingInterest = round(
                (float) $pendingInstallment->interest_amount - (float) ($pendingInstallment->interest_paid ?? 0),
                2
            );
        }

        $settlementTotal = round($remainingPrincipal + $pendingInterest, 2);

        if ($settlementTotal <= 0) {
            return $this->errorResponse('No hay saldo para liquidar', 422);
        }

        return DB::connection(self::CONNECTION)->transaction(function () use (
            $credit, $companyId, $creditId, $payload, $pendingInstallment,
            $pendingInterest, $remainingPrincipal, $settlementTotal
        ) {
            $tz = $payload['timezone'] ?? 'UTC';
            $now = Carbon::now($tz);
            $paymentMethod = $payload['payment_method'] ?? 'Efectivo';
            $voucherPath = $payload['voucher_path'] ?? null;
            $notes = $payload['notes'] ?? 'Liquidacion de credito';

            // Si hay cuota pendiente: actualizar para reflejar pago de interes + capital total.
            if ($pendingInstallment) {
                $newInterestPaid = (float) $pendingInstallment->interest_amount;
                $newPrincipalPaid = (float) ($pendingInstallment->principal_paid ?? 0) + $remainingPrincipal;
                $newAmount = (float) $pendingInstallment->interest_amount + $remainingPrincipal;
                $newPrincipalAmount = (float) ($pendingInstallment->principal_amount ?? 0) + $remainingPrincipal;

                $pendingInstallment->update([
                    'status' => 'pagado',
                    'amount' => round($newAmount, 2),
                    'principal_amount' => round($newPrincipalAmount, 2),
                    'paid_amount' => round($newInterestPaid + $newPrincipalPaid, 2),
                    'interest_paid' => round($newInterestPaid, 2),
                    'principal_paid' => round($newPrincipalPaid, 2),
                    'payment_method' => $paymentMethod,
                    'notes' => $notes,
                    'voucher_path' => $voucherPath,
                    'last_payment_at' => $now,
                ]);

                \App\Models\Collection\CollectionPayment::create([
                    'company_id' => $companyId,
                    'credit_id' => $creditId,
                    'installment_number' => $pendingInstallment->installment_number,
                    'amount_paid' => $settlementTotal,
                    'payment_date' => $payload['payment_date'] ?? $now->toDateString(),
                    'payment_method' => $paymentMethod,
                    'notes' => $notes,
                    'voucher_path' => $voucherPath,
                    'recorded_at' => $now,
                ]);
            }

            // Cerrar credito
            $credit->update(['status' => 'pagado']);

            // Wallet: ingreso por liquidacion
            app(\App\Services\Collection\CollectionWalletService::class)->recordMovement([
                'company_id' => $companyId,
                'currency' => $credit->currency ?? 'COP',
                'country_code' => $credit->country_code ?? 'CO',
                'amount' => $settlementTotal,
                'type' => 'credit',
                'action_type' => 'payment',
                'reference_type' => 'credit',
                'reference_id' => $creditId,
                'description' => "Liquidacion de credito #{$creditId}",
            ]);

            return $this->successResponse([
                'success' => true,
                'message' => 'Credito liquidado con exito',
                'settled_total' => $settlementTotal,
                'paid_interest' => $pendingInterest,
                'paid_principal' => $remainingPrincipal,
            ]);
        });
    }

    /**
     * Credito abierto: cuando todas las cuotas existentes estan pagadas, crea la siguiente
     * cuota mensual de interes sobre el capital pendiente. Si el capital ya se pago totalmente,
     * cierra el credito.
     */
    public function generateNextOpenEndedInstallment(CollectionCredit $credit): void
    {
        $meta = is_array($credit->metadata) ? $credit->metadata : [];
        if (empty($meta['is_open_ended'])) {
            return;
        }

        // Capital pendiente = credit.amount - suma(principal_paid).
        $paidPrincipal = (float) DB::connection(self::CONNECTION)
            ->table('collection_installments')
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->sum('principal_paid');

        $remainingPrincipal = round((float) $credit->amount - $paidPrincipal, 2);

        if ($remainingPrincipal <= 0) {
            $credit->update(['status' => 'pagado']);
            return;
        }

        // Solo generar siguiente si no hay ninguna cuota pendiente o parcial.
        $hasPending = DB::connection(self::CONNECTION)
            ->table('collection_installments')
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->whereIn('status', ['pendiente', 'parcial'])
            ->exists();

        if ($hasPending) {
            return;
        }

        $lastInstallment = DB::connection(self::CONNECTION)
            ->table('collection_installments')
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->orderByDesc('installment_number')
            ->first();

        if (!$lastInstallment) {
            return;
        }

        $nextNumber = (int) $lastInstallment->installment_number + 1;
        $nextDue = Carbon::parse($lastInstallment->due_date)->addMonth()->toDateString();
        $interest = round(($remainingPrincipal * (float) $credit->interest_rate) / 100, 2);

        DB::connection(self::CONNECTION)->table('collection_installments')->insert([
            'company_id' => $credit->company_id,
            'credit_id' => $credit->id,
            'installment_number' => $nextNumber,
            'due_date' => $nextDue,
            'amount' => $interest,
            'principal_amount' => 0,
            'interest_amount' => $interest,
            'paid_amount' => 0,
            'principal_paid' => 0,
            'interest_paid' => 0,
            'status' => 'pendiente',
            'recorded_at' => Carbon::now(),
        ]);

        $credit->increment('total_installments');
    }

    public function deleteInstallment(int $installmentId, array $securityToken = [], ?int $requestedCompanyId = null)
    {
        $companyId = $this->resolveCompanyId($requestedCompanyId);
        if (!$companyId) {
            return $this->errorResponse('No se pudo determinar la compañía para Collection', 422);
        }

        // Mandatory Security Validation
        $securityService = app(\App\Services\Collection\CollectionSecurityService::class);
        $requestId = $securityToken['request_id'] ?? '';
        $code = $securityToken['code'] ?? '';

        if (!$securityService->validateToken($requestId, $code, $companyId)) {
            return $this->errorResponse('Código de autorización gerencial inválido o expirado', 403);
        }

        $installment = \App\Models\Collection\CollectionInstallment::query()
            ->where('company_id', $companyId)
            ->where('id', $installmentId)
            ->first();

        if (!$installment) {
            return $this->errorNotFoundResponse('Cuota no encontrada');
        }

        return DB::connection(self::CONNECTION)->transaction(function () use ($installment, $companyId) {
            // Backup current state (with payment info) for audit
            $history = $installment->toArray();
            
            // Reversa física parcial de saldo: Marcamos los registros de la tabla de pagos como eliminados (Soft Delete manual)
            \App\Models\Collection\CollectionPayment::where('company_id', $installment->company_id)
                ->where('credit_id', $installment->credit_id)
                ->where('installment_number', $installment->installment_number)
                ->update(['deleted_at' => Carbon::now()]);

            $installment->update([
                'paid_amount' => 0,
                'status' => 'pendiente',
                'payment_method' => null,
                'notes' => null,
                'voucher_path' => null,
                'last_payment_at' => null,
                // Audit the reversal action
                'deleted_at' => Carbon::now(),
                'deleted_by' => Auth::id(),
                'deleted_ip' => request()->ip(),
                'history' => $history,
            ]);

            // Sync with Centralized Wallet (Reversal: Substract what was previously added)
            $totalReversed = (float) ($history['paid_amount'] ?? 0);
            if ($totalReversed > 0) {
                $credit = CollectionCredit::find($installment->credit_id);
                app(\App\Services\Collection\CollectionWalletService::class)->recordMovement([
                    'company_id' => $companyId,
                    'currency' => $credit->currency ?? 'COP',
                    'country_code' => $credit->country_code ?? 'CO',
                    'amount' => $totalReversed,
                    'type' => 'debit', // Reversing an income
                    'action_type' => 'payment_reversal',
                    'reference_type' => 'credit',
                    'reference_id' => $installment->credit_id,
                    'description' => "Reversión de pago cuota #{$installment->installment_number} crédito #{$installment->credit_id}",
                ]);
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Cobro/Abono eliminado correctamente. La cuota ahora está pendiente.'
            ]);
        });
    /**
     * Recalcula el interes de las cuotas futuras pendientes si el capital varia.
     */
    public function recalculateFutureInstallments(CollectionCredit $credit): void
    {
        $meta = is_array($credit->metadata) ? $credit->metadata : [];
        if (empty($meta['is_open_ended'])) {
            return;
        }

        // 1. Calcular capital pendiente actual
        $paidPrincipal = (float) DB::connection(self::CONNECTION)
            ->table('collection_installments')
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->whereNull('deleted_at')
            ->sum('principal_paid');

        $remainingPrincipal = round((float) $credit->amount - $paidPrincipal, 2);

        if ($remainingPrincipal <= 0) {
            // Si el capital ya se saldo, marcar todas las pendientes como cerradas (o que el proximo pago liquide el interes)
             DB::connection(self::CONNECTION)
                ->table('collection_installments')
                ->where('company_id', $credit->company_id)
                ->where('credit_id', $credit->id)
                ->where('status', 'pendiente')
                ->update([
                    'interest_amount' => 0,
                    'amount' => 0,
                    'status' => 'pagado', // Opcional: si ya no hay deuda, se cierran
                ]);
            $credit->update(['status' => 'pagado']);
            return;
        }

        // 2. Buscar todas las cuotas PENDIENTES o PARCIALES (futuras)
        $futureInstallments = \App\Models\Collection\CollectionInstallment::query()
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->whereIn('status', ['pendiente', 'parcial'])
            ->get();

        foreach ($futureInstallments as $inst) {
            $interestRate = (float) $credit->interest_rate;
            $newInterest = round(($remainingPrincipal * $interestRate) / 100, 2);
            
            // Si ya tiene algo pagado de interes, hay que restar eso
            $pendingInterest = max(0, $newInterest - (float)($inst->interest_paid ?? 0));
            $pendingPrincipal = max(0, (float)($inst->principal_amount ?? 0) - (float)($inst->principal_paid ?? 0));
            
            $inst->update([
                'interest_amount' => $newInterest,
                'amount' => round($newInterest + (float)($inst->principal_amount ?? 0), 2),
                'paid_amount' => (float)($inst->interest_paid ?? 0) + (float)($inst->principal_paid ?? 0),
            ]);
        }
    }
}
