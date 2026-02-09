<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncomeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'value' => $this->faker->randomFloat(2, 10, 500),
            'description' => $this->faker->sentence(),
            'user_id' => User::factory(),
            'business_date' => now()->toDateString(),
            'status' => 'Aprobado',
        ];
    }
}
