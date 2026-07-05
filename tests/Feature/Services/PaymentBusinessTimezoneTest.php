<?php

namespace Tests\Feature\Services;

use App\Http\Requests\Payment\PaymentRequest;
use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Seller;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El día de negocio del PAGO se calcula con la zona del VENDEDOR (BD), no con la
 * que reporta el teléfono. Así un dispositivo con zona mal —o un supervisor
 * cargando desde otro país— no corre el día del pago.
 */
class PaymentBusinessTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_el_dia_del_pago_usa_la_zona_del_vendedor_no_la_del_telefono(): void
    {
        // Instante en el que UTC ya es 05, pero en Bogotá (UTC-5) sigue siendo 04.
        Carbon::setTestNow(Carbon::parse('2026-07-05 02:00:00', 'UTC'));

        // Vendedor de Colombia → America/Bogota.
        $country = Country::factory()->create(['name' => 'Colombia']);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create(['city_id' => $city->id]);
        $client = Client::factory()->create([
            'seller_id' => $seller->id,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);
        $credit = Credit::factory()->create([
            'seller_id' => $seller->id,
            'client_id' => $client->id,
            'payment_frequency' => 'Diaria',
            'status' => 'Vigente',
        ]);

        // Registra un "No pago" (amount 0) mandando client_timezone = UTC.
        $request = PaymentRequest::create('/payments', 'POST', [
            'credit_id' => $credit->id,
            'amount' => 0,
            'status' => 'No Pagado',
            'payment_date' => '2026-07-04',
            'client_timezone' => 'UTC',
            'payment_method' => 'cash',
        ]);
        $request->setContainer($this->app)->setRedirector($this->app->make(\Illuminate\Routing\Redirector::class));
        $request->validateResolved();

        $this->actingAs($seller->user);
        app(PaymentService::class)->create($request);

        $payment = DB::table('payments')->where('credit_id', $credit->id)->latest('id')->first();
        $this->assertNotNull($payment);

        // Clave: el día/zona de negocio son de Bogotá (04), NO de UTC (05).
        $this->assertSame('America/Bogota', $payment->business_timezone);
        $this->assertSame('2026-07-04', substr((string) $payment->business_date, 0, 10));

        // La zona reportada por el dispositivo se conserva solo para auditoría.
        $this->assertSame('UTC', $payment->client_timezone);
    }
}
