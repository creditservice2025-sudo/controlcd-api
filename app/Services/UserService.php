<?php

namespace App\Services;

use Hash;
use App\Models\User;
use App\Models\Client;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\UserRoute;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserService
{

    use ApiResponse;


    public function create($params)
    {
        DB::beginTransaction();
        try {
            if ($params['role_id'] == 'super-admin') {
                return $this->errorResponse('No tienes permisos para crear este usuario', 403);
            }

            $params['password'] = Hash::make($params['password']);

            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $params['created_at'] = \Carbon\Carbon::now($params['timezone']);
                $params['updated_at'] = \Carbon\Carbon::now($params['timezone']);
                $userTimezone = $params['timezone'];
                unset($params['timezone']); // Evitar error en fillable
            } else {
                $userTimezone = null;
            }

            $user = User::create($params);

            $createdAt = isset($params['created_at']) ? $params['created_at'] : null;
            $updatedAt = isset($params['updated_at']) ? $params['updated_at'] : null;

            if (isset($params['seller_id']) && $params['seller_id']) {
                if (is_array($params['seller_id'])) {
                    foreach ($params['seller_id'] as $sid) {
                        UserRoute::create([
                            'user_id' => $user->id,
                            'seller_id' => $sid,
                            'created_at' => $createdAt,
                            'updated_at' => $updatedAt
                        ]);
                    }
                } else {
                    UserRoute::create([
                        'user_id' => $user->id,
                        'seller_id' => $params['seller_id'],
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt
                    ]);
                }
            }

            // Asignar rutas al usuario
            if (!empty($params['routes'])) {
                foreach ($params['routes'] as $routeId) {
                    $user->routes()->attach($routeId);
                }
            }

            $user->parent_id = $params['role_id'];
            $user->save();
            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => "Miembro creado con éxito",
                'data' => $user
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al crear el miembro', 500);
        }
    }

    public function update($userId, $params)
    {
        DB::beginTransaction();
        try {
            $user = User::find($userId);

            if ($user == null) {
                return $this->errorNotFoundResponse('Miembro no encontrado');
            }

            if (isset($params['password'])) {
                $params['password'] = Hash::make($params['password']);
            } else {
                unset($params['password']);
            }

            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $params['updated_at'] = \Carbon\Carbon::now($params['timezone']);
                $userTimezone = $params['timezone'];
                unset($params['timezone']);
            } else {
                $userTimezone = null;
            }

            $user->update($params);

            if (isset($params['routes'])) {
                $pivotData = [];
                if ($userTimezone) {
                    $now = \Carbon\Carbon::now($userTimezone);
                    foreach ($params['routes'] as $routeId) {
                        $pivotData[$routeId] = ['updated_at' => $now];
                    }
                    $user->routes()->sync($pivotData);
                } else {
                    $user->routes()->sync($params['routes']);
                }
            }
            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => "Miembro actualizado con éxito",
                'data' => $user
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al actualizar el miembro', 500);
        }
    }

    public function delete($userId, $timezone = null)
    {
        try {
            $user = User::find($userId);

            if ($user == null) {
                return $this->errorNotFoundResponse('Miembro no encontrado');
            }

            $userRoute = UserRoute::where('user_id', $userId)->get();

            foreach ($userRoute as $route) {
                if ($timezone) {
                    $route->deleted_at = \Carbon\Carbon::now($timezone);
                    $route->save();
                    $route->delete();
                } else {
                    $route->delete();
                }
            }

            if ($timezone) {
                $user->deleted_at = \Carbon\Carbon::now($timezone);
                $user->save();
                $user->delete();
            } else {
                $user->delete();
            }

            return $this->successResponse([
                'success' => true,
                'message' => "Miembro eliminado con éxito",
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al eliminar el miembro');
        }
    }

public function me()
{
    /** @var \App\Models\User|null $user */
    $user = Auth::user();

    if (!$user || !($user instanceof \App\Models\User)) {
        return $this->errorResponse('No autenticado', 401);
    }

    $roles = $user->getRoleNames(); 
    $permissions = $user->getAllPermissions()->pluck('name'); 

    if ($roles->isEmpty() && !empty($user->role_id)) {
        $roleModel = \Spatie\Permission\Models\Role::find($user->role_id);
        if ($roleModel) {
            $roles = collect([$roleModel->name]);
            $permissions = $roleModel->permissions()->pluck('name');
        } else {
            $roleFromTable = \DB::table('roles')->where('id', $user->role_id)->value('name');
            if ($roleFromTable) {
                $roles = collect([$roleFromTable]);
                $permissions = collect();
            }
        }
    }

    return $this->successResponse([
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'roles' => $roles,
        'permissions' => $permissions,
    ]);
}

    public function getUsers(string $search, int $perpage, $companyId = null)
    {
        try {
            $user = Auth::user();
            $roleId = $user->role_id;
            $company = $user->company;
            $seller = $user->seller;
    
            $excludedRoleIds = Role::whereIn('name', ['super-admin', 'cobrador', 'admin'])->pluck('id')->toArray();
    
            $usersQuery = User::query()
                ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
                ->select(
                    'users.id',
                    'users.uuid',
                    'users.name',
                    'users.email',
                    'users.phone',
                    'users.address',
                    'users.dni',
                    'users.city_id',
                    'users.parent_id',
                    'users.role_id',
                    'users.status',
                    'roles.name as role_name'
                )
                ->with(['city', 'city.country'])
                ->whereNull('users.deleted_at')
                ->whereNotIn('users.role_id', $excludedRoleIds);
    
            // === FILTRO POR ROL ===
            switch ($roleId) {
                case 1: // Admin: ve todos
                    // Si el admin está en modo empresa, filtra por company_id
                    if ($companyId) {
                        $sellerIds = Seller::where('company_id', $companyId)->pluck('id')->toArray();
                        $userIds = UserRoute::whereIn('seller_id', $sellerIds)->pluck('user_id')->toArray();
                        $usersQuery->whereIn('users.id', $userIds);
                    }
                    break;
                case 2: // Empresa: usuarios relacionados a la empresa por sellers
                    if ($company) {
                        // Trae IDs de vendedores de la empresa
                        $sellerIds = Seller::where('company_id', $company->id)->pluck('id')->toArray();
    
                        // Trae IDs de usuarios asociados a esos vendedores vía users_routes
                        $userIds = UserRoute::whereIn('seller_id', $sellerIds)->pluck('user_id')->toArray();
    
                        // Incluye también al usuario empresa autenticado
                        $userIds[] = $user->id;
    
                        // Filtra por esos usuarios
                        $usersQuery->whereIn('users.id', $userIds);
                    }
                    break;
                default: // Otros roles: no ven nada
                    $usersQuery->whereRaw('0 = 1');
                    break;
            }
    
            // Filtro de búsqueda
            if (!empty(trim($search))) {
                $usersQuery->where(function ($query) use ($search) {
                    $query->where('users.name', 'like', '%' . $search . '%')
                        ->orWhere('users.email', 'like', '%' . $search . '%')
                        ->orWhere('users.phone', 'like', '%' . $search . '%')
                        ->orWhere('users.address', 'like', '%' . $search . '%')
                        ->orWhere('users.dni', 'like', '%' . $search . '%');
                });
            }
    
            $users = $usersQuery
                ->orderByDesc('users.created_at')
                ->paginate($perpage);
    
            return $this->successResponse([
                'success' => true,
                'data' => $users
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener los miembros');
        }
    }
    public function getUser($userId)
    {
        try {

            $user = User::select([
                'users.id',
                'users.name',
                'users.email',
                'users.phone',
                'users.address',
                'users.dni',
                'users.city_id',
                'users.parent_id',
                'users.status',
                'users.role_id',
                'roles.name as role_name' // Agregamos el nombre del rol
            ])
                ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
                ->with(['city'/* , 'routes' */]) // Incluye relaciones necesarias
                ->where('users.id', $userId)
                ->orWhere('users.uuid', $userId)
                ->first();

            if ($user == null) {
                return $this->errorNotFoundResponse('Miembro no encontrado');
            }

            return $this->successResponse([
                'success' => true,
                'data' => $user
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al obtener el miembro');
        }
    }

    public function getUsersSelect(Request $request, $companyId = null)
    {
        try {
            $excludedRoleIds = Role::whereIn('name', ['super-admin', 'cobrador'])
                ->pluck('id')
                ->toArray();

            $query = User::select('id', 'name')
                ->whereNull('deleted_at')
                ->whereNotIn('role_id', $excludedRoleIds);

            if ($request->has('city_id') && !empty($request->city_id)) {
                $query->whereHas('city', function ($q) use ($request) {
                    $q->where('id', $request->city_id);
                });
            }

            // Filtrar por company_id si el admin está en modo empresa
            $user = Auth::user();
            if ($user->role_id == 1 && $companyId) {
                $sellerIds = Seller::where('company_id', $companyId)->pluck('id')->toArray();
                $userIds = UserRoute::whereIn('seller_id', $sellerIds)->pluck('user_id')->toArray();
                $query->whereIn('id', $userIds);
            }

            $users = $query->get();

            return $this->successResponse([
                'success' => true,
                'data' => $users
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener los miembros');
        }
    }

    public function getVendorsSelect(Request $request, $companyId = null)
    {
        try {
            $cobradorRoleId = Role::where('name', 'cobrador')->value('id');
            
            if (empty($cobradorRoleId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rol "cobrador" no encontrado'
                ], 404);
            }

            $query = User::select('id', 'name', 'email')
                ->whereNull('deleted_at')
                ->where('role_id', $cobradorRoleId);

            if ($request->has('city_id') && !empty($request->city_id)) {
                $query->whereHas('city', function ($q) use ($request) {
                    $q->where('id', $request->city_id);
                });
            }

            if ($request->has('country_id') && !empty($request->country_id)) {
                $query->whereHas('city.country', function ($q) use ($request) {
                    $q->where('id', $request->country_id);
                });
            }

            // Filtrar por company_id si el admin está en modo empresa
            $user = Auth::user();
            if ($user->role_id == 1 && $companyId) {
                $sellerIds = Seller::where('company_id', $companyId)->pluck('id')->toArray();
                $userIds = UserRoute::whereIn('seller_id', $sellerIds)->pluck('user_id')->toArray();
                $query->whereIn('id', $userIds);
            }

            $vendors = $query->get();

            return response()->json([
                'success' => true,
                'data' => $vendors
            ]);
        } catch (\Exception $e) {
            \Log::error('Error obteniendo vendedores: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los vendedores'
            ], 500);
        }
    }


    public function toggleStatus($userId, $status)
    {
        try {
            $user = User::find($userId);

            if ($user == null) {
                return $this->errorNotFoundResponse('Miembro no encontrado');
            }

            $user->status = $status;
            $user->save();

            return $this->successResponse([
                'success' => true,
                'message' => "Estado del miembro actualizado con éxito",
                'data' => $user
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al actualizar el estado del miembro', 500);
        }
    }

    public function getLiquidationsBySeller(int $sellerId, array $filters = [], int $perPage = 20)
    {
        try {
            $query = Liquidation::with(['seller'])
                ->where('seller_id', $sellerId);

            // Aplicar filtros adicionales
            $this->applyFilters($query, $filters);

            // Ordenar por defecto por fecha descendente
            $query->orderBy('date', 'desc');

            $liquidations = $query->paginate($perPage);

            return $this->successResponse([
                'success' => true,
                'data' => $liquidations
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener las liquidaciones', 500);
        }
    }


    /**
     * Aplica filtros adicionales a la consulta
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     */
    protected function applyFilters($query, array $filters): void
    {
        // Filtro por rango de fechas
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('date', [
                $filters['start_date'],
                $filters['end_date']
            ]);
        }

        // Filtro por estado
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filtro por faltantes
        if (isset($filters['has_shortage'])) {
            $query->where('shortage', '>', 0);
        }

        // Filtro por sobrantes
        if (isset($filters['has_surplus'])) {
            $query->where('surplus', '>', 0);
        }

        // Filtro por búsqueda general
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('status', 'like', $searchTerm)
                    ->orWhere('date', 'like', $searchTerm);
            });
        }
    }

    /**
     * Obtiene estadísticas de liquidaciones para un vendedor
     *
     * @param int $sellerId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSellerStats(int $sellerId)
    {
        try {
            $stats = [
                'total_liquidations' => Liquidation::where('seller_id', $sellerId)->count(),
                'pending_count' => Liquidation::where('seller_id', $sellerId)
                    ->where('status', 'pending')->count(),
                'average_collected' => Liquidation::where('seller_id', $sellerId)
                    ->avg('total_collected'),
                'total_shortage' => Liquidation::where('seller_id', $sellerId)
                    ->sum('shortage'),
                'total_surplus' => Liquidation::where('seller_id', $sellerId)
                    ->sum('surplus'),
            ];

            return $this->successResponse([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener estadísticas', 500);
        }
    }
}
