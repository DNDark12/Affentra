<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Dashboard\DashboardSummaryRequest;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    /**
     * Render the dashboard Inertia page.
     */
    public function index(Request $request): Response
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $summary = $this->dashboardService->getSummary($user, '30days');

        return Inertia::render('Dashboard/Index', [
            'summary' => [
                'period'     => $summary['period'],
                'clicks'     => $summary['clicks'],
                'orders'     => $summary['orders'],
                'approved'   => $summary['approved'],
                'commission' => $summary['commission'],
                'daily'      => $summary['daily'],
            ],
        ]);
    }

    /**
     * JSON API: dashboard summary (for AJAX refresh).
     */
    public function summary(DashboardSummaryRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $period = $request->period();

        $summary = $this->dashboardService->getSummary($user, (string) $period);

        return ApiResponse::success($summary);
    }
}
