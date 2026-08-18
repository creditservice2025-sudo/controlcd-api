<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * El middleware `role:` autoriza por users.role_id.
 *
 * El sistema guarda el rol en `users.role_id` y NUNCA escribe el pivote de
 * Spatie: no hay una llamada a assignRole() en todo el código. Con el
 * middleware de Spatie, 8 administradores activos —Admin en users.role_id, sin
 * fila en model_has_roles— recibían 403 "User does not have the right roles" al
 * intentar bloquear nuevos créditos, marcar cartera irrecuperable o tocar
 * feriados.
 *
 * Lo que se prueba acá es el borde de autorización: que el Admin sin pivote
 * entre, y sobre todo que el que no es Admin SIGA sin entrar.
 */
class RoleMiddlewareByRoleIdTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:api', 'role:Super-Admin|Admin'])
            ->get('/_test/solo-admin', fn () => response()->json(['ok' => true]));

        Route::middleware(['api', 'auth:api', 'role:Super-Admin'])
            ->get('/_test/solo-super', fn () => response()->json(['ok' => true]));
    }

    private function usuarioConRol(int $roleId, bool $conPivote = false): User
    {
        $this->asegurarRol($roleId);
        $user = User::factory()->create(['role_id' => $roleId]);

        if ($conPivote) {
            DB::table('model_has_roles')->insert([
                'role_id' => $roleId,
                'model_type' => User::class,
                'model_id' => $user->id,
            ]);
        }

        return $user;
    }

    private function asegurarRol(int $id): void
    {
        $nombres = [1 => 'Super-Admin', 2 => 'Admin', 5 => 'Cobrador', 6 => 'Supervisor', 11 => 'Secretaria'];

        if (!DB::table('roles')->where('id', $id)->exists()) {
            DB::table('roles')->insert([
                'id' => $id,
                'name' => $nombres[$id] ?? ('Role-' . $id),
                'guard_name' => 'api',
                'is_assignable' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * El caso reportado: Admin de verdad, sin fila en el pivote.
     *
     * @test
     */
    public function el_admin_sin_pivote_de_spatie_entra(): void
    {
        $admin = $this->usuarioConRol(2);

        $this->assertFalse(
            DB::table('model_has_roles')->where('model_id', $admin->id)->exists(),
            'El escenario exige que NO tenga el pivote: es el del bug.'
        );

        $this->actingAs($admin, 'api')
            ->getJson('/_test/solo-admin')
            ->assertOk();
    }

    /** @test */
    public function el_admin_con_pivote_sigue_entrando(): void
    {
        $admin = $this->usuarioConRol(2, conPivote: true);

        $this->actingAs($admin, 'api')
            ->getJson('/_test/solo-admin')
            ->assertOk();
    }

    /**
     * Lo que importa de verdad: que arreglar el 403 no abra la puerta.
     *
     * @test
     */
    public function el_cobrador_sigue_sin_entrar(): void
    {
        $cobrador = $this->usuarioConRol(5);

        $this->actingAs($cobrador, 'api')
            ->getJson('/_test/solo-admin')
            ->assertForbidden();
    }

    /** @test */
    public function el_supervisor_sigue_sin_entrar(): void
    {
        $supervisor = $this->usuarioConRol(6);

        $this->actingAs($supervisor, 'api')
            ->getJson('/_test/solo-admin')
            ->assertForbidden();
    }

    /**
     * Las rutas de Super-Admin no se ablandan: un Admin no entra ahí.
     *
     * @test
     */
    public function el_admin_no_entra_a_las_rutas_de_super_admin(): void
    {
        $admin = $this->usuarioConRol(2);

        $this->actingAs($admin, 'api')
            ->getJson('/_test/solo-super')
            ->assertForbidden();

        $super = $this->usuarioConRol(1);

        $this->actingAs($super, 'api')
            ->getJson('/_test/solo-super')
            ->assertOk();
    }

    /** @test */
    public function sin_sesion_no_entra(): void
    {
        $this->getJson('/_test/solo-admin')->assertUnauthorized();
    }

    /**
     * Secretaria tiene 69 permisos, más que muchos: igual no es Admin y no
     * entra. El middleware decide por el NOMBRE del rol, no por cuánto puede.
     *
     * (Un role_id inexistente no hace falta probarlo: `users.role_id` tiene
     * clave foránea contra `roles`, así que la base no lo permite.)
     *
     * @test
     */
    public function un_rol_poderoso_que_no_es_admin_tampoco_entra(): void
    {
        $this->asegurarRol(11);
        $secretaria = User::factory()->create(['role_id' => 11]);

        $this->actingAs($secretaria, 'api')
            ->getJson('/_test/solo-admin')
            ->assertForbidden();
    }
}
