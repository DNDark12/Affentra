<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\AffiliatePayout;
use App\Models\PayoutBatch;
use App\Models\User;
use App\Services\Scope\ScopeResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PayoutBatchService
{
    public function __construct(
        private readonly ScopeResolver $scopeResolver,
    ) {}

    /**
     * @param  list<int>  $payoutIds
     */
    public function create(User $actor, array $payoutIds, ?string $note = null): PayoutBatch
    {
        $this->assertCanManage($actor);

        $normalizedIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $payoutIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($normalizedIds === []) {
            throw new \DomainException('Vui lòng chọn ít nhất một payout.');
        }

        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($actor);

        return DB::transaction(function () use ($actor, $normalizedIds, $scopeUserIds, $note): PayoutBatch {
            $payoutQuery = AffiliatePayout::query()
                ->whereIn('id', $normalizedIds)
                ->lockForUpdate();

            if ($scopeUserIds !== null) {
                $payoutQuery->whereIn('user_id', $scopeUserIds);
            }

            $payouts = $payoutQuery->get();
            if ($payouts->count() !== count($normalizedIds)) {
                throw new NotFoundHttpException('Not found.');
            }

            if ($payouts->contains(static fn (AffiliatePayout $payout): bool => $payout->payout_batch_id !== null)) {
                throw new ConflictHttpException('Một hoặc nhiều payout đã thuộc batch khác.');
            }

            $batch = $this->createBatchWithUniqueNumber($actor, $payouts, $note);

            AffiliatePayout::query()
                ->whereIn('id', $normalizedIds)
                ->update([
                    'payout_batch_id' => $batch->id,
                    'updated_at' => now(),
                ]);

            return $batch->fresh(['creator', 'finalizer']) ?? $batch;
        });
    }

    public function finalize(User $actor, PayoutBatch $batch): PayoutBatch
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($actor, $batch): PayoutBatch {
            $lockedBatch = PayoutBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedBatch) {
                throw new NotFoundHttpException('Not found.');
            }

            $this->assertBatchVisible($actor, $lockedBatch);

            if ($lockedBatch->status !== 'draft') {
                throw new ConflictHttpException('Batch đã được chốt hoặc xuất trước đó.');
            }

            $totals = AffiliatePayout::query()
                ->where('payout_batch_id', $lockedBatch->id)
                ->lockForUpdate()
                ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
                ->selectRaw('COUNT(*) as payout_count')
                ->first();

            $lockedBatch->update([
                'status' => 'finalized',
                'finalized_at' => now(),
                'finalized_by' => $actor->id,
                'total_amount' => (float) ($totals?->total_amount ?? 0),
                'payout_count' => (int) ($totals?->payout_count ?? 0),
            ]);

            return $lockedBatch->fresh(['creator', 'finalizer']) ?? $lockedBatch;
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(User $actor, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $this->assertCanManage($actor);

        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($actor);

        $query = PayoutBatch::query()
            ->with(['creator:id,name,email', 'finalizer:id,name,email'])
            ->latest('id');

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['batch_no'])) {
            $query->where('batch_no', 'LIKE', '%' . trim((string) $filters['batch_no']) . '%');
        }

        if ($scopeUserIds !== null) {
            $query
                ->whereHas('payouts', static function ($payoutQuery) use ($scopeUserIds): void {
                    $payoutQuery->whereIn('user_id', $scopeUserIds);
                })
                ->whereDoesntHave('payouts', static function ($payoutQuery) use ($scopeUserIds): void {
                    $payoutQuery->whereNotIn('user_id', $scopeUserIds);
                });
        }

        return $query->paginate($perPage);
    }

    /**
     * @return array{
     *   batch:PayoutBatch,
     *   payouts:Collection<int,AffiliatePayout>
     * }
     */
    public function detail(User $actor, PayoutBatch $batch): array
    {
        $this->assertCanManage($actor);
        $this->assertBatchVisible($actor, $batch);

        $payouts = AffiliatePayout::query()
            ->with('user:id,name,email')
            ->where('payout_batch_id', $batch->id)
            ->latest('payout_at')
            ->get();

        return [
            'batch' => $batch->loadMissing(['creator:id,name,email', 'finalizer:id,name,email']),
            'payouts' => $payouts,
        ];
    }

    /**
     * @return Collection<int, AffiliatePayout>
     */
    public function listUnbatchedPayouts(User $actor, int $limit = 50): Collection
    {
        $this->assertCanManage($actor);

        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($actor);

        $query = AffiliatePayout::query()
            ->with('user:id,name,email')
            ->whereNull('payout_batch_id')
            ->latest('payout_at')
            ->limit(max(1, $limit));

        if ($scopeUserIds !== null) {
            $query->whereIn('user_id', $scopeUserIds);
        }

        return $query->get();
    }

    private function assertBatchVisible(User $actor, PayoutBatch $batch): void
    {
        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($actor);
        if ($scopeUserIds === null) {
            return;
        }

        $hasAny = AffiliatePayout::query()
            ->where('payout_batch_id', $batch->id)
            ->whereIn('user_id', $scopeUserIds)
            ->exists();
        if (! $hasAny) {
            throw new NotFoundHttpException('Not found.');
        }

        $hasOutside = AffiliatePayout::query()
            ->where('payout_batch_id', $batch->id)
            ->whereNotIn('user_id', $scopeUserIds)
            ->exists();
        if ($hasOutside) {
            throw new NotFoundHttpException('Not found.');
        }
    }

    private function assertCanManage(User $actor): void
    {
        if ($actor->isPartner()) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden');
        }
    }

    /**
     * @param  Collection<int, AffiliatePayout>  $payouts
     */
    private function createBatchWithUniqueNumber(User $actor, Collection $payouts, ?string $note): PayoutBatch
    {
        $maxAttempts = 5;
        $prefix = 'BATCH-' . now()->format('Ym') . '-';

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $sequence = (int) PayoutBatch::query()
                ->where('batch_no', 'LIKE', $prefix . '%')
                ->lockForUpdate()
                ->count() + 1 + $attempt;

            $batchNo = $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

            try {
                return PayoutBatch::query()->create([
                    'batch_no' => $batchNo,
                    'status' => 'draft',
                    'total_amount' => round((float) $payouts->sum('amount'), 2),
                    'payout_count' => $payouts->count(),
                    'note' => $note,
                    'created_by' => $actor->id,
                ]);
            } catch (QueryException $exception) {
                // Retry on unique collision only.
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
            }
        }

        throw new ConflictHttpException('Không thể tạo mã batch. Vui lòng thử lại.');
    }
}
