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
 * Corte de caja AUTOMÁTICO del módulo Collection. Ya no existe cierre manual:
 * para cada empresa con el módulo habilitado, a las 23:59:59 de SU hora local
 * cierra el día (corte total). Además, como red de seguridad, cierra cualquier
 * día anterior con movimientos que se haya quedado sin cierre (ej. el cron no
 * corrió a las 23:59 ese día).
 *
 * Debe agendarse cada minuto (bootstrap/app.php) para clavar las 23:59 en cada
 * zona horaria. Requiere `schedule:run` vivo en el servidor.
 */
class CollectionCheckPendingClosures extends Command
{
    protected $signature = 'collection:check-pending-closures
        {--company= : Procesar solo una empresa específica}
        {--force : Ignorar la ventana horaria y cortar el día ya}';

    protected $description = 'Corte de caja automático (23:59:59 hora local) para módulo Collection';

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
        $tz = $this->guessTimezone($company);
        $now = Carbon::now($tz);
        $today = $now->toDateString();
        $hour = (int) $now->format('H');
        $minute = (int) $now->format('i');

        // Red de seguridad: cerrar cualquier día ANTERIOR con movimientos que se
        // haya quedado sin cierre (ej. el cron no corrió a las 23:59 ese día).
        foreach ($this->closureSvc->autoCloseMissedDays($company->id, $tz) as $missedDate) {
            $this->info("[{$company->id}] Corte automático recuperado del {$missedDate}");
        }

        // Corte del día actual: solo a partir de las 23:59 hora local.
        $isAutoCloseTime = $hour === self::AUTO_CLOSE_HOUR && $minute >= self::AUTO_CLOSE_MINUTE;
        if (!$isAutoCloseTime && !$force) {
            return;
        }

        // ¿Ya tiene cierre HOY? Nada que hacer.
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

        $closure = $this->closureSvc->autoCloseDay($company->id, $today, $tz);
        if ($closure) {
            $this->info("[{$company->id}] Corte automático del día {$today}");
            $this->notifyAdmins(
                $company,
                'Caja cerrada automáticamente',
                "La caja del {$today} se cerró automáticamente a las 23:59:59. " .
                "Esperado en caja: {$closure->esperado}.",
                "/collection/daily-records",
                'auto_close',
            );
        }
    }

    /**
     * Notifica a todos los admins (rol 1 o 2) de la empresa via in-app +
     * Telegram (si la empresa tiene chat configurado).
     */
    private function notifyAdmins(Company $company, string $title, string $message, ?string $url, string $type): void
    {
        // El admin de la empresa es su usuario dueño (companies.user_id). Los
        // usuarios NO tienen company_id; la relación va por Company::user.
        $owner = $company->user;
        $admins = ($owner && in_array((int) $owner->role_id, [1, 2], true))
            ? collect([$owner])
            : collect();

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
     * Zona horaria (IANA) de la empresa. Se lee de companies.timezone; si la
     * empresa no la tiene configurada, cae a America/Lima como valor seguro.
     */
    private function guessTimezone(Company $company): string
    {
        return $company->timezone ?: 'America/Lima';
    }
}
