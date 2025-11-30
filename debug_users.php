<?php
try {
    $user = \App\Models\User::where('email', 'super@gmail.com')->first();
    if (!$user) {
        echo "Admin user not found\n";
        exit;
    }
    \Illuminate\Support\Facades\Auth::login($user);

    $service = app(\App\Services\UserService::class);
    $result = $service->getUsers('', "10", null);

    if ($result->status() !== 200) {
        echo "Error: " . $result->getContent() . "\n";
    } else {
        $data = json_decode($result->getContent());
        echo "Success: " . count($data->data->data) . " users found\n";
    }
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
