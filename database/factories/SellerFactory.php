<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SellerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'city_id' => City::factory(),
            'company_id' => Company::factory(),
            'status' => 'active',
            'address' => $this->faker->address(),
        ];
    }
}
