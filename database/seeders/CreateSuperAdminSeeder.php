<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Hash;

class CreateSuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'super@gmail.com',
            'password' => Hash::make('12345678'),
            'role_id' => 1,
            'dni' => '1122334455',
            'phone' => '123456789',
            'address' => 'Dirección',
            'status' => 'active',
        ]);

        $role = Role::where(['name' => 'super-admin'])->first();

        $user->assignRole($role);
    }
}
