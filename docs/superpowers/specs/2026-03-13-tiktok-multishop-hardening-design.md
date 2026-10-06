# TikTok Multi-Shop Hardening Design (Option A)

Date: 2026-03-13
Project: affentra
Scope: Complete end-to-end TikTok OAuth multi-shop flow with session-bound security, atomic one-time consume, and test coverage.

## 1) Problem Summary

Current TikTok OAuth integration has four production risks:

1. Frontend lacks multi-shop picker flow for `tiktok_select_shop`, so multi-shop users cannot complete connection.
2. Temporary authorization key (`tmp_auth_key`) is bound to `user_id` only, not session.
3. One-time consume is non-atomic (`get` + `forget` race window).
4. Test coverage does not cover TikTok callback/pending/select-shop/race flow.

## 2) Goals

- Complete multi-shop UX from callback redirect to finalized connection.
- Bind temporary authorization flow to both authenticated user and active session.
- Make `select-shop` consume flow race-safe and one-time.
- Keep API responses consistent with project `ApiResponse` envelope.
- Add focused tests for security and concurrency edge cases.

## 3) Non-Goals

- No migration to DB-backed pending authorization table in this scope.
- No redesign of TikTok token exchange beyond required flow correctness.
- No unrelated refactors in Integrations domain.

## 4) Architecture Decisions

### 4.1 Keep Option A (Cache-backed temporary auth)

Continue using temporary cache payload created in TikTok callback, but harden by:

- Storing `session_id` in cached payload.
- Adding read-only endpoint to fetch pending shops for picker.
- Using lock-based atomic finalize for select-shop.
- Forgetting temporary key only after successful persist.

### 4.2 Security and consistency rules

- `pending-shops` and `select-shop` run under `web + auth` (session + CSRF).
- `pending-shops` uses `POST` (not `GET`) to avoid exposing `tmp_auth_key` in query paths.
- Both endpoints verify `user_id` and `session_id` ownership.
- `pending-shops` returns minimal shop fields only.
- All responses use `App\Helpers\ApiResponse`.

## 5) Backend Design

## 5.1 Data stored in temporary cache (multi-shop callback)

Cache key: existing `tiktok_tmp_auth_<random>` format.
TTL: `config('integrations.tiktok.tmp_auth_ttl', 600)`.

Payload:

- `user_id`: authenticated user id.
- `session_id`: `session()->getId()` at callback time.
- `oauth_blob_encrypted`: encrypted payload for OAuth finalize (includes token data and internal shop fields needed server-side).
- `shops`: authorized shop list (full internal data kept server-side).

## 5.2 New API endpoint: pending shops

Route:

- `POST /api/integrations/tiktok/pending-shops`
- body: `tmp_auth_key`
- middleware: `web`, `auth` (session-backed).
- must NOT be placed in stateless `api` middleware group.

Behavior:

1. Validate `tmp_auth_key` required/string.
2. Load cache payload.
3. If missing/expired/consumed => `422`.
4. Verify `user_id` + `session_id` match current auth/session.
5. Return minimal shops only:
   - `id`
   - `name`
   - `region`

Never return:

- decrypted OAuth token payload
- `shop_cipher`
- any signing/credential material

Response format:

- Success: `ApiResponse::success(['shops' => [...]])`
- Failure: `ApiResponse::error(...)` with status per section 7.

## 5.3 Hardened select-shop finalize

Route (existing):

- `POST /api/integrations/tiktok/select-shop`
- middleware: `web`, `auth` (session-backed).
- must NOT be placed in stateless `api` middleware group.
- request must pass standard web CSRF verification.

Input:

- `tmp_auth_key` required|string
- `shop_id` required|string

Atomic flow:

1. Build lock name from hashed key:
   - `tiktok_auth_lock:` + `hash('sha256', tmp_auth_key)`
2. Acquire cache lock with explicit lock TTL and wait timeout (e.g. lock TTL 10s, wait timeout 3s; configurable).
3. If lock not acquired within wait timeout => `422` with message: “Phiên đang được xử lý, vui lòng thử lại.”
4. Inside lock:
   - load cached payload,
   - validate existence,
   - verify `user_id` and `session_id`,
   - verify selected `shop_id` belongs to payload shops,
   - call `persistConnection(...)`.
5. Only if persist succeeds: `Cache::forget(tmp_auth_key)`.
6. Return success envelope with connection id.
7. Ensure lock release in finally only when lock ownership was acquired.

## 5.4 Error handling and observability

- Keep warning log for user/session mismatch with safe fields only.
- Do not log tokens.
- Do not log raw `tmp_auth_key` or full URL containing it; always redact/mask this value in logs.
- Keep explicit messages for expired/consumed/invalid shop/lock-busy.

## 6) Frontend Design (Integrations Index)

Primary file: `resources/js/Pages/Integrations/Index.vue`.

Support file (new): `resources/js/Pages/Integrations/Partials/TikTokShopPickerModal.vue`.

Flow:

1. On page init, inspect URL for `tiktok_select_shop`.
2. If present, call pending-shops endpoint via `POST` body (`tmp_auth_key`).
3. If success and shops exist, open picker modal.
4. User selects shop and confirms.
5. Submit `tmp_auth_key + shop_id` to select-shop endpoint.
6. While submitting, disable action to reduce accidental duplicates.
7. On success:
   - close modal,
   - show success feedback,
   - reload `connections`,
   - remove `tiktok_select_shop` from URL.
8. On failure:
   - `403` mismatch => show security error, close modal, clear query param.
   - `422` expired/used/invalid => show business error, close modal, clear query param.

Implementation notes:

- Keep modal focused on selection only; no token data in FE.
- Use existing feedback/toast pattern in Index page.
- Use existing route helper naming under `api.integrations.tiktok.*`.

## 7) Status / Error Mapping

- `200`: success read/finalize.
- `403`: ownership mismatch (`user_id` or `session_id` mismatch).
- `422`:
  - validation errors,
  - key expired/consumed,
  - shop not in authorized list,
  - lock acquire timeout (in-flight processing).

Envelope (always, per `ApiResponse`):

- `ok`
- `data`
- `message`
- `errors`
- `code` (nullable)

## 8) Test Plan

### 8.1 Feature tests for TikTok auth controller

Add dedicated TikTok auth feature test coverage for:

1. Callback state mismatch -> redirect with error.
2. Callback multi-shop -> redirect with `tiktok_select_shop` key.
3. Pending-shops success -> returns minimal shop fields only.
4. Pending-shops forbidden on user/session mismatch -> `403`.
5. Select-shop success -> returns `ok=true`, persists/upserts connection.
6. Select-shop key expired/used -> `422`.
7. Select-shop invalid shop id -> `422`.
8. Select-shop lock timeout -> `422` with busy message.
9. One-time semantics -> second call with same key fails `422`.

### 8.2 Race test (required)

Concurrency test for two simultaneous select-shop requests with same key:

- Both requests must use the same `tmp_auth_key` (and same `shop_id`) and be started concurrently via barrier/parallel execution.
- Exactly one request succeeds.
- The other request returns `422`.
- Assert persist/upsert side effect occurs exactly once.
- Assert key consumption happens once; second request fails `422`.
- DB result remains single correct connection/upsert target.

## 9) Rollout / Risk

- Backward compatible with existing callback redirect key.
- Main risk is cache lock behavior across cache drivers; tests should run on configured test driver and validate deterministic handling.
- If lock is unavailable, fail closed (`422`) with retry message.

## 10) Acceptance Criteria

- Multi-shop user can complete OAuth connection from callback to persisted connection.
- Pending shop list cannot be retrieved across user/session boundary.
- Select-shop cannot be double-consumed under concurrent submission.
- FE handles happy path and error path without stale `tiktok_select_shop` URL state.
- New tests pass and cover all listed scenarios.
