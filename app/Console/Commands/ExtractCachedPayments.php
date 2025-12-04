<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\Models\Credit;
use App\Models\Payment;

class ExtractCachedPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:extract-cached';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Extract all cached partial payments from Redis before migration';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('=== Extracting Cached Payments from Redis ===');
        $this->info('');

        // Get all credit IDs
        $creditIds = Credit::pluck('id');
        $cachedPaymentsData = [];
        $totalCached = 0;
        $totalAmount = 0;

        $this->info("Scanning {$creditIds->count()} credits for cached payments...");
        $this->info('');

        $bar = $this->output->createProgressBar($creditIds->count());
        $bar->start();

        foreach ($creditIds as $creditId) {
            $cacheKey = "credit:{$creditId}:pending_payments";
            $cachePaymentsKey = "credit:{$creditId}:pending_payments_list";

            $accumulated = Cache::get($cacheKey);
            $pendingPayments = Cache::get($cachePaymentsKey);

            if ($accumulated && $pendingPayments && is_array($pendingPayments) && count($pendingPayments) > 0) {
                $credit = Credit::with('client')->find($creditId);

                $cachedPaymentsData[] = [
                    'credit_id' => $creditId,
                    'client_name' => $credit->client->name ?? 'N/A',
                    'client_dni' => $credit->client->dni ?? 'N/A',
                    'accumulated_amount' => $accumulated,
                    'pending_payments' => $pendingPayments,
                    'payment_count' => count($pendingPayments),
                    'extracted_at' => now()->toDateTimeString()
                ];

                $totalCached += count($pendingPayments);
                $totalAmount += $accumulated;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->info('');
        $this->info('');

        // Save to JSON file
        $filename = storage_path('app/cached_payments_' . date('Y-m-d_H-i-s') . '.json');
        file_put_contents($filename, json_encode($cachedPaymentsData, JSON_PRETTY_PRINT));

        // Display summary
        $this->info('=== Extraction Summary ===');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Credits with cached payments', count($cachedPaymentsData)],
                ['Total cached payments', $totalCached],
                ['Total accumulated amount', '$' . number_format($totalAmount, 2)],
            ]
        );

        $this->info('');
        $this->info("✓ Data saved to: {$filename}");
        $this->info('');

        // Show details if there are cached payments
        if (count($cachedPaymentsData) > 0) {
            $this->warn('⚠ Found cached payments that need migration!');
            $this->info('');
            $this->info('Credits with cached payments:');

            foreach ($cachedPaymentsData as $data) {
                $this->line(sprintf(
                    "  • Credit #%d (%s - %s): %d payments, $%s accumulated",
                    $data['credit_id'],
                    $data['client_name'],
                    $data['client_dni'],
                    $data['payment_count'],
                    number_format($data['accumulated_amount'], 2)
                ));
            }

            $this->info('');
            $this->warn('Next step: Run "php artisan payments:migrate-cached --dry-run" to test migration');
        } else {
            $this->info('✓ No cached payments found. Safe to deploy new version.');
        }

        return 0;
    }
}
