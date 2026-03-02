<?php
$log = file_get_contents('storage/logs/laravel.log');
$lines = explode("\n", $log);
foreach ($lines as $line) {
    if (strpos($line, 'ShopeeIntegration: fetchCampaignsViaCookie response') !== false) {
        echo $line . "\n";
    }
}
