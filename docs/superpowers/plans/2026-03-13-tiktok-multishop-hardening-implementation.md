# TikTok Multi-Shop Hardening Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hoàn tất end-to-end TikTok multi-shop OAuth với bảo mật session-bound, one-time consume atomic, FE picker flow, và test coverage production-grade.

**Architecture:** Giữ Option A (cache-backed temporary auth) nhưng harden bằng encrypted OAuth blob + `user_id/session_id` binding. Thêm `pending-shops` POST endpoint để FE lấy danh sách shop tối thiểu, và `select-shop` atomic qua cache lock hash key. FE Index page mở picker khi có `tiktok_select_shop`, finalize qua API và cleanup URL.

**Tech Stack:** Laravel 12/PHP 8.4, Inertia + Vue 3, Axios, PHPUnit (Feature/Unit), Cache locks.

---

## File Structure / Responsibilities

### Modify
- `app/Http/Controllers/API/TikTokAuthController.php`
  - Encode encrypted temp OAuth payload.
  - Add `pendingShops()` API.
  - Harden `selectShop()` with lock + user/session validation + consume-after-persist.
  - Standardize JSON with `ApiResponse`.
- `routes/api.php`
  - Add `POST /api/integrations/tiktok/pending-shops` route.
- `config/integrations.php`
  - Add TikTok lock config keys (TTL/wait timeout).
- `resources/js/Pages/Integrations/Index.vue`
  - Parse `tiktok_select_shop` query, fetch pending shops, show picker modal, finalize selection, cleanup URL.
- `tests/Feature/OfferControllerTest.php`
  - Add TikTok short-link regression for `product_id` context.

### Create
- `resources/js/Pages/Integrations/Partials/TikTokShopPickerModal.vue`
  - Dedicated modal for selecting one TikTok shop.
- `tests/Feature/TikTokAuthControllerTest.php`
  - Full flow tests: callback, pending-shops, select-shop, mismatch, timeout, one-time, race.
- `tests/Unit/Integration/TikTok/TikTokIntegrationTest.php`
  - Adapter-level tests for normalize status mapping and signed request behavior through public methods.

### Notes
- Use existing `ApiResponse` envelope (`ok/data/message/errors/code`).
- No new package dependency.
- No git commit/push in this workspace execution.

---

## Runtime/Test Command Convention (choose one and keep consistent)

- **Sail:** `./vendor/bin/sail artisan test ...`
- **Docker Compose:** `docker-compose exec php-fpm php artisan test ...`

Expected test output pattern at pass: `OK` / `PASS` with targeted test count.

---

## Chunk 1: Backend contract + secure temp payload

### Task 1: Add failing feature tests for pending-shops/select-shop contracts

**Files:**
- Create: `tests/Feature/TikTokAuthControllerTest.php`
- Modify: none
- Test: `tests/Feature/TikTokAuthControllerTest.php`

- [ ] **Step 1: Write failing test for pending-shops happy path (minimal fields only)**

```php
public function test_pending_shops_returns_minimal_shop_fields_only(): void
{
    $user = User::factory()->create(['role' => 'owner']);

    $this->actingAs($user)->withSession(['_token' => 'csrf-token']);
    $sessionId = session()->getId();

    $tmpKey = 'tiktok_tmp_auth_test_key';
    $shops = [[
        'id' => '1001',
        'name' => 'Shop A',
        'region' => 'VN',
        'cipher' => 'secret_cipher',
    ]];

    Cache::put($tmpKey, [
        'user_id' => $user->id,
        'session_id' => $sessionId,
        'shops' => $shops,
        'oauth_blob_encrypted' => encrypt(json_encode([
            'token_data' => ['access_token' => 'tok', 'refresh_token' => 'ref'],
            'shops' => $shops,
        ], JSON_THROW_ON_ERROR)),
    ], 600);

    $response = $this->postJson(route('api.integrations.tiktok.pending-shops'), [
        'tmp_auth_key' => $tmpKey,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.shops.0.id', '1001')
        ->assertJsonPath('data.shops.0.name', 'Shop A')
        ->assertJsonPath('data.shops.0.region', 'VN')
        ->assertJsonMissingPath('data.shops.0.cipher')
        ->assertJsonMissingPath('data.shops.0.token_data');
}
```

- [ ] **Step 2: Write failing test for pending-shops session mismatch (403)**

```php
public function test_pending_shops_rejects_session_mismatch(): void
{
    $user = User::factory()->create(['role' => 'owner']);

    $this->actingAs($user)->withSession(['_token' => 'csrf-token']);

    $tmpKey = 'tiktok_tmp_auth_session_mismatch';
    Cache::put($tmpKey, [
        'user_id' => $user->id,
        'session_id' => 'other-session-id',
        'shops' => [['id' => '1001', 'name' => 'Shop A', 'region' => 'VN', 'cipher' => 'c1']],
        'oauth_blob_encrypted' => encrypt(json_encode([
            'token_data' => ['access_token' => 'tok', 'refresh_token' => 'ref'],
            'shops' => [['id' => '1001', 'name' => 'Shop A', 'region' => 'VN', 'cipher' => 'c1']],
        ], JSON_THROW_ON_ERROR)),
    ], 600);

    $response = $this->postJson(route('api.integrations.tiktok.pending-shops'), [
        'tmp_auth_key' => $tmpKey,
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('ok', false);
}
```

- [ ] **Step 3: Write failing test for expired/used key (422)**

```php
public function test_select_shop_returns_422_when_tmp_key_missing(): void
{
    $user = User::factory()->create(['role' => 'owner']);

    $response = $this->actingAs($user)->postJson(route('api.integrations.tiktok.select-shop'), [
        'tmp_auth_key' => 'missing',
        'shop_id' => '1001',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('ok', false);
}
```

- [ ] **Step 4: Write failing test for invalid shop id (422)**

```php
public function test_select_shop_returns_422_when_shop_is_not_in_authorized_list(): void
{
    $user = User::factory()->create(['role' => 'owner']);

    $this->actingAs($user)->withSession(['_token' => 'csrf-token']);
    $sessionId = session()->getId();

    $tmpKey = 'tiktok_tmp_auth_invalid_shop';
    $shops = [['id' => '1001', 'name' => 'Shop A', 'region' => 'VN', 'cipher' => 'c1']];

    Cache::put($tmpKey, [
        'user_id' => $user->id,
        'session_id' => $sessionId,
        'shops' => $shops,
        'oauth_blob_encrypted' => encrypt(json_encode([
            'token_data' => ['access_token' => 'tok', 'refresh_token' => 'ref'],
            'shops' => $shops,
        ], JSON_THROW_ON_ERROR)),
    ], 600);

    $response = $this->postJson(route('api.integrations.tiktok.select-shop'), [
        'tmp_auth_key' => $tmpKey,
        'shop_id' => '9999',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('ok', false);
}
```

- [ ] **Step 5: Write failing test for lock-timeout busy path (422)**

```php
public function test_select_shop_returns_422_when_lock_is_busy(): void
{
    $user = User::factory()->create(['role' => 'owner']);

    $this->actingAs($user)->withSession(['_token' => 'csrf-token']);
    $sessionId = session()->getId();

    $tmpKey = 'tiktok_tmp_auth_lock_busy';
    $shops = [['id' => '1001', 'name' => 'Shop A', 'region' => 'VN', 'cipher' => 'c1']];

    Cache::put($tmpKey, [
        'user_id' => $user->id,
        'session_id' => $sessionId,
        'shops' => $shops,
        'oauth_blob_encrypted' => encrypt(json_encode([
            'token_data' => ['access_token' => 'tok', 'refresh_token' => 'ref'],
            'shops' => $shops,
        ], JSON_THROW_ON_ERROR)),
    ], 600);

    $lockKey = 'tiktok_auth_lock:' . hash('sha256', $tmpKey);
    $lock = Cache::lock($lockKey, 10);
    $this->assertTrue($lock->get());

    try {
        $response = $this->postJson(route('api.integrations.tiktok.select-shop'), [
            'tmp_auth_key' => $tmpKey,
            'shop_id' => '1001',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Phiên đang được xử lý, vui lòng thử lại.');
    } finally {
        $lock->release();
    }
}
```

- [ ] **Step 6: Write failing one-time consume test (second call fails 422)**

```php
public function test_select_shop_can_only_be_used_once(): void
{
    $user = User::factory()->create(['role' => 'owner']);

    $this->actingAs($user)->withSession(['_token' => 'csrf-token']);
    $sessionId = session()->getId();

    $tmpKey = 'tiktok_tmp_auth_one_time';
    $shops = [['id' => '1001', 'name' => 'Shop A', 'region' => 'VN', 'cipher' => 'c1']];

    Cache::put($tmpKey, [
        'user_id' => $user->id,
        'session_id' => $sessionId,
        'shops' => $shops,
        'oauth_blob_encrypted' => encrypt(json_encode([
            'token_data' => ['access_token' => 'tok', 'refresh_token' => 'ref'],
            'shops' => $shops,
        ], JSON_THROW_ON_ERROR)),
    ], 600);

    $first = $this->postJson(route('api.integrations.tiktok.select-shop'), [
        'tmp_auth_key' => $tmpKey,
        'shop_id' => '1001',
    ]);

    $second = $this->postJson(route('api.integrations.tiktok.select-shop'), [
        'tmp_auth_key' => $tmpKey,
        'shop_id' => '1001',
    ]);

    $first->assertStatus(200);
    $second->assertStatus(422)->assertJsonPath('ok', false);
}
```

- [ ] **Step 7: Write failing race test (2 concurrent requests share one tmp key)**

```php
public function test_select_shop_race_allows_only_one_success(): void
{
    $this->assertTrue(function_exists('pcntl_fork'), 'pcntl extension is required for required race test coverage.');

    $user = User::factory()->create(['role' => 'owner']);

    $this->actingAs($user)->withSession(['_token' => 'csrf-token']);
    $sessionId = session()->getId();

    $tmpKey = 'tiktok_tmp_auth_race';
    $shopId = '1001';
    $shops = [['id' => $shopId, 'name' => 'Shop A', 'region' => 'VN', 'cipher' => 'c1']];

    Cache::put($tmpKey, [
        'user_id' => $user->id,
        'session_id' => $sessionId,
        'shops' => $shops,
        'oauth_blob_encrypted' => encrypt(json_encode([
            'token_data' => ['access_token' => 'tok', 'refresh_token' => 'ref'],
            'shops' => $shops,
        ], JSON_THROW_ON_ERROR)),
    ], 600);

    $barrierDir = storage_path('framework/testing/tiktok-race-' . uniqid('', true));
    mkdir($barrierDir, 0777, true);

    $runAt = microtime(true) + 0.5;
    $pids = [];

    foreach ([1, 2] as $i) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            while (microtime(true) < $runAt) {
                usleep(1000);
            }

            $response = $this->actingAs($user)
                ->withSession(['_token' => 'csrf-token'])
                ->postJson(route('api.integrations.tiktok.select-shop'), [
                    'tmp_auth_key' => $tmpKey,
                    'shop_id' => $shopId,
                ]);

            file_put_contents("{$barrierDir}/status{$i}.txt", (string) $response->status());
            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $statuses = [
        (int) file_get_contents("{$barrierDir}/status1.txt"),
        (int) file_get_contents("{$barrierDir}/status2.txt"),
    ];

    sort($statuses);

    $this->assertSame([200, 422], $statuses);
    $this->assertSame(1, PlatformConnection::query()
        ->where('user_id', $user->id)
        ->where('platform', 'tiktok')
        ->where('shop_id', $shopId)
        ->count());
    $this->assertNull(Cache::get($tmpKey), 'tmp_auth_key must be consumed after successful finalize.');

    $followUp = $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson(route('api.integrations.tiktok.select-shop'), [
            'tmp_auth_key' => $tmpKey,
            'shop_id' => $shopId,
        ]);

    $followUp->assertStatus(422)
        ->assertJsonPath('ok', false);
}
```

- [ ] **Step 8: Run targeted tests to confirm failure**

Run (Sail):
`./vendor/bin/sail artisan test --filter=TikTokAuthControllerTest`

Run (Compose):
`docker-compose exec php-fpm php artisan test --filter=TikTokAuthControllerTest`

Expected: FAIL on new tests only (route/method/behavior gaps not implemented yet).

- [ ] **Step 9: Checkpoint (no commit)**
- Confirm failure messages are contract gaps required by spec (403/422 mapping, lock behavior, one-time, race).

---

### Task 2: Implement pending-shops + encrypted temp payload + atomic select-shop

**Files:**
- Modify: `app/Http/Controllers/API/TikTokAuthController.php`
- Modify: `routes/api.php`
- Modify: `config/integrations.php`
- Test: `tests/Feature/TikTokAuthControllerTest.php`

- [ ] **Step 1: Add lock config keys in integrations config**

```php
// config/integrations.php under tiktok:
'select_shop_lock_ttl' => (int) env('TIKTOK_SELECT_SHOP_LOCK_TTL', 10),
'select_shop_lock_wait' => (int) env('TIKTOK_SELECT_SHOP_LOCK_WAIT', 3),
```

- [ ] **Step 2: Add pending-shops POST route under session-backed API group**

```php
// routes/api.php (inside existing Route::middleware(['auth'])->group(...))
Route::post('/tiktok/pending-shops', [\App\Http\Controllers\API\TikTokAuthController::class, 'pendingShops'])
    ->name('tiktok.pending-shops')
    ->middleware('throttle:10,1');
```

Expected:
- Endpoint runs under `web + auth` (session + CSRF) via existing route layering.
- Request must pass standard CSRF verification.
- Do not place this endpoint in any stateless API middleware path.

- [ ] **Step 3: Refactor callback temp cache payload to encrypted blob**

```php
Cache::put($tmpKey, [
    'user_id' => $request->user()->id,
    'session_id' => $request->session()->getId(),
    'shops' => $shops,
    'oauth_blob_encrypted' => encrypt(json_encode([
        'token_data' => $tokenResponse,
        'shops' => $shops,
    ], JSON_THROW_ON_ERROR)),
], config('integrations.tiktok.tmp_auth_ttl', 600));
```

- [ ] **Step 4: Implement `pendingShops(Request $request): JsonResponse` with ApiResponse validation errors**

```php
public function pendingShops(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'tmp_auth_key' => ['required', 'string'],
    ]);

    if ($validator->fails()) {
        return ApiResponse::error('Validation failed.', $validator->errors()->toArray(), 422);
    }

    $payload = $this->resolveTmpPayload($request, (string) $request->input('tmp_auth_key'));
    if ($payload['error'] !== null) {
        return $payload['error'];
    }

    $shops = collect($payload['shops'])->map(fn (array $shop) => [
        'id' => (string) ($shop['id'] ?? ''),
        'name' => (string) ($shop['name'] ?? 'Unknown'),
        'region' => (string) ($shop['region'] ?? ''),
    ])->values()->all();

    return ApiResponse::success(['shops' => $shops]);
}
```

- [ ] **Step 5: Harden `selectShop()` with hashed lock + consume-after-persist**

```php
$lockKey = 'tiktok_auth_lock:' . hash('sha256', $tmpKey);
$lock = Cache::lock($lockKey, (int) config('integrations.tiktok.select_shop_lock_ttl', 10));
$acquired = false;

try {
    $acquired = $lock->block((int) config('integrations.tiktok.select_shop_lock_wait', 3));
} catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
    return ApiResponse::error('Phiên đang được xử lý, vui lòng thử lại.', null, 422);
}

try {
    // resolve + verify payload (user/session/shop)
    // persistConnection(...)
    Cache::forget($tmpKey); // only after persist success
    return ApiResponse::success(['connection_id' => $connection->id], 'Kết nối TikTok Shop thành công!');
} finally {
    if ($acquired) {
        $lock->release();
    }
}
```

- [ ] **Step 6: Add payload resolver helper with security checks and decrypt handling**

```php
/** @return array{shops: array<int, array<string,mixed>>, token_data: array<string,mixed>, error: ?JsonResponse} */
private function resolveTmpPayload(Request $request, string $tmpKey): array
{
    // load cached
    // compare user_id + session_id
    // decrypt oauth_blob_encrypted safely
    // return structured payload or ApiResponse::error(...)
}
```

- [ ] **Step 7: Replace raw `response()->json` with `ApiResponse` in TikTok auth API methods**

Expected:
- success => `ApiResponse::success(...)`
- errors => `ApiResponse::error(...)` with 403/422 mapping.

- [ ] **Step 8: Run targeted tests for backend contracts**

Run (Sail):
`./vendor/bin/sail artisan test --filter=TikTokAuthControllerTest`

Run (Compose):
`docker-compose exec php-fpm php artisan test --filter=TikTokAuthControllerTest`

Expected: previously failing tests now PASS for implemented behavior.

- [ ] **Step 9: Run existing regression file for integrations API**

Run (Sail):
`./vendor/bin/sail artisan test --filter=IntegrationControllerTest`

Run (Compose):
`docker-compose exec php-fpm php artisan test --filter=IntegrationControllerTest`

Expected: PASS, no regression on existing integration endpoints.

- [ ] **Step 10: Checkpoint (no commit)**
- Diff review only backend + route + config + tests touched.

---

## Chunk 2: Frontend picker flow in Integrations Index

### Task 3: Build TikTok shop picker modal component

**Files:**
- Create: `resources/js/Pages/Integrations/Partials/TikTokShopPickerModal.vue`
- Test: manual UI verification in Integrations page

- [ ] **Step 1: Create modal shell with open/close + shop list + confirm button**

```vue
<script setup>
const props = defineProps({ isOpen: Boolean, shops: Array, loading: Boolean, submitting: Boolean });
const emit = defineEmits(['close', 'submit']);
const selectedShopId = ref('');
</script>
```

- [ ] **Step 2: Add validation UX (disable submit until selected + submitting guard)**

```vue
<button :disabled="!selectedShopId || submitting" @click="emit('submit', selectedShopId)">Kết nối shop</button>
```

- [ ] **Step 3: Add display fields limited to id/name/region**

Expected:
- No token/cipher displayed.

- [ ] **Step 4: Checkpoint (no commit)**
- Component is self-contained and reusable.

---

### Task 4: Integrate picker flow into Index.vue

**Files:**
- Modify: `resources/js/Pages/Integrations/Index.vue`
- Modify: `routes/api.php` route names usage validation
- Test: manual flow + `npm run build`

- [ ] **Step 1: Import/register picker modal and add local state**

```js
const tiktokTmpAuthKey = ref('');
const tiktokPendingShops = ref([]);
const tiktokPickerOpen = ref(false);
const tiktokPickerLoading = ref(false);
const tiktokPickerSubmitting = ref(false);
```

- [ ] **Step 2: Add helper to read and clear `tiktok_select_shop` query param**

```js
function getTmpAuthKeyFromUrl() {
  const url = new URL(window.location.href);
  return url.searchParams.get('tiktok_select_shop') || '';
}

function clearTmpAuthKeyFromUrl() {
  const url = new URL(window.location.href);
  url.searchParams.delete('tiktok_select_shop');
  window.history.replaceState({}, '', url.toString());
}
```

- [ ] **Step 3: Add initializer that calls pending-shops via POST**

```js
async function bootstrapTikTokPicker() {
  const key = getTmpAuthKeyFromUrl();
  if (!key) return;

  tiktokPickerLoading.value = true;
  try {
    const { data } = await axios.post(route('api.integrations.tiktok.pending-shops'), {
      tmp_auth_key: key,
    });
    tiktokTmpAuthKey.value = key;
    tiktokPendingShops.value = data?.data?.shops || [];
    tiktokPickerOpen.value = tiktokPendingShops.value.length > 0;
  } catch (error) {
    showFeedback('error', error.response?.data?.message || 'Không thể tải danh sách shop TikTok.');
    clearTmpAuthKeyFromUrl();
  } finally {
    tiktokPickerLoading.value = false;
  }
}
```

- [ ] **Step 4: Trigger initializer once when page mounts**

```js
onMounted(() => {
  void bootstrapTikTokPicker();
});
```

- [ ] **Step 5: Add finalize handler (POST select-shop)**

```js
async function submitTikTokShop(shopId) {
  if (!tiktokTmpAuthKey.value || tiktokPickerSubmitting.value) return;
  tiktokPickerSubmitting.value = true;

  try {
    const { data } = await axios.post(route('api.integrations.tiktok.select-shop'), {
      tmp_auth_key: tiktokTmpAuthKey.value,
      shop_id: shopId,
    });

    showFeedback('success', data?.message || 'Kết nối TikTok Shop thành công.');
    tiktokPickerOpen.value = false;
    clearTmpAuthKeyFromUrl();
    await router.reload({ only: ['connections'], preserveScroll: true, preserveState: true });
  } catch (error) {
    const status = error.response?.status;
    showFeedback(status === 403 ? 'error' : 'warning', error.response?.data?.message || 'Không thể hoàn tất kết nối TikTok.');
    tiktokPickerOpen.value = false;
    clearTmpAuthKeyFromUrl();
  } finally {
    tiktokPickerSubmitting.value = false;
  }
}
```

- [ ] **Step 6: Mount modal in template with Teleport**

```vue
<Teleport to="body">
  <TikTokShopPickerModal
    :isOpen="tiktokPickerOpen"
    :shops="tiktokPendingShops"
    :loading="tiktokPickerLoading"
    :submitting="tiktokPickerSubmitting"
    @close="() => { tiktokPickerOpen = false; clearTmpAuthKeyFromUrl(); }"
    @submit="submitTikTokShop"
  />
</Teleport>
```

- [ ] **Step 7: Build frontend assets**

Run:
`npm run build`

Expected: Vite build succeeds without type/runtime syntax errors.

- [ ] **Step 8: Manual smoke test**
- Start app, simulate URL `/integrations?tiktok_select_shop=<key>` with valid key.
- Verify modal appears, choose shop, success message, list reload, query key removed.
- Verify invalid key path shows error and query key removed.

- [ ] **Step 9: Checkpoint (no commit)**
- Confirm only `Index.vue` + modal file changed for FE flow.

---

## Chunk 3: Concurrency hardening + integration regressions

### Task 5: Add lock timeout + one-time + race tests for select-shop

**Files:**
- Modify: `tests/Feature/TikTokAuthControllerTest.php`
- Test: `tests/Feature/TikTokAuthControllerTest.php`

- [ ] **Step 1: Add lock-timeout test (pre-acquire same hashed lock)**

```php
public function test_select_shop_returns_422_when_lock_is_busy(): void
{
    $user = User::factory()->create(['role' => 'owner']);
    $tmpKey = 'tiktok_tmp_auth_lock_busy';
    // seed cache payload for this key...

    $lockKey = 'tiktok_auth_lock:' . hash('sha256', $tmpKey);
    $lock = Cache::lock($lockKey, 10);
    $this->assertTrue($lock->get());

    try {
        $response = $this->actingAs($user)->postJson(route('api.integrations.tiktok.select-shop'), [
            'tmp_auth_key' => $tmpKey,
            'shop_id' => '1001',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Phiên đang được xử lý, vui lòng thử lại.');
    } finally {
        $lock->release();
    }
}
```

- [ ] **Step 2: Add one-time consume test (first success, second 422)**

```php
public function test_select_shop_can_only_be_used_once(): void
{
    $user = User::factory()->create(['role' => 'owner']);

    $this->actingAs($user)->withSession(['_token' => 'csrf-token']);
    $sessionId = session()->getId();

    $tmpKey = 'tiktok_tmp_auth_one_time_chunk3';
    $shops = [['id' => '1001', 'name' => 'Shop A', 'region' => 'VN', 'cipher' => 'c1']];

    Cache::put($tmpKey, [
        'user_id' => $user->id,
        'session_id' => $sessionId,
        'shops' => $shops,
        'oauth_blob_encrypted' => encrypt(json_encode([
            'token_data' => ['access_token' => 'tok', 'refresh_token' => 'ref'],
            'shops' => $shops,
        ], JSON_THROW_ON_ERROR)),
    ], 600);

    $first = $this->postJson(route('api.integrations.tiktok.select-shop'), [
        'tmp_auth_key' => $tmpKey,
        'shop_id' => '1001',
    ]);

    $second = $this->postJson(route('api.integrations.tiktok.select-shop'), [
        'tmp_auth_key' => $tmpKey,
        'shop_id' => '1001',
    ]);

    $first->assertStatus(200);
    $second->assertStatus(422)->assertJsonPath('ok', false);
}
```

- [ ] **Step 3: Add true race test with 2 concurrent workers**

Implementation guidance (no new dependencies):
- Require `pcntl_fork()` for this mandatory race test in CI/local test env.
- Force shared lock store for this test (`config(['cache.default' => 'file'])`).
- Seed one tmp key and same `shop_id`.
- Spawn 2 child workers that each submit `select-shop` concurrently using the same barrier timestamp before request dispatch.
- Collect status codes in temp files.

Expected assertions:
- Exactly one `200` and one `422`.
- DB contains exactly one upsert target connection for `(user_id, platform=tiktok, shop_id)`.
- `tmp_auth_key` is consumed (cache key removed) after race completes.
- A follow-up third call with same key returns `422`.

```php
$this->assertTrue(function_exists('pcntl_fork'), 'pcntl extension is required for required race test coverage.');
```

- [ ] **Step 4: Run focused feature test file**

Run (Sail):
`./vendor/bin/sail artisan test --filter=TikTokAuthControllerTest`

Run (Compose):
`docker-compose exec php-fpm php artisan test --filter=TikTokAuthControllerTest`

Expected: PASS with timeout/one-time/race coverage.

- [ ] **Step 5: Checkpoint (no commit)**
- Keep race test deterministic and non-flaky before moving on.

---

### Task 6: Run full regression set + verification checklist

**Files:**
- Modify: `tests/Feature/TikTokAuthControllerTest.php`
- Modify: `app/Http/Controllers/API/TikTokAuthController.php`
- Modify: `resources/js/Pages/Integrations/Index.vue`
- Create: `resources/js/Pages/Integrations/Partials/TikTokShopPickerModal.vue`
- Test: backend + frontend target suites

- [ ] **Step 1: Run backend focused suites**

Run (Sail):
`./vendor/bin/sail artisan test --filter="TikTokAuthControllerTest|IntegrationControllerTest"`

Run (Compose):
`docker-compose exec php-fpm php artisan test --filter="TikTokAuthControllerTest|IntegrationControllerTest"`

Expected: PASS.

- [ ] **Step 2: Build frontend**

Run:
`npm run build`

Expected: successful Vite build.

- [ ] **Step 3: Final verification checklist**
- API uses `ApiResponse` envelope for new endpoints.
- pending-shops is POST body (no tmp key in query usage).
- cached OAuth token data is encrypted only.
- select-shop lock key uses sha256(tmp_auth_key).
- tmp key forgotten only after persist success.
- race assertions verify one success/one failure + single persist + key consumed + third call 422.
- FE removes `tiktok_select_shop` query in both success/failure.

- [ ] **Step 4: Checkpoint (no commit)**
- Prepare diff for review.

---

## Plan-level Verification Before Execution Completion

- Run formatter/linter only if already configured in repo (do not add tools).
- Ensure no unrelated file changes.
- Ensure no secrets logged or returned to FE.

---

## References

- Spec: `docs/superpowers/specs/2026-03-13-tiktok-multishop-hardening-design.md`
- Plan: `docs/superpowers/plans/2026-03-13-tiktok-multishop-hardening-implementation.md`
