<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Helpers\TimezoneHelper;
use Carbon\Carbon;

/**
 * Congela el "día de negocio" de los créditos en columnas propias, replicando
 * el patrón ya existente en `payments` (business_timestamp / business_date /
 * business_timezone). Objetivo: que el día de un crédito NO dependa de la zona
 * horaria del navegador de quien lo crea ni de quien lo consulta, sino que
 * quede anclado a la zona del vendedor (país) en el momento del alta.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotente/reanudable: si una corrida previa quedó a medias (columnas
        // creadas, backfill parcial, índices faltantes), volver a ejecutar la
        // completa sin fallar. También más seguro para el deploy de producción.
        Schema::table('credits', function (Blueprint $table) {
            if (!Schema::hasColumn('credits', 'business_timestamp')) {
                $table->timestamp('business_timestamp')->nullable()->after('created_at');
            }
            if (!Schema::hasColumn('credits', 'business_date')) {
                $table->date('business_date')->nullable()->after('business_timestamp');
            }
            if (!Schema::hasColumn('credits', 'business_timezone')) {
                $table->string('business_timezone', 64)->nullable()->after('business_date');
            }
        });

        // ---- Backfill histórico ---------------------------------------------
        // created_at está en UTC (APP_TIMEZONE=UTC). Para créditos importados el
        // día de negocio es el día de carga (imported_at), coherente con el
        // filtro previo que hacía "created_at IN rango OR imported_at IN rango".
        // Convertimos a la zona del país del vendedor EN PHP para no depender de
        // CONVERT_TZ (que exige las tablas de zonas de MySQL cargadas).
        $defaultTz = config('app.timezone') ?: 'America/Lima';
        if ($defaultTz === 'UTC') {
            $defaultTz = 'America/Lima';
        }

        // Mapa seller_id => zona resuelta con la MISMA lógica que en runtime
        // (TimezoneHelper::getSellerTimezone usa el nombre del país). Así los
        // créditos históricos quedan anclados igual que los nuevos. Fallback a
        // la columna countries.timezone y luego al default.
        $countryMap = TimezoneHelper::COUNTRY_TIMEZONES;
        $sellerRows = DB::table('sellers')
            ->leftJoin('cities', 'cities.id', '=', 'sellers.city_id')
            ->leftJoin('countries', 'countries.id', '=', 'cities.country_id')
            ->select('sellers.id as seller_id', 'countries.name as country_name', 'countries.timezone as country_tz')
            ->get();

        $sellerTz = [];
        foreach ($sellerRows as $r) {
            $sellerTz[$r->seller_id] = $countryMap[$r->country_name] ?? $r->country_tz ?? $defaultTz;
        }

        DB::table('credits')
            ->whereNull('business_date')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($sellerTz, $defaultTz) {
                foreach ($rows as $c) {
                    $tz = $sellerTz[$c->seller_id] ?? $defaultTz;
                    if (empty($tz)) {
                        $tz = $defaultTz;
                    }

                    // Importados: anclar al día de carga; normales: al de creación.
                    $sourceUtc = $c->imported_at ?: $c->created_at;
                    if (empty($sourceUtc)) {
                        continue;
                    }

                    $local = Carbon::parse($sourceUtc, 'UTC')->setTimezone($tz);

                    DB::table('credits')->where('id', $c->id)->update([
                        'business_timezone' => $tz,
                        'business_date' => $local->toDateString(),
                        'business_timestamp' => $local->format('Y-m-d H:i:s'),
                    ]);
                }
            });

        $this->addIndexIfMissing('credits_business_date_index', ['business_date']);
        $this->addIndexIfMissing('credits_business_date_deleted_index', ['business_date', 'deleted_at']);
    }

    public function down(): void
    {
        Schema::table('credits', function (Blueprint $table) {
            $table->dropIndex('credits_business_date_index');
            $table->dropIndex('credits_business_date_deleted_index');
            $table->dropColumn(['business_timestamp', 'business_date', 'business_timezone']);
        });
    }

    private function addIndexIfMissing(string $indexName, array $columns): void
    {
        $exists = collect(DB::select('SHOW INDEX FROM `credits` WHERE Key_name = ?', [$indexName]))->isNotEmpty();
        if (!$exists) {
            Schema::table('credits', function (Blueprint $table) use ($columns, $indexName) {
                $table->index($columns, $indexName);
            });
        }
    }
};
