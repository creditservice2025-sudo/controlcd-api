<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabla de Pagos/Abonos diseñada para máxima integridad y precisión.
     * Mantiene compatibilidad total con el modelo Payment.php.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credit_id')->index(); // Vínculo al crédito en Postgres
            $table->unsignedBigInteger('seller_id')->index(); // Vínculo a la ruta en Postgres
            $table->unsignedBigInteger('company_id')->index(); // Vínculo a la empresa en MySQL
            
            // Sello DevOps: Precisión decimal (15, 2) para cero errores financieros
            $table->decimal('amount', 15, 2); 
            $table->dateTime('payment_date');
            $table->string('receipt_number')->nullable()->index(); // Número de recibo físico
            $table->text('note')->nullable();
            
            $table->softDeletes();
            $table->timestamps();

            // Sello DevOps: Índice para velocidad de auditoría por fecha
            $table->index(['credit_id', 'payment_date']);
            $table->index(['seller_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
