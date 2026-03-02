<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Finance\FinanceIndexRequest;
use App\Http\Requests\Finance\FinanceSyncRequest;
use App\Services\Finance\FinanceService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    public function __construct(
        private readonly FinanceService $financeService,
    ) {}

    /**
     * Display the finance overview.
     */
    public function index(FinanceIndexRequest $request): Response
    {
        $payload = $this->financeService->indexData(
            user: $request->user(),
            filters: $request->filters(),
        );

        return Inertia::render('Finance/Index', [
            'billings' => $payload['billings'],
            'payouts' => $payload['payouts'],
            'summary' => $payload['summary'],
            'filters' => $payload['filters'],
            'sync' => $payload['sync'],
        ]);
    }

    /**
     * Trigger a manual sync for payment data.
     */
    public function sync(FinanceSyncRequest $request): JsonResponse
    {
        $result = $this->financeService->triggerManualSync($request->user());

        if ($result['ok'] === false) {
            $statusCode = match ($result['status']) {
                'already_running' => 409,
                'no_connection' => 422,
                default => 422,
            };

            return ApiResponse::error(
                message: $result['message'],
                errors: ['status' => [$result['status']]],
                status: $statusCode,
            );
        }

        return ApiResponse::success($result, $result['message']);
    }
}
