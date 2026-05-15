<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seller_config_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_config_id');
            $table->unsignedBigInteger('seller_id');
            // Quién hizo el cambio. Puede ser null si la modificación viene
            // de un seeder/comando sin contexto de auth.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 191)->nullable();
            $table->string('user_email', 191)->nullable();
            // 'created' al crear el config, 'updated' en cada save posterior.
            $table->string('event', 32);
            // Diff: solo los campos que efectivamente cambiaron, en formato
            // {campo: {old: X, new: Y}}. Para 'created', old es null.
            $table->json('changes');
            $table->timestamp('created_at')->useCurrent();

            $table->index('seller_config_id');
            $table->index('seller_id');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_config_audits');
    }
};
