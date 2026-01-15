<?php
use App\Models\Expense;
use App\Services\ExpenseService;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

$expenseId = 1087; // Let's use 1087 which I saw in the list
$expense = Expense::find($expenseId);

if (!$expense) {
    echo "Expense $expenseId not found\n";
    exit;
}

echo "Testing delete for Expense ID: $expenseId\n";
echo "Description: " . $expense->description . "\n";
echo "Value: " . $expense->value . "\n";

// We need to be authenticated as an admin or owner
$user = User::find($expense->user_id);
Auth::guard('api')->setUser($user);

try {
    $service = app(ExpenseService::class);
    $response = $service->delete($expenseId);
    echo "Response:\n";
    echo json_encode($response->getData(), JSON_PRETTY_PRINT) . "\n";
} catch (\Exception $e) {
    echo "Caught Exception: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
