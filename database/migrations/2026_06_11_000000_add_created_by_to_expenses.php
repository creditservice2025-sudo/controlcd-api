<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `created_by` a `expenses`: registra QUÉ usuario realizó/registró el
 * gasto (auditoría visible en la tabla de Gastos). Es distinto de `user_id`,
 * que es el vendedor DUEÑO del gasto: un admin/superadmin puede registrar un
 * gasto por cuenta de un vendedor, y aquí queda quién lo hizo realmente.
 *
 * Nullable y sin FK estricta para no romper datos existentes ni el soft-delete.
 * Los gastos creados antes de esta migración quedarán con created_by = null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('expenses', 'created_by')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by')->nullable()->after('user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('expenses', 'created_by')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropColumn('created_by');
            });
        }
    }
};
