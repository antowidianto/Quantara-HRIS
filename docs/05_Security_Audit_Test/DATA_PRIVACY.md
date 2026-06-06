# HRIS — Data Privacy & Permission Scope

Dokumen ini menjelaskan **bagaimana HRIS memastikan data personal karyawan
hanya bisa diakses oleh yang berhak**. Wajib dibaca saat:
- Menambah endpoint list/show baru yang punya kolom `employee_id`
- Mengubah role/permission seeder
- Audit kepatuhan UU PDP / ISO 27001

## Prinsip dasar

Setiap data personal di HRIS harus melewati **3 lapis filter**:

```
┌────────────────────────────────────────────────────────────────┐
│  Layer 1: TenantContext.applyScope() — company_id boundary     │
│           Super admin bypass; semua user lain dipaksa ke       │
│           company_id mereka.                                   │
├────────────────────────────────────────────────────────────────┤
│  Layer 2: PrivacyScope.applyListScope() — employee_id filter   │
│           User tanpa permission "view all" dipaksa ke          │
│           employee_id mereka sendiri. Filter `?employee_id=X`  │
│           dari query string di-override.                       │
├────────────────────────────────────────────────────────────────┤
│  Layer 3: TenantContext.applyAttendanceScope() — branch_id     │
│           (Khusus modul yang punya scope cabang)               │
│           User tanpa `attendance.view_all_branches` dipaksa    │
│           ke branch mereka.                                    │
└────────────────────────────────────────────────────────────────┘
```

Tanpa salah satu layer ini, data antar tenant atau antar karyawan bisa bocor.

## Konvensi permission slug

Sprint 5-6 menstandarkan nama permission per modul. Setiap modul yang punya
data personal employee mengikuti pola:

| Module    | "View all"               | "View own"                            | Action lain                   |
|-----------|--------------------------|---------------------------------------|-------------------------------|
| leave     | `leave.approve` *(legacy)* / `leave.view_all` | `leave.view`, `leave.create`, `leave.view.own` | `leave.manage_types` |
| overtime  | `overtime.approve` *(legacy)* / `overtime.view_all` | `overtime.view`, `overtime.create` | — |
| payslip   | `payslip.view_all`       | `payslip.view_self`                   | — |
| attendance | `attendance.view_all`, `attendance.approve` | `attendance.view`, `attendance.clock_in` | `attendance.view_all_branches` |
| reimbursement | `reimbursement.approve`, `reimbursement.view_all` | `reimbursement.view`, `reimbursement.create` | — |
| loan      | `loan.approve`, `loan.view_all` | `loan.view`, `loan.create`     | — |

Mapping ini di-encode di `App\Libraries\PrivacyScope::MODULE_MAP`.

> **Backward compatibility**: HRIS punya legacy slugs (mis. `leave.view`)
> yang dulu HR pakai untuk "lihat semua", padahal sekarang dipakai untuk
> "view own". MODULE_MAP memetakan kedua konvensi sehingga tidak butuh
> migrasi data — user existing tetap berfungsi.

## Pola pemakaian di controller

### List endpoint (index)

```php
public function index()
{
    $db = \Config\Database::connect();
    $builder = $db->table('leaves l')
        ->select('l.*, e.full_name AS employee_name')
        ->join('employees e', 'e.id = l.employee_id');

    // Permintaan filter dari query string:
    $requestedEmpId = (int) ($this->request->getGet('employee_id') ?? 0);
    $mine = $this->request->getGet('mine');

    // SATU panggilan, terapkan tenant + employee scope:
    $effectiveEmpId = $this->privacy()->applyListScope(
        $builder,
        module: 'leave',                           // → MODULE_MAP['leave']
        companyColumn: 'l.company_id',
        employeeColumn: 'l.employee_id',
        requestedEmployeeId: $mine
            ? $this->tenant()->getEmployeeId()
            : ($requestedEmpId ?: null),
    );
    if ($effectiveEmpId === null) {
        return $this->fail('Anda tidak memiliki akses ke modul cuti.', 403);
    }

    // Filter aman lain (status, date) boleh langsung pakai input:
    if ($status = $this->request->getGet('status')) {
        $builder->where('l.status', $status);
    }

    return $this->paginated(/* ... */);
}
```

**Yang dilakukan `applyListScope` otomatis:**
- Tenant scope `WHERE l.company_id = ?` (kecuali super admin)
- Employee scope:
  - User dengan canViewAll → optional filter (kalau request explicit)
  - User canViewOwn only → paksa `WHERE l.employee_id = $tenant.employee_id`,
    abaikan input `?employee_id=X` (silent override + audit log NOTICE)
- Return `null` kalau user tidak punya akses sama sekali (block 403)

### Show / update / delete endpoint

```php
public function show($id = null)
{
    $row = $db->table('leaves')->where('id', (int) $id)->get()->getRowArray();
    if (! $row) return $this->fail('Not found', 404);

    if (! $this->privacy()->canAccessRow($row, 'leave')) {
        return $this->fail('Forbidden', 403);
    }

    return $this->ok($row);
}
```

`canAccessRow($row, $module)` return true kalau salah satu:
- Super admin
- Row tenant cocok DAN canViewAll($module)
- Row tenant cocok DAN row.employee_id == tenant.employee_id

### Filter whitelist

Untuk endpoint yang menerima banyak query param, gunakan
`sanitizeFilters($input, $module, $allowed)` yang strip filter
cross-employee untuk user view.own only:

```php
$clean = $this->privacy()->sanitizeFilters(
    $this->request->getGet(),
    'leave',
    ['status', 'start_date', 'end_date', 'employee_id', 'branch_id'],
);
// User view.own only: clean['employee_id'] dan clean['branch_id'] di-strip
// User canViewAll: pass-through semua
```

## Test matrix

Untuk memverifikasi pattern bekerja, run skenario ini saat menambah endpoint:

| Skenario                                                  | Expected                                |
|-----------------------------------------------------------|-----------------------------------------|
| Super admin GET /leaves                                   | 200, semua data semua company           |
| HR Admin GET /leaves                                      | 200, semua leave dalam company         |
| HR Admin GET /leaves?employee_id=999                      | 200, leave dari employee 999 (kalau dalam company) |
| HR Admin GET /leaves?employee_id=999 (di company lain)    | 200, kosong (tenant scope kena)         |
| Manager GET /leaves                                       | 200, semua leave dalam company (punya `leave.approve`) |
| Employee GET /leaves                                      | 200, hanya leave miliknya              |
| Employee GET /leaves?employee_id=999 (manipulasi)         | 200, hanya miliknya (silent override + audit log NOTICE) |
| Employee GET /leaves/{id-orang-lain}                      | 403 Forbidden                           |
| User tanpa role apa-apa GET /leaves                       | 403 Forbidden                           |

## Yang sudah di-refactor di Sprint 5-6

| Endpoint                          | Status before                                       | Status after                                                |
|-----------------------------------|----------------------------------------------------|-------------------------------------------------------------|
| GET /leaves                       | `?employee_id=X` pass-through tanpa cek            | PrivacyScope.applyListScope('leave')                        |
| GET /leaves/{id}                  | Tenant scope OK                                    | Tetap aman                                                   |
| GET /payslips                     | `?employee_id=X` pass-through tanpa cek            | PrivacyScope.applyListScope('payslip')                      |
| GET /payslips/{id}                | Hardcoded `roleId in [4]`                          | PrivacyScope.canAccessRow('payslip')                        |
| GET /payslips/{id}/pdf            | Hardcoded `roleId in [4]`                          | PrivacyScope.canAccessRow('payslip')                        |
| GET /payslips/{id}/bukti-potong   | Hardcoded `roleId in [4]`                          | PrivacyScope.canAccessRow('payslip')                        |
| GET /overtimes                    | `?employee_id=X` pass-through tanpa cek            | PrivacyScope.applyListScope('overtime')                     |
| GET /overtimes/{id}               | **Tidak ada authorization sama sekali**            | PrivacyScope.canAccessRow('overtime') ← **fix kebocoran**   |

## Yang belum di-refactor (defer ke Sprint berikutnya)

Endpoint berikut belum di-migrasi ke PrivacyScope tapi sudah punya layer
tenant scoping & authorization minimal. Daftar TODO:

- `/api/v1/attendance` — punya `attendance.view_all_branches`, sudah branch-scope
- `/api/v1/reimbursements`, `/api/v1/loans` — pakai resource convention
- `/api/v1/asset-assignments`, `/api/v1/disciplinary-records`, `/api/v1/severance`
- `/api/v1/business-trips`, `/api/v1/employee-tasks`, `/api/v1/certificates`
- `/api/v1/training-registrations`, `/api/v1/performance-reviews`

Pattern yang sama bisa diterapkan di iterasi berikut. Cara mendeteksi yang
butuh refactor: cari `getGet('employee_id')` di semua controllers — kalau
nilai itu langsung masuk ke `where('employee_id', $val)` tanpa permission
check, itu kebocoran.

## Audit & alert

`App\Libraries\PrivacyScope::sanitizeEmployeeFilter` mencatat audit log
NOTICE setiap kali user mencoba pass `?employee_id=X` yang bukan miliknya
saat hanya punya `view.own`. Filter `module=PRIVACY` di
`/admin/audit-logs?action=privacy.scope_override` untuk lihat siapa yang
mencoba akses cross-employee.

`php spark hr:audit` (Sprint 1 audit otomatis) bisa di-extend supaya
mendeteksi spike audit `privacy.scope_override` (indikasi attack atau bug
frontend yang kirim filter salah).