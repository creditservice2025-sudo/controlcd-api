<?php

namespace App\Console\Commands;

use App\Helpers\TimezoneHelper;
use App\Models\Liquidation;
use App\Services\LiquidationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repara los movimientos de caja que genera el ajuste de un crédito.
 *
 * Cuando se modifica el capital o la póliza de un crédito que NO se creó hoy,
 * CreditService::updateCreditFrequency genera el ingreso (devolución de
 * capital) o el gasto (entrega de capital) correspondiente. Hasta el arreglo
 * de este mismo commit los creaba SIN business_date, y todos los totales de la
 * liquidación filtran esa columna por igualdad
 * (LiquidationService::calculateLiquidationMetrics y getDailyTotals). Con la
 * columna en null el movimiento no entraba en ningún total: aparecía en el
 * listado "Ingresos del día" —que sí tiene rama de compatibilidad por
 * created_at— pero la caja lo ignoraba.
 *
 * Caso testigo: crédito #144878, capital 400 -> 200 el 2026-09-12. Quedaron
 * un ingreso de $200 y un gasto de $6 invisibles para la caja del vendedor 61.
 *
 * De dónde sale el día: `created_at` leído en la zona de la APLICACIÓN
 * (config('app.timezone'), que es como Laravel lo escribió) y trasladado a la
 * zona del VENDEDOR dueño del movimiento (seller -> city -> country). Es una
 * reconstrucción, no un dato original: el instante es exacto, pero si la zona
 * de la app cambió alguna vez estas filas quedan aproximadas.
 *
 * SEGURIDAD
 *  - Solo escribe business_date / business_timestamp / business_timezone, y
 *    SOLO donde business_date está en null. No toca value, description,
 *    created_at ni ninguna otra columna.
 *  - Usa query builder (no Eloquent) para no disparar observers ni mover
 *    updated_at.
 *  - Dry-run por defecto; --apply para escribir.
 *  - NO recalcula días APROBADOS. Una caja firmada está congelada a propósito
 *    (ver el comentario de LiquidationService::recalculateLiquidation): correr
 *    el saldo de un día que el cobrador ya firmó es exactamente el mecanismo
 *    por el que "se descuadran las cajas". Esos días se listan al final para
 *    que alguien decida reabrirlos a mano, que queda auditado.
 */
class BackfillCreditAdjustmentBusinessDate extends Command
{
    protected $signature = 'credits:backfill-adjustment-business-date
                            {--apply : Escribe los cambios (por defecto es dry-run)}
                            {--credit=* : Acotar a uno o más créditos (--credit=144878)}
                            {--seller= : Acotar a un vendedor}
                            {--from= : Solo movimientos con created_at >= esta fecha}
                            {--to= : Solo movimientos con created_at <= esta fecha}
                            {--chunk=500 : Filas por lote}
                            {--sin-recalcular : Estampa la fecha pero no recalcula liquidaciones}
                            {--forzar-dias-cerrados : Recalcula TAMBIÉN días aprobados (corre saldos firmados)}';

    protected $description = 'Completa business_date de los ingresos/gastos generados por ajustes de crédito y recalcula la caja. Dry-run por defecto.';

    /** Prefijos exactos que produce CreditService al ajustar un crédito. */
    private const PREFIJOS = [
        'AJUSTE CAPITAL CRÉDITO #',
        'AJUSTE SEGURO CRÉDITO #',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $recalcular = !$this->option('sin-recalcular');
        $forzarCerrados = (bool) $this->option('forzar-dias-cerrados');

        $creditFilter = array_values(array_filter(array_map('intval', (array) $this->option('credit'))));
        $sellerFilter = $this->option('seller');
        $from = $this->option('from');
        $to = $this->option('to');

        $this->info('== Backfill del día de negocio de ajustes de crédito — ' . ($apply ? 'APLICAR' : 'DRY-RUN') . ' ==');
        $this->warn('Solo escribe business_date / business_timestamp / business_timezone donde están en null.');
        $this->newLine();

        $appTz = config('app.timezone') ?: 'UTC';
        $this->line("Zona de la aplicación (origen de created_at): <options=bold>{$appTz}</>");

        // Zona y vendedor de cada usuario, resueltos de una sola vez: el bucle
        // recorre miles de filas y volver a cargar seller->city->country por
        // fila sería N+1.
        [$sellerByUser, $timezoneByUser] = $this->sellerMapByUser();

        $pendientes = [];   // filas a escribir
        $sinSeller = [];    // movimientos cuyo user_id no resuelve a un vendedor
        $afectadas = [];    // seller_id => fecha mínima tocada

        foreach (['incomes' => 'Ingreso', 'expenses' => 'Gasto'] as $tabla => $etiqueta) {
            $base = DB::table($tabla)
                ->whereNull('business_date')
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    foreach (self::PREFIJOS as $prefijo) {
                        $q->orWhere('description', 'like', $prefijo . '%');
                    }
                });

            if ($sellerFilter) {
                $userIds = array_keys($sellerByUser, (int) $sellerFilter, true);
                $base->whereIn('user_id', $userIds ?: [0]);
            }
            if ($from) {
                $base->where('created_at', '>=', Carbon::parse($from, $appTz)->startOfDay());
            }
            if ($to) {
                $base->where('created_at', '<=', Carbon::parse($to, $appTz)->endOfDay());
            }
            if ($creditFilter) {
                // Acota la consulta; el id exacto se valida después con regex,
                // porque LIKE '%#1448%' también pegaría con #144878.
                $base->where(function ($q) use ($creditFilter) {
                    foreach ($creditFilter as $cid) {
                        $q->orWhere('description', 'like', '%#' . $cid . '%');
                    }
                });
            }

            $base->select(['id', 'value', 'description', 'user_id', 'created_at'])
                ->orderBy('id')
                ->chunkById($chunkSize, function ($rows) use (
                    $tabla, $etiqueta, $appTz, $creditFilter,
                    $sellerByUser, $timezoneByUser,
                    &$pendientes, &$sinSeller
                ) {
                    foreach ($rows as $row) {
                        $creditId = $this->creditIdDesde($row->description);

                        // Descarta los falsos positivos del LIKE (#1448 vs #144878).
                        if ($creditFilter && !in_array($creditId, $creditFilter, true)) {
                            continue;
                        }

                        $userId = (int) $row->user_id;
                        $sellerId = $sellerByUser[$userId] ?? null;
                        $tz = $timezoneByUser[$userId] ?? null;

                        if (!$sellerId || !$tz) {
                            // Sin vendedor no hay zona ni caja que recalcular:
                            // se informa y se deja intacto, no se adivina.
                            $sinSeller[] = [$tabla, $row->id, $creditId ?? '?', $userId];
                            continue;
                        }

                        $local = Carbon::parse($row->created_at, $appTz)->setTimezone($tz);

                        $pendientes[] = [
                            'tabla' => $tabla,
                            'etiqueta' => $etiqueta,
                            'id' => (int) $row->id,
                            'credit_id' => $creditId,
                            'seller_id' => $sellerId,
                            'value' => (float) $row->value,
                            'created_at' => (string) $row->created_at,
                            'stamp' => [
                                'business_date' => $local->toDateString(),
                                'business_timestamp' => $local->format('Y-m-d H:i:s'),
                                'business_timezone' => $tz,
                            ],
                        ];
                    }
                });
        }

        if (empty($pendientes) && empty($sinSeller)) {
            $this->info('No hay movimientos de ajuste sin anclar. Nada que hacer.');
            return self::SUCCESS;
        }

        // Estado de la caja de cada día tocado: un día aprobado no se recalcula.
        $estadoDia = $this->estadoDeLosDias($pendientes);

        $filas = [];
        foreach ($pendientes as $p) {
            $clave = $p['seller_id'] . '|' . $p['stamp']['business_date'];

            $filas[] = [
                $p['etiqueta'],
                $p['id'],
                $p['credit_id'] ?? '?',
                number_format($p['value'], 2),
                $p['created_at'],
                $p['stamp']['business_date'],
                $p['stamp']['business_timezone'],
                $estadoDia[$clave] ?? 'sin liquidación',
            ];

            if (!isset($afectadas[$p['seller_id']]) || $p['stamp']['business_date'] < $afectadas[$p['seller_id']]) {
                $afectadas[$p['seller_id']] = $p['stamp']['business_date'];
            }
        }

        $this->newLine();
        $this->table(
            ['tipo', 'id', 'crédito', 'valor', 'created_at (app)', 'business_date', 'zona', 'caja del día'],
            array_slice($filas, 0, 40)
        );
        if (count($filas) > 40) {
            $this->line('  ... y ' . (count($filas) - 40) . ' movimientos más.');
        }

        if ($sinSeller) {
            $this->newLine();
            $this->warn('Movimientos cuyo user_id NO resuelve a un vendedor (se dejan intactos):');
            $this->table(['tabla', 'id', 'crédito', 'user_id'], $sinSeller);
        }

        if (!$apply) {
            $this->newLine();
            $this->info('Resumen: ' . count($pendientes) . ' movimientos se anclarían, ' . count($afectadas) . ' vendedor(es) afectado(s).');
            $this->warn('DRY-RUN: no se escribió nada. Volvé a correr con --apply.');
            return self::SUCCESS;
        }

        // ── Escritura ──────────────────────────────────────────────────────
        $escritos = 0;
        foreach ($pendientes as $p) {
            // El whereNull se repite acá a propósito: entre el relevamiento y
            // la escritura la fila pudo anclarse por otra vía.
            $escritos += DB::table($p['tabla'])
                ->where('id', $p['id'])
                ->whereNull('business_date')
                ->update($p['stamp']);
        }

        $this->newLine();
        $this->info("✓ Anclados {$escritos} movimientos.");

        if (!$recalcular) {
            $this->warn('--sin-recalcular: las liquidaciones NO se tocaron. La caja sigue mostrando los totales viejos.');
            return self::SUCCESS;
        }

        $this->recalcular($afectadas, $forzarCerrados);

        return self::SUCCESS;
    }

    /**
     * Recalcula la caja de cada vendedor desde su día más antiguo tocado y
     * reporta cuánto se movió `real_to_deliver`. Los días aprobados se saltean
     * salvo --forzar-dias-cerrados.
     */
    private function recalcular(array $afectadas, bool $forzarCerrados): void
    {
        $service = app(LiquidationService::class);
        $reporte = [];
        $sellados = [];

        foreach ($afectadas as $sellerId => $desde) {
            $liquidaciones = Liquidation::where('seller_id', $sellerId)
                ->where('date', '>=', $desde)
                ->orderBy('date')
                ->get();

            $antes = $liquidaciones->mapWithKeys(fn ($l) => [$l->id => (float) $l->real_to_deliver]);

            if (!$forzarCerrados) {
                foreach ($liquidaciones as $l) {
                    if ($l->isSealed()) {
                        $sellados[] = [
                            $sellerId,
                            $l->date->format('Y-m-d'),
                            $l->id,
                            number_format((float) $l->real_to_deliver, 2),
                        ];
                    }
                }
            }

            $correr = function () use ($service, $sellerId, $desde, $forzarCerrados) {
                $service->recalculateLiquidation($sellerId, $desde, null, $forzarCerrados);
                $service->recalculateNextLiquidations($sellerId, $desde);
            };

            $forzarCerrados
                ? Liquidation::withoutIntegrityGuards($correr)
                : $correr();

            foreach (Liquidation::whereIn('id', $antes->keys())->orderBy('date')->get() as $l) {
                $delta = (float) $l->real_to_deliver - $antes[$l->id];
                if (abs($delta) > 0.001) {
                    $reporte[] = [
                        $sellerId,
                        $l->date->format('Y-m-d'),
                        number_format($antes[$l->id], 2),
                        number_format((float) $l->real_to_deliver, 2),
                        ($delta > 0 ? '+' : '') . number_format($delta, 2),
                    ];
                }
            }
        }

        $this->newLine();
        if ($reporte) {
            $this->info('Cajas recalculadas (solo las que cambiaron):');
            $this->table(['vendedor', 'fecha', 'real_to_deliver antes', 'después', 'delta'], $reporte);
        } else {
            $this->line('Ninguna caja cambió de saldo.');
        }

        if ($sellados) {
            $this->newLine();
            $this->warn('Días APROBADOS que NO se recalcularon (caja firmada = caja congelada):');
            $this->table(['vendedor', 'fecha', 'liquidación', 'real_to_deliver'], $sellados);
            $this->line('Para corregirlos hay que reabrir el día (queda auditado) o volver a correr con --forzar-dias-cerrados.');
        }
    }

    /** Estado ('En curso' / 'approved' / ...) de cada caja seller|fecha tocada. */
    private function estadoDeLosDias(array $pendientes): array
    {
        $claves = [];
        foreach ($pendientes as $p) {
            $claves[$p['seller_id']][$p['stamp']['business_date']] = true;
        }

        $estado = [];
        foreach ($claves as $sellerId => $fechas) {
            Liquidation::where('seller_id', $sellerId)
                ->whereIn('date', array_keys($fechas))
                ->get(['id', 'seller_id', 'date', 'status'])
                ->each(function ($l) use (&$estado) {
                    $estado[$l->seller_id . '|' . $l->date->format('Y-m-d')] =
                        $l->isSealed() ? 'APROBADA (no se toca)' : $l->status;
                });
        }

        return $estado;
    }

    /** Id del crédito embebido en la descripción del ajuste. */
    private function creditIdDesde(?string $description): ?int
    {
        if ($description && preg_match('/CR[ÉE]DITO #(\d+)/u', $description, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * seller_id y zona horaria por user_id, en una sola consulta.
     *
     * @return array{0: array<int,int>, 1: array<int,string>}
     */
    private function sellerMapByUser(): array
    {
        $rows = DB::table('sellers')
            ->leftJoin('cities', 'cities.id', '=', 'sellers.city_id')
            ->leftJoin('countries', 'countries.id', '=', 'cities.country_id')
            ->whereNotNull('sellers.user_id')
            ->get(['sellers.id as seller_id', 'sellers.user_id', 'countries.name as country']);

        $sellerByUser = [];
        $timezoneByUser = [];

        foreach ($rows as $r) {
            $sellerByUser[(int) $r->user_id] = (int) $r->seller_id;
            $timezoneByUser[(int) $r->user_id] = TimezoneHelper::COUNTRY_TIMEZONES[$r->country]
                ?? TimezoneHelper::COUNTRY_TIMEZONES['default'];
        }

        return [$sellerByUser, $timezoneByUser];
    }
}
