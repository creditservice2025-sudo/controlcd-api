<?php
use App\Models\Income;
use App\Models\Seller;
use App\Models\User;
use App\Models\Liquidation;
use App\Services\IncomeService;
use Illuminate\Support\Facades\Auth;

// Mock user
$admin = User::find(1); // Admin
Auth::login($admin);

$pabloUserId = 23;
$pabloSellerId = 16;

// Create a liquidation for today if it doesn't exist
$today = now()->format('Y-m-d');
$liquidation = Liquidation::where('seller_id', $pabloSellerId)->where('date', $today)->first();
if (!$liquidation) {
    $liquidation = Liquidation::create([
        'seller_id' => $pabloSellerId,
        'date' => $today,
        'status' => 'En curso',
        'initial_cash' => 1000,
        'real_to_deliver' => 1000,
        'collection_target' => 3000
    ]);
}

$income = Income::create([
    'value' => 150.00,
    'description' => 'Test Income Audit ' . time(),
    'user_id' => $pabloUserId,
    'created_at' => now(),
]);

print "Created Income #{$income->id} for Seller #{$pabloSellerId}\n";

$service = app(IncomeService::class);
$response = $service->delete($income->id);

print "Delete Response: " . json_encode($response->getData()) . "\n";

$audit = \App\Models\LiquidationAudit::where('action', 'deleted_income')
    ->where('changes->income_id', $income->id)
    ->first();

if ($audit) {
    print "Audit found!\n";
    print "Changes: " . json_encode($audit->changes, JSON_PRETTY_PRINT) . "\n";
} else {
    print "Audit NOT found.\n";
}
