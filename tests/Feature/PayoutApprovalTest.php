<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PayoutReviewStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_approve_pending_payout_profile_and_writes_audit_log(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner,
            'status' => UserStatus::Active,
        ]);
        $ctv = User::factory()->create([
            'role' => UserRole::CTV,
            'status' => UserStatus::Active,
            'parent_id' => $owner->id,
        ]);

        $profile = UserProfile::factory()->create([
            'user_id' => $ctv->id,
            'is_payout_ready' => false,
            'payout_review_status' => PayoutReviewStatus::Pending,
        ]);

        $response = $this->actingAs($owner)
            ->withHeader('X-Request-ID', 'payout-approve-req-001')
            ->postJson(route('api.payout-approvals.approve', ['userId' => $ctv->id]));

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.status', 'approved');

        $profile->refresh();
        $this->assertTrue((bool) $profile->is_payout_ready);
        $this->assertSame(PayoutReviewStatus::Approved->value, $profile->payout_review_status->value);
        $this->assertSame($owner->id, $profile->payout_reviewed_by);
        $this->assertNotNull($profile->payout_reviewed_at);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $owner->id,
            'target_type' => UserProfile::class,
            'target_id' => $profile->id,
            'action' => 'payout_profile.approve',
            'request_id' => 'payout-approve-req-001',
        ]);
    }

    public function test_leader_cannot_approve_profile_out_of_scope_and_gets_404(): void
    {
        $leaderA = User::factory()->create([
            'role' => UserRole::Leader,
            'status' => UserStatus::Active,
        ]);
        $leaderB = User::factory()->create([
            'role' => UserRole::Leader,
            'status' => UserStatus::Active,
        ]);
        $ctvOfB = User::factory()->create([
            'role' => UserRole::CTV,
            'status' => UserStatus::Active,
            'parent_id' => $leaderB->id,
        ]);

        UserProfile::factory()->create([
            'user_id' => $ctvOfB->id,
            'payout_review_status' => PayoutReviewStatus::Pending,
        ]);

        $response = $this->actingAs($leaderA)
            ->postJson(route('api.payout-approvals.approve', ['userId' => $ctvOfB->id]));

        $response->assertStatus(404);
    }

    public function test_ctv_cannot_access_payout_approval_endpoints(): void
    {
        $ctv = User::factory()->create([
            'role' => UserRole::CTV,
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($ctv)->getJson(route('api.payout-approvals.index'));
        $response->assertStatus(403);
    }

    public function test_reject_requires_reason(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner,
            'status' => UserStatus::Active,
        ]);
        $ctv = User::factory()->create([
            'role' => UserRole::CTV,
            'status' => UserStatus::Active,
            'parent_id' => $owner->id,
        ]);

        UserProfile::factory()->create([
            'user_id' => $ctv->id,
            'payout_review_status' => PayoutReviewStatus::Pending,
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('api.payout-approvals.reject', ['userId' => $ctv->id]), []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }
}
