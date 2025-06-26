<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Route;
use Spatie\Permission\Models\Role;
use Hash;
use Database\Seeders\RolesSeeder;
use Database\Seeders\CitiesSeeder;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\CreateSuperAdminSeeder;
use Database\Seeders\ClientsSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
            CountriesSeeder::class,
            CreateSuperAdminSeeder::class,
            CitiesSeeder::class,
            /* ClientsSeeder::class,
            GuarantorsSeeder::class, */
        ]);

        $roles = [
            3 => 'Socio',
            5 => 'Cobrador',
            4 => 'Asistente'
        ];

        foreach ($roles as $roleId => $roleDisplayName) {
            for ($i = 1; $i <= 3; $i++) {
                $user = new User();
                $user->name = $roleDisplayName . ' ' . $i;
                $user->email = strtolower($roleDisplayName) . $i . '@gmail.com';
                $user->password = Hash::make('12345678');
                $user->dni = $this->generateUniqueDni();
                $user->phone = '123456789' . $i;
                $user->address = 'Calle 123';
                $user->parent_id = 1;
                $user->status = 'active';
                $user->role_id = $roleId;
                $user->save();
            }
        }

        $route = new Route();

        $route->name = 'Ruta 1';
        $route->sector = 'Sector 1';
        $route->save();
    }

    private function generateUniqueDni()
    {
        do {
            $dni = mt_rand(10000000, 99999999);
        } while (User::where('dni', $dni)->exists());

        return $dni;
    }
}
