<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origen de cada movimiento: desde qué app se registró.
 *
 *  - client_platform: 'ios' | 'android' | 'web'.
 *  - client_app_version: versión de la app móvil que lo registró (la web no
 *    manda versión).
 *
 * Lo llena el trait RecordsClientOrigin al crear el registro, a partir de los
 * headers X-Client-Platform / X-App-Version / X-Client-Type. Los registros
 * anteriores quedan en NULL ("sin dato"): no se puede saber de dónde vinieron.
 *
 * Solo agrega columnas NULL al final de la tabla (en MySQL 8 es un ALTER
 * instantáneo, no reescribe la tabla). No toca datos existentes. Idempotente.
 */
return new class extends Migration
{
    private const TABLES = ['payments', 'expenses', 'incomes', 'credits'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'client_platform')) {
                    $table->string('client_platform', 10)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'client_app_version')) {
                    $table->string('client_app_version', 20)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['client_platform', 'client_app_version'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
