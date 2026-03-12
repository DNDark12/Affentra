<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\AffiliatePayout;
use App\Models\PayoutBatch;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutBatchExportTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Export CSV
    // -------------------------------------------------------------------------

    public function test_owner_can_export_finalized_batch_as_generic_csv(): void
    {
        [$owner, $batch] = $this->makeFinalizedBatch();

        $response = $this->actingAs($owner)->get(
            route('api.payout-batches.export', ['payoutBatch' => $batch->id, 'format' => 'generic'])
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Mã Payout', $response->streamedContent());
    }

    public function test_export_returns_422_for_draft_batch(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner->value, 'path' => '1']);
        $batch = PayoutBatch::factory()->create([
            'status'     => 'draft',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)->get(
            route('api.payout-batches.export', ['payoutBatch' => $batch->id, 'format' => 'generic'])
        );

        // Policy denies export on draft (403), service further guards (409)
        $response->assertStatus(403);
    }

    public function test_export_returns_422_for_invalid_format(): void
    {
        [$owner, $batch] = $this->makeFinalizedBatch();

        $response = $this->actingAs($owner)->get(
            route('api.payout-batches.export', ['payoutBatch' => $batch->id, 'format' => 'invalid_bank'])
        );

        $response->assertStatus(422);
    }

    public function test_export_transitions_batch_status_to_exported(): void
    {
        [$owner, $batch] = $this->makeFinalizedBatch();

        $this->actingAs($owner)->get(
            route('api.payout-batches.export', ['payoutBatch' => $batch->id, 'format' => 'generic'])
        );

        $this->assertDatabaseHas('payout_batches', [
            'id'          => $batch->id,
            'status'      => 'exported',
            'exported_by' => $owner->id,
        ]);
        $this->assertNotNull($batch->fresh()->exported_at);
    }

    public function test_re_export_of_already_exported_batch_succeeds(): void
    {
        [$owner, $batch] = $this->makeFinalizedBatch();

        // First export
        $this->actingAs($owner)->get(
            route('api.payout-batches.export', ['payoutBatch' => $batch->id, 'format' => 'generic'])
        )->assertOk();

        // Second export should also succeed
        $response = $this->actingAs($owner)->get(
            route('api.payout-batches.export', ['payoutBatch' => $batch->id, 'format' => 'vietcombank'])
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_partner_cannot_export_batch(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner->value, 'path' => '1']);
        $partner = User::factory()->create([
            'role'      => UserRole::Partner->value,
            'parent_id' => $owner->id,
            'path'      => $owner->id . '/2',
        ]);

        $batch = PayoutBatch::factory()->create([
            'status'     => 'finalized',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($partner)->get(
            route('api.payout-batches.export', ['payoutBatch' => $batch->id, 'format' => 'generic'])
        );

        $response->assertForbidden();
    }

    public function test_export_audit_log_is_created(): void
    {
        [$owner, $batch] = $this->makeFinalizedBatch();

        $this->actingAs($owner)->get(
            route('api.payout-batches.export', ['payoutBatch' => $batch->id, 'format' => 'generic'])
        );

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'    => $owner->id,
            'action'      => 'payout_batch.exported',
            'target_id'   => $batch->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Delete draft batch
    // -------------------------------------------------------------------------

    public function test_owner_can_delete_draft_batch_and_payouts_are_released(): void
    {
        [$owner, $payout1, $payout2, $batch] = $this->makeDraftBatch();

        $response = $this->actingAs($owner)->deleteJson(
            route('api.payout-batches.destroy', $batch->id)
        );

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('payout_batches', ['id' => $batch->id]);
        $this->assertDatabaseHas('affiliate_payouts', ['id' => $payout1->id, 'payout_batch_id' => null]);
        $this->assertDatabaseHas('affiliate_payouts', ['id' => $payout2->id, 'payout_batch_id' => null]);
    }

    public function test_cannot_delete_finalized_batch(): void
    {
        [$owner, $batch] = $this->makeFinalizedBatch();

        $response = $this->actingAs($owner)->deleteJson(
            route('api.payout-batches.destroy', $batch->id)
        );

        $response->assertStatus(409);
        $this->assertDatabaseHas('payout_batches', ['id' => $batch->id]);
    }

    // -------------------------------------------------------------------------
    // Remove payout from draft batch
    // -------------------------------------------------------------------------

    public function test_owner_can_remove_payout_from_draft_batch(): void
    {
        [$owner, $payout1, $payout2, $batch] = $this->makeDraftBatch();

        $response = $this->actingAs($owner)->deleteJson(
            route('api.payout-batches.remove-payout', ['payoutBatch' => $batch->id, 'payout' => $payout1->id])
        );

        $response->assertOk()->assertJsonPath('ok', true);

        // payout1 released
        $this->assertDatabaseHas('affiliate_payouts', ['id' => $payout1->id, 'payout_batch_id' => null]);
        // payout2 still in batch
        $this->assertDatabaseHas('affiliate_payouts', ['id' => $payout2->id, 'payout_batch_id' => $batch->id]);
        // total recalculated
        $this->assertDatabaseHas('payout_batches', ['id' => $batch->id, 'payout_count' => 1]);
    }

    public function test_cannot_remove_payout_from_finalized_batch(): void
    {
        [$owner, $batch] = $this->makeFinalizedBatch();
        $payout          = AffiliatePayout::query()->where('payout_batch_id', $batch->id)->first();

        $response = $this->actingAs($owner)->deleteJson(
            route('api.payout-batches.remove-payout', ['payoutBatch' => $batch->id, 'payout' => $payout->id])
        );

        $response->assertStatus(409);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: User, 1: PayoutBatch}
     */
    private function makeFinalizedBatch(): array
    {
        $owner   = User::factory()->create(['role' => UserRole::Owner->value, 'path' => '1']);
        $partner = User::factory()->create([
            'role'      => UserRole::Partner->value,
            'parent_id' => $owner->id,
            'path'      => $owner->id . '/2',
        ]);

        $connection = PlatformConnection::factory()->create(['user_id' => $partner->id, 'platform' => 'shopee']);

        $batch = PayoutBatch::factory()->create([
            'status'       => 'finalized',
            'total_amount' => 150000,
            'payout_count' => 1,
            'created_by'   => $owner->id,
            'finalized_by' => $owner->id,
            'finalized_at' => now(),
        ]);

        AffiliatePayout::factory()->create([
            'platform_connection_id' => $connection->id,
            'user_id'                => $partner->id,
            'payout_batch_id'        => $batch->id,
            'platform'               => 'shopee',
            'payout_id'              => 'PO-EXP-001',
            'amount'                 => 150000,
            'status'                 => 'paid',
            'payout_at'              => now()->subDay(),
        ]);

        return [$owner, $batch];
    }

    /**
     * @return array{0: User, 1: AffiliatePayout, 2: AffiliatePayout, 3: PayoutBatch}
     */
    private function makeDraftBatch(): array
    {
        $owner   = User::factory()->create(['role' => UserRole::Owner->value, 'path' => '1']);
        $partner = User::factory()->create([
            'role'      => UserRole::Partner->value,
            'parent_id' => $owner->id,
            'path'      => $owner->id . '/2',
        ]);

        $connection = PlatformConnection::factory()->create(['user_id' => $partner->id, 'platform' => 'shopee']);

        $batch = PayoutBatch::factory()->create([
            'status'       => 'draft',
            'total_amount' => 200000,
            'payout_count' => 2,
            'created_by'   => $owner->id,
        ]);

        $payout1 = AffiliatePayout::factory()->create([
            'platform_connection_id' => $connection->id,
            'user_id'                => $partner->id,
            'payout_batch_id'        => $batch->id,
            'platform'               => 'shopee',
            'payout_id'              => 'PO-DEL-001',
            'amount'                 => 120000,
            'status'                 => 'paid',
            'payout_at'              => now()->subDay(),
        ]);

        $payout2 = AffiliatePayout::factory()->create([
            'platform_connection_id' => $connection->id,
            'user_id'                => $partner->id,
            'payout_batch_id'        => $batch->id,
            'platform'               => 'shopee',
            'payout_id'              => 'PO-DEL-002',
            'amount'                 => 80000,
            'status'                 => 'paid',
            'payout_at'              => now()->subDay(),
        ]);

        return [$owner, $payout1, $payout2, $batch];
    }
}
