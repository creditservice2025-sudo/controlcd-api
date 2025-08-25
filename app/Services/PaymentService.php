<?php

namespace App\Services;

use App\Helpers\Helper;
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
use App\Models\Liquidation;
use App\Models\PaymentImage;
use App\Models\PaymentInstallment;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class PaymentService
{
    use ApiResponse;

    public function create(PaymentRequest $request)
    {
        try {
            DB::beginTransaction();
            $params = $request->validated();
            $credit = Credit::find($request->credit_id);
            $cacheKey = null;
            $cachePaymentsKey = null;
            $user = Auth::user();

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
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude
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

            $nextInstallment = Installment::where('credit_id', $credit->id)
                ->whereIn('status', ['Pendiente', 'Parcial', 'Atrasado'])
                ->orderBy('due_date', 'asc')
                ->first();

            if (!$nextInstallment) {
                throw new \Exception('No hay cuotas pendientes para aplicar el pago.');
            }

            $pendingAmountNextInstallment = $nextInstallment->quota_amount - $nextInstallment->paid_amount;
            $isAbono = $request->amount < $pendingAmountNextInstallment;

            // Crear registro de pago (estado inicial)
            $payment = Payment::create([
                'credit_id' => $request->credit_id,
                'payment_date' => $request->payment_date,
                'amount' => $request->amount,
                'status' => $isAbono ? 'Abonado' : 'Pagado',
                'payment_method' => $request->payment_method,
                'payment_reference' => $request->payment_reference ?: '',
                'latitude' => $request->latitude,
                'longitude' => $request->longitude
            ]);

            if (!$isAbono) {
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
                $minPendingQuota = $pendingInstallments->min('quota_amount') ?? 0;

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
            } else {
                $cacheKey = "credit:{$credit->id}:pending_payments";
                $cachePaymentsKey = "credit:{$credit->id}:pending_payments_list";

                $accumulated = Cache::get($cacheKey, 0);
                $accumulated = is_numeric($accumulated) ? $accumulated : 0;

                $pendingPayments = Cache::get($cachePaymentsKey, []);

                $pendingPayments[] = [
                    'payment_id' => $payment->id,
                    'amount' => $request->amount,
                    'payment_date' => $request->payment_date
                ];

                $newAccumulated = $accumulated + $request->amount;
                $remainingAccumulated = $newAccumulated;
                $completedInstallmentId = null;

                $installments = Installment::where('credit_id', $credit->id)
                    ->whereIn('status', ['Pendiente', 'Parcial', 'Atrasado'])
                    ->orderBy('due_date', 'asc')
                    ->get();

                $amountAppliedToInstallment = 0;
                foreach ($installments as $installment) {
                    $pendingAmount = $installment->quota_amount - $installment->paid_amount;
                    if ($pendingAmount <= 0) continue;

                    if ($newAccumulated >= $pendingAmount) {
                        $installment->paid_amount += $pendingAmount;
                        $installment->status = 'Pagado';
                        $installment->save();

                        $completedInstallmentId = $installment->id;
                        $amountAppliedToInstallment = $pendingAmount;
                        $remainingAccumulated -= $pendingAmount;

                        $amountToAssign = $pendingAmount;
                        foreach ($pendingPayments as $pendingPayment) {
                            if ($amountToAssign <= 0) break;

                            $amountApplied = min($pendingPayment['amount'], $amountToAssign);

                            PaymentInstallment::create([
                                'payment_id' => $pendingPayment['payment_id'],
                                'installment_id' => $completedInstallmentId,
                                'applied_amount' => $amountApplied
                            ]);

                            if ($amountApplied == $pendingPayment['amount']) {
                                $p = Payment::find($pendingPayment['payment_id']);
                                $p->status = 'Abonado';
                                $p->save();
                            } else {
                                $pendingPayment['amount'] -= $amountApplied;
                            }

                            $amountToAssign -= $amountApplied;
                        }

                        $pendingPayments = array_filter($pendingPayments, function ($p) {
                            return $p['amount'] > 0;
                        });

                        break;
                    }
                }

                if ($remainingAccumulated > 0) {
                    Cache::put($cacheKey, $remainingAccumulated);
                    Cache::put($cachePaymentsKey, $pendingPayments);
                } else {
                    Cache::forget($cacheKey);
                    Cache::forget($cachePaymentsKey);
                }

                if ($amountAppliedToInstallment > 0) {
                    $payment->status = $isAbono ? 'Abonado' : 'Pagado';
                    $payment->save();
                }

                $credit->remaining_amount = max($credit->remaining_amount - $request->amount, 0);
            }


            $pendingInstallments = Installment::where('credit_id', $credit->id)
                ->where('status', '<>', 'Pagado')
                ->exists();

            if (!$pendingInstallments) {
                $credit->status = 'Liquidado';
                Cache::forget($cacheKey);
            } elseif ($request->payment_date > $credit->end_date) {
                $credit->status = 'Vigente';
            }
            $credit->save();

            if ($request->hasFile('image')) {
                $imageFile = $request->file('image');

                $imagePath = Helper::uploadFile($imageFile, 'payments');

                PaymentImage::create([
                    'payment_id' => $payment->id,
                    'user_id' => $user->id,
                    'path' => $imagePath
                ]);
            }

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => $isAbono ? 'Abono procesado correctamente' : 'Pago procesado correctamente',
                'data' => $payment
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }



    public function index($creditId, Request $request, $perPage)
    {
        try {
            $credit = Credit::find($creditId);

            if (!$credit) {
                throw new \Exception('El crédito no existe.');
            }

            $paymentsQuery = Payment::leftJoin('payment_installments', 'payments.id', '=', 'payment_installments.payment_id')
                ->leftJoin('installments', 'payment_installments.installment_id', '=', 'installments.id')
                ->join('credits', 'payments.credit_id', '=', 'credits.id')
                ->join('clients', 'credits.client_id', '=', 'clients.id')
                ->leftJoin('payment_images', 'payments.id', '=', 'payment_images.payment_id')
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
                    'payment_images.path as image_path',
                    'payments.payment_date',
                    'payments.created_at',
                    'payments.amount as total_payment',
                    'payments.payment_method',
                    'payments.payment_reference',
                    'payments.status',

                    DB::raw('GROUP_CONCAT(installments.quota_number ORDER BY installments.quota_number) as quotas'),
                    DB::raw('COALESCE(SUM(payment_installments.applied_amount), 0) as total_applied')
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
                    'payments.status',
                    'payments.created_at',
                    'payment_images.path'

                )
                ->orderBy('payments.created_at', 'desc');


            if ($request->has('status') && $request->status === 'Abonado') {
                $paymentsQuery->where('payments.status', 'Abonado');
            } elseif ($request->has('status') && $request->status === 'Pagado') {
                $paymentsQuery->where('payments.status', 'Pagado');
            }

            $payments = $paymentsQuery->paginate($perPage, ['*']);

            return $this->successResponse([
                'success' => true,
                'message' => 'Pagos obtenidos correctamente',
                'data' => $payments
            ]);

            /*   return $this->successResponse([
                'success' => true,
                'message' => 'Pagos obtenidos correctamente',
                'data' => $payments->items(),
                'pagination' => [
                    'total' => $payments->total(),
                    'current_page' => $payments->currentPage(),
                    'per_page' => $payments->perPage(),
                    'last_page' => $payments->lastPage(),
                ]
            ]); */
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getPaymentsBySeller($sellerId, Request $request, $perPage)
    {
        $seller = Seller::find($sellerId);

        if (!$seller) {
            return $this->successResponse([
                'success' => false,
                'message' => 'El vendedor no existe.',
                'data' => null
            ], 404);
        }

        // Consulta base para obtener pagos
        $paymentsQuery = Payment::query()
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->leftJoin('payment_images', 'payments.id', '=', 'payment_images.payment_id')
            ->where('clients.seller_id', $sellerId)
            ->select(
                'payments.id',
                'clients.id as client_id',
                'clients.name as client_name',
                'clients.dni as client_dni',
                'credits.id as credit_id',
                'credits.credit_value',
                'credits.status as credit_status',
                'credits.total_interest',
                'credits.total_amount',
                'credits.number_installments',
                'credits.start_date',
                'payments.payment_date',
                'payments.amount as total_payment',
                'payments.payment_method',
                'payments.payment_reference',
                'payments.status',
                'payments.amount',
                'payments.created_at',
                'payment_images.path as image_path'
            )
            ->orderBy('clients.name')
            ->orderBy('credits.id')
            ->orderBy('payments.payment_date', 'desc');

        // Filtros de fecha
        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->get('start_date'))->startOfDay();
            $endDate = Carbon::parse($request->get('end_date'))->endOfDay();
            $paymentsQuery->whereBetween('payments.payment_date', [$startDate, $endDate]);
        } elseif ($request->has('date')) {
            $filterDate = Carbon::parse($request->get('date'))->toDateString();
            $paymentsQuery->whereDate('payments.payment_date', $filterDate);
        } else {
            $paymentsQuery->whereDate('payments.payment_date', Carbon::today());
        }

        // Filtro de estado
        if ($request->has('status') && in_array($request->status, ['Abonado', 'Pagado'])) {
            $paymentsQuery->where('payments.status', $request->status);
        }

        $payments = $paymentsQuery->get();

        // Obtener IDs de pagos para cargar cuotas
        $paymentIds = $payments->where('status', 'Pagado')->pluck('id');

        // Cargar cuotas en una sola consulta
        $installmentsDetails = collect();
        if ($paymentIds->isNotEmpty()) {
            $installmentsDetails = DB::table('payment_installments')
                ->join('installments', 'payment_installments.installment_id', '=', 'installments.id')
                ->whereIn('payment_installments.payment_id', $paymentIds)
                ->select(
                    'installments.*',
                    'payment_installments.applied_amount',
                    'payment_installments.created_at',
                    'payment_installments.payment_id'
                )
                ->get()
                ->groupBy('payment_id');
        }

        // Procesar cada pago
        $payments->transform(function ($payment) use ($installmentsDetails) {
            if ($payment->status === 'Pagado') {
                $payment->installments_details = $installmentsDetails->get($payment->id, collect());
                $payment->total_applied = $payment->installments_details->sum('applied_amount');
            } else {
                $payment->installments_details = collect();
                $payment->total_applied = 0;
            }
            return $payment;
        });

        // Agrupar pagos por cliente y crédito
        $groupedByClientAndCredit = $payments->groupBy(['client_id', 'credit_id']);

        $groupedPayments = collect();
        foreach ($groupedByClientAndCredit as $clientId => $credits) {
            foreach ($credits as $creditId => $creditPayments) {
                $firstPayment = $creditPayments->first();

                $groupedPayments->push([
                    'client_id' => $clientId,
                    'client_name' => $firstPayment->client_name,
                    'client_dni' => $firstPayment->client_dni,
                    'credit_id' => $creditId,
                    'credit_value' => $firstPayment->credit_value,
                    'status' => $firstPayment->credit_status,
                    'total_interest' => $firstPayment->total_interest,
                    'total_amount' => $firstPayment->total_amount,
                    'number_installments' => $firstPayment->number_installments,
                    'start_date' => $firstPayment->start_date,
                    'payments' => $creditPayments->map(function ($payment) {
                        return [
                            'id' => $payment->id,
                            'payment_date' => $payment->payment_date,
                            'total_payment' => $payment->total_payment,
                            'payment_method' => $payment->payment_method,
                            'payment_reference' => $payment->payment_reference,
                            'status' => $payment->status,
                            'amount' => $payment->amount,
                            'created_at' => $payment->created_at,
                            'installments_details' => $payment->installments_details,
                            'total_applied' => $payment->total_applied,
                            'image_path' => $payment->image_path
                        ];
                    })->values()
                ]);
            }
        }

        $currentPage = (int)$request->input('page', 1); // Conversión a entero
        $offset = ($currentPage - 1) * $perPage;
        $currentPageItems = $groupedPayments->slice($offset, $perPage)->values();

        return $this->successResponse([
            'success' => true,
            'message' => 'Pagos obtenidos correctamente',
            'data' => [
                'grouped_payments' => $currentPageItems,
                'pagination' => [
                    'total' => $groupedPayments->count(),
                    'per_page' => $perPage,
                    'current_page' => $currentPage
                ]
            ]
        ]);
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
                ->leftJoin('payment_images', 'payments.id', '=', 'payment_images.payment_id')
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

    public function delete($paymentId)
    {
        try {
            DB::beginTransaction();

            $payment = Payment::with(['credit.client', 'installments.installment'])->find($paymentId);

            if (!$payment) {
                throw new \Exception('El pago no existe.');
            }

            $today = Carbon::today();
            $paymentDate = Carbon::parse($payment->created_at)->startOfDay();

            if (!$paymentDate->equalTo($today)) {
                throw new \Exception('Solo se pueden eliminar pagos creados el día de hoy.');
            }

            // Verificar si existe una liquidación para el vendedor en la fecha del pago
            $credit = $payment->credit;
            $sellerId = $credit->client->seller_id;

            $liquidationExists = Liquidation::where('seller_id', $sellerId)
                ->whereDate('created_at', $paymentDate)
                ->exists();

            if ($liquidationExists) {
                throw new \Exception('No se puede eliminar el pago. El vendedor ya tiene una liquidación registrada para el día de hoy.');
            }

            // Validación adicional: Verificar si hay pagos posteriores que dependan de este
            $laterPayments = Payment::where('credit_id', $payment->credit_id)
                ->where('created_at', '>', $payment->created_at)
                ->exists();

            if ($laterPayments) {
                throw new \Exception('No se puede eliminar este pago porque existen pagos posteriores en el mismo crédito. Debe eliminar primero los pagos más recientes.');
            }

            // Validación para abonos acumulados
            if ($payment->status === 'Abonado') {
                $cacheKey = "credit:{$credit->id}:pending_payments";
                $cachePaymentsKey = "credit:{$credit->id}:pending_payments_list";

                $pendingPayments = Cache::get($cachePaymentsKey, []);

                // Verificar si este pago está en la lista de abonos pendientes
                $isInPending = collect($pendingPayments)->contains('payment_id', $paymentId);

                if ($isInPending && count($pendingPayments) > 1) {
                    // Encontrar la posición de este pago en la lista
                    $paymentIndex = null;
                    foreach ($pendingPayments as $index => $pendingPayment) {
                        if ($pendingPayment['payment_id'] == $paymentId) {
                            $paymentIndex = $index;
                            break;
                        }
                    }

                    // Si no es el último pago, no se puede eliminar
                    if ($paymentIndex !== null && $paymentIndex < count($pendingPayments) - 1) {
                        throw new \Exception('No se puede eliminar este abono porque existen abonos posteriores que dependen de él. Debe eliminar primero los abonos más recientes.');
                    }
                }
            }

            // Revertir los montos aplicados a las cuotas
            foreach ($payment->installments as $paymentInstallment) {
                $installment = $paymentInstallment->installment;

                if ($installment) {
                    // Revertir el monto aplicado a la cuota
                    $installment->paid_amount = max(0, $installment->paid_amount - $paymentInstallment->applied_amount);

                    // Actualizar el estado de la cuota
                    if ($installment->paid_amount <= 0) {
                        // Si no se ha pagado nada, determinar estado según fecha de vencimiento
                        $dueDate = Carbon::parse($installment->due_date);
                        $installment->status = $dueDate->isPast() ? 'Atrasado' : 'Pendiente';
                    } elseif ($installment->paid_amount < $installment->quota_amount) {
                        $installment->status = 'Parcial';
                    } else {
                        $installment->status = 'Pagado';
                    }

                    $installment->save();
                }
            }

            // Eliminar registros relacionados
            PaymentInstallment::where('payment_id', $paymentId)->delete();
            PaymentImage::where('payment_id', $paymentId)->delete();

            // Revertir el pago en el crédito
            $credit->remaining_amount += $payment->amount;

            // Si el crédito estaba marcado como Liquidado, volver a estado Vigente
            if ($credit->status === 'Liquidado') {
                $credit->status = 'Vigente';
            }

            $credit->save();

            // Si era un abono pendiente, actualizar la caché
            if ($payment->status === 'Abonado') {
                $cacheKey = "credit:{$credit->id}:pending_payments";
                $cachePaymentsKey = "credit:{$credit->id}:pending_payments_list";

                $accumulated = Cache::get($cacheKey, 0);
                $pendingPayments = Cache::get($cachePaymentsKey, []);

                // Restar el monto del acumulado
                $newAccumulated = max($accumulated - $payment->amount, 0);

                // Remover este pago de la lista
                $pendingPayments = array_filter($pendingPayments, function ($p) use ($paymentId) {
                    return $p['payment_id'] != $paymentId;
                });

                if ($newAccumulated > 0) {
                    Cache::put($cacheKey, $newAccumulated);
                    Cache::put($cachePaymentsKey, $pendingPayments);
                } else {
                    Cache::forget($cacheKey);
                    Cache::forget($cachePaymentsKey);
                }
            }

            // Eliminar el pago
            $payment->delete();

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Pago eliminado correctamente',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error al eliminar el pago con ID {$paymentId}: " . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getTotalWithoutInstallments($creditId)
    {
        try {
            $credit = Credit::find($creditId);

            if (!$credit) {
                throw new \Exception('El crédito no existe.');
            }

            $total = Payment::leftJoin('payment_installments', 'payments.id', '=', 'payment_installments.payment_id')
                ->where('payments.credit_id', $creditId)
                ->whereNull('payment_installments.id')
                ->sum('payments.amount');

            return $this->successResponse([
                'success' => true,
                'message' => 'Total de pagos sin cuotas asignadas obtenido correctamente',
                'data' => [
                    'total' => $total
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
