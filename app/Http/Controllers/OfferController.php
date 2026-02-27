<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Offers\GetOfferLinkRequest;
use App\Http\Requests\Offers\SearchOffersRequest;
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
        return Inertia::render('Offers/Index', [
            'connections' => $this->integrationService->listConnectionsForOfferDiscovery($request->user()),
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
}
