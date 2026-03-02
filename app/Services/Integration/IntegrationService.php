<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Jobs\Sync\SyncPlatformConnectionJob;
use App\Jobs\Sync\SyncPaymentDataJob;
use App\Jobs\Sync\SyncShopeeCampaignsForConnectionJob;
use App\Models\PlatformConnection;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

class IntegrationService
{
    /**
     * @return list<string>
     */
    public function availableMethodsForUser(User $user): array
    {
        $methods = ['open_api', 'portal_export'];

        if ($this->canUseCookieMethod($user)) {
            $methods[] = 'cookie';
        }

        return $methods;
    }

    /**
     * List all connections for a user.
     *
     * @return Collection<int, PlatformConnection>
     */
    public function listConnections(User $user): Collection
    {
        return $user->platformConnections()
            ->withCount('syncRuns')
            ->with(['syncRuns' => static function ($query): void {
                $query->latest('started_at')->limit(5);
            }])
            ->latest()
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listConnectionsForIndex(User $user): array
    {
        return $this->listConnections($user)
            ->map(function (PlatformConnection $connection): array {
                $capabilities = $this->syncCapabilitiesSnapshot($connection);

                return [
                    'id'               => $connection->id,
                    'label'            => $connection->label,
                    'platform'         => $connection->platform,
                    'method'           => $connection->method,
                    'app_id'           => $connection->app_id,
                    'has_open_api'     => !empty($connection->app_id) && !empty($connection->app_secret),
                    'has_cookie'       => !empty($connection->cookie_header),
                    'cookie_source'    => $connection->cookie_source,
                    'status'           => $connection->status,
                    'sync_mode'        => $connection->sync_mode,
                    'capabilities'     => $capabilities,
                    'last_sync_at'     => $connection->last_sync_at?->toIso8601String(),
                    'last_campaign_sync_at' => $connection->last_campaign_sync_at?->toIso8601String(),
                    'last_sync_status' => $connection->last_sync_status,
                    'last_error'       => $connection->last_error,
                    'last_error_at'    => $connection->last_error_at?->toIso8601String(),
                    'backfill_days_effective' => $connection->backfill_days_override
                        ?? (int) config("integrations.{$connection->platform}.backfill_days", 14),
                    'sync_runs_count'  => $connection->sync_runs_count ?? 0,
                    'recent_sync_runs' => $connection->syncRuns->map(function ($run) {
                        return [
                            'id' => $run->id,
                            'started_at' => $run->started_at?->toIso8601String(),
                            'status' => $run->status,
                            'type' => $run->type,
                            'records_fetched' => $run->records_fetched,
                            'records_upserted' => $run->records_upserted,
                            'records_failed' => $run->records_failed,
                            // Backward-compatible alias for older UI keys.
                            'records_inserted' => $run->records_upserted,
                            'error_message' => $run->error_message,
                            'details' => $run->details,
                            'finished_at' => $run->finished_at?->toIso8601String(),
                            // Backward-compatible alias for older UI keys.
                            'completed_at' => $run->finished_at?->toIso8601String(),
                        ];
                    })->all(),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listConnectionsForOfferDiscovery(User $user): array
    {
        return $this->listConnections($user)
            ->filter(static fn (PlatformConnection $connection): bool => IntegrationFactory::supports($connection->platform))
            ->map(static fn (PlatformConnection $connection): array => [
                'id'           => $connection->id,
                'label'        => $connection->label,
                'platform'     => $connection->platform,
                'method'       => $connection->method,
                'app_id'       => $connection->app_id,
                'status'       => $connection->status,
                'sync_mode'    => $connection->sync_mode,
                'last_sync_at' => $connection->last_sync_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Create a new platform connection.
     *
     * @param  array<string, mixed>  $data
     */
    public function createConnection(User $user, array $data): PlatformConnection
    {
        $method = $data['method'] ?? 'open_api';

        if ($method === 'cookie' && ! $this->canUseCookieMethod($user)) {
            throw new RuntimeException('Cookie method is currently disabled for your account.');
        }

        $payload = [
            'platform'   => $data['platform'],
            'label'      => $data['label'] ?? null,
            'method'     => $method,
            'status'     => 'inactive',
            'sync_mode'  => 'manual',
        ];

        if ($payload['method'] === 'open_api') {
            $payload['app_id'] = $data['app_id'] ?? null;
            $payload['app_secret'] = $data['app_secret'] ?? null;
        } elseif ($payload['method'] === 'cookie') {
            $payload['consent_acknowledged_at'] = now();
            if (!empty($data['curl_command'])) {
                $parser = new \App\Services\Integration\Parsers\CurlCookieParserService();
                $parsedBlocks = $parser->parseMany((string) $data['curl_command']);
                if ($parsedBlocks === []) {
                    throw new RuntimeException('Không parse được cURL command.');
                }

                $mergedCookieHeader = null;
                $resolvedUserAgent = null;
                foreach ($parsedBlocks as $parsed) {
                    $mergedCookieHeader = $this->mergeCookieCredentialsPayload(
                        parsed: $parsed,
                        existingCookieHeader: $mergedCookieHeader,
                    );
                    $resolvedUserAgent = $parsed['user_agent'] ?? $resolvedUserAgent;
                }

                $payload['cookie_header'] = $mergedCookieHeader;
                $payload['cookie_user_agent'] = $resolvedUserAgent;
                $payload['cookie_source'] = 'curl';
            } elseif (!empty($data['cookie_header'])) {
                $payload['cookie_header'] = $data['cookie_header'];
                $payload['cookie_user_agent'] = null;
                $payload['cookie_source'] = 'manual';
            }
        } elseif ($payload['method'] === 'portal_export') {
            $payload['app_id'] = null;
            $payload['app_secret'] = null;
        }

        if ($payload['method'] === 'cookie' && blank($payload['cookie_header'] ?? null)) {
            throw new RuntimeException('Cookie credentials are required for Cookie method.');
        }

        return $user->platformConnections()->create($payload);
    }

    /**
     * Update an existing platform connection.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateConnection(User $actor, PlatformConnection $connection, array $data): bool
    {
        $payload = [];
        $targetMethod = $data['method'] ?? $connection->method;

        if ($targetMethod === 'cookie' && ! $this->canUseCookieMethod($actor)) {
            throw new RuntimeException('Cookie method is currently disabled for your account.');
        }

        if (array_key_exists('method', $data) && $targetMethod !== $connection->method) {
            $payload['method'] = $targetMethod;
        }

        if ($targetMethod === 'open_api' && !empty($data['app_id'])) {
            $payload['app_id'] = $data['app_id'];
        }
        
        if ($targetMethod === 'open_api' && !empty($data['app_secret'])) {
            $payload['app_secret'] = $data['app_secret'];
        }

        if ($targetMethod === 'cookie') {
            if (!empty($data['cookie_header']) || !empty($data['curl_command'])) {
                if (!empty($data['curl_command'])) {
                    $parser = new \App\Services\Integration\Parsers\CurlCookieParserService();
                    $parsedBlocks = $parser->parseMany((string) $data['curl_command']);
                    if ($parsedBlocks === []) {
                        throw new RuntimeException('Không parse được cURL command.');
                    }

                    $mergedCookieHeader = $connection->cookie_header;
                    $resolvedUserAgent = $connection->cookie_user_agent;
                    foreach ($parsedBlocks as $parsed) {
                        $mergedCookieHeader = $this->mergeCookieCredentialsPayload(
                            parsed: $parsed,
                            existingCookieHeader: $mergedCookieHeader,
                        );
                        $resolvedUserAgent = $parsed['user_agent'] ?? $resolvedUserAgent;
                    }

                    $payload['cookie_header'] = $mergedCookieHeader;
                    $payload['cookie_user_agent'] = $resolvedUserAgent;
                    $payload['cookie_source'] = 'curl';
                } elseif (!empty($data['cookie_header'])) {
                    $payload['cookie_header'] = $data['cookie_header'];
                    $payload['cookie_user_agent'] = null;
                    $payload['cookie_source'] = 'manual';
                }

                // Require re-validation after credential changes.
                $payload['cookie_validated_at'] = null;
            }

            if (array_key_exists('consent_acknowledged', $data) && (bool) $data['consent_acknowledged'] === true) {
                $payload['consent_acknowledged_at'] = now();
            }

            $nextCookieHeader = $payload['cookie_header'] ?? $connection->cookie_header;
            if (blank($nextCookieHeader)) {
                throw new RuntimeException('Cookie credentials are required for Cookie method.');
            }

            $nextConsentAt = $payload['consent_acknowledged_at'] ?? $connection->consent_acknowledged_at;
            if ($nextConsentAt === null) {
                throw new RuntimeException('Consent acknowledgement is required for Cookie method.');
            }
        }

        if (array_key_exists('label', $data)) {
            $payload['label'] = $data['label'];
        }

        if (array_key_exists('sync_mode', $data)) {
            $payload['sync_mode'] = $data['sync_mode'];
        }

        if (array_key_exists('status', $data)) {
            $payload['status'] = $data['status'];
        }

        if (empty($payload)) {
            return false;
        }

        return $connection->update($payload);
    }

    public function removeConnection(PlatformConnection $connection): bool
    {
        return (bool) $connection->delete();
    }

    public function canManageConnections(User $user): bool
    {
        return ! $user->isCTV();
    }

    public function canUseCookieMethod(User $user): bool
    {
        return (bool) config('integrations.enable_cookie_method', false)
            && ($user->isOwner() || $user->isLeader());
    }

    public function isOwnedBy(User $user, PlatformConnection $connection): bool
    {
        return $connection->user_id === $user->id;
    }

    public function canSyncConnection(User $user, PlatformConnection $connection): bool
    {
        return $this->isOwnedBy($user, $connection) || $user->isOwner();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSyncHistory(PlatformConnection $connection, int $limit = 10): array
    {
        return $connection->syncRuns()
            ->latest()
            ->take($limit)
            ->get()
            ->map(static fn ($run): array => [
                'id'               => $run->id,
                'status'           => $run->status,
                'type'             => $run->type,
                'started_at'       => $run->started_at?->toIso8601String(),
                'finished_at'      => $run->finished_at?->toIso8601String(),
                'records_fetched'  => $run->records_fetched,
                'records_upserted' => $run->records_upserted,
                'records_failed'   => $run->records_failed,
                'error_message'    => $run->error_message,
                'details'          => $run->details,
            ])
            ->all();
    }

    public function canSyncNow(PlatformConnection $connection): bool
    {
        return in_array($connection->status, ['active', 'error'], true);
    }

    public function supportsSync(PlatformConnection $connection): bool
    {
        return IntegrationFactory::supports($connection->platform);
    }

    public function dispatchManualSync(User $actor, PlatformConnection $connection): SyncRun
    {
        $syncRun = SyncRun::create([
            'user_id' => $actor->id,
            'platform_connection_id' => $connection->id,
            'integration' => $connection->platform,
            'type' => 'manual',
            'status' => 'pending',
            'started_at' => now(),
            'records_fetched' => 0,
            'records_upserted' => 0,
            'records_failed' => 0,
            'error_message' => 'Queued for execution.',
        ]);

        SyncPlatformConnectionJob::dispatch(
            connectionId: $connection->id,
            type: 'manual',
            userId: $actor->id,
            syncRunId: $syncRun->id,
        )->onQueue('sync');

        // Keep payout status/settlement data in sync with manual sync requests.
        SyncPaymentDataJob::dispatch($connection, triggerType: 'manual')->onQueue('sync');

        if ($connection->platform === 'shopee') {
            SyncShopeeCampaignsForConnectionJob::dispatch($connection->id, 'manual')->onQueue('sync');
        }

        return $syncRun;
    }

    /**
     * @return array{valid: bool, checks: array<string, mixed>, message: string}
     */
    public function testConnection(PlatformConnection $connection): array
    {
        if (! IntegrationFactory::supports($connection->platform)) {
            throw new RuntimeException('Platform not supported.');
        }

        $adapter = IntegrationFactory::make($connection->platform);

        if (method_exists($adapter, 'testConnectionDetailed')) {
            /** @var array{valid: bool, checks?: array<string, mixed>, message?: string} $detailed */
            $detailed = $adapter->testConnectionDetailed($connection);

            return [
                'valid' => (bool) ($detailed['valid'] ?? false),
                'checks' => $detailed['checks'] ?? [],
                'message' => (string) ($detailed['message'] ?? (($detailed['valid'] ?? false) ? 'Connection valid.' : 'Connection failed.')),
            ];
        }

        $valid = $adapter->testConnection($connection);

        return [
            'valid' => $valid,
            'checks' => [],
            'message' => $valid ? 'Connection valid.' : 'Connection failed.',
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function mergeCookieCredentialsPayload(array $parsed, ?string $existingCookieHeader): string
    {
        $base = [
            'cookie' => (string) ($parsed['cookie'] ?? ''),
            'af_ac_enc_dat' => (string) ($parsed['af_ac_enc_dat'] ?? ''),
            'af_ac_enc_sz_token' => (string) ($parsed['af_ac_enc_sz_token'] ?? ''),
            'affiliate_program_type' => (string) ($parsed['affiliate_program_type'] ?? '1'),
            'csrf_token' => (string) ($parsed['csrf_token'] ?? ''),
            'x_sap_ri' => (string) ($parsed['x_sap_ri'] ?? ''),
            'x_sap_sec' => (string) ($parsed['x_sap_sec'] ?? ''),
            'x_sz_sdk_version' => (string) ($parsed['x_sz_sdk_version'] ?? ''),
            'profiles' => [],
        ];

        if ($existingCookieHeader !== null && str_starts_with(trim($existingCookieHeader), '{')) {
            $existing = json_decode($existingCookieHeader, true);
            if (is_array($existing)) {
                // Preserve existing endpoint profiles and fallback values where new cURL omitted headers.
                $base['profiles'] = is_array($existing['profiles'] ?? null) ? $existing['profiles'] : [];

                foreach ([
                    'cookie',
                    'af_ac_enc_dat',
                    'af_ac_enc_sz_token',
                    'affiliate_program_type',
                    'csrf_token',
                    'x_sap_ri',
                    'x_sap_sec',
                    'x_sz_sdk_version',
                ] as $key) {
                    if ($base[$key] === '' && isset($existing[$key])) {
                        $base[$key] = (string) $existing[$key];
                    }
                }
            }
        }

        $endpointKey = (string) ($parsed['endpoint_key'] ?? '');
        if ($endpointKey !== '') {
            $base['profiles'][$endpointKey] = [
                'af_ac_enc_dat' => (string) ($parsed['af_ac_enc_dat'] ?? ''),
                'af_ac_enc_sz_token' => (string) ($parsed['af_ac_enc_sz_token'] ?? ''),
                'affiliate_program_type' => (string) ($parsed['affiliate_program_type'] ?? '1'),
                'csrf_token' => (string) ($parsed['csrf_token'] ?? ''),
                'x_sap_ri' => (string) ($parsed['x_sap_ri'] ?? ''),
                'x_sap_sec' => (string) ($parsed['x_sap_sec'] ?? ''),
                'x_sz_sdk_version' => (string) ($parsed['x_sz_sdk_version'] ?? ''),
                'referer' => (string) ($parsed['referer'] ?? ''),
                'request_url' => (string) ($parsed['request_url'] ?? ''),
            ];
        }

        return json_encode($base, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: json_encode($base);
    }

    /**
     * @return array<string, mixed>
     */
    private function syncCapabilitiesSnapshot(PlatformConnection $connection): array
    {
        if (! IntegrationFactory::supports($connection->platform)) {
            return [];
        }

        if ($connection->method === 'portal_export') {
            $capabilities = [
                'supportsAutoSync' => false,
                'supportsOfferDiscovery' => false,
                'supportsShortLink' => false,
                'supportsSubId' => true,
            ];
        } elseif ($connection->method === 'cookie') {
            $capabilities = [
                'supportsAutoSync' => true,
                'supportsOfferDiscovery' => true,
                'supportsShortLink' => false,
                'supportsSubId' => true,
            ];
        } else {
            $adapter = IntegrationFactory::make($connection->platform);
            $capabilities = $adapter->capabilities()->toArray();
        }

        if ($connection->capabilities !== $capabilities) {
            $connection->update(['capabilities' => $capabilities]);
        }

        return $capabilities;
    }
}
