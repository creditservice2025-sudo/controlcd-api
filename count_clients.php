<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$seller_id = 16;
$orders = App\Models\Client::where('status', 'active')
    ->where('seller_id', $seller_id)
    ->pluck('routing_order');

$uniqueCount = $orders->unique()->count();
$totalCount = $orders->count();
$nullCount = $orders->filter(fn($v) => is_null($v))->count();

echo "Seller 16:\n";
echo "Total Active: $totalCount\n";
echo "Unique routing_orders: $uniqueCount\n";
echo "NULL routing_orders: $nullCount\n";
if ($totalCount > 0) {
    echo "Sample orders: " . $orders->take(10)->implode(', ') . "\n";
}
