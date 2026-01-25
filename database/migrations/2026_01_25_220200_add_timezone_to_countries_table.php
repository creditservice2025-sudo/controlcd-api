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
        Schema::table('countries', function (Blueprint $table) {
            $table->string('timezone')->nullable()->after('name')->default('America/Lima');
        });

        // Seed basic timezones
        DB::table('countries')->where('name', 'like', '%España%')->update(['timezone' => 'Europe/Madrid']);
        DB::table('countries')->where('name', 'like', '%Perú%')->update(['timezone' => 'America/Lima']);
        DB::table('countries')->where('name', 'like', '%Colombia%')->update(['timezone' => 'America/Bogota']);
        DB::table('countries')->where('name', 'like', '%Ecuador%')->update(['timezone' => 'America/Guayaquil']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
