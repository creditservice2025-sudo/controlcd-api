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
        $sellerName = $user->name ?? 'Vendedor';
        $clientName = $request->input('client_name', 'N/A');

        // Use user ID as part of the key to make it unique per session/user
        $key = "photo_change_" . $user->id;
        
        $otp = $this->otpService->generate($key);

        $sent = $this->telegramService->sendOtp($otp, $sellerName, $clientName);

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
