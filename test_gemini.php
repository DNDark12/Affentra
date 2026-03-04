<?php
require 'vendor/autoload.php';
putenv('APP_ENV=testing'); // Skip docker remap
$client = new \GuzzleHttp\Client();
$url = "http://127.0.0.1:8045/v1beta/models/gemini-1.5-pro:generateContent?key=sk-dd9760f89f424b2c86b92464c27215f0";

$payload = [
    'contents' => [
        [
            'parts' => [['text' => 'Hello']]
        ]
    ]
];

echo "Sending to: $url\n";
try {
    $response = $client->post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'json' => $payload,
    ]);
    echo "SUCCESS:\n";
    echo substr($response->getBody(), 0, 500) . "\n";
} catch (\GuzzleHttp\Exception\ClientException $e) {
    echo "ERROR " . $e->getResponse()->getStatusCode() . ":\n";
    echo $e->getResponse()->getBody() . "\n";
} catch (\Exception $e) {
    echo "GENERAL ERROR: " . $e->getMessage() . "\n";
}
