<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Offers\GetOfferLinkRequest;
use App\Http\Requests\Offers\SearchOffersRequest;
use App\Models\Campaign;
use App\Services\Integration\IntegrationService;
use App\Services\Offer\OfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OfferController extends Controller
{
    public function __construct(
        private readonly OfferService $offerService,
        private readonly IntegrationService $integrationService,
    ) {}

    /**
     * Render the Offer Discovery page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $campaigns = Campaign::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get(['id', 'name']);

        return Inertia::render('Offers/Index', [
            'connections' => $this->integrationService->listConnectionsForOfferDiscovery($user),
            'campaigns'   => $campaigns,
        ]);
    }

    /**
     * Render offer detail page.
     */
    public function showPage(Request $request, string $offerId): Response
    {
        $validated = $request->validate([
            'connection_id' => ['required', 'integer'],
            'shop_id' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $detail = $this->offerService->getDetail(
            actor: $user,
            validated: [
                'connection_id' => (int) $validated['connection_id'],
                'offer_id' => $offerId,
                'shop_id' => $validated['shop_id'] ?? null,
            ],
        );

        $campaigns = Campaign::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get(['id', 'name']);

        return Inertia::render('Offers/Show', [
            'offer'        => $detail,
            'connectionId' => (int) $validated['connection_id'],
            'connections'  => $this->integrationService->listConnectionsForOfferDiscovery($user),
            'campaigns'    => $campaigns,
        ]);
    }

    /**
     * Search offers via cached server-side proxy.
     * Cache key: offers:{platform}:{connection_id}:{hash(filters)}
     * TTL: config('integrations.shopee.offer_cache_ttl') minutes.
     */
    public function search(SearchOffersRequest $request): JsonResponse
    {
        try {
            $result = $this->offerService->search(
                actor: $request->user(),
                validated: $request->validated(),
            );

            return ApiResponse::success($result);
        } catch (NotFoundHttpException) {
            return ApiResponse::error('Not found.', [], 404);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }
    }

    /**
     * One-click "Get Link" — create a TrackingLink from an offer.
     */
    public function getLink(GetOfferLinkRequest $request): JsonResponse
    {
        try {
            $payload = $this->offerService->getLink(
                actor: $request->user(),
                validated: $request->validated(),
            );

            return ApiResponse::success($payload, 'Tracking link created from offer.');
        } catch (NotFoundHttpException) {
            return ApiResponse::error('Not found.', [], 404);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }
    }

    /**
     * Fetch platform category tree for offer discovery filter UI.
     * Fixes live 500: route /api/offers/categories had no handler.
     */
    public function categories(Request $request): JsonResponse
    {
        $request->validate(['connection_id' => ['required', 'integer']]);

        try {
            $categories = $this->offerService->getCategories(
                actor: $request->user(),
                connectionId: (int) $request->query('connection_id'),
            );

            return ApiResponse::success($categories);
        } catch (NotFoundHttpException) {
            return ApiResponse::error('Not found.', [], 404);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }
    }

    /**
     * Fetch a single offer detail.
     */
    public function show(Request $request, string $offerId): JsonResponse
    {
        $validated = $request->validate([
            'connection_id' => ['required', 'integer'],
            'shop_id' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $result = $this->offerService->getDetail(
                actor: $request->user(),
                validated: [
                    'connection_id' => (int) $validated['connection_id'],
                    'offer_id' => $offerId,
                    'shop_id' => $validated['shop_id'] ?? null,
                ],
            );

            return ApiResponse::success($result);
        } catch (NotFoundHttpException) {
            return ApiResponse::error('Not found.', [], 404);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }
    }
}
