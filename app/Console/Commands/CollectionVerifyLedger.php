<?php

namespace App\Console\Commands;

use App\Models\Collection\CollectionLedger;
use App\Models\Collection\CollectionWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Verifica la integridad del ledger de Collection y reconcilia el saldo de cada
 * wallet contra él (el ledger append-only es la FUENTE DE VERDAD del saldo).
 *
 * Por cada wallet revisa:
 *   1) Cadena: cada asiento.balance_before == asiento_previo.balance_after.
 *   2) Saldo: wallet.balance == balance_after del último asiento.
 *
 * Con --fix corrige wallet.balance para igualar al ledger (NO reescribe el
 * ledger). Devuelve exit 1 si detecta drift (útil para monitoreo/CI).
 *
 * Uso:
 *   php artisan collection:verify-ledger
 *   php artisan collection:verify-ledger --company=4
 *   php artisan collection:verify-ledger --fix
 */
class CollectionVerifyLedger extends Command
{
    protected $signature = 'collection:verify-ledger
        {--company= : Limitar a una empresa}
        {--fix : Corregir wallet.balance para igualar al ledger}';

    protected $description = 'Verifica el ledger de Collection y reconcilia el saldo de los wallets';

    private const EPS = 0.01;

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $companyId = $this->option('company');

        $wallets = CollectionWallet::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->get();

        if ($wallets->isEmpty()) {
            $this->info('No hay wallets para verificar.');
            return self::SUCCESS;
        }

        $chainIssues = 0;
        $balanceDrift = 0;
        $fixed = 0;

        foreach ($wallets as $w) {
            $entries = CollectionLedger::where('wallet_id', $w->id)
                ->orderBy('id')
                ->get(['id', 'amount', 'balance_before', 'balance_after']);

            // 1) Integridad de la cadena.
            $prevAfter = null;
            foreach ($entries as $e) {
                if ($prevAfter !== null && abs((float) $e->balance_before - $prevAfter) > self::EPS) {
                    $chainIssues++;
                    $line = "  wallet #{$w->id} ({$w->currency}/{$w->country_code}): asiento #{$e->id} balance_before="
                        . number_format((float) $e->balance_before, 2) . " ≠ anterior balance_after=" . number_format($prevAfter, 2);
                    $this->warn($line);
                    Log::warning('[collection:verify-ledger] cadena rota' . $line);
                }
                $prevAfter = (float) $e->balance_after;
            }

            // 2) Saldo del wallet vs último balance_after del ledger.
            $ledgerBalance = $entries->isEmpty() ? 0.0 : (float) $entries->last()->balance_after;
            $walletBalance = (float) $w->balance;

            if (abs($ledgerBalance - $walletBalance) > self::EPS) {
                $balanceDrift++;
                $this->warn(
                    "  wallet #{$w->id} ({$w->currency}/{$w->country_code}): saldo wallet="
                    . number_format($walletBalance, 2) . " ≠ ledger=" . number_format($ledgerBalance, 2)
                    . ($fix ? ' → corregido' : '')
                );
                Log::warning("[collection:verify-ledger] drift saldo wallet #{$w->id}: wallet={$walletBalance} ledger={$ledgerBalance}");

                if ($fix) {
                    $w->balance = round($ledgerBalance, 2);
                    $w->save();
                    $fixed++;
                }
            }
        }

        $this->newLine();
        $this->info("Wallets: {$wallets->count()} · cadenas rotas: {$chainIssues} · drift de saldo: {$balanceDrift}"
            . ($fix ? " · corregidos: {$fixed}" : ''));

        if ($chainIssues > 0 || $balanceDrift > 0) {
            if (!$fix) {
                $this->error('Hay inconsistencias. Corré con --fix para reconciliar los saldos (el ledger no se toca).');
            }
            return self::FAILURE;
        }

        $this->info('Ledger consistente. Sin drift.');
        return self::SUCCESS;
    }
}
