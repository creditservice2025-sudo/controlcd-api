<?php
use App\Models\User;
use App\Models\Seller;

echo "--- SELLERS TABLE (Direct IDs) ---\n";
$s18 = Seller::find(18);
if ($s18) {
    echo "Seller ID 18 -> User ID: " . $s18->user_id . " | User Name: " . ($s18->user->name ?? 'N/A') . "\n";
} else {
    echo "Seller ID 18 NOT FOUND\n";
}

$s25 = Seller::find(25);
if ($s25) {
    echo "Seller ID 25 -> User ID: " . $s25->user_id . " | User Name: " . ($s25->user->name ?? 'N/A') . "\n";
} else {
    echo "Seller ID 25 NOT FOUND\n";
}

echo "\n--- USERS BY NAME (Alejandra3) ---\n";
$users = User::where('name', 'like', '%Alejandra3%')->get();
foreach ($users as $u) {
    echo "User ID: " . $u->id . " | Name: " . $u->name . "\n";
    $seller = Seller::where('user_id', $u->id)->first();
    if ($seller) {
        echo "  -> Assigned Seller ID: " . $seller->id . "\n";
    }
}
