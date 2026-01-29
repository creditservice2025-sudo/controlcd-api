<?php
use App\Models\Seller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\CitiesService;
use Illuminate\Support\Facades\Auth;

// Find an admin user to mock authentication
$admin = User::where('role_id', 1)->first();
Auth::login($admin);

$service = app(CitiesService::class);
$request = new Request();
// Alejandra3 is in city 24
$response = $service->getSellersByCity(24, $request);
$data = json_decode($response->getContent(), true);

echo "Total sellers found in city 24: " . count($data['data']) . "\n";
foreach ($data['data'] as $seller) {
    echo "ID: " . $seller['id'] . " | User Name: " . ($seller['user']['name'] ?? 'N/A') . " | User ID: " . ($seller['user']['id'] ?? 'N/A') . "\n";
}
