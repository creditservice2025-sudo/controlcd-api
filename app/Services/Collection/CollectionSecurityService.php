<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionAuthCode;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionSecurityService
{
    use ApiResponse;

    public function generateDeletionToken(array $payload)
    {
        $companyId = $this->resolveCompanyId($payload['company_id'] ?? null);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $requestId = 'DEL-' . strtoupper(Str::random(4));
        $code = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);

        // id generado por la secuencia de PostgreSQL.
        $auth = CollectionAuthCode::create([
            'company_id' => $companyId,
            'request_id' => $requestId,
            'code' => $code,
            'action_type' => 'delete_payment',
            'metadata' => [
                'installment_id' => $payload['installment_id'] ?? null,
                'collector_id' => Auth::id(),
                'collector_name' => Auth::user()->name ?? 'Desconocido',
            ],
            'status' => 'pending',
            'expires_at' => Carbon::now()->addMinutes(15),
            'created_at' => Carbon::now(),
        ]);

        // Entregar el código DE VERDAD: enviarlo al Telegram de la empresa
        // (donde lo ve el admin/gerente), en vez del teléfono de prueba
        // hardcodeado que había antes. Fail-safe: si la empresa no tiene
        // Telegram o falla el envío, no rompe la solicitud (el admin igual
        // puede verlo en el panel de códigos pendientes).
        $collectorName = Auth::user()->name ?? 'Un cobrador';
        $installmentId = $payload['installment_id'] ?? null;
        $msg = "🔐 *Autorización requerida*\n\n"
            . "{$collectorName} solicita ELIMINAR un pago"
            . ($installmentId ? " (cuota #{$installmentId})" : '') . ".\n"
            . "Código: *{$code}*\n"
            . "Solicitud: {$requestId}\n"
            . "Vence en 15 min. Entregá el código solo si autorizás la eliminación.";
        $delivered = false;
        try {
            $delivered = (new CollectionTelegramNotifier())
                ->sendToCompany($companyId, $msg, 'deletion_auth_code');
        } catch (\Throwable $e) {
            \Log::warning('[collection.security] no se pudo enviar el código por Telegram: ' . $e->getMessage());
        }

        return $this->successResponse([
            'request_id' => $auth->request_id,
            'expires_in' => 15, // minutes
            'delivered_via_telegram' => $delivered,
        ]);
    }

    public function validateToken(string $requestId, string $code, int $companyId)
    {
        $auth = CollectionAuthCode::where('company_id', $companyId)
            ->where('request_id', $requestId)
            ->where('code', $code)
            ->where('status', 'pending')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$auth) return false;

        $auth->update(['status' => 'used']);
        return true;
    }

    public function getPendingTokens($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        return CollectionAuthCode::where('company_id', $companyId)
            ->where('status', 'pending')
            ->where('expires_at', '>', Carbon::now())
            ->orderBy('created_at', 'desc')
            ->get();
    }

    private function resolveCompanyId($requestedId): int
    {
        if ($requestedId) return (int) $requestedId;
        $user = Auth::user();
        $companyId = $user ? ($user->company->id ?? $user->seller->company_id ?? null) : null;
        // Fail-closed: sin empresa resoluble cortamos con 422 (no null), para
        // que Collection nunca consulte con WHERE company_id IS NULL.
        abort_if($companyId === null, 422, 'No se pudo resolver la empresa para la operación de Collection.');
        return (int) $companyId;
    }
}
