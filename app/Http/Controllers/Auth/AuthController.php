<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function showLogin(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        if ($request->has('ref')) {
            $request->session()->put('ref', $request->query('ref'));
        }

        return Inertia::render('Auth/Login', [
            'status' => session('status'),
        ]);
    }

    public function showRegister(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        if ($request->has('ref')) {
            $request->session()->put('ref', $request->query('ref'));
        }

        return Inertia::render('Auth/Register');
    }

    public function register(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $parentId = session('ref');

        if ($parentId && !User::where('id', $parentId)->exists()) {
            $parentId = null;
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'status' => UserStatus::Active,
            'role' => UserRole::CTV,
            'parent_id' => $parentId,
        ]);

        Auth::login($user);

        return redirect()->route('dashboard');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $request->ensureIsNotThrottled();

        $validated = $request->validated();

        $success = $this->authService->attemptLogin(
            email: $validated['email'],
            password: $validated['password'],
            remember: (bool) ($validated['remember'] ?? false),
        );

        if (! $success) {
            RateLimiter::hit($request->throttleKey());

            return back()->withErrors([
                'email' => __('auth.failed'),
            ])->onlyInput('email');
        }

        RateLimiter::clear($request->throttleKey());

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->authService->logout($request);

        return redirect()->route('login');
    }

    public function showForgotPassword(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/ForgotPassword');
    }

    public function sendResetLink(ForgotPasswordRequest $request): RedirectResponse
    {
        // Fire-and-forget: always return the same generic message
        // to prevent email enumeration attacks.
        try {
            $this->authService->sendPasswordResetLink(
                email: $request->validated('email'),
            );
        } catch (\Throwable) {
            // Swallow any exception (e.g. missing password_resets table in test)
            // to avoid leaking email existence via error vs success response.
        }

        return back()->with('status', __('passwords.sent'));
    }
}
