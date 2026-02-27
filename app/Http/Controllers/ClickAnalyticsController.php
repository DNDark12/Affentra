<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\Clicks\ClickReportFilter;
use App\Http\Requests\Clicks\ClickReportFilterRequest;
use App\Services\Clicks\ClickAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return Inertia::render('Clicks/Overview', [
            'summary' => $summary,
            'filters' => $request->all()
        ]);
    }

    public function report(Request $request): Response
    {
        $dto = ClickReportFilter::fromRequest($request->all());
        $reportData = $this->analyticsService->getReportData($dto, $request->user());

        return Inertia::render('Clicks/Report', [
            'report' => $reportData,
            'filters' => $request->all()
        ]);
    }

    public function conversion(Request $request): Response
    {
        $dto = ClickReportFilter::fromRequest($request->all());
        $conversionData = $this->analyticsService->getConversionData($dto, $request->user());

        return Inertia::render('Clicks/Conversion', [
            'conversion' => $conversionData,
            'filters' => $request->all()
        ]);
    }

    public function summary(ClickReportFilterRequest $request): JsonResponse
    {
        $dto = ClickReportFilter::fromRequest($request->validated());
        $data = $this->analyticsService->getSummary($dto, $request->user());

        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function reportData(ClickReportFilterRequest $request): JsonResponse
    {
        $dto = ClickReportFilter::fromRequest($request->validated());
        $data = $this->analyticsService->getReportData($dto, $request->user());

        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function conversionData(ClickReportFilterRequest $request): JsonResponse
    {
        $dto = ClickReportFilter::fromRequest($request->validated());
        $data = $this->analyticsService->getConversionData($dto, $request->user());

        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function exportData(ClickReportFilterRequest $request): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => []]);
    }
}
