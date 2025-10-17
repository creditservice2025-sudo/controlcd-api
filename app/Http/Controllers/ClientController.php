<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Services\ClientService;
use App\Http\Requests\Client\ClientRequest;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ClientController extends Controller
{

    use ApiResponse;

    protected $clientService;

    public function __construct(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    public function create(ClientRequest $request)
    {
        try {
            return $this->clientService->create($request);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(ClientRequest $request, $clientId)
    {
        try {
            return $this->clientService->update($request, $clientId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function updateOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'clients' => 'required|array',
            'clients.*.id' => 'required|exists:clients,id',
            'clients.*.routing_order' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $clientIds = collect($request->clients)->pluck('id');
            $clients = Client::whereIn('id', $clientIds)->get()->keyBy('id');

            foreach ($request->clients as $clientData) {
                $client = $clients[$clientData['id']];

                if ($client->routing_order != $clientData['routing_order']) {
                    $client->update(['routing_order' => $clientData['routing_order']]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Orden de ruta actualizado correctamente',
                'data' => Client::whereIn('id', $clientIds)
                    ->orderBy('routing_order')
                    ->get()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el orden',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function delete($clientId)
    {
        try {
            return $this->clientService->delete($clientId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
    public function index(ClientRequest $request)
    {
        try {
            $search = (string) $request->input('search', '');
            $orderBy = $request->input('orderBy', 'created_at');
            $orderDirection = $request->input('orderDirection', 'desc');
            $countryId = $request->input('country_id');
            $cityId = $request->input('city_id');
            $sellerId = $request->input('seller_id');
            $status = $request->input('status', null);

            return $this->clientService->index($search, $orderBy, $orderDirection, $countryId, $cityId, $sellerId, $status);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function indexWithCredits(ClientRequest $request)
    {
        try {
            $search = (string) $request->input('search', '');
            $orderBy = $request->input('orderBy', 'created_at');
            $orderDirection = $request->input('orderDirection', 'desc');
            $countryId = $request->input('countryId');
            $cityId = $request->input('cityId');
            $sellerId = $request->input('sellerId');
            $status = $request->input('status', null);
            $daysOverdue = $request->input('daysOverdue', null);

            return $this->clientService->indexWithCredits($search, $orderBy, $orderDirection, $countryId, $cityId, $sellerId, $status, $daysOverdue);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getClientsBySeller($sellerId, Request $request)
    {
        try {
            $search = $request->input('search', '');

            $seller = Seller::find($sellerId);
            if (!$seller) {
                return $this->errorResponse('Vendedor no encontrado', 404);
            }

            $clients = $this->clientService->getClientsBySeller($sellerId, $search);

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

    public function reactivateClientsByCriteria(Request $request)
    {
        $request->validate([
            'country_id' => 'nullable|integer|exists:countries,id',
            'city_id' => 'nullable|integer|exists:cities,id',
            'seller_id' => 'nullable|integer|exists:sellers,id'
        ]);

        $countryId = $request->input('country_id');
        $cityId = $request->input('city_id');
        $sellerId = $request->input('seller_id');

        if (!$countryId && !$cityId && !$sellerId) {
            return response()->json([
                'success' => false,
                'message' => 'Debe proporcionar al menos un criterio (país, ciudad o vendedor)'
            ], 400);
        }

        return $this->clientService->reactivateClients($countryId, $cityId, $sellerId);
    }

    public function reactivateClientsByIds(Request $request)
    {
        $request->validate([
            'client_ids' => 'required|array',
            'client_ids.*' => 'integer|exists:clients,id',
        ]);
    
        try {
            $clientIds = $request->input('client_ids');
    
            $result = $this->clientService->reactivateClientsByIds($clientIds);
    
            return response()->json([
                'success' => true,
                'message' => 'Clientes reactivados exitosamente',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            \Log::error("Error reactivando clientes: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al reactivar clientes',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteInactiveClientsWithoutCredits(Request $request)
    {

        try {
            $result = $this->clientService->deleteInactiveClientsWithoutCredits();
            return $this->successResponse([
                'success' => true,
                'message' => "Clientes inactivos sin créditos eliminados exitosamente",
                'data' => $result
            ]);
        } catch (\Exception $e) {
            \Log::error("Error eliminando clientes inactivos sin créditos: " . $e->getMessage());
            return $this->errorResponse('Error eliminando clientes inactivos sin créditos', 500);
        }
    }

    public function deleteClientsByIds(Request $request)
    {
        try {
            $params = $request->validate([
                'client_ids' => 'required|array',
                'client_ids.*' => 'integer|exists:clients,id',
            ]);

            $result = $this->clientService->deleteClientsByIds($params['client_ids']);

            return $this->successResponse([
                'success' => true,
                'message' => "Clientes eliminados exitosamente",
                'data' => $result
            ]);
        } catch (\Exception $e) {
            \Log::error("Error eliminando clientes: " . $e->getMessage());
            return $this->errorResponse('Error eliminando clientes', 500);
        }
    }

    public function totalClients()
    {
        try {


            return $this->clientService->totalClients();
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getClientsSelect(string $search = '')
    {
        try {
            return $this->clientService->getClientsSelect($search);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }



    public function show($clientId)
    {
        try {
            return $this->clientService->show($clientId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getClientDetails($clientId)
    {
        try {
            return $this->clientService->getClientDetails($clientId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getDebtorClientsBySeller($sellerId)
    {
        try {
            $seller = Seller::find($sellerId);
            if (!$seller) {
                return $this->errorResponse('Vendedor no encontrado', 404);
            }

            return $this->clientService->getDebtorClientsBySeller($sellerId);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los clientes morosos', 500);
        }
    }



    public function getForCollections(ClientRequest $request)
    {
        try {
            $search = (string) $request->input('search', '');
            $perpage = (int) $request->input('perpage', 10);
            $page = (int) $request->input('page', 1);
            $filter = (string) $request->input('filter', 'all');
            $orderBy = (string) $request->input('orderBy', 'created_at');
            $orderDirection = (string) $request->input('orderDirection', 'desc');
            $frequency = (string) $request->input('frequency', '');
            $paymentStatus = (string) $request->input('payment_status', '');

            return $this->clientService->getForCollections(
                $search,
                $perpage,
                $page,
                $filter,
                $frequency,
                $paymentStatus,
                $orderBy,
                $orderDirection,

            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getForCollectionSummary(Request $request)
    {
        try {
            $date = (string) $request->input('date', '');
            /*     $filter = (string) $request->input('filter', 'all');
            $frequency = (string) $request->input('frequency', '');
            $paymentStatus = (string) $request->input('payment_status', ''); */

            return $this->clientService->getCollectionSummary(
                $date
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getSellerClientsForMap(Request $request, $sellerId)
    {
        try {
            $search = $request->input('search', '');
            $date = $request->input('date', null);
            $clients = $this->clientService->getAllClientsBySeller($sellerId, $search, $date);

            return response()->json([
                'success' => true,
                'message' => 'Clientes obtenidos exitosamente',
                'data' => $clients
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener clientes',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getLiquidationWithAllClients($sellerId, $date, $userId)
    {
        try {
            // 1. Definir la zona horaria deseada
            $timezone = 'America/Caracas';
    
            $seller = Seller::find($sellerId);
            if (!$seller) {
                return $this->errorResponse('Vendedor no encontrado', 404);
            }
    
            $todayCaracas = \Carbon\Carbon::now($timezone)->startOfDay(); 
            
            $inputDateCaracas = \Carbon\Carbon::parse($date, $timezone)->startOfDay();
    
            if ($inputDateCaracas->gt($todayCaracas)) {
                return $this->errorResponse('La fecha seleccionada no puede ser mayor que la fecha actual.', 422);
            }
    
            $result = $this->clientService->getLiquidationWithAllClients($sellerId, $date, $userId);
    
            return response()->json([
                'success' => true,
                'message' => 'Clientes obtenidos exitosamente',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los datos de liquidación y clientes', 500);
        }
    }

    public function toggleStatus(Request $request, $clientId)
    {
        try {
            $params = $request->validate([
                'status' => 'required|string|in:active,inactive,uncollectible',
            ]);

            return $this->clientService->toggleStatus($clientId, $params['status']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
    public function getInactiveClientsWithoutCredits()
    {
        try {
            return $this->clientService->getInactiveClientsWithoutCredits();
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los clientes inactivos sin créditos', 500);
        }
    }

    public function getInactiveClientsWithoutCreditsWithFilters(Request $request)
    {
        $search = $request->query('search', '');
        $orderBy = $request->query('orderBy', 'created_at');
        $orderDirection = $request->query('orderDirection', 'desc');
        $countryId = $request->query('countryId');
        $cityId = $request->query('cityId');
        $sellerId = $request->query('sellerId');

        return app(ClientService::class)->getInactiveClientsWithoutCreditsWithFilters(
            $search,
            $orderBy,
            $orderDirection,
            $countryId,
            $cityId,
            $sellerId
        );
    }

    public function getDeletedClientsWithFilters(Request $request)
    {
        $search = $request->query('search', '');
        $orderBy = $request->query('orderBy', 'deleted_at');
        $orderDirection = $request->query('orderDirection', 'desc');
        $countryId = $request->query('countryId');
        $cityId = $request->query('cityId');
        $sellerId = $request->query('sellerId');

        return app(ClientService::class)->getDeletedClientsWithFilters(
            $search,
            $orderBy,
            $orderDirection,
            $countryId,
            $cityId,
            $sellerId
        );
    }
}
