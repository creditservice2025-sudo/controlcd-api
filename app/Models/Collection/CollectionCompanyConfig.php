<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

class CollectionCompanyConfig extends Model
{
    protected $connection = 'collection_pgsql';
    protected $table = 'collection_company_configs';

    protected $fillable = [
        'company_id',
        'currencies',
        'default_currency',
        'default_country_code',
        'timezone',
        'settings',
    ];

    protected $casts = [
        'currencies' => 'array',
        'settings' => 'array',
    ];

    /**
     * Retorna los pares currency/country_code configurados.
     * @return array<array{currency: string, country_code: string}>
     */
    public function getCurrencyPairs(): array
    {
        return $this->currencies ?? [['currency' => 'COP', 'country_code' => 'CO']];
    }

    public function hasCurrency(string $currency, string $countryCode): bool
    {
        foreach ($this->getCurrencyPairs() as $pair) {
            if ($pair['currency'] === $currency && $pair['country_code'] === $countryCode) {
                return true;
            }
        }
        return false;
    }

    public function getDefaultPair(): array
    {
        return [
            'currency' => $this->default_currency ?? 'COP',
            'country_code' => $this->default_country_code ?? 'CO',
        ];
    }
}
