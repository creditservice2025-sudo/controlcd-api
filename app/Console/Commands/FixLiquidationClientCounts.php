<?php

namespace App\Console\Commands;

use App\Services\LiquidationService;
use App\Services\MetricsCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repara los conteos de clientes grabados en las liquidaciones: la "A" (con
 * crédito) y la "S" (sin crédito) de la columna P/A/L/S/N.
 *
 * Venían mal por dos motivos, los dos corregidos en
 * LiquidationService::clientCreditCountsForDate:
 *
 *  1. No se filtraban los créditos BORRADOS. Un crédito cargado mal, borrado y
 *     rehecho queda con deleted_at puesto pero status 'Vigente', y seguía
 *     contando como cartera viva. Son 2.345 créditos así, que mal-clasifican a
 *     888 clientes de 160 vendedores.
 *
 *  2. El conteo era una foto de HOY, no del día liquidado, y quedaba congelado
 *     con la foto del último recálculo. Medido: las liquidaciones de diciembre
 *     2025 del vendedor 21 decían 10 clientes con crédito, cuando ese vendedor
 *     no tuvo su primer crédito hasta el 2026-01-26. El valor correcto es 0.
 *
 * Por eso este comando NO reescribe con el número de hoy: reconstruye el corte
 * de CADA día con el mismo predicado que usa el resumen general
 * (creditoVivoAlCorte), que es la fuente que se tomó como buena.
 *
 * ALCANCE — SOLO CONTEO, NUNCA PLATA
 *  - Escribe únicamente esas dos columnas. No toca total_collected,
 *    total_income, total_expenses, real_to_deliver, shortage/surplus ni
 *    ninguna otra: son informativas y no entran en la fórmula de caja.
 *  - No toca NADA de Deuda & Abono (Collection): solo la tabla liquidations.
 *  - Por eso SÍ repara días ya aprobados. El cerrojo de caja firmada protege
 *    los MONTOS que el cobrador firmó, y acá ninguno se toca. Si aun así
 *    preferís no tocarlos, está --solo-abiertas.
 *  - Cada liquidación corregida deja su rastro en `liquidation_audits`, acción
 *    'correccion_conteo_clientes', con el antes y el después.
 *  - Usa query builder (no Eloquent) para no disparar observers ni mover
 *    updated_at de la liquidación.
 *  - Dry-run por defecto; --apply para escribir.
 */
class FixLiquidationClientCounts extends Command
{
    protected $signature = 'liquidations:fix-client-counts
                            {--apply : Escribe los cambios (por defecto es dry-run)}
                            {--seller=* : Acotar a uno o más vendedores}
                            {--from= : Solo liquidaciones con fecha >= }
                            {--to= : Solo liquidaciones con fecha <= }
                            {--solo-abiertas : No tocar las liquidaciones aprobadas}
                            {--por-vendedor : Recorre vendedor por vendedor (RECOMENDADO a escala: el modo global no termina)}
                            {--user= : Id de usuario a firmar en la auditoría (por defecto queda como corrección de sistema)}';

    protected $description = 'Corrige la A y la S de P/A/L/S/N reconstruyendo el corte de cada día. Solo conteo, nunca montos. Dry-run por defecto.';

    public function handle(LiquidationService $service, MetricsCacheService $cache): int
    {
        $apply = (bool) $this->option('apply');
        $soloAbiertas = (bool) $this->option('solo-abiertas');
        $userId = $this->option('user') ? (int) $this->option('user') : null;

        $sellerFilter = array_values(array_filter(array_map('intval', (array) $this->option('seller'))));
        $from = $this->option('from');
        $to = $this->option('to');

        $this->info('== Corrección de conteos de clientes — ' . ($apply ? 'APLICAR' : 'DRY-RUN') . ' ==');
        $this->warn('Solo escribe active_clients_with_credit_count y clients_without_credit_count.');
        $this->line('NO toca montos: recaudo, ingresos, gastos, real_to_deliver y faltante/sobrante quedan intactos.');
        $this->line('Cada día se reconstruye con SU corte, no con la foto de hoy.');
        $this->newLine();

        $query = DB::table('liquidations')->whereNull('deleted_at');
        if ($sellerFilter) {
            $query->whereIn('seller_id', $sellerFilter);
        }
        if ($from) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('date', '<=', $to);
        }
        if ($soloAbiertas) {
            $query->where('status', '!=', 'approved');
        }

        $liquidaciones = $query
            ->orderBy('date')
            ->get(['id', 'seller_id', 'date', 'status',
                   'active_clients_with_credit_count', 'clients_without_credit_count']);

        if ($liquidaciones->isEmpty()) {
            $this->info('No hay liquidaciones en el alcance. Nada que hacer.');
            return self::SUCCESS;
        }

        // Cómo se recorre el trabajo. Las dos formas dan el MISMO resultado
        // (lo fija un test); cambia el tamaño de cada consulta:
        //
        //  - por FECHA (global): una consulta por día para TODOS los vendedores
        //    de ese día. Parece lo más barato y a escala completa no termina:
        //    medido sobre 27.080 liquidaciones, +35 min sin producir salida.
        //    El join sobre credits+payments sin acotar por vendedor es el cuello.
        //
        //  - por VENDEDOR (--por-vendedor): las mismas consultas pero acotadas
        //    a un vendedor. Medido: 257 fechas de un vendedor en 8,9 s, y los
        //    225 vendedores del sistema en 19m23s, con progreso visible y
        //    fallando de a uno si falla. Es el modo para correr esto de verdad.
        $grupos = $this->option('por-vendedor')
            ? $liquidaciones->groupBy('seller_id')->map(fn ($f) => $f->groupBy(fn ($l) => substr((string) $l->date, 0, 10)))
            : collect(['todos' => $liquidaciones->groupBy(fn ($l) => substr((string) $l->date, 0, 10))]);

        $cortes = $grupos->sum(fn ($g) => $g->count());

        $this->line('Liquidaciones en alcance: <options=bold>' . $liquidaciones->count() . '</>'
            . ' en ' . $cortes . ' cortes'
            . ($this->option('por-vendedor') ? ' de ' . $grupos->count() . ' vendedores' : ' (fechas distintas)'));

        $barra = $this->output->createProgressBar($cortes);
        $barra->start();

        $pendientes = [];

        foreach ($grupos as $porFecha) {
            foreach ($porFecha as $fecha => $filas) {
                $sellerIds = $filas->pluck('seller_id')->unique()->values()->all();
                $estado = $service->getClientCreditStateBySeller($fecha, null, $sellerIds);

                foreach ($filas as $f) {
                    $e = $estado[$f->seller_id] ?? null;
                    $a = (int) ($e['clients_with_active_credit'] ?? 0);
                    $sc = (int) ($e['clients_without_credit'] ?? 0);

                    if ((int) $f->active_clients_with_credit_count === $a
                        && (int) $f->clients_without_credit_count === $sc) {
                        continue;
                    }

                    $pendientes[] = [
                        'id' => (int) $f->id,
                        'seller_id' => (int) $f->seller_id,
                        'date' => $fecha,
                        'status' => $f->status,
                        'a_antes' => (int) $f->active_clients_with_credit_count,
                        'a_despues' => $a,
                        's_antes' => (int) $f->clients_without_credit_count,
                        's_despues' => $sc,
                    ];
                }

                $barra->advance();
            }
        }

        $barra->finish();
        $this->newLine(2);

        if (!$pendientes) {
            $this->info('Todos los conteos ya están correctos. Nada que corregir.');
            return self::SUCCESS;
        }

        $muestra = array_map(fn ($p) => [
            $p['seller_id'],
            $p['date'],
            $p['status'],
            $p['a_antes'] . ' → ' . $p['a_despues'],
            $p['s_antes'] . ' → ' . $p['s_despues'],
        ], array_slice($pendientes, 0, 30));

        $this->table(['vendedor', 'fecha', 'estado', 'A (con crédito)', 'S (sin crédito)'], $muestra);
        if (count($pendientes) > 30) {
            $this->line('  ... y ' . (count($pendientes) - 30) . ' liquidaciones más.');
        }

        $aprobadas = count(array_filter($pendientes, fn ($p) => $p['status'] === 'approved'));

        $this->newLine();
        $this->info('Resumen: ' . count($pendientes) . ' liquidaciones a corregir'
            . ($aprobadas ? " ({$aprobadas} de ellas ya aprobadas — solo se les tocan los conteos)" : '') . '.');

        if (!$apply) {
            $this->newLine();
            $this->warn('DRY-RUN: no se escribió nada. Volvé a correr con --apply.');
            return self::SUCCESS;
        }

        $escritas = 0;
        $ahora = now();

        foreach (array_chunk($pendientes, 200) as $lote) {
            DB::transaction(function () use ($lote, $userId, $ahora, &$escritas) {
                $auditorias = [];

                foreach ($lote as $p) {
                    DB::table('liquidations')->where('id', $p['id'])->update([
                        'active_clients_with_credit_count' => $p['a_despues'],
                        'clients_without_credit_count' => $p['s_despues'],
                    ]);

                    // El histórico del cambio. Se inserta a mano porque el
                    // update de arriba va por query builder y no despierta al
                    // LiquidationAuditObserver.
                    $auditorias[] = [
                        'liquidation_id' => $p['id'],
                        'user_id' => $userId,
                        'action' => 'correccion_conteo_clientes',
                        'changes' => json_encode([
                            'description' => 'Corrección del conteo de clientes: se dejaron de contar los créditos borrados y el número pasó a reconstruirse al corte del día en vez de mostrar la foto de hoy. NO se modificó ningún monto.',
                            'fecha_liquidacion' => $p['date'],
                            'estado_liquidacion' => $p['status'],
                            'active_clients_with_credit_count' => [
                                'antes' => $p['a_antes'],
                                'despues' => $p['a_despues'],
                            ],
                            'clients_without_credit_count' => [
                                'antes' => $p['s_antes'],
                                'despues' => $p['s_despues'],
                            ],
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ];

                    $escritas++;
                }

                DB::table('liquidation_audits')->insert($auditorias);
            });

            // El caché de métricas vive una hora por (vendedor, fecha). Sin
            // esto la pantalla seguiría mostrando el conteo viejo hasta que
            // expire, y parecería que el comando no hizo nada. Va FUERA de la
            // transacción: si el commit falla, no hay nada que invalidar.
            foreach ($lote as $p) {
                $cache->invalidateLiquidationMetrics($p['seller_id'], $p['date']);
            }
        }

        $this->newLine();
        $this->info("✓ Corregidas {$escritas} liquidaciones, cada una con su registro en liquidation_audits.");
        $this->line("  Para revisarlas: select * from liquidation_audits where action = 'correccion_conteo_clientes';");

        return self::SUCCESS;
    }
}
