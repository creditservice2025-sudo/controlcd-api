<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Expense;
use App\Models\User;
use Carbon\Carbon;

// Check for expenses created in the last 24 hours that show a date mismatch
$now = Carbon::now();
echo "Current Server Time (UTC): " . $now->toDateTimeString() . "\n";
echo "Current Local Time (Approx UTC-5): " . $now->copy()->subHours(5)->toDateTimeString() . "\n\n";

$expenses = Expense::where('created_at', '>', $now->copy()->subDays(2))
    ->with('user')
    ->get();

$affectedSellers = [];

foreach($expenses as $e) {
    $utcDate = $e->created_at->toDateString();
    $businessDate = $e->business_date ? $e->business_date->toDateString() : 'N/A';
    
    // If UTC date is different from business date, it's a potential confusion point
    if ($utcDate !== $businessDate && $businessDate !== 'N/A') {
        $userId = $e->user_id;
        $userName = $e->user ? $e->user->name : "Unknown ({$userId})";
        
        if (!isset($affectedSellers[$userId])) {
            $affectedSellers[$userId] = [
                'name' => $userName,
                'count' => 0,
                'examples' => []
            ];
        }
        
        $affectedSellers[$userId]['count']++;
        if (count($affectedSellers[$userId]['examples']) < 2) {
            $affectedSellers[$userId]['examples'][] = "ID {$e->id}: Business $businessDate vs UTC $utcDate";
        }
    }
}

echo "Sellers seeing the '18 vs 19' (or similar) date mismatch today:\n";
if (empty($affectedSellers)) {
    echo "No sellers affected by date mismatch found in recent records.\n";
}

foreach($affectedSellers as $id => $data) {
    echo "User ID: $id | Name: {$data['name']} | Count: {$data['count']}\n";
    foreach($data['examples'] as $ex) {
        echo "  - $ex\n";
    }
}
