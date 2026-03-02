# Phase 2 UI Design Checklist (MCP Pencil)

Updated: 2026-03-02  
Pencil file: `/Users/DNDark/Workspaces/design/affiliate/pencil-new.pen`
Contract source: `/Users/DNDark/Workspaces/affentra/docs/architecture/01-audit-contracts-and-plan.md`

## A. Contracts Alignment
- [x] Finance has global summary cards (`Unpaid Balance`, `Total Earned`, `Total Paid`)
- [x] Finance has period filter UI
- [x] Finance has sync-state feedback UI (stable + last/next sync hint)
- [x] Campaigns summary is explicitly global-scope context
- [x] Campaign rows include direct action affordance to Tracking Links
- [x] Partners has global headers (`Total`, `Active`, `New this month`)
- [x] Partners has performance period selector
- [x] Profile includes payout approval queue concept in UI

## B. Flow Coverage
- [x] Payout flow represented: pending -> processing -> paid / failed / cancelled / rejected (header hint)
- [x] Approval flow represented: approve / reject actions visible in queue rows
- [x] Readable status color semantics: success / warning / error style states present

## C. Visual Consistency (Light-first)
- [x] Primary CTA uses primary token (non-black)
- [x] Secondary actions use bordered neutral style
- [x] Tables are card-based with border + spacing consistency
- [x] Inter typography and 8pt spacing pattern preserved

## D. Remaining UI Gaps (non-blocking for this patch)
- [ ] Dedicated full screen for bulk payout approval management (beyond profile-side queue)
- [ ] Explicit empty/loading/error states for new payout approval queue
- [ ] Route-level click-through prototype wiring for “Xem Tracking Links” and “Duyệt / Từ chối”

## E. Implementation Sync Checklist (Design -> Logic -> FE)

### E1. Finance (Payouts / Settlements)
- [x] Design: summary cards + period selector + sync state row on `Payouts — Light`
- [x] Backend Logic: global stats API (`unpaid_balance`, `total_earned`, `total_paid`) bound by scope + date semantics
- [x] Backend Logic: sync lock response contract (`already_running`) and throttling
- [x] Frontend: bind cards/period selector to API payload; polling + idempotent sync button state

### E2. Campaigns
- [x] Design: explicit row action `Xem Tracking Links` + global metrics context label
- [x] Backend Logic: global stats computed from full filtered scope (not paginator)
- [x] Backend Logic: impressions snapshot rule (all-time or hidden when date filter active)
- [x] Frontend: deep-link preserve filters to Tracking Links (`campaign_id`, period, status)

### E3. Partners / CTV
- [x] Design: global headers (`Total`, `Active`, `New This Month`) + performance period row
- [x] Backend Logic: partner aggregates with date-range + scope resolver alignment
- [x] Frontend: real filter/search/date wiring, remove placeholder-only behaviors

### E4. Payout Approval (Profile Security)
- [x] Design: right panel converted to `Payout Approval Queue` with statuses/actions
- [x] Backend Logic: approve/reject endpoints + policy anti-IDOR (404 out-of-scope, 403 no capability)
- [x] Backend Logic: audit log persistence + payout review fields/indexes
- [x] Frontend: action handlers for `Duyệt / Từ chối / Xem lý do`, success/error toasts, optimistic refresh

### E5. Cross-cutting (Required before Done)
- [ ] Backend Logic: timezone/date contracts (`Asia/Ho_Chi_Minh`, inclusive boundaries)
- [ ] Backend Logic: performance indexes + EXPLAIN artifact for aggregate queries
- [ ] Frontend: empty/loading/error states across new aggregate/approval widgets
- [ ] QA: feature + security + regression tests aligned with architecture contract

## F. Current Status Summary
- UI design patch in Pencil: **Completed for current scope** (Finance, Campaigns, Partners, Profile Approval panel).
- Logic implementation sync: **In progress** (Finance/Campaigns/Partners completed in this wave; Approval flows pending).
- FE data-binding + action wiring: **In progress** (Finance/Campaigns/Partners completed in this wave; Approval flows pending).
