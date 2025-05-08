<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\Route;
use App\Models\UserRoute;

class RouteService
{

    use ApiResponse;

    public function create($params)
    {
        try {

            $route = Route::create($params);

            foreach ($params['members'] as $user) {
                $userRoute = new UserRoute();
                $userRoute->user_id = $user;
                $userRoute->route_id = $route->id;
                $userRoute->save();
            }

            return $this->successResponse([
                'success' => true,
                'message' => "Ruta creada con éxito",
                'data' => $route
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al crear la ruta');
        }
    }

    public function update($routeId, $params)
    {

        try {
            $route = Route::find($routeId);

            if ($route == null) {
                return $this->errorNotFoundResponse('Ruta no encontrada');
            }

            $route->update($params);

            // Ensure 'members' is an array
            $members = $params['members'] ?? [];

            // Delete user routes not in the new members list
            $userRoutes = UserRoute::where('route_id', $routeId)->get();
            foreach ($userRoutes as $userRoute) {
                if (!in_array($userRoute->user_id, $members)) {
                    $userRoute->delete();
                }
            }

            // Add new user routes
            foreach ($members as $user) {
                $userRoute = UserRoute::where('user_id', $user)->where('route_id', $routeId)->first();
                if ($userRoute == null) {
                    $userRoute = new UserRoute();
                    $userRoute->user_id = $user;
                    $userRoute->route_id = $routeId;
                    $userRoute->save();
                }
            }

            return $this->successResponse([
                'success' => true,
                'message' => "Ruta actualizada con éxito",
                'data' => $route
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al actualizar la ruta');
        }
    }

    public function delete($routeId)
    {
        try {
            $route = Route::find($routeId);

            if ($route == null) {
                return $this->errorNotFoundResponse('Ruta no encontrada');
            }

            $userRoutes = UserRoute::where('route_id', $routeId)->get();

            foreach ($userRoutes as $userRoute) {
                $userRoute->delete();
            }

            $route->delete();

            return $this->successResponse([
                'success' => true,
                'message' => "Ruta eliminada con éxito"
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al eliminar la ruta');
        }
    }

    public function getRoutes($search, $perPage)
    {
        try {
            $routes = Route::with('userRoutes.user')
                ->select('id', 'name', 'sector', 'status')
                // ->withSum(['credits as total_credits' => function ($query) {
                //     $query->where('active', true);
                // }], 'total_value')
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%')
                        ->orWhere('sector', 'like', '%' . $search . '%');
                })
                ->paginate($perPage);

            foreach ($routes as $route) {
                $route->total_credits = $route->total_credits ?? 0;
            }

            return $this->successResponse([
                'success' => true,
                'data' => $routes
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener las rutas');
        }
    }

    public function getRoutesSelect()
    {
        try {
            $routes = Route::select('id', 'name')->get();

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
            $route = Route::find($routeId);

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
}
