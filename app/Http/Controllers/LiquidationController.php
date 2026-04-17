<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\Income;
use App\Models\LiquidationAudit;
use App\Services\LiquidationService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Liquidation;
use App\Models\Expense;
use App\Models\Credit;
use App\Models\Payment;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use App\Notifications\GeneralNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\Liquidation\CalculateLiquidationRequest;
use App\Http\Requests\Liquidation\StoreLiquidationRequest;
use App\Http\Requests\Liquidation\UpdateLiquidationRequest;
use App\Http\Requests\Liquidation\ReopenRouteRequest;
use App\Http\Requests\Liquidation\LiquidationHistoryRequest;
use App\Exports\LiquidationExport;

class LiquidationController extends Controller
{
    use ApiResponse;
    protected $liquidationService;
    protected $metricsCacheService;

    public function __construct(
        LiquidationService $liquidationService,
        \App\Services\MetricsCacheService $metricsCacheService
    ) {
        $this->liquidationService = $liquidationService;
        $this->metricsCacheService = $metricsCacheService;
    }
    public function calculateLiquidation(CalculateLiquidationRequest $request)
    {
        $user = Auth::user();
        $sellerId = $request->seller_id;
        $date = $request->date;

        $timezone = 'America/Lima';
        $dateLocal = Carbon::parse($date, $timezone)->format('Y-m-d');

        // Verificar permisos
        if (!$this->checkAuthorization($user, $sellerId)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Obtener datos para la liquidación
        $data = $this->getLiquidationData($sellerId, $dateLocal, $request);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function storeLiquidation(StoreLiquidationRequest $request)
    {
        $user = Auth::user();

        // ✅ MULTI-PAÍS: Obtener configuración del país del vendedor
        $seller = Seller::with('city.country')->find($request->seller_id);
        $country = $seller->city->country ?? null;
        $currency = $country->currency ?? 'PEN'; // Fallback a PEN si no hay país
        $timezone = $country->timezone ?? config('app.timezone'); // Fallback a config

        Log::info('Liquidation creation started', [
            'user_id' => $user->id,
            'seller_id' => $request->seller_id,
            'date' => $request->date,
            'country' => $country?->name ?? 'Unknown',
            'currency' => $currency,
            'timezone' => $timezone,
        ]);

        try {
            $todayDate = Carbon::now($timezone)->toDateString();

            // Verificar si ya existe liquidación para este día (cualquier estado)
            $existingLiquidation = Liquidation::where('seller_id', $request->seller_id)
                ->whereDate('date', $request->date)
                ->first();

            if ($existingLiquidation && $existingLiquidation->status === 'approved' && $user->role_id !== 1) {
                Log::warning('Duplicate approved liquidation attempt blocked', [
                    'seller_id' => $request->seller_id,
                    'date' => $request->date,
                    'existing_id' => $existingLiquidation->id,
                    'country' => $country?->name,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe una liquidación aprobada para este vendedor en la fecha seleccionada'
                ], 422);
            }

            // Obtener el user_id del vendedor
            $sellerForCheck = Seller::find($request->seller_id);
            $sellerUserId = $sellerForCheck ? $sellerForCheck->user_id : $user->id;

            $pendingExpenses = Expense::where('user_id', $sellerUserId)
                ->whereDate('created_at', $request->date)
                ->where('status', 'Pendiente')
                ->where('description', 'not like', '%AJUSTE%')
                ->exists();

            if ($pendingExpenses) {
                Log::warning('Liquidation blocked due to pending expenses', [
                    'seller_id' => $request->seller_id,
                    'date' => $request->date,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede liquidar porque tienes gastos pendientes de aprobación en la fecha seleccionada'
                ], 422);
            }

            // === TRANSACCIÓN ATÓMICA: Todo o nada ===
            $liquidation = DB::transaction(function () use ($request, $user, $timezone, $currency, $existingLiquidation) {
                // === Calcular créditos irrecuperables ===
                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->where('credits.seller_id', $request->seller_id)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereDate('credits.updated_at', $request->date)
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');

                // === Calcular renovaciones desembolsadas ===
                $startUTC = Carbon::parse($request->date, $timezone)->startOfDay()->setTimezone('UTC');
                $endUTC = Carbon::parse($request->date, $timezone)->endOfDay()->setTimezone('UTC');

                $renewalCredits = Credit::where('seller_id', $request->seller_id)
                    ->whereBetween('created_at', [$startUTC, $endUTC])
                    ->whereNotNull('renewed_from_id')
                    ->get();

                $total_renewal_disbursed = 0;
                foreach ($renewalCredits as $renewCredit) {
                    $oldCredit = Credit::find($renewCredit->renewed_from_id);
                    $pendingAmount = 0;
                    if ($oldCredit) {
                        $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                        $oldCreditPaid = Payment::where('credit_id', $oldCredit->id)->sum('amount');
                        $pendingAmount = $oldCreditTotal - $oldCreditPaid;
                    }
                    $netDisbursement = $renewCredit->credit_value - $pendingAmount;
                    $total_renewal_disbursed += $netDisbursement;
                }

                // === Calcular valor de póliza ===
                $poliza = Credit::where('seller_id', $request->seller_id)
                    ->whereDate('created_at', $request->date)
                    ->sum(DB::raw('micro_insurance_percentage * credit_value / 100'));

                // === Calcular real_to_deliver incluyendo los nuevos campos ===
                $realToDeliver = $request->initial_cash +
                    $request->base_delivered +
                    ($request->total_income + $request->total_collected + $poliza) -
                    ($request->total_expenses + $request->new_credits + $irrecoverableCredits + $total_renewal_disbursed);

                $shortage = 0;
                $surplus = 0;
                $pendingDebt = 0;

                if ($realToDeliver > 0) {
                    if ($request->cash_delivered < $realToDeliver) {
                        $shortage = $realToDeliver - $request->cash_delivered;
                    } else {
                        $surplus = $request->cash_delivered - $realToDeliver;
                    }
                } else {
                    $debtAmount = abs($realToDeliver);

                    if ($request->cash_delivered > $debtAmount) {
                        $surplus = $request->cash_delivered - $debtAmount;
                        $pendingDebt = 0;
                    } else {
                        $pendingDebt = $debtAmount - $request->cash_delivered;
                        $shortage = $pendingDebt;
                    }
                }

                $liquidationData = [
                    'date' => $request->date,
                    'seller_id' => $request->seller_id,
                    'currency' => $currency, // ✅ Moneda del país
                    'collection_target' => $request->collection_target,
                    'initial_cash' => $request->initial_cash,
                    'base_delivered' => $request->base_delivered,
                    'total_collected' => $request->total_collected,
                    'total_expenses' => $request->total_expenses,
                    'total_income' => $request->total_income,
                    'new_credits' => $request->new_credits,
                    'real_to_deliver' => $realToDeliver,
                    'shortage' => $shortage,
                    'surplus' => $surplus,
                    'path' => $request->path,
                    'cash_delivered' => $request->cash_delivered,
                    'status' => 'pending',
                    'irrecoverable_credits_amount' => $irrecoverableCredits,
                    'renewal_disbursed_total' => $total_renewal_disbursed,
                    'poliza' => $poliza,
                ];

                if ($request->has('created_at')) {
                    $liquidationData['created_at'] = $request->created_at;
                } else {
                    if ($request->filled('timezone')) {
                        $liquidationData['created_at'] = Carbon::now();
                        $liquidationData['updated_at'] = Carbon::now();
                    }
                }

                if ($request->has('path')) {
                    $imageFile = $request->file('path');
                    $imagePath = Helper::uploadFile($imageFile, 'liquidations');
                    $liquidationData['path'] = $imagePath;
                }

                // Crear o Actualizar liquidación (dentro de transacción)
                if ($existingLiquidation) {
                    $existingLiquidation->update($liquidationData);
                    $liquidation = $existingLiquidation;
                    $action = 'updated';
                } else {
                    $liquidation = Liquidation::create($liquidationData);
                    $action = 'created';
                }

                // Crear auditoría (dentro de transacción)
                LiquidationAudit::create([
                    'liquidation_id' => $liquidation->id,
                    'user_id' => $user->id,
                    'action' => $action,
                    'changes' => json_encode($liquidation->toArray()),
                    'created_at' => Carbon::now($timezone),
                ]);

                Log::info("Liquidation $action successfully", [
                    'liquidation_id' => $liquidation->id,
                    'seller_id' => $request->seller_id,
                    'currency' => $currency,
                    'real_to_deliver' => $realToDeliver,
                    'shortage' => $shortage,
                    'surplus' => $surplus,
                ]);

                // Retornar datos para notificaciones (fuera de transacción)
                return [
                    'liquidation' => $liquidation,
                    'shortage' => $shortage,
                    'surplus' => $surplus,
                ];
            });

            // === NOTIFICACIONES (Fuera de transacción, async) ===
            $this->sendLiquidationNotifications(
                $liquidation['liquidation'],
                $liquidation['shortage'],
                $liquidation['surplus'],
                $request->seller_id,
                $request->date,
                $user
            );

            return response()->json([
                'success' => true,
                'data' => $liquidation['liquidation'],
                'message' => 'Liquidación guardada correctamente'
            ]);

        } catch (\Exception $e) {
            Log::error('Liquidation creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->id,
                'seller_id' => $request->seller_id,
                'date' => $request->date,
                'request_data' => $request->except(['path']), // Excluir archivo
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la liquidación. El equipo técnico ha sido notificado.',
                'error_id' => \Illuminate\Support\Str::uuid(),
            ], 500);
        }
    }

    /**
     * Envía notificaciones de liquidación (método auxiliar)
     */
    private function sendLiquidationNotifications($liquidation, $shortage, $surplus, $sellerId, $date, $user)
    {
        try {
            // Notificación de sobrante/faltante si está activo en SellerConfig
            $sellerConfig = \App\Models\SellerConfig::where('seller_id', $sellerId)->first();
            
            if ($sellerConfig && $sellerConfig->notify_shortage_surplus) {
                $seller = Seller::with(['user', 'company'])->find($sellerId);
                $companyId = $seller->company_id;

                // Filtrar administradores: SuperAdmin (1) ve todo, Admin (2) solo su empresa
                $admins = \App\Models\User::where(function($query) use ($companyId) {
                    $query->where('role_id', 1)
                          ->orWhere(function($q) use ($companyId) {
                              $q->where('role_id', 2)
                                ->whereHas('company', function($c) use ($companyId) {
                                    $c->where('id', $companyId);
                                });
                          });
                })->get();

                $userToNotify = $seller->user;
                
                if ($shortage > 0) {
                    $message = 'Alerta: El vendedor ' . $seller->user->name . ' tiene un faltante de $' . number_format($shortage, 2) . ' en la liquidación del ' . $date . '.';
                    $link = '/dashboard/liquidaciones/' . $liquidation->id;
                    $data = [
                        'liquidation_id' => $liquidation->id,
                        'seller_id' => $seller->id,
                        'shortage' => $shortage,
                        'date' => $date,
                    ];
                    $userToNotify->notify(new \App\Notifications\GeneralNotification('Alerta de faltante en liquidación', $message, $link, $data));
                    foreach ($admins as $admin) {
                        $admin->notify(new \App\Notifications\GeneralNotification('Alerta de faltante en liquidación', $message, $link, $data));
                    }
                }
                
                if ($surplus > 0) {
                    $message = 'Alerta: El vendedor ' . $seller->user->name . ' tiene un sobrante de $' . number_format($surplus, 2) . ' en la liquidación del ' . $date . '.';
                    $link = '/dashboard/liquidaciones/' . $liquidation->id;
                    $data = [
                        'liquidation_id' => $liquidation->id,
                        'seller_id' => $seller->id,
                        'surplus' => $surplus,
                        'date' => $date,
                    ];
                    $userToNotify->notify(new \App\Notifications\GeneralNotification('Alerta de sobrante en liquidación', $message, $link, $data));
                    foreach ($admins as $admin) {
                        $admin->notify(new \App\Notifications\GeneralNotification('Alerta de sobrante en liquidación', $message, $link, $data));
                    }
                }
            }

            if ($user->role_id === 5) {
                $seller = Seller::with(['user', 'city.country', 'company'])->find($sellerId);
                $companyId = $seller->company_id;

                // Filtrar administradores destinatarios
                $adminUsers = User::where(function($query) use ($companyId) {
                    $query->where('role_id', 1)
                          ->orWhere(function($q) use ($companyId) {
                              $q->where('role_id', 2)
                                ->whereHas('company', function($c) use ($companyId) {
                                    $c->where('id', $companyId);
                                });
                          });
                })->get();

                foreach ($adminUsers as $adminUser) {
                    $adminUser->notify(new GeneralNotification(
                        'Solicitud de liquidación ',
                        'El vendedor ' . $seller->user->name . ' de la ruta ' . $seller->city->country->name . ',' . $seller->city->name . ' ha creado una nueva liquidación para la fecha ' . $date,
                        '/dashboard/liquidaciones',
                        [
                            'country_id' => $seller->city->country->id,
                            'city_id' => $seller->city->id,
                            'seller_id' => $seller->id,
                            'date' => $date,
                        ]
                    ));
                }
            }

            Log::info('Liquidation notifications sent', [
                'liquidation_id' => $liquidation->id,
            ]);

        } catch (\Exception $e) {
            // No fallar si las notificaciones fallan
            Log::error('Liquidation notifications failed', [
                'error' => $e->getMessage(),
                'liquidation_id' => $liquidation->id,
            ]);
        }
    }

    public function updateLiquidation(UpdateLiquidationRequest $request, $id)
    {
        $user = Auth::user();
        $timezone = $request->input('timezone', 'America/Lima');
        
        // Resolver modelo explícitamente para coincidir con la firma del servicio
        $liquidation = $id instanceof Liquidation ? $id : Liquidation::findOrFail($id);

        $data = $request->all();
        
        // Asegurar que observation esté presente si se envía
        if ($request->has('observation')) {
            $data['observation'] = $request->input('observation');
        }

        // Manejar subida de archivo (captura) si existe
        if ($request->hasFile('path')) {
            $imageFile = $request->file('path');
            $imagePath = Helper::uploadFile($imageFile, 'liquidations');
            $data['path'] = $imagePath;
            // Respaldar también en capture_path por si se usa ese campo
            $data['capture_path'] = $imagePath;
        }

        // Llamar al servicio pasando el objeto Liquidation y el array de datos
        // NOTA: El servicio espera (Liquidation $liquidation, array $data)
        // Eliminamos $user y $timezone de los argumentos si el servicio no los pide en esa firma
        // Revisando LiquidationService::updateLiquidation(Liquidation $liquidation, array $data)
        
        try {
            $resultLiquidation = $this->liquidationService->updateLiquidation($liquidation, $data);
            
            return response()->json([
                'success' => true,
                'data' => $resultLiquidation,
                'message' => 'Liquidación actualizada correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating liquidation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la liquidación: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reopenRoute(ReopenRouteRequest $request)
    {
        $result = $this->liquidationService->reopenRoute($request->seller_id, $request->date, $request);
        return response()->json($result);
    }

    // Tu función de recalculo debe estar en el mismo controlador:
    public function recalculateLiquidation($sellerId, $date)
    {
        // Obtener timezone del vendedor dinámicamente
        $seller = Seller::with('city.country')->find($sellerId);
        $country = $seller?->city?->country ?? null;
        $timezone = $country?->timezone ?? config('app.timezone', 'America/Lima');
        
        $startUTC = Carbon::parse($date, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC = Carbon::parse($date, $timezone)->endOfDay()->setTimezone('UTC');

        // Busca la liquidación del vendedor en esa fecha
        $liquidation = Liquidation::where('seller_id', $sellerId)
            ->whereBetween('date', [$startUTC, $endUTC])
            ->first();

        if (!$liquidation)
            return;

        // 1. Obtener el user_id del vendedor
        $seller = Seller::find($sellerId);
        $userId = $seller ? $seller->user_id : null;

        // 2. Recalcula los totales actuales desde la BD
        $totalExpenses = $userId
            ? Expense::where('user_id', $userId)
                ->whereBetween('created_at', [$startUTC, $endUTC])
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->where('status', 'Aprobado')
                        ->orWhere('description', 'like', '%AJUSTE%');
                })
                ->sum('value')
            : 0;

        $totalIncome = $userId
            ? Income::where('user_id', $userId)
                ->whereBetween('created_at', [$startUTC, $endUTC])
                ->whereNull('deleted_at')
                ->sum('value')
            : 0;

        $newCredits = Credit::where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNull('renewed_from_id')
            ->whereNull('deleted_at') // ✅ FIX: Excluir eliminados
            ->whereNull('unification_reason') // ✅ FIX: Excluir unificados
            ->sum('credit_value');

        $totalCollected = Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->whereNull('payments.deleted_at')
            ->where('payments.business_date', $date)
            ->whereIn('payments.status', ['Pagado', 'Aprobado', 'Abonado'])
            ->sum('payments.amount');

        $renewalCredits = \App\Models\Credit::where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNotNull('renewed_from_id')
            ->whereNull('deleted_at')
            ->get();

        $total_renewal_disbursed = 0;
        $total_pending_absorbed = 0;

        foreach ($renewalCredits as $renewCredit) {
            $oldCredit = \App\Models\Credit::where('id', $renewCredit->renewed_from_id)->first();

            $pendingAmount = 0;
            if ($oldCredit) {
                $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                $oldCreditPaid = Payment::where('credit_id', $oldCredit->id)
                    ->whereNull('deleted_at')
                    ->sum('amount');
                $pendingAmount = $oldCreditTotal - $oldCreditPaid;
                $total_pending_absorbed += $pendingAmount;
            }

            $netDisbursement = $renewCredit->credit_value - $pendingAmount;
            $total_renewal_disbursed += $netDisbursement;
        }

        $poliza = \App\Models\Credit::where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNull('deleted_at') // ✅ FIX: Excluir eliminados
            ->whereNull('unification_reason') // ✅ FIX: Excluir unificados
            ->sum(DB::raw('micro_insurance_percentage * credit_value / 100'));

        // === Calcular créditos irrecuperables ===
        $irrecoverableCredits = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('credits.status', 'Cartera Irrecuperable')
            ->whereBetween('credits.updated_at', [$startUTC, $endUTC])
            ->where('installments.status', 'Pendiente')
            ->sum('installments.quota_amount');

        $realToDeliver = $liquidation->initial_cash
            + $liquidation->base_delivered
            + ($totalIncome + $totalCollected + $poliza)
            - $totalExpenses
            - $newCredits
            - $total_renewal_disbursed
            - $irrecoverableCredits;

        $cashDelivered = $liquidation->cash_delivered;
        $shortage = 0;
        $surplus = 0;
        if ($realToDeliver >= 0) {
            // Escenario normal: Hay dinero para entregar
            if ($cashDelivered < $realToDeliver) {
                // Faltante: Entregó menos de lo que debía
                $shortage = $realToDeliver - $cashDelivered;
            } else {
                // Sobrante: Entregó más de lo que debía
                $surplus = $cashDelivered - $realToDeliver;
            }
        } else {
            // Escenario de saldo a favor del vendedor (base negativa, muchos gastos, etc)
            // Se trata como una "deuda" del sistema al vendedor
            $debtAmount = abs($realToDeliver);
            
            // Si el vendedor entregó dinero (aunque no debía), es sobrante
            // Si entregó 0, no hay faltante ni sobrante relativo a la entrega, 
            // pero el saldo sigue siendo negativo (se reflejará en real_to_deliver)
            
             if ($cashDelivered > 0) {
                $surplus = $cashDelivered;
                 // Opcional: Si quisiéramos cubrir la deuda, sería lógica distinta, 
                 // pero por ahora mantenemos simple: lo entregado es sobrante porque no debía entregar nada.
            }
            // NOTA: No calculamos shortage en negativo, el real_to_deliver ya indica el saldo.
        }

        // Actualiza la liquidación con todos los campos recalculados
        $liquidation->update([
            'total_expenses' => $totalExpenses,
            'new_credits' => $newCredits,
            'total_income' => $totalIncome,
            'total_collected' => $totalCollected,
            'poliza' => $poliza,
            'real_to_deliver' => $realToDeliver,
            'shortage' => $shortage,
            'surplus' => $surplus,
            'irrecoverable_credits_amount' => $irrecoverableCredits,
            'renewal_disbursed_total' => $total_renewal_disbursed,
        ]);
    }
    public function approveLiquidation(Request $request, $id)
    {
        try {
            $liquidation = Liquidation::findOrFail($id);

            if ($request->filled('observation')) {
                $liquidation->observation = $request->observation;
            }

            if ($request->hasFile('capture')) {
                $path = $request->file('capture')->store('liquidations/captures', 'public');
                $liquidation->capture_path = $path;
            }

            $liquidation->save();

            // Registrar auditoría de aprobación administrativa
            \App\Models\LiquidationAudit::create([
                'liquidation_id' => $liquidation->id,
                'user_id' => auth()->id(),
                'action' => 'Aprobación administrativa',
            ]);

            return $this->liquidationService->approve($id, $request->input('timezone'));
        } catch (\Exception $e) {
            \Log::error("Error al aprobar liquidación: " . $e->getMessage());
            return $this->errorResponse('Error al aprobar la liquidación', 500);
        }
    }

    public function approveMultipleLiquidations(Request $request)
    {
        try {
            $ids = $request->input('ids');
            return $this->liquidationService->approveMultiple($ids);
        } catch (\Exception $e) {
            \Log::error("Error al aprobar liquidaciones múltiples: " . $e->getMessage());
            return $this->errorResponse('Error al aprobar las liquidaciones', 500);
        }
    }

    public function annulBase(Request $request, $id)
    {
        $liquidation = Liquidation::findOrFail($id);

        try {
            DB::beginTransaction();

            // Anula la base
            $liquidation->update([
                'base_delivered' => 0,
            ]);

            // Recalcula todas las liquidaciones posteriores al día de esta liquidación
            $this->recalculateNextLiquidations($liquidation->seller_id, $liquidation->date);

            DB::commit();

            // Refresca los datos
            $liquidation->refresh();

            return response()->json([
                'success' => true,
                'data' => $liquidation,
                'message' => 'Base anulada y liquidación recalculada correctamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al anular la base: ' . $e->getMessage()
            ], 500);
        }
    }

    protected function recalculateNextLiquidations($sellerId, $fromDate)
    {
        return $this->liquidationService->recalculateNextLiquidations($sellerId, $fromDate);
    }

    private function checkAuthorization($user, $sellerId)
    {
        // Superadministrador (Role 1) tiene acceso total
        if ($user->role_id == 1) {
            return true;
        }

        // Administradores de empresa (Role 2) solo acceden a sus propios vendedores
        if ($user->role_id == 2) {
            $seller = Seller::find($sellerId);
            $userCompany = $user->company; // Proviene de la relación User -> hasOne(Company)
            
            return $seller && $userCompany && $seller->company_id == $userCompany->id;
        }

        // Vendedores (Role 5) solo acceden a sus propios datos
        if ($user->role_id == 5) {
            $seller = Seller::where('user_id', $user->id)->first();
            return $seller && $seller->id == $sellerId;
        }

        return false;
    }

    public function getLiquidationData($sellerId, $date, Request $request)
    {
        $timezone = $request->query('timezone', 'America/Lima');
        $user = Auth::user();
        return $this->liquidationService->getLiquidationData($sellerId, $date, $user->id, $timezone);
    }

    protected function getDailyTotals($sellerId, $date, $user, $timezone = 'America/Lima')
    {
        $targetDate = Carbon::parse($date, $timezone);
        $formattedDate = $targetDate->format('Y-m-d');

        $startUTC = $targetDate->copy()->startOfDay()->timezone('UTC');
        $endUTC = $targetDate->copy()->endOfDay()->timezone('UTC');

        \Log::info('[getDailyTotals] sellerId: ' . $sellerId . ', date: ' . $date . ', timezone: ' . $timezone);
        \Log::info('[getDailyTotals] startUTC: ' . $startUTC . ', endUTC: ' . $endUTC);

        $query = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->select(
                'payments.payment_method',
                DB::raw('SUM(payments.amount) as total')
            )
            ->whereNull('payments.deleted_at')
            ->where('payments.business_date', $formattedDate)
            ->where('credits.seller_id', $sellerId)
            ->whereIn('payments.status', ['Pagado', 'Aprobado', 'Abonado'])
            ->groupBy('payments.payment_method');

        $firstPaymentQuery = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->select(DB::raw('MIN(payments.created_at) as first_payment_date'))
            ->whereNull('payments.deleted_at')
            ->where('payments.business_date', $formattedDate);

        if ($sellerId) {
            $firstPaymentQuery->where('credits.seller_id', $sellerId);
        }

        $firstPaymentResult = $firstPaymentQuery->first();
        $firstPaymentDate = null;
        if ($firstPaymentResult && $firstPaymentResult->first_payment_date) {
            $firstPaymentDate = Carbon::parse($firstPaymentResult->first_payment_date)
                ->setTimezone($timezone)
                ->toDateTimeString();
        }

        $paymentResults = $query->get();

        \Log::info('[getDailyTotals] paymentResults: ', $paymentResults->toArray());

        $totals = [
            'cash' => 0,
            'transfer' => 0,
            'collected_total' => 0,
            'base_value' => 0,
            'liquidation_start_date' => $firstPaymentDate
        ];

        foreach ($paymentResults as $result) {
            $amount = (float) $result->total;
            if ($result->payment_method === 'Efectivo') {
                $totals['cash'] = $amount;
            } elseif ($result->payment_method === 'Transferencia') {
                $totals['transfer'] = $amount;
            }
            $totals['collected_total'] += $amount;
        }

        $totals['expected_total'] = (float) DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('installments.due_date', $formattedDate)
            ->sum('installments.quota_amount');

        $credits = DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNull('renewed_from_id')
            ->whereNull('deleted_at')
            ->whereNull('unification_reason')
            ->select([
                DB::raw('COALESCE(SUM(credit_value), 0) as value'),
                DB::raw('COALESCE(SUM(
                CASE
                    WHEN total_interest IS NOT NULL AND total_interest > 0
                    THEN credit_value * (total_interest / 100)
                    ELSE 0
                END
            ), 0) as interest')
            ])
            ->first();

        \Log::info('[getDailyTotals] credits: ', (array) $credits);

        /* Log::info('Créditos creados en ' . $targetDate->format('Y-m-d') . ':', [
            'query' => DB::table('credits')
                ->where('seller_id', $sellerId)
                ->whereBetween('created_at', [$startUTC, $endUTC])
                ->toSql(),
            'bindings' => DB::table('credits')
                ->where('seller_id', $sellerId)
                ->whereBetween('created_at', [$startUTC, $endUTC])
                ->getBindings(),
            'result' => $credits
        ]); */

        $totals['created_credits_value'] = (float) $credits->value;
        $totals['created_credits_interest'] = (float) $credits->interest;

        // Usar el user_id del vendedor si está disponible
        $targetUserId = $user instanceof \App\Models\User ? $user->id : $user;
        if ($sellerId) {
            $sellerObj = Seller::find($sellerId);
            if ($sellerObj && $sellerObj->user_id) {
                $targetUserId = $sellerObj->user_id;
            }
        }

        $totals['total_expenses'] = (float) Expense::where('user_id', $targetUserId)
            ->where(function($q) use ($formattedDate, $startUTC, $endUTC) {
                $q->where('business_date', $formattedDate)
                  ->orWhereBetween('created_at', [$startUTC, $endUTC]);
            })
            ->where(function ($q) {
                $q->where('status', 'Aprobado')
                    ->orWhere('description', 'like', '%AJUSTE%');
            })
            ->sum('value');

        $totals['total_income'] = (float) Income::where('user_id', $targetUserId)
            ->where(function($q) use ($formattedDate, $startUTC, $endUTC) {
                $q->where('business_date', $formattedDate)
                  ->orWhereBetween('created_at', [$startUTC, $endUTC]);
            })
            ->sum('value');


        $totals['total_clients'] = (int) DB::table('clients')
            ->whereExists(function ($query) use ($sellerId) {
                $query->select(DB::raw(1))
                    ->from('credits')
                    ->whereColumn('credits.client_id', 'clients.id')
                    ->where('credits.seller_id', $sellerId);
            })
            ->count();

        // Calcular saldos
        $totals['daily_goal'] = $totals['expected_total'];
        $totals['current_balance'] = $totals['collected_total'] - $totals['total_expenses'];

        /*  Log::info($totals['expected_total']); */

        $renewalCredits = DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNotNull('renewed_from_id')
            ->get();


        $detalles_renovaciones = [];
        $total_renewal_disbursed = 0;
        $total_pending_absorbed = 0;

        foreach ($renewalCredits as $renewCredit) {
            $oldCredit = DB::table('credits')->where('id', $renewCredit->renewed_from_id)->first();

            $pendingAmount = 0;
            $oldCreditTotal = 0;
            $oldCreditPaid = 0;
            if ($oldCredit) {
                $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                $oldCreditPaid = DB::table('payments')->where('credit_id', $oldCredit->id)->sum('amount');
                $pendingAmount = $oldCreditTotal - $oldCreditPaid;
                $total_pending_absorbed += $pendingAmount;
            }


            $netDisbursement = $renewCredit->credit_value - $pendingAmount;
            $total_renewal_disbursed += $netDisbursement;
        }

        $totals['total_renewal_disbursed'] = $total_renewal_disbursed;
        $totals['total_crossed_credits'] = $total_pending_absorbed;

        // === Calcular valor de póliza ===
        $totals['poliza'] = (float) DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->sum(DB::raw('micro_insurance_percentage * credit_value / 100'));

        \Log::info('[getDailyTotals] poliza: ', ['poliza' => $totals['poliza']]);

        \Log::info('[getDailyTotals] totals before return: ', ['totals' => $totals]);

        return $totals;
    }

    public function getBySeller(Request $request, $sellerId)
    {
        try {
            $perPage = $request->input('per_page', 20);


            $response = $this->liquidationService->getLiquidationsBySeller(
                $sellerId,
                $request,
            );

            return $response;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener liquidaciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtiene estadísticas de un vendedor
     *
     * @param int $sellerId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSellerStats($sellerId)
    {
        try {
            $response = $this->liquidationService->getSellerStats($sellerId);
            return $response;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Muestra una liquidación específica
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $liquidation = Liquidation::with('seller')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $liquidation
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Liquidación no encontrada'
            ], 404);
        }
    }

    // Método para formatear respuesta de liquidación existente
    protected function formatLiquidationResponse($liquidation, $isExisting = false, $timezone = 'America/Lima')
    {
        $user = Auth::user();
        return $this->liquidationService->getLiquidationData($liquidation->seller_id, $liquidation->date, $user->id, $timezone);
    }

    // Método para obtener liquidación anterior
    protected function getPreviousLiquidation($sellerId, $date)
    {
        $lastLiquidation = Liquidation::where('seller_id', $sellerId)
            ->where('date', '<', $date)
            ->orderBy('date', 'desc')
            ->first();

        return $lastLiquidation ? $this->formatLiquidationDetails($lastLiquidation) : null;
    }

    // Método para formatear detalles de liquidación
    protected function formatLiquidationDetails($liquidation)
    {
        // Cargar la relación seller.user si no está cargada
        if (!$liquidation->relationLoaded('seller')) {
            $liquidation->load('seller.user');
        }

        return [
            'id' => $liquidation->id,
            'date' => $liquidation->date,
            'real_to_deliver' => $liquidation->real_to_deliver,
            'total_collected' => $liquidation->total_collected,
            'total_expenses' => $liquidation->total_expenses,
            'new_credits' => $liquidation->new_credits,
            'base_delivered' => $liquidation->base_delivered,
            'shortage' => $liquidation->shortage,
            'surplus' => $liquidation->surplus,
            'cash_delivered' => $liquidation->cash_delivered,
            'status' => $liquidation->status,
            'created_at' => $liquidation->created_at,
            'updated_at' => $liquidation->updated_at,
            'end_date' => $liquidation->end_date,
            'poliza' => $liquidation->poliza,
            'observation' => $liquidation->observation,
            'capture_path' => $liquidation->capture_path,
            'seller' => $liquidation->seller ? [
                'id' => $liquidation->seller->id,
                'user' => $liquidation->seller->user ? [
                    'id' => $liquidation->seller->user->id,
                    'name' => $liquidation->seller->user->name,
                ] : null
            ] : null

        ];
    }
    public function getLiquidationHistory(LiquidationHistoryRequest $request)
    {
        $user = Auth::user();
        $sellerId = $request->seller_id;
        if (!$this->checkAuthorization($user, $sellerId))
            return response()->json(['error' => 'Unauthorized'], 403);
        $result = $this->liquidationService->getLiquidationHistory($sellerId, $request->start_date, $request->end_date);
        return response()->json(['success' => true, 'data' => $result]);
    }
    public function getAccumulatedByCity(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            $user = Auth::user();
            $companyId = $request->input('company_id');
            $sellerIds = null;

            // Aislamiento: Role 2 solo ve su empresa
            if ($user->role_id == 2) {
                $companyId = $user->company ? $user->company->id : -1;
            }

            $results = $this->liquidationService->getAccumulatedByCity($startDate, $endDate, $companyId, $sellerIds);

            return response()->json([
                'success' => true,
                'message' => 'Datos obtenidos exitosamente',
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los datos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAccumulatedByCityWithSellers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'city_id' => 'required|exists:cities,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $cityId = $request->input('city_id');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            $user = Auth::user();
            $companyId = $request->input('company_id');
            $sellerIds = null;

            // Aislamiento: Role 2 solo ve su empresa y sus vinculados
            if ($user->role_id == 2) {
                $companyId = $user->company ? $user->company->id : -1;
                $sellerIds = \App\Models\UserRoute::where('user_id', $user->id)->pluck('seller_id')->toArray();
                if (empty($sellerIds)) {
                     $sellerIds = [-1];
                }
            }

            // Llamar al nuevo servicio (agregando companyId y sellerIds)
            $results = $this->liquidationService->getAccumulatedBySellerInCity($cityId, $startDate, $endDate, $companyId, $sellerIds);

            return response()->json([
                'success' => true,
                'message' => 'Datos obtenidos exitosamente',
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los datos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getSellersSummaryByCity(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $cityId = $request->input('city_id');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            $user = Auth::user();
            $companyId = $request->input('company_id');
            $sellerIds = null;

            // Aislamiento: Role 2 solo ve su empresa
            if ($user->role_id == 2) {
                $companyId = $user->company ? $user->company->id : -1;
            }

            $results = $this->liquidationService->getAccumulatedBySellersInCity($cityId, $startDate, $endDate, $companyId, $sellerIds);

            return response()->json([
                'success' => true,
                'message' => 'Resumen por vendedores obtenido exitosamente',
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el resumen por vendedores',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getSellerLiquidationsDetail(Request $request, $sellerId)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            $user = Auth::user();

            // Autorización: El administrador debe poder ver a este vendedor
            if (!$this->checkAuthorization($user, $sellerId)) {
                return response()->json(['success' => false, 'message' => 'No tienes permisos para ver este vendedor'], 403);
            }

            $liquidations = Liquidation::with(['seller', 'seller.user'])
                ->where('seller_id', $sellerId)
                ->whereBetween('date', [$startDate, $endDate])
                ->orderBy('date', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Liquidaciones del vendedor obtenidas exitosamente',
                'data' => $liquidations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las liquidaciones del vendedor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Descarga el reporte de liquidación en PDF o Excel
     */
    public function downloadReport($id, Request $request)
    {
        $format = $request->get('format', 'pdf');
        $timezone = $request->get('timezone', 'America/Lima');
        return $this->liquidationService->downloadLiquidationReport($id, $format, $timezone);
    }


    public function getDailyMovements(Request $request, $sellerId)
    {
        try {
            $date = $request->query('date', null);
            $timezone = $request->query('timezone', 'America/Lima');

            // autorización
            $user = Auth::user();
            if (!$this->checkAuthorization($user, $sellerId)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // delegar al servicio
            $result = $this->liquidationService->getDailyMovements($sellerId, $date, $timezone);

            return response()->json([
                'success' => true,
                'data' => $result['data'] ?? $result,
                'date' => $result['date'] ?? $date,
                'timezone' => $result['timezone'] ?? $timezone,
                'count' => $result['count'] ?? (is_array($result['data'] ?? null) ? count($result['data']) : null)
            ]);
        } catch (\Exception $e) {
            Log::error("Error en getDailyMovements controlador: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener movimientos del día',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Devuelve la fecha de la primera liquidación aprobada de cada vendedor (con ciudad y país)
     */
    public function getFirstApprovedLiquidationBySeller()
    {
        $user = Auth::user();
        $result = $this->liquidationService->getFirstApprovedLiquidationBySeller($user);
        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }

    /**
     * Devuelve el detalle de una liquidación con totalizadores y listados paginados
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLiquidationDetail($id, Request $request)
    {
        $response = $this->liquidationService->getLiquidationDetail($id, $request);
        return response()->json($response);
    }

    /**
     * Ajuste de caja manual (Super-Admin / Sistema)
     */
    public function adjustBox(Request $request)
    {
        try {
            $user = Auth::user();
            // Verificar permisos básicos (Super-Admin o permiso específico)
            if ($user->role_id !== 1 && !$user->can('ajustar_caja')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tiene permisos para realizar esta operación.'
                ], 403);
            }

            $result = $this->liquidationService->adjustBox($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Caja ajustada y liquidaciones recalculadas correctamente',
                'data' => $result
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Error en adjustBox: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Simulación de recálculo en cascada
     */
    public function simulateRecalculation(Request $request)
    {
        try {
            $sellerId = $request->get('seller_id');
            $date = $request->get('date');
            $timezone = $request->get('timezone', 'America/Lima');

            if (!$sellerId || !$date) {
                return response()->json([
                    'success' => false,
                    'message' => 'Faltan parámetros requeridos (seller_id, date).'
                ], 422);
            }

            $simulation = $this->liquidationService->simulateRecalculation($sellerId, $date, $timezone);

            return response()->json([
                'success' => true,
                'data' => $simulation
            ]);
        } catch (\Exception $e) {
            Log::error("Error en simulateRecalculation: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
