<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\DataTransferObjects\AI\GeneratedMediaResult;
use App\DataTransferObjects\AI\GeneratedTextResult;
use App\Services\AI\Contracts\AIProviderClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Seedance AI provider client — async video generation.
 *
 * API docs: https://seedance2.app/docs/api-reference
 *
 * Base URL: https://seedance2.app/api/v1
 * Auth:     Authorization: Bearer <api_key>
 * Generate: POST /generate → { data: { video_id }, error: null }
 * Status:   GET  /videos/:video_id → { data: { status, video_url, ... }, error: null }
 * Models:   doubao-seedance-2-0, doubao-seedance-1-5-pro, nano-banana-2
 */
class SeedanceClient implements AIProviderClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;

    private const DEFAULT_BASE_URL = 'https://seedance2.app/api/v1';

    public function __construct(
        ?string $apiKey  = null,
        ?string $baseUrl = null,
        ?string $model   = null,
    ) {
        $this->apiKey  = $apiKey ?? '';
        $this->baseUrl = rtrim($baseUrl ?: self::DEFAULT_BASE_URL, '/');
        
        // Ensure model is valid for Seedance, fallback to default if not
        $this->model = $model ?: 'doubao-seedance-2-0';
        if (! str_starts_with($this->model, 'doubao')) {
            $this->model = 'doubao-seedance-2-0';
        }

        if (empty($this->apiKey)) {
            throw new RuntimeException(
                'Seedance API key chưa được cấu hình. Vui lòng nhập API key khi thêm provider.'
            );
        }
    }

    // ── Interface: text generation (not supported) ────────────────────────────

    public function generateText(string $prompt, array $options = []): GeneratedTextResult
    {
        throw new RuntimeException(
            "Provider 'seedance' không hỗ trợ tạo text. Chỉ hỗ trợ video generation."
        );
    }

    // ── Interface: media generation (async, video only) ───────────────────────

    public function generateMedia(
        string $prompt,
        string $type = 'video',
        array  $options = [],
    ): GeneratedMediaResult {
        if ($type !== 'video') {
            throw new RuntimeException("Không hỗ trợ '{$type}'. Seedance chỉ hỗ trợ video.");
        }

        $targetModel = $options['model'] ?? $this->model;
        if (! str_starts_with($targetModel, 'doubao')) {
            $targetModel = $this->model;
        }

        $payload = [
            'prompt' => $prompt,
            'model'  => $targetModel,
        ];

        // Optional params from Seedance API
        if (! empty($options['duration']))       $payload['duration']        = (int) $options['duration'];
        if (! empty($options['aspect_ratio']))   $payload['aspect_ratio']    = $options['aspect_ratio'];
        if (! empty($options['resolution']))     $payload['resolution']      = $options['resolution'];
        if (! empty($options['generation_type'])) $payload['generation_type'] = $options['generation_type'];
        if (! empty($options['image_url']))      $payload['image_url']       = $options['image_url'];
        if (! empty($options['duration']))       $payload['duration']        = (int) $options['duration'];

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type'  => 'application/json',
        ])->timeout(30)->post("{$this->baseUrl}/generate", $payload);

        // Seedance returns { data: {...}, error: null } or { data: null, error: { message, code } }
        $body = $response->json();

        if (! $response->successful() || ! empty($body['error'])) {
            $errorMsg = $body['error']['message']
                ?? $body['error']
                ?? "Seedance API error (HTTP {$response->status()})";

            Log::error('SeedanceClient: generateMedia failed', [
                'status' => $response->status(),
                'error'  => $errorMsg,
                'body'   => $body,
            ]);

            throw new RuntimeException($errorMsg);
        }

        $videoId = $body['data']['video_id'] ?? null;

        if (! $videoId) {
            throw new RuntimeException('Seedance trả về thành công nhưng thiếu video_id.');
        }

        return new GeneratedMediaResult(
            media:    [],
            provider: 'seedance',
            model:    $payload['model'],
            meta:     ['task_id' => $videoId],
        );
    }

    // ── Poll task status ──────────────────────────────────────────────────────

    /**
     * Check the status of a video generation task.
     *
     * API: GET /videos/:video_id
     * Response: { data: { status, video_url, error, ... }, error: null }
     *
     * @return array{status: string, video_url: ?string, progress: ?int, error: ?string}
     */
    public function checkTaskStatus(string $taskId): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
        ])->timeout(15)->get("{$this->baseUrl}/videos/{$taskId}");

        $body = $response->json();

        if (! $response->successful() || ! empty($body['error'])) {
            $errorMsg = $body['error']['message']
                ?? $body['error']
                ?? "Seedance status poll failed (HTTP {$response->status()})";

            throw new RuntimeException($errorMsg);
        }

        $data = $body['data'] ?? [];

        return [
            'status'    => $data['status'] ?? 'unknown',
            'video_url' => $data['video_url'] ?? null,
            'progress'  => $data['progress'] ?? null,
            'error'     => $data['error'] ?? null,
        ];
    }

    // ── Connection test (GET /credits) ──────────────────────────────────────

    /**
     * Verify API key and connection by calling GET /credits.
     *
     * @throws RuntimeException on failure
     */
    public function checkCredits(): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
        ])->timeout(10)->get("{$this->baseUrl}/credits");

        $body = $response->json();

        if (! $response->successful() || ! empty($body['error'])) {
            throw new RuntimeException(
                $body['error']['message']
                    ?? 'Không thể kết nối đến Seedance API (HTTP ' . $response->status() . ')'
            );
        }

        return $body['data'] ?? [];
    }

    // ── Interface identity ────────────────────────────────────────────────────

    public function providerKey(): string
    {
        return 'seedance';
    }

    public function modelKey(): string
    {
        return $this->model;
    }

    public function supportsAsyncMedia(): bool
    {
        return true;
    }

    public function supportsCapability(string $capability): bool
    {
        return $capability === 'video';
    }

    public function supportsNativeSystemPrompt(string $modality): bool
    {
        return false;
    }
}
