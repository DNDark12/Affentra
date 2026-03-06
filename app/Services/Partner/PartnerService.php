<?php

declare(strict_types=1);

namespace App\Services\Partner;

use App\Enums\UserRole;
use App\Models\AffiliateBilling;
use App\Models\AffiliatePayout;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\TrackingLink;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Mail\PartnerInvitationMail;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Scope\ScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PartnerService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly ScopeResolver $scopeResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForManager(User $manager, array $filters = []): LengthAwarePaginator
    {
        return $this->userRepository->paginatePartnersForManager(
            manager: $manager,
            filters: $filters,
            perPage: 20,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *   total_partners:int,
     *   active_partners:int,
     *   new_this_month:int
     * }
     */
    public function summaryForManager(User $manager, array $filters = []): array
    {
        return $this->userRepository->partnerSummaryForManager($manager, $filters);
    }

    /**
     * @return array{
     *   partner:array{
     *     id:int,
     *     name:string,
     *     email:string,
     *     role:string,
     *     status:string,
     *     created_at:string|null,
     *     avatar:string|null,
     *     parent:array{id:int,name:string}|null
     *   },
     *   overview:array{
     *     total_clicks:int,
     *     total_orders:int,
     *     total_commission:float,
     *     active_links_count:int
     *   },
     *   finance_summary:array{
     *     unpaid_balance:float,
     *     total_earned:float,
     *     total_paid:float,
     *     billings_count:int,
     *     payouts_count:int
     *   },
     *   recent_orders:list<array<string,mixed>>,
     *   recent_billings:list<array<string,mixed>>,
     *   recent_payouts:list<array<string,mixed>>,
     *   activity:list<array<string,mixed>>
     * }
     */
    public function detailForManager(User $manager, User $partner): array
    {
        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($manager);
        if ($scopeUserIds !== null && ! in_array($partner->id, $scopeUserIds, true)) {
            throw new NotFoundHttpException('Not found.');
        }

        if ($partner->role !== UserRole::Partner) {
            throw new NotFoundHttpException('Not found.');
        }

        $partner->loadMissing('parent:id,name', 'profile:id,user_id');

        $kpi = DB::table('daily_stats')
            ->where('user_id', $partner->id)
            ->selectRaw('COALESCE(SUM(clicks), 0) as total_clicks')
            ->selectRaw('COALESCE(SUM(orders), 0) as total_orders')
            ->selectRaw('COALESCE(SUM(commission), 0) as total_commission')
            ->first();

        $activeLinksCount = TrackingLink::query()
            ->where('user_id', $partner->id)
            ->where('status', 'active')
            ->count();

        $billingTotalsByStatus = AffiliateBilling::query()
            ->where('user_id', $partner->id)
            ->selectRaw('LOWER(status) as normalized_status')
            ->selectRaw('COALESCE(SUM(net_amount), 0) as total')
            ->groupByRaw('LOWER(status)')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [
                (string) ($row->normalized_status ?? '') => (float) ($row->total ?? 0.0),
            ])
            ->all();

        $recentOrders = Order::query()
            ->where('user_id', $partner->id)
            ->latest('ordered_at')
            ->limit(10)
            ->get([
                'id',
                'order_code',
                'status',
                'payout_status',
                'order_amount',
                'commission',
                'ordered_at',
                'approved_at',
                'paid_at',
                'tracking_link_id',
            ])
            ->map(static function (Order $order): array {
                return [
                    'id' => (int) $order->id,
                    'order_code' => (string) $order->order_code,
                    'status' => (string) $order->status->value,
                    'payout_status' => (string) $order->payout_status,
                    'order_amount' => (float) $order->order_amount,
                    'commission' => (float) $order->commission,
                    'ordered_at' => $order->ordered_at?->toIso8601String(),
                    'approved_at' => $order->approved_at?->toIso8601String(),
                    'paid_at' => $order->paid_at?->toIso8601String(),
                    'tracking_link_id' => $order->tracking_link_id,
                ];
            })
            ->values()
            ->all();

        $recentBillings = AffiliateBilling::query()
            ->where('user_id', $partner->id)
            ->latest('period_end')
            ->limit(10)
            ->get([
                'id',
                'billing_id',
                'status',
                'period_start',
                'period_end',
                'net_amount',
            ])
            ->map(static function (AffiliateBilling $billing): array {
                return [
                    'id' => (int) $billing->id,
                    'billing_id' => (string) $billing->billing_id,
                    'status' => (string) $billing->status,
                    'period_start' => $billing->period_start?->toIso8601String(),
                    'period_end' => $billing->period_end?->toIso8601String(),
                    'net_amount' => (float) $billing->net_amount,
                ];
            })
            ->values()
            ->all();

        $recentPayouts = AffiliatePayout::query()
            ->where('user_id', $partner->id)
            ->latest('payout_at')
            ->limit(10)
            ->get([
                'id',
                'payout_id',
                'status',
                'amount',
                'payout_at',
                'bank_name',
                'account_number_masked',
            ])
            ->map(static function (AffiliatePayout $payout): array {
                return [
                    'id' => (int) $payout->id,
                    'payout_id' => (string) $payout->payout_id,
                    'status' => (string) $payout->status,
                    'amount' => (float) $payout->amount,
                    'payout_at' => $payout->payout_at?->toIso8601String(),
                    'bank_name' => $payout->bank_name,
                    'account_number_masked' => $payout->account_number_masked,
                ];
            })
            ->values()
            ->all();

        $profileId = $partner->profile?->id;
        $activity = AuditLog::query()
            ->with('actor:id,name,email')
            ->where(function ($query) use ($partner, $profileId): void {
                $query->where('actor_id', $partner->id)
                    ->orWhere(function ($targetQuery) use ($partner): void {
                        $targetQuery->where('target_type', User::class)
                            ->where('target_id', $partner->id);
                    });

                if ($profileId !== null) {
                    $query->orWhere(function ($targetQuery) use ($profileId): void {
                        $targetQuery->where('target_type', UserProfile::class)
                            ->where('target_id', $profileId);
                    });
                }
            })
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(static function (AuditLog $log): array {
                return [
                    'id' => (int) $log->id,
                    'action' => (string) $log->action,
                    'reason' => $log->reason,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'actor' => [
                        'id' => $log->actor?->id,
                        'name' => $log->actor?->name,
                        'email' => $log->actor?->email,
                    ],
                ];
            })
            ->values()
            ->all();

        $financeSummary = [
            'unpaid_balance' => round($this->sumStatusBucket($billingTotalsByStatus, ['pending', 'processing']), 2),
            'total_earned' => round($this->sumStatusBucket($billingTotalsByStatus, ['paid', 'settled', 'completed', 'success']), 2),
            'total_paid' => round((float) AffiliatePayout::query()
                ->where('user_id', $partner->id)
                ->where(static function ($query): void {
                    $query->whereRaw('LOWER(status) = ?', ['completed'])
                        ->orWhereRaw('LOWER(status) = ?', ['success'])
                        ->orWhereRaw('LOWER(status) = ?', ['paid']);
                })
                ->sum('amount'), 2),
            'billings_count' => AffiliateBilling::query()->where('user_id', $partner->id)->count(),
            'payouts_count' => AffiliatePayout::query()->where('user_id', $partner->id)->count(),
        ];

        return [
            'partner' => [
                'id' => (int) $partner->id,
                'name' => (string) $partner->name,
                'email' => (string) $partner->email,
                'role' => (string) $partner->role->value,
                'status' => (string) $partner->status->value,
                'created_at' => $partner->created_at?->toIso8601String(),
                'avatar' => $partner->avatar,
                'parent' => $partner->parent
                    ? ['id' => (int) $partner->parent->id, 'name' => (string) $partner->parent->name]
                    : null,
            ],
            'overview' => [
                'total_clicks' => (int) ($kpi?->total_clicks ?? 0),
                'total_orders' => (int) ($kpi?->total_orders ?? 0),
                'total_commission' => round((float) ($kpi?->total_commission ?? 0), 2),
                'active_links_count' => $activeLinksCount,
            ],
            'finance_summary' => $financeSummary,
            'recent_orders' => $recentOrders,
            'recent_billings' => $recentBillings,
            'recent_payouts' => $recentPayouts,
            'activity' => $activity,
        ];
    }

    public function create(User $manager, array $validated): array
    {
        $email = $validated['email'];
        $partner = $this->userRepository->findByEmail($email);

        if ($partner) {
            // User exists: send confirmation link
            if ($partner->parent_id !== null) {
                if ($partner->parent_id === $manager->id) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'Người dùng này đã là Partner của bạn.']);
                } else {
                    throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'Người dùng này đã thuộc hệ thống của người quản lý khác.']);
                }
            }

            // Generate a signed URL that expires in 7 days
            $signedUrl = URL::temporarySignedRoute(
                'partner.confirm',
                now()->addDays(7),
                [
                    'id' => $partner->id,
                    'manager_id' => $manager->id,
                ]
            );

            // Send Email via Job or Mail Facade
            Mail::to($email)->queue(new PartnerInvitationMail($partner, null, $signedUrl));

            return [
                'id'   => $partner->id,
                'name' => $partner->name,
                'type' => 'confirmation',
            ];
        } else {
            // User does not exist: send registration link
            $registrationUrl = config('app.url') . '/register?ref=' . $manager->id;
            
            Mail::to($email)->queue(new \App\Mail\PartnerRegistrationMail($manager, $registrationUrl));

            return [
                'id'   => 0,
                'name' => 'Chưa đăng ký',
                'type' => 'registration',
            ];
        }
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
