<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$url = 'http://host.docker.internal:8045/v1/images/generations';
$key = 'sk-dd9760f89f424b2c86b92464c27215f0';

try {
    $response = Illuminate\Support\Facades\Http::timeout(30)
        ->withToken($key)
        ->post($url, [
            'model'  => 'gemini-3.1-flash-image',
            'prompt' => 'a cute cat',
            'n'      => 1,
            'size'   => '1024x1024',
        ]);
        
    echo "Status: " . $response->status() . "\n";
    echo "Body: " . $response->body() . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
