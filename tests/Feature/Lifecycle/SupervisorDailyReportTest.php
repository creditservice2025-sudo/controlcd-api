<?php

namespace Tests\Feature\Lifecycle;

use App\Models\City;
use App\Models\Country;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\User;
use App\Models\UserRoute;
use App\Services\LiquidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El reporte "Cierre aplicado" del Supervisor tiene que salir con los datos
 * del VENDEDOR CONSULTADO, no con los del propio supervisor.
 *
 * El rol 6 no tiene fila en `sellers`: trabaja sobre la ruta activa que eligió.
 * Como el reporte derivaba el vendedor de `$user->seller`, para el supervisor
 * quedaba en null y devolvía "No puedes generar un reporte para este día.
 * Contacta al vendedor para cerrar la liquidación correspondiente." — incluso
 * con la caja aprobada. Es el mismo patrón que ya había mordido en los flujos
 * de alta: el rol 6 se resuelve por active_seller_id, nunca por $user->seller.
 */
class SupervisorDailyReportTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Lima';

    private Seller $seller;
    private User $sellerUser;
    private User $supervisor;
    private string $hoy;

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

        foreach ([5, 6] as $rol) {
            $this->ensureRole($rol);
        }

        $country = Country::factory()->create(['name' => 'Perú', 'timezone' => self::TZ]);
        $city = City::factory()->create(['country_id' => $country->id]);

        $this->sellerUser = User::factory()->create(['role_id' => 5]);
        $this->seller = Seller::factory()->create([
            'city_id' => $city->id,
            'user_id' => $this->sellerUser->id,
        ]);

        $this->supervisor = User::factory()->create(['role_id' => 6]);
        UserRoute::create([
            'user_id'   => $this->supervisor->id,
            'seller_id' => $this->seller->id,
        ]);

        $this->hoy = \Carbon\Carbon::now(self::TZ)->toDateString();
    }

    /** Deja la caja del día aprobada, que es la precondición del reporte. */
    private function cajaAprobada(): Liquidation
    {
        $liq = app(LiquidationService::class)
            ->getOrCreateLiquidation($this->seller->id, $this->hoy, self::TZ);
        $liq->update(['status' => 'approved']);

        return $liq;
    }

    private function pedirReporte(User $actor, ?int $rutaActiva = null): \Illuminate\Testing\TestResponse
    {
        $headers = ['X-Client-Type' => 'mobile'];
        if ($rutaActiva) {
            $headers['X-Active-Seller-Id'] = (string) $rutaActiva;
        }

        return $this->actingAs($actor, 'api')
            ->withHeaders($headers)
            ->getJson('/api/reports/daily-collection?date=' . $this->hoy);
    }

    public function test_el_supervisor_obtiene_el_reporte_del_vendedor_que_esta_consultando(): void
    {
        $this->cajaAprobada();

        $respuesta = $this->pedirReporte($this->supervisor, $this->seller->id);

        $respuesta->assertOk();
        $this->assertSame(
            $this->seller->id,
            $respuesta->json('seller.id') ?? $respuesta->json('data.seller.id'),
            'El reporte debe venir con el vendedor de la ruta activa, no vacío'
        );
    }

    /**
     * La contracara del bug: sin ruta activa no hay vendedor que resolver. El
     * mensaje tiene que decir eso —elegí una ruta— y no culpar al vendedor por
     * no haber cerrado una caja que sí está cerrada.
     */
    public function test_sin_ruta_activa_el_supervisor_recibe_un_mensaje_que_apunta_a_la_ruta(): void
    {
        $this->cajaAprobada();

        $respuesta = $this->pedirReporte($this->supervisor);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString(
            'ruta',
            mb_strtolower(json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE)),
            'El error debe hablar de la ruta sin seleccionar'
        );
    }

    public function test_el_cobrador_sigue_obteniendo_su_propio_reporte(): void
    {
        $this->cajaAprobada();

        // Aprobar la caja le revoca la sesión (middleware liquidation.closed).
        // Se suelta el candado para poder medir el reporte en sí, que es lo que
        // este test vigila.
        \Illuminate\Support\Facades\Cache::forget(
            \App\Services\LoginService::liquidationClosedKey($this->sellerUser->id)
        );

        $this->pedirReporte($this->sellerUser)->assertOk();
    }

    /**
     * Sigue valiendo la regla original: sin caja aprobada no hay reporte. Lo
     * que se arregló es de QUIÉN se mira la caja, no que deje de mirarse.
     */
    public function test_sin_caja_aprobada_no_hay_reporte_ni_para_el_supervisor(): void
    {
        app(LiquidationService::class)
            ->getOrCreateLiquidation($this->seller->id, $this->hoy, self::TZ); // queda 'En curso'

        $respuesta = $this->pedirReporte($this->supervisor, $this->seller->id);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString(
            'cerrar la liquidación',
            json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE)
        );
    }
}
