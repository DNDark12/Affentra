<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    /**
     * Attempt to authenticate a user by credentials.
     */
    public function attemptLogin(string $email, string $password, bool $remember = false): bool
    {
        if (! Auth::attempt(['email' => $email, 'password' => $password], $remember)) {
            return false;
        }

        /** @var User */
        $user = Auth::user();

        $this->ensureActiveOrFail($user, request());

        return true;
    }

    /**
     * Ensure an authenticated user is active; otherwise logout and throw.
     */
    public function ensureActiveOrFail(User $user, Request $request): void
    {
        if ($user->status === UserStatus::Active) {
            return;
        }

        $this->logout($request);

        throw ValidationException::withMessages([
            'email' => $this->inactiveStatusMessage($user->status),
        ]);
    }

    /**
     * Resolve the correct flash/error message for non-active users.
     */
    public function inactiveStatusMessage(UserStatus $status): string
    {
        return match ($status) {
            UserStatus::Pending => 'Tài khoản của bạn đang chờ duyệt. Vui lòng đợi Admin kích hoạt.',
            UserStatus::Suspended => 'Tài khoản của bạn đang bị tạm khóa. Vui lòng liên hệ Admin.',
            UserStatus::Banned => 'Tài khoản của bạn đã bị khóa vĩnh viễn.',
            UserStatus::Rejected => 'Tài khoản của bạn đã bị từ chối. Vui lòng liên hệ Admin nếu cần hỗ trợ.',
            default => 'Tài khoản của bạn hiện chưa thể đăng nhập.',
        };
    }

    /**
     * Log out the currently authenticated user.
     */
    public function logout(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Send a password reset link to the given email address.
     *
     * @return string  One of Password::RESET_LINK_SENT or Password::INVALID_USER
     */
    public function sendPasswordResetLink(string $email): string
    {
        return Password::sendResetLink(['email' => $email]);
    }

    /**
     * Get the currently authenticated user.
     */
    public function currentUser(): ?User
    {
        /** @var User|null */
        return Auth::user();
    }
}
