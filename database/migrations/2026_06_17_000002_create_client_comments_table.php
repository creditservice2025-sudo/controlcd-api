<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comentarios / bitácora del cliente.
 *
 * Hilo de comentarios (no se sobrescriben): cada entrada guarda autor y fecha.
 * Lo pueden agregar y ver supervisor, admins y vendedor (regla acordada:
 * "todos agregan y ven"). El conteo (comments_count) alimenta el distintivo
 * en el listado de clientes.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('client_comments')) {
            return;
        }

        Schema::create('client_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('user_id'); // autor del comentario
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index('client_id');
            $table->index('user_id');

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_comments');
    }
};
