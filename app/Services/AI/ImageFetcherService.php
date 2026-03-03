<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use Exception;

class ImageFetcherService
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
    private const TIMEOUT_SECONDS = 5;
    private const MAX_REDIRECTS = 3;
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Fetch images and return their Base64 representations.
     * Fails gracefully, returning an array of successfully fetched base64 strings.
     * 
     * @param array $urls
     * @param string|null $generationId
     * @return array
     */
    public function fetchAsBase64(array $urls, ?string $generationId = null): array
    {
        $base64Images = [];
        $urls = array_slice($urls, 0, 5); // Hard limit to 5 images

        foreach ($urls as $url) {
            $base64 = $this->fetchSingleAsBase64($url, $generationId);
            if ($base64) {
                $base64Images[] = $base64;
            }
        }

        return $base64Images;
    }

    /**
     * Safely fetch a single image and convert to Base64.
     *
     * @param string $url
     * @param string|null $generationId
     * @return string|null
     */
    public function fetchSingleAsBase64(string $url, ?string $generationId = null): ?string
    {
        try {
            // Validate URL format before attempting fetch
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $this->logFailure($generationId, $url, 'Invalid URL format');
                return null;
            }

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'image/jpeg, image/png, image/webp'
            ])
            ->timeout(self::TIMEOUT_SECONDS)
            ->maxRedirects(self::MAX_REDIRECTS)
            ->get($url);

            if (!$response->successful()) {
                $this->logFailure($generationId, $url, "HTTP Error: {$response->status()}");
                return null;
            }

            $body = $response->body();
            
            if (strlen($body) > self::MAX_FILE_SIZE) {
                $this->logFailure($generationId, $url, 'File exceeds 5MB limit');
                return null;
            }

            // File signature sniffing & Mime Type Validation
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($body);

            if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
                $this->logFailure($generationId, $url, "Disallowed MIME type: {$mimeType}");
                return null;
            }

            // Note: Advanced resizing (1024x1024 constraint check) would require GD/Imagick.
            // For now, size limits and MIME restrictions provide core safety. 
            // If GD is available, image downscaling can be done here.

            $base64 = base64_encode($body);
            return "data:{$mimeType};base64,{$base64}";

        } catch (ConnectionException $e) {
            $this->logFailure($generationId, $url, 'Timeout or Connection Error');
            return null;
        } catch (Exception $e) {
             $this->logFailure($generationId, $url, 'Unknown Exception: ' . $e->getMessage());
             return null;
        }
    }

    private function logFailure(?string $generationId, string $url, string $reason): void
    {
        Log::warning('ImageFetcherService failed to retrieve image', [
            'generation_id' => $generationId ?? 'unknown',
            'url' => $url,
            'reason' => $reason
        ]);
    }
}
