<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zona horaria por empresa. Necesaria para el corte de caja automático del
 * módulo Deuda & Abono (Collection), que debe cerrar a las 23:59:59 de la
 * hora LOCAL de cada empresa (Perú, Colombia, Bolivia, etc.). Guarda un
 * identificador IANA (ej. "America/Lima").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('is_collection_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });
    }
};
