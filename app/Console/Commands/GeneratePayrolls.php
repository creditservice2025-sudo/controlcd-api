<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Seller;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\Credit;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class GeneratePayrolls extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payrolls:generate {--start= : Start date (YYYY-MM-DD)} {--end= : End date (YYYY-MM-DD)} {--seller= : Seller ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate weekly payrolls for sellers based on collections (Monday to Saturday)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $startDateStr = $this->option('start') ?: now()->subWeeks(4)->startOfWeek()->format('Y-m-d');
        $endDateStr = $this->option('end') ?: now()->format('Y-m-d');

        $start = Carbon::parse($startDateStr)->startOfWeek(); // Monday
        $end = Carbon::parse($endDateStr);

        $period = CarbonPeriod::create($start, '7 days', $end);

        $sellersQuery = Seller::with(['config', 'user']);

        if ($this->option('seller')) {
            $sellersQuery->where('id', $this->option('seller'));
        }

        $sellers = $sellersQuery->get();

        if ($sellers->isEmpty()) {
            $this->warn("No sellers found with active commission system.");
            return Command::SUCCESS;
        }

        foreach ($period as $date) {
            $monday = $date->copy()->startOfWeek();
            $saturday = $monday->copy()->addDays(5); // Saturday
            
            $this->info("Processing week: {$monday->toDateString()} to {$saturday->toDateString()}");

            foreach ($sellers as $seller) {
                $this->generateForSeller($seller, $monday, $saturday);
            }
        }

        $this->info("Payroll generation completed.");
        return Command::SUCCESS;
    }

    /**
     * Generate payroll for a specific seller and date range.
     */
    private function generateForSeller($seller, $start, $end)
    {
        // Check if payroll already exists to avoid duplicates
        $exists = Payroll::where('seller_id', $seller->id)
            ->where('start_date', $start->toDateString())
            ->where('end_date', $end->toDateString())
            ->exists();

        if ($exists) {
            $this->warn("  - Payroll already exists for Seller #{$seller->id} ({$seller->user->name}) in this range.");
            return;
        }

        $config = $seller->config;

        // Fetch payments in the period
        $payments = Payment::whereHas('credit', function($q) use ($seller) {
                $q->where('seller_id', $seller->id);
            })
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', 'Anulado')
            ->with('credit')
            ->get();

        $totalRecaudo = $payments->sum('amount');
        $totalInteres = 0;

        foreach ($payments as $payment) {
            $credit = $payment->credit;
            if (!$credit) continue;

            $totalCreditAmount = $credit->total_amount;
            if ($totalCreditAmount <= 0) {
               $totalCreditAmount = ($credit->credit_value ?? 0) * (1 + ($credit->total_interest ?? 0) / 100);
            }

            if ($totalCreditAmount > 0) {
                $ratio = ($credit->credit_value ?? 0) / $totalCreditAmount;
                $capitalPortion = $payment->amount * $ratio;
                $interestPortion = $payment->amount - $capitalPortion;
                $totalInteres += $interestPortion;
            }
        }

        // Commission Calculations
        $commissionColl = $totalRecaudo * (($config->commission_total_collection ?? 0) / 100);
        $commissionUtil = $totalInteres * (($config->commission_utility_recon_madrid ?? 0) / 100);
        
        // Fixed Weekly Salary and Allowance (Divide monthly portions by 4)
        $salaryWeekly = ($config->monthly_fixed_salary ?? 0) / 4;
        $allowanceWeekly = ($config->weekly_allowance ?? 0);
        
        // Deductions (Savings, Pension, Earnings, ARL - assumed monthly totals divided by 4)
        $savingsWeekly = ($config->monthly_savings ?? 0) / 4;
        $legalDeductionsWeekly = (($config->pension_discount ?? 0) + ($config->eps_discount ?? 0) + ($config->arl_discount ?? 0)) / 4;

        $netTotal = $salaryWeekly + $allowanceWeekly + $commissionColl + $commissionUtil - ($savingsWeekly + $legalDeductionsWeekly);

        // Skip if there's no activity and no fixed income/deductions
        if ($totalRecaudo <= 0 && $salaryWeekly <= 0 && $allowanceWeekly <= 0 && $commissionColl <= 0 && $commissionUtil <= 0) {
            return;
        }

        Payroll::create([
            'seller_id' => $seller->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'total_collected' => $totalRecaudo,
            'total_utility' => $totalInteres,
            'commission_utility' => $commissionUtil,
            'commission_collection' => $commissionColl,
            'commission_credits' => 0, 
            'salary' => $salaryWeekly,
            'allowance' => $allowanceWeekly,
            'deductions_savings' => $savingsWeekly,
            'deductions_arl' => $legalDeductionsWeekly,
            'net_total' => max(0, $netTotal),
            'status' => 'pending'
        ]);

        $this->info("  - Generated Payroll for Seller #{$seller->id} ({$seller->user->name}). Net: {$netTotal}");
    }
}
