<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class ResetPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function showResetForm(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->query('email'),
            'token' => $token,
        ]);
    }

    /**
     * Handle an incoming new password request.
     */
    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(\Illuminate\Support\Str::random(60));

                $user->save();
                event(new PasswordReset($user));
                $user->notify(new PasswordChangedNotification());
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('Mật khẩu đã được khôi phục thành công. Vui lòng đăng nhập lại.'));
        }

        return back()->withErrors(['email' => __($status)]);
    }
}
