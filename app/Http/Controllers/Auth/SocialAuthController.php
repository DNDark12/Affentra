<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $user = $this->authService->handleSocialiteUser($googleUser, 'google');

        if ($user->status !== UserStatus::Active) {
            $flashKey = $user->status === UserStatus::Pending ? 'status' : 'error';

            return redirect()->route('login')->with(
                $flashKey,
                $this->authService->inactiveStatusMessage($user->status)
            );
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
