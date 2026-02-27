<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Client;
use App\Models\Credit;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();
try {
    $clients = Client::all();
    $totalFixed = 0;
    foreach ($clients as $client) {
        $credits = Credit::where('client_id', $client->id)
            ->orderBy('id', 'asc')
            ->get();
        
        if ($credits->count() > 0) {
            $firstId = $credits[0]->id;
            
            // Set first one to true
            Credit::where('id', $firstId)->update(['is_initial_credit' => true]);
            
            // Set all others to false
            $others = Credit::where('client_id', $client->id)
                ->where('id', '>', $firstId)
                ->where('is_initial_credit', true)
                ->update(['is_initial_credit' => false]);
            
            if ($others > 0) {
                $totalFixed += $others;
            }
        }
    }
    DB::commit();
    echo "Corrected $totalFixed credits that were incorrectly marked as initial.\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
