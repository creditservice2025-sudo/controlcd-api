<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Día de negocio del COMENTARIO, anclado a la zona del vendedor.
 *
 * Hasta acá el comentario solo tenía `created_at`, que lo escribe el timestamp
 * por defecto de Eloquent: o sea el reloj GLOBAL de la aplicación
 * (config('app.timezone'), America/Lima en producción), no el del vendedor que
 * lo escribió. Para saber "de qué día" era un comentario había que traducir esa
 * hora, y el resultado solo coincidía con el día real del vendedor porque los
 * tres países en operación —Perú, Colombia y Ecuador— son UTC-5 sin horario de
 * verano. Es una coincidencia, no un anclaje: el primer país con otro offset
 * (México, Chile, Argentina, Bolivia, Venezuela) empezaría a fechar mal los
 * comentarios de la primera y la última hora de la jornada.
 *
 * Se replica el mismo trío que ya usan `payments`, `credits` y `client_visits`:
 *   business_timestamp  hora LOCAL del vendedor, guardada cruda (se muestra sin
 *                       convertir; ver formatBusinessDateTime en el frontend)
 *   business_date       día calendario local: la base estable para filtrar
 *   business_timezone   la zona con la que se congeló, para poder auditarlo
 *
 * NULLABLE a propósito: las filas históricas se quedan en null y las consultas
 * caen al rango sobre `created_at` para ellas (mismo patrón que payments). Así
 * la migración no exige ventana de mantenimiento ni backfill previo; el
 * comando `comments:backfill-business-date` los completa después, sin apuro.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('client_comments')) {
            return;
        }

        Schema::table('client_comments', function (Blueprint $table) {
            if (!Schema::hasColumn('client_comments', 'business_date')) {
                $table->date('business_date')->nullable()->after('body');
            }
            if (!Schema::hasColumn('client_comments', 'business_timestamp')) {
                $table->dateTime('business_timestamp')->nullable()->after('business_date');
            }
            if (!Schema::hasColumn('client_comments', 'business_timezone')) {
                $table->string('business_timezone', 64)->nullable()->after('business_timestamp');
            }
        });

        // El filtro de las pantallas es siempre "comentarios de este cliente en
        // este día", así que el índice va en ese orden.
        $indexes = collect(Schema::getIndexes('client_comments'))
            ->pluck('name')
            ->map(fn ($n) => strtolower((string) $n))
            ->all();

        if (!in_array('client_comments_client_id_business_date_index', $indexes, true)) {
            Schema::table('client_comments', function (Blueprint $table) {
                $table->index(['client_id', 'business_date'], 'client_comments_client_id_business_date_index');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('client_comments')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('client_comments'))
            ->pluck('name')
            ->map(fn ($n) => strtolower((string) $n))
            ->all();

        if (in_array('client_comments_client_id_business_date_index', $indexes, true)) {
            Schema::table('client_comments', function (Blueprint $table) {
                $table->dropIndex('client_comments_client_id_business_date_index');
            });
        }

        Schema::table('client_comments', function (Blueprint $table) {
            $table->dropColumn(['business_date', 'business_timestamp', 'business_timezone']);
        });
    }
};
