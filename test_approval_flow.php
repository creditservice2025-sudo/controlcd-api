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

// 2. Buscar un cliente o crear uno para la prueba
$client = Client::first();
if (!$client) {
    echo "No se encontró un cliente para la prueba.\n";
    return;
}

// Asegurarse de que el cliente tenga una imagen para que se dispare la EDICIÓN
if (!$client->images()->where('type', 'document')->exists()) {
    $client->images()->create([
        'path' => 'clients/test_image.jpg',
        'type' => 'document'
    ]);
    echo "Imagen de prueba creada para el cliente.\n";
}

echo "Probando interceptación en ClientService para el cliente: {$client->name} (ID: {$client->id})\n";

// 3. Simular Request de actualización de imagen
// No usamos fake completo para evitar problemas con persistencia en Tinker si no se maneja bien
$file = UploadedFile::fake()->image('documento_nuevo.jpg');

$request = Request::create('/api/clients/update/' . $client->id, 'PUT', [
    'images' => [
        0 => ['type' => 'document']
    ]
]);
$request->files->set('images', [
    0 => ['file' => $file]
]);

// 4. Ejecutar Update
try {
    $service = app(ClientService::class);
    // Forzamos el usuario en el request también por si acaso
    $request->setUserResolver(fn() => $vendedorUser);
    
    $service->update($request, $client->id);
    echo "ERROR: El servicio no debería haber aplicado el cambio directamente.\n";
} catch (\Exception $e) {
    echo "Interceptado correctamente (Exception): " . $e->getMessage() . "\n";
}

// Verificar logs si no hubo excepción pero no se actualizó
$approvalRequest = ImageApprovalRequest::where('user_id', $vendedorUser->id)
    ->where('entity_id', $client->id)
    ->where('status', 'pending')
    ->orderBy('created_at', 'desc')
    ->first();

if ($approvalRequest) {
    echo "Solicitud de aprobación creada exitosamente (ID: {$approvalRequest->id})\n";
    echo "Ruta temporal: {$approvalRequest->new_image_path}\n";
    
    // 6. Simular Aprobación por Admin
    auth()->login(User::where('role_id', 1)->first());
    
    $controller = app(\App\Http\Controllers\ApprovalRequestController::class);
    $response = $controller->approve($approvalRequest->id);
    
    $approvalRequest->refresh();
    echo "Token generado: {$approvalRequest->token}\n";
    echo "Estado tras aprobación: {$approvalRequest->status}\n";
} else {
    echo "FAIL: No se creó la solicitud de aprobación. Revisa laravel.log.\n";
}
