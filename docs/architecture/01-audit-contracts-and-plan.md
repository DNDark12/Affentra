# System Contracts & Audit Remediation Plan

> **Date**: 2026-03-02
> **Context**: System Audit Phase 11 Remediation (Phases A0 -> G)

This document serves as the absolute source of truth for metrics, date handling, data scoping, and security approvals across the system, incorporating the rigorous 8-point reinforcement plan.

---

## Part 1: System Contracts (Phase A)

### 1.1 Metric Definition Contract
**Finance Module**
The absolute source of truth for Finance is the `affiliate_billings` and `affiliate_payouts` tables.
*   **Total Earned (Net Revenue)**: `SUM(net_amount)` from `affiliate_billings` where `status` IN ('paid', 'settled').
*   **Total Unpaid (Pending Balance)**: `SUM(net_amount)` from `affiliate_billings` where `status` IN ('pending', 'processing').
*   **Total Paid**: `SUM(amount)` from `affiliate_payouts` where `status` = 'completed' (or 'success').

**Campaign & Partner Stats**
*   **Impressions**: Recognised as a **Snapshot Metric** synced directly from the Platform API (e.g., Shopee). Because this is a static snapshot, **Impressions must NOT be subjected to date-range filters**. If a date filter is applied on the UI, either hide the impression metric or clearly label it as "(All-time)".
*   **Aggregation Consistency**: Clicks, Orders, and Commission aggregated via `daily_stats` or relationship chaining must compute the total dataset within the applied filters + scope, entirely bypassing the UI paginator `count()`.

### 1.2 Date Filter Semantics
To prevent temporal discrepancies across modules (Links, Analytics, Partners), all date filtering must strictly obey:
*   **Timezone**: Operations occur in the Application Timezone (`Asia/Ho_Chi_Minh`). Database queries (`whereBetween`) must receive boundaries cast inside this timezone.
*   **Inclusive Boundaries**: 
    *   `date_from`: Cast to `00:00:00` of the given day.
    *   `date_to`: Cast to `23:59:59` of the given day.
*   **Standard Presets**:
    *   `today`: Current day bounds.
    *   `7days`: Start of (Now - 6 days) to End of Now.
    *   `30days`: Start of (Now - 29 days) to End of Now.
    *   `this_month`: Start of current month to end of current month.
    *   `last_month`: Start of previous month to end of previous month.

### 1.3 Role-Scope Matrix & Single Source of Truth
The system must employ a **unified `ScopeResolver` service** to compute access arrays.
*   **Owner**: Global. Returns `[all]` or dynamically bypasses `whereIn` clauses.
*   **Leader**: Descendants. Can access their own records + ALL deeper descendants where the user's materialized path (`path`) starts with `LeaderPath/LeaderID`.
*   **CTV (Partner)**: Self. Restricted solely to `user_id` = Context User ID.

### 1.4 Approval Security Policy & Data Integrity (Anti-IDOR)
*   **404 Not Found**: If a Leader requests to view/action a user/record *outside* their scope (determined via `ScopeResolver`), the API must respond with `404 Not Found`. Returning `403` leaks enumeration info.
*   **403 Forbidden**: If a user attempts to call an endpoint but lacks the base authorization capability entirely (e.g., `can_approve_payouts`), return `403 Forbidden`.
*   **Encryption at Rest**: Highly sensitive PII fields (`bank_account_number`, `tax_id`) in `user_profiles` must use Eloquent's `encrypted` cast. A backfill migration is required to encrypt existing plain text rows.
*   **Audit Logging**: Every `approve` or `reject` action must be written to an `audit_logs` table capturing: Actor ID, Target ID, Previous State, New State, Reason, Timestamp, and IP.

### 1.5 API Payload Contract (Global Stats & Filters)
To ensure Frontend Anti-Drift during refactoring, aggregate endpoints must return precise data shapes:

*Dashboard & Analytics Summaries:*
```json
{
  "ok": true,
  "data": {
    "period": "30days",
    "clicks": 1250,
    "orders": 45,
    "approved": 40,
    "commission": 1500000.0,
    "daily": [{ "date": "2026-03-01", "clicks": 50 }]
  }
}
```

*Finance Global Summary:*
```json
{
  "unpaid_balance": 500000.0,
  "total_earned": 2000000.0,
  "total_paid": 1500000.0
}
```

*Campaigns Global Summary:*
```json
{
  "total_campaigns": 120,
  "active_campaigns": 85,
  "total_impressions": 150000,
  "total_clicks": 25000
}
```

*Partners/CTV Global Summary:*
```json
{
  "total_partners": 35,
  "active_partners": 30,
  "new_this_month": 5
}
```

---

## Part 2: Implementation Plan (Phases A0 -> G)

### Phase A0: Immediate Hotfixes
- **Partner Creation (`StorePartnerRequest`)**: The business logic is strictly "invite via email only". Modify `StorePartnerRequest` and `PartnerService` to ONLY send an invitation email. Do NOT insert a row into the `users` table until the invitee completes the registration form. Update the FE to ensure it's purely an email dispatch flow. Wire up the missing search/filters in `Partners/Index.vue`.

### Phase A: Core Architecture & Setup
- Commit these contracts to the repo repository (`docs/architecture/`).
- Build the unified `ScopeResolver` service.

### Phase B: Finance Module
- Implement exact global stats (Earned, Paid, Unpaid) fulfilling the JSON contract.
- Add robust date filtering adhering to semantics.
- **Sync Idempotency (Double-Lock)**: Prevent spam logic. Implement `Cache::lock` BOTH in the Controller (before dispatching) and inside the Job's `handle()` method (for multi-node/retry safety). Set TTL (e.g., 5-10 mins). 
  - **Lock Fail Response Contract**: If lock acquisition fails in the Controller, the API MUST immediately return HTTP 429 or 409 with payload: `{ "ok": false, "message": "Vui lòng đợi 5 phút", "status": "already_running" }`. This ensures FE polling logic interprets it deterministically instead of treating it as a standard failure.
- **Migration (Indexes)**: Explicitly add `(user_id, period_end)` / `(user_id, status, period_end)` to `affiliate_billings` and equivalent to payouts.

### Phase C: Campaigns Module
- Fix global stats calculation bypassing pagination.
- Apply the Snapshot rule for Impressions (no date filtering applied).
- Add "View Tracking Links" deep-link.

### Phase D: Partners Module
- Implement date-filtered metrics specifically for Partner stats (utilizing ScopeResolver).
- Add Global Headers context.

### Phase E: Payout Approval (Profile Security)
- **Encryption Update**: Cast banking details as encrypted.
- **Schema Update**: Add `payout_review_status` (enum: pending, approved, rejected), `payout_reviewed_by`, `payout_reviewed_at`, and `payout_reject_reason` to `user_profiles`. **CRITICAL**: Add database indexes for `payout_review_status`, `payout_reviewed_at`, and `payout_reviewed_by` to support efficient dashboard lists.
- **Audit Logs Creation**: Create a dedicated `audit_logs` table (and models/migrations) expressly to record state transitions. Payload must include: `actor_id`, `target_id`, `previous_state`, `new_state`, `reason`, `ip_address`, and `created_at`.
- Implement strictly secure API/UI (enforcing 404/403 rules).

### Phase F: Tracking Configuration
- Decouple magic constants (presets/thresholds) into `config/tracking.php` based on currency/platform.

### Phase G: Performance & Scalability Pass
- Execute query optimization targeting `EXPLAIN` verification (see Verification section).
- Check async generation for large dataset exports.

---

## Part 3: Verification Protocol & Perf Gates

1. **Feature Tests**: For every endpoint calculating global stats, applying date filters, or enforcing role scope (`ScopeResolver`).
2. **Security Tests**: Validate 404 vs 403 on arbitrary ID insertions across hierarchical bounds.
3. **Regression Tests**: For pagination and UI contracts.
4. **Performance Gate (Mandatory)**: 
    * **Benchmark Profile**: P95 latency for `index` (list/stats) endpoints must fall $< 500ms$ locally. 
        - Dataset definition: 50,000+ Clicks, 5,000+ Orders, 20+ Partners.
        - Environment: MySQL 8.x (Docker/Sail), warm database cache, cold application cache.
    * **`EXPLAIN` Plan Validation**: Pull `EXPLAIN` query plans for all global aggregate queries and save them as markdown artifacts. This physically validates that newly added composite indexes are utilized and avoids "filesort" or full table scans.
