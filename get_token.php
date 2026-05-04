<?php

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = \App\Models\User::first();
if (!$user) {
    echo "No user found!\n";
    exit(1);
}

echo "User: " . $user->email . "\n";
$token = $user->createToken('admin-test-token')->plainTextToken;
echo "Token: " . $token . "\n";
