<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Expense;
use Illuminate\Support\Facades\DB;

$exp = Expense::where('value', 500000)->whereNull('deleted_at')->first();
if ($exp) {
    echo "Expense ID: " . $exp->id . "\n";
    echo "Value: " . $exp->value . "\n";
    echo "Description: " . ($exp->description ?? 'N/A') . "\n";
    echo "Date: " . $exp->business_date . "\n";
    echo "Created At: " . $exp->created_at . "\n";
    echo "User ID: " . $exp->user_id . "\n";
    
    $user = DB::table('users')->where('id', $exp->user_id)->first();
    echo "User Name: " . ($user ? $user->name : 'N/A') . "\n";
    
    $seller = DB::table('sellers')->where('user_id', $exp->user_id)->first();
    echo "Seller Deleted At: " . ($seller ? $seller->deleted_at : 'N/A') . "\n";
} else {
    echo "No expense found with value 500,000\n";
}
