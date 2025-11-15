<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{

    public function up(): void
    {
        DB::statement("
            ALTER TABLE credits
            MODIFY COLUMN status
            ENUM('Pendiente','Nuevo','Vigente','Renovado','Inactivo','Irrecuperable','Liquidado')
            DEFAULT 'Vigente'
        ");

  
    }

    public function down(): void
    {
        DB::table('credits')
            ->where('status', 'Renovado')
            ->update(['status' => 'Vigente']);

        DB::statement("
            ALTER TABLE credits
            MODIFY COLUMN status
            ENUM('Pendiente','Nuevo','Vigente','Inactivo','Irrecuperable','Liquidado')
            DEFAULT 'Vigente'
        ");
    }
};