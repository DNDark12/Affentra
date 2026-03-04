<?php
require 'vendor/autoload.php';
putenv('APP_ENV=testing'); // Skip docker remap
$client = new \GuzzleHttp\Client();
$url = "http://127.0.0.1:8045/v1/chat/completions";
$payload = [
    'model' => 'gemini-3.1-pro-high',
    'messages' => [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]]
    ]
];

echo "Sending to: $url\n";
try {
    $response = $client->post($url, [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer sk-dd9760f89f424b2c86b92464c27215f0'
        ],
        'json' => $payload,
    ]);
    echo "SUCCESS:\n";
    echo $response->getBody() . "\n";
} catch (\GuzzleHttp\Exception\ClientException $e) {
    echo "ERROR " . $e->getResponse()->getStatusCode() . ":\n";
    echo $e->getResponse()->getBody() . "\n";
}
