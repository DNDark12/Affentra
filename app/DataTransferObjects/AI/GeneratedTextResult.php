<?php

declare(strict_types=1);

namespace App\DataTransferObjects\AI;

/**
 * Structured result returned by AIProviderClient::generateText().
 */
readonly class GeneratedTextResult
{
    /**
     * @param  array<int, array{kind: string, text: string}> $variants
     *         Canonical shape: [ { kind: 'caption'|'post'|'hashtags'|'script', text: '...' } ]
     */
    public function __construct(
        public readonly array $variants,
        public readonly int   $tokensPrompt = 0,
        public readonly int   $tokensCompletion = 0,
        public readonly string $provider = '',
        public readonly string $model = '',
    ) {}

    /**
     * Serialize to the canonical output_payload shape stored in DB.
     *
     * @return array{variants: array<int, array{kind: string, text: string}>}
     */
    public function toPayload(): array
    {
        return ['variants' => $this->variants];
    }
}
