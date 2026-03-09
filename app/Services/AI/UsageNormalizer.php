<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Normalizes usage metadata from different AI providers.
 */
class UsageNormalizer
{
    /**
     * Normalize token usage from provider response.
     *
     * @param array<string, mixed> $rawUsage
     * @return array{prompt: int, completion: int, total: int}
     */
    public function normalizeTokens(array $rawUsage): array
    {
        // Field mapping variants
        $prompt = (int) ($rawUsage['prompt_tokens'] 
            ?? $rawUsage['input_tokens'] 
            ?? $rawUsage['tokens_prompt'] 
            ?? 0);

        $completion = (int) ($rawUsage['completion_tokens'] 
            ?? $rawUsage['output_tokens'] 
            ?? $rawUsage['tokens_completion'] 
            ?? 0);

        $total = (int) ($rawUsage['total_tokens'] 
            ?? $rawUsage['tokens_total'] 
            ?? ($prompt + $completion));

        // If total is provided but prompt/completion aren't, keep it as total
        if ($total > 0 && $prompt === 0 && $completion === 0) {
            // We don't synthesize prompt=total because it's misleading
        }

        return [
            'prompt'     => $prompt,
            'completion' => $completion,
            'total'      => $total,
        ];
    }
}
