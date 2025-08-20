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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('ruc')->unique()->nullable();;
            $table->string('name');
            $table->string('phone')->default('+51')->nullable();;
            $table->string('email')->nullable();;
            $table->string('logo_path')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
