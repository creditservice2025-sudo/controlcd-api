<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Seller;
use App\Models\Liquidation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LiquidateSpecificDate extends Command
{
    protected $signature = 'liquidation:date {date?} {--seller=}';
    protected $description = 'Genera liquidación para una fecha específica. Uso: liquidation:date [YYYY-MM-DD] [--seller=ID]';

    public function handle()
    {
        $timezone = 'America/Lima';
        
        // Obtener fecha del argumento o usar ayer como default
        $dateInput = $this->argument('date');
        if ($dateInput) {
            try {
                $targetDate = Carbon::parse($dateInput, $timezone)->toDateString();
            } catch (\Exception $e) {
                $this->error("Formato de fecha inválido. Use: YYYY-MM-DD (ejemplo: 2025-11-19)");
                return 1;
            }
        } else {
            $targetDate = Carbon::now($timezone)->subDay()->toDateString();
            $this->info("No se especificó fecha. Usando fecha de AYER: {$targetDate}");
        }

        $this->info("Generando liquidaciones para: {$targetDate}");

        // Obtener vendedor específico o todos
        $sellerId = $this->option('seller');
        if ($sellerId) {
            $sellers = Seller::where('id', $sellerId)->get();
            if ($sellers->isEmpty()) {
                $this->error("Vendedor con ID {$sellerId} no encontrado");
                return 1;
            }
            $this->info("Procesando vendedor ID: {$sellerId}");
        } else {
            $sellers = Seller::all();
            $this->info("Procesando TODOS los vendedores ({$sellers->count()} total)");
        }

        $created = 0;
        $skipped = 0;

        foreach ($sellers as $seller) {
            // Verifica si ya existe liquidación para la fecha
            $exists = Liquidation::where('seller_id', $seller->id)
                ->whereDate('date', $targetDate)
                ->exists();

            if ($exists) {
                $this->warn("  ⚠ Vendedor {$seller->id} ({$seller->user->name}): Ya existe liquidación para {$targetDate}");
                $skipped++;
                continue;
            }

            // Obtén la liquidación anterior para obtener initial_cash
            $previousLiquidation = Liquidation::where('seller_id', $seller->id)
                ->whereDate('date', '<', $targetDate)
                ->orderBy('date', 'desc')
                ->first();

            $initialCash = $previousLiquidation ? $previousLiquidation->real_to_deliver : 0;

            // Calcular totales del día
            $total_collected = DB::table('payments')
                ->join('credits', 'payments.credit_id', '=', 'credits.id')
                ->where('credits.seller_id', $seller->id)
                ->whereDate('payments.created_at', $targetDate)
                ->sum('payments.amount');

            $total_expenses = DB::table('expenses')
                ->where('user_id', $seller->user_id)
                ->whereDate('created_at', $targetDate)
                ->sum('value');

            $total_income = DB::table('incomes')
                ->where('user_id', $seller->user_id)
                ->whereDate('created_at', $targetDate)
                ->sum('value');

            $new_credits = DB::table('credits')
                ->where('seller_id', $seller->id)
                ->whereNull('renewed_from_id')
                ->whereDate('created_at', $targetDate)
                ->sum('credit_value');

            $irrecoverableCredits = DB::table('installments')
                ->join('credits', 'installments.credit_id', '=', 'credits.id')
                ->where('credits.seller_id', $seller->id)
                ->where('credits.status', 'Cartera Irrecuperable')
                ->whereDate('credits.updated_at', $targetDate)
                ->where('installments.status', 'Pendiente')
                ->sum('installments.quota_amount');

            // Calcular renovaciones
            $renewalCredits = DB::table('credits')
                ->where('seller_id', $seller->id)
                ->whereNotNull('renewed_from_id')
                ->whereDate('created_at', $targetDate)
                ->get();

            $total_renewal_disbursed = 0;
            $total_pending_absorbed = 0;

            foreach ($renewalCredits as $renewCredit) {
                $oldCredit = DB::table('credits')->where('id', $renewCredit->renewed_from_id)->first();
                $pendingAmount = 0;
                if ($oldCredit) {
                    $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                    $oldCreditPaid = DB::table('payments')->where('credit_id', $oldCredit->id)->sum('amount');
                    $pendingAmount = $oldCreditTotal - $oldCreditPaid;
                    $total_pending_absorbed += $pendingAmount;
                }
                $netDisbursement = $renewCredit->credit_value - $pendingAmount;
                $total_renewal_disbursed += $netDisbursement;
            }

            $real_to_deliver = $initialCash + ($total_income + $total_collected)
                - ($total_expenses + $new_credits + $irrecoverableCredits + $total_renewal_disbursed);

            $liquidationData = [
                'date' => $targetDate,
                'seller_id' => $seller->id,
                'collection_target' => 0,
                'initial_cash' => $initialCash,
                'base_delivered' => 0,
                'total_collected' => $total_collected,
                'total_expenses' => $total_expenses,
                'total_income' => $total_income,
                'new_credits' => $new_credits,
                'real_to_deliver' => $real_to_deliver,
                'shortage' => 0,
                'surplus' => 0,
                'cash_delivered' => 0,
                'status' => 'manual',
                'irrecoverable_credits_amount' => $irrecoverableCredits,
                'renewal_disbursed_total' => $total_renewal_disbursed,
                'total_pending_absorbed' => $total_pending_absorbed, 
            ];

            Liquidation::create($liquidationData);

            $this->info("  ✓ Vendedor {$seller->id} ({$seller->user->name}): Liquidación creada | Real a entregar: \${$real_to_deliver}");
            $created++;
        }

        $this->newLine();
        $this->info("═══════════════════════════════════════");
        $this->info("  Resumen para {$targetDate}:");
        $this->info("  ✓ Creadas: {$created}");
        $this->info("  ⚠ Omitidas (ya existían): {$skipped}");
        $this->info("═══════════════════════════════════════");

        return 0;
    }
}
