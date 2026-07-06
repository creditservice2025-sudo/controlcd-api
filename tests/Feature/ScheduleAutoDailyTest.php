<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Blinda la cadencia del auto-cierre. auto-daily solo actua en las ventanas
 * 23:55+ y 00:00-00:29; con ->hourly() (dispara a los :00) la ventana 23:55
 * nunca se alcanzaba y solo el run de las 00:00 cerraba el dia -> un unico
 * intento por noche = dias huerfanos si fallaba. Debe correr cada 5 minutos.
 */
class ScheduleAutoDailyTest extends TestCase
{
    public function test_auto_daily_corre_cada_cinco_minutos(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        // Ubicar la linea del comando auto-daily
        $line = collect(explode("\n", $output))
            ->first(fn ($l) => str_contains($l, 'liquidation:auto-daily'));

        $this->assertNotNull($line, 'liquidation:auto-daily debe estar programado');
        $this->assertStringContainsString(
            '*/5',
            $line,
            'auto-daily debe correr cada 5 min (no hourly), o la ventana 23:55 nunca se dispara'
        );
    }
}
