<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Auth\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function redirectToGoogle(Request $request): RedirectResponse
    {
        if ($request->has('ref')) {
            $request->session()->put('ref', $request->query('ref'));
        }

        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable) {
            return redirect()->route('login')->with('error', 'Không thể xác thực Google. Vui lòng thử lại.');
        }

        $providerId = (string) $googleUser->getId();
        $email = $googleUser->getEmail();

        if ($providerId === '' || ! is_string($email) || $email === '') {
            return redirect()->route('login')->with('error', 'Tài khoản Google chưa cung cấp email hợp lệ.');
        }

        [$user, $isNewUser] = DB::transaction(function () use ($googleUser, $providerId, $email): array {
            $identity = UserIdentity::query()
                ->where('provider', 'google')
                ->where('provider_id', $providerId)
                ->first();

            $user = $identity?->user;
            $isNewUser = false;

            if (! $user) {
                $user = User::query()->where('email', $email)->first();
            }

            if (! $user) {
                $parentId = session('ref');

                if ($parentId && !User::where('id', $parentId)->exists()) {
                    $parentId = null;
                }

                $user = User::query()->create([
                    'name'              => $googleUser->getName() ?: 'Google User',
                    'email'             => $email,
                    'email_verified_at' => now(),
                    'avatar'            => $googleUser->getAvatar(),
                    'role'              => UserRole::CTV,
                    'status'            => UserStatus::Active,
                    'password'          => null,
                    'parent_id'         => $parentId,
                ]);
                $isNewUser = true;
            }

            $expiresAt = is_numeric($googleUser->expiresIn)
                ? now()->addSeconds((int) $googleUser->expiresIn)
                : null;

            UserIdentity::query()->updateOrCreate(
                [
                    'provider'    => 'google',
                    'provider_id' => $providerId,
                ],
                [
                    'user_id'         => $user->id,
                    'provider_email'  => $email,
                    'avatar'          => $googleUser->getAvatar(),
                    'access_token'    => $googleUser->token,
                    'refresh_token'   => $googleUser->refreshToken,
                    'expires_at'      => $expiresAt,
                ]
            );

            $user->refresh();

            return [$user, $isNewUser];
        });

        Auth::login($user, true);

        if ($user->status !== UserStatus::Active) {
            $this->authService->logout($request);

            return redirect()->route('login')->with(
                'error',
                $this->authService->inactiveStatusMessage($user->status)
            );
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
