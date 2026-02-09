<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->lexify('???'),
            'user_id' => User::factory(),
            'ruc' => $this->faker->unique()->numerify('###########'),
            'name' => $this->faker->company(),
            'phone' => '+51' . $this->faker->numerify('#########'),
            'email' => $this->faker->unique()->safeEmail(),
        ];
    }
}
