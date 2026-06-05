# Bugfix Requirements Document

## Introduction

When HR or super-admin completes the Bonus Engine lifecycle (DRAFT → CALCULATED → APPROVED → PAID) for an eligible employee, the resulting bonus is not delivered to that employee's self-service view. The bonus does not appear on the "Riwayat Bonus" page (`/bonuses/my`, backed by `GET /employees/{id}/bonus-history`) even after the run reaches APPROVED or PAID status. As a result, employees have no transparency into bonuses that HR has already approved or paid out, which breaks the trust contract of the self-service module and forces manual confirmation through HR/Finance.

The fix must restore correct delivery of APPROVED/PAID bonus rows to the affected employee while preserving the existing confidentiality rules (DRAFT/CALCULATED hidden by default), exclusion semantics (`is_excluded=1` rows omitted), multi-tenant scoping (cross-company isolation), and self-vs-HR authorization rules already enforced by the endpoint.

## Bug Analysis

### Current Behavior (Defect)

The bonus does not reach the employee's "Riwayat Bonus" page even after HR has fully approved or paid the bonus run that includes them.

1.1 WHEN HR advances a bonus run that includes an eligible employee from CALCULATED to APPROVED THEN the system does not list that bonus in the employee's "Riwayat Bonus" page (`/bonuses/my`)

1.2 WHEN HR marks a bonus run that includes an eligible employee as PAID THEN the system does not list that bonus in the employee's "Riwayat Bonus" page

1.3 WHEN the employee opens `/bonuses/my` after their bonus has been approved or paid THEN the system shows the "Belum ada bonus" empty state instead of the approved/paid bonus

1.4 WHEN the employee's frontend calls `GET /employees/{self_id}/bonus-history` after their bonus has been approved or paid THEN the system returns an empty `history[]` array (or omits the bonus from `history[]`) and zeroed `stats` for that bonus

### Expected Behavior (Correct)

The bonus must be delivered to the employee as soon as the run reaches APPROVED, and must remain visible after PAID, with correct metadata.

2.1 WHEN HR advances a bonus run that includes an eligible, non-excluded recipient row for an employee from CALCULATED to APPROVED THEN the system SHALL include that bonus in the employee's "Riwayat Bonus" page with `run_status = APPROVED`

2.2 WHEN HR marks a bonus run that includes an eligible, non-excluded recipient row for an employee as PAID THEN the system SHALL include that bonus in the employee's "Riwayat Bonus" page with `run_status = PAID`

2.3 WHEN the employee opens `/bonuses/my` and at least one APPROVED or PAID bonus run holds a non-excluded recipient row for them within their tenant scope THEN the system SHALL return that recipient row in `history[]` with correct `amount`, `scheme_name`, `scheme_code`, `scheme_kind`, `pay_date`, `period_year`, `tax_treatment`, and `run_status`

2.4 WHEN aggregate stats (`total_amount`, `count`, `by_kind`) are computed for the response THEN the system SHALL include every APPROVED/PAID, non-excluded recipient row that belongs to the requesting employee within their tenant scope

2.5 WHEN the employee is logged in and their account is linked to an employee record that has at least one APPROVED/PAID, non-excluded recipient row in the same company THEN the system SHALL resolve the request to that employee's recipient rows (no silent mismatch on `employee_id` resolution or company scope)

### Unchanged Behavior (Regression Prevention)

The fix must preserve all confidentiality, exclusion, cancellation, multi-tenant, and authorization behavior that already works for non-buggy inputs.

3.1 WHEN a bonus run is in DRAFT or CALCULATED status and the caller does not pass `include_pending=1` THEN the system SHALL CONTINUE TO omit that bonus from the employee's "Riwayat Bonus" page

3.2 WHEN the employee toggles "Tampilkan Pending" (sends `include_pending=1`) and they have a non-excluded recipient row on a DRAFT or CALCULATED run THEN the system SHALL CONTINUE TO include that bonus in the response

3.3 WHEN a recipient row has `is_excluded = 1` THEN the system SHALL CONTINUE TO omit that bonus from the employee's "Riwayat Bonus" page regardless of run status

3.4 WHEN a bonus run is in CANCELLED status THEN the system SHALL CONTINUE TO omit that bonus from the employee's "Riwayat Bonus" page

3.5 WHEN a bonus run is soft-deleted (`bonus_runs.deleted_at IS NOT NULL`) THEN the system SHALL CONTINUE TO omit that bonus from the employee's "Riwayat Bonus" page

3.6 WHEN a user is logged in but their account is not linked to any employee record (e.g., super-admin / IT admin without employee mapping) THEN the system SHALL CONTINUE TO render the "Akun belum terhubung ke karyawan" diagnostic state and SHALL NOT call the bonus-history endpoint with an invalid id

3.7 WHEN an authenticated user without `bonus.view` permission requests `GET /employees/{id}/bonus-history` for an `id` that is not their own employee id THEN the system SHALL CONTINUE TO respond with HTTP 403 Forbidden

3.8 WHEN an HR user with `bonus.view` permission requests `GET /employees/{id}/bonus-history` for any employee in their company THEN the system SHALL CONTINUE TO return that employee's APPROVED/PAID, non-excluded recipient rows scoped to the HR user's tenant company

3.9 WHEN a non-super-admin user requests bonus history for an employee whose recipient row belongs to a different company than the caller's tenant THEN the system SHALL CONTINUE TO exclude rows from the other company (multi-tenant isolation)

3.10 WHEN a super-admin requests bonus history for any employee THEN the system SHALL CONTINUE TO return rows across all companies (no company filter applied)

3.11 WHEN the response is empty for any of the existing diagnostic cases (cross-company mismatch, all recipients excluded, only pending rows exist, only cancelled rows exist, no recipient rows at all) THEN the system SHALL CONTINUE TO populate the `diagnostic` payload so the UI can render the corresponding actionable message

3.12 WHEN the caller passes `limit` THEN the system SHALL CONTINUE TO clamp it to the range [1, 200] and default it to 50 when omitted

3.13 WHEN the response orders rows THEN the system SHALL CONTINUE TO sort by `run.pay_date DESC, run.id DESC`

3.14 WHEN the response groups rows by year on the frontend THEN the system SHALL CONTINUE TO use `period_year` (falling back to year-of-`pay_date`) as the grouping key

3.15 WHEN the bonus run is APPROVED or PAID and the recipient row exists with `is_excluded = 0` for an employee not in the requesting user's company AND the requesting user is not super-admin THEN the system SHALL CONTINUE TO omit that bonus (no leak across tenants)
