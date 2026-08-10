<?php

namespace Tests\Feature\Lifecycle;

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
 * El ciclo de vida de una caja, paso a paso: nace → recibe movimientos →
 * la cierra el vendedor → la aprueba el administrador → se reabre.
 *
 * En CADA paso se verifican las dos cosas que se estaban perdiendo:
 *
 *   1. LA CADENA: el saldo inicial de un día es SIEMPRE el importe a entregar
 *      del día anterior. Es la invariante que nadie verificaba y que estaba
 *      rota en 19 días del histórico.
 *   2. LA AUDITORÍA: todo cambio de estado deja rastro de quién y desde dónde.
 *      Antes approve() y approveMultiple() no escribían nada, por eso no se
 *      pudo reconstruir quién dejó abierto el 2025-11-11 del vendedor 19.
 */
class LiquidationChainLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Lima';

    private Seller $seller;
    private User $sellerUser;
    private User $admin;
    private Credit $credit;

    /** `users.role_id` tiene FK contra `roles`, que RefreshDatabase deja vacía. */
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

        // Crédito ANTERIOR a la ventana bajo prueba: así su desembolso no
        // ensucia el `new_credits` de los días que se están midiendo, y sirve
        // de soporte para los cobros.
        $client = Client::factory()->create([
            'seller_id'   => $this->seller->id,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);
        $this->credit = Credit::factory()->create([
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
    }

    private function svc(): LiquidationService
    {
        return app(LiquidationService::class);
    }

    private function dia(int $n): string
    {
        return '2026-06-0' . $n;
    }

    private function abrir(string $date): Liquidation
    {
        return $this->svc()->getOrCreateLiquidation($this->seller->id, $date, self::TZ);
    }

    private function cobro(string $date, float $monto): void
    {
        DB::table('payments')->insert([
            'credit_id'         => $this->credit->id,
            'amount'            => $monto,
            'unapplied_amount'  => 0,
            'status'            => 'Pagado',
            'payment_method'    => 'Efectivo',
            'payment_date'      => $date,
            'business_date'     => $date,
            'created_at'        => $date . ' 12:00:00',
            'updated_at'        => $date . ' 12:00:00',
        ]);
    }

    private function gasto(string $date, float $monto): void
    {
        DB::table('expenses')->insert([
            'value'         => $monto,
            'description'   => 'Gasto de prueba',
            'status'        => 'Aprobado',
            'user_id'       => $this->sellerUser->id,
            'business_date' => $date,
            'created_at'    => $date . ' 13:00:00',
            'updated_at'    => $date . ' 13:00:00',
        ]);
    }

    private function ingreso(string $date, float $monto): void
    {
        DB::table('incomes')->insert([
            'value'         => $monto,
            'description'   => 'BASE',
            'user_id'       => $this->sellerUser->id,
            'business_date' => $date,
            'created_at'    => $date . ' 08:00:00',
            'updated_at'    => $date . ' 08:00:00',
        ]);
    }

    /**
     * LA invariante. Se llama después de cada paso del ciclo.
     */
    private function assertCadenaIntacta(string $mensaje = ''): void
    {
        $dias = Liquidation::where('seller_id', $this->seller->id)
            ->orderBy('date')->get();

        $previo = null;
        foreach ($dias as $dia) {
            if ($previo !== null) {
                $this->assertEqualsWithDelta(
                    (float) $previo->real_to_deliver,
                    (float) $dia->initial_cash,
                    0.01,
                    "Cadena rota en {$dia->date->toDateString()}: el saldo inicial no es el cierre del día anterior. {$mensaje}"
                );
            }
            $previo = $dia;
        }
    }

    private function auditorias(int $liquidationId, string $accion): \Illuminate\Support\Collection
    {
        return LiquidationAudit::where('liquidation_id', $liquidationId)
            ->where('action', $accion)
            ->get();
    }

    // ── PASO 1 · NACE ──────────────────────────────────────────────────────

    public function test_paso1_la_apertura_crea_el_dia_en_curso_y_deja_auditoria(): void
    {
        $this->actingAs($this->sellerUser, 'api');

        $liq = $this->abrir($this->dia(1));

        $this->assertSame('En curso', $liq->status);
        $this->assertEqualsWithDelta(0.0, (float) $liq->initial_cash, 0.01, 'El primer día arranca en cero');

        $audit = $this->auditorias($liq->id, 'apertura');
        $this->assertCount(1, $audit, 'La apertura del día debe quedar auditada');

        // La apertura es un acto del SISTEMA (la dispara el primer movimiento o
        // el login), no una decisión del cobrador: se firma con user_id null y
        // el actor queda en el detalle. Además, firmarla con el cobrador
        // alteraría el gate `hasAuditForSeller` del front.
        $this->assertNull($audit->first()->user_id);
        $this->assertSame($this->sellerUser->id, $audit->first()->changes['actor']);
        $this->assertSame('En curso', $audit->first()->changes['estado']);
    }

    // ── PASO 2 · RECIBE MOVIMIENTOS ────────────────────────────────────────

    public function test_paso2_los_movimientos_entran_en_la_caja_y_la_cadena_sigue_intacta(): void
    {
        $liq = $this->abrir($this->dia(1));

        $this->ingreso($this->dia(1), 3000);   // base entregada por la empresa
        $this->cobro($this->dia(1), 500);      // recaudo
        $this->gasto($this->dia(1), 200);      // gasto aprobado

        $this->svc()->recalculateLiquidation($this->seller->id, $this->dia(1), self::TZ);

        $liq->refresh();
        // 0 (inicial) + 3000 (ingreso) + 500 (cobro) − 200 (gasto) = 3300
        $this->assertEqualsWithDelta(3300.0, (float) $liq->real_to_deliver, 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $liq->total_collected, 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $liq->total_expenses, 0.01);

        // El día siguiente hereda exactamente ese importe.
        $dia2 = $this->abrir($this->dia(2));
        $this->assertEqualsWithDelta(3300.0, (float) $dia2->initial_cash, 0.01);

        $this->assertCadenaIntacta('tras registrar movimientos');
    }

    // ── PASO 3 · LA CIERRA EL VENDEDOR ─────────────────────────────────────

    public function test_paso3_el_cierre_del_vendedor_deja_auditoria_de_cambio_de_estado(): void
    {
        $this->actingAs($this->sellerUser, 'api');

        $liq = $this->abrir($this->dia(1));
        $this->ingreso($this->dia(1), 3000);
        $this->svc()->recalculateLiquidation($this->seller->id, $this->dia(1), self::TZ);

        $liq->update(['status' => 'pending', 'closed_at' => now(), 'cash_delivered' => 3000]);

        $audits = $this->auditorias($liq->id, 'cambio_estado');
        $this->assertCount(1, $audits, 'El cierre debe quedar auditado');

        $cambio = $audits->first();
        $this->assertSame('En curso', $cambio->changes['status']['de']);
        $this->assertSame('pending', $cambio->changes['status']['a']);
        $this->assertSame($this->sellerUser->id, $cambio->user_id, 'El cierre lo firma quien lo hizo');

        // El efectivo entregado es un monto manual: también deja rastro.
        // (delta, no assertSame: el detalle va y vuelve por JSON y el tipo
        // numérico exacto depende de la precisión de serialización.)
        $this->assertEqualsWithDelta(3000.0, $cambio->changes['cash_delivered']['a'], 0.01);
    }

    /**
     * El cierre REAL del vendedor, por el mismo camino que usa el APK:
     * PUT /liquidations/update/{id} → LiquidationService::updateLiquidation.
     *
     * El resto de los pasos simula el cierre con `$liq->update(['status' =>
     * 'pending'])`, y por eso no veían el agujero: la fila del día ya existe
     * (la abre el login), así que el front NUNCA pasa por storeLiquidation —el
     * único lugar que escribía 'pending'—, y updateLiquidation descartaba el
     * `status` del payload. La caja se cerraba en pantalla y quedaba 'En curso'
     * en la base: ni el bloqueo de login ni el candado de sesión se disparaban,
     * y el cobrador volvía a entrar el mismo día.
     */
    public function test_paso3bis_el_cierre_por_el_endpoint_real_deja_la_caja_en_pending(): void
    {
        $this->actingAs($this->sellerUser, 'api');

        $liq = $this->abrir($this->dia(1));
        $this->ingreso($this->dia(1), 3000);
        $this->svc()->recalculateLiquidation($this->seller->id, $this->dia(1), self::TZ);

        $this->assertSame('En curso', $liq->fresh()->status, 'Precondición: la caja está abierta');

        // Payload tal cual lo manda el front al cerrar caja.
        $this->svc()->updateLiquidation($liq->fresh(), [
            'date'              => $this->dia(1),
            'seller_id'         => $this->seller->id,
            'collection_target' => 0,
            'initial_cash'      => 0,
            'base_delivered'    => 3000,
            'cash_delivered'    => 3000,
            'total_collected'   => 0,
            'total_expenses'    => 0,
            'new_credits'       => 0,
            'status'            => 'pending',
            'timezone'          => self::TZ,
        ]);

        $liq->refresh();
        $this->assertSame('pending', $liq->status, 'Cerrar caja debe dejarla en pending, no en En curso');
        $this->assertSame($this->sellerUser->id, $liq->closed_by, 'El cierre debe quedar firmado');
        $this->assertNotNull($liq->closed_at);

        // Consecuencia operativa: el candado que impide volver a entrar el
        // mismo día lo pone el observer del modelo al ver un estado cerrado.
        $this->assertTrue(
            \Illuminate\Support\Facades\Cache::has(
                \App\Services\LoginService::liquidationClosedKey($this->sellerUser->id)
            ),
            'Al cerrar la caja el cobrador debe quedar bloqueado para volver a ingresar'
        );
    }

    /**
     * La contracara: el PUT sigue SIN poder cambiar el estado a otra cosa. Es
     * el motivo por el que `status` se descarta en validateData(), y la razón
     * de que la excepción de arriba sea 'En curso' → 'pending' y nada más.
     */
    public function test_paso3bis_el_endpoint_de_edicion_no_puede_aprobar_ni_reabrir(): void
    {
        $this->actingAs($this->admin, 'api');

        $liq = $this->abrir($this->dia(1));
        $base = [
            'date'              => $this->dia(1),
            'seller_id'         => $this->seller->id,
            'collection_target' => 0,
            'initial_cash'      => 0,
            'base_delivered'    => 0,
            'cash_delivered'    => 0,
            'total_collected'   => 0,
            'total_expenses'    => 0,
            'new_credits'       => 0,
        ];

        // 1) No se aprueba por acá: eso pasa por approve(), con su permiso y su
        //    control de secuencia.
        $this->svc()->updateLiquidation($liq->fresh(), $base + ['status' => 'approved']);
        $this->assertSame('En curso', $liq->fresh()->status);

        // 2) Ni se reabre: una caja cerrada solo vuelve por reopenRoute().
        $liq->update(['status' => 'pending']);
        $this->svc()->updateLiquidation($liq->fresh(), $base + ['status' => 'En curso']);
        $this->assertSame('pending', $liq->fresh()->status);

        // 3) Ni se re-cierra una aprobada mandando 'pending'.
        $liq->update(['status' => 'approved']);
        $this->svc()->updateLiquidation($liq->fresh(), $base + ['status' => 'pending']);
        $this->assertSame('approved', $liq->fresh()->status);
    }

    // ── PASO 4 · LA APRUEBA EL ADMINISTRADOR ───────────────────────────────

    public function test_paso4_la_aprobacion_deja_auditoria_y_no_mueve_la_cadena(): void
    {
        $liq = $this->abrir($this->dia(1));
        $this->ingreso($this->dia(1), 3000);
        $this->svc()->recalculateLiquidation($this->seller->id, $this->dia(1), self::TZ);
        $liq->update(['status' => 'pending']);

        $antes = (float) $liq->fresh()->real_to_deliver;

        $this->actingAs($this->admin, 'api');
        $respuesta = $this->svc()->approve($liq->id, self::TZ);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame('approved', $liq->fresh()->status);
        $this->assertEqualsWithDelta($antes, (float) $liq->fresh()->real_to_deliver, 0.01, 'Aprobar no cambia los montos');

        $cambios = $this->auditorias($liq->id, 'cambio_estado')
            ->filter(fn ($a) => ($a->changes['status']['a'] ?? null) === 'approved');

        $this->assertCount(1, $cambios, 'La aprobación debe quedar auditada (antes no dejaba NINGÚN rastro)');
        $this->assertSame($this->admin->id, $cambios->first()->user_id);
        $this->assertCadenaIntacta('tras aprobar');
    }

    // ── PASO 5 · NO SE APRUEBA FUERA DE ORDEN ──────────────────────────────

    public function test_paso5_no_se_puede_aprobar_un_dia_dejando_otro_abierto_por_debajo(): void
    {
        $dia1 = $this->abrir($this->dia(1));   // queda 'En curso' a propósito
        $dia2 = $this->abrir($this->dia(2));
        $dia2->update(['status' => 'pending']);

        $this->actingAs($this->admin, 'api');
        $respuesta = $this->svc()->approve($dia2->id, self::TZ);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame('pending', $dia2->fresh()->status, 'El día 2 NO debe quedar aprobado');
        $this->assertSame('En curso', $dia1->fresh()->status);

        // El paso a 'pending' de arriba SÍ es un cambio real y está auditado;
        // lo que no puede existir es un rastro de aprobación, porque no la hubo.
        $aprobaciones = $this->auditorias($dia2->id, 'cambio_estado')
            ->filter(fn ($a) => ($a->changes['status']['a'] ?? null) === 'approved');

        $this->assertCount(0, $aprobaciones, 'Un rechazo no debe dejar rastro de aprobación');
    }

    // ── PASO 6 · SE REABRE ─────────────────────────────────────────────────

    public function test_paso6_la_reapertura_conserva_el_dia_y_deja_auditoria(): void
    {
        $hoy = \Carbon\Carbon::now(self::TZ)->toDateString();

        $liq = $this->abrir($hoy);
        $this->cobro($hoy, 400);
        $this->svc()->recalculateLiquidation($this->seller->id, $hoy, self::TZ);
        $liq->update(['status' => 'pending', 'closed_at' => now()]);

        $this->svc()->reopenRoute($this->seller->id, $hoy, null);

        $liq->refresh();
        $this->assertSame('En curso', $liq->status);
        $this->assertNull($liq->deleted_at, 'La reapertura NO debe borrar el día: así se perdían los movimientos');

        $vuelta = $this->auditorias($liq->id, 'cambio_estado')
            ->filter(fn ($a) => ($a->changes['status']['a'] ?? null) === 'En curso');
        $this->assertCount(1, $vuelta, 'La reapertura debe quedar auditada como cambio de estado');

        // Y lo más importante: el recaudo del día sigue en la caja.
        $this->assertEqualsWithDelta(400.0, (float) $liq->total_collected, 0.01);
    }

    // ── EL CICLO COMPLETO ──────────────────────────────────────────────────

    public function test_la_cadena_se_mantiene_a_lo_largo_de_todo_el_ciclo(): void
    {
        $this->actingAs($this->admin, 'api');

        // Día 1: base 3000, cobra 500, gasta 200 → cierra en 3300
        $d1 = $this->abrir($this->dia(1));
        $this->ingreso($this->dia(1), 3000);
        $this->cobro($this->dia(1), 500);
        $this->gasto($this->dia(1), 200);

        // Día 2: cobra 700, gasta 100 → cierra en 3900
        $d2 = $this->abrir($this->dia(2));
        $this->cobro($this->dia(2), 700);
        $this->gasto($this->dia(2), 100);

        // Día 3: cobra 300 → cierra en 4200
        $d3 = $this->abrir($this->dia(3));
        $this->cobro($this->dia(3), 300);

        foreach ([1, 2, 3] as $n) {
            $this->svc()->recalculateLiquidation($this->seller->id, $this->dia($n), self::TZ);
        }
        $this->assertCadenaIntacta('tras los movimientos');

        $this->assertEqualsWithDelta(3300.0, (float) $d1->fresh()->real_to_deliver, 0.01);
        $this->assertEqualsWithDelta(3900.0, (float) $d2->fresh()->real_to_deliver, 0.01);
        $this->assertEqualsWithDelta(4200.0, (float) $d3->fresh()->real_to_deliver, 0.01);

        // Cierre y aprobación EN ORDEN de los tres días.
        foreach ([$d1, $d2, $d3] as $dia) {
            $dia->update(['status' => 'pending']);
            $respuesta = $this->svc()->approve($dia->id, self::TZ);
            $this->assertSame(200, $respuesta->getStatusCode(), "Debe poder aprobarse {$dia->date->toDateString()}");
            $this->assertCadenaIntacta('tras aprobar ' . $dia->date->toDateString());
        }

        // Los tres quedaron aprobados y los tres tienen su auditoría de estado.
        foreach ([$d1, $d2, $d3] as $dia) {
            $this->assertSame('approved', $dia->fresh()->status);
            $this->assertGreaterThanOrEqual(
                1,
                $this->auditorias($dia->id, 'cambio_estado')
                    ->filter(fn ($a) => ($a->changes['status']['a'] ?? null) === 'approved')
                    ->count()
            );
        }
    }
}
