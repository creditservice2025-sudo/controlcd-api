<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Liquidation;
use App\Services\LiquidationService;
use Carbon\Carbon;

class FixLiquidationPolicies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'liquidation:fix-policies';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Corrección masiva de pólizas en liquidaciones históricas';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando corrección de pólizas...');

        $service = app(LiquidationService::class);

        // 1. Encontrar liquidaciones con nuevos créditos pero sin póliza (o cero)
        $liquidations = Liquidation::where('new_credits', '>', 0)
            ->where(function($q) {
                $q->whereNull('poliza')
                  ->orWhere('poliza', 0);
            })
            ->orderBy('date', 'desc')
            ->get();

        $count = $liquidations->count();
        $this->info("Se encontraron {$count} liquidaciones potenciales para corregir.");

        if ($count === 0) {
            $this->info('No hay nada que corregir. ¡Todo está en orden!');
            return 0;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $fixed = 0;
        $errors = 0;

        foreach ($liquidations as $liq) {
            try {
                // Forzamos el recálculo
                $service->recalculateLiquidation($liq->seller_id, $liq->date->toDateString());
                $fixed++;
            } catch (\Exception $e) {
                $this->error("Error en ID {$liq->id}: " . $e->getMessage());
                $errors++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        
        $this->info("Proceso completado.");
        $this->info("Corregidas: {$fixed}");
        $this->info("Errores: {$errors}");

        return 0;
    }
}
