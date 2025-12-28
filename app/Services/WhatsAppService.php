<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para enviar mensajes de WhatsApp usando CallMeBot API (gratuito)
 * 
 * IMPORTANTE: Para usar este servicio, el usuario debe:
 * 1. Agregar el número de CallMeBot (+34 644 44 21 82) a sus contactos
 * 2. Enviar un mensaje a ese número con el texto: "I allow callmebot to send me messages"
 * 3. Obtener su API key del mensaje de respuesta
 * 
 * Más info: https://www.callmebot.com/blog/free-api-whatsapp-messages/
 */
class WhatsAppService
{
    /**
     * URL base de CallMeBot API
     */
    private const API_URL = 'https://api.callmebot.com/whatsapp.php';

    /**
     * Tiempo de expiración del código de verificación (5 minutos)
     */
    private const CODE_EXPIRATION_MINUTES = 5;

    /**
     * Genera un código de verificación de 6 dígitos
     */
    public function generateVerificationCode(): string
    {
        return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Envía un código de verificación por WhatsApp usando CallMeBot
     * 
     * @param string $phone Número de teléfono (debe incluir código de país sin +)
     * @param string $code Código de verificación
     * @param string|null $apiKey API key de CallMeBot (opcional, se puede configurar en .env)
     * @return bool
     */
    public function sendVerificationCode(string $phone, string $code, ?string $apiKey = null): bool
    {
        try {
            // Si no se proporciona API key, intentar obtenerla del .env
            $apiKey = $apiKey ?? config('services.callmebot.api_key');

            if (!$apiKey) {
                Log::warning('WhatsApp: No se encontró API key de CallMeBot');
                // En desarrollo, simular envío exitoso
                if (config('app.env') === 'local') {
                    Log::info("WhatsApp: Código de verificación (simulado): {$code} para {$phone}");
                    return true;
                }
                return false;
            }

            // Formatear número (remover espacios, guiones, +)
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

            // Mensaje de verificación
            $message = "Tu código de verificación es: *{$code}*\n\n"
                     . "Este código expirará en " . self::CODE_EXPIRATION_MINUTES . " minutos.\n\n"
                     . "Si no solicitaste este código, ignora este mensaje.";

            // Enviar a CallMeBot API
            $response = Http::timeout(10)->get(self::API_URL, [
                'phone' => $cleanPhone,
                'text' => $message,
                'apikey' => $apiKey,
            ]);

            if ($response->successful()) {
                Log::info("WhatsApp: Código enviado exitosamente a {$phone}");
                return true;
            }

            Log::error("WhatsApp: Error al enviar código", [
                'phone' => $phone,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error("WhatsApp: Excepción al enviar código", [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Valida un código de verificación
     * 
     * @param \App\Models\Company $company
     * @param string $code
     * @return bool
     */
    public function validateCode($company, string $code): bool
    {
        // Verificar que el código no haya expirado
        if (!$company->verification_code_expires_at) {
            return false;
        }

        if (now()->isAfter($company->verification_code_expires_at)) {
            Log::info("WhatsApp: Código expirado para empresa {$company->id}");
            return false;
        }

        // Verificar que el código coincida
        if ($company->last_verification_code !== $code) {
            Log::info("WhatsApp: Código incorrecto para empresa {$company->id}");
            return false;
        }

        Log::info("WhatsApp: Código válido para empresa {$company->id}");
        return true;
    }

    /**
     * Obtiene la fecha de expiración del código
     */
    public function getCodeExpirationTime(): \Carbon\Carbon
    {
        return now()->addMinutes(self::CODE_EXPIRATION_MINUTES);
    }

    /**
     * Marca una empresa como verificada por WhatsApp
     */
    public function markAsVerified($company): void
    {
        $company->update([
            'whatsapp_verified' => true,
            'last_verification_code' => null,
            'verification_code_expires_at' => null,
        ]);

        Log::info("WhatsApp: Empresa {$company->id} marcada como verificada");
    }

    /**
     * Envía un mensaje personalizado por WhatsApp
     * 
     * @param string $phone
     * @param string $message
     * @param string|null $apiKey
     * @return bool
     */
    public function sendMessage(string $phone, string $message, ?string $apiKey = null): bool
    {
        try {
            $apiKey = $apiKey ?? config('services.callmebot.api_key');

            if (!$apiKey) {
                return false;
            }

            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

            $response = Http::timeout(10)->get(self::API_URL, [
                'phone' => $cleanPhone,
                'text' => $message,
                'apikey' => $apiKey,
            ]);

            return $response->successful();

        } catch (\Exception $e) {
            Log::error("WhatsApp: Error al enviar mensaje", [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
