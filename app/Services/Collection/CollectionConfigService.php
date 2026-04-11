<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCompanyConfig;
use App\Traits\ApiResponse;

class CollectionConfigService
{
    use ApiResponse;

    public const AVAILABLE_CURRENCIES = [
        ['code' => 'CO', 'name' => 'Colombia', 'currency' => 'COP', 'symbol' => '$'],
        ['code' => 'VE', 'name' => 'Venezuela', 'currency' => 'VES', 'symbol' => 'Bs.'],
        ['code' => 'US', 'name' => 'Estados Unidos', 'currency' => 'USD', 'symbol' => '$'],
        ['code' => 'PE', 'name' => 'Peru', 'currency' => 'PEN', 'symbol' => 'S/'],
        ['code' => 'MX', 'name' => 'Mexico', 'currency' => 'MXN', 'symbol' => '$'],
        ['code' => 'EC', 'name' => 'Ecuador', 'currency' => 'USD', 'symbol' => '$'],
        ['code' => 'CL', 'name' => 'Chile', 'currency' => 'CLP', 'symbol' => '$'],
        ['code' => 'AR', 'name' => 'Argentina', 'currency' => 'ARS', 'symbol' => '$'],
        ['code' => 'BR', 'name' => 'Brasil', 'currency' => 'BRL', 'symbol' => 'R$'],
        ['code' => 'PA', 'name' => 'Panama', 'currency' => 'PAB', 'symbol' => 'B/.'],
        ['code' => 'DO', 'name' => 'Rep. Dominicana', 'currency' => 'DOP', 'symbol' => 'RD$'],
        ['code' => 'UY', 'name' => 'Uruguay', 'currency' => 'UYU', 'symbol' => '$U'],
    ];

    public function getConfig(int $companyId): CollectionCompanyConfig
    {
        return CollectionCompanyConfig::firstOrCreate(
            ['company_id' => $companyId],
            [
                'currencies' => [['currency' => 'COP', 'country_code' => 'CO']],
                'default_currency' => 'COP',
                'default_country_code' => 'CO',
            ]
        );
    }

    public function updateCurrencies(int $companyId, array $currencies, string $defaultCurrency, string $defaultCountryCode)
    {
        if (empty($currencies)) {
            return $this->errorResponse('Debe seleccionar al menos una moneda', 422);
        }

        // Validar que el default esté dentro de las seleccionadas
        $defaultInList = collect($currencies)->contains(function ($pair) use ($defaultCurrency, $defaultCountryCode) {
            return $pair['currency'] === $defaultCurrency && $pair['country_code'] === $defaultCountryCode;
        });

        if (!$defaultInList) {
            return $this->errorResponse('La moneda por defecto debe estar en la lista de monedas seleccionadas', 422);
        }

        $config = $this->getConfig($companyId);
        $config->update([
            'currencies' => $currencies,
            'default_currency' => $defaultCurrency,
            'default_country_code' => $defaultCountryCode,
        ]);

        return $this->successResponse([
            'message' => 'Monedas actualizadas',
            'data' => $config->fresh(),
        ]);
    }

    public function getAvailableCurrencies(): array
    {
        return self::AVAILABLE_CURRENCIES;
    }
}
