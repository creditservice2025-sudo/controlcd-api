<?php

use App\Helpers\TimezoneHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pone countries.timezone de acuerdo con la realidad geografica.
 *
 * Cinco paises figuraban como 'America/Lima' sin serlo: Argentina (UTC-3),
 * Bolivia (UTC-4), Chile, Mexico y Venezuela (UTC-4). Bolivia son 14 rutas
 * activas, Argentina 2 y Venezuela 2.
 *
 * El valor sale de TimezoneHelper::COUNTRY_TIMEZONES, que es la unica fuente
 * de verdad del sistema: es el mapa que usan los diez servicios que escriben
 * business_date y, desde el commit que unifico las zonas, tambien todos los
 * caminos de lectura. Esta migracion existe para que la BASE deje de afirmar
 * lo contrario que el codigo, y para que se aplique sola en cada ambiente en
 * vez de depender de que alguien corra un script a mano.
 *
 * NO recalcula ninguna fecha ya registrada. Ningun escritor de business_date
 * lee esta columna, asi que corregirla no mueve un solo movimiento historico:
 * corrige de aca en adelante lo que se muestra, se filtra y se notifica.
 *
 * Conviene aplicarla fuera del horario de operacion de esas rutas: el corte de
 * reopenRoute compara contra la hora local, y justo en el limite del dia podria
 * cambiarle el resultado a un cierre en curso.
 */
return new class extends Migration
{
    public function up(): void
    {
        $corregidos = [];

        foreach (TimezoneHelper::COUNTRY_TIMEZONES as $pais => $zona) {
            if ($pais === 'default') {
                continue;
            }

            // Solo se tocan las filas que difieren, y se acota por nombre: si
            // un ambiente tiene otros ids, igual corrige el pais correcto.
            $filas = DB::table('countries')
                ->where('name', $pais)
                ->where(function ($q) use ($zona) {
                    $q->where('timezone', '<>', $zona)->orWhereNull('timezone');
                })
                ->update(['timezone' => $zona]);

            if ($filas > 0) {
                $corregidos[$pais] = $zona;
            }
        }

        Log::info('[fix_country_timezones] zonas corregidas', [
            'paises' => $corregidos,
            'total' => count($corregidos),
        ]);
    }

    public function down(): void
    {
        // A proposito no revierte. El estado anterior era sencillamente
        // incorrecto —Bolivia no es UTC-5— y devolverlo solo reintroduciria el
        // defecto. Si hiciera falta, se corrige a mano el pais puntual.
    }
};
