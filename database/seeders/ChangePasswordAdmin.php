<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Hash;

class ChangePasswordAdmin extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::find(1);

        if ($admin) {
            $admin->update([
                'password' => Hash::make('12345678'),
            ]);
        }
    }
}

