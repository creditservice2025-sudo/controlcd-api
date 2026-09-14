<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    /**
     * Capital sobre el que se calculó el interés de cada cuota.
     *
     * En el crédito abierto el interés de una cuota se devenga sobre el capital
     * que había cuando esa cuota se generó, y NO se recalcula después. Sin este
     * dato, la cuota queda huérfana de su origen: se ve "interés 600" junto a un
     * capital que hoy es otro, y no hay forma de explicar de dónde salió.
     *
     * Se persiste al generar la cuota (nunca se reescribe) en lugar de deducirlo
     * como interes/tasa: esa división arrastra el redondeo y mostraría un capital
     * con céntimos que nunca existieron.
     */
    public function up(): void
    {
        // En PostgreSQL, al añadir columnas a una tabla padre (PARTITION BY),
        // estas se propagan automáticamente a todas sus particiones.
        $schema = Schema::connection($this->connection);
        $schema->table('collection_installments', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('collection_installments', 'principal_base')) {
                $table->decimal('principal_base', 20, 2)->nullable()->after('interest_amount');
            }
        });

        $this->backfillConsistentRows();
    }

    /**
     * Backfill conservador de las cuotas ya existentes.
     *
     * Solo se rellena cuando el capital reconstruido reproduce EXACTAMENTE el
     * interés guardado (base × tasa redondeado == interest_amount). Si no cuadra
     * —tasa 0, cuotas legacy con capital+interés, montos tocados a mano— la
     * columna queda NULL y la UI lo trata como dato desconocido en vez de
     * inventar una cifra.
     */
    private function backfillConsistentRows(): void
    {
        DB::connection($this->connection)->statement("
            UPDATE collection_installments AS i
               SET principal_base = ROUND(i.interest_amount * 100 / c.interest_rate, 2)
              FROM collection_credits AS c
             WHERE c.id = i.credit_id
               AND c.company_id = i.company_id
               AND i.principal_base IS NULL
               AND c.interest_rate > 0
               AND i.interest_amount > 0
               AND ROUND(ROUND(i.interest_amount * 100 / c.interest_rate, 2) * c.interest_rate / 100, 2)
                   = ROUND(i.interest_amount, 2)
        ");
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->table('collection_installments', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('collection_installments', 'principal_base')) {
                $table->dropColumn('principal_base');
            }
        });
    }
};
