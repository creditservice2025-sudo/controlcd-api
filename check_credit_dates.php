<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$c = App\Models\Credit::find(2252);
echo "ID: " . $c->id . "\n";
echo "Start Date: " . ($c->start_date ?? 'NULL') . "\n";
echo "Created At: " . $c->created_at . "\n";
echo "Micro insurance: " . ($c->micro_insurance_percentage ?? 0) . "%\n";
echo "Credit Value: " . $c->credit_value . "\n";
