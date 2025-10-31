<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Liquidation;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Services\ClientService;
use App\Http\Requests\Client\ClientRequest;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\Client\UpdateOrderRequest;
use App\Http\Requests\Client\ReactivateClientsByCriteriaRequest;
use App\Http\Requests\Client\ReactivateClientsByIdsRequest;
use App\Http\Requests\Client\DeleteClientsByIdsRequest;
use App\Http\Requests\Client\ToggleStatusRequest;
use App\Http\Requests\Client\InactiveClientsWithFiltersRequest;
use App\Http\Requests\Client\DeletedClientsWithFiltersRequest;

class ClientController extends Controller
{

    use ApiResponse;

    protected $clientService;

    public function __construct(ClientService $clientService)
    {
        $this->clientService = $clientService;

       /*  $this->middleware('permission:ver_clientes')->only('index');
        $this->middleware('permission:crear_clientes')->only('store');
        $this->middleware('permission:editar_clientes')->only('update');
        $this->middleware('permission:eliminar_clientes')->only('destroy'); */
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

    public function updateOrder(UpdateOrderRequest $request)
    {
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
            $companyId = $request->input('company_id');

            return $this->clientService->index($search, $orderBy, $orderDirection, $countryId, $cityId, $sellerId, $status, $companyId);
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
            $companyId = $request->input('company_id');

            return $this->clientService->indexWithCredits($search, $orderBy, $orderDirection, $countryId, $cityId, $sellerId, $status, $daysOverdue, $companyId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getClientsBySeller($sellerId, Request $request)
    {
        try {
            $search = $request->input('search', '');
            $companyId = $request->input('company_id');
            $status = $request->input('status', null);

            $seller = Seller::find($sellerId);
            if (!$seller) {
                return $this->errorResponse('Vendedor no encontrado', 404);
            }

            $clients = $this->clientService->getClientsBySeller($sellerId, $search, $companyId, $status);

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

    public function reactivateClientsByCriteria(ReactivateClientsByCriteriaRequest $request)
    {
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

    public function reactivateClientsByIds(ReactivateClientsByIdsRequest $request)
    {
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

    public function deleteClientsByIds(DeleteClientsByIdsRequest $request)
    {
        try {
            $result = $this->clientService->deleteClientsByIds($request->input('client_ids'));

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

    public function getClientsSelect(Request $request)
    {
        try {
            $search = $request->input('search', '');
            $companyId = $request->input('company_id');
            return $this->clientService->getClientsSelect($search, $companyId);
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

    public function getClientDetails(Request $request, $clientId)
    {
        try {
            $companyId = $request->input('company_id');
            return $this->clientService->getClientDetails($clientId, $companyId);
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
            $companyId = $request->input('company_id');

            return $this->clientService->getForCollections(
                $search,
                $perpage,
                $page,
                $filter,
                $frequency,
                $paymentStatus,
                $orderBy,
                $orderDirection,
                $companyId
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
            $timezone = $request->input('timezone', null);
            $clients = $this->clientService->getAllClientsBySeller($sellerId, $search, $date, $timezone);

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
    public function getLiquidationWithAllClients($sellerId, $date, $userId, Request $request)
    {
        try {
            // Obtener timezone del request si está presente
            $timezone = $request->input('timezone', 'America/Lima');
            if (!in_array($timezone, \DateTimeZone::listIdentifiers())) {
                $timezone = 'America/Lima';
            }
            $seller = Seller::find($sellerId);
            if (!$seller) {
                return $this->errorResponse('Vendedor no encontrado', 404);
            }
            $todayLocal = \Carbon\Carbon::now($timezone)->startOfDay();
            $inputDateLocal = \Carbon\Carbon::parse($date, $timezone)->startOfDay();
            if ($inputDateLocal->gt($todayLocal)) {
                return $this->errorResponse('La fecha seleccionada no puede ser mayor que la fecha actual.', 422);
            }
            $previousLiquidation = Liquidation::where('seller_id', $sellerId)
                ->whereDate('created_at', '<', $inputDateLocal->format('Y-m-d'))
                ->orderByDesc('created_at')
                ->first();
            if ($previousLiquidation && $previousLiquidation->status !== 'approved') {
                return $this->errorResponse('No puede consultar la liquidación porque la anterior no está aprobada.', 422);
            }
            $result = $this->clientService->getLiquidationWithAllClients($sellerId, $date, $userId, $timezone);
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

    public function toggleStatus(ToggleStatusRequest $request, $clientId)
    {
        try {
            return $this->clientService->toggleStatus($clientId, $request->input('status'));
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

    public function getInactiveClientsWithoutCreditsWithFilters(InactiveClientsWithFiltersRequest $request)
    {
        return app(ClientService::class)->getInactiveClientsWithoutCreditsWithFilters(
            $request->input('search', ''),
            $request->input('orderBy', 'created_at'),
            $request->input('orderDirection', 'desc'),
            $request->input('countryId'),
            $request->input('cityId'),
            $request->input('sellerId')
        );
    }

    public function getDeletedClientsWithFilters(DeletedClientsWithFiltersRequest $request)
    {
        return app(ClientService::class)->getDeletedClientsWithFilters(
            $request->input('search', ''),
            $request->input('orderBy', 'deleted_at'),
            $request->input('orderDirection', 'desc'),
            $request->input('countryId'),
            $request->input('cityId'),
            $request->input('sellerId')
        );
    }

    public function updateCapacity(Request $request, $id)
    {
        $request->validate([
            'capacity' => 'required|numeric|min:0'
        ]);
        $client = $this->clientService->updateCapacity($id, $request->input('capacity'));
        return response()->json([
            'success' => true,
            'message' => 'Cupo actualizado correctamente',
            'data' => $client
        ]);
    }
}
