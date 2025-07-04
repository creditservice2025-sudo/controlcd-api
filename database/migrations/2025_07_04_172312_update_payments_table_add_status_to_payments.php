<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE payments MODIFY status ENUM('Abonado', 'Pagado', 'No pagado', 'Devuelto', 'Aplicado', 'Consolidado') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE payments MODIFY status ENUM('Abonado', 'Pagado', 'No pagado', 'Devuelto', 'Aplicado') NOT NULL");
    }
};
