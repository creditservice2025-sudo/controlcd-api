<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Seller;
use App\Models\Liquidation;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Credit;
use Carbon\Carbon;

class CreateHistoricalLiquidation extends Command
{
    protected $signature = 'liquidation:historical';
    protected $description = 'Crea una liquidación histórica por cada día para cada vendedor desde su primera actividad hasta hoy';

    public function handle()
    {
        $timezone = 'America/Caracas';
        $todayDate = Carbon::now($timezone)->toDateString();

        $sellers = Seller::all();
        foreach ($sellers as $seller) {
            // FECHA DE INICIO: el menor entre la fecha de creación del seller y cualquier movimiento relevante
            $firstCreditDate = Credit::where('seller_id', $seller->id)->min('created_at');
            $firstPaymentDate = Payment::whereHas('credit', function($q) use ($seller) {
                $q->where('seller_id', $seller->id);
            })->min('created_at');
            $firstExpenseDate = Expense::where('user_id', $seller->user_id)->min('created_at');
            $firstIncomeDate = Income::where('user_id', $seller->user_id)->min('created_at');
            $firstSellerDate = $seller->created_at;

            $dates = [
                $firstCreditDate,
                $firstPaymentDate,
                $firstExpenseDate,
                $firstIncomeDate,
                $firstSellerDate,
            ];
            $dates = array_filter($dates); // remueve nulls
            $startDate = Carbon::parse(min($dates))->toDateString();
            $endDate = Carbon::now($timezone)->subDay()->toDateString();

            $datePointer = Carbon::parse($startDate, $timezone);
            $initialCash = 0;

            while ($datePointer->toDateString() <= $endDate) {
                $currentDate = $datePointer->toDateString();

                // Verifica si ya existe liquidación para este día
                $exists = Liquidation::where('seller_id', $seller->id)
                    ->whereDate('date', $currentDate)
                    ->exists();

                if ($exists) {
                    $datePointer->addDay();
                    continue;
                }

                // Movimientos del día
                $total_collected = Payment::whereHas('credit', function($q) use ($seller) {
                        $q->where('seller_id', $seller->id);
                    })
                    ->whereBetween('created_at', [
                        Carbon::parse($currentDate, $timezone)->startOfDay(),
                        Carbon::parse($currentDate, $timezone)->endOfDay()
                    ])
                    ->sum('amount');

                $total_expenses = Expense::where('user_id', $seller->user_id)
                    ->whereBetween('created_at', [
                        Carbon::parse($currentDate, $timezone)->startOfDay(),
                        Carbon::parse($currentDate, $timezone)->endOfDay()
                    ])
                    ->sum('value');

                $total_income = Income::where('user_id', $seller->user_id)
                    ->whereBetween('created_at', [
                        Carbon::parse($currentDate, $timezone)->startOfDay(),
                        Carbon::parse($currentDate, $timezone)->endOfDay()
                    ])
                    ->sum('value');

                // Créditos creados en la fecha y timezone correcto
                $creditTest = Credit::where('seller_id', $seller->id)
                    ->whereBetween('created_at', [
                        Carbon::parse($currentDate, $timezone)->startOfDay(),
                        Carbon::parse($currentDate, $timezone)->endOfDay()
                    ])
                    ->get();

                $new_credits = $creditTest->sum('credit_value');

                // Log para depuración
                $this->info("Fecha: $currentDate - Créditos nuevos para seller_id {$seller->id}: " . $creditTest->pluck('id')->implode(', ') . " - Total créditos: $new_credits");

                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->where('credits.seller_id', $seller->id)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereBetween('credits.updated_at', [
                        Carbon::parse($currentDate, $timezone)->startOfDay(),
                        Carbon::parse($currentDate, $timezone)->endOfDay()
                    ])
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');

                // Si quieres que el saldo inicial sea el saldo final del día anterior:
                // Busca la liquidación anterior a este día
                $previousLiquidation = Liquidation::where('seller_id', $seller->id)
                    ->whereDate('date', '<', $currentDate)
                    ->orderBy('date', 'desc')
                    ->first();

                $initialCash = $previousLiquidation ? $previousLiquidation->real_to_deliver : 0;

                $real_to_deliver = $initialCash + ($total_income + $total_collected)
                    - ($total_expenses + $new_credits + $irrecoverableCredits);

                Liquidation::create([
                    'date' => $currentDate,
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
                    'status' => 'historical',
                ]);

                $this->info("Liquidación histórica creada para vendedor {$seller->id} en {$currentDate}");

                $datePointer->addDay();
            }
        }

        $this->info('Liquidaciones históricas diarias creadas.');
    }
}