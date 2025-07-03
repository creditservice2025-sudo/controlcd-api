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
use Illuminate\Http\Request;

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
        $isPartialPayment = false;

        $installments = Installment::where('credit_id', $credit->id)
            ->whereIn('status', ['Pendiente', 'Parcial', 'Atrasado'])
            ->orderBy('due_date')
            ->get();

        if ($installments->isEmpty()) {
            throw new \Exception('No hay cuotas pendientes para aplicar el pago.');
        }

        $pendingInstallments = $installments->filter(fn($i) => $i->status === 'Pendiente');
        $minPendingQuota = $pendingInstallments->min('quota_amount');

        if ($remainingAmount >= $minPendingQuota) {
            foreach ($pendingInstallments as $installment) {
                if ($remainingAmount <= 0) break;

                $pendingAmount = $installment->quota_amount - $installment->paid_amount;
                if ($pendingAmount <= 0) continue;

                $toApply = min($pendingAmount, $remainingAmount);
                $installment->paid_amount += $toApply;

                $installment->status = $installment->paid_amount >= $installment->quota_amount ? 'Pagado' : 'Parcial';
                $installment->save();

                PaymentInstallment::create([
                    'payment_id' => $payment->id,
                    'installment_id' => $installment->id,
                    'applied_amount' => $toApply
                ]);

                $remainingAmount -= $toApply;
            }
        }

        if ($remainingAmount > 0) {
            $targetInstallment = $installments->firstWhere(fn($i) => in_array($i->status, ['Parcial', 'Pendiente']));
            if ($targetInstallment) {
                $pendingAmount = $targetInstallment->quota_amount - $targetInstallment->paid_amount;
                $toApply = min($pendingAmount, $remainingAmount);

                $targetInstallment->paid_amount += $toApply;
                $targetInstallment->status = $targetInstallment->paid_amount >= $targetInstallment->quota_amount ? 'Pagado' : 'Parcial';
                $targetInstallment->save();

                PaymentInstallment::create([
                    'payment_id' => $payment->id,
                    'installment_id' => $targetInstallment->id,
                    'applied_amount' => $toApply
                ]);

                $remainingAmount -= $toApply;
                $isPartialPayment = true;
            }
        }

        if ($isPartialPayment) {
            $payment->status = 'Abonado';
            $payment->save();
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



    public function index($creditId, Request $request, $page, $perPage)
    {
        try {
            $credit = Credit::find($creditId);

            if (!$credit) {
                throw new \Exception('El crédito no existe.');
            }

            $paymentsQuery = Payment::join('payment_installments', 'payments.id', '=', 'payment_installments.payment_id')
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
                    
                    'payments.status',
                    DB::raw('GROUP_CONCAT(installments.quota_number ORDER BY installments.quota_number) as quotas'),
                    DB::raw('SUM(payment_installments.applied_amount) as total_applied')
                )
                ->groupBy(
                    'payments.id',
                    'clients.name',
                    'clients.dni',
                    'credits.credit_value',
                    'credits.total_interest',
                    'credits.total_amount',
                    'credits.number_installments',
                    'credits.start_date',
                    'payments.payment_date',
                    'payments.amount',
                    'payments.payment_method',
                    'payments.payment_reference',
                    'payments.status'
                );



            if ($request->status === 'Abonado') {
                $paymentsQuery->where('payments.status', 'Abonado');
            } elseif ($request->status === 'Pagado') {
                $paymentsQuery->where('payments.status', 'Pagado');
            }

            $payments = $paymentsQuery->paginate($perPage, ['*'], 'page', $page);

            return $this->successResponse([
                'success' => true,
                'message' => 'Pagos obtenidos correctamente',
                'data' => $payments->items(),
                'pagination' => [
                    'total' => $payments->total(),
                    'current_page' => $payments->currentPage(),
                    'per_page' => $payments->perPage(),
                    'last_page' => $payments->lastPage(),
                ]
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
