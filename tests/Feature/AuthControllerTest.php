<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('test@example.com|127.0.0.1');
    }

    public function test_login_page_renders_successfully(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create([
            'email'  => 'test@example.com',
            'role'   => UserRole::CTV,
            'status' => UserStatus::Active,
        ]);

        $response = $this->withSession(['_token' => 'test-token'])
            ->post('/login', [
                '_token'   => 'test-token',
                'email'    => $user->email,
                'password' => 'password',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $response = $this->withSession(['_token' => 'test-token'])
            ->post('/login', [
                '_token'   => 'test-token',
                'email'    => $user->email,
                'password' => 'wrong-password',
            ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_non_active_users_cannot_login(): void
    {
        $user = User::factory()->create([
            'email'  => 'test@example.com',
            'status' => UserStatus::Pending,
        ]);

        $response = $this->withSession(['_token' => 'test-token'])
            ->post('/login', [
                '_token'   => 'test-token',
                'email'    => $user->email,
                'password' => 'password',
            ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_login_throttles_after_five_attempts(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com']);

        // First 5 failed attempts
        for ($i = 0; $i < 5; $i++) {
            $this->withSession(['_token' => 'test-token'])
                ->post('/login', [
                    '_token'   => 'test-token',
                    'email'    => $user->email,
                    'password' => 'wrong-password',
                ]);
        }

        // 6th attempt should be throttled
        $response = $this->withSession(['_token' => 'test-token'])
            ->post('/login', [
                '_token'   => 'test-token',
                'email'    => $user->email,
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(429);
    }
}
