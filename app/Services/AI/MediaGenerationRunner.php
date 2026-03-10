<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\ContentGeneration;
use App\Services\AI\Contracts\AIProviderClient;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Executes Image/Video generation tasks.
 */
class MediaGenerationRunner
{
    public function __construct(
        private readonly AIProviderClient $client,
        private readonly \App\Actions\AI\PersistGeneratedMediaAction $persistAction,
    ) {}

    /**
     * Run the media generation.
     */
    public function run(
        ContentGeneration $generation,
        string $modality,
        string $renderedPrompt,
        ?string $systemPrompt = null
    ): ContentGeneration {
        try {
            $options = $generation->prompt_attributes ?: [];
            $options['system_prompt'] = $systemPrompt;

            // Execute via provider client
            $result = $this->client->generateMedia($renderedPrompt, $modality, $options);

            // Append generated media to the 'media' array in output_payload
            $currentOutput = $generation->output_payload ?: [];
            $mediaResult = $result->toPayload();
            
            $existingMedia = $currentOutput['media'] ?? [];
            if (isset($mediaResult['media']) && is_array($mediaResult['media'])) {
                $existingMedia = array_merge($existingMedia, $mediaResult['media']);
            } elseif (isset($mediaResult['url'])) {
                $existingMedia[] = [
                    'type' => $modality,
                    'url'  => $mediaResult['url'],
                    'provider' => $result->provider,
                ];
            }

            $currentOutput['media'] = $existingMedia;

            // Update generation record
            $generation->update([
                'status'            => 'succeeded',
                'output_payload'    => $currentOutput,
                'tokens_prompt'     => ($generation->tokens_prompt ?? 0) + ($result->tokensPrompt ?? 0),
                'tokens_completion' => ($generation->tokens_completion ?? 0) + ($result->tokensCompletion ?? 0),
                'ai_provider'       => $result->provider, // Only updates if we are treating this as primary
                'ai_model'          => $result->model,
            ]);

            // Persist locally
            $this->persistAction->execute($generation, [
                'prompt_tokens'     => $result->tokensPrompt ?? 0,
                'completion_tokens' => $result->tokensCompletion ?? 0,
            ]);

        } catch (Exception $e) {
            Log::error('ai.media_generation_failed', [
                'generation_id' => $generation->id,
                'error'         => $e->getMessage(),
                'modality'      => $modality,
            ]);

            $generation->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $generation;
    }
}
