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
| Backend PHPUnit | PASS — 974 tests, 2,240 assertions |
| Frontend Vitest | PASS — 4 files, 27 tests |
| Frontend production build | PASS — Vite build completed |
| ID/EN locale dictionary parity | PASS |
| Files modified by this audit | This tracker only |

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
| Backend controllers | 118 files |
| Backend libraries/services | 129 files |
| Backend models | 19 files |
| Database migrations | 110 files |
| Backend test files | 62 files |
| Frontend views | 168 files |
| Frontend test files | 4 files |
| Mobile test files | 5 files |
| `Routes.php` size | 1,357 lines |
| Route-level `permission:*` filters | 4 occurrences |

Large files observed:

| File | Lines |
|---|---:|
| `backend/app/Controllers/Api/V1/Employee/JicController.php` | 1,505 |
| `backend/app/Libraries/Recruitment/RecruitmentService.php` | 1,227 |
| `backend/app/Controllers/Api/V1/Recruitment/OnlineTestController.php` | 1,201 |
| `backend/app/Controllers/Api/V1/Payroll/BonusController.php` | 1,192 |
| `backend/app/Controllers/Api/V1/Leave/LeaveController.php` | 1,060 |
| `frontend/src/views/Employees/EmployeeModal.vue` | 1,668 |
| `frontend/src/views/Career/CareerApply.vue` | 1,574 |
| `frontend/src/views/Recruitment/PipelineBoard.vue` | 1,513 |

## 4. Master Tracking Board

| ID | Priority | Area | Work item | Status | Owner | Target | Evidence/PR |
|---|---|---|---|---|---|---|---|
| SEC-01 | P0 | Authorization | Build and enforce route-to-permission matrix | IN PROGRESS | Codex | — | Runtime permission seed gaps fixed; route-policy matrix remains |
| SEC-02 | P0 | Tenancy | Make tenant scoping mandatory and add CI leak tests | IN PROGRESS | Codex | — | Critical payroll/disbursement lookups and writes scoped; repository-wide work remains |
| DB-01 | P0 | Migration | Resolve duplicate migration version `2026-09-01-000009` | DONE | Codex | 2026-07-12 | Renamed menu backfill to `000011`; migration check passes |
| DB-02 | P0 | Migration | Add migration integrity and upgrade-path CI checks | IN PROGRESS | Codex | — | Integrity check added to Composer and CI; fresh/upgrade DB matrix remains |
| PAY-01 | P0 | Payroll | Enforce maker-checker and segregation of duties | TODO | — | — | — |
| PAY-02 | P0 | Payroll | Freeze immutable calculation/finalization snapshots | TODO | — | — | — |
| REL-01 | P0 | Reliability | Require idempotency on critical mutations | TODO | — | — | — |
| TEST-01 | P0 | Testing | Add tenant-isolation integration suite | TODO | — | — | — |
| TEST-02 | P1 | Testing | Add employee-to-payroll E2E scenarios | TODO | — | — | — |
| TEST-03 | P1 | Testing | Add frontend Playwright critical paths | TODO | — | — | — |
| ARCH-01 | P1 | Architecture | Modularize `Routes.php` by domain | TODO | — | — | — |
| ARCH-02 | P1 | Architecture | Refactor large controllers/services into use cases | TODO | — | — | — |
| FE-01 | P1 | Frontend | Expand tests for critical views and route guards | TODO | — | — | — |
| FE-02 | P1 | i18n | Remove remaining hardcoded UI strings | TODO | — | — | — |
| OPS-01 | P1 | Operations | Monitor critical cron/jobs by business outcome | TODO | — | — | — |
| OPS-02 | P0 | Recovery | Verify backup and perform restore drill | TODO | — | — | — |
| PROD-01 | P1 | Product | Classify feature readiness and remove false claims | TODO | — | — | — |
| PAY-03 | P1 | Payroll | Implement retro and off-cycle payroll controls | TODO | — | — | — |
| PAY-04 | P1 | Payroll | Implement bank and accounting reconciliation | TODO | — | — | — |
| DATA-01 | P2 | Core HR | Introduce effective-dated employee master data | TODO | — | — | — |
| DATA-02 | P2 | Governance | Add employee data-quality dashboard | TODO | — | — | — |
| COMP-01 | P2 | Compensation | Add merit/compensation review cycle | TODO | — | — | — |

## 5. P0 — Before the Next Production Release

### SEC-01 — Centralize and enforce authorization

**Finding:** Most API endpoints are protected by JWT, but only four route-level permission filters were observed, all around billing lifecycle actions. Other authorization may exist inside controllers, but it is harder to audit and easier to omit.

**Tasks:**

- [ ] Export every route with HTTP method, controller method, feature flag, permission, and tenant scope.
- [x] Seed all permission slugs currently referenced by runtime code and make permission seed coverage a CI gate.
- [x] Enforce route-level permission filters for payroll calculate, finalize, and disburse.
- [x] Add a regression test preventing critical payroll mutation routes from reverting to JWT-only access.
- [ ] Define separate read, create, update, delete, approve, calculate, finalize, export, and impersonate permissions.
- [ ] Apply permission filters or a centralized policy layer to every protected endpoint.
- [ ] Ensure menu visibility is not treated as an authorization boundary.
- [ ] Add negative authorization tests for employee, supervisor, manager, HR admin, payroll admin, finance, CFO, auditor, and super admin.
- [ ] Add an automated check that fails when a new protected route has no declared policy.

**Acceptance criteria:**

- Every non-public route has an explicit authorization policy.
- Unauthorized roles receive `403`, not an empty result or `404` used inconsistently.
- Payroll preparer cannot approve/finalize their own payroll.
- Permission tests run in CI.

### SEC-02 — Make tenant isolation mandatory

**Finding:** The repository has substantially more controllers/services than models, and many backend files access the database directly. This creates a risk that a new query omits `company_id` scoping.

**Tasks:**

- [ ] Inventory direct `db_connect()` / database access in controllers and libraries.
- [x] Scope critical payroll period and disbursement reads/writes to the active company.
- [x] Correct disbursement payslip foreign-key lookup from `period_id` to `payroll_period_id`.
- [x] Scope approval-chain and delegation ID lookups/writes and reject cross-tenant delegation targets/chains.
- [x] Scope API key lifecycle, scopes, usage logs, and last-used updates by company; enforce explicit API-key route permissions.
- [x] Reject cross-tenant approval decisions and bulk decisions at controller and approval-engine boundaries.
- [ ] Move tenant-owned queries to scoped repositories/models.
- [ ] Prohibit direct DB access from controllers through a static check.
- [ ] Verify every tenant-owned table has `company_id` and appropriate composite indexes.
- [ ] Include `company_id` in applicable unique constraints.
- [ ] Run `SecurityScanTenantLeaks` in CI.
- [x] Run the initial tenant scan: 266 files, 1,125 heuristic findings (`TABLE_NO_COMPANY_FILTER` 1,117; `MODEL_FIND_NO_SCOPE` 7; raw query 1).
- [ ] Triage global-table and chained-query false positives, then create a reviewed version-controlled baseline.
- [ ] Enable scanner strict mode only after the baseline is reviewed; current non-strict command exits successfully despite findings.
- [ ] Add two-tenant integration tests for every critical aggregate.
- [ ] Test super-admin tenant switching and impersonation audit logs.

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
- [ ] If one or both have shipped, create an additive repair migration; do not rewrite deployed history.
- [ ] Document the decision in the release notes.

**Acceptance criteria:**

- No duplicate migration version or class exists.
- Fresh install and upgrade from the latest production snapshot both succeed.

### DB-02 — Migration safety CI

**Tasks:**

- [x] Fail CI on duplicate migration timestamp/version and duplicate migration class.
- [ ] Run all migrations against an empty SQLite database.
- [ ] Run all migrations against MySQL/MariaDB used in production.
- [ ] Test upgrade from a sanitized production-schema snapshot.
- [ ] Verify indexes and foreign keys after migration.
- [ ] Require safe roll-forward handling for irreversible data migrations.

**Acceptance criteria:** Migration checks are required before merge.

### PAY-01 — Payroll maker-checker and segregation of duties

**Tasks:**

- [ ] Record `prepared_by`, `submitted_by`, `approved_by`, and `finalized_by` separately.
- [ ] Prevent a user from approving/finalizing a payroll they prepared.
- [ ] Require configured approval thresholds for high-value payroll runs.
- [ ] Require re-approval whenever material payroll values change.
- [ ] Add break-glass workflow with reason, elevated audit severity, and notification.

**Acceptance criteria:** The API, not only the UI, enforces four-eyes control.

### PAY-02 — Immutable payroll snapshots

**Tasks:**

- [ ] Snapshot employee compensation, attendance, leave, overtime, tax status, BPJS status, rates, formulas, cost centers, and bank account at calculation/finalization.
- [ ] Hash or version the snapshot for audit integrity.
- [ ] Prevent editing a finalized period.
- [ ] Correct finalized results using adjustment/reversal records.
- [ ] Ensure regenerated payslips use the finalized snapshot.

**Acceptance criteria:** A later master-data change cannot silently change a finalized payslip.

### REL-01 — Require idempotency for critical operations

**Finding:** Idempotency support is present but described as optional when clients omit the header.

**Tasks:**

- [ ] Require `Idempotency-Key` for clock-in/out.
- [ ] Require it for leave, overtime, reimbursement, loan, and business-trip submissions.
- [ ] Require it for approve/reject/bulk approval actions.
- [ ] Require it for payroll calculate/finalize/disburse and bonus runs.
- [ ] Require it for candidate offer responses and critical webhooks.
- [ ] Add database-level uniqueness and concurrent-request tests.
- [ ] Define key TTL, payload mismatch behavior, and replay response semantics.

**Acceptance criteria:** Concurrent identical requests create exactly one business transaction.

### TEST-01 — Tenant-isolation integration suite

**Required scenarios:**

- [ ] Same NIK exists in two tenants without collisions or leakage.
- [ ] Tenant A cannot read/update/delete Tenant B employee.
- [ ] Tenant A cannot access Tenant B payslip or attachment via guessed ID/path.
- [ ] Background jobs maintain tenant context.
- [ ] Exports contain only the requesting tenant's records.
- [ ] Cache keys are tenant-qualified.
- [ ] Webhooks and notification recipients are tenant-qualified.

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

- [ ] Join mid-month → attendance → prorated salary → PPh 21 → payslip.
- [ ] Leave/half-day → attendance → payroll deduction.
- [ ] Overtime plan → approval → realization → payroll component.
- [ ] Reimbursement → approval → payroll/payment output.
- [ ] Payroll calculate → variance review → approval → finalize → disburse.
- [ ] JIC/salary change with a past effective date → retro adjustment.
- [ ] Resignation → final payroll → THR/severance → offboarding → access revocation.
- [ ] Transfer → effective date → organization/cost-center/payroll effect.
- [ ] Approval delegation when the primary approver is unavailable.
- [ ] Mobile offline replay without duplicate transactions.

### TEST-03 — Frontend Playwright critical paths

- [ ] Employee login and forced password/2FA handling.
- [ ] Clock-in/out with rejected and successful states.
- [ ] Leave submission and manager approval.
- [ ] Payroll review and finalize confirmation.
- [ ] Candidate application and offer response.
- [ ] Tenant admin setup/onboarding.
- [ ] Accessibility scan with axe-core.
- [ ] Mobile viewport coverage for employee self-service.

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

- [ ] Preserve route order where wildcard matching depends on order.
- [ ] Keep permission and feature policy next to each route.
- [ ] Add route registration/snapshot tests before splitting.

### ARCH-02 — Refactor large controllers and services

Start with the highest-risk files:

- [ ] `JicController.php`
- [ ] `RecruitmentService.php`
- [ ] `OnlineTestController.php`
- [ ] `BonusController.php`
- [ ] `LeaveController.php`
- [ ] `PayrollService.php`

Target pattern:

```text
Controller → Request validation → Authorization policy → Use case
Use case   → Domain rules → Repository transaction → Domain event/outbox
```

**Acceptance criteria:** Controllers orchestrate HTTP concerns only; business invariants are testable without HTTP.

### FE-01 — Frontend critical test coverage

- [ ] Route guards and permission-based actions.
- [ ] Feature flags and subscription restrictions.
- [ ] Approval double-click and stale state handling.
- [ ] Payroll finalize confirmation and error recovery.
- [ ] Form validation and backend error-code translation.
- [ ] Loading, empty, partial, and failure states.
- [ ] Request race/cancellation behavior.
- [ ] Set an initial coverage threshold and increase it gradually.

### FE-02 — Complete internationalization

- [ ] Scan Vue templates for hardcoded visible strings and placeholders.
- [ ] Migrate remaining strings in employee, training, roster, cost center, and organization views.
- [ ] Split the ~3,000-line locale files by domain.
- [ ] Keep locale key parity test in CI.
- [ ] Add a test/lint rule for untranslated visible strings.

### PAY-03 — Retro and off-cycle payroll

- [ ] Define retro triggers: salary, allowance, attendance, tax status, BPJS, transfer, and contract corrections.
- [ ] Calculate delta against the original finalized snapshot.
- [ ] Post corrections in a controlled adjustment/off-cycle period.
- [ ] Show original, adjustment, and cumulative totals on payslips.
- [ ] Prevent silent historical rewrites.

### PAY-04 — Reconciliation and accounting controls

- [ ] Payroll register versus bank file reconciliation.
- [ ] Disbursement callback/status reconciliation.
- [ ] Duplicate account and duplicate payment detection.
- [ ] GL journal generation with balanced debit/credit validation.
- [ ] Cost-center and branch allocation validation.
- [ ] Month-over-month payroll variance report.
- [ ] Unresolved exception queue before finalization.

### OPS-01 — Business job observability

Track more than process uptime:

- [ ] Payroll calculation duration and failures.
- [ ] Stuck approval requests by SLA.
- [ ] Cron last-success timestamp and processed record count.
- [ ] Notification/email/push delivery failure rate.
- [ ] Webhook retry/dead-letter count.
- [ ] Tenant-leak scan result.
- [ ] Backup age and last successful restore drill.
- [ ] Alerts include tenant, request ID, job ID, and safe diagnostic context.

### PROD-01 — Feature readiness audit

Classify every advertised module as:

- [ ] Production-ready
- [ ] Beta
- [ ] Backend only
- [ ] Frontend only
- [ ] Partially wired
- [ ] Feature-flagged
- [ ] Placeholder
- [ ] Deprecated

Known item to verify: `WebPushChannel.php` contains a note that the subscription endpoint is not yet implemented.

**Acceptance criteria:** README, pricing/feature flags, menus, API, and operational capability describe the same reality.

## 7. P2 — Product Maturity Roadmap

### DATA-01 — Effective-dated Core HR data

- [ ] Compensation history with `valid_from` / `valid_to`.
- [ ] Position, manager, department, branch, cost center, and grade history.
- [ ] Contract and benefit eligibility history.
- [ ] As-of-date queries for payroll and audit.
- [ ] Future-dated changes with approval and scheduled application.

### DATA-02 — Employee data-quality dashboard

- [ ] Missing/invalid NIK and NPWP.
- [ ] Missing or duplicate bank account.
- [ ] BPJS registration mismatch.
- [ ] Expired contract/document/certification.
- [ ] Missing manager, position, cost center, or work location.
- [ ] Salary below applicable minimum wage.
- [ ] Orphaned user/employee/organization references.

### COMP-01 — Compensation review cycle

- [ ] Merit budget by company/department/cost center.
- [ ] Compa-ratio and salary range penetration.
- [ ] Performance/calibration input.
- [ ] Manager proposal and multi-level approval.
- [ ] Budget overrun controls.
- [ ] Effective date and payroll integration.
- [ ] Employee letter and audit trail.

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

- [ ] Backend tests pass.
- [ ] Frontend tests, lint, and production build pass.
- [ ] Mobile type check/tests pass when mobile code changes.
- [ ] Migration duplicate and upgrade-path checks pass.
- [ ] Tenant-leak scan passes.
- [ ] Authorization matrix contains all new/changed routes.
- [ ] Critical mutation endpoints have idempotency behavior.
- [ ] Payroll calculation golden scenarios pass when payroll code changes.
- [ ] No secrets or production PII are committed.
- [ ] Rollback/roll-forward plan is documented.
- [ ] Metrics, alerts, and runbooks cover new background jobs.
- [ ] Feature documentation matches actual readiness.

## 10. Decision Log

Record decisions that change scope or acceptance criteria.

| Date | ID | Decision | Rationale | Decided by |
|---|---|---|---|---|
| — | — | — | — | — |

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
