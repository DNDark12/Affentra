<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScraperSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => UserRole::CTV]);
    }

    // ─── Allowed domains ──────────────────────────────────────────────────────

    public function test_allowed_shopee_vn_url_passes_validation(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/tools/scrape-product', [
                'url' => 'https://shopee.vn/product/123456/9999',
            ]);

        // Validation passes (may fail with network/scrape error, not 422)
        $this->assertNotEquals(422, $response->getStatusCode());
    }

    public function test_subdomain_of_shopee_vn_passes_validation(): void
    {
        // m.shopee.vn is a valid subdomain
        $response = $this->actingAs($this->user)
            ->postJson('/api/tools/scrape-product', [
                'url' => 'https://m.shopee.vn/product/123',
            ]);

        $this->assertNotEquals(422, $response->getStatusCode());
    }

    // ─── Blocked: evil.com trap ───────────────────────────────────────────────

    public function test_domain_ending_in_shopee_vn_evil_subdomain_is_blocked(): void
    {
        // Critical: shopee.vn.evil.com should NOT pass allowlist
        $response = $this->actingAs($this->user)
            ->postJson('/api/tools/scrape-product', [
                'url' => 'https://shopee.vn.evil.com/product/123',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['url']);
    }

    public function test_domain_containing_shopee_but_not_in_allowlist_is_blocked(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/tools/scrape-product', [
                'url' => 'https://fakeshopee.vn/product/123',
            ]);

        $response->assertStatus(422);
    }

    // ─── Blocked: SSRF / private IP ───────────────────────────────────────────

    public function test_localhost_url_is_blocked(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/tools/scrape-product', [
                'url' => 'https://localhost/secret',
            ]);

        $response->assertStatus(422);
    }

    public function test_private_ip_url_is_blocked(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/tools/scrape-product', [
                'url' => 'https://192.168.1.1/secret',
            ]);

        $response->assertStatus(422);
    }

    public function test_loopback_ip_url_is_blocked(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/tools/scrape-product', [
                'url' => 'https://127.0.0.1/secret',
            ]);

        $response->assertStatus(422);
    }

    // ─── Blocked: HTTP (non-HTTPS) ────────────────────────────────────────────

    public function test_http_shopee_url_is_blocked(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/tools/scrape-product', [
                'url' => 'http://shopee.vn/product/123',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['url']);
    }

    // ─── Misc ─────────────────────────────────────────────────────────────────

    public function test_missing_url_field_returns_422(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/tools/scrape-product', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['url']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/tools/scrape-product', [
            'url' => 'https://shopee.vn/product/123',
        ]);

        $response->assertStatus(401);
    }
}
