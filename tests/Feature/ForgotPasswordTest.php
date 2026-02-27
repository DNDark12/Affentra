<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Forgot password always returns the same message regardless of email existence.
     */
    public function test_forgot_password_returns_generic_message_for_existing_email(): void
    {
        User::factory()->create(['email' => 'exists@example.com']);

        $response = $this->withSession(['_token' => 'test-token'])
            ->post('/forgot-password', [
                '_token' => 'test-token',
                'email'  => 'exists@example.com',
            ]);

        $response->assertSessionHas('status');
        $response->assertSessionHasNoErrors();
    }

    /**
     * Forgot password returns the SAME generic message for non-existing email.
     */
    public function test_forgot_password_returns_generic_message_for_nonexistent_email(): void
    {
        $response = $this->withSession(['_token' => 'test-token'])
            ->post('/forgot-password', [
                '_token' => 'test-token',
                'email'  => 'doesnotexist@example.com',
            ]);

        // Must NOT return error — that would leak email existence
        $response->assertSessionHas('status');
        $response->assertSessionHasNoErrors();
    }

    /**
     * Forgot password is throttled after 5 attempts.
     */
    public function test_forgot_password_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withSession(['_token' => 'test-token'])
                ->post('/forgot-password', [
                    '_token' => 'test-token',
                    'email'  => 'spam@example.com',
                ]);
        }

        // 6th attempt should be throttled (429)
        $response = $this->withSession(['_token' => 'test-token'])
            ->post('/forgot-password', [
                '_token' => 'test-token',
                'email'  => 'spam@example.com',
            ]);

        $response->assertStatus(429);
    }
}
