<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

echo "--- Permission Check ---\n";

$cobradorRole = Role::where('name', 'Cobrador')->first();
if ($cobradorRole) {
    echo "Role 'Cobrador' exists. ID: " . $cobradorRole->id . "\n";
    echo "Permissions: " . $cobradorRole->permissions->pluck('name')->implode(', ') . "\n";
} else {
    echo "Role 'Cobrador' NOT found!\n";
}

// Find a user with role_id 5
$user = User::where('role_id', 5)->first();
if ($user) {
    echo "User found: " . $user->name . " (ID: " . $user->id . ", role_id: " . $user->role_id . ")\n";
    echo "Spatie Roles: " . $user->getRoleNames()->implode(', ') . "\n";
    echo "Has 'crear_pagos' per Spatie: " . ($user->hasPermissionTo('crear_pagos') ? 'YES' : 'NO') . "\n";
} else {
    echo "No user with role_id 5 found.\n";
}

$allRoles = Role::all()->pluck('name', 'id');
echo "All Roles in DB: " . json_encode($allRoles) . "\n";
