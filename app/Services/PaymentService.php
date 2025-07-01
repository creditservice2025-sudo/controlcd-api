<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Credit;
use App\Http\Requests\Credit\CreditRequest;
use Carbon\Carbon;
use App\Models\Payment;
use App\Http\Requests\Payment\PaymentRequest;
use App\Models\Installment;
use App\Models\PaymentInstallment;

class PaymentService
{
    use ApiResponse;

    public function create(PaymentRequest $request)
    {
        try {
            DB::beginTransaction();

            $params = $request->validated();

            $credit = Credit::find($request->credit_id);

            if (!$credit) {
                throw new \Exception('El crédito no existe.');
            }

            /*   if (!in_array($credit->status, ['Pendiente', 'Moroso'])) {
                throw new \Exception('No se pueden realizar pagos a un crédito en estado: ' . $credit->status);
            } */

            if ($request->amount == 0) {
                $payment = Payment::create([
                    'credit_id' => $request->credit_id,
                    'payment_date' => $request->payment_date,
                    'amount' => 0,
                    'status' => 'No pagado',
                    'payment_method' => $request->payment_method,
                    'payment_reference' => $request->payment_reference ?: 'Registro de no pago',
                ]);

                $nextInstallment = Installment::where('credit_id', $credit->id)
                    ->whereIn('status', ['Pendiente', 'Parcial'])
                    ->orderBy('due_date', 'asc')
                    ->first();

                if ($nextInstallment) {
                    if (now()->gt($nextInstallment->due_date)) {
                        $nextInstallment->status = 'Atrasado';
                        $nextInstallment->save();
                    }

                    PaymentInstallment::create([
                        'payment_id' => $payment->id,
                        'installment_id' => $nextInstallment->id,
                        'applied_amount' => 0
                    ]);
                }

                DB::commit();

                return $this->successResponse([
                    'success' => true,
                    'message' => 'Registro de no pago realizado',
                    'data' => $payment
                ]);
            }

            Log::info($request->credit_id);
            $payment = Payment::create([
                'credit_id' => $request->credit_id,
                'payment_date' => $request->payment_date,
                'amount' => $request->amount,
                'status' => 'Pagado',
                'payment_method' => $request->payment_method,
                'payment_reference' => $request->payment_reference ?: '',
            ]);

            $remainingAmount = $request->amount;

            $installments = Installment::where('credit_id', $credit->id)
                ->whereIn('status', ['Pendiente', 'Atrasado', 'Parcial'])
                ->orderBy('due_date')
                ->get();

            if ($installments->isEmpty()) {
                throw new \Exception('No hay cuotas pendientes para aplicar el pago.');
            }

            foreach ($installments as $installment) {
                if ($remainingAmount <= 0) break;

                $pendingAmount = $installment->quota_amount - $installment->paid_amount;
                $toApply = min($pendingAmount, $remainingAmount);

                $installment->paid_amount += $toApply;

                if ($installment->paid_amount >= $installment->quota_amount) {
                    $installment->status = 'Pagado';
                } elseif ($installment->paid_amount > 0) {
                    $installment->status = 'Parcial';
                }

                $installment->save();

                PaymentInstallment::create([
                    'payment_id' => $payment->id,
                    'installment_id' => $installment->id,
                    'applied_amount' => $toApply
                ]);

                $remainingAmount -= $toApply;
            }

            $appliedAmount = $request->amount - $remainingAmount;
            $credit->remaining_amount -= $appliedAmount;

            if ($credit->remaining_amount < 0) {
                $credit->remaining_amount = 0;
            }

            $pendingInstallments = Installment::where('credit_id', $credit->id)
                ->where('status', '<>', 'Pagado')
                ->exists();

            if (!$pendingInstallments) {
                $credit->status = 'Saldado';
            } elseif ($request->payment_date > $credit->end_date) {
                $credit->status = 'Pendiente';
            }

            $credit->save();

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Pago procesado correctamente',
                'data' => $payment
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function index($creditId)
    {
        try {
            $credit = Credit::find($creditId);

            if (!$credit) {
                throw new \Exception('El crédito no existe.');
            }

            $payments = Payment::join('payment_installments', 'payments.id', '=', 'payment_installments.payment_id')
                ->join('installments', 'payment_installments.installment_id', '=', 'installments.id')
                ->join('credits', 'installments.credit_id', '=', 'credits.id') 
                ->join('clients', 'credits.client_id', '=', 'clients.id')
                ->where('credits.id', $creditId)
                ->select(
                    'payments.id',
                    'clients.name as client_name',
                    'clients.dni as client_dni',
                    'credits.credit_value',
                    'credits.total_interest',
                    'credits.total_amount',
                    'credits.number_installments',
                    'credits.start_date',
                    'payments.payment_date',
                    'payments.amount as total_payment',
                    'payments.payment_method',
                    'payments.payment_reference',
                    'installments.quota_number',
                    'installments.status',
                    'installments.due_date',
                    'installments.quota_amount',
                    'installments.paid_amount',
                    'installments.quota_number',
                    'payment_installments.applied_amount'
                )
                ->get();

                Log::info($payments);

            return $this->successResponse([
                'success' => true,
                'message' => 'Pagos obtenidos correctamente',
                'data' => $payments
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show($creditId, $paymentId)
    {
        try {
            $credit = Credit::find($creditId);

            if (!$credit) {
                throw new \Exception('El crédito no existe.');
            }

            $payment = Payment::join('installments', 'payments.installment_id', '=', 'installments.id')
                ->join('credits', 'installments.credit_id', '=', 'credits.id')
                ->join('clients', 'credits.client_id', '=', 'clients.id')
                ->join('guarantors', 'credits.guarantor_id', '=', 'guarantors.id')
                ->where('credits.id', $creditId)
                ->where('payments.id', $paymentId)
                ->select(
                    'clients.name as client_name',
                    'clients.dni as client_dni',
                    'guarantors.name as guarantor_name',
                    'guarantors.dni as guarantor_dni',
                    'credits.credit_value',
                    'credits.total_interest',
                    'credits.total_amount',
                    'credits.number_installments',
                    'credits.start_date',
                    'payments.payment_date',
                    'payments.amount',
                    'payments.payment_method',
                    'payments.payment_reference'
                )
                ->first();

            if (!$payment) {
                throw new \Exception('El pago no existe o no está asociado a este crédito.');
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Pago obtenido correctamente',
                'data' => $payment
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
