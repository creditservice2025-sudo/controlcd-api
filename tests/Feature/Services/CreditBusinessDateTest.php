<?php

namespace Tests\Feature\Services;

use App\Helpers\TimezoneHelper;
use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Seller;
use App\Services\CreditService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Blinda el "día de negocio" de los créditos (business_date / business_timestamp /
 * business_timezone), que se congela a la zona horaria del VENDEDOR al crear el
 * crédito y se usa como base del filtro por fecha.
 *
 * Bug de origen: created_at se guarda en UTC y el filtro/pantalla dependían de la
 * zona del navegador de quien consultaba. Un crédito creado el 1/07 21:13 local
 * (Perú) se guarda como 2026-07-02 01:13 UTC; antes esto causaba confusión ("se
 * creó el 02 pero se lista en el 01"). Ahora business_date = 2026-07-01 es estable
 * y no depende de quién mira.
 */
class CreditBusinessDateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // limpiar el "now" congelado
        parent::tearDown();
    }

    /** Vendedor cuyo país define la zona horaria de negocio (via nombre de país). */
    private function makeSeller(string $countryName): Seller
    {
        $country = Country::factory()->create(['name' => $countryName]);
        $city = City::factory()->create(['country_id' => $country->id]);

        return Seller::factory()->create(['city_id' => $city->id]);
    }

    /** Persiste un crédito con los campos business_* ya estampados. */
    private function makeCreditWithStamp(Seller $seller, array $stamp): Credit
    {
        $client = Client::factory()->create([
            'seller_id' => $seller->id,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);

        return Credit::factory()->create(array_merge([
            'seller_id' => $seller->id,
            'client_id' => $client->id,
            'payment_frequency' => 'Diaria',
        ], $stamp));
    }

    private function fetch(int $sellerId, array $query): array
    {
        $request = Request::create('/test', 'GET', $query);
        $response = app(CreditService::class)->getSellerCreditsByDate($sellerId, $request, 15);

        return json_decode($response->getContent(), true);
    }

    // ----------------------------------------------------------------------
    // 1) El estampado se ancla a la zona del VENDEDOR, no a UTC
    // ----------------------------------------------------------------------

    public function test_business_stamp_se_ancla_a_la_zona_del_vendedor_no_utc(): void
    {
        // Instante real: 2026-07-02 01:13:30 UTC (el mismo del caso reportado).
        Carbon::setTestNow(Carbon::parse('2026-07-02 01:13:30', 'UTC'));

        $seller = $this->makeSeller('Perú'); // America/Lima (UTC-5)
        $stamp = TimezoneHelper::businessStampForSeller($seller);

        // En Lima son las 20:13:30 del 1/07 → el día de negocio es el 1, no el 2.
        $this->assertSame('2026-07-01', $stamp['business_date']);
        $this->assertSame('2026-07-01 20:13:30', $stamp['business_timestamp']);
        $this->assertSame('America/Lima', $stamp['business_timezone']);
    }

    /**
     * Mismo instante UTC, distinto país => puede caer en días distintos. Prueba
     * que anclar a la zona del vendedor es lo que importa (no un valor fijo).
     */
    public function test_mismo_instante_utc_distinto_pais_puede_cambiar_el_dia(): void
    {
        // 04:30 UTC del 2/07: en Lima (UTC-5) aún es 23:30 del 1; en Caracas
        // (UTC-4) ya es 00:30 del 2.
        Carbon::setTestNow(Carbon::parse('2026-07-02 04:30:00', 'UTC'));

        $peru = TimezoneHelper::businessStampForSeller($this->makeSeller('Perú'));
        $venezuela = TimezoneHelper::businessStampForSeller($this->makeSeller('Venezuela'));

        $this->assertSame('2026-07-01', $peru['business_date']);
        $this->assertSame('2026-07-02', $venezuela['business_date']);
    }

    public function test_seller_nulo_cae_al_timezone_por_defecto(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 01:13:30', 'UTC'));

        $stamp = TimezoneHelper::businessStampForSeller(null);

        // Default America/Lima => 1/07 20:13:30.
        $this->assertSame('America/Lima', $stamp['business_timezone']);
        $this->assertSame('2026-07-01', $stamp['business_date']);
    }

    /**
     * Con $moment explícito (caso importación): convierte ese instante UTC a la
     * zona del vendedor, sin depender del "now".
     */
    public function test_business_stamp_con_momento_explicito(): void
    {
        $seller = $this->makeSeller('Perú');
        $moment = Carbon::parse('2026-07-02 02:00:00', 'UTC'); // 21:00 del 1 en Lima

        $stamp = TimezoneHelper::businessStampForSeller($seller, $moment);

        $this->assertSame('2026-07-01', $stamp['business_date']);
        $this->assertSame('2026-07-01 21:00:00', $stamp['business_timestamp']);
    }

    // ----------------------------------------------------------------------
    // 2) El filtro usa business_date y NO depende de la zona del navegador
    // ----------------------------------------------------------------------

    public function test_filtro_usa_business_date_independiente_del_timezone_del_request(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 01:13:30', 'UTC'));
        $seller = $this->makeSeller('Perú');
        $this->makeCreditWithStamp($seller, TimezoneHelper::businessStampForSeller($seller));
        // business_date del crédito = 2026-07-01

        // El navegador está en UTC-4 (distinto al vendedor) — no debe alterar nada.
        $enDia1 = $this->fetch($seller->id, ['date' => '2026-07-01', 'timezone' => 'America/Santo_Domingo']);
        $this->assertCount(1, $enDia1['data']);

        $enDia2 = $this->fetch($seller->id, ['date' => '2026-07-02', 'timezone' => 'America/Santo_Domingo']);
        $this->assertCount(0, $enDia2['data']);
    }

    /**
     * Reproduce el escenario exacto reportado: crédito con created_at en UTC del
     * día 2 debe listarse bajo el día 1 (su día de negocio real en Perú).
     */
    public function test_credito_creado_utc_dia_2_se_lista_en_su_dia_de_negocio_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 01:13:30', 'UTC'));
        $seller = $this->makeSeller('Perú');

        $credit = $this->makeCreditWithStamp($seller, array_merge(
            TimezoneHelper::businessStampForSeller($seller),
            ['created_at' => '2026-07-02 01:13:30'] // UTC real
        ));

        $this->assertSame('2026-07-01', $credit->fresh()->business_date);

        $res = $this->fetch($seller->id, ['date' => '2026-07-01', 'timezone' => 'America/Lima']);
        $this->assertCount(1, $res['data']);
        $this->assertSame($credit->id, $res['data'][0]['id']);
    }

    public function test_rango_de_fechas_filtra_por_business_date(): void
    {
        $seller = $this->makeSeller('Perú');

        $this->makeCreditWithStamp($seller, [
            'business_date' => '2026-07-01',
            'business_timestamp' => '2026-07-01 10:00:00',
            'business_timezone' => 'America/Lima',
        ]);
        $this->makeCreditWithStamp($seller, [
            'business_date' => '2026-07-05',
            'business_timestamp' => '2026-07-05 10:00:00',
            'business_timezone' => 'America/Lima',
        ]);

        $dentro = $this->fetch($seller->id, [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-03',
            'timezone' => 'America/Lima',
        ]);
        $this->assertCount(1, $dentro['data']);
    }

    // ----------------------------------------------------------------------
    // 3) Fallback para créditos históricos sin business_date
    // ----------------------------------------------------------------------

    public function test_fallback_para_credito_legacy_sin_business_date(): void
    {
        $seller = $this->makeSeller('Perú');

        // Crédito "viejo": business_date NULL, solo created_at UTC dentro del día.
        $this->makeCreditWithStamp($seller, [
            'business_date' => null,
            'business_timestamp' => null,
            'business_timezone' => null,
            'created_at' => '2026-07-01 10:00:00', // UTC → dentro del 1/07 en Lima
        ]);

        $res = $this->fetch($seller->id, ['date' => '2026-07-01', 'timezone' => 'America/Lima']);
        $this->assertCount(1, $res['data']);
    }
}
