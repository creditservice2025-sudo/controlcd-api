<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `updated_by` a `users`: registra QUÉ usuario realizó la última
 * actualización del miembro (auditoría visible en la tabla de Usuarios).
 * Nullable y sin FK estricta para no romper datos existentes ni el soft-delete;
 * guarda el id del usuario autenticado al crear/editar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'updated_by')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('parent_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'updated_by')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('updated_by');
            });
        }
    }
};
