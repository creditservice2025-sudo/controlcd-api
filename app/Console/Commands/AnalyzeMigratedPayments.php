<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Payment;
use App\Models\Credit;

class AnalyzeMigratedPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:analyze-migrated {--export : Export results to CSV}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze payments that were migrated from Redis cache';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('=== Analyzing Migrated Payments ===');
        $this->info('');

        $migratedPayments = Payment::where('migrated_from_cache', true)
            ->with('credit.client')
            ->orderBy('migrated_at', 'desc')
            ->get();

        if ($migratedPayments->isEmpty()) {
            $this->warn('No migrated payments found.');
            return 0;
        }

        // Overall statistics
        $totalCount = $migratedPayments->count();
        $totalAmount = $migratedPayments->sum('amount');
        $totalUnapplied = $migratedPayments->sum('unapplied_amount');
        $totalApplied = $totalAmount - $totalUnapplied;

        $this->info('=== Overall Statistics ===');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total migrated payments', $totalCount],
                ['Total amount', '$' . number_format($totalAmount, 2)],
                ['Amount applied to installments', '$' . number_format($totalApplied, 2)],
                ['Amount still unapplied', '$' . number_format($totalUnapplied, 2)],
                ['Application rate', number_format(($totalApplied / $totalAmount) * 100, 2) . '%'],
            ]
        );

        // Group by credit
        $this->info('');
        $this->info('=== By Credit ===');

        $byCredit = $migratedPayments->groupBy('credit_id')->map(function ($payments, $creditId) {
            $credit = $payments->first()->credit;
            return [
                'credit_id' => $creditId,
                'client_name' => $credit->client->name ?? 'N/A',
                'client_dni' => $credit->client->dni ?? 'N/A',
                'payment_count' => $payments->count(),
                'total_amount' => $payments->sum('amount'),
                'unapplied_amount' => $payments->sum('unapplied_amount'),
            ];
        })->sortByDesc('unapplied_amount')->values();

        $this->table(
            ['Credit ID', 'Client', 'DNI', 'Payments', 'Total', 'Unapplied'],
            $byCredit->take(20)->map(function ($item) {
                return [
                    $item['credit_id'],
                    $item['client_name'],
                    $item['client_dni'],
                    $item['payment_count'],
                    '$' . number_format($item['total_amount'], 2),
                    '$' . number_format($item['unapplied_amount'], 2),
                ];
            })->toArray()
        );

        if ($byCredit->count() > 20) {
            $this->line("... and " . ($byCredit->count() - 20) . " more credits");
        }

        // Status breakdown
        $this->info('');
        $this->info('=== By Payment Status ===');

        $byStatus = $migratedPayments->groupBy('status')->map(function ($payments, $status) {
            return [
                'status' => $status,
                'count' => $payments->count(),
                'total_amount' => $payments->sum('amount'),
                'unapplied_amount' => $payments->sum('unapplied_amount'),
            ];
        });

        $this->table(
            ['Status', 'Count', 'Total Amount', 'Unapplied'],
            $byStatus->map(function ($item) {
                return [
                    $item['status'],
                    $item['count'],
                    '$' . number_format($item['total_amount'], 2),
                    '$' . number_format($item['unapplied_amount'], 2),
                ];
            })->toArray()
        );

        // Migration timeline
        $this->info('');
        $this->info('=== Migration Timeline ===');

        $byDate = $migratedPayments->groupBy(function ($payment) {
            return $payment->migrated_at ? $payment->migrated_at->format('Y-m-d H:i') : 'Unknown';
        })->map(function ($payments, $date) {
            return [
                'date' => $date,
                'count' => $payments->count(),
                'amount' => $payments->sum('amount'),
            ];
        })->sortBy('date');

        $this->table(
            ['Migration Date/Time', 'Payments', 'Amount'],
            $byDate->map(function ($item) {
                return [
                    $item['date'],
                    $item['count'],
                    '$' . number_format($item['amount'], 2),
                ];
            })->toArray()
        );

        // Export option
        if ($this->option('export')) {
            $filename = storage_path('app/migrated_payments_analysis_' . date('Y-m-d_H-i-s') . '.csv');

            $fp = fopen($filename, 'w');
            fputcsv($fp, ['Payment ID', 'Credit ID', 'Client Name', 'Client DNI', 'Amount', 'Unapplied Amount', 'Status', 'Migrated At', 'Payment Date']);

            foreach ($migratedPayments as $payment) {
                fputcsv($fp, [
                    $payment->id,
                    $payment->credit_id,
                    $payment->credit->client->name ?? 'N/A',
                    $payment->credit->client->dni ?? 'N/A',
                    $payment->amount,
                    $payment->unapplied_amount,
                    $payment->status,
                    $payment->migrated_at ? $payment->migrated_at->format('Y-m-d H:i:s') : 'N/A',
                    $payment->payment_date,
                ]);
            }

            fclose($fp);

            $this->info('');
            $this->info("✓ Exported to: {$filename}");
        }

        return 0;
    }
}
