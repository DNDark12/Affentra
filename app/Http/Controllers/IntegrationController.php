<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Integrations\StoreConnectionRequest;
use App\Http\Requests\Integrations\UpdateConnectionRequest;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Integration\IntegrationFactory;
use App\Services\Integration\IntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    public function __construct(
        private readonly IntegrationService $integrationService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $auth */
        $auth = $request->user();

        // Only Owner and Leader can configure Integrations
        if (! $this->integrationService->canManageConnections($auth)) {
            abort(403, 'Unauthorized action.');
        }

        return Inertia::render('Integrations/Index', [
            'connections' => $this->integrationService->listConnectionsForIndex($auth),
            'supportedPlatforms' => IntegrationFactory::supportedPlatforms(),
            'allowedMethods' => $this->integrationService->availableMethodsForUser($auth),
        ]);
    }

    public function show(Request $request, PlatformConnection $connection): Response
    {
        /** @var User $auth */
        $auth = $request->user();

        if (! $this->integrationService->canManageConnections($auth)) {
            abort(403, 'Unauthorized action.');
        }

        if (! $this->integrationService->isOwnedBy($auth, $connection)) {
            abort(404, 'Not found.');
        }

        $connectionPayload = collect($this->integrationService->listConnectionsForIndex($auth))
            ->first(static fn (array $item): bool => (int) ($item['id'] ?? 0) === (int) $connection->id);

        if (! is_array($connectionPayload)) {
            abort(404, 'Not found.');
        }

        return Inertia::render('Integrations/Detail', [
            'connections' => [$connectionPayload],
            'supportedPlatforms' => IntegrationFactory::supportedPlatforms(),
            'allowedMethods' => $this->integrationService->availableMethodsForUser($auth),
        ]);
    }

    public function store(StoreConnectionRequest $request): JsonResponse
    {
        /** @var User $auth */
        $auth = $request->user();

        if (! $this->integrationService->canManageConnections($auth)) {
            return ApiResponse::error('Unauthorized action.', [], 403);
        }

        try {
            $connection = $this->integrationService->createConnection($auth, $request->validated());
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }

        return ApiResponse::success([
            'id'       => $connection->id,
            'platform' => $connection->platform,
        ], 'Connection created.');
    }

    public function update(UpdateConnectionRequest $request, PlatformConnection $connection): JsonResponse
    {
        // Must belong to user
        if (! $this->integrationService->isOwnedBy($request->user(), $connection)) {
            return ApiResponse::error('Not found.', [], 404);
        }

        try {
            $this->integrationService->updateConnection($request->user(), $connection, $request->validated());
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }

        return ApiResponse::success(null, 'Connection updated.');
    }

    public function destroy(Request $request, PlatformConnection $connection): JsonResponse
    {
        if (! $this->integrationService->isOwnedBy($request->user(), $connection)) {
            return ApiResponse::error('Not found.', [], 404);
        }

        $this->integrationService->removeConnection($connection, $request->user());

        return ApiResponse::success(null, 'Connection removed.');
    }

    public function syncHistory(Request $request, PlatformConnection $connection): JsonResponse
    {
        if (! $this->integrationService->isOwnedBy($request->user(), $connection)) {
            return ApiResponse::error('Not found.', [], 404);
        }

        $limit = (int) $request->integer('limit', 10);
        $limit = max(1, min($limit, 100));

        return ApiResponse::success($this->integrationService->getSyncHistory($connection, $limit));
    }

    /**
     * Trigger an immediate sync for a connection (manual).
     */
    public function syncNow(Request $request, PlatformConnection $connection): JsonResponse
    {
        if (! $this->integrationService->canSyncConnection($request->user(), $connection)) {
            return ApiResponse::error('Unauthorized', [], 403);
        }

        if (! $this->integrationService->canSyncNow($connection)) {
            return ApiResponse::error('Connection must be active to sync.', [], 422);
        }

        if (! $this->integrationService->supportsSync($connection)) {
            return ApiResponse::error('Platform not supported for API sync.', [], 422);
        }

        $syncRun = $this->integrationService->dispatchManualSync($request->user(), $connection);

        return ApiResponse::success([
            'sync_run_id' => $syncRun->id,
            'status' => $syncRun->status,
        ], 'Sync queued. Tracking status in sync history.');
    }

    /**
     * Test connection credentials (validate API key/secret).
     */
    public function testConnection(Request $request, PlatformConnection $connection): JsonResponse
    {
        if (! $this->integrationService->isOwnedBy($request->user(), $connection)) {
            return ApiResponse::error('Not found.', [], 404);
        }

        try {
            $result = $this->integrationService->testConnection($connection);

            if ((bool) ($result['valid'] ?? false) === true) {
                $checks = is_array($result['checks'] ?? null) ? $result['checks'] : [];
                $hasFailedChecks = collect($checks)->contains(
                    static fn (mixed $check): bool => is_array($check) && (($check['ok'] ?? null) === false)
                );

                $payload = [
                    'status' => 'active',
                    'last_sync_status' => 'completed',
                    'cookie_validated_at' => $connection->method === 'cookie' ? now() : $connection->cookie_validated_at,
                ];

                if (! $hasFailedChecks) {
                    $payload['last_error'] = null;
                    $payload['last_error_at'] = null;
                }

                $connection->update($payload);
            }

            $this->auditLogger->log(
                actor: $request->user(),
                action: 'integration.connection.test',
                target: $connection,
                previousState: null,
                newState: [
                    'valid' => (bool) ($result['valid'] ?? false),
                    'message' => (string) ($result['message'] ?? ''),
                    'checks' => $result['checks'] ?? [],
                ],
            );

            return ApiResponse::success([
                'valid' => (bool) ($result['valid'] ?? false),
                'checks' => $result['checks'] ?? [],
            ], (string) ($result['message'] ?? 'Connection test completed.'));
        } catch (\Throwable $e) {
            Log::warning('Connection test failed', [
                'connection_id' => $connection->id,
                'platform' => $connection->platform,
                'method' => $connection->method,
                'error_class' => $e::class,
            ]);

            return ApiResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : 'Connection test failed.',
                [],
                422
            );
        }
    }
}
