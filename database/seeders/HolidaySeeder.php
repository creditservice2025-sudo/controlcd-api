<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Holiday;
use Carbon\Carbon;

class HolidaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $years = [2025, 2026];

        // 1. Feriados Comunes (Globales)
        foreach ($years as $year) {
            $commonHolidays = [
                ['date' => "$year-01-01", 'description' => 'Año Nuevo'],
                ['date' => "$year-05-01", 'description' => 'Día del Trabajo'],
                ['date' => "$year-12-25", 'description' => 'Navidad'],
            ];

            foreach ($commonHolidays as $h) {
                Holiday::updateOrCreate(
                    ['country_id' => null, 'date' => $h['date']],
                    ['description' => $h['description']]
                );
            }
        }

        // 2. Feriados por País (IDs basados en la base de datos)
        $countryHolidays = [
            // Argentina (1)
            1 => [
                '2025' => [
                    ['date' => '03-24', 'description' => 'Día de la Memoria'],
                    ['date' => '04-02', 'description' => 'Día de las Malvinas'],
                    ['date' => '05-25', 'description' => 'Revolución de Mayo'],
                    ['date' => '06-20', 'description' => 'Día de la Bandera'],
                    ['date' => '07-09', 'description' => 'Día de la Independencia'],
                    ['date' => '12-08', 'description' => 'Inmaculada Concepción'],
                ],
                '2026' => [
                    ['date' => '03-24', 'description' => 'Día de la Memoria'],
                    ['date' => '04-02', 'description' => 'Día de las Malvinas'],
                    ['date' => '05-25', 'description' => 'Revolución de Mayo'],
                    ['date' => '06-20', 'description' => 'Día de la Bandera'],
                    ['date' => '07-09', 'description' => 'Día de la Independencia'],
                    ['date' => '12-08', 'description' => 'Inmaculada Concepción'],
                ]
            ],
            // Colombia (5)
            5 => [
                '2025' => [
                    ['date' => '07-20', 'description' => 'Independencia de Colombia'],
                    ['date' => '08-07', 'description' => 'Batalla de Boyacá'],
                    ['date' => '12-08', 'description' => 'Día de las Velitas / Inmaculada'],
                ],
                '2026' => [
                    ['date' => '07-20', 'description' => 'Independencia de Colombia'],
                    ['date' => '08-07', 'description' => 'Batalla de Boyacá'],
                    ['date' => '12-08', 'description' => 'Día de las Velitas / Inmaculada'],
                ]
            ],
            // México (14)
            14 => [
                '2025' => [
                    ['date' => '02-05', 'description' => 'Día de la Constitución'],
                    ['date' => '03-21', 'description' => 'Natalicio de Benito Juárez'],
                    ['date' => '09-16', 'description' => 'Día de la Independencia'],
                    ['date' => '11-20', 'description' => 'Día de la Revolución'],
                    ['date' => '12-12', 'description' => 'Día de la Virgen de Guadalupe'],
                ],
                '2026' => [
                    ['date' => '02-05', 'description' => 'Día de la Constitución'],
                    ['date' => '03-21', 'description' => 'Natalicio de Benito Juárez'],
                    ['date' => '09-16', 'description' => 'Día de la Independencia'],
                    ['date' => '11-20', 'description' => 'Día de la Revolución'],
                    ['date' => '12-12', 'description' => 'Día de la Virgen de Guadalupe'],
                ]
            ],
            // Perú (18)
            18 => [
                '2025' => [
                    ['date' => '06-29', 'description' => 'San Pedro y San Pablo'],
                    ['date' => '07-28', 'description' => 'Fiestas Patrias'],
                    ['date' => '07-29', 'description' => 'Fiestas Patrias'],
                    ['date' => '08-30', 'description' => 'Santa Rosa de Lima'],
                    ['date' => '10-08', 'description' => 'Combate de Angamos'],
                    ['date' => '12-08', 'description' => 'Inmaculada Concepción'],
                    ['date' => '12-09', 'description' => 'Batalla de Ayacucho'],
                ],
                '2026' => [
                    ['date' => '06-29', 'description' => 'San Pedro y San Pablo'],
                    ['date' => '07-28', 'description' => 'Fiestas Patrias'],
                    ['date' => '07-29', 'description' => 'Fiestas Patrias'],
                    ['date' => '08-30', 'description' => 'Santa Rosa de Lima'],
                    ['date' => '10-08', 'description' => 'Combate de Angamos'],
                    ['date' => '12-08', 'description' => 'Inmaculada Concepción'],
                    ['date' => '12-09', 'description' => 'Batalla de Ayacucho'],
                ]
            ],
            // Venezuela (21)
            21 => [
                '2025' => [
                    ['date' => '04-19', 'description' => 'Declaración de la Independencia'],
                    ['date' => '06-24', 'description' => 'Batalla de Carabobo'],
                    ['date' => '07-05', 'description' => 'Día de la Independencia'],
                    ['date' => '07-24', 'description' => 'Natalicio de Simón Bolívar'],
                    ['date' => '10-12', 'description' => 'Día de la Resistencia Indígena'],
                    ['date' => '12-24', 'description' => 'Noche Buena'],
                    ['date' => '12-31', 'description' => 'Fin de Año'],
                ],
                '2026' => [
                    ['date' => '04-19', 'description' => 'Declaración de la Independencia'],
                    ['date' => '06-24', 'description' => 'Batalla de Carabobo'],
                    ['date' => '07-05', 'description' => 'Día de la Independencia'],
                    ['date' => '07-24', 'description' => 'Natalicio de Simón Bolívar'],
                    ['date' => '10-12', 'description' => 'Día de la Resistencia Indígena'],
                    ['date' => '12-24', 'description' => 'Noche Buena'],
                    ['date' => '12-31', 'description' => 'Fin de Año'],
                ]
            ],
            // Panamá (16)
            16 => [
                '2025' => [
                    ['date' => '01-09', 'description' => 'Día de los Mártires'],
                    ['date' => '11-03', 'description' => 'Separación de Panamá de Colombia'],
                    ['date' => '11-04', 'description' => 'Día de los Símbolos Patrios'],
                    ['date' => '11-05', 'description' => 'Consolidación de la Separación'],
                    ['date' => '11-10', 'description' => 'Primer Grito de Independencia'],
                    ['date' => '11-28', 'description' => 'Independencia de Panamá de España'],
                    ['date' => '12-08', 'description' => 'Día de la Madre'],
                ],
                '2026' => [
                    ['date' => '01-09', 'description' => 'Día de los Mártires'],
                    ['date' => '11-03', 'description' => 'Separación de Panamá de Colombia'],
                    ['date' => '11-04', 'description' => 'Día de los Símbolos Patrios'],
                    ['date' => '11-05', 'description' => 'Consolidación de la Separación'],
                    ['date' => '11-10', 'description' => 'Primer Grito de Independencia'],
                    ['date' => '11-28', 'description' => 'Independencia de Panamá de España'],
                    ['date' => '12-08', 'description' => 'Día de la Madre'],
                ]
            ]
        ];

        foreach ($countryHolidays as $countryId => $yearsData) {
            foreach ($yearsData as $year => $holidays) {
                foreach ($holidays as $h) {
                    Holiday::updateOrCreate(
                        ['country_id' => $countryId, 'date' => "$year-{$h['date']}"],
                        ['description' => $h['description']]
                    );
                }
            }
        }
    }
}
