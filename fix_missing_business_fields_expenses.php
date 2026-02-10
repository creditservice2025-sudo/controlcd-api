<?php

use App\Models\Expense;
use Carbon\Carbon;

// Ensure we are in the correct environment
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$timezone = 'America/Lima';

echo "Searching for expenses with missing business_date or business_timezone...\n";

$expenses = Expense::whereNull('business_date')
    ->orWhereNull('business_timezone')
    ->get();

$count = $expenses->count();

if ($count === 0) {
    echo "No expenses found with missing business fields.\n";
    exit;
}

echo "Found {$count} expenses to update.\n";

$updated = 0;

foreach ($expenses as $expense) {
    try {
        if (!$expense->created_at) {
            echo "Skipping Expense ID {$expense->id}: created_at is null.\n";
            continue;
        }

        // created_at is UTC by default in Laravel
        $createdAt = Carbon::parse($expense->created_at);

        // Convert to target timezone
        $businessTime = $createdAt->copy()->setTimezone($timezone);

        $expense->business_timezone = $timezone;
        $expense->business_timestamp = $businessTime;
        $expense->business_date = $businessTime->toDateString();

        $expense->save();

        echo "Updated Expense ID {$expense->id}: created_at({$createdAt}) -> business_date({$expense->business_date}) [{$timezone}]\n";
        $updated++;

    } catch (\Exception $e) {
        echo "Error updating Expense ID {$expense->id}: " . $e->getMessage() . "\n";
    }
}

echo "\nCompleted. Updated {$updated} expenses.\n";
