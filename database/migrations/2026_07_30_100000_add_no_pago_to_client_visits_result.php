<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega "No pagó" a los resultados posibles de una gestión.
 *
 * Cuando el cobrador toca el botón "No pagó" en la ruta está declarando algo
 * concreto: estuvo con el cliente y no hubo dinero. Eso es una gestión con
 * resultado propio, no un "Otro" genérico ni la ausencia de gestión.
 *
 * Sin este valor, esas visitas quedaban invisibles para el administrador: el
 * pago en cero se registraba, pero la fila mostraba "Sin visita".
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('client_visits')) {
            return;
        }

        DB::statement("
            ALTER TABLE client_visits
            MODIFY COLUMN result
            ENUM('Cobrado','Abono','No pagó','Promesa de pago','No estaba','Se negó','Casa cerrada','Ilocalizable','Otro')
            NOT NULL
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('client_visits')) {
            return;
        }

        DB::table('client_visits')->where('result', 'No pagó')->update(['result' => 'Otro']);

        DB::statement("
            ALTER TABLE client_visits
            MODIFY COLUMN result
            ENUM('Cobrado','Abono','Promesa de pago','No estaba','Se negó','Casa cerrada','Ilocalizable','Otro')
            NOT NULL
        ");
    }
};
