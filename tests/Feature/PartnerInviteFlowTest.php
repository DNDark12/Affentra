<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\PartnerRegistrationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnerInviteFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_partner_accepts_email_only_payload(): void
    {
        Mail::fake();

        $leader = User::factory()->create([
            'role' => UserRole::Leader,
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($leader)->postJson(route('api.partners.store'), [
            'email' => 'partner-new@example.com',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.type', 'registration');

        Mail::assertQueued(PartnerRegistrationMail::class);
    }
}

