<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionUserProfile extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_user_profiles';

    protected $fillable = [
        'user_id',
        'company_id',
        'role',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    /**
     * Roles disponibles con sus permisos por defecto.
     */
    public const ROLES = [
        'admin' => [
            'label' => 'Administrador',
            'description' => 'Acceso total al modulo',
            'color' => 'blue',
            'permissions' => ['*'],
        ],
        'manager' => [
            'label' => 'Gerente',
            'description' => 'Todo excepto configuracion del sistema',
            'color' => 'purple',
            'permissions' => [
                'dashboard.view',
                'wallet.view', 'wallet.inject',
                'clients.view', 'clients.create', 'clients.edit', 'clients.delete',
                // credits.delete = anular un crédito del día (no borra: marca y
                // reintegra caja). Va con el gerente, como clients.delete.
                'credits.view', 'credits.create', 'credits.delete',
                'payments.view', 'payments.create',
                'expenses.view', 'expenses.create',
                'users.view',
                'reports.view', 'reports.export',
            ],
        ],
        'analyst' => [
            'label' => 'Analista',
            'description' => 'Solo lectura: dashboard, reportes y cartera',
            'color' => 'teal',
            'permissions' => [
                'dashboard.view',
                'wallet.view',
                'clients.view',
                'credits.view',
                'payments.view',
                'expenses.view',
                'reports.view', 'reports.export',
            ],
        ],
        'collector' => [
            'label' => 'Cobrador',
            'description' => 'Registrar cobros, gastos y gestionar clientes',
            'color' => 'green',
            'permissions' => [
                'dashboard.view',
                'wallet.view',
                'clients.view', 'clients.create', 'clients.edit',
                'credits.view',
                'payments.view', 'payments.create',
                'expenses.view', 'expenses.create',
            ],
        ],
    ];

    /**
     * Verifica si este perfil tiene un permiso específico.
     */
    public function hasPermission(string $permission): bool
    {
        $perms = $this->permissions ?: [];
        if (in_array('*', $perms)) return true;
        return in_array($permission, $perms);
    }

    public function getRoleInfo(): array
    {
        return self::ROLES[$this->role] ?? self::ROLES['collector'];
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
