<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
        $response->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
        $response->assertHeader('X-Request-ID');

        $this->assertTrue(
            $response->headers->has('Content-Security-Policy-Report-Only')
            || $response->headers->has('Content-Security-Policy')
        );
    }

    public function test_request_id_uses_client_value_when_valid(): void
    {
        $response = $this->withHeader('X-Request-ID', 'frontend-req-123')->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Request-ID', 'frontend-req-123');
    }
}
