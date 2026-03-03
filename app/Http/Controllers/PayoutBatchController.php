<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\PayoutBatch;
use App\Services\Finance\PayoutBatchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PayoutBatchController extends Controller
{
    public function __construct(
        private readonly PayoutBatchService $payoutBatchService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = array_filter([
            'status' => $request->query('status'),
            'batch_no' => $request->query('batch_no'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $batches = $this->payoutBatchService
            ->list($request->user(), $filters, 20)
            ->through(static function (PayoutBatch $batch): array {
                return [
                    'id' => (int) $batch->id,
                    'batch_no' => (string) $batch->batch_no,
                    'status' => (string) $batch->status,
                    'total_amount' => (float) $batch->total_amount,
                    'payout_count' => (int) $batch->payout_count,
                    'note' => $batch->note,
                    'created_at' => $batch->created_at?->toIso8601String(),
                    'finalized_at' => $batch->finalized_at?->toIso8601String(),
                    'creator' => $batch->creator ? [
                        'id' => (int) $batch->creator->id,
                        'name' => (string) $batch->creator->name,
                    ] : null,
                    'finalizer' => $batch->finalizer ? [
                        'id' => (int) $batch->finalizer->id,
                        'name' => (string) $batch->finalizer->name,
                    ] : null,
                ];
            });

        $unbatchedPayouts = $this->payoutBatchService
            ->listUnbatchedPayouts($request->user(), 50)
            ->map(static function ($payout): array {
                return [
                    'id' => (int) $payout->id,
                    'payout_id' => (string) $payout->payout_id,
                    'status' => (string) $payout->status,
                    'amount' => (float) $payout->amount,
                    'payout_at' => $payout->payout_at?->toIso8601String(),
                    'user' => $payout->user ? [
                        'id' => (int) $payout->user->id,
                        'name' => (string) $payout->user->name,
                        'email' => (string) $payout->user->email,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Finance/PayoutBatches/Index', [
            'batches' => $batches,
            'filters' => $filters,
            'unbatchedPayouts' => $unbatchedPayouts,
        ]);
    }

    public function show(Request $request, PayoutBatch $payoutBatch): Response
    {
        $payload = $this->payoutBatchService->detail($request->user(), $payoutBatch);
        /** @var PayoutBatch $batch */
        $batch = $payload['batch'];

        return Inertia::render('Finance/PayoutBatches/Show', [
            'batch' => [
                'id' => (int) $batch->id,
                'batch_no' => (string) $batch->batch_no,
                'status' => (string) $batch->status,
                'total_amount' => (float) $batch->total_amount,
                'payout_count' => (int) $batch->payout_count,
                'note' => $batch->note,
                'created_at' => $batch->created_at?->toIso8601String(),
                'finalized_at' => $batch->finalized_at?->toIso8601String(),
                'creator' => $batch->creator ? [
                    'id' => (int) $batch->creator->id,
                    'name' => (string) $batch->creator->name,
                    'email' => (string) $batch->creator->email,
                ] : null,
                'finalizer' => $batch->finalizer ? [
                    'id' => (int) $batch->finalizer->id,
                    'name' => (string) $batch->finalizer->name,
                    'email' => (string) $batch->finalizer->email,
                ] : null,
            ],
            'payouts' => $payload['payouts']->map(static function ($payout): array {
                return [
                    'id' => (int) $payout->id,
                    'payout_id' => (string) $payout->payout_id,
                    'status' => (string) $payout->status,
                    'amount' => (float) $payout->amount,
                    'currency' => (string) $payout->currency,
                    'payout_at' => $payout->payout_at?->toIso8601String(),
                    'bank_name' => $payout->bank_name,
                    'account_number_masked' => $payout->account_number_masked,
                    'user' => $payout->user ? [
                        'id' => (int) $payout->user->id,
                        'name' => (string) $payout->user->name,
                        'email' => (string) $payout->user->email,
                    ] : null,
                ];
            })->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payout_ids' => ['required', 'array', 'min:1'],
            'payout_ids.*' => ['integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $batch = $this->payoutBatchService->create(
                actor: $request->user(),
                payoutIds: $validated['payout_ids'],
                note: $validated['note'] ?? null,
            );

            return ApiResponse::created([
                'id' => (int) $batch->id,
                'batch_no' => (string) $batch->batch_no,
                'status' => (string) $batch->status,
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
        try {
            $batch = $this->payoutBatchService->finalize($request->user(), $payoutBatch);

            return ApiResponse::success([
                'id' => (int) $batch->id,
                'batch_no' => (string) $batch->batch_no,
                'status' => (string) $batch->status,
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
}
