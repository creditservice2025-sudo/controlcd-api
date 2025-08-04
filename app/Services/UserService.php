<?php

namespace App\Services;

use Hash;
use App\Models\User;
use App\Models\Client;
use App\Models\Liquidation;
use App\Models\UserRoute;
use App\Traits\ApiResponse;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserService {

    use ApiResponse;


    public function create($params)
    {
        DB::beginTransaction();
        try {
            if ($params['role_id'] == 'super-admin') {
                return $this->errorResponse('No tienes permisos para crear este usuario', 403);
            }

            $params['password'] = Hash::make($params['password']);
            $user = User::create($params);

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

            $user->update($params);

            if (isset($params['routes'])) {
                $user->routes()->sync($params['routes']);
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

    public function delete($userId)
    {
        try {
            $user = User::find($userId);

            if($user == null) {
                return $this->errorNotFoundResponse('Miembro no encontrado');
            }

            $userRoute = UserRoute::where('user_id', $userId)->get();

            foreach($userRoute as $route){
                $route->delete();
            }

            $user->delete();

            return $this->successResponse([
                'success' => true,
                'message' => "Miembro eliminado con éxito",
            ]);

        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al eliminar el miembro');
        }
    }

    public function getUsers(string $search, int $perpage){
        try {
            $superAdminRoleId = Role::where('name', 'super-admin')->value('id');
    
            $users = User::query()
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
           /*  ->with(['routes:id,name,sector']) */
            ->where(function ($query) use ($search) {
                $query->where('users.name', 'like', '%'.$search.'%')
                    ->orWhere('users.email', 'like', '%'.$search.'%')
                    ->orWhere('users.phone', 'like', '%'.$search.'%')
                    ->orWhere('users.address', 'like', '%'.$search.'%')
                    ->orWhere('users.dni', 'like', '%'.$search.'%');
            })
            ->whereNull('users.deleted_at')
            ->where('users.role_id', '!=', $superAdminRoleId)
            ->orderByDesc('users.created_at')
            ->paginate($perpage);

            return $this->successResponse([
                'success' => true,
                'data' => $users
            ]);
    
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al obtener los miembros');
        }
    }

    public function getUser($userId){
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

            if($user == null) {
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

    public function getUsersSelect(){
        try {

            $superAdminRoleId = Role::where('name', 'super-admin')->value('id');

            $users = User::select('id', 'name')
            ->whereNull('deleted_at')
            ->whereDoesntHave('roles', function ($query) use ($superAdminRoleId) {
                $query->where('id', $superAdminRoleId);
            })
            ->get();

            return $this->successResponse([
                'success' => true,
                'data' => $users
            ]);

        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al obtener los miembros');
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