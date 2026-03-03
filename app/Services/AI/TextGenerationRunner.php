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
        private readonly AIProviderClient      $client,
        private readonly PromptTemplateRegistry $registry,
    ) {}

    /**
     * Execute synchronous text generation.
     *
     * Updates the ContentGeneration row in-place and returns it.
     */
    public function run(ContentGeneration $generation): ContentGeneration
    {
        try {
            // 1. Render prompt from template registry
            $prompt = $this->registry->render(
                $generation->prompt_template_id,
                $generation->prompt_attributes ?? [],
            );

            // 2. Call AI provider
            $result = $this->client->generateText($prompt, [
                'max_tokens'  => 2048,
                'temperature' => 0.9,
                'images'      => $generation->prompt_attributes['images'] ?? [],
            ]);

            // 3. Persist successful result
            $generation->update([
                'status'            => 'succeeded',
                'output_payload'    => $result->toPayload(),
                'ai_provider'       => $result->provider,
                'ai_model'          => $result->model,
                'tokens_prompt'     => $result->tokensPrompt,
                'tokens_completion' => $result->tokensCompletion,
            ]);

        } catch (RuntimeException $e) {
            Log::error('TextGenerationRunner: provider failed', [
                'generation_id' => $generation->id,
                'error_class'   => $e::class,
                'message'       => $e->getMessage(),
                // Intentionally NOT logging prompt_attributes to avoid PII leakage
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
