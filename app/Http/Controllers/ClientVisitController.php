<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientVisit;
use App\Services\ClientVisitService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Visitas / gestión de cobranza.
 *
 * A diferencia del comentario, aquí la ubicación es OBLIGATORIA: una visita sin
 * GPS no prueba nada y sería peor que no tener el dato, porque daría una falsa
 * sensación de control. Si el cobrador no puede obtener ubicación, el APK debe
 * resolver el problema (permiso, GPS apagado) en lugar de registrar una visita
 * ciega.
 */
class ClientVisitController extends Controller
{
    use ApiResponse;

    public function __construct(private ClientVisitService $visitService)
    {
    }

    public function index($clientId)
    {
        $client = Client::find($clientId);
        if (!$client) {
            return $this->errorResponse('El cliente no existe.', 404);
        }

        if (!$this->canAccess($client)) {
            return $this->errorResponse('No tiene acceso a este cliente.', 403);
        }

        return $this->successResponse([
            'success' => true,
            'data' => $this->visitService->history($client),
        ]);
    }

    public function store(Request $request, $clientId)
    {
        $client = Client::find($clientId);
        if (!$client) {
            return $this->errorResponse('El cliente no existe.', 404);
        }

        if (!$this->canAccess($client)) {
            return $this->errorResponse('No tiene acceso a este cliente.', 403);
        }

        $validator = Validator::make($request->all(), [
            // Lo genera el APK: es la clave de idempotencia del reintento offline.
            'uuid' => 'required|uuid',
            'result' => 'required|string|in:' . implode(',', ClientVisit::RESULTS),
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            // Origen del punto: sin esta marca no se puede distinguir después
            // "estuvo lejos" de "el teléfono midió mal".
            'gps_source' => 'nullable|string|in:gps,network,unknown',
            'address' => 'nullable|string|max:500',
            // Comentario y categoría son obligatorios en la gestión manual: sin
            // ellos el registro no explica nada. La gestión AUTOMÁTICA derivada
            // de un pago no pasa por acá y por eso no los exige.
            'comment' => 'required|string|max:2000',
            'comment_category_id' => 'required|integer|exists:comment_categories,id',
            // Momento real de la visita (puede ser anterior si venía en cola).
            'occurred_at' => 'nullable|date',
        ], [
            'uuid.required' => 'Falta el identificador de la visita.',
            'result.required' => 'Debe indicar el resultado de la visita.',
            'result.in' => 'El resultado de la visita no es válido.',
            'latitude.required' => 'La visita requiere ubicación: active el GPS e intente de nuevo.',
            'longitude.required' => 'La visita requiere ubicación: active el GPS e intente de nuevo.',
            'comment.required' => 'El comentario es obligatorio.',
            'comment_category_id.required' => 'La categoría es obligatoria.',
            'comment_category_id.exists' => 'La categoría seleccionada no existe.',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $visit = $this->visitService->register($client, $validator->validated());

        return $this->successResponse([
            'success' => true,
            'message' => 'Visita registrada',
            'data' => $this->visitService->present($visit),
        ]);
    }

    /**
     * Un usuario solo puede registrar/consultar visitas de clientes de su
     * propia operación. Se valida explícitamente porque este endpoint expone
     * ubicación de personas: filtrar aquí es distinto a filtrar datos de saldo.
     */
    private function canAccess(Client $client): bool
    {
        $user = Auth::user();
        $roleId = (int) $user->role_id;

        // Super admin: acceso completo.
        if ($roleId === 1) {
            return true;
        }

        // Cobrador: solo sus propios clientes.
        if ($roleId === 5) {
            return $user->seller && (int) $client->seller_id === (int) $user->seller->id;
        }

        // Supervisor: la ruta activa que resolvió el middleware, o sus rutas.
        if ($roleId === 6) {
            $activeSellerId = request()->attributes->get('active_seller_id');
            if ($activeSellerId) {
                return (int) $client->seller_id === (int) $activeSellerId;
            }

            return \App\Models\UserRoute::where('user_id', $user->id)
                ->where('seller_id', $client->seller_id)
                ->exists();
        }

        // Administrador de empresa: clientes de vendedores de su empresa.
        //
        // La empresa del administrador se obtiene por la RELACIÓN company()
        // (companies.user_id), no por users.company_id: esa columna está NULL
        // en todos los administradores del sistema. Compararla habría negado
        // el acceso a todos ellos. Mismo criterio que usa ClientController.
        $client->loadMissing('seller');

        return $client->seller
            && $user->company
            && (int) $client->seller->company_id === (int) $user->company->id;
    }
}
