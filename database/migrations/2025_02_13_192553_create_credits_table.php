<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->foreign('client_id')->references('id')->on('clients');
            $table->unsignedBigInteger('guarantor_id');
            $table->foreign('guarantor_id')->references('id')->on('guarantors');
            $table->unsignedBigInteger('route_id')->nullable();
            $table->foreign('route_id')->references('id')->on('routes');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('credit_value', 10, 2);
            $table->integer('number_installments');
            $table->date('first_quota_date');
            $table->enum('payment_frequency', ['Diaria', 'Semanal', 'Quincenal', 'Mensual']);
            $table->enum('status', ['Pendiente', 'Cancelado', 'Finalizado', 'Renovado', 'Moroso']);
            $table->decimal('total_interest', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('remaining_amount', 10, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credits');
    }
};
