<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Profile\ApprovePayoutProfileRequest;
use App\Http\Requests\Profile\ListPayoutApprovalsRequest;
use App\Http\Requests\Profile\RejectPayoutProfileRequest;
use App\Services\Profile\PayoutApprovalService;
use Illuminate\Http\JsonResponse;

class PayoutApprovalController extends Controller
{
    public function __construct(
        private readonly PayoutApprovalService $payoutApprovalService,
    ) {}

    public function index(ListPayoutApprovalsRequest $request): JsonResponse
    {
        $payload = $this->payoutApprovalService->queueForReviewer(
            reviewer: $request->user(),
            limit: $request->limit(),
        );

        return ApiResponse::success($payload);
    }

    public function approve(ApprovePayoutProfileRequest $request, int $userId): JsonResponse
    {
        try {
            $payload = $this->payoutApprovalService->approve(
                reviewer: $request->user(),
                targetUserId: $userId,
            );
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        return ApiResponse::success($payload, 'Đã duyệt hồ sơ payout.');
    }

    public function reject(RejectPayoutProfileRequest $request, int $userId): JsonResponse
    {
        try {
            $payload = $this->payoutApprovalService->reject(
                reviewer: $request->user(),
                targetUserId: $userId,
                reason: (string) $request->validated('reason'),
            );
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        return ApiResponse::success($payload, 'Đã từ chối hồ sơ payout.');
    }
}
