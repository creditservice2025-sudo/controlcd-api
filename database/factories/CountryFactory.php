<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CountryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Colombia',
            'currency' => 'COP',
            'status' => 'active',
            'timezone' => 'America/Bogota',
        ];
    }
}
