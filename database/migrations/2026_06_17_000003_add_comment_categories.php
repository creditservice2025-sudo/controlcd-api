<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorías para los comentarios del cliente.
 *
 * Set independiente del de Gastos (`categories`) para no mezclar etiquetas
 * de distintos módulos. El comentario puede tener una categoría (opcional) y
 * se pueden crear nuevas desde el mismo diálogo.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('comment_categories')) {
            Schema::create('comment_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->unsignedBigInteger('user_id')->nullable(); // creador
                $table->timestamps();
                $table->softDeletes();

                $table->unique('name');
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (
            Schema::hasTable('client_comments') &&
            !Schema::hasColumn('client_comments', 'comment_category_id')
        ) {
            Schema::table('client_comments', function (Blueprint $table) {
                $table->unsignedBigInteger('comment_category_id')->nullable()->after('user_id');
                $table->index('comment_category_id');
                $table->foreign('comment_category_id')
                    ->references('id')->on('comment_categories')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('client_comments') &&
            Schema::hasColumn('client_comments', 'comment_category_id')
        ) {
            Schema::table('client_comments', function (Blueprint $table) {
                $table->dropForeign(['comment_category_id']);
                $table->dropIndex(['comment_category_id']);
                $table->dropColumn('comment_category_id');
            });
        }

        Schema::dropIfExists('comment_categories');
    }
};
