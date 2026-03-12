<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PayoutBatchStatus;
use App\Helpers\ApiResponse;
use App\Http\Requests\Finance\StorePayoutBatchRequest;
use App\Models\AffiliatePayout;
use App\Models\PayoutBatch;
use App\Services\Finance\PayoutBatchService;
use App\Services\Finance\PayoutExportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PayoutBatchController extends Controller
{
    public function __construct(
        private readonly PayoutBatchService $payoutBatchService,
        private readonly PayoutExportService $payoutExportService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = array_filter([
            'status'   => $request->query('status'),
            'batch_no' => $request->query('batch_no'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $batches = $this->payoutBatchService
            ->list($request->user(), $filters, 20)
            ->through(static function (PayoutBatch $batch): array {
                return [
                    'id'           => (int) $batch->id,
                    'batch_no'     => (string) $batch->batch_no,
                    'status'       => $batch->status instanceof PayoutBatchStatus ? $batch->status->value : (string) $batch->status,
                    'total_amount' => (float) $batch->total_amount,
                    'payout_count' => (int) $batch->payout_count,
                    'note'         => $batch->note,
                    'created_at'   => $batch->created_at?->toIso8601String(),
                    'finalized_at' => $batch->finalized_at?->toIso8601String(),
                    'creator'      => $batch->creator ? [
                        'id'   => (int) $batch->creator->id,
                        'name' => (string) $batch->creator->name,
                    ] : null,
                    'finalizer'    => $batch->finalizer ? [
                        'id'   => (int) $batch->finalizer->id,
                        'name' => (string) $batch->finalizer->name,
                    ] : null,
                ];
            });

        $unbatchedPayouts = $this->payoutBatchService
            ->listUnbatchedPayouts($request->user(), 50)
            ->map(static function ($payout): array {
                return [
                    'id'        => (int) $payout->id,
                    'payout_id' => (string) $payout->payout_id,
                    'status'    => (string) $payout->status,
                    'amount'    => (float) $payout->amount,
                    'payout_at' => $payout->payout_at?->toIso8601String(),
                    'user'      => $payout->user ? [
                        'id'    => (int) $payout->user->id,
                        'name'  => (string) $payout->user->name,
                        'email' => (string) $payout->user->email,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Finance/PayoutBatches/Index', [
            'batches'          => $batches,
            'filters'          => $filters,
            'unbatchedPayouts' => $unbatchedPayouts,
        ]);
    }

    public function show(Request $request, PayoutBatch $payoutBatch): Response
    {
        $this->authorize('view', $payoutBatch);

        $payload = $this->payoutBatchService->detail($request->user(), $payoutBatch);
        /** @var PayoutBatch $batch */
        $batch = $payload['batch'];

        return Inertia::render('Finance/PayoutBatches/Show', [
            'batch'   => [
                'id'           => (int) $batch->id,
                'batch_no'     => (string) $batch->batch_no,
                'status'       => $batch->status instanceof PayoutBatchStatus ? $batch->status->value : (string) $batch->status,
                'total_amount' => (float) $batch->total_amount,
                'payout_count' => (int) $batch->payout_count,
                'note'         => $batch->note,
                'created_at'   => $batch->created_at?->toIso8601String(),
                'finalized_at' => $batch->finalized_at?->toIso8601String(),
                'exported_at'  => $batch->exported_at?->toIso8601String(),
                'creator'      => $batch->creator ? [
                    'id'    => (int) $batch->creator->id,
                    'name'  => (string) $batch->creator->name,
                    'email' => (string) $batch->creator->email,
                ] : null,
                'finalizer'    => $batch->finalizer ? [
                    'id'    => (int) $batch->finalizer->id,
                    'name'  => (string) $batch->finalizer->name,
                    'email' => (string) $batch->finalizer->email,
                ] : null,
            ],
            'payouts' => $payload['payouts']->map(static function ($payout): array {
                return [
                    'id'                    => (int) $payout->id,
                    'payout_id'             => (string) $payout->payout_id,
                    'status'                => (string) $payout->status,
                    'amount'                => (float) $payout->amount,
                    'currency'              => (string) $payout->currency,
                    'payout_at'             => $payout->payout_at?->toIso8601String(),
                    'bank_name'             => $payout->bank_name,
                    'account_number_masked' => $payout->account_number_masked,
                    'user'                  => $payout->user ? [
                        'id'    => (int) $payout->user->id,
                        'name'  => (string) $payout->user->name,
                        'email' => (string) $payout->user->email,
                    ] : null,
                ];
            })->values()->all(),
        ]);
    }

    /**
     * JSON endpoint for API clients — policy-gated, returns batch data without Inertia.
     */
    public function showJson(Request $request, PayoutBatch $payoutBatch): JsonResponse
    {
        $this->authorize('view', $payoutBatch);

        try {
            $payload = $this->payoutBatchService->detail($request->user(), $payoutBatch);
            /** @var PayoutBatch $batch */
            $batch = $payload['batch'];

            return ApiResponse::success([
                'id'           => (int) $batch->id,
                'batch_no'     => (string) $batch->batch_no,
                'status'       => $batch->status instanceof PayoutBatchStatus ? $batch->status->value : (string) $batch->status,
                'total_amount' => (float) $batch->total_amount,
                'payout_count' => (int) $batch->payout_count,
                'note'         => $batch->note,
                'created_at'   => $batch->created_at?->toIso8601String(),
                'finalized_at' => $batch->finalized_at?->toIso8601String(),
                'exported_at'  => $batch->exported_at?->toIso8601String(),
                'creator'      => $batch->creator ? ['id' => (int) $batch->creator->id, 'name' => (string) $batch->creator->name] : null,
                'finalizer'    => $batch->finalizer ? ['id' => (int) $batch->finalizer->id, 'name' => (string) $batch->finalizer->name] : null,
            ]);
        } catch (NotFoundHttpException) {
            return ApiResponse::notFound('Not found.');
        } catch (AuthorizationException) {
            return ApiResponse::forbidden('Forbidden');
        }
    }

    public function store(StorePayoutBatchRequest $request): JsonResponse
    {
        try {
            $batch = $this->payoutBatchService->create(
                actor: $request->user(),
                payoutIds: (array) $request->validated('payout_ids'),
                note: $request->validated('note'),
            );

            return ApiResponse::created([
                'id'           => (int) $batch->id,
                'batch_no'     => (string) $batch->batch_no,
                'status'       => $batch->status instanceof PayoutBatchStatus ? $batch->status->value : (string) $batch->status,
                'total_amount' => (float) $batch->total_amount,
                'payout_count' => (int) $batch->payout_count,
            ], 'Đã tạo payout batch.');
        } catch (NotFoundHttpException) {
            return ApiResponse::notFound('Not found.');
        } catch (ConflictHttpException $e) {
            return ApiResponse::error($e->getMessage(), ['status' => ['conflict']], 409);
        } catch (AuthorizationException) {
            return ApiResponse::forbidden('Forbidden');
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }
    }

    public function finalize(Request $request, PayoutBatch $payoutBatch): JsonResponse
    {
        $this->authorize('finalize', $payoutBatch);

        try {
            $batch = $this->payoutBatchService->finalize($request->user(), $payoutBatch);

            return ApiResponse::success([
                'id'           => (int) $batch->id,
                'batch_no'     => (string) $batch->batch_no,
                'status'       => $batch->status instanceof PayoutBatchStatus ? $batch->status->value : (string) $batch->status,
                'finalized_at' => $batch->finalized_at?->toIso8601String(),
                'finalized_by' => $batch->finalized_by,
            ], 'Đã chốt payout batch.');
        } catch (NotFoundHttpException) {
            return ApiResponse::notFound('Not found.');
        } catch (ConflictHttpException $e) {
            return ApiResponse::error($e->getMessage(), ['status' => ['conflict']], 409);
        } catch (AuthorizationException) {
            return ApiResponse::forbidden('Forbidden');
        }
    }

    /**
     * Export the batch as a CSV file and transition it to `exported` status.
     */
    public function export(Request $request, PayoutBatch $payoutBatch): StreamedResponse|JsonResponse
    {
        $this->authorize('export', $payoutBatch);

        $format = (string) $request->query('format', 'generic');
        if (! in_array($format, PayoutExportService::FORMATS, true)) {
            return ApiResponse::error('Định dạng xuất không hợp lệ. Chọn: generic, vietcombank, techcombank.', [], 422);
        }

        try {
            // Mark as exported first (audit + status transition)
            $this->payoutBatchService->markExported($request->user(), $payoutBatch);

            return $this->payoutExportService->generateCsv($payoutBatch, $format, $request->user());
        } catch (ConflictHttpException $e) {
            return ApiResponse::error($e->getMessage(), [], 409);
        } catch (AuthorizationException) {
            return ApiResponse::forbidden('Forbidden');
        }
    }

    /**
     * Delete a draft batch and release its payouts.
     */
    public function destroy(Request $request, PayoutBatch $payoutBatch): JsonResponse
    {
        $this->authorize('delete', $payoutBatch);

        try {
            $this->payoutBatchService->destroy($request->user(), $payoutBatch);

            return ApiResponse::success(null, 'Đã xóa payout batch.');
        } catch (ConflictHttpException $e) {
            return ApiResponse::error($e->getMessage(), [], 409);
        } catch (NotFoundHttpException) {
            return ApiResponse::notFound('Not found.');
        } catch (AuthorizationException) {
            return ApiResponse::forbidden('Forbidden');
        }
    }

    /**
     * Remove a specific payout from a draft batch.
     */
    public function removePayout(Request $request, PayoutBatch $payoutBatch, AffiliatePayout $payout): JsonResponse
    {
        $this->authorize('update', $payoutBatch);

        try {
            $batch = $this->payoutBatchService->removePayout($request->user(), $payoutBatch, $payout);

            return ApiResponse::success([
                'id'           => (int) $batch->id,
                'total_amount' => (float) $batch->total_amount,
                'payout_count' => (int) $batch->payout_count,
            ], 'Đã xóa payout khỏi batch.');
        } catch (ConflictHttpException $e) {
            return ApiResponse::error($e->getMessage(), [], 409);
        } catch (NotFoundHttpException) {
            return ApiResponse::notFound('Not found.');
        } catch (AuthorizationException) {
            return ApiResponse::forbidden('Forbidden');
        }
    }
}
