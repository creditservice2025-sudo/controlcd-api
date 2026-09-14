<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Healthcheck del auto-cierre de Collection (Deuda & Abono).
 *
 * El corte de caja y el arrastre del saldo dependen de que `schedule:run` (que
 * dispara `collection:check-pending-closures` cada minuto) esté vivo. Si el cron
 * muere, se acumulan días huérfanos y el acumulado se corrompe EN SILENCIO.
 *
 * Este comando revisa el "heartbeat" que deja el cron en cada corrida y:
 *   - Devuelve exit 0 si el latido es fresco (sano).
 *   - Devuelve exit 1 si está frío o ausente (el scheduler probablemente murió),
 *     y registra el problema en el log.
 *
 * Pensado para ser invocado por un monitor EXTERNO (uptime, cron aparte, CI),
 * no por el mismo scheduler que vigila.
 *
 * Uso:
 *   php artisan collection:healthcheck            # umbral 10 min
 *   php artisan collection:healthcheck --max-lag=5
 */
class CollectionHealthcheck extends Command
{
    protected $signature = 'collection:healthcheck {--max-lag=10 : Minutos máximos sin latido antes de marcar caído}';

    protected $description = 'Verifica que el cron de auto-cierre de Collection esté vivo (heartbeat)';

    private const KEY = 'collection:autoclose:last_run';

    public function handle(): int
    {
        $maxLag = max(2, (int) $this->option('max-lag'));

        $lastRunRaw = null;
        try {
            $lastRunRaw = Cache::store('file')->get(self::KEY);
        } catch (\Throwable $e) {
            $this->error('No se pudo leer el heartbeat: ' . $e->getMessage());
        }

        if (!$lastRunRaw) {
            $msg = 'CAÍDO: no hay heartbeat del auto-cierre de Collection (el scheduler nunca corrió o el cache se limpió).';
            $this->error($msg);
            Log::error('[collection:healthcheck] ' . $msg);
            return self::FAILURE;
        }

        $lastRun = Carbon::parse($lastRunRaw);
        $lagMin = (int) round($lastRun->diffInMinutes(Carbon::now()));

        if ($lagMin > $maxLag) {
            $msg = "CAÍDO: el auto-cierre de Collection no late hace {$lagMin} min (umbral {$maxLag}). Revisar schedule:run.";
            $this->error($msg);
            Log::error('[collection:healthcheck] ' . $msg . ' Último latido: ' . $lastRun->toIso8601String());
            return self::FAILURE;
        }

        $this->info("SANO: auto-cierre de Collection latió hace {$lagMin} min (último: {$lastRun->toIso8601String()}).");
        return self::SUCCESS;
    }
}
