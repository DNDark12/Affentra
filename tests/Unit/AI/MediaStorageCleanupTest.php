<?php

namespace Tests\Unit\AI;

use App\Actions\AI\PersistGeneratedMediaAction;
use App\Models\ContentGeneration;
use App\Models\TrackingLink;
use App\Models\User;
use App\Services\AI\MediaStorageService;
use App\Services\AI\UsageNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaStorageCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_strips_base64_after_successful_persistence()
    {
        Storage::fake('ai_media');
        
        $user = User::factory()->create();
        $link = \App\Models\TrackingLink::create([
            'user_id' => $user->id,
            'short_code' => 'TEST' . rand(1000, 9999),
            'destination_url' => 'https://example.com',
            'platform' => 'shopee',
        ]);
        
        $generation = ContentGeneration::create([
            'user_id' => $user->id,
            'tracking_link_id' => $link->id,
            'type' => 'image',
            'status' => 'succeeded',
            'output_payload' => [
                'media' => [
                    [
                        'type' => 'image',
                        'base64' => 'dGVzdCBjb250ZW50', // base64 for "test content"
                    ]
                ]
            ]
        ]);

        $action = app(PersistGeneratedMediaAction::class);
        $action->execute($generation);

        $generation->refresh();
        $payload = $generation->output_payload;

        $this->assertArrayNotHasKey('base64', $payload['media'][0]);
        $this->assertArrayHasKey('storage', $payload['media'][0]);
        $this->assertEquals('ai_media', $payload['media'][0]['storage']['disk']);
        $this->assertNotEmpty($payload['media'][0]['delivery']['playback_url']);
        
        Storage::disk('ai_media')->assertExists($payload['media'][0]['storage']['path']);
    }

    public function test_it_retains_base64_on_storage_failure()
    {
        // Mock service to throw exception
        $mockService = $this->createMock(MediaStorageService::class);
        $mockService->method('store')->willThrowException(new \RuntimeException('Storage failed'));

        $user = User::factory()->create();
        $link = \App\Models\TrackingLink::create([
            'user_id' => $user->id,
            'short_code' => 'TEST' . rand(1000, 9999),
            'destination_url' => 'https://example.com',
            'platform' => 'shopee',
        ]);

        $generation = ContentGeneration::create([
            'user_id' => $user->id,
            'tracking_link_id' => $link->id,
            'type' => 'image',
            'status' => 'succeeded',
            'output_payload' => [
                'media' => [
                    [
                        'type' => 'image',
                        'base64' => 'dGVzdCBjb250ZW50',
                    ]
                ]
            ]
        ]);

        $action = new PersistGeneratedMediaAction($mockService, app(UsageNormalizer::class));
        $action->execute($generation);

        $generation->refresh();
        $payload = $generation->output_payload;

        // Base64 should still be there because storage failed
        $this->assertArrayHasKey('base64', $payload['media'][0]);
        $this->assertEquals('dGVzdCBjb250ZW50', $payload['media'][0]['base64']);
    }

}
