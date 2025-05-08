<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\City;

class CitiesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $cities = [
            ['name' => 'Bogotá D.C.', 'country_id' => 5],
            ['name' => 'Medellín', 'country_id' => 5],
            ['name' => 'Cali', 'country_id' => 5],
            ['name' => 'Barranquilla', 'country_id' => 5],
            ['name' => 'Cartagena', 'country_id' => 5],
            ['name' => 'Cúcuta', 'country_id' => 5],
            ['name' => 'Soledad', 'country_id' => 5],
            ['name' => 'Bucaramanga', 'country_id' => 5],
            ['name' => 'Soacha', 'country_id' => 5],
            ['name' => 'Villavicencio', 'country_id' => 5],
            ['name' => 'Santa Marta', 'country_id' => 5],
            ['name' => 'Manizales', 'country_id' => 5],
            ['name' => 'Valledupar', 'country_id' => 5],
            ['name' => 'Montería', 'country_id' => 5],
            ['name' => 'Neiva', 'country_id' => 5],
            ['name' => 'Pasto', 'country_id' => 5],
            ['name' => 'Armenia', 'country_id' => 5],
            ['name' => 'Pereira', 'country_id' => 5],
            ['name' => 'Ibagué', 'country_id' => 5],
        ];

        foreach ($cities as $city) {
            City::create($city);
        }
    }
}
