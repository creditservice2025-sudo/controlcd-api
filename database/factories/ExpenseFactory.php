<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'value' => $this->faker->randomFloat(2, 5, 100),
            'description' => $this->faker->sentence(),
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'business_date' => now()->toDateString(),
            'status' => 'Aprobado',
        ];
    }
}
