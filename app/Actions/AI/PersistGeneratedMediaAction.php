<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\ContentGeneration;
use App\Services\AI\MediaStorageService;
use App\Services\AI\UsageNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Unified action to persist generated media and clean up raw database payloads.
 */
class PersistGeneratedMediaAction
{
    public function __construct(
        private readonly MediaStorageService $storageService,
        private readonly UsageNormalizer $usageNormalizer,
    ) {}

    /**
     * Persist remote media assets and strip raw/base64 data from the database.
     *
     * @param array<string, mixed> $usageRaw Optional usage metadata from provider
     */
    public function execute(ContentGeneration $generation, array $usageRaw = []): ContentGeneration
    {
        return DB::transaction(function () use ($generation, $usageRaw) {
            $generation->refresh();
            
            $payload = $generation->output_payload ?: [];
            $mediaItems = $payload['media'] ?? [];

            // Compatibility: root-level 'url' in payload
            if (empty($mediaItems) && isset($payload['url'])) {
                $mediaItems[] = [
                    'type' => $generation->type,
                    'url'  => $payload['url'],
                    'provider' => $generation->ai_provider,
                ];
            }

            $updatedMedia = [];
            foreach ($mediaItems as $item) {
                // Idempotency: skip if already normalized with localized storage
                if (isset($item['storage']) && !empty($item['storage']['path'])) {
                    $updatedMedia[] = $item;
                    continue;
                }

                $remoteUrl = $item['url'] ?? $item['remote_url'] ?? null;
                $base64    = $item['base64'] ?? $item['b64_json'] ?? null;

                try {
                    $storageMetadata = null;

                    if ($remoteUrl) {
                        $storageMetadata = $this->storageService->download($remoteUrl, "gen_{$generation->id}");
                    } elseif ($base64) {
                        if (str_contains($base64, ';base64,')) {
                            $base64 = explode(';base64,', $base64)[1];
                        }
                        $content = base64_decode($base64, true);
                        if ($content !== false) {
                            $storageMetadata = $this->storageService->store($content, "gen_{$generation->id}");
                        }
                    }

                    if ($storageMetadata) {
                        // Standardized schema normalization
                        $item['storage'] = [
                            'disk'      => $storageMetadata['disk'],
                            'path'      => $storageMetadata['path'],
                            'mime_type' => $storageMetadata['mime_type'],
                            'size'      => $storageMetadata['size'],
                            'visibility'=> $storageMetadata['visibility'] ?? 'private',
                        ];

                        // Delivery URL resolution (Service abstracted)
                        $item['delivery'] = [
                            'playback_url' => $this->storageService->resolveDeliveryUrl($storageMetadata),
                        ];
                        
                        // Keep remote_url for reference
                        if ($remoteUrl) {
                            $item['remote_url'] = $remoteUrl;
                        }

                        // ATOMIC CLEANUP: Strip large raw data ONLY after storage succeeds
                        unset($item['url'], $item['base64'], $item['b64_json'], $item['local']);
                    }
                } catch (\Exception $e) {
                    Log::error('ai.persist_media_failed', [
                        'generation_id' => $generation->id,
                        'url'           => $remoteUrl,
                        'has_base64'    => !empty($base64),
                        'error'         => $e->getMessage(),
                    ]);
                    // Raw data is RETAINED on failure for manual retry/recovery
                }

                $updatedMedia[] = $item;
            }

            $payload['media'] = $updatedMedia;
            
            // Final cleanup of legacy keys at root level if they were moved into standardized media array
            unset($payload['url'], $payload['base64']);

            $updateData = [
                'output_payload' => $payload,
            ];

            if (!empty($usageRaw)) {
                $usage = $this->usageNormalizer->normalizeTokens($usageRaw);
                $updateData['tokens_prompt']     = $usage['prompt'] ?: $generation->tokens_prompt;
                $updateData['tokens_completion'] = $usage['completion'] ?: $generation->tokens_completion;
            }

            $generation->update($updateData);

            return $generation;
        });
    }
}

