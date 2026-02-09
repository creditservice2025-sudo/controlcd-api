<?php

namespace Database\Factories;

use App\Models\Credit;
use Illuminate\Database\Eloquent\Factories\Factory;

class InstallmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'credit_id' => Credit::factory(),
            'quota_number' => 1,
            'quota_amount' => 50,
            'due_date' => now()->toDateString(),
            'status' => 'Pendiente',
        ];
    }
}
