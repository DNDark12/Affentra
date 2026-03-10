<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\ContentGeneration;
use App\Services\AI\Contracts\AIProviderClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Runs text generation synchronously within the HTTP request lifecycle.
 *
 * Lifecycle: running → succeeded | failed
 * No Job queue involved for Text V1.
 */
class TextGenerationRunner
{
    public function __construct(
        private readonly AIProviderClient $client,
    ) {}

    /**
     * Execute synchronous text generation.
     *
     * Updates the ContentGeneration row in-place and returns it.
     */
    public function run(
        ContentGeneration $generation,
        string $modality,
        string $renderedPrompt,
        ?string $systemPrompt = null
    ): ContentGeneration {
        try {
            $options = [
                'max_tokens'    => 2048,
                'temperature'   => 0.9,
                'images'        => $generation->prompt_attributes['images'] ?? [],
                'system_prompt' => $systemPrompt,
            ];

            // 1. Call AI provider
            $result = $this->client->generateText($renderedPrompt, $options);

            // 2. Append to existing or new payload
            $currentOutput = $generation->output_payload ?: [];
            $textResult    = $result->toPayload();

            // Merge everything (text, suggestions, tokens, etc.)
            $newOutput = array_merge($currentOutput, $textResult);

            // 3. Persist successful result
            $generation->update([
                'status'            => 'succeeded',
                'output_payload'    => $newOutput,
                'ai_provider'       => $result->provider,
                'ai_model'          => $result->model,
                'tokens_prompt'     => ($generation->tokens_prompt ?? 0) + $result->tokensPrompt,
                'tokens_completion' => ($generation->tokens_completion ?? 0) + $result->tokensCompletion,
            ]);

        } catch (RuntimeException $e) {
            Log::error('TextGenerationRunner: provider failed', [
                'generation_id' => $generation->id,
                'error_class'   => $e::class,
                'message'       => $e->getMessage(),
            ]);

            $generation->update([
                'status'        => 'failed',
                'error_code'    => 'PROVIDER_ERROR',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $generation->refresh();
    }
}
