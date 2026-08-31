<?php

namespace App\Console\Commands;

use App\Helpers\TimezoneHelper;
use App\Models\Collection\CollectionCashbox;
use App\Models\Collection\CollectionDailyRecord;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repara el país y la moneda de los registros diarios que no coinciden con los
 * de SU caja.
 *
 * El alta tomaba `country_code` y `currency` del payload del navegador (el país
 * y la moneda activos del módulo) en vez de los de la caja donde entra el
 * movimiento. Un movimiento cargado en una caja de Perú podía nacer marcado
 * como Colombia y desaparecer de todo lo que filtra por país —el corte del
 * módulo, los reportes— aunque la bitácora de la caja sí lo mostrara.
 *
 * El alta ya quedó arreglada (front + back, ver CollectionDailyRecordService::create);
 * este comando corrige lo que quedó grabado antes.
 *
 * CUIDADO con `business_date`: es la fecha contable CONGELADA en el alta, y se
 * calculó con la zona del país que tenía el registro. Si al corregir el país la
 * zona cambia de offset, la fecha contable correcta podría ser otra — y esa
 * fecha es la base de todos los cortes y del auto-cierre. Por eso esos casos NO
 * se tocan: se listan aparte para revisarlos a mano. (CO y PE son ambos UTC−5,
 * así que en la práctica casi nunca aparecen.)
 *
 * Idempotente: se puede reejecutar sin efecto.
 *
 * Uso:
 *   php artisan collection:fix-record-currency-country --dry-run
 *   php artisan collection:fix-record-currency-country
 *   php artisan collection:fix-record-currency-country --company=25
 *   php artisan collection:fix-record-currency-country --force   (sin preguntar)
 */
class CollectionFixRecordCurrencyCountry extends Command
{
    protected $signature = 'collection:fix-record-currency-country
        {--dry-run : Solo mostrar lo que cambiaría, sin escribir}
        {--company= : Limitar a una empresa}
        {--force : No pedir confirmación (para correr desatendido)}';

    protected $description = 'Alinea country_code y currency de los registros diarios con los de su caja';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $companyFilter = $this->option('company') !== null ? (int) $this->option('company') : null;

        $this->line('');
        $this->info('Base Collection: ' . DB::connection('collection_pgsql')->getDatabaseName());
        $this->line('Modo: ' . ($dry ? 'SIMULACIÓN (no escribe)' : 'APLICAR CAMBIOS'));
        $this->line('');

        // Cajas indexadas por id: son la fuente de verdad del país y la moneda.
        $cashboxQuery = CollectionCashbox::query();
        if ($companyFilter) $cashboxQuery->where('company_id', $companyFilter);
        $cashboxes = $cashboxQuery->get()->keyBy('id');

        if ($cashboxes->isEmpty()) {
            $this->warn('No hay cajas' . ($companyFilter ? " para la empresa {$companyFilter}" : '') . '; nada que hacer.');
            return self::SUCCESS;
        }

        // Solo movimientos con caja asignada: sin caja no hay de dónde derivar.
        $query = CollectionDailyRecord::query()
            ->whereNotNull('cashbox_id')
            ->whereIn('cashbox_id', $cashboxes->keys()->all());
        if ($companyFilter) $query->where('company_id', $companyFilter);

        $aCorregir = [];   // cambios seguros
        $aRevisar = [];    // cambiarían la fecha contable: se dejan quietos
        $sinDatos = 0;     // cajas sin país/moneda cargados: no hay verdad que copiar
        $revisados = 0;

        $query->orderBy('id')->chunk(500, function ($rows) use (
            $cashboxes, &$aCorregir, &$aRevisar, &$sinDatos, &$revisados
        ) {
            foreach ($rows as $rec) {
                $revisados++;
                $cb = $cashboxes->get((int) $rec->cashbox_id);
                if (!$cb) continue;

                $paisCaja = $cb->country_code ? strtoupper(trim($cb->country_code)) : null;
                $monedaCaja = $cb->currency ? strtoupper(trim($cb->currency)) : null;
                if (!$paisCaja && !$monedaCaja) { $sinDatos++; continue; }

                $paisRec = $rec->country_code ? strtoupper(trim($rec->country_code)) : null;
                $monedaRec = $rec->currency ? strtoupper(trim($rec->currency)) : null;

                $cambiaPais = $paisCaja && $paisRec !== $paisCaja;
                $cambiaMoneda = $monedaCaja && $monedaRec !== $monedaCaja;
                if (!$cambiaPais && !$cambiaMoneda) continue;

                // ¿La fecha contable seguiría siendo la misma con el país nuevo?
                $fechaActual = $rec->business_date
                    ? Carbon::parse($rec->business_date)->toDateString()
                    : null;
                $fechaNueva = $fechaActual;
                if ($cambiaPais && $rec->recorded_at) {
                    $tzNueva = TimezoneHelper::timezoneForCountryCode($paisCaja);
                    if ($tzNueva) {
                        $fechaNueva = Carbon::parse($rec->recorded_at, 'UTC')
                            ->setTimezone($tzNueva)->toDateString();
                    }
                }

                $item = [
                    'id' => (int) $rec->id,
                    'company_id' => (int) $rec->company_id,
                    'caja' => $cb->name,
                    'tipo' => $rec->type,
                    'monto' => (float) $rec->amount,
                    'pais' => $cambiaPais ? "{$paisRec} → {$paisCaja}" : ($paisRec ?: '—'),
                    'moneda' => $cambiaMoneda ? "{$monedaRec} → {$monedaCaja}" : ($monedaRec ?: '—'),
                    'fecha' => $fechaActual,
                    'fecha_nueva' => $fechaNueva,
                    'set' => array_filter([
                        'country_code' => $cambiaPais ? $paisCaja : null,
                        'currency' => $cambiaMoneda ? $monedaCaja : null,
                    ]),
                ];

                if ($fechaNueva !== $fechaActual) {
                    $aRevisar[] = $item;   // mueve el día contable: no se toca
                } else {
                    $aCorregir[] = $item;
                }
            }
        });

        $this->line("Registros con caja revisados: {$revisados}");
        if ($sinDatos) {
            $this->warn("Saltados por caja sin país ni moneda cargados: {$sinDatos}");
        }
        $this->line('');

        if (empty($aCorregir) && empty($aRevisar)) {
            $this->info('Todo alineado: ningún registro difiere de su caja. Nada que hacer.');
            return self::SUCCESS;
        }

        if (!empty($aCorregir)) {
            $this->info('A CORREGIR (' . count($aCorregir) . ') — la fecha contable no se mueve:');
            $this->table(
                ['id', 'empresa', 'caja', 'tipo', 'monto', 'país', 'moneda', 'fecha contable'],
                array_map(fn($r) => [
                    $r['id'], $r['company_id'], $r['caja'], $r['tipo'],
                    number_format($r['monto'], 2), $r['pais'], $r['moneda'], $r['fecha'],
                ], array_slice($aCorregir, 0, 50))
            );
            if (count($aCorregir) > 50) {
                $this->line('  … y ' . (count($aCorregir) - 50) . ' más (se corrigen todos).');
            }
            $this->line('');
        }

        if (!empty($aRevisar)) {
            $this->error('NO SE TOCAN (' . count($aRevisar) . ') — corregir el país movería la fecha contable.');
            $this->line('Esa fecha es la base de los cortes y del auto-cierre: revisalos a mano.');
            $this->table(
                ['id', 'empresa', 'caja', 'monto', 'país', 'fecha actual', 'fecha que quedaría'],
                array_map(fn($r) => [
                    $r['id'], $r['company_id'], $r['caja'], number_format($r['monto'], 2),
                    $r['pais'], $r['fecha'], $r['fecha_nueva'],
                ], array_slice($aRevisar, 0, 50))
            );
            $this->line('');
        }

        if (empty($aCorregir)) {
            $this->warn('No hay nada seguro que corregir.');
            return self::SUCCESS;
        }

        if ($dry) {
            $this->comment('Simulación: no se escribió nada. Volvé a correrlo sin --dry-run para aplicar.');
            return self::SUCCESS;
        }

        if (!$this->option('force')) {
            $db = DB::connection('collection_pgsql')->getDatabaseName();
            if (!$this->confirm("¿Aplicar " . count($aCorregir) . " correcciones sobre '{$db}'?", false)) {
                $this->warn('Cancelado. No se escribió nada.');
                return self::SUCCESS;
            }
        }

        $aplicados = 0;
        DB::connection('collection_pgsql')->transaction(function () use ($aCorregir, &$aplicados) {
            foreach ($aCorregir as $r) {
                // update() directo: no queremos tocar updated_at ni disparar
                // observers por una corrección de metadatos.
                $n = CollectionDailyRecord::where('id', $r['id'])->update($r['set']);
                $aplicados += $n;
            }
        });

        $this->line('');
        $this->info("Listo: {$aplicados} registros corregidos.");
        if (!empty($aRevisar)) {
            $this->warn('Quedan ' . count($aRevisar) . ' pendientes de revisión manual (mueven la fecha contable).');
        }

        return self::SUCCESS;
    }
}
