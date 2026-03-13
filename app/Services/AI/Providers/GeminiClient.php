<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\DataTransferObjects\AI\GeneratedTextResult;
use App\DataTransferObjects\AI\GeneratedMediaResult;
use App\Services\AI\Contracts\AIProviderClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Gemini API adapter.
 *
 * Configure via:
 *   AI_PROVIDER_GEMINI_KEY=...
 *   AI_PROVIDER_GEMINI_MODEL=gemini-1.5-flash   (default)
 */
class GeminiClient implements AIProviderClient
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $model = null, ?string $baseUrl = null)
    {
        $this->apiKey  = $apiKey ?? '';
        $this->model   = $model ?: 'gemini-3.1-flash';
        
        // Remove strict enforcing of /v1beta/models so OpenAI proxy falling back works cleanly
        $this->baseUrl = $this->remapLocalhost($baseUrl ? rtrim($baseUrl, '/') : 'https://generativelanguage.googleapis.com/v1beta');

        if (empty($this->apiKey)) {
            throw new \RuntimeException(
                'Gemini API key chưa được cấu hình. Vui lòng nhập API key khi thêm provider.'
            );
        }
    }

    /**
     * When running inside Docker, transparently remap localhost to the host gateway.
     */
    private function remapLocalhost(string $url): string
    {
        if (app()->environment('testing')) {
            return $url;
        }

        $gateway = env('DOCKER_HOST_GATEWAY', 'host.docker.internal');

        return preg_replace(
            '#(https?://)(?:127\.0\.0\.1|localhost)(:\d+)?#',
            '$1' . $gateway . '$2',
            $url,
        ) ?? $url;
    }

    public function providerKey(): string
    {
        return 'gemini';
    }

    public function modelKey(): string
    {
        return $this->model;
    }

    /**
     * Veo models require async polling; text/image models are synchronous.
     */
    public function supportsAsyncMedia(): bool
    {
        return $this->isVeoModel($this->model);
    }

    public function supportsCapability(string $capability): bool
    {
        return in_array($capability, ['text', 'image', 'video'], true);
    }

    private function isVeoModel(string $model): bool
    {
        return str_starts_with($model, 'veo-');
    }

    public function supportsNativeSystemPrompt(string $modality): bool
    {
        // Gemini supports system_instruction for text models. 
        // For Imagen/Media, we usually prepend or use specific config if supported.
        return $modality === 'text';
    }

    /**
     * @throws RuntimeException  on provider failure or bad response
     */
    public function generateText(string $prompt, array $options = []): GeneratedTextResult
    {
        $maxTokens = (int) ($options['max_tokens'] ?? 2048);

        $parts = [['text' => $prompt]];

        $images = $options['images'] ?? [];
        foreach ($images as $base64DataScheme) {
            if (preg_match('/^data:(image\/[^;]+);base64,(.+)$/', $base64DataScheme, $matches)) {
                $parts[] = [
                    'inlineData' => [
                        'mimeType' => $matches[1],
                        'data'     => $matches[2],
                    ]
                ];
            }
        }

        // Detection for custom endpoints (Proxy/Localhost)
        $isOfficial = str_contains($this->baseUrl, 'generativelanguage.googleapis.com');

        if (! $isOfficial) {
            return $this->generateTextOpenAICompat($prompt, $options);
        }

        try {
            $requestUrl = $this->baseUrl . "/models/{$this->model}:generateContent?key=" . $this->apiKey;

            $payload = [
                'contents' => [
                    ['role' => 'user', 'parts' => $parts],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => $maxTokens,
                    'temperature'     => (float) ($options['temperature'] ?? 0.9),
                ],
            ];

            if (! empty($options['system_prompt'])) {
                $payload['system_instruction'] = [
                    'parts' => [['text' => $options['system_prompt']]],
                ];
            }

            $response = Http::timeout(12)
                ->retry(1, 500)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($requestUrl, $payload)
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException(
                'Gemini API error: ' . $e->response->json('error.message', $e->getMessage()),
                $e->getCode(),
                $e,
            );
        }

        $body       = $response->json();
        $rawText    = data_get($body, 'candidates.0.content.parts.0.text', '');

        // Parse variants separated by ---
        $variants = $this->parseVariants($rawText);

        // Extract usage
        $tokensPrompt     = (int) data_get($body, 'usageMetadata.promptTokenCount', 0);
        $tokensCompletion = (int) data_get($body, 'usageMetadata.candidatesTokenCount', 0);

        return new GeneratedTextResult(
            variants:          $variants,
            tokensPrompt:      $tokensPrompt,
            tokensCompletion:  $tokensCompletion,
            provider:          $this->providerKey(),
            model:             $this->modelKey(),
        );
    }

    /**
     * Fallback to OpenAI API format for text generation (used for local proxies).
     */
    private function generateTextOpenAICompat(string $prompt, array $options = []): GeneratedTextResult
    {
        $maxTokens = (int) ($options['max_tokens'] ?? 2048);
        $url       = preg_replace('#/(v1|v1beta)(?:/models)?$#', '', $this->baseUrl) . '/v1/chat/completions';

        // --- SMART TEXT MODEL AUTO-SWAP ---
        // If the user mistakenly set an image model as their provider's default model, ensure we use a text model for text tasks.
        $targetModel = $this->model;
        if (str_contains($targetModel, 'image')) {
            $targetModel = 'gemini-3.1-flash'; // Safest fallback text model for Gemini proxies
        }

        $messages = [];
        if (! empty($options['system_prompt'])) {
            $messages[] = ['role' => 'system', 'content' => $options['system_prompt']];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        try {
            $response = Http::timeout(60)
                ->retry(1, 500)
                ->withToken($this->apiKey)
                ->post($url, [
                    'model'       => $targetModel,
                    'messages'    => $messages,
                    'max_tokens'  => $maxTokens,
                    'temperature' => (float) ($options['temperature'] ?? 0.9),
                ])
                ->throw();
        } catch (RequestException $e) {
            $responseBody = $e->response->body();
            $errorMessage = $e->response->json('error.message') 
                ?? $e->response->json('error') 
                ?? $this->extractProxyError($responseBody)
                ?? 'Lỗi kết nối tới Proxy hoặc Google API.';

            throw new RuntimeException(
                "Gemini Proxy Error: {$errorMessage}",
                $e->getCode() ?: 500,
                $e,
            );
        }

        $body     = $response->json();
        $rawText  = data_get($body, 'choices.0.message.content', '');
        $variants = $this->parseVariants($rawText);

        return new GeneratedTextResult(
            variants:          $variants,
            tokensPrompt:      (int) data_get($body, 'usage.prompt_tokens', 0),
            tokensCompletion:  (int) data_get($body, 'usage.completion_tokens', 0),
            provider:          $this->providerKey(),
            model:             $this->modelKey(),
        );
    }

    /**
     * Split LLM output by "---" separator and map to canonical variant shape.
     *
     * @return array<int, array{kind: string, text: string}>
     */
    private function parseVariants(string $raw): array
    {
        $blocks = array_filter(
            array_map('trim', preg_split('/\n?---\n?/', $raw) ?: []),
            fn (string $b) => $b !== '',
        );

        return array_values(array_map(fn (string $text) => [
            'kind' => 'post',
            'text' => $text,
        ], $blocks));
    }

    public function generateMedia(string $prompt, string $type = 'image', array $options = []): GeneratedMediaResult
    {
        if ($type === 'video') {
            return $this->generateVideoAsyncDispatch($prompt, $options);
        }

        // Detection for custom endpoints (Proxy/Localhost)
        $isOfficial = str_contains($this->baseUrl, 'generativelanguage.googleapis.com');

        // --- SMART MODEL AUTO-SWAP ---
        // If the user's primary/default model is a text model, swap it to the correct image model silently.
        // This solves the UI limitation where users can only choose 1 model but tick both "Text" and "Image".
        $targetModel = $this->model;
        if (str_contains($targetModel, 'pro') || (str_contains($targetModel, 'flash') && !str_contains($targetModel, 'image'))) {
            // For proxy, fallback to standard gemini-3.1-flash-image on standard proxies just in case.
            $targetModel = $isOfficial ? 'imagen-3.0-generate-001' : 'gemini-3.1-flash-image';
        }

        if (! $isOfficial) {
            return $this->generateMediaOpenAICompat($prompt, $options, $targetModel);
        }

        // ── Gemini Image Generation via generateContent + responseModalities ──
        try {
            $requestUrl = $this->baseUrl . "/models/{$targetModel}:generateContent?key=" . $this->apiKey;

            $response = Http::timeout(30)
                ->retry(1, 1000)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($requestUrl, [
                    'contents' => [
                        [
                            'role'  => 'user',
                            'parts' => [['text' => $prompt]],
                        ],
                    ],
                    'generationConfig' => [
                        'responseModalities' => ['IMAGE'],
                        'imageConfig' => [
                            'aspectRatio' => $options['aspect_ratio'] ?? '16:9',
                        ],
                    ],
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException(
                'Image generation failed: ' . $e->response->json('error.message', $e->getMessage()),
                $e->getCode(),
                $e,
            );
        }

        $body  = $response->json();
        $parts = data_get($body, 'candidates.0.content.parts', []);

        // Extract images from response parts
        $media = [];
        foreach ($parts as $part) {
            if (isset($part['inlineData']['data'])) {
                $mimeType = $part['inlineData']['mimeType'] ?? 'image/png';
                $media[] = [
                    'type'     => 'image',
                    'url'      => "data:{$mimeType};base64,{$part['inlineData']['data']}",
                    'provider' => $this->providerKey(),
                ];
            }
        }

        if (empty($media)) {
            throw new RuntimeException('Gemini trả về thành công nhưng không có dữ liệu ảnh.');
        }

        $tokensPrompt     = (int) data_get($body, 'usageMetadata.promptTokenCount', 0);
        $tokensCompletion = (int) data_get($body, 'usageMetadata.candidatesTokenCount', 0);

        return new GeneratedMediaResult(
            media:            $media,
            provider:         $this->providerKey(),
            model:            $targetModel,
            tokensPrompt:     $tokensPrompt,
            tokensCompletion: $tokensCompletion,
        );
    }

    /**
     * Fallback for OpenAI-compatible proxies that strictly isolate Text and Image models.
     */
    private function generateMediaOpenAICompat(string $prompt, array $options = [], ?string $targetModel = null): GeneratedMediaResult
    {
        $url = preg_replace('#/(v1|v1beta)(?:/models)?$#', '', $this->baseUrl) . '/v1/images/generations';

        try {
            $response = Http::timeout(120)
                ->retry(1, 1000)
                ->withToken($this->apiKey)
                ->post($url, [
                    'model'  => $targetModel ?? $this->model,
                    'prompt' => mb_substr($prompt, 0, 1000), // OpenAI limits image prompts
                    'n'      => (int) ($options['variant_count'] ?? $options['n'] ?? 1),
                    'size'   => '1024x1024',
                ])
                ->throw();
        } catch (RequestException $e) {
            $responseBody = $e->response->body();
            $errorMessage = $e->response->json('error.message') 
                ?? $e->response->json('error') 
                ?? $this->extractProxyError($responseBody)
                ?? 'Lỗi kết nối tới Proxy hoặc Google API khi tạo ảnh.';

            throw new RuntimeException(
                "Image Generation Failed (Proxy): {$errorMessage}",
                $e->getCode() ?: 500,
                $e,
            );
        }

        $body  = $response->json();
        $data = data_get($body, 'data', []);
        
        $media = array_map(fn($item) => [
            'type'   => 'image',
            'url'    => $item['url'] ?? '',
            'base64' => $item['b64_json'] ?? null,
            'provider' => $this->providerKey(),
        ], $data);

        if (empty($media)) {
            throw new RuntimeException('Proxy returned no images.');
        }

        return new GeneratedMediaResult(
            media:            $media,
            provider:         $this->providerKey(),
            model:            $targetModel ?? $this->modelKey(),
            tokensPrompt:     (int) data_get($body, 'usage.prompt_tokens', 0),
            tokensCompletion: (int) data_get($body, 'usage.completion_tokens', 0),
        );
    }

    // ── Video Generation (Veo) ────────────────────────────────────────────────

    /**
     * Dispatch an async Veo video generation request.
     * Returns a GeneratedMediaResult with meta['task_id'] = operation name.
     * The caller (ContentGenerationService) will dispatch PollGeminiVideoJob.
     */
    private function generateVideoAsyncDispatch(string $prompt, array $options = []): GeneratedMediaResult
    {
        // Smart model swap: if current model is not Veo, use default Veo model.
        $targetModel = $this->isVeoModel($this->model)
            ? $this->model
            : 'veo-3.1-generate-001';

        $config = [
            'durationSeconds'  => (int) ($options['duration_sec'] ?? $options['duration'] ?? 5),
            'aspectRatio'      => $options['aspect_ratio'] ?? '9:16',
            'numberOfVideos'   => 1,
            'personGeneration' => 'dont_allow',
        ];

        if (! empty($options['resolution'])) {
            $config['resolution'] = $options['resolution'];
        }

        if (! empty($options['generate_audio'])) {
            $config['generateAudio'] = (bool) $options['generate_audio'];
        }

        try {
            $requestUrl = $this->baseUrl . "/models/{$targetModel}:generateVideos?key=" . $this->apiKey;

            $response = Http::timeout(30)
                ->retry(1, 1000)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($requestUrl, [
                    'prompt' => $prompt,
                    'config' => $config,
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException(
                'Veo video generation failed: ' . $e->response->json('error.message', $e->getMessage()),
                $e->getCode(),
                $e,
            );
        }

        $body = $response->json();
        $operationName = $body['name'] ?? null;

        if (empty($operationName)) {
            throw new RuntimeException('Veo API trả về thành công nhưng thiếu operation name.');
        }

        return new GeneratedMediaResult(
            media:    [],
            provider: $this->providerKey(),
            model:    $targetModel,
            meta:     ['task_id' => $operationName],
        );
    }

    /**
     * Poll the status of a Veo video generation operation.
     *
     * @return array{status: string, video_url: ?string, progress: ?int, error: ?string}
     */
    public function checkTaskStatus(string $operationName): array
    {
        try {
            $requestUrl = $this->baseUrl . "/{$operationName}?key=" . $this->apiKey;

            $response = Http::timeout(15)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->get($requestUrl)
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException(
                'Veo poll failed: ' . $e->response->json('error.message', $e->getMessage()),
                $e->getCode(),
                $e,
            );
        }

        $body = $response->json();
        $done = (bool) ($body['done'] ?? false);

        if (! $done) {
            return [
                'status'    => 'processing',
                'video_url' => null,
                'progress'  => null,
                'error'     => null,
            ];
        }

        // Check for error
        if (isset($body['error'])) {
            return [
                'status'    => 'failed',
                'video_url' => null,
                'progress'  => null,
                'error'     => $body['error']['message'] ?? 'Veo generation failed.',
            ];
        }

        // Extract video URI — must append API key for download
        $videoUri = data_get($body, 'response.generatedVideos.0.video.uri');

        if (empty($videoUri)) {
            return [
                'status'    => 'failed',
                'video_url' => null,
                'progress'  => null,
                'error'     => 'Operation completed but no video URI returned.',
            ];
        }

        // Append API key so PersistGeneratedMediaAction can download the file
        $downloadUrl = $videoUri . '&key=' . $this->apiKey;

        return [
            'status'    => 'completed',
            'video_url' => $downloadUrl,
            'progress'  => 100,
            'error'     => null,
        ];
    }

    /**
     * Health-check method required by AiSettingsService::testConnection()
     * for providers where supportsAsyncMedia() may return true.
     *
     * Veo models only support /generateVideos — NOT /generateContent.
     * We therefore always ping a lightweight text model for the health-check
     * regardless of which model the user has selected.
     */
    public function checkCredits(): array
    {
        // Veo models do NOT support generateContent, so we use a cheap text model
        // to validate the API key, regardless of the user's selected model.
        $healthClient = new self($this->apiKey, 'gemini-2.0-flash-lite');
        $healthClient->generateText('Reply with only the word: OK', ['max_tokens' => 5]);
        return ['status' => 'ok'];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Helper to cleanly extract text error messages from Proxy's plain text responses.
     */
    private function extractProxyError(string $body): ?string
    {
        if (empty(trim($body))) {
            return null;
        }

        // Check if the proxy returned an upstream JSON error trapped inside text
        // E.g., Upstream error 404 Not Found: { "error": ... }
        // E.g., All accounts exhausted. Last error: HTTP 404: { "error": ... }
        if (preg_match('/(?:Upstream error|Last error).*?: (\{.*\})/is', $body, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (isset($decoded['error']['message'])) {
                return $decoded['error']['message'];
            }
        }

        // Return a clean snippet of the text, removing HTML or overly long content
        $clean = strip_tags($body);
        return substr(trim($clean), 0, 150) . (strlen($clean) > 150 ? '...' : '');
    }
}
