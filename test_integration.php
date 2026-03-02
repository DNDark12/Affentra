<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\PlatformConnection;
use App\Services\Integration\Shopee\ShopeeIntegration;

$connection = PlatformConnection::find(1);
if (!$connection) {
    echo "Connection ID 1 not found\n";
    exit(1);
}

echo "Testing connection status...\n";
$adapter = app(ShopeeIntegration::class);

try {
    $result = $adapter->testConnection($connection);
    echo "testConnection returned: " . ($result ? 'true' : 'false') . "\n";
} catch (\Exception $e) {
    echo "Exception in testConnection: " . $e->getMessage() . "\n";
}

echo "\nConnection Status after test: " . $connection->fresh()->status . "\n";
echo "Last Error: " . $connection->fresh()->last_error . "\n";

echo "\nTesting fetchCampaigns...\n";
try {
    $campaigns = $adapter->fetchCampaigns($connection);
    echo "fetchCampaigns returned " . count($campaigns) . " campaigns\n";
} catch (\Exception $e) {
    echo "Exception in fetchCampaigns: " . $e->getMessage() . "\n";
}

echo "\nConnection Status after fetchCampaigns: " . $connection->fresh()->status . "\n";
echo "Last Error: " . $connection->fresh()->last_error . "\n";
