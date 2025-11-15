<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Guarantor;

class GuarantorsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $guarantor = new Guarantor();
            $guarantor->name = 'Fiador ' . $i;
            $guarantor->dni = $this->generateUniqueDni();
            $guarantor->address = 'Calle 123';
            $guarantor->phone = fake()->phoneNumber();
            $guarantor->email = 'fiador' . $i . '@gmail.com';
            $guarantor->save();
        }
    }

    private function generateUniqueDni()
    {
        do {
            $dni = mt_rand(10000000, 99999999);
        } while (Guarantor::where('dni', $dni)->exists());
        return $dni;
    }
}
