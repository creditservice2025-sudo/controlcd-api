<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\Role;
use Illuminate\Support\Facades\Schema;

class RoleService
{
    use ApiResponse;

    public function getAllRoles($search = '', $perPage = 100)
    {
        // Spatie ordena roles alfabéticamente por defecto. Con perPage=10
        // y 11+ roles, los que caen al final del alfabeto (ej. "Supervisor")
        // quedaban en página 2 y el frontend solo pedía página 1 → no aparecían
        // en el dropdown. Subimos el default a 100 (los roles son un set
        // fijo y pequeño; la paginación real solo se usa si search filtra).
        //
        // `is_assignable` se incluye solo si la columna existe: así el endpoint
        // sigue respondiendo aunque el código se despliegue antes de correr la
        // migración 2026_06_09_120000_add_is_assignable_to_roles.
        $columns = ['id', 'name', 'guard_name'];
        if (Schema::hasColumn('roles', 'is_assignable')) {
            $columns[] = 'is_assignable';
        }
        $roles = Role::select($columns)
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
