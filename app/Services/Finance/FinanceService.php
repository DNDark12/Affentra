<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Contracts\Repositories\AffiliateBillingRepositoryInterface;
use App\Contracts\Repositories\AffiliatePayoutRepositoryInterface;
use App\Jobs\Sync\SyncPaymentDataJob;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Scope\ScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class FinanceService
{
    public function __construct(
        private readonly AffiliateBillingRepositoryInterface $billingRepository,
        private readonly AffiliatePayoutRepositoryInterface $payoutRepository,
        private readonly ScopeResolver $scopeResolver,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *   billings:\Illuminate\Pagination\LengthAwarePaginator,
     *   payouts:\Illuminate\Pagination\LengthAwarePaginator,
     *   summary:array{unpaid_balance:float,total_earned:float,total_paid:float},
     *   filters:array<string,mixed>,
     *   sync:array{is_running:bool}
     * }
     */
    public function indexData(User $user, array $filters = []): array
    {
        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($user);
        $normalizedFilters = $this->normalizeDateRange($filters);

        $billings = $this->billingRepository->paginateForScope(
            scopeUserIds: $scopeUserIds,
            filters: $normalizedFilters,
            perPage: 20,
            pageName: 'billings_page',
        );

        $payouts = $this->payoutRepository->paginateForScope(
            scopeUserIds: $scopeUserIds,
            filters: $normalizedFilters,
            perPage: 20,
            pageName: 'payouts_page',
        );

        $billingTotalsByStatus = $this->billingRepository->sumNetAmountGroupedByStatus(
            scopeUserIds: $scopeUserIds,
            filters: $normalizedFilters,
        );

        $summary = [
            'unpaid_balance' => round($this->sumStatusBucket(
                $billingTotalsByStatus,
                ['pending', 'processing'],
            ), 2),
            'total_earned' => round($this->sumStatusBucket(
                $billingTotalsByStatus,
                ['paid', 'settled', 'completed', 'success'],
            ), 2),
            'total_paid' => round($this->payoutRepository->sumAmountByStatuses(
                scopeUserIds: $scopeUserIds,
                statuses: ['completed', 'success', 'paid'],
                filters: $normalizedFilters,
            ), 2),
        ];

        return [
            'billings' => $billings,
            'payouts' => $payouts,
            'summary' => $summary,
            'filters' => $this->exposeFilters($filters),
            'sync' => [
                'is_running' => \App\Models\SyncRun::query()
                    ->where('user_id', $user->id)
                    ->whereIn('type', ['payment_sync', 'manual'])
                    ->where('status', 'processing')
                    ->exists(),
            ],
        ];
    }

    /**
     * @return array{ok:bool,status:string,message:string,jobs_dispatched?:int}
     */
    public function triggerManualSync(User $user): array
    {
        $connections = PlatformConnection::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('method', 'cookie')
            ->get();

        if ($connections->isEmpty()) {
            $this->auditLogger->log(
                actor: $user,
                action: 'finance.sync.manual_rejected',
                target: User::class,
                targetId: $user->id,
                previousState: null,
                newState: ['status' => 'no_connection'],
                reason: 'No active cookie connection found.',
            );

            return [
                'ok' => false,
                'status' => 'no_connection',
                'message' => 'Không tìm thấy kết nối Shopee Cookie nào đang hoạt động.',
            ];
        }

        $lockTtlSeconds = max(300, (int) config('integrations.sync.lock_ttl_seconds', 900));
        $lockKey = $this->syncLockKey($user->id);
        if (! Cache::add($lockKey, now()->toIso8601String(), now()->addSeconds($lockTtlSeconds))) {
            $this->auditLogger->log(
                actor: $user,
                action: 'finance.sync.manual_rejected',
                target: User::class,
                targetId: $user->id,
                previousState: null,
                newState: ['status' => 'already_running'],
                reason: 'Sync lock exists.',
            );

            return [
                'ok' => false,
                'status' => 'already_running',
                'message' => 'Vui lòng đợi 5 phút',
            ];
        }

        foreach ($connections as $connection) {
            SyncPaymentDataJob::dispatch($connection);
        }

        $this->auditLogger->log(
            actor: $user,
            action: 'finance.sync.manual_dispatch',
            target: User::class,
            targetId: $user->id,
            previousState: null,
            newState: [
                'status' => 'queued',
                'jobs_dispatched' => $connections->count(),
                'connection_ids' => $connections->pluck('id')->all(),
            ],
        );

        return [
            'ok' => true,
            'status' => 'queued',
            'message' => 'Đã bắt đầu tiến trình đồng bộ dữ liệu tài chính.',
            'jobs_dispatched' => $connections->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeDateRange(array $filters): array
    {
        $timezone = 'Asia/Ho_Chi_Minh';
        $normalized = $filters;

        if (! empty($filters['date_from'])) {
            $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['date_from'], $timezone)
                ->startOfDay()
                ->setTimezone(config('app.timezone', 'UTC'));
            $normalized['date_from'] = $from->toDateTimeString();
        }

        if (! empty($filters['date_to'])) {
            $to = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['date_to'], $timezone)
                ->endOfDay()
                ->setTimezone(config('app.timezone', 'UTC'));
            $normalized['date_to'] = $to->toDateTimeString();
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function exposeFilters(array $filters): array
    {
        return [
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'billing_status' => $filters['billing_status'] ?? null,
            'payout_status' => $filters['payout_status'] ?? null,
        ];
    }

    private function syncLockKey(int $userId): string
    {
        return "finance_sync:user:{$userId}";
    }

    /**
     * @param  array<string,float>  $totalsByStatus
     * @param  list<string>  $statuses
     */
    private function sumStatusBucket(array $totalsByStatus, array $statuses): float
    {
        $total = 0.0;
        foreach ($statuses as $status) {
            $total += (float) ($totalsByStatus[mb_strtolower($status)] ?? 0.0);
        }

        return $total;
    }
}
