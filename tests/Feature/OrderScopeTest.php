<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $leader;
    private User $partner1;
    private User $partner2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'role'   => 'owner',
            'status' => 'active',
            'path'   => null,
        ]);
        
        $this->leader = User::factory()->create([
            'role'      => 'leader',
            'status'    => 'active',
            'parent_id' => $this->owner->id,
            'path'      => (string) $this->owner->id,
        ]);
        
        $this->partner1 = User::factory()->create([
            'role' => 'partner',
            'status'    => 'active',
            'parent_id' => $this->leader->id,
            'path'      => $this->owner->id . '/' . $this->leader->id,
        ]);

        $this->partner2 = User::factory()->create([
            'role' => 'partner',
            'status'    => 'active',
            'parent_id' => $this->owner->id, // Direct child of owner, sibling of leader
            'path'      => (string) $this->owner->id,
        ]);

        // Create orders
        Order::create(['user_id' => $this->owner->id, 'order_code' => 'O1', 'platform' => 'shopee', 'order_amount' => 100, 'commission' => 10, 'status' => 'pending', 'ordered_at' => now()]);
        Order::create(['user_id' => $this->leader->id, 'order_code' => 'L1', 'platform' => 'shopee', 'order_amount' => 100, 'commission' => 10, 'status' => 'pending', 'ordered_at' => now()]);
        Order::create(['user_id' => $this->partner1->id, 'order_code' => 'C1', 'platform' => 'shopee', 'order_amount' => 100, 'commission' => 10, 'status' => 'pending', 'ordered_at' => now()]);
        Order::create(['user_id' => $this->partner2->id, 'order_code' => 'C2', 'platform' => 'shopee', 'order_amount' => 100, 'commission' => 10, 'status' => 'pending', 'ordered_at' => now()]);
    }

    public function test_partner_only_sees_own_orders(): void
    {
        $response = $this->actingAs($this->partner1)->getJson(route('api.orders.list'));
        
        $response->assertStatus(200);
        $data = $response->json('data.data');
        
        $this->assertCount(1, $data);
        $this->assertEquals('C1', $data[0]['order_code']);
    }

    public function test_leader_sees_own_and_direct_child_orders(): void
    {
        $response = $this->actingAs($this->leader)->getJson(route('api.orders.list'));
        
        $response->assertStatus(200);
        $data = $response->json('data.data');
        
        // Should see L1 and C1, but not O1 or C2
        $this->assertCount(2, $data);
        $codes = collect($data)->pluck('order_code')->toArray();
        $this->assertContains('L1', $codes);
        $this->assertContains('C1', $codes);
        $this->assertNotContains('C2', $codes); // Different branch
    }

    public function test_owner_sees_all_orders_in_downline(): void
    {
        $response = $this->actingAs($this->owner)->getJson(route('api.orders.list'));
        
        $response->assertStatus(200);
        $data = $response->json('data.data');
        
        // Owner sees O1, L1, C1, C2
        $this->assertCount(4, $data);
    }
}
