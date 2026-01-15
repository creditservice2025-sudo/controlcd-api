<?php

use App\Models\Expense;
use App\Services\ExpenseService;
use Illuminate\Http\Request;

// Get a test expense
$expense = Expense::orderBy('created_at', 'desc')->first();

if (!$expense) {
    echo "No expenses found in database\n";
    exit;
}

echo "Testing simulateDelete for Expense ID: {$expense->id}\n";
echo "Description: {$expense->description}\n";
echo "Value: {$expense->value}\n";
echo "Created at: {$expense->created_at}\n\n";

// Create a mock request
$request = new Request();

// Call the service method
$service = new ExpenseService();

try {
    $response = $service->simulateDelete($expense->id);
    
    // Get the response content
    $content = $response->getContent();
    $data = json_decode($content, true);
    
    echo "Response:\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}
