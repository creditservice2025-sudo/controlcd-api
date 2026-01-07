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
        Schema::table('images', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_id')->nullable()->after('client_id');
            $table->foreign('credit_id')->references('id')->on('credits')->onDelete('cascade');
        });

        // Migrate existing data from description (e.g. "Crédito ID: 2139")
        $images = \DB::table('images')->whereNull('credit_id')->where('description', 'like', 'Crédito ID: %')->get();
        foreach ($images as $image) {
            if (preg_match('/Crédito ID: (\d+)/', $image->description, $matches)) {
                $creditId = $matches[1];
                \DB::table('images')->where('id', $image->id)->update(['credit_id' => $creditId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropForeign(['credit_id']);
            $table->dropColumn('credit_id');
        });
    }
};
