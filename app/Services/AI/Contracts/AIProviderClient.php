<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

use App\DataTransferObjects\AI\GeneratedTextResult;

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
     * Provider identifier key, e.g. "gemini", "openai".
     */
    public function providerKey(): string;

    /**
     * Model being used for generation, e.g. "gemini-1.5-flash".
     */
    public function modelKey(): string;
}
