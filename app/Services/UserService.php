<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\User;
use App\Models\UserRoute;
use App\Models\Client;
use Hash;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserService {

    use ApiResponse;


    public function create($params)
    {
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

            return $this->successResponse([
                'success' => true,
                'message' => "Miembro creado con éxito",
                'data' => $user
            ]);

        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al crear el miembro', 500);
        }

    }

    public function update($userId, $params)
    {
        try {
            $user = User::find($userId);

            if ($user == null) {
                return $this->errorNotFoundResponse('Miembro no encontrado');
            }

            if (isset($params['role_id'])) {
                $parentUser = User::find($params['role_id']);
                if (!$parentUser) {
                    return $this->errorResponse('El usuario padre no existe', 400);
                }
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

            return $this->successResponse([
                'success' => true,
                'message' => "Miembro actualizado con éxito",
                'data' => $user
            ]);

        } catch (\Exception $e) {
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
    
            $users = User::select('id', 'name', 'email', 'phone', 'address', 'dni', 'city_id', 'parent_id', 'role_id', 'status')
                ->with('routes')
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('address', 'like', '%'.$search.'%')
                        ->orWhere('dni', 'like', '%'.$search.'%');
                })
                ->whereNull('deleted_at')
                ->whereDoesntHave('roles', function ($query) use ($superAdminRoleId) {
                    $query->where('id', $superAdminRoleId);
                })
                ->orderBy('created_at', 'desc')
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
                'id',
                'name',
                'email',
                'phone',
                'address',
                'dni',
                'city_id',
                'parent_id'
            ])
            ->with(['city', 'routes']) // Include related routes
            ->find($userId);

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

}