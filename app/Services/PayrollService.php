<?php

namespace App\Services;

use App\Models\Seller;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\Credit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    /**
     * Calculate the start and end dates for a payroll period based on seller config.
     * 
     * @param Seller $seller
     * @param Carbon $date Any date within the desired period
     * @return array [start, end]
     */
    public function calculatePeriod(Seller $seller, Carbon $date, $overrideFrequency = null)
    {
        $config = $seller->config;
        $frequency = $overrideFrequency ?: ($config->payroll_frequency ?? 'weekly');
        $startDay = $config->payroll_start_day ?? 1; // 1 = Monday

        $start = $date->copy();
        $end = $date->copy();

        switch ($frequency) {
            case 'daily':
                $start = $date->copy()->startOfDay();
                $end = $date->copy()->endOfDay();
                break;

            case 'weekly':
                // Move back to the nearest startDay
                while ($start->dayOfWeekIso != $startDay) {
                    $start->subDay();
                }
                $start->startOfDay();
                $end = $start->copy()->addDays(6)->endOfDay();
                break;

            case 'biweekly':
                // Similar to weekly but 14 days
                // We assume biweekly periods start from a fixed anchor or just the nearest startDay
                while ($start->dayOfWeekIso != $startDay) {
                    $start->subDay();
                }
                $start->startOfDay();
                $end = $start->copy()->addDays(13)->endOfDay();
                break;

            case 'monthly':
                // Monthly starting on a specific day of the month
                // if startDay is 1, it's the 1st of the month.
                $start = $date->copy()->day($startDay);
                if ($start->isAfter($date)) {
                    $start->subMonth();
                }
                $start->startOfDay();
                $end = $start->copy()->addMonth()->subDay()->endOfDay();
                break;
        }

        return [$start, $end];
    }

    /**
     * Generate payroll for a seller in a specific range.
     */
    public function generateForSeller(Seller $seller, Carbon $start, Carbon $end)
    {
        $config = $seller->config;
        
        // Fetch payments in the period
        $query = Payment::whereHas('credit', function($q) use ($seller) {
                $q->where('seller_id', $seller->id);
            })
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', 'Anulado')
            ->with('credit');

        // Sunday Exclusion Logic
        if (!($config->include_sundays ?? false)) {
            $query->whereRaw('DAYOFWEEK(payment_date) != 1');
        }

        $payments = $query->get();

        $totalRecaudo = $payments->sum('amount');
        $totalInteres = 0;

        foreach ($payments as $payment) {
            $credit = $payment->credit;
            if (!$credit) continue;

            $totalCreditAmount = (float)$credit->total_amount;
            if ($totalCreditAmount <= 0) {
               $totalCreditAmount = (float)($credit->credit_value ?? 0) * (1 + (float)($credit->total_interest ?? 0) / 100);
            }

            if ($totalCreditAmount > 0) {
                $ratio = (float)($credit->credit_value ?? 0) / $totalCreditAmount;
                $capitalPortion = $payment->amount * $ratio;
                $interestPortion = $payment->amount - $capitalPortion;
                $totalInteres += $interestPortion;
            }
        }

        // Commission Calculations
        $commissionColl = $totalRecaudo * (($config->commission_total_collection ?? 0) / 100);
        $commissionUtil = $totalInteres * (($config->commission_utility_recon_madrid ?? 0) / 100);
        
        // Prorrating Factory
        $daysInPeriod = $start->diffInDays($end) + 1;
        $monthFactor = $daysInPeriod / 30; 
        $weekFactor = $daysInPeriod / 7;

        $salary = ($config->monthly_fixed_salary ?? 0) * $monthFactor;
        $allowance = ($config->weekly_allowance ?? 0) * $weekFactor;
        
        $savings = ($config->monthly_savings ?? 0) * $monthFactor;
        $legalDeductions = (($config->pension_discount ?? 0) + ($config->eps_discount ?? 0) + ($config->arl_discount ?? 0)) * $monthFactor;

        $netTotal = $salary + $allowance + $commissionColl + $commissionUtil - ($savings + $legalDeductions);

        // Skip if there's no activity and no fixed income/deductions
        if ($totalRecaudo <= 0 && $salary <= 0 && $allowance <= 0 && $commissionColl <= 0 && $commissionUtil <= 0) {
            return null;
        }

        return Payroll::updateOrCreate(
            [
                'seller_id' => $seller->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ],
            [
                'total_collected' => $totalRecaudo,
                'total_utility' => $totalInteres,
                'commission_utility' => $commissionUtil,
                'commission_collection' => $commissionColl,
                'commission_credits' => 0, 
                'salary' => $salary,
                'allowance' => $allowance,
                'deductions_savings' => $savings,
                'deductions_arl' => $legalDeductions,
                'net_total' => max(0, $netTotal),
                'status' => 'pending'
            ]
        );
    }

    /**
     * Check if a specific date is the final day (end_date) of a payroll period for a seller.
     */
    public function isPayrollDay(Seller $seller, Carbon $date)
    {
        if (!$this->isParameterized($seller)) return false;
        
        [$start, $end] = $this->calculatePeriod($seller, $date);
        return $date->isSameDay($end);
    }

    /**
     * Check if seller has mandatory payroll configuration.
     */
    public function isParameterized(Seller $seller)
    {
        $config = $seller->config;
        return $config && $config->payroll_frequency && $config->payroll_start_day !== null;
    }

    /**
     * Gets or creates a placeholder for the current running payroll.
     */
    public function getOrCreateCurrentPayroll(Seller $seller, Carbon $date = null)
    {
        if (!$this->isParameterized($seller)) return null;
        
        $date = $date ?: Carbon::now();
        
        // If today is Sunday and NOT included, and we are at the START of a new period,
        // we might want to skip or show the PREVIOUS one? 
        // User says: "como hoy es domingo no mostrarme información, sino el periodo a correr"
        // This implies if today is Sunday 29, and it's NOT included, 
        // they want to see the period that INCLUDES today (Weekly 29-04 or Biweekly 23-05).
        
        [$start, $end] = $this->calculatePeriod($seller, $date);
        
        $payroll = Payroll::where('seller_id', $seller->id)
            ->where('start_date', $start->toDateString())
            ->where('end_date', $end->toDateString())
            ->first();
            
        if (!$payroll) {
            // Virtual payroll for the dashboard
            $payroll = $this->generateForSeller($seller, $start, $end);
            
            // If generateForSeller returns null (no activity), we force a minimal one 
            // if we really want to show the "Periodo a correr"
            if (!$payroll) {
                $payroll = new Payroll([
                    'seller_id' => $seller->id,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'total_collected' => 0,
                    'net_total' => 0,
                    'status' => 'pending'
                ]);
                $payroll->seller = $seller; // Load relations for frontend
            }
        }
        
        return $payroll;
    }
}
