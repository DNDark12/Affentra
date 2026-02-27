<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConfirmPartnerController extends Controller
{
    /**
     * Handle the signed confirmation link for manually added partners.
     */
    public function confirm(Request $request, int $id): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect()->route('login')->with(
                'error',
                'Liên kết xác nhận không hợp lệ hoặc đã hết hạn. Vui lòng liên hệ Admin.'
            );
        }

        /** @var User */
        $user = User::findOrFail($id);
        
        $managerId = $request->query('manager_id');
        $manager = null;
        if ($managerId) {
            /** @var User|null */
            $manager = User::find($managerId);
        }

        $updated = false;

        if ($user->status === UserStatus::Pending) {
            $user->status = UserStatus::Active;
            $updated = true;
        }

        if ($manager && $user->parent_id !== $manager->id) {
            if ($user->parent_id === null) {
                $user->parent_id = $manager->id;
                $user->depth = $manager->depth + 1;
                $user->path = trim((string) $manager->path . '/' . $manager->id, '/');
                $updated = true;
            } else {
                return redirect()->route('login')->with(
                    'error',
                    'Tài khoản này đã thuộc về cấu trúc của người quản lý khác!'
                );
            }
        }

        if ($updated) {
            $user->save();
            return redirect()->route('login')->with(
                'status',
                'Xác nhận tài khoản thành công! Bạn có thể bắt đầu sử dụng hệ thống ngay bây giờ.'
            );
        }

        return redirect()->route('login')->with(
            'status',
            'Tài khoản hoặc vai trò đối tác của bạn đã được xác nhận từ trước. Vui lòng đăng nhập.'
        );
    }
}
