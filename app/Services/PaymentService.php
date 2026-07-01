<?php

namespace App\Services;

use App\Exceptions\CashClosedException;
use App\Helpers\Helper;
use App\Services\Traits\EnforcesCashOpen;
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
    use ApiResponse, EnforcesCashOpen;

    private GeolocationHistoryService $geolocationHistoryService;
    private MetricsCacheService $metricsCacheService;

    public function __construct(
        GeolocationHistoryService $geolocationHistoryService,
        MetricsCacheService $metricsCacheService
    ) {
        $this->geolocationHistoryService = $geolocationHistoryService;
        $this->metricsCacheService = $metricsCacheService;
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
            // TRUCO: Creamos un Carbon en UTC con la hora LOCAL para que al guardarse quede "11:05" en DB
            // y al leerse (con AppTimezone Caracas) se interprete como "11:05 Caracas".
            $businessTimestampUtc = Carbon::createFromFormat('Y-m-d H:i:s', $businessNow->format('Y-m-d H:i:s'), 'UTC');
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

            // ======= GUARD: caja del día ya cerrada =======
            // Defensa en profundidad: aun si el middleware liquidation.closed
            // se desregistra, el cache lock falla o el flujo viene por otra
            // vía, este check rechaza pagos cuando la liquidación del día
            // del vendedor está en pending/auto/approved.
            $this->assertSellerCashOpen($credit->seller_id, $businessDate);

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
            // Changed to GLOBAL CREDIT LOCK to prevent race conditions between users/processes
            $lockKey = "payment:create:credit:{$credit->id}";
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
                        // Quién registró el pago (servidor, no falsificable).
                        'created_by' => $user->id,
                        'amount' => $request->amount,
                        'status' => 'No pagado',
                        'payment_method' => $params['payment_method'] ?? null,
                        'payment_reference' => $params['payment_reference'] ?? 'No pagó',
                        'latitude' => $params['latitude'] ?? null,
                        'longitude' => $params['longitude'] ?? null,
                        'address' => $params['address'] ?? null,

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
                        'client_created_at' => $params['client_created_at'] ?? null,
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
                    // Quién registró el pago (servidor, no falsificable).
                    'created_by' => $user->id,
                    'amount' => $request->amount,
                    'status' => $isAbono ? 'Abonado' : 'Pagado',
                    'payment_method' => $params['payment_method'] ?? null,
                    'payment_reference' => $params['payment_reference'] ?? '',
                    'latitude' => $params['latitude'] ?? null,
                    'longitude' => $params['longitude'] ?? null,
                    'address' => $params['address'] ?? null,

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
                    'client_created_at' => $params['client_created_at'] ?? null,
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
                    $stackPayments = Payment::where('credit_id', $credit->id)
                        ->where('unapplied_amount', '>', 0)
                        ->orderBy('business_date', 'asc')
                        ->orderBy('created_at', 'asc')
                        ->get();

                    if ($stackPayments->isEmpty()) break;

                    $amountNeeded = $targetAmount;
                    foreach ($stackPayments as $stackPayment) {
                        if ($amountNeeded <= 0.001) break;

                        $available = $stackPayment->unapplied_amount;
                        $toTake = min($available, $amountNeeded);

                        $this->applyPaymentToInstallment($stackPayment, $installment, $toTake);

                        $amountNeeded -= $toTake;
                    }

                    // If we still need money for this installment, it means we exhausted the stack
                    if ($amountNeeded > 0.001) {
                        break;
                    }
                }

                // Recalcular remaining_amount + status desde la verdad
                // (cuotas), en lugar del delta `-= amount` que se
                // desincronizaba si había un pago previo con unapplied_amount,
                // un payment eliminado mal, o cualquier path inesperado.
                // El método del modelo Credit consolida la lógica.
                $credit->refresh();
                $credit->recalculateRemainingAndStatus();

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

                // Notificar por Telegram si el pago lo CARGA el supervisor
                // (rol 6): deja constancia al administrador. No bloquea si falla.
                try {
                    if ($user && (int) $user->role_id === 6) {
                        app(\App\Services\TelegramService::class)->notifyNewPayment($payment->fresh(), $user);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[telegram] notifyNewPayment falló: ' . $e->getMessage());
                }

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

                // Invalidate Liquidation Cache
                $this->metricsCacheService->invalidateLiquidationMetrics($credit->seller_id, $businessDate);

                // Recalcular liquidaciones para asegurar integridad
                $liquidationService = app(\App\Services\LiquidationService::class);
                $liquidationService->recalculateLiquidation($credit->seller_id, $businessDate);
                $liquidationService->recalculateNextLiquidations($credit->seller_id, $businessDate);

                return $response;

            } finally {
                try {
                    $lock->release();
                } catch (\Throwable $ex) {
                    Log::warning('Failed to release payment creation lock', ['lock_key' => $lockKey, 'error' => $ex->getMessage()]);
                }
            }

        } catch (CashClosedException $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 422);
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

            // Recalcular remaining_amount + status del crédito tras revertir
            // el movimiento. Antes este método actualizaba paid_amount de la
            // cuota y unapplied_amount del pago, pero no tocaba el crédito,
            // dejándolo desincronizado.
            $credit->refresh();
            $credit->recalculateRemainingAndStatus();

            DB::commit();

            // Invalidate Liquidation Cache
            $this->metricsCacheService->invalidateLiquidationMetrics($sellerId, $paymentBusinessDate);

            // Recalcular liquidaciones
            $liquidationService = app(\App\Services\LiquidationService::class);
            $liquidationService->recalculateLiquidation($sellerId, $paymentBusinessDate);
            $liquidationService->recalculateNextLiquidations($sellerId, $paymentBusinessDate);

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
                    'payments.created_by',

                    'payments.amount as total_payment',
                    'payments.unapplied_amount', // Added this field
                    'payments.payment_method',
                    'payments.payment_reference',
                    'payments.status',
                    'payments.business_timezone',
                    'payments.business_timestamp',


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
                    'payments.status',
                    'payments.created_at',
                    'payments.created_by',
                    'payments.unapplied_amount',
                    'payments.business_timezone',
                    'payments.business_timestamp',
                    'payment_images.path'

                )
                ->orderBy('payments.created_at', 'desc');


            if ($request->has('status') && $request->status === 'Abonado') {
                $paymentsQuery->where('payments.status', 'Abonado');
            } elseif ($request->has('status') && $request->status === 'Pagado') {
                $paymentsQuery->where('payments.status', 'Pagado');
            }

            $payments = $paymentsQuery->paginate($perPage, ['*']);

            // Trazabilidad: nombre de quién CARGÓ cada pago (created_by),
            // resuelto en post-proceso para no meter otro JOIN en la consulta
            // agrupada. UX: solo se expone el nombre cuando lo cargó alguien
            // DISTINTO al vendedor (supervisor/admin); si el propio vendedor
            // cargó su pago, no se muestra nada (evita ruido).
            $sellerUserId = \App\Models\Seller::where('id', $credit->seller_id)->value('user_id');
            $creatorIds = collect($payments->items())->pluck('created_by')->filter()->unique()->values();
            $creators = $creatorIds->isNotEmpty()
                ? \App\Models\User::whereIn('id', $creatorIds)->pluck('name', 'id')
                : collect();
            $payments->getCollection()->transform(function ($item) use ($creators, $sellerUserId) {
                $isBySeller = !empty($sellerUserId) && (int) $item->created_by === (int) $sellerUserId;
                $item->created_by_name = (!empty($item->created_by) && !$isBySeller && isset($creators[$item->created_by]))
                    ? $creators[$item->created_by]
                    : null;
                return $item;
            });

            // PAGOS COMPLETOS eliminados (soft-deleted) de este crédito. El
            // query principal usa el scope SoftDeletes (deleted_at IS NULL), por
            // lo que NUNCA incluye un pago que el vendedor/usuario eliminó. Esto
            // se devuelve aparte para listar esas eliminaciones (monto, fecha,
            // quién y cuándo) además de las "cuotas eliminadas" por mantenimiento.
            $deletedRaw = Payment::onlyTrashed()
                ->leftJoin('users as du', 'payments.deleted_by', '=', 'du.id')
                ->where('payments.credit_id', $creditId)
                ->select(
                    'payments.id',
                    'payments.amount',
                    'payments.payment_date',
                    'payments.status',
                    'payments.payment_method',
                    'payments.payment_reference',
                    'payments.business_timestamp',
                    'payments.business_timezone',
                    'payments.created_at',
                    'payments.deleted_at',
                    'payments.deleted_by',
                    'du.name as deleted_by_name'
                )
                ->orderBy('payments.deleted_at', 'desc')
                ->get();

            // Cuotas (payment_installments, también soft-deleted) que tenía
            // aplicado cada pago eliminado, para mostrar su distribución.
            $deletedQuotas = collect();
            if ($deletedRaw->isNotEmpty()) {
                $deletedQuotas = PaymentInstallment::withTrashed()
                    ->whereIn('payment_installments.payment_id', $deletedRaw->pluck('id'))
                    ->leftJoin('installments', 'payment_installments.installment_id', '=', 'installments.id')
                    ->select(
                        'payment_installments.payment_id',
                        'payment_installments.applied_amount',
                        'installments.quota_number',
                        'installments.quota_amount'
                    )
                    ->orderBy('installments.quota_number')
                    ->get()
                    ->groupBy('payment_id');
            }

            $deletedPayments = $deletedRaw->map(function ($p) use ($deletedQuotas) {
                return [
                    'id'                => $p->id,
                    'amount'            => $p->amount,
                    'payment_date'      => $p->payment_date,
                    'status'            => $p->status,
                    'payment_method'    => $p->payment_method,
                    'payment_reference' => $p->payment_reference,
                    'business_timestamp' => $p->business_timestamp,
                    'business_timezone' => $p->business_timezone,
                    'created_at'        => $p->created_at,
                    'deleted_at'        => $p->deleted_at,
                    'deleted_by'        => $p->deleted_by,
                    'deleted_by_name'   => $p->deleted_by_name,
                    'quotas'            => ($deletedQuotas[$p->id] ?? collect())
                        ->map(fn($q) => [
                            'quota_number'   => $q->quota_number,
                            'applied_amount' => $q->applied_amount,
                            'quota_amount'   => $q->quota_amount,
                        ])->values(),
                ];
            });

            return $this->successResponse([
                'success' => true,
                'message' => 'Pagos obtenidos correctamente',
                'data' => $payments,
                'deleted_payments' => $deletedPayments,
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
                    'payments.business_timezone',
                    'payments.business_timestamp',
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
                    'payments.status',
                    'payments.created_at',
                    'payments.business_timezone',
                    'payments.business_timestamp',
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
        $includeDeleted = $request->has('include_deleted') && $request->include_deleted === 'true';
        $flatStructure = $request->has('flat') && $request->flat === 'true';

        // 1. Filtra los pagos por fecha, estado y seller
        $paymentsFilterQuery = Payment::query();
        
        // Incluir pagos eliminados si se solicita
        if ($includeDeleted) {
            $paymentsFilterQuery->onlyTrashed();
        }

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

        // --- Estado del crédito A LA FECHA filtrada (solo presentación) ---
        // La columna `credits.status` es un único valor mutable: refleja el
        // estado de HOY, no el del día del pago. Por eso, al filtrar pagos de
        // una fecha pasada, un crédito liquidado DESPUÉS se ve "Liquidado" en
        // esa fecha (cuando en realidad estaba Activo). Esto NO altera datos ni
        // lógica: solo se calcula un campo derivado `status_as_of_date`.
        //
        // Regla acotada (Fase A): si el crédito está Liquidado pero su fecha de
        // liquidación real (último pago vivo que lo finiquitó) es POSTERIOR a la
        // fecha filtrada, entonces a esa fecha todavía estaba VIGENTE. Se
        // devuelve el CÓDIGO 'Vigente' (no la etiqueta "Activo"): el front lo
        // traduce a "Activo" y lo pinta en verde vía getStatusText/Color. Los
        // demás estados se dejan tal cual (no son derivables desde pagos).
        $asOfDate = $request->has('end_date')
            ? Carbon::parse($request->get('end_date'), $timezone)->toDateString()
            : ($request->has('date')
                ? Carbon::parse($request->get('date'), $timezone)->toDateString()
                : Carbon::now($timezone)->toDateString());

        $liquidatedCreditIds = $credits->where('status', 'Liquidado')->pluck('id');
        $liquidationDates = collect();
        if ($liquidatedCreditIds->isNotEmpty()) {
            $liquidationDates = DB::table('payments')
                ->whereIn('credit_id', $liquidatedCreditIds)
                ->whereNull('deleted_at')
                ->where('status', 'Pagado')
                ->where('amount', '>', 0)
                ->groupBy('credit_id')
                ->selectRaw('credit_id, MAX(business_date) as d')
                ->pluck('d', 'credit_id');
        }

        $statusAsOfFor = function ($credit) use ($liquidationDates, $asOfDate) {
            if ($credit->status === 'Liquidado') {
                $ld = $liquidationDates[$credit->id] ?? null;
                if ($ld && $ld > $asOfDate) {
                    return 'Vigente';
                }
            }
            return $credit->status;
        };

        // 5. Traer TODOS los pagos requeridos para estos créditos en UNA sola consulta
        $paymentsQuery = Payment::whereIn('credit_id', $pageCreditIds)
            ->orderBy('created_at', 'desc');
        
        // Incluir pagos eliminados si se solicita
        if ($includeDeleted) {
            $paymentsQuery->onlyTrashed();
        }

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

        // 8. Construir respuesta según el formato solicitado
        if ($flatStructure) {
            // ESTRUCTURA PLANA: Lista de pagos individuales
            $flatPayments = collect();

            foreach ($credits as $credit) {
                $creditPayments = $paymentsByCredit->get($credit->id, collect());
                $creditStatusAsOf = $statusAsOfFor($credit);

                foreach ($creditPayments as $payment) {
                    $paymentInstallments = $installmentsDetails->get($payment->id, collect());
                    $quotaNumbers = $paymentInstallments->pluck('quota_number')->sort()->values()->toArray();

                    $flatPayments->push([
                        'payment_id' => $payment->id,
                        'credit_id' => $credit->id,
                        'client_id' => $credit->client->id,
                        'client_name' => $credit->client->name,
                        'client_dni' => $credit->client->dni,
                        'client' => $credit->client, // Incluir objeto completo
                        'credit_info' => $credit->loadMissing(['installments', 'payments']), // Cargar relaciones necesarias para el historial
                        'credit_status_as_of_date' => $creditStatusAsOf, // Estado del crédito a la fecha filtrada (display)
                        'payment_date' => $payment->created_at, // Usar created_at para fecha y hora exacta
                        'business_date' => $payment->business_date,
                        'amount' => $payment->amount,
                        'status' => $payment->status,
                        'business_timezone' => $payment->business_timezone,
                        'business_timestamp' => $payment->business_timestamp,
                        'payment_method' => $payment->payment_method,
                        'payment_reference' => $payment->payment_reference,
                        'quota_numbers' => $quotaNumbers,
                        'quota_numbers_text' => !empty($quotaNumbers) ? implode(', ', $quotaNumbers) : 'N/A',
                        'installments_details' => $paymentInstallments->values(),
                        'total_applied' => $paymentInstallments->sum('paid_amount'),
                        'created_at' => $payment->created_at,
                        'deleted_at' => $payment->deleted_at,
                        'is_deleted' => $includeDeleted,
                    ]);
                }
            }

            // Ordenar por fecha de pago descendente
            $flatPayments = $flatPayments->sortByDesc('payment_date')->values();

            return $this->successResponse([
                'success' => true,
                'message' => 'Pagos obtenidos correctamente',
                'data' => [
                    'payments' => $flatPayments,
                    'pagination' => [
                        'total' => $creditsPaginator->total(),
                        'per_page' => $creditsPaginator->perPage(),
                        'current_page' => $creditsPaginator->currentPage(),
                        'last_page' => $creditsPaginator->lastPage(),
                    ],
                    'total_payments_amount' => $totalPaymentsAmount,
                    'include_deleted' => $includeDeleted
                ]
            ]);
        }

        // ESTRUCTURA AGRUPADA (comportamiento original)
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
                // Estado vigente A LA FECHA filtrada (solo display). Igual a
                // `status` salvo cuando un crédito Liquidado aún no lo estaba
                // en esa fecha → 'Activo'. El front pinta este campo.
                'status_as_of_date' => $statusAsOfFor($credit),
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

        $timezone = $seller->city->country->timezone ?? 'America/Lima';
        $flatStructure = $request->get('flat', 'false') === 'true';
        $includeDeleted = $request->get('include_deleted', 'false') === 'true';
        
        $paymentsQuery = Payment::query();
        
        if ($includeDeleted) {
            $paymentsQuery->withTrashed();
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $startDateStr = $request->get('start_date');
            $endDateStr = $request->get('end_date');
            
            $startRange = Carbon::parse($startDateStr, $timezone)->startOfDay()->utc();
            $endRange = Carbon::parse($endDateStr, $timezone)->endOfDay()->utc();

            $paymentsQuery->where(function($q) use ($startDateStr, $endDateStr, $startRange, $endRange) {
                $q->whereBetween('business_date', [$startDateStr, $endDateStr])
                  ->orWhere(function($q2) use ($startRange, $endRange) {
                      $q2->whereNull('business_date')
                         ->whereBetween('created_at', [$startRange, $endRange]);
                  });
            });
        } elseif ($request->has('date')) {
            $filterDate = $request->get('date');
            $startRange = Carbon::parse($filterDate, $timezone)->startOfDay()->utc();
            $endRange = Carbon::parse($filterDate, $timezone)->endOfDay()->utc();

            $paymentsQuery->where(function($q) use ($filterDate, $startRange, $endRange) {
                $q->where('business_date', $filterDate)
                  ->orWhere(function($q2) use ($startRange, $endRange) {
                      $q2->whereNull('business_date')
                         ->whereBetween('created_at', [$startRange, $endRange]);
                  });
            });
        } else {
            $nowInTz = Carbon::now($timezone);
            $filterDate = $nowInTz->toDateString();
            $startRange = $nowInTz->copy()->startOfDay()->utc();
            $endRange = $nowInTz->copy()->endOfDay()->utc();

            $paymentsQuery->where(function($q) use ($filterDate, $startRange, $endRange) {
                $q->where('business_date', $filterDate)
                  ->orWhere(function($q2) use ($startRange, $endRange) {
                      $q2->whereNull('business_date')
                         ->whereBetween('created_at', [$startRange, $endRange]);
                  });
            });
        }

        // Solo pagos de créditos del seller
        $paymentsQuery->whereHas('credit', function ($q) use ($sellerId, $includeDeleted) {
            if ($includeDeleted) {
                $q->withTrashed();
            }
            $q->where('seller_id', $sellerId);
        });

        // Cargar relaciones para el crédito. El cliente trae withCount de
        // comentarios para el distintivo (bitácora) en la lista de pagos.
        $paymentsQuery->with([
            'credit' => function($q) {
                $q->withTrashed()->with([
                    'client' => function ($cq) {
                        $cq->withCount('comments');
                    },
                    'installments',
                    'payments',
                ]);
            },
            // Trazabilidad: quién cargó / quién eliminó el pago (p. ej. supervisor).
            'createdByUser:id,name',
            'deletedByUser:id,name',
        ]);

        $payments = $paymentsQuery->orderBy('created_at', 'desc')->get();

        // UX: exponer "cargado por" SOLO cuando el pago lo cargó alguien
        // distinto al vendedor (supervisor). Si lo cargó el propio vendedor,
        // se oculta el creador para no ensuciar la vista.
        $sellerUserId = (int) ($seller->user_id ?? 0);
        $payments->each(function ($p) use ($sellerUserId) {
            if (!empty($p->created_by) && (int) $p->created_by === $sellerUserId) {
                $p->setRelation('createdByUser', null);
            }
        });

        if ($flatStructure) {
            $flatPayments = $payments->map(function ($payment) use ($includeDeleted) {
                $credit = $payment->credit;
                $paymentInstallments = DB::table('payment_installments')
                    ->join('installments', 'payment_installments.installment_id', '=', 'installments.id')
                    ->where('payment_installments.payment_id', $payment->id)
                    ->select('installments.quota_number')
                    ->get();
                
                $quotaNumbers = $paymentInstallments->pluck('quota_number')->sort()->values()->toArray();

                return [
                    'payment_id' => $payment->id,
                    'credit_id' => $credit->id ?? null,
                    'client_id' => $credit->client->id ?? null,
                    'client_name' => $credit->client->name ?? 'N/A',
                    'client_dni' => $credit->client->dni ?? 'N/A',
                    'client' => $credit->client ?? null,
                    'client_comments_count' => optional($credit->client)->comments_count ?? 0,
                    'credit_info' => $credit, // Objeto completo con relaciones cargadas
                    'payment_date' => $payment->created_at, // Exact time
                    'business_date' => $payment->business_date,
                    'amount' => $payment->amount,
                    'status' => $payment->status,
                    'business_timezone' => $payment->business_timezone,
                    'business_timestamp' => $payment->business_timestamp,
                    'payment_method' => $payment->payment_method,
                    'payment_reference' => $payment->payment_reference,
                    'quota_numbers' => $quotaNumbers,
                    'quota_numbers_text' => !empty($quotaNumbers) ? implode(', ', $quotaNumbers) : 'N/A',
                    'created_at' => $payment->created_at,
                    'deleted_at' => $payment->deleted_at,
                    'is_deleted' => $includeDeleted,
                    // Trazabilidad de quién cargó / eliminó el pago.
                    'created_by' => $payment->created_by,
                    'created_by_user' => $payment->createdByUser,
                    'deleted_by' => $payment->deleted_by,
                    'deleted_by_user' => $payment->deletedByUser,
                ];
            });

            return $this->successResponse([
                'success' => true,
                'message' => 'Pagos obtenidos correctamente (flat)',
                'data' => [
                    'payments' => $flatPayments
                ]
            ]);
        }

        return $this->successResponse([
            'success' => true,
            'message' => 'Pagos obtenidos correctamente',
            'data' => $payments
        ]);
    }

    public function getPaymentsByDate($date, Request $request, $sellerId = null)
    {
        $query = Payment::with([
            'credit:id,client_id,credit_value,status',
            'credit.client:id,name,dni,address'
        ])
            ->where('business_date', $date);

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
            $paymentDate = Carbon::parse($payment->business_date);

            if ($paymentDate->format('Y-m-d') !== $today->format('Y-m-d')) {
                throw new \Exception('Solo se pueden eliminar pagos creados el día de hoy.');
            }

            // Verificar si existe una liquidación para el vendedor en la fecha del pago
            $credit = $payment->credit;
            $sellerId = $credit->client->seller_id;
            $businessDate = $payment->business_date;

            $liquidation = Liquidation::where('seller_id', $sellerId)
                ->whereDate('date', '=', $businessDate)
                ->first();

            $user = Auth::user();
            if ($liquidation && $liquidation->status === 'cerrada' && !$user->can('ajustar_caja') && $user->role_id !== 1) {
                throw new \Exception('No se puede eliminar el pago. El vendedor ya tiene una liquidación cerrada para el día de hoy y usted no tiene permisos para ajustar caja.');
            }

            // ... (rest of simple validations as before)

            // Revertir los montos aplicados a las cuotas
            foreach ($payment->installments as $paymentInstallment) {
                $installment = $paymentInstallment->installment;

                if ($installment) {
                    $installment->paid_amount = max(0, $installment->paid_amount - $paymentInstallment->applied_amount);

                    if ($installment->paid_amount <= 0) {
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

            // Recalcular remaining_amount + status desde las cuotas. Si el
            // pago eliminado dejaba el crédito en Liquidado y ahora hay
            // cuotas pendientes, el helper revierte a 'Vigente'.
            $credit->refresh();
            $credit->recalculateRemainingAndStatus();

            // Si hay liquidación, agregar observación
            if ($liquidation) {
                $userName = $user->name;
                $amountFormated = number_format($payment->amount, 2);
                $newObservation = "Eliminación de pago #{$payment->id} por \${$amountFormated} realizada por {$userName} el " . now()->toDateTimeString();
                $liquidation->observation = $liquidation->observation 
                    ? $liquidation->observation . "\n" . $newObservation 
                    : $newObservation;
                $liquidation->save();
            }

            // Registrar QUIÉN elimina (servidor) antes del soft-delete.
            // Auth::id() puede ser null en procesos sin sesión → columna nullable.
            $payment->deleted_by = Auth::id();
            $payment->save();

            // Eliminar el pago
            $payment->delete();

            // Recalcular liquidaciones
            $liquidationService = app(LiquidationService::class);
            $liquidationService->recalculateLiquidation($sellerId, $businessDate);
            $liquidationService->recalculateNextLiquidations($sellerId, $businessDate);

            DB::commit();

            // Notificar por Telegram si el pago lo ELIMINA el supervisor
            // (rol 6): deja constancia al administrador. No bloquea si falla.
            try {
                if ($user && (int) $user->role_id === 6) {
                    app(\App\Services\TelegramService::class)->notifyDeletedPayment($payment, $user);
                }
            } catch (\Throwable $e) {
                Log::warning('[telegram] notifyDeletedPayment falló: ' . $e->getMessage());
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Pago eliminado y liquidaciones recalculadas correctamente',
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
            'created_at' => $payment->business_timestamp ?? $payment->created_at,
            'updated_at' => now()
        ]);

        // 2. Update Installment
        $installment->paid_amount += $amount;

        // Check if fully paid (allowing for small float diffs)
        if ($installment->quota_amount - $installment->paid_amount <= 0.001) {
            $installment->status = 'Pagado';
        } else {
            // Updated to 'Parcial' when some payment is applied but not full
            $installment->status = 'Parcial';
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
                ->orderBy('payment_date', 'asc')
                ->orderBy('created_at', 'asc')
                ->get();

            if ($stackPayments->isEmpty()) {
                DB::commit();
                return $this->successResponse(['success' => true, 'message' => 'No hay abonos para aplicar.', 'applied_total' => 0]);
            }

            // 2. Get all pending installments
            $installments = Installment::where('credit_id', $creditId)
                ->whereIn('status', ['Pendiente', 'Parcial', 'Atrasado'])
                ->orderBy('due_date', 'asc')
                ->get();

            if ($installments->isEmpty()) {
                DB::commit();
                return $this->successResponse(['success' => true, 'message' => 'No hay cuotas pendientes.', 'applied_total' => 0, 'installments_covered' => 0]);
            }

            $appliedTotal = 0;
            $installmentsCovered = 0;

            foreach ($installments as $installment) {
                $quotaAmount = (float) $installment->quota_amount;
                $alreadyPaid = (float) $installment->paid_amount;
                $targetAmount = round($quotaAmount - $alreadyPaid, 2);

                if ($targetAmount <= 0.001) {
                    if ($installment->status !== 'Pagado') {
                        $installment->status = 'Pagado';
                        $installment->save();
                        $installmentsCovered++;
                    }
                    continue;
                }

                // Check total stack available BEFORE applying anything to THIS installment
                $totalStack = $stackPayments->sum('unapplied_amount');
                if ($totalStack < ($targetAmount - 0.001)) {
                    // Not enough money to fill THIS quota.
                    break;
                }

                $amountNeeded = $targetAmount;

                foreach ($stackPayments as $stackPayment) {
                    if ($amountNeeded <= 0.001)
                        break;

                    if ($stackPayment->unapplied_amount <= 0.001)
                        continue;

                    $available = $stackPayment->unapplied_amount;
                    $toTake = min($available, $amountNeeded);

                    $this->applyPaymentToInstallment($stackPayment, $installment, $toTake);

                    $amountNeeded -= $toTake;
                    $appliedTotal += $toTake;
                }

                if ($amountNeeded <= 0.001) {
                    $installmentsCovered++;
                }
            }

            // Recalcular remaining_amount + status desde las cuotas. Antes
            // este método NO actualizaba remaining_amount, así que créditos
            // que terminaban de cubrirse aplicando abonos previos quedaban
            // en 'Vigente' aunque todas las cuotas estuvieran 'Pagado'.
            $credit->refresh();
            $credit->recalculateRemainingAndStatus();

            $remainingUnapplied = Payment::where('credit_id', $creditId)->sum('unapplied_amount');

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Abonos aplicados correctamente.',
                'applied_total' => $appliedTotal,
                'installments_covered' => $installmentsCovered,
                'remaining_unapplied' => $remainingUnapplied,
                'data' => $credit
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error reapplying payments: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
