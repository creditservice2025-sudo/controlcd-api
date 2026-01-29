<?php
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$sellerId = 18; // Alejandra3 confirmed
echo "--- IDENTITY CHECK ---\n";
$seller = Seller::with('user')->find($sellerId);
echo "Seller ID 18 -> User: " . ($seller->user->name ?? 'N/A') . " (User ID: " . ($seller->user_id ?? 'N/A') . ")\n";

$seller25 = Seller::with('user')->find(25);
echo "Seller ID 25 -> User: " . ($seller25->user->name ?? 'N/A') . " (User ID: " . ($seller25->user_id ?? 'N/A') . ")\n";

echo "\n--- RAW DB CHECK FOR SELLER 18 (2026-01-27) ---\n";
$rawLiqs = DB::table('liquidations')->where('seller_id', 18)->whereDate('date', '2026-01-27')->get();
foreach ($rawLiqs as $l) {
    echo "ID: {$l->id} | Status: '{$l->status}' | End Date: '{$l->end_date}' | Deleted: '{$l->deleted_at}'\n";
}

echo "\n--- RAW DB CHECK FOR SELLER 18 (All before 2026-01-29) ---\n";
$all = DB::table('liquidations')->where('seller_id', 18)->where('date', '<', '2026-01-29')->orderBy('date', 'asc')->get();
foreach ($all as $l) {
    echo "Date: {$l->date} | ID: {$l->id} | Status: '{$l->status}'\n";
}

echo "\n--- QUERY SIMULATION (Seller 18, Status != 'approved') ---\n";
$pending = DB::table('liquidations')
    ->where('seller_id', 18)
    ->where('date', '<', '2026-01-29')
    ->where('status', '!=', 'approved')
    ->whereNull('deleted_at')
    ->orderBy('date', 'asc')
    ->first();

if ($pending) {
    echo "FOUND PENDING: Date: {$pending->date} | ID: {$pending->id} | Status: '{$pending->status}'\n";
} else {
    echo "NO PENDING FOUND IN DB.\n";
}
