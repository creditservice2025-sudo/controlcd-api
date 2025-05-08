<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Client;
use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;


class ClientsSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $client = new Client();
            $client->name = 'Cliente ' . $i;
            $client->dni = $this->generateUniqueDni();
            $client->address = 'Calle 123';
            $client->geolocation = json_encode([
                'latitude' => fake()->latitude(),
                'longitude' => fake()->longitude(),
            ]);
            $client->phone = fake()->phoneNumber();
            $client->email = 'cliente' . $i . '@gmail.com';
            $client->save();

            $this->saveClientImages($client);
        }

    }

    private function generateUniqueDni()
    {
        do {
            $dni = mt_rand(10000000, 99999999);
        } while (Client::where('dni', $dni)->exists());
        return $dni;
    }

    private function saveClientImages(Client $client)
    {
        $types = ['profile', 'gallery'];

        foreach ($types as $type) {
            $imageName = Str::random(10) . '.jpg';

            $localImagePath = storage_path('app/public/default.jpg');

            Storage::copy("public/default.jpg", "public/clients/{$imageName}");

            Image::create([
                'path' => "storage/clients/{$imageName}",
                'type' => $type,
                'client_id' => $client->id,
            ]);
        }
    }
}
