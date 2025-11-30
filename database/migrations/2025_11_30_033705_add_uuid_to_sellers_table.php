<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->uuid('uuid')->after('id')->nullable()->unique();
        });

        // Populate UUIDs for existing records
        $sellers = \Illuminate\Support\Facades\DB::table('sellers')->whereNull('uuid')->get();
        foreach ($sellers as $seller) {
            \Illuminate\Support\Facades\DB::table('sellers')
                ->where('id', $seller->id)
                ->update(['uuid' => \Illuminate\Support\Str::uuid()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
