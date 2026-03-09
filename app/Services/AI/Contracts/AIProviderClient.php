<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

use App\DataTransferObjects\AI\GeneratedTextResult;
use App\DataTransferObjects\AI\GeneratedMediaResult;

interface AIProviderClient
{
    /**
     * Generate text variants from a rendered prompt.
     *
     * @param  string $prompt       Fully rendered prompt string (built by PromptTemplateRegistry).
     * @param  array  $options      Provider-specific options (max_tokens, temperature, etc.)
     * @return GeneratedTextResult
     */
    public function generateText(string $prompt, array $options = []): GeneratedTextResult;

    /**
     * Generate image or video from a prompt.
     *
     * @param  string $prompt
     * @param  string $type         'image' or 'video'
     * @param  array  $options      Provider-specific options (size, quality, etc.)
     * @return GeneratedMediaResult
     */
    public function generateMedia(string $prompt, string $type = 'image', array $options = []): GeneratedMediaResult;

    /**
     * Provider identifier key, e.g. "gemini", "openai".
     */
    public function providerKey(): string;

    /**
     * Model being used for generation, e.g. "gemini-1.5-flash".
     */
    public function modelKey(): string;

    /**
     * Whether this provider handles media generation asynchronously.
     *
     * Async providers return a task_id instead of immediate results,
     * and require polling to retrieve the finished output.
     */
    public function supportsAsyncMedia(): bool;

    /**
     * Check if this provider supports a specific capability (text, image, video).
     */
    public function supportsCapability(string $capability): bool;

    /**
     * Check if this provider supports native system prompt for a modality.
     */
    public function supportsNativeSystemPrompt(string $modality): bool;
}
