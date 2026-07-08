<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Seller;

/**
 * Prende/apaga el auto-cierre por vendedor (seller_configs.auto_closures_collectors).
 *
 * OJO: este flag por si solo NO cierra nada. Es el interruptor que marca QUIENES
 * deben auto-cerrarse; el que ejecuta es el cron (schedule:run -> liquidation:
 * auto-daily). Hacen falta LOS DOS.
 *
 * Por defecto DRY-RUN. Con --apply aplica. Reversible (--off vuelve atras).
 *
 *   php artisan sellers:auto-closure --on --seller=135,37        (dry-run)
 *   php artisan sellers:auto-closure --on --seller=135,37 --apply
 *   php artisan sellers:auto-closure --on --all --apply          (todos)
 *   php artisan sellers:auto-closure --on --role=Cobrador --apply (solo cobradores)
 *   php artisan sellers:auto-closure --off --all --apply         (revertir)
 */
class ToggleSellerAutoClosure extends Command
{
    protected $signature = 'sellers:auto-closure {--on} {--off} {--seller=} {--role=} {--all} {--apply}';
    protected $description = 'Prende/apaga el auto-cierre por vendedor (auto_closures_collectors). Dry-run por defecto.';

    public function handle()
    {
        $on = (bool) $this->option('on');
        $off = (bool) $this->option('off');
        if ($on === $off) {
            $this->error('Especificá exactamente uno: --on o --off.');
            return self::FAILURE;
        }
        $value = $on ? 1 : 0;

        $sellerOpt = $this->option('seller');
        $roleOpt = $this->option('role');
        $all = (bool) $this->option('all');
        if (!$sellerOpt && !$roleOpt && !$all) {
            $this->error('Especificá el alcance: --seller=1,2,3, --role=Cobrador o --all.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info('== Auto-cierre por vendedor → ' . ($on ? 'PRENDER' : 'APAGAR')
            . ' · ' . ($apply ? 'APLICAR' : 'DRY-RUN') . ' ==');

        $query = Seller::query()->with(['config', 'user']);
        if ($sellerOpt) {
            $ids = array_filter(array_map('intval', explode(',', $sellerOpt)));
            $query->whereIn('id', $ids);
        }
        if ($roleOpt) {
            // Filtro por nombre de rol via la relacion, agnostico al guard
            // (Cobrador vive en guard 'api', el scope role() de Spatie asume 'web').
            $query->whereHas('user.roles', function ($q) use ($roleOpt) {
                $q->where('name', $roleOpt);
            });
        }
        $sellers = $query->orderBy('id')->get();

        if ($sellers->isEmpty()) {
            $this->warn('No se encontraron vendedores para ese criterio.');
            return self::SUCCESS;
        }

        $toChange = [];
        $sinConfig = [];
        foreach ($sellers as $s) {
            if (!$s->config) {
                $sinConfig[] = $s;
                continue;
            }
            $current = (int) $s->config->auto_closures_collectors;
            if ($current !== $value) {
                $toChange[] = $s;
            }
        }

        $this->line("Vendedores evaluados: {$sellers->count()}");
        $this->line("<fg=green>A cambiar (" . ($on ? '0→1' : '1→0') . "): " . count($toChange) . "</>");
        foreach (array_slice($toChange, 0, 40) as $s) {
            $this->line('   seller ' . str_pad($s->id, 4) . '  ' . (optional($s->user)->name ?? '?'));
        }
        if (count($toChange) > 40) {
            $this->line('   ... y ' . (count($toChange) - 40) . ' más');
        }
        if ($sinConfig) {
            $this->warn('Sin registro de config (se omiten): ' . count($sinConfig)
                . ' → ' . collect($sinConfig)->pluck('id')->implode(', '));
        }

        if (!$apply) {
            $this->newLine();
            $this->comment('Dry-run: no se modificó nada. Agregá --apply para aplicar.');
            return self::SUCCESS;
        }

        $changed = 0;
        foreach ($toChange as $s) {
            $s->config->update(['auto_closures_collectors' => $value]);
            $changed++;
        }

        $this->newLine();
        $this->info("APLICADO: $changed vendedores → auto_closures_collectors = $value.");
        $this->line('Recordá: el flag no cierra solo — falta que el cron del servidor ('
            . '* * * * * php artisan schedule:run) esté vivo.');
        return self::SUCCESS;
    }
}
