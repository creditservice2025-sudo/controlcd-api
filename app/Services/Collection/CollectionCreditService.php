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

            $newId = (int) CollectionCredit::query()
                ->where('company_id', $companyId)
                ->max('id') + 1;

            $creditValue = (float) ($payload['credit_value'] ?? 0);
            $interestRate = (float) ($payload['interest_rate'] ?? 0);
            $installments = (int) ($payload['number_installments'] ?? 1);
            $installments = $installments > 0 ? $installments : 1;

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
                'installment_distribution_mode' => $payload['installment_distribution_mode'] ?? 'capital_interest',
                'created_by' => Auth::id(),
                'created_ip' => request()->ip(),
            ];

            $credit = CollectionCredit::query()->create([
                'id' => $newId,
                'company_id' => $companyId,
                'client_id' => $clientId,
                'amount' => $creditValue,
                'interest_rate' => $interestRate,
                'total_installments' => $installments,
                'payment_frequency' => $payload['payment_frequency'] ?? 'Diaria',
                'first_installment_date' => $firstInstallmentDate,
                'status' => 'active',
                'business_date' => Carbon::now()->toDateString(),
                'metadata' => $metadata,
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

        $count = (int) $credit->total_installments;
        if ($count <= 0) return;

        $principal = (float) $credit->amount;
        $interestRate = (float) $credit->interest_rate;
        $totalInterest = ($principal * $interestRate) / 100;
        $principalPerInstallment = $principal / $count;
        $interestPerInstallment = $totalInterest / $count;

        // Custom distribution mode (interest_only / capital_interest)
        $meta = is_array($credit->metadata) ? $credit->metadata : [];
        $mode = $meta['installment_distribution_mode'] ?? 'capital_interest';

        $currentDate = Carbon::parse($credit->first_installment_date);
        $frequency = $credit->payment_frequency ?? 'Diaria';

        // Normalize excluded days to lowercase for comparison
        $excludedDays = array_map('strtolower', $excludedDays);

        for ($i = 1; $i <= $count; $i++) {
            // If it's an excluded day, skip to the next valid day
            while (in_array(strtolower($currentDate->locale('en')->dayName), $excludedDays)) {
                $currentDate->addDay();
            }

            $newId = (int) DB::connection(self::CONNECTION)
                ->table('collection_installments')
                ->where('company_id', $credit->company_id)
                ->max('id') + 1;

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
                'id' => $newId,
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

        return DB::connection(self::CONNECTION)->transaction(function () use ($installment) {
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

            return $this->successResponse([
                'success' => true,
                'message' => 'Cobro/Abono eliminado correctamente. La cuota ahora está pendiente.'
            ]);
        });
    }
}
