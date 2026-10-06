<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PlatformConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles TikTok Shop OAuth flow:
 * 1. authorize  → Redirect user to TikTok consent screen.
 * 2. callback   → Exchange auth_code for tokens, fetch authorized shops.
 * 3. selectShop → Persist the chosen shop as a PlatformConnection.
 */
class TikTokAuthController extends Controller
{
    // ─── Step 1: Build authorize URL ──────────────────────────────────────────

    public function redirectToTikTok(Request $request): RedirectResponse
    {
        $appKey      = config('integrations.tiktok.app_key');
        $redirectUri = config('integrations.tiktok.redirect_uri');

        if (blank($appKey) || blank($redirectUri)) {
            return redirect()->route('integrations.index')
                ->with('error', 'TikTok OAuth chưa được cấu hình. Thiếu app_key hoặc redirect_uri.');
        }

        // Generate & store CSRF state (bind to session + user)
        $state = Str::random(40);
        session(['tiktok_oauth_state' => $state]);

        $authUrl = config('integrations.tiktok.auth_url') . '?' . http_build_query([
            'app_key'      => $appKey,
            'service_id'   => $appKey, // Bắt buộc cho Global Service/Partner App
            'client_id'    => $appKey, // Alias thường gặp trong OAuth2
            'client_key'   => $appKey, // Để khớp với lỗi "invalid client_key" của TikTok
            'state'        => $state,
            'redirect_uri' => $redirectUri,
        ]);

        return redirect()->away($authUrl);
    }

    // ─── Step 2: Handle callback ─────────────────────────────────────────────

    public function callback(Request $request): RedirectResponse
    {
        // Validate state (CSRF protection)
        $expectedState = session()->pull('tiktok_oauth_state');
        $returnedState = $request->query('state');

        if (blank($expectedState) || $returnedState !== $expectedState) {
            Log::warning('TikTok OAuth: invalid state', [
                'expected' => $expectedState,
                'received' => $returnedState,
            ]);

            return redirect()->route('integrations.index')
                ->with('error', 'OAuth state mismatch — vui lòng thử lại.');
        }

        $authCode = $request->query('code');
        if (blank($authCode)) {
            return redirect()->route('integrations.index')
                ->with('error', 'TikTok không trả về authorization code.');
        }

        // Exchange auth_code for tokens
        $tokenResponse = $this->exchangeToken((string) $authCode);
        if ($tokenResponse === null) {
            return redirect()->route('integrations.index')
                ->with('error', 'Không thể lấy token từ TikTok. Kiểm tra cấu hình App.');
        }

        // Fetch authorized shops
        $shops = $this->fetchAuthorizedShops($tokenResponse['access_token']);

        if (empty($shops)) {
            return redirect()->route('integrations.index')
                ->with('error', 'Không tìm thấy shop nào được ủy quyền.');
        }

        // Single shop → auto-create connection
        if (count($shops) === 1) {
            $this->persistConnection(
                user: $request->user(),
                tokenData: $tokenResponse,
                shopData: $shops[0],
            );

            return redirect()->route('integrations.index')
                ->with('success', 'Kết nối TikTok Shop thành công!');
        }

        // Multi-shop → store tokens temporarily, redirect to selection UI
        $tmpKey = 'tiktok_tmp_auth_' . Str::random(32);
        Cache::put($tmpKey, [
            'user_id'    => $request->user()->id,
            'token_data' => $tokenResponse,
            'shops'      => $shops,
        ], config('integrations.tiktok.tmp_auth_ttl', 600));

        return redirect()->route('integrations.index', [
            'tiktok_select_shop' => $tmpKey,
        ]);
    }

    // ─── Step 3: Persist selected shop ───────────────────────────────────────

    public function selectShop(Request $request): JsonResponse
    {
        $request->validate([
            'tmp_auth_key' => ['required', 'string'],
            'shop_id'      => ['required', 'string'],
        ]);

        $tmpKey = $request->input('tmp_auth_key');

        // Read WITHOUT consuming — validate ownership first
        $cached = Cache::get($tmpKey);

        if ($cached === null) {
            return response()->json([
                'ok'      => false,
                'message' => 'Phiên ủy quyền đã hết hạn hoặc đã được sử dụng. Vui lòng kết nối lại.',
            ], 422);
        }

        // Security: bind to current user + session
        $currentUser = $request->user();
        if ($cached['user_id'] !== $currentUser->id) {
            Log::warning('TikTok OAuth: user mismatch on select-shop', [
                'cached_user'  => $cached['user_id'],
                'current_user' => $currentUser->id,
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'Phiên không hợp lệ.',
            ], 403);
        }

        // Now consume the key (one-time use) — after ownership is verified
        Cache::forget($tmpKey);

        $selectedShopId = $request->input('shop_id');
        $selectedShop   = collect($cached['shops'])->firstWhere('id', $selectedShopId);

        if ($selectedShop === null) {
            return response()->json([
                'ok'      => false,
                'message' => 'Shop không tồn tại trong danh sách được ủy quyền.',
            ], 422);
        }

        $connection = $this->persistConnection(
            user: $currentUser,
            tokenData: $cached['token_data'],
            shopData: $selectedShop,
        );

        return response()->json([
            'ok'      => true,
            'data'    => ['connection_id' => $connection->id],
            'message' => 'Kết nối TikTok Shop thành công!',
        ]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Exchange authorization code for access/refresh tokens.
     *
     * @return array{access_token: string, refresh_token: string, access_token_expire_in: int, refresh_token_expire_in: int}|null
     */
    private function exchangeToken(string $authCode): ?array
    {
        $response = Http::post(config('integrations.tiktok.token_url'), [
            'app_key'    => config('integrations.tiktok.app_key'),
            'app_secret' => config('integrations.tiktok.app_secret'),
            'auth_code'  => $authCode,
            'grant_type' => 'authorized_code',
        ]);

        if (! $response->successful()) {
            Log::error('TikTok OAuth: token exchange failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }

        $body = $response->json();

        // TikTok wraps response in 'data' key
        $data = $body['data'] ?? $body;

        if (empty($data['access_token'])) {
            Log::error('TikTok OAuth: no access_token in response', ['body' => $body]);
            return null;
        }

        return $data;
    }

    /**
     * Fetch authorized shops for the given access token.
     *
     * @return list<array{id: string, name: string, cipher: string, region: string}>
     */
    private function fetchAuthorizedShops(string $accessToken): array
    {
        $appKey    = config('integrations.tiktok.app_key');
        $appSecret = config('integrations.tiktok.app_secret');
        $baseUrl   = config('integrations.tiktok.base_url');
        $version   = config('integrations.tiktok.api_versions.authorized_shop', '202309');

        $path   = "/authorization/{$version}/shops";
        $timestamp = time();

        // Build HMAC-SHA256 signature for this request
        $sign = $this->buildSign($path, [], $appSecret, $timestamp);

        $response = Http::withHeaders([
            'x-tts-access-token' => $accessToken,
            'Content-Type'       => 'application/json',
        ])->get("{$baseUrl}{$path}", [
            'app_key'   => $appKey,
            'timestamp' => $timestamp,
            'sign'      => $sign,
        ]);

        if (! $response->successful()) {
            Log::error('TikTok: fetch authorized shops failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return [];
        }

        $shops = $response->json('data.shops', []);

        return collect($shops)->map(fn (array $shop) => [
            'id'     => (string) ($shop['id'] ?? ''),
            'name'   => $shop['name'] ?? 'Unknown',
            'cipher' => $shop['cipher'] ?? '',
            'region' => $shop['region'] ?? '',
        ])->filter(fn (array $shop) => filled($shop['id']))->values()->all();
    }

    /**
     * Build TikTok Open API HMAC-SHA256 signature.
     *
     * sign = HMAC-SHA256(app_secret, app_secret + path + sorted_params + app_secret)
     *
     * @param  array<string, mixed>  $queryParams  (business params, excluding app_key/sign/timestamp)
     */
    private function buildSign(string $path, array $queryParams, string $appSecret, int $timestamp): string
    {
        $appKey = config('integrations.tiktok.app_key');

        // All params that participate in signing (matches adapter logic)
        $signParams = $queryParams;
        $signParams['app_key']   = $appKey;
        $signParams['timestamp'] = $timestamp;

        ksort($signParams);

        $paramString = '';
        foreach ($signParams as $key => $value) {
            $paramString .= $key . (string) $value;
        }

        $signInput = $appSecret . $path . $paramString . $appSecret;

        return hash_hmac('sha256', $signInput, $appSecret);
    }

    /**
     * Persist (upsert) a TikTok connection for the given user and shop.
     */
    private function persistConnection(
        \App\Models\User $user,
        array $tokenData,
        array $shopData,
    ): PlatformConnection {
        return PlatformConnection::updateOrCreate(
            [
                'user_id'  => $user->id,
                'platform' => 'tiktok',
                'shop_id'  => $shopData['id'],
            ],
            [
                'method'             => 'open_api',
                'shop_cipher'        => $shopData['cipher'] ?? null,
                'market'             => $shopData['region'] ?? 'VN',
                'access_token'       => $tokenData['access_token'],
                'refresh_token'      => $tokenData['refresh_token'] ?? null,
                'token_expires_at'   => now()->addSeconds($tokenData['access_token_expire_in'] ?? 0),
                'token_refreshed_at' => now(),
                'status'             => 'active',
                'label'              => 'TikTok Shop — ' . ($shopData['name'] ?? 'Unknown'),
            ],
        );
    }
}
