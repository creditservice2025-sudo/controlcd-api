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
        $sellers = Seller::all();
        $count = 0;

        foreach ($sellers as $seller) {
            try {
                $businessTimezone = \App\Helpers\TimezoneHelper::getSellerTimezone($seller);
                $now = Carbon::now($businessTimezone);
                
                // Determinamos la fecha de cierre objetivo.
                // Si son las 23:55+, el objetivo es HOY.
                // Si son las 00:00 - 00:30, el objetivo es AYER (por si el cron de las 23:55 falló o tardó).
                $targetDate = null;
                
                if ($now->hour == 23 && $now->minute >= 55) {
                    $targetDate = $now->toDateString();
                } elseif ($now->hour == 0 && $now->minute < 30) {
                    $targetDate = $now->copy()->subDay()->toDateString();
                }
                
                // Si no estamos en ventana de cierre, saltamos
                if (!$targetDate) {
                    continue;
                }

                // Verifica si ya existe liquidación para esa fecha objetivo
                $exists = Liquidation::where('seller_id', $seller->id)
                    ->whereDate('date', $targetDate)
                    ->exists();

                if (!$exists) {
                    $this->generateLiquidation($seller, $targetDate, $businessTimezone);
                    $count++;
                }

            } catch (\Exception $e) {
                \Log::error("Error auto-liquidating seller {$seller->id}: " . $e->getMessage());
            }
        }

        $this->info("Proceso completado. {$count} liquidaciones generadas.");
    }

    private function generateLiquidation($seller, $date, $timezone)
    {
        // Totals Calculation based on Business Date
        
        // 1. Initial Cash (Carry over from previous liquidation)
        $previousLiq = Liquidation::where('seller_id', $seller->id)
            ->whereDate('date', '<', $date)
            ->orderBy('date', 'desc')
            ->first();
        $initialCash = $previousLiq ? $previousLiq->real_to_deliver : 0;

        // 2. Payments (Collected) - Assuming 'business_date' exists as per request
        // Fallback checks just in case
        $total_collected = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $seller->id)
            ->where(function($q) use ($date) {
                // Try business_date if exists (we assume user confirmed it exists)
                // If not, fallback to created_at logic ??? 
                // User said Payment has it. We use it.
                $q->where('payments.business_date', $date);
            })
            ->sum('payments.amount');

        // 3. Expenses - Use business_date
        $total_expenses = DB::table('expenses')
            ->where('user_id', $seller->user_id)
            ->where('status', 'Aprobado') // Filter approved
            ->where(function($q) use ($date) {
                $q->where('business_date', $date)
                  ->orWhere(function($sub) use ($date) {
                      $sub->whereNull('business_date')
                          ->whereDate('created_at', $date); // Fallback
                  });
            })
            ->sum('value');

        // 4. Incomes - Use business_date
        $total_income = DB::table('incomes')
            ->where('user_id', $seller->user_id)
            ->where(function($q) use ($date) {
                $q->where('business_date', $date)
                  ->orWhere(function($sub) use ($date) {
                      $sub->whereNull('business_date')
                          ->whereDate('created_at', $date); // Fallback
                  });
            })
            ->sum('value');

        // 5. New Credits - Credits (Assuming no business_date migration yet)
        // Use Timezone conversion
        $startOfDayUTC = Carbon::parse($date, $timezone)->startOfDay()->utc();
        $endOfDayUTC = Carbon::parse($date, $timezone)->endOfDay()->utc();

        $new_credits = DB::table('credits')
            ->where('seller_id', $seller->id)
            ->whereNull('renewed_from_id')
            ->whereBetween('created_at', [$startOfDayUTC, $endOfDayUTC]) // Accurate UTC range
            ->sum('credit_value');

        // 6. Irrecoverable Credits
         $irrecoverableCredits = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $seller->id)
            ->where('credits.status', 'Cartera Irrecuperable')
            ->whereBetween('credits.updated_at', [$startOfDayUTC, $endOfDayUTC])
            ->where('installments.status', 'Pendiente')
            ->sum('installments.quota_amount');

        // 7. Renewals
        $renewalCredits = DB::table('credits')
            ->where('seller_id', $seller->id)
            ->whereNotNull('renewed_from_id')
            ->whereBetween('created_at', [$startOfDayUTC, $endOfDayUTC])
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
            'date' => $date,
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
            'irrecoverable_credits_amount' => $irrecoverableCredits,
            'renewal_disbursed_total' => $total_renewal_disbursed,
            'total_pending_absorbed' => $total_pending_absorbed, 
        ];

        Liquidation::create($liquidationData);

        $this->info("Liquidación automática creada para vendedor {$seller->id} (Zona: {$timezone}) Fecha: {$date}");
    }
}
