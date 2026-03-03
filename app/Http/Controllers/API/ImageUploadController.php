<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUploadController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:5120'], // Max 5MB
        ]);

        $file = $request->file('image');
        
        // Generate a random, safe filename
        $extension = $file->getClientOriginalExtension() ?: 'png';
        $filename = Str::random(40) . '.' . $extension;
        
        // Store in the public disk under a specific folder
        $path = $file->storeAs('uploads/ai', $filename, 'public');

        if (!$path) {
            return ApiResponse::error('Failed to upload image.', [], 500);
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        $url = $disk->url($path);

        return ApiResponse::success([
            'url' => $url,
            'path' => $path,
        ], 'Image uploaded successfully.', 201);
    }
}
