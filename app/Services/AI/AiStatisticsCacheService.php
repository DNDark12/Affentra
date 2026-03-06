<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;

class AiStatisticsCacheService
{
    private const SCHEMA_VERSION = 3;

    public function ttlSeconds(): int
    {
        return max(5, (int) config('services.ai.statistics_cache_ttl', 60));
    }

    public function accountCacheKey(int $userId): string
    {
        $userVersion = $this->version($this->userVersionKey($userId));

        return "ai:stats:s" . self::SCHEMA_VERSION . ":account:{$userId}:v{$userVersion}";
    }

    public function linkCacheKey(int $userId, int $trackingLinkId): string
    {
        $userVersion = $this->version($this->userVersionKey($userId));
        $linkVersion = $this->version($this->linkVersionKey($trackingLinkId));

        return "ai:stats:s" . self::SCHEMA_VERSION . ":link:{$userId}:{$trackingLinkId}:u{$userVersion}:l{$linkVersion}";
    }

    public function bumpFor(int $userId, ?int $trackingLinkId = null): void
    {
        $this->bumpVersion($this->userVersionKey($userId));

        if ($trackingLinkId !== null && $trackingLinkId > 0) {
            $this->bumpVersion($this->linkVersionKey($trackingLinkId));
        }
    }

    private function version(string $key): int
    {
        $version = (int) Cache::get($key, 1);

        return $version > 0 ? $version : 1;
    }

    private function bumpVersion(string $key): void
    {
        $current = $this->version($key);
        Cache::put($key, $current + 1, now()->addDays(7));
    }

    private function userVersionKey(int $userId): string
    {
        return "ai:stats:v:user:{$userId}";
    }

    private function linkVersionKey(int $trackingLinkId): string
    {
        return "ai:stats:v:link:{$trackingLinkId}";
    }
}
