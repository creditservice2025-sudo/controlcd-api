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
        Schema::create('telegram_logs', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->text('message');
            $table->string('type')->default('otp');
            $table->string('status')->default('success');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_logs');
    }
};
