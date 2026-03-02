<?php

use App\Models\PlatformConnection;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\MessageFormatter;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$connection = PlatformConnection::find(1);
if (!$connection) {
    echo "Connection ID 1 not found\n";
    exit(1);
}

echo "Testing connection to Shopee Cookie API...\n";
// Rely on model's decryption (assuming it has it)
$cookieString = (string) $connection->cookie;
$ua = (string) ($connection->cookie_user_agent ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36');

if (!$cookieString || str_contains($cookieString, '{"iv":')) {
    echo "Cookie still appears encrypted or empty. Trying to access via property directly.\n";
}

$headers = [
    'User-Agent' => $ua,
    'Accept' => 'application/json, text/plain, */*',
    'Accept-Language' => 'en-US,en;q=0.9,vi;q=0.8',
    'Cookie' => $cookieString,
    'Referer' => 'https://affiliate.shopee.vn/offer/campaign_list',
];

$client = new Client([
    'timeout' => 10,
    'verify' => false,
]);

$url = 'https://affiliate.shopee.vn/api/v3/gql';
$body = [
    'operationName' => 'getBatchAffiliateCampaignList',
    'query' => 'query getBatchAffiliateCampaignList($pageNum: Int, $pageSize: Int, $campaignStatus: Int, $campaignType: Int) { getBatchAffiliateCampaignList(pageNum: $pageNum, pageSize: $pageSize, campaignStatus: $campaignStatus, campaignType: $campaignType) { list { campaignId campaignName campaignStatus campaignStartTime campaignEndTime campaignUrl campaignDescription campaignImpressionNum campaignClickNum bannerImageId } pagination { totalCount pageNum pageSize } } }',
    'variables' => [
        'pageNum' => 1,
        'pageSize' => 20,
        'campaignStatus' => 1,
        'campaignType' => 0,
    ],
];

echo "Sending request to: $url\n";

try {
    $response = $client->post($url, [
        'headers' => $headers,
        'json' => $body,
    ]);

    echo "Status: " . $response->getStatusCode() . "\n";
    $payload = json_decode($response->getBody(), true);
    echo "Payload Keys: " . implode(', ', array_keys($payload ?? [])) . "\n";
    echo "Code: " . ($payload['code'] ?? 'N/A') . "\n";

    if (isset($payload['data'])) {
        echo "Data Keys: " . implode(', ', array_keys($payload['data'])) . "\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
