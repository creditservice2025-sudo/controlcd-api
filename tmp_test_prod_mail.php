<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Resend\Laravel\Facades\Resend;

echo "Testing Resend PROD delivery...\n";
$from = env('MAIL_FROM_ADDRESS');
$to = 'creditservice2025@gmail.com'; // User's email
$to2 = 'onexmedia2025@gmail.com'; // Other email

echo "From: $from\n";
echo "To: $to and $to2\n";

$start = microtime(true);
try {
    $result = Resend::emails()->send([
        'from' => $from,
        'to' => [$to, $to2],
        'subject' => 'Production Verified Test - Control CD',
        'html' => '<strong>Testing delivery from verified domain: ' . $from . '</strong>',
    ]);
    $end = microtime(true);
    echo "Result ID: " . $result->id . "\n";
    echo "Duration: " . round($end - $start, 2) . "s\n";
    echo "Email successfully sent according to Resend SDK.\n";
} catch (\Exception $e) {
    echo "Caught Exception: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
}
