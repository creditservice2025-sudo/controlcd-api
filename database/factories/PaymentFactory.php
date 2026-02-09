<?php

namespace Database\Factories;

use App\Models\Credit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'credit_id' => Credit::factory(),
            'user_id' => User::factory(),
            'amount' => $this->faker->randomFloat(2, 5, 50),
            'payment_method' => 'Efectivo',
            'business_date' => now()->toDateString(),
            'status' => 'Pagado',
        ];
    }
}
