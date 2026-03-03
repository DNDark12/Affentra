<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\AI\GenerateContentRequest;
use App\Models\TrackingLink;
use App\Services\AI\ContentGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ContentGenerationController
{
    public function __construct(
        private readonly ContentGenerationService $service,
    ) {}

    /**
     * POST /api/links/{trackingLink}/content/generate
     *
     * Idempotency-Key header: optional UUID from FE; prevents double-submit.
     * Only reused for network timeouts (Retry). Regenerate uses a new key.
     */
    public function generate(GenerateContentRequest $request, TrackingLink $trackingLink): JsonResponse
    {
        // --- Idempotency check ---
        $idempKey = $request->header('Idempotency-Key');
        if ($idempKey) {
            $storeKey = 'ai:idem:' . $request->user()->id . ':' . $idempKey;
            $existing = Redis::get($storeKey);

            if ($existing) {
                // If it exists, return the cached successful response directly
                return response()->json(json_decode($existing, true), 200);
            }
        }

        // --- Scope check: link must belong to the resolved workspace/user scope ---
        if ((int) $trackingLink->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        // --- Execute generation ---
        $generation = $this->service->generate(
            link:    $trackingLink,
            user:    $request->user(),
            payload: $request->validated(),
        );

        // --- Build response ---
        $usage = $generation->status === 'succeeded' && ! $generation->from_cache
            ? [
                'tokens_prompt'     => $generation->tokens_prompt,
                'tokens_completion' => $generation->tokens_completion,
                'model'             => $generation->ai_model,
                'provider'          => $generation->ai_provider,
            ]
            : null;

        $responseBody = [
            'ok'            => $generation->status === 'succeeded',
            'data'          => [
                'status'        => $generation->status,
                'provider_used' => $generation->ai_provider,
                'model_used'    => $generation->ai_model,
                'generation_id' => $generation->id,
                'output'        => $generation->output_payload,
                'from_cache'    => $generation->from_cache,
                'usage'         => $usage,
            ],
            'message'       => $generation->status === 'failed' ? $generation->error_message : null,
            'errors'        => null,
        ];

        $statusCode = $generation->status === 'succeeded' ? 200 : 422; // 422 for generation failure matches standard

        // --- Store idempotency response ---
        // Only cache successful requests. If it failed, allow retry with same ID to attempt again, or FE can generate new
        if ($idempKey && $generation->status === 'succeeded') {
            $storeKey = 'ai:idem:' . $request->user()->id . ':' . $idempKey;
            Redis::setex($storeKey, 300, json_encode($responseBody));
        }

        return response()->json($responseBody, $statusCode);
    }

    /**
     * GET /api/links/{trackingLink}/content/history
     * Lightweight list of generations.
     */
    public function history(Request $request, TrackingLink $trackingLink): JsonResponse
    {
        if ((int) $trackingLink->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $generations = $trackingLink->contentGenerations()
            ->select(['id', 'tracking_link_id', 'status', 'from_cache', 'error_code', 'error_message', 'created_at', 'output_payload', 'prompt_template_id'])
            ->orderByDesc('created_at')
            ->paginate(20);

        // Transform for lightweight history
        $generations->getCollection()->transform(function ($gen) {
            $preview = '';
            if ($gen->status === 'succeeded' && !empty($gen->output_payload['variants'])) {
                $preview = mb_substr(strip_tags($gen->output_payload['variants'][0]['text'] ?? ''), 0, 100) . '...';
            }

            return [
                'id' => $gen->id,
                'status' => $gen->status,
                'from_cache' => $gen->from_cache,
                'preset_id' => $gen->prompt_template_id,
                'preview_text' => $preview,
                'error_code' => $gen->error_code,
                'error_message_short' => $gen->error_message ? mb_substr($gen->error_message, 0, 50) : null,
                'created_at' => $gen->created_at,
            ];
        });

        return response()->json([
            'ok'   => true,
            'data' => $generations,
        ]);
    }

    /**
     * GET /api/content-generations/{id}
     * Full details for loading into Draft editor.
     */
    public function show(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        $generation = \App\Models\ContentGeneration::findOrFail($id);

        if ($generation->user_id !== $request->user()->id) {
            abort(404);
        }

        $usage = $generation->status === 'succeeded' && ! $generation->from_cache
            ? [
                'tokens_prompt'     => $generation->tokens_prompt,
                'tokens_completion' => $generation->tokens_completion,
                'model'             => $generation->ai_model,
                'provider'          => $generation->ai_provider,
            ]
            : null;

        return response()->json([
            'ok' => true,
            'data' => [
                'status'        => $generation->status,
                'provider_used' => $generation->ai_provider,
                'model_used'    => $generation->ai_model,
                'from_cache'    => $generation->from_cache,
                'generation_id' => $generation->id,
                'output'        => $generation->output_payload,
                'usage'         => $usage,
                'created_at'    => $generation->created_at,
                // Passing prompt_attributes helps FE restore the form preset correctly
                'preset_id'         => $generation->prompt_template_id,
                'prompt_attributes' => $generation->prompt_attributes,
            ]
        ]);
    }
}
