<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credits', function (Blueprint $table) {
            // Fecha real de importación para carga masiva.
            // Para créditos normales es NULL.
            // Para créditos de carga masiva, guarda NOW() del momento del import.
            // Las queries de liquidación usan COALESCE(imported_at, created_at)
            // para totalizar correctamente créditos históricos.
            $table->timestamp('imported_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('credits', function (Blueprint $table) {
            $table->dropColumn('imported_at');
        });
    }
};
