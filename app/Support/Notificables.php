<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A quién le corresponde enterarse de lo que hace un vendedor.
 *
 * La regla del negocio es una sola: el Super-Admin (rol 1) ve TODO, y el Admin
 * (rol 2) solo lo de SU empresa. Estaba escrita correctamente en el aviso de
 * faltante/sobrante (LiquidationController) y faltaba en los otros cuatro
 * avisos, que notificaban únicamente al rol 1 —el admin de la empresa nunca se
 * enteraba de que su cobrador no había cerrado la liquidación, que es
 * justamente quien tiene que llamarlo—.
 *
 * Vive acá y no copiada en cada comando para que cambiarla sea tocar un solo
 * lugar.
 */
class Notificables
{
    /**
     * Memoria por empresa dentro de la misma corrida.
     *
     * Los comandos recorren los 177 vendedores de a uno; sin esto se repetiría
     * la misma consulta de administradores una vez por vendedor.
     *
     * @var array<string, Collection>
     */
    private static array $memoria = [];

    /**
     * Administradores que deben recibir un aviso sobre un vendedor de esta
     * empresa: el Super-Admin siempre, y el Admin de esa empresa.
     *
     * @param int|null $companyId Empresa del vendedor. Con null solo devuelve
     *                            los Super-Admin: sin empresa no hay a qué
     *                            admin atribuirlo, y mandárselo a todos sería
     *                            filtrar datos de una empresa a otra.
     */
    public static function adminsDe($companyId): Collection
    {
        $clave = $companyId === null ? 'sin-empresa' : (string) (int) $companyId;

        if (isset(self::$memoria[$clave])) {
            return self::$memoria[$clave];
        }

        $query = User::whereNull('deleted_at')
            ->where(function ($q) use ($companyId) {
                $q->where('role_id', 1);

                if ($companyId !== null) {
                    $q->orWhere(function ($admin) use ($companyId) {
                        $admin->where('role_id', 2)
                            ->whereHas('company', function ($c) use ($companyId) {
                                $c->where('id', $companyId);
                            });
                    });
                }
            });

        return self::$memoria[$clave] = $query->get();
    }

    /** Vacía la memoria. Para los tests, que crean usuarios entre llamadas. */
    public static function olvidar(): void
    {
        self::$memoria = [];
    }
}
