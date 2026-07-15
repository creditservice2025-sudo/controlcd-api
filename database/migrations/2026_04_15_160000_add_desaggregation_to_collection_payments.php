<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        // En PostgreSQL, al añadir columnas a una tabla padre (PARTITION BY),
        // estas se propagan automaticamente a todas sus particiones.
        // Guardas hasColumn: en entornos donde las columnas ya existían (creadas
        // por otra vía) la migración es idempotente y no falla.
        $schema = Schema::connection($this->connection);
        $schema->table('collection_payments', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('collection_payments', 'interest_paid')) {
                $table->decimal('interest_paid', 20, 2)->default(0)->after('amount_paid');
            }
            if (!$schema->hasColumn('collection_payments', 'principal_paid')) {
                $table->decimal('principal_paid', 20, 2)->default(0)->after('interest_paid');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->table('collection_payments', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('collection_payments', 'principal_paid')) {
                $table->dropColumn('principal_paid');
            }
            if ($schema->hasColumn('collection_payments', 'interest_paid')) {
                $table->dropColumn('interest_paid');
            }
        });
    }
};
