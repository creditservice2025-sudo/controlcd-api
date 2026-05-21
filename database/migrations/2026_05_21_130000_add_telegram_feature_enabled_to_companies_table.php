<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master switch controlado por el SuperAdmin. Decide si la empresa
 * tiene acceso al módulo de notificaciones Telegram. Si está OFF, el
 * admin de la empresa NO ve la opción en su panel y sendToCompany no
 * envía nada (ni siquiera si telegram_enabled está activo).
 *
 * Modelo de 2 niveles:
 *   - SA controla feature_enabled (esta columna)
 *   - Admin de empresa controla telegram_enabled + chat + eventos
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('telegram_feature_enabled')->default(false)->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('telegram_feature_enabled');
        });
    }
};
