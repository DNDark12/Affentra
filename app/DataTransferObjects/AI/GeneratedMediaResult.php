<?php

declare(strict_types=1);

namespace App\DataTransferObjects\AI;

/**
 * Structured result returned by AIProviderClient::generateMedia().
 * Used for Image or Video generation.
 */
readonly class GeneratedMediaResult
{
    /**
     * @param  array<int, array{url: string, base64?: string}> $media
     */
    public function __construct(
        public array  $media,
        public int    $tokensPrompt = 0,
        public int    $tokensCompletion = 0,
        public string $provider = '',
        public string $model = '',
    ) {}

    /**
     * @return array{media: array<int, array{url: string, base64?: string}>}
     */
    public function toPayload(): array
    {
        return ['media' => $this->media];
    }
}
