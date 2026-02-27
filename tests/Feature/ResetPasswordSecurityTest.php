<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\PasswordChangedNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ResetPasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_sends_password_changed_notification_and_requires_relogin(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email'    => 'reset@example.com',
            'password' => Hash::make('old-password-123'),
        ]);

        $token = Password::broker()->createToken($user);

        $response = $this->post(route('password.update'), [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');
        $this->assertGuest();

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', (string) $user->password));

        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }
}
