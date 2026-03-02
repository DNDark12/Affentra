<?php

declare(strict_types=1);

namespace App\Services\Partner;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Mail\PartnerInvitationMail;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class PartnerService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
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

    public function create(User $manager, array $validated): array
    {
        $email = $validated['email'];
        $partner = $this->userRepository->findByEmail($email);

        if ($partner) {
            // User exists: send confirmation link to join as descendant
            if ($partner->parent_id !== null) {
                if ($partner->parent_id === $manager->id) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'Người dùng này đã là CTV của bạn.']);
                } else {
                    throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'Người dùng này đã thuộc hệ thống của người quản lý khác.']);
                }
            }

            // Generate a signed URL for confirmation
            $signedUrl = URL::temporarySignedRoute(
                'partner.confirm',
                now()->addDays(7),
                [
                    'id'         => $partner->id,
                    'manager_id' => $manager->id,
                ]
            );

            Mail::to($email)->queue(new PartnerInvitationMail($partner, null, $signedUrl));

            return [
                'type' => 'confirmation_sent',
                'email' => $email,
            ];
        }

        // User does not exist: Strictly send registration invitation email
        // We do NOT create a user record here (Phase A0 Contract)
        $registrationUrl = route('register', ['ref' => $manager->id]);
        
        Mail::to($email)->queue(new \App\Mail\PartnerRegistrationMail($manager, $registrationUrl));

        return [
            'type' => 'invitation_sent',
            'email' => $email,
        ];
    }
}
