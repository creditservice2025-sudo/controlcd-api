<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "DB Connection: " . DB::connection()->getDatabaseName() . "\n";

$ids = [51429, 51407];

foreach ($ids as $id) {
    // Try normal find
    $p = App\Models\Payment::find($id);
    if (!$p) {
        // Try withTrashed
        $p = App\Models\Payment::withTrashed()->find($id);
        if ($p) {
            echo "Payment ID $id FOUND (DELETED).\n";
        } else {
            echo "Payment ID $id NOT FOUND (Checked normal and trash).\n";
            continue;
        }
    } else {
        echo "Payment ID $id FOUND (Active).\n";
    }

    echo "  CreatedAt Raw:    " . ($p->getAttributes()['created_at'] ?? 'NULL') . "\n";
    echo "  BusinessTS Raw:   " . ($p->getAttributes()['business_timestamp'] ?? 'NULL') . "\n";
    echo "----------------------------------\n";
}
