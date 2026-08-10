<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Blinda la cadencia del auto-cierre. auto-daily cierra SOLO el dia en curso
 * (hoy), en la ventana 23:55-23:59 de la zona del pais del vendedor. Se quito la
 * ventana 00:00-00:29 que cerraba AYER (el cierre es del mismo dia, nunca
 * retroactivo). Como la ventana es corta y no hay red de las 00:00, debe correr
 * cada minuto: asi 23:55-23:59 se dispara 5 veces (reintentos same-day). Cada 5
 * minutos solo pegaria las 23:55 -> un unico intento = riesgo de dia huerfano.
 */
class ScheduleAutoDailyTest extends TestCase
{
    public function test_auto_daily_corre_cada_minuto(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        // Ubicar la linea del comando auto-daily
        $line = collect(explode("\n", $output))
            ->first(fn ($l) => str_contains($l, 'liquidation:auto-daily'));

        $this->assertNotNull($line, 'liquidation:auto-daily debe estar programado');

        // Normalizar espacios para comparar la expresion cron
        $normalized = preg_replace('/\s+/', ' ', $line);

        $this->assertStringContainsString(
            '* * * * *',
            $normalized,
            'auto-daily debe correr cada minuto: la ventana 23:55-23:59 necesita reintentos same-day y no hay red de las 00:00'
        );
        $this->assertStringNotContainsString(
            '*/5',
            $normalized,
            'auto-daily ya no corre cada 5 min (la ventana 23:55 se disparaba una sola vez)'
        );
    }

    /**
     * El cron cierra SOLO el día en curso. El barrido de días anteriores no va
     * programado: cerrar un día viejo le recalcula los montos con los datos de
     * hoy y no recalcula los días que vienen detrás, así que el `initial_cash`
     * del siguiente queda desalineado. Medido antes de apagarlo: 215 días
     * abiertos con fecha pasada, 96 de ellos con días posteriores.
     *
     * El barrido sigue existiendo como herramienta manual, para usarse en
     * ventana controlada y verificando la cadena antes y después.
     */
    public function test_el_cron_no_cierra_dias_anteriores(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $line = collect(explode("\n", $output))
            ->first(fn ($l) => str_contains($l, 'liquidation:auto-daily'));

        $this->assertNotNull($line, 'liquidation:auto-daily debe estar programado');

        $this->assertStringContainsString(
            '--no-sweep',
            preg_replace('/\s+/', ' ', $line),
            'El cron programado debe llevar --no-sweep: un despliegue no puede cerrar ni recalcular días históricos por su cuenta.'
        );
    }
}
