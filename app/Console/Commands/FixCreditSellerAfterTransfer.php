<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repara la huella del bug de transferencia de clientes: al transferir un
 * cliente solo se actualizaba `clients.seller_id`, dejando `credits.seller_id`
 * apuntando al vendedor anterior. Como la cartera, el recaudo y los contadores
 * del dashboard se atribuyen por `credits.seller_id`, esto descuadra la wallet
 * (el cliente queda con un vendedor y su plata con otro).
 *
 * Regla de reparación: un crédito siempre debe pertenecer al mismo vendedor que
 * su cliente. Este comando alinea `credits.seller_id` al `clients.seller_id`
 * actual en todos los créditos donde difieran.
 *
 * El fix de código en ClientController::transfer/transferMassive evita que esto
 * vuelva a ocurrir; este comando solo limpia los datos ya transferidos.
 *
 * Uso:
 *   php artisan clients:fix-credit-seller --dry-run        # solo reporta, no toca nada
 *   php artisan clients:fix-credit-seller --seller-id=23   # filtra por vendedor destino (cliente)
 *   php artisan clients:fix-credit-seller                  # aplica la corrección
 */
class FixCreditSellerAfterTransfer extends Command
{
    protected $signature = 'clients:fix-credit-seller
                            {--dry-run : No modifica nada, solo reporta lo que haría}
                            {--seller-id= : Limita a créditos cuyo cliente pertenece a este vendedor}';

    protected $description = 'Alinea credits.seller_id al seller_id de su cliente (repara transferencias antiguas)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sellerId = $this->option('seller-id');

        $mode = $dryRun ? 'DRY-RUN (no se modifica nada)' : 'APLICAR cambios en BD';
        $this->info("Modo: {$mode}");

        // Créditos desalineados: el seller del crédito no coincide con el de su cliente.
        $baseQuery = DB::table('credits as c')
            ->join('clients as cl', 'c.client_id', '=', 'cl.id')
            ->whereColumn('c.seller_id', '<>', 'cl.seller_id')
            ->whereNull('c.deleted_at');

        if ($sellerId) {
            $baseQuery->where('cl.seller_id', (int) $sellerId);
        }

        // Reporte: cuántos créditos se moverían y entre qué vendedores.
        $breakdown = (clone $baseQuery)
            ->select(
                'c.seller_id as seller_credito_actual',
                'cl.seller_id as seller_cliente',
                DB::raw('COUNT(*) as creditos')
            )
            ->groupBy('c.seller_id', 'cl.seller_id')
            ->orderBy('cl.seller_id')
            ->get();

        $total = (int) (clone $baseQuery)->count();

        if ($total === 0) {
            $this->info('No hay créditos desalineados. Nada que reparar.');
            return self::SUCCESS;
        }

        $this->table(
            ['Seller crédito (viejo)', 'Seller cliente (correcto)', 'Créditos'],
            $breakdown->map(fn ($r) => [
                $r->seller_credito_actual,
                $r->seller_cliente,
                $r->creditos,
            ])->all()
        );
        $this->warn("Total de créditos a reasignar: {$total}");

        if ($dryRun) {
            $this->info('DRY-RUN: no se aplicó ningún cambio.');
            return self::SUCCESS;
        }

        if (! $this->confirm("¿Reasignar {$total} crédito(s) a su vendedor correcto?", true)) {
            $this->info('Cancelado. No se modificó nada.');
            return self::SUCCESS;
        }

        // Aplicar: igualar credits.seller_id al de su cliente.
        $affected = DB::table('credits as c')
            ->join('clients as cl', 'c.client_id', '=', 'cl.id')
            ->whereColumn('c.seller_id', '<>', 'cl.seller_id')
            ->whereNull('c.deleted_at')
            ->when($sellerId, fn ($q) => $q->where('cl.seller_id', (int) $sellerId))
            ->update(['c.seller_id' => DB::raw('cl.seller_id')]);

        $this->info("✓ Listo. {$affected} crédito(s) reasignado(s) a su vendedor correcto.");
        \Log::info("clients:fix-credit-seller reasignó {$affected} créditos al seller de su cliente.");

        return self::SUCCESS;
    }
}
