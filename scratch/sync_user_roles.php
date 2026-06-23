<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

use App\Models\User;
use Spatie\Permission\Models\Role;

echo "--- User Role Sync ---\n";

$users = User::whereNotNull('role_id')->get();
$count = 0;

foreach ($users as $user) {
    $role = Role::find($user->role_id);
    if ($role) {
        if (!$user->hasRole($role)) {
            $user->assignRole($role);
            echo "Assigned '{$role->name}' to user '{$user->name}' (ID: {$user->id})\n";
            $count++;
        }
    }
}

echo "Sync complete. Total users updated: $count\n";

// Clear Spatie cache again just in case
app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
