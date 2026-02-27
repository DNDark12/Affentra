<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_login_creates_pending_user_and_identity_for_new_email(): void
    {
        $this->fakeGoogleUser(
            id: 'google-uid-100',
            email: 'new-google@example.com',
            name: 'New Google User',
        );

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');
        $this->assertGuest();

        $this->assertDatabaseHas('users', [
            'email'  => 'new-google@example.com',
            'status' => UserStatus::Pending->value,
            'role'   => UserRole::CTV->value,
        ]);

        $createdUser = User::query()->where('email', 'new-google@example.com')->firstOrFail();

        $this->assertDatabaseHas('user_identities', [
            'user_id'      => $createdUser->id,
            'provider'     => 'google',
            'provider_id'  => 'google-uid-100',
            'provider_email' => 'new-google@example.com',
        ]);
    }

    public function test_google_login_logs_in_existing_active_user(): void
    {
        $user = User::factory()->create([
            'email'  => 'active-google@example.com',
            'role'   => UserRole::CTV,
            'status' => UserStatus::Active,
        ]);

        $this->fakeGoogleUser(
            id: 'google-uid-200',
            email: 'active-google@example.com',
            name: 'Active User',
        );

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user->fresh());

        $this->assertDatabaseHas('user_identities', [
            'user_id'      => $user->id,
            'provider'     => 'google',
            'provider_id'  => 'google-uid-200',
            'provider_email' => 'active-google@example.com',
        ]);
    }

    public function test_google_login_rejects_existing_non_active_user(): void
    {
        $user = User::factory()->create([
            'email'  => 'suspended-google@example.com',
            'role'   => UserRole::CTV,
            'status' => UserStatus::Suspended,
        ]);

        $this->fakeGoogleUser(
            id: 'google-uid-300',
            email: 'suspended-google@example.com',
            name: 'Suspended User',
        );

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertGuest();

        $this->assertDatabaseHas('user_identities', [
            'user_id'      => $user->id,
            'provider'     => 'google',
            'provider_id'  => 'google-uid-300',
            'provider_email' => 'suspended-google@example.com',
        ]);
    }

    private function fakeGoogleUser(string $id, string $email, string $name): void
    {
        $socialiteUser = new SocialiteUser();
        $socialiteUser->map([
            'id'      => $id,
            'email'   => $email,
            'name'    => $name,
            'avatar'  => 'https://example.com/avatar.png',
        ]);
        $socialiteUser->token = 'google-access-token';
        $socialiteUser->refreshToken = 'google-refresh-token';
        $socialiteUser->expiresIn = 3600;

        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturn($provider);
    }
}
