<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Models\PlatformConnection;
use App\Services\Integration\CookieCredentialService;
use Tests\TestCase;

class CookieCredentialServiceTest extends TestCase
{
    private CookieCredentialService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CookieCredentialService();
    }

    // =========================================================
    // extractRawCookie
    // =========================================================

    public function test_extract_raw_cookie_from_json_format(): void
    {
        $connection = $this->makeConnection(json_encode([
            'cookie' => 'SPC_EC=abc123; SPC_F=def456',
            'profiles' => ['signed' => []],
        ]));

        $result = $this->service->extractRawCookie($connection);

        $this->assertEquals('SPC_EC=abc123; SPC_F=def456', $result);
    }

    public function test_extract_raw_cookie_from_legacy_plain_string(): void
    {
        $connection = $this->makeConnection('SPC_EC=abc123; SPC_F=def456');

        $result = $this->service->extractRawCookie($connection);

        $this->assertEquals('SPC_EC=abc123; SPC_F=def456', $result);
    }

    public function test_extract_raw_cookie_empty_header_returns_empty(): void
    {
        $connection = $this->makeConnection('');

        $result = $this->service->extractRawCookie($connection);

        $this->assertEquals('', $result);
    }

    public function test_extract_raw_cookie_null_header_returns_empty(): void
    {
        $connection = $this->makeConnection(null);

        $result = $this->service->extractRawCookie($connection);

        $this->assertEquals('', $result);
    }

    // =========================================================
    // parseCookiePairs
    // =========================================================

    public function test_parse_basic_cookies(): void
    {
        $result = $this->service->parseCookiePairs('SPC_EC=abc; SPC_F=def');

        $this->assertEquals(['SPC_EC' => 'abc', 'SPC_F' => 'def'], $result);
    }

    public function test_parse_cookie_with_equals_in_value(): void
    {
        // Base64 values contain '='
        $result = $this->service->parseCookiePairs('token=abc123==; SPC_EC=xyz');

        $this->assertEquals([
            'token' => 'abc123==',
            'SPC_EC' => 'xyz',
        ], $result);
    }

    public function test_parse_deduplicates_by_last_value(): void
    {
        $result = $this->service->parseCookiePairs('SPC_EC=old; other=x; SPC_EC=new');

        $this->assertEquals([
            'SPC_EC' => 'new',
            'other' => 'x',
        ], $result);
    }

    public function test_parse_empty_string_returns_empty(): void
    {
        $this->assertEquals([], $this->service->parseCookiePairs(''));
    }

    public function test_parse_segments_without_equals_are_skipped(): void
    {
        $result = $this->service->parseCookiePairs('SPC_EC=abc; novalue; SPC_F=def');

        $this->assertEquals(['SPC_EC' => 'abc', 'SPC_F' => 'def'], $result);
    }

    // =========================================================
    // mergeCookies
    // =========================================================

    public function test_merge_cookies_new_overrides_old(): void
    {
        $old = 'SPC_EC=old; SPC_F=keep';
        $new = 'SPC_EC=refreshed; newKey=added';

        $result = $this->service->mergeCookies($old, $new);

        // Sorted by key
        $this->assertStringContainsString('SPC_EC=refreshed', $result);
        $this->assertStringContainsString('SPC_F=keep', $result);
        $this->assertStringContainsString('newKey=added', $result);
        $this->assertStringNotContainsString('SPC_EC=old', $result);
    }

    // =========================================================
    // hashCookie
    // =========================================================

    public function test_hash_is_order_insensitive(): void
    {
        $hash1 = $this->service->hashCookie('A=1; B=2');
        $hash2 = $this->service->hashCookie('B=2; A=1');

        $this->assertEquals($hash1, $hash2);
    }

    public function test_hash_changes_when_value_changes(): void
    {
        $hash1 = $this->service->hashCookie('SPC_EC=old');
        $hash2 = $this->service->hashCookie('SPC_EC=new');

        $this->assertNotEquals($hash1, $hash2);
    }

    public function test_hash_is_12_chars(): void
    {
        $hash = $this->service->hashCookie('SPC_EC=abc123');

        $this->assertEquals(12, strlen($hash));
    }

    // =========================================================
    // isValidShopeeCookie
    // =========================================================

    public function test_valid_cookie_with_spc_ec(): void
    {
        $this->assertTrue($this->service->isValidShopeeCookie('SPC_EC=abc123; other=x'));
    }

    public function test_invalid_cookie_missing_spc_ec(): void
    {
        $this->assertFalse($this->service->isValidShopeeCookie('SPC_F=abc123; other=x'));
    }

    public function test_invalid_cookie_empty_spc_ec(): void
    {
        $this->assertFalse($this->service->isValidShopeeCookie('SPC_EC=; other=x'));
    }

    public function test_invalid_cookie_empty_string(): void
    {
        $this->assertFalse($this->service->isValidShopeeCookie(''));
    }

    // =========================================================
    // normalize
    // =========================================================

    public function test_normalize_trims_whitespace(): void
    {
        $this->assertEquals('SPC_EC=abc', $this->service->normalize('  SPC_EC=abc  '));
    }

    public function test_normalize_removes_cookie_prefix(): void
    {
        $this->assertEquals('SPC_EC=abc', $this->service->normalize('Cookie: SPC_EC=abc'));
    }

    public function test_normalize_removes_trailing_semicolons(): void
    {
        $this->assertEquals('SPC_EC=abc', $this->service->normalize('SPC_EC=abc;;; '));
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function makeConnection(?string $cookieHeader): PlatformConnection
    {
        $connection = new PlatformConnection();
        $connection->cookie_header = $cookieHeader;

        return $connection;
    }
}
