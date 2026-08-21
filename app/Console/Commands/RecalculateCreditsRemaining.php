<?php

namespace App\Console\Commands;

use App\Models\Credit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Resincroniza credits.remaining_amount y status desde la cadena real
 * (installments + payments), para los créditos que quedaron desincronizados.
 *
 * ORIGEN DEL DAÑO (auditoría 2026-08-19, 129.735 créditos vivos):
 *  - ImportService creaba el crédito SIN remaining_amount, así que nacía en 0
 *    (el default de la columna) y solo se corregía de rebote si más tarde
 *    recibía un pago. Los importados sin ningún pago se quedaban en cero para
 *    siempre: 927 créditos ocultando $420.274.740 de deuda real.
 *  - recalculateRemainingAndStatus() no restaba unapplied_amount, así que un
 *    abono que no completaba una cuota entraba a caja sin bajar el saldo del
 *    cliente: se le seguía cobrando lo que ya había puesto.
 * Ambos ya están corregidos en el código. Este comando repara lo YA cargado.
 *
 * DISEÑO PARA PRODUCCIÓN — este comando corre con cobradores trabajando:
 *  1. SIMULA por defecto. Sin --apply no escribe nada.
 *  2. La simulación es exacta: corre el MISMO método que la aplicación real
 *     dentro de una transacción y hace rollback. No replica la lógica, así que
 *     no puede divergir de lo que pasaría de verdad.
 *  3. Solo toca los créditos que están mal. Los sanos ni se abren.
 *  4. Trabaja en lotes chicos, con pausa opcional (--sleep) para no saturar
 *     la base mientras la ruta está cobrando.
 *  5. Deja auditoría CSV (antes/después por crédito, con vendedor y cliente).
 *  6. Deja un .sql de REVERSA que devuelve todo al estado previo.
 *  7. Se puede correr ruta por ruta (--seller-id) y reanudar (--from-id).
 *
 * Uso típico:
 *   php artisan credits:recalculate-remaining --seller-id=72            # simula
 *   php artisan credits:recalculate-remaining --seller-id=72 --apply    # aplica
 *   php artisan credits:recalculate-remaining --apply --sleep=200       # todo, suave
 */
class RecalculateCreditsRemaining extends Command
{
    protected $signature = 'credits:recalculate-remaining
                            {--apply : Aplica los cambios. SIN este flag el comando solo simula}
                            {--dry-run : Explícitamente simular (es el comportamiento por defecto)}
                            {--seller-id= : Procesar solo la ruta de un vendedor}
                            {--pais= : Procesar solo un país (cada país es una moneda distinta)}
                            {--credit-id= : Procesar un único crédito}
                            {--exclude= : Ids de crédito a NO tocar, separados por coma (los que requieren revisión manual)}
                            {--status= : Filtrar por status del crédito}
                            {--from-id= : Reanudar desde este id de crédito}
                            {--limit= : Procesar como máximo N créditos}
                            {--chunk=200 : Tamaño del lote}
                            {--sleep=0 : Pausa en milisegundos entre lotes (para no saturar la BD)}
                            {--threshold=0.01 : Diferencia mínima en $ para considerar que hay que corregir}
                            {--max-delta= : Corregir solo créditos cuyo ajuste sea menor a este monto (cuarentena de outliers)}
                            {--min-delta= : Corregir solo créditos cuyo ajuste supere este monto (para revisar los grandes aparte)}
                            {--force : No pedir confirmación al aplicar}
                            {--csv= : Ruta del CSV de auditoría}';

    protected $description = 'Resincroniza remaining_amount y status desde installments+payments (simula por defecto, con auditoría y reversa)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $threshold = (float) $this->option('threshold');
        $chunk = max(1, (int) $this->option('chunk'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if ($apply && $this->option('dry-run')) {
            $this->error('--apply y --dry-run son contradictorios. Elegí uno.');
            return self::FAILURE;
        }

        $candidatos = $this->buscarCandidatos($threshold, $limit);

        if (empty($candidatos)) {
            $this->info('No hay créditos desincronizados con los filtros dados. Nada que hacer.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<options=bold>ALCANCE</>');
        $this->line('  Créditos a corregir: <options=bold>' . number_format(count($candidatos)) . '</>');
        $this->line('  Modo: ' . ($apply
            ? '<fg=red;options=bold>APLICAR (escribe en la base)</>'
            : '<fg=green;options=bold>SIMULACIÓN (no escribe nada)</>'));
        if ($sleepMs > 0) {
            $this->line("  Pausa entre lotes: {$sleepMs} ms");
        }

        if ($apply && !$this->option('force')) {
            if (!$this->option('seller-id') && !$this->option('credit-id')) {
                $this->warn('  Sin --seller-id: se van a tocar TODAS las rutas a la vez.');
                $this->warn('  En producción conviene ir ruta por ruta, fuera del horario de cobro.');
            }
            if (!$this->confirm('¿Aplicar los cambios?', false)) {
                $this->info('Cancelado. No se modificó nada.');
                return self::SUCCESS;
            }
        }

        $sello = now()->format('Ymd_His');
        $csvPath = $this->option('csv')
            ?: storage_path('app/recalculate_' . ($apply ? 'APLICADO' : 'SIMULADO') . "_{$sello}.csv");
        $sqlPath = storage_path("app/recalculate_REVERSA_{$sello}.sql");

        $fp = fopen($csvPath, 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, [
            'modo', 'credito_id', 'vendedor_id', 'vendedor', 'cliente',
            'remaining_antes', 'remaining_despues', 'diferencia',
            'status_antes', 'status_despues',
            'deuda_cuotas', 'abono_sin_aplicar',
        ]);

        $fsql = null;
        if ($apply) {
            $fsql = fopen($sqlPath, 'w');
            fwrite($fsql, "-- Reversa de credits:recalculate-remaining ({$sello})\n");
            fwrite($fsql, "-- Devuelve remaining_amount y status al estado previo.\n");
            fwrite($fsql, "-- Ejecutar dentro de una transacción y verificar antes de commitear.\n\n");
            fwrite($fsql, "START TRANSACTION;\n");
        }

        $cambRemaining = 0;
        $cambStatus = 0;
        $porVendedor = [];
        $deltaTotal = 0.0;
        $muestra = [];

        $bar = $this->output->createProgressBar(count($candidatos));
        $bar->start();

        foreach (array_chunk($candidatos, $chunk) as $lote) {
            $ids = array_column($lote, 'id');
            $creditos = Credit::whereIn('id', $ids)->get()->keyBy('id');

            DB::beginTransaction();
            try {
                foreach ($lote as $meta) {
                    $credit = $creditos[$meta['id']] ?? null;
                    if (!$credit) {
                        $bar->advance();
                        continue;
                    }

                    $remAntes = round((float) $credit->remaining_amount, 2);
                    $stAntes = $credit->status;

                    // Se ejecuta el MÉTODO REAL, no una réplica. En simulación
                    // el rollback de más abajo deshace la escritura, así que lo
                    // que se reporta es exactamente lo que pasaría al aplicar.
                    $credit->recalculateRemainingAndStatus();

                    $remDespues = round((float) $credit->remaining_amount, 2);
                    $stDespues = $credit->status;

                    $difRem = round($remDespues - $remAntes, 2);
                    if (abs($difRem) > $threshold) {
                        $cambRemaining++;
                        $deltaTotal += $difRem;
                    }
                    if ($stDespues !== $stAntes) {
                        $cambStatus++;
                    }

                    $vendedor = $meta['vendedor'] ?: '(sin vendedor)';
                    if (!isset($porVendedor[$vendedor])) {
                        $porVendedor[$vendedor] = ['n' => 0, 'delta' => 0.0, 'seller_id' => $meta['seller_id']];
                    }
                    $porVendedor[$vendedor]['n']++;
                    $porVendedor[$vendedor]['delta'] += $difRem;

                    fputcsv($fp, [
                        $apply ? 'APLICADO' : 'SIMULADO',
                        $credit->id, $meta['seller_id'], $vendedor, $meta['cliente'],
                        $remAntes, $remDespues, $difRem,
                        $stAntes, $stDespues,
                        $meta['deuda_cuotas'], $meta['sin_aplicar'],
                    ]);

                    if ($fsql) {
                        fwrite($fsql, sprintf(
                            "UPDATE credits SET remaining_amount = %.2f, status = %s WHERE id = %d;\n",
                            $remAntes, DB::getPdo()->quote($stAntes), $credit->id
                        ));
                    }

                    if (count($muestra) < 15) {
                        $muestra[] = [
                            '#00' . $credit->id,
                            mb_substr($vendedor, 0, 16),
                            mb_substr((string) $meta['cliente'], 0, 20),
                            number_format($remAntes, 2),
                            number_format($remDespues, 2),
                            $stAntes === $stDespues ? $stAntes : "{$stAntes} → {$stDespues}",
                        ];
                    }

                    $bar->advance();
                }

                if ($apply) {
                    DB::commit();
                } else {
                    // Simulación: se deshace todo lo escrito en el lote.
                    DB::rollBack();
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                $bar->finish();
                fclose($fp);
                if ($fsql) {
                    fclose($fsql);
                }
                $this->newLine(2);
                $this->error('Lote abortado y revertido: ' . $e->getMessage());
                $this->warn('Los lotes anteriores ya commiteados se pueden deshacer con: ' . $sqlPath);
                return self::FAILURE;
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $bar->finish();
        fclose($fp);
        if ($fsql) {
            fwrite($fsql, "\n-- Verificá el resultado antes de: COMMIT;\n");
            fclose($fsql);
        }
        $this->newLine(2);

        $this->line('<options=bold>RESULTADO' . ($apply ? '' : ' (SIMULADO)') . '</>');
        $this->line('  Créditos con remaining_amount corregido: ' . number_format($cambRemaining));
        $this->line('  Créditos con status corregido:           ' . number_format($cambStatus));
        $this->line('  Efecto neto sobre la cartera:            $' . number_format($deltaTotal, 2));

        $this->newLine();
        $this->line('<options=bold>POR VENDEDOR</>');
        uasort($porVendedor, fn ($a, $b) => $b['n'] <=> $a['n']);
        $filas = [];
        foreach (array_slice($porVendedor, 0, 15, true) as $nombre => $d) {
            $filas[] = [$d['seller_id'], $nombre, number_format($d['n']), '$' . number_format($d['delta'], 2)];
        }
        $this->table(['seller_id', 'Vendedor', 'Créditos', 'Efecto neto'], $filas);
        if (count($porVendedor) > 15) {
            $this->line('  ... y ' . (count($porVendedor) - 15) . ' vendedor(es) más en el CSV.');
        }

        $this->newLine();
        $this->line('<options=bold>MUESTRA</>');
        $this->table(['Crédito', 'Vendedor', 'Cliente', 'Antes', 'Después', 'Status'], $muestra);

        $this->newLine();
        $this->info("Auditoría: {$csvPath}");
        if ($apply) {
            $this->info("Reversa:   {$sqlPath}");
            $this->line('Para verificar: <comment>php artisan credits:validate-chain --only=C6</comment>');
        } else {
            $this->warn('SIMULACIÓN: no se modificó nada. Volvé a correr con --apply para aplicar.');
        }

        return self::SUCCESS;
    }

    /**
     * Encuentra los créditos desincronizados con consultas agregadas, sin
     * abrir un modelo por crédito. Sobre 129.735 créditos esto son unas pocas
     * consultas en vez de medio millón, y deja fuera a los sanos para que el
     * recálculo real solo toque lo que está mal.
     */
    private function buscarCandidatos(float $threshold, ?int $limit): array
    {
        $this->info('Buscando créditos desincronizados...');

        $q = DB::table('credits as c')
            ->leftJoin('clients as cl', 'cl.id', '=', 'c.client_id')
            ->leftJoin('sellers as s', 's.id', '=', 'c.seller_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin(DB::raw('(
                SELECT credit_id, SUM(quota_amount - paid_amount) as deuda
                FROM installments WHERE deleted_at IS NULL GROUP BY credit_id
            ) as i'), 'i.credit_id', '=', 'c.id')
            ->leftJoin(DB::raw('(
                SELECT credit_id, SUM(unapplied_amount) as sin_aplicar
                FROM payments WHERE deleted_at IS NULL GROUP BY credit_id
            ) as p'), 'p.credit_id', '=', 'c.id')
            ->whereNull('c.deleted_at')
            // Mismo cálculo que Credit::recalculateRemainingAndStatus().
            ->whereRaw(
                'ABS(c.remaining_amount - GREATEST(COALESCE(i.deuda,0) - COALESCE(p.sin_aplicar,0), 0)) > ?',
                [$threshold]
            );

        if ($sellerId = $this->option('seller-id')) {
            $q->where('c.seller_id', $sellerId);
        }
        if ($pais = $this->option('pais')) {
            $q->leftJoin('cities as ci', 'ci.id', '=', 's.city_id')
                ->leftJoin('countries as co', 'co.id', '=', 'ci.country_id')
                ->where('co.name', $pais);
        }
        if ($creditId = $this->option('credit-id')) {
            $q->where('c.id', $creditId);
        }
        // Créditos que quedan fuera de la corrida automática porque las dos
        // vías de cálculo no coinciden y alguien tiene que mirarlos primero
        // (ver credits:affected-report --solo-descuadrados).
        if ($excluir = $this->option('exclude')) {
            $ids = array_filter(array_map('intval', explode(',', $excluir)));
            if ($ids) {
                $q->whereNotIn('c.id', $ids);
            }
        }
        if ($status = $this->option('status')) {
            $q->where('c.status', $status);
        }
        if ($fromId = $this->option('from-id')) {
            $q->where('c.id', '>=', (int) $fromId);
        }

        // Cuarentena de outliers. El monto está brutalmente concentrado: 10
        // créditos concentran el 80% y 50 el 99%, todos importados en el mismo
        // lote, con remaining en 0 y sin un solo pago. Corregirlos hace
        // aparecer cientos de millones en los reportes de cartera de un saque,
        // así que conviene revisarlos a mano y aplicar aparte el resto, que son
        // ajustes chicos y sin discusión.
        $delta = 'ABS(c.remaining_amount - GREATEST(COALESCE(i.deuda,0) - COALESCE(p.sin_aplicar,0), 0))';
        if ($this->option('max-delta') !== null) {
            $q->whereRaw("{$delta} <= ?", [(float) $this->option('max-delta')]);
        }
        if ($this->option('min-delta') !== null) {
            $q->whereRaw("{$delta} > ?", [(float) $this->option('min-delta')]);
        }
        if ($limit) {
            $q->limit($limit);
        }

        return $q->orderBy('c.id')
            ->select(
                'c.id', 'c.seller_id', 'u.name as vendedor', 'cl.name as cliente',
                DB::raw('COALESCE(i.deuda, 0) as deuda_cuotas'),
                DB::raw('COALESCE(p.sin_aplicar, 0) as sin_aplicar')
            )
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'seller_id' => $r->seller_id,
                'vendedor' => $r->vendedor,
                'cliente' => $r->cliente,
                'deuda_cuotas' => round((float) $r->deuda_cuotas, 2),
                'sin_aplicar' => round((float) $r->sin_aplicar, 2),
            ])
            ->all();
    }
}
