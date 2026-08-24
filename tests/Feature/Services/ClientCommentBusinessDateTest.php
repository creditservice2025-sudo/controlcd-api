<?php

namespace Tests\Feature\Services;

use App\Http\Controllers\ClientCommentController;
use App\Models\City;
use App\Models\Client;
use App\Models\ClientComment;
use App\Models\ClientVisit;
use App\Models\Company;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Payment;
use App\Models\Seller;
use App\Models\User;
use App\Services\ClientVisitService;
use App\Services\GeolocationHistoryService;
use App\Services\PaymentService;
use App\Services\ReverseGeocodeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El día de negocio del comentario tiene que salir de la zona del VENDEDOR.
 *
 * Hasta el anclaje, `client_comments` solo tenía `created_at`, que lo escribe el
 * timestamp por defecto de Eloquent con el reloj GLOBAL de la aplicación
 * (config('app.timezone'), America/Lima en producción). La jornada del
 * comentario se deducía traduciendo esa hora, y solo coincidía con la real
 * mientras el vendedor estuviera en un país UTC-5.
 *
 * No es hipotético: en la base hay vendedores operando en Bolivia (UTC-4),
 * Venezuela (UTC-4) y Argentina (UTC-3). Para ellos, un comentario escrito en la
 * primera o la última hora de la jornada caía fechado en el día equivocado.
 *
 * Estos tests fijan el instante con Carbon::setTestNow: sin eso el resultado
 * dependería de la hora a la que se corriera la suite, que es justo lo que un
 * test no puede permitirse.
 */
class ClientCommentBusinessDateTest extends TestCase
{
    use DatabaseTransactions;

    /** Zona de la APLICACIÓN, la de producción. NO la del vendedor. */
    private const APP_TZ = 'America/Lima';

    private string $phpTzOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        // Producción tiene APP_TIMEZONE=America/Lima, y Laravel al arrancar hace
        // date_default_timezone_set() con ese valor. Cambiar solo la config NO
        // mueve la zona de PHP —que es la que termina escribiendo created_at—,
        // así que el test estaría corriendo con dos relojes distintos a los de
        // producción y mediría otra cosa. Se fijan los dos.
        $this->phpTzOriginal = date_default_timezone_get();
        config(['app.timezone' => self::APP_TZ]);
        date_default_timezone_set(self::APP_TZ);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->phpTzOriginal);
        parent::tearDown();
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

    /**
     * Escenario con el vendedor en el país indicado.
     *
     * El nombre del país NO es decorativo: TimezoneHelper resuelve la zona por
     * nombre (COUNTRY_TIMEZONES), mientras que la lectura de pagos por vendedor
     * usa la COLUMNA countries.timezone. Se fijan las dos coherentes para que el
     * test mida el anclaje del comentario y no esa divergencia.
     */
    private function makeScenario(string $countryName, string $sellerTz): array
    {
        $this->ensureRole(2);
        $admin = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['user_id' => $admin->id]);
        $country = Country::factory()->create([
            'name' => $countryName,
            'timezone' => $sellerTz,
        ]);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create([
            'company_id' => $company->id,
            'city_id' => $city->id,
            'user_id' => $admin->id,
        ]);
        $client = Client::factory()->create([
            'seller_id' => $seller->id,
            'name' => 'Cliente ' . $countryName,
            'geolocation' => '[]',
            'uuid' => Str::uuid()->toString(),
        ]);
        $credit = Credit::factory()->create([
            'client_id' => $client->id,
            'seller_id' => $seller->id,
            'payment_frequency' => 'Diaria',
        ]);

        Auth::login($admin);

        return compact('admin', 'company', 'country', 'seller', 'client', 'credit');
    }

    private function makeCategory(): int
    {
        return DB::table('comment_categories')->insertGetId([
            'name' => 'Categoria-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Crea un comentario suelto por el mismo camino que usa la pantalla. */
    private function storeComment(Client $client, string $body): ClientComment
    {
        $request = Request::create('/api/clients/' . $client->id . '/comments', 'POST', [
            'body' => $body,
            'comment_category_id' => $this->makeCategory(),
        ]);

        app(ClientCommentController::class)->store(
            $request,
            $client->id,
            app(GeolocationHistoryService::class),
            app(ReverseGeocodeService::class)
        );

        return ClientComment::where('client_id', $client->id)
            ->where('body', $body)
            ->firstOrFail();
    }

    // =====================================================================
    // ESCRITURA — los tres caminos por los que nace un comentario
    // =====================================================================

    /**
     * El caso que motivó todo, en el sentido "el vendedor va ATRASADO respecto
     * de la app": vendedor en México (UTC-6), aplicación en Lima (UTC-5).
     *
     * A las 23:30 del día 15 en México ya son las 00:30 del 16 en Lima. Con el
     * día deducido de created_at, ese comentario se archivaba en el 16 —un día
     * en el que el cobrador ni siquiera había salido a trabajar— y desaparecía
     * de la jornada a la que pertenece.
     *
     * @test
     */
    public function el_comentario_de_la_noche_no_se_va_al_dia_siguiente(): void
    {
        $esc = $this->makeScenario('México', 'America/Mexico_City');

        // 05:30 UTC = 23:30 del 15 en México = 00:30 del 16 en Lima.
        Carbon::setTestNow(Carbon::parse('2026-08-16 05:30:00', 'UTC'));

        $comment = $this->storeComment($esc['client'], 'cierre de la jornada');

        $this->assertSame('2026-08-15', $comment->business_date->toDateString(),
            'El comentario pertenece al día 15 del vendedor, no al 16 del reloj de la app.');
        $this->assertSame('2026-08-15 23:30:00', $comment->business_timestamp,
            'La hora se guarda cruda, tal cual la vio quien escribió.');
        $this->assertSame('America/Mexico_City', $comment->business_timezone);

        // Y la prueba de que el problema era real: created_at, que es lo único
        // que había antes, dice el día siguiente.
        $this->assertSame('2026-08-16', $comment->created_at->toDateString(),
            'created_at sigue en la zona de la app: por eso no sirve para fechar la jornada.');
    }

    /**
     * El simétrico: vendedor ADELANTADO respecto de la app. Venezuela (UTC-4)
     * con la aplicación en Lima (UTC-5) — y en la base hay 2 vendedores
     * venezolanos con 1.733 pagos este año, así que no es un supuesto.
     *
     * A las 00:30 del 16 en Caracas todavía son las 23:30 del 15 en Lima: el
     * comentario de la madrugada se archivaba un día ANTES.
     *
     * @test
     */
    public function el_comentario_de_la_madrugada_no_se_va_al_dia_anterior(): void
    {
        $esc = $this->makeScenario('Venezuela', 'America/Caracas');

        // 04:30 UTC = 00:30 del 16 en Caracas = 23:30 del 15 en Lima.
        Carbon::setTestNow(Carbon::parse('2026-08-16 04:30:00', 'UTC'));

        $comment = $this->storeComment($esc['client'], 'arranque del día');

        $this->assertSame('2026-08-16', $comment->business_date->toDateString());
        $this->assertSame('2026-08-16 00:30:00', $comment->business_timestamp);
        $this->assertSame('America/Caracas', $comment->business_timezone);
        $this->assertSame('2026-08-15', $comment->created_at->toDateString(),
            'created_at cae en el día anterior: exactamente el desfase que el ancla corrige.');
    }

    /**
     * El comentario que viaja con una visita hereda el ancla de la VISITA, sin
     * recalcularla. La visita se fecha por el momento en que OCURRIÓ (puede
     * haberse sincronizado horas después, sin señal): si el comentario volviera
     * a resolver su día por su cuenta, los dos lados del mismo acto podrían
     * quedar en fechas distintas.
     *
     * @test
     */
    public function el_comentario_de_la_visita_hereda_el_ancla_de_la_visita(): void
    {
        $esc = $this->makeScenario('Argentina', 'America/Argentina/Buenos_Aires');

        // La visita ocurrió a las 23:40 del 15 (hora de Argentina) pero llega al
        // servidor al otro día.
        Carbon::setTestNow(Carbon::parse('2026-08-16 14:00:00', 'UTC'));

        $visit = app(ClientVisitService::class)->register($esc['client'], [
            'uuid' => Str::uuid()->toString(),
            'result' => 'No pagó',
            'latitude' => -34.60,
            'longitude' => -58.38,
            'comment' => 'no estaba, vuelvo mañana',
            'comment_category_id' => $this->makeCategory(),
            'occurred_at' => '2026-08-16T02:40:00Z', // 23:40 del 15 en Buenos Aires
        ]);

        $comment = ClientComment::findOrFail($visit->client_comment_id);

        $this->assertSame('2026-08-15', $visit->business_date->toDateString(),
            'La visita se ancla al momento en que ocurrió.');
        $this->assertSame($visit->business_date->toDateString(), $comment->business_date->toDateString(),
            'El comentario y su visita son el mismo acto: mismo día.');
        $this->assertSame($visit->business_timestamp, $comment->business_timestamp);
        $this->assertSame($visit->business_timezone, $comment->business_timezone);
    }

    /**
     * El motivo del "No pagó" comparte el día del pago que lo generó. Si
     * divergieran, la fila del pago y su motivo caerían en jornadas distintas y
     * el administrador vería el pago en cero sin explicación.
     *
     * @test
     */
    public function el_motivo_del_no_pago_comparte_el_dia_del_pago(): void
    {
        $esc = $this->makeScenario('México', 'America/Mexico_City');

        // 05:30 UTC = 23:30 del 15 en México, 00:30 del 16 en Lima.
        Carbon::setTestNow(Carbon::parse('2026-08-16 05:30:00', 'UTC'));

        // PaymentRequest de verdad, con sus reglas corriendo: el "No Pagado"
        // exige motivo y categoría, y ese es justo el comentario que se ancla.
        $request = \App\Http\Requests\Payment\PaymentRequest::create('/api/payment', 'POST', [
            'credit_id' => $esc['credit']->id,
            'amount' => 0,
            'payment_date' => '2026-08-15',
            'status' => 'No Pagado',
            'comment' => 'no tenia el dinero',
            'comment_category_id' => $this->makeCategory(),
        ]);
        $request->setUserResolver(fn () => $esc['admin']);
        $request->setContainer(app());
        $request->validateResolved();

        app(PaymentService::class)->create($request);

        $payment = Payment::where('credit_id', $esc['credit']->id)->latest('id')->firstOrFail();
        $comment = ClientComment::where('client_id', $esc['client']->id)
            ->where('body', 'no tenia el dinero')
            ->firstOrFail();

        // Payment castea business_date a Carbon; se compara la fecha, no el objeto.
        $pagoDia = $payment->business_date instanceof \DateTimeInterface
            ? $payment->business_date->format('Y-m-d')
            : (string) $payment->business_date;

        $this->assertSame('2026-08-15', $pagoDia,
            'El pago ya se anclaba bien: la zona la decide el vendedor.');
        $this->assertSame($pagoDia, $comment->business_date->toDateString(),
            'El motivo pertenece al mismo día de negocio que el registro que lo generó.');
        $this->assertSame($payment->business_timezone, $comment->business_timezone);
    }

    /**
     * El trío nunca puede quedar a medias: un business_date sin su timestamp y
     * su zona deja una fila que se filtra bien pero se muestra mal.
     *
     * @test
     */
    public function el_trio_de_campos_se_escribe_completo(): void
    {
        $esc = $this->makeScenario('Perú', self::APP_TZ);
        Carbon::setTestNow(Carbon::parse('2026-08-16 15:00:00', 'UTC'));

        $comment = $this->storeComment($esc['client'], 'nota cualquiera');

        $this->assertNotNull($comment->business_date);
        $this->assertNotNull($comment->business_timestamp);
        $this->assertNotNull($comment->business_timezone);
    }

    // =====================================================================
    // LECTURA — la gestión llega en la jornada correcta, y lo viejo no se rompe
    // =====================================================================

    private function paymentsRequest(string $date): Request
    {
        $request = Request::create('/api/payments/seller', 'GET', [
            'date' => $date,
            'include_managements' => 'true',
        ]);
        app()->instance('request', $request);

        return $request;
    }

    private function managementRows($sellerId, string $date): array
    {
        $response = app(PaymentService::class)
            ->getAllPaymentsBySeller($sellerId, $this->paymentsRequest($date));

        $data = json_decode($response->getContent(), true)['data'] ?? [];

        return array_values(array_filter($data, fn ($row) => !empty($row['is_management_only'])));
    }

    /**
     * De punta a punta: la gestión aparece en el día del VENDEDOR, y no aparece
     * en el día del reloj de la app. Este es el test que fallaría si alguien
     * volviera a fechar por created_at.
     *
     * @test
     */
    public function la_gestion_aparece_en_el_dia_del_vendedor(): void
    {
        $esc = $this->makeScenario('México', 'America/Mexico_City');
        Carbon::setTestNow(Carbon::parse('2026-08-16 05:30:00', 'UTC'));

        $this->storeComment($esc['client'], 'no abrio la tienda');

        $delDia15 = $this->managementRows($esc['seller']->id, '2026-08-15');
        $this->assertCount(1, $delDia15, 'La gestión pertenece al 15, el día real del vendedor.');
        $this->assertSame('2026-08-15', $delDia15[0]['business_date']);
        $this->assertSame('no abrio la tienda', $delDia15[0]['comments'][0]['body']);

        $delDia16 = $this->managementRows($esc['seller']->id, '2026-08-16');
        $this->assertCount(0, $delDia16,
            'No debe filtrarse al 16, que es solo el día del reloj de la aplicación.');
    }

    /**
     * NO-RUPTURA. Los 12.566 comentarios que ya existen tienen business_date en
     * null hasta que corra el backfill: se siguen leyendo por el rango sobre
     * created_at. Sin esto, desplegar el anclaje los haría desaparecer a todos.
     *
     * @test
     */
    public function el_historico_sin_anclar_se_sigue_leyendo(): void
    {
        $esc = $this->makeScenario('Perú', self::APP_TZ);

        ClientComment::forceCreate([
            'client_id' => $esc['client']->id,
            'user_id' => $esc['admin']->id,
            'body' => 'comentario viejo sin anclar',
            'business_date' => null,
            'business_timestamp' => null,
            'business_timezone' => null,
            'created_at' => '2026-08-15 12:00:00', // zona de la app
            'updated_at' => '2026-08-15 12:00:00',
        ]);

        $filas = $this->managementRows($esc['seller']->id, '2026-08-15');

        $this->assertCount(1, $filas);
        $this->assertSame('comentario viejo sin anclar', $filas[0]['comments'][0]['body']);
        $this->assertSame('2026-08-15', $filas[0]['business_date'],
            'Sin ancla, la fila se etiqueta con la fecha reconstruida, no con null.');
    }

    /**
     * Anclados y sin anclar en la MISMA consulta: es el estado real que va a
     * tener producción entre el deploy y el backfill.
     *
     * @test
     */
    public function los_anclados_y_los_viejos_conviven_en_la_misma_consulta(): void
    {
        $esc = $this->makeScenario('Perú', self::APP_TZ);
        Carbon::setTestNow(Carbon::parse('2026-08-15 17:00:00', 'UTC')); // 12:00 en Lima

        $otro = Client::factory()->create([
            'seller_id' => $esc['seller']->id,
            'name' => 'Segundo cliente',
            'geolocation' => '[]',
            'uuid' => Str::uuid()->toString(),
        ]);
        Credit::factory()->create([
            'client_id' => $otro->id,
            'seller_id' => $esc['seller']->id,
            'payment_frequency' => 'Diaria',
        ]);

        $this->storeComment($esc['client'], 'nuevo con ancla');

        ClientComment::forceCreate([
            'client_id' => $otro->id,
            'user_id' => $esc['admin']->id,
            'body' => 'viejo sin ancla',
            'created_at' => '2026-08-15 12:00:00',
            'updated_at' => '2026-08-15 12:00:00',
        ]);

        $cuerpos = collect($this->managementRows($esc['seller']->id, '2026-08-15'))
            ->pluck('comments.0.body')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['nuevo con ancla', 'viejo sin ancla'], $cuerpos);
    }

    /**
     * El ancla no puede romper la regla de no duplicar: si el cliente tuvo pago
     * ese día, su comentario viaja DENTRO de la fila del pago y no genera una
     * fila de gestión aparte.
     *
     * @test
     */
    public function el_cliente_con_pago_sigue_sin_duplicarse(): void
    {
        $esc = $this->makeScenario('México', 'America/Mexico_City');
        Carbon::setTestNow(Carbon::parse('2026-08-16 05:30:00', 'UTC'));

        Payment::forceCreate([
            'credit_id' => $esc['credit']->id,
            'payment_date' => '2026-08-15',
            'amount' => 0,
            'status' => 'No pagado',
            'business_date' => '2026-08-15',
        ]);

        $this->storeComment($esc['client'], 'motivo del no pago');

        $this->assertCount(0, $this->managementRows($esc['seller']->id, '2026-08-15'),
            'Ya tiene fila de pago: el comentario viaja ahí, no como gestión aparte.');
    }
}
