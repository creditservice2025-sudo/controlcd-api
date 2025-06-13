<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Helpers\Helper;
use App\Http\Requests\Client\ClientRequest;
use App\Models\Credit;
use Illuminate\Support\Facades\Storage;
use App\Models\Guarantor;
use Illuminate\Support\Facades\Auth;

class ClientService
{
    use ApiResponse;

    public function create(ClientRequest $request)
    {
        try {
            $params = $request->validated();
            /* 
            if ($request->has('images')) {
                $validationResponse = $this->validateImages($request);
                if ($validationResponse !== true) {
                    return $validationResponse;
                }
            } */
            \Log::info('Datos recibidos:', $params);

            if (!empty($params['guarantor_name'])) {
                $guarantorData = [
                    'name' => $params['guarantor_name'],
                    'dni' => $params['guarantor_dni'] ?? null,
                    'address' => $params['guarantor_address'] ?? null,
                    'phone' => $params['guarantor_phone'] ?? null,
                    'email' => $params['guarantor_email'] ?? null,
                ];

                $guarantor = Guarantor::create($guarantorData);
                $guarantorId = $guarantor->id;
            }

            $clientData = [
                'name' => $params['name'],
                'dni' => $params['dni'],
                'address' => $params['address'],
                'geolocation' => $params['geolocation'],
                'phone' => $params['phone'],
                'email' => $params['email'],
                'company_name' => $params['company_name'],
                'guarantor_id' => $guarantorId,
                'seller_id' => $params['seller_id'],
            ];


            $client = Client::create($clientData);




            if (!empty($params['credit_value'])) {
                $creditData = [
                    'client_id' => $client->id,
                    'guarantor_id' => $guarantorId,
                    'seller_id' => $params['seller_id'],
                    'credit_value' => $params['credit_value'],
                    'total_interest' => $params['interest_rate'],
                    'number_installments' => $params['installment_count'],
                    'payment_frequency' => $params['payment_frequency'],
                    'first_quota_date' => $params['first_installment_date'],
                    'excluded_days' => json_encode($params['excluded_days'] ?? []),
                    'micro_insurance_percentage' => $params['micro_insurance_percentage'] ?? null,
                    'micro_insurance_amount' => $params['micro_insurance_amount'] ?? null,
                ];

                $credit = Credit::create($creditData);
            }

            if ($request->has('images')) {
                $images = $request->input('images');
                foreach ($images as $index => $imageData) {
                    $imageFile = $request->file("images.{$index}.file");
                    $imagePath = Helper::uploadFile($imageFile, 'clients');

                    $client->images()->create([
                        'path' => $imagePath,
                        'type' => $imageData['type']
                    ]);
                }
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Cliente creado con éxito',
                'data' => $client
            ]);
        } catch (\Exception $e) {
            if (isset($filePath)) {
                Helper::deleteFile($filePath);
            }
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al crear el cliente', 500);
        }
    }

    public function update(ClientRequest $request, $clientId)
    {
        try {
            $client = Client::find($clientId);
            if (!$client) {
                return $this->errorNotFoundResponse('Cliente no encontrado');
            }

            $params = $request->validated();

            $client->update($params);
            if ($request->has('images')) {
                $validationResponse = $this->validateImages($request);
                if ($validationResponse !== true) {
                    return $validationResponse;
                }

                $client->images()->delete();

                $images = $request->input('images');
                foreach ($images as $index => $imageData) {
                    $imageFile = $request->file("images.{$index}.file");
                    $imagePath = Helper::uploadFile($imageFile, 'clients');
                    $client->images()->create([
                        'path' => $imagePath,
                        'type' => $imageData['type']
                    ]);
                }
            }

            if ($request->has('guarantors_ids')) {
                $client->guarantors()->sync($request->input('guarantors_ids'));
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Cliente actualizado con éxito',
                'data' => $client
            ]);
        } catch (\Exception $e) {
            if (isset($uploadedImagePaths)) {
                foreach ($uploadedImagePaths as $path) {
                    Helper::deleteFile($path);
                }
            }
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al actualizar el cliente', 500);
        }
    }

    private function validateImages($request)
    {
        $images = $request->all()['images'];
        $profileCount = 0;
        $galleryCount = 0;

        foreach ($images as $index => $imageData) {
            if (!isset($imageData['file']) || !isset($imageData['type'])) {
                return $this->errorResponse('Cada imagen debe contener un archivo y un tipo ("profile" o "gallery").', 400);
            }

            if ($imageData['type'] === 'profile') {
                $profileCount++;
            } elseif ($imageData['type'] === 'gallery') {
                $galleryCount++;
            }

            if ($profileCount > 1) {
                return $this->errorResponse('Solo se permite una foto de perfil.', 400);
            }

            if ($galleryCount > 4) {
                return $this->errorResponse('Solo se permiten hasta 4 imágenes en la galería.', 400);
            }

            $imageFile = $request->file("images.{$index}.file");
            if (!$imageFile) {
                return $this->errorResponse('No se encontró la imagen en la solicitud.', 400);
            }

            if (!$imageFile instanceof \UploadedFile) {
                return $this->errorResponse('Formato incorrecto de imágenes.', 400);
            }

            if (!in_array($imageData['type'], ['profile', 'gallery'])) {
                return $this->errorResponse('El tipo de imagen es requerido y debe ser "profile" o "gallery".', 400);
            }
        }

        return true;
    }

    public function delete($clientId)
    {
        try {
            $client = Client::find($clientId);

            if ($client == null) {
                return $this->errorNotFoundResponse('Cliente no encontrado');
            }

            $client->guarantors()->detach();

            $client->images()->each(function ($image) {
                $image->delete();
            });

            $client->delete();

            return $this->successResponse([
                'success' => true,
                'message' => "Cliente eliminado con éxito",
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al eliminar el cliente', 500);
        }
    }


    public function index(string $search, int $perpage)
    {
        try {
            $user = Auth::user();
            $seller = $user->seller;

            $clientsQuery = Client::with(['guarantors', 'images', 'credits', 'seller', 'seller.city'])
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('dni', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });

            if ($user->role_id == 5 && $seller) {
                $clientsQuery->where('seller_id', $seller->id);
            }

            $clients = $clientsQuery->orderBy('created_at', 'desc')->paginate($perpage);

            return $this->successResponse([
                'success' => true,
                'message' => 'Clientes encontrados',
                'data' => $clients
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los clientes', 500);
        }
    }

    public function totalClients()
    {
        try {
            $user = Auth::user();
            $seller = $user->seller;

            $clientsQuery = Client::query();

            if ($user->role_id == 5 && $seller) {
                $clientsQuery->where('seller_id', $seller->id);
            }

            $totalClients = $clientsQuery->count();

            return $this->successResponse([
                'success' => true,
                'message' => 'Total de clientes obtenido',
                'data' => $totalClients
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener el total de clientes', 500);
        }
    }



    public function getClientsSelect(string $search = '')
    {
        try {
            $clients = Client::where('name', 'like', "%{$search}%")
                ->orWhere('dni', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->select('id', 'name')
                ->get();

            if ($clients->isEmpty()) {
                return $this->errorNotFoundResponse('No se encontraron clientes');
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Clientes encontrados',
                'data' => $clients
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los clientes', 500);
        }
    }

    public function show($clientId)
    {
        try {
            $client = Client::with(['credits.guarantor', 'credits.installments', 'images'])->find($clientId);

            // Verificar si el cliente no existe
            if (!$client) {
                return $this->errorNotFoundResponse('Cliente no encontrado');
            }

            $images = $client->images->map(function ($image) {
                return [
                    'id' => $image->id,
                    'path' => $image->path,
                    'type' => $image->type
                ];
            });

            return $this->successResponse([
                'success' => true,
                'message' => 'Cliente encontrado',
                'data' => $client
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener el cliente', 500);
        }
    }
}
