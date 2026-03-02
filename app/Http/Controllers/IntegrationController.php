<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Integrations\StoreConnectionRequest;
use App\Http\Requests\Integrations\UpdateConnectionRequest;
use App\Models\PlatformConnection;
use App\Models\User;
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
        private readonly IntegrationService $integrationService
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

        $this->integrationService->removeConnection($connection);

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

        $this->integrationService->dispatchManualSync($request->user(), $connection);

        return ApiResponse::success(null, 'Sync triggered. Check status in sync history.');
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
