<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traza de ediciones de crédito (ventana del mismo día).
 *
 * Un crédito mueve dinero al crearse (débito loan_issue en la wallet), así que
 * toda corrección posterior queda registrada aquí con el estado anterior y el
 * nuevo. Mismo patrón que collection_client_audits.
 *
 * Ejecutar con:
 *   php artisan migrate --database=collection_pgsql --path=database/migrations/collection
 */
return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        Schema::connection($this->connection)->create('collection_credit_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('credit_id')->index();
            $table->string('action', 30);
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->jsonb('changes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('collection_credit_audits');
    }
};
