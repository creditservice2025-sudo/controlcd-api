<?php

namespace Tests\Feature\Lifecycle;

use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\User;
use App\Services\LiquidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CAJA FIRMADA = CAJA CONGELADA.
 *
 * Reproduce el caso Mono9P: un día viejo sin aprobar por debajo de días que ya
 * fueron cerrados y firmados. Al aprobarlo, la cascada de recálculo reescribía
 * TODOS los posteriores con la foto de hoy —que no es la misma que la del
 * cierre, porque después se borraron pagos y se corrigieron business_date con
 * backfills—. Medido sobre producción: 207 días firmados reescritos, la caja de
 * la ruta corrida $572 y un faltante de $940 inventado en un día liquidado
 * siete meses antes.
 *
 * Estas pruebas fijan las dos mitades de la regla:
 *   · lo firmado NO se toca, aunque los datos de origen hayan cambiado;
 *   · el día que el administrador está trabajando SÍ se recalcula (regla
 *     vigente: si carga un movimiento después del cierre del cobrador, ese
 *     día tiene que reflejarlo).
 */
class LiquidationSealedDaysTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Lima';

    /** Campos que definen la caja de un día firmado. */
    private const MONTOS = [
        'initial_cash', 'total_collected', 'total_expenses', 'total_income',
        'new_credits', 'real_to_deliver', 'shortage', 'surplus',
    ];

    private Seller $seller;
    private User $sellerUser;
    private User $admin;
    private Credit $credit;

    private function ensureRole(int $id): void
    {
        if (!DB::table('roles')->where('id', $id)->exists()) {
            DB::table('roles')->insert([
                'id' => $id, 'name' => 'Role-' . $id . '-' . uniqid(),
                'guard_name' => 'web', 'is_assignable' => 1,
                'created_at' => now(), 'updated_at' => now(),
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

        $client = Client::factory()->create([
            'seller_id' => $this->seller->id,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);
        $this->credit = Credit::factory()->create([
            'seller_id' => $this->seller->id,
            'client_id' => $client->id,
            'credit_value' => 1000,
            'total_interest' => 20,
            'total_amount' => 1200,
            'number_installments' => 4,
            'payment_frequency' => 'Semanal',
            'status' => 'Vigente',
            'created_at' => '2026-05-01 10:00:00',
        ]);
    }

    private function svc(): LiquidationService
    {
        return app(LiquidationService::class);
    }

    private function abrir(string $date): Liquidation
    {
        return $this->svc()->getOrCreateLiquidation($this->seller->id, $date, self::TZ);
    }

    private function cobro(string $date, float $monto): int
    {
        return DB::table('payments')->insertGetId([
            'credit_id' => $this->credit->id,
            'amount' => $monto,
            'unapplied_amount' => 0,
            'status' => 'Pagado',
            'payment_method' => 'Efectivo',
            'payment_date' => $date,
            'business_date' => $date,
            'created_at' => $date . ' 12:00:00',
            'updated_at' => $date . ' 12:00:00',
        ]);
    }

    private function gasto(string $date, float $monto): void
    {
        DB::table('expenses')->insert([
            'value' => $monto,
            'description' => 'Gasto cargado por el administrador',
            'status' => 'Aprobado',
            'user_id' => $this->sellerUser->id,
            'business_date' => $date,
            'created_at' => $date . ' 18:00:00',
            'updated_at' => $date . ' 18:00:00',
        ]);
    }

    private function montos(Liquidation $liq): array
    {
        $fresh = $liq->fresh();

        return array_map(
            fn ($c) => number_format((float) $fresh->$c, 2, '.', ''),
            array_combine(self::MONTOS, self::MONTOS)
        );
    }

    /**
     * Monta el escenario Mono9P: día 1 abierto, días 2 y 3 firmados por encima,
     * y después un dato de origen que se mueve (pago borrado tras la firma).
     */
    private function escenarioMono9(): array
    {
        $d1 = $this->abrir('2026-06-01');
        $d2 = $this->abrir('2026-06-02');
        $d3 = $this->abrir('2026-06-03');

        $pagoDia2 = $this->cobro('2026-06-02', 500);
        $this->cobro('2026-06-03', 300);

        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $dia) {
            $this->svc()->recalculateLiquidation($this->seller->id, $dia, self::TZ);
        }

        // Los días 2 y 3 quedan firmados, igual que en el histórico real (donde
        // se aprobaron por encima de un día que había quedado abierto).
        $d2->update(['status' => 'approved', 'cash_delivered' => 500]);
        $d3->update(['status' => 'approved', 'cash_delivered' => 300]);

        return [$d1, $d2, $d3, $pagoDia2];
    }

    public function test_aprobar_un_dia_viejo_no_mueve_los_dias_ya_firmados(): void
    {
        [$d1, $d2, $d3, $pagoDia2] = $this->escenarioMono9();

        $antes2 = $this->montos($d2);
        $antes3 = $this->montos($d3);

        // EL DATO SE MUEVE DESPUÉS DE LA FIRMA: alguien borra un pago del día 2.
        // Es exactamente lo que pasó en producción (pagos borrados, business_date
        // corregidos por backfill) y lo que hacía que el recálculo diera distinto.
        DB::table('payments')->where('id', $pagoDia2)->update(['deleted_at' => now()]);

        // Ahora se aprueba el día viejo → dispara la cascada sobre 2 y 3.
        $this->actingAs($this->admin, 'api');
        $respuesta = $this->svc()->approve($d1->id, self::TZ);

        $this->assertSame(200, $respuesta->getStatusCode());

        $this->assertSame($antes2, $this->montos($d2), 'El día 2 está FIRMADO: sus montos no pueden moverse');
        $this->assertSame($antes3, $this->montos($d3), 'El día 3 está FIRMADO: sus montos no pueden moverse');
    }

    public function test_sin_el_congelamiento_los_dias_firmados_si_se_moverian(): void
    {
        [$d1, $d2, , $pagoDia2] = $this->escenarioMono9();

        $antes2 = $this->montos($d2);
        DB::table('payments')->where('id', $pagoDia2)->update(['deleted_at' => now()]);

        // Contraprueba: con los cerrojos bajados (modo reparación) el recálculo
        // SÍ reescribe el día firmado. Demuestra que la prueba de arriba mide el
        // congelamiento y no un escenario donde no pasaba nada de todos modos.
        Liquidation::withoutIntegrityGuards(function () {
            $this->svc()->recalculateLiquidation($this->seller->id, '2026-06-02', self::TZ);
        });

        $this->assertNotSame(
            $antes2,
            $this->montos($d2),
            'Con los cerrojos bajados el día firmado se reescribe: por eso hacen falta'
        );
        $this->assertSame('0.00', $this->montos($d2)['total_collected']);
    }

    public function test_la_regla_del_administrador_sigue_viva(): void
    {
        // Regla vigente: el cobrador cierra, el administrador agrega un
        // movimiento y ese día TIENE que recalcularse antes de aprobarse.
        $dia = $this->abrir('2026-06-01');
        $this->cobro('2026-06-01', 1000);
        $this->svc()->recalculateLiquidation($this->seller->id, '2026-06-01', self::TZ);

        $dia->update(['status' => 'pending', 'cash_delivered' => 1000]);
        $this->assertEqualsWithDelta(1000.0, (float) $dia->fresh()->real_to_deliver, 0.01);

        // El administrador carga un gasto DESPUÉS del cierre del cobrador.
        $this->gasto('2026-06-01', 150);

        $this->actingAs($this->admin, 'api');
        $this->svc()->approve($dia->id, self::TZ);

        $aprobada = $dia->fresh();
        $this->assertSame('approved', $aprobada->status);
        $this->assertEqualsWithDelta(150.0, (float) $aprobada->total_expenses, 0.01, 'El gasto del administrador debe entrar');
        $this->assertEqualsWithDelta(850.0, (float) $aprobada->real_to_deliver, 0.01, '1000 cobrado − 150 de gasto');
    }

    public function test_un_dia_posterior_sin_firmar_si_se_re_encadena(): void
    {
        $d1 = $this->abrir('2026-06-01');
        $d2 = $this->abrir('2026-06-02');   // queda abierto

        $this->cobro('2026-06-01', 700);
        foreach (['2026-06-01', '2026-06-02'] as $dia) {
            $this->svc()->recalculateLiquidation($this->seller->id, $dia, self::TZ);
        }

        $d1->update(['status' => 'pending']);

        $this->actingAs($this->admin, 'api');
        $this->svc()->approve($d1->id, self::TZ);

        // El día 2 no está firmado: se encadena desde el cierre del día 1.
        $this->assertEqualsWithDelta(
            (float) $d1->fresh()->real_to_deliver,
            (float) $d2->fresh()->initial_cash,
            0.01,
            'Un día sin firmar sí toma el saldo del día anterior'
        );
    }

    public function test_el_dia_firmado_tampoco_se_mueve_por_un_ajuste_retroactivo(): void
    {
        [, $d2, $d3] = $this->escenarioMono9();

        $antes2 = $this->montos($d2);
        $antes3 = $this->montos($d3);

        // Otra puerta hacia la misma cascada: un movimiento cargado sobre un
        // día viejo. No debe alcanzar a los días firmados.
        $this->gasto('2026-06-01', 400);
        $this->svc()->recalculateLiquidation($this->seller->id, '2026-06-01', self::TZ);
        $this->svc()->recalculateNextLiquidations($this->seller->id, '2026-06-01');

        $this->assertSame($antes2, $this->montos($d2));
        $this->assertSame($antes3, $this->montos($d3));
    }
}
