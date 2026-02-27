<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Mật khẩu Affentra vừa được thay đổi')
            ->greeting('Xin chào,')
            ->line('Mật khẩu tài khoản của bạn vừa được thay đổi.')
            ->line('Nếu bạn không thực hiện thao tác này, vui lòng liên hệ Admin ngay để được hỗ trợ bảo mật.');
    }
}
