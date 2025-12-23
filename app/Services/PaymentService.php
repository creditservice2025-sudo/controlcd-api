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

    private GeolocationHistoryService $geolocationHistoryService;

    public function __construct(GeolocationHistoryService $geolocationHistoryService)
    {
        $this->geolocationHistoryService = $geolocationHistoryService;
    }

    private function resolveBusinessTimezone(?string $paymentClientTimezone, Request $request): string
    {
        $tz = $paymentClientTimezone
            ?: ($request->has('timezone') ? $request->get('timezone') : null)
            ?: config('app.timezone');

        return is_string($tz) && $tz !== '' ? $tz : config('app.timezone');
    }

    public function create(PaymentRequest $request)
    {
        try {
            DB::beginTransaction();

            $params = $request->validated();

            // Obtener zona horaria del cliente (donde se realiza el pago)
            $clientTimezone = $request->get('client_timezone', config('app.timezone'));

            // 1. TIMESTAMP TÉCNICO (auditoría del sistema)
            $serverNow = Carbon::now('UTC');

            // 2. TIMESTAMP DE NEGOCIO (hora oficial del pago)
            $businessNow = Carbon::now($clientTimezone);
            $businessTimestampUtc = $businessNow->copy()->utc();
            $businessDate = $businessNow->toDateString();

            Log::info('Payment Create - Business Timestamps Generated', [
                'client_timezone' => $clientTimezone,
                'server_now_utc' => $serverNow->toDateTimeString(),
                'business_now_local' => $businessNow->toDateTimeString(),
                'business_timestamp_utc' => $businessTimestampUtc->toDateTimeString(),
                'business_date' => $businessDate
            ]);

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
                $now = $clientTimezone ? Carbon::now($clientTimezone) : Carbon::now();
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
                        'credit_id' => $credit->id,
                        'user_id' => $user->id,
                        'amount' => $request->amount,
                        'status' => 'No pagado',
                        'payment_method' => $params['payment_method'] ?: null,
                        'payment_reference' => $params['payment_reference'] ?: 'No pagó',
                        'latitude' => $params['latitude'],
                        'longitude' => $params['longitude'],

                        // TIMESTAMPS TÉCNICOS (auditoría)
                        'created_at' => $serverNow,
                        'updated_at' => $serverNow,

                        // TIMESTAMPS DE NEGOCIO (operaciones)
                        'business_timestamp' => $businessTimestampUtc,
                        'business_date' => $businessDate,
                        'business_timezone' => $clientTimezone,

                        // COMPATIBILIDAD (mantener por ahora)
                        'payment_date' => $businessDate,
                        'client_timezone' => $clientTimezone,
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
                            'created_at' => $serverNow,
                            'updated_at' => $serverNow
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
                    'credit_id' => $credit->id,
                    'user_id' => $user->id,
                    'amount' => $request->amount,
                    'status' => $isAbono ? 'Abonado' : 'Pagado',
                    'payment_method' => $params['payment_method'],
                    'payment_reference' => $params['payment_reference'] ?: '',
                    'latitude' => $params['latitude'],
                    'longitude' => $params['longitude'],

                    // TIMESTAMPS TÉCNICOS (auditoría)
                    'created_at' => $serverNow,
                    'updated_at' => $serverNow,

                    // TIMESTAMPS DE NEGOCIO (operaciones)
                    'business_timestamp' => $businessTimestampUtc,
                    'business_date' => $businessDate,
                    'business_timezone' => $clientTimezone,

                    // COMPATIBILIDAD (mantener por ahora)
                    'payment_date' => $businessDate,
                    'client_timezone' => $clientTimezone,
                ];

                $payment = Payment::create($paymentData);

                // ======= NEW STACKING LOGIC (Priority + FIFO) =======

                // 1. Initialize unapplied_amount for the NEW payment
                $payment->unapplied_amount = $payment->amount;
                $payment->save();

                $remainingAmount = $request->amount; // Just for tracking, logic uses unapplied_amount

                $installments = Installment::where('credit_id', $credit->id)
                    ->whereIn('status', ['Pendiente', 'Parcial', 'Atrasado'])
                    ->orderBy('due_date')
                    ->get();

                foreach ($installments as $installment) {
                    $quotaAmount = (float) $installment->quota_amount;
                    // We treat 'paid_amount' as 0 for logic purposes because we want to fully pay it or nothing
                    // BUT, if there was legacy partial payment, we should respect it.
                    // Let's assume we want to pay the *pending* part of the installment.
                    $alreadyPaid = (float) $installment->paid_amount;
                    $targetAmount = round($quotaAmount - $alreadyPaid, 2);

                    if ($targetAmount <= 0.001) {
                        if ($installment->status !== 'Pagado') {
                            $installment->status = 'Pagado';
                            $installment->save();
                        }
                        continue;
                    }

                    // --- STEP 1: PRIORITY CHECK (Try to pay with NEW payment only) ---
                    // Refresh payment to get latest unapplied_amount
                    $payment->refresh();

                    if ($payment->unapplied_amount >= $targetAmount) {
                        // Apply directly from NEW payment
                        $this->applyPaymentToInstallment($payment, $installment, $targetAmount);
                        continue; // Done with this installment, move to next
                    }

                    // --- STEP 2: STACK CHECK (Fallback to FIFO) ---
                    // Calculate Total Available Surplus (All payments for this credit with unapplied > 0)
                    $stackPayments = Payment::where('credit_id', $credit->id)
                        ->where('unapplied_amount', '>', 0)
                        ->orderBy('created_at', 'asc') // FIFO
                        ->get();

                    $totalStack = $stackPayments->sum('unapplied_amount');

                    if ($totalStack >= $targetAmount) {
                        // We have enough in the stack! Consume FIFO.
                        $amountNeeded = $targetAmount;

                        foreach ($stackPayments as $stackPayment) {
                            if ($amountNeeded <= 0)
                                break;

                            $available = $stackPayment->unapplied_amount;
                            $toTake = min($available, $amountNeeded);

                            $this->applyPaymentToInstallment($stackPayment, $installment, $toTake);

                            $amountNeeded -= $toTake;
                        }
                    } else {
                        // Not enough money even with the stack. Stop distribution.
                        // The money stays in unapplied_amount of the payments.
                        break;
                    }
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
                        'created_at' => $params['created_at'] ?? $serverNow,
                        'updated_at' => $params['updated_at'] ?? $serverNow
                    ]);
                }

                // Save to Idempotency Cache
                if ($idempotencyKey) {
                    Cache::put("payment_processed:{$idempotencyKey}", $payment, 86400); // 24h
                }

                DB::commit();

                Log::info('Payment processed successfully (Unified Flow) for Credit ID: ' . $credit->id);

                $response = $this->successResponse([
                    'success' => true,
                    'message' => $isAbono ? 'Abono procesado correctamente' : 'Pago procesado correctamente',
                    'data' => $payment
                ]);

                // Record Geolocation History
                if (isset($params['latitude']) && isset($params['longitude'])) {
                    $this->geolocationHistoryService->record(
                        $credit->client_id,
                        $params['latitude'],
                        $params['longitude'],
                        'payment_created',
                        'Abono/Pago a crédito',
                        $payment->id,
                        null, // Address might not be in params, check if needed
                        null  // Accuracy might not be in params
                    );
                }

                return $response;

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

    public function deletePaymentInstallment($paymentInstallmentId, Request $request)
    {
        try {
            DB::beginTransaction();

            $paymentInstallment = PaymentInstallment::with(['payment.credit.client', 'installment'])
                ->find($paymentInstallmentId);

            if (!$paymentInstallment) {
                throw new \Exception('El movimiento no existe.');
            }

            $payment = $paymentInstallment->payment;
            $installment = $paymentInstallment->installment;

            if (!$payment || !$installment) {
                throw new \Exception('No se pudo resolver el pago o la cuota asociada al movimiento.');
            }

            $timezone = $payment->business_timezone ?? config('app.timezone');
            $today = Carbon::now($timezone)->toDateString();
            $paymentBusinessDate = $payment->business_date;

            if ($paymentBusinessDate !== $today) {
                throw new \Exception('Solo se pueden eliminar movimientos de pagos creados el día de hoy.');
            }

            $credit = $payment->credit;
            if (!$credit) {
                throw new \Exception('El crédito asociado al pago no existe.');
            }

            $sellerId = $credit->client->seller_id;

            $liquidationExists = Liquidation::where('seller_id', $sellerId)
                ->whereDate('date', '=', $paymentBusinessDate)
                ->exists();

            if ($liquidationExists && Auth::user()->role_id !== 1) {
                throw new \Exception('No se puede eliminar el movimiento. El vendedor ya tiene una liquidación registrada para el día de hoy.');
            }

            $laterPayments = Payment::where('credit_id', $payment->credit_id)
                ->where('created_at', '>', $payment->created_at)
                ->exists();

            if ($laterPayments) {
                throw new \Exception('No se puede eliminar este movimiento porque existen pagos posteriores en el mismo crédito. Debe eliminar primero los pagos más recientes.');
            }

            $appliedAmount = (float) $paymentInstallment->applied_amount;

            // Revert installment amounts/status
            $installment->paid_amount = max(0, (float) $installment->paid_amount - $appliedAmount);

            if ($installment->paid_amount <= 0) {
                $dueDate = Carbon::parse($installment->due_date);
                $installment->status = $dueDate->isPast() ? 'Atrasado' : 'Pendiente';
            } elseif ($installment->paid_amount < (float) $installment->quota_amount) {
                $installment->status = 'Parcial';
            } else {
                $installment->status = 'Pagado';
            }
            $installment->save();

            // Return money to payment's unapplied amount
            $payment->unapplied_amount = (float) ($payment->unapplied_amount ?? 0) + $appliedAmount;
            $payment->save();

            // Soft delete movement with audit
            $paymentInstallment->deleted_by = Auth::id();
            $paymentInstallment->save();
            $paymentInstallment->delete();

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Movimiento eliminado correctamente',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error al eliminar el movimiento con ID {$paymentInstallmentId}: " . $e->getMessage());
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

            // Aumentar el límite de GROUP_CONCAT para evitar truncamiento del JSON
            DB::statement('SET SESSION group_concat_max_len = 1000000');

            $paymentsQuery = Payment::leftJoin('payment_installments', function ($join) {
                $join->on('payments.id', '=', 'payment_installments.payment_id')
                    ->whereNull('payment_installments.deleted_at');
            })
                ->leftJoin('installments', 'payment_installments.installment_id', '=', 'installments.id')
                ->leftJoin('payment_installments as deleted_payment_installments', function ($join) {
                    $join->on('payments.id', '=', 'deleted_payment_installments.payment_id')
                        ->whereNotNull('deleted_payment_installments.deleted_at');
                })
                ->leftJoin('installments as deleted_installments', 'deleted_payment_installments.installment_id', '=', 'deleted_installments.id')
                ->leftJoin('users as deleted_users', 'deleted_payment_installments.deleted_by', '=', 'deleted_users.id')
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
                    DB::raw("\n             CONCAT(
                '[',
                COALESCE(GROUP_CONCAT(DISTINCT JSON_OBJECT(
                        'payment_installment_id', payment_installments.id,
                        'quota_number', installments.quota_number,
                        'applied_amount', payment_installments.applied_amount,
                        'applied_at', payment_installments.created_at,
                        'installment_status', installments.status,
                        'quota_amount', installments.quota_amount,
                        'due_date', installments.due_date
                    )
                    ORDER BY installments.quota_number
                ), ''),
                ']'
            ) as installment_details_json
        "),

                    DB::raw("\n             CONCAT(
                '[',
                COALESCE(GROUP_CONCAT(DISTINCT JSON_OBJECT(
                        'payment_installment_id', deleted_payment_installments.id,
                        'quota_number', deleted_installments.quota_number,
                        'applied_amount', deleted_payment_installments.applied_amount,
                        'deleted_at', deleted_payment_installments.deleted_at,
                        'deleted_by', deleted_users.name
                    )
                    ORDER BY deleted_payment_installments.deleted_at DESC
                ), ''),
                ']'
            ) as deleted_installment_details_json
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

            $timezone = $request->input('timezone', config('app.timezone'));
            $today = Carbon::now($timezone)->toDateString();

            $paymentsQuery = Payment::leftJoin('payment_installments', 'payments.id', '=', 'payment_installments.payment_id')
                ->leftJoin('installments', 'payment_installments.installment_id', '=', 'installments.id')
                ->join('credits', 'payments.credit_id', '=', 'credits.id')
                ->join('clients', 'credits.client_id', '=', 'clients.id')
                ->leftJoin('payment_images', 'payments.id', '=', 'payment_images.payment_id')
                ->where('credits.id', $creditId)
                ->whereDate('payments.payment_date', $today)
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
            $startDate = Carbon::parse($request->get('start_date'), $timezone)->toDateString();
            $endDate = Carbon::parse($request->get('end_date'), $timezone)->toDateString();
            $paymentsFilterQuery->whereBetween('business_date', [$startDate, $endDate]);
        } elseif ($request->has('date')) {
            $filterDate = Carbon::parse($request->get('date'), $timezone)->toDateString();
            $paymentsFilterQuery->where('business_date', $filterDate);
        } else {
            $filterDate = Carbon::now($timezone)->toDateString();
            $paymentsFilterQuery->where('business_date', $filterDate);
        }

        if ($request->has('status') && in_array($request->status, ['Abonado', 'Pagado'])) {
            $paymentsFilterQuery->where('status', $request->status);
        }

        // Solo pagos de créditos del seller
        $paymentsFilterQuery->whereHas('credit', function ($q) use ($sellerId) {
            $q->where('seller_id', $sellerId);
        });

        // OPTIMIZACIÓN: Calcular total antes de modificar el query con distinct/pluck
        // Clonamos para no afectar el query builder si fuera necesario, aunque sum() es terminal.
        // Pero pluck() abajo modificará el builder si encadenamos.
        $totalPaymentsAmount = (clone $paymentsFilterQuery)->sum('amount');

        // 2. Obtén los credit_id de esos pagos (Optimizado: pluck en lugar de get)
        $filteredCreditIds = $paymentsFilterQuery->distinct()->pluck('credit_id');

        // 4. Trae los créditos que tienen esos pagos y el cliente
        $creditsQuery = Credit::whereIn('id', $filteredCreditIds)
            ->with('client')
            ->orderBy('id');

        $creditsPaginator = $creditsQuery->paginate($perPage, ['*'], 'page', $page);
        $credits = collect($creditsPaginator->items());
        $pageCreditIds = $credits->pluck('id');

        // 5. Traer TODOS los pagos requeridos para estos créditos en UNA sola consulta
        // Aplicando los mismos filtros de fecha que usamos arriba
        $paymentsQuery = Payment::whereIn('credit_id', $pageCreditIds)
            ->orderBy('created_at', 'desc');

        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->get('start_date'), $timezone)->toDateString();
            $endDate = Carbon::parse($request->get('end_date'), $timezone)->toDateString();
            $paymentsQuery->whereBetween('business_date', [$startDate, $endDate]);
        } elseif ($request->has('date')) {
            $filterDate = Carbon::parse($request->get('date'), $timezone)->toDateString();
            $paymentsQuery->where('business_date', $filterDate);
        } else {
            $filterDate = Carbon::now($timezone)->toDateString();
            $paymentsQuery->where('business_date', $filterDate);
        }

        if ($request->has('status') && in_array($request->status, ['Abonado', 'Pagado'])) {
            $paymentsQuery->where('status', $request->status);
        }

        $allPayments = $paymentsQuery->get();
        $allPaymentIds = $allPayments->pluck('id');

        // 6. Traer detalles de cuotas para TODOS esos pagos en UNA sola consulta
        $installmentsDetails = collect();
        if ($allPaymentIds->isNotEmpty()) {
            $installmentsDetails = DB::table('payment_installments')
                ->join('installments', 'payment_installments.installment_id', '=', 'installments.id')
                ->whereIn('payment_installments.payment_id', $allPaymentIds)
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

        // 7. Agrupar pagos por crédito
        $paymentsByCredit = $allPayments->groupBy('credit_id');

        // 8. Construir respuesta
        $groupedPayments = collect();

        foreach ($credits as $credit) {
            $creditPayments = $paymentsByCredit->get($credit->id, collect());

            // Adjuntar detalles de cuotas
            $creditPayments->transform(function ($payment) use ($installmentsDetails) {
                $payment->installments_details = $installmentsDetails->get($payment->id, collect())->values();
                $payment->total_applied = $payment->installments_details->sum('paid_amount');
                return $payment;
            });

            $total_paid = $creditPayments->sum('amount');

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
                'payments' => $creditPayments->values(),
                'total_paid' => $total_paid,
            ]);
        }

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
            $startDate = Carbon::parse($request->get('start_date'), $timezone)->toDateString();
            $endDate = Carbon::parse($request->get('end_date'), $timezone)->toDateString();
            $paymentsQuery->whereBetween('business_date', [$startDate, $endDate]);
        } elseif ($request->has('date')) {
            $filterDate = Carbon::parse($request->get('date'), $timezone)->toDateString();
            $paymentsQuery->where('business_date', $filterDate);
        } else {
            $filterDate = Carbon::now($timezone)->toDateString();
            $paymentsQuery->where('business_date', $filterDate);
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
                ->whereDate('date', '=', $paymentDate->format('Y-m-d'))
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

    private function applyPaymentToInstallment(Payment $payment, Installment $installment, float $amount)
    {
        // 1. Create PaymentInstallment record
        PaymentInstallment::create([
            'payment_id' => $payment->id,
            'installment_id' => $installment->id,
            'applied_amount' => $amount,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 2. Update Installment
        $installment->paid_amount += $amount;

        // Check if fully paid (allowing for small float diffs)
        if ($installment->quota_amount - $installment->paid_amount <= 0.001) {
            $installment->status = 'Pagado';
        } else {
            // It remains 'Pendiente' (or 'Parcial' if we used that status, but user wants 'Pendiente')
            // We ensure it's not 'Pagado' if it was somehow marked before
            if ($installment->status === 'Pagado') {
                $installment->status = 'Pendiente';
            }
        }
        $installment->save();

        // 3. Update Payment Unapplied Amount
        $payment->unapplied_amount -= $amount;
        if ($payment->unapplied_amount < 0) {
            $payment->unapplied_amount = 0;
        }
        $payment->save();
    }
    public function reapplyPayments($creditId)
    {
        try {
            DB::beginTransaction();

            $credit = Credit::find($creditId);
            if (!$credit) {
                throw new \Exception('El crédito no existe.');
            }

            // 1. Get all payments with unapplied amount > 0 (FIFO)
            $stackPayments = Payment::where('credit_id', $creditId)
                ->where('unapplied_amount', '>', 0)
                ->orderBy('created_at', 'asc')
                ->get();

            if ($stackPayments->isEmpty()) {
                throw new \Exception('No hay abonos pendientes de aplicar.');
            }

            // 2. Get all pending installments
            $installments = Installment::where('credit_id', $creditId)
                ->whereIn('status', ['Pendiente', 'Parcial', 'Atrasado'])
                ->orderBy('due_date', 'asc')
                ->get();

            if ($installments->isEmpty()) {
                throw new \Exception('No hay cuotas pendientes.');
            }

            $appliedTotal = 0;

            foreach ($installments as $installment) {
                $quotaAmount = (float) $installment->quota_amount;
                $alreadyPaid = (float) $installment->paid_amount;
                $targetAmount = round($quotaAmount - $alreadyPaid, 2);

                if ($targetAmount <= 0.001) {
                    if ($installment->status !== 'Pagado') {
                        $installment->status = 'Pagado';
                        $installment->save();
                    }
                    continue;
                }

                // Check stack
                $totalStack = $stackPayments->sum('unapplied_amount');

                if ($totalStack >= $targetAmount) {
                    $amountNeeded = $targetAmount;

                    foreach ($stackPayments as $stackPayment) {
                        if ($amountNeeded <= 0)
                            break;

                        // Refresh to get latest unapplied if modified in previous iteration?
                        // No, we are iterating the collection. But we modify the objects.
                        // Since we modify the object reference in the collection, it should be fine.

                        if ($stackPayment->unapplied_amount <= 0)
                            continue;

                        $available = $stackPayment->unapplied_amount;
                        $toTake = min($available, $amountNeeded);

                        $this->applyPaymentToInstallment($stackPayment, $installment, $toTake);

                        $amountNeeded -= $toTake;
                        $appliedTotal += $toTake;
                    }
                } else {
                    // Not enough to cover this installment fully?
                    // We should still apply what we have!
                    // The original logic stopped if not enough to cover fully?
                    // "if ($totalStack >= $targetAmount)" -> YES, it stopped.
                    // BUT for re-application, maybe we want to apply whatever is available?
                    // Let's stick to the original logic: only apply if we can cover the installment fully OR if it's the last effort?
                    // Actually, usually partial payments are allowed.
                    // But the business rule seems to be: "Don't break a payment into tiny pieces unless it completes a quota".
                    // However, if I have 50 and quota is 100, I should probably pay 50.
                    // The original logic had: "if ($totalStack >= $targetAmount) ... else break".
                    // This implies we ONLY pay if we can pay the FULL pending amount of the installment.
                    // This might be why the user had issues!
                    // If I have 3 payments of 10, and quota is 100. Total 30 < 100. It breaks.
                    // So the money stays unapplied.

                    // User request: "ya tengo todos los abonos con el monto completo para aplicarle a la cuota 3"
                    // So likely they have enough now.
                    // I will keep the logic consistent with the main create method for now.
                    break;
                }
            }

            // Update Credit Status
            $pendingInstallmentsExists = Installment::where('credit_id', $credit->id)
                ->where('status', '<>', 'Pagado')
                ->exists();

            if (!$pendingInstallmentsExists && $credit->remaining_amount <= 0.001) {
                $credit->status = 'Liquidado';
            }
            $credit->save();

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Abonos aplicados correctamente. Total aplicado: $' . number_format($appliedTotal, 2),
                'data' => $credit
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error reapplying payments: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
