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
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        // Prefer injected credentials (from AiSettingsService/DB), fall back to .env for backwards compat
        $this->apiKey = $apiKey ?? (string) config('ai.providers.gemini.key');
        $this->model  = $model  ?? (string) (config('ai.providers.gemini.model') ?? 'gemini-1.5-flash');

        if (empty($this->apiKey)) {
            throw new \RuntimeException(
                "Gemini API key chưa được cấu hình. Vào Cài đặt → AI để thêm."
            );
        }
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

        try {
            $response = Http::timeout(12)
                ->retry(1, 500)
                ->withQueryParameters(['key' => $this->apiKey])
                ->post(self::BASE_URL . "/{$this->model}:generateContent", [
                    'contents' => [
                        ['role' => 'user', 'parts' => $parts],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => $maxTokens,
                        'temperature'     => (float) ($options['temperature'] ?? 0.9),
                    ],
                ])
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
        // Currently Gemini Image Generation (Imagen) often requires a different endpoint or Google Cloud Vertex AI.
        // For 'gemini-1.5-flash/pro', they are mostly multimodal INPUT, not OUTPUT.
        // We throw a clear error if not supported, or implement if a specific model is detected.
        
        throw new \RuntimeException(
            "Provider 'gemini' hiện chưa hỗ trợ tạo {$type} trực tiếp qua endpoint này. " .
            "Hãy sử dụng các provider OpenAI-compatible hoặc Local."
        );
    }
}
