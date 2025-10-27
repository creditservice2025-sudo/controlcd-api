<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\CreditService;
use App\Http\Requests\Credit\CreditRequest;
use App\Traits\ApiResponse;
use Exception;

class CreditController extends Controller
{
    use ApiResponse;

    protected $creditService;

    public function __construct(CreditService $creditService)
    {
        $this->creditService = $creditService;
    }

    public function create(CreditRequest $request)
    {
        try {
            return $this->creditService->create($request);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function renew(Request $request)
    {
        try {
            return $this->creditService->renew($request);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function unifyCredits(Request $request)
    {
        try {
            return $this->creditService->unifyCredits($request);
        } catch (Exception $e) {
            \Log::error("Error al unificar créditos: " . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(CreditRequest $request, $creditId)
    {
        try {
            return $this->creditService->update($request, $creditId);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function delete($id)
    {
        try {
            return $this->creditService->delete($id);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function index(CreditRequest $request)
    {
        try {
            return $this->creditService->index($request);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            return $this->creditService->show($id);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /*    public function getCreditsSelect(string $search = '')
    {
        try {
            return $this->creditService->getCreditsSelect($search);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    } */

    public function toggleCreditStatus(Request $request, $creditId)
    {
        try {
            $status = $request->input('status');

            if (!in_array($status, ['uncollectible', 'vigente'])) {
                return $this->errorResponse('Estado no válido', 400);
            }

            return $this->creditService->toggleCreditStatus($creditId, $status);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function toggleCreditsStatusMassively(Request $request)
    {
        try {
            $creditIds = $request->input('credit_ids');
            $status = $request->input('status');


            if (empty($creditIds) || !is_array($creditIds)) {
                return $this->errorResponse('IDs de créditos son requeridos y deben ser un array', 400);
            }

            if (!in_array($status, ['uncollectible', 'vigente'])) {
                return $this->errorResponse('Estado no válido', 400);
            }

            return $this->creditService->toggleCreditsStatusMassively($creditIds, $status);
        } catch (Exception $e) {
            \Log::error("Error al actualizar los estados de los créditos masivamente: " . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function changeCreditClient(Request $request, $creditId)
    {
        try {
            $newClientId = $request->input('new_client_id');
            if (!$newClientId) {
                return $this->errorResponse('El nuevo cliente es requerido', 400);
            }
            return $this->creditService->changeCreditClient($creditId, $newClientId);
        } catch (\Exception $e) {
            \Log::error("Error al cambiar el cliente del crédito: " . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getClientCredits(Request $request)
    {
        try {

            $search = $request->get('search') ?? '';
            $perPage = $request->get('perPage') ?? 10;

            return $this->creditService->getClientCredits($search, $perPage);
        } catch (Exception $e) {
            return $this->handlerException($e->getMessage());
        }
    }

    public function getCredits(Request $request, $clientId)
    {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('perPage', 10);
            $search = $request->get('search', null);

            return $this->creditService->getCredits($clientId, $page, $perPage, $search);
        } catch (Exception $e) {
            return $this->handlerException($e->getMessage());
        }
    }
    public function getSellerCredits(Request $request, int $sellerId)
    {
        try {
            $perPage = $request->get('perPage') ?? 10;
            return $this->creditService->getSellerCreditsByDate($sellerId, $request, $perPage);
        } catch (Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los créditos del vendedor: ' . $e->getMessage(), 500);
        }
    }

    public function dailyCollectionReport(Request $request)
    {
        try {
            return $this->creditService->getReport($request);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener reporte: ' . $e->getMessage(), 500);
        }
    }

    public function creditReport(Request $request, $creditId)
    {
        try {
            return $this->creditService->getCreditReport($request, (int) $creditId);
        } catch (\Exception $e) {
            \Log::error("Error al generar reporte de crédito (ID: {$creditId}): " . $e->getMessage());
            return $this->errorResponse('Error al generar el reporte: ' . $e->getMessage(), 500);
        }
    }
}
