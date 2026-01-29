<?php
use App\Models\User;
use App\Models\Seller;

$users = User::where('name', 'like', '%Alejandra3%')->get();
echo "Users found: " . $users->count() . "\n";
foreach ($users as $u) {
    echo "User ID: " . $u->id . " | Name: " . $u->name . "\n";
    $seller = Seller::where('user_id', $u->id)->first();
    if ($seller) {
        echo "  -> Seller ID: " . $seller->id . "\n";
    } else {
        echo "  -> (No seller record)\n";
    }
}
