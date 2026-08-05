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
                'No se pueden registrar pagos: la caja del día ' . $paymentDate . ' está cerrada. El corte del día ya es definitivo.',
                409
            );
        }

        $this->partitionService->ensurePartitions($companyId);

        // Validacion previa a la transaccion: un destino explicito (solo interes /
        // solo capital) no puede exceder lo que ese concepto debe. Se corta aqui
        // para poder devolver 422 con mensaje claro en vez de aplicar de menos.
        if ($error = $this->validateAllocations($companyId, $creditId, $payments)) {
            return $error;
        }

        return DB::connection(self::CONNECTION)->transaction(function () use ($payload, $companyId, $creditId, $payments) {
            
            $results = [];
            $principalWasReduced = false;
            $totalApplied = 0.0;
            $credit = CollectionCredit::find($creditId);
            $creditMeta = $credit && is_array($credit->metadata) ? $credit->metadata : [];
            $isOpenEnded = !empty($creditMeta['is_open_ended']);

            // Capital pendiente del credito abierto: tope duro de lo que puede
            // abonarse a capital en esta operacion. Se calcula una vez y se va
            // descontando a medida que se aplican los abonos.
            $remainingPrincipal = null;
            if ($isOpenEnded && $credit) {
                $paidPrincipal = (float) CollectionInstallment::query()
                    ->where('company_id', $companyId)
                    ->where('credit_id', $creditId)
                    ->whereNull('deleted_at')
                    ->sum('principal_paid');
                $remainingPrincipal = round((float) $credit->amount - $paidPrincipal, 2);
            }

            foreach ($payments as $pData) {
                $instNum = $pData['installment_number'];
                $amountToDistribute = (float) ($pData['amount'] ?? 0);

                // Destino del abono elegido por el cobrador:
                //   'interest'  → solo cubre el interes devengado de la cuota
                //   'principal' → solo baja capital, el interes vigente queda intacto
                //   'auto'      → interes primero y el excedente a capital (historico)
                $allocation = strtolower((string) ($pData['allocation'] ?? 'auto'));
                if (!in_array($allocation, ['auto', 'interest', 'principal'], true)) {
                    $allocation = 'auto';
                }

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

                    // Cuanto puede irse a capital en esta cuota. En credito abierto el
                    // capital vive en el credito, no en la cuota: el tope es el capital
                    // pendiente, no `principal_amount` (que es 0 en cuotas de interes).
                    $principalRoom = ($isOpenEnded && $isLastGenerated)
                        ? max(0.0, (float) ($remainingPrincipal ?? 0))
                        : $pendingPrincipal;

                    if ($allocation === 'interest') {
                        // Solo interes: nunca mas que el interes devengado pendiente.
                        $payInterest = min($amountToDistribute, $pendingInterest);
                        $payPrincipal = 0.0;
                        $appliedToThisInstallment = $payInterest;
                    } elseif ($allocation === 'principal') {
                        // Solo capital: el interes devengado de esta cuota NO se toca ni
                        // se recalcula. El nuevo interes se aplica a la cuota siguiente.
                        $payInterest = 0.0;
                        $payPrincipal = min($amountToDistribute, $principalRoom);
                        $appliedToThisInstallment = $payPrincipal;
                    } else {
                        $appliedToThisInstallment = ($isOpenEnded && $isLastGenerated)
                            ? min($amountToDistribute, $pendingInterest + $principalRoom)
                            : min($amountToDistribute, $pendingTotal);

                        // 1. Interes primero
                        $payInterest = min($appliedToThisInstallment, $pendingInterest);
                        // 2. El excedente baja capital
                        $payPrincipal = $appliedToThisInstallment - $payInterest;
                    }

                    if (round($appliedToThisInstallment, 2) <= 0) {
                        // Nada aplicable aqui (p.ej. abono a capital con capital ya saldado,
                        // o abono a interes sobre una cuota sin interes pendiente).
                        break;
                    }

                    $newInterestPaid = (float) ($installment->interest_paid ?? 0) + $payInterest;
                    $newPrincipalPaid = (float) ($installment->principal_paid ?? 0) + $payPrincipal;

                    if ($payPrincipal > 0 && $remainingPrincipal !== null) {
                        $remainingPrincipal = round($remainingPrincipal - $payPrincipal, 2);
                    }

                    $newPaidAmount = (float) $installment->paid_amount + $appliedToThisInstallment;

                    // Estado por componentes, no por `paid_amount >= amount`: un abono a
                    // capital puede superar el monto de la cuota y aun asi dejar el
                    // interes del mes sin pagar — esa cuota sigue abierta.
                    $interestSettled = round($newInterestPaid, 2) >= round((float) $installment->interest_amount, 2);
                    $principalSettled = round($newPrincipalPaid, 2) >= round((float) $installment->principal_amount, 2);

                    $newStatus = ($interestSettled && $principalSettled) ? 'pagado' : 'parcial';

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
                    $totalApplied += $appliedToThisInstallment;
                    $amountToDistribute -= $appliedToThisInstallment;

                    // Con destino explicito el abono se agota en la cuota vigente:
                    // no se arrastra a la siguiente (que ni siquiera existe todavia
                    // en un credito abierto).
                    if ($allocation !== 'auto') {
                        break;
                    }

                    if ($amountToDistribute > 0) {
                        $instNum++; // Move to next installment for the remaining excess
                    }
                }
            }

            // Sync with Centralized Wallet. Si el front no envio amount_total se usa
            // lo realmente aplicado, para que el cobro nunca deje de entrar a caja.
            $totalAmountCollected = (float) ($payload['amount_total'] ?? 0);
            if ($totalAmountCollected <= 0) {
                $totalAmountCollected = round($totalApplied, 2);
            }

            if ($credit && $principalWasReduced) {
                app(\App\Services\Collection\CollectionCreditService::class)->recalculateFutureInstallments($credit);
            }

            if ($totalAmountCollected > 0 && $credit) {
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

    /**
     * Un abono con destino explicito no puede exceder lo que ese concepto debe:
     * "solo interes" tope el interes devengado de la cuota, "solo capital" tope el
     * capital pendiente del credito. Sin esto el excedente se perderia en silencio
     * (el bucle de aplicacion recorta y no hay cuota siguiente donde arrastrarlo).
     */
    private function validateAllocations(int $companyId, int $creditId, array $payments)
    {
        $credit = CollectionCredit::query()
            ->where('company_id', $companyId)
            ->where('id', $creditId)
            ->first();

        if (!$credit) {
            return $this->errorNotFoundResponse('Crédito no encontrado');
        }

        $paidPrincipal = (float) CollectionInstallment::query()
            ->where('company_id', $companyId)
            ->where('credit_id', $creditId)
            ->whereNull('deleted_at')
            ->sum('principal_paid');

        $principalRoom = round((float) $credit->amount - $paidPrincipal, 2);

        foreach ($payments as $pData) {
            $allocation = strtolower((string) ($pData['allocation'] ?? 'auto'));
            if (!in_array($allocation, ['interest', 'principal'], true)) {
                continue;
            }

            $amount = round((float) ($pData['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            if ($allocation === 'principal') {
                if ($amount > $principalRoom + 0.01) {
                    return $this->errorResponse(
                        'El abono a capital (' . number_format($amount, 2) . ') supera el capital pendiente ('
                        . number_format(max(0, $principalRoom), 2) . ').',
                        422
                    );
                }
                $principalRoom = round($principalRoom - $amount, 2);
                continue;
            }

            $installment = CollectionInstallment::query()
                ->where('company_id', $companyId)
                ->where('credit_id', $creditId)
                ->where('installment_number', $pData['installment_number'] ?? 0)
                ->whereNull('deleted_at')
                ->first();

            $pendingInterest = $installment
                ? round((float) $installment->interest_amount - (float) ($installment->interest_paid ?? 0), 2)
                : 0.0;

            if ($amount > $pendingInterest + 0.01) {
                return $this->errorResponse(
                    'El abono a interés (' . number_format($amount, 2) . ') supera el interés pendiente de la cuota ('
                    . number_format(max(0, $pendingInterest), 2) . ').',
                    422
                );
            }
        }

        return null;
    }


}
