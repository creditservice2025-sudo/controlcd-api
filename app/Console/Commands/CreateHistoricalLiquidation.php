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
        $timezone = 'America/Lima';
        $todayDate = Carbon::now($timezone)->toDateString();

        $sellers = Seller::whereHas('config', function ($q) {
            $q->where('auto_closures_collectors', true);
        })->get();
        foreach ($sellers as $seller) {
            // FECHA DE INICIO: el menor entre la fecha de creación del seller y cualquier movimiento relevante
            $firstCreditDate = Credit::where('seller_id', $seller->id)->min('created_at');
            $firstPaymentDate = Payment::whereHas('credit', function ($q) use ($seller) {
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


                if (Carbon::parse($currentDate, $timezone)->isSunday()) {
                    $datePointer->addDay();
                    continue;
                }


                // Verifica si ya existe liquidación para este día
                $exists = Liquidation::where('seller_id', $seller->id)
                    ->whereDate('date', $currentDate)
                    ->exists();

                if ($exists) {
                    $datePointer->addDay();
                    continue;
                }

                $total_collected = Payment::whereHas('credit', function ($q) use ($seller) {
                    $q->where('seller_id', $seller->id);
                })
                    ->whereDate('created_at', $currentDate)
                    ->sum('amount');

                $total_expenses = Expense::where('user_id', $seller->user_id)
                    ->whereDate('created_at', $currentDate)
                    ->sum('value');

                $total_income = Income::where('user_id', $seller->user_id)
                    ->whereDate('created_at', $currentDate)
                    ->sum('value');

                // Créditos creados en la fecha (ignorando hora)
                $creditTest = Credit::where('seller_id', $seller->id)
                    ->whereDate('created_at', $currentDate)
                    ->get();

                $new_credits = $creditTest->whereNull('renewed_from_id')->sum('credit_value');

                $this->info("Fecha: $currentDate - Créditos nuevos para seller_id {$seller->id}: " . $creditTest->pluck('id')->implode(', ') . " - Total créditos: $new_credits");

                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->where('credits.seller_id', $seller->id)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereDate('credits.updated_at', $currentDate)
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');

                // === Detalle de renovaciones ===
                $renewalCredits = Credit::where('seller_id', $seller->id)
                    ->whereDate('created_at', $currentDate)
                    ->whereNotNull('renewed_from_id')
                    ->get();

                $total_renewal_disbursed = 0;
                $total_pending_absorbed = 0;

                $this->info("Fecha: $currentDate - Créditos de renovación encontrados: " . $renewalCredits->pluck('id')->implode(', '));

                foreach ($renewalCredits as $renewCredit) {
                    $oldCredit = Credit::find($renewCredit->renewed_from_id);

                    $pendingAmount = 0;
                    $oldCreditTotal = 0;
                    $oldCreditPaid = 0;
                    if ($oldCredit) {
                        $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                        $oldCreditPaid = Payment::where('credit_id', $oldCredit->id)->sum('amount');
                        $pendingAmount = $oldCreditTotal - $oldCreditPaid;
                        $total_pending_absorbed += $pendingAmount;
                        $this->info("Renovación: NuevoCreditoID: {$renewCredit->id}, CreditoAnteriorID: {$oldCredit->id}, TotalAnterior: {$oldCreditTotal}, PagadoAnterior: {$oldCreditPaid}, PendienteAbsorbido: {$pendingAmount}");
                    } else {
                        $this->info("Renovación: NuevoCreditoID: {$renewCredit->id}, CreditoAnteriorID: {$renewCredit->renewed_from_id} NO ENCONTRADO");
                    }

                    $netDisbursement = $renewCredit->credit_value - $pendingAmount;
                    $total_renewal_disbursed += $netDisbursement;
                }

                // Busca la liquidación anterior a este día
                $previousLiquidation = Liquidation::where('seller_id', $seller->id)
                    ->whereDate('date', '<', $currentDate)
                    ->orderBy('date', 'desc')
                    ->first();

                $initialCash = $previousLiquidation ? $previousLiquidation->real_to_deliver : 0;

                $real_to_deliver = $initialCash + ($total_income + $total_collected)
                    - ($total_expenses + $new_credits + $irrecoverableCredits + $total_renewal_disbursed);

                $this->info("Fecha: $currentDate - total_pending_absorbed a guardar: {$total_pending_absorbed}, total_renewal_disbursed: {$total_renewal_disbursed}");

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
                    'irrecoverable_credits_amount' => $irrecoverableCredits,
                    'renewal_disbursed_total' => $total_renewal_disbursed,
                    'total_pending_absorbed' => $total_pending_absorbed,
                ]);

                $this->info("Liquidación histórica creada para vendedor {$seller->id} en {$currentDate} | total_pending_absorbed: {$total_pending_absorbed}");

                $datePointer->addDay();
            }
        }

        $this->info('Liquidaciones históricas diarias creadas.');
    }
}
