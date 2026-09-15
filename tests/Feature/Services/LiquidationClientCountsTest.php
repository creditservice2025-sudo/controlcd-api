<?php

namespace Tests\Feature\Services;

use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Seller;
use App\Models\User;
use App\Services\LiquidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fija los conteos de clientes de una liquidación: la "A" (con crédito) y la
 * "S" (sin crédito) de la columna P/A/L/S/N.
 *
 * Venían mal por dos motivos, y la liquidación no coincidía con el resumen
 * general (vendedor 21 al 2026-09-12: 121/83 contra 117/87):
 *
 *  1. No se filtraban los créditos BORRADOS. Uno cargado mal, borrado y
 *     rehecho queda con deleted_at puesto pero status 'Vigente', y seguía
 *     contando como cartera viva.
 *  2. El número era una foto de HOY, no del día liquidado, y quedaba congelado
 *     con la foto del último recálculo: las liquidaciones de diciembre 2025 del
 *     vendedor 21 decían 10 clientes con crédito cuando ese vendedor no tuvo su
 *     primer crédito hasta el 2026-01-26.
 *
 * Ahora la liquidación delega en getClientCreditStateBySeller, el MISMO método
 * que alimenta el resumen, así que los dos números no pueden divergir.
 */
class LiquidationClientCountsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSeller(): Seller
    {
        $country = Country::factory()->create(['name' => 'Perú']);
        $city = City::factory()->create(['country_id' => $country->id]);

        return Seller::factory()->create([
            'city_id' => $city->id,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeClient(Seller $seller): Client
    {
        return Client::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);
    }

    /** Crédito con su día de negocio, opcionalmente borrado. */
    private function makeCredit(Seller $seller, Client $client, string $businessDate, string $status = 'Vigente', bool $borrado = false): Credit
    {
        $credit = Credit::factory()->create([
            'seller_id' => $seller->id,
            'client_id' => $client->id,
            'status' => $status,
            'payment_frequency' => 'Semanal',
            'business_date' => $businessDate,
            'business_timezone' => 'America/Lima',
        ]);

        if ($borrado) {
            DB::table('credits')->where('id', $credit->id)
                ->update(['deleted_at' => $businessDate . ' 12:00:00']);
        }

        return $credit;
    }

    /**
     * Le carga un pago al crédito. Hace falta para que un 'Liquidado' cuente
     * como cerrado: creditoVivoAlCorte() trata al liquidado SIN pagos como
     * vivo a propósito —se saldó a mano, así que al corte todavía debía—.
     */
    private function pagar(Credit $credit, string $businessDate): void
    {
        DB::table('payments')->insert([
            'credit_id' => $credit->id,
            'amount' => 100,
            'unapplied_amount' => 0,
            'status' => 'Pagado',
            'payment_method' => 'Efectivo',
            'payment_date' => $businessDate,
            'business_date' => $businessDate,
            'created_at' => $businessDate . ' 12:00:00',
            'updated_at' => $businessDate . ' 12:00:00',
        ]);
    }

    private function counts(Seller $seller, string $date): array
    {
        return app(LiquidationService::class)->clientCreditCountsForDate($seller->id, $date);
    }

    // ----------------------------------------------------------------------

    public function test_un_credito_borrado_no_cuenta_como_cartera_viva(): void
    {
        $seller = $this->makeSeller();

        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-01');

        // Cliente cuyo crédito "Vigente" está BORRADO: es el caso que inflaba
        // el número (se cargó mal, se borró y se rehizo).
        $roto = $this->makeClient($seller);
        $this->makeCredit($seller, $roto, '2026-09-01', 'Vigente', true);
        $this->pagar($this->makeCredit($seller, $roto, '2026-09-01', 'Liquidado'), '2026-09-05');

        $c = $this->counts($seller, '2026-09-12');

        $this->assertSame(1, $c['active_clients_with_credit_count'], 'El crédito borrado no debe contar.');
        $this->assertSame(1, $c['clients_without_credit_count'], 'Y su cliente pasa a "sin crédito".');
    }

    public function test_el_conteo_se_ancla_al_dia_y_no_a_la_foto_de_hoy(): void
    {
        $seller = $this->makeSeller();
        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-10');

        // Antes de que exista el crédito, la cartera del vendedor está vacía.
        // Con la consulta vieja —que ignoraba la fecha— este día mostraba 1.
        $this->assertSame(0, $this->counts($seller, '2026-09-05')['active_clients_with_credit_count']);
        $this->assertSame(1, $this->counts($seller, '2026-09-12')['active_clients_with_credit_count']);
    }

    public function test_cada_cohorte_muestra_su_propio_numero(): void
    {
        $seller = $this->makeSeller();
        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-01');
        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-08');
        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-11');

        // La huella del bug era justamente que los tres daban lo mismo.
        $this->assertSame(1, $this->counts($seller, '2026-09-02')['active_clients_with_credit_count']);
        $this->assertSame(2, $this->counts($seller, '2026-09-09')['active_clients_with_credit_count']);
        $this->assertSame(3, $this->counts($seller, '2026-09-12')['active_clients_with_credit_count']);
    }

    public function test_coincide_con_el_resumen_general(): void
    {
        $seller = $this->makeSeller();

        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-01');
        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-02');
        $roto = $this->makeClient($seller);
        $this->makeCredit($seller, $roto, '2026-09-01', 'Vigente', true);
        $this->makeClient($seller); // sin ningún crédito

        $svc = app(LiquidationService::class);
        $liquidacion = $svc->clientCreditCountsForDate($seller->id, '2026-09-12');
        $resumen = $svc->getClientCreditStateBySeller('2026-09-12', null, [$seller->id])[$seller->id];

        // La garantía que se buscaba: las dos pantallas, el mismo número.
        $this->assertSame($resumen['clients_with_active_credit'], $liquidacion['active_clients_with_credit_count']);
        $this->assertSame($resumen['clients_without_credit'], $liquidacion['clients_without_credit_count']);
    }

    public function test_los_dos_conteos_suman_los_clientes_activos_de_la_ruta(): void
    {
        $seller = $this->makeSeller();

        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-01');
        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-01', 'Cartera Irrecuperable');
        $this->makeClient($seller);

        $c = $this->counts($seller, '2026-09-12');

        // Antes 'Cartera Irrecuperable' se caía entre las dos consultas (no era
        // "vivo" ni "terminado") y la suma quedaba corta.
        $this->assertSame(3, $c['active_clients_with_credit_count'] + $c['clients_without_credit_count']);
    }

    public function test_un_credito_renovado_no_duplica_al_cliente(): void
    {
        $seller = $this->makeSeller();
        $cliente = $this->makeClient($seller);

        // El saldo se mudó al crédito nuevo: el viejo queda 'Renovado' y no es
        // cartera propia. La consulta vieja lo contaba como vivo.
        $this->makeCredit($seller, $cliente, '2026-09-01', 'Renovado');
        $this->makeCredit($seller, $cliente, '2026-09-05', 'Vigente');

        $c = $this->counts($seller, '2026-09-12');

        $this->assertSame(1, $c['active_clients_with_credit_count']);
        $this->assertSame(0, $c['clients_without_credit_count']);
    }

    /**
     * EL test que protege la caja.
     *
     * recalculateLiquidation comparaba montos y conteos juntos: si cambiaba
     * cualquier métrica, hacía update() con TODAS. Así, corregir un conteo
     * reescribía los montos — y hay 121 liquidaciones no aprobadas cuyos montos
     * grabados ya no coinciden con sus movimientos ($75,8M en diferencias), que
     * se habrían movido solas.
     *
     * Se verifica con `irrecoverable_credits_amount`: un campo que el recálculo
     * SÍ produce pero que no entra en la comparación de montos, así que si
     * alguien vuelve a unificar los caminos, el update() completo lo pisa y
     * este test falla.
     */
    public function test_un_cambio_de_conteo_no_reescribe_montos(): void
    {
        $seller = $this->makeSeller();
        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-01');

        $svc = app(LiquidationService::class);
        $liq = $svc->getOrCreateLiquidation($seller->id, '2026-09-12', 'America/Lima');

        // Primero se alinean los montos, para que el único cambio pendiente
        // sea el conteo.
        $svc->recalculateLiquidation($seller->id, '2026-09-12');
        $liq->refresh();

        // Se ensucian a mano (sin observers) el conteo y un monto que el
        // recálculo recomputa pero no compara.
        DB::table('liquidations')->where('id', $liq->id)->update([
            'active_clients_with_credit_count' => 99,
            'clients_without_credit_count' => 99,
            'irrecoverable_credits_amount' => 4710.55,
        ]);

        $svc->recalculateLiquidation($seller->id, '2026-09-12');
        $liq->refresh();

        $this->assertSame(1, (int) $liq->active_clients_with_credit_count);
        $this->assertSame(0, (int) $liq->clients_without_credit_count);

        $this->assertEqualsWithDelta(
            4710.55,
            (float) $liq->irrecoverable_credits_amount,
            0.01,
            'Un cambio de conteo no tiene permitido reescribir montos.'
        );
    }

    /**
     * La contracara: cuando el que cambia es un MONTO, el recálculo sigue
     * persistiendo todo como siempre. El blindaje no debe convertir la
     * liquidación en algo que ya no se actualiza.
     */
    public function test_un_cambio_de_monto_si_persiste_el_recalculo(): void
    {
        $seller = $this->makeSeller();
        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-01');

        $svc = app(LiquidationService::class);
        $svc->getOrCreateLiquidation($seller->id, '2026-09-12', 'America/Lima');
        $svc->recalculateLiquidation($seller->id, '2026-09-12');

        DB::table('expenses')->insert([
            'value' => 41,
            'description' => 'Gasolina',
            'user_id' => $seller->user_id,
            'status' => 'Aprobado',
            'business_date' => '2026-09-12',
            'business_timezone' => 'America/Lima',
            'created_at' => '2026-09-12 12:00:00',
            'updated_at' => '2026-09-12 12:00:00',
        ]);

        $svc->recalculateLiquidation($seller->id, '2026-09-12');

        $liq = DB::table('liquidations')->where('seller_id', $seller->id)->first();
        $this->assertEqualsWithDelta(41, (float) $liq->total_expenses, 0.01);
    }

    /**
     * La ficha de un vendedor DADO DE BAJA tiene que seguir mostrando su
     * historia.
     *
     * getClientCreditStateBySeller filtra los vendedores borrados —correcto
     * para el resumen, que agrega rutas activas—, y al reusarlo sin más la
     * liquidación individual pasaba a mostrar 0/0 en las 934 liquidaciones de
     * los 58 vendedores de baja del sistema. Es el mismo problema que
     * calculateLiquidationMetrics ya resuelve con withTrashed() para gastos e
     * ingresos: "un vendedor dado de baja seguía resolviendo a null y sus
     * gastos e ingresos históricos se calculaban en CERO".
     */
    public function test_un_vendedor_dado_de_baja_conserva_sus_conteos(): void
    {
        $seller = $this->makeSeller();
        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-01');
        $this->makeClient($seller); // sin crédito

        $antes = $this->counts($seller, '2026-09-12');
        $this->assertSame(1, $antes['active_clients_with_credit_count']);
        $this->assertSame(1, $antes['clients_without_credit_count']);

        // Se da de baja al vendedor: su historia no cambia.
        DB::table('sellers')->where('id', $seller->id)
            ->update(['deleted_at' => '2026-09-13 10:00:00']);

        $despues = $this->counts($seller, '2026-09-12');
        $this->assertSame(1, $despues['active_clients_with_credit_count'], 'Un vendedor de baja no puede quedar en 0.');
        $this->assertSame(1, $despues['clients_without_credit_count']);
    }

    /**
     * La contracara: el RESUMEN sigue dejando fuera a los vendedores de baja.
     * El parámetro nuevo es opt-in y no puede cambiar lo que ya mostraba.
     */
    public function test_el_resumen_sigue_excluyendo_a_los_vendedores_de_baja(): void
    {
        $seller = $this->makeSeller();
        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-01');

        $svc = app(LiquidationService::class);
        $this->assertArrayHasKey($seller->id, $svc->getClientCreditStateBySeller('2026-09-12', null, [$seller->id]));

        DB::table('sellers')->where('id', $seller->id)
            ->update(['deleted_at' => '2026-09-13 10:00:00']);

        $this->assertArrayNotHasKey(
            $seller->id,
            $svc->getClientCreditStateBySeller('2026-09-12', null, [$seller->id]),
            'El resumen agrega rutas activas: un vendedor de baja no debe aparecer.'
        );
    }
    public function test_la_liquidacion_graba_los_conteos_corregidos(): void
    {
        $seller = $this->makeSeller();
        $this->makeCredit($seller, $this->makeClient($seller), '2026-09-01');
        $roto = $this->makeClient($seller);
        $this->makeCredit($seller, $roto, '2026-09-01', 'Vigente', true);

        $svc = app(LiquidationService::class);
        $liq = $svc->getOrCreateLiquidation($seller->id, '2026-09-12', 'America/Lima');
        $svc->recalculateLiquidation($seller->id, '2026-09-12');
        $liq->refresh();

        $this->assertSame(1, (int) $liq->active_clients_with_credit_count);
        $this->assertSame(1, (int) $liq->clients_without_credit_count);
    }
}
