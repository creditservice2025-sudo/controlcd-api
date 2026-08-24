<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\PaymentController;
use App\Models\Client;
use App\Models\Company;
use App\Models\Credit;
use App\Models\Seller;
use App\Models\User;
use App\Models\UserRoute;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * IDOR en los cuatro endpoints de PaymentController que reciben el id del
 * vendedor por la URL (OWASP A01, CWE-639).
 *
 * Ninguno validaba ese id: cualquier usuario autenticado podía pedir la jornada
 * de un vendedor de OTRA empresa. No es solo cuestión de montos — estas
 * respuestas llevan nombre y DNI del cliente, los comentarios del día y la
 * ubicación GPS desde donde se escribieron, o sea datos de personas.
 *
 * Complementa TenantScopeIdorTest, que cubre el helper Tenant a nivel unitario;
 * acá se comprueba que los controladores efectivamente lo invoquen.
 */
class PaymentSellerEndpointsIdorTest extends TestCase
{
    use DatabaseTransactions;

    /** Endpoints bajo prueba: método del controlador => argumentos extra. */
    private const SELLER_ENDPOINTS = [
        'indexBySeller',
        'getSellerPayments',
        'getSellerDelDiaLite',
        'getSellerCobradoLite',
    ];

    private function ensureRole(int $id): void
    {
        if (!DB::table('roles')->where('id', $id)->exists()) {
            DB::table('roles')->insert([
                'id' => $id,
                'name' => 'Role-' . $id . '-' . uniqid(),
                'guard_name' => 'web',
                'is_assignable' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Empresa con su admin dueño (Company.user_id) y un vendedor con cartera. */
    private function makeCompany(?int $sellerUserId = null): array
    {
        $this->ensureRole(2);
        $admin = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['user_id' => $admin->id]);
        $seller = Seller::factory()->create(array_filter([
            'company_id' => $company->id,
            'user_id' => $sellerUserId,
        ], fn ($v) => !is_null($v)));
        $client = Client::factory()->create([
            'seller_id' => $seller->id,
            'geolocation' => '[]',
            'uuid' => Str::uuid()->toString(),
        ]);
        Credit::factory()->create([
            'client_id' => $client->id,
            'seller_id' => $seller->id,
            'payment_frequency' => 'Diaria',
        ]);

        return compact('admin', 'company', 'seller', 'client');
    }

    private function callEndpoint(string $method, $sellerId)
    {
        $request = Request::create('/api/payments/seller/' . $sellerId, 'GET', [
            'date' => now()->toDateString(),
            'timezone' => 'America/Lima',
        ]);
        app()->instance('request', $request);

        return app(PaymentController::class)->{$method}($request, $sellerId);
    }

    /**
     * Un admin no puede leer la jornada de un vendedor de otra empresa por
     * ninguno de los cuatro endpoints.
     *
     * @test
     */
    public function admin_no_accede_a_vendedor_de_otra_empresa(): void
    {
        $propia = $this->makeCompany();
        $ajena = $this->makeCompany();
        Auth::login($propia['admin']);

        foreach (self::SELLER_ENDPOINTS as $method) {
            try {
                $this->callEndpoint($method, $ajena['seller']->id);
                $this->fail("IDOR en {$method}: se esperaba 403 sobre un vendedor ajeno.");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(), "{$method} debe responder 403");
            }
        }
    }

    /**
     * Garantía de NO-RUPTURA: sobre su propio vendedor los cuatro siguen
     * respondiendo. Un guard que bloquea de más rompe la operación en campo.
     *
     * @test
     */
    public function admin_si_accede_a_su_propio_vendedor(): void
    {
        $propia = $this->makeCompany();
        Auth::login($propia['admin']);

        foreach (self::SELLER_ENDPOINTS as $method) {
            $response = $this->callEndpoint($method, $propia['seller']->id);
            $this->assertSame(
                200,
                $response->getStatusCode(),
                "{$method} debe seguir respondiendo 200 sobre el vendedor propio"
            );
        }
    }

    /**
     * El cobrador solo ve lo suyo: su propio seller sí, el de un compañero no,
     * aunque sean de la misma empresa.
     *
     * @test
     */
    public function cobrador_solo_accede_a_su_propio_seller(): void
    {
        $this->ensureRole(5);
        $cobrador = User::factory()->create(['role_id' => 5]);
        $propia = $this->makeCompany($cobrador->id);
        // Compañero de la MISMA empresa: la barrera no es solo la empresa.
        $companero = Seller::factory()->create(['company_id' => $propia['company']->id]);

        Auth::login($cobrador);

        $response = $this->callEndpoint('getSellerPayments', $propia['seller']->id);
        $this->assertSame(200, $response->getStatusCode(), 'su propio seller debe responder');

        try {
            $this->callEndpoint('getSellerPayments', $companero->id);
            $this->fail('IDOR: un cobrador leyó la jornada de un compañero.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /**
     * El supervisor accede a los vendedores de sus rutas y a ninguno más.
     *
     * @test
     */
    public function supervisor_accede_solo_a_los_vendedores_de_sus_rutas(): void
    {
        $this->ensureRole(6);
        $supervisor = User::factory()->create(['role_id' => 6]);
        $asignada = $this->makeCompany();
        $ajena = $this->makeCompany();

        UserRoute::forceCreate([
            'user_id' => $supervisor->id,
            'seller_id' => $asignada['seller']->id,
        ]);

        Auth::login($supervisor);

        $response = $this->callEndpoint('getSellerPayments', $asignada['seller']->id);
        $this->assertSame(200, $response->getStatusCode(), 'la ruta asignada debe responder');

        try {
            $this->callEndpoint('getSellerPayments', $ajena['seller']->id);
            $this->fail('IDOR: un supervisor leyó un vendedor fuera de sus rutas.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /**
     * El 403 tiene que SALIR. Los catch de este controlador atrapan \Exception,
     * y HttpException lo es: sin el re-throw explícito el guard quedaba
     * convertido en un 500 con cuerpo de éxito y el acceso seguía adelante.
     *
     * @test
     */
    public function el_403_no_queda_atrapado_por_el_catch_del_controlador(): void
    {
        $propia = $this->makeCompany();
        $ajena = $this->makeCompany();
        Auth::login($propia['admin']);

        foreach (self::SELLER_ENDPOINTS as $method) {
            try {
                $response = $this->callEndpoint($method, $ajena['seller']->id);
                $this->fail(
                    "{$method}: el guard fue tragado por el catch y devolvió "
                    . $response->getStatusCode() . ' en vez de propagar el 403.'
                );
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }
}
