# Cost Center Integration (Sprint 6)

## Mengapa cost center?

Sistem multi-tenant + multi-branch perlu dimensi accounting "cost center"
untuk track ke mana biaya HR (gaji, allowance, lembur, reimbursement,
pinjaman, bonus, training, severance, business trip, asset, recruitment)
di-charge.

**Tanpa cost center:**
- Finance tidak bisa allocate biaya per profit center / lini bisnis
- Budget vs actual hanya bisa di level company atau branch (terlalu kasar)
- Laporan keuangan ke external (akuntansi, GL) tidak punya dimensi
  analitik yang dibutuhkan controller / management accounting
- Tidak ada akuntabilitas budget per kepala departemen / unit

**Dengan cost center:**
- Setiap transaksi HR di-tag ke cost center spesifik
- Budget tahunan/quarter/bulanan bisa di-set per cost center per kategori
- Real-time budget vs actual per cost center
- Hierarchical roll-up: cost center child sum-up ke parent untuk
  reporting tier-by-tier (Team → Dept → Directorate → Company)

## Konsep

### Hierarki

```
ROOT (DEFAULT, auto-created per company saat migration)
├── DIRECTORATE-COMMERCIAL
│   ├── DEPT-SALES
│   │   ├── TEAM-JKT
│   │   └── TEAM-SBY
│   └── DEPT-MARKETING
└── DIRECTORATE-OPERATIONS
    └── DEPT-IT
        ├── TEAM-DEV
        └── TEAM-INFRA
```

Setiap cost center punya:
- `code` (unik per company): mis. `CC-IT-DEV`, `OPS-JKT`
- `name` lengkap
- `parent_id` (hirarki, optional)
- `manager_employee_id` (PIC budget)
- `default_branch_id` / `default_department_id` (display only)
- `budget_yearly` (cache total)
- `is_active`

### Resolusi cost_center_id (chain of responsibility)

Saat sistem perlu menentukan cost center untuk transaksi, urutan prioritas:

1. **Override eksplisit** di payload — caller sengaja pilih
2. **`employee.cost_center_id`** — kalau di-set di profil karyawan
3. **`department.default_cost_center_id`** — default per dept
4. **`branch.default_cost_center_id`** — default per cabang
5. **Company root** — fallback `code = 'DEFAULT'`
6. **NULL** — last resort, transaksi tetap bisa lanjut

```php
// CostCenterService::resolveFromChainPure()
$result = CostCenterService::resolveFromChainPure(
    overrides: ['cost_center_id' => 99],   // 1. menang dari semua
    employee:  ['cost_center_id' => 11],   // 2. menang kalau no override
    department:['cost_center_id' => 12],   // 3.
    branch:    ['cost_center_id' => 13],   // 4.
    companyRootId: 14,                     // 5.
);
```

Method DB-aware tinggal pakai:

```php
// Saat insert transaksi (reimbursement, payroll entry, dll)
$ccId = Services::costCenter()->resolveForEmployee($employeeId, [
    'cost_center_id' => $request->payload->cost_center_id ?? null,
]);
```

## Schema

### Tabel baru

**`cost_centers`** — master cost center per company

| Kolom | Tipe | Deskripsi |
|-------|------|-----------|
| id | INT PK | |
| company_id | INT FK | tenant |
| parent_id | INT FK | hirarki (nullable = root) |
| code | VARCHAR(32) | unik per company |
| name | VARCHAR(200) | |
| description | TEXT | |
| default_branch_id | INT FK | display only |
| default_department_id | INT FK | display only |
| manager_employee_id | INT FK | PIC budget |
| budget_yearly | DECIMAL(18,2) | cache total |
| is_active | TINYINT | |
| sort_order | INT | display order |
| notes, created_by, updated_by, created_at, updated_at, deleted_at | | |

**`cost_center_budgets`** — budget periodisasi

| Kolom | Tipe | Deskripsi |
|-------|------|-----------|
| id | INT PK | |
| company_id | INT FK | |
| cost_center_id | INT FK | |
| period_year | YEAR | mis. 2026 |
| period_type | ENUM | ANNUAL / QUARTERLY / MONTHLY |
| period_no | TINYINT | 1-4 (Q) / 1-12 (M) / NULL (annual) |
| amount | DECIMAL(18,2) | |
| category | VARCHAR(32) | SALARY/BONUS/TRAINING/dll (nullable = total) |
| notes | | |

Unique key: `(cost_center_id, period_year, period_type, period_no, category)`

### Kolom `cost_center_id` ditambahkan ke

**Master:**
- `departments` — default per dept
- `branches` — default per branch
- `employees` — primary resolver source

**Recruitment:**
- `manpower_requests` — di-plan saat MPP submit
- `job_postings` — carry-over dari MPP

**Transactional:**
- `payroll_entries` — populated saat calculate
- `reimbursements`
- `loans`
- `business_trips`
- `business_trip_expenses`
- `bonus_runs`
- `training_budgets`
- `severance_calculations`
- `asset_assignments`
- `employee_allowances` (kalau tabel ada)

Semua nullable & idempotent (cek via `getFieldNames`).

## Wiring per modul

### MPP (`ManpowerController`)

Saat requestor submit MPP, cost_center_id di-set:
1. Eksplisit dari payload (validate `assertValidForCompany`)
2. Auto-resolve dari `department_id`/`branch_id` payload kalau tidak

```php
// ManpowerController::create()
if (in_array('cost_center_id', $mppCols, true)) {
    $explicitCc = ! empty($data['cost_center_id']) ? (int) $data['cost_center_id'] : null;
    if ($explicitCc) {
        Services::costCenter()->assertValidForCompany($explicitCc, $companyId);
        $insert['cost_center_id'] = $explicitCc;
    } else {
        $insert['cost_center_id'] = Services::costCenter()
            ->resolveForRequestData($companyId, $insert);
    }
}
```

### Recruitment (`OnboardingPlanService::syncFromMpp` + `RecruitmentService::convertToEmployee`)

MPP → Job Posting → Employee carry-over:

```
manpower_requests.cost_center_id
    ↓ (TA buat posting)
job_postings.cost_center_id (via OnboardingPlanService::syncFromMpp)
    ↓ (HR hire candidate)
employees.cost_center_id (via Services::costCenter()->resolveForEmployee)
```

Override hire form, posting, MPP, dept, branch, root — semua dievaluasi
chain.

### Transaksional (semua controller pakai pattern uniform)

Pattern uniform untuk insert transaksi:

```php
if (in_array('cost_center_id', $db->getFieldNames($table), true)) {
    try {
        $insert['cost_center_id'] = Services::costCenter()->resolveForEmployee(
            (int) $employeeId,
            ['cost_center_id' => $payload['cost_center_id'] ?? null],
        );
    } catch (\Throwable $e) {
        log_message('warning', "{$module} cost_center resolve failed: " . $e->getMessage());
    }
}
```

`getFieldNames` cek defensif kalau migration 000057 belum jalan.
`resolveForEmployee` mempertimbangkan: override → employee → dept →
branch → company root.

**Sudah ter-wire (Sprint 6):**
- `ReimbursementController::create` — `reimbursements.cost_center_id`
- `LoanController::create` — `loans.cost_center_id`
- `BusinessTripController::create` — `business_trips.cost_center_id`
- `BonusController::create` — `bonus_runs.cost_center_id` (header
  level; recipient masih per-employee resolve di `calculate`)
- `SeveranceController::create` — `severance_calculations.cost_center_id`
- `PayrollService::calculateEmployee` — `payslips.cost_center_id`
  (untuk laporan budget vs actual kategori SALARY/OVERTIME/BENEFIT/BONUS)

## API Endpoints

### Master CRUD
- `GET    /cost-centers` — list flat dengan filter q, is_active, page
- `GET    /cost-centers/tree` — hirarki tree dengan field `children`
- `GET    /cost-centers/picker` — dropdown lightweight (id, code, name, parent)
- `GET    /cost-centers/{id}` — detail + counts
- `POST   /cost-centers` — create (validate code format & uniqueness)
- `PUT    /cost-centers/{id}` — update (cek cycle prevention pada parent_id)
- `DELETE /cost-centers/{id}` — soft-delete (block kalau punya child / employees)

### Budget periodisasi
- `GET  /cost-centers/{id}/budgets?year=2026` — list semua budget row
- `POST /cost-centers/{id}/budgets` — bulk upsert array `budgets[]`
- `DELETE /cost-center-budgets/{id}` — hapus single row

### Reports
- `GET /cost-centers/{id}/budget-vs-actual?year=2026`
  → `{ budget, actual, variance, utilization_pct, by_category }`
- `GET /cost-centers/{id}/actuals?from=&to=`
  → breakdown `by_category` + `breakdown[]` per source table

### Operasi
- `POST /cost-centers/backfill-employees` — bulk apply default ke karyawan
  yang belum di-tag (idempotent)

## Permissions

- `cost_center.view` — list/show + tree + budget vs actual
- `cost_center.manage` — CRUD master + budget + backfill

Auto-grant default:
- Role 1 (super), 2 (HR), 5 (finance) → view + manage
- Role 3 (manager) → view (untuk dashboard budget tim)

## Bisnis Flow (typical)

### Setup awal (sekali per company)

1. HR/Finance buka **Master → Cost Centers**
2. Buat cost center root (parent NULL):
   - DIRECTORATE-COMMERCIAL
   - DIRECTORATE-OPERATIONS
   - DIRECTORATE-CORPORATE
3. Buat sub-tree per directorate (dept → team)
4. Set `manager_employee_id` per cost center
5. (Opsional) Set `default_cost_center_id` di department & branch master:
   - mis. Department IT default ke `CC-IT`, Branch Jakarta ke `CC-OPS-JKT`
6. Klik **Backfill Employees** — sistem auto-tag karyawan ke cost center
   sesuai dept/branch-nya
7. Set budget tahunan per cost center per kategori (SALARY, BONUS, TRAINING, dll)

### Saat transaksi berlangsung

- **MPP submit** — requestor pilih cost center (auto-suggest dari dept)
- **Employee hire** — auto-resolve dari MPP → posting → dept → branch
- **Reimbursement** — auto-tag dari profil karyawan
- **Business trip** — auto-tag, bisa di-override di expense item
- **Bonus run** — header level cost center default semua recipient
- **Severance** — tag ke cost center tempat karyawan terakhir bekerja

### Reporting

HR/Finance buka **Cost Center → {nama} → Budget vs Actual**:

```
Budget Yearly: Rp 5,000,000,000
Actual YTD:    Rp 3,450,000,000
Variance:      Rp 1,550,000,000 (31% available)
Utilization:   69%

By Category:
  SALARY:    [██████████░] Budget 3.0M  Actual 2.4M  (80%)
  ALLOWANCE: [█████░░░░░░] Budget 800K  Actual 420K  (53%)
  BONUS:     [██░░░░░░░░░] Budget 500K  Actual 100K  (20%)
  TRAVEL:    [████████░░░] Budget 200K  Actual 160K  (80%)
  TRAINING:  [█░░░░░░░░░░] Budget 300K  Actual 30K   (10%)  ⚠ underutilized
  ...
```

## Test

`backend/tests/Unit/CostCenterServiceTest.php` — 27 tests, 47 assertions.

Cakupan pure helpers:
- **`resolveFromChainPure`** (9 test):
  - Override menang dari semua
  - Employee menang di atas dept/branch/root
  - Department menang kalau employee missing
  - Branch menang kalau dept missing
  - Root sebagai last resort
  - Null kalau semua kosong
  - Zero/empty string treated as not-set
  - Coerces string number to int
- **`validateCostCenterCodePure`** (9 test):
  - Format valid (alphanumeric + hyphen + underscore)
  - Min 2 char, max 32 char
  - Reject spaces, special chars
  - Must contain letter (numeric-only ditolak)
  - Trim whitespace
- **`isDescendantPure`** (8 test):
  - Self, direct child, deep descendant
  - Sibling, ancestor, cousin tidak descendant
  - Cycle protection (tidak hang on bad data)
- **Constants sanity**: BUDGET_CATEGORIES, PERIOD_TYPES

```
PHPUnit 10.5.63
...........................                                       27 / 27 (100%)
OK (27 tests, 47 assertions)
```

Full suite: **607 tests, 1396 assertions** — semua lulus.

## Migration ID

`backend/app/Database/Migrations/2024-01-01-000057_CreateCostCenters.php`

Semua perubahan idempotent:
- `tableExists` cek sebelum create
- `getFieldNames` cek sebelum addColumn
- Backfill ROOT cost center per company saat migrate-up

## File yang berubah

**Baru (backend):**
- `backend/app/Database/Migrations/2024-01-01-000057_CreateCostCenters.php`
- `backend/app/Libraries/CostCenterService.php`
- `backend/app/Controllers/Api/V1/CostCenterController.php`
- `backend/tests/Unit/CostCenterServiceTest.php`
- `docs/COST_CENTER.md` (file ini)

**Baru (frontend):**
- `frontend/src/views/Master/CostCenters.vue` — master page dengan 3 tab
  (List, Hirarki, Budget vs Actual). CRUD lengkap, set budget per
  periode (annual/quarterly/monthly), report budget vs actual per
  kategori, dan tombol "Backfill Karyawan" untuk apply default ke
  semua karyawan yang belum di-tag.
- `frontend/src/views/Master/CostCenterNode.vue` — recursive tree node
  component (dipakai tab "Hirarki").
- `frontend/src/components/CostCenterPicker.vue` — dropdown reusable.
  Module-level cache supaya banyak picker pada page yang sama hanya
  fetch `/cost-centers/picker` 1×. Pakai dari form transaksi
  (reimbursement, loan, business trip, bonus, severance) atau master
  (employee, MPP) untuk override default.

  Contoh:
  ```vue
  <script setup>
  import CostCenterPicker from '@/components/CostCenterPicker.vue'
  const form = ref({ cost_center_id: null })
  </script>
  <template>
    <label class="label">Cost Center</label>
    <CostCenterPicker v-model="form.cost_center_id" />
  </template>
  ```

**Diubah:**
- `backend/app/Config/Services.php` — daftarkan `Services::costCenter()`
- `backend/app/Config/Routes.php` — endpoint cost-centers + budgets
- `backend/app/Controllers/Api/V1/ManpowerController.php` — accept & validate cost_center_id
- `backend/app/Libraries/OnboardingPlanService.php` — carry-over MPP → posting
- `backend/app/Libraries/RecruitmentService.php` — apply ke employee saat hire
- `backend/app/Libraries/PayrollService.php` — tag payslip ke cost
  center karyawan saat calculate (resolveForEmployee chain)
- `backend/app/Libraries/CostCenterService.php` (`actualsBreakdown`) —
  agregat dari tabel `payslips` untuk SALARY/OVERTIME/BENEFIT/BONUS,
  ditambah legacy fallback ke `payroll_entries`
- `frontend/src/router/index.js` — route `/master/cost-centers`
- `frontend/src/layouts/AppLayout.vue` — menu sidebar di group
  "Master Data" (perm: `cost_center.view`)

## Backward compatibility

- Semua kolom baru **nullable**.
- Migration **idempotent** — bisa di-rerun aman.
- Service tetap berfungsi untuk instance yang belum jalan migration
  (`getFieldNames` check di setiap method DB-aware).
- Transaksi lama yang `cost_center_id IS NULL` akan default ke ROOT
  saat ditampilkan di laporan budget vs actual.