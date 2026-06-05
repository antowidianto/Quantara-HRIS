# Bonus Not Visible In Employee History — Bugfix Design

## Overview

When HR advances a bonus run from `CALCULATED` to `APPROVED` or `PAID`, the affected employee's
"Riwayat Bonus" page (`/bonuses/my`) does not show the bonus. The page is backed by
`GET /employees/{id}/bonus-history`, which returns an empty `history[]` and zeroed `stats` even
though the underlying `bonus_recipients` row is `is_excluded = 0` and the parent `bonus_runs` row
is `APPROVED` or `PAID`, not soft-deleted, and belongs to the employee's tenant.

The fix is targeted at the read path — `BonusController::employeeHistory` and
`BonusService::bonusHistoryForEmployee` in the backend (CodeIgniter 4.5). The strategy is:

1. Reproduce the bug end-to-end on the unfixed code with a recipient row that satisfies the
   stated bug condition.
2. Diff the resulting `history[]` against the `diagnostic.recipients_by_status` payload returned
   by the same endpoint (which reads from the same tables but with a simpler query). If
   `recipients_by_status.APPROVED > 0` while `history` is empty, the defect is isolated to the
   join/filter chain in `bonusHistoryForEmployee` (or in how `BonusController::employeeHistory`
   resolves arguments before calling it).
3. Apply the minimal correction that restores delivery for `APPROVED`/`PAID`, non-excluded,
   non-soft-deleted, in-tenant rows, leaving every other behavior of the endpoint untouched.

The fix MUST preserve every behavior listed in the requirements §3 (regression prevention):
default hiding of `DRAFT`/`CALCULATED`, the `include_pending=1` opt-in, `is_excluded=1` exclusion,
`CANCELLED` hiding, soft-deleted run hiding, the 403 for cross-employee non-HR access, super-admin
cross-company visibility, the `diagnostic` payload, `limit` clamping to `[1, 200]` (default 50),
ordering by `run.pay_date DESC, run.id DESC`, and the frontend grouping by `period_year`.

## Glossary

- **Bug_Condition (C)**: The condition that triggers the bug — a `bonus_recipients` row exists
  for the requested employee with `is_excluded = 0`, joined to a `bonus_runs` row whose
  `status IN ('APPROVED','PAID')`, `deleted_at IS NULL`, and `company_id` matches the caller's
  tenant scope (or the caller is super-admin), yet `history[]` does not include that row.
- **Property (P)**: The desired behavior — that exact recipient row appears in `history[]` with
  correct `amount`, `scheme_name`, `scheme_code`, `scheme_kind`, `pay_date`, `period_year`,
  `tax_treatment`, and `run_status`, and is included in `stats.total_amount`, `stats.count`, and
  `stats.by_kind`.
- **Preservation**: Every non-buggy input keeps the exact response it produces today —
  `DRAFT`/`CALCULATED` runs stay hidden unless `include_pending=1`, `is_excluded=1` rows stay
  hidden, `CANCELLED` and soft-deleted runs stay hidden, cross-tenant rows stay hidden for
  non-super-admins, super-admins still see across companies, the diagnostic payload still
  populates on empty results, `limit` is still clamped to `[1, 200]`, and ordering stays
  `run.pay_date DESC, run.id DESC`.
- **bonusHistoryForEmployee**: The method in `app/Libraries/BonusService.php` that builds the
  query against `bonus_recipients` joined to `bonus_runs` and `bonus_schemes`, returning the
  recipient rows used for the response.
- **employeeHistory**: The controller action in `app/Controllers/Api/V1/BonusController.php`
  that handles `GET /employees/{id}/bonus-history`. It performs auth, parses query params,
  resolves `companyId` from `TenantContext`, calls `bonusHistoryForEmployee`, and shapes the
  final `history`/`stats`/`diagnostic` envelope.
- **TenantContext**: `App\Libraries\TenantContext` — holds `companyId`, `userId`, `roleId`,
  `employeeId` for the authenticated request, populated by `JwtAuthFilter`.
- **bonus_runs**: Table with status workflow `DRAFT → CALCULATED → APPROVED → PAID / CANCELLED`,
  soft-deletable via `deleted_at`. Source of `pay_date`, `period_year`, `status`.
- **bonus_recipients**: Per-employee line items for a run, with `is_excluded`, `amount`,
  `breakdown_json`. Hard-deleted (no `deleted_at` column).

## Bug Details

### Bug Condition

The bug manifests whenever the employee (or an HR caller acting on the employee's behalf) has
at least one `bonus_recipients` row that should be visible according to the documented contract,
yet that row is missing from `history[]`. "Should be visible" means the row passes every filter
the endpoint already documents: status gate, exclusion gate, soft-delete gate, and tenant gate.

`employeeHistory` / `bonusHistoryForEmployee` is either failing to retrieve a recipient row that
satisfies all visibility filters, applying an unintended additional filter, scoping
`company_id` to a value that excludes the row, or returning the row but losing it during shape
transformation.

**Formal Specification:**
```
FUNCTION isBugCondition(input)
  INPUT: input of type {
    employeeId: int,
    callerTenant: TenantContext,        // companyId, isSuperAdmin, hasPermission(bonus.view)
    queryParams: {                      // raw query string of the GET request
      limit?: int,
      include_pending?: bool
    },
    db: DatabaseSnapshot                // bonus_runs + bonus_recipients + bonus_schemes
  }
  OUTPUT: boolean

  // Caller is allowed to see this employee's history
  authorized := (callerTenant.employeeId == employeeId)
                OR callerTenant.isSuperAdmin
                OR callerTenant.hasPermission('bonus.view')
  IF NOT authorized THEN RETURN false

  // There exists at least one recipient row that the contract says MUST appear
  expectedVisible := EXISTS row IN db.bonus_recipients r
    JOIN db.bonus_runs run ON run.id = r.bonus_run_id
    WHERE r.employee_id        = employeeId
      AND r.is_excluded         = 0
      AND run.deleted_at        IS NULL
      AND run.status            IN ('APPROVED', 'PAID')
      AND (callerTenant.isSuperAdmin
           OR run.company_id    = callerTenant.companyId)

  IF NOT expectedVisible THEN RETURN false

  // Actual response from GET /employees/{employeeId}/bonus-history
  actualResponse := callEndpoint(employeeId, callerTenant, queryParams)

  // Bug: at least one expected row is missing from history[],
  // OR stats do not reflect that row's amount/count
  RETURN expectedRowMissingFromHistory(actualResponse, db, employeeId, callerTenant)
         OR statsDoNotIncludeExpectedRow(actualResponse, db, employeeId, callerTenant)
END FUNCTION
```

### Examples

- An employee in `company_id = 7` has one `bonus_recipients` row (`is_excluded = 0`,
  `amount = 5_000_000`) attached to a `bonus_runs` row with `status = 'APPROVED'`,
  `deleted_at IS NULL`, `company_id = 7`. They open `/bonuses/my`. **Expected:** one row in
  `history[]` with `run_status = 'APPROVED'`, `stats.count = 1`, `stats.total_amount = 5000000`.
  **Actual:** `history = []`, `stats.count = 0`, `stats.total_amount = 0`, `stats.by_kind = {}`.
- Same employee, after HR marks the run `PAID`. **Expected:** same row, now with
  `run_status = 'PAID'`. **Actual:** `history = []`.
- HR (`bonus.view`) calls `GET /employees/42/bonus-history` for an employee in their company who
  has an `APPROVED` bonus. **Expected:** that row appears. **Actual:** empty `history[]`, but the
  same response's `diagnostic.recipients_by_status.APPROVED >= 1` confirms the recipient exists
  in the same DB session.
- Edge case: a super-admin (no `companyId` filter) calls the endpoint for an employee whose
  `APPROVED` recipient row lives in a different company than the super-admin's currently
  selected `X-Company-Id`. **Expected:** the row appears (super-admin sees across companies).
  **Actual:** does not appear.

## Expected Behavior

### Preservation Requirements

**Unchanged Behaviors:**
- `bonus_runs` rows in `DRAFT` or `CALCULATED` status are still omitted by default
  (no `include_pending=1`).
- When `include_pending=1` is sent and the caller has `bonus.view`, `DRAFT`/`CALCULATED`,
  non-excluded rows still appear; the self-only caller without `bonus.view` is still forced
  back to `include_pending=false`.
- `bonus_recipients` rows with `is_excluded = 1` are still omitted regardless of run status.
- `bonus_runs` rows with `status = 'CANCELLED'` are still omitted.
- `bonus_runs` rows with `deleted_at IS NOT NULL` (soft-deleted) are still omitted.
- `GET /employees/{id}/bonus-history` still returns 403 when the caller lacks `bonus.view`
  and `id` is not their own `employee_id`.
- Super-admin still sees recipient rows across all companies (no `company_id` filter applied
  when `tenant->isSuperAdmin() && companyId === 0`); HR with `bonus.view` still sees all
  employees in their tenant company.
- Non-super-admin callers still get cross-tenant rows excluded.
- The `diagnostic` payload still populates with `employee_id`, `employee_company_id`,
  `employee_exists`, `user_tenant_company_id`, `filter_company_id`, `cross_company`,
  `total_recipients_db`, `excluded_count`, `recipients_by_status`,
  `distinct_run_companies` whenever the response can be diagnostic.
- `limit` is still clamped to `[1, 200]` and defaults to `50`.
- Result ordering is still `run.pay_date DESC, run.id DESC`.
- The frontend still groups by `period_year` (falling back to year-of-`pay_date`); the
  response shape — `{ history, stats: { total_amount, count, by_kind, include_pending },
  diagnostic }` — is unchanged.
- `AuditLogger` calls on the mutation endpoints (`approveRun`, `markRunPaid`, `cancelRun`)
  remain untouched. `BaseModel::scoped()` semantics in unrelated modules are not modified.

**Scope:**
All inputs that do NOT satisfy the bug condition must be byte-for-byte unaffected by this fix.
This includes:
- Calls where no recipient row exists for the employee at all.
- Calls where every recipient row is `is_excluded = 1`.
- Calls where every recipient row's run is `CANCELLED` or soft-deleted.
- Calls where every recipient row's run is in `DRAFT`/`CALCULATED` and `include_pending` is not
  set to `1`.
- Calls violating authorization (return 403).
- Calls from a non-super-admin caller for an employee whose recipient runs are all in another
  tenant.

## Hypothesized Root Cause

The defect lives somewhere between request parsing in `employeeHistory` and the SQL emitted by
`bonusHistoryForEmployee`. From most to least likely:

1. **Filter mismatch in `bonusHistoryForEmployee`'s query builder**:
   The history query stacks several conditions in a single chain:
   ```
   ->where('r.employee_id', $employeeId)
   ->where('r.is_excluded', 0)
   ->whereIn('run.status', $statuses)
   ->where('run.deleted_at IS NULL')
   ```
   while the diagnostic query (`bonusHistoryDiagnostic`) builds essentially the same join with
   the same `run.deleted_at IS NULL` clause but without the `is_excluded` and `status`
   constraints, and the diagnostic correctly returns the row. The difference between those two
   queries is the most likely source of the defect:
   - The status whitelist may be evaluated against an unexpected value (e.g., the column
     comparison may be case- or whitespace-sensitive in some MySQL configurations, or the
     `$statuses` array may have been built from a stale local under a refactor).
   - The `is_excluded = 0` clause may be matching against a non-zero stored value due to
     an earlier write path setting `is_excluded` to `NULL` instead of `0` for default-included
     recipients (CI4 default of `0` only applies on `INSERT` if the column is omitted; an
     explicit `null` would survive). With `WHERE r.is_excluded = 0`, `NULL` rows would be
     filtered out.
   - The `INNER JOIN bonus_schemes sch ON sch.id = run.bonus_scheme_id` would silently drop
     any row whose underlying scheme has been soft-deleted (`bonus_schemes.deleted_at IS NOT
     NULL`) — schemes are soft-deletable per migration 000043. Once HR archives a scheme, the
     scheme join still passes (no `deleted_at` filter on the join), but if a refactor introduces
     a `sch.deleted_at IS NULL` filter, every recipient of that scheme disappears from
     `history[]`. We should also confirm an `INNER JOIN` is the right choice; a `LEFT JOIN` to
     the scheme would let the row appear with `scheme_name = null` rather than vanish.

2. **`companyId` resolution in `employeeHistory`**:
   ```
   $companyId = $tenant->isSuperAdmin() ? null : $tenant->getCompanyId();
   ```
   Passing the tenant's `companyId` is correct for non-super-admin callers, but if the
   employee being queried (self) is mapped to a different `company_id` than the JWT tenant
   resolved by `JwtAuthFilter`, the run's `company_id` filter eliminates every row. This
   mirrors the already-handled "cross-company mismatch" diagnostic case but should be checked
   when the employee row's `company_id` matches the tenant.

3. **Argument-level loss between controller and service**:
   The controller passes `'limit' => $limit, 'include_pending' => $includePending` while the
   service reads `$opts['limit'] ?? 50` and `$opts['include_pending'] ?? false`. If a refactor
   renamed one side, history would silently default to `include_pending=false` (unchanged) but
   the bug here isn't about pending. More dangerous would be a `limit` of `0` slipping through
   (the controller does clamp to `>= 1`, so this is unlikely).

4. **Shape transformation in the response builder**:
   The controller computes `stats.total_amount` and `stats.by_kind` by iterating over
   `$history`. If `$history` is being filtered/reduced after the SQL call (e.g., by a stale
   in-memory exclusion in a future change), rows would be lost downstream. Currently the
   service returns rows directly, so this is the lowest-probability cause.

5. **Status data drift**:
   `bonus_runs.status` is `ENUM('DRAFT','CALCULATED','APPROVED','PAID','CANCELLED')`. A
   migration or seeder writing `'Approved'`/`'paid'` would not match `whereIn` against
   uppercase strings on a case-sensitive collation. We should verify by sampling
   `SELECT DISTINCT status FROM bonus_runs` during exploration.

The exploratory bug-condition tests in §Testing Strategy are designed to discriminate between
these candidates by inspecting the diagnostic payload, raw DB rows, and the SQL the builder
produces.

## Correctness Properties

Property 1: Bug Condition - APPROVED/PAID Non-Excluded Recipients Reach Employee History

_For any_ tuple of `(employeeId, callerTenant, db)` where `isBugCondition` returns `true` —
i.e., at least one `bonus_recipients` row exists for `employeeId` with `is_excluded = 0`, whose
parent `bonus_runs` row has `status IN ('APPROVED','PAID')`, `deleted_at IS NULL`, and
`company_id` matching the caller's tenant scope (or the caller is super-admin) — the fixed
`employeeHistory` endpoint SHALL include that recipient row in `history[]` with the documented
fields (`amount`, `scheme_name`, `scheme_code`, `scheme_kind`, `pay_date`, `period_year`,
`tax_treatment`, `run_status`), AND SHALL include its `amount` in `stats.total_amount`, increment
`stats.count`, and add its amount under `stats.by_kind[scheme_kind]`.

**Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5**

Property 2: Preservation - Non-Buggy Inputs Behave Identically Before And After Fix

_For any_ tuple of `(employeeId, callerTenant, queryParams, db)` where `isBugCondition` returns
`false` — including but not limited to: `DRAFT`/`CALCULATED` runs without `include_pending=1`,
`is_excluded = 1` rows, `CANCELLED` runs, soft-deleted runs, cross-tenant rows for non-super-
admin callers, unauthorized callers (403), missing employees, and empty result sets that should
populate `diagnostic` — the fixed endpoint SHALL produce a response that is structurally
identical to the original endpoint: identical HTTP status, identical `history[]` content
(rows, order, fields, values), identical `stats` (`total_amount`, `count`, `by_kind`,
`include_pending`), and identical `diagnostic` payload (same keys with same values), with
`limit` still clamped to `[1, 200]` and ordering still `run.pay_date DESC, run.id DESC`.

**Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 3.10, 3.11, 3.12, 3.13, 3.14, 3.15**

## Fix Implementation

### Changes Required

Assuming our root cause analysis is correct, the fix is localized to the read path. No schema
change, no controller signature change, no route change.

**File**: `backend/app/Libraries/BonusService.php`

**Function**: `bonusHistoryForEmployee(int $employeeId, ?int $companyId = null, array $opts = [])`

**Specific Changes**:

1. **Tighten the recipient-status query so it matches the diagnostic's reading of the same
   tables**: Once the exploratory tests pinpoint which clause filters the row out, adjust only
   that clause. The shape of the corrected query MUST remain:
   ```
   FROM bonus_recipients r
   JOIN bonus_runs   run ON run.id = r.bonus_run_id
   JOIN bonus_schemes sch ON sch.id = run.bonus_scheme_id
   WHERE r.employee_id = :employeeId
     AND r.is_excluded = 0
     AND run.status IN (:statuses)
     AND run.deleted_at IS NULL
     [AND run.company_id = :companyId  -- only when companyId !== null]
   ORDER BY run.pay_date DESC, run.id DESC
   LIMIT :limit
   ```
   Concrete corrections to consider, applied minimally based on the exploratory results:
   - If `is_excluded` may be stored as `NULL` rather than `0`, replace
     `->where('r.is_excluded', 0)` with `->groupStart()->where('r.is_excluded', 0)
     ->orWhere('r.is_excluded IS NULL')->groupEnd()`. This still excludes
     `is_excluded = 1` rows (preservation §3.3) but tolerates legacy `NULL` rows.
   - If the bonus scheme can be soft-deleted while recipients still exist (which we observed
     in migration 000043), make the `bonus_schemes` join robust: keep it as `INNER JOIN` only
     if every run guarantees a non-deleted scheme; otherwise switch to `LEFT JOIN` so the
     recipient still appears (with `scheme_name = null` only when truly missing). The fix
     MUST NOT add a `sch.deleted_at IS NULL` predicate, since that would re-introduce the
     same defect for archived schemes.
   - If `whereIn('run.status', $statuses)` is being short-circuited by an empty `$statuses`
     array under some `include_pending` path, defensively guard against an empty array (still
     produce zero rows when explicitly requested, but never silently match nothing because of
     a typo).

2. **Make the `companyId` filter explicit and aligned with the controller's intent**: The
   controller passes `null` for super-admin and `tenant.companyId` otherwise. The service's
   `if ($companyId !== null) { $b->where('run.company_id', $companyId); }` is correct;
   verify during testing that `companyId` is forwarded unchanged from controller to service
   (i.e., no implicit cast of `null` to `0`).

3. **Preserve all SELECT columns**: The columns enumerated in the existing `select(...)` call
   must remain (controller and frontend depend on `scheme_kind`, `tax_treatment`,
   `period_year`, etc.). The fix MUST NOT trim or rename them.

4. **Preserve ordering**: Keep `->orderBy('run.pay_date', 'DESC')->orderBy('run.id', 'DESC')`.

5. **Preserve the `breakdown_json` decode loop and numeric casts** (`amount`, `monthly_salary`,
   `tenure_months`).

**File**: `backend/app/Controllers/Api/V1/BonusController.php`

**Function**: `employeeHistory($employeeId = null)`

**Specific Changes**:

1. **No change to authorization order**: The 403 check (`! $isSelf && ! $this->canView()`) and
   the self-without-permission `include_pending` downgrade remain.

2. **No change to the diagnostic payload**: `bonusHistoryDiagnostic(...)` is the empty-result
   contract for the UI and is exercised by preservation §3.11.

3. **Verify forwarding**: Confirm `companyId` is `null` exactly when
   `$tenant->isSuperAdmin() && $tenant->getCompanyId() === 0` (the `applyScope` super-admin
   contract). For a super-admin with a selected `X-Company-Id`, `getCompanyId()` is non-zero
   and the filter is applied — that matches preservation §3.10 only for the "no company
   selected" sub-case. (If the requirement is that super-admin always sees across companies
   regardless of selected `X-Company-Id`, that would be a separate scope and is not in this
   fix.)

4. **Controller line budget**: `BonusController` is already under the 500-line ceiling per
   project conventions; the fix MUST NOT push it over. New helpers, if any, belong in
   `BonusService`.

5. **AuditLogger**: This is a read endpoint; no audit log emission is added or removed.

## Testing Strategy

### Validation Approach

Two phases. First, surface counterexamples on the unfixed code that demonstrate the bug and
discriminate between root-cause candidates. Second, after fixing, prove that the bug condition
no longer triggers (Property 1) and that every non-buggy input is byte-identical between the
unfixed and fixed code (Property 2).

Tests live in `backend/tests/Unit/` per project conventions, named `BonusHistoryTest.php` (or
extending the existing bonus-engine test file if one exists). They use the in-memory SQLite
profile already used by the test suite for fast iteration on Windows, plus the full MySQL CI
matrix to catch ENUM/collation differences.

### Exploratory Bug Condition Checking

**Goal**: Reproduce the defect and pin down which clause(s) filter the expected row out, before
writing any production code change.

**Test Plan**: Seed `bonus_schemes` + `bonus_runs` + `bonus_recipients` rows that exactly match
the bug condition (Section §Bug Condition). Authenticate as the recipient employee. Call
`GET /employees/{self_id}/bonus-history` and assert that `history[]` is empty and `stats.count`
is `0` (this confirms reproduction on unfixed code). Then inspect the same response's
`diagnostic.recipients_by_status.APPROVED` and `diagnostic.total_recipients_db` to confirm the
row is in the DB and is reachable by the diagnostic but not by the main query — that nails the
defect to `bonusHistoryForEmployee`'s filter chain rather than to `JwtAuthFilter`,
`TenantContext`, or the route.

**Test Cases**:
1. **Self APPROVED Reproduction**: Employee `E1` in `company_id = 1` has one recipient row
   (`is_excluded = 0`, `amount = 5_000_000`) on an `APPROVED`, non-soft-deleted run in
   `company_id = 1`. Authenticated as `E1`, `GET /employees/E1/bonus-history`. Expect
   `history = []` on unfixed code; `diagnostic.recipients_by_status.APPROVED >= 1` (will fail
   on unfixed code).
2. **Self PAID Reproduction**: Same shape, `status = 'PAID'`. Expect same failure.
3. **HR APPROVED Reproduction**: HR user (`bonus.view`) in `company_id = 1`,
   `GET /employees/E1/bonus-history` for `E1` in same company. Expect failure mirroring case 1.
4. **Super-Admin APPROVED Reproduction**: Super-admin with `companyId = 0`,
   `GET /employees/E1/bonus-history` for `E1` in any company. Expect failure mirroring case 1.
5. **Edge — Soft-Deleted Scheme**: Same shape as case 1 but `bonus_schemes.deleted_at` is set.
   If the row appears on unfixed code we know the join doesn't filter on scheme delete (good);
   if it does NOT appear we have a candidate sub-cause for the main bug (the scheme join is
   already over-filtering for some other reason).
6. **Edge — `is_excluded = NULL`**: Recipient inserted with `is_excluded` explicitly `NULL`
   (legacy data path). On unfixed code observe whether `where('r.is_excluded', 0)` excludes it.

**Expected Counterexamples**:
- `history` is empty while `diagnostic.recipients_by_status.APPROVED >= 1` and
  `diagnostic.total_recipients_db >= 1` — confirms the bug is in
  `bonusHistoryForEmployee`'s query.
- Possible discriminating causes once observed: `is_excluded = NULL` mismatch, `INNER JOIN
  bonus_schemes` dropping rows under some condition, `whereIn('run.status', $statuses)` not
  matching due to data drift, `companyId` resolution skewed by `getCompanyId()` returning
  something other than the recipient's run company.

### Fix Checking

**Goal**: For every input where `isBugCondition` returns `true`, the fixed endpoint produces
the expected `history` row and `stats` increments (Property 1).

**Pseudocode:**
```
FOR ALL input WHERE isBugCondition(input) DO
  response := callEndpoint_fixed(input.employeeId, input.callerTenant, input.queryParams)

  ASSERT response.statusCode == 200
  ASSERT EXISTS row IN response.body.history WHERE
       row.bonus_run_id == expected.bonus_run_id
   AND row.amount       == expected.amount
   AND row.run_status   IN ('APPROVED', 'PAID')
   AND row.scheme_kind  == expected.scheme_kind
   AND row.pay_date     == expected.pay_date
   AND row.period_year  == expected.period_year

  ASSERT response.body.stats.count >= 1
  ASSERT response.body.stats.total_amount >= expected.amount
  ASSERT response.body.stats.by_kind[expected.scheme_kind] >= expected.amount
END FOR
```

### Preservation Checking

**Goal**: For every input where `isBugCondition` returns `false`, the fixed endpoint returns
exactly the same response as the original endpoint (Property 2).

**Pseudocode:**
```
FOR ALL input WHERE NOT isBugCondition(input) DO
  ASSERT callEndpoint_original(input) == callEndpoint_fixed(input)
  // structural deep equality across statusCode, body.history, body.stats,
  // body.diagnostic (key set + values), Content-Type, and HTTP status
END FOR
```

**Testing Approach**: Property-based testing is recommended for preservation checking because:
- It generates many DB shapes automatically (random mixes of `DRAFT`, `CALCULATED`,
  `APPROVED`, `PAID`, `CANCELLED`, soft-deleted, excluded recipients, cross-tenant runs).
- It catches edge cases that hand-written unit tests miss — e.g., a `NULL` `is_excluded` on a
  `CANCELLED` run, an `include_pending` self-without-permission downgrade, a `limit = 200`
  boundary, a `period_year` that disagrees with `YEAR(pay_date)`.
- It provides strong evidence the fix didn't shift any non-buggy behavior.

**Test Plan**: Capture the unfixed endpoint's response for a fixed seed of randomized scenarios
(snapshot files committed under `tests/Unit/__fixtures__/bonus-history/`). After the fix,
re-run with the same seed and assert byte equality. Treat any divergence on a non-buggy
input as a regression and a blocker for the PR.

**Test Cases**:
1. **DRAFT/CALCULATED Hidden By Default**: Observe on unfixed code that a `CALCULATED`,
   non-excluded recipient row is hidden when `include_pending` is omitted; assert the fixed
   code keeps it hidden.
2. **`include_pending=1` Reveals Pending**: HR with `bonus.view` toggling
   `include_pending=1` sees `DRAFT`/`CALCULATED` rows; behavior identical before/after.
3. **`is_excluded=1` Stays Hidden**: Excluded rows remain absent regardless of run status;
   identical before/after.
4. **`CANCELLED` Stays Hidden**: `CANCELLED` runs absent; identical before/after.
5. **Soft-Deleted Run Stays Hidden**: `bonus_runs.deleted_at IS NOT NULL` keeps the row
   absent; identical before/after.
6. **403 For Cross-Employee Non-HR**: Non-HR user requesting another employee's history still
   gets HTTP 403 with the same `code`/`message` shape; identical before/after.
7. **HR Same-Tenant Visibility**: HR user requesting any employee in their company sees that
   employee's `APPROVED`/`PAID` rows; identical before/after.
8. **Cross-Tenant Isolation**: Non-super-admin requesting an employee whose recipient row
   lives in another `company_id` gets an empty `history[]` plus a populated `diagnostic.cross_company = true`;
   identical before/after.
9. **Super-Admin Cross-Company**: Super-admin with `companyId = 0` sees rows from any
   tenant; identical before/after.
10. **Diagnostic Payload Populates On Empty**: Every existing empty-result case
    (no recipients, all excluded, only pending, only cancelled, cross-company) keeps emitting
    the diagnostic with the documented keys.
11. **`limit` Clamping**: `limit=0`, `limit=-5`, `limit=500`, `limit` omitted, `limit=200`
    each map to the same effective limit before and after.
12. **Ordering Stable**: `run.pay_date DESC, run.id DESC` — randomized rows ordered
    identically before and after.
13. **`period_year` Field Present**: Every returned row still exposes `period_year` for the
    frontend grouping.

### Unit Tests

- `bonusHistoryForEmployee` query smoke tests — happy path, every gate (status, exclusion,
  soft-delete, tenant), limit, ordering, super-admin null `companyId`.
- `employeeHistory` controller tests for auth — self vs cross-employee with/without
  `bonus.view`, `include_pending` downgrade for self-without-permission.
- `bonusHistoryDiagnostic` payload structure tests — keys present, counts correct under
  mixed-status seed.

### Property-Based Tests

- Generate random `(employee, run, recipient)` triples with random `status`, `is_excluded`,
  `deleted_at`, `company_id`. Assert: a row appears in `history[]` IFF
  `(status IN ('APPROVED','PAID')) AND (is_excluded = 0) AND (deleted_at IS NULL) AND
  (super-admin OR run.company_id = caller.companyId)`.
- Generate random `(scheme, run)` pairs with the scheme optionally soft-deleted. Assert
  presence of the recipient row is unaffected by `bonus_schemes.deleted_at`.
- Generate random `limit` values in `[-100, 1000]`. Assert effective limit is in `[1, 200]`
  and `count(history) <= effective_limit`.
- Generate two databases that differ only outside the bug condition. Assert the fixed and
  original endpoints return identical responses on both (preservation under deep equality).

### Integration Tests

- End-to-end: HR creates scheme → run → calculate → approve → mark-paid; employee opens
  `/bonuses/my` (frontend Vitest test mocking the API) and sees the bonus appear at each
  status transition; year grouping by `period_year` renders correctly.
- Multi-tenant: two companies in the same DB; an employee in company A and an HR in
  company B confirm that HR-B cannot see employee-A's bonus history (403 / empty plus
  cross-company diagnostic).
- Super-admin: switching `X-Company-Id` does not change visibility for employee history
  endpoint when `companyId = 0`; selecting a company applies the filter.
