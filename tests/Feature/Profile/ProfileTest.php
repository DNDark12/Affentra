<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_profile_edit_page()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Profile/Edit')
            ->has('settings')
            ->has('payout')
        );
    }

    public function test_user_can_update_profile_settings()
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'phone' => '0123456789'
        ]);

        $response = $this->actingAs($user)->put(route('profile.settings.update'), [
            'name' => 'New Name',
            'phone' => '0987654321',
        ]);

        $response->assertRedirect();
        $this->assertEquals('New Name', $user->fresh()->name);
        $this->assertEquals('0987654321', $user->fresh()->phone);
    }

    public function test_user_can_change_password()
    {
        Notification::fake();
        $user = User::factory()->create([
            'password' => Hash::make('old-password')
        ]);

        $response = $this->actingAs($user)->put(route('profile.settings.update'), [
            'name' => $user->name,
            'current_password' => 'old-password',
            'new_password' => 'new-secure-password',
            'new_password_confirmation' => 'new-secure-password',
        ]);

        $response->assertRedirect();
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    public function test_password_change_requires_correct_current_password()
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password')
        ]);

        $response = $this->actingAs($user)->put(route('profile.settings.update'), [
            'name' => $user->name,
            'current_password' => 'wrong-password',
            'new_password' => 'new-secure-password',
            'new_password_confirmation' => 'new-secure-password',
        ]);

        $response->assertSessionHasErrors(['current_password']);
        $this->assertTrue(Hash::check('correct-password', $user->fresh()->password));
    }

    public function test_user_can_update_payout_information()
    {
        $user = User::factory()->create();
        $profile = UserProfile::factory()->create([
            'user_id' => $user->id,
            'bank_name' => 'Old Bank',
            'is_payout_ready' => true
        ]);

        $response = $this->actingAs($user)->put(route('profile.payout.update'), [
            'bank_code' => 'vcb',
            'bank_name' => 'Vietcombank',
            'bank_account_name' => 'JOHN DOE',
            'bank_account_number' => '1234567890',
        ]);

        $response->assertRedirect();
        $profile = $profile->fresh();
        $this->assertEquals('Vietcombank', $profile->bank_name);
        $this->assertFalse($profile->is_payout_ready);
    }
}
