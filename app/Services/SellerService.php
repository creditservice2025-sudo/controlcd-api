<?php

namespace App\Services;

use App\Helpers\Helper;
use App\Http\Requests\Seller\SellerRequest;
use App\Traits\ApiResponse;
use App\Models\Seller;
use App\Models\User;
use App\Models\UserRoute;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Log;
use Illuminate\Support\Str;

class SellerService
{

    use ApiResponse;

    public function create(SellerRequest $request)
    {
        DB::beginTransaction();
    
        try {
            $params = $request->validated();
    
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
                'password' => Hash::make($params['password']),
                'role_id' => 5
            ]);
    
            $seller = Seller::create([
                'user_id' => $user->id,
                'city_id' => $params['city_id'],
                'company_id' => $params['company_id'],
                'status' => 'ACTIVE'
            ]);
    
            if (isset($params['members']) && is_array($params['members'])) {
                foreach ($params['members'] as $memberId) {
                    UserRoute::create([
                        'user_id' => $memberId,
                        'seller_id' => $seller->id
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
                        'type' => $imageData['type']
                    ]);
                }
            }
    
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
        DB::beginTransaction();

        try {
            $params = $request->validated();
            $seller = Seller::with(['user', 'images'])->findOrFail($sellerId);

            $seller->user->update([
                'name' => $params['name'],
                'email' => $params['email'],
                'dni' => $params['dni'],
                'password' => isset($params['password']) ? Hash::make($params['password']) : $seller->user->password
            ]);

            $seller->update([
                'city_id' => $params['city_id'],
            ]);

            $memberIds = array_map('intval', $params['members'] ?? []);
            $memberIds = array_filter($memberIds);

            $this->syncMembers($seller, $memberIds);


            if ($request->hasFile('profilePhoto')) {
                $this->updateProfilePhoto($seller, $request->file('profilePhoto'));
            }

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

    private function syncMembers(Seller $seller, array $memberIds)
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
                'user_id' => $memberId
            ]);
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

    public function delete($routeId)
    {
        try {
            $route = Seller::find($routeId);

            if ($route == null) {
                return $this->errorNotFoundResponse('Ruta no encontrada');
            }

            $userRoutes = UserRoute::where('seller_id', $routeId)->get();

            foreach ($userRoutes as $userRoute) {
                $userRoute->delete();
            }

            \Log::info('Eliminando ruta con ID: ' . $routeId);
            \Log::info('Ruta: ' . $route);
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

    public function getRoutes($page = 1, $perPage = 10, $search = null)
    {
        try {
            $user = Auth::user();
            $company = $user->company;

            $routes = Seller::with('userRoutes.user', 'city.country', 'user', 'images', 'company')
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'desc')
                ->select('id', 'user_id', 'city_id', 'company_id', 'status', 'created_at');

            switch ($user->role_id) {
                case 1:
                    break;

                case 2:
                    $routes->where('company_id', $company->id);
                    break;

                case 3:
                    $routes->where('user_id', $user->id);
                    break;

                default:
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

            // Resto del código de búsqueda y paginación...
            if ($search) {
                $searchTerm = Str::lower($search);
                $routes->where(function ($query) use ($searchTerm) {
                    $query->whereHas('user', function ($q) use ($searchTerm) {
                        $q->whereRaw('LOWER(name) LIKE ?', ["%{$searchTerm}%"]);
                    })->orWhereHas('city', function ($q) use ($searchTerm) {
                        $q->whereRaw('LOWER(name) LIKE ?', ["%{$searchTerm}%"]);
                    })->orWhereHas('city.country', function ($q) use ($searchTerm) {
                        $q->whereRaw('LOWER(name) LIKE ?', ["%{$searchTerm}%"]);
                    });
                });
            }

            $routesQuery = $routes->paginate(
                $perPage,
                ['id', 'user_id', 'city_id', 'company_id', 'status', 'created_at'],
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
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener las rutas');
        }
    }

    public function getRoutesSelect()
    {
        try {
            $routes = Seller::with('user:id,name')
                ->select('id', 'user_id')
                ->get();

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
}
