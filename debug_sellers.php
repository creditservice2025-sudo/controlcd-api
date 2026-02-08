<?php

use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Roles ===\n";
$roles = Role::all();
foreach ($roles as $role) {
    echo "ID: {$role->id}, Name: {$role->name}\n";
}

echo "\n=== Testing 'cobrador' Role Loopup ===\n";
$cobradorRoleId = Role::where('name', 'cobrador')->value('id');
echo "Cobrador Role ID from DB: " . ($cobradorRoleId ?? 'NOT FOUND') . "\n";

if ($cobradorRoleId) {
    $vendors = User::where('role_id', $cobradorRoleId)->get();
    echo "Found " . $vendors->count() . " users with role_id $cobradorRoleId.\n";
    foreach ($vendors as $v) {
        echo "- [{$v->id}] {$v->name} ({$v->email})\n";
    }
} else {
    echo "Checking for 'Vendedor' role instead...\n";
    $vendedorRole = Role::where('name', 'like', '%vendedor%')->first();
    if ($vendedorRole) {
         echo "Found alternative role: {$vendedorRole->name} (ID: {$vendedorRole->id})\n";
         $vendors = User::where('role_id', $vendedorRole->id)->get();
         echo "Users with this role: " . $vendors->count() . "\n";
    }
}
