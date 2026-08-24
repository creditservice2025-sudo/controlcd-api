<?php

namespace Tests\Feature\Services;

use App\Models\City;
use App\Models\Client;
use App\Models\ClientComment;
use App\Models\Company;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Payment;
use App\Models\Seller;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El administrador tiene que poder LEER el motivo de la jornada.
 *
 * La lista del día se arma desde los créditos que tuvieron pago, así que un
 * cliente sin movimiento no producía fila y su comentario no se leía en ningún
 * lado. Medido sobre producción cuando se detectó: 1.810 comentarios
 * invisibles, 238 de ellos con resultado "No pagó".
 *
 * Las filas de gestión son OPT-IN porque el mismo endpoint lo consume el APK ya
 * instalado, que no sabe pintarlas.
 */
class PaymentCommentsVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Zona del servidor Y del vendedor. Se fijan IGUALES a propósito, porque es
     * la situación de producción (APP_TIMEZONE=America/Lima y cobradores en
     * Perú y Colombia, ambos UTC-5). Si se dejaran distintas, el desfase que
     * aparece no sería un fallo del filtro sino un día de negocio que
     * legítimamente cae en otra fecha, y el test estaría midiendo otra cosa.
     */
    private const TZ = 'America/Lima';

    protected function setUp(): void
    {
        parent::setUp();
        // client_comments.created_at se guarda en la zona de la app: para que
        // el test valga hay que reproducir la de producción, no la de phpunit.
        config(['app.timezone' => self::TZ]);
    }

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

    private function makeScenario(): array
    {
        $this->ensureRole(2);
        $admin = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['user_id' => $admin->id]);
        // El día de negocio lo decide la zona del país del vendedor: se fija
        // explícitamente en vez de heredar la del factory, que varía.
        $country = Country::factory()->create(['timezone' => self::TZ]);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create([
            'company_id' => $company->id,
            'city_id' => $city->id,
        ]);

        $hacer = function (string $nombre) use ($seller) {
            $client = Client::factory()->create([
                'seller_id' => $seller->id,
                'name' => $nombre,
                'geolocation' => '[]',
                'uuid' => Str::uuid()->toString(),
            ]);
            $credit = Credit::factory()->create([
                'client_id' => $client->id,
                'seller_id' => $seller->id,
                'payment_frequency' => 'Diaria',
            ]);

            return [$client, $credit];
        };

        [$conPago, $creditoConPago] = $hacer('Cliente con pago');
        [$sinPago] = $hacer('Cliente sin pago');

        // Día de negocio del vendedor, no el del reloj del servidor.
        $hoy = now(self::TZ)->toDateString();

        Payment::forceCreate([
            'credit_id' => $creditoConPago->id,
            'payment_date' => $hoy,
            'amount' => 0,
            'status' => 'No pagado',
            'business_date' => $hoy,
        ]);

        // Hora explícita: con now() el test pasaría o fallaría según a qué hora
        // se corriera, que es justo lo que un test no puede permitirse.
        ClientComment::forceCreate([
            'client_id' => $conPago->id,
            'user_id' => $admin->id,
            'body' => 'motivo del cliente con pago',
            'created_at' => $hoy . ' 12:00:00',
            'updated_at' => $hoy . ' 12:00:00',
        ]);
        ClientComment::forceCreate([
            'client_id' => $sinPago->id,
            'user_id' => $admin->id,
            'body' => 'motivo del cliente sin pago',
            'created_at' => $hoy . ' 12:00:00',
            'updated_at' => $hoy . ' 12:00:00',
        ]);

        Auth::login($admin);

        return compact('admin', 'seller', 'conPago', 'sinPago', 'hoy');
    }

    private function llamar(Seller $seller, string $hoy, array $extra = []): array
    {
        $request = Request::create('/api/payments/seller/' . $seller->id . '/all', 'GET', array_merge([
            'date' => $hoy,
            'timezone' => self::TZ,
        ], $extra));
        app()->instance('request', $request);

        $respuesta = app(PaymentService::class)->getAllPaymentsBySeller($seller->id, $request);

        return json_decode($respuesta->getContent(), true)['data'];
    }

    private function cuerpos(array $filas): array
    {
        $out = [];
        foreach ($filas as $fila) {
            foreach ($fila['comments'] ?? [] as $c) {
                $out[] = $c['body'];
            }
        }

        return $out;
    }

    /**
     * La fila de un pago lleva el comentario del cliente dentro. Antes viajaba
     * solo un CONTEO, y encima el histórico del cliente y no el del día.
     *
     * @test
     */
    public function el_comentario_viaja_en_la_fila_del_pago(): void
    {
        $e = $this->makeScenario();

        $filas = $this->llamar($e['seller'], $e['hoy']);

        $this->assertContains('motivo del cliente con pago', $this->cuerpos($filas));
    }

    /**
     * Sin el parámetro, la respuesta es la de siempre: el APK ya instalado no
     * puede recibir filas que no sabe pintar (monto null y un botón "Eliminar
     * pago" sobre algo que no es un pago).
     *
     * @test
     */
    public function sin_el_parametro_no_llegan_las_gestiones_sin_pago(): void
    {
        $e = $this->makeScenario();

        $filas = $this->llamar($e['seller'], $e['hoy']);

        $gestiones = array_filter($filas, fn ($f) => !empty($f['is_management_only']));
        $this->assertCount(0, $gestiones, 'El APK viejo no debe recibir filas de gestión.');
        $this->assertNotContains('motivo del cliente sin pago', $this->cuerpos($filas));
    }

    /**
     * Con el parámetro sí: el comentario del cliente que no tuvo movimiento
     * aparece, marcado como gestión y sin monto.
     *
     * @test
     */
    public function con_el_parametro_llega_la_gestion_sin_pago(): void
    {
        $e = $this->makeScenario();

        $filas = $this->llamar($e['seller'], $e['hoy'], ['include_managements' => 'true']);

        $gestiones = array_values(array_filter($filas, fn ($f) => !empty($f['is_management_only'])));

        $this->assertCount(1, $gestiones);
        $this->assertSame($e['sinPago']->id, $gestiones[0]['client_id']);
        $this->assertNull($gestiones[0]['amount'], 'Una gestión no tiene monto: no hubo movimiento.');
        $this->assertNull($gestiones[0]['payment_id'], 'Una gestión no es un pago y no debe poder eliminarse.');
        $this->assertContains('motivo del cliente sin pago', $this->cuerpos($filas));
    }

    /**
     * Un cliente que ya tiene fila de pago NO se repite como gestión: su
     * comentario viaja dentro de la fila del pago y nada más.
     *
     * @test
     */
    public function el_cliente_con_pago_no_se_duplica_como_gestion(): void
    {
        $e = $this->makeScenario();

        $filas = $this->llamar($e['seller'], $e['hoy'], ['include_managements' => 'true']);

        $idsDeGestion = array_map(
            fn ($f) => $f['client_id'],
            array_filter($filas, fn ($f) => !empty($f['is_management_only']))
        );

        $this->assertNotContains(
            $e['conPago']->id,
            $idsDeGestion,
            'El cliente con pago ya tiene fila: no debe aparecer también como gestión.'
        );

        $apariciones = array_count_values($this->cuerpos($filas));
        $this->assertSame(1, $apariciones['motivo del cliente con pago'] ?? 0);
    }

    /**
     * client_comments.created_at NO está en UTC: lo escribe el timestamp por
     * defecto de Eloquent, o sea en la zona de la app. La versión anterior
     * traducía la ventana del día a UTC y la comparaba contra ese valor, así
     * que se corría el equivalente al offset —cinco horas en Perú— y perdía
     * todo lo escrito entre medianoche y las 05:00.
     *
     * Con la ventana en la zona de la app este comentario de las 03:00 entra;
     * con la anterior quedaba fuera. Es el caso que distingue una de otra.
     *
     * Se cubren las DOS vías, que tienen cada una su propia ventana y por lo
     * tanto pueden romperse por separado:
     *   - cliente CON pago  -> commentsForPaymentsView
     *   - cliente SIN pago  -> managementsWithoutPayment
     *
     * @test
     */
    public function el_comentario_de_madrugada_no_se_pierde(): void
    {
        $e = $this->makeScenario();

        foreach ([['conPago', 'madrugada en cliente con pago'], ['sinPago', 'madrugada en cliente sin pago']] as [$quien, $texto]) {
            ClientComment::forceCreate([
                'client_id' => $e[$quien]->id,
                'user_id' => $e['admin']->id,
                'body' => $texto,
                'created_at' => $e['hoy'] . ' 03:00:00',
                'updated_at' => $e['hoy'] . ' 03:00:00',
            ]);
        }

        $cuerpos = $this->cuerpos($this->llamar($e['seller'], $e['hoy'], ['include_managements' => 'true']));

        $this->assertContains(
            'madrugada en cliente con pago',
            $cuerpos,
            'La ventana de commentsForPaymentsView perdió el comentario de madrugada.'
        );
        $this->assertContains(
            'madrugada en cliente sin pago',
            $cuerpos,
            'La ventana de managementsWithoutPayment perdió el comentario de madrugada.'
        );
    }
}
