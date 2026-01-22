<?php

use App\Models\User;
use App\Models\Client;
use App\Models\Image;
use App\Models\ImageApprovalRequest;
use App\Services\ClientService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

// 1. Simular Vendedor (Rol 5)
$vendedorUser = User::where('role_id', 5)->first();
if (!$vendedorUser) {
    echo "No se encontró un vendedor con rol 5 para la prueba.\n";
    return;
}
auth()->login($vendedorUser);

// 2. Buscar un cliente con imagen para simular EDICIÓN
$client = Client::has('images')->first();
if (!$client) {
    echo "No se encontró un cliente con imágenes para probar la edición.\n";
    return;
}

echo "Probando interceptación en ClientService para el cliente: {$client->name} (ID: {$client->id})\n";

// 3. Simular Request de actualización de imagen
Storage::fake('public');
$file = UploadedFile::fake()->image('documento_nuevo.jpg');

$request = new Request();
$request->files->set('images', [
    0 => ['file' => $file, 'type' => 'document']
]);
$request->merge([
    'images' => [
        0 => ['type' => 'document']
    ]
]);

// 4. Ejecutar Update
try {
    $service = app(ClientService::class);
    $service->update($request, $client->id);
    echo "ERROR: El servicio no debería haber aplicado el cambio directamente.\n";
} catch (\Exception $e) {
    echo "Interceptado correctamente: " . $e->getMessage() . "\n";
}

// 5. Verificar si se creó la solicitud
$approvalRequest = ImageApprovalRequest::where('user_id', $vendedorUser->id)
    ->where('entity_id', $client->id)
    ->where('status', 'pending')
    ->first();

if ($approvalRequest) {
    echo "Solicitud de aprobación creada exitosamente (ID: {$approvalRequest->id})\n";
    echo "Ruta temporal: {$approvalRequest->new_image_path}\n";
    
    // 6. Simular Aprobación por Admin
    auth()->logout();
    $admin = User::where('role_id', 1)->first();
    auth()->login($admin);
    
    $controller = app(\App\Http\Controllers\ApprovalRequestController::class);
    $response = $controller->approve($approvalRequest->id);
    
    $approvalRequest->refresh();
    echo "Token generado: {$approvalRequest->token}\n";
    echo "Estado tras aprobación: {$approvalRequest->status}\n";
} else {
    echo "FAIL: No se creó la solicitud de aprobación.\n";
}
