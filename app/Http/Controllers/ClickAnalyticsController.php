<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\Clicks\ClickReportFilter;
use App\Helpers\ApiResponse;
use App\Http\Requests\Clicks\ClickReportFilterRequest;
use App\Http\Resources\Clicks\ClickConversionCollection;
use App\Http\Resources\Clicks\ClickReportCollection;
use App\Http\Resources\Clicks\ClickSummaryResource;
use App\Services\Clicks\ClickAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClickAnalyticsController extends Controller
{
    public function __construct(
        private readonly ClickAnalyticsService $analyticsService
    ) {}

    public function index(Request $request): Response
    {
        $dto = ClickReportFilter::fromRequest($request->all());
        $summary = $this->analyticsService->getSummary($dto, $request->user());

        $campaignIds = \App\Models\DailyStat::query()
            ->when($request->user()->isLeader(), fn($q) => $q->where('leader_id', $request->user()->id))
            ->when($request->user()->isPartner(), fn($q) => $q->where('partner_user_id', $request->user()->id))
            ->distinct()
            ->pluck('campaign_id');
            
        $campaigns = \App\Models\Campaign::whereIn('id', $campaignIds)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('Clicks/Overview', [
            'summary' => (new ClickSummaryResource($summary))->resolve($request),
            'filters' => $request->all(),
            'filterOptions' => [
                'campaigns' => $campaigns,
            ]
        ]);
    }

    public function report(Request $request): Response
    {
        $dto = ClickReportFilter::fromRequest($request->all());
        $reportData = $this->analyticsService->getReportData($dto, $request->user());

        $sources = DB::table('clicks')
            ->whereNotNull('utm_source')
            ->select('utm_source')
            ->distinct()
            ->limit(100)
            ->pluck('utm_source');

        $campaigns = DB::table('clicks')
            ->whereNotNull('utm_campaign')
            ->select('utm_campaign')
            ->distinct()
            ->limit(100)
            ->pluck('utm_campaign');

        return Inertia::render('Clicks/Report', [
            'report' => (new ClickReportCollection($reportData))->resolve($request),
            'filters' => $request->all(),
            'filterOptions' => [
                'utm_sources' => $sources,
                'utm_campaigns' => $campaigns,
                'devices' => ['desktop', 'mobile', 'tablet'],
                'group_bys' => ClickReportFilter::getAllowedGroupBys(),
            ]
        ]);
    }

    public function conversion(Request $request): Response
    {
        $dto = ClickReportFilter::fromRequest($request->all());
        $conversionData = $this->analyticsService->getConversionData($dto, $request->user());

        $sources = DB::table('clicks')
            ->whereNotNull('utm_source')
            ->select('utm_source')
            ->distinct()
            ->limit(100)
            ->pluck('utm_source');

        $campaigns = DB::table('clicks')
            ->whereNotNull('utm_campaign')
            ->select('utm_campaign')
            ->distinct()
            ->limit(100)
            ->pluck('utm_campaign');

        return Inertia::render('Clicks/Conversion', [
            'conversion' => (new ClickConversionCollection($conversionData))->resolve($request),
            'filters' => $request->all(),
            'filterOptions' => [
                'utm_sources' => $sources,
                'utm_campaigns' => $campaigns,
                'devices' => ['desktop', 'mobile', 'tablet'],
                'group_bys' => ClickReportFilter::getAllowedGroupBys(),
            ]
        ]);
    }

    public function summary(ClickReportFilterRequest $request): JsonResponse
    {
        $dto = ClickReportFilter::fromRequest($request->validated());
        $data = $this->analyticsService->getSummary($dto, $request->user());

        return ApiResponse::success((new ClickSummaryResource($data))->resolve($request));
    }

    public function reportData(ClickReportFilterRequest $request): JsonResponse
    {
        $dto = ClickReportFilter::fromRequest($request->validated());
        $data = $this->analyticsService->getReportData($dto, $request->user());

        return ApiResponse::success((new ClickReportCollection($data))->resolve($request));
    }

    public function conversionData(ClickReportFilterRequest $request): JsonResponse
    {
        $dto = ClickReportFilter::fromRequest($request->validated());
        $data = $this->analyticsService->getConversionData($dto, $request->user());

        return ApiResponse::success((new ClickConversionCollection($data))->resolve($request));
    }

    public function exportData(ClickReportFilterRequest $request): JsonResponse
    {
        return ApiResponse::success([]);
    }
}
