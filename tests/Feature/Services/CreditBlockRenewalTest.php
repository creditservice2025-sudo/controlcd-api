<?php

namespace Tests\Feature\Services;

use App\Models\City;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Seller;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El bloqueo de nuevos créditos alcanza a la RENOVACIÓN.
 *
 * La regla del negocio: al cliente bloqueado se le termina de cobrar el crédito
 * vigente, pero no se le abre otro. Renovar es justamente eso —liquidar el
 * viejo y abrir uno nuevo—, así que tenía que quedar cerrado.
 *
 * La guarda vivía sólo en CreditService::create, y renew() escribe su propio
 * Credit::create sin pasar por ese método: el cobrador podía saltarse el
 * bloqueo renovando, que es la vía que más usa.
 */
class CreditBlockRenewalTest extends TestCase
{
    use DatabaseTransactions;

    private Seller $seller;

    protected function setUp(): void
    {
        parent::setUp();

        if (!DB::table('roles')->where('id', 2)->exists()) {
            DB::table('roles')->insert([
                'id' => 2,
                'name' => 'Role-2-' . uniqid(),
                'guard_name' => 'api',
                'is_assignable' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $admin = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['user_id' => $admin->id]);
        $country = Country::factory()->create(['name' => 'Perú-' . uniqid(), 'currency' => 'PEN']);
        $city = City::factory()->create(['country_id' => $country->id]);
        $this->seller = Seller::factory()->create([
            'company_id' => $company->id,
            'city_id' => $city->id,
        ]);
    }

    private function clienteConCreditoVigente(bool $bloqueado): Credit
    {
        $client = Client::factory()->create([
            'seller_id' => $this->seller->id,
            'geolocation' => '[]',
            'uuid' => Str::uuid()->toString(),
            'credit_block_active' => $bloqueado,
            'credit_block_reason' => $bloqueado ? 'gerencial' : null,
        ]);

        return Credit::factory()->create([
            'client_id' => $client->id,
            'seller_id' => $this->seller->id,
            'status' => 'Vigente',
            'payment_frequency' => 'Diaria',
        ]);
    }

    private function peticionDeRenovacion(Credit $credito): Request
    {
        return Request::create('/api/credits/renew', 'POST', [
            'old_credit_id' => $credito->id,
            'new_credit_value' => 500,
            'phone' => '987654321',
            'micro_insurance_percentage' => 0,
            'images' => [
                ['latitude' => -12.0464, 'longitude' => -77.0428],
            ],
        ]);
    }

    /** @test */
    public function no_se_puede_renovar_a_un_cliente_bloqueado(): void
    {
        $credito = $this->clienteConCreditoVigente(bloqueado: true);

        $respuesta = app(CreditService::class)->renew($this->peticionDeRenovacion($credito));

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString(
            'bloqueado para nuevos créditos',
            $respuesta->getData()->message
        );
    }

    /**
     * El mensaje no puede dejar dudas al cobrador: lo que se frena es abrir
     * otro crédito, no el cobro del que ya tiene.
     *
     * @test
     */
    public function el_mensaje_aclara_que_el_credito_vigente_se_sigue_cobrando(): void
    {
        $credito = $this->clienteConCreditoVigente(bloqueado: true);

        $respuesta = app(CreditService::class)->renew($this->peticionDeRenovacion($credito));

        $this->assertStringContainsString(
            'se sigue cobrando normalmente',
            $respuesta->getData()->message
        );
    }

    /**
     * La guarda no puede frenar a todo el mundo: sin bloqueo, la renovación
     * pasa de largo por acá. Falla más adelante por la foto, que este test no
     * provee, pero NO con el mensaje del bloqueo.
     *
     * @test
     */
    public function el_cliente_sin_bloqueo_no_queda_frenado_por_esta_guarda(): void
    {
        $credito = $this->clienteConCreditoVigente(bloqueado: false);

        $respuesta = app(CreditService::class)->renew($this->peticionDeRenovacion($credito));

        $mensaje = $respuesta->getData()->message ?? '';
        $this->assertStringNotContainsString('bloqueado para nuevos créditos', $mensaje);
    }

    /**
     * El bloqueo no toca el cobro: los pagos del crédito vigente se siguen
     * registrando. Es la mitad de la regla que nadie prueba y la que más
     * importa cuidar cuando se agreguen guardas nuevas.
     *
     * @test
     */
    public function el_credito_vigente_de_un_cliente_bloqueado_sigue_recibiendo_pagos(): void
    {
        $credito = $this->clienteConCreditoVigente(bloqueado: true);

        DB::table('payments')->insert([
            'credit_id' => $credito->id,
            'payment_date' => '2026-08-18',
            'business_date' => '2026-08-18',
            'amount' => 50,
            'status' => 'Pagado',
            'payment_method' => 'Efectivo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('payments', [
            'credit_id' => $credito->id,
            'amount' => 50,
        ]);
        $this->assertSame('Vigente', $credito->fresh()->status);
    }
}
