<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('liquidation:auto-daily')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('liquidation:historical')->dailyAt('23:55');
        $schedule->command('liquidation:notify-pending')->dailyAt('21:52');

        // Collection (Deuda & Abono): recordatorios + auto-cierre.
        // Corre cada 30 minutos para capturar las ventanas 18, 21, 23 y 23:59.
        $schedule->command('collection:check-pending-closures')
            ->everyThirtyMinutes()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
