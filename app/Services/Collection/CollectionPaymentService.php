<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionInstallment;
use App\Models\Collection\CollectionPayment;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CollectionPaymentService
{
    use ApiResponse;

    private const CONNECTION = 'collection_pgsql';

    public function recordPayment(array $payload)
    {
        $companyId = (int) ($payload['company_id'] ?? 0);
        $creditId = (int) ($payload['credit_id'] ?? 0);
        
        // Support both old installment_numbers array and new detailed payments array
        $payments = $payload['payments'] ?? [];
        
        if (empty($payments) && !empty($payload['installment_numbers'])) {
            foreach ($payload['installment_numbers'] as $num) {
                $payments[] = [
                    'installment_number' => $num,
                    'amount' => null, // Will use full installment amount
                    'payment_method' => $payload['payment_method'] ?? 'Efectivo',
                    'notes' => $payload['reference'] ?? $payload['notes'] ?? null,
                ];
            }
        }
        
        if (!$companyId || !$creditId || empty($payments)) {
            return $this->errorResponse('Información de pago incompleta', 422);
        }

        return DB::connection(self::CONNECTION)->transaction(function () use ($payload, $companyId, $creditId, $payments) {
            $this->ensurePaymentPartition($companyId);
            
            $results = [];

            foreach ($payments as $pData) {
                $instNum = $pData['installment_number'];
                
                $installment = CollectionInstallment::query()
                    ->where('company_id', $companyId)
                    ->where('credit_id', $creditId)
                    ->where('installment_number', $instNum)
                    ->first();

                if (!$installment || $installment->status === 'pagado') {
                    continue; 
                }

                $pendingAmount = (float) $installment->amount - (float) $installment->paid_amount;
                $amountToPay = (float) ($pData['amount'] ?? $pendingAmount);

                // Prevent overpayment at backend level
                if ($amountToPay > $pendingAmount) {
                    $amountToPay = $pendingAmount;
                }

                if ($amountToPay <= 0) {
                    continue; 
                }

                $newPaidAmount = (float) $installment->paid_amount + $amountToPay;
                
                // Determine new status
                $newStatus = 'pagado';
                if (round($newPaidAmount, 2) < round((float) $installment->amount, 2)) {
                    $newStatus = 'parcial';
                }

                // Update Installment
                $installment->update([
                    'status' => $newStatus,
                    'paid_amount' => $newPaidAmount,
                    'payment_method' => $pData['payment_method'] ?? $payload['payment_method'] ?? 'Efectivo',
                    'notes' => $pData['notes'] ?? $payload['reference'] ?? null,
                    'voucher_path' => $payload['voucher_path'] ?? null,
                    'last_payment_at' => isset($payload['payment_date']) ? Carbon::parse($payload['payment_date']) : Carbon::now(),
                ]);

                // Create Payment Audit Record
                $newId = (int) CollectionPayment::query()
                    ->where('company_id', $companyId)
                    ->max('id') + 1;

                $payment = CollectionPayment::create([
                    'id' => $newId,
                    'company_id' => $companyId,
                    'credit_id' => $creditId,
                    'installment_number' => $instNum,
                    'amount_paid' => $amountToPay,
                    'payment_date' => $payload['payment_date'] ?? Carbon::now()->toDateString(),
                    'receipt_number' => $payload['reference'] ?? null,
                    'payment_method' => $pData['payment_method'] ?? $payload['payment_method'] ?? 'Efectivo',
                    'notes' => $pData['notes'] ?? $payload['reference'] ?? null,
                    'voucher_path' => $payload['voucher_path'] ?? null,
                    'recorded_at' => Carbon::now(),
                ]);

                $results[] = $payment->id;
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Pagos registrados con éxito',
                'count' => count($results),
            ]);
        });
    }

    private function ensurePaymentPartition(int $companyId): void
    {
        $partitionTable = sprintf('collection_payments_company_%d', $companyId);
        DB::connection(self::CONNECTION)->statement(
            "CREATE TABLE IF NOT EXISTS {$partitionTable} PARTITION OF collection_payments FOR VALUES IN ({$companyId})"
        );
    }
}
