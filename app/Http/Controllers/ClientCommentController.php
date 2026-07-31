<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientComment;
use App\Models\ClientGeolocationHistory;
use App\Models\CommentCategory;
use App\Services\GeolocationHistoryService;
use App\Services\ReverseGeocodeService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Comentarios / bitácora del cliente.
 *
 * Regla acordada: supervisor (rol 6), admins (rol 1/2) y vendedor (rol 5)
 * pueden agregar y ver. Cada comentario guarda autor y fecha (hilo). Solo el
 * autor o un admin (rol 1/2) puede eliminar.
 *
 * UBICACIÓN DEL COMENTARIO: cada comentario puede registrar desde DÓNDE se
 * escribió. No se guarda en `client_comments` sino en el historial ya existente
 * `client_geolocation_histories` (action_type = 'comment_created'), que es la
 * única fuente de verdad de ubicaciones del sistema. Es OPCIONAL a propósito:
 * un supervisor comenta desde la oficina y eso es legítimo. La ubicación
 * obligatoria pertenece al registro de VISITA, que es otra entidad.
 */
class ClientCommentController extends Controller
{
    use ApiResponse;

    /** action_type con el que se indexa la ubicación de un comentario. */
    private const GEO_ACTION_TYPE = 'comment_created';

    public function index($clientId)
    {
        $client = Client::find($clientId);
        if (!$client) {
            return $this->errorResponse('El cliente no existe.', 404);
        }

        $comments = ClientComment::with(['user:id,name,role_id', 'category:id,name'])
            ->where('client_id', $client->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Ubicación de cada comentario en UNA sola consulta (sin N+1).
        $locations = ClientGeolocationHistory::where('action_type', self::GEO_ACTION_TYPE)
            ->whereIn('action_id', $comments->pluck('id'))
            ->get(['action_id', 'latitude', 'longitude', 'accuracy', 'address'])
            ->keyBy('action_id');

        $comments->transform(function ($comment) use ($locations) {
            $location = $locations->get($comment->id);
            $comment->location = $location ? [
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'accuracy' => $location->accuracy !== null ? (float) $location->accuracy : null,
                // Nunca vacío: si la dirección aún no se resolvió van las
                // coordenadas, y geo:fill-addresses la completa después.
                'address' => $location->displayAddress(),
                'address_pending' => empty($location->address),
            ] : null;

            return $comment;
        });

        return $this->successResponse([
            'success' => true,
            'data' => $comments,
        ]);
    }

    public function store(
        Request $request,
        $clientId,
        GeolocationHistoryService $geolocationHistory,
        ReverseGeocodeService $reverseGeocode
    ) {
        $client = Client::find($clientId);
        if (!$client) {
            return $this->errorResponse('El cliente no existe.', 404);
        }

        $validator = Validator::make($request->all(), [
            'body' => 'required|string|max:2000',
            'comment_category_id' => 'required|integer|exists:comment_categories,id',
            // Ubicación opcional; si viene, debe venir completa y ser válida.
            'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
            'accuracy' => 'nullable|numeric|min:0',
            'address' => 'nullable|string|max:500',
        ], [
            'body.required' => 'El comentario no puede estar vacío.',
            'body.max' => 'El comentario no puede superar los 2000 caracteres.',
            'comment_category_id.required' => 'La categoría es obligatoria.',
            'comment_category_id.exists' => 'La categoría seleccionada no existe.',
            'latitude.required_with' => 'La ubicación llegó incompleta.',
            'longitude.required_with' => 'La ubicación llegó incompleta.',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $comment = ClientComment::create([
            'client_id' => $client->id,
            'user_id' => Auth::id(),
            'comment_category_id' => $request->input('comment_category_id'),
            'body' => trim($request->input('body')),
        ]);

        $comment->load(['user:id,name,role_id', 'category:id,name']);
        $comment->location = null;

        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');

        if ($latitude !== null && $longitude !== null) {
            $latitude = (float) $latitude;
            $longitude = (float) $longitude;
            $accuracy = $request->input('accuracy') !== null ? (float) $request->input('accuracy') : null;

            // Dirección: se prefiere la que ya resolvió el front (evita una
            // llamada extra). Si no vino, se intenta acá contra la cascada de
            // proveedores. Si aun así falla, se guarda null a propósito: la
            // coordenada ya quedó registrada y el comando geo:fill-addresses
            // reintenta hasta completarla. En pantalla nunca se ve vacío.
            $address = $request->input('address') ?: $reverseGeocode->resolve($latitude, $longitude);

            $author = Auth::user();
            $geolocationHistory->record(
                $client->id,
                $latitude,
                $longitude,
                self::GEO_ACTION_TYPE,
                'Comentario registrado por ' . ($author->name ?? 'usuario') . ' (#' . $comment->id . ')',
                $comment->id,
                $address,
                $accuracy
            );

            $comment->location = [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy' => $accuracy,
                'address' => $address ?: sprintf('Ubicación %.5f, %.5f', $latitude, $longitude),
                'address_pending' => empty($address),
            ];
        }

        return $this->successResponse([
            'success' => true,
            'message' => 'Comentario agregado',
            'data' => $comment,
        ]);
    }

    public function destroy(Request $request, $clientId, $commentId)
    {
        $comment = ClientComment::where('client_id', $clientId)
            ->where('id', $commentId)
            ->first();

        if (!$comment) {
            return $this->errorResponse('El comentario no existe.', 404);
        }

        $user = Auth::user();
        $isAdmin = in_array((int) $user->role_id, [1, 2], true);
        if ((int) $comment->user_id !== (int) $user->id && !$isAdmin) {
            return $this->errorResponse('Solo el autor o un administrador puede eliminar el comentario.', 403);
        }

        // Solo se puede eliminar un comentario el MISMO DÍA en que se creó
        // (zona horaria del usuario, enviada por el front; default America/Lima).
        // Los administradores (rol 1 y 2) sí pueden moderar cualquier día.
        if (!$isAdmin) {
            $tz = $request->input('timezone') ?: 'America/Lima';
            $createdDay = $comment->created_at->copy()->setTimezone($tz)->toDateString();
            $today = now($tz)->toDateString();
            if ($createdDay !== $today) {
                return $this->errorResponse(
                    'Solo puedes eliminar un comentario el mismo día en que se creó.',
                    403
                );
            }
        }

        $comment->delete();

        return $this->successResponse([
            'success' => true,
            'message' => 'Comentario eliminado',
        ]);
    }

    // ── Categorías de comentarios (set propio, separado del de Gastos) ──────
    public function categories()
    {
        $categories = CommentCategory::orderBy('name', 'asc')->get(['id', 'name']);

        return $this->successResponse([
            'success' => true,
            'data' => $categories,
        ]);
    }

    public function storeCategory(Request $request)
    {
        // Normalizamos a formato "Título": la primera letra de cada palabra en
        // mayúscula y el resto en minúscula, sin importar cómo lo escribió el
        // usuario, para mantener un estándar visual ("cliente CLAVO" => "Cliente
        // Clavo"). mb_convert_case respeta acentos/UTF-8. Se normaliza ANTES de
        // validar para que la regla unique compare contra el nombre estándar.
        $normalized = mb_convert_case(trim((string) $request->input('name')), MB_CASE_TITLE, 'UTF-8');
        $request->merge(['name' => $normalized]);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:comment_categories,name',
        ], [
            'name.required' => 'El nombre de la categoría es obligatorio.',
            'name.unique' => 'Ya existe una categoría de comentarios con ese nombre.',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $category = CommentCategory::create([
            'name' => $normalized,
            'user_id' => Auth::id(),
        ]);

        return $this->successResponse([
            'success' => true,
            'message' => 'Categoría creada',
            'data' => $category->only(['id', 'name']),
        ]);
    }
}
