<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->decimal('paid_amount', 10, 2)->default(0)->after('quota_amount');
            
            $table->enum('status', ['Pendiente', 'Pagado', 'Atrasado', 'Parcial'])->change();
        });
    }
    
    public function down()
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
            $table->enum('status', ['Pendiente', 'Pagado', 'Atrasado'])->change();
        });
    }
};
