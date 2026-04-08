<?php

namespace App\Services\Collection;

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
     * Record a financial movement in the ledger and update the wallet balance.
     */
    public function recordMovement(array $data)
    {
        return DB::connection('collection_pgsql')->transaction(function () use ($data) {
            $companyId = $data['company_id'];
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
            
            // Create Ledger Entry
            $ledgerId = (int) CollectionLedger::where('company_id', $companyId)->max('id') + 1;
            
            return CollectionLedger::create([
                'id' => $ledgerId,
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
            $newId = (int) CollectionWallet::where('company_id', $companyId)->max('id') + 1;
            $wallet = CollectionWallet::create([
                'id' => $newId,
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
        // Ensure default wallets exist for standard countries
        $defaults = [
            ['currency' => 'COP', 'country_code' => 'CO'],
            ['currency' => 'VES', 'country_code' => 'VE'],
            ['currency' => 'USD', 'country_code' => 'US'],
        ];

        foreach ($defaults as $def) {
            $this->getOrCreateWallet($companyId, $def['currency'], $def['country_code']);
        }

        return CollectionWallet::where('company_id', $companyId)
            ->orderBy('country_code', 'asc')
            ->get();
    }

    public function getLedgerMovements(int $companyId, array $filters = [])
    {
        $query = CollectionLedger::with('wallet')
            ->where('company_id', $companyId);

        if (!empty($filters['action_type'])) {
            $query->where('action_type', $filters['action_type']);
        }

        if (!empty($filters['country_code'])) {
            $query->whereHas('wallet', function($q) use ($filters) {
                $q->where('country_code', $filters['country_code']);
            });
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }
}
