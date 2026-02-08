<?php

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Running Permission Update Script...\n";

try {
    DB::beginTransaction();

    // 1. Create Permission
    $permissionName = 'realizar_carga_masiva';
    $permission = Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'api']);
    echo "Permission '{$permissionName}' ensured.\n";

    // 2. Assign to Super-Admin
    $superAdmin = Role::where('name', 'Super-Admin')->first();
    if ($superAdmin) {
        $superAdmin->givePermissionTo($permission);
        echo "Assigned to Super-Admin.\n";
    } else {
        echo "WARNING: Super-Admin role not found.\n";
    }

    // 3. Assign to Admin
    $admin = Role::where('name', 'Admin')->first();
    if ($admin) {
        $admin->givePermissionTo($permission);
        echo "Assigned to Admin.\n";
    } else {
        echo "WARNING: Admin role not found.\n";
    }

    DB::commit();
    echo "SUCCESS: Permissions updated successfully.\n";

} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
