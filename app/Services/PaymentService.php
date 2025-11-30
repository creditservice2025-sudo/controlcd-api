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

            // LOG 1: Ver los parámetros validados, incluyendo el timezone si se envió.
            Log::info('Payment Create - Validated Request Params Received', $params);

            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $params['created_at'] = Carbon::now($params['timezone']);
                $params['updated_at'] = Carbon::now($params['timezone']);
                $userTimezone = $params['timezone'];
                unset($params['timezone']);
                // LOG 2: Confirmar la aplicación de la zona horaria y las fechas resultantes.
                Log::info('Timezone Applied Success. Timestamps:', [
                    'timezone' => $userTimezone,
                    'created_at' => $params['created_at']->toDateTimeString(),
                    'updated_at' => $params['updated_at']->toDateTimeString()
                ]);
            } else {
                $userTimezone = null;
                // LOG 3: Si no hay timezone.
                Log::info('No Timezone Provided. Using default system/app timezone for timestamps.');
            }

            $credit = Credit::find($request->credit_id);
            $user = Auth::user();

            if (!$credit) {
                throw new \Exception('El crédito no existe.');
            }

            // ======= IDEMPOTENCY CHECK =======
            $idempotencyKey = $request->header('X-Idempotency-Key');
            if ($idempotencyKey) {
                $cacheKey = "payment_processed:{$idempotencyKey}";
                if (Cache::has($cacheKey)) {
                    DB::rollBack(); // No need for transaction here
                    return $this->successResponse([
                        'success' => true,
                        'message' => 'Pago ya procesado anteriormente (Idempotencia).',
                        'data' => Cache::get($cacheKey)
                    ]);
                }
            }

            // ======= LOCKING =======
            // Increased TTL to 60 seconds to handle slow uploads
            $lockKey = "payment:create:credit:{$credit->id}:user:{$user->id}";
            $lock = Cache::lock($lockKey, 60);

            if (!$lock->get()) {
                throw new \Exception('Se está procesando otro pago para este crédito. Por favor espera unos segundos e intenta de nuevo.');
            }

            try {
                // Double check idempotency inside lock to be sure
                if ($idempotencyKey && Cache::has("payment_processed:{$idempotencyKey}")) {
                    return $this->successResponse([
                        'success' => true,
                        'message' => 'Pago ya procesado anteriormente (Idempotencia).',
                        'data' => Cache::get("payment_processed:{$idempotencyKey}")
                    ]);
                }

                // Duplicate check (Legacy/Fallback) - Keep it but it's less critical with Idempotency Key
                $now = $userTimezone ? Carbon::now($userTimezone) : Carbon::now();
                $windowStart = $now->copy()->subHour();

                // Only check if NO idempotency key provided, or as a safety net
                if (!$idempotencyKey) {
                    $duplicateQuery = Payment::where('credit_id', $credit->id)
                        ->where('amount', $request->amount)
                        ->where('created_at', '>=', $windowStart);

                    if ($request->filled('payment_method')) {
                        $duplicateQuery->where('payment_method', $request->payment_method);
                    }

                    $recentPayment = $duplicateQuery->first();

                    if ($recentPayment) {
                        Log::warning('Duplicate payment attempt blocked (Legacy Check)', [
                            'credit_id' => $credit->id,
                            'amount' => $request->amount,
                            'recent_id' => $recentPayment->id
                        ]);
                        throw new \Exception('Pago duplicado detectado. Ya se registró un pago similar en la última hora.');
                    }
                }

                if ($request->amount == 0) {
                    // Logic for "No Pago" remains mostly the same but ensure it's robust
                    $paymentData = [
                        'credit_id' => $params['credit_id'],
                        'payment_date' => $params['payment_date'],
                        'amount' => $params['amount'],
                        'status' => 'No pagado',
                        'payment_method' => $params['payment_method'],
                        'payment_reference' => $params['payment_reference'] ?: 'Registro de no pago',
                        'latitude' => $params['latitude'],
                        'longitude' => $params['longitude'],
                        'created_at' => $params['created_at'] ?? null,
                        'updated_at' => $params['updated_at'] ?? null
                    ];

                    $payment = Payment::create($paymentData);

                    // Link to next installment even if amount is 0, for tracking?
                    // Existing logic did this, preserving it.
                    $nextInstallment = Installment::where('credit_id', $credit->id)
                        ->whereIn('status', ['Pendiente', 'Parcial', 'Atrasado'])
                        ->orderBy('due_date', 'asc')
                        ->first();

                    if ($nextInstallment) {
                        PaymentInstallment::create([
                            'payment_id' => $payment->id,
                            'installment_id' => $nextInstallment->id,
                            'applied_amount' => 0,
                            'created_at' => $params['created_at'] ?? null,
                            'updated_at' => $params['updated_at'] ?? null
                        ]);
                    }

                    // Save to Idempotency Cache
                    if ($idempotencyKey) {
                        Cache::put("payment_processed:{$idempotencyKey}", $payment, 86400); // 24h
                    }

                    DB::commit();
                    return $this->successResponse([
                        'success' => true,
                        'message' => 'Registro de no pago realizado',
                        'data' => $payment
                    ]);
                }

                // ======= UNIFIED PAYMENT LOGIC (Full & Partial) =======
                // No more "if ($isAbono) use Cache". Always persist.

                $nextInstallment = Installment::where('credit_id', $credit->id)
                    ->whereIn('status', ['Pendiente', 'Parcial', 'Atrasado'])
                    ->orderBy('due_date', 'asc')
                    ->first();

                if (!$nextInstallment) {
                    throw new \Exception('No hay cuotas pendientes para aplicar el pago.');
                }

                $pendingAmountNextInstallment = $nextInstallment->quota_amount - $nextInstallment->paid_amount;
                // Determine status based on if it covers the next installment fully
                // Note: This status is for the PAYMENT record.
                // If it pays at least the pending amount of the immediate next installment, we can call it 'Pagado' (or logic preference).
                // However, usually 'Abonado' means partial payment of the TOTAL debt or partial of the installment?
                // The original logic: $isAbono = $request->amount < $pendingAmountNextInstallment;
                // We will keep this distinction for the Payment status label.
                $isAbono = $request->amount < $pendingAmountNextInstallment;

                $paymentData = [
                    'credit_id' => $params['credit_id'],
                    'payment_date' => $params['payment_date'],
                    'amount' => $params['amount'],
                    'status' => $isAbono ? 'Abonado' : 'Pagado',
                    'payment_method' => $params['payment_method'],
                    'payment_reference' => $params['payment_reference'] ?: '',
                    'latitude' => $params['latitude'],
                    'longitude' => $params['longitude'],
                    'created_at' => $params['created_at'] ?? null,
                    'updated_at' => $params['updated_at'] ?? null
                ];

                $payment = Payment::create($paymentData);

                // Apply payment to installments
                $remainingAmount = $request->amount;

                $installments = Installment::where('credit_id', $credit->id)
                    ->whereIn('status', ['Pendiente', 'Parcial', 'Atrasado'])
                    ->orderBy('due_date')
                    ->get();

                foreach ($installments as $installment) {
                    if ($remainingAmount <= 0)
                        break;

                    $quotaAmount = (float) $installment->quota_amount;
                    $paidAmount = (float) $installment->paid_amount;
                    $pendingAmount = $quotaAmount - $paidAmount;

                    // Fix for "Zombie" installments (Paid but status not updated)
                    if ($pendingAmount <= 0.001) {
                        if ($installment->status !== 'Pagado') {
                            $installment->status = 'Pagado';
                            $installment->save();
                        }
                        continue;
                    }

                    $toApply = min($pendingAmount, $remainingAmount);
                    $toApply = round($toApply, 2);

                    if ($toApply <= 0)
                        continue;

                    $installment->paid_amount = $paidAmount + $toApply;

                    // Update Status
                    if ($installment->paid_amount >= ($quotaAmount - 0.001)) {
                        $installment->status = 'Pagado';
                        // Ensure we don't exceed quota amount visually
                        if ($installment->paid_amount > $quotaAmount) {
                            $installment->paid_amount = $quotaAmount;
                        }
                    } else {
                        $installment->status = 'Parcial';
                    }

                    $installment->save();

                    PaymentInstallment::create([
                        'payment_id' => $payment->id,
                        'installment_id' => $installment->id,
                        'applied_amount' => $toApply,
                        'created_at' => $params['created_at'] ?? null,
                        'updated_at' => $params['updated_at'] ?? null
                    ]);

                    $remainingAmount -= $toApply;
                }

                // Update Credit Remaining Amount
                // We subtract the TOTAL payment amount from the credit's remaining amount
                // Logic: remaining_amount tracks total debt.
                $credit->remaining_amount -= $request->amount;
                if ($credit->remaining_amount < 0) {
                    $credit->remaining_amount = 0;
                }

                // Update Credit Status
                $pendingInstallmentsExists = Installment::where('credit_id', $credit->id)
                    ->where('status', '<>', 'Pagado')
                    ->exists();

                if (!$pendingInstallmentsExists && $credit->remaining_amount <= 0.001) {
                    $credit->status = 'Liquidado';
                } elseif ($request->payment_date > $credit->end_date) {
                    // Only change to Vigente if it was something else?
                    // Or logic: if not liquidado, check if overdue?
                    // Original logic: if ($request->payment_date > $credit->end_date) $credit->status = 'Vigente';
                    // Wait, if payment_date > end_date, it might be 'Vencido' (Overdue)?
                    // 'Vigente' usually means 'Current/Active'.
                    // Let's preserve original logic for status update to avoid side effects,
                    // but 'Vigente' seems to be the default active status.
                    $credit->status = 'Vigente';
                }
                $credit->save();

                // Handle Image Upload
                if ($request->hasFile('image')) {
                    $imageFile = $request->file('image');
                    $imagePath = Helper::uploadFile($imageFile, 'payments');

                    PaymentImage::create([
                        'payment_id' => $payment->id,
                        'user_id' => $user->id,
                        'path' => $imagePath,
                        'created_at' => $params['created_at'] ?? null,
                        'updated_at' => $params['updated_at'] ?? null
                    ]);
                }

                // Save to Idempotency Cache
                if ($idempotencyKey) {
                    Cache::put("payment_processed:{$idempotencyKey}", $payment, 86400); // 24h
                }

                DB::commit();

                Log::info('Payment processed successfully (Unified Flow) for Credit ID: ' . $credit->id);

                return $this->successResponse([
                    'success' => true,
                    'message' => $isAbono ? 'Abono procesado correctamente' : 'Pago procesado correctamente',
                    'data' => $payment
                ]);

            } finally {
                try {
                    $lock->release();
                } catch (\Throwable $ex) {
                    Log::warning('Failed to release payment creation lock', ['lock_key' => $lockKey, 'error' => $ex->getMessage()]);
                }
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing payment for Credit ID: ' . ($request->credit_id ?? 'N/A') . '. Message: ' . $e->getMessage(), ['exception' => $e]);
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
                    DB::raw('COALESCE(SUM(payment_installments.applied_amount), 0) as total_applied'),
                    DB::raw("
            CONCAT(
                '[',
                GROUP_CONCAT(
                    JSON_OBJECT(
                        'quota_number', installments.quota_number,
                        'applied_amount', payment_installments.applied_amount
                    )
                    ORDER BY installments.quota_number
                ),
                ']'
            ) as installment_details_json
        "),

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

    public function paymentsToday($creditId, Request $request, $perPage)
    {
        try {
            \Log::info("paymentsToday called with creditId:", ['creditId' => $creditId]);
            \Log::info("Server date for filter:", ['today' => \Carbon\Carbon::today()->toDateString()]);

            $credit = Credit::find($creditId);

            if (!$credit) {
                \Log::warning("Credit not found for ID: $creditId");
                throw new \Exception('El crédito no existe.');
            }

            $paymentsQuery = Payment::leftJoin('payment_installments', 'payments.id', '=', 'payment_installments.payment_id')
                ->leftJoin('installments', 'payment_installments.installment_id', '=', 'installments.id')
                ->join('credits', 'payments.credit_id', '=', 'credits.id')
                ->join('clients', 'credits.client_id', '=', 'clients.id')
                ->leftJoin('payment_images', 'payments.id', '=', 'payment_images.payment_id')
                ->where('credits.id', $creditId)
                ->whereDate('payments.payment_date', \Carbon\Carbon::today())
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
                    \DB::raw('MAX(payments.amount) as total_payment'),
                    'payments.payment_method',
                    'payments.payment_reference',
                    'payments.status',
                    \DB::raw('GROUP_CONCAT(installments.quota_number ORDER BY installments.quota_number) as quotas'),
                    \DB::raw('COALESCE(SUM(payment_installments.applied_amount), 0) as total_applied')
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
                    'payments.payment_method',
                    'payments.payment_reference',
                    'payments.status',
                    'payments.created_at',
                    'payment_images.path'
                )
                ->orderBy('payments.created_at', 'desc');

            if ($request->filled('status')) {
                $paymentsQuery->where('payments.status', $request->status);
            }

            // Log SQL query
            \Log::info("SQL Query:", ['sql' => $paymentsQuery->toSql(), 'bindings' => $paymentsQuery->getBindings()]);

            // Log count before paginating
            $count = $paymentsQuery->count();
            \Log::info("Payments found before paginate:", ['count' => $count]);

            $payments = $paymentsQuery->paginate($perPage);

            return $this->successResponse([
                'success' => true,
                'message' => 'Pagos del día para el crédito obtenidos correctamente',
                'data' => $payments
            ]);
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

        $timezone = $request->input('timezone', 'America/Lima');

        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('perPage', 10);

        // 1. Filtra los pagos por fecha, estado y seller
        $paymentsFilterQuery = Payment::query();

        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->get('start_date'), $timezone)->startOfDay();
            $endDate = Carbon::parse($request->get('end_date'), $timezone)->endOfDay();
            $paymentsFilterQuery->whereBetween('created_at', [$startDate, $endDate]);
        } elseif ($request->has('date')) {
            $filterDate = Carbon::parse($request->get('date'), $timezone)->toDateString();
            $paymentsFilterQuery->whereDate('created_at', $filterDate);
        } else {
            $filterDate = Carbon::now($timezone)->toDateString();
            $paymentsFilterQuery->whereDate('created_at', $filterDate);
        }

        if ($request->has('status') && in_array($request->status, ['Abonado', 'Pagado'])) {
            $paymentsFilterQuery->where('status', $request->status);
        }

        // Solo pagos de créditos del seller
        $paymentsFilterQuery->whereHas('credit', function ($q) use ($sellerId) {
            $q->where('seller_id', $sellerId);
        });

        // 2. Obtén los pagos filtrados
        $filteredPayments = $paymentsFilterQuery->get();

        // 3. Obtén los credit_id de esos pagos
        $filteredCreditIds = $filteredPayments->pluck('credit_id')->unique();

        // 4. Trae los créditos que tienen esos pagos y el cliente
        $creditsQuery = Credit::whereIn('id', $filteredCreditIds)
            ->with('client')
            ->orderBy('id');

        $creditsPaginator = $creditsQuery->paginate($perPage, ['*'], 'page', $page);
        $credits = collect($creditsPaginator->items());

        // 5. Agrupa pagos por crédito
        $groupedPayments = collect();

        foreach ($credits as $credit) {
            // Para la vista agrupada por cuotas, traer los pagos filtrados por fecha
            $allCreditPaymentsQuery = Payment::where('credit_id', $credit->id)
                ->orderBy('created_at', 'desc');

            if ($request->has('start_date') && $request->has('end_date')) {
                $startDate = Carbon::parse($request->get('start_date'), $timezone)->startOfDay();
                $endDate = Carbon::parse($request->get('end_date'), $timezone)->endOfDay();
                $allCreditPaymentsQuery->whereBetween('created_at', [$startDate, $endDate]);
            } elseif ($request->has('date')) {
                $filterDate = Carbon::parse($request->get('date'), $timezone)->toDateString();
                $allCreditPaymentsQuery->whereDate('created_at', $filterDate);
            } else {
                $filterDate = Carbon::now($timezone)->toDateString();
                $allCreditPaymentsQuery->whereDate('created_at', $filterDate);
            }

            $allCreditPayments = $allCreditPaymentsQuery->get();

            // Calcular el total pagado SOLO de los pagos filtrados (para el resumen)
            $filteredCreditPayments = $filteredPayments->where('credit_id', $credit->id);
            $total_paid = $filteredCreditPayments->sum('amount');

            // Obtener cuotas para TODOS los pagos históricos del crédito
            $paymentIds = $allCreditPayments->pluck('id');
            $installmentsDetails = collect();
            if ($paymentIds->isNotEmpty()) {
                $installmentsDetails = DB::table('payment_installments')
                    ->join('installments', 'payment_installments.installment_id', '=', 'installments.id')
                    ->whereIn('payment_installments.payment_id', $paymentIds)
                    ->select(
                        'installments.quota_number',
                        'installments.due_date',
                        'installments.quota_amount as installment_amount',
                        'payment_installments.applied_amount as paid_amount',
                        'payment_installments.created_at',
                        'payment_installments.payment_id'
                    )
                    ->get()
                    ->groupBy('payment_id');
            }

            $allCreditPayments->transform(function ($payment) use ($installmentsDetails) {
                $payment->installments_details = $installmentsDetails->get($payment->id, collect())->values();
                $payment->total_applied = $payment->installments_details->sum('paid_amount');
                return $payment;
            });

            $groupedPayments->push([
                'client_id' => $credit->client->id,
                'client_name' => $credit->client->name,
                'client_dni' => $credit->client->dni,
                'credit_id' => $credit->id,
                'credit_value' => $credit->credit_value,
                'status' => $credit->status,
                'total_interest' => $credit->total_interest,
                'total_amount' => $credit->total_amount,
                'number_installments' => $credit->number_installments,
                'start_date' => $credit->start_date,
                'payments' => $allCreditPayments, // Todos los pagos históricos
                'total_paid' => $total_paid, // Solo pagos del rango filtrado
            ]);
        }

        // 6. Suma total solo de pagos filtrados
        $totalPaymentsAmount = $filteredPayments->sum('amount');

        return $this->successResponse([
            'success' => true,
            'message' => 'Pagos obtenidos correctamente',
            'data' => [
                'grouped_payments' => $groupedPayments,
                'pagination' => [
                    'total' => $creditsPaginator->total(),
                    'per_page' => $creditsPaginator->perPage(),
                    'current_page' => $creditsPaginator->currentPage(),
                    'last_page' => $creditsPaginator->lastPage(),
                ],
                'total_payments_amount' => $totalPaymentsAmount
            ]
        ]);
    }

    public function getAllPaymentsBySeller($sellerId, Request $request)
    {
        $seller = Seller::find($sellerId);
        if (!$seller) {
            return $this->successResponse([
                'success' => false,
                'message' => 'El vendedor no existe.',
                'data' => null
            ], 404);
        }

        $timezone = 'America/Lima';
        $paymentsQuery = Payment::query();

        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->get('start_date'), $timezone)->startOfDay();
            $endDate = Carbon::parse($request->get('end_date'), $timezone)->endOfDay();
            $paymentsQuery->whereBetween('created_at', [$startDate, $endDate]);
        } elseif ($request->has('date')) {
            $filterDate = Carbon::parse($request->get('date'), $timezone)->toDateString();
            $paymentsQuery->whereDate('created_at', $filterDate);
        } else {
            $filterDate = Carbon::now($timezone)->toDateString();
            $paymentsQuery->whereDate('created_at', $filterDate);
        }

        if ($request->has('status') && in_array($request->status, ['Abonado', 'Pagado'])) {
            $paymentsQuery->where('status', $request->status);
        }

        // Solo pagos de créditos del seller
        $paymentsQuery->whereHas('credit', function ($q) use ($sellerId) {
            $q->where('seller_id', $sellerId);
        });

        $payments = $paymentsQuery->with(['credit:id,client_id', 'credit.client:id,name,dni'])->orderBy('created_at', 'desc')->get();

        return $this->successResponse([
            'success' => true,
            'message' => 'Pagos obtenidos correctamente',
            'data' => $payments
        ]);
    }

    public function getPaymentsByDate($date, $sellerId = null, Request $request)
    {
        $query = Payment::with([
            'credit:id,client_id,credit_value,status',
            'credit.client:id,name,dni,address'
        ])
            ->whereDate('created_at', $date);

        Log::info($query->toSql());
        Log::info($query->getBindings());

        if ($sellerId) {
            $query->whereHas('credit', function ($q) use ($sellerId) {
                $q->where('seller_id', $sellerId);
            });
        }

        Log::info($query->toSql());
        Log::info($query->getBindings());

        $payments = $query->get();

        Log::info('PaymentsByDate results:', ['count' => $payments->count(), 'data' => $payments->toArray()]);

        $result = $payments->map(function ($payment) {
            return [
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'payment_date' => $payment->payment_date,
                'created_at' => $payment->created_at,
                'latitude' => $payment->latitude,
                'longitude' => $payment->longitude,
                'payment_method' => $payment->payment_method,
                'status' => $payment->status,
                'client' => [
                    'id' => $payment->credit->client->id ?? null,
                    'name' => $payment->credit->client->name ?? null,
                    'dni' => $payment->credit->client->dni ?? null,
                    'address' => $payment->credit->client->address ?? null,
                ],
                'credit' => [
                    'id' => $payment->credit->id ?? null,
                    'credit_value' => $payment->credit->credit_value ?? null,
                    'status' => $payment->credit->status ?? null,
                ],
            ];
        });

        return $result;
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

    public function delete($paymentId, Request $request)
    {
        try {
            DB::beginTransaction();

            $payment = Payment::with(['credit.client', 'installments.installment'])->find($paymentId);

            if (!$payment) {
                throw new \Exception('El pago no existe.');
            }

            $timezone = $request->has('timezone') ? $request->get('timezone') : null;

            $today = Carbon::now($timezone)->startOfDay();
            $paymentDate = Carbon::parse($payment->created_at)->setTimezone($timezone)->startOfDay();

            if (!$paymentDate->equalTo($today)) {
                throw new \Exception('Solo se pueden eliminar pagos creados el día de hoy.');
            }

            // Verificar si existe una liquidación para el vendedor en la fecha del pago
            $credit = $payment->credit;
            $sellerId = $credit->client->seller_id;

            $liquidationExists = Liquidation::where('seller_id', $sellerId)
                ->whereDate('date', $paymentDate)
                ->exists();

            if ($liquidationExists && Auth::user()->role_id !== 1) {
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
