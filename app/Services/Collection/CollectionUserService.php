<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionUserProfile;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CollectionUserService
{
    use ApiResponse;

    public function getCompanyUsers(int $companyId)
    {
        // Solo usuarios del modulo Deuda & Abono:
        // 1. El administrador dueño de la empresa (Company->user_id)
        // 2. Usuarios creados explicitamente para el modulo (perfil Collection en PG)
        //
        // NO se listan vendedores/cobradores de Control CD.
        $profileUserIds = CollectionUserProfile::where('company_id', $companyId)
            ->pluck('user_id')
            ->toArray();

        $allUsers = User::where(function ($q) use ($companyId, $profileUserIds) {
            $q->whereHas('company', fn($c) => $c->where('id', $companyId))
              ->orWhereIn('id', $profileUserIds);
        })->orderBy('name')->get();

        if ($allUsers->isEmpty()) {
            return $this->successResponse(['users' => [], 'roles' => CollectionUserProfile::ROLES]);
        }

        $userIds = $allUsers->pluck('id')->toArray();

        // Perfiles Collection desde PG
        $profiles = CollectionUserProfile::where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        // Stats desde PG
        $expenseStats = DB::connection('collection_pgsql')
            ->table('collection_expenses')
            ->whereIn('user_id', $userIds)->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->select('user_id')
            ->selectRaw('COUNT(*) as expenses_count')
            ->selectRaw('MAX(recorded_at) as last_expense_at')
            ->groupBy('user_id')->get()->keyBy('user_id');

        $ledgerStats = DB::connection('collection_pgsql')
            ->table('collection_ledger')
            ->whereIn('user_id', $userIds)->where('company_id', $companyId)
            ->select('user_id')
            ->selectRaw('COUNT(*) as operations_count')
            ->selectRaw('MAX(created_at) as last_ledger_at')
            ->groupBy('user_id')->get()->keyBy('user_id');

        $enriched = $allUsers->map(function ($user) use ($profiles, $expenseStats, $ledgerStats) {
            $profile = $profiles[$user->id] ?? null;
            $es = $expenseStats[$user->id] ?? null;
            $ls = $ledgerStats[$user->id] ?? null;
            $isAdmin = in_array((int) $user->role_id, [1, 2]);

            $role = 'collector';
            $roleInfo = CollectionUserProfile::ROLES['collector'];
            if ($isAdmin) {
                $role = 'admin';
                $roleInfo = CollectionUserProfile::ROLES['admin'];
            } elseif ($profile) {
                $role = $profile->role;
                $roleInfo = $profile->getRoleInfo();
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role_id' => $user->role_id,
                'status' => $user->status,
                'is_collection_user' => (bool) $user->is_collection_user || $isAdmin,
                'collection_role' => $role,
                'collection_role_label' => $roleInfo['label'],
                'collection_role_color' => $roleInfo['color'],
                'permissions' => $profile ? $profile->permissions : ($isAdmin ? ['*'] : []),
                'stats' => [
                    'operations_count' => (int) ($ls->operations_count ?? 0),
                    'expenses_count' => (int) ($es->expenses_count ?? 0),
                    'last_activity' => max($es->last_expense_at ?? null, $ls->last_ledger_at ?? null) ?: null,
                ],
            ];
        });

        return $this->successResponse([
            'users' => $enriched,
            'roles' => CollectionUserProfile::ROLES,
        ]);
    }

    /**
     * Crear un usuario para el módulo Collection.
     */
    public function createUser(array $data, int $companyId, int $creatorId)
    {
        $exists = User::where('email', $data['email'])->first();
        if ($exists) {
            return $this->errorResponse('Ya existe un usuario con ese correo', 422);
        }

        $role = $data['role'] ?? 'collector';
        if (!isset(CollectionUserProfile::ROLES[$role])) {
            return $this->errorResponse('Rol no válido', 422);
        }

        // Crear usuario en MySQL con clave temporal (debe cambiarla al primer login)
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'dni' => $data['dni'] ?? 'COL-' . time(),
            'phone' => $data['phone'] ?? null,
            'role_id' => 5,
            'parent_id' => $creatorId,
            'status' => 'active',
            'is_collection_user' => true,
            'must_change_password' => true,
        ]);

        // Crear perfil Collection en PG
        $permissions = CollectionUserProfile::ROLES[$role]['permissions'];
        CollectionUserProfile::create([
            'user_id' => $user->id,
            'company_id' => $companyId,
            'role' => $role,
            'permissions' => $permissions,
        ]);

        return $this->successCreatedResponse([
            'message' => 'Usuario creado exitosamente',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'collection_role' => $role,
            ],
        ]);
    }

    /**
     * Cambiar rol Collection de un usuario.
     */
    public function updateRole(int $userId, int $companyId, string $newRole)
    {
        if (!isset(CollectionUserProfile::ROLES[$newRole])) {
            return $this->errorResponse('Rol no válido', 422);
        }

        $user = $this->findCompanyUser($userId, $companyId);
        if (!$user) return $this->errorResponse('Usuario no encontrado', 404);

        if (in_array((int) $user->role_id, [1, 2])) {
            return $this->errorResponse('No se puede cambiar el rol de un administrador', 422);
        }

        $profile = CollectionUserProfile::updateOrCreate(
            ['user_id' => $userId, 'company_id' => $companyId],
            ['role' => $newRole, 'permissions' => CollectionUserProfile::ROLES[$newRole]['permissions']]
        );

        // Asegurar acceso al módulo
        if (!$user->is_collection_user) {
            $user->is_collection_user = true;
            $user->save();
        }

        return $this->successResponse([
            'message' => 'Rol actualizado a ' . CollectionUserProfile::ROLES[$newRole]['label'],
            'user_id' => $userId,
            'role' => $newRole,
        ]);
    }

    /**
     * Actualizar permisos granulares del perfil Collection de un usuario.
     * Reemplaza los permisos actuales con la lista nueva.
     */
    public function updatePermissions(int $userId, int $companyId, array $permissions)
    {
        $user = $this->findCompanyUser($userId, $companyId);
        if (!$user) return $this->errorResponse('Usuario no encontrado', 404);

        if (in_array((int) $user->role_id, [1, 2])) {
            return $this->errorResponse('Los administradores siempre tienen acceso total', 422);
        }

        $profile = CollectionUserProfile::where('user_id', $userId)
            ->where('company_id', $companyId)
            ->first();

        if (!$profile) {
            return $this->errorResponse('Usuario sin perfil Collection. Asignale un rol primero.', 422);
        }

        // Filtrar solo permisos validos
        $validPerms = array_values(array_intersect($permissions, self::ALL_PERMISSIONS));

        $profile->permissions = $validPerms;
        $profile->save();

        if (!$user->is_collection_user) {
            $user->is_collection_user = true;
            $user->save();
        }

        return $this->successResponse([
            'message' => 'Permisos actualizados',
            'user_id' => $userId,
            'permissions' => $validPerms,
        ]);
    }

    /**
     * Lista maestra de todos los permisos disponibles en el modulo.
     */
    public const ALL_PERMISSIONS = [
        'dashboard.view',
        'wallet.view', 'wallet.inject',
        'clients.view', 'clients.create', 'clients.edit', 'clients.delete',
        'credits.view', 'credits.create', 'credits.delete',
        'payments.view', 'payments.create',
        'expenses.view', 'expenses.create',
        'reports.view', 'reports.export',
        'users.view', 'users.manage',
        'config.view', 'config.edit',
    ];

    public function toggleAccess(int $userId, int $companyId)
    {
        $user = $this->findCompanyUser($userId, $companyId);
        if (!$user) return $this->errorResponse('Usuario no encontrado', 404);

        if (in_array((int) $user->role_id, [1, 2])) {
            return $this->errorResponse('Los administradores siempre tienen acceso', 422);
        }

        $user->is_collection_user = !$user->is_collection_user;
        $user->save();

        return $this->successResponse([
            'message' => $user->is_collection_user ? 'Acceso habilitado' : 'Acceso removido',
            'user_id' => $user->id,
            'is_collection_user' => $user->is_collection_user,
        ]);
    }

    public function getUserActivity(int $userId, int $companyId)
    {
        $ledger = DB::connection('collection_pgsql')
            ->table('collection_ledger')
            ->where('user_id', $userId)->where('company_id', $companyId)
            ->orderByDesc('created_at')->limit(20)
            ->get(['id', 'type', 'action_type', 'amount', 'balance_after', 'description', 'created_at']);

        $expenses = DB::connection('collection_pgsql')
            ->table('collection_expenses')
            ->where('user_id', $userId)->where('company_id', $companyId)
            ->whereNull('deleted_at')->orderByDesc('recorded_at')->limit(20)
            ->get(['id', 'amount', 'category', 'description', 'recorded_at']);

        return $this->successResponse(['ledger' => $ledger, 'expenses' => $expenses]);
    }

    /**
     * Resetear clave temporal con caducidad de 24 horas.
     */
    public function resetPassword(int $userId, int $companyId)
    {
        $user = $this->findCompanyUser($userId, $companyId);
        if (!$user) return $this->errorResponse('Usuario no encontrado', 404);

        if (in_array((int) $user->role_id, [1, 2])) {
            return $this->errorResponse('No se puede resetear la clave de un administrador', 422);
        }

        // Generar clave temporal
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $tempPassword = '';
        for ($i = 0; $i < 8; $i++) {
            $tempPassword .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $user->password = Hash::make($tempPassword);
        $user->must_change_password = true;
        $user->save();

        return $this->successResponse([
            'message' => 'Clave temporal generada (expira en 24 horas)',
            'temp_password' => $tempPassword,
            'expires_at' => now()->addHours(24)->toIso8601String(),
            'user_id' => $userId,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'user_phone' => $user->phone,
        ]);
    }

    private function findCompanyUser(int $userId, int $companyId): ?User
    {
        $profileUserIds = CollectionUserProfile::where('company_id', $companyId)
            ->pluck('user_id')->toArray();

        return User::where('id', $userId)
            ->where(function ($q) use ($companyId, $profileUserIds) {
                $q->whereHas('company', fn($c) => $c->where('id', $companyId))
                  ->orWhereHas('seller', fn($s) => $s->where('company_id', $companyId))
                  ->orWhereIn('id', $profileUserIds);
            })->first();
    }
}
