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
     */
    public function generate(GenerateContentRequest $request, TrackingLink $link): JsonResponse
    {
        // --- Idempotency check ---
        $idempKey = $request->header('Idempotency-Key');
        if ($idempKey) {
            $storeKey = 'ai:idem:' . auth()->id() . ':' . $idempKey;
            $existing = Redis::get($storeKey);

            if ($existing) {
                return response()->json(json_decode($existing, true), 200);
            }
        }

        // --- Scope check: link must belong to the resolved workspace/user scope ---
        if ((int) $link->user_id !== (int) auth()->id()) {
            abort(404);
        }

        // --- Execute generation ---
        $generation = $this->service->generate(
            link:    $link,
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
                'generation_id' => $generation->id,
                'output'        => $generation->output_payload,
                'from_cache'    => $generation->from_cache,
                'usage'         => $usage,
            ],
            'message'       => $generation->status === 'failed' ? $generation->error_message : null,
            'errors'        => null,
        ];

        $statusCode = $generation->status === 'succeeded' ? 200 : 500;

        // --- Store idempotency response ---
        if ($idempKey && $generation->status === 'succeeded') {
            $storeKey = 'ai:idem:' . auth()->id() . ':' . $idempKey;
            Redis::setex($storeKey, 300, json_encode($responseBody));
        }

        return response()->json($responseBody, $statusCode);
    }

    /**
     * GET /api/links/{trackingLink}/content/history
     */
    public function history(Request $request, TrackingLink $link): JsonResponse
    {
        if ($link->user_id !== auth()->id()) {
            abort(404);
        }

        $generations = $link->contentGenerations()
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'ok'   => true,
            'data' => $generations,
        ]);
    }
}
