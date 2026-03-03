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
 * OpenAI-Compatible API adapter.
 *
 * Works with:
 *  - OpenAI (api.openai.com)
 *  - Self-hosted: Ollama, LM Studio, vLLM, LocalAI, LiteLLM, etc.
 *    Any server exposing POST /v1/chat/completions
 *
 * For self-hosted:
 *   base_url = http://127.0.0.1:8045
 *   api_key  = sk-dd9760f89f424b2c86b92464c27215f0 (or 'ollama' for Ollama)
 *
 * For OpenAI:
 *   base_url = null (uses https://api.openai.com/v1)
 *   api_key  = sk-...
 */
class OpenAICompatibleClient implements AIProviderClient
{
    private const OPENAI_BASE = 'https://api.openai.com/v1';
    private const DEFAULT_MODEL = 'gpt-4o-mini';

    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private string $provider;

    public function __construct(
        ?string $apiKey     = null,
        ?string $baseUrl    = null,
        ?string $model      = null,
        string  $providerKey = 'openai',
    ) {
        $this->apiKey   = $apiKey ?? '';
        $rawUrl         = $this->remapLocalhost(rtrim($baseUrl ?? self::OPENAI_BASE, '/'));
        // Ensure the base URL always ends with /v1 (users often omit it)
        $this->baseUrl  = preg_replace('#/v1$#', '', $rawUrl) . '/v1';
        $this->model    = $model ?? self::DEFAULT_MODEL;
        $this->provider = $providerKey;
    }

    /**
     * When running inside Docker, `127.0.0.1` and `localhost` refer to the
     * container itself, not the host machine. Transparently remap them to
     * `host.docker.internal` (Mac/Windows Docker Desktop) or to the value
     * of DOCKER_HOST_GATEWAY env var (useful for Linux: 172.17.0.1).
     *
     * The remap is skipped when APP_ENV=testing to avoid breaking test fakes.
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
        return $this->provider;
    }

    public function modelKey(): string
    {
        return $this->model;
    }

    /**
     * @throws RuntimeException on provider failure or missing config
     */
    public function generateText(string $prompt, array $options = []): GeneratedTextResult
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException(
                "API key chưa được cấu hình cho provider '{$this->provider}'. Vào Cài đặt → AI để thêm."
            );
        }

        $maxTokens = (int) ($options['max_tokens'] ?? 2048);

        try {
            $response = Http::timeout(12)
                ->retry(1, 500)
                ->withToken($this->apiKey)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $this->model,
                    'messages'    => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens'  => $maxTokens,
                    'temperature' => (float) ($options['temperature'] ?? 0.9),
                ])
                ->throw();
        } catch (RequestException $e) {
            $errMsg = $e->response?->json('error.message') ?? $e->getMessage();
            throw new RuntimeException(
                "Provider '{$this->provider}' error: {$errMsg}",
                $e->getCode(),
                $e,
            );
        }

        $body    = $response->json();
        $rawText = data_get($body, 'choices.0.message.content', '');

        $variants = $this->parseVariants($rawText);

        $tokensPrompt     = (int) data_get($body, 'usage.prompt_tokens', 0);
        $tokensCompletion = (int) data_get($body, 'usage.completion_tokens', 0);

        return new GeneratedTextResult(
            variants:         $variants,
            tokensPrompt:     $tokensPrompt,
            tokensCompletion: $tokensCompletion,
            provider:         $this->providerKey(),
            model:            $this->modelKey(),
        );
    }

    /**
     * Split output by "---" delimiter into canonical variant objects.
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
        if (empty($this->apiKey)) {
            throw new \RuntimeException("API key not configured.");
        }

        // Default OpenAI image generation endpoint
        $endpoint = "{$this->baseUrl}/images/generations";
        $payload = [
            'model'  => $options['model'] ?? 'dall-e-3',
            'prompt' => $prompt,
            'n'      => (int) ($options['n'] ?? 1),
            'size'   => $options['size'] ?? '1024x1024',
        ];

        try {
            $response = Http::timeout(60) // Images take longer
                ->withToken($this->apiKey)
                ->post($endpoint, $payload)
                ->throw();
        } catch (RequestException $e) {
            $errMsg = $e->response?->json('error.message') ?? $e->getMessage();
            throw new \RuntimeException("Image generation failed: {$errMsg}");
        }

        $body = $response->json();
        $data = data_get($body, 'data', []);
        
        $media = array_map(fn($item) => [
            'url'    => $item['url'] ?? '',
            'base64' => $item['b64_json'] ?? null,
        ], $data);

        return new GeneratedMediaResult(
            media:    $media,
            provider: $this->providerKey(),
            model:    (string) $payload['model'],
        );
    }
}
