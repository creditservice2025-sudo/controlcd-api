<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->uuid('uuid')->after('id')->nullable()->unique();
        });

        // Populate UUIDs for existing records
        $clients = \Illuminate\Support\Facades\DB::table('clients')->whereNull('uuid')->get();
        foreach ($clients as $client) {
            \Illuminate\Support\Facades\DB::table('clients')
                ->where('id', $client->id)
                ->update(['uuid' => \Illuminate\Support\Str::uuid()]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
