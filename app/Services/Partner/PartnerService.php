<?php

declare(strict_types=1);

namespace App\Services\Partner;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Mail\PartnerInvitationMail;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

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

    public function create(User $manager, array $validated): array
    {
        $email = $validated['email'];
        $partner = $this->userRepository->findByEmail($email);

        if ($partner) {
            // User exists: send confirmation link
            if ($partner->parent_id !== null) {
                if ($partner->parent_id === $manager->id) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'Người dùng này đã là CTV của bạn.']);
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
}
