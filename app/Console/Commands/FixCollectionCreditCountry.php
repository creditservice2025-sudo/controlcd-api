<?php

namespace App\Console\Commands;

use App\Models\Collection\CollectionCompanyConfig;
use App\Services\Collection\CollectionWalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reasigna los créditos de Deuda & Abono que quedaron en la caja equivocada.
 *
 * CAUSA: CollectionCreditController::store no declaraba `currency` ni
 * `country_code` en sus reglas, y `$request->validate()` devuelve SOLO las
 * claves validadas. El par viajaba desde el front y se perdía ahí; el servicio
 * caía a su default COP/CO. Resultado: TODO crédito quedó en Colombia sin
 * importar la wallet elegida, y el dashboard del país correcto mostraba cero.
 *
 * OJO — ESTO ES UNA INFERENCIA, NO UNA RESTAURACIÓN:
 * el valor elegido nunca llegó a la base, así que no se puede recuperar. Se usa
 * el país del CLIENTE, que es el mismo default que propone el modal al abrirse.
 * Un crédito de un cliente colombiano al que el operador le eligió a mano otra
 * wallet es INDETECTABLE: se queda como está y no aparece en el informe. Por eso
 * conviene revisar el listado del dry-run antes de aplicar.
 *
 *   php artisan collection:fix-credit-country                 (simula, no escribe)
 *   php artisan collection:fix-credit-country --company=4
 *   php artisan collection:fix-credit-country --credit=23
 *   php artisan collection:fix-credit-country --apply         (escribe)
 */
class FixCollectionCreditCountry extends Command
{
    protected $signature = 'collection:fix-credit-country
        {--company= : Limitar a una empresa}
        {--credit= : Limitar a un credito puntual}
        {--apply : Escribir los cambios (por defecto solo simula)}';

    protected $description = 'Reasigna a su caja los creditos de Deuda & Abono que quedaron marcados como COP/CO por el bug del validate().';

    private const CONNECTION = 'collection_pgsql';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $db = DB::connection(self::CONNECTION);

        $q = $db->table('collection_credits as cr')
            ->leftJoin('collection_clients as cl', 'cl.id', '=', 'cr.client_id')
            ->select(
                'cr.id', 'cr.company_id', 'cr.client_id', 'cr.amount', 'cr.status',
                'cr.country_code as cred_country', 'cr.currency as cred_currency',
                'cl.country_code as client_country'
            )
            ->orderBy('cr.company_id')->orderBy('cr.id');

        if ($this->option('company')) $q->where('cr.company_id', (int) $this->option('company'));
        if ($this->option('credit'))  $q->where('cr.id', (int) $this->option('credit'));

        $credits = $q->get();
        if ($credits->isEmpty()) {
            $this->info('No hay creditos que revisar.');
            return self::SUCCESS;
        }

        // Pares habilitados por empresa: country_code => currency.
        $pairsByCompany = [];
        foreach ($credits->pluck('company_id')->unique() as $companyId) {
            $config = CollectionCompanyConfig::where('company_id', $companyId)->first();
            $map = [];
            foreach (($config?->getCurrencyPairs() ?? []) as $p) {
                $map[strtoupper($p['country_code'] ?? '')] = strtoupper($p['currency'] ?? '');
            }
            $pairsByCompany[$companyId] = $map;
        }

        $plan = [];
        $skipped = [];

        foreach ($credits as $c) {
            $clientCountry = strtoupper((string) $c->client_country);
            $credCountry   = strtoupper((string) $c->cred_country);

            if ($clientCountry === '') {
                $skipped[] = [$c->id, $c->company_id, 'el cliente no tiene pais cargado'];
                continue;
            }
            if ($clientCountry === $credCountry) {
                continue; // ya coincide, nada que hacer
            }

            $enabled = $pairsByCompany[$c->company_id] ?? [];
            if (!isset($enabled[$clientCountry])) {
                $skipped[] = [$c->id, $c->company_id, "el pais del cliente ({$clientCountry}) no esta habilitado en Configuracion"];
                continue;
            }

            $plan[] = [
                'id' => $c->id,
                'company_id' => $c->company_id,
                'amount' => $c->amount,
                'status' => $c->status,
                'from' => $credCountry . '/' . strtoupper((string) $c->cred_currency),
                'to_country' => $clientCountry,
                'to_currency' => $enabled[$clientCountry],
            ];
        }

        // ── Informe ──
        if ($skipped) {
            $this->newLine();
            $this->warn('Creditos que NO se pueden decidir automaticamente (' . count($skipped) . '):');
            $this->table(['Credito', 'Empresa', 'Motivo'], $skipped);
        }

        if (!$plan) {
            $this->info('Ningun credito necesita reasignacion.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Creditos a reasignar (' . count($plan) . '):');
        $this->table(
            ['Credito', 'Empresa', 'Monto', 'Estado', 'Caja actual', 'Caja correcta'],
            array_map(fn ($p) => [
                $p['id'], $p['company_id'], $p['amount'], $p['status'],
                $p['from'], $p['to_country'] . '/' . $p['to_currency'],
            ], $plan)
        );

        if (!$apply) {
            $this->newLine();
            $this->comment('Simulacion: no se escribio nada. Volve a correr con --apply para aplicarlo.');
            $this->comment('Recorda que un credito de cliente colombiano mandado a mano a otra caja NO aparece aca: es indetectable.');
            return self::SUCCESS;
        }

        // ── Aplicacion ──
        $walletSvc = app(CollectionWalletService::class);
        $touchedWallets = [];
        $moved = 0;

        $db->transaction(function () use ($db, $plan, $walletSvc, &$touchedWallets, &$moved) {
            foreach ($plan as $p) {
                $target = $walletSvc->getOrCreateWallet($p['company_id'], $p['to_currency'], $p['to_country']);

                // Wallets de origen de los movimientos de este credito.
                $sourceIds = $db->table('collection_ledger')
                    ->where('reference_type', 'credit')->where('reference_id', $p['id'])
                    ->distinct()->pluck('wallet_id');

                $db->table('collection_credits')->where('id', $p['id'])->update([
                    'country_code' => $p['to_country'],
                    'currency'     => $p['to_currency'],
                    'updated_at'   => now(),
                ]);

                // Desembolso, adiciones de capital y cobros: todos referencian al
                // credito, asi que se mueven juntos a la caja correcta.
                $db->table('collection_ledger')
                    ->where('reference_type', 'credit')->where('reference_id', $p['id'])
                    ->update(['wallet_id' => $target->id]);

                foreach ($sourceIds as $wid) $touchedWallets[$wid] = true;
                $touchedWallets[$target->id] = true;
                $moved++;
            }

            // Recalcula saldo y corridas de cada caja tocada reproduciendo su
            // ledger en orden. El invariante (saldo == suma del ledger) se
            // verifica al final.
            foreach (array_keys($touchedWallets) as $walletId) {
                $running = 0.0;
                $rows = $db->table('collection_ledger')->where('wallet_id', $walletId)->orderBy('id')->get();
                foreach ($rows as $row) {
                    $before = $running;
                    $running = $row->type === 'credit'
                        ? $before + (float) $row->amount
                        : $before - (float) $row->amount;
                    $db->table('collection_ledger')->where('id', $row->id)->update([
                        'balance_before' => $before,
                        'balance_after'  => $running,
                    ]);
                }
                $db->table('collection_wallets')->where('id', $walletId)->update([
                    'balance'    => $running,
                    'updated_at' => now(),
                ]);
            }
        });

        $this->newLine();
        $this->info("Listo: {$moved} credito(s) reasignado(s), " . count($touchedWallets) . ' caja(s) recalculada(s).');

        // ── Verificacion del invariante ──
        $bad = 0;
        foreach (array_keys($touchedWallets) as $walletId) {
            $w = $db->table('collection_wallets')->find($walletId);
            $sum = (float) $db->table('collection_ledger')->where('wallet_id', $walletId)
                ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) AS s")
                ->value('s');
            $ok = abs($sum - (float) $w->balance) < 0.01;
            if (!$ok) $bad++;
            $this->line(sprintf(
                '  caja %-4s %s/%-4s saldo %14s  %s',
                $w->id, $w->country_code, $w->currency, number_format((float) $w->balance, 2, '.', ''), $ok ? 'ok' : 'NO CUADRA'
            ));
        }

        if ($bad > 0) {
            $this->error("{$bad} caja(s) quedaron descuadradas. Revisar antes de seguir.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
