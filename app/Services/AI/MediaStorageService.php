<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Service to download and validate AI-generated media from remote providers.
 */
class MediaStorageService
{
    private const ALLOWED_HOSTS = [
        'seedance2.app',
        'googleusercontent.com',
        'openai.com',
        'seedance-api.s3.amazonaws.com',
        'amazonaws.com',
    ];

    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'video/mp4',
        'video/quicktime', // .mov
    ];

    private const MAX_FILE_SIZE = 52428800; // 50MB

    private string $disk;

    public function __construct()
    {
        $this->disk = config('affentra.ai_media_disk', 'ai_media');
    }

    /**
     * Download a remote asset and store it.
     *
     * @return array{disk: string, path: string, mime_type: string, size: int, hash: string, visibility: string, original_url: string}
     */
    public function download(string $url, string $prefix = 'ai'): array
    {
        $this->validateUrl($url);

        $response = Http::timeout(60)->get($url);

        if (!$response->successful()) {
            throw new RuntimeException("Failed to download media from: {$url}");
        }

        $content = $response->body();
        
        $data = $this->store($content, $prefix);
        $data['original_url'] = $url;

        return $data;
    }

    /**
     * Store raw media content.
     * 
     * @return array{disk: string, path: string, mime_type: string, size: int, hash: string, visibility: string, stored_at: string}
     */
    public function store(string $content, string $prefix = 'ai'): array
    {
        $size = strlen($content);

        if ($size > self::MAX_FILE_SIZE) {
            throw new RuntimeException("Media file too large: " . round($size / 1024 / 1024, 2) . "MB");
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($content);

        if (!in_array($mime, self::ALLOWED_MIMES)) {
            Log::warning('ai.media_storage.invalid_mime', ['mime' => $mime]);
            throw new RuntimeException("Unsupported media type: {$mime}");
        }

        $extension = $this->getExtensionForMime($mime);
        $hash = sha1($content);
        $filename = "{$prefix}_{$hash}." . $extension;
        $path = $filename; // Store directly in the root of the disk (which is already /ai_assets)

        Storage::disk($this->disk)->put($path, $content);

        return [
            'disk'         => $this->disk,
            'path'         => $path,
            'mime_type'    => $mime,
            'size'         => $size,
            'hash'         => $hash,
            'visibility'   => 'private', // Currently private by policy
            'stored_at'    => now()->toIso8601String(),
        ];
    }

    /**
     * Resolve the delivery URL for a stored asset.
     */
    public function resolveDeliveryUrl(array $metadata): string
    {
        $diskName = $metadata['disk'] ?? $this->disk;
        $path     = $metadata['path'] ?? '';

        if (empty($path)) {
            return '';
        }

        $disk = Storage::disk($diskName);

        // If it's a future public S3 disk, return public URL
        if ($diskName === 's3' && config("filesystems.disks.{$diskName}.visibility") === 'public') {
            return $disk->url($path);
        }

        // For private assets or local disk, use our signed serve route
        return route('api.ai.media.serve', ['filename' => basename($path)]);
    }

    private function validateUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        
        if (!$host) {
            throw new RuntimeException("Invalid URL: {$url}");
        }

        $isAllowed = false;
        foreach (self::ALLOWED_HOSTS as $allowed) {
            if (str_ends_with($host, $allowed)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            Log::warning('ai.media_storage.unauthorized_host', ['host' => $host, 'url' => $url]);
        }
    }

    private function getExtensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'image/gif'       => 'gif',
            'video/mp4'       => 'mp4',
            'video/quicktime' => 'mov',
            default           => 'bin',
        };
    }
}

