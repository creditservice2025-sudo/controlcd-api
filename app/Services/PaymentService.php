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

class PaymentService
{
    use ApiResponse;

    public function create(PaymentRequest $request)
    {
        try {
            $params = $request->validated();

            $installment = Installment::find($request->installment_id);

            if (!$installment) {
                throw new \Exception('La cuota no existe.');
            }

            if ($installment->status === 'Pagado') {
                throw new \Exception('No se puede realizar un pago a una cuota que ya está pagada.');
            }

            $credit = $installment->credit;

            if (!$credit) {
                throw new \Exception('El crédito no existe.');
            }

            if ($request->status === 'Pagado' && $request->amount != $installment->quota_amount) {
                throw new \Exception('El pago debe ser igual al monto de la cuota.');
            }

            if ($request->status === 'Devuelto') {
                if ($request->amount != $credit->remaining_amount) {
                    throw new \Exception('El pago debe ser igual al monto restante del crédito.');
                }

                if ($credit->remaining_amount <= 0 || ($credit->status !== 'Pendiente' && $credit->status !== 'Moroso')) {
                    throw new \Exception('El crédito no tiene un monto pendiente o no está en estado "Pendiente" o "Moroso".');
                }
            }

            $payment = Payment::create($params);

            if ($payment->status === 'Pagado') {
                $installment->update(['status' => 'Pagado']);
                $credit->remaining_amount -= $payment->amount;
            
                if ($credit->remaining_amount < 0) {
                    $credit->remaining_amount = 0;
                }

                if ($request->payment_date > $credit->end_date) {
                    $credit->status = 'Moroso';
                }
            
                if ($credit->remaining_amount == 0) {
                    $credit->status = 'Finalizado';
                }  
            
                $credit->save();
            }

            if ($payment->status === 'Devuelto') {
                $credit->installments()->update(['status' => 'Pagado']);

                $credit->status = 'Renovado';

                $credit->remaining_amount = 0;
                $credit->save();
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Pago procesado correctamente',
                'data' => $payment
            ]);

        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function index($creditId)
    {
        try{
            $credit = Credit::find($creditId);

            if (!$credit) {
                throw new \Exception('El crédito no existe.');
            }

            $payments = Payment::join('installments', 'payments.installment_id', '=', 'installments.id')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->join('guarantors', 'credits.guarantor_id', '=', 'guarantors.id')
            ->where('credits.id', $creditId)
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
            ->get();

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
