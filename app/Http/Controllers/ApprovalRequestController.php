<?php

namespace App\Http\Controllers;

use App\Models\ImageApprovalRequest;
use App\Models\Client;
use App\Models\Seller;
use App\Models\Image;
use App\Models\User;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Helpers\Helper;
use App\Notifications\GeneralNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ApprovalRequestController extends Controller
{
    use ApiResponse;

    /**
     * Listar solicitudes para el SuperAdmin.
     */
    public function index(Request $request)
    {
        $status = $request->input('status', 'pending');
        $requests = ImageApprovalRequest::with(['user', 'entity'])
            ->where('status', $status)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return $this->successResponse([
            'success' => true,
            'data' => $requests->items(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'total' => $requests->total(),
                'last_page' => $requests->lastPage(),
            ]
        ]);
    }

    /**
     * Aprobar solicitud y generar token.
     */
    public function approve($id)
    {
        $approvalRequest = ImageApprovalRequest::findOrFail($id);
        
        if ($approvalRequest->status !== 'pending') {
            return $this->errorResponse('Esta solicitud ya no está pendiente.', 422);
        }

        $token = $approvalRequest->generateToken();
        $approvalRequest->update(['status' => 'approved']);

        // Notificar al vendedor
        $vendedor = $approvalRequest->user;
        $entityName = $approvalRequest->entity_type === 'Seller' ? 'tu perfil' : 'un crédito/cliente';
        
        $vendedor->notify(new GeneralNotification(
            "Solicitud de imagen aprobada",
            "Tu solicitud para actualizar la imagen de {$entityName} ha sido aprobada. Usa el código: {$token} para confirmar el cambio.",
            null
        ));

        return $this->successResponse([
            'success' => true,
            'message' => 'Solicitud aprobada. El vendedor ha sido notificado con el token.',
            'token' => $token // Solo para debug/admin si fuera necesario
        ]);
    }

    /**
     * Rechazar solicitud.
     */
    public function reject(Request $request, $id)
    {
        $approvalRequest = ImageApprovalRequest::findOrFail($id);
        $reason = $request->input('reason', 'Solicitud rechazada por el administrador.');

        $approvalRequest->update([
            'status' => 'rejected',
            'reason' => $reason
        ]);

        $approvalRequest->user->notify(new GeneralNotification(
            "Solicitud de imagen rechazada",
            "Tu solicitud de cambio de imagen ha sido rechazada. Motivo: {$reason}",
            null
        ));

        return $this->successResponse(['success' => true, 'message' => 'Solicitud rechazada.']);
    }

    /**
     * Confirmar y aplicar el cambio con el token (Vendedor).
     */
    public function confirmWithToken(Request $request)
    {
        $request->validate([
            'request_id' => 'required|exists:image_approval_requests,id',
            'token' => 'required|string|size:6'
        ]);

        $approvalRequest = ImageApprovalRequest::findOrFail($request->request_id);

        if ($approvalRequest->status !== 'approved') {
            return $this->errorResponse('La solicitud debe estar aprobada para confirmarse.', 422);
        }

        if ($approvalRequest->token !== $request->token) {
            return $this->errorResponse('El código ingresado es incorrecto.', 422);
        }

        if ($approvalRequest->expires_at->isPast()) {
            return $this->errorResponse('El código ha expirado.', 422);
        }

        // Aplicar el cambio final
        DB::beginTransaction();
        try {
            $entity = $approvalRequest->entity;
            $newPath = $approvalRequest->new_image_path;
            $type = $approvalRequest->image_type;

            // Mover archivo de temp a destino final
            $finalFolder = $approvalRequest->entity_type === 'Seller' ? 'sellers/profiles' : 'clients';
            $fileName = basename($newPath);
            $finalPath = "{$finalFolder}/{$fileName}";

            if (Storage::disk('public')->exists($newPath)) {
                Storage::disk('public')->move($newPath, $finalPath);
            }

            // Actualizar tabla Image
            $existingImage = $entity->images()->where('type', $type)->first();
            if ($existingImage) {
                $existingImage->delete(); // SoftDelete ya habilitado
            }

            $entity->images()->create([
                'path' => $finalPath,
                'type' => $type
            ]);

            $approvalRequest->update(['status' => 'applied']);

            DB::commit();
            return $this->successResponse(['success' => true, 'message' => '¡Imagen actualizada con éxito!']);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Error aplicando cambio de imagen: " . $e->getMessage());
            return $this->errorResponse('Error al procesar el cambio final.', 500);
        }
    }
}
