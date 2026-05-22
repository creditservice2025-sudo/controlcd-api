<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Encola el envío de un mensaje Telegram para una empresa. Saca la llamada
 * HTTP del request lifecycle del usuario, permite reintentos automáticos
 * con backoff, y respeta un rate-limit global del bot (Telegram limita
 * ~30 msgs/seg).
 *
 * NOTA: el constructor recibe valores escalares (no la entidad fuente) para
 * evitar problemas de serialización si la entidad fue eliminada antes del
 * proceso del job. El mensaje ya está construido al momento de despachar.
 */
class SendTelegramNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30; // segundos entre reintentos
    public int $timeout = 15; // máximo por intento

    public function __construct(
        public int $companyId,
        public string $message,
        public string $type
    ) {}

    /**
     * Middleware del job: RateLimited evita superar el límite de Telegram
     * (~30 msgs/seg por bot). Si se satura, el job vuelve a cola.
     */
    public function middleware(): array
    {
        return [
            (new RateLimited('telegram-notifications')),
        ];
    }

    /**
     * Identificador único para evitar duplicados si el mismo evento se
     * encola dos veces (por reintentos del request original, etc.).
     */
    public function uniqueId(): string
    {
        return "telegram_notif_{$this->companyId}_" . md5($this->message);
    }

    public int $uniqueFor = 30; // segundos de ventana de unicidad

    public function handle(TelegramService $telegram): void
    {
        $company = Company::find($this->companyId);

        if (!$company) {
            Log::info('[telegram.job] empresa no encontrada, descartando', [
                'company_id' => $this->companyId,
            ]);
            return;
        }

        // sendToCompany ya valida feature_enabled, enabled y chat_id.
        // Si esos flags cambiaron entre el dispatch y el handle, el job
        // termina silenciosamente sin error.
        $sent = $telegram->sendToCompany($company, $this->message, $this->type);

        if (!$sent) {
            Log::info('[telegram.job] envío no realizado (filtros aplicados)', [
                'company_id' => $this->companyId,
                'type' => $this->type,
            ]);
        }
    }

    /**
     * Se ejecuta cuando todos los reintentos fallaron. Quedará en failed_jobs
     * para revisión manual o reintento explícito.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('[telegram.job] fallo definitivo tras todos los reintentos', [
            'company_id' => $this->companyId,
            'type' => $this->type,
            'error' => $e->getMessage(),
        ]);
    }
}
