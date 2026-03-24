<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->date('start_date');
            $table->date('end_date');
            
            $table->decimal('total_collected', 15, 2)->default(0);
            $table->decimal('total_utility', 15, 2)->default(0); // Sum of utility for reference
            
            // Incomes
            $table->decimal('commission_utility', 15, 2)->default(0);
            $table->decimal('commission_collection', 15, 2)->default(0);
            $table->decimal('commission_credits', 15, 2)->default(0);
            $table->decimal('salary', 15, 2)->default(0);
            $table->decimal('allowance', 15, 2)->default(0); // Viáticos
            
            // Deductions
            $table->decimal('deductions_savings', 15, 2)->default(0);
            $table->decimal('deductions_arl', 15, 2)->default(0);
            
            // Totals
            $table->decimal('net_total', 15, 2)->default(0);
            
            // Status (pending, paid)
            $table->string('status')->default('pending');
            $table->string('receipt_path')->nullable(); // Path to the PDF
            
            $table->timestamps();

            $table->foreign('seller_id')->references('id')->on('sellers')->onDelete('cascade');
            
            // Ensure no duplicate payrolls for the same seller in the same date range
            $table->unique(['seller_id', 'start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('payrolls');
    }
};
