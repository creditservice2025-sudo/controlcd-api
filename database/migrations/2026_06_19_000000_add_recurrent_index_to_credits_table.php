<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Índice compuesto para acelerar el listado de "Clientes recurrentes".
 *
 * Motivación (medido en prod con ~90k créditos / 32k clientes):
 *   - El sub-modo "Vigente + otro activado" hace
 *       GROUP BY client_id HAVING COUNT(*) >= 2
 *     que hoy recorre el índice COMPLETO de credits (~90k filas, EXPLAIN
 *     type=index) → ~340 ms, y corre 2 veces (badge + filtro).
 *   - Los whereHas generan EXISTS correlacionados por
 *       client_id = ? AND status IN (...) AND created_at BETWEEN ...
 *
 * El índice (client_id, status, created_at) cubre ambos patrones: convierte
 * el full-index-scan del GROUP BY en un recorrido acotado y resuelve el
 * EXISTS por rango de índice.
 *
 * ⚠️ Toca la tabla `credits` (core del módulo financing). Es un cambio
 * ADITIVO y de bajo riesgo (no altera lógica ni datos), pero por la regla
 * de no intervenir Control CD se aplica de forma COORDINADA, en ventana de
 * mantenimiento. En MySQL 8 el índice se crea online (ALGORITHM=INPLACE).
 */
return new class extends Migration {
    public function up(): void
    {
        // Idempotente: no recrear si ya existe.
        $exists = collect(DB::select("SHOW INDEX FROM credits"))
            ->contains(fn($i) => $i->Key_name === 'credits_client_status_created_idx');

        if (!$exists) {
            Schema::table('credits', function (Blueprint $table) {
                $table->index(
                    ['client_id', 'status', 'created_at'],
                    'credits_client_status_created_idx'
                );
            });
        }
    }

    public function down(): void
    {
        $exists = collect(DB::select("SHOW INDEX FROM credits"))
            ->contains(fn($i) => $i->Key_name === 'credits_client_status_created_idx');

        if ($exists) {
            Schema::table('credits', function (Blueprint $table) {
                $table->dropIndex('credits_client_status_created_idx');
            });
        }
    }
};
