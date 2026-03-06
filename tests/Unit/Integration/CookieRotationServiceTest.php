<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Models\PlatformConnection;
use App\Services\Integration\CookieCredentialService;
use App\Services\Integration\CookieRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CookieRotationServiceTest extends TestCase
{
    use RefreshDatabase;

    private CookieRotationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CookieRotationService::class);
    }

    // =========================================================
    // Gate 1: Feature flag
    // =========================================================

    public function test_skips_when_flag_off(): void
    {
        config(['services.shopee.cookie_auto_rotation' => false]);

        $connection = $this->createConnectionWithCookie('SPC_EC=old; SPC_F=keepme');

        $result = $this->service->rotateIfEligible($connection, [
            'ok' => true,
            'refreshed_cookie' => 'SPC_EC=new; SPC_F=keepme',
        ]);

        $this->assertEquals('skipped_flag_off', $result);
    }

    // =========================================================
    // Gate 2: Response eligibility
    // =========================================================

    public function test_skips_when_ok_is_false(): void
    {
        config(['services.shopee.cookie_auto_rotation' => true]);

        $connection = $this->createConnectionWithCookie('SPC_EC=old');

        $result = $this->service->rotateIfEligible($connection, [
            'ok' => false,
            'refreshed_cookie' => 'SPC_EC=new',
        ]);

        $this->assertEquals('skipped_not_eligible', $result);
    }

    public function test_skips_on_auth_failure_error_type(): void
    {
        config(['services.shopee.cookie_auto_rotation' => true]);

        $connection = $this->createConnectionWithCookie('SPC_EC=old');

        $result = $this->service->rotateIfEligible($connection, [
            'ok' => true,
            'error_type' => 'auth_failure',
            'refreshed_cookie' => 'SPC_EC=new',
        ]);

        $this->assertEquals('skipped_not_eligible', $result);
    }

    public function test_skips_on_blocked_bot_error_type(): void
    {
        config(['services.shopee.cookie_auto_rotation' => true]);

        $connection = $this->createConnectionWithCookie('SPC_EC=old');

        $result = $this->service->rotateIfEligible($connection, [
            'ok' => true,
            'error_type' => 'blocked_bot',
            'refreshed_cookie' => 'SPC_EC=new',
        ]);

        $this->assertEquals('skipped_not_eligible', $result);
    }

    public function test_skips_when_no_refreshed_cookie(): void
    {
        config(['services.shopee.cookie_auto_rotation' => true]);

        $connection = $this->createConnectionWithCookie('SPC_EC=old');

        $result = $this->service->rotateIfEligible($connection, [
            'ok' => true,
            'refreshed_cookie' => null,
        ]);

        $this->assertEquals('skipped_not_eligible', $result);
    }

    // =========================================================
    // Gate 3: Cookie validity
    // =========================================================

    public function test_skips_when_refreshed_cookie_missing_spc_ec(): void
    {
        config(['services.shopee.cookie_auto_rotation' => true]);

        $connection = $this->createConnectionWithCookie('SPC_EC=old');

        $result = $this->service->rotateIfEligible($connection, [
            'ok' => true,
            'refreshed_cookie' => 'SPC_F=something; other=x',
        ]);

        $this->assertEquals('skipped_invalid', $result);
    }

    // =========================================================
    // Gate 4: Hash comparison
    // =========================================================

    public function test_skips_when_hash_identical(): void
    {
        config(['services.shopee.cookie_auto_rotation' => true]);

        $connection = $this->createConnectionWithCookie('SPC_EC=abc123; SPC_F=def456');

        $result = $this->service->rotateIfEligible($connection, [
            'ok' => true,
            'refreshed_cookie' => 'SPC_EC=abc123; SPC_F=def456',
        ]);

        $this->assertEquals('skipped_hash_identical', $result);
    }

    public function test_skips_when_hash_identical_regardless_of_order(): void
    {
        config(['services.shopee.cookie_auto_rotation' => true]);

        $connection = $this->createConnectionWithCookie('SPC_F=def456; SPC_EC=abc123');

        $result = $this->service->rotateIfEligible($connection, [
            'ok' => true,
            // Same cookies, different order
            'refreshed_cookie' => 'SPC_EC=abc123; SPC_F=def456',
        ]);

        $this->assertEquals('skipped_hash_identical', $result);
    }

    // =========================================================
    // Gate 5: Lock + Success path
    // =========================================================

    public function test_success_rotates_cookie_in_db(): void
    {
        config(['services.shopee.cookie_auto_rotation' => true]);

        $connection = $this->createConnectionWithCookie('SPC_EC=old; SPC_F=keep');

        $result = $this->service->rotateIfEligible($connection, [
            'ok' => true,
            'refreshed_cookie' => 'SPC_EC=new; SPC_F=keep; SPC_NEW=extra',
        ]);

        $this->assertEquals('success', $result);

        // Verify DB was updated
        $fresh = PlatformConnection::find($connection->id);
        $decoded = json_decode($fresh->cookie_header, true);

        $this->assertStringContainsString('SPC_EC=new', $decoded['cookie']);
        $this->assertStringContainsString('SPC_F=keep', $decoded['cookie']);
        $this->assertStringContainsString('SPC_NEW=extra', $decoded['cookie']);
        $this->assertStringNotContainsString('SPC_EC=old', $decoded['cookie']);
    }

    public function test_success_preserves_profiles_in_json(): void
    {
        config(['services.shopee.cookie_auto_rotation' => true]);

        $profiles = ['signed' => ['af-ac-enc-dat' => 'test123']];
        $connection = $this->createConnectionWithCookie(
            'SPC_EC=old',
            ['profiles' => $profiles]
        );

        $result = $this->service->rotateIfEligible($connection, [
            'ok' => true,
            'refreshed_cookie' => 'SPC_EC=new',
        ]);

        $this->assertEquals('success', $result);

        $fresh = PlatformConnection::find($connection->id);
        $decoded = json_decode($fresh->cookie_header, true);

        $this->assertStringContainsString('SPC_EC=new', $decoded['cookie']);
        $this->assertEquals($profiles, $decoded['profiles']);
    }

    public function test_lock_timeout_when_already_locked(): void
    {
        config(['services.shopee.cookie_auto_rotation' => true]);

        $connection = $this->createConnectionWithCookie('SPC_EC=old');

        // Pre-acquire the lock
        $lock = Cache::lock("cookie:rotate:{$connection->id}", 5);
        $lock->get();

        $result = $this->service->rotateIfEligible($connection, [
            'ok' => true,
            'refreshed_cookie' => 'SPC_EC=new',
        ]);

        $this->assertEquals('lock_timeout', $result);

        $lock->release();
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function createConnectionWithCookie(
        string $rawCookie,
        array $extraJson = []
    ): PlatformConnection {
        $json = array_merge(['cookie' => $rawCookie], $extraJson);

        return PlatformConnection::factory()->create([
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => json_encode($json, JSON_UNESCAPED_SLASHES),
        ]);
    }
}
