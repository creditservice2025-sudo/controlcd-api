<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionController extends Controller
{
    public function assignPermissions(Request $request, $roleId)
    {
        $role = Role::findOrFail($roleId);
        $permissions = $request->input('permissions', []);

        // Valida que los permisos existan
        $validPermissions = Permission::whereIn('name', $permissions)->pluck('name')->toArray();

        $role->syncPermissions($validPermissions);

        return response()->json([
            'message' => 'Permisos asignados correctamente',
            'role' => $role->name,
            'permissions' => $validPermissions,
        ]);
    }

    public function show($roleId)
    {
        $role = Role::findOrFail($roleId);
        $permissions = $role->permissions()->pluck('name');
        return response()->json([
            'role' => $role->name,
            'permissions' => $permissions,
        ]);
    }
}
