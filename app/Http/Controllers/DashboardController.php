<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Dashboard\DashboardSummaryRequest;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
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
    public function index(DashboardSummaryRequest $request): Response
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $summary = $this->dashboardService->getSummary($user, $request->period());

        return Inertia::render('Dashboard/Index', ['summary' => $summary]);
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
