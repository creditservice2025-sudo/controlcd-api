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
        Schema::create('image_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('entity_type'); // 'Seller' o 'Client'
            $table->unsignedBigInteger('entity_id');
            $table->string('image_type'); // 'profile', 'credit_doc', etc.
            $table->string('new_image_path');
            $table->enum('status', ['pending', 'approved', 'rejected', 'applied'])->default('pending');
            $table->string('token', 6)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('reason')->nullable(); // Motivo opcional del vendedor o del admin
            $table->softDeletes();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_approval_requests');
    }
};
