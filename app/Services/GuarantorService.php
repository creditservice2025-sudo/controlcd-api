<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\Guarantor;
use App\Models\Client;
use App\Http\Requests\Guarantor\GuarantorRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class GuarantorService
{
    use ApiResponse;

    public function create(GuarantorRequest $request)
    {
        try {
            $params = $request->validated();
            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $params['created_at'] = \Carbon\Carbon::now($params['timezone']);
                $params['updated_at'] = \Carbon\Carbon::now($params['timezone']);
                unset($params['timezone']);
            }
            $guarantor = Guarantor::create(array_merge($request->except('clients_ids'), [
                'created_at' => $params['created_at'] ?? null,
                'updated_at' => $params['updated_at'] ?? null
            ]));

            return $this->successResponse([
                'success' => true,
                'message' => 'Fiador creado con éxito',
                'data' => $guarantor
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al crear el fiador: ' . $e->getMessage(), 500);
        }
    }

    public function update(GuarantorRequest $request, $guarantorId)
    {
        try {
            
            if ($request->has('clients_ids')) {
                $clientsIds = $request->input('clients_ids');

                if (count($clientsIds) > 2) {
                    return $this->errorResponse('Un fiador no puede estar asociado a más de dos clientes', 400);
                }

                $existingClients = Client::whereIn('id', $clientsIds)->get();
                if ($existingClients->count() != count($clientsIds)) {
                    return $this->errorResponse('Uno o más clientes no existen', 400);
                }
            }

            $params = $request->validated();
            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $params['updated_at'] = \Carbon\Carbon::now($params['timezone']);
                unset($params['timezone']);
            }

            $guarantor = Guarantor::find($guarantorId);
            if (!$guarantor) {
                return $this->errorNotFoundResponse('Fiador no encontrado');
            }

            $guarantor->update(array_merge($request->except('clients_ids'), [
                'updated_at' => $params['updated_at'] ?? null
            ]));

            if ($request->has('clients_ids')) {
                $guarantor->clients()->sync($request->input('clients_ids'));
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Fiador actualizado con éxito',
                'data' => $guarantor
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al actualizar el fiador: ' . $e->getMessage(), 500);
        }
    }

    public function delete($guarantorId, $timezone = null)
    {
        try {
            $guarantor = Guarantor::find($guarantorId);
            if (!$guarantor) {
                return $this->errorNotFoundResponse('Fiador no encontrado');
            }

            $guarantor->clients()->detach();
            if ($timezone) {
                $guarantor->deleted_at = \Carbon\Carbon::now($timezone);
                $guarantor->save();
                $guarantor->delete();
            } else {
                $guarantor->delete();
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Fiador eliminado con éxito',
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Error al eliminar el fiador: ' . $e->getMessage(), 500);
        }
    }

    public function getGuarantorsSelect(string $search = '')
    {
        try {
            $guarantors = Guarantor::where('name', 'like', "%{$search}%")
                ->orWhere('dni', 'like', "%{$search}%")
                ->select('id', 'name')  
                ->get();

            return $this->successResponse([
                'success' => true,
                'data' => $guarantors
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los fiadores', 500);
        }
    }
    
    public function show($guarantorId)
    {
        try {
            $guarantor = Guarantor::with([
                'clients:name,dni,phone,geolocation,address,email'
            ])->find($guarantorId);
    
            if (!$guarantor) {
                return $this->errorNotFoundResponse('Fiador no encontrado');
            }
    
            return $this->successResponse([
                'success' => true,
                'data' => $guarantor
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener el fiador', 500);
        }
    
    }

    public function index(string $search, int $perpage)
    {
        try {
            $guarantors = Guarantor::with([
                'clients:name,dni,phone,geolocation,address,email'
            ])->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('dni', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->paginate($perpage);

            if ($guarantors->isEmpty()) {
                return $this->errorNotFoundResponse('No se encontraron fiadores');
            }

            return $this->successResponse([
                'success' => true,
                'data' => $guarantors
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());  
            return $this->errorResponse('Error al obtener los fiadores', 500);
        }
    }
}


