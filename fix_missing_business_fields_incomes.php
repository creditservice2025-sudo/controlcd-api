<?php

use App\Models\Income;
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

echo "Searching for incomes with missing business_date or business_timezone...\n";

$incomes = Income::whereNull('business_date')
    ->orWhereNull('business_timezone')
    ->get();

$count = $incomes->count();

if ($count === 0) {
    echo "No incomes found with missing business fields.\n";
    exit;
}

echo "Found {$count} incomes to update.\n";

$updated = 0;

foreach ($incomes as $income) {
    try {
        if (!$income->created_at) {
            echo "Skipping Income ID {$income->id}: created_at is null.\n";
            continue;
        }

        // created_at is UTC by default in Laravel
        $createdAt = Carbon::parse($income->created_at);

        // Convert to target timezone
        $businessTime = $createdAt->copy()->setTimezone($timezone);

        $income->business_timezone = $timezone;
        $income->business_timestamp = $businessTime;
        $income->business_date = $businessTime->toDateString();

        $income->save();

        echo "Updated Income ID {$income->id}: created_at({$createdAt}) -> business_date({$income->business_date}) [{$timezone}]\n";
        $updated++;

    } catch (\Exception $e) {
        echo "Error updating Income ID {$income->id}: " . $e->getMessage() . "\n";
    }
}

echo "\nCompleted. Updated {$updated} incomes.\n";
