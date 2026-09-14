<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'is_financing_enabled')) {
                $table->boolean('is_financing_enabled')->default(true)->after('logo_path');
            }
            if (!Schema::hasColumn('companies', 'is_collection_enabled')) {
                $table->boolean('is_collection_enabled')->default(false)->after('is_financing_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'is_collection_enabled')) {
                $table->dropColumn('is_collection_enabled');
            }
            if (Schema::hasColumn('companies', 'is_financing_enabled')) {
                $table->dropColumn('is_financing_enabled');
            }
        });
    }
};
