<?php

namespace App\Services;

use App\Helpers\Helper;
use App\Http\Requests\Seller\SellerRequest;
use App\Models\Liquidation;
use App\Traits\ApiResponse;
use App\Models\Seller;
use App\Models\User;
use App\Models\UserRoute;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Log;
use Illuminate\Support\Str;

class SellerService
{

    use ApiResponse;

    public function create(SellerRequest $request)
    {
        if (Auth::user()->role_id == 11) {
            return $this->errorResponse('No tiene permisos para crear vendedores.', 403);
        }

        DB::beginTransaction();

        try {
            $params = $request->validated();

            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $params['created_at'] = Carbon::now($params['timezone']);
                $params['updated_at'] = Carbon::now($params['timezone']);
                $userTimezone = $params['timezone'];
                unset($params['timezone']);
            } else {
                $userTimezone = null;
            }

            if ($request->has('images')) {
                $validationResponse = $this->validateImages($request);
                if ($validationResponse !== true) {
                    return $validationResponse;
                }
            }

            $user = User::create([
                'name' => $params['name'],
                'email' => $params['email'],
                'dni' => $params['dni'],
                'phone' => $params['phone'] ?? null,
                'password' => Hash::make($params['password']),
                'role_id' => $params['role_id'] ?? 5,
                'created_at' => $params['created_at'] ?? null,
                'updated_at' => $params['updated_at'] ?? null
            ]);

            $companyId = $params['company_id'] ?? null;
            if (Auth::user()->role_id == 2) {
                $companyId = Auth::user()->company->id ?? $companyId;
            }

            $seller = Seller::create([
                'user_id' => $user->id,
                'city_id' => $params['city_id'],
                'address' => $params['address'] ?? null,
                'company_id' => $companyId,
                'status' => 'ACTIVE',
                'routing_order' => $params['routing_order'] ?? null,
                'created_at' => $params['created_at'] ?? null,
                'updated_at' => $params['updated_at'] ?? null
            ]);

            if (isset($params['members']) && is_array($params['members'])) {
                foreach ($params['members'] as $memberId) {
                    UserRoute::create([
                        'user_id' => $memberId,
                        'seller_id' => $seller->id,
                        'created_at' => $params['created_at'] ?? null,
                        'updated_at' => $params['updated_at'] ?? null
                    ]);
                }
            }

            if ($request->has('images')) {
                $images = $request->input('images');
                foreach ($images as $index => $imageData) {
                    $imageFile = $request->file("images.{$index}.file");
                    $imagePath = Helper::uploadFile($imageFile, 'clients');
                    $seller->images()->create([
                        'path' => $imagePath,
                        'type' => $imageData['type'],
                        'created_at' => $params['created_at'] ?? null,
                        'updated_at' => $params['updated_at'] ?? null
                    ]);
                }
            }

            if ($request->hasFile('image')) {
                $imageFile = $request->file('image');
                $imagePath = Helper::uploadFile($imageFile, 'sellers');
                $seller->images()->create([
                    'path' => $imagePath,
                    'created_at' => $params['created_at'] ?? null,
                    'updated_at' => $params['updated_at'] ?? null
                ]);
            }

            // Create default config
            $seller->config()->create([
                'commission_system_active' => $params['commission_system_active'] ?? false,
                'commission_utility_recon_madrid' => $params['commission_utility_recon_madrid'] ?? null,
                'commission_total_collection' => $params['commission_total_collection'] ?? null,
                'commission_regrouping' => $params['commission_regrouping'] ?? null,
                'commission_paid_credits' => $params['commission_paid_credits'] ?? null,
                'monthly_fixed_salary' => $params['monthly_fixed_salary'] ?? null,
                'pension_discount' => $params['pension_discount'] ?? null,
                'eps_discount' => $params['eps_discount'] ?? null,
                'arl_discount' => $params['arl_discount'] ?? null,
                'weekly_allowance' => $params['weekly_allowance'] ?? null,
            ]);

            DB::commit();
            return $this->successResponse([
                'success' => true,
                'message' => 'Vendedor creado con éxito',
                'data' => $seller
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error creating seller: ' . $e->getMessage());
            return $this->errorResponse('Error al crear el vendedor', 500);
        }
    }

    public function update($sellerId, SellerRequest $request)
    {
        if (Auth::user()->role_id == 11) {
            return $this->errorResponse('No tiene permisos para editar vendedores.', 403);
        }

        DB::beginTransaction();

        try {
            $params = $request->validated();
            $seller = Seller::with(['user', 'images'])->findOrFail($sellerId);

            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $params['updated_at'] = Carbon::now($params['timezone']);
                $userTimezone = $params['timezone'];
                unset($params['timezone']);
            } else {
                $userTimezone = null;
            }

            $seller->user->update([
                'name' => $params['name'],
                'email' => $params['email'],
                'dni' => $params['dni'],
                'phone' => $params['phone'] ?? $seller->user->phone,
                'role_id' => $params['role_id'],
                'password' => isset($params['password']) ? Hash::make($params['password']) : $seller->user->password,
                'updated_at' => $params['updated_at'] ?? null
            ]);

            $companyId = $params['company_id'] ?? $seller->company_id;
            if (Auth::user()->role_id == 2) {
                $companyId = Auth::user()->company->id ?? $companyId;
            }

            $seller->update([
                'city_id' => $params['city_id'],
                'address' => $params['address'] ?? $seller->address,
                'company_id' => $companyId,
                'routing_order' => $params['routing_order'] ?? $seller->routing_order,
                'updated_at' => $params['updated_at'] ?? null
            ]);

            $memberIds = array_map('intval', $params['members'] ?? []);
            $memberIds = array_filter($memberIds);

            $this->syncMembersWithTimezone($seller, $memberIds, $params['updated_at'] ?? null);

            if ($request->hasFile('profilePhoto')) {
                $this->updateProfilePhoto($seller, $request->file('profilePhoto'));
            }

            if ($request->hasFile('image')) {
                $imageFile = $request->file('image');
                $imagePath = Helper::uploadFile($imageFile, 'sellers');
                $seller->images()->create([
                    'path' => $imagePath,
                    'updated_at' => $params['updated_at'] ?? null
                ]);
            }

            // Update or create config
            $seller->config()->updateOrCreate(
                ['seller_id' => $seller->id],
                [
                    'commission_system_active' => $params['commission_system_active'] ?? false,
                    'commission_utility_recon_madrid' => $params['commission_utility_recon_madrid'] ?? null,
                    'commission_total_collection' => $params['commission_total_collection'] ?? null,
                    'commission_regrouping' => $params['commission_regrouping'] ?? null,
                    'commission_paid_credits' => $params['commission_paid_credits'] ?? null,
                    'monthly_fixed_salary' => $params['monthly_fixed_salary'] ?? null,
                    'pension_discount' => $params['pension_discount'] ?? null,
                    'eps_discount' => $params['eps_discount'] ?? null,
                    'arl_discount' => $params['arl_discount'] ?? null,
                    'weekly_allowance' => $params['weekly_allowance'] ?? null,
                ]
            );

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => "Vendedor actualizado con éxito",
                'data' => $seller->load('user', 'images', 'userRoutes.user')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error updating seller: ' . $e->getMessage());
            return $this->errorResponse('Error al actualizar el vendedor', 500);
        }
    }

    private function syncMembersWithTimezone(Seller $seller, array $memberIds, $updatedAt = null)
    {
        $existingMemberIds = $seller->userRoutes->pluck('user_id')->toArray();
        $toDelete = array_diff($existingMemberIds, $memberIds);
        $toAdd = array_diff($memberIds, $existingMemberIds);

        if (!empty($toDelete)) {
            UserRoute::where('seller_id', $seller->id)
                ->whereIn('user_id', $toDelete)
                ->delete();
        }

        foreach ($toAdd as $memberId) {
            UserRoute::create([
                'seller_id' => $seller->id,
                'user_id' => $memberId,
                'updated_at' => $updatedAt
            ]);
        }
    }

    public function listActiveRoutes($hasLiquidation = null, $search = null, $countryId = null, $cityId = null, $sellerId = null, $companyId = null, $request = null)
    {
        try {
            $user = Auth::user();
            $company = $user->company;
            $timezone = $request->get('timezone', 'America/Lima');
            $targetDateInput = $request->get('date');
            $targetDate = $targetDateInput ? Carbon::parse($targetDateInput, $timezone) : Carbon::now($timezone);
            
            $targetDay = $targetDate->format('Y-m-d');
            $todayLima = Carbon::now($timezone)->format('Y-m-d');
            
            // 1. Bloqueo de fechas futuras
            if ($targetDay > $todayLima) {
                return $this->errorResponse('No se pueden consultar rutas de fechas futuras.', 422);
            }

            $targetStartUTC = $targetDate->copy()->startOfDay()->utc();
            $targetEndUTC = $targetDate->copy()->endOfDay()->utc();
            $isToday = $targetDay === $todayLima;

            \Log::info("Rutas Activas Consulted", [
                'target_day' => $targetDay,
                'is_today' => $isToday,
                'start_utc' => $targetStartUTC->toDateTimeString(),
                'end_utc' => $targetEndUTC->toDateTimeString(),
                'timezone' => $timezone
            ]);

            $routes = Seller::with([
                'user:id,name',
                // FILTRO ORIGINAL: Solo sesiones activas (sin logout)
                // 'user.sessionLogs' => function ($q) use ($today) {
                //     $q->whereDate('login_at', $today)
                //         ->whereNull('logout_at');
                // },
                'user.sessionLogs' => function ($q) use ($targetDay, $timezone) {
                    $q->whereDate('login_at', $targetDay)
                      ->orderBy('login_at', 'asc');
                },
                'city:id,name,country_id',
                'city.country:id,name,timezone'
            ])
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'desc');

            // Filtro para rol 11: solo sellers asociados en UserRoute
            if ($user->role_id == 11) {
                $sellerIds = UserRoute::where('user_id', $user->id)->pluck('seller_id')->toArray();
                $routes->whereIn('id', $sellerIds);
            }

            switch ($user->role_id) {
                case 1:
                    if ($companyId) {
                        $routes->where('company_id', $companyId);
                    }
                    break;
                case 2:
                    $routes->where('company_id', $company->id);
                    break;
                case 3:
                    $routes->where('user_id', $user->id);
                    break;
                default:
                    if ($user->role_id != 11) {
                        return $this->successResponse(['data' => []]);
                    }
            }

            if ($search) {
                $routes->where(function ($query) use ($search) {
                    $query->whereHas('user', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })->orWhereHas('city', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })->orWhereHas('city.country', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    });
                });
            }

            // NUEVOS FILTROS
            if ($countryId) {
                $routes->whereHas('city.country', function ($q) use ($countryId) {
                    $q->where('id', $countryId);
                });
            }
            if ($cityId) {
                $routes->where('city_id', $cityId);
            }
            if ($sellerId) {
                $routes->where('user_id', $sellerId);
            }

            // FILTRO ORIGINAL: Solo rutas con sesiones activas (sin logout)
            // $routesList = $routes->whereHas('user.sessionLogs', function ($q) use ($today) {
            //     $q->whereDate('login_at', $today)
            //         ->whereNull('logout_at');
            // })->get([

            $routesList = $routes->get([
                        'id',
                        'user_id',
                        'city_id',
                        'company_id',
                        'status',
                        'created_at'
                    ]);

            // ─── PRE-LOAD BATCH DATA (elimina queries N+1) ───────────────
            $routeIds = $routesList->pluck('id');

            // 1. Liquidaciones de hoy para todos los vendedores (1 query)
            $liquidationsToday = Liquidation::whereIn('seller_id', $routeIds)
                ->whereDate('date', $targetDay)
                ->get()
                ->keyBy('seller_id');

            // 2. Auditorías de esas liquidaciones (1 query)
            $liquidationIds = $liquidationsToday->pluck('id');
            $auditsToday = \App\Models\LiquidationAudit::with('user')
                ->whereIn('liquidation_id', $liquidationIds)
                ->whereIn('action', ['updated', 'created', 'Aprobación administrativa'])
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->get()
                ->groupBy('liquidation_id');

            // 3. Rutas con liquidaciones pendientes de días anteriores (1 query)
            $previousPendingSellerIds = Liquidation::whereIn('seller_id', $routeIds)
                ->whereDate('date', '<', $targetDay)
                ->whereIn('status', ['pending', 'auto', 'En curso'])
                ->pluck('seller_id')
                ->unique()
                ->flip();


            // 5. Todos los pagos de hoy para los sellers (para el resumen de ruta)
            $allPaymentsToday = \App\Models\Payment::with('credit:id,seller_id', 'credit.client:id,name')
                ->whereHas('credit', function ($q) use ($routeIds) {
                    $q->whereIn('seller_id', $routeIds);
                })
                ->whereDate('payment_date', $targetDay) // Usamos payment_date para consistencia de negocio
                ->orderBy('created_at', 'asc')
                ->get()
                ->groupBy(function ($payment) {
                    return $payment->credit->seller_id;
                });
            // ─────────────────────────────────────────────────────────────────

            $data = $routesList->map(function ($route) use ($targetDay, $targetStartUTC, $targetEndUTC, $liquidationsToday, $auditsToday, $previousPendingSellerIds, $allPaymentsToday) {
                $liquidationToday = $liquidationsToday->get($route->id);


                $liquidationOpen = null;
                $liquidationClosed = null;
                $liquidationAuditId = null;
                $closedBySellerToday = false;
                $liquidationStatus = null;

                $sellerPaymentsToday = $allPaymentsToday->get($route->id) ?? collect();
                $firstPayment = $sellerPaymentsToday->first();
                $lastPayment = $sellerPaymentsToday->last();

                if ($liquidationToday) {
                    $liquidationStatus = $liquidationToday->status;
                    $liquidationOpen = $liquidationToday->date;

                    $lastAudit = ($auditsToday->get($liquidationToday->id) ?? collect())
                        ->where('user_id', $route->user_id)
                        ->first();
                    $liquidationClosed = $liquidationToday->end_date ?? null;
                    if ($lastAudit) {
                        $liquidationAuditId = $lastAudit->id;
                        $closedBySellerToday = $lastAudit->action === 'updated' || $lastAudit->action === 'created';
                    }
                }

                // Determinar si existe auditoría del vendedor o admin en la liquidación de hoy
                $auditExists = false;
                $closedByAdmin = false;
                $closingRoleId = null;
                if ($liquidationToday) {
                    $lastClosureAudit = ($auditsToday->get($liquidationToday->id) ?? collect())->first();

                    if ($lastClosureAudit) {
                        $auditExists = true;
                        $closingRoleId = $lastClosureAudit->user->role_id ?? null;
                        if ($lastClosureAudit->action === 'Aprobación administrativa') {
                            $closedByAdmin = true;
                        }
                    }
                }

                // Regla para is_open:
                // - Si no hay liquidación hoy => abierta (true)
                // - Si el estado es 'approved' => cerrada (false)
                // - Si el estado es 'pending' o 'auto' y existe auditoría => cerrada (false)
                $isOpen = true;
                if ($liquidationToday) {
                    if ($liquidationStatus === 'approved' || in_array($liquidationStatus, ['pending', 'auto']) || $auditExists) {
                        $isOpen = false;
                    }
                }

                // Verificar si hay liquidaciones pendientes de días anteriores (pre-cargado)
                $hasPreviousPending = isset($previousPendingSellerIds[$route->id]);

                // Considerar cerrado si tiene status correcto o auditoria
                $isClosed = $liquidationToday && (in_array($liquidationStatus, ['pending', 'auto', 'approved']) || $auditExists);


                $sessionLogs = $route->user ? $route->user->sessionLogs : collect([]);
                
                // Encontrar el log de inicio de ruta:
                // 1. Un log que haya iniciado hoy (rango UTC)
                // 2. O el log activo más reciente si empezó antes de hoy
                // 3. O simplemente el log más reciente disponible
                $firstLog = $sessionLogs->whereBetween('login_at', [$targetStartUTC, $targetEndUTC])->sortBy('login_at')->first()
                          ?? $sessionLogs->where('login_at', '<', $targetStartUTC)->whereNull('logout_at')->sortByDesc('login_at')->first()
                          ?? $sessionLogs->sortByDesc('login_at')->first();

                // Si aún así no hay log, usamos la fecha de la liquidación de hoy como último recurso
                $fallbackDate = $liquidationToday?->created_at 
                             ?? ($liquidationToday?->date ? Carbon::parse($liquidationToday->date) : null);
                
                // PRIORIDAD: 
                // 1. Si hay una liquidación para hoy (Apertura), esa es la hora de inicio oficial.
                // 2. Si no, usamos el log de sesión detectado.
                $firstLog = $sessionLogs
                    ->where('login_at', '>=', $targetStartUTC->copy()->startOfDay())
                    ->sortBy('login_at')
                    ->first();

                // PRIORIDAD: 1. Primer Login del día, 2. Apertura de caja, 3. Primer Pago (fallback visual)
                $routeStartDate = null;
                if ($firstLog && $firstLog->login_at) {
                    $routeStartDate = \Carbon\Carbon::parse($firstLog->login_at)->format('Y-m-d H:i:s');
                } elseif ($liquidationOpen) {
                    $routeStartDate = $liquidationOpen->format('Y-m-d') . ' ' . $liquidationToday->created_at->format('H:i:s');
                } elseif ($firstPayment) {
                    $routeStartDate = \Carbon\Carbon::parse($firstPayment->created_at)->format('Y-m-d H:i:s');
                }
                return [
                    'route_id' => $route->id,
                    'country' => $route->city->country->name ?? null,
                    'city' => $route->city->name ?? null,
                    'seller_name' => $route->user->name ?? null,
                    'status' => $route->status,
                    'closed_today' => $isClosed,
                    'closed_by_admin' => $closedByAdmin,
                    'closing_role_id' => $closingRoleId,
                    'has_previous_pending' => $hasPreviousPending,
                    'liquidation_open' => $liquidationToday?->date ? \Carbon\Carbon::parse($liquidationToday->date)->format('Y-m-d') : null,
                    'liquidation_closed' => $liquidationClosed,
                    'liquidation_audit_id' => $liquidationAuditId,
                    'liquidation_status' => $liquidationStatus,
                    'is_approved' => $liquidationStatus === 'approved',
                    'seller_finished_at' => $liquidationToday?->created_at ? \Carbon\Carbon::parse($liquidationToday->created_at)->format('Y-m-d H:i:s') : null,
                    'admin_finished_at' => ($liquidationStatus === 'approved') 
                        ? ($liquidationToday?->end_date ? \Carbon\Carbon::parse($liquidationToday->end_date)->format('Y-m-d H:i:s') : \Carbon\Carbon::parse($liquidationToday->updated_at)->format('Y-m-d H:i:s')) 
                        : null,
                    'is_open' => $isOpen,
                    'created_at' => $routeStartDate ? \Carbon\Carbon::parse($routeStartDate)->format('Y-m-d H:i:s') : null,
                    'session_logs' => $sessionLogs->map(function ($log) {
                        $parsed = \App\Helpers\Helper::parseUserAgent($log->user_agent);
                        return [
                            'id' => $log->id,
                            'login_at' => \Carbon\Carbon::parse($log->login_at)->format('Y-m-d H:i:s'),
                            'logout_at' => $log->logout_at ? \Carbon\Carbon::parse($log->logout_at)->format('Y-m-d H:i:s') : null,
                            'ip' => $log->ip,
                            'connection_country' => 'Desconocido', // Obtenido por Jobs o DB si fuese necesario para no saturar timeouts
                            'device' => $parsed['device'] . ' (' . $parsed['os'] . ')',
                            'browser' => $parsed['browser'],
                            'is_active' => is_null($log->logout_at),
                        ];
                    }),
                    'route_summary' => [
                        'start_location' => $firstPayment ? [
                            'lat' => $firstPayment->latitude,
                            'lng' => $firstPayment->longitude,
                            'time' => $firstPayment->created_at->format('H:i:s'),
                            'description' => 'Inicio de cobranza'
                        ] : null,
                        'end_location' => $lastPayment ? [
                            'lat' => $lastPayment->latitude,
                            'lng' => $lastPayment->longitude,
                            'time' => $lastPayment->created_at->format('H:i:s'),
                            'description' => 'Final de cobranza'
                        ] : null,
                        'collection_points' => $sellerPaymentsToday->filter(fn($p) => $p->latitude && $p->longitude)->map(function ($p) {
                            $duration = null;
                            if ($p->client_created_at && $p->business_timestamp) {
                                try {
                                    $arrival = Carbon::parse($p->client_created_at);
                                    $finish = Carbon::parse($p->business_timestamp);
                                    $duration = $finish->diffInSeconds($arrival);
                                } catch (\Exception $e) {}
                            }
                            return [
                                'lat' => $p->latitude,
                                'lng' => $p->longitude,
                                'amount' => $p->amount,
                                'client_name' => $p->credit->client->name ?? 'Cliente',
                                'time' => Carbon::parse($p->created_at)->format('H:i:s'),
                                'duration_seconds' => $duration
                            ];
                        })->values()
                    ]
                ];
            });

            if ($hasLiquidation !== null) {
                $data = $data->filter(function ($item) use ($hasLiquidation) {
                    if ($hasLiquidation === 'started') {
                        // "Iniciaron Ruta" significa que tienen al menos un log de sesión Y han abierto caja.
                        return count($item['session_logs']) > 0 && $item['liquidation_open'] !== null;
                    }
                    if ($hasLiquidation === '0') {
                        // "No Cerró" ahora excluye a los que ni siquiera han abierto.
                        return !$item['closed_today'] && $item['liquidation_open'] !== null;
                    }
                    if ($hasLiquidation === '1') {
                        // "Cerró Caja Hoy"
                        return $item['closed_today'];
                    }
                    return true;
                })->values();
            }

            return $this->successResponse([
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener las rutas activas');
        }
    }

    private function updateProfilePhoto(Seller $seller, UploadedFile $file)
    {
        if ($seller->images()->where('type', 'profile')->exists()) {
            $oldImage = $seller->images()->where('type', 'profile')->first();
            Helper::deleteFile($oldImage->path);
            $oldImage->delete();
        }

        $profilePath = Helper::uploadFile($file, 'sellers/profiles');

        $seller->images()->create([
            'path' => $profilePath,
            'type' => 'profile'
        ]);
    }

    private function validateImages($request)
    {
        $images = $request->all()['images'];
        $profileCount = 0;

        foreach ($images as $index => $imageData) {
            if (!isset($imageData['file']) || !isset($imageData['type'])) {
                return $this->errorResponse('Cada imagen debe contener un archivo y un tipo ("profile" o "gallery").', 400);
            }

            if ($imageData['type'] === 'profile') {
                $profileCount++;
            }
            if ($profileCount > 1) {
                return $this->errorResponse('Solo se permite una foto de perfil.', 400);
            }


            $imageFile = $request->file("images.{$index}.file");

            if ($imageFile->getSize() > 2 * 1024 * 1024) {
                return $this->errorResponse("La imagen {$index} excede 2MB", 400);
            }
            if (!$imageFile) {
                return $this->errorResponse('No se encontró la imagen en la solicitud.', 400);
            }

            /*  if (!$imageFile instanceof \UploadedFile) {
                return $this->errorResponse('Formato incorrecto de imágenes.', 400);
            }
 */
            if (!in_array($imageData['type'], ['profile'])) {
                return $this->errorResponse('El tipo de imagen es requerido y debe ser "profile" o "gallery".', 400);
            }
        }

        return true;
    }

    public function delete($sellerId, $timezone = null)
    {
        try {
            $seller = Seller::with('user')->find($sellerId);

            if ($seller == null) {
                return $this->errorNotFoundResponse('Vendedor no encontrado');
            }

            $userRoutes = UserRoute::where('seller_id', $sellerId)->get();

            foreach ($userRoutes as $userRoute) {
                if ($timezone) {
                    $userRoute->deleted_at = Carbon::now($timezone);
                    $userRoute->save();
                    $userRoute->delete();
                } else {
                    $userRoute->delete();
                }
            }

            if ($timezone) {
                $seller->deleted_at = Carbon::now($timezone);
                $seller->save();
                $seller->delete();
                if ($seller->user) {
                    $seller->user->deleted_at = Carbon::now($timezone);
                    $seller->user->save();
                    $seller->user->delete();
                }
            } else {
                $seller->delete();
                if ($seller->user) {
                    $seller->user->delete();
                }
            }

            \Log::info('Eliminando vendedor con ID: ' . $sellerId);
            \Log::info('Vendedor: ' . $seller);
            return $this->successResponse([
                'success' => true,
                'message' => "Vendedor eliminado con éxito"
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al eliminar el vendedor');
        }
    }

    public function getRoutes($page = 1, $perPage = 10, $search = null, $countryId = null, $cityId = null, $companyId = null)
    {
        try {
            $user = Auth::user();

            // Generate unique cache key based on all parameters
            $cacheKey = sprintf(
                'sellers_list_u%s_p%s_pp%s_s%s_co%s_ci%s_comp%s_r%s',
                $user->id,
                $page,
                $perPage,
                $search ?? 'null',
                $countryId ?? 'null',
                $cityId ?? 'null',
                $companyId ?? 'null',
                $user->role_id
            );

            // Cache for 30 seconds
            return Cache::remember($cacheKey, 30, function () use ($user, $page, $perPage, $search, $countryId, $cityId, $companyId) {
                $company = $user->company;

                $routes = Seller::with([
                    'userRoutes.user',
                    'city.country',
                    'user',
                    'images',
                    'company',
                    'config'
                ])
                    ->withCount([
                        'credits as credits_count' => function ($query) {
                            $query->whereNotIn('status', ['Cartera Irrecuperable', 'Liquidado'])
                                ->whereNull('deleted_at');
                        }
                    ])
                    // Suma solo del capital
                    ->withSum([
                        'credits as credits_value_sum' => function ($query) {
                            $query->whereNotIn('status', ['Cartera Irrecuperable', 'Liquidado'])
                                ->whereNull('deleted_at');
                        }
                    ], 'credit_value')
                    // Suma solo de la utilidad monetaria
                    ->addSelect([
                        'credits_utility_sum' => DB::table('credits')
                            ->selectRaw('COALESCE(SUM(credit_value * total_interest / 100), 0)')
                            ->whereColumn('seller_id', 'sellers.id')
                            ->whereNotIn('status', ['Cartera Irrecuperable', 'Liquidado'])
                            ->whereNull('deleted_at'),
                        // Suma del capital + utilidad monetaria
                        'credits_total_sum' => DB::table('credits')
                            ->selectRaw('COALESCE(SUM(credit_value + credit_value * total_interest / 100), 0)')
                            ->whereColumn('seller_id', 'sellers.id')
                            ->whereNotIn('status', ['Cartera Irrecuperable', 'Liquidado'])
                            ->whereNull('deleted_at'),
                        // Cartera recuperada
                        'recovered_portfolio' => DB::table('payments')
                            ->selectRaw('COALESCE(SUM(amount), 0)')
                            ->whereIn('credit_id', function ($query) {
                                $query->select('id')
                                    ->from('credits')
                                    ->whereColumn('seller_id', 'sellers.id')
                                    ->whereNotIn('status', ['Cartera Irrecuperable', 'Liquidado'])
                                    ->whereNull('deleted_at');
                            })
                            ->whereNull('deleted_at'),
                    ])
                    ->whereNull('deleted_at')
                    ->orderBy('created_at', 'desc');

                // Filtro para rol 11: solo sellers asociados en UserRoute
                if ($user->role_id == 11) {
                    $sellerIds = UserRoute::where('user_id', $user->id)->pluck('seller_id')->toArray();
                    $routes->whereIn('id', $sellerIds);
                }

                switch ($user->role_id) {
                    case 1:
                        if ($companyId) {
                            $routes->where('company_id', $companyId);
                        }
                        break;

                    case 2:
                        $routes->where('company_id', $company->id);
                        break;

                    case 3:
                        $routes->where('user_id', $user->id);
                        break;

                    default:
                        if ($user->role_id != 11) {
                            return $this->successResponse([
                                'data' => [],
                                'pagination' => [
                                    'total' => 0,
                                    'current_page' => 1,
                                    'per_page' => $perPage,
                                    'last_page' => 1,
                                ]
                            ]);
                        }
                }

                // Resto del código de búsqueda y paginación...
                if ($search) {
                    $routes->where(function ($query) use ($search) {
                        $query->whereHas('user', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%");
                        })->orWhereHas('city', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%");
                        })->orWhereHas('city.country', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%");
                        });
                    });
                }

                if ($countryId) {
                    $routes->whereHas('city.country', function ($q) use ($countryId) {
                        $q->where('id', $countryId);
                    });
                }

                // Filtro por ciudad
                if ($cityId) {
                    $routes->where('city_id', $cityId);
                }

                $routesQuery = $routes->paginate(
                    $perPage,
                    ['id', 'uuid', 'user_id', 'city_id', 'company_id', 'status', 'created_at'],
                    'page',
                    $page
                );

                return $this->successResponse([
                    'data' => $routesQuery->items(),
                    'pagination' => [
                        'total' => $routesQuery->total(),
                        'current_page' => $routesQuery->currentPage(),
                        'per_page' => $routesQuery->perPage(),
                        'last_page' => $routesQuery->lastPage(),
                    ]
                ]);
            });
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener las rutas');
        }
    }

    public function getRoutesSelect()
    {
        try {
            $user = Auth::user();
            $query = Seller::with('user:id,name,dni')
                ->withCount([
                    'clients',
                    'credits' => function ($query) {
                        $query->whereNotIn('status', ['Cartera Irrecuperable', 'Liquidado'])
                              ->whereNull('deleted_at');
                    }
                ]);

            if ($user->role_id == 2) {
                $companyId = $user->company ? $user->company->id : -1;
                $query->where('company_id', $companyId);
            } elseif ($user->role_id == 5) {
                $query->where('user_id', $user->id);
            }

            $routes = $query->get(['id', 'uuid', 'user_id', 'company_id']); 

            return $this->successResponse([
                'success' => true,
                'data' => $routes
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al obtener las rutas');
        }
    }

    public function toggleStatus($routeId, $status)
    {
        try {
            $route = Seller::find($routeId);

            if ($route == null) {
                return $this->errorNotFoundResponse('Ruta no encontrada');
            }

            $route->status = $status;
            $route->save();

            return $this->successResponse([
                'success' => true,
                'message' => "Estado de la ruta actualizado con éxito",
                'data' => $route
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al actualizar el estado de la ruta', 500);
        }
    }

    /**
     * Get seller cash info
     */
    public function getCashInfo($sellerId)
    {
        try {
            $timezone = 'America/Lima'; // Standardize timezone
            $today = Carbon::now($timezone)->toDateString();

            // Get last two liquidations to get current and previous cash
            $lastTwoLiquidations = Liquidation::where('seller_id', $sellerId)
                ->orderBy('date', 'DESC')
                ->limit(2)
                ->get();

            $latestLiq = $lastTwoLiquidations->first();
            $previousLiq = $lastTwoLiquidations->skip(1)->first();

            $currentCash = 0;
            $previousCash = $previousLiq ? $previousLiq->real_to_deliver : 0;

            if ($latestLiq && $latestLiq->date->toDateString() === $today) {
                // If today's liquidation already exists, use its value (which should be recalculated by the app or on demand)
                $currentCash = $latestLiq->real_to_deliver;
                $previousCash = $previousLiq ? $previousLiq->real_to_deliver : 0;
            } else {
                // If today's liquidation doesn't exist yet, we calculate it dynamically
                // Previous cash is actually the latest liquidations real_to_deliver
                $previousCash = $latestLiq ? $latestLiq->real_to_deliver : 0;

                $liquidationService = app(\App\Services\LiquidationService::class);
                $seller = Seller::find($sellerId);
                if ($seller) {
                    $dynamicData = $liquidationService->getLiquidationData($sellerId, $today, $seller->user_id, $timezone);
                    $currentCash = $dynamicData['real_to_deliver'] ?? 0;
                }
            }

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'current_cash' => floatval($currentCash),
                    'previous_cash' => floatval($previousCash),
                    'is_live' => !($latestLiq && $latestLiq->date->toDateString() === $today)
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error getting seller cash info: ' . $e->getMessage());
            return $this->errorResponse('Error al obtener la información de caja: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get seller liquidations
     */
    public function getLiquidations($sellerId, $limit = 10)
    {
        try {
            $timezone = 'America/Lima';
            $today = Carbon::now($timezone)->toDateString();

            $liquidations = Liquidation::with(['audits.user' => function($q) {
                    $q->select('id', 'name');
                }])
                ->where('seller_id', $sellerId)
                ->orderBy('date', 'DESC')
                ->limit($limit)
                ->get();

            $hasToday = $liquidations->contains(function ($liq) use ($today) {
                return $liq->date->toDateString() === $today;
            });

            $liquidationService = app(\App\Services\LiquidationService::class);

            if (!$hasToday) {
                $seller = Seller::find($sellerId);
                if ($seller) {
                    $dynamicData = $liquidationService->getLiquidationData($sellerId, $today, $seller->user_id, $timezone);
                    
                    // Create a virtual liquidation object for the list
                    $virtualToday = new Liquidation([
                        'id' => null, // null ID indicates it's virtual/in-progress
                        'seller_id' => $sellerId,
                        'date' => Carbon::now($timezone),
                        'status' => 'En curso',
                        'initial_cash' => floatval($dynamicData['initial_cash'] ?? 0),
                        'total_collected' => floatval($dynamicData['total_collected'] ?? 0),
                        'total_expenses' => floatval($dynamicData['total_expenses'] ?? 0),
                        'total_income' => floatval($dynamicData['total_income'] ?? 0),
                        'new_credits' => floatval($dynamicData['new_credits'] ?? 0),
                        'renewal_disbursed_total' => floatval($dynamicData['total_renewal_disbursed'] ?? 0),
                        'total_pending_absorbed' => floatval($dynamicData['total_pending_absorbed'] ?? 0),
                        'irrecoverable_credits_amount' => floatval($dynamicData['irrecoverable_credits'] ?? 0),
                        'poliza' => floatval($dynamicData['poliza'] ?? 0),
                        'real_to_deliver' => floatval($dynamicData['real_to_deliver'] ?? 0),
                        'clients_paid_count' => intval($dynamicData['clients_paid_count'] ?? 0),
                        'clients_without_credit_count' => intval($dynamicData['clients_without_credit_count'] ?? 0),
                        'new_clients_count' => intval($dynamicData['new_clients_count'] ?? 0),
                        'active_clients_with_credit_count' => intval($dynamicData['active_clients_with_credit_count'] ?? 0),
                        'clients_liquidated_count' => intval($dynamicData['clients_liquidated_count'] ?? 0),
                        'clients_full_payment_count' => intval($dynamicData['clients_full_payment_count'] ?? 0),
                        'clients_partial_payment_count' => intval($dynamicData['clients_partial_payment_count'] ?? 0),
                        'clients_liquidated_and_renewed_count' => intval($dynamicData['clients_liquidated_and_renewed_count'] ?? 0),
                        'cash_delivered' => 0,
                        'shortage' => 0,
                        'surplus' => 0,
                    ]);
                    
                    // Explicitly set date and status just in case
                    $virtualToday->date = Carbon::now($timezone);
                    $virtualToday->status = 'En curso';
                    
                    // For virtual records, audits will naturally be empty as there's no ID to link them to
                    $virtualToday->setRelation('audits', collect());

                    $liquidations = collect([$virtualToday])->concat($liquidations)->take($limit);
                }
            }

            // Map and format for response
            $formattedLiquidations = $liquidations->map(function ($liq) use ($liquidationService) {
                // We use setRelation or manual mapping to ensure audits are present
                $data = $liq->toArray();
                
                // Ensure audits use the standardized format the frontend expects
                if ($liq->relationLoaded('audits')) {
                    $data['audits'] = $liq->audits->map(function ($audit) {
                        return [
                            'id' => $audit->id,
                            'user_id' => $audit->user_id,
                            'user_name' => optional($audit->user)->name,
                            'action' => $audit->action,
                            'changes' => $audit->changes,
                            'created_at' => $audit->created_at ? $audit->created_at->format('Y-m-d H:i:s') : null
                        ];
                    });
                } else {
                    $data['audits'] = [];
                }
                
                return $data;
            });

            return $this->successResponse([
                'success' => true,
                'data' => $formattedLiquidations
            ]);
        } catch (\Exception $e) {
            \Log::error('Error getting seller liquidations: ' . $e->getMessage());
            return $this->errorResponse('Error al obtener las liquidaciones: ' . $e->getMessage(), 500);
        }
    }
}
