<?php

namespace Tests\Feature\Lifecycle;

use App\Http\Middleware\BlockAdminFieldOperations;
use App\Models\City;
use App\Models\Country;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REGLA: cobrar y colocar es trabajo de CAMPO.
 *
 * El Super-Admin (1) y el Admin de empresa (2) administran; no registran
 * pagos, no colocan créditos nuevos y no renuevan. Un pago cargado desde el
 * back-office entra en la caja del cobrador sin que él lo haya recibido y le
 * cierra la liquidación con un recaudo que no hizo.
 *
 * Se prueba con el 403 del endpoint, no leyendo el middleware: lo que importa
 * es que la puerta esté cerrada para quien llame, venga de la pantalla que
 * venga o de Postman.
 */
class AdminFieldOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Seller $seller;
    private User $sellerUser;

    private function ensureRole(int $id): void
    {
        if (!DB::table('roles')->where('id', $id)->exists()) {
            DB::table('roles')->insert([
                'id'            => $id,
                'name'          => 'Role-' . $id . '-' . uniqid(),
                'guard_name'    => 'web',
                'is_assignable' => 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1, 2, 5, 6] as $rol) {
            $this->ensureRole($rol);
        }

        $country = Country::factory()->create(['name' => 'Perú', 'timezone' => 'America/Lima']);
        $city = City::factory()->create(['country_id' => $country->id]);

        $this->sellerUser = User::factory()->create(['role_id' => 5]);
        $this->seller = Seller::factory()->create([
            'city_id' => $city->id,
            'user_id' => $this->sellerUser->id,
        ]);
    }

    /**
     * Las tres altas de campo, por los dos roles de back-office. El payload va
     * vacío a propósito: si el 403 llega igual, el bloqueo corre ANTES de la
     * validación y no hay forma de colarse armando bien el cuerpo.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('altasDeCampo')]
    public function test_el_back_office_no_puede_dar_de_alta_operaciones_de_campo(string $ruta, int $rol): void
    {
        $user = User::factory()->create(['role_id' => $rol]);

        $this->actingAs($user, 'api')
            ->postJson($ruta, [])
            ->assertStatus(403)
            ->assertJsonPath('code', BlockAdminFieldOperations::ADMIN_FIELD_OPERATION_BLOCKED);
    }

    public static function altasDeCampo(): array
    {
        $casos = [];

        foreach ([1 => 'superadmin', 2 => 'admin'] as $rol => $nombre) {
            $casos["pago · {$nombre}"]            = ['/api/payment/create', $rol];
            $casos["crear crédito · {$nombre}"]   = ['/api/credit/create', $rol];
            $casos["renovar crédito · {$nombre}"] = ['/api/credit/renew', $rol];
        }

        return $casos;
    }

    /**
     * La contracara: los roles de campo NO tocan este muro. No se afirma que
     * la operación termine bien (eso depende del payload), sino que el rechazo
     * —si lo hay— viene de otra regla, no de esta.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rolesDeCampo')]
    public function test_los_roles_de_campo_no_quedan_bloqueados_por_esta_regla(int $rol): void
    {
        $user = $rol === 5
            ? $this->sellerUser
            : User::factory()->create(['role_id' => $rol]);

        foreach (['/api/payment/create', '/api/credit/create', '/api/credit/renew'] as $ruta) {
            $respuesta = $this->actingAs($user, 'api')
                ->withHeaders(['X-Client-Type' => 'mobile'])
                ->postJson($ruta, []);

            $this->assertNotSame(
                BlockAdminFieldOperations::ADMIN_FIELD_OPERATION_BLOCKED,
                $respuesta->json('code'),
                "El rol {$rol} es de campo: {$ruta} no debe rebotar por la regla de back-office"
            );
        }
    }

    public static function rolesDeCampo(): array
    {
        return [
            'cobrador'   => [5],
            'supervisor' => [6],
        ];
    }

    /**
     * El bloqueo es del ALTA, no de la administración. Estos endpoints son los
     * que el admin sí necesita para corregir lo que el campo cargó mal, y
     * quedan fijados acá para que nadie los arrastre al mismo `->only([...])`.
     */
    public function test_el_back_office_conserva_la_correccion_de_movimientos_existentes(): void
    {
        $admin = User::factory()->create(['role_id' => 2]);

        // Ids inexistentes: la respuesta será 404/422/500 según el caso, pero
        // NUNCA el 403 de esta regla. Eso es lo único que se afirma.
        $rutas = [
            ['DELETE', '/api/payment/delete/999999'],
            ['DELETE', '/api/credit/delete/999999'],
            ['POST',   '/api/payment/reapply/999999'],
        ];

        foreach ($rutas as [$verbo, $ruta]) {
            $respuesta = $this->actingAs($admin, 'api')->json($verbo, $ruta);

            $this->assertNotSame(
                BlockAdminFieldOperations::ADMIN_FIELD_OPERATION_BLOCKED,
                $respuesta->json('code'),
                "{$ruta} es corrección de back-office: no debe caer en la regla de campo"
            );
        }
    }
}
