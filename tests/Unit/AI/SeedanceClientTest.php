<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\DataTransferObjects\AI\GeneratedMediaResult;
use App\Services\AI\Providers\SeedanceClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit tests for SeedanceClient.
 *
 * Uses Http::fake() — no real API calls.
 * Matches API docs: https://seedance2.app/docs/api-reference
 */
class SeedanceClientTest extends TestCase
{
    private function makeClient(): SeedanceClient
    {
        return new SeedanceClient(
            apiKey:  'test-api-key',
            baseUrl: 'https://seedance2.app/api/v1',
            model:   'doubao-seedance-2-0',
        );
    }

    // ─── Construction ────────────────────────────────────────────────────────

    public function test_throws_when_api_key_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API key');

        new SeedanceClient(apiKey: '', baseUrl: 'https://seedance2.app/api/v1');
    }

    public function test_defaults_base_url_when_empty(): void
    {
        // Should NOT throw — defaults to https://seedance2.app/api/v1
        $client = new SeedanceClient(apiKey: 'key', baseUrl: '');
        $this->assertEquals('seedance', $client->providerKey());
    }

    // ─── Text Generation (not supported) ─────────────────────────────────────

    public function test_generate_text_throws(): void
    {
        $client = $this->makeClient();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('không hỗ trợ tạo text');

        $client->generateText('any prompt');
    }

    // ─── Capability ──────────────────────────────────────────────────────────

    public function test_supports_async_media(): void
    {
        $this->assertTrue($this->makeClient()->supportsAsyncMedia());
    }

    public function test_model_fallback_on_invalid_name(): void
    {
        // If passed a Gemini model name to SeedanceClient, it should fallback to default
        $client = new SeedanceClient('key', null, 'gemini-1.5-flash');
        $this->assertEquals('doubao-seedance-2-0', $client->modelKey());
    }

    public function test_supports_capability(): void
    {
        $client = $this->makeClient();
        $this->assertTrue($client->supportsCapability('video'));
        $this->assertFalse($client->supportsCapability('text'));
        $this->assertFalse($client->supportsCapability('image'));
    }

    public function test_provider_key(): void
    {
        $this->assertEquals('seedance', $this->makeClient()->providerKey());
    }

    public function test_model_key(): void
    {
        $this->assertEquals('doubao-seedance-2-0', $this->makeClient()->modelKey());
    }

    // ─── generateMedia() ─────────────────────────────────────────────────────
    // API: POST /generate → { data: { video_id: "..." }, error: null }

    public function test_generate_media_submits_task_and_returns_task_id(): void
    {
        Http::fake([
            'seedance2.app/api/v1/generate' => Http::response([
                'data'  => ['video_id' => 'vid_abc123'],
                'error' => null,
            ], 200),
        ]);

        $client = $this->makeClient();
        $result = $client->generateMedia('Make a video about cats', 'video');

        $this->assertInstanceOf(GeneratedMediaResult::class, $result);
        $this->assertEmpty($result->media);
        $this->assertEquals('vid_abc123', $result->meta['task_id']);
        $this->assertEquals('seedance', $result->provider);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/generate')
                && $request['prompt'] === 'Make a video about cats'
                && $request['model'] === 'doubao-seedance-2-0';
        });
    }

    public function test_generate_media_falls_back_on_invalid_model_override(): void
    {
        Http::fake([
            'seedance2.app/api/v1/generate' => Http::response([
                'data'  => ['video_id' => 'vid_fallback'],
                'error' => null,
            ], 200),
        ]);

        $client = $this->makeClient(); // default model is doubao-seedance-2-0
        
        // Passing a gpt-4o model as an override in options
        $result = $client->generateMedia('prompt', 'video', ['model' => 'gpt-4o']);

        $this->assertEquals('vid_fallback', $result->meta['task_id']);
        
        Http::assertSent(function ($request) {
            return $request['model'] === 'doubao-seedance-2-0'; // Should NOT be gpt-4o
        });
    }

    public function test_generate_media_throws_for_non_video_type(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Không hỗ trợ 'image'");

        $this->makeClient()->generateMedia('prompt', 'image');
    }

    public function test_generate_media_throws_on_missing_video_id(): void
    {
        Http::fake([
            'seedance2.app/api/v1/generate' => Http::response([
                'data'  => [],
                'error' => null,
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('thiếu video_id');

        $this->makeClient()->generateMedia('prompt', 'video');
    }

    public function test_generate_media_throws_on_api_error(): void
    {
        Http::fake([
            'seedance2.app/api/v1/generate' => Http::response([
                'data'  => null,
                'error' => ['message' => 'Rate limit exceeded', 'code' => 'rate_limit_exceeded'],
            ], 429),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $this->makeClient()->generateMedia('prompt', 'video');
    }

    // ─── checkTaskStatus() ───────────────────────────────────────────────────
    // API: GET /videos/:video_id → { data: { status, video_url, ... }, error: null }

    public function test_check_task_status_returns_parsed_status(): void
    {
        Http::fake([
            'seedance2.app/api/v1/videos/vid_xyz' => Http::response([
                'data' => [
                    'status'    => 'completed',
                    'video_url' => 'https://cdn.seedance2.app/video.mp4',
                    'progress'  => 100,
                ],
                'error' => null,
            ], 200),
        ]);

        $result = $this->makeClient()->checkTaskStatus('vid_xyz');

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals('https://cdn.seedance2.app/video.mp4', $result['video_url']);
        $this->assertEquals(100, $result['progress']);
    }

    public function test_check_task_status_returns_failed_with_error(): void
    {
        Http::fake([
            'seedance2.app/api/v1/videos/vid_fail' => Http::response([
                'data' => [
                    'status' => 'failed',
                    'error'  => 'Content policy violation.',
                ],
                'error' => null,
            ], 200),
        ]);

        $result = $this->makeClient()->checkTaskStatus('vid_fail');

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('Content policy violation.', $result['error']);
        $this->assertNull($result['video_url']);
    }

    public function test_check_task_status_throws_on_api_error(): void
    {
        Http::fake([
            'seedance2.app/api/v1/videos/vid_err' => Http::response([
                'data'  => null,
                'error' => ['message' => 'Internal server error', 'code' => 'internal_error'],
            ], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Internal server error');

        $this->makeClient()->checkTaskStatus('vid_err');
    }
}
