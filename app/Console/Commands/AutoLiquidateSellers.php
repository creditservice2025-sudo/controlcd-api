<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Seller;
use App\Models\Liquidation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AutoLiquidateSellers extends Command
{
    protected $signature = 'liquidation:auto-daily';
    protected $description = 'Genera liquidación diaria automática para todos los vendedores si no existe';

    public function handle()
    {
        $timezone = 'America/Caracas';
        $todayDate = Carbon::now($timezone)->toDateString();

        $sellers = Seller::all();
        foreach ($sellers as $seller) {
            // Verifica si ya existe liquidación para hoy
            $exists = Liquidation::where('seller_id', $seller->id)
                ->whereDate('date', $todayDate)
                ->exists();

            if (!$exists) {
                // Obtén los movimientos del día
                $initialCash = optional(Liquidation::where('seller_id', $seller->id)->orderBy('date', 'desc')->first())->real_to_deliver ?? 0;

                $total_collected = DB::table('payments')
                    ->join('credits', 'payments.credit_id', '=', 'credits.id')
                    ->where('credits.seller_id', $seller->id)
                    ->whereDate('payments.created_at', $todayDate)
                    ->sum('payments.amount');

                $total_expenses = DB::table('expenses')
                    ->where('user_id', $seller->user_id)
                    ->whereDate('created_at', $todayDate)
                    ->sum('value');

                $total_income = DB::table('incomes')
                    ->where('user_id', $seller->user_id)
                    ->whereDate('created_at', $todayDate)
                    ->sum('value');

                $new_credits = DB::table('credits')
                    ->where('seller_id', $seller->id)
                    ->whereDate('created_at', $todayDate)
                    ->sum('credit_value');

                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->where('credits.seller_id', $seller->id)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereDate('credits.updated_at', $todayDate)
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');

                $real_to_deliver = $initialCash + ($total_income + $total_collected)
                    - ($total_expenses + $new_credits + $irrecoverableCredits);

                $liquidationData = [
                    'date' => $todayDate,
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
                    'status' => 'auto',
                ];

                Liquidation::create($liquidationData);

                $this->info("Liquidación automática creada para vendedor {$seller->id} en {$todayDate}");
            }
        }

        $this->info('Liquidaciones automáticas diarias completadas.');
    }
}