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

    public function __construct(
        private readonly CollectionPartitionService $partitionService,
        private readonly CollectionCashClosureService $closureSvc,
    ) {
    }

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

        // Bloquear si el día del pago tiene cierre de caja activo.
        $tz = $payload['timezone'] ?? 'America/Bogota';
        $paymentDate = $payload['payment_date'] ?? Carbon::now($tz)->toDateString();
        if ($this->closureSvc->isDayClosed($companyId, $paymentDate)) {
            return $this->errorResponse(
                'No se pueden registrar pagos: la caja del día ' . $paymentDate . ' está cerrada. Reabre el cierre primero.',
                409
            );
        }

        $this->partitionService->ensurePartitions($companyId);
        
        return DB::connection(self::CONNECTION)->transaction(function () use ($payload, $companyId, $creditId, $payments) {
            
            $results = [];
            $principalWasReduced = false;
            $credit = CollectionCredit::find($creditId);
            $creditMeta = $credit && is_array($credit->metadata) ? $credit->metadata : [];
            $isOpenEnded = !empty($creditMeta['is_open_ended']);

            foreach ($payments as $pData) {
                $instNum = $pData['installment_number'];
                $amountToDistribute = (float) ($pData['amount'] ?? 0);

                // If amount is null/zero, it means pay the full installment (old behavior)
                // but for overpayment logic, we need an actual number.
                if ($pData['amount'] === null) {
                    $inst = CollectionInstallment::query()
                        ->where('company_id', $companyId)
                        ->where('credit_id', $creditId)
                        ->where('installment_number', $instNum)
                        ->first();
                    $amountToDistribute = $inst ? ($inst->amount - $inst->paid_amount) : 0;
                }

                while ($amountToDistribute > 0) {
                    $installment = CollectionInstallment::query()
                        ->where('company_id', $companyId)
                        ->where('credit_id', $creditId)
                        ->where('installment_number', $instNum)
                        ->first();

                    if (!$installment) {
                        break; // No more installments to pay
                    }

                    if ($installment->status === 'pagado') {
                        $instNum++; // Try next one
                        continue;
                    }

                    $pendingInterest = (float) $installment->interest_amount - (float) ($installment->interest_paid ?? 0);
                    $pendingPrincipal = (float) $installment->principal_amount - (float) ($installment->principal_paid ?? 0);
                    $pendingTotal = $pendingInterest + $pendingPrincipal;

                    // If it's the last available installment of an open-ended credit,
                    // we allow "overpaying" it to reduce principal directly.
                    $isLastGenerated = !CollectionInstallment::query()
                        ->where('company_id', $companyId)
                        ->where('credit_id', $creditId)
                        ->where('installment_number', '>', $instNum)
                        ->exists();

                    if ($isOpenEnded && $isLastGenerated) {
                        $appliedToThisInstallment = $amountToDistribute;
                    } else {
                        $appliedToThisInstallment = min($amountToDistribute, $pendingTotal);
                    }

                    $tempAmount = $appliedToThisInstallment;

                    // 1. Pay Interest First
                    $payInterest = min($tempAmount, $pendingInterest);
                    $newInterestPaid = (float) ($installment->interest_paid ?? 0) + $payInterest;
                    $tempAmount -= $payInterest;

                    // 2. Pay Principal
                    $payPrincipal = $tempAmount;
                    $newPrincipalPaid = (float) ($installment->principal_paid ?? 0) + $payPrincipal;

                    $newPaidAmount = (float) $installment->paid_amount + $appliedToThisInstallment;
                    
                    // Determine new status
                    $newStatus = 'pagado';
                    if (round($newPaidAmount, 2) < round((float) $installment->amount, 2)) {
                        $newStatus = 'parcial';
                    }

                    $tz = $payload['timezone'] ?? 'UTC';
                    $now = Carbon::now($tz);

                    // Update Installment
                    $installment->update([
                        'status' => $newStatus,
                        'paid_amount' => $newPaidAmount,
                        'interest_paid' => $newInterestPaid,
                        'principal_paid' => $newPrincipalPaid,
                        'payment_method' => $pData['payment_method'] ?? $payload['payment_method'] ?? 'Efectivo',
                        'notes' => $pData['notes'] ?? $payload['reference'] ?? null,
                        'voucher_path' => $payload['voucher_path'] ?? null,
                        'last_payment_at' => isset($payload['payment_date']) ? Carbon::parse($payload['payment_date'], $tz) : $now,
                    ]);

                    // Create Payment Audit Record — id generado por secuencia.
                    $payment = CollectionPayment::create([
                        'company_id' => $companyId,
                        'credit_id' => $creditId,
                        'installment_number' => $installment->installment_number,
                        'amount_paid' => $appliedToThisInstallment,
                        'interest_paid' => $payInterest,
                        'principal_paid' => $payPrincipal,
                        'payment_date' => $payload['payment_date'] ?? $now->toDateString(),
                        'receipt_number' => $payload['reference'] ?? null,
                        'payment_method' => $pData['payment_method'] ?? $payload['payment_method'] ?? 'Efectivo',
                        'notes' => $pData['notes'] ?? $payload['reference'] ?? null,
                        'voucher_path' => $payload['voucher_path'] ?? null,
                        'recorded_at' => $now,
                    ]);

                    if ($payPrincipal > 0) {
                        $principalWasReduced = true;
                    }

                    $results[] = $payment->id;
                    $amountToDistribute -= $appliedToThisInstallment;

                    if ($amountToDistribute > 0) {
                        $instNum++; // Move to next installment for the remaining excess
                    }
                }
            }

            // Sync with Centralized Wallet
            $totalAmountCollected = (float) ($payload['amount_total'] ?? 0);
            if ($totalAmountCollected > 0 && $credit) {
                if ($principalWasReduced) {
                    app(\App\Services\Collection\CollectionCreditService::class)->recalculateFutureInstallments($credit);
                }

                app(\App\Services\Collection\CollectionWalletService::class)->recordMovement([
                    'company_id' => $companyId,
                    'currency' => $credit->currency ?? 'COP',
                    'country_code' => $credit->country_code ?? 'CO',
                    'amount' => $totalAmountCollected,
                    'type' => 'credit', // Income
                    'action_type' => 'payment',
                    'reference_type' => 'credit',
                    'reference_id' => $creditId,
                    'description' => "Cobro de cuota(s) crédito #{$creditId}",
                ]);
            }

            // Credito abierto: si todas las cuotas quedaron pagadas, autogenerar la cuota
            // de interes del proximo mes sobre el capital pendiente.
            if ($credit) {
                $meta = is_array($credit->metadata) ? $credit->metadata : [];
                if (!empty($meta['is_open_ended']) && $credit->status === 'active') {
                    app(\App\Services\Collection\CollectionCreditService::class)
                        ->generateNextOpenEndedInstallment($credit);
                }
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Pagos registrados con éxito',
                'count' => count($results),
            ]);
        });
    }


}
