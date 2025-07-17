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
            $search = $request->input('search', '');
            $perpage = $request->input('perpage', 10);
            $orderBy = $request->input('orderBy', 'created_at');
            $orderDirection = $request->input('orderDirection', 'desc');

            return $this->clientService->index($search, $perpage, $orderBy, $orderDirection);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getClientsBySeller($sellerId, Request $request)
    {
        try {
            $perpage = $request->input('perpage', 10);
            $search = $request->input('search', '');

            $seller = Seller::find($sellerId);
            if (!$seller) {
                return $this->errorResponse('Vendedor no encontrado', 404);
            }

            $clients = $this->clientService->getClientsBySeller($sellerId, $search, $perpage);

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



    public function getForCollections(ClientRequest $request)
    {
        try {
            $search = (string) $request->input('search', ''); 
            $perpage = (int) $request->input('perpage', 10);
            $page = (int) $request->input('page', 1);
            $filter = (string) $request->input('filter', 'all');
            $orderBy = (string) $request->input('orderBy', 'created_at');
            $orderDirection = (string) $request->input('orderDirection', 'desc');
            $status = (string) $request->input('status', '');

            return $this->clientService->getForCollections(
                $search,
                $perpage,
                $page,
                $filter,
                $orderBy,
                $orderDirection,
                $status
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getForCollectionSummary(ClientRequest $request)
    {
        try {
            $search = (string) $request->input('search', '');
            $filter = (string) $request->input('filter', 'all');
            $status = (string) $request->input('status', '');

            return $this->clientService->getCollectionSummary(
                $search,
                $filter,
                $status
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
