<?php

namespace Tests\Feature\Services;

use App\Models\Client;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Image;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La foto del crédito tiene que quedar colgada del CRÉDITO, no solo del cliente.
 *
 * ClientService::storeClientImages recibía el crédito pero lo usaba solo para
 * redactar la descripción ("Crédito ID: 132290 - Valor: ..."), sin escribir la
 * FK. Como el cliente nuevo nace junto con su crédito obligatorio, el PRIMER
 * crédito de cada cliente quedaba sin foto y en Liquidaciones la columna "Doc"
 * mostraba un guion aunque la evidencia del dinero en mano estuviera cargada.
 *
 * Medido sobre produccion al detectarlo: del 1 al 13 de agosto, 7 de 1.239
 * primeros créditos tenían la foto ligada (0,6%), contra 7.582 de 7.585 en los
 * créditos posteriores (100%).
 */
class CreditImageLinkTest extends TestCase
{
    use DatabaseTransactions;

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
        $country = Country::factory()->create(['timezone' => 'America/Lima']);
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
        $credit = Credit::factory()->create([
            'client_id' => $client->id,
            'seller_id' => $seller->id,
            'payment_frequency' => 'Diaria',
        ]);

        return compact('admin', 'seller', 'client', 'credit');
    }

    /** Foto como la dejaba el alta rota: colgada del cliente, sin la FK. */
    private function fotoHuerfana(array $esc, string $type, ?int $creditIdEnTexto, ?Client $client = null): Image
    {
        $client = $client ?: $esc['client'];
        $descripcion = $creditIdEnTexto !== null
            ? "Crédito ID: {$creditIdEnTexto} - Valor: $1,000.00 - Creado: 2026-08-16 15:51"
            : 'Foto sin referencia de crédito';

        return Image::forceCreate([
            'path' => 'images/clients/' . Str::random(12) . '.jpg',
            'type' => $type,
            'description' => $descripcion,
            'client_id' => $client->id,
            'credit_id' => null,
        ]);
    }

    // =====================================================================
    // El arreglo hacia adelante
    // =====================================================================

    /**
     * El metodo ya recibia el credito: lo que faltaba era escribir la columna.
     * Se comprueba sobre el propio ClientService, invocando el metodo privado,
     * para que el test falle si alguien vuelve a sacar la FK del registro.
     *
     * @test
     */
    public function el_alta_cuelga_la_foto_del_credito_y_no_solo_del_cliente(): void
    {
        $esc = $this->makeScenario();

        $request = \Illuminate\Http\Request::create('/api/client/create', 'POST', [
            'images' => [
                ['type' => 'money_in_hand', 'latitude' => -12.04, 'longitude' => -77.04],
            ],
        ]);
        $request->files->set('images', [
            ['file' => \Illuminate\Http\UploadedFile::fake()->image('dinero.jpg')],
        ]);

        $service = app(\App\Services\ClientService::class);
        $metodo = new \ReflectionMethod($service, 'storeClientImages');
        $metodo->setAccessible(true);
        $metodo->invoke($service, $esc['client'], $request, $esc['credit']);

        $foto = Image::where('client_id', $esc['client']->id)->latest('id')->first();

        $this->assertNotNull($foto, 'La foto se tiene que haber guardado.');
        $this->assertSame(
            (int) $esc['credit']->id,
            (int) $foto->credit_id,
            'La FK al crédito es lo que hace que la columna "Doc" deje de mostrar un guion.'
        );
        $this->assertSame(
            (int) $esc['client']->id,
            (int) $foto->client_id,
            'Y la foto sigue colgando del cliente: no se reemplaza un vínculo por el otro.'
        );
    }

    /**
     * GARANTÍA: la relación que lee Liquidaciones deja de venir vacía. Es
     * exactamente lo que la pantalla evalúa (row.images.length > 0).
     *
     * @test
     */
    public function el_credito_ve_su_foto_por_la_relacion(): void
    {
        $esc = $this->makeScenario();

        Image::forceCreate([
            'path' => 'images/clients/x.jpg',
            'type' => 'money_in_hand',
            'client_id' => $esc['client']->id,
            'credit_id' => $esc['credit']->id,
        ]);

        $this->assertCount(1, $esc['credit']->fresh()->images);
    }

    // =====================================================================
    // La reparación del histórico
    // =====================================================================

    /**
     * Dry-run es el modo por defecto: informa y no escribe.
     *
     * @test
     */
    public function el_dry_run_no_escribe_nada(): void
    {
        $esc = $this->makeScenario();
        $foto = $this->fotoHuerfana($esc, 'money_in_hand', $esc['credit']->id);

        $this->artisan('images:relink-credit')->assertExitCode(0);

        $this->assertNull($foto->fresh()->credit_id, 'Sin --apply no se toca ni una fila.');
    }

    /**
     * El caso central: el id estaba escrito en la descripción y se recupera.
     *
     * @test
     */
    public function reconecta_la_foto_usando_el_id_de_la_descripcion(): void
    {
        $esc = $this->makeScenario();
        $foto = $this->fotoHuerfana($esc, 'money_in_hand', $esc['credit']->id);

        $this->artisan('images:relink-credit --apply')->assertExitCode(0);

        $fresh = $foto->fresh();
        $this->assertSame((int) $esc['credit']->id, (int) $fresh->credit_id);
        $this->assertSame((int) $esc['client']->id, (int) $fresh->client_id,
            'client_id queda intacto: la foto sigue siendo del cliente.');
    }

    /**
     * LA PROTECCIÓN QUE IMPORTA: si el crédito nombrado en la descripción es de
     * OTRO cliente, no se liga. Colgar la evidencia de dinero en mano del
     * crédito equivocado seria peor que dejarla sin ligar.
     *
     * @test
     */
    public function no_liga_si_el_credito_es_de_otro_cliente(): void
    {
        $esc = $this->makeScenario();
        $otro = $this->makeScenario();

        $foto = $this->fotoHuerfana($esc, 'money_in_hand', $otro['credit']->id);

        $this->artisan('images:relink-credit --apply')->assertExitCode(0);

        $this->assertNull($foto->fresh()->credit_id,
            'El crédito existe pero es de otro cliente: no se toca.');
    }

    /**
     * Si el crédito nombrado ya no existe (borrado), tampoco se liga.
     *
     * @test
     */
    public function no_liga_si_el_credito_no_existe(): void
    {
        $esc = $this->makeScenario();
        $foto = $this->fotoHuerfana($esc, 'money_in_hand', 999999999);

        $this->artisan('images:relink-credit --apply')->assertExitCode(0);

        $this->assertNull($foto->fresh()->credit_id);
    }

    /**
     * Las fotos sin id en la descripción se quedan como están. Son las
     * anteriores al flujo (perfiles sueltos): no hay dato del cual recuperarlas
     * y no se inventa uno por cercanía de tiempo.
     *
     * @test
     */
    public function deja_en_paz_las_fotos_sin_referencia(): void
    {
        $esc = $this->makeScenario();
        $foto = $this->fotoHuerfana($esc, 'profile', null);

        $this->artisan('images:relink-credit --apply')->assertExitCode(0);

        $this->assertNull($foto->fresh()->credit_id);
    }

    /**
     * No pisa lo que ya estaba ligado: el comando se puede correr dos veces, o
     * quedar a medias y retomarse, sin cambiar lo ya resuelto.
     *
     * @test
     */
    public function no_pisa_una_foto_ya_ligada(): void
    {
        $esc = $this->makeScenario();
        $otro = $this->makeScenario();

        // Ligada a un crédito, pero con OTRO id en el texto: si el comando la
        // tocara, la movería. No debe ni mirarla.
        $foto = Image::forceCreate([
            'path' => 'images/clients/ya.jpg',
            'type' => 'money_in_hand',
            'description' => "Crédito ID: {$otro['credit']->id} - Valor: $1,000.00",
            'client_id' => $esc['client']->id,
            'credit_id' => $esc['credit']->id,
        ]);

        $this->artisan('images:relink-credit --apply')->assertExitCode(0);

        $this->assertSame((int) $esc['credit']->id, (int) $foto->fresh()->credit_id);
    }

    /**
     * El filtro es `credit_id is null` y el lote deja de cumplirlo al
     * escribirlo: con paginación por offset se saltearían tantas filas como se
     * acaban de arreglar. Un chunk chico frente a la cantidad de filas lo
     * expone.
     *
     * @test
     */
    public function no_se_saltea_filas_al_paginar(): void
    {
        $esc = $this->makeScenario();

        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->fotoHuerfana($esc, 'money_in_hand', $esc['credit']->id)->id;
        }

        $this->artisan('images:relink-credit --apply --chunk=5')->assertExitCode(0);

        $sinLigar = Image::whereIn('id', $ids)->whereNull('credit_id')->count();
        $this->assertSame(0, $sinLigar, 'Las 25 tienen que quedar ligadas, no solo los primeros lotes.');
    }
}
