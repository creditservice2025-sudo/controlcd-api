<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

class CreditFactory extends Factory
{
    public function definition(): array
    {
        $value = $this->faker->numberBetween(100, 5000);
        $interest = 20; // Default 20%
        $total = $value * (1 + $interest / 100);

        return [
            'client_id' => Client::factory(),
            'seller_id' => Seller::factory(),
            'credit_value' => $value,
            'total_interest' => $interest,
            'total_amount' => $total,
            'remaining_amount' => $total,
            'number_installments' => 24,
            'payment_frequency' => 'diario',
            'status' => 'Vigente',
            'start_date' => now()->toDateString(),
            'first_quota_date' => now()->addDay()->toDateString(),
        ];
    }
}
