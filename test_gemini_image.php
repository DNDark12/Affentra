<?php
require 'vendor/autoload.php';

$client = new \GuzzleHttp\Client();
$apiKey = "sk-dd9760f89f424b2c86b92464c27215f0";

echo "--- TEST 1: OpenAI Images Endpoint ---\n";
try {
    $res = $client->post('http://127.0.0.1:8045/v1/images/generations', [
        'headers' => ['Authorization' => "Bearer $apiKey"],
        'json' => ['model' => 'gemini-3-pro-image', 'prompt' => 'A photo of a cat']
    ]);
    echo "SUCCESS: " . substr($res->getBody(), 0, 200) . "\n";
} catch (\Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n--- TEST 2: OpenAI Chat Endpoint ---\n";
try {
    $res = $client->post('http://127.0.0.1:8045/v1/chat/completions', [
        'headers' => ['Authorization' => "Bearer $apiKey"],
        'json' => ['model' => 'gemini-3-pro-image', 'messages' => [['role'=>'user', 'content'=>'A photo of a cat']]]
    ]);
    echo "SUCCESS: " . substr($res->getBody(), 0, 200) . "\n";
} catch (\Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }
