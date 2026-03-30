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

    private $payrollService;

    public function __construct(\App\Services\PayrollService $payrollService)
    {
        parent::__construct();
        $this->payrollService = $payrollService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $startDateStr = $this->option('start') ?: now()->subWeeks(4)->startOfWeek()->format('Y-m-d');
        $endDateStr = $this->option('end') ?: now()->format('Y-m-d');

        $start = Carbon::parse($startDateStr);
        $end = Carbon::parse($endDateStr);

        $sellersQuery = Seller::with(['config', 'user'])->whereHas('config', function($q){
            $q->where('commission_system_active', true);
        });

        if ($this->option('seller')) {
            $sellersQuery->where('id', $this->option('seller'));
        }

        $sellers = $sellersQuery->get();

        if ($sellers->isEmpty()) {
            $this->warn("No sellers found with active commission system.");
            return Command::SUCCESS;
        }

        foreach ($sellers as $seller) {
            $this->info("Processing Seller: {$seller->user->name}");
            
            // Iterate through the range based on seller's frequency
            $current = $start->copy();
            while ($current->isBefore($end) || $current->isSameDay($end)) {
                [$pStart, $pEnd] = $this->payrollService->calculatePeriod($seller, $current);
                
                $this->info("  - Period: {$pStart->toDateString()} to {$pEnd->toDateString()}");
                
                $payroll = $this->payrollService->generateForSeller($seller, $pStart, $pEnd);
                
                if ($payroll) {
                    $this->info("    * Generated Payroll ID: {$payroll->id}. Net: {$payroll->net_total}");
                }

                // Advance current to original end + 1 day to find the next period
                $current = $pEnd->copy()->addDay();
            }
        }

        $this->info("Payroll generation completed.");
        return Command::SUCCESS;
    }
}
