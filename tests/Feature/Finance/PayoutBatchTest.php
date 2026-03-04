<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\AffiliatePayout;
use App\Models\PayoutBatch;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_payout_batch_from_unbatched_payouts(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $partner = User::factory()->create([
            'role' => UserRole::CTV->value,
            'parent_id' => $owner->id,
            'path' => $owner->id . '/2',
        ]);

        $connection = PlatformConnection::factory()->create([
            'user_id' => $partner->id,
            'platform' => 'shopee',
        ]);

        $payout1 = AffiliatePayout::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $partner->id,
            'platform' => 'shopee',
            'payout_id' => 'PO-1001',
            'amount' => 120000,
            'status' => 'paid',
            'payout_at' => now()->subDay(),
        ]);

        $payout2 = AffiliatePayout::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $partner->id,
            'platform' => 'shopee',
            'payout_id' => 'PO-1002',
            'amount' => 80000,
            'status' => 'paid',
            'payout_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($owner)->postJson('/api/payout-batches', [
            'payout_ids' => [$payout1->id, $payout2->id],
            'note' => 'Batch QA',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.payout_count', 2)
            ->assertJsonPath('data.total_amount', 200000);

        $this->assertDatabaseHas('payout_batches', [
            'payout_count' => 2,
            'total_amount' => 200000.00,
            'status' => 'draft',
            'note' => 'Batch QA',
        ]);

        $batchId = (int) $response->json('data.id');
        $this->assertDatabaseHas('affiliate_payouts', [
            'id' => $payout1->id,
            'payout_batch_id' => $batchId,
        ]);
        $this->assertDatabaseHas('affiliate_payouts', [
            'id' => $payout2->id,
            'payout_batch_id' => $batchId,
        ]);
    }

    public function test_batch_creation_rejects_when_any_payout_already_batched(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);
        $partner = User::factory()->create([
            'role' => UserRole::CTV->value,
            'parent_id' => $owner->id,
            'path' => $owner->id . '/2',
        ]);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $partner->id,
            'platform' => 'shopee',
        ]);

        $existingBatch = PayoutBatch::query()->create([
            'batch_no' => 'BATCH-202603-0001',
            'status' => 'draft',
            'total_amount' => 50000,
            'payout_count' => 1,
            'created_by' => $owner->id,
        ]);

        $alreadyBatched = AffiliatePayout::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $partner->id,
            'platform' => 'shopee',
            'payout_id' => 'PO-2001',
            'amount' => 50000,
            'status' => 'paid',
            'payout_batch_id' => $existingBatch->id,
            'payout_at' => now()->subDay(),
        ]);

        $newPayout = AffiliatePayout::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $partner->id,
            'platform' => 'shopee',
            'payout_id' => 'PO-2002',
            'amount' => 35000,
            'status' => 'paid',
            'payout_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($owner)->postJson('/api/payout-batches', [
            'payout_ids' => [$alreadyBatched->id, $newPayout->id],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertDatabaseMissing('affiliate_payouts', [
            'id' => $newPayout->id,
            'payout_batch_id' => $existingBatch->id,
        ]);
    }

    public function test_leader_gets_404_when_batching_payout_outside_scope(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $leader = User::factory()->create([
            'role' => UserRole::Leader->value,
            'parent_id' => $owner->id,
            'path' => $owner->id . '/2',
        ]);

        $otherLeader = User::factory()->create([
            'role' => UserRole::Leader->value,
            'parent_id' => $owner->id,
            'path' => $owner->id . '/3',
        ]);

        $outsidePartner = User::factory()->create([
            'role' => UserRole::CTV->value,
            'parent_id' => $otherLeader->id,
            'path' => $owner->id . '/3/4',
        ]);

        $outsideConnection = PlatformConnection::factory()->create([
            'user_id' => $outsidePartner->id,
            'platform' => 'shopee',
        ]);

        $outsidePayout = AffiliatePayout::query()->create([
            'platform_connection_id' => $outsideConnection->id,
            'user_id' => $outsidePartner->id,
            'platform' => 'shopee',
            'payout_id' => 'PO-3001',
            'amount' => 99000,
            'status' => 'paid',
            'payout_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($leader)->postJson('/api/payout-batches', [
            'payout_ids' => [$outsidePayout->id],
        ]);

        $response->assertStatus(404);
    }

    public function test_owner_can_finalize_draft_batch(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);
        $partner = User::factory()->create([
            'role' => UserRole::CTV->value,
            'parent_id' => $owner->id,
            'path' => $owner->id . '/2',
        ]);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $partner->id,
            'platform' => 'shopee',
        ]);

        $batch = PayoutBatch::query()->create([
            'batch_no' => 'BATCH-202603-0002',
            'status' => 'draft',
            'total_amount' => 0,
            'payout_count' => 0,
            'created_by' => $owner->id,
        ]);

        AffiliatePayout::query()->create([
            'platform_connection_id' => $connection->id,
            'payout_batch_id' => $batch->id,
            'user_id' => $partner->id,
            'platform' => 'shopee',
            'payout_id' => 'PO-4001',
            'amount' => 70000,
            'status' => 'paid',
            'payout_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($owner)->postJson('/api/payout-batches/' . $batch->id . '/finalize');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.status', 'finalized');

        $this->assertDatabaseHas('payout_batches', [
            'id' => $batch->id,
            'status' => 'finalized',
            'payout_count' => 1,
            'total_amount' => 70000.00,
            'finalized_by' => $owner->id,
        ]);
    }

    public function test_finalize_rejects_when_batch_is_not_draft(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $batch = PayoutBatch::query()->create([
            'batch_no' => 'BATCH-202603-0003',
            'status' => 'finalized',
            'total_amount' => 100000,
            'payout_count' => 1,
            'created_by' => $owner->id,
            'finalized_by' => $owner->id,
            'finalized_at' => now(),
        ]);

        $response = $this->actingAs($owner)->postJson('/api/payout-batches/' . $batch->id . '/finalize');

        $response->assertStatus(409)
            ->assertJsonPath('ok', false);
    }

    public function test_ctv_cannot_create_or_finalize_batch(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $ctv = User::factory()->create([
            'role' => UserRole::CTV->value,
            'parent_id' => $owner->id,
            'path' => $owner->id . '/2',
        ]);

        $connection = PlatformConnection::factory()->create([
            'user_id' => $ctv->id,
            'platform' => 'shopee',
        ]);

        $payout = AffiliatePayout::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $ctv->id,
            'platform' => 'shopee',
            'payout_id' => 'PO-5001',
            'amount' => 123000,
            'status' => 'paid',
            'payout_at' => now()->subDay(),
        ]);

        $createResponse = $this->actingAs($ctv)->postJson('/api/payout-batches', [
            'payout_ids' => [$payout->id],
        ]);
        $createResponse->assertStatus(403);

        $batch = PayoutBatch::query()->create([
            'batch_no' => 'BATCH-202603-0004',
            'status' => 'draft',
            'total_amount' => 123000,
            'payout_count' => 1,
            'created_by' => $owner->id,
        ]);

        $payout->update(['payout_batch_id' => $batch->id]);

        $finalizeResponse = $this->actingAs($ctv)->postJson('/api/payout-batches/' . $batch->id . '/finalize');
        $finalizeResponse->assertStatus(403);
    }
    public function test_owner_cannot_finalize_batch_belonging_to_different_isolated_scope(): void
    {
        // Two owners in separate isolated trees — Owner B tries to finalize Owner A's batch
        $ownerA = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $ownerB = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '2',
        ]);

        $batchOfA = PayoutBatch::query()->create([
            'batch_no'     => 'BATCH-202603-9001',
            'status'       => 'draft',
            'total_amount' => 50000,
            'payout_count' => 1,
            'created_by'   => $ownerA->id,
        ]);

        // Owner B attempts to finalize Owner A's batch
        $response = $this->actingAs($ownerB)->postJson('/api/payout-batches/' . $batchOfA->id . '/finalize');

        // Policy check fires before service — batch belongs to different owner scope → 403
        $response->assertForbidden();

        $this->assertDatabaseHas('payout_batches', [
            'id'     => $batchOfA->id,
            'status' => 'draft', // unchanged
        ]);
    }

    public function test_show_batch_of_another_scope_is_forbidden(): void
    {
        $ownerA = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $ownerB = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '2',
        ]);

        $batchOfA = PayoutBatch::query()->create([
            'batch_no'     => 'BATCH-202603-9002',
            'status'       => 'draft',
            'total_amount' => 10000,
            'payout_count' => 0,
            'created_by'   => $ownerA->id,
        ]);

        $response = $this->actingAs($ownerB)->getJson('/api/payout-batches/' . $batchOfA->id);

        $response->assertForbidden();
    }
}
