<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Seller;
use App\Models\Liquidation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

try {
    // Try yesterday, or force a known date from logs log like 2026-02-12
    $targetDay = Carbon::yesterday()->format('Y-m-d'); 
    echo "Testing date: $targetDay\n";

    $routes = Seller::with([
        'liquidations' => function ($q) use ($targetDay) {
            $q->whereDate('date', $targetDay)
              ->with(['audits' => function ($q) {
                  $q->whereNull('deleted_at')->orderByDesc('created_at')->with('user');
              }]);
        }
    ])
    // Remove limit to find *any* route with liquidation
    ->get();

    echo "Fetched " . $routes->count() . " routes.\n";

    $found = 0;
    foreach ($routes as $route) {
        $liquidationToday = $route->liquidations->first();
        
        if ($liquidationToday) {
            $found++;
            echo "Route " . $route->id . " has liquidation " . $liquidationToday->id . "\n";
            echo "  Type of liquidationToday: " . get_class($liquidationToday) . "\n";
            
            // This is the suspect line:
            $audits = $liquidationToday->audits;
            echo "  Accessing audits property... Type: " . get_class($audits) . "\n";
            
            $lastAudit = $audits->where('user_id', $route->user_id)->first();
            echo "  Last audit found: " . ($lastAudit ? $lastAudit->id : 'None') . "\n";
            
            if ($found > 2) break;
        }
    }
    
    if ($found === 0) {
        echo "No liquidations found for $targetDay. Try another date.\n";
    }
    
    echo "Test completed successfully.\n";

} catch (\Exception $e) {
    echo "ERROR caught: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
