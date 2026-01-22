<?php

namespace App\Helpers;

use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TimezoneHelper
{
    /**
     * Mapa de zonas horarias por país.
     * En el futuro esto debería venir de la base de datos (tabla countries).
     */
    const COUNTRY_TIMEZONES = [
        'Colombia' => 'America/Bogota',
        'Peru' => 'America/Lima',
        'Perú' => 'America/Lima',
        'Mexico' => 'America/Mexico_City',
        'México' => 'America/Mexico_City',
        'Chile' => 'America/Santiago',
        'Argentina' => 'America/Argentina/Buenos_Aires',
        'Ecuador' => 'America/Guayaquil',
        'Venezuela' => 'America/Caracas',
        // Default fallback
        'default' => 'America/Lima'
    ];

    /**
     * Resuelve la zona horaria de negocio para un vendedor.
     * 
     * @param Seller|null $seller
     * @return string
     */
    public static function getSellerTimezone(?Seller $seller): string
    {
        if (!$seller) {
            return self::COUNTRY_TIMEZONES['default'];
        }

        try {
            // Intentar obtener país a través de la relación
            // Seller -> City -> Country
            $seller->loadMissing('city.country');
            
            if ($seller->city && $seller->city->country) {
                $countryName = $seller->city->country->name;
                
                if (isset(self::COUNTRY_TIMEZONES[$countryName])) {
                    return self::COUNTRY_TIMEZONES[$countryName];
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error resolving timezone for seller {$seller->id}: " . $e->getMessage());
        }

        return self::COUNTRY_TIMEZONES['default'];
    }

    /**
     * Obtiene el timestamp de negocio actual para un vendedor.
     * 
     * @param Seller|null $seller
     * @return Carbon
     */
    public static function getBusinessNow(?Seller $seller): Carbon
    {
        $timezone = self::getSellerTimezone($seller);
        return Carbon::now($timezone);
    }
}
