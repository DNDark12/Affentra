<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\Integration\CookieCredentialService;
use Exception;

class ProductScraperService
{
    private const ALLOWED_DOMAIN_SUFFIXES = [
        'shopee.vn',
        'shopee.co.th',
        'shopee.sg',
        'shopee.com.my',
        'shopee.ph',
        'shopee.co.id',
        'shopee.tw'
    ];

    private const BLOCKED_IP_RANGES = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '::1/128',
        'fc00::/7'
    ];

    /**
     * Scrape product data from a given URL.
     */
    public function scrape(string $url, ?\App\Models\User $user = null): array
    {
        $parsedUrl = parse_url($url);

        if (!isset($parsedUrl['scheme']) || !in_array($parsedUrl['scheme'], ['http', 'https'])) {
            throw new Exception("Invalid URL scheme. Only HTTP and HTTPS are allowed.");
        }

        if (!isset($parsedUrl['host'])) {
            throw new Exception("Invalid URL structure.");
        }

        $host = strtolower($parsedUrl['host']);
        $baseHost = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        if (! $this->isAllowedDomain($baseHost)) {
            throw new Exception("Domain not allowed: {$host}.");
        }

        $ip = gethostbyname($host);
        if ($this->isBlockedIp($ip)) {
            throw new Exception("SSRF attempt detected.");
        }

        // Shopee-specific: use internal API (SPA renders no HTML product data)
        if (str_contains($baseHost, 'shopee.')) {
            $shopeeResult = $this->scrapeShopeeApi($url, $baseHost, $user);
            if ($shopeeResult && ($shopeeResult['data']['title'] || $shopeeResult['data']['price_value'])) {
                return $shopeeResult;
            }
            // Fallback to HTML if API fails
        }

        $html = $this->fetchHtml($url);
        
        // 1. LD+JSON extraction
        $ldJsonNodes = $this->extractLdJson($html);
        $ldJsonResult = $this->parseLdJson($ldJsonNodes);

        // 2. Meta/OG extraction
        $metaResult = $this->extractMeta($html);

        // 3. Regex fallback
        $regexResult = $this->extractRegex($html);

        // 4. Merge results
        return $this->mergeResults($ldJsonResult, $metaResult, $regexResult);
    }

    /**
     * Shopee-specific: extract shopId + itemId from URL and call internal API.
     */
    private function scrapeShopeeApi(string $url, string $domain, ?\App\Models\User $user = null): ?array
    {
        [$shopId, $itemId] = $this->extractShopeeIdentifiers($url);

        if (!$itemId) {
            Log::warning('shopee_scraper.cannot_parse_ids', ['url' => $url]);
            return null;
        }

        $cookieConnection = null;
        $openApiConnection = null;

        if ($user) {
            $cookieConnection = $user->platformConnections()
                ->where('platform', 'shopee')
                ->where('method', 'cookie')
                ->where('status', 'active')
                ->first();

            $openApiConnection = $user->platformConnections()
                ->where('platform', 'shopee')
                ->whereIn('method', ['open_api', 'portal_export'])
                ->where('status', 'active')
                ->first();
        }

        // 1. Try Scraper with user cookies (Primary Strategy)
        if ($cookieConnection) {
            $scraperResult = $this->scrapeViaInternalService($itemId, $shopId, $cookieConnection);
            if ($scraperResult) {
                return $scraperResult;
            }
        }

        // 2. Try Affiliate API (OpenAPI) if available
        $affiliateError = null;
        if ($openApiConnection) {
            try {
                $affiliateResult = $this->scrapeShopeeAffiliateApi($itemId, $openApiConnection, $shopId);
                if ($affiliateResult) {
                    return $affiliateResult;
                }
            } catch (\RuntimeException $e) {
                $affiliateError = $e;
                Log::warning('shopee_scraper.openapi_failed', [
                    'item_id' => $itemId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 3. Fallback to public Shopee API (v4/item/get) if shopId is available
        if (!$shopId) {
            if ($affiliateError instanceof \RuntimeException) {
                throw $affiliateError;
            }
            return null;
        }

        try {
            $apiUrl = "https://{$domain}/api/v4/item/get?shopid={$shopId}&itemid={$itemId}";

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept' => 'application/json',
                'Accept-Language' => 'vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7',
                'Referer' => "https://{$domain}/",
                'Origin' => "https://{$domain}",
                'X-Requested-With' => 'XMLHttpRequest',
                'sec-ch-ua' => '"Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"Windows"',
            ])
            ->timeout(8)
            ->get($apiUrl);

            if (!$response->successful()) {
                Log::warning('shopee_scraper.api_failed', ['status' => $response->status(), 'url' => $apiUrl]);
                return null;
            }

            $json = $response->json();
            $item = $json['data'] ?? null;

            if (!$item) {
                Log::warning('shopee_scraper.no_data', ['url' => $apiUrl]);
                return null;
            }

            // Extract product info
            $title = $item['name'] ?? null;
            
            // Price in Shopee API is in dongs * 100000
            $priceValue = null;
            if (isset($item['price_min'])) {
                $priceValue = (float)$item['price_min'] / 100000;
            } elseif (isset($item['price'])) {
                $priceValue = (float)$item['price'] / 100000;
            }

            // Images
            $images = [];
            if (!empty($item['images'])) {
                foreach (array_slice($item['images'], 0, 5) as $hash) {
                    $images[] = $this->normalizeShopeeImageUrl("https://down-vn.img.susercontent.com/file/{$hash}");
                }
            } elseif (!empty($item['image'])) {
                $images[] = $this->normalizeShopeeImageUrl("https://down-vn.img.susercontent.com/file/{$item['image']}");
            }

            $description = $item['description'] ?? null;

            $data = [
                'title' => $title,
                'price_value' => $priceValue,
                'price_display' => $priceValue ? number_format($priceValue, 0, ',', '.') . '₫' : null,
                'images' => $images,
                'description' => $description,
            ];

            $missingFields = [];
            if (!$data['title']) $missingFields[] = 'title';
            if (!$data['price_value']) $missingFields[] = 'price';
            if (empty($data['images'])) $missingFields[] = 'images';

            $confidence = 0.0;
            if ($data['title']) $confidence += 0.4;
            if ($data['price_value']) $confidence += 0.4;
            if (!empty($data['images'])) $confidence += 0.2;

            return [
                'data' => $data,
                'missing_fields' => $missingFields,
                'confidence' => $confidence,
                'source' => 'shopee_api',
                'source_details' => [
                    'shop_id' => $shopId,
                    'item_id' => $itemId,
                ]
            ];
        } catch (Exception $e) {
            Log::warning('shopee_scraper.exception', ['error' => $e->getMessage(), 'url' => $url]);

            if ($affiliateError instanceof \RuntimeException) {
                throw $affiliateError;
            }
            return null;
        }
    }

    /**
     * Fallback: call internal Python scraper microservice (Camoufox + Playwright)
     * to scrape Shopee product data when all direct API methods fail.
     *
     * The microservice runs as a Docker container alongside the Laravel app.
     * Config: config('services.scraper')
     *
     * @return array<string, mixed>|null  Standard scrape result or null on failure
     */
    private function scrapeViaInternalService(
        ?string $itemId,
        ?string $shopId,
        ?\App\Models\PlatformConnection $connection = null
    ): ?array {
        if (! config('services.scraper.enabled', true)) {
            return null;
        }

        if (! $itemId) {
            return null;
        }

        // Get raw cookie string from PlatformConnection (not the full JSON)
        $cookies = null;
        if ($connection && $connection->method === 'cookie' && $connection->cookie_header) {
            $cookies = app(CookieCredentialService::class)->extractRawCookie($connection);
        }

        if (! $cookies) {
            Log::debug('shopee_scraper.no_cookies_for_internal_service', [
                'item_id' => $itemId,
                'connection_id' => $connection?->id,
            ]);
            return null;
        }

        $baseUrl = config('services.scraper.url', 'http://scraper:8000');
        $apiKey  = config('services.scraper.api_key', 'dev-secret-key');
        $timeout = (int) config('services.scraper.timeout', 30);

        try {
            $payload = [
                'item_id' => $itemId,
                'cookies' => $cookies,
            ];
            if ($shopId) {
                $payload['shop_id'] = $shopId;
            }

            $response = Http::withHeaders([
                'Accept'           => 'application/json',
                'Content-Type'     => 'application/json',
                'X-Internal-Token' => $apiKey,
            ])
                ->timeout($timeout)
                ->post("{$baseUrl}/api/v1/shopee/product", $payload);

            if (! $response->successful()) {
                Log::warning('shopee_scraper.internal_service_http_failed', [
                    'item_id'     => $itemId,
                    'shop_id'     => $shopId,
                    'http_status' => $response->status(),
                ]);
                return null;
            }

            $json = $response->json();

            if (! is_array($json) || ! ($json['ok'] ?? false)) {
                $errorCode = $json['error_code'] ?? null;
                Log::warning('shopee_scraper.internal_service_error', [
                    'item_id'    => $itemId,
                    'error_code' => $errorCode,
                    'message'    => $json['message'] ?? null,
                ]);
                // If cookies expired, mark connection for re-validation
                if ($errorCode === 'MISSING_COOKIES' || $errorCode === 'SCRAPE_FAILED') {
                    if ($connection && str_contains($json['message'] ?? '', 'expired')) {
                        $connection->update(['status' => 'needs_revalidation']);
                    }
                }
                return null;
            }

            $scraped = $json['data'] ?? null;
            if (! is_array($scraped) || empty($scraped['item_name'])) {
                return null;
            }

            // Normalize to standard scrape result format
            $priceValue = isset($scraped['price_min']) ? (float) $scraped['price_min'] / 100000 : null;
            $images = [];
            if (! empty($scraped['image_url'])) {
                $images[] = $this->normalizeShopeeImageUrl($scraped['image_url']);
            }
            if (! empty($scraped['images']) && is_array($scraped['images'])) {
                foreach (array_slice($scraped['images'], 0, 5) as $img) {
                    $images[] = $this->normalizeShopeeImageUrl($img);
                }
            }
            $images = array_values(array_unique($images));

            $data = [
                'title'         => $scraped['item_name'],
                'price_value'   => $priceValue,
                'price_display' => $priceValue ? number_format($priceValue, 0, ',', '.') . '₫' : null,
                'images'        => $images,
                'description'   => null,
            ];

            $confidence = 0.0;
            if ($data['title'])       $confidence += 0.4;
            if ($data['price_value']) $confidence += 0.4;
            if (! empty($images))     $confidence += 0.2;

            Log::info('shopee_scraper.internal_service_success', [
                'item_id'    => $itemId,
                'title'      => Str::limit($data['title'], 50),
                'source'     => $scraped['source'] ?? 'unknown',
                'confidence' => $confidence,
            ]);

            return [
                'data'           => $data,
                'missing_fields' => [],
                'confidence'     => $confidence,
                'source'         => 'internal_scraper',
                'source_details' => [
                    'item_id'      => $itemId,
                    'shop_id'      => $shopId ?? ($scraped['shop_id'] ?? null),
                    'fetched_at'   => $json['fetched_at'] ?? null,
                    'parse_source' => $scraped['source'] ?? null,
                ],
            ];
        } catch (Exception $e) {
            Log::warning('shopee_scraper.internal_service_exception', [
                'item_id' => $itemId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract product data from Shopee Affiliate API.
     */
    private function scrapeShopeeAffiliateApi(
        string $itemId,
        \App\Models\PlatformConnection $connection,
        ?string $shopId = null
    ): ?array
    {
        try {
            $integration = app(\App\Services\Integration\Shopee\ShopeeIntegration::class);
            $detailFilters = [];
            if (is_string($shopId) && ctype_digit($shopId)) {
                $detailFilters['shopId'] = (int) $shopId;
            }

            $product = null;
            $integrationError = null;
            try {
                $product = $integration->getOfferDetail($connection, $itemId, $detailFilters);
                if (! is_array($product) || $product === []) {
                    $product = null;
                }
            } catch (\RuntimeException $e) {
                // Do not fail immediately: legacy endpoints/page-meta may still work.
                $integrationError = $e;
                $product = null;
            }

            if ($connection->method === 'open_api' && $product === null) {
                if ($integrationError instanceof \RuntimeException) {
                    throw $integrationError;
                }
                return null;
            }

            $referer = "https://affiliate.shopee.vn/offer/product_offer/{$itemId}";
            $headers = $integration->buildCookieHeaders($connection, $referer);

            $apiCandidates = [
                "https://affiliate.shopee.vn/api/v3/offer/product?item_id={$itemId}",
                "https://affiliate.shopee.vn/api/v3/offer/product_offer?item_id={$itemId}",
            ];

            $lastOfferCode = null;
            if ($product === null) {
                foreach ($apiCandidates as $apiUrl) {
                    $response = Http::withHeaders($headers)
                        ->timeout(10)
                        ->get($apiUrl);

                    if (! $response->successful()) {
                        Log::warning('shopee_scraper.affiliate_api_failed', [
                            'status' => $response->status(),
                            'itemId' => $itemId,
                            'user_id' => $connection->user_id,
                            'url' => $apiUrl,
                        ]);
                        continue;
                    }

                    $json = $response->json();
                    $apiCode = $this->extractShopeeApiCode($json);
                    $apiMessage = $this->extractShopeeApiMessage($json);
                    if ($apiCode !== null && $apiCode !== 0) {
                        $lastOfferCode = $apiCode;
                    }
                    $product = $this->extractAffiliateProductPayload($json);
                    if ($product !== null) {
                        break;
                    }

                    Log::warning('shopee_scraper.affiliate_no_data', [
                        'itemId' => $itemId,
                        'url' => $apiUrl,
                            'code' => $apiCode,
                            'msg' => $apiMessage,
                        ]);
                }
            }

            if ($product === null) {
                $product = $this->scrapeShopeeOfferViaGraphql($itemId, $headers, $connection->id);
            }

            if ($product === null) {
                $fallback = $this->scrapeShopeeOfferPageViaCookie($itemId, $headers, $connection->id);
                if ($fallback !== null) {
                    return $fallback;
                }
                if ($integrationError instanceof \RuntimeException) {
                    throw $integrationError;
                }
                if ($lastOfferCode === 90309999) {
                    throw new \RuntimeException(
                        'Shopee anti-bot challenge (90309999). Vui lòng cập nhật cURL mới từ trang Product Offer rồi thử lại.'
                    );
                }
                return null;
            }

            $data = [
                'title' => $product['product_name'] ?? $product['productName'] ?? $product['name'] ?? $product['item_name'] ?? null,
                'price_value' => $this->normalizeShopeePrice(
                    $product['price']
                    ?? $product['price_min']
                    ?? $product['priceMin']
                    ?? null
                ),
                'price_display' => null,
                'images' => $this->normalizeShopeeImages($product),
                'description' => $product['product_description'] ?? $product['description'] ?? null,
                'commission_rate' => $product['commission_rate'] ?? null,
            ];
            if ($data['price_value'] !== null && $data['price_value'] > 0) {
                $data['price_display'] = number_format((float) $data['price_value'], 0, ',', '.') . '₫';
            }

            $confidence = 0.0;
            if ($data['title']) $confidence += 0.4;
            if ($data['price_value']) $confidence += 0.4;
            if (!empty($data['images'])) $confidence += 0.2;

            return [
                'data' => $data,
                'missing_fields' => [],
                'confidence' => $confidence,
                'source' => 'shopee_affiliate_api',
                'source_details' => [
                    'item_id' => $itemId,
                    'connection_id' => $connection->id,
                ]
            ];
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('shopee_scraper.affiliate_exception', [
                'error' => $e->getMessage(),
                'itemId' => $itemId
            ]);
            return null;
        }
    }

    private function extractShopeeApiCode(mixed $payload): ?int
    {
        if (! is_array($payload)) {
            return null;
        }

        $candidates = [
            $payload['code'] ?? null,
            $payload['error'] ?? null,
            $payload['3'] ?? null,
            $payload[3] ?? null,
            $payload['0'] ?? null,
            $payload[0] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        return null;
    }

    private function extractShopeeApiMessage(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $candidates = [
            $payload['msg'] ?? null,
            $payload['message'] ?? null,
            $payload['1'] ?? null,
            $payload[1] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|null
     */
    private function scrapeShopeeOfferViaGraphql(string $itemId, array $headers, int $connectionId): ?array
    {
        try {
            $payload = [
                'operationName' => 'productOfferV2',
                'query' => <<<'GRAPHQL'
                query productOfferV2($itemId: Long, $page: Int, $limit: Int) {
                    productOfferV2(itemId: $itemId, page: $page, limit: $limit) {
                        nodes {
                            itemId
                            productName
                            productLink
                            offerLink
                            imageUrl
                            priceMin
                            priceMax
                            commissionRate
                            shopId
                            shopName
                        }
                    }
                }
                GRAPHQL,
                'variables' => [
                    'itemId' => (int) $itemId,
                    'page' => 1,
                    'limit' => 1,
                ],
            ];

            $gqlHeaders = $headers;
            $gqlHeaders['Content-Type'] = 'application/json; charset=UTF-8';

            $response = Http::withHeaders($gqlHeaders)
                ->timeout(12)
                ->post('https://affiliate.shopee.vn/api/v3/gql?q=productOfferV2', $payload);

            if (! $response->successful()) {
                Log::warning('shopee_scraper.affiliate_gql_failed', [
                    'status' => $response->status(),
                    'itemId' => $itemId,
                    'connection_id' => $connectionId,
                ]);
                return null;
            }

            $json = $response->json();
            $nodes = $json['data']['productOfferV2']['nodes'] ?? [];
            if (! is_array($nodes) || empty($nodes)) {
                return null;
            }

            $node = null;
            foreach ($nodes as $candidate) {
                if (is_array($candidate) && (string) ($candidate['itemId'] ?? '') === $itemId) {
                    $node = $candidate;
                    break;
                }
            }

            if ($node === null) {
                $first = $nodes[0] ?? null;
                $node = is_array($first) ? $first : null;
            }

            if ($node === null) {
                return null;
            }

            return [
                'product_name' => $node['productName'] ?? null,
                'name' => $node['productName'] ?? null,
                'price_min' => $node['priceMin'] ?? null,
                'price_max' => $node['priceMax'] ?? null,
                'image_url' => $node['imageUrl'] ?? null,
                'product_link' => $node['productLink'] ?? null,
                'offer_link' => $node['offerLink'] ?? null,
                'commission_rate' => $node['commissionRate'] ?? null,
                'shop_id' => $node['shopId'] ?? null,
                'shop_name' => $node['shopName'] ?? null,
            ];
        } catch (Exception $e) {
            Log::warning('shopee_scraper.affiliate_gql_exception', [
                'error' => $e->getMessage(),
                'itemId' => $itemId,
                'connection_id' => $connectionId,
            ]);
            return null;
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function scrapeShopeeOfferPageViaCookie(string $itemId, array $headers, int $connectionId): ?array
    {
        try {
            $url = "https://affiliate.shopee.vn/offer/product_offer/{$itemId}";
            $pageHeaders = $headers;
            $pageHeaders['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

            $response = Http::withHeaders($pageHeaders)
                ->timeout(10)
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();
            $title = null;
            $image = null;
            $priceValue = null;

            if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) === 1) {
                $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
            }

            if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) === 1) {
                $image = trim($m[1]);
            }

            if (preg_match('/"product_name"\s*:\s*"([^"]+)"/i', $html, $m) === 1 && $title === null) {
                $title = html_entity_decode(stripslashes($m[1]), ENT_QUOTES | ENT_HTML5);
            }

            if (preg_match('/"price(?:_min|Min)?"\s*:\s*"?(\d+)"?/i', $html, $m) === 1) {
                $priceValue = $this->normalizeShopeePrice($m[1]);
            }

            if ($title === null && $image === null && $priceValue === null) {
                return null;
            }

            return [
                'data' => [
                    'title' => $title,
                    'price_value' => $priceValue,
                    'price_display' => $priceValue !== null ? number_format((float) $priceValue, 0, ',', '.') . '₫' : null,
                    'images' => $image ? [$image] : [],
                    'description' => null,
                ],
                'missing_fields' => [],
                'confidence' => ($title !== null ? 0.45 : 0.0) + ($image !== null ? 0.2 : 0.0) + ($priceValue !== null ? 0.35 : 0.0),
                'source' => 'shopee_affiliate_page',
                'source_details' => [
                    'item_id' => $itemId,
                    'connection_id' => $connectionId,
                ],
            ];
        } catch (Exception $e) {
            Log::warning('shopee_scraper.offer_page_exception', [
                'error' => $e->getMessage(),
                'itemId' => $itemId,
            ]);
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function extractAffiliateProductPayload(array $payload): ?array
    {
        $candidates = [
            $payload['data']['product'] ?? null,
            $payload['data']['item'] ?? null,
            $payload['data'] ?? null,
            $payload['product'] ?? null,
            $payload['item'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && ! empty($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return list<string>
     */
    private function normalizeShopeeImages(array $product): array
    {
        $images = [];

        if (is_string($product['image_url'] ?? null) && trim($product['image_url']) !== '') {
            $images[] = $this->normalizeShopeeImageUrl(trim((string) $product['image_url']));
        }

        if (is_string($product['imageUrl'] ?? null) && trim($product['imageUrl']) !== '') {
            $images[] = $this->normalizeShopeeImageUrl(trim((string) $product['imageUrl']));
        }

        $list = $product['images'] ?? $product['image_urls'] ?? null;
        if (is_array($list)) {
            foreach ($list as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $images[] = $this->normalizeShopeeImageUrl(trim($value));
                }
            }
        }

        return array_values(array_unique($images));
    }

    private function normalizeShopeeImageUrl(string $url): string
    {
        $url = trim($url);
        // Ensure Shopee CDN images have an extension
        if (str_contains($url, 'susercontent.com') && !preg_match('/\.(jpe?g|png|gif|webp)$/i', $url)) {
            // Remove any trailing suffixes like _tn
            $url = preg_replace('/_tn$/i', '', $url);
            $url .= '.jpeg';
        }

        return $url;
    }

    private function normalizeShopeePrice(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_string($raw)) {
            $normalized = str_replace(['.', ',', '₫', 'đ', ' '], '', $raw);
            if ($normalized === '' || ! is_numeric($normalized)) {
                return null;
            }
            $raw = (float) $normalized;
        }

        if (! is_numeric($raw)) {
            return null;
        }

        $price = (float) $raw;
        if ($price >= 1_000_000) {
            return round($price / 100000, 2);
        }

        return round($price, 2);
    }

    private function fetchHtml(string $url): string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7',
            ])
            ->timeout(10)
            ->maxRedirects(3)
            ->get($url);

            if (!$response->successful()) {
                throw new Exception("HTTP status {$response->status()}");
            }

            return $response->body();
        } catch (Exception $e) {
            Log::warning("Scraper fetch failed", ['url' => $url, 'error' => $e->getMessage()]);
            throw new Exception("Failed to fetch product page.");
        }
    }

    private function extractLdJson(string $html): array
    {
        $nodes = [];
        if (preg_match_all('/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $json) {
                try {
                    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                    if ($decoded) {
                        $nodes[] = $decoded;
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
        }
        return $nodes;
    }

    private function parseLdJson(array $nodes): array
    {
        $data = ['title' => null, 'price_value' => null, 'images' => [], 'description' => null];
        $found = false;

        foreach ($nodes as $node) {
            // Handle @graph
            $items = isset($node['@graph']) ? $node['@graph'] : [$node];

            foreach ($items as $item) {
                if (!isset($item['@type'])) continue;

                $types = (array)$item['@type'];
                if (!in_array('Product', $types)) continue;

                $found = true;
                $data['title'] = $data['title'] ?? ($item['name'] ?? null);
                $data['description'] = $data['description'] ?? ($item['description'] ?? null);
                
                if (isset($item['image'])) {
                    $imgs = (array)$item['image'];
                    foreach ($imgs as $img) {
                        $url = is_array($img) ? ($img['url'] ?? null) : $img;
                        if ($url) $data['images'][] = $url;
                    }
                }

                if (isset($item['offers'])) {
                    $offers = isset($item['offers']['@type']) ? [$item['offers']] : (array)$item['offers'];
                    foreach ($offers as $offer) {
                        if (isset($offer['lowPrice'])) {
                            $data['price_value'] = (float)$offer['lowPrice'];
                            break;
                        }
                        if (isset($offer['price'])) {
                            $data['price_value'] = (float)$offer['price'];
                            break;
                        }
                    }
                }
            }
        }

        return [
            'data' => $data,
            'source' => 'ldjson',
            'found' => $found,
            'details' => ['nodes_count' => count($nodes)]
        ];
    }

    private function extractMeta(string $html): array
    {
        $data = ['title' => null, 'images' => [], 'description' => null];
        
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOBLANKS | LIBXML_NOERROR);
        $xpath = new \DOMXPath($dom);

        $ogTitle = $this->getMetaContent($xpath, 'og:title');
        $twitterTitle = $this->getMetaNameContent($xpath, 'twitter:title');
        $data['title'] = $ogTitle ?: ($twitterTitle ?: $this->getTagContent($dom, 'title'));
        
        $ogImage = $this->getMetaContent($xpath, 'og:image');
        $twitterImage = $this->getMetaNameContent($xpath, 'twitter:image');
        if ($ogImage) $data['images'][] = $ogImage;
        elseif ($twitterImage) $data['images'][] = $twitterImage;

        $ogDesc = $this->getMetaContent($xpath, 'og:description');
        $twitterDesc = $this->getMetaNameContent($xpath, 'twitter:description');
        $data['description'] = $ogDesc ?: ($twitterDesc ?: $this->getMetaNameContent($xpath, 'description'));

        return [
            'data' => $data,
            'source' => 'meta',
            'found' => !empty($data['title'])
        ];
    }

    private function extractRegex(string $html): array
    {
        $data = ['title' => null, 'price_value' => null];
        
        // Price regex (common patterns in Shopee scripts)
        // Look for "price": 123456 or "price_min": 123456 or "product_price": 123456
        if (preg_match('/"(?:price|price_min|product_price|amount)":\s*"?(\d+)"?/', $html, $matches)) {
            $data['price_value'] = (float)$matches[1];
        }

        // Title fallback from script if meta fails
        if (preg_match('/"name":\s*"([^"]+)"/', $html, $matches)) {
            $data['title'] = $data['title'] ?? htmlspecialchars_decode($matches[1]);
        }

        return [
            'data' => $data,
            'source' => 'regex',
            'found' => !empty($data['price_value'])
        ];
    }

    private function mergeResults(array $ldJson, array $meta, array $regex): array
    {
        $finalData = [
            'title' => $ldJson['data']['title'] ?? $meta['data']['title'] ?? null,
            'price_value' => $ldJson['data']['price_value'] ?? $regex['data']['price_value'] ?? null,
            'images' => !empty($ldJson['data']['images']) ? array_unique($ldJson['data']['images']) : $meta['data']['images'],
            'description' => $ldJson['data']['description'] ?? $meta['data']['description'] ?? null,
        ];

        // Format price
        $finalData['price_display'] = $finalData['price_value'] 
            ? number_format((float)$finalData['price_value'], 0, ',', '.') . '₫'
            : null;

        $missingFields = [];
        if (!$finalData['title']) $missingFields[] = 'title';
        if (!$finalData['price_value']) $missingFields[] = 'price';
        if (empty($finalData['images'])) $missingFields[] = 'images';

        $source = 'meta';
        if ($ldJson['found']) $source = 'ldjson';
        elseif ($regex['found'] && !$finalData['price_value']) $source = 'regex'; // unlikely but for logic

        $confidence = 0.0;
        if ($finalData['title']) $confidence += 0.4;
        if ($finalData['price_value']) $confidence += 0.4;
        if (!empty($finalData['images'])) $confidence += 0.2;

        return [
            'data' => $finalData,
            'missing_fields' => $missingFields,
            'confidence' => $confidence,
            'source' => $source,
            'source_details' => [
                'ldjson_found' => $ldJson['found'],
                'ldjson_nodes' => $ldJson['details']['nodes_count'] ?? 0,
                'regex_price_found' => isset($regex['data']['price_value'])
            ]
        ];
    }

    private function getMetaContent(\DOMXPath $xpath, string $property): ?string
    {
        $node = $xpath->query('//meta[@property="' . $property . '"]/@content')->item(0);
        return $node ? trim($node->nodeValue) : null;
    }

    private function getMetaNameContent(\DOMXPath $xpath, string $name): ?string
    {
        $node = $xpath->query('//meta[@name="' . $name . '"]/@content')->item(0);
        return $node ? trim($node->nodeValue) : null;
    }

    private function getTagContent(\DOMDocument $dom, string $tag): ?string
    {
        $node = $dom->getElementsByTagName($tag)->item(0);
        return $node ? trim($node->textContent) : null;
    }

    private function isAllowedDomain(string $host): bool
    {
        foreach (self::ALLOWED_DOMAIN_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:string|null,1:string|null}
     */
    private function extractShopeeIdentifiers(string $url): array
    {
        $shopId = null;
        $itemId = null;

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        if (preg_match('#/product/(\d+)/(\d+)#', $path, $m) === 1) {
            $shopId = $m[1];
            $itemId = $m[2];
        } elseif (preg_match('#-i\.(\d+)\.(\d+)#', $path, $m) === 1) {
            $shopId = $m[1];
            $itemId = $m[2];
        } elseif (preg_match('#/offer/product_offer/(\d+)#', $path, $m) === 1) {
            $itemId = $m[1];
        } elseif (preg_match('#/offer/product/(\d+)#', $path, $m) === 1) {
            $itemId = $m[1];
        }

        if ($itemId === null) {
            parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);
            $candidate = $query['item_id'] ?? $query['itemid'] ?? $query['product_id'] ?? null;
            if (is_scalar($candidate)) {
                $candidate = trim((string) $candidate);
                if ($candidate !== '' && ctype_digit($candidate)) {
                    $itemId = $candidate;
                }
            }
        }

        return [$shopId, $itemId];
    }

    private function isBlockedIp(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') return true;
        foreach (self::BLOCKED_IP_RANGES as $cidr) {
            if ($this->cidrMatch($ip, $cidr)) return true;
        }
        return false;
    }

    private function cidrMatch(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) return $ip === $cidr;
        list($subnet, $bits) = explode('/', $cidr);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = ip2long($ip);
            $subnet = ip2long($subnet);
            $mask = -1 << (32 - $bits);
            return ($ip & $mask) == ($subnet & $mask);
        }
        return false;
    }
}
