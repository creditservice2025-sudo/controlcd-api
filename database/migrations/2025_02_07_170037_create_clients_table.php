<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name'); 
            $table->string('dni')->unique();
            $table->string('address'); 
            $table->json('geolocation'); 
            $table->string('phone'); 
            $table->string('email')->unique()->nullable();
            $table->softDeletes();
            $table->timestamps();
            
        });
    }

    
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
