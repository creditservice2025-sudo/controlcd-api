<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metas de recaudo por EMPRESA y MONEDA (la escala de colores rojo / amarillo
 * / azul / verde del Resumen General de vendedores).
 *
 * Hasta ahora la escala estaba escrita a mano en el código (EscalaRecaudo y
 * ReportsPage.vue) con montos pensados para PEN, y se aplicaba igual a
 * cualquier moneda: un vendedor en ARS con 9 millones salía "verde" contra una
 * meta de 13.000.
 *
 *  - seller_collection_scales: una escala viva por empresa + moneda. Tres cortes
 *    (techo del rojo, del amarillo y del azul; arriba del azul es verde) y el
 *    tipo de meta: daily | weekly | monthly | range. `range` es la regla que
 *    había: el TOTAL del período consultado contra los cortes, sin prorrateo.
 *    Borrado suave + deleted_by: una escala eliminada queda para la auditoría.
 *  - seller_collection_scale_audits: historial (created / updated / deleted)
 *    con el diff {campo: {old, new}} y el usuario copiado.
 *
 * Siembra: cada empresa recibe una escala por cada moneda en la que tiene
 * vendedores, con la regla que estaba en el código (10.500 / 11.500 / 13.000,
 * tipo `range`). Así el día del despliegue ninguna fila cambia de color; cada
 * empresa ajusta después lo suyo.
 *
 * Idempotente: no recrea las tablas ni duplica la siembra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('seller_collection_scales')) {
            Schema::create('seller_collection_scales', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('currency', 3);
                $table->string('period_type', 20)->default('range');
                $table->decimal('cut_red', 15, 2);
                $table->decimal('cut_yellow', 15, 2);
                $table->decimal('cut_blue', 15, 2);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['company_id', 'currency']);
            });
        }

        if (!Schema::hasTable('seller_collection_scale_audits')) {
            Schema::create('seller_collection_scale_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('scale_id');
                $table->unsignedBigInteger('company_id');
                // Null si el cambio vino de la siembra o de un comando.
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name', 191)->nullable();
                $table->string('event', 32);
                $table->json('changes')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['scale_id', 'created_at']);
                $table->index('company_id');
            });
        }

        if (DB::table('seller_collection_scales')->exists()) {
            return;
        }

        // Monedas en las que opera cada empresa: las de los países de las rutas
        // de sus vendedores activos.
        $pares = DB::table('sellers as s')
            ->join('cities as ci', 'ci.id', '=', 's.city_id')
            ->join('countries as co', 'co.id', '=', 'ci.country_id')
            ->whereNull('s.deleted_at')
            ->whereNotNull('s.company_id')
            ->whereNotNull('co.currency')
            ->where('co.currency', '<>', '')
            ->select('s.company_id', 'co.currency')
            ->distinct()
            ->get();

        $now = now();
        foreach ($pares as $p) {
            $id = DB::table('seller_collection_scales')->insertGetId([
                'company_id' => $p->company_id,
                'currency' => strtoupper($p->currency),
                'period_type' => 'range',
                'cut_red' => 10500,
                'cut_yellow' => 11500,
                'cut_blue' => 13000,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('seller_collection_scale_audits')->insert([
                'scale_id' => $id,
                'company_id' => $p->company_id,
                'user_id' => null,
                'user_name' => 'Sistema (regla anterior)',
                'event' => 'created',
                'changes' => json_encode([
                    'currency' => ['old' => null, 'new' => strtoupper($p->currency)],
                    'period_type' => ['old' => null, 'new' => 'range'],
                    'cut_red' => ['old' => null, 'new' => 10500],
                    'cut_yellow' => ['old' => null, 'new' => 11500],
                    'cut_blue' => ['old' => null, 'new' => 13000],
                ]),
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_collection_scale_audits');
        Schema::dropIfExists('seller_collection_scales');
    }
};
