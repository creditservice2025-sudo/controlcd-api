<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('app_versions', function (Blueprint $table) {
            $table->id();
            $table->string('platform'); // android, ios
            $table->string('environment'); // production, staging, testing
            $table->string('min_version'); // e.g., 1.0.0
            $table->string('latest_version'); // e.g., 1.0.5
            $table->boolean('force_update')->default(false);
            $table->string('store_url')->nullable(); // URL to download APK
            $table->text('release_notes')->nullable();
            $table->timestamps();

            // Unique constraint to ensure one config per platform/env
            $table->unique(['platform', 'environment']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
