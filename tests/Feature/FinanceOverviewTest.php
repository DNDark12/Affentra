<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AffiliateBilling;
use App\Models\AffiliatePayout;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FinanceOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_summary_respects_global_scope_and_date_filter(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner,
            'status' => UserStatus::Active,
        ]);

        $connection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'method' => 'cookie',
            'status' => 'active',
        ]);

        AffiliateBilling::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'billing_id' => 'BILL-PENDING-1',
            'period_start' => '2026-02-01 00:00:00',
            'period_end' => '2026-02-10 12:00:00',
            'net_amount' => 100,
            'status' => 'pending',
        ]);
        AffiliateBilling::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'billing_id' => 'BILL-PROCESSING-1',
            'period_start' => '2026-02-01 00:00:00',
            'period_end' => '2026-02-12 12:00:00',
            'net_amount' => 50,
            'status' => 'processing',
        ]);
        AffiliateBilling::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'billing_id' => 'BILL-PAID-1',
            'period_start' => '2026-02-01 00:00:00',
            'period_end' => '2026-02-15 12:00:00',
            'net_amount' => 200,
            'status' => 'paid',
        ]);
        AffiliateBilling::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'billing_id' => 'BILL-SETTLED-1',
            'period_start' => '2026-02-01 00:00:00',
            'period_end' => '2026-02-20 12:00:00',
            'net_amount' => 300,
            'status' => 'settled',
        ]);
        AffiliateBilling::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'billing_id' => 'BILL-OUTSIDE-1',
            'period_start' => '2026-03-01 00:00:00',
            'period_end' => '2026-03-20 12:00:00',
            'net_amount' => 999,
            'status' => 'pending',
        ]);

        AffiliatePayout::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'payout_id' => 'PAY-COMPLETED-1',
            'payout_at' => '2026-02-18 12:00:00',
            'amount' => 180,
            'status' => 'completed',
        ]);
        AffiliatePayout::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'payout_id' => 'PAY-SUCCESS-1',
            'payout_at' => '2026-02-19 12:00:00',
            'amount' => 20,
            'status' => 'success',
        ]);
        AffiliatePayout::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'payout_id' => 'PAY-PENDING-1',
            'payout_at' => '2026-02-20 12:00:00',
            'amount' => 10,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($owner)->get('/finance?date_from=2026-02-01&date_to=2026-02-28');
        $response->assertOk();

        $page = $response->original->getData()['page'];
        $summary = $page['props']['summary'];

        $this->assertEquals(150.0, (float) $summary['unpaid_balance']);
        $this->assertEquals(500.0, (float) $summary['total_earned']);
        $this->assertEquals(200.0, (float) $summary['total_paid']);
    }

    public function test_finance_sync_returns_already_running_when_lock_exists(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner,
            'status' => UserStatus::Active,
        ]);

        PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'method' => 'cookie',
            'status' => 'active',
        ]);

        Cache::put("finance_sync:user:{$owner->id}", now()->toIso8601String(), now()->addMinutes(5));

        $response = $this->actingAs($owner)
            ->withHeader('X-Request-ID', 'finance-sync-req-001')
            ->postJson(route('api.finance.sync'));

        $response
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Vui lòng đợi 5 phút')
            ->assertJsonPath('errors.status.0', 'already_running');

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $owner->id,
            'target_type' => User::class,
            'target_id' => $owner->id,
            'action' => 'finance.sync.manual_rejected',
            'request_id' => 'finance-sync-req-001',
        ]);
    }
}
