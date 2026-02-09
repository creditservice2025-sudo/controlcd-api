<?php

namespace Database\Factories;

use App\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'dni' => $this->faker->unique()->numerify('########'),
            'phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
            'seller_id' => Seller::factory(),
            'status' => 'active',
        ];
    }
}
