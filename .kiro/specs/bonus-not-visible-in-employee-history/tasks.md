# Implementation Plan

## Overview

Bugfix plan for `bonus-not-visible-in-employee-history` following the bug condition
methodology. Tasks 1–2 capture the bug and the baseline behavior on UNFIXED code (test-first).
Task 3 applies a minimal, targeted fix in `BonusService::bonusHistoryForEmployee` (with a
controller forwarding check) and re-runs the same tests. Task 4 is the quality-gate
checkpoint. The fix is constrained to the read path of `GET /employees/{id}/bonus-history`
and must preserve every behavior in design §Preservation Requirements (Requirements §3.1–§3.15).

## Task Dependency Graph

```
1 (Bug Condition exploration test, fails on unfixed code)
   └──> 3.1 (Apply targeted fix in BonusService)
         └──> 3.2 (Verify controller forwarding)
               └──> 3.3 (Re-run task 1 — must now PASS)
                     └──> 3.4 (Re-run task 2 — must still PASS)
                           └──> 4 (Checkpoint: full suite + static analysis + format)

2 (Preservation property tests, pass on unfixed code)
   └──> 3.4 (Re-run task 2 — must still PASS after fix)
```

Tasks 1 and 2 are independent and may be authored in parallel; both must complete before
task 3.1. Tasks 3.1 → 3.2 → 3.3 → 3.4 are strictly sequential. Task 4 runs last.

```json
{
  "waves": [
    { "wave": 1, "tasks": ["1", "2"] },
    { "wave": 2, "tasks": ["3.1"] },
    { "wave": 3, "tasks": ["3.2"] },
    { "wave": 4, "tasks": ["3.3"] },
    { "wave": 5, "tasks": ["3.4"] },
    { "wave": 6, "tasks": ["4"] }
  ]
}
```

## Tasks

- [-] 1. Write bug condition exploration test (BEFORE implementing fix)
  - **Property 1: Bug Condition** - APPROVED/PAID Non-Excluded Recipients Reach Employee History
  - **CRITICAL**: This test MUST FAIL on unfixed code — failure confirms the bug exists.
  - **DO NOT attempt to fix the test or the production code when it fails in this task.**
  - **GOAL**: Surface counterexamples that prove a non-excluded recipient row attached to an
    `APPROVED` or `PAID`, non-soft-deleted, in-tenant `bonus_runs` row is missing from
    `GET /employees/{id}/bonus-history` `history[]`, while the same response's
    `diagnostic.recipients_by_status` still reports the row exists in the DB. This isolates
    the defect to `BonusService::bonusHistoryForEmployee` (read path) per the design's
    Hypothesized Root Cause section.
  - **Test file**: `backend/tests/Unit/BonusHistoryTest.php` (new file). Use the existing CI4
    test base (`CIUnitTestCase` + `DatabaseTestTrait` with the SQLite test profile). Follow
    the conventions from `tests/Unit/BonusCalculatorTest.php` for seeding bonus runs.
  - **Endpoint under test**: `GET /api/v1/employees/{id}/bonus-history` →
    `App\Controllers\Api\V1\BonusController::employeeHistory` →
    `App\Libraries\BonusService::bonusHistoryForEmployee`.
  - **Scoped PBT Approach**: This is a deterministic bug. Scope the property to the concrete
    failing cases enumerated in the design's Exploratory Bug Condition Checking section:
      1. Self-call, single recipient row, `is_excluded = 0`, run `status = 'APPROVED'`,
         `deleted_at IS NULL`, `company_id` matches caller tenant.
      2. Same as (1) but run `status = 'PAID'`.
      3. HR caller (`bonus.view`), same shape as (1), in-tenant.
      4. Super-admin caller (`companyId = 0`), recipient in any company.
      5. Recipient inserted with `is_excluded = NULL` (legacy default) — observe whether
         `where('r.is_excluded', 0)` excludes it on unfixed code.
      6. Recipient on a run whose `bonus_schemes.deleted_at IS NOT NULL` — observe whether
         the `INNER JOIN bonus_schemes` drops it.
    Generate the seed data permutation programmatically; for each permutation assert the row
    appears in `history[]`. Each scoped case is a discriminator for one root-cause candidate.
  - **Bug Condition (from design `isBugCondition`)**: a recipient row exists with
    `r.employee_id = employeeId`, `r.is_excluded = 0`, joined to `bonus_runs` with
    `status IN ('APPROVED','PAID')`, `deleted_at IS NULL`, and (`isSuperAdmin` OR
    `run.company_id = callerTenant.companyId`), AND the caller is authorized
    (self / super-admin / `bonus.view`).
  - **Expected Behavior asserted by the test (from design Property 1)**: For each scoped
    case, the response body MUST contain at least one row in `history[]` with
    `bonus_run_id`, `amount`, `scheme_name`, `scheme_code`, `scheme_kind`, `pay_date`,
    `period_year`, `tax_treatment`, and `run_status` matching the seeded row, AND
    `stats.count >= 1`, `stats.total_amount >= seeded amount`,
    `stats.by_kind[scheme_kind] >= seeded amount`.
  - **Cross-check with diagnostic**: For every failing case, also assert
    `diagnostic.recipients_by_status.APPROVED >= 1` (or `PAID >= 1`) and
    `diagnostic.total_recipients_db >= 1` so the failure log proves the row is in the DB
    and reachable from the same request, isolating the defect to the history query.
  - Run the test on UNFIXED code via `composer test:unit -- --filter BonusHistoryTest`
    from `backend/`.
  - **EXPECTED OUTCOME**: Test FAILS (this is correct — it proves the bug exists). For each
    scoped case, document the counterexample in the test output, e.g.:
    "calculatePrice-style counterexample: employee=42, run.status=APPROVED, is_excluded=0,
    company_id=7 ⇒ history=[], diagnostic.recipients_by_status.APPROVED=1".
  - Mark this task complete when the test is written, run, and the failure (with
    counterexamples) is documented in this file under task 1's notes.
  - _Requirements: 1.1, 1.2, 1.3, 1.4, 2.1, 2.2, 2.3, 2.4, 2.5_

- [~] 2. Write preservation property tests (BEFORE implementing fix)
  - **Property 2: Preservation** - Non-Buggy Inputs Behave Identically Before And After Fix
  - **IMPORTANT**: Follow observation-first methodology. Do NOT assume what the unfixed code
    returns — run it, capture the response, then assert that response shape in the test.
  - **Test file**: extend `backend/tests/Unit/BonusHistoryTest.php` with a second test class
    section / test methods named `*Preservation*`. Capture snapshots into
    `backend/tests/Unit/__fixtures__/bonus-history/` (create folder) for the deep-equality
    cases, committed alongside the fix PR.
  - **Endpoint under test**: same as task 1.
  - **Property-Based Approach**: For each scenario, generate a small randomized DB shape with
    a fixed seed (so the snapshot is reproducible), call the endpoint on UNFIXED code,
    capture the full JSON response, and assert that response in the test. After the fix the
    same seed must produce a byte-identical response.
  - **Scenarios to capture (one test method each, all from design Preservation Requirements
    §3.1–§3.15 and Testing Strategy → Preservation Checking)**:
      1. `DRAFT`/`CALCULATED` non-excluded recipient is hidden when `include_pending` is
         omitted. Assert `history` is empty for that recipient and the diagnostic is
         populated. (Requirement 3.1)
      2. HR caller with `bonus.view` and `include_pending=1` sees `DRAFT`/`CALCULATED`
         non-excluded rows. (Requirement 3.2)
      3. Self caller without `bonus.view` passing `include_pending=1` is force-downgraded to
         `include_pending=false` and does NOT see `DRAFT`/`CALCULATED` rows.
         (Requirement 3.7 + design "self-without-permission downgrade")
      4. Recipient with `is_excluded = 1` is omitted regardless of run status.
         (Requirement 3.3)
      5. `CANCELLED` runs are omitted. (Requirement 3.4)
      6. Soft-deleted runs (`bonus_runs.deleted_at IS NOT NULL`) are omitted.
         (Requirement 3.5)
      7. Super-admin without an employee mapping (`employee_id` not set) does not crash and
         renders the diagnostic state expected by the UI. (Requirement 3.6)
      8. Non-HR caller requesting another employee's `id` gets HTTP 403 with the same
         `code`/`message` envelope. (Requirement 3.7)
      9. HR caller in `company_id = 1` requesting an employee in `company_id = 1` sees that
         employee's `APPROVED`/`PAID` non-excluded rows. (Requirement 3.8)
     10. Non-super-admin requesting an employee whose recipient row lives in another
         `company_id` gets `history = []` plus `diagnostic.cross_company = true`.
         (Requirements 3.9, 3.15)
     11. Super-admin with `companyId = 0` sees rows across companies; super-admin with a
         selected `X-Company-Id` applies that filter. (Requirement 3.10)
     12. Empty-result diagnostic payload contains exactly these keys with non-null values
         where applicable: `employee_id`, `employee_company_id`, `employee_exists`,
         `user_tenant_company_id`, `filter_company_id`, `cross_company`,
         `total_recipients_db`, `excluded_count`, `recipients_by_status`,
         `distinct_run_companies`. (Requirement 3.11)
     13. `limit` clamping: `limit=0`, `limit=-5`, `limit=500`, `limit=200`, omitted —
         each maps to the documented effective limit (default 50, clamped to [1, 200]).
         (Requirement 3.12)
     14. Ordering: randomized rows come back ordered by `run.pay_date DESC, run.id DESC`.
         (Requirement 3.13)
     15. Every returned row exposes `period_year` (used by the frontend grouper).
         (Requirement 3.14)
  - **Property-based assertion** (covers all of the above as one universal property):
    For randomized `(employee, run, recipient)` triples, the row appears in `history[]`
    IFF `(status IN ('APPROVED','PAID') OR (status IN ('DRAFT','CALCULATED') AND
    include_pending = true AND caller has bonus.view)) AND is_excluded = 0 AND
    deleted_at IS NULL AND (isSuperAdmin OR run.company_id = caller.companyId)`. Implement
    this universal property with a small generator (e.g., `array_map` over a deterministic
    seed list of ~50 permutations) and assert IFF holds.
  - Run the tests on UNFIXED code via
    `composer test:unit -- --filter BonusHistoryTest`.
  - **EXPECTED OUTCOME**: Tests PASS on UNFIXED code (this confirms the baseline behavior to
    preserve). Snapshot fixtures are committed.
  - Mark this task complete when all preservation tests are written, run, and passing on
    unfixed code, with snapshots committed.
  - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 3.10, 3.11, 3.12, 3.13, 3.14, 3.15_

- [ ] 3. Fix for bonus not visible in employee history

  - [~] 3.1 Diagnose root cause from task 1 counterexamples and apply the targeted fix in `BonusService::bonusHistoryForEmployee`
    - **File**: `backend/app/Libraries/BonusService.php`
    - **Function**: `bonusHistoryForEmployee(int $employeeId, ?int $companyId = null, array $opts = [])`
    - Read the failing counterexamples documented in task 1 to identify which clause(s)
      filter the expected row out (per design Hypothesized Root Cause: most-likely is the
      `is_excluded` predicate against legacy `NULL` rows, the `INNER JOIN bonus_schemes`
      dropping rows when a scheme is soft-deleted, an `$statuses` whitelist mismatch, or
      `companyId` resolution skew).
    - Apply the **minimum** correction(s) consistent with the design's Fix Implementation
      section. Concretely, choose from (apply only those the failing case proves are
      necessary):
        - If `is_excluded` legacy `NULL` is the cause: replace `->where('r.is_excluded', 0)`
          with `->groupStart()->where('r.is_excluded', 0)
          ->orWhere('r.is_excluded IS NULL')->groupEnd()`. This still excludes
          `is_excluded = 1` (preserves Requirement 3.3) but tolerates legacy NULL.
        - If the `INNER JOIN bonus_schemes` is dropping rows whose scheme is soft-deleted:
          switch to a `LEFT JOIN` against `bonus_schemes`. **Do NOT** add a
          `sch.deleted_at IS NULL` predicate — that would re-introduce the defect.
        - If the `whereIn('run.status', $statuses)` matches nothing because `$statuses`
          can be empty: defensively guard against empty arrays (preserve current empty-set
          semantics when explicitly empty, but never silently match nothing on a typo).
        - If `companyId` is skewed (e.g., super-admin path passing `0` instead of `null`):
          ensure the controller still passes `null` for super-admin (`isSuperAdmin() &&
          getCompanyId() === 0`) and the service still applies the filter only when
          `$companyId !== null`.
    - The corrected query MUST keep the shape documented in design Fix Implementation
      step 1 (FROM/JOIN/WHERE/ORDER BY/LIMIT layout).
    - Do not modify the SELECT column list, the `breakdown_json` decode loop, or the
      numeric casts (`amount`, `monthly_salary`, `tenure_months`).
    - Keep ordering `->orderBy('run.pay_date', 'DESC')->orderBy('run.id', 'DESC')`.
    - Use `BaseModel::scoped()` semantics where applicable; do NOT bypass tenant filters.
    - Respect project conventions: type hints on parameter + return, PHPStan level 0
      passes (`composer stan` from `backend/`), Pint clean (`composer format:check`).
    - Service file is under the 800-line ceiling; this fix must not push it over.
    - _Bug_Condition: isBugCondition(input) — recipient row with `is_excluded=0`, run
      `status IN ('APPROVED','PAID')`, `deleted_at IS NULL`, in-tenant or super-admin, but
      `history[]` does not include it._
    - _Expected_Behavior: expectedBehavior(result) — the row appears in `history[]` with
      the documented fields and is included in `stats.total_amount`, `stats.count`,
      `stats.by_kind[scheme_kind]`._
    - _Preservation: design §Preservation Requirements §3.1–§3.15 (DRAFT/CALCULATED
      hidden by default, include_pending opt-in, is_excluded=1 hidden, CANCELLED hidden,
      soft-deleted hidden, 403 cross-employee, super-admin cross-company, diagnostic
      payload, limit [1,200], ordering pay_date DESC, id DESC, period_year grouping)._
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5_

  - [~] 3.2 Verify controller forwarding in `BonusController::employeeHistory`
    - **File**: `backend/app/Controllers/Api/V1/BonusController.php`
    - **Function**: `employeeHistory($employeeId = null)`
    - Confirm — and only adjust if necessary — that:
        - The 403 check (`! $isSelf && ! $this->canView()`) is unchanged and runs first.
        - The self-without-permission `include_pending` downgrade is unchanged.
        - `companyId` is resolved as `$tenant->isSuperAdmin() ? null : $tenant->getCompanyId()`
          and forwarded unchanged to `bonusHistoryForEmployee` (no implicit cast of `null`
          to `0`). Add a strict comparison if the current code uses loose comparison.
        - `'limit'` and `'include_pending'` keys in `$opts` match what the service reads.
        - `bonusHistoryDiagnostic(...)` is still called for empty-result responses with the
          same key set.
    - This task should result in **zero or minimal** controller changes per the design's
      Fix Implementation → Controller section. If the root cause from task 1 is purely in
      the service, do not edit the controller.
    - Layering rule: parsing/auth/response shaping stays in the controller; any new helper
      logic must live in `BonusService` per project layering convention.
    - Multi-tenant: do not add or remove tenant scoping at the controller; rely on the
      service's existing `companyId` filter.
    - Do not add or remove `AuditLogger` calls — this is a read endpoint.
    - _Bug_Condition: isBugCondition(input) from design._
    - _Expected_Behavior: expectedBehavior(result) — controller forwards the correct
      `companyId` and `opts` to the service, response envelope unchanged._
    - _Preservation: design §Preservation Requirements (especially §3.6, §3.7, §3.10, §3.11)._
    - _Requirements: 2.5, 3.6, 3.7, 3.10, 3.11_

  - [~] 3.3 Verify bug condition exploration test now passes
    - **Property 1: Expected Behavior** - APPROVED/PAID Non-Excluded Recipients Reach Employee History
    - **IMPORTANT**: Re-run the SAME test from task 1 — do NOT write a new test. The test
      from task 1 encodes the expected behavior; when it passes, the expected behavior is
      satisfied.
    - Run from `backend/`:
      `composer test:unit -- --filter BonusHistoryTest::testBugConditionApprovedRecipientAppears`
      (and the other scoped cases authored in task 1).
    - **EXPECTED OUTCOME**: All scoped bug-condition cases PASS — the recipient row appears
      in `history[]` with correct fields, and `stats.count`, `stats.total_amount`,
      `stats.by_kind[scheme_kind]` reflect that row.
    - If any scoped case still fails, return to task 3.1 and refine the fix; do not modify
      the test.
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5 (Expected Behavior Properties from design Property 1)_

  - [~] 3.4 Verify preservation tests still pass (no regressions)
    - **Property 2: Preservation** - Non-Buggy Inputs Behave Identically Before And After Fix
    - **IMPORTANT**: Re-run the SAME tests and snapshots from task 2 — do NOT write new
      tests and do NOT regenerate snapshots.
    - Run from `backend/`:
      `composer test:unit -- --filter BonusHistoryTest`.
    - **EXPECTED OUTCOME**: All preservation tests PASS, all snapshot deep-equality
      assertions match, the universal IFF property holds across all randomized
      permutations.
    - If any preservation test fails, the fix has caused a regression on a non-buggy
      input. Return to task 3.1 and narrow the fix; the rule is byte-equality on every
      input where `isBugCondition` returns false.
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 3.10, 3.11, 3.12, 3.13, 3.14, 3.15_

- [~] 4. Checkpoint - Ensure all tests pass and quality gates are green
  - Run the full backend unit suite from `backend/`: `composer test`.
  - Run static analysis: `composer stan`.
  - Run formatter check: `composer format:check`.
  - Run the CI mirror: `composer ci`.
  - Manually exercise the end-to-end flow once on a dev DB: HR creates scheme → run →
    calculate → approve → mark-paid; employee opens `/bonuses/my` (or calls
    `GET /api/v1/employees/{self_id}/bonus-history`) and confirms the row appears with
    correct status at each transition (APPROVED then PAID).
  - Re-confirm preservation by re-running task 2's snapshot tests one more time after any
    last-minute refactor.
  - Ensure `BonusController.php` stays under 500 lines and `BonusService.php` stays under
    800 lines per project conventions.
  - Ensure `AuditLogger`, `BaseModel::scoped()`, and tenant scoping in unrelated modules
    are unchanged.
  - If any question or ambiguity arises during the checkpoint (e.g., the failing scoped
    case in task 1 points to a root cause not enumerated in design Hypothesized Root
    Cause), pause and ask the user before proceeding.


## Notes

- The PBT tasks (1 and 2) use the `**Property N: Type**` format on their first bullet so the
  Kiro hover status indicator activates. Property 1 covers the Bug Condition; Property 2
  covers Preservation. Sub-tasks 3.3 and 3.4 reuse those same property identifiers so the
  status flips from "failing on unfixed code" to "passing after fix" without rewriting tests.
- Layering rule (per `.kiro/steering/structure.md`): Controller → Service → Model. The fix is
  scoped to `app/Libraries/BonusService.php`; controller edits in 3.2 are forwarding checks
  only.
- Multi-tenant: tenant scoping must remain enforced via the existing `companyId` filter (and
  super-admin null short-circuit). Do not bypass `BaseModel::scoped()` in unrelated code.
- Audit log is not touched — `employeeHistory` is a read endpoint and emits no audit events.
- Test environment uses the SQLite test profile for fast iteration on Windows; the CI matrix
  exercises MySQL to catch ENUM/collation differences flagged in the design's root-cause
  candidate #5.
- Snapshot fixtures from task 2 live under `backend/tests/Unit/__fixtures__/bonus-history/`
  and are committed alongside the fix PR for reproducibility.
- If a counterexample from task 1 points to a root cause not enumerated in the design's
  Hypothesized Root Cause section, pause at task 3.1 and ask the user before extending the
  fix beyond the documented scope.
