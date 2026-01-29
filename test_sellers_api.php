<?php
use App\Models\Seller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\CitiesService;

$service = app(CitiesService::class);
$request = new Request();
// Alejandra3's city_id is 24 (from my tinker check)
$response = $service->getSellersByCity(24, $request);
$data = json_decode($response->getContent(), true);

foreach ($data['data'] as $seller) {
    if ($seller['user']['name'] === 'Alejandra3') {
        echo "Found Alejandra3:\n";
        echo "Seller ID: " . $seller['id'] . "\n";
        echo "User ID: " . $seller['user']['id'] . "\n";
    }
}
