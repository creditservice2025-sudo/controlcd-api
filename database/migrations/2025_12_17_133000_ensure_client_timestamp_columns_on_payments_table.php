<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'client_created_at')) {
                $table->string('client_created_at', 40)->nullable()->after('updated_at');
            }

            if (!Schema::hasColumn('payments', 'client_timezone')) {
                $table->string('client_timezone', 64)->nullable()->after('client_created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'client_timezone')) {
                $table->dropColumn('client_timezone');
            }

            if (Schema::hasColumn('payments', 'client_created_at')) {
                $table->dropColumn('client_created_at');
            }
        });
    }
};
