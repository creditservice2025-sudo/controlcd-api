<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('installment_id');
            $table->foreign('installment_id')->references('id')->on('installments');
            $table->date('payment_date');
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['Abonado', 'Pagado', 'No Pagado', 'Devuelto']);
            $table->string('payment_method');
            $table->string('payment_reference');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
