<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la columna `token_revoked` en `users`. LoginService la usa desde hace
 * tiempo (login la lee, logout la setea en 1) pero NUNCA existió una migración
 * que la creara → `logout()` lanzaba 500 ("Unknown column 'token_revoked'")
 * al hacer $user->save(), y como ese save corre ANTES de liberar el lock
 * supervisor→cobrador, el cobrador quedaba bloqueado. Boolean, default false.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'token_revoked')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('token_revoked')->default(false)->after('updated_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'token_revoked')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('token_revoked');
            });
        }
    }
};
