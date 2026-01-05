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
        Schema::table('credits', function (Blueprint $table) {
            $table->boolean('has_been_modified')->default(false)->after('renewal_blocked');
            $table->integer('modification_count')->default(0)->after('has_been_modified');
            $table->timestamp('last_modified_at')->nullable()->after('modification_count');
            $table->unsignedBigInteger('last_modified_by')->nullable()->after('last_modified_at');
            
            $table->foreign('last_modified_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credits', function (Blueprint $table) {
            $table->dropForeign(['last_modified_by']);
            $table->dropColumn(['has_been_modified', 'modification_count', 'last_modified_at', 'last_modified_by']);
        });
    }
};
