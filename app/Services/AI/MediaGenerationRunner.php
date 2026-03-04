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
        private readonly PromptTemplateRegistry $registry,
    ) {}

    /**
     * Run the media generation.
     */
    public function run(ContentGeneration $generation): ContentGeneration
    {
        $templateId = $generation->prompt_template_id;
        $attributes = $generation->prompt_attributes ?: [];
        $type       = $generation->type; // 'image' or 'video'

        try {
            // Render the prompt
            $prompt = $this->registry->render($templateId, $attributes);

            // Execute via provider client
            $result = $this->client->generateMedia($prompt, $type, $attributes);

            // Update generation record
            $generation->update([
                'status'            => 'succeeded',
                'output_payload'    => $result->toPayload(),
                'tokens_prompt'     => $result->tokensPrompt,
                'tokens_completion' => $result->tokensCompletion,
                'ai_provider'       => $result->provider,
                'ai_model'          => $result->model,
            ]);

        } catch (Exception $e) {
            Log::error('ai.media_generation_failed', [
                'generation_id' => $generation->id,
                'error'         => $e->getMessage(),
                'template'      => $templateId,
            ]);

            $generation->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $generation;
    }

    /**
     * Run media generation and append to existing text generation output.
     */
    public function runMediaEnrichment(ContentGeneration $generation, string $type): ContentGeneration
    {
        $templateId = $generation->prompt_template_id;
        $attributes = $generation->prompt_attributes ?: [];

        try {
            $mediaTemplateId = "{$templateId}_{$type}";
            if ($this->registry->supports($mediaTemplateId, $type)) {
                $prompt = $this->registry->render($mediaTemplateId, $attributes);
            } else {
                // Fallback to a generic basic prompt if a specialized media prompt was not registered
                $productTitle = $attributes['product_title'] ?? 'sản phẩm';
                $prompt = "Tạo một hình ảnh/media đẹp mắt minh hoạ cho sản phẩm: {$productTitle}";
            }

            // Execute via provider client
            // We pass the type ('image' or 'video') explicitly here
            $result = $this->client->generateMedia($prompt, $type, $attributes);

            $currentOutput = $generation->output_payload ?: [];
            $mediaResult = $result->toPayload();
            
            // Append generated media to the 'media' array in output_payload
            $existingMedia = $currentOutput['media'] ?? [];
            if (isset($mediaResult['media']) && is_array($mediaResult['media'])) {
                $existingMedia = array_merge($existingMedia, $mediaResult['media']);
            } elseif (isset($mediaResult['url'])) {
                $existingMedia[] = [
                    'type' => $type,
                    'url'  => $mediaResult['url'],
                    'provider' => $result->provider,
                ];
            }

            $currentOutput['media'] = $existingMedia;

            // Update generation record with additive usage
            $generation->update([
                'output_payload'    => $currentOutput,
                'tokens_prompt'     => ($generation->tokens_prompt ?? 0) + ($result->tokensPrompt ?? 0),
                'tokens_completion' => ($generation->tokens_completion ?? 0) + ($result->tokensCompletion ?? 0),
                // We keep the primary text provider/model for the record or we could track multiple
            ]);

        } catch (Exception $e) {
            Log::error('ai.media_enrichment_failed', [
                'generation_id' => $generation->id,
                'error'         => $e->getMessage(),
                'type'          => $type,
            ]);
            // Don't fail the generation, just log it
        }

        return $generation;
    }
}
