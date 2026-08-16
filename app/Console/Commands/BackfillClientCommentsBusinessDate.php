<?php

namespace App\Console\Commands;

use App\Helpers\TimezoneHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Completa el día de negocio de los comentarios históricos.
 *
 * Los comentarios escritos antes del anclaje tienen `business_date` en null:
 * su jornada solo se podía deducir de `created_at`, que es el reloj GLOBAL de
 * la app (config('app.timezone')) y no el del vendedor. Mientras no se corra
 * este comando esas filas se siguen leyendo por la rama de compatibilidad
 * (ver TimezoneHelper::whereBusinessDayBetween), así que NO es urgente y no
 * necesita ventana de mantenimiento: el sistema funciona con o sin él.
 *
 * De dónde sale el día, por orden de confianza:
 *   1. La VISITA asociada (client_visits.client_comment_id). Ya viene anclada a
 *      la zona del vendedor y al momento en que ocurrió —no en que llegó al
 *      servidor—, así que es el dato bueno. Se copia tal cual.
 *   2. Sin visita: se convierte `created_at` desde la zona de la app a la del
 *      vendedor del cliente. Es una reconstrucción, no un dato original: si la
 *      zona de la app cambió alguna vez, estas filas quedan aproximadas. Por
 *      eso se informan aparte en el resumen.
 *
 * SEGURIDAD — solo escribe las tres columnas business_*, y SOLO donde
 * business_date está en null. No toca body, created_at, ni ninguna otra fila.
 * Usa query builder (no Eloquent) para no disparar observers ni mover
 * updated_at. Dry-run por defecto; --apply para escribir.
 */
class BackfillClientCommentsBusinessDate extends Command
{
    protected $signature = 'comments:backfill-business-date
                            {--apply : Escribe los cambios (por defecto es dry-run)}
                            {--chunk=500 : Filas por lote}
                            {--seller= : Acotar a un vendedor}';

    protected $description = 'Completa business_date/timestamp/timezone de los comentarios históricos. Dry-run por defecto.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $sellerFilter = $this->option('seller');

        $this->info('== Backfill del día de negocio de comentarios — ' . ($apply ? 'APLICAR' : 'DRY-RUN') . ' ==');
        $this->warn('Solo escribe business_date / business_timestamp / business_timezone donde están en null.');
        $this->newLine();

        $appTz = config('app.timezone') ?: 'UTC';
        $this->line("Zona de la aplicación (origen de created_at): <options=bold>{$appTz}</>");

        $base = DB::table('client_comments as cc')
            ->join('clients as cl', 'cl.id', '=', 'cc.client_id')
            ->whereNull('cc.business_date');

        if ($sellerFilter) {
            $base->where('cl.seller_id', $sellerFilter);
        }

        $total = (clone $base)->count();
        if ($total === 0) {
            $this->info('No hay comentarios sin anclar. Nada que hacer.');
            return self::SUCCESS;
        }

        $this->line("Comentarios sin anclar: <options=bold>{$total}</>");
        $this->newLine();

        // Zona por vendedor, resuelta una sola vez: la tabla se recorre por
        // lotes y volver a cargar seller→city→country por fila sería N+1 sobre
        // miles de comentarios.
        $timezoneBySeller = $this->sellerTimezones();

        $fromVisit = 0;
        $fromCreatedAt = 0;
        $sinSeller = 0;
        $ejemplos = [];

        (clone $base)
            ->orderBy('cc.id')
            ->select([
                'cc.id', 'cc.created_at', 'cl.seller_id',
                // La visita manda cuando existe: ya está anclada de origen.
                DB::raw('(select v.business_date from client_visits v
                          where v.client_comment_id = cc.id and v.deleted_at is null
                          order by v.id limit 1) as visit_business_date'),
                DB::raw('(select v.business_timestamp from client_visits v
                          where v.client_comment_id = cc.id and v.deleted_at is null
                          order by v.id limit 1) as visit_business_timestamp'),
                DB::raw('(select v.business_timezone from client_visits v
                          where v.client_comment_id = cc.id and v.deleted_at is null
                          order by v.id limit 1) as visit_business_timezone'),
            ])
            // chunkById y NO chunk: el filtro es `business_date is null` y el
            // lote justamente lo deja de cumplir al escribirlo. chunk() pagina
            // con offset, así que cada lote aplicado corre la ventana y se saltea
            // tantas filas como acaba de arreglar. Con chunkById la paginación va
            // por id, que no cambia.
            ->chunkById($chunkSize, function ($rows) use (
                $apply, $appTz, $timezoneBySeller,
                &$fromVisit, &$fromCreatedAt, &$sinSeller, &$ejemplos
            ) {
                foreach ($rows as $row) {
                    if ($row->visit_business_date) {
                        $stamp = [
                            'business_date' => $row->visit_business_date,
                            'business_timestamp' => $row->visit_business_timestamp,
                            'business_timezone' => $row->visit_business_timezone,
                        ];
                        $fromVisit++;
                        $origen = 'visita';
                    } else {
                        $tz = $timezoneBySeller[(int) $row->seller_id] ?? null;
                        if (!$tz) {
                            $tz = TimezoneHelper::COUNTRY_TIMEZONES['default'];
                            $sinSeller++;
                        }
                        // created_at está en la zona de la app: se lo lee como
                        // tal y se lo traslada a la del vendedor.
                        $local = Carbon::parse($row->created_at, $appTz)->setTimezone($tz);
                        $stamp = [
                            'business_date' => $local->toDateString(),
                            'business_timestamp' => $local->format('Y-m-d H:i:s'),
                            'business_timezone' => $tz,
                        ];
                        $fromCreatedAt++;
                        $origen = 'created_at';
                    }

                    if (count($ejemplos) < 8) {
                        $ejemplos[] = [
                            $row->id, $origen, $row->created_at,
                            $stamp['business_date'], $stamp['business_timestamp'], $stamp['business_timezone'],
                        ];
                    }

                    if ($apply) {
                        DB::table('client_comments')->where('id', $row->id)->update($stamp);
                    }
                }
            }, 'cc.id', 'id');

        $this->newLine();
        $this->table(
            ['id', 'origen', 'created_at (app)', 'business_date', 'business_timestamp', 'zona'],
            $ejemplos
        );

        $this->newLine();
        $this->info('Resumen:');
        $this->line("  Anclados desde su VISITA (dato original): {$fromVisit}");
        $this->line("  Reconstruidos desde created_at:           {$fromCreatedAt}");
        if ($sinSeller > 0) {
            $this->warn("  Sin vendedor resoluble (zona por defecto): {$sinSeller}");
        }

        $this->newLine();
        if ($apply) {
            $this->info('✓ Aplicado.');
        } else {
            $this->warn('DRY-RUN: no se escribió nada. Volvé a correr con --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Zona horaria de cada vendedor, en una sola consulta.
     *
     * @return array<int, string>
     */
    private function sellerTimezones(): array
    {
        return DB::table('sellers')
            ->leftJoin('cities', 'cities.id', '=', 'sellers.city_id')
            ->leftJoin('countries', 'countries.id', '=', 'cities.country_id')
            ->pluck('countries.name', 'sellers.id')
            ->map(fn ($countryName) => TimezoneHelper::COUNTRY_TIMEZONES[$countryName]
                ?? TimezoneHelper::COUNTRY_TIMEZONES['default'])
            ->all();
    }
}
