<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Liquidation;
use App\Services\LiquidationService;

/**
 * Limpia duplicados VIVOS (seller_id, día) ANTES de crear el índice único de la
 * migración siguiente (2026_07_05_000001). Sin esto, el ALTER ADD UNIQUE
 * fallaría en producción si ya existen duplicados de la carrera histórica.
 *
 * Regla de resolución (conservadora):
 *  - Se conserva la fila con MAYOR total_collected (la que tiene los datos
 *    reales); empate → updated_at más reciente → id menor.
 *  - Las demás se soft-deletean SOLO si su total_collected es ~0 (artefacto
 *    vacío de la carrera). Luego se recalcula el día y se re-encadena.
 *  - Si algún duplicado "perdedor" tiene recaudo > 0 (caso AMBIGUO: dos filas
 *    con datos distintos), NO se toca y la migración ABORTA listándolos, para
 *    que se resuelvan a mano antes de reintentar. Falla fuerte, nunca adivina.
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::select(
            "SELECT seller_id, DATE(`date`) d
             FROM liquidations
             WHERE deleted_at IS NULL
             GROUP BY seller_id, DATE(`date`)
             HAVING COUNT(*) > 1"
        );

        if (empty($groups)) {
            return; // nada que hacer
        }

        $svc = app(LiquidationService::class);
        $ambiguous = [];

        foreach ($groups as $g) {
            $rows = Liquidation::where('seller_id', $g->seller_id)
                ->whereDate('date', $g->d)
                ->orderByDesc('total_collected')
                ->orderByDesc('updated_at')
                ->orderBy('id')
                ->get();

            $keeper = $rows->shift(); // la mejor
            $losers = $rows;

            // Ambiguo: algún perdedor con datos reales → no adivinar.
            foreach ($losers as $l) {
                if ((float) $l->total_collected > 0.01) {
                    $ambiguous[] = "seller {$g->seller_id} / {$g->d} (ids: {$keeper->id} vs {$l->id})";
                }
            }
            if (!$losers->every(fn ($l) => (float) $l->total_collected <= 0.01)) {
                continue; // grupo ambiguo: se reporta abajo, no se toca
            }

            DB::transaction(function () use ($losers, $svc, $g, $keeper) {
                foreach ($losers as $l) {
                    $l->delete(); // soft-delete del artefacto vacío
                }
                $svc->recalculateLiquidation($g->seller_id, $keeper->date->toDateString());
                $svc->recalculateNextLiquidations($g->seller_id, $keeper->date->toDateString());
            });
        }

        if (!empty($ambiguous)) {
            throw new \RuntimeException(
                "Duplicados de liquidación AMBIGUOS (dos filas con recaudo). Resolvé a mano y reintentá:\n  - "
                . implode("\n  - ", array_unique($ambiguous))
            );
        }
    }

    public function down(): void
    {
        // Irreversible por diseño: no se restauran automáticamente los
        // artefactos vacíos eliminados (quedan soft-deleted y son restaurables
        // a mano si hiciera falta).
    }
};
