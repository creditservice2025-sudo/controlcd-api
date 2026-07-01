<?php

namespace App\Http\Controllers;

use App\Services\OTPService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Auth;

class VerificationController extends Controller
{
    use ApiResponse;

    protected $otpService;
    protected $telegramService;

    public function __construct(OTPService $otpService, TelegramService $telegramService)
    {
        $this->otpService = $otpService;
        $this->telegramService = $telegramService;
    }

    /**
     * Generate and send an OTP via Telegram
     */
    public function sendOtp(Request $request)
    {
        $user = Auth::user();
        $clientName = $request->input('client_name', 'N/A');
        $clientId = $request->input('client_id');

        // Resolver el VENDEDOR dueño del cliente (contexto), independiente de
        // quién hace la edición: cuando el supervisor edita el cliente de un
        // vendedor, el mensaje debe mostrar al vendedor real Y marcar que la
        // solicitud la hizo el supervisor.
        $sellerName = null;
        if ($clientId) {
            $client = is_numeric($clientId)
                ? \App\Models\Client::find($clientId)
                : \App\Models\Client::where('uuid', $clientId)->first();
            $sellerName = optional(optional($client?->seller)->user)->name;
        }
        // Fallback: si no se pudo resolver y el editor es el propio vendedor.
        if (!$sellerName && (int) ($user->role_id ?? 0) === 5) {
            $sellerName = $user->name;
        }

        // Use user ID as part of the key to make it unique per session/user
        $key = "photo_change_" . $user->id;

        $otp = $this->otpService->generate($key);

        // Detalle de cambios (campos antes→después y fotos) calculado en el
        // frontend, para mostrarlo en el mensaje de verificación.
        $changes = $request->input('changes', []);

        // Pasar el editor (Auth::user) para que el mensaje indique si la edición
        // la realiza el vendedor o el supervisor, y el detalle de cambios.
        $sent = $this->telegramService->sendOtp($otp, $sellerName ?? 'N/D', $clientName, $user, $changes);

        if ($sent) {
            return $this->successResponse(['success' => true, 'message' => 'Código enviado correctamente via Telegram']);
        }

        return $this->errorResponse('No se pudo enviar el código. Verifique la configuración de Telegram.', 500);
    }

    /**
     * Verify the provided OTP
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        $user = Auth::user();
        $key = "photo_change_" . $user->id;
        $otp = $request->input('otp');

        if ($this->otpService->verify($key, $otp)) {
            return $this->successResponse(['valid' => true, 'message' => 'Código verificado con éxito']);
        }

        return $this->errorResponse('Código inválido o expirado', 422);
    }
}
