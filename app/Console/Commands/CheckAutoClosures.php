<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use App\Models\Seller;
use App\Models\Liquidation;
use App\Helpers\TimezoneHelper;
use Carbon\Carbon;

/**
 * Verifica que el auto-cierre (liquidation:auto-daily) haya hecho su trabajo.
 *
 * Para cada vendedor con auto_closures_collectors = true, mira el dia que YA
 * debio cerrarse (ayer en la zona del pais del vendedor). Si ese dia existe y
 * sigue en 'En curso', el auto-cierre fallo (cron caido, excepcion, ventana
 * perdida) y se NOTIFICA por correo con la lista.
 *
 * NO cierra nada: solo verifica y avisa. Complementa a auto-daily; se agenda un
 * rato despues de la medianoche de todos los paises.
 *
 * OJO: si el servidor esta caido, este chequeo tampoco corre (comparte el mismo
 * cron). Para cubrir ese caso hace falta un monitor EXTERNO (dead-man switch).
 *
 *   php artisan liquidation:check-auto-closures            (revisa ayer, envia correo)
 *   php artisan liquidation:check-auto-closures --dry      (solo muestra, no envia)
 *   php artisan liquidation:check-auto-closures --date=2026-07-08
 *   php artisan liquidation:check-auto-closures --email=otro@correo.com
 */
class CheckAutoClosures extends Command
{
    protected $signature = 'liquidation:check-auto-closures {--date=} {--email=} {--dry}';
    protected $description = 'Verifica el auto-cierre de los cobradores y notifica por correo los dias que quedaron sin cerrar.';

    private const ALERT_EMAIL = 'creditservice2025@gmail.com';

    public function handle()
    {
        $dry = (bool) $this->option('dry');
        $alertEmail = $this->option('email') ?: self::ALERT_EMAIL;
        $forcedDate = $this->option('date');

        $sellers = Seller::whereHas('config', function ($q) {
            $q->where('auto_closures_collectors', true);
        })->with('user')->get();

        $failed = [];
        $skippedTooEarly = 0;
        foreach ($sellers as $seller) {
            $tz = TimezoneHelper::getSellerTimezone($seller);
            $nowLocal = Carbon::now($tz);

            // GUARDA anti-falso-positivo: si en el pais del vendedor todavia es
            // muy temprano (< 01:00), la ventana 23:55-23:59 de "ayer" recien
            // paso y el cierre podria estar en curso. No evaluamos a ese vendedor
            // en esta corrida (lo agarra la del dia siguiente). Independiza la
            // correccion del horario UTC fijo del schedule: aunque se agregue un
            // pais mas al oeste, nunca dispara alarma antes de tiempo.
            if (!$forcedDate && $nowLocal->hour < 1) {
                $skippedTooEarly++;
                continue;
            }

            // Dia que YA debio cerrarse: ayer en la zona del vendedor (o el forzado).
            $day = $forcedDate ?: $nowLocal->copy()->subDay()->toDateString();

            $liq = Liquidation::where('seller_id', $seller->id)
                ->whereDate('date', $day)
                ->first();

            // Falla = el dia existe y sigue En curso (debio pasar a 'auto').
            // Si no existe, el vendedor no abrio ese dia: no es falla de cierre.
            if ($liq && $liq->status === 'En curso') {
                $failed[] = [
                    'seller_id' => $seller->id,
                    'name'      => optional($seller->user)->name ?? "seller {$seller->id}",
                    'tz'        => $tz,
                    'date'      => $day,
                    'liq_id'    => $liq->id,
                ];
            }
        }

        if ($skippedTooEarly > 0) {
            $this->comment("Nota: {$skippedTooEarly} vendedor(es) omitidos por ser aún temprano en su país (< 01:00). Si son muchos, atrasá el horario del schedule.");
        }

        if (empty($failed)) {
            $this->info('OK: no hay dias sin cerrar de cobradores con auto-cierre.');
            return self::SUCCESS;
        }

        // Armar el cuerpo del aviso.
        $lines = [];
        foreach ($failed as $f) {
            $lines[] = "- {$f['name']} (seller {$f['seller_id']}, {$f['tz']}) · dia {$f['date']} · liq #{$f['liq_id']} sigue EN CURSO";
        }
        $count = count($failed);
        $body = "El auto-cierre dejo {$count} dia(s) SIN cerrar (siguen 'En curso' cuando debieron pasar a 'auto').\n\n"
              . implode("\n", $lines)
              . "\n\nRevisar: que el cron del servidor (schedule:run) este vivo y que liquidation:auto-daily no este fallando.\n"
              . "Generado por liquidation:check-auto-closures.";

        $this->warn("Dias sin cerrar detectados: {$count}");
        foreach ($lines as $l) {
            $this->line($l);
        }

        if ($dry) {
            $this->newLine();
            $this->comment("Dry-run: no se envio correo. Se habria enviado a {$alertEmail}.");
            return self::SUCCESS;
        }

        try {
            Mail::raw($body, function ($m) use ($alertEmail, $count) {
                $m->to($alertEmail)
                  ->subject("[Control CD] Auto-cierre: {$count} dia(s) sin cerrar");
            });
            $this->info("Correo de alerta enviado a {$alertEmail}.");
        } catch (\Throwable $e) {
            $this->error('No se pudo enviar el correo: ' . $e->getMessage());
            \Log::error('[check-auto-closures] fallo al enviar correo', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
