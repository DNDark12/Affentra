<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Contracts\Repositories\ProfileRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileService
{
    public function __construct(
        private readonly ProfileRepositoryInterface $profileRepository,
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    /**
     * Update user settings including password if provided.
     *
     * @throws ValidationException
     */
    public function updateSettings(User $user, array $data): void
    {
        $payload = [
            'name'   => $data['name'],
            'avatar' => $data['avatar'] ?? $user->avatar,
            'phone'  => $data['phone'] ?? $user->phone,
        ];

        $newPassword = $data['new_password'] ?? null;
        if (is_string($newPassword) && $newPassword !== '') {
            if ($user->password !== null) {
                $currentPassword = (string) ($data['current_password'] ?? '');

                if ($currentPassword === '' || ! Hash::check($currentPassword, $user->password)) {
                    throw ValidationException::withMessages([
                        'current_password' => 'Mật khẩu hiện tại không đúng.',
                    ]);
                }
            }

            $payload['password'] = Hash::make($newPassword);
            $user->notify(new \App\Notifications\PasswordChangedNotification());
        }

        $user->update($payload);
    }

    /**
     * Update payout information and reset readiness if changed.
     */
    public function updatePayout(User $user, array $data): void
    {
        $profile = $user->profile;
        
        $fields = [
            'bank_code',
            'bank_name',
            'bank_account_name',
            'bank_account_number',
            'tax_id'
        ];

        $hasChanged = false;
        if ($profile) {
            foreach ($fields as $field) {
                if (array_key_exists($field, $data) && $profile->{$field} !== $data[$field]) {
                    $hasChanged = true;
                    break;
                }
            }
        } else {
            $hasChanged = true;
        }

        $attributes = [
            'bank_code'           => $data['bank_code'] ?? null,
            'bank_name'           => $data['bank_name'] ?? null,
            'bank_account_name'   => $data['bank_account_name'] ?? null,
            'bank_account_number' => $data['bank_account_number'] ?? null,
            'tax_id'              => $data['tax_id'] ?? null,
        ];

        if ($hasChanged) {
            $attributes['is_payout_ready'] = false;
        }

        $this->profileRepository->updateOrCreateForUser($user, $attributes);
    }
}
