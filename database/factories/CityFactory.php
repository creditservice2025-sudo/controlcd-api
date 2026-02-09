<?php

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

class CityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->city(),
            'country_id' => Country::factory(), // Dynamic country creation
        ];
    }
}
