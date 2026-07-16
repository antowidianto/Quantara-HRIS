# HRIS Repository & Business Flow Audit Tracker

**Audit date:** 12 July 2026  
**Repository:** Quantara HRIS  
**Scope:** Backend, frontend, mobile, database migrations, security, testing, deployment, and end-to-end HR business flows  
**Document purpose:** Single tracker for closing gaps found during the July 2026 repository audit.

> This document records the implementation state observed on the audit date. Revalidate evidence before closing an item because the repository continues to change.

## 1. Executive Summary

Quantara HRIS already has broad functional coverage: Core HR, attendance, leave, overtime, payroll, recruitment, performance, training, workforce planning, employee lifecycle, SaaS tenancy, billing, and mobile capabilities.

The main risk is no longer missing feature breadth. The highest-value improvements are:

1. Enforce authorization and tenant isolation consistently.
2. Deepen payroll controls: maker-checker, immutable snapshots, retro adjustments, reconciliation, and off-cycle payroll.
3. Add integration and end-to-end tests for cross-module employee lifecycles.
4. Reduce architectural concentration in very large controllers, services, routes, and Vue views.
5. Separate production-ready capabilities from beta, placeholder, or partially wired features.

### Audit verification result

| Verification | Result |
|---|---|
| Backend PHPUnit | PASS — 1,142 tests, 3,159 assertions (revalidated 2026-07-15) |
| Backend PHPStan | PASS — 167 files, no errors (revalidated 2026-07-15) |
| Frontend Vitest | PASS — 11 files, 51 tests (revalidated 2026-07-14) |
| Frontend production build | PASS — Vite build completed |
| ID/EN locale dictionary parity | PASS |
| Authorization gate | PASS — 957 routes, 0 protected routes without declared policy |
| Permission seed audit | PASS — 211 runtime slugs, 0 unseeded |
| Fresh SQLite migrations | PASS — 129 migrations, 535 indexes, 171 tenant tables indexed, FK/integrity clean |

## 2. Status and Priority Convention

### Status

- [ ] `TODO` — not started
- [ ] `IN PROGRESS` — implementation has started
- [ ] `BLOCKED` — waiting for a decision or dependency
- [x] `DONE` — acceptance criteria verified
- [ ] `WONTFIX` — consciously rejected, with rationale recorded

### Priority

| Priority | Meaning | Target |
|---|---|---|
| P0 | Release, security, payroll correctness, or tenant-isolation risk | Before next production release |
| P1 | High operational risk or major maintainability gap | Next 2–4 weeks |
| P2 | Product maturity and scale improvement | Next 1–2 quarters |

## 3. Baseline Metrics

| Metric | Observed |
|---|---:|
| Backend controllers | 119 files |
| Backend libraries/services | 137 files |
| Backend models | 19 files |
| Database migrations | 124 files |
| Backend test files | 89 files |
| Frontend views | 168 files |
| Frontend test files | 6 files |
| Mobile test files | 5 files |
| `Routes.php` size | 1,278 logical lines |
| Route-level `permission:*` filters | 4 occurrences |

Large files observed:

| File | Lines |
|---|---:|
| `backend/app/Controllers/Api/V1/Employee/JicController.php` | 1,505 |
| `backend/app/Libraries/Recruitment/RecruitmentService.php` | 1,227 |
| `backend/app/Controllers/Api/V1/Recruitment/OnlineTestController.php` | 1,201 |
| `backend/app/Controllers/Api/V1/Payroll/BonusController.php` | 1,192 |
| `backend/app/Controllers/Api/V1/Leave/LeaveController.php` | 1,060 |
| `frontend/src/views/Employees/EmployeeModal.vue` | 1,738 |
| `frontend/src/views/Career/CareerApply.vue` | 1,574 |
| `frontend/src/views/Recruitment/PipelineBoard.vue` | 1,513 |

## 4. Master Tracking Board

| ID | Priority | Area | Work item | Status | Owner | Target | Evidence/PR |
|---|---|---|---|---|---|---|---|
| SEC-01 | P0 | Authorization | Build and enforce route-to-permission matrix | DONE | Codex | 2026-07-13 | 945 routes inventoried; 0 protected routes lack an explicit permission/ownership/super-admin/import policy; empty strict baseline rejects regressions |
| SEC-02 | P0 | Tenancy | Make tenant scoping mandatory and add CI leak tests | IN PROGRESS | Codex | — | Critical services scoped; 718 reviewed legacy findings and 0 net-new findings across 308 scanned files; repositories are inside the scanner boundary, and new controller DB access remains prohibited |
| DB-01 | P0 | Migration | Resolve duplicate migration version `2026-09-01-000009` | DONE | Codex | 2026-07-12 | Renamed menu backfill to `000011`; migration check passes |
| DB-02 | P0 | Migration | Add migration integrity and upgrade-path CI checks | IN PROGRESS | Codex | — | Integrity and fresh SQLite runtime gates active; MySQL and sanitized production upgrade jobs remain |
| PAY-01 | P0 | Payroll | Enforce maker-checker and segregation of duties | DONE | Codex | 2026-07-13 | Four-eyes flow, configurable high-value dual approval, re-approval reset, and audited break-glass verified |
| PAY-02 | P0 | Payroll | Freeze immutable calculation/finalization snapshots | DONE | Codex | 2026-07-13 | Versioned SHA-256 snapshot, immutable approved periods, adjustment/reversal records, and snapshot-based PDF verified |
| REL-01 | P0 | Reliability | Require idempotency on critical mutations | DONE | Codex | 2026-07-14 | Required route mode, universal web/mobile mutation keys, provider-native webhook dedupe, and database race test verified |
| TEST-01 | P0 | Testing | Add tenant-isolation integration suite | DONE | Codex | 2026-07-13 | Required employee, payslip/document, job/export, cache, webhook/notification, and super-admin tenant scenarios verified |
| TEST-02 | P1 | Testing | Add employee-to-payroll E2E scenarios | DONE | Codex | 2026-07-14 | All ten cross-module scenarios are covered, including statutory THR eligibility in resignation final settlement |
| TEST-03 | P1 | Testing | Add frontend Playwright critical paths | DONE | Codex | 2026-07-14 | Nineteen Chromium scenarios cover all listed critical paths, stateful retries/handoffs, candidate application/offer response, onboarding, data-quality and compensation governance, axe-core, and mobile viewport |
| ARCH-01 | P1 | Architecture | Modularize `Routes.php` by domain | DONE | Codex | 2026-07-14 | Fourteen domain registrars preserve the effective 944-route registry; `Routes.php` is now a 41-line composition root |
| ARCH-02 | P1 | Architecture | Refactor large controllers/services into use cases | DONE | Codex | 2026-07-15 | All six high-risk targets are DB-free facades/controllers backed by tenant-aware repositories and independently tested use cases; JIC workflow, notifications, and detail read-model queries are now extracted and its legacy controller exception was removed |
| FE-01 | P1 | Frontend | Expand tests for critical views and route guards | DONE | Codex | 2026-07-14 | Permission/feature/subscription guards, duplicate mutations, stale requests, payroll validation/error translation, and loading/empty/partial/failure states have automated coverage |
| FE-02 | P1 | i18n | Remove remaining hardcoded UI strings | IN PROGRESS | Codex | — | Locale dictionaries are split into twenty-four domain modules per language; all employee and cost-center views plus organization, Recruitment Pipelines, Benefit Policies, Approval Chains, Task/Contract Templates, Shift Assignments/Patterns, Allowances/Natura, PKWT alerts, complete Training flows, and the Roster Matrix were audited/migrated, reducing the reviewed legacy inventory from 3,759 to 3,148 with 0 additions |
| OPS-01 | P1 | Operations | Monitor critical cron/jobs by business outcome | DONE | Codex | 2026-07-14 | Persistent runs, metrics, alert/recovery runbook, and a fail-closed restore-evidence ledger cover all listed outcomes; execution of the real drill remains OPS-02 |
| OPS-02 | P0 | Recovery | Verify backup and perform restore drill | TODO | — | — | — |
| PROD-01 | P1 | Product | Classify feature readiness and remove false claims | DONE | Codex | 2026-07-14 | Readiness matrix reconciles advertised modules and all feature flags; removed unsupported OAuth/SSO claims, disabled SSO for all plans, and made unavailable Web Push delivery fail closed |
| PAY-03 | P1 | Payroll | Implement retro and off-cycle payroll controls | DONE | Codex | 2026-07-14 | Append-only adjustments/reversals, explicit triggers, off-cycle periods, and JIC delta against an integrity-checked finalized salary snapshot are verified |
| PAY-04 | P1 | Payroll | Implement bank and accounting reconciliation | DONE | Codex | 2026-07-13 | Persisted pre-submit exception gate, bank/register totals, duplicate-account/allocation checks, bank callback settlement, and balanced cost-center GL journal verified |
| DATA-01 | P2 | Core HR | Introduce effective-dated employee master data | DONE | Codex | 2026-07-14 | Approved effective snapshots cover organization, compensation, contracts, and benefit eligibility; JIC/transfer write history, payroll reads period-end state, and the due-date command applies scheduled changes |
| DATA-02 | P2 | Governance | Add employee data-quality dashboard | DONE | Codex | 2026-07-14 | Tenant-aware API/UI covers all seven categories, returns only safe employee samples, and is protected by analytics/compliance read policy |
| COMP-01 | P2 | Compensation | Add merit/compensation review cycle | DONE | Codex | 2026-07-14 | Bilingual API/UI and tested tenant-scoped cycle flow cover scoped budgets, salary-band metrics, calibrated proposals, distinct multi-level approvals, budget enforcement, effective payroll scheduling, letters, and immutable approval evidence |

## 5. P0 — Before the Next Production Release

### SEC-01 — Centralize and enforce authorization

**Finding:** Most API endpoints are protected by JWT, but only four route-level permission filters were observed, all around billing lifecycle actions. Other authorization may exist inside controllers, but it is harder to audit and easier to omit.

**Tasks:**

- [x] Export the effective route registry with HTTP method, handler, auth/filter-derived feature flag, permission/policy, tenant scope, and declared/missing status.
- [x] Seed all permission slugs currently referenced by runtime code and make permission seed coverage a CI gate.
- [x] Enforce route-level permission filters for payroll calculate, finalize, and disburse.
- [x] Add a regression test preventing critical payroll mutation routes from reverting to JWT-only access.
- [x] Define explicit read/manage boundaries plus dedicated approve, calculate, finalize, export, impersonate, self-service ownership, and dynamic import policies. CRUD endpoints intentionally share domain `*.manage` where splitting create/update/delete would not change role assignment; state transitions retain dedicated permissions.
- [x] Apply permission filters or a centralized ownership/super-admin/import policy layer to every protected endpoint.
- [x] Ensure menu visibility is not treated as an authorization boundary; `menus/me` itself has an account-ownership policy and every target API route is independently authorized.
- [x] Add negative authorization tests for employee, supervisor, manager, HR admin, payroll admin, finance, CFO, and auditor, plus explicit super-admin bypass and any-of policy tests.
- [x] Add an automated check that fails when a new protected route has no declared policy; the version-controlled baseline is now empty, so CI rejects every new undeclared protected route.

**Acceptance criteria:**

- Every non-public route has an explicit authorization policy.
- Unauthorized roles receive `403`, not an empty result or `404` used inconsistently.
- Payroll preparer cannot approve/finalize their own payroll.
- Permission tests run in CI.

### SEC-02 — Make tenant isolation mandatory

**Finding:** The repository has substantially more controllers/services than models, and many backend files access the database directly. This creates a risk that a new query omits `company_id` scoping.

**Tasks:**

- [x] Inventory direct database access across controllers, libraries, models, and repositories; the machine-readable scan currently covers 300 files and reports 728 reviewed heuristic findings for remediation.
- [x] Scope critical payroll period and disbursement reads/writes to the active company.
- [x] Correct disbursement payslip foreign-key lookup from `period_id` to `payroll_period_id`.
- [x] Scope approval-chain and delegation ID lookups/writes and reject cross-tenant delegation targets/chains.
- [x] Scope API key lifecycle, scopes, usage logs, and last-used updates by company; enforce explicit API-key route permissions.
- [x] Reject cross-tenant approval decisions and bulk decisions at controller and approval-engine boundaries.
- [ ] Move tenant-owned queries to scoped repositories/models.
- [x] Prohibit new direct DB access from controllers through a static check. `composer controller-db:check` tracks 103 reviewed legacy controller files / 571 calls by per-file count; a new file or any count increase fails CI while refactors may only reduce the baseline.
- [x] Verify every tenant-owned table has `company_id` and appropriate composite indexes. The fresh-schema gate proves all 166 direct tenant tables have a `company_id` index and requires every table without that column to be explicitly classified as global/infrastructure or tenant-owned through a declared parent; declared foreign-key boundaries are verified on SQLite and MySQL.
- [x] Include `company_id` in applicable unique constraints. The schema gate inventories every unique constraint on direct tenant tables that omits `company_id` and requires an exact reviewed classification as a globally addressable identifier or a key bounded by tenant-owned parent IDs; unclassified or stale constraints fail CI.
- [x] Run `SecurityScanTenantLeaks` in CI using strict mode against a stable, version-controlled fingerprint baseline.
- [x] Run the initial tenant scan: 266 files, 1,125 heuristic findings (`TABLE_NO_COMPANY_FILTER` 1,117; `MODEL_FIND_NO_SCOPE` 7; raw query 1).
- [x] Triage global-table and chained-query false positives, then create a reviewed version-controlled baseline. Global allowlisting now comes from the schema ownership classification, fluent statements and assigned builders are recognized, and focused scanner policy tests protect both behavior and the unrelated-query boundary.
- [x] Enable scanner strict mode for net-new findings. The baseline retains acknowledged legacy fingerprints; current code has 783 findings and 0 net-new findings, so the remaining debt is not represented as fixed.
- [x] Add two-tenant integration tests for employee, attendance, leave, overtime, reimbursement, loan, business trip, approval request, payslip/document, export job, cache, webhook/notification, and super-admin tenant-switch aggregates.
- [x] Test impersonation audit policy and super-admin tenant switching: impersonation requires a reason, emits a HIGH audit event, carries actor identity in a 15-minute token, and now fails closed if audit persistence is unavailable; header-based tenant switches also fail closed on audit failure. `BaseModel` scopes a selected company while retaining explicit company-0 global mode.

**Acceptance criteria:**

- No tenant-owned controller query can execute without a resolved tenant context.
- Cross-tenant fetch/update/delete tests fail safely.
- Security tenant scan is a required CI gate.

### DB-01 — Resolve duplicate migration version

**Finding:** Two migrations share the prefix `2026-09-01-000009`:

- `2026-09-01-000009_BackfillMenuTranslationKeys.php`
- `2026-09-01-000009_BackfillOrganizationKeyRoles.php`

**Tasks:**

- [x] Determine repository history: the menu translation migration was untracked at audit time and therefore had not shipped through this repository.
- [x] Rename the untracked menu translation migration to unique version `2026-09-01-000011`.
- [x] Confirm additive repair is not applicable: repository history showed the renamed menu migration was untracked and had not shipped.
- [x] Document the decision in this tracker's Decision Log and Progress Log.

**Acceptance criteria:**

- No duplicate migration version or class exists.
- Fresh install and upgrade from the latest production snapshot both succeed.

### DB-02 — Migration safety CI

**Tasks:**

- [x] Fail CI on duplicate migration timestamp/version and duplicate migration class.
- [x] Run all 119 migrations against an empty in-memory SQLite database with foreign keys enabled and `integrity_check`; enforce through Composer and CI.
- [ ] Run all migrations against MySQL/MariaDB used in production. A clean MySQL 8.4 CI service/job is configured, but this remains open until a remote CI run is observed; Docker/MySQL is unavailable in the current workspace.
- [ ] Test upgrade from a sanitized production-schema snapshot.
- [ ] Verify indexes and foreign keys after migration. SQLite verification passes (524 indexes, all 166 explicit tenant tables indexed, FK enforcement/check clean); the MySQL CI job performs equivalent metadata checks but still needs an observed run.
- [x] Require safe roll-forward handling for irreversible data migrations: integrity CI inspects only `up()` and rejects destructive DDL/DML unless the migration carries an explicit `@roll-forward-reviewed` marker and release runbook.

**Acceptance criteria:** Migration checks are required before merge.

### PAY-01 — Payroll maker-checker and segregation of duties

**Tasks:**

- [x] Record `prepared_by`, `submitted_by`, `approved_by`, and `finalized_by` separately.
- [x] Prevent a user from approving/finalizing a payroll they prepared.
- [x] Require configured approval thresholds for high-value payroll runs; `payroll.high_value_approval_threshold` requires two distinct approvers at or above the configured amount.
- [x] Require re-approval whenever material payroll values change; recalculation clears submission, approval, and snapshot evidence.
- [x] Add break-glass workflow with a minimum-length reason, dedicated elevated permission, critical audit record, and notification to payroll finalizers/break-glass holders.

**Acceptance criteria:** The API, not only the UI, enforces four-eyes control.

### PAY-02 — Immutable payroll snapshots

**Tasks:**

- [x] Snapshot employee/payslip master data, calculation breakdown, attendance/overtime totals, tax/BPJS results, cost center, and bank account evidence before approval.
- [x] Hash and version the snapshot for audit integrity.
- [x] Prevent editing or recalculating an approved/finalized period.
- [x] Correct finalized results using tenant-scoped, maker-checker adjustment/reversal records posted into a later editable payroll period.
- [x] Ensure regenerated payslips use the finalized snapshot and reject missing/corrupt snapshots.

**Acceptance criteria:** A later master-data change cannot silently change a finalized payslip.

### REL-01 — Require idempotency for critical operations

**Finding:** Idempotency support is present but described as optional when clients omit the header.

**Tasks:**

- [x] Require `Idempotency-Key` for clock-in/out.
- [x] Require it for leave, overtime, reimbursement, loan, and business-trip submissions.
- [x] Require it for approve/reject/bulk approval actions.
- [x] Require it for payroll calculate/finalize/disburse and bonus runs.
- [x] Require it for candidate offer responses.
- [x] Ensure every web and mobile state-changing request (`POST`, `PUT`, `PATCH`, `DELETE`) sends a key, including public/candidate clients and direct push/signup/auth calls; retain an existing key on retry.
- [x] Define provider-native deduplication rules for critical inbound webhooks: generic sources must send `X-HRIS-Source-Event`/`event_id`; SES uses stable SNS `MessageId` hashed into a unique provider event key.
- [x] Add concurrent-request database tests. The schema unique key `uniq_idem_company_key` on `(company_id, key_hash)` permits one race winner while allowing the same key in another tenant.
- [x] Define key TTL, payload mismatch behavior, and replay response semantics: 24-hour TTL, `422` on payload/endpoint mismatch, `409` while in flight, cached response after completion.

**Acceptance criteria:** Concurrent identical requests create exactly one business transaction.

### TEST-01 — Tenant-isolation integration suite

**Required scenarios:**

- [x] Same NIK exists in two tenants without collisions or leakage.
- [x] Tenant A cannot read/update/delete Tenant B employee through scoped models.
- [x] Tenant A cannot access Tenant B payslip or attachment via guessed ID/path through scoped models.
- [x] Background export jobs carry explicit `company_id`, scope the request lookup, and restore worker tenant context after completion.
- [x] Export ZIP integration test proves only the requesting tenant's rows and tenant-bound manifest are emitted.
- [x] Tenant-owned lookup cache keys include company ID; global regulation keys remain explicitly global and role-permission keys use globally unique role IDs.
- [x] Inbound webhooks resolve tenant from the source; SES messages propagate a company tag, ambiguous legacy recipients fail closed, and notification dispatch verifies recipient-company membership.

### OPS-02 — Backup and restore drill

**Tasks:**

- [ ] Verify database backup cadence, encryption, retention, and off-site copy.
- [ ] Include employee documents, contracts, payslips, and uploaded attendance evidence.
- [ ] Perform a restore into an isolated environment.
- [ ] Verify restored record counts, hashes, attachments, and login capability.
- [ ] Record achieved RPO and RTO.

**Acceptance criteria:** A dated restore report exists and is approved by the operational owner.

## 6. P1 — Next 2–4 Weeks

### TEST-02 — Cross-module business E2E tests

- [x] Join mid-month → attendance → prorated salary → PPh 21 → payslip. Payroll selects employees by join/effective period, consumes tenant-scoped attendance, prorates calendar-day basic salary, calculates PPh 21, and persists the payslip; boundary and handoff tests are green.
- [x] Leave/half-day → attendance → payroll deduction. Approved unpaid leave is tenant-scoped, supports stored half-day values, clips period overlaps, and emits a separate `UNPAID_LEAVE` payslip deduction.
- [x] Overtime plan → approval → realization → payroll component. Variance above 50% requires justification, realized hours use the statutory calculator, and payroll now consumes both `APPROVED` and `REALIZED` overtime.
- [x] Reimbursement → approval → payroll/payment output. Approval records actor/time, approved unpaid claims enter payroll as non-taxable `REIMB`, finalization reserves them to the period, and only a completed bank callback marks them `PAID`.
- [x] Payroll calculate → variance review → approval → finalize → disburse. Material total/employee variance blocks submit until an audited review; finalization now produces `FINALIZED` (not prematurely `PAID`), and completed disbursement advances it to `PAID`.
- [x] JIC/salary change with a past effective date → retro adjustment. Calendar-day deltas are generated per finalized source period as traceable `DRAFT` adjustments and require an editable target period plus independent payroll approval.
- [x] Resignation → final payroll → THR/severance → offboarding → access revocation. Final-payroll employee selection, tenant-scoped clearance, one-time leave/severance/eligible-THR components, cumulative two-calendar-year PPh 21 final brackets, payslip linkage, and tenant-scoped account deactivation are implemented. THR eligibility covers PKWTT employees resigning within 30 days before the matching religious holiday and applies the statutory tenure proration.
- [x] Transfer → effective date → organization/cost-center/payroll effect. Early implementation is blocked; due transfers update tenant-scoped organization fields, recompute allowances/reporting, and payroll resolves the effective cost-center snapshot.
- [x] Approval delegation when the primary approver is unavailable. Resolver queries are tenant-scoped and lifecycle modules are supported in addition to transactional approvals.
- [x] Mobile offline replay without duplicate transactions. The outbox retains the same idempotency key across failed retries; mobile TypeScript and all 29 tests pass.

### TEST-03 — Frontend Playwright critical paths

- [x] Employee login and forced password/2FA handling. Playwright submits employee credentials against a deterministic 2FA challenge and verifies the OTP state.
- [x] Clock-in/out with rejected and successful states. Geolocation is supplied by the browser context; the first clock-in returns a visible 422 geofence error and a second click succeeds.
- [x] Leave submission and manager approval. An eligible annual-leave policy initializes the form, the employee submits it, and a manager opens a pending approval detail and approves it.
- [x] Payroll review and finalize confirmation. An approved period opens the confirmation dialog; a 409 failure releases the action lock and the retry successfully posts finalization.
- [x] Candidate application and offer response. A logged-in candidate restores a complete autosaved draft, records explicit consent, submits the application, reaches success, then reviews and accepts a sent offer.
- [x] Tenant admin setup/onboarding. The admin loads persisted onboarding progress, completes the remaining checklist step, reloads progress, and reaches the 100% tenant-active state.
- [x] Accessibility scan with axe-core. Login has zero serious/critical violations; the discovered password-toggle contrast defect was corrected.
- [x] Mobile viewport coverage for employee self-service. The 390×844 login journey verifies visible identifier and sign-in controls.

### ARCH-01 — Modularize routes

Suggested structure:

```text
Config/Routes/
├── PublicRoutes.php
├── AuthRoutes.php
├── EmployeeRoutes.php
├── TimeRoutes.php
├── PayrollRoutes.php
├── TalentRoutes.php
├── RecruitmentRoutes.php
├── AdminRoutes.php
└── IntegrationRoutes.php
```

- [x] Preserve route order where wildcard matching depends on order. The effective registry remains 957 routes and the employee-specific-before-wildcard assertion passes after extraction.
- [x] Keep permission and feature policy next to each route. Registrars move complete route declarations, including their filter arrays; strict route-policy export remains 0 missing.
- [x] Add route registration/snapshot tests before splitting. The snapshot locks 944 effective routes, critical wildcard order, and payroll mutation filters.

### ARCH-02 — Refactor large controllers and services

Start with the highest-risk files:

- [x] `JicController.php` — list/detail reads, draft mutations, workflow transitions, notification resolution, and organization/actor lookups are tenant-aware repository/use-case boundaries; the HTTP controller has 0 direct DB calls and approval/consent/cancellation invariants have two-tenant SQLite coverage.
- [x] `RecruitmentService.php` — reduced to a DB-free facade/domain analyzer; tenant reads, atomic stage/reject transitions, hire conversion with company-qualified row lock, document carry-over, and contract generation live behind repository/use-case boundaries.
- [x] `OnlineTestController.php` — tenant reads, assessment engine, and business mutations are repository/use-case backed; spreadsheet normalization and the built-in seed catalog are independently tested, leaving 0 direct DB calls in the HTTP controller.
  - Admin test/question-bank/assignment/attempt graph reads and writes are tenant-bound at the first query; candidate access derives company ownership from the owned assignment. The service engine requires `company_id` for start/resume/save/submit/result/auto-submit/manual-grade and resolves attempts/answers only through tenant-owned assignments. Pagination/detail reads use `OnlineTestRepository`; mutations use `OnlineTestAdminService`; spreadsheet normalization and seed catalogs are pure helpers.
- [x] `BonusController.php` — HTTP orchestration is DB-free; scheme/run/recipient reads require explicit company context, state transitions and aggregate updates are transactional use cases, and guessed cross-tenant IDs are covered by SQLite policy tests.
- [x] `LeaveController.php` — reduced to DB-free HTTP/integration orchestration; policy windows and eligibility are pure/testable, while tenant-scoped create/approve/reject/cancel transactions atomically protect balances and attendance.
- [x] `PayrollService.php` — reduced to a DB-free orchestration facade; tenant-owned persistence, employee calculation, pending-approval scans, and immutable finalization are isolated behind repository/use-case boundaries with fail-closed tenant tests.

Target pattern:

```text
Controller → Request validation → Authorization policy → Use case
Use case   → Domain rules → Repository transaction → Domain event/outbox
```

**Acceptance criteria:** Controllers orchestrate HTTP concerns only; business invariants are testable without HTTP.

### FE-01 — Frontend critical test coverage

- [x] Route guards and permission-based actions. A pure navigation policy is covered for single and any-of permission metadata.
- [x] Feature flags and subscription restrictions. Unknown/disabled features fail closed and read-only subscriptions redirect mutation routes.
- [x] Approval double-click and stale state handling. Keyed async actions coalesce duplicate mutations and latest-request guards reject stale success/error updates.
- [x] Payroll finalize confirmation and error recovery. Finalization remains confirmed, disables while pending, and releases its action lock in `finally` so failure can be retried.
- [x] Form validation and backend error-code translation. Payroll period input is validated before mutation; stable backend codes and field-error payloads have pure regression tests.
- [x] Loading, empty, partial, and failure states. `useResource` exposes explicit state while retaining partial rows on refresh failure and suppressing stale responses.
- [x] Request race/cancellation behavior. `useLatestRequest` invalidates older responses and has success/error race regression tests.
- [x] Set an initial coverage threshold and increase it gradually. Vitest starts at lines/functions/statements 20% and branches 15%.

### FE-02 — Complete internationalization

- [x] Scan Vue templates for hardcoded visible strings and placeholders. The version-controlled scan currently inventories 3,148 reviewed legacy occurrences across Vue template text and visible attributes, down from the 3,759 baseline with 0 additions.
- [x] Migrate remaining strings in organization views. The organization chart, recursive department cards, department drill-down/moves, supervisor synchronization, hierarchy editing, contextual help, Organization Roles CRUD/assignment, and the SimpleMaster-based branch, location, department, position, job-title, and job-grade views use paired ID/EN translations.
- [ ] Migrate the remaining reviewed legacy strings domain by domain. The current ratcheted inventory is 3,148 occurrences; Shift Assignments/Patterns and Allowances/Natura are complete, and the next domain will be selected from the largest remaining reviewed inventory.
- [x] Split the ~3,000-line locale files by domain. `en.js` and `id.js` are now small aggregators over twenty-four paired domain dictionaries, including dedicated Allowances/Natura, Benefit Policies, Recruitment Pipelines, Organization Roles, Organization Structure, Cost Center, Training, Roster Matrix, Employee Form, Employee Modal, Employee 360, Employee List, and Contract Signing modules.
- [x] Keep locale key parity test in CI.
- [x] Add a test/lint rule for untranslated visible strings. `npm run i18n:check` rejects a new literal or any count increase and runs in the frontend CI job.

### PAY-03 — Retro and off-cycle payroll

- [x] Define retro triggers: salary, allowance, attendance, tax status, BPJS, transfer, and contract corrections.
- [x] Calculate delta against the original finalized snapshot. Retro JIC resolves the salary basis from the SHA-256-verified finalized snapshot and fails closed on missing/tampered evidence.
- [x] Post corrections in a controlled adjustment/off-cycle period.
- [x] Show original-period reference and adjustment line in the target payslip; target gross/deduction/net totals include the correction.
- [x] Prevent silent historical rewrites through append-only adjustment/reversal records and immutable approved snapshots.

### PAY-04 — Reconciliation and accounting controls

- [x] Payroll register versus bank file reconciliation.
- [x] Disbursement callback/status reconciliation.
- [x] Duplicate account and duplicate payment detection.
- [x] GL journal generation with balanced debit/credit validation.
- [x] Cost-center and branch allocation validation.
- [x] Month-over-month payroll variance report with configurable threshold, total-net comparison, per-employee new/missing/material exceptions, persisted review evidence, and first-period baseline handling.
- [x] Unresolved exception evidence is persisted and blocks payroll submission until register, bank-account, duplicate, and cost-center issues are resolved.

### OPS-01 — Business job observability

Track more than process uptime:

- [x] Payroll calculation duration and failures. Calculation runs persist outcome, duration, tenant, job, and request correlation.
- [x] Stuck approval requests by SLA. Metrics expose pending approvals older than the configured threshold.
- [x] Cron last-success timestamp and processed record count. Persistent business-job runs retain start/finish timestamps and result context.
- [x] Notification/email/push delivery failure rate. Metrics expose failed email delivery records; push requires a production channel before its rate can be meaningful.
- [x] Webhook retry/dead-letter count. Metrics expose failed/dead webhook deliveries.
- [x] Tenant-leak scan result. Scanner execution is recorded as a business job and retains safe summary context.
- [x] Backup age and last successful restore drill. Metrics expose both ages (`-1` means never); recording a PASSED drill requires complete source-backup/count/hash/attachment/login evidence. The real isolated drill itself remains required under OPS-02.
- [x] Alerts include tenant, request ID, job ID, and safe diagnostic context. Sensitive keys such as password, token, secret, bank data, NIK, and NPWP are scrubbed before persistence.

### PROD-01 — Feature readiness audit

Classify every advertised module as:

- [x] Production-ready
- [x] Beta
- [x] Backend only
- [x] Frontend only
- [x] Partially wired
- [x] Feature-flagged
- [x] Placeholder
- [x] Deprecated

Verified correction: the Web Push subscription endpoint is implemented and tenant-scoped. VAPID delivery remains Beta because `minishlink/web-push` is optional; the channel now fails closed when either required class is absent.

**Acceptance criteria:** README, pricing/feature flags, menus, API, and operational capability describe the same reality.

Evidence: `docs/02_Business_Features/FEATURE_READINESS_MATRIX_2026-07-14.md` and `FeatureReadinessPolicyTest.php` (3 tests, 21 assertions). Unsupported OAuth 2.0 wording was removed and the unimplemented SSO entitlement is disabled for every plan.

## 7. P2 — Product Maturity Roadmap

### DATA-01 — Effective-dated Core HR data

- [x] Compensation history with `valid_from` / `valid_to`. Approved snapshots preserve salary and fixed-allowance periods without rewriting prior rows.
- [x] Position, manager, department, branch, cost center, and grade history. Tenant-validated references are captured in every effective snapshot; JIC and transfer implementation also append workflow-sourced snapshots.
- [x] Contract and benefit eligibility history. Contract dates/type and the applicable benefit-policy IDs are stored with each snapshot.
- [x] As-of-date queries for payroll and audit. Read endpoints expose history and a date-specific snapshot; payroll overlays the period-end snapshot before calculation.
- [x] Future-dated changes with approval and scheduled application. `employee.manage` creates approved, idempotent schedules and `hr:apply-effective-changes` applies due rows transactionally.

### DATA-02 — Employee data-quality dashboard

- [x] Missing/invalid NIK and NPWP. Values are decrypted and validated server-side but never returned by the dashboard.
- [x] Missing or duplicate bank account. Normalized plaintext is used only in-memory for tenant-local duplicate detection and never exposed.
- [x] BPJS registration mismatch. Missing and invalid Health/Employment registration formats are distinguished.
- [x] Expired contract/document/certification. Historical contract rows do not create false positives; current employee contract dates and active, non-deleted documents/certifications are evaluated.
- [x] Missing manager, position, cost center, or work location.
- [x] Salary below applicable minimum wage. The latest matching UMK is preferred, with UMP fallback.
- [x] Orphaned user/employee/organization references. Every reference join is company-qualified to detect cross-tenant or missing targets safely.

### COMP-01 — Compensation review cycle

- [x] Merit budget by company/department/cost center. Draft cycles accept either one company-wide budget or unique department/cost-center scopes, with tenant ownership validation.
- [x] Compa-ratio and salary range penetration. Both metrics are persisted from the employee job-grade salary band for every proposal.
- [x] Performance/calibration input. A bounded 0–5 performance score and calibration narrative are retained with the proposal.
- [x] Manager proposal and multi-level approval. Managers submit through an idempotent mutation; 1–5 approval levels require distinct approvers and prohibit self-approval.
- [x] Budget overrun controls. Final approval fails closed when the matching scope has no budget or the approved increase would exceed it.
- [x] Effective date and payroll integration. Final approval transactionally creates an approved effective-history salary snapshot consumed by period-end payroll.
- [x] Employee letter and audit trail. Approved proposals expose a stable letter reference and safe letter payload; each approval/rejection is append-only evidence by level and actor.

### Other candidate capabilities

- [ ] Position/headcount control: approved, occupied, vacant, and frozen positions.
- [ ] Private employee relations/case management separate from general helpdesk.
- [ ] Skill gap → learning plan → certification → promotion eligibility.
- [ ] Overtime fatigue and absence-risk controls.
- [ ] Versioned business-rule configuration with effective dates.
- [ ] Payroll configuration sandbox and comparison simulation.
- [ ] Audit evidence pack for payroll, tax, BPJS, and data privacy.

## 8. Business Lifecycle Definition of Done

### Hire-to-retire

- [ ] Approved requisition/headcount precedes recruitment where required.
- [ ] Candidate acceptance creates onboarding without duplicate employee records.
- [ ] Contract, payroll, attendance, access, assets, and BPJS setup are tracked.
- [ ] Transfers and JIC changes respect approval and effective dates.
- [ ] Resignation/termination calculates final obligations and revokes access.
- [ ] Retention/deletion policies run after legal retention periods.

### Time-to-pay

- [ ] Shift/roster determines expected attendance.
- [ ] Attendance anomalies are resolved before payroll cut-off.
- [ ] Leave and overtime approvals are frozen at cut-off.
- [ ] Payroll uses versioned rates and employee snapshots.
- [ ] Variances and exceptions are reviewed before approval.
- [ ] Finalization is immutable and segregated from preparation.
- [ ] Bank and accounting outputs reconcile to the payroll register.

### Recruit-to-onboard

- [ ] Consent and privacy notice are recorded.
- [ ] Duplicate candidate detection is tenant-scoped.
- [ ] Assessment integrity and result access are controlled.
- [ ] Offer approval precedes candidate response.
- [ ] Accepted offer data maps into Core HR with validation.

### Performance-to-reward

- [ ] Goals are approved and versioned.
- [ ] Review, calibration, and acknowledgement are auditable.
- [ ] Reward recommendations respect compensation budgets.
- [ ] Approved changes become future-dated compensation records.
- [ ] Payroll consumes them only from their effective date.

## 9. Release Gate Checklist

Before every production release:

- [x] Backend tests pass. Latest full gate: 1,142 tests / 3,159 assertions; PHPStan reports 0 errors across 167 files, including repositories.
- [x] Frontend tests, lint, and production build pass. Latest: 51 Vitest tests, 19 Playwright scenarios, lint with no errors (112 legacy warnings remain), i18n ratchet clean, and Vite production build green.
- [x] Mobile type check/tests pass when mobile code changes. Latest: TypeScript clean and 29 tests green.
- [ ] Migration duplicate and upgrade-path checks pass. Duplicate/fresh-SQLite gates pass; observed MySQL and sanitized-production upgrade remain open under DB-02.
- [x] Tenant-leak scan passes. Strict gate: 718 acknowledged legacy findings, 0 new across 308 scanned controller/library/model/repository files.
- [x] Authorization matrix contains all new/changed routes. Latest: 945 routes, 0 undeclared protected routes.
- [x] Critical mutation endpoints have idempotency behavior.
- [x] Payroll calculation golden scenarios pass when payroll code changes.
- [x] No secrets or production PII are committed. The local/CI tracked-file gate covers runtime `.env`, private/signing keys, known live-token formats, and production dump artifacts; the discovered Android release keystore was removed from tracking and release builds now fail closed without environment-provided signing secrets.
- [x] Rollback/roll-forward plan is documented in `docs/06_DevOps_Ops/DEPLOYMENT.md`; destructive forward migrations require an explicit review marker and runbook.
- [x] Metrics, alerts, and runbooks cover new background jobs. See `docs/06_DevOps_Ops/BUSINESS_JOB_RUNBOOK.md` for thresholds, correlation, idempotent retry, reconciliation, and escalation.
- [x] Feature documentation matches actual readiness through `docs/02_Business_Features/FEATURE_READINESS_MATRIX_2026-07-14.md` and its policy test.

## 10. Decision Log

Record decisions that change scope or acceptance criteria.

| Date | ID | Decision | Rationale | Decided by |
|---|---|---|---|---|
| 2026-07-13 | DB-01 | Rename the unshipped menu translation migration to `000011`; do not add a repair migration | The duplicate file was untracked at audit time, so no deployed migration history was rewritten | Codex, based on repository evidence |

## 11. Progress Log

| Date | IDs updated | Summary | Evidence |
|---|---|---|---|
| 2026-07-12 | Initial audit | Created repository and business-flow improvement tracker | Backend tests, frontend tests, and build passed during audit |
| 2026-07-12 | DB-01, DB-02 | Removed duplicate migration identity and added `composer migration:check` as a CI gate | `php scripts/check-migrations.php` |
| 2026-07-12 | SEC-01 | Seeded six runtime permission gaps and added `composer permission:check` to CI | `php scripts/audit_permissions.php` |
| 2026-07-12 | SEC-01 | Enforced permissions on payroll calculate/finalize/disburse and added sensitive-route regression tests | `SensitiveRoutePolicyTest.php` |
| 2026-07-12 | SEC-02 | Scoped critical payroll/disbursement operations to company context and corrected the payslip period key | `PayrollPeriodController.php`, `DisbursementController.php` |
| 2026-07-12 | SEC-02, TEST-01 | Established tenant-scan baseline metrics: 266 files and 1,125 heuristic findings; strict CI deferred pending triage | `security:scan-tenant-leaks` report |
| 2026-07-12 | SEC-01, SEC-02 | Enforced approval-chain route permissions and tenant-scoped approval delegation/chain mutations | Approval controllers and routes |
| 2026-07-12 | SEC-01, SEC-02 | Enforced API-key view/manage route policies and company-scoped key rotation, revocation, scopes, and usage analytics | API key controller/service |
| 2026-07-12 | SEC-02 | Added tenant guards to approval inbox, authorization checks, individual decisions, and bulk decisions | Approval chain controller and engine |
| 2026-07-13 | REL-01 | Made idempotency mandatory after authentication for attendance, submissions, approvals, payroll, bonus runs, and candidate offer responses; web/mobile clients now generate keys automatically | `IdempotencyPolicyTest.php`, frontend idempotency tests, `php spark filter:check` |
| 2026-07-13 | REL-01 | Closed webhook and concurrency gaps: required provider event IDs, deduplicated SES events with a unique hash, and verified one database reservation winner per tenant | `WebhookIdempotencyPolicyTest.php`, `IdempotencyConcurrencyTest.php`, migration `000013` |
| 2026-07-13 | PAY-01, PAY-02 | Added calculate-submit-approve-finalize controls, preparer separation, approval reset on recalculation, and versioned SHA-256 payroll snapshots | `PayrollControlService.php`, `PayrollControlPolicyTest.php`, migration `000014` |
| 2026-07-13 | PAY-01 | Added configurable high-value dual approval and audited break-glass finalization with reason and stakeholder notification | `PayrollControlService.php`, `PayrollPeriodController.php`, migration `000015` |
| 2026-07-13 | PAY-02, PAY-03 | Added tenant-scoped post-finalization adjustments/reversals and forced PAID/CLOSED payslip PDF regeneration from the integrity-checked snapshot | `PayrollAdjustmentService.php`, `PayslipController.php`, migration `000016` |
| 2026-07-13 | PAY-01, PAY-02 | Closed payroll controls after full regression and static analysis | PHPUnit 994/994 (2,338 assertions), PHPStan 132 files, Vitest 30/30, Vite production build |
| 2026-07-13 | DB-02 | Added a fresh in-memory SQLite migration gate; fixed MySQL-only update joins, an InnoDB-only table attribute, and invalid permission seed columns exposed by the gate | `composer migration:fresh-sqlite`: 116 migrations, FK enabled, integrity OK |
| 2026-07-13 | SEC-02 | Made tenant scan CLI flags effective, added stable occurrence-counted fingerprints, version-controlled the current legacy baseline, and enabled strict net-new CI enforcement | `composer tenant:check`: 269 files, 1,106 baseline findings, 0 new |
| 2026-07-13 | SEC-01 | Added a generated effective route-policy matrix that makes undeclared authorization visible instead of treating JWT as authorization | `composer route:matrix`: 967 routes, 888 protected routes still undeclared |
| 2026-07-13 | SEC-02, TEST-01 | Added real two-tenant SQLite integration tests for duplicate NIK, employee mutations, guessed payslip/document IDs, and super-admin switching; fixed `BaseModel` leaking cross-company data when a super-admin had selected a tenant | `TenantIsolationIntegrationTest.php`: 4 tests, 13 assertions |
| 2026-07-13 | SEC-02, TEST-01 | Repaired queued tenant export wiring (`dispatch`, concrete job, company-bound request guard), restored tenant context between long-lived worker jobs, and verified the generated ZIP excludes the other tenant | `TenantIsolationIntegrationTest.php`: 5 tests, 20 assertions |
| 2026-07-13 | SEC-02, TEST-01 | Tenant-qualified SES webhook recipients and notification dispatch; ambiguous duplicate emails now fail closed instead of updating the first cross-tenant match | `EmailWebhookTenantPolicyTest.php`, `CacheServiceTest.php` |
| 2026-07-13 | DB-02 | Added a clean MySQL 8.4 migration CI service with FK/index metadata assertions and made destructive `up()` migrations fail integrity CI without explicit roll-forward review | MySQL job configured in `ci.yml`; local SQLite gate: 116 migrations, 497 indexes, FK/integrity clean |
| 2026-07-13 | SEC-01 | Enforced route-level RBAC across platform administration, master data, employee, attendance, leave, overtime, payroll, payslip, reimbursement, loan, and business-trip endpoints; added any-of permission enforcement for self/all and maker/approver access | `composer permission:check` clean; matrix reduced from 888 to 620 undeclared routes |
| 2026-07-13 | SEC-01 | Added a version-controlled authorization-route baseline and CI gate that rejects every new JWT-protected endpoint without a declared policy | `composer route:check`: 943 routes, 620 acknowledged legacy gaps, 0 new |
| 2026-07-13 | SEC-01 | Centralized single/any-of permission evaluation through `TenantContext` and added negative 403 coverage for employee, supervisor, manager, HR admin, payroll admin, finance, CFO, auditor, and super-admin behavior | `RoleAuthorizationNegativeTest.php`: 10 tests, 26 assertions; PHPStan clean |
| 2026-07-13 | SEC-01 | Split read/manage policies for cost-center budgets and organization-structure mutations, then ran full backend regression | `composer route:check`: 943 routes, 597 legacy gaps, 0 new; PHPUnit 1,016 tests / 2,418 assertions |
| 2026-07-13 | TEST-02 | Implemented calendar-day basic-salary proration for join/resignation inside a payroll period while retaining contractual salary as the overtime/BPJS basis; covered the handoff into PPh21 | `PayrollProrationBusinessFlowTest.php`: 3 tests, 6 assertions; PHPStan clean |
| 2026-07-13 | TEST-02 | Verified the proration change against the complete backend suite | PHPUnit 1,019 tests / 2,424 assertions |
| 2026-07-13 | SEC-01 | Added self/all/handler authorization boundaries to the complete helpdesk ticket lifecycle | Permission audit clean; `composer route:check`: 586 legacy gaps, 0 new |
| 2026-07-13 | SEC-01, REL-01 | Enforced read/manage/approve/implement/execute policies and idempotent state transitions across severance, disciplinary, resignation, offboarding, JIC, employee transfer, and termination workflows | Matrix reduced 586 → 509; `LifecycleRoutePolicyTest.php`: 10 tests / 17 assertions; permission audit and PHPStan clean |
| 2026-07-13 | SEC-01, REL-01 | Added view/submit/approve/fulfill controls to MPP and mapping/view/post controls to ERP journals, including idempotent request and journal state transitions | Matrix reduced 509 → 476; permission audit and targeted regression clean |
| 2026-07-13 | SEC-01, REL-01 | Protected signing provider/envelope lifecycle and recruitment job/candidate/offer lifecycle with separated view/manage/hire/respond permissions and idempotent state transitions | Matrix reduced 476 → 430; permission audit and targeted regression clean |
| 2026-07-13 | SEC-01 | Added an explicit account/employee ownership boundary for self-service routes; account, session, avatar, and device routes now declare ownership instead of receiving an unrelated admin permission | Matrix reduced 430 → 416; `OwnershipPolicyFilterTest.php` 3 tests / 5 assertions; PHPStan clean |
| 2026-07-13 | SEC-01, REL-01 | Applied own/view/manage policies to IDP and view/manage/approve policies to bonus schemes, runs, recipients, and employee history; critical workflow transitions remain idempotent | Matrix reduced 416 → 387; permission audit and targeted regression clean |
| 2026-07-13 | SEC-01, REL-01 | Enforced view/manage/self-complete policies for onboarding tasks, view/manage policies for exit interviews, employee-360 read policy, and succession/talent-pool boundaries | Matrix reduced 387 → 352; permission audit and targeted regression clean |
| 2026-07-13 | SEC-01, REL-01 | Split asset/work-location read and mutation access, then enforced cycle/goal/review/360/pulse-survey view/manage/conduct/approve/self-response boundaries with idempotent transitions | Matrix reduced 352 → 296; permission audit and targeted regression clean |
| 2026-07-13 | SEC-01, REL-01 | Enforced training/certificate/budget/register/approve policies and project/timesheet submit/approve/report/self ownership, including idempotent status transitions | Matrix reduced 296 → 259; permission audit and targeted regression clean |
| 2026-07-13 | SEC-01, REL-01 | Protected regulations, contract templates/generated contracts, employee signing/pending-contract ownership, delegation self/admin actions, and assigned-approver inbox/decisions | Matrix reduced 259 → 227; permission audit and targeted regression clean |
| 2026-07-13 | SEC-01, REL-01 | Enforced category/pipeline/test/question-bank/assignment/attempt view/manage/grade boundaries across online assessment, with idempotent cancellation and grading | Matrix reduced 227 → 188; permission audit and targeted regression clean |
| 2026-07-13 | SEC-01, REL-01 | Added seeded webhook view/manage permissions, protected outbound/inbound webhook configuration and rotation, and applied account ownership/broadcast/settings policies to notifications and email settings | Matrix reduced 188 → 156; permission audit, webhook tenant tests, and targeted regression clean |
| 2026-07-13 | SEC-01, REL-01 | Protected roster/shift-pattern/assignment and employee-license lifecycle; introduced a dynamic import boundary that enforces each `ImportRegistry` resource permission before controller execution | Matrix reduced 156 → 120; `ImportPermissionFilterTest.php` 2 tests / 4 assertions; PHPStan clean |
| 2026-07-13 | SEC-01, REL-01 | Completed route authorization across self-service/privacy, payroll simulation/THR, talent, competency, succession, analytics, AI, workforce, compensation, upload, license, and remaining lifecycle endpoints; reset the acknowledged route baseline to empty | `composer route:check`: 943 routes, 0 undeclared; permission audit: 211 used / 0 unseeded; PHPUnit 1,034 tests / 2,450 assertions; PHPStan clean |
| 2026-07-13 | SEC-02, DB-02, TEST-01 | Added a schema gate and additive migration for tenant indexes, scoped attendance clock-in/out reads and writes by active company, and expanded two-tenant destructive-access tests across seven additional business aggregates | Fresh SQLite: 117 migrations, 518 indexes, 164 tenant tables indexed; tenant scan 1,097 current / 0 new; tenant integration 6 tests / 48 assertions; PHPStan clean |
| 2026-07-13 | SEC-02 | Made impersonation and super-admin tenant switching fail closed when the privileged audit record cannot be persisted; tenant branch lookup is now company-scoped | `ImpersonationAuditPolicyTest.php`: 2 tests; central authorization regression and PHPStan clean |
| 2026-07-13 | SEC-02 | Replaced unscoped menu lookups with an explicit tenant-or-global model boundary, removed raw health-check/model false positives from the scanner, taught the scanner to recognize tenant-keyed insert payloads, and company-scoped attendance, overtime, leave, reimbursement, benefit-balance, and loan reads/writes | Tenant findings reduced 1,097 → 966 with 0 new; `CriticalTransactionTenantPolicyTest.php` 5 tests / 27 assertions; leave/overtime regression and PHPStan clean |
| 2026-07-13 | SEC-02, TEST-02 | Scoped attendance-change and SPPD expense mutations, then made the payroll orchestrator require an active company and tenant-scope period, recurring component, attendance, overtime, reimbursement, loan, payslip, installment, contract, and notification data | Tenant findings 966 → 939; business-trip smoke 33/33; payroll controls 11 tests / 47 assertions; tenant-policy regression 6 tests / 37 assertions; PHPStan clean |
| 2026-07-13 | TEST-02 | Connected approved unpaid leave to payroll deductions, including half-day values and payroll-period overlap clipping | `PayrollProrationBusinessFlowTest.php`: 5 tests / 10 assertions; PHPStan clean |
| 2026-07-13 | TEST-02 | Extracted the overtime plan-versus-realization variance rule and fixed payroll to include realized overtime instead of silently excluding it | `OvertimeCalculatorTest.php`: 3 tests / 16 assertions; PHPStan clean |
| 2026-07-13 | TEST-02 | Verified reimbursement approval metadata, non-taxable payroll aggregation, and final paid-period handoff | `ReimbursementPayrollBusinessFlowTest.php`: 2 tests / 10 assertions; PHPStan clean |
| 2026-07-13 | TEST-02, PAY-04 | Added persisted month-over-month payroll variance analysis and review gating; corrected lifecycle semantics from approval → `FINALIZED` → disbursement → `PAID`, tenant-scoped the Iris payload/update boundary, and normalized the MySQL status enum | `PayrollVarianceBusinessFlowTest.php`; fresh SQLite 119 migrations; targeted payroll 10 tests / 56 assertions; route matrix 944/0; PHPStan 133 files clean |
| 2026-07-13 | TEST-02, PAY-03 | Connected past-effective JIC salary changes to calendar-day retro deltas and traceable draft adjustments; added lifecycle trigger taxonomy and off-cycle periods without mutating historical payslips | Retro/off-cycle targeted suite 8 tests / 39 assertions; fresh SQLite 122 migrations; frontend locale parity and production build clean |
| 2026-07-13 | TEST-02, SEC-02 | Tenant-scoped approval delegation, enabled lifecycle delegation modules, blocked pre-effective transfers, and verified stable-key mobile outbox retry | Mobile TypeScript clean; mobile 24/24 tests; tenant findings 939 → 925 with 0 new |
| 2026-07-13 | PAY-04 | Added persisted pre-submit reconciliation exceptions for register totals, bank data, duplicate accounts, and cost centers; moved loan/reimbursement/PKWT settlement behind completed bank callback and repaired live Iris/ERP payroll schema mappings | Accounting/reconciliation suite 36 tests / 102 assertions; PHPStan clean; fresh SQLite 121 migrations at reconciliation checkpoint |
| 2026-07-13 | FE-01, OPS-01 | Added fail-closed route guard policy and initial frontend coverage thresholds; persisted safe business-job outcomes and exposed payroll/approval/email/webhook/backup metrics | Guard/idempotency 8 tests; business observability 3 tests / 22 assertions; mobile 24/24 |
| 2026-07-13 | TEST-02, SEC-02 | Connected resignation clearance and final settlement to tenant-scoped one-time payroll components, included resigned/inactive employees in their final period, linked generated payslips back to resignation, and scoped account deactivation | Final-payroll/offboarding 31 tests / 70 assertions; official PMK 16/2010 bracket verification; tenant findings 925 → 905 with 0 new |
| 2026-07-13 | ARCH-01 | Added an effective route registry snapshot before domain extraction and prepared the payroll registrar | 944-route snapshot; wildcard order and payroll policy assertions green |
| 2026-07-14 | ARCH-01 | Activated nine domain route registrars while retaining declaration order and adjacent permission/feature filters | Route snapshot remains 944; strict policy matrix 0 missing; registrar PHPStan clean |
| 2026-07-14 | FE-01 | Added reusable duplicate-mutation locking and latest-request race suppression, then applied both to payroll period review/finalization | Async/guard targeted suite 9 tests; frontend lint clean |
| 2026-07-14 | FE-01 | Closed frontend state coverage with payroll form validation, API error-code/field translation, explicit resource states, partial-data retention, and stale refresh suppression | FE targeted suite 17 tests; frontend lint clean |
| 2026-07-14 | PROD-01 | Published a feature-readiness source of truth, removed false OAuth/SSO claims, disabled placeholder SSO entitlements, tenant-scoped Web Push subscriptions, and made unavailable delivery fail closed | `FeatureReadinessPolicyTest.php` 3 tests / 21 assertions; targeted PHPStan clean |
| 2026-07-14 | PAY-03, ARCH-01 | Bound retro salary deltas to the integrity-checked finalized snapshot and made static route-policy tests consume all modular registrars | Backend PHPUnit 1,071 tests / 2,689 assertions; PHPStan clean; tenant scan 903 legacy / 0 new |
| 2026-07-14 | SEC-02, ARCH-02 | Added a ratcheting CI gate that prohibits new direct database access in controllers and detects additions even inside legacy files | `composer controller-db:check`: 106 legacy files / 634 calls / 0 additions; Composer manifest valid |
| 2026-07-14 | SEC-02, ARCH-02 | Extracted the JIC list/inbox query into a tenant-aware repository; non-super-admin requests fail closed without company context and every employee/organization join is tenant-qualified | JIC repository policy 3 tests / 13 assertions; PHPStan clean; direct controller calls 634 → 633; tenant findings 903 → 902 / 0 new |
| 2026-07-14 | SEC-02, ARCH-02 | Moved JIC detail/approver access reads to the repository and tenant-qualified every touched JIC mutation and notification lookup | Targeted JIC 7 tests / 44 assertions; PHPStan clean; direct controller calls 633 → 630; tenant findings 902 → 887 / 0 new |
| 2026-07-14 | TEST-02 | Completed the resignation business flow with tenant-aware statutory THR eligibility, full/prorated entitlement, one-time payroll posting, and settlement audit evidence | `ResignationFinalPayrollBusinessFlowTest.php`: 6 tests / 27 assertions; targeted PHPStan clean |
| 2026-07-14 | SEC-02, DB-02 | Closed schema-classification blind spots: every direct tenant table is indexed, every non-tenant-column table has a reviewed ownership class/parent boundary, and every tenant unique constraint without `company_id` is explicitly justified | Fresh SQLite: 124 migrations, 522 indexes, 166 direct tenant tables, 45 classified indirect/global tables; FK/integrity clean |
| 2026-07-14 | SEC-02 | Reworked tenant-scan triage to consume the reviewed global-table classification and recognize fluent/assigned query builders, then regenerated the occurrence-counted baseline under the clarified semantics | `TenantLeakScannerPolicyTest.php`: 4 tests / 8 assertions; 276 files, 866 reviewed findings, 0 new; PHPStan clean |
| 2026-07-14 | ARCH-01 | Completed route modularization by extracting the remaining master, core, development, lifecycle, and platform-evolution declarations into domain registrars | Root `Routes.php` 921 → 41 lines; 14 registrars; snapshot 944 routes / 0 policy gaps; PHPStan clean |
| 2026-07-14 | FE-02 | Split both 3,000-line locale dictionaries into eight domain modules, retained exact key/value parity, and added a ratcheting CI scan for untranslated template text/visible attributes | Locale parity 2/2; frontend lint/build green; `npm run i18n:check`: 3,759 reviewed occurrences / 0 additions |
| 2026-07-14 | TEST-03 | Added Playwright/Chromium CI, mocked tenant/API bootstrapping, forced-2FA coverage, six authenticated critical-route smokes, public candidate routes, clock-in rejection/retry, payroll finalization confirmation/failure recovery, axe-core, and mobile validation; fixed the login password-toggle contrast issue found by axe | Playwright Chromium: 12/12 passed |
| 2026-07-14 | TEST-03 | Extended Playwright from route smoke into a stateful employee/manager handoff for eligible leave submission and approval | Targeted Playwright leave/approval: 2/2 passed; suite total 14 scenarios |
| 2026-07-14 | TEST-03 | Added stateful candidate offer acceptance and tenant onboarding checklist completion through the real Vue flows with deterministic APIs | Targeted Playwright candidate/onboarding: 2/2 passed; suite total 16 scenarios |
| 2026-07-14 | TEST-03 | Completed the browser acceptance matrix with candidate draft restoration, explicit data-processing consent, application submission, and success redirect | Targeted candidate application Playwright passed; suite total 17 scenarios |
| 2026-07-14 | TEST-03, Release gate | Revalidated the complete frontend acceptance gate and corrected the candidate access contract: applications are public while offers require candidate authentication | Vitest 47/47; Playwright 17/17; lint/i18n/build green |
| 2026-07-14 | Release gate | Added a tracked-sensitive-data CI gate, removed the Android release keystore from Git tracking, and made release signing fail closed without environment secrets; documented alert, diagnosis, safe retry, reconciliation, and escalation for business jobs | Sensitive gate: 1,942 tracked files clean; mobile 24/24 and TypeScript clean; `BUSINESS_JOB_RUNBOOK.md` |
| 2026-07-14 | Release gate | Revalidated all locally executable backend gates after the audit changes | PHPUnit 1,083 tests / 2,747 assertions; PHPStan 0 errors; 125 migrations / 524 indexes; 944 routes / 0 gaps; controller DB additions 0 |
| 2026-07-14 | OPS-01 | Added an evidence ledger/CLI and age metric for isolated restore drills; PASSED is fail-closed unless the source backup and count/hash/attachment/login checks are complete | 125 migrations / 524 indexes; observability 2 tests / 23 assertions; PHPStan clean; real drill remains OPS-02 |
| 2026-07-14 | DATA-02 | Added a tenant-scoped employee data-quality service/API and bilingual dashboard with a database-driven menu, covering identity/tax, bank duplicates, BPJS, expiry, organization completeness, minimum wage, and orphaned references without exposing sensitive values | Integration 2 tests / 16 assertions; route snapshot 945 / 0 gaps; 126 migrations; PHPStan, locale parity, i18n ratchet, lint, and build green |
| 2026-07-14 | DATA-02, TEST-03 | Added browser acceptance for the protected data-quality dashboard and completed full regression | Playwright 18/18; PHPUnit 1,086 tests / 2,764 assertions; permission/route/tenant/controller/sensitive gates clean |
| 2026-07-14 | DATA-01 | Added tenant-scoped effective-dated employee snapshots, approval/scheduling API and due-date command; connected JIC and transfer writes plus payroll period-end reads | Effective-history integration 2 tests / 13 assertions; full PHPUnit 1,089 / 2,780; 948 routes / 0 gaps; 127 migrations / 527 indexes; PHPStan and tenant/controller gates clean |
| 2026-07-14 | COMP-01 | Added the compensation review domain foundation: tenant-scoped cycles, department/cost-center budgets, salary-band metrics, calibrated proposals, distinct multi-level approval/SoD evidence, hard budget enforcement, letter references, and transactional effective salary scheduling | Fresh SQLite 128 migrations / 535 indexes / 171 tenant tables; PHPStan 142 files clean; tenant gate clean; API/UI and business-flow tests remain |
| 2026-07-14 | COMP-01, TEST-03 | Completed the bilingual compensation-review API/UI, employee picker, scoped budget configuration, calibrated proposal and approval/rejection flow, effective salary scheduling, and employee letter; added backend and browser acceptance evidence | Business flow 2 tests / 17 assertions; full PHPUnit 1,092 / 2,802; Playwright 19/19; 957 routes / 0 gaps; 129 migrations; frontend 47/47, i18n/lint/build and all backend policy gates green |
| 2026-07-14 | FE-02 | Migrated My Team, PKWT duration/compensation alerts, and recursive cost-center actions to paired ID/EN dictionaries with accessible labels | Hardcoded inventory 3,759 → 3,745 / 0 additions; locale parity 47-test frontend suite and lint green |
| 2026-07-14 | FE-02 | Migrated the entire Training List flow—stats, budget, filters, table, create form, statuses, confirmations, errors, and locale-aware dates/currency—to paired ID/EN dictionaries | Hardcoded inventory 3,745 → 3,682 / 0 additions; frontend 47/47, lint, and production build green |
| 2026-07-14 | FE-02 | Completed the Training Detail and Certificate flows in paired ID/EN dictionaries, including dynamic statuses/categories, dialogs, validation prompts, errors, accessibility labels, and locale-aware dates/currency; removed obsolete full employee-list loaders | Hardcoded inventory 3,682 → 3,610 / 0 additions; frontend 47/47, locale parity, lint with no errors, and production build green |
| 2026-07-14 | FE-02 | Migrated the complete Roster Matrix to a dedicated paired ID/EN domain dictionary, covering filters, weekday headers, matrix states, legends, cell editing, manual override deletion, pattern generation/dry-run summaries, range clearing, errors, and accessible dialog controls | Hardcoded inventory 3,610 → 3,585 / 0 additions; frontend 47/47, locale parity, lint with no errors, and production build green |
| 2026-07-14 | FE-02 | Migrated the complete employee create/edit form to a dedicated paired ID/EN domain dictionary, covering identity, address, employment, salary/tax, application-account controls, dynamic status/type labels, notifications, loading state, and actions | Hardcoded inventory 3,585 → 3,537 / 0 additions; frontend 47/47, locale parity, lint with 0 errors (133 legacy warnings), and production build green |
| 2026-07-14 | FE-02 | Migrated Employee 360 to a dedicated paired ID/EN dictionary across profile state, exit alerts, employment, compensation, payroll, attendance, leave, performance, training, assets, disciplinary, documents, and lifecycle sections; date/time and IDR output now follow the active locale | Hardcoded inventory 3,537 → 3,490 / 0 additions; frontend 47/47, locale parity, lint with 0 errors (132 legacy warnings), and production build green |
| 2026-07-14 | FE-02 | Completed Employee List internationalization for worker/status filters, table status badges, locale-aware join dates, and accessible search/detail/bonus/edit/deactivate icon actions; removed its unused-router lint warning | Hardcoded inventory 3,490 → 3,488 / 0 additions; frontend 47/47, locale parity, lint with 0 errors (131 legacy warnings), and production build green |
| 2026-07-14 | FE-02 | Completed the employee contract-signing flow in paired ID/EN dictionaries, including validation/toasts, contract and signature previews, draw/upload controls, accessible iframe/canvas/upload interactions, submit and success states; fixed canvas initialization after loading and safe reset/reinitialization when switching signature methods | Hardcoded inventory 3,488 → 3,475 / 0 additions; frontend 47/47, locale parity, lint with 0 errors (130 legacy warnings), and production build green |
| 2026-07-14 | FE-02 | Completed generated-contract management using the existing paired contract dictionary, adding locale-aware timestamps and accessible preview/send/sign/revoke/delete actions; removed the obsolete employee loader and hardened preview tabs against popup blocking, opener access, and object-URL leaks | Hardcoded inventory 3,475 → 3,469 / 0 additions; frontend 47/47, locale parity, lint with 0 errors (129 legacy warnings), and production build green |
| 2026-07-14 | FE-02 | Completed the full employee detail/create modal in a dedicated paired ID/EN dictionary across identity, KTP/family, employment, approval, salary/tax, addresses, allowances, contracts, leave, assets/warnings, lifecycle tasks, confirmations, and errors; dynamic enum labels and date/currency output now follow the active locale, dialogs/actions have accessible names, and generated-contract preview handling is hardened | Hardcoded inventory 3,469 → 3,400 / 0 additions; frontend 51/51, locale parity, lint with 0 errors (127 legacy warnings), and production build green |
| 2026-07-14 | FE-02 | Completed Cost Center internationalization in a dedicated paired ID/EN dictionary across list/tree navigation, CRUD, employee backfill, annual/quarterly/monthly budgets, actual/committed/forecast reporting, category and period labels, confirmations, validation, and errors; currency/month output follows the active locale and dialogs/icon actions have accessible names | Hardcoded inventory 3,400 → 3,375 / 0 additions; frontend 51/51, locale parity, lint with 0 errors (126 legacy warnings), and production build green |
| 2026-07-14 | FE-02 | Completed the organization-chart flow across the parent view, recursive department cards, and department drill-down modal using a dedicated paired ID/EN dictionary; covered chart/list selection, pan controls, hierarchy drag/drop staging, stale-state handling, direct-supervisor preview/sync, department CRUD, employee moves with target positions, MPP/tier labels, contextual help, and accessible dialogs/icon actions | Hardcoded inventory 3,375 → 3,294 / 0 additions; frontend 51/51, locale parity, lint with 0 errors (121 legacy warnings), and production build green |
| 2026-07-14 | FE-02 | Completed Organization Roles internationalization in a dedicated paired ID/EN dictionary across level/filter labels, role CRUD, holder and PJS assignment, approval scopes, validation, confirmations, errors, and empty/loading states; dialogs and icon actions now have accessible names, and four obsolete helpers/imports were removed | Hardcoded inventory 3,294 → 3,239 / 0 additions; frontend 51/51, locale parity, lint with 0 errors (117 legacy warnings), and production build green |
| 2026-07-14 | FE-02 | Completed Recruitment Pipelines internationalization in a dedicated paired ID/EN dictionary across template/stage CRUD, stage types, online-test gates, SLA/terminal/optional controls, validation, duplication, confirmations, errors, and empty/loading states; dialog, icon, and reorder actions now have accessible names, and the unused reorder icon import was removed | Hardcoded inventory 3,239 → 3,203 / 0 additions; frontend 51/51, locale parity, lint with 0 errors (116 legacy warnings), and production build green |
| 2026-07-14 | FE-02 | Completed Benefit Policies internationalization in a dedicated paired ID/EN dictionary across list/empty/loading states, yearly/monthly reset labels, CRUD, validation, confirmations, errors, and locale-aware IDR formatting; added negative-limit and duplicate-submit guards, replaced the native delete prompt with the application confirmation dialog, and gave the modal/icon actions accessible names | Hardcoded inventory 3,203 → 3,184 / 0 additions; frontend 51/51, locale parity, lint with 0 errors (116 legacy warnings), and production build green |
| 2026-07-14 | FE-02 | Completed the Approval Chains audit using the existing paired ID/EN dictionary across chain metadata, steps, approver/fallback/module enums, conditions, assignments, confirmations, and errors; made previously ineffective code/name requirements explicit, added in-flight guards for chain and assignment mutations, normalized translated visible attributes, added accessible dialog/icon/reorder actions, and removed the unused users state | Hardcoded inventory 3,184 → 3,169 / 0 additions; frontend 51/51, locale parity, lint with 0 errors (115 legacy warnings), and production build green |
| 2026-07-14 | FE-02 | Completed the onboarding/offboarding Task Templates audit using the existing paired ID/EN dictionary across template/task CRUD, owner roles, due-date anchors, mandatory/default/active states, validation, duplication, confirmations, and errors; fixed new-template refresh selecting the wrong record, added explicit save/delete guards, localized type badges, normalized translated visible attributes, and made icon/reorder actions accessible | Hardcoded inventory 3,169 → 3,157 / 0 additions; frontend 51/51, locale parity, lint with 0 errors (115 legacy warnings), and production build green |
| 2026-07-14 | FE-02, SEC-02 | Audited the already bilingual Contract Templates editor, removed obsolete employee preloading, added save/delete/generate guards, preview-state reset, localized type choices, and accessible modal/icon controls; made contract rendering fail closed for cross-tenant employees/contracts and tenant-scoped every organization reference, then restricted RBAC catalog synchronization to global menus and moved two controller lookups behind scoped models | Hardcoded inventory remains 3,157 / 0 additions; tenant scan 285 files / 854 reviewed / 0 new; controller DB gate 106 files / 630 calls / 0 additions; security regression 16 tests / 116 assertions; PHPStan 145 files clean; frontend 51/51, lint 0 errors / 114 warnings, and production build green |
| 2026-07-14 | FE-02, SEC-02 | Completed Shift Assignments and Shift Patterns hardening: normalized translated attributes, accessible dialogs/actions, save/delete/preview guards, stale-preview reset, and cycle-slot consistency; backend mutations, nested pattern slots, scope references, joins, and preview employees now fail closed across tenant boundaries with date-range and partial-update validation | Hardcoded inventory 3,157 → 3,151 / 0 additions; tenant scan 285 files / 849 reviewed / 0 new; controller DB gate 106 files / 630 calls / 0 additions; full PHPUnit 1,112 / 2,917; PHPStan 145 files clean; frontend 51/51, lint 0 errors / 112 warnings, and production build green |
| 2026-07-14 | FE-02, SEC-02 | Completed Allowances/Natura hardening: persisted the previously dropped Natura configuration, enforced tenant-bound type/rate/reference/snapshot operations, required company context for every recompute caller, and propagated Natura as non-cash earnings with its monthly tax base; the bilingual UI now validates values/dates, prevents duplicate mutations, exposes loading states, uses locale-aware IDR, and has accessible dialogs/actions | Hardcoded inventory 3,151 → 3,148 / 0 additions; tenant scan 285 files / 845 reviewed / 0 new; controller DB gate 106 files / 629 calls / 0 additions; full PHPUnit 1,115 / 2,940; PHPStan 145 files clean; frontend 51/51, lint 0 errors / 112 warnings, and production build green |
| 2026-07-15 | SEC-02, ARCH-02 | Hardened Online Test administration and candidate assessment boundaries: direct-ID checks now resolve through company-qualified test/question/assignment/attempt graphs, joins are tenant-qualified, candidate access derives tenant from the account-owned assignment, and the API validates score/attempt bounds, positive question points, non-empty updates, assignment date order, and attempts-used floors | Tenant scan 845 → 824 reviewed findings across 285 files / 0 new; controller DB gate 106 files / 629 calls / 0 additions; Online Test regression 29 tests / 65 assertions; full PHPUnit 1,118 / 2,965; PHPStan and diff checks clean |
| 2026-07-15 | SEC-02, ARCH-02 | Moved the Online Test attempt boundary into the service engine: every start/resume/save/submit/result/auto-submit/manual-grade operation requires explicit company context, attempt and answer access resolves through the tenant-owned assignment graph, candidate callers reuse their account-owned assignment, and admin callers pass the resolved tenant context | Tenant scan 824 → 785 reviewed findings across 285 files / 0 new; controller DB gate 106 files / 628 calls / 0 additions; Online Test regression 30 tests / 79 assertions; full PHPUnit 1,119 / 2,979; PHPStan 145 files and diff checks clean |
| 2026-07-15 | SEC-02, ARCH-02 | Extracted Online Test admin pagination and detail graphs into a fail-closed tenant repository, made list endpoints require an explicit company, reduced controller query orchestration, added repositories to both tenant scanning and the permanent PHPStan scope, and ratcheted direct-DB baselines | `OnlineTestController.php` 1,306 → 1,247 lines and 24 → 17 direct DB calls; global controller gate 628 → 621; tenant scan 287 files / 785 reviewed / 0 new; Online Test/scanner policy 10 tests / 57 assertions; full PHPUnit 1,121 / 2,989; PHPStan 146 files clean |
| 2026-07-15 | SEC-02, ARCH-02 | Extracted tenant-owned test/question create-update-delete use cases from the Online Test controller, retaining stable error codes, unique test codes, immutable built-in templates, assignment-aware soft deletion, tenant-derived question ownership, and ordered question insertion | `OnlineTestController.php` 1,247 → 1,144 lines and 17 → 11 direct DB calls; global controller gate 621 → 615; tenant scan 289 files / 785 reviewed / 0 new; Online Test regression 32 tests / 100 assertions; full PHPUnit 1,122 / 3,001; PHPStan 148 files clean |
| 2026-07-15 | SEC-02, ARCH-02 | Extracted Online Test seed/import, question-bank, and assignment mutations into the tenant-aware admin use case; assignment creation is transactional, validates test/candidate/date/attempt invariants, preserves stage advancement and audit evidence, while the controller retains only HTTP validation, spreadsheet/template handling, and notification/webhook orchestration | `OnlineTestController.php` 1,144 → 871 lines and 11 → 0 direct DB calls; global controller gate 615 → 604 across 105 legacy files; tenant scan 289 files / 783 reviewed / 0 new; Online Test regression 32 tests / 115 assertions; full PHPUnit 1,122 / 3,016; PHPStan 148 files clean |
| 2026-07-15 | ARCH-02 | Completed the Online Test controller extraction by moving spreadsheet row normalization and built-in TPA/English/DISC seed data into independent, pure domain helpers; import errors remain row-addressable while the controller now limits itself to HTTP/file response and integration orchestration | `OnlineTestController.php` 871 → 667 lines, retaining 0 direct DB calls; Online Test regression 34 tests / 134 assertions; full PHPUnit 1,124 / 3,035; PHPStan 150 files clean; tenant scan 291 files / 783 reviewed / 0 new; diff check clean |
| 2026-07-15 | SEC-02, ARCH-02 | Began Recruitment service decomposition with a fail-closed tenant repository for job references, candidate ownership, funnel/stale metrics, online-test assignment gates, pipeline counts/templates, and category lookup; stage transitions now require explicit company context end to end from recruitment and offer callers, with tenant-qualified stage/history mutations | `RecruitmentService.php` 1,146 → 1,069 lines and 53 → 34 direct DB calls; tenant scan 292 files / 773 reviewed / 0 new; recruitment policy/gate regression 18 tests / 74 assertions; full PHPUnit 1,126 / 3,057; PHPStan 151 files clean; controller DB gate 105 files / 604 calls / 0 additions |
| 2026-07-15 | SEC-02, ARCH-02 | Extracted candidate stage movement and rejection into a transactional tenant-aware use case; custom/default pipeline validation, online-test category gates, terminal-stage rules, stage/history writes, and rollback behavior are now isolated from the Recruitment facade and exercised against a real two-tenant SQLite schema | `RecruitmentService.php` 1,069 → 833 lines and 34 → 22 direct DB calls; stage/policy regression 20 tests / 99 assertions; full PHPUnit 1,128 / 3,077; PHPStan 152 files clean; tenant scan 293 files / 771 reviewed / 0 new; controller DB gate 105 files / 604 calls / 0 additions |
| 2026-07-15 | SEC-02, ARCH-02 | Completed Recruitment decomposition by extracting hire conversion, employee-number generation, tenant-qualified candidate lock/offer/job/document operations, payroll identity carry-over, onboarding integrations, headcount closure, and contract generation into a dedicated hire use case; the public facade retains stable domain constants/analyzer methods and explicit company arguments | `RecruitmentService.php` 833 → 280 lines and 22 → 0 direct DB calls; recruitment regression 21 tests / 105 assertions; full PHPUnit 1,129 / 3,088; PHPStan 153 files clean; tenant scan 294 files / 764 reviewed / 0 new; controller DB gate 105 files / 604 calls / 0 additions |
| 2026-07-15 | SEC-02, ARCH-02 | Completed Bonus controller decomposition: scheme/run/recipient reads moved to a fail-closed tenant repository; create/update/delete, approval/payment/cancellation, include-all recomputation, cap validation, and aggregate refresh moved into a transactional workflow service; bonus calculation/history/payroll handoffs now require explicit company context | `BonusController.php` 1,192 → 910 lines and 22 → 0 direct DB calls; tenant policy 3 tests / 16 assertions; full PHPUnit 1,132 / 3,104; PHPStan 155 files clean; tenant scan 296 files / 731 reviewed / 0 new; controller DB gate 104 files / 582 calls / 0 additions |
| 2026-07-15 | SEC-02, ARCH-02 | Completed Leave controller decomposition: tenant reads/calendar moved to a fail-closed repository; payroll-window/category policy and working-day calculation moved to a domain policy; submission, overlap prevention, approval/rejection, balance mutation, attendance marking, and cancellation restoration moved into transactional use cases; leave-balance summary now requires explicit company context | `LeaveController.php` 1,089 → 592 lines and 11 → 0 direct DB calls; workflow tenant policy 3 tests / 18 assertions; full PHPUnit 1,135 / 3,127; PHPStan 159 files clean; tenant scan 300 files / 728 reviewed / 0 new; controller DB gate 103 files / 571 calls / 0 additions |
| 2026-07-15 | SEC-02, ARCH-02 | Completed Payroll service decomposition: period/payslip/adjustment persistence moved to a fail-closed tenant repository, pending transaction scans and per-employee calculation became independent use cases, and snapshot-verified finalization now reserves payable inputs transactionally before notification orchestration | `PayrollService.php` 1,171 → 397 lines and all direct DB access removed; payroll architecture policy 3 tests / 13 assertions; full PHPUnit 1,138 / 3,145; PHPStan 163 files clean; tenant scan 304 files / 728 reviewed / 0 new; controller DB gate 103 files / 571 calls / 0 additions |
| 2026-07-15 | SEC-02, ARCH-02 | Continued JIC decomposition by moving draft employee/duplicate/position reads and create-update-delete persistence behind a fail-closed tenant repository; employee master snapshots and organization lookups now require explicit company context | `JicController.php` 1,375 → 1,355 lines and controller DB gate 571 → 568 calls; JIC policy 5 tests / 36 assertions; full PHPUnit 1,139 / 3,150; PHPStan 164 files clean; tenant scan 305 files / 723 reviewed / 0 new |
| 2026-07-15 | SEC-02, ARCH-02 | Closed ARCH-02 by extracting JIC submit/approve/reject/consent/cancel state rules, SK generation, notification-recipient resolution, and complete tenant-qualified detail read-model access; the JIC controller baseline exception is removed and all six named high-risk targets now contain no direct DB connection | `JicController.php` 1,505 audit baseline → 871 lines / 0 direct DB calls; JIC targeted 12 tests / 58 assertions including two-tenant workflow transitions; full PHPUnit 1,142 / 3,159; PHPStan 167 files clean; tenant scan 308 files / 718 reviewed / 0 new; controller gate 102 legacy files / 557 calls / 0 additions; diff check clean |
| 2026-07-14 | REL-01 | Expanded client idempotency from selected critical paths to every state-changing request across the main web API, public career API, candidate authentication/API, signup, web-push, and mobile API; existing retry keys are preserved | Static audit: 178 required backend routes; frontend 47/47, mobile 29/29 + TypeScript, backend 1,094/2,809; mobile lint remains blocked by pre-existing ESLint rule/config errors |

## 12. Recommended Execution Order

1. Fix duplicate migration identity and add migration CI guard.
2. Build the route-permission matrix and enforce negative authorization tests.
3. Make tenant-isolation scanning and integration tests mandatory.
4. Enforce idempotency for critical business mutations.
5. Lock payroll maker-checker and immutable snapshot rules.
6. Add the ten cross-module E2E scenarios.
7. Refactor large route/controller/service files behind the new tests.
8. Improve frontend E2E, accessibility, and i18n coverage.
9. Add retro payroll, reconciliation, and effective-dated Core HR data.
10. Continue product maturity work only after P0 controls are verified.
