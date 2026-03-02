<?php

use App\Models\PlatformConnection;
use App\Models\User;
use App\Models\Campaign;
use App\Services\Campaign\CampaignSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = User::first();
$connection = PlatformConnection::where('platform', 'shopee')->first();

if (!$connection) {
    echo "No Shopee connection found.\n";
    exit(1);
}

// Simulated Shopee data based on my enhanced mapping logic
$rows = [
    [
        'campaignId' => '12345',
        'campaignName' => 'Test Campaign 1',
        'campaignStatus' => 1,
        'campaignStartTime' => now()->subDays(7)->timestamp,
        'campaignEndTime' => now()->addDays(7)->timestamp,
        'campaignUrl' => 'https://shopee.vn/m/test-1',
    ],
    [
        'id' => '67890',
        'name' => 'Test Campaign 2',
        'status' => 'ongoing',
        'start_time' => now()->subDays(1)->toDateTimeString(),
        'end_time' => now()->addDays(30)->toDateTimeString(),
    ]
];

$service = app(CampaignSyncService::class);
$now = now();

echo "Mapping rows...\n";
$payload = [];
foreach ($rows as $row) {
    try {
        $reflection = new ReflectionClass(CampaignSyncService::class);
        $method = $reflection->getMethod('mapShopeeCampaign');
        $method->setAccessible(true);

        $mapped = $method->invoke($service, $connection, $row, $now);
        if ($mapped) {
            $payload[] = $mapped;
            echo "Mapped Campaign: " . $mapped['name'] . " (Ext ID: " . $mapped['external_id'] . ")\n";
        } else {
            echo "Failed to map row: " . json_encode($row) . "\n";
        }
    } catch (\Exception $e) {
        echo "Error mapping: " . $e->getMessage() . "\n";
    }
}

if (!empty($payload)) {
    echo "Upserting " . count($payload) . " records...\n";
    try {
        $uniqueKeys = ['platform', 'external_id', 'user_id'];
        $updateKeys = array_diff(array_keys($payload[0]), $uniqueKeys);

        $result = Campaign::upsert($payload, $uniqueKeys, $updateKeys);
        echo "Upsert Result: " . $result . "\n";

        $count = Campaign::where('platform', 'shopee')->count();
        echo "Total Shopee Campaigns in DB: " . $count . "\n";

        $first = Campaign::where('external_id', '12345')->first();
        if ($first) {
            echo "Verified DB Record: " . $first->name . " | Status: " . ($first->status instanceof \App\Enums\CampaignStatus ? $first->status->value : $first->status) . " | Start: " . $first->date_start . "\n";
        }
    } catch (\Exception $e) {
        echo "Error upserting: " . $e->getMessage() . "\n";
        echo "Trace: " . $e->getTraceAsString() . "\n";
    }
} else {
    echo "No payload to upsert.\n";
}
