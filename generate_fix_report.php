<?php

use App\Models\Credit;
use Carbon\Carbon;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Define the time window for the fix (approx. last 45 minutes)
// Adjust if necessary, but 'since 1 hour ago' is safe for this session.
$startTime = Carbon::now()->subHour();

$credits = Credit::with(['client', 'seller'])
    ->where('updated_at', '>=', $startTime)
    ->orderBy('id', 'asc') // Order by ID
    ->get();

$count = $credits->count();

if ($count === 0) {
    echo "No updated credits found in the last hour.\n";
    exit;
}

echo "Found {$count} credits updated in the last hour.\n";
echo "Generating report...\n\n";

$filename = 'affected_credits_report.csv';
$handle = fopen($filename, 'w');

// Add BOM for Excel compatibility
fputs($handle, "\xEF\xBB\xBF");

// Header
fputcsv($handle, ['Nro Credito (ID)', 'Cliente', 'Vendedor', 'Fecha Creacion', 'Estatus Actual', 'Saldo Actual']);

// Console Output Header
echo str_pad("ID", 8) . " | " . 
     str_pad("Client", 30) . " | " . 
     str_pad("Seller", 20) . " | " . 
     str_pad("Created At", 20) . "\n";
echo str_repeat("-", 85) . "\n";

$previewCount = 0;

foreach ($credits as $credit) {
    $clientName = $credit->client ? ($credit->client->name . ' ' . $credit->client->last_name) : 'N/A';
    
    // Handle Seller name resolution (check if seller relation exists and has name, or user relation)
    $sellerName = 'N/A';
    if ($credit->seller) {
        $sellerName = $credit->seller->name ?? ($credit->seller->user->name ?? 'Unknown');
    }

    $createdAt = $credit->created_at ? $credit->created_at->format('d/m/Y H:i') : 'N/A';

    // Write to CSV
    fputcsv($handle, [
        $credit->id,
        $clientName,
        $sellerName,
        $createdAt,
        $credit->status,
        $credit->remaining_amount
    ]);

    // Print first 20 to console
    if ($previewCount < 20) {
        echo str_pad($credit->id, 8) . " | " . 
             str_pad(substr($clientName, 0, 28), 30) . " | " . 
             str_pad(substr($sellerName, 0, 18), 20) . " | " . 
             str_pad($createdAt, 20) . "\n";
        $previewCount++;
    }
}

fclose($handle);

echo str_repeat("-", 85) . "\n";
if ($count > 20) {
    echo "... and " . ($count - 20) . " more.\n";
}
echo "\nFull report saved to: " . realpath($filename) . "\n";
