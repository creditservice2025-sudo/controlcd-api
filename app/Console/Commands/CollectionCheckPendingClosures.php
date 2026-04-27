<?php

namespace App\Console\Commands;

use App\Models\Collection\CollectionCashClosure;
use App\Models\Company;
use App\Models\User;
use App\Notifications\GeneralNotification;
use App\Services\Collection\CollectionCashClosureService;
use App\Services\Collection\CollectionTelegramNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Verifica cierres de caja pendientes para cada empresa con módulo Collection
 * habilitado. Envía recordatorios escalonados durante el día y, al final del
 * día (23:59 hora local de la empresa), genera un auto-cierre que el admin
 * deberá validar al día siguiente.
 *
 * Programado para correr cada hora vía Laravel Scheduler.
 */
class CollectionCheckPendingClosures extends Command
{
    protected $signature = 'collection:check-pending-closures
        {--company= : Procesar solo una empresa específica}
        {--force : Ignorar las ventanas horarias y procesar todo}';

    protected $description = 'Recordatorios + auto-cierre de caja para módulo Collection';

    // Ventanas horarias (hora local empresa) y mensajes asociados.
    private const REMINDER_WINDOWS = [
        18 => ['level' => 'soft', 'title' => 'Recuerda cerrar la caja del día'],
        21 => ['level' => 'medium', 'title' => 'Cierre del día pendiente'],
        23 => ['level' => 'urgent', 'title' => 'Última llamada — auto-cierre en 30 min'],
    ];
    private const AUTO_CLOSE_HOUR = 23;     // hora local
    private const AUTO_CLOSE_MINUTE = 59;   // minuto local

    public function __construct(
        private readonly CollectionCashClosureService $closureSvc,
        private readonly CollectionTelegramNotifier $telegram,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $companyId = $this->option('company');
        $force = (bool) $this->option('force');

        $companies = Company::query()
            ->where('is_collection_enabled', true)
            ->when($companyId, fn($q) => $q->where('id', $companyId))
            ->get();

        if ($companies->isEmpty()) {
            $this->info('No hay empresas con módulo Collection habilitado.');
            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            try {
                $this->processCompany($company, $force);
            } catch (\Throwable $e) {
                Log::error("CollectionCheckPendingClosures error en company {$company->id}: " . $e->getMessage());
                $this->error("Error en empresa {$company->id}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function processCompany(Company $company, bool $force): void
    {
        // Asume timezone por país (simplificación). En el futuro se puede leer
        // de companies.timezone si se agrega esa columna.
        $tz = $this->guessTimezone($company);
        $now = Carbon::now($tz);
        $today = $now->toDateString();
        $hour = (int) $now->format('H');
        $minute = (int) $now->format('i');

        // ¿Existe cierre activo o auto_pending para HOY? Si sí, nada que hacer.
        $existing = CollectionCashClosure::where('company_id', $company->id)
            ->where('closure_date', $today)
            ->whereIn('status', [
                CollectionCashClosure::STATUS_CLOSED,
                CollectionCashClosure::STATUS_AUTO_PENDING,
            ])
            ->exists();

        if ($existing) {
            $this->line("[{$company->id}] {$today} ya tiene cierre. Skip.");
            return;
        }

        // Auto-cierre: si pasamos las 23:59 y todavía no hay cierre.
        $isAutoCloseTime = $hour === self::AUTO_CLOSE_HOUR && $minute >= self::AUTO_CLOSE_MINUTE;
        if ($isAutoCloseTime || $force) {
            $closure = $this->closureSvc->autoCloseDay($company->id, $today, $tz);
            if ($closure) {
                $this->info("[{$company->id}] Auto-cierre creado para {$today}");
                $this->notifyAdmins(
                    $company,
                    'Auto-cierre generado',
                    "Se generó un cierre automático para el {$today} porque no se realizó manualmente. " .
                    "Esperado en caja: {$closure->esperado}. Validalo desde la sección 'Cierres por validar'.",
                    "/collection/cierres-pendientes",
                    'auto_close',
                );
            }
            return;
        }

        // Recordatorios: si la hora coincide con una ventana, envía.
        if (isset(self::REMINDER_WINDOWS[$hour]) && $minute < 60) {
            $window = self::REMINDER_WINDOWS[$hour];
            $msg = "{$window['title']}. La caja del {$today} no ha sido cerrada. " .
                   ($hour >= 23 ? "En 30 min se generará un cierre automático." : "Ciérrala desde el módulo Deuda & Abono.");
            $this->notifyAdmins(
                $company,
                $window['title'],
                $msg,
                "/collection/daily-records",
                "reminder_{$window['level']}",
            );
            $this->line("[{$company->id}] Recordatorio {$window['level']} enviado.");
        }
    }

    /**
     * Notifica a todos los admins (rol 1 o 2) de la empresa via in-app +
     * Telegram (si la empresa tiene chat configurado).
     */
    private function notifyAdmins(Company $company, string $title, string $message, ?string $url, string $type): void
    {
        $admins = User::where('company_id', $company->id)
            ->whereIn('role_id', [1, 2])
            ->get();

        // In-app a cada admin
        foreach ($admins as $admin) {
            try {
                $admin->notify(new GeneralNotification($title, $message, $url, ['type' => $type, 'module' => 'collection']));
            } catch (\Throwable $e) {
                Log::warning("Notif in-app falló para user {$admin->id}: " . $e->getMessage());
            }
        }

        // Telegram (1 mensaje al chat de la empresa, si configurado)
        $telegramMsg = "*{$title}*\n\n{$message}";
        $this->telegram->sendToCompany($company->id, $telegramMsg, "collection_{$type}");
    }

    /**
     * Adivina timezone razonable por país (default Bogotá).
     */
    private function guessTimezone(Company $company): string
    {
        // En el futuro: leer de companies.timezone. Por ahora: Bogotá.
        return 'America/Bogota';
    }
}
