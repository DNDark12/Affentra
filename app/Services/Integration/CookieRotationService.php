<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Safely rotates Shopee cookies after a successful scraper/proxy call.
 *
 * Only updates the `cookie` field inside existing cookie_header JSON.
 * Never touches profiles, raw_headers, or anti-bot tokens.
 *
 * Guarded by:
 * - Feature flag (SHOPEE_COOKIE_AUTO_ROTATION_ENABLED)
 * - Eligibility gate (ok response, no error_type in denylist, has SPC_EC)
 * - Redis lock (prevent race between concurrent proxy calls)
 * - Optimistic guard (updated_at check to prevent stale writes)
 * - Hash comparison (skip if cookie hasn't actually changed)
 */
class CookieRotationService
{
    /** Error types that disqualify rotation. */
    private const ERROR_TYPE_DENYLIST = [
        'auth_failure',
        'blocked_bot',
        'rate_limited',
        'network',
    ];

    public function __construct(
        private readonly CookieCredentialService $cookieService,
    ) {}

    /**
     * Attempt to rotate cookies for a connection after a successful scraper call.
     *
     * @param  PlatformConnection  $connection
     * @param  array{
     *     ok?: bool,
     *     error_type?: string|null,
     *     refreshed_cookie?: string|null,
     *     cookie_hash?: string|null,
     * }  $scraperMeta  Fields from the scraper response
     * @return string  Result status: success|skipped_flag_off|skipped_not_eligible|skipped_invalid|skipped_hash_identical|conflict|lock_timeout
     */
    public function rotateIfEligible(PlatformConnection $connection, array $scraperMeta): string
    {
        $connectionId = $connection->id;

        // Gate 1: Feature flag
        if (! config('services.shopee.cookie_auto_rotation', false)) {
            Log::debug('cookie_rotation.skipped_flag_off', [
                'connection_id' => $connectionId,
            ]);

            return 'skipped_flag_off';
        }

        // Gate 2: Response eligibility
        $ok = (bool) ($scraperMeta['ok'] ?? false);
        $errorType = $scraperMeta['error_type'] ?? null;
        $refreshedCookie = $scraperMeta['refreshed_cookie'] ?? null;

        if (! $ok || in_array($errorType, self::ERROR_TYPE_DENYLIST, true)) {
            Log::debug('cookie_rotation.skipped_not_eligible', [
                'connection_id' => $connectionId,
                'ok' => $ok,
                'error_type' => $errorType,
            ]);

            return 'skipped_not_eligible';
        }

        if (empty($refreshedCookie) || ! is_string($refreshedCookie)) {
            return 'skipped_not_eligible';
        }

        // Gate 3: Cookie validity — must contain SPC_EC
        $normalizedNew = $this->cookieService->normalize($refreshedCookie);
        if (! $this->cookieService->isValidShopeeCookie($normalizedNew)) {
            Log::warning('cookie_rotation.skipped_invalid', [
                'connection_id' => $connectionId,
                'reason' => 'missing_SPC_EC',
            ]);

            return 'skipped_invalid';
        }

        // Gate 4: Hash comparison — skip if identical
        $currentRaw = $this->cookieService->extractRawCookie($connection);
        $currentHash = $this->cookieService->hashCookie($currentRaw);
        $newHash = $this->cookieService->hashCookie($normalizedNew);

        if ($currentHash === $newHash) {
            Log::debug('cookie_rotation.skipped_hash_identical', [
                'connection_id' => $connectionId,
                'hash' => $currentHash,
            ]);

            return 'skipped_hash_identical';
        }

        // Gate 5: Redis lock — prevent concurrent rotation
        $lockKey = "cookie:rotate:{$connectionId}";
        $lock = Cache::lock($lockKey, 5);

        if (! $lock->get()) {
            Log::info('cookie_rotation.lock_timeout', [
                'connection_id' => $connectionId,
            ]);

            return 'lock_timeout';
        }

        try {
            // Optimistic guard — re-read to catch concurrent changes
            $fresh = PlatformConnection::find($connectionId);
            if (! $fresh) {
                return 'skipped_not_eligible';
            }

            // Check updated_at hasn't changed since we loaded the connection
            if ($fresh->updated_at->ne($connection->updated_at)) {
                Log::info('cookie_rotation.conflict', [
                    'connection_id' => $connectionId,
                    'reason' => 'updated_at_changed',
                ]);

                return 'conflict';
            }

            // Merge: only update the 'cookie' field in the JSON, preserve everything else
            $existing = json_decode($fresh->cookie_header ?? '{}', true) ?: [];

            // Merge old cookie + new cookie (new overrides by name)
            $mergedCookie = $this->cookieService->mergeCookies(
                $currentRaw,
                $normalizedNew,
            );

            $existing['cookie'] = $mergedCookie;

            $fresh->cookie_header = json_encode($existing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $fresh->save();

            Log::info('cookie_rotation.success', [
                'connection_id' => $connectionId,
                'old_hash' => $currentHash,
                'new_hash' => $this->cookieService->hashCookie($mergedCookie),
            ]);

            return 'success';

        } finally {
            $lock->release();
        }
    }
}
