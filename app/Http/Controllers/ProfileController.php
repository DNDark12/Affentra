<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdatePayoutProfileRequest;
use App\Http\Requests\Profile\UpdateProfileSettingsRequest;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('profile');

        return Inertia::render('Profile/Edit', [
            'settings' => [
                'name'         => $user->name,
                'email'        => $user->email,
                'avatar'       => $user->avatar,
                'phone'        => $user->phone,
                'has_password' => $user->password !== null,
            ],
            'payout' => [
                'bank_code'           => $user->profile?->bank_code,
                'bank_name'           => $user->profile?->bank_name,
                'bank_account_name'   => $user->profile?->bank_account_name,
                'bank_account_number' => $user->profile?->bank_account_number,
                'tax_id'              => $user->profile?->tax_id,
                'is_payout_ready'     => (bool) ($user->profile?->is_payout_ready ?? false),
            ],
        ]);
    }

    public function updateSettings(UpdateProfileSettingsRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $payload = [
            'name'   => $data['name'],
            'avatar' => $data['avatar'] ?? null,
            'phone'  => $data['phone'] ?? null,
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

            $payload['password'] = $newPassword;
        }

        $user->fill($payload)->save();

        return back()->with('success', 'Cập nhật hồ sơ thành công.');
    }

    public function updatePayout(UpdatePayoutProfileRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $profile = $user->profile ?: new UserProfile(['user_id' => $user->id]);

        $trackedFields = ['bank_code', 'bank_name', 'bank_account_name', 'bank_account_number', 'tax_id'];
        $hasChanged = false;

        foreach ($trackedFields as $field) {
            if (array_key_exists($field, $data) && $profile->{$field} !== $data[$field]) {
                $hasChanged = true;
                break;
            }
        }

        $profile->fill([
            'bank_code'           => $data['bank_code'] ?? null,
            'bank_name'           => $data['bank_name'] ?? null,
            'bank_account_name'   => $data['bank_account_name'] ?? null,
            'bank_account_number' => $data['bank_account_number'] ?? null,
            'tax_id'              => $data['tax_id'] ?? null,
        ]);

        if ($hasChanged) {
            $profile->is_payout_ready = false;
        }

        $profile->user()->associate($user);
        $profile->save();

        return back()->with('success', 'Đã lưu thông tin thanh toán. Hồ sơ payout sẽ được xét duyệt lại.');
    }
}
