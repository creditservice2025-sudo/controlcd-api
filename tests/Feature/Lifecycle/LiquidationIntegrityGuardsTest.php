<?php

namespace Tests\Feature\Lifecycle;

use App\Exceptions\LiquidationIntegrityException;
use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Liquidation;
use App\Models\LiquidationAudit;
use App\Models\Seller;
use App\Models\User;
use App\Services\LiquidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Los cerrojos del ciclo de vida. Cada prueba reproduce una forma concreta en
 * la que la caja se venía descuadrando:
 *
 *  · Abrir un día por detrás de uno aprobado  → caso Mono9P (2025-11-11).
 *  · Dar de baja un día con movimientos       → ~$110k perdidos en 34 días.
 *  · Cambiar el estado por PUT /update        → aprobar sin control ni permiso.
 *  · Mover el día a otro vendedor o fecha     → reasignar el recaudo.
 *  · Ajustar a mano el saldo inicial          → romper la cadena (19 casos).
 */
class LiquidationIntegrityGuardsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Lima';

    private Seller $seller;
    private User $sellerUser;
    private User $admin;

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

        $this->ensureRole(1);
        $this->ensureRole(5);

        $country = Country::factory()->create(['name' => 'Perú']);
        $city = City::factory()->create(['country_id' => $country->id]);

        $this->sellerUser = User::factory()->create(['role_id' => 5]);
        $this->seller = Seller::factory()->create([
            'city_id' => $city->id,
            'user_id' => $this->sellerUser->id,
        ]);
        $this->admin = User::factory()->create(['role_id' => 1]);
    }

    private function svc(): LiquidationService
    {
        return app(LiquidationService::class);
    }

    private function abrir(string $date): Liquidation
    {
        return $this->svc()->getOrCreateLiquidation($this->seller->id, $date, self::TZ);
    }

    private function cobroEn(string $date, float $monto = 500): void
    {
        $client = Client::factory()->create([
            'seller_id'   => $this->seller->id,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);
        $credit = Credit::factory()->create([
            'seller_id'           => $this->seller->id,
            'client_id'           => $client->id,
            'credit_value'        => 1000,
            'total_interest'      => 20,
            'total_amount'        => 1200,
            'number_installments' => 4,
            'payment_frequency'   => 'Semanal',
            'status'              => 'Vigente',
            'created_at'          => '2026-05-01 10:00:00',
        ]);

        DB::table('payments')->insert([
            'credit_id'        => $credit->id,
            'amount'           => $monto,
            'unapplied_amount' => 0,
            'status'           => 'Pagado',
            'payment_method'   => 'Efectivo',
            'payment_date'     => $date,
            'business_date'    => $date,
            'created_at'       => $date . ' 12:00:00',
            'updated_at'       => $date . ' 12:00:00',
        ]);
    }

    // ── Guard 1 · no abrir un día por detrás de uno aprobado ───────────────

    public function test_no_se_puede_abrir_un_dia_por_detras_de_uno_aprobado(): void
    {
        // El 2026-06-02 queda aprobado: sella la cadena hasta ahí.
        $this->abrir('2026-06-02')->update(['status' => 'approved']);

        // Intentar abrir el 2026-06-01 (anterior) es exactamente lo que dejó
        // al vendedor 19 con un día abierto bajo 242 días aprobados.
        $this->expectException(LiquidationIntegrityException::class);
        $this->expectExceptionMessageMatches('/ya fue aprobado y sella la cadena/');

        $this->abrir('2026-06-01');
    }

    public function test_el_dia_retroactivo_ni_siquiera_llega_a_crearse(): void
    {
        $this->abrir('2026-06-02')->update(['status' => 'approved']);

        try {
            $this->abrir('2026-06-01');
        } catch (LiquidationIntegrityException) {
            // esperado
        }

        $this->assertSame(
            0,
            Liquidation::withTrashed()->where('seller_id', $this->seller->id)
                ->whereDate('date', '2026-06-01')->count(),
            'No debe quedar ninguna fila del día rechazado, ni siquiera borrada'
        );
    }

    public function test_la_herramienta_de_reparacion_si_puede_rellenar_el_hueco(): void
    {
        $this->abrir('2026-06-02')->update(['status' => 'approved']);

        // liquidations:regenerate-day existe para tapar agujeros: baja el guard
        // a propósito. Un día faltante en el medio descuadra más que uno regenerado.
        $liq = Liquidation::withoutIntegrityGuards(fn () => $this->abrir('2026-06-01'));

        $this->assertNotNull($liq->id);
        $this->assertSame('En curso', $liq->status);
    }

    public function test_el_guard_se_vuelve_a_levantar_despues_de_la_reparacion(): void
    {
        $this->abrir('2026-06-03')->update(['status' => 'approved']);
        Liquidation::withoutIntegrityGuards(fn () => $this->abrir('2026-06-01'));

        // Fuera del bloque autorizado el cerrojo tiene que estar puesto otra vez.
        $this->expectException(LiquidationIntegrityException::class);
        $this->abrir('2026-06-02');
    }

    // ── Guard 2 · no dar de baja un día con plata ──────────────────────────

    public function test_no_se_puede_dar_de_baja_un_dia_con_movimientos(): void
    {
        $liq = $this->abrir('2026-06-01');
        $this->cobroEn('2026-06-01', 500);

        $this->expectException(LiquidationIntegrityException::class);
        $this->expectExceptionMessageMatches('/tiene movimientos registrados/');

        $liq->delete();
    }

    public function test_el_dia_con_movimientos_sigue_vivo_tras_el_intento(): void
    {
        $liq = $this->abrir('2026-06-01');
        $this->cobroEn('2026-06-01', 500);

        try {
            $liq->delete();
        } catch (LiquidationIntegrityException) {
            // esperado
        }

        $this->assertNull($liq->fresh()->deleted_at, 'El día debe seguir vivo: su recaudo no puede salir de la cadena');
    }

    public function test_un_dia_vacio_si_se_puede_dar_de_baja_y_queda_auditado(): void
    {
        $liq = $this->abrir('2026-06-01');

        $this->actingAs($this->admin, 'api');
        $liq->delete();

        $this->assertNotNull($liq->fresh()->deleted_at);

        $baja = LiquidationAudit::where('liquidation_id', $liq->id)->where('action', 'baja_dia')->first();
        $this->assertNotNull($baja, 'La baja de un día debe quedar auditada');
        $this->assertSame($this->admin->id, $baja->user_id);
    }

    public function test_no_se_puede_borrar_en_duro(): void
    {
        $liq = $this->abrir('2026-06-01');

        // El borrado en duro arrastraría la auditoría del día: la FK
        // liquidation_audits.liquidation_id es ON DELETE CASCADE.
        $this->expectException(LiquidationIntegrityException::class);
        $this->expectExceptionMessageMatches('/no se borra en duro/');

        $liq->forceDelete();
    }

    // ── Guard 3 · el endpoint update no toca identidad ni estado ───────────

    private function payloadValido(Liquidation $liq): array
    {
        return [
            'date'              => $liq->date->toDateString(),
            'seller_id'         => $liq->seller_id,
            'collection_target' => 0,
            'initial_cash'      => (float) $liq->initial_cash,
            'base_delivered'    => 0,
            'total_collected'   => 0,
            'total_expenses'    => 0,
            'new_credits'       => 0,
            'cash_delivered'    => 100,
        ];
    }

    public function test_update_no_puede_cambiar_el_estado(): void
    {
        $liq = $this->abrir('2026-06-01');

        $this->svc()->updateLiquidation($liq, array_merge($this->payloadValido($liq), [
            'status' => 'approved',   // el intento de aprobar por la puerta de atrás
        ]));

        $this->assertSame(
            'En curso',
            $liq->fresh()->status,
            'PUT /update no puede aprobar: se saltearía el control de secuencia y el permiso'
        );
    }

    public function test_update_no_puede_mover_el_dia_a_otro_vendedor(): void
    {
        $otroUser = User::factory()->create(['role_id' => 5]);
        $otro = Seller::factory()->create([
            'city_id' => $this->seller->city_id,
            'user_id' => $otroUser->id,
        ]);

        $liq = $this->abrir('2026-06-01');

        $this->svc()->updateLiquidation($liq, array_merge($this->payloadValido($liq), [
            'seller_id' => $otro->id,
        ]));

        $this->assertSame(
            $this->seller->id,
            $liq->fresh()->seller_id,
            'La caja no puede cambiar de dueño: se llevaría el recaudo del día a otra ruta'
        );
    }

    public function test_update_no_puede_cambiar_la_fecha_del_dia(): void
    {
        $liq = $this->abrir('2026-06-01');

        $this->svc()->updateLiquidation($liq, array_merge($this->payloadValido($liq), [
            'date' => '2026-06-05',
        ]));

        $this->assertSame('2026-06-01', $liq->fresh()->date->toDateString());
    }

    public function test_update_si_guarda_los_montos_manuales_y_los_audita(): void
    {
        $this->actingAs($this->sellerUser, 'api');
        $liq = $this->abrir('2026-06-01');

        $this->svc()->updateLiquidation($liq, array_merge($this->payloadValido($liq), [
            'base_delivered' => 250,
        ]));

        $this->assertEqualsWithDelta(250.0, (float) $liq->fresh()->base_delivered, 0.01);

        $ajuste = LiquidationAudit::where('liquidation_id', $liq->id)
            ->where('action', 'ajuste_montos')->get()
            ->first(fn ($a) => isset($a->changes['base_delivered']));

        $this->assertNotNull($ajuste, 'Cambiar un monto manual debe quedar auditado');
        $this->assertEqualsWithDelta(250.0, $ajuste->changes['base_delivered']['a'], 0.01);
    }

    // ── Guard 4 · el saldo inicial no se ajusta a mano ─────────────────────

    public function test_el_ajuste_de_caja_rechaza_el_saldo_inicial(): void
    {
        $liq = $this->abrir('2026-06-01');
        config(['app.dummy' => null]);
        putenv('SYSTEM_ADJUST_PASSWORD=secreto');

        $this->actingAs($this->admin, 'api');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/no se ajusta a mano/');

        $this->svc()->adjustBox([
            'liquidation_id' => $liq->id,
            'password'       => 'secreto',
            'observation'    => 'Corrección del saldo inicial de prueba',
            'initial_cash'   => 9999,
        ]);
    }

    public function test_el_ajuste_de_caja_si_permite_los_montos_entregados(): void
    {
        $liq = $this->abrir('2026-06-01');
        putenv('SYSTEM_ADJUST_PASSWORD=secreto');

        $this->actingAs($this->admin, 'api');

        // El modal de ajuste manda SIEMPRE initial_cash, precargado con el
        // valor actual. Si el rechazo fuera ciego, ningún ajuste funcionaría:
        // tolerar el valor sin cambios es lo que mantiene vivo el flujo real.
        $this->svc()->adjustBox([
            'liquidation_id' => $liq->id,
            'password'       => 'secreto',
            'observation'    => 'Ajuste de la base entregada, motivo de prueba',
            'initial_cash'   => (float) $liq->initial_cash,
            'base_delivered' => 300,
        ]);

        $this->assertEqualsWithDelta(300.0, (float) $liq->fresh()->base_delivered, 0.01);
        $this->assertStringContainsString('AJUSTE MANUAL', (string) $liq->fresh()->observation);
    }

    // ── Guard 5 · el endpoint update no es de acceso libre ─────────────────

    public function test_un_usuario_ajeno_no_puede_editar_la_caja_de_otro_vendedor(): void
    {
        $liq = $this->abrir('2026-06-01');

        $intruso = User::factory()->create(['role_id' => 5]);
        $otraCiudad = City::factory()->create(['country_id' => $this->seller->city->country_id]);
        Seller::factory()->create(['city_id' => $otraCiudad->id, 'user_id' => $intruso->id]);

        // Se invoca el controller directamente y no por HTTP a propósito: el
        // guard 'api' es Passport y en el entorno de pruebas de este repo no
        // hay cliente/llaves instaladas, así que toda petición muere en 401
        // (es la causa de los fallos preexistentes de FinancialIntegrityTest).
        // Un 401 mediría el login; lo que hay que medir acá es el control de
        // acceso al recurso, que es lo que faltaba.
        $this->actingAs($intruso, 'api');

        $request = \App\Http\Requests\Liquidation\UpdateLiquidationRequest::create(
            '/api/liquidations/update/' . $liq->id,
            'PUT',
            $this->payloadValido($liq)
        );

        $respuesta = app(\App\Http\Controllers\LiquidationController::class)
            ->updateLiquidation($request, $liq->id);

        $this->assertSame(403, $respuesta->getStatusCode());
        $this->assertEqualsWithDelta(0.0, (float) $liq->fresh()->cash_delivered, 0.01);
    }
}
