<?php

namespace Tests\Feature\Lifecycle;

use App\Models\City;
use App\Models\Country;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\User;
use App\Services\LiquidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * INGRESO TARDÍO SOBRE UNA CAJA YA CERRADA.
 *
 * El caso medido en producción: el administrador aprueba la liquidación y
 * recién después carga un ingreso del día. El ingreso quedaba grabado en
 * `incomes` —y se veía en "Movimientos recientes"— pero la caja aprobada no se
 * recalcula nunca, así que `total_income` quedaba en 0 y el importe no entraba
 * en ninguna caja. S/ 2.000 fuera de la cadena en un solo día.
 *
 * La regla ahora: el ingreso SE ADMITE y AJUSTA la caja, con dos condiciones
 * que se verifican antes de tocar nada:
 *   1. tiene que ser el día EN CURSO del vendedor;
 *   2. no puede existir una liquidación posterior, porque el ajuste correría
 *      su saldo inicial y el de todas las siguientes.
 */
class LateIncomeOnClosedCashTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Lima';

    private Seller $seller;
    private User $sellerUser;
    private User $admin;
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

        foreach ([1, 2, 5] as $rol) {
            $this->ensureRole($rol);
        }

        $country = Country::factory()->create(['name' => 'Perú', 'timezone' => self::TZ]);
        $city = City::factory()->create(['country_id' => $country->id]);

        $this->sellerUser = User::factory()->create(['role_id' => 5]);
        $this->seller = Seller::factory()->create([
            'city_id' => $city->id,
            'user_id' => $this->sellerUser->id,
        ]);
        $this->admin = User::factory()->create(['role_id' => 2]);

        $this->hoy = \Carbon\Carbon::now(self::TZ)->toDateString();
    }

    private function svc(): LiquidationService
    {
        return app(LiquidationService::class);
    }

    private function abrirCaja(?string $fecha = null): Liquidation
    {
        return $this->svc()->getOrCreateLiquidation($this->seller->id, $fecha ?? $this->hoy, self::TZ);
    }

    private function cargarIngreso(int $valor, ?string $fecha = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'api')->postJson('/api/income/create', [
            'value'       => $valor,
            'description' => 'Ingreso tardío de prueba',
            'user_id'     => $this->sellerUser->id,
            'created_at'  => $fecha ?? $this->hoy,
            'timezone'    => self::TZ,
        ]);
    }

    // ══ LO QUE SE ROMPÍA ═══════════════════════════════════════════════════

    /**
     * EL test del reporte: caja aprobada, ingreso después, la caja tiene que
     * quedar con el importe adentro. Antes terminaba en total_income = 0.
     */
    public function test_un_ingreso_sobre_caja_aprobada_ajusta_la_caja_del_dia(): void
    {
        $liq = $this->abrirCaja();
        $liq->update(['status' => 'approved']);

        $antes = (float) $liq->fresh()->real_to_deliver;

        $this->cargarIngreso(2000)
            ->assertOk()
            ->assertJsonPath('cash_box_adjusted', true);

        $liq->refresh();

        $this->assertEqualsWithDelta(2000.0, (float) $liq->total_income, 0.01, 'El ingreso debe entrar en la caja del día');
        $this->assertEqualsWithDelta(
            $antes + 2000.0,
            (float) $liq->real_to_deliver,
            0.01,
            'El importe a entregar debe subir con el ingreso: si no, la plata quedó fuera de la caja'
        );
        $this->assertSame('approved', $liq->status, 'Ajustar no desaprueba la caja');
    }

    public function test_lo_mismo_vale_para_una_caja_cerrada_por_el_vendedor(): void
    {
        $liq = $this->abrirCaja();
        $liq->update(['status' => 'pending']);

        $this->cargarIngreso(500)->assertOk()->assertJsonPath('cash_box_adjusted', true);

        $this->assertEqualsWithDelta(500.0, (float) $liq->fresh()->total_income, 0.01);
    }

    public function test_con_la_caja_abierta_no_hay_ajuste_sino_el_recalculo_normal(): void
    {
        $liq = $this->abrirCaja(); // 'En curso'

        $this->cargarIngreso(300)
            ->assertOk()
            ->assertJsonPath('cash_box_adjusted', false);

        $this->assertEqualsWithDelta(300.0, (float) $liq->fresh()->total_income, 0.01);
    }

    // ══ LOS DOS CANDADOS ═══════════════════════════════════════════════════

    /**
     * Un día viejo NO se ajusta. Ahí el camino es reabrir la caja, que queda
     * auditado; si esto pasara, se estaría reescribiendo un día firmado sin
     * dejar rastro de por qué cambió.
     */
    public function test_un_ingreso_sobre_un_dia_anterior_cerrado_se_rechaza(): void
    {
        $ayer = \Carbon\Carbon::now(self::TZ)->subDay()->toDateString();

        $liqAyer = $this->abrirCaja($ayer);
        $liqAyer->update(['status' => 'approved']);

        $respuesta = $this->cargarIngreso(1000, $ayer);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('no es el día en curso', $respuesta->json('message') ?? '');

        $this->assertEqualsWithDelta(0.0, (float) $liqAyer->fresh()->total_income, 0.01, 'La caja de ayer no se toca');
        $this->assertDatabaseMissing('incomes', [
            'user_id' => $this->sellerUser->id,
            'value'   => 1000,
        ]);
    }

    /**
     * El candado de la cadena: el saldo inicial de un día es el importe a
     * entregar del anterior. Si ya existe el día siguiente, ajustar hoy lo
     * dejaría descolgado —y a todos los que vengan detrás—. Antes que romper
     * la cadena en silencio, se rechaza.
     */
    public function test_no_se_ajusta_si_el_dia_siguiente_ya_esta_abierto(): void
    {
        $liqHoy = $this->abrirCaja();
        $liqHoy->update(['status' => 'approved']);

        $manana = \Carbon\Carbon::now(self::TZ)->addDay()->toDateString();
        $this->abrirCaja($manana);

        $respuesta = $this->cargarIngreso(700);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ya existe la caja del', $respuesta->json('message') ?? '');

        $this->assertEqualsWithDelta(0.0, (float) $liqHoy->fresh()->total_income, 0.01);
        $this->assertDatabaseMissing('incomes', [
            'user_id' => $this->sellerUser->id,
            'value'   => 700,
        ]);
    }

    /**
     * El rechazo NO puede dejar el ingreso creado: IncomeService no corre en
     * transacción, así que la verificación va antes del insert. Este test es el
     * que sostiene esa decisión.
     */
    public function test_un_ingreso_rechazado_no_queda_grabado(): void
    {
        $ayer = \Carbon\Carbon::now(self::TZ)->subDay()->toDateString();
        $this->abrirCaja($ayer)->update(['status' => 'approved']);

        $this->cargarIngreso(1234, $ayer)->assertStatus(422);

        $this->assertSame(
            0,
            DB::table('incomes')->where('user_id', $this->sellerUser->id)->count(),
            'Un ingreso rechazado no debe quedar en la base: sería el mismo huérfano que se está corrigiendo'
        );
    }

    // ══ EL COBRADOR SIGUE BLOQUEADO ════════════════════════════════════════

    public function test_el_cobrador_no_ajusta_ninguna_caja_cerrada(): void
    {
        $liq = $this->abrirCaja();
        $liq->update(['status' => 'pending']);

        // Cerrar caja le revoca la sesión (401 del middleware liquidation.closed).
        // Se suelta ese candado a propósito para medir la capa de abajo: el
        // guard del servicio, que es lo único que queda si la cache falla.
        \Illuminate\Support\Facades\Cache::forget(
            \App\Services\LoginService::liquidationClosedKey($this->sellerUser->id)
        );

        $this->actingAs($this->sellerUser, 'api')
            ->withHeaders(['X-Client-Type' => 'mobile'])
            ->postJson('/api/income/create', [
                'value'       => 900,
                'description' => 'Ingreso del cobrador tras cerrar',
                'created_at'  => $this->hoy,
                'timezone'    => self::TZ,
            ])
            ->assertStatus(422);

        $this->assertEqualsWithDelta(0.0, (float) $liq->fresh()->total_income, 0.01);
    }

    // ══ LA CADENA SIGUE EN PIE ═════════════════════════════════════════════

    public function test_el_ajuste_no_rompe_la_cadena_de_dias(): void
    {
        $ayer = \Carbon\Carbon::now(self::TZ)->subDay()->toDateString();

        $liqAyer = $this->abrirCaja($ayer);
        $liqAyer->update(['status' => 'approved']);

        $liqHoy = $this->abrirCaja();
        $liqHoy->update(['status' => 'approved']);

        $this->cargarIngreso(1500)->assertOk();

        $liqAyer->refresh();
        $liqHoy->refresh();

        // El día de ayer no se movió...
        $this->assertEqualsWithDelta(0.0, (float) $liqAyer->total_income, 0.01);

        // ...y el saldo inicial de hoy sigue siendo el cierre de ayer.
        $this->assertEqualsWithDelta(
            (float) $liqAyer->real_to_deliver,
            (float) $liqHoy->initial_cash,
            0.01,
            'La cadena tiene que seguir intacta después del ajuste'
        );
    }
}
