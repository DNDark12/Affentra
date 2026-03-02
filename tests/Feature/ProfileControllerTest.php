<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_user_can_set_password_without_current_password(): void
    {
        $user = User::factory()->create([
            'role'     => UserRole::CTV,
            'status'   => UserStatus::Active,
            'password' => null,
        ]);

        $response = $this->actingAs($user)->put(route('profile.settings.update'), [
            'name'                      => 'Updated Name',
            'phone'                     => '0901123456',
            'avatar'                    => 'https://example.com/avatar.jpg',
            'new_password'              => 'new-password-123',
            'new_password_confirmation' => 'new-password-123',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('0901123456', $user->phone);
        $this->assertSame('https://example.com/avatar.jpg', $user->avatar);
        $this->assertNotNull($user->password);
        $this->assertTrue(Hash::check('new-password-123', (string) $user->password));
    }

    public function test_updating_bank_info_marks_payout_as_not_ready(): void
    {
        $user = User::factory()->create([
            'role'   => UserRole::CTV,
            'status' => UserStatus::Active,
        ]);

        UserProfile::query()->create([
            'user_id'             => $user->id,
            'bank_code'           => 'VCB',
            'bank_name'           => 'Vietcombank',
            'bank_account_name'   => 'Old Name',
            'bank_account_number' => '1234567890',
            'tax_id'              => '12345678',
            'is_payout_ready'     => true,
        ]);

        $response = $this->actingAs($user)->put(route('profile.payout.update'), [
            'bank_code'           => 'TCB',
            'bank_name'           => 'Techcombank',
            'bank_account_name'   => 'New Name',
            'bank_account_number' => '0987654321',
            'tax_id'              => '12345678',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $profile = UserProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('TCB', $profile->bank_code);
        $this->assertSame('Techcombank', $profile->bank_name);
        $this->assertSame('New Name', $profile->bank_account_name);
        $this->assertSame('0987654321', $profile->bank_account_number);
        $this->assertFalse((bool) $profile->is_payout_ready);
        $this->assertSame('pending', $profile->payout_review_status->value);
    }
}
