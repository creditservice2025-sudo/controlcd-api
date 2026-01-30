<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Seller;

$inactives = Seller::with('user')
    ->where('status', 'INACTIVE')
    ->get();

echo "=== VENDEDORES MARCADOS COMO INACTIVOS ===\n\n";

if ($inactives->count() > 0) {
    foreach ($inactives as $s) {
        echo "- " . ($s->user->name ?? 'N/A') . " (ID Vendedor: {$s->id}) | Status: {$s->status}\n";
    }
} else {
    echo "No hay vendedores marcados como INACTIVE.\n";
}

echo "\n--- TOTAL: " . $inactives->count() . " ---\n";
