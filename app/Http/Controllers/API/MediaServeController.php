<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaServeController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'image' => [
                'required',
                'file',
                'max:5120',                               // Max 5 MB
                'mimetypes:image/jpeg,image/png,image/webp,image/gif',
            ],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('image');

        // ─── Signature check: verify the file can be decoded as a real image ───
        $tmpPath = $file->getRealPath();
        if ($tmpPath === false || ! @getimagesize($tmpPath)) {
            return ApiResponse::error('Uploaded file is not a valid image.', [], 422);
        }

        // ─── Derive extension from actual MIME, never trust the client ─────────
        $extension = $file->guessExtension() ?? 'bin';
        $filename  = Str::random(40) . '.' . $extension;

        // ─── Store in private disk (not publicly executable) ──────────────────
        $path = $file->storeAs('uploads/ai', $filename, 'local');

        if (! $path) {
            return ApiResponse::error('Failed to upload image.', [], 500);
        }

        // ─── Serve via a signed URL (expires in 2 hours) ──────────────────────
        $url = route('api.ai.media.serve', ['filename' => $filename]);

        return ApiResponse::success([
            'url'  => $url,
            'path' => $path,
        ], 'Image uploaded successfully.', 201);
    }

    /**
     * Serve a privately stored AI media file (image or video).
     * Route: GET /api/ai/media/{filename}
     */
    public function serve(Request $request, string $filename): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        // ─── 1. Sanitize & Locate ───────────────────────────────────────────
        $filename = basename($filename);
        $path     = 'ai_assets/' . $filename;
        $disk     = Storage::disk('local');

        if (!$disk->exists($path)) {
            // Fallback for legacy uploads if any
            $path = 'uploads/ai/' . $filename;
            if (!$disk->exists($path)) {
                abort(404);
            }
        }

        $absPath = $disk->path($path);

        // ─── 2. Authorization (Tenant Isolation) ────────────────────────────
        if (preg_match('/^gen_(\d+)_/', $filename, $matches)) {
            $generationId = $matches[1];
            $generation = \App\Models\ContentGeneration::find($generationId);
            
            if ($generation && (int) $generation->user_id !== (int) $request->user()->id) {
                Log::warning('ai.media_serve.unauthorized', [
                    'user_id' => $request->user()->id,
                    'gen_user_id' => $generation->user_id,
                    'file' => $filename
                ]);
                abort(403, 'You do not have permission to access private media.');
            }
        }

        // ─── 3. Serve with Range Support ────────────────────────────────────
        return response()->file($absPath, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
