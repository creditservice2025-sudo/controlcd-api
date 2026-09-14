<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ícono y color por caja (parametrizables desde la UI, igual que las
 * categorías de la bitácora). Nullable: si vienen vacíos, el frontend deriva
 * un ícono/color automático por el nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('collection_pgsql')->table('collection_cashboxes', function (Blueprint $table) {
            $table->string('icon', 60)->nullable()->after('name');
            $table->string('color', 30)->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::connection('collection_pgsql')->table('collection_cashboxes', function (Blueprint $table) {
            $table->dropColumn(['icon', 'color']);
        });
    }
};
