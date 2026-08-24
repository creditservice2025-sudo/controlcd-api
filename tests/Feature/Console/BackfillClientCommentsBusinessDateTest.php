<?php

namespace Tests\Feature\Console;

use App\Models\City;
use App\Models\Client;
use App\Models\ClientComment;
use App\Models\ClientVisit;
use App\Models\Company;
use App\Models\Country;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Backfill del día de negocio de los comentarios históricos.
 *
 * Es un comando que escribe sobre datos reales de producción, así que lo que se
 * prueba acá no es solo "que funcione": es que en dry-run NO escriba, que no
 * pise lo que ya estaba anclado, y que no se saltee filas al paginar.
 */
class BackfillClientCommentsBusinessDateTest extends TestCase
{
    use DatabaseTransactions;

    private const APP_TZ = 'America/Lima';

    private string $phpTzOriginal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->phpTzOriginal = date_default_timezone_get();
        config(['app.timezone' => self::APP_TZ]);
        date_default_timezone_set(self::APP_TZ);
    }

    protected function tearDown(): void
    {
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

    private function makeScenario(string $countryName, string $sellerTz): array
    {
        $this->ensureRole(2);
        $admin = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['user_id' => $admin->id]);
        $country = Country::factory()->create(['name' => $countryName, 'timezone' => $sellerTz]);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create([
            'company_id' => $company->id,
            'city_id' => $city->id,
        ]);
        $client = Client::factory()->create([
            'seller_id' => $seller->id,
            'geolocation' => '[]',
            'uuid' => Str::uuid()->toString(),
        ]);

        return compact('admin', 'company', 'seller', 'client');
    }

    private function makeComment(array $esc, string $body, string $createdAt, array $extra = []): ClientComment
    {
        return ClientComment::forceCreate(array_merge([
            'client_id' => $esc['client']->id,
            'user_id' => $esc['admin']->id,
            'body' => $body,
            'business_date' => null,
            'business_timestamp' => null,
            'business_timezone' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $extra));
    }

    /**
     * Dry-run es el modo por defecto: informa y no escribe. Si esto se rompe, el
     * primer "a ver qué haría" del operador se convierte en una escritura sobre
     * 12.566 filas de producción.
     *
     * @test
     */
    public function el_dry_run_no_escribe_nada(): void
    {
        $esc = $this->makeScenario('Bolivia', 'America/La_Paz');
        $comment = $this->makeComment($esc, 'sin anclar', '2026-08-15 12:00:00');

        $this->artisan('comments:backfill-business-date')
            ->assertExitCode(0);

        $this->assertNull($comment->fresh()->business_date,
            'Sin --apply no se toca ni una fila.');
    }

    /**
     * Cuando el comentario tiene visita, el día sale de la VISITA tal cual: ese
     * es un dato original, anclado en su momento a la zona del vendedor y al
     * instante en que ocurrió. Reconstruirlo desde created_at sería reemplazar
     * un dato bueno por una estimación.
     *
     * @test
     */
    public function el_comentario_con_visita_copia_el_dato_de_la_visita(): void
    {
        $esc = $this->makeScenario('Argentina', 'America/Argentina/Buenos_Aires');
        $comment = $this->makeComment($esc, 'con visita', '2026-08-16 09:00:00');

        ClientVisit::forceCreate([
            'uuid' => Str::uuid()->toString(),
            'client_id' => $esc['client']->id,
            'seller_id' => $esc['seller']->id,
            'user_id' => $esc['admin']->id,
            'client_comment_id' => $comment->id,
            'result' => 'No pagó',
            'source' => 'manual',
            'latitude' => -34.60,
            'longitude' => -58.38,
            // La visita ocurrió el 15 aunque el comentario llegó el 16.
            'business_date' => '2026-08-15',
            'business_timestamp' => '2026-08-15 23:40:00',
            'business_timezone' => 'America/Argentina/Buenos_Aires',
            'created_at' => '2026-08-16 09:00:00',
            'updated_at' => '2026-08-16 09:00:00',
        ]);

        $this->artisan('comments:backfill-business-date --apply')->assertExitCode(0);

        $fresh = $comment->fresh();
        $this->assertSame('2026-08-15', $fresh->business_date->toDateString());
        $this->assertSame('2026-08-15 23:40:00', $fresh->business_timestamp);
        $this->assertSame('America/Argentina/Buenos_Aires', $fresh->business_timezone);
    }

    /**
     * Sin visita hay que reconstruir: created_at está en la zona de la APP y se
     * traslada a la del vendedor. Bolivia es UTC-4 y la app UTC-5, así que un
     * comentario guardado a las 00:30 de Lima pertenece al día ANTERIOR... no:
     * pertenece al MISMO instante, que en La Paz ya es una hora más tarde.
     *
     * Caso elegido a propósito en el borde: 23:30 en Lima del día 15 son las
     * 00:30 del 16 en La Paz. Fechado por created_at quedaba en el 15; el día
     * real del cobrador boliviano es el 16.
     *
     * @test
     */
    public function el_comentario_sin_visita_se_reconstruye_en_la_zona_del_vendedor(): void
    {
        $esc = $this->makeScenario('Bolivia', 'America/La_Paz');
        $comment = $this->makeComment($esc, 'sin visita', '2026-08-15 23:30:00');

        $this->artisan('comments:backfill-business-date --apply')->assertExitCode(0);

        $fresh = $comment->fresh();
        $this->assertNotNull($fresh->business_date);
        $this->assertNotNull($fresh->business_timestamp);
        $this->assertNotNull($fresh->business_timezone);

        // La zona sale de TimezoneHelper, que es la MISMA fuente que usa el
        // sistema para estampar pagos y visitas. Si algún día se corrige el mapa
        // de países, este test acompaña el cambio en vez de contradecirlo.
        $tzEsperada = \App\Helpers\TimezoneHelper::getSellerTimezone(
            Seller::with('city.country')->find($esc['seller']->id)
        );
        $esperado = \Carbon\Carbon::parse('2026-08-15 23:30:00', self::APP_TZ)
            ->setTimezone($tzEsperada);

        $this->assertSame($tzEsperada, $fresh->business_timezone);
        $this->assertSame($esperado->toDateString(), $fresh->business_date->toDateString());
        $this->assertSame($esperado->format('Y-m-d H:i:s'), $fresh->business_timestamp);
    }

    /**
     * No pisa lo que ya estaba anclado. El comando se puede correr dos veces
     * —o quedar a medias y retomarse— sin cambiar lo ya resuelto.
     *
     * @test
     */
    public function no_toca_los_comentarios_ya_anclados(): void
    {
        $esc = $this->makeScenario('Bolivia', 'America/La_Paz');
        $yaAnclado = $this->makeComment($esc, 'ya anclado', '2026-08-15 12:00:00', [
            'business_date' => '2026-08-10',
            'business_timestamp' => '2026-08-10 08:00:00',
            'business_timezone' => 'America/La_Paz',
        ]);

        $this->artisan('comments:backfill-business-date --apply')->assertExitCode(0);

        $fresh = $yaAnclado->fresh();
        $this->assertSame('2026-08-10', $fresh->business_date->toDateString(),
            'Un comentario ya anclado no se recalcula.');
        $this->assertSame('2026-08-10 08:00:00', $fresh->business_timestamp);
    }

    /**
     * El comando pagina, y el filtro es justamente `business_date is null`: cada
     * lote aplicado deja de cumplir la condición. Con paginación por offset eso
     * corre la ventana y saltea tantas filas como acaba de arreglar. Con un
     * chunk deliberadamente chico frente a la cantidad de filas, este test
     * fallaría si alguien volviera a chunk().
     *
     * @test
     */
    public function no_se_saltea_filas_al_paginar(): void
    {
        $esc = $this->makeScenario('Bolivia', 'America/La_Paz');

        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->makeComment($esc, "comentario {$i}", '2026-08-15 12:00:00')->id;
        }

        $this->artisan('comments:backfill-business-date --apply --chunk=5')->assertExitCode(0);

        $sinAnclar = ClientComment::whereIn('id', $ids)->whereNull('business_date')->count();
        $this->assertSame(0, $sinAnclar,
            'Las 25 filas tienen que quedar ancladas, no solo las de los primeros lotes.');
    }

    /**
     * --seller acota el alcance: permite correrlo por partes y verificar una
     * ruta antes de tocar el resto.
     *
     * @test
     */
    public function el_filtro_por_vendedor_acota_el_alcance(): void
    {
        $uno = $this->makeScenario('Bolivia', 'America/La_Paz');
        $otro = $this->makeScenario('Perú', self::APP_TZ);

        $delUno = $this->makeComment($uno, 'del vendedor uno', '2026-08-15 12:00:00');
        $delOtro = $this->makeComment($otro, 'del vendedor dos', '2026-08-15 12:00:00');

        $this->artisan('comments:backfill-business-date --apply --seller=' . $uno['seller']->id)
            ->assertExitCode(0);

        $this->assertNotNull($delUno->fresh()->business_date);
        $this->assertNull($delOtro->fresh()->business_date,
            'El vendedor no incluido en el filtro queda intacto.');
    }
}
