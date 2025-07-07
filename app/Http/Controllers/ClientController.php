<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Services\ClientService;
use App\Http\Requests\Client\ClientRequest;
use App\Models\Seller;

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

            return $this->clientService->index($search, $perpage);
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
}
