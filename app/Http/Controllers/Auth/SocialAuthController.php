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

        $user = $this->authService->handleSocialiteUser($googleUser, 'google');

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

