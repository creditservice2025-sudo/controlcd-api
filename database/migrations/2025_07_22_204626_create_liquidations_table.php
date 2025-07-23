<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('liquidations', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('seller_id')
                ->nullable()
                ->constrained('sellers');
            $table->decimal('collection_target', 15, 2);
            $table->decimal('initial_cash', 15, 2);
            $table->decimal('base_delivered', 15, 2);
            $table->decimal('total_collected', 15, 2);
            $table->decimal('total_expenses', 15, 2);
            $table->decimal('new_credits', 15, 2);
            $table->decimal('real_to_deliver', 15, 2);
            $table->decimal('shortage', 15, 2);
            $table->decimal('surplus', 15, 2);
            $table->decimal('cash_delivered', 15, 2);
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('liquidations');
    }
};
