<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\Role;

class RoleService
{
    use ApiResponse;

    public function getAllRoles($search = '', $perPage = 10)
    {
        $roles = Role::select('id','name', 'guard_name')
            ->where('name', 'like', "%$search%")
            ->paginate($perPage);
        return $this->successResponse([
            'success' => true,
            'data' => $roles
        ]);
    }

    public function getRoleById($id)
    {
        return Role::findOrFail($id);
    }

    public function createRole($data)
    {
        return Role::create($data);
    }

    public function updateRole($id, $data)
    {
        $role = Role::findOrFail($id);
        $role->update($data);
        return $role;
    }

    public function deleteRole($id)
    {
        $role = Role::findOrFail($id);
        $role->delete();
        return $role;
    }
}
