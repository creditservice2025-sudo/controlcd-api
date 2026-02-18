<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Seller;

$s = Seller::find(52);
if ($s) {
    echo "Seller ID: 52 | User ID: " . $s->user_id . " | Name: " . ($s->user ? $s->user->name : 'N/A') . "\n";
} else {
    echo "Seller 52 not found\n";
}
