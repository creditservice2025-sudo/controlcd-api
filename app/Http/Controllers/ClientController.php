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
            $perPage = $request->get('perPage', 5);
            $orderBy = $request->input('orderBy', 'created_at');
            $orderDirection = $request->input('orderDirection', 'desc');

            return $this->clientService->index($search, $perPage, $orderBy, $orderDirection);
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
            $seller = Seller::find($sellerId);
            if (!$seller) {
                return $this->errorResponse('Vendedor no encontrado', 404);
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
}
