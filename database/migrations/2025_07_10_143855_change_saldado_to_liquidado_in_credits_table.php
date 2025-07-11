<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE credits 
            MODIFY COLUMN status 
            ENUM('Pendiente', 'Nuevo', 'Vigente', 'Inactivo', 'Irrecuperable', 'Saldado', 'Liquidado') 
            DEFAULT 'Vigente'
        ");
        
        DB::table('credits')
            ->where('status', 'Saldado')
            ->update(['status' => 'Liquidado']);
        
        DB::statement("
            ALTER TABLE credits 
            MODIFY COLUMN status 
            ENUM('Pendiente', 'Nuevo', 'Vigente', 'Inactivo', 'Irrecuperable', 'Liquidado') 
            DEFAULT 'Vigente'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("
            ALTER TABLE credits 
            MODIFY COLUMN status 
            ENUM('Pendiente', 'Nuevo', 'Vigente', 'Inactivo', 'Irrecuperable', 'Liquidado', 'Saldado') 
            DEFAULT 'Vigente'
        ");
        
        DB::table('credits')
            ->where('status', 'Liquidado')
            ->update(['status' => 'Saldado']);
        
        DB::statement("
            ALTER TABLE credits 
            MODIFY COLUMN status 
            ENUM('Pendiente', 'Nuevo', 'Vigente', 'Inactivo', 'Irrecuperable', 'Saldado') 
            DEFAULT 'Vigente'
        ");
    }
};