<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Cambiar índice único de DNI para permitir mismo DNI en diferentes sellers.
     *
     * Esto permite:
     * - Diferentes vendedores pueden registrar clientes con el mismo DNI
     * - Un mismo vendedor NO puede repetir el mismo DNI (validado por código)
     * - Si un cliente es eliminado (soft delete), se puede re-registrar el DNI
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Eliminar índice único actual en dni
            // El índice se llama 'clients_dni_unique' por convención Laravel
            $table->dropUnique(['dni']);
        });

        // Nota: NO creamos índice compuesto porque queremos permitir
        // soft deletes (re-registrar DNI eliminados).
        // La validación se hará por código en ClientService.php
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Restaurar índice único simple
            $table->unique('dni');
        });
    }
};
