<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill de `created_by` en gastos históricos (creados antes de existir la
 * columna). No hay registro de quién los realizó, así que se asume el caso más
 * común y útil: lo registró el propio vendedor dueño del gasto (user_id).
 * Solo afecta filas con created_by NULL; las nuevas ya guardan el registrante.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('expenses')
            ->whereNull('created_by')
            ->whereNotNull('user_id')
            ->update(['created_by' => DB::raw('user_id')]);
    }

    public function down(): void
    {
        // No se revierte: no hay forma de distinguir el backfill de un valor real.
    }
};
