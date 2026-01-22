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
        /*  $this->middleware('permission:ver_creditos')->only('index');
         $this->middleware('permission:crear_creditos')->only('create');
         $this->middleware('permission:editar_creditos')->only('update');
         $this->middleware('permission:eliminar_creditos')->only('delete'); */
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

    public function updateSchedule(Request $request, $creditId)
    {
        try {
            $newDate = $request->input('first_quota_date');
            $newStartDate = $request->input('new_start_date', null);
            $timezone = $request->input('timezone', null);
            $notes = $request->input('notes', 'Cambio de fecha inicial de cuotas');

            if (!$newDate) {
                return $this->errorResponse('first_quota_date es requerido', 400);
            }

            return $this->creditService->updateCreditSchedule((int) $creditId, $newDate, $timezone, $notes, $newStartDate);
        } catch (\Exception $e) {
            \Log::error("Error updateSchedule ({$creditId}): " . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function updateFrequency(Request $request, $creditId)
    {
        try {
            $newFrequency = $request->input('frequency');
            $newFirstDate = $request->input('first_quota_date', null);
            $newInstallments = $request->input('number_installments', null);
            $newInterestRate = $request->input('interest_rate', null);
            $newInsurance = $request->input('micro_insurance_percentage', null);
            $newCreditValue = $request->input('credit_value', null); // Nuevo
            $timezone = $request->input('timezone', null);
            $notes = $request->input('notes', 'Modificación de frecuencia y/o parámetros financieros');

            if (!$newFrequency) {
                return $this->errorResponse('El campo frequency es requerido', 400);
            }

            return $this->creditService->updateCreditFrequency(
                (int) $creditId,
                $newFrequency,
                $newFirstDate,
                $newInstallments ? (int) $newInstallments : null,
                $newInterestRate ? (float) $newInterestRate : null,
                $newInsurance ? (float) $newInsurance : null,
                $timezone,
                $notes,
                $request->input('new_start_date', null),
                $newCreditValue ? (float) $newCreditValue : null,
                (bool) $request->input('recalculate_paid', false)
            );
        } catch (\Exception $e) {
            \Log::error("Error updateFrequency ({$creditId}): " . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function simulateEdit(Request $request, $creditId)
    {
        try {
            return $this->creditService->simulateEdit((int) $creditId, $request->all());
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function setRenewalBlocked(Request $request, $creditId)
    {
        try {
            if (!$request->has('blocked')) {
                return $this->errorResponse('El campo blocked es requerido', 400);
            }

            $blocked = filter_var($request->input('blocked'), FILTER_VALIDATE_BOOLEAN);
            return $this->creditService->setCreditRenewalBlocked((int) $creditId, $blocked);
        } catch (\Exception $e) {
            \Log::error("Error setRenewalBlocked ({$creditId}): " . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function simulateSchedule(Request $request, $creditId)
    {
        try {
            $newDate = $request->input('first_quota_date');
            if (!$newDate) {
                return $this->errorResponse('El campo first_quota_date es requerido', 400);
            }

            return $this->creditService->simulateScheduleChange((int) $creditId, $newDate, 'schedule');
        } catch (\Exception $e) {
            \Log::error("Error simulateSchedule ({$creditId}): " . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function simulateFrequency(Request $request, $creditId)
    {
        try {
            $newFrequency = $request->input('frequency');
            $newFirstDate = $request->input('first_quota_date', null);
            $newInstallments = $request->input('number_installments', null);
            $newInterestRate = $request->input('interest_rate', null);
            $newInsurance = $request->input('micro_insurance_percentage', null);
            $newCreditValue = $request->input('credit_value', null); // Nuevo

            return $this->creditService->simulateScheduleChange(
                (int) $creditId,
                $newFirstDate,
                'frequency',
                $newFrequency,
                $newInstallments ? (int) $newInstallments : null,
                $newInterestRate ? (float) $newInterestRate : null,
                $newInsurance ? (float) $newInsurance : null,
                $request->input('new_start_date', null),
                $newCreditValue ? (float) $newCreditValue : null // Nuevo
            );
        } catch (\Exception $e) {
            \Log::error("Error simulateFrequency ({$creditId}): " . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function simulateDelete($creditId)
    {
        try {
            return $this->creditService->simulateDelete($creditId);
        } catch (\Throwable $e) {
            \Log::error("Error in CreditController::simulateDelete: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function validateDeletion($id)
    {
        try {
            return $this->creditService->validateDeletion($id);
        } catch (Exception $e) {
            \Log::error("Error in CreditController@validateDeletion ({$id}): " . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getModifications($creditId)
    {
        try {
            $modifications = \App\Models\CreditModification::with('user')
                ->where('credit_id', $creditId)
                ->orderBy('created_at', 'desc')
                ->get();
            return $this->successResponse($modifications);
        } catch (\Exception $e) {
            \Log::error("Error getModifications ({$creditId}): " . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function delete(Request $request, $id)
    {
        try {
            \Log::info("Attempting to delete credit with ID: {$id}");
            $password = $request->input('password');
            return $this->creditService->delete($id, $password);
        } catch (Exception $e) {
            \Log::error("Error in CreditController@delete: " . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function index(CreditRequest $request)
    {
        try {
            $search = $request->input('search', '');
            $perPage = $request->input('perPage', 10);
            return $this->creditService->index($search, $perPage);
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
            if (!is_numeric($clientId)) {
                $client = \App\Models\Client::where('uuid', $clientId)->first();
                if (!$client) {
                    return $this->errorResponse('Cliente no encontrado', 404);
                }
                $clientId = $client->id;
            }
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
