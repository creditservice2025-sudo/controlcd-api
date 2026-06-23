<?php

namespace Tests\Feature\Services;

use App\Models\City;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Seller;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Verifica el flujo de moneda agregado a getSellerCreditsByDate().
 *
 * El frontend (vista del vendedor: Ventas, Pagos, Egresos/Ingresos, cards de
 * cartera) muestra los montos con el símbolo del país donde opera el vendedor.
 * Ese símbolo se deriva del código ISO que este servicio resuelve por la
 * cadena vendedor -> ciudad -> país -> currency, con fallback a 'PEN'.
 *
 * Si esta resolución queda mal aplicada (no lee el país, o no cae al default),
 * toda la vista mostraría una moneda incorrecta. Estos tests blindan ese punto.
 */
class CreditServiceCurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crea un vendedor cuyo país tiene el código de moneda indicado.
     * $currency = null simula un país sin moneda configurada.
     */
    private function makeSellerWithCountryCurrency(?string $currency): Seller
    {
        $country = Country::factory()->create(['currency' => $currency]);
        $city = City::factory()->create(['country_id' => $country->id]);

        return Seller::factory()->create(['city_id' => $city->id]);
    }

    /**
     * Llama al servicio tal como lo hace el controlador y devuelve el
     * código de moneda que viaja en el payload (lo que consume el frontend).
     */
    private function resolveCurrency(int $sellerId): ?string
    {
        $request = Request::create('/test', 'GET', [
            'timezone' => 'America/Lima',
            'all_records' => '1',
        ]);

        $response = app(CreditService::class)
            ->getSellerCreditsByDate($sellerId, $request, 15);

        $payload = json_decode($response->getContent(), true);

        return $payload['currency'] ?? null;
    }

    public function test_usa_la_moneda_del_pais_del_vendedor(): void
    {
        $seller = $this->makeSellerWithCountryCurrency('BOB');

        $this->assertSame('BOB', $this->resolveCurrency($seller->id));
    }

    /**
     * Asegura que lee el valor real del país y no devuelve un símbolo fijo:
     * un país distinto debe producir una moneda distinta.
     */
    public function test_lee_el_valor_real_y_no_un_valor_hardcodeado(): void
    {
        $seller = $this->makeSellerWithCountryCurrency('COP');

        $this->assertSame('COP', $this->resolveCurrency($seller->id));
    }

    public function test_cae_a_pen_cuando_el_pais_no_tiene_moneda_configurada(): void
    {
        $seller = $this->makeSellerWithCountryCurrency(null);

        $this->assertSame('PEN', $this->resolveCurrency($seller->id));
    }

    /**
     * El campo currency debe viajar junto a los datos de los créditos, no en
     * lugar de ellos: el frontend usa ambos en la misma respuesta.
     */
    public function test_la_moneda_viaja_junto_a_los_creditos(): void
    {
        $seller = $this->makeSellerWithCountryCurrency('BOB');

        // El cliente necesita geolocation (columna JSON NOT NULL); la factory
        // por defecto no la setea, así que la proveemos explícitamente.
        $client = \App\Models\Client::factory()->create([
            'seller_id' => $seller->id,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);
        Credit::factory()->create([
            'seller_id' => $seller->id,
            'client_id' => $client->id,
            // El default de la factory ('diario') no encaja en el enum real
            // de la columna; usamos un valor válido.
            'payment_frequency' => 'Diaria',
        ]);

        $request = Request::create('/test', 'GET', [
            'timezone' => 'America/Lima',
            'all_records' => '1',
        ]);

        $response = app(CreditService::class)
            ->getSellerCreditsByDate($seller->id, $request, 15);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame('BOB', $payload['currency']);
        $this->assertCount(1, $payload['data']);
    }
}
