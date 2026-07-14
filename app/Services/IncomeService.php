<?php

namespace App\Services;

use App\Exceptions\CashClosedException;
use App\Helpers\Helper;
use App\Models\IncomeImage;
use App\Services\Traits\EnforcesCashOpen;
use App\Traits\ApiResponse;
use App\Models\Income;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IncomeService
{
    use ApiResponse, EnforcesCashOpen;

    const TIMEZONE = 'America/Lima';

    public function create(Request $request)
    {
        try {
            $validated = $request->validate([
                'value' => 'nullable|numeric|min:0',
                'description' => 'nullable|string',
                'user_id' => 'nullable|numeric',
                'image' => 'nullable|image|max:2048',
                'created_at' => 'nullable|date',
                'timezone' => 'nullable|string',
            ]);

            $user = Auth::user();
            $isAdmin = in_array($user->role_id, [1, 2]);

            // Dueño del movimiento (a nombre de quién queda):
            // - Supervisor (rol 6): SIEMPRE el vendedor activo de la sección
            //   (validado por el middleware ResolveActiveSeller). El supervisor
            //   NO se auto-registra movimientos: los registra al vendedor que
            //   supervisa.
            // - Admin (rol 1/2): el user_id que envíe (operar por un vendedor).
            // - Cobrador y demás: su propio usuario.
            $activeSellerId = $request->attributes->get('active_seller_id');
            if ((int) $user->role_id === 6 && $activeSellerId) {
                $userId = optional(Seller::find($activeSellerId))->user_id ?? $user->id;
            } elseif ($isAdmin && $request->has('user_id')) {
                $userId = $validated['user_id'];
            } else {
                $userId = $user->id;
            }

            $seller = Seller::where('user_id', $userId)->first();
            
            // 1. La zona de negocio la decide el VENDEDOR (BD:
            // seller→city→country→timezone), NO el teléfono — igual que el pago.
            // Un dispositivo con zona/hora mal ya no corre el día del ingreso. La
            // zona reportada por el request se guarda solo como client_timezone.
            $businessTimezone = \App\Helpers\TimezoneHelper::getSellerTimezone($seller);
            $clientTimezone = $validated['timezone'] ?? config('app.timezone');
            unset($validated['timezone']);

            // Business Timestamp Calculation
            $nowInBusinessZone = Carbon::now($businessTimezone);
            
            // 2. Calcular timestamp local y fecha de negocio
            if ($request->has('created_at')) {
                // Asumimos que la fecha enviada es "local" para el usuario (en su timezone efectivo)
                $userProvidedDate = Carbon::parse($validated['created_at'], $businessTimezone);
                $businessTimestamp = $userProvidedDate; 
                $createdAt = $userProvidedDate->copy()->setTimezone('UTC'); 
            } else {
                $businessTimestamp = $nowInBusinessZone;
                $createdAt = $nowInBusinessZone->copy()->setTimezone('UTC');
            }
            
            $businessDate = $businessTimestamp->toDateString();

            // Día NO laborable de la ruta (domingo sin works_sundays o feriado):
            // nadie —ni el admin— registra ingresos ese día.
            if ($seller) {
                $this->assertSellerWorksOnDate($seller, $businessDate);
            }

            // Guard de defensa en profundidad: rechaza el ingreso si la
            // liquidación del día del vendedor ya está cerrada.
            //
            // El admin/superadmin (rol 1 y 2) SÍ puede registrar ingresos sobre
            // una caja cerrada: es justamente quien "reabre/ajusta" la caja. El
            // bloqueo solo aplica al vendedor y demás roles operativos.
            // (El bloqueo de caja APROBADA para todos es solo para EGRESOS.)
            if ($seller && !$isAdmin) {
                $this->assertSellerCashOpen($seller->id, $businessDate);
            }

            $incomeData = [
                'value' => $validated['value'],
                'description' => $validated['description'],
                'user_id' => $userId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'client_timezone' => $clientTimezone,
                'business_timezone' => $businessTimezone, // Dynamic based on operation
                'business_timestamp' => $businessTimestamp->format('Y-m-d H:i:s'),
                'business_date' => $businessDate
            ];

            // Trazabilidad: quién REGISTRÓ el ingreso (un supervisor/admin a
            // nombre de un vendedor). El dueño sigue siendo user_id. Defensivo:
            // solo si la columna existe (hasta correr la migración
            // 2026_06_29_000001_add_created_by_to_incomes).
            if (\Illuminate\Support\Facades\Schema::hasColumn('incomes', 'created_by')) {
                $incomeData['created_by'] = $user->id;
            }

            $income = Income::create($incomeData);

            if ($seller) {
                // Recalcular liquidaciones
                $liquidationService = app(\App\Services\LiquidationService::class);
                $liquidationService->recalculateLiquidation($seller->id, $businessDate);
                $liquidationService->recalculateNextLiquidations($seller->id, $businessDate);
            }

            if ($request->hasFile('image')) {
                $imageFile = $request->file('image');
                $imagePath = Helper::uploadFile($imageFile, 'incomes');
                IncomeImage::create([
                    'income_id' => $income->id,
                    'user_id' => $userId,
                    'path' => $imagePath,
                    'created_at' => $createdAt ?? null,
                    'updated_at' => $createdAt ?? null
                ]);
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Ingreso creado con éxito',
                'data' => $income,
            ]);
        } catch (CashClosedException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            \Log::error("Error changing income status: " . $e->getMessage());
            return $this->errorResponse('Error al cambiar estado del ingreso', 500);
        }
    }

    public function simulateDelete($incomeId)
    {
        try {
            $income = Income::find($incomeId);
            if (!$income) {
                return $this->errorResponse('Ingreso no encontrado', 404);
            }

            // Assuming user_id -> Seller mapping
            $seller = Seller::where('user_id', $income->user_id)->first();
            if (!$seller) {
                 return $this->errorResponse('Vendedor no encontrado', 404);
            }

            $incomeDate = $income->business_date 
                ? $income->business_date->format('Y-m-d') 
                : ($income->created_at ? $income->created_at->setTimezone(self::TIMEZONE)->format('Y-m-d') : date('Y-m-d'));
            
            $liquidation = Liquidation::whereDate('date', $incomeDate)
                ->where('seller_id', $seller->id)
                ->first();

            $simulationData = [];

            if ($liquidation) {
                $amount = $income->value;
                
                // If we delete an income, total_income decreases.
                // real_to_deliver = ... + income ...
                // So if income decreases, real_to_deliver DECREASES.
                
                $newTotalIncome = $liquidation->total_income - $amount;
                $newRealToDeliver = $liquidation->real_to_deliver - $amount;

                $simulationData[] = [
                    'liquidation' => [
                        'id' => $liquidation->id,
                        'date' => $liquidation->date,
                        'total_income' => $liquidation->total_income,
                        'initial_cash' => $liquidation->initial_cash,
                        'real_to_deliver' => $liquidation->real_to_deliver
                    ],
                    'changes' => [
                        'total_income' => $newTotalIncome,
                        'real_to_deliver' => $newRealToDeliver
                    ],
                    'impact_amount' => $amount,
                    'type' => 'Ingreso'
                ];

                // Get all subsequent liquidations
                $subsequentLiquidations = Liquidation::where('seller_id', $seller->id)
                    ->whereDate('date', '>', $incomeDate)
                    ->orderBy('date', 'ASC')
                    ->get();

                // Calculate cascading effect on subsequent liquidations
                // For income deletion, the impact is NEGATIVE (decreases real_to_deliver)
                $cumulativeImpact = -$amount; // Negative because income deletion reduces cash
                foreach ($subsequentLiquidations as $subLiq) {
                    $newInitialCash = $subLiq->initial_cash + $cumulativeImpact;
                    $newRealToDeliverSub = $subLiq->real_to_deliver + $cumulativeImpact;

                    $simulationData[] = [
                        'liquidation' => [
                            'id' => $subLiq->id,
                            'date' => $subLiq->date,
                            'initial_cash' => $subLiq->initial_cash,
                            'real_to_deliver' => $subLiq->real_to_deliver
                        ],
                        'changes' => [
                            'initial_cash' => $newInitialCash,
                            'real_to_deliver' => $newRealToDeliverSub
                        ],
                        'impact_amount' => $cumulativeImpact,
                        'type' => 'Cascada'
                    ];
                }
            }

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'entity' => $income,
                    'affected_liquidations' => $simulationData
                ]
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Error simulando eliminación: ' . $e->getMessage(), 500);
        }
    }

   public function update(Request $request, $incomeId)
{
    try {
        $income = Income::find($incomeId);
        if (!$income) {
            return $this->errorNotFoundResponse('Ingreso no encontrado');
        }

        // Logging para depuración (opcional)
        // Log::info('Income update request all', $request->all());
        // Log::info('Income update files', $request->files->all());

        // Reglas base
        $rules = [
            'value' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'timezone' => 'nullable|string',
            'user_id' => 'nullable|numeric',
            // 'image' solo si se sube archivo
        ];

        if ($request->hasFile('image')) {
            $rules['image'] = 'image|max:2048';
        }

        // Opcional: allow remove_image flag (boolean)
        $rules['remove_image'] = 'nullable|boolean';

        $validated = $request->validate($rules);

        // Buscar seller como antes (tu lógica)
        $seller = null;
        if ($income->user_id) {
            $seller = Seller::where('user_id', $income->user_id)->first();
        }

        if (!$seller) {
            $authUser = Auth::user();
            if ($authUser) {
                $seller = Seller::where('user_id', $authUser->id)->first();
            }
        }

        // El admin/superadmin (rol 1 y 2) puede ajustar el ingreso aunque la
        // liquidación de la fecha ya exista/esté cerrada. Es quien reabre/ajusta
        // la caja. El resto de roles sí queda bloqueado.
        $currentUser = Auth::user();
        $isAdmin = $currentUser && in_array($currentUser->role_id, [1, 2]);

        if ($seller && !$isAdmin) {
            $incomeDate = $income->business_date
                ? $income->business_date->format('Y-m-d')
                : ($income->created_at ? Carbon::parse($income->created_at)->toDateString() : date('Y-m-d'));

            $liquidation = Liquidation::where('seller_id', $seller->id)
                ->whereDate('date', $incomeDate)
                ->first();

            if ($liquidation) {
                return $this->errorResponse(
                    'No se puede editar el ingreso porque ya existe una liquidación aprobada para esta fecha',
                    422
                );
            }
        }

        // Manejo timezone => set updated_at
        if (isset($validated['timezone']) && !empty($validated['timezone'])) {
            unset($validated['timezone']);
        }
        $validated['updated_at'] = Carbon::now();

        // Preparar datos para update (solo los campos permitidos)
        $updateData = [];
        if (array_key_exists('value', $validated) && $validated['value'] !== null) {
            $updateData['value'] = $validated['value'];
        }
        if (array_key_exists('description', $validated)) {
            $updateData['description'] = $validated['description'];
        }
        if (array_key_exists('user_id', $validated) && $validated['user_id']) {
            $updateData['user_id'] = $validated['user_id'];
        }
        if (array_key_exists('updated_at', $validated)) {
            $updateData['updated_at'] = $validated['updated_at'];
        }

        // Actualizar income
        if (!empty($updateData)) {
            $income->update($updateData);
        }

        // === Manejo de imagen ===
        // 1) Si viene archivo nuevo: subir, crear registro image y eliminar anterior
        if ($request->hasFile('image')) {
            $imageFile = $request->file('image');
            $imagePath = Helper::uploadFile($imageFile, 'incomes'); // tu helper

            // Buscar imagen previa (ajusta según relación: income->images())
            $oldImage = IncomeImage::where('income_id', $income->id)->first();

            // Crear nuevo registro
            $newImage = IncomeImage::create([
                'income_id' => $income->id,
                'user_id' => $income->user_id ?? Auth::id(),
                'path' => $imagePath,
                'created_at' => $updateData['updated_at'] ?? now(),
                'updated_at' => $updateData['updated_at'] ?? now(),
            ]);

            // Borrar archivo y registro anterior si existía
            if ($oldImage) {
                /* Comentado para preservar historial de archivos (Borrado Lógico Total)
                try {
                    if (function_exists('Helper') && method_exists(Helper::class, 'deleteFile')) {
                        Helper::deleteFile($oldImage->path);
                    } else {
                        \Illuminate\Support\Facades\Storage::delete($oldImage->path);
                    }
                } catch (\Exception $ex) {
                    Log::warning("No se pudo borrar archivo antiguo: " . $ex->getMessage());
                }
                */

                // eliminar registro antiguo (ahora será SoftDelete)
                $oldImage->delete();
            }
        } else if (!empty($validated['remove_image']) && $validated['remove_image']) {
            // 2) Si viene flag remove_image = true: borrar imagen existente y registro
            $oldImage = IncomeImage::where('income_id', $income->id)->first();
            if ($oldImage) {
                /* Comentado para preservar historial de archivos (Borrado Lógico Total)
                try {
                    if (function_exists('Helper') && method_exists(Helper::class, 'deleteFile')) {
                        Helper::deleteFile($oldImage->path);
                    } else {
                        \Illuminate\Support\Facades\Storage::delete($oldImage->path);
                    }
                } catch (\Exception $ex) {
                    Log::warning("No se pudo borrar archivo antiguo: " . $ex->getMessage());
                }
                */
                $oldImage->delete();
            }
        }

        // Refrescar modelo para devolver data actualizada
        $income->refresh();
        
        // RECALCULAR LIQUIDACION SI HUBO CAMBIOS DE VALOR
        if ($seller && isset($updateData['value'])) {
             $liquidationService = app(\App\Services\LiquidationService::class);
             $incomeDateRecalc = $income->business_date 
                 ? $income->business_date->format('Y-m-d') 
                 : ($income->created_at ? Carbon::parse($income->created_at)->toDateString() : date('Y-m-d'));
             
             $liquidationService->recalculateLiquidation($seller->id, $incomeDateRecalc);
             $liquidationService->recalculateNextLiquidations($seller->id, $incomeDateRecalc);
        }

        return $this->successResponse([
            'success' => true,
            'message' => 'Ingreso actualizado con éxito',
            'data' => $income
        ]);
    } catch (\Exception $e) {
        Log::error('Error actualizando ingreso: ' . $e->getMessage(), ['exception' => $e]);
        return $this->errorResponse('Error al actualizar el ingreso', 500);
    }
}

    public function delete($incomeId, Request $request = null)
    {
        try {
            $user = Auth::user();

            $income = Income::find($incomeId);
            if (!$income) {
                return $this->errorNotFoundResponse('Ingreso no encontrado');
            }

            // Solo permitir eliminar ingresos del día actual
            $timezone = 'America/Lima'; // Used for currentDate fallback if needed, but we prefer business time

            $incomeDate = $income->business_date 
                ? $income->business_date->format('Y-m-d') 
                : Carbon::parse($income->created_at)->timezone($timezone)->format('Y-m-d');
            
            // Obtener el vendedor asociado al usuario del ingreso
            $seller = Seller::where('user_id', $income->user_id)->first();
            
            if (!$seller) {
                return $this->errorResponse('No se encontró el vendedor asociado a este ingreso', 422);
            }
            
            $currentDate = \App\Helpers\TimezoneHelper::getBusinessNow($seller)->format('Y-m-d');

            if ($incomeDate !== $currentDate && (!$user->can('ajustar_caja') && $user->role_id != 1)) {
                return $this->errorResponse(
                    'Solo se pueden eliminar ingresos creados el día de hoy o si tiene permisos de ajuste',
                    422
                );
            }

            // Ensure liquidation record exists for auditing
            $liquidationService = app(LiquidationService::class);
            $liquidation = $liquidationService->getOrCreateLiquidation($seller->id, $incomeDate);

            if ($liquidation && $liquidation->status === 'approved' && !$user->can('ajustar_caja') && $user->role_id != 1) {
                return $this->errorResponse(
                    'No se puede eliminar el ingreso porque ya existe una liquidación aprobada para esta fecha y no tiene permisos de ajuste',
                    422
                );
            }

            $timezoneRequest = $request && $request->has('timezone') ? $request->get('timezone') : null;
            if ($timezoneRequest) {
                $income->deleted_at = Carbon::now($timezoneRequest);
                $income->save();
                $income->delete();
            } else {
                $income->delete();
            }

            if ($liquidation) {
                $oldRealToDeliver = floatval($liquidation->real_to_deliver);

                // Recalcular liquidaciones
                $liquidationService = app(LiquidationService::class);
                $liquidationService->recalculateLiquidation($seller->id, $incomeDate);
                $liquidationService->recalculateNextLiquidations($seller->id, $incomeDate);

                // Re-fetch liquidation to get new values
                $updatedLiquidation = Liquidation::find($liquidation->id);
                $newRealToDeliver = $updatedLiquidation ? floatval($updatedLiquidation->real_to_deliver) : 0;

                // Record Audit
                \App\Models\LiquidationAudit::create([
                    'liquidation_id' => $liquidation->id,
                    'user_id' => $user->id,
                    'action' => 'deleted_income',
                    'changes' => [
                        'description' => "Eliminación de Ingreso #{$income->id} - Desc: {$income->description} - Monto: {$income->value}",
                        'income_id' => $income->id,
                        'amount' => floatval($income->value),
                        'old_real_to_deliver' => $oldRealToDeliver,
                        'new_real_to_deliver' => $newRealToDeliver,
                        'impact' => $newRealToDeliver - $oldRealToDeliver
                    ]
                ]);
            }

            return $this->successResponse([
                'success' => true,
                'message' => "Ingreso eliminado con éxito y liquidaciones actualizadas",
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al eliminar el ingreso', 500);
        }
    }


    public function index(
        Request $request,
        string $search,
        int $perpage,
        string $orderBy = 'created_at',
        string $orderDirection = 'desc',
        $companyId = null
    ) {
        try {
            $user = Auth::user();
            $role = $user->role_id;

            $incomeQuery = Income::with(['user', 'images', 'createdByUser:id,name,email'])
                ->where(function ($query) use ($search) {
                    $query->where('description', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                });

            if ($companyId) {
                $userIds = User::whereHas('seller', function ($query) use ($companyId) {
                    $query->where('company_id', $companyId);
                })->pluck('id');
                $incomeQuery->whereIn('user_id', $userIds);
            } else if ($role === 1) {
                // Admin: sin restricciones (ve todo). Misma semántica que ClientService.
            } else if ($role === 2) {
                if (!$user->company) {
                    return $this->successResponse([
                        'success' => true,
                        'message' => 'Ingresos encontrados',
                        'data' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perpage)
                    ]);
                }
                $companyId = $user->company->id;
                $userIds = User::whereHas('seller', function ($query) use ($companyId) {
                    $query->where('company_id', $companyId);
                })->pluck('id');
                $incomeQuery->whereIn('user_id', $userIds);
            } else if ($role === 5) {
                // Vendedor: Ver ingresos de hoy (Negocio)
                $seller = Seller::where('user_id', $user->id)->first();
                $todayDate = \App\Helpers\TimezoneHelper::getBusinessNow($seller)->toDateString();

                $incomeQuery->where(function($q) use ($todayDate) {
                     $q->where('business_date', $todayDate)
                       ->orWhere(function($sub) use ($todayDate) {
                           $sub->whereNull('business_date')
                        ->whereBetween('created_at', [
                            \App\Helpers\TimezoneHelper::getBusinessNow($seller)->startOfDay()->utc(),
                            \App\Helpers\TimezoneHelper::getBusinessNow($seller)->endOfDay()->utc()
                        ]); // Fallback aproximado con rango UTC
                       });
                });
            } else if ($role === 6 && $request->attributes->get('active_seller_id')) {
                // Supervisor con ruta activa seleccionada (header X-Active-Seller-Id
                // resuelto por middleware): mostrar SOLO los ingresos del cobrador
                // seleccionado, sin mezclar los de las demás rutas vinculadas.
                $activeSellerId = (int) $request->attributes->get('active_seller_id');
                $sellerUserId = Seller::where('id', $activeSellerId)->value('user_id');
                if ($sellerUserId) {
                    $incomeQuery->where('user_id', $sellerUserId);
                } else {
                    $incomeQuery->whereRaw('1=0');
                }
            } else {
                // Roles intermedios (Socio=3, Asistente=4, Supervisor=6, Cobrador-abono=7,
                // Limitado=8, Digitador=9, Contador=10, Secretaria=11): aislamiento por
                // empresa. Resuelve company del usuario en este orden:
                //   1) relación directa user->company (admin)
                //   2) seller asociado (cobradores promovidos)
                //   3) parent_id->company (subordinados de un admin)
                // Si nada se puede resolver, fail-closed.
                $resolvedCompanyId = $user->company?->id
                    ?? optional(Seller::where('user_id', $user->id)->first())->company_id
                    ?? optional(User::find($user->parent_id))?->company?->id;
                if ($resolvedCompanyId) {
                    $userIds = User::whereHas('seller', function ($query) use ($resolvedCompanyId) {
                        $query->where('company_id', $resolvedCompanyId);
                    })->pluck('id');
                    $incomeQuery->whereIn('user_id', $userIds);
                } else {
                    $incomeQuery->whereRaw('1=0');
                }
            }

            if ($request->has('seller_id') && $request->seller_id) {
                $incomeQuery->where('user_id', $request->seller_id);
            }

            if ($request->has('start_date') && $request->has('end_date')) {
                $startDate = $request->start_date;
                $endDate = $request->end_date;
                $timezone = $request->input('timezone', 'America/Lima'); // Backup parameter
                
                $incomeQuery->where(function($q) use ($startDate, $endDate, $timezone) {
                     $q->whereBetween('business_date', [$startDate, $endDate])
                       ->orWhere(function($sub) use ($startDate, $endDate, $timezone) {
                            $sub->whereNull('business_date')
                                ->whereBetween('created_at', [
                                     Carbon::parse($startDate, $timezone)->startOfDay()->utc(),
                                     Carbon::parse($endDate, $timezone)->endOfDay()->utc()
                                ]);
                       });
                });
            }

            $validOrderDirections = ['asc', 'desc'];
            $orderDirection = in_array(strtolower($orderDirection), $validOrderDirections)
                ? $orderDirection
                : 'desc';

            $incomeQuery->orderBy($orderBy, $orderDirection);

            $income = $incomeQuery->paginate($perpage);

            return $this->successResponse([
                'success' => true,
                'message' => 'Ingresos encontrados',
                'data' => $income
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los ingresos', 500);
        }
    }

    public function show($expenseId)
    {
        try {
            $income = Income::with(['user'])->find($expenseId);

            if (!$income) {
                return $this->errorNotFoundResponse('Ingreso no encontrado');
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Ingreso encontrado',
                'data' => $income
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener el gasto', 500);
        }
    }

    public function getIncomeSummary()
    {
        try {
            $user = Auth::user();

            $query = Income::query();

            $totalIncome = $query->sum('value');
            $incomeCount = $query->count();
            $averageIncome = $incomeCount > 0 ? $totalIncome / $incomeCount : 0;

            $recentIncome = $query->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['description', 'value', 'created_at']);

            return $this->successResponse([
                'success' => true,
                'message' => 'Resumen de ingresos',
                'data' => [
                    'total_expenses' => $totalIncome,
                    'expense_count' => $incomeCount,
                    'average_expense' => round($averageIncome, 2),
                    'recent_expenses' => $recentIncome
                ]
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener el resumen de ingresos', 500);
        }
    }

    public function getIncomeByUser($userId)
    {
        try {
            $income = Income::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->successResponse([
                'success' => true,
                'message' => 'Ingresos por usuario',
                'data' => $income
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener ingresos por usuario', 500);
        }
    }

    public function getMonthlyIncomeReport()
    {
        try {
            $user = Auth::user();

            $query = Income::select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('SUM(value) as total'),
                DB::raw('COUNT(*) as count')
            )
                ->groupBy('year', 'month')
                ->orderBy('year', 'desc')
                ->orderBy('month', 'desc');

            $report = $query->get();

            return $this->successResponse([
                'success' => true,
                'message' => 'Reporte mensual de ingresos',
                'data' => $report
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al generar reporte mensual', 500);
        }
    }
    public function getSellerIncomeByDate(int $sellerId, Request $request, int $perpage, $companyId = null)
    {
        try {
            $seller = Seller::where('id', $sellerId)
                ->when($companyId, function ($query) use ($companyId) {
                    $query->where('company_id', $companyId);
                })
                ->first();

            if (!$seller || !$seller->user_id) {
                return $this->successResponse([
                    'success' => true,
                    'message' => 'No se encontró el usuario asociado a este ID de vendedor.',
                    'data' => []
                ]);
            }

            $incomeQuery = Income::query()
                ->select('incomes.*')
                ->addSelect([
                    'liquidation_number' => DB::table('liquidations')
                        ->select('id')
                        ->whereColumn('date', DB::raw('COALESCE(incomes.business_date, DATE(incomes.created_at))'))
                        ->where('seller_id', $seller->id)
                        ->whereNull('deleted_at')
                        ->limit(1)
                ])
                ->with(['user', 'images', 'createdByUser:id,name,email'])
                ->where('incomes.user_id', $seller->user_id);

            $timezone = $request->input('timezone', 'America/Lima');

            // Check if include_all_dates flag is present
            if (!$request->has('include_all_dates')) {
                // Apply date filtering only if include_all_dates is NOT set
                if ($request->has('start_date') && $request->has('end_date')) {
                    $startDate = $request->get('start_date');
                    $endDate = $request->get('end_date');
                    
                    $incomeQuery->where(function($q) use ($startDate, $endDate, $timezone) {
                        $q->whereBetween('business_date', [$startDate, $endDate])
                          ->orWhere(function($sub) use ($startDate, $endDate, $timezone) {
                               $sub->whereNull('business_date')
                                   ->whereBetween('incomes.created_at', [
                                        Carbon::parse($startDate, $timezone)->startOfDay()->utc(),
                                        Carbon::parse($endDate, $timezone)->endOfDay()->utc()
                                   ]);
                          });
                    });

                } elseif ($request->has('date')) {
                    $filterDate = $request->get('date');
                    $incomeQuery->where(function($q) use ($filterDate, $timezone) {
                        $q->where('business_date', $filterDate)
                          ->orWhere(function($sub) use ($filterDate, $timezone) {
                               $sub->whereNull('business_date')
                                   ->whereBetween('incomes.created_at', [
                                        Carbon::parse($filterDate, $timezone)->startOfDay()->utc(),
                                        Carbon::parse($filterDate, $timezone)->endOfDay()->utc()
                                   ]);
                          });
                    });
                } else {
                    $todayDate = \App\Helpers\TimezoneHelper::getBusinessNow($seller)->toDateString();
                    $incomeQuery->where(function($q) use ($todayDate, $timezone) {
                        $q->where('business_date', $todayDate)
                          ->orWhere(function($sub) use ($todayDate, $timezone) {
                               $sub->whereNull('business_date')
                                   ->whereBetween('incomes.created_at', [
                                       Carbon::parse($todayDate, $timezone)->startOfDay()->utc(),
                                       Carbon::parse($todayDate, $timezone)->endOfDay()->utc()
                                   ]);
                          });
                    });
                }
            }

            $incomeQuery->orderBy('incomes.created_at', 'desc');

            $income = $incomeQuery->paginate($perpage);

            return $this->successResponse([
                'success' => true,
                'message' => 'Ingresos obtenidos correctamente para el vendedor y fecha(s) especificadas',
                'data' => $income
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los ingresos del vendedor: ' . $e->getMessage(), 500);
        }
    }
}
