<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUploadController extends Controller
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
        $url = route('api.ai.image.serve', ['filename' => $filename]);

        return ApiResponse::success([
            'url'  => $url,
            'path' => $path,
        ], 'Image uploaded successfully.', 201);
    }

    /**
     * Serve a privately stored AI upload image.
     * Route: GET /api/ai/images/{filename}
     */
    public function serve(Request $request, string $filename): \Illuminate\Http\Response
    {
        // Sanitize filename — no directory traversal
        $filename = basename($filename);
        $path     = 'uploads/ai/' . $filename;

        abort_unless(Storage::disk('local')->exists($path), 404);

        $contents = Storage::disk('local')->get($path);
        $absPath  = Storage::disk('local')->path($path);
        $mimeType = mime_content_type($absPath) ?: 'application/octet-stream';

        return response((string) $contents, 200, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => 'inline',
            'Cache-Control'       => 'private, max-age=7200',
        ]);
    }
}
