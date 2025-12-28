<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('session_logs', function (Blueprint $table) {
            $table->string('app_version')->nullable()->after('user_agent');
            $table->string('device_info')->nullable()->after('app_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('session_logs', function (Blueprint $table) {
            $table->dropColumn(['app_version', 'device_info']);
        });
    }
};
