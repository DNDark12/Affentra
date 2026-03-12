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
     *     parent:array{id:int,name:string}|null,
     *     payout_review_status:string|null,
     *     has_banking_info:bool,
     *     masked_bank_account:string|null,
     *     masked_bank_name:string|null
     *   },
     *   connections_meta:array{
     *     total_count:int,
     *     active_count:int
     *   }
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

        $partner->loadMissing('parent:id,name', 'profile');

        $connections = DB::table('platform_connections')
            ->where('user_id', $partner->id)
            ->get(['status']);

        $profile = $partner->profile;
        $hasBankingInfo = $profile && !empty($profile->bank_account_number) && !empty($profile->bank_name);

        return [
            'partner' => [
                'id' => (int) $partner->id,
                'name' => (string) $partner->name,
                'email' => (string) $partner->email,
                'role' => (string) $partner->role->value,
                'status' => (string) $partner->status->value,
                'created_at' => $partner->created_at?->toIso8601String(),
                'avatar' => $partner->avatar_url ?? null,
                'parent' => $partner->parent
                    ? ['id' => (int) $partner->parent->id, 'name' => (string) $partner->parent->name]
                    : null,
                'payout_review_status' => $profile ? ($profile->payout_review_status?->value ?? 'pending') : 'pending',
                'has_banking_info' => $hasBankingInfo,
                'masked_bank_account' => $hasBankingInfo ? \Illuminate\Support\Str::mask($profile->bank_account_number ?? '', '*', 0, -4) : null,
                'masked_bank_name' => $hasBankingInfo ? $profile->bank_name : null,
            ],
            'connections_meta' => [
                'total_count' => $connections->count(),
                'active_count' => $connections->where('status', 'active')->count(),
            ],
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
