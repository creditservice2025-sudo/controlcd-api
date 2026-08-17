<?php

namespace Tests\Feature\Services;

use App\Helpers\TimezoneHelper;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\Seller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zona horaria de los vendedores bolivianos.
 *
 * Bolivia es UTC-4, pero faltaba en el mapa de TimezoneHelper y caía al default
 * (Lima, UTC-5). El efecto no era cosmético: el día de negocio se cortaba una
 * hora antes, así que lo registrado entre 00:00 y 00:59 hora boliviana quedaba
 * fechado el día anterior, y con él la liquidación de esa jornada.
 *
 * Por eso el test no se conforma con comparar el string de la zona: estampa el
 * trío de negocio en el instante exacto donde las dos zonas discrepan.
 */
class SellerTimezoneBoliviaTest extends TestCase
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

    /**
     * El nombre del país es la clave del mapa y la comparación es exacta, así
     * que el escenario usa el mismo texto que hay en la tabla countries.
     */
    private function makeSeller(string $countryName): Seller
    {
        $this->ensureRole(2);
        $admin = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['user_id' => $admin->id]);
        $country = Country::factory()->create(['name' => $countryName]);
        $city = City::factory()->create(['country_id' => $country->id]);

        return Seller::factory()->create([
            'company_id' => $company->id,
            'city_id' => $city->id,
        ]);
    }

    /** @test */
    public function el_vendedor_boliviano_resuelve_america_la_paz(): void
    {
        $seller = $this->makeSeller('Bolivia');

        $this->assertSame(
            'America/La_Paz',
            TimezoneHelper::getSellerTimezone($seller),
            'Bolivia debe resolver su propia zona, no el default de Lima.'
        );
    }

    /**
     * La medianoche boliviana es el instante donde el error se veía: 00:30 en
     * La Paz son las 23:30 del día anterior en Lima. Si el mapa vuelve a caer
     * al default, este test falla por el DÍA, que es lo que le importa a la
     * liquidación, no solo por el nombre de la zona.
     *
     * @test
     */
    public function lo_registrado_pasada_la_medianoche_pertenece_al_dia_nuevo(): void
    {
        $seller = $this->makeSeller('Bolivia');
        $momento = Carbon::parse('2026-08-16 04:30:00', 'UTC'); // 00:30 en La Paz

        $stamp = TimezoneHelper::businessStampForSeller($seller, $momento);

        $this->assertSame('America/La_Paz', $stamp['business_timezone']);
        $this->assertSame('2026-08-16', $stamp['business_date'],
            'Con la zona de Lima este instante caía el 15: un día de negocio menos.');
        $this->assertSame('2026-08-16 00:30:00', $stamp['business_timestamp']);
    }

    /**
     * Agregar Bolivia no puede correr a nadie más: Perú sigue en Lima y un país
     * que no está en el mapa sigue cayendo al default.
     *
     * @test
     */
    public function no_cambia_la_zona_de_los_demas_paises(): void
    {
        $this->assertSame('America/Lima', TimezoneHelper::getSellerTimezone($this->makeSeller('Perú')));
        $this->assertSame('America/Bogota', TimezoneHelper::getSellerTimezone($this->makeSeller('Colombia')));
        $this->assertSame(
            TimezoneHelper::COUNTRY_TIMEZONES['default'],
            TimezoneHelper::getSellerTimezone($this->makeSeller('Paraguay')),
            'Un país sin entrada propia sigue resolviendo el default.'
        );
    }
}
