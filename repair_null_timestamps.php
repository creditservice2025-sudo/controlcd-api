<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Expense;

$ids = [31, 32];
echo "Repairing Expenses 31 and 32...\n";

foreach ($ids as $id) {
    $expense = Expense::find($id);
    if ($expense && is_null($expense->created_at)) {
        $expense->created_at = $expense->updated_at;
        $expense->save(['timestamps' => false]); // Evitar que updated_at cambie de nuevo
        echo "Successfully repaired Expense ID: $id | New Created At: {$expense->created_at}\n";
    } else {
        echo "Expense ID: $id not found or already has created_at.\n";
    }
}
