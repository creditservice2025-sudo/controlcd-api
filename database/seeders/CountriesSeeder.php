<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Country;

class CountriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $countries = [
            ['id' => 1, 'name' => 'Argentina'],
            ['id' => 2, 'name' => 'Bolivia'],
            ['id' => 3, 'name' => 'Brasil'],
            ['id' => 4, 'name' => 'Chile'],
            ['id' => 5, 'name' => 'Colombia'],
            ['id' => 6, 'name' => 'Costa Rica'],
            ['id' => 7, 'name' => 'Cuba'],
            ['id' => 8, 'name' => 'Dominicana'],
            ['id' => 9, 'name' => 'Ecuador'],
            ['id' => 10, 'name' => 'El Salvador'],
            ['id' => 11, 'name' => 'Guatemala'],
            ['id' => 12, 'name' => 'Haití'],
            ['id' => 13, 'name' => 'Honduras'],
            ['id' => 14, 'name' => 'México'],
            ['id' => 15, 'name' => 'Nicaragua'],
            ['id' => 16, 'name' => 'Panamá'],
            ['id' => 17, 'name' => 'Paraguay'],
            ['id' => 18, 'name' => 'Perú'],
            ['id' => 19, 'name' => 'Puerto Rico'],
            ['id' => 20, 'name' => 'Uruguay'],
            ['id' => 21, 'name' => 'Venezuela']
        ];

        foreach ($countries as $country) {
            Country::create($country);
        }
    }
}
