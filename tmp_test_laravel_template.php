<?php

use App\Mail\WelcomeCompanyMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$to = 'creditservice2025@gmail.com'; 
$to2 = 'onexmedia2025@gmail.com';

echo "Sending WELCOME EMAIL TEMPLATE via Laravel Facade...\n";
echo "Mailer: " . config('mail.default') . "\n";
echo "QUEUE: " . config('queue.default') . "\n";

try {
    $user = User::where('email', 'onexmedia2025@gmail.com')->first() ?? User::factory()->make(['email' => 'onexmedia2025@gmail.com', 'name' => 'Usuario Prueba']);
    $company = Company::first() ?? Company::factory()->make(['name' => 'Empresa de Prueba']);
    
    Mail::to([$to, $to2])->send(new WelcomeCompanyMail($user, $company, '3bcCgK4M'));
    echo "SUCCESS! Mailable dispatched. Check your inbox.\n";
} catch (\Exception $e) {
    echo "ERROR: " . get_class($e) . ": " . $e->getMessage() . "\n";
}
