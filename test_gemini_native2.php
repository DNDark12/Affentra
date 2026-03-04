<?php
require 'vendor/autoload.php';
$client = new \GuzzleHttp\Client();
$apiKey = "sk-dd9760f89f424b2c86b92464c27215f0";

try {
    $res = $client->post('http://127.0.0.1:8045/v1beta/models/imagen-3.0-generate-001:predict?key=' . $apiKey, [
        'headers' => ['Content-Type' => 'application/json'],
        'json' => [
            'instances' => [['prompt' => 'A photo of a cat']],
            'parameters' => ['sampleCount' => 1]
        ]
    ]);
    echo "SUCCESS: " . substr($res->getBody(), 0, 200) . "\n";
} catch (\Exception $e) { echo "ERROR PREDICT: " . $e->getMessage() . "\n"; }

try {
    $res = $client->post('http://127.0.0.1:8045/v1beta/models/imagen-3.0-generate-001:generateContent?key=' . $apiKey, [
        'headers' => ['Content-Type' => 'application/json'],
        'json' => ['contents' => [['role' => 'user', 'parts' => [['text' => 'A photo of a cat']]]]]
    ]);
    echo "SUCCESS: " . substr($res->getBody(), 0, 200) . "\n";
} catch (\Exception $e) { echo "ERROR GC: " . $e->getMessage() . "\n"; }

