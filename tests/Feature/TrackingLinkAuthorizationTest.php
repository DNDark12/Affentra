<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LinkStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Campaign;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingLinkAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that a user CANNOT update another user's tracking link (IDOR prevention).
     */
    public function test_user_cannot_update_another_users_tracking_link(): void
    {
        $userA = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);
        $userB = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);

        $linkOfB = TrackingLink::create([
            'user_id'         => $userB->id,
            'short_code'      => 'linkofb1',
            'destination_url' => 'https://example.com/b',
            'status'          => LinkStatus::Active,
        ]);

        $response = $this->actingAs($userA)
            ->withSession(['_token' => 'test-token'])
            ->patchJson("/api/links/{$linkOfB->id}", [
                '_token'          => 'test-token',
                'destination_url' => 'https://evil.com/hijacked',
            ]);

        $response->assertStatus(404);

        // Ensure the link was NOT modified
        $linkOfB->refresh();
        $this->assertEquals('https://example.com/b', $linkOfB->destination_url);
    }

    /**
     * Test that a user CANNOT archive another user's tracking link.
     */
    public function test_user_cannot_archive_another_users_tracking_link(): void
    {
        $userA = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);
        $userB = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);

        $linkOfB = TrackingLink::create([
            'user_id'         => $userB->id,
            'short_code'      => 'archtest',
            'destination_url' => 'https://example.com/b',
            'status'          => LinkStatus::Active,
        ]);

        $response = $this->actingAs($userA)
            ->withSession(['_token' => 'test-token'])
            ->patchJson("/api/links/{$linkOfB->id}/archive", [
                '_token' => 'test-token',
            ]);

        $response->assertStatus(404);

        // Ensure the link was NOT archived
        $linkOfB->refresh();
        $this->assertEquals(LinkStatus::Active, $linkOfB->status);
    }

    /**
     * Test that a user CAN update their own tracking link.
     */
    public function test_user_can_update_own_tracking_link(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);

        $link = TrackingLink::create([
            'user_id'         => $user->id,
            'short_code'      => 'ownlink1',
            'destination_url' => 'https://example.com/old',
            'status'          => LinkStatus::Active,
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Request-ID', 'tracking-update-req-001')
            ->withSession(['_token' => 'test-token'])
            ->patchJson("/api/links/{$link->id}", [
                '_token'          => 'test-token',
                'destination_url' => 'https://example.com/new',
            ]);

        $response->assertStatus(200);

        $link->refresh();
        $this->assertEquals('https://example.com/new', $link->destination_url);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'target_type' => TrackingLink::class,
            'target_id' => $link->id,
            'action' => 'tracking_link.update',
            'request_id' => 'tracking-update-req-001',
        ]);
    }

    /**
     * Test that an Owner can update any user's tracking link.
     */
    public function test_owner_can_update_any_tracking_link(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'status' => UserStatus::Active]);
        $partner   = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);

        $link = TrackingLink::create([
            'user_id'         => $partner->id,
            'short_code'      => 'partnerlink1',
            'destination_url' => 'https://example.com/partner',
            'status'          => LinkStatus::Active,
        ]);

        $response = $this->actingAs($owner)
            ->withSession(['_token' => 'test-token'])
            ->patchJson("/api/links/{$link->id}", [
                '_token'          => 'test-token',
                'destination_url' => 'https://example.com/fixed-by-owner',
            ]);

        $response->assertStatus(200);
    }

    public function test_legacy_inactive_status_input_is_normalized_to_paused(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);

        $link = TrackingLink::create([
            'user_id'         => $user->id,
            'short_code'      => 'legacy11',
            'destination_url' => 'https://example.com/legacy',
            'status'          => LinkStatus::Active,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->patchJson("/api/links/{$link->id}", [
                '_token' => 'test-token',
                'status' => 'inactive',
            ]);

        $response->assertStatus(200);
        $link->refresh();

        $this->assertSame(LinkStatus::Paused, $link->status);
    }

    public function test_archived_tracking_link_cannot_transition_back_to_active(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);

        $link = TrackingLink::create([
            'user_id'         => $user->id,
            'short_code'      => 'archlock1',
            'destination_url' => 'https://example.com/archived',
            'status'          => LinkStatus::Archived,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->patchJson("/api/links/{$link->id}", [
                '_token' => 'test-token',
                'status' => 'active',
            ]);

        $response->assertStatus(422);
        $link->refresh();

        $this->assertSame(LinkStatus::Archived, $link->status);
    }

    public function test_user_cannot_assign_campaign_outside_scope_on_update(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);
        $other = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);

        $campaign = Campaign::create([
            'user_id' => $other->id,
            'name' => 'Out Scope',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        $link = TrackingLink::create([
            'user_id'         => $user->id,
            'short_code'      => 'cmpnoscp',
            'destination_url' => 'https://example.com/mine',
            'status'          => LinkStatus::Active,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->patchJson("/api/links/{$link->id}", [
                '_token' => 'test-token',
                'campaign_id' => $campaign->id,
            ]);

        $response->assertStatus(422);
        $link->refresh();

        $this->assertNull($link->campaign_id);
    }
}
