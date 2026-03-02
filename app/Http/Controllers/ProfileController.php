<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdatePayoutProfileRequest;
use App\Http\Requests\Profile\UpdateProfileSettingsRequest;
use App\Models\User;
use App\Services\Profile\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
    ) {}

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
        $this->profileService->updateSettings($request->user(), $request->validated());

        return back()->with('success', 'Cập nhật hồ sơ thành công.');
    }

    public function updatePayout(UpdatePayoutProfileRequest $request): RedirectResponse
    {
        $this->profileService->updatePayout($request->user(), $request->validated());

        return back()->with('success', 'Đã lưu thông tin thanh toán. Hồ sơ payout sẽ được xét duyệt lại.');
    }
}
