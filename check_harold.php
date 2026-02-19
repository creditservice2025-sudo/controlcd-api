<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\DB;

$u = User::where('name', 'like', '%Harold3%')->first();
if($u) {
    echo "User Harold3 found: ID {$u->id}\n";
} else {
    echo "User Harold3 not found\n";
}

echo "\nLatest Audit Logs (last 10):\n";
$audits = DB::table('liquidation_audits')->orderBy('id', 'desc')->limit(10)->get();
foreach($audits as $a) {
    echo "ID: {$a->id} | User: {$a->user_id} | Action: {$a->action} | Created: {$a->created_at}\n";
    echo "  Changes: {$a->changes}\n";
}
