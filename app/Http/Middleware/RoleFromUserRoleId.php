<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\UnauthorizedException;

/**
 * Autoriza por rol mirando `users.role_id`, no el pivote de Spatie.
 *
 * POR QUÉ EXISTE. La aplicación NUNCA escribe `model_has_roles`: no hay una
 * sola llamada a assignRole() ni a syncRoles() en todo el código. El rol se
 * guarda en `users.role_id`, que apunta a la MISMA tabla `roles`. Las filas del
 * pivote que existen vienen de seeders o de trabajo manual sobre la base, así
 * que todo usuario creado desde la aplicación queda sin rol de Spatie.
 *
 * El resto del sistema ya autoriza por `role_id`: el Gate::before de
 * AppServiceProvider le concede todo a los role_id 1 y 2, y por eso el
 * middleware `permission:` —que pasa por el Gate— funciona para ellos. El
 * middleware `role:` de Spatie es el único que lee el pivote directo, y por eso
 * devolvía 403 "User does not have the right roles" a 8 administradores activos
 * que sí son Admin en `users.role_id`.
 *
 * Se conserva el chequeo de Spatie como segunda vuelta: si alguien tiene el rol
 * por pivote y no por `role_id`, sigue pasando. Nadie pierde acceso con esto.
 */
class RoleFromUserRoleId
{
    /**
     * Cache por request del nombre de rol de cada role_id, para no repetir la
     * consulta cuando hay middlewares encadenados.
     *
     * @var array<int, string|null>
     */
    private static array $nombrePorRoleId = [];

    public function handle(Request $request, Closure $next, string $roles, ?string $guard = null)
    {
        $user = $guard ? Auth::guard($guard)->user() : Auth::user();

        if (!$user) {
            throw UnauthorizedException::notLoggedIn();
        }

        $permitidos = array_filter(array_map('trim', explode('|', $roles)));

        $nombre = $this->nombreDelRol($user->role_id ?? null);
        if ($nombre !== null && in_array($nombre, $permitidos, true)) {
            return $next($request);
        }

        // Compatibilidad con los usuarios que sí tienen el pivote cargado.
        try {
            if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($permitidos)) {
                return $next($request);
            }
        } catch (\Throwable $e) {
            // El usuario no usa el trait de Spatie: se decide solo por role_id.
        }

        throw UnauthorizedException::forRoles($permitidos);
    }

    private function nombreDelRol(?int $roleId): ?string
    {
        if ($roleId === null) {
            return null;
        }

        if (!array_key_exists($roleId, self::$nombrePorRoleId)) {
            self::$nombrePorRoleId[$roleId] = DB::table('roles')->where('id', $roleId)->value('name');
        }

        return self::$nombrePorRoleId[$roleId];
    }
}
