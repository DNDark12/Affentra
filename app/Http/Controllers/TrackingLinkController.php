<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\TrackingLink\CreateTrackingLinkRequest;
use App\Http\Requests\TrackingLink\ListTrackingLinksRequest;
use App\Http\Requests\TrackingLink\UpdateTrackingLinkRequest;
use App\Services\TrackingLink\TrackingLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TrackingLinkController extends Controller
{
    public function __construct(
        private readonly TrackingLinkService $trackingLinkService,
    ) {}

     /**
     * Paginated list page (Inertia).
     */
    public function index(ListTrackingLinksRequest $request): Response
    {
        /** @var \App\Models\User $user */
        $user    = $request->user();
        $filters = $request->validated();

        $links = $this->trackingLinkService->listForUser($user, $filters);
        $summary = $this->trackingLinkService->summarizeForUser($user, $filters);

        $campaigns = \App\Models\Campaign::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get(['id', 'name']);

        $platformConnections = \App\Models\PlatformConnection::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'error'])
            ->orderByDesc('last_sync_at')
            ->orderByDesc('id')
            ->get(['id', 'label', 'platform', 'status']);

        return Inertia::render('TrackingLinks/Index', [
            'links'     => $links,
            'filters'   => $filters,
            'summary'   => $summary,
            'campaigns' => $campaigns,
            'platformConnections' => $platformConnections,
        ]);
    }

    /**
     * JSON: paginated list for AJAX/SPA refresh.
     */
    public function list(ListTrackingLinksRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user    = $request->user();
        $filters = $request->validated();

        $links = $this->trackingLinkService->listForUser($user, $filters);

        return ApiResponse::success($links);
    }

    /**
     * Detail page for a single tracking link.
     */
    public function show(Request $request, int $trackingLink): Response
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $detail = $this->trackingLinkService->detailForUser($user, $trackingLink);

        return Inertia::render('TrackingLinks/Show', [
            'trackingLink' => $detail,
        ]);
    }

    /**
     * JSON detail endpoint.
     */
    public function showJson(Request $request, int $trackingLink): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        try {
            $detail = $this->trackingLinkService->detailForUser($user, $trackingLink);
        } catch (NotFoundHttpException) {
            return ApiResponse::error('Not found.', [], 404);
        }

        return ApiResponse::success($detail);
    }

    /**
     * Create a new tracking link.
     */
    public function store(CreateTrackingLinkRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        try {
            $link = $this->trackingLinkService->create($user, $request->validated());
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }

        $wasCreated = (bool) ($link->wasRecentlyCreated ?? false);
        $message = $wasCreated
            ? 'Tracking link created.'
            : 'Tracking link already exists. Reused existing link.';

        return ApiResponse::success($link, $message, $wasCreated ? 201 : 200);
    }

    /**
     * Update an existing tracking link.
     */
    public function update(UpdateTrackingLinkRequest $request, int $trackingLink): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        try {
            $link = $this->trackingLinkService->updateForUser($user, $trackingLink, $request->validated());
        } catch (NotFoundHttpException) {
            return ApiResponse::error('Not found.', [], 404);
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }

        return ApiResponse::success($link, 'Tracking link updated.');
    }

    /**
     * Archive a tracking link (soft-delete equivalent).
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        $archived = $this->trackingLinkService->archiveForUser($request->user(), $id);

        return response()->json([
            'ok' => true,
            'message' => 'Tracking link archived.',
            'data' => [
                'id' => $archived->id,
                'status' => $archived->status->value,
            ],
        ]);
    }

    public function refreshProduct(Request $request, int $id): JsonResponse
    {
        try {
            $updated = $this->trackingLinkService->refreshProductInfo($request->user(), $id);

            return response()->json([
                'ok' => true,
                'message' => 'Product information refreshed successfully.',
                'data' => [
                    'id' => $updated->id,
                    'product_name' => $updated->product_name,
                    'product_price' => $updated->product_price,
                    'product_scrape_confidence' => $updated->product_scrape_confidence,
                    'product_scrape_source' => $updated->product_scrape_source,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Failed to refresh product information: ' . $e->getMessage(),
            ], 422);
        }
    }
}
