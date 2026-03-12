<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AffiliateBilling;
use App\Models\AffiliatePayout;
use App\Models\Order;
use App\Models\User;
use App\Http\Resources\PartnerSummaryResource;
use App\Http\Resources\PartnerTrendResource;
use App\Http\Resources\PartnerConnectionResource;
use App\Http\Resources\PartnerTrackingLinkResource;
use App\Services\Partner\PartnerAnalyticsService;
use App\Services\Partner\PartnerConnectionService;
use App\Services\Scope\ScopeResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PartnerDetailApiController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scopeResolver,
        private readonly PartnerAnalyticsService $analyticsService,
        private readonly PartnerConnectionService $connectionService,
    ) {}

    private function authorizePartnerAccess(User $user, User $partner): void
    {
        if ($user->role === UserRole::Partner) {
            abort(403, 'Unauthorized.');
        }

        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($user);
        
        if ($scopeUserIds !== null && !in_array($partner->id, $scopeUserIds, true)) {
            throw new NotFoundHttpException('Not found.');
        }

        if ($partner->role !== UserRole::Partner) {
            throw new NotFoundHttpException('Not found.');
        }
    }

    public function summary(Request $request, User $partner): PartnerSummaryResource
    {
        $this->authorizePartnerAccess($request->user(), $partner);
        
        $days = $request->integer('days');
        $days = $days > 0 ? $days : null; // If 0 or missing, let service handle default/null
        
        $platform = $request->string('platform')->value();
        $platform = $platform === '' ? null : $platform;

        $data = $this->analyticsService->summary($partner, $days, $platform);
        return new PartnerSummaryResource($data);
    }

    public function trend(Request $request, User $partner): AnonymousResourceCollection
    {
        $this->authorizePartnerAccess($request->user(), $partner);

        $days = $request->integer('days');
        $days = $days > 0 ? $days : null;
        
        $platform = $request->string('platform')->value();
        $platform = $platform === '' ? null : $platform;

        $platformConnectionId = $request->integer('platform_connection_id');
        $platformConnectionId = $platformConnectionId > 0 ? $platformConnectionId : null;

        $data = $this->analyticsService->trend($partner, $days, $platform, $platformConnectionId);
        return PartnerTrendResource::collection($data);
    }

    public function connections(Request $request, User $partner): AnonymousResourceCollection
    {
        $this->authorizePartnerAccess($request->user(), $partner);

        $data = $this->connectionService->connections($partner);
        return PartnerConnectionResource::collection($data);
    }

    public function trackingLinks(Request $request, User $partner): AnonymousResourceCollection
    {
        $this->authorizePartnerAccess($request->user(), $partner);

        $perPage = $request->integer('per_page', 15);
        $platform = $request->string('platform')->value();
        $platform = $platform === '' ? null : $platform;

        $data = $this->analyticsService->topTrackingLinks($partner, $perPage, $platform);
        return PartnerTrackingLinkResource::collection($data);
    }

    public function orders(Request $request, User $partner): \Illuminate\Http\JsonResponse
    {
        $this->authorizePartnerAccess($request->user(), $partner);

        $query = Order::query()
            ->where('user_id', $partner->id)
            ->latest('ordered_at');

        if ($request->filled('date_from')) {
            $query->whereDate('ordered_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('ordered_at', '<=', $request->date('date_to'));
        }

        $paginator = $query->paginate($request->integer('per_page', 10));

        // Format directly in controller for simplicity in phase 12.2, 
        // normally use an API resource but this matches existing PartnerService output format
        $paginator->getCollection()->transform(function (Order $order) {
            return [
                'id' => (int) $order->id,
                'order_code' => (string) $order->order_code,
                'status' => (string) $order->status->value,
                'payout_status' => (string) $order->payout_status,
                'order_amount' => (float) $order->order_amount,
                'commission' => (float) $order->commission,
                'ordered_at' => $order->ordered_at?->toIso8601String(),
                'approved_at' => $order->approved_at?->toIso8601String(),
                'paid_at' => $order->paid_at?->toIso8601String(),
                'tracking_link_id' => $order->tracking_link_id,
            ];
        });

        return response()->json($paginator);
    }

    public function billings(Request $request, User $partner): \Illuminate\Http\JsonResponse
    {
        $this->authorizePartnerAccess($request->user(), $partner);

        $query = AffiliateBilling::query()
            ->where('user_id', $partner->id)
            ->latest('period_end');

        if ($request->filled('date_from')) {
            $query->whereDate('period_start', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('period_end', '<=', $request->date('date_to'));
        }

        $paginator = $query->paginate($request->integer('per_page', 10));

        $paginator->getCollection()->transform(function (AffiliateBilling $billing) {
            return [
                'id' => (int) $billing->id,
                'billing_id' => (string) $billing->billing_id,
                'status' => (string) $billing->status,
                'period_start' => $billing->period_start?->toIso8601String(),
                'period_end' => $billing->period_end?->toIso8601String(),
                'net_amount' => (float) $billing->net_amount,
            ];
        });

        return response()->json($paginator);
    }

    public function payouts(Request $request, User $partner): \Illuminate\Http\JsonResponse
    {
        $this->authorizePartnerAccess($request->user(), $partner);

        $query = AffiliatePayout::query()
            ->where('user_id', $partner->id)
            ->latest('payout_at');

        if ($request->filled('date_from')) {
            $query->whereDate('payout_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('payout_at', '<=', $request->date('date_to'));
        }

        $paginator = $query->paginate($request->integer('per_page', 10));

        $paginator->getCollection()->transform(function (AffiliatePayout $payout) {
            return [
                'id' => (int) $payout->id,
                'payout_id' => (string) $payout->payout_id,
                'status' => (string) $payout->status,
                'amount' => (float) $payout->amount,
                'payout_at' => $payout->payout_at?->toIso8601String(),
                'bank_name' => $payout->bank_name,
                'account_number_masked' => $payout->account_number_masked,
            ];
        });

        return response()->json($paginator);
    }
}
