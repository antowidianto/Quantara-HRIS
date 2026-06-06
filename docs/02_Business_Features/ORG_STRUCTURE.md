# Master Jabatan Kunci (Organization Roles) & Org Structure

Master jabatan kunci adalah single source of truth untuk **siapa yang
saat ini menjabat sebagai HR Manager, Direktur HR, Finance Manager,
dst** di setiap company. Modul lain (MPP, Reimbursement, Severance,
Approval Engine) memakai data ini untuk routing approval — supaya tidak
hard-coded di kode/seed.

Lihat juga:
- [`docs/MPP.md`](./MPP.md) untuk konsumen utama (approver MPP).
- [`docs/APPROVAL_AND_CONTRACTS.md`](./APPROVAL_AND_CONTRACTS.md) untuk
  approval chain generik (per-modul).
- [`docs/APPROVAL_DELEGATION.md`](./APPROVAL_DELEGATION.md) untuk PIC
  pengganti saat penjabat cuti panjang.

---

## Kenapa perlu Master Jabatan Kunci?

Sebelum modul ini ada, approval routing memakai *role-based* (RBAC):
"siapapun yang punya permission `mpp.approve` akan melihat di inboxnya".
Itu cukup untuk demo, tapi di production:

1. **Banyak orang bisa punya permission yang sama.** HRD Manager,
   Asisten HRD Manager, dan Direktur HR bisa sama-sama punya `mpp.approve`.
   Yang dimaksudkan flow: HRD Manager dulu, baru Direktur. Tanpa
   master jabatan, sistem tidak tahu siapa yang "first level".
2. **Pergantian penjabat sering terjadi.** Kalau HRD Manager pindah ke
   Direktur HR, harus update di banyak tempat. Master Jabatan = update
   sekali, semua modul ikut berubah.
3. **Audit & legal.** Surat keputusan, kontrak, payslip, dll perlu
   tahu "siapa pejabat yang menandatangani saat itu". Tabel ini juga
   menjadi referensi historis (bisa di-query lewat audit log).
4. **Org chart visualisasi.** Frontend bisa render organogram tanpa
   harus join banyak tabel. Cukup `parent_role_id` chain.

---

## Skema database

### `organization_structures` (migrasi 32)

| Kolom                  | Tipe                | Catatan                                        |
| ---------------------- | ------------------- | ---------------------------------------------- |
| `id`                   | INT PK              |                                                |
| `company_id`           | INT FK              | Multi-tenant                                   |
| `role_key`             | VARCHAR(64)         | Identifier stabil, mis. `HRD_MANAGER`. Unique  |
|                        |                     | per company. **Tidak bisa diubah** setelah     |
|                        |                     | dibuat.                                        |
| `title`                | VARCHAR(200)        | Display name, mis. "HR Manager Pusat"          |
| `level`                | ENUM                | BOD, EXECUTIVE, GENERAL_MANAGER, MANAGER,      |
|                        |                     | ASST_MANAGER, SUPERVISOR, OFFICER, STAFF       |
| `description`          | TEXT                | Free text                                      |
| `department_id`        | INT FK NULL         | Scope departemen (NULL = lintas dept)          |
| `branch_id`            | INT FK NULL         | Scope cabang (NULL = semua cabang)             |
| `parent_role_id`       | INT FK NULL         | Atasan strategis. Self-referencing, bentuk     |
|                        |                     | tree organogram                                |
| `employee_id`          | INT FK NULL         | Penjabat saat ini                              |
| `backup_employee_ids`  | JSON                | Array employee_id PJS. Dipakai saat penjabat   |
|                        |                     | cuti panjang/vacant                            |
| `approval_scopes`      | JSON                | Array string scope, mis. `["mpp.budget",       |
|                        |                     | "mpp.unbudget"]`. Dipakai routing engine       |
| `is_key_role`          | TINYINT(1)          | Muncul di picker frontend (default 1)          |
| `is_active`            | TINYINT(1)          | Soft enable/disable                            |
| `sort_order`           | INT                 | Urutan tampilan dalam grup level               |
| `created_*`/`deleted_*`| TIMESTAMP           | Audit                                          |

### Field expected approver di `manpower_request_approvals` (migrasi 33)

Saat MPP di-submit, `ManpowerService::submit()` resolve role_key →
employee/user via `OrganizationStructureService::resolveApproverUser()`.
Hasilnya disimpan ke 2 kolom baru:

- `expected_approver_user_id`     INT NULL
- `expected_approver_employee_id` INT NULL

Kolom ini snapshot — meskipun penjabat di Master Jabatan diganti
setelah submit, snapshot tetap menunjuk ke penjabat lama. Frontend
tetap bisa override realtime dengan call `OrganizationStructureService`
jika diperlukan (lihat `ManpowerController::show()` enrichment loop).

---

## Permission

Migrasi 32 menambah 2 permission:

| Permission              | Role default      | Untuk                                     |
| ----------------------- | ----------------- | ----------------------------------------- |
| `org_structure.view`    | HR, Manager       | Lihat daftar role + organogram            |
| `org_structure.manage`  | HR Admin, SuperAdmin | Tambah/edit role, assign penjabat       |

Note: assignment penjabat tidak butuh permission khusus selain
`org_structure.manage`. Untuk audit trail, semua perubahan ditulis ke
`audit_logs` dengan action `org_structure.assign`.

---

## API

Base: `/api/v1/organization-roles` (auth required).

### List & filter
```
GET /organization-roles?q=&level=&has_scope=&assigned_only=1
```
Return: array role dengan join nama penjabat, dept, branch.

### Get one
```
GET /organization-roles/:id
```

### Create / Update / Delete
```
POST   /organization-roles              # body: role_key, title, level, ...
PUT    /organization-roles/:id          # role_key tidak boleh diubah
DELETE /organization-roles/:id
```

### Assign penjabat (quick-action)
```
POST /organization-roles/:id/assign
{
  "employee_id": 42,                   // null untuk vacant
  "backup_employee_ids": [55, 67]      // PJS
}
```

### Picker untuk dropdown
```
GET /organization-roles/picker?scope=mpp.budget
```
Return slim list `{id, role_key, title, employee_name, user_id}`
yang aktif + match scope. Dipakai oleh dropdown approver di MPP form.

### Resolve role → employee/user (programmatic)
```
GET /organization-roles/resolve?role_key=HRD_MANAGER
```
Return `{employee_id, user_id, employee_name, employee_number}`.
Backend lain bisa call ini lewat `Services::orgStructure()` daripada
HTTP.

### Organogram
```
GET /organization-roles/organogram
```
Return tree-shaped dari `parent_role_id` chain. Dipakai untuk
visualisasi struktur organisasi.

---

## Service (backend)

`App\Libraries\OrganizationStructureService` di-register sebagai
service singleton:

```php
$svc = \Config\Services::orgStructure();

// Resolve siapa yang harus approve scope tertentu
$userId  = $svc->resolveApproverUser($companyId, 'HRD_MANAGER');
$empId   = $svc->resolveApproverEmployee($companyId, 'HRD_MANAGER');

// Lookup metadata
$role    = $svc->findByRoleKey($companyId, 'HRD_MANAGER');
$title   = $role['title'] ?? 'HRD Manager';

// Cari role berdasarkan scope (utility)
$roles   = $svc->findRolesWithScope($companyId, 'mpp.unbudget');
```

Behavior `resolveApproverUser()`:
1. Cari row aktif dengan `role_key` cocok.
2. Kalau `employee_id` ada dan ter-link ke user → return user_id-nya.
3. Kalau `employee_id` ada tapi tidak ada user → cek
   `backup_employee_ids` satu per satu, ambil yang punya user.
4. Kalau semua vacant → return `null`. Caller harus handle fallback
   (mis. ke permission-based routing atau kirim notifikasi ke HR Admin).

---

## Seed default (HR Indonesia)

Migrasi 32 termasuk `seedDefaultRoles()` yang menambah 11 jabatan kunci
khas perusahaan Indonesia per company:

| role_key             | title                  | level             | scopes                                  |
| -------------------- | ---------------------- | ----------------- | --------------------------------------- |
| `DIRECTOR`           | Direktur Utama         | BOD               | mpp.unbudget, severance.high_value       |
| `HRD_DIRECTOR`       | Direktur HR            | EXECUTIVE         | mpp.unbudget, resignation.approve        |
| `FINANCE_DIRECTOR`   | Direktur Finance       | EXECUTIVE         | reimbursement.high_value, payroll.finalize |
| `OPERATIONS_DIRECTOR`| Direktur Operasional   | EXECUTIVE         | —                                       |
| `HRD_MANAGER`        | HR Manager             | MANAGER           | mpp.budget, mpp.fulfill, leave.long_term|
| `HRD_RECRUITMENT`    | Recruitment Manager    | MANAGER           | mpp.fulfill, recruitment.manage         |
| `HRD_PAYROLL`        | Payroll Manager        | MANAGER           | payroll.manage, allowance.manage        |
| `HRD_TRAINING`       | Training Manager       | MANAGER           | training.manage, performance.manage     |
| `FINANCE_MANAGER`    | Finance Manager        | MANAGER           | reimbursement.approve                    |
| `IT_MANAGER`         | IT Manager             | MANAGER           | onboarding.it_setup                      |
| `LEGAL_MANAGER`      | Legal Manager          | MANAGER           | disciplinary.issue                       |

Penjabat tetap kosong (vacant) saat seed. HR Admin harus assign
manual via halaman Master Jabatan Kunci — tidak ada auto-assign.

---

## Frontend (Master Jabatan Kunci)

Halaman: `/master/organization-roles` (component
`Master/OrganizationRoles.vue`).

Fitur:
- Group by level (BOD → STAFF) untuk skim cepat.
- Quick-assign penjabat lewat modal kecil dengan radio + search.
- Backup PJS multi-select.
- Tambah role custom (mis. "Section Head Production" untuk
  manufaktur).
- Filter by level, scope, "hanya yang sudah ada penjabat".
- Indikator vacant (warning amber + ⚠ icon).
- Indikator user account (✓ hijau bila employee punya login — bisa
  terima notifikasi & approval).

Permission gate: `org_structure.view` untuk akses, `org_structure.manage`
untuk tombol edit/assign.

---

## Konsumen saat ini

| Modul                    | Cara pakai                                                |
| ------------------------ | --------------------------------------------------------- |
| MPP (Manpower Planning)  | `submit()` resolve `HRD_MANAGER` (BUDGET) atau `HRD_MANAGER` + `DIRECTOR` (UNBUDGET) ke step approval. Frontend tampil "HR Manager → Budi Santoso" alih-alih "HRD_MANAGER". |
| Resignation              | (planned) Resolve `HRD_DIRECTOR` saat resignation di-approve final. |
| Severance high-value     | (planned) Cek scope `severance.high_value` untuk additional approver. |
| Approval Engine generic  | (planned migration) ApprovalChain bisa point ke role_key alih-alih hard-coded user_id. |

---

## Flow saat penjabat berubah

Skenario: HR Manager lama (Budi) pindah ke Direktur HR. Andi promoted
jadi HR Manager baru.

1. HR Admin buka `/master/organization-roles`.
2. Cari row "HRD_MANAGER" — penjabat saat ini = Budi.
3. Klik tombol assign (UserCircleIcon), pilih Andi sebagai penjabat
   utama. Save.
4. Sistem update `organization_structures.employee_id` ke Andi.
5. **MPP yang sudah submit** sebelum perubahan tetap menampilkan Budi
   sebagai expected approver (snapshot di
   `manpower_request_approvals.expected_approver_*`). Andi tidak
   otomatis dapat inbox MPP lama. Untuk migrasi, HR Admin bisa pilih:
   - Approve manual atas nama lama lewat Andi (audit log mencatat).
   - Atau pakai delegation (`/approvals/delegations`) untuk forward
     inbox Budi → Andi.
6. **MPP baru** setelah perubahan otomatis route ke Andi.

Kalau penjabat lama lupa logout / di-disable user-nya, fallback otomatis
ke `backup_employee_ids` (PJS). Selalu set minimal 1 PJS untuk role
penting (Direktur, HR Manager) supaya operasional tetap jalan.

---

## Best practices

- **Jangan ubah `role_key`.** Sudah dipakai di kode, alias permission,
  approval scope. Kalau salah ketik saat create, hapus dan buat lagi.
- **Set scope yang relevan.** Tanpa scope, `findRolesWithScope()` tidak
  bisa cari role itu untuk approval routing. Contoh: kalau add role
  custom "MARKETING_MANAGER", tambah scope `marketing.budget` supaya
  bisa dipanggil dari modul marketing nanti.
- **Backup penjabat untuk role kritis.** Direktur, HR Manager, Finance
  Manager wajib punya minimal 1 PJS supaya approval tidak stuck saat
  cuti panjang.
- **Gunakan `parent_role_id` untuk hierarki strategis,** bukan untuk
  hierarki teknis (atasan langsung). Atasan langsung sudah ada di
  `employees.line_manager_id`.

---

## Smoke test

```bash
cd backend
php scripts/test-org-structure-flow.php
```

Script ini:
1. Create 1 company + 2 employees (Budi, Andi).
2. Buat user untuk masing-masing.
3. Resolve `HRD_MANAGER` — expect null (vacant).
4. Assign Budi ke `HRD_MANAGER`. Resolve lagi — expect user_id Budi.
5. Disable user Budi. Assign Andi sebagai PJS. Resolve — expect
   user_id Andi (fallback ke backup).
6. Submit MPP, cek `manpower_request_approvals.expected_approver_user_id`
   = user_id Andi (snapshot saat submit).

Semua step return ✓ kalau berhasil. Lihat output script untuk detail
field yang divalidasi.

Khusus untuk validasi `bulk-reorder` (tree drag-and-drop) ada smoke test
terpisah:

```bash
php scripts/test-org-bulk-reorder.php
```

Test ini memverifikasi 4 skenario stale-state:
- Dept yang sudah soft-deleted masuk request → 409 dgn `reason=DELETED`.
- Dept dari company lain masuk request → 409 dgn `reason=CROSS_TENANT`.
- `parent_id` yang tidak ada di DB → 409 dgn `reason=NOT_FOUND`.
- `moves` kosong → 422 `NO_MOVES`.

---

## Error handling: `STALE_TREE_STATE` di bulk-reorder

`POST /org-structure/bulk-reorder` dipanggil dari halaman frontend
**Struktur Organisasi** ketika user drag-drop banyak department lalu
klik **Simpan**. Karena tree di FE bisa "out of sync" dengan DB
(mis. dept dihapus di tab lain, super admin pindah `activeCompanyId`,
atau ada concurrent edit), backend mengaggregasikan semua department
yang tidak valid menjadi satu response **HTTP 409** dengan kode
`STALE_TREE_STATE`:

```json
{
  "status": "error",
  "message": "Beberapa department dalam perubahan tidak valid (sudah dihapus: Engineering; ada di perusahaan lain: Direksi). Tree mungkin sudah berubah di sesi/tab lain — silakan muat ulang halaman.",
  "code": "STALE_TREE_STATE",
  "errors": {
    "missing_department_ids": [11, 14, 15],
    "diagnostics": {
      "11": { "reason": "DELETED",      "name": "Sales" },
      "14": { "reason": "DELETED",      "name": "Engineering" },
      "15": { "reason": "CROSS_TENANT", "name": "Direksi" }
    }
  }
}
```

Frontend (`OrgStructure.vue`) menangani response ini dengan:
1. Menampilkan toast 8 detik berisi pesan dari backend (sudah
   user-friendly).
2. Membuang seluruh `pendingMoves`.
3. Memanggil `load()` untuk refresh tree dari DB.

Selain itu, frontend juga melakukan **pruning preventif** sebelum
mengirim request: drop semua move yang department/parent-nya tidak
ada lagi di tree saat ini, dan menampilkan warning "X perubahan
diabaikan". Ini mengurangi round-trip kalau user drag-drop dept yang
kemudian dihapus di tab lain.

Tambahan: ketika super admin mengganti `activeCompanyId` di sidebar,
semua `pendingMoves` otomatis dibatalkan dan tree di-load ulang
dengan scope company baru. Ini cegah ID dept dari company sebelumnya
"bocor" ke request bulk-reorder.
