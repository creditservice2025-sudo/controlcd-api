<?php

namespace Tests\Feature\Services;

use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Seller;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Blinda la guardia anti-typo de pagos: un pago no puede exceder la deuda
 * pendiente del crédito (más 10% de tolerancia). La deuda se calcula desde
 * installments, NO desde credits.remaining_amount.
 *
 * Previene datos corruptos como el histórico #5435 (pago 100x la deuda) y los
 * sobrepagos por dígito de más.
 */
class PaymentDebtGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Crea un crédito con 4 cuotas de $quota c/u (deuda = 4*quota), sin pagos. */
    private function makeCreditWithInstallments(float $quota = 90000): Credit
    {
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
            'credit_value' => 300000,
            'total_interest' => 20,
            'total_amount' => $quota * 4,
            'number_installments' => 4,
            'payment_frequency' => 'Semanal',
        ]);
        for ($n = 1; $n <= 4; $n++) {
            DB::table('installments')->insert([
                'credit_id' => $credit->id,
                'quota_number' => $n,
                'due_date' => date('Y-m-d', strtotime("2026-02-16 +{$n} week")),
                'quota_amount' => $quota,
                'paid_amount' => 0,
                'status' => 'Pendiente',
                'created_at' => '2026-02-16 00:00:00',
                'updated_at' => '2026-02-16 00:00:00',
            ]);
        }
        return $credit;
    }

    private function guard(): PaymentService
    {
        return app(PaymentService::class);
    }

    public function test_rechaza_pago_100x_la_deuda(): void
    {
        $credit = $this->makeCreditWithInstallments(90000); // deuda 360.000

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('excede la deuda pendiente');
        $this->guard()->assertAmountWithinDebt($credit, 36000000); // 100x
    }

    public function test_rechaza_sobrepago_moderado(): void
    {
        $credit = $this->makeCreditWithInstallments(90000); // deuda 360.000

        // 2x la deuda (como los casos reales del seller 218) → rechazado.
        $this->expectException(\Exception::class);
        $this->guard()->assertAmountWithinDebt($credit, 720000);
    }

    public function test_acepta_pago_de_la_deuda_exacta(): void
    {
        $credit = $this->makeCreditWithInstallments(90000); // deuda 360.000

        // No debe lanzar excepción.
        $this->guard()->assertAmountWithinDebt($credit, 360000);
        $this->assertTrue(true);
    }

    public function test_acepta_pago_parcial_y_dentro_de_tolerancia(): void
    {
        $credit = $this->makeCreditWithInstallments(90000); // deuda 360.000

        $this->guard()->assertAmountWithinDebt($credit, 90000);   // una cuota
        $this->guard()->assertAmountWithinDebt($credit, 380000);  // +5.5% (dentro del 10%)
        $this->assertTrue(true);
    }

    public function test_ignora_monto_cero_y_credito_sin_cuotas(): void
    {
        $credit = $this->makeCreditWithInstallments(90000);
        $this->guard()->assertAmountWithinDebt($credit, 0); // "No pago": no valida

        // Crédito sin cuotas: no se evalúa (no bloquea flujos atípicos).
        $country = Country::factory()->create(['name' => 'Colombia']);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create(['city_id' => $city->id]);
        $client = Client::factory()->create([
            'seller_id' => $seller->id,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);
        $noInstallments = Credit::factory()->create([
            'seller_id' => $seller->id,
            'client_id' => $client->id,
            'payment_frequency' => 'Diaria',
        ]);
        $this->guard()->assertAmountWithinDebt($noInstallments, 999999999);
        $this->assertTrue(true);
    }
}
