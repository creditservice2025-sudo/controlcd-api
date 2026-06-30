<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientComment;
use App\Models\CommentCategory;
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
 */
class ClientCommentController extends Controller
{
    use ApiResponse;

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

        return $this->successResponse([
            'success' => true,
            'data' => $comments,
        ]);
    }

    public function store(Request $request, $clientId)
    {
        $client = Client::find($clientId);
        if (!$client) {
            return $this->errorResponse('El cliente no existe.', 404);
        }

        $validator = Validator::make($request->all(), [
            'body' => 'required|string|max:2000',
            'comment_category_id' => 'required|integer|exists:comment_categories,id',
        ], [
            'body.required' => 'El comentario no puede estar vacío.',
            'body.max' => 'El comentario no puede superar los 2000 caracteres.',
            'comment_category_id.required' => 'La categoría es obligatoria.',
            'comment_category_id.exists' => 'La categoría seleccionada no existe.',
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
