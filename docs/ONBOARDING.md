# Onboarding & Offboarding Checklist

Dokumen ini menjelaskan modul **Onboarding/Offboarding Tasks** yang
ditambahkan di iterasi roadmap fase 2 item #7.

## Latar Belakang

Setiap perusahaan punya prosedur formal saat menerima karyawan baru
(tanda tangan kontrak, BPJS, setup laptop, induksi, training) dan saat
karyawan keluar (kembalikan asset, tutup akses, exit interview, pesangon,
paklaring). Tanpa sistem, prosedur ini sering ter-lupa, terlambat, atau
diserahkan ke orang yang salah.

Modul ini menyediakan:

- **Template task** yang reusable per perusahaan, dengan owner_role +
  due_offset_days di setiap item
- **Auto-generate task instance** per karyawan saat onboarding/offboarding
- **Resolve assignee otomatis** berdasarkan owner_role (HR, IT, SUPERVISOR,
  EMPLOYEE, FINANCE)
- **Notifikasi otomatis** ke pihak yang ditugaskan
- **Progress tracking** dengan kanban-style board untuk monitoring HR

## Apa yang Berubah

### Database

Migration `2024-01-01-000016_CreateTaskChecklists.php`:

- **`task_templates`**: master template per perusahaan
  (`code`, `name`, `type` ONBOARD/OFFBOARD, `description`, `is_default`,
  `is_active`, `tasks` JSON).
- **`employee_tasks`**: instance task per karyawan
  (`task_key`, `task_name`, `description`, `owner_role`,
  `assignee_user_id`, `due_date`, `mandatory`, `sort_order`,
  `status` PENDING/IN_PROGRESS/COMPLETED/SKIPPED/CANCELLED,
  `completed_at`, `completed_by`, `evidence_path`).
- 3 permission baru:
  - `onboarding.view` — lihat task (auto-grant role 1-5)
  - `onboarding.manage` — kelola template + generate task (auto-grant role 1, 2)
  - `onboarding.complete_self` — complete task yang ditugaskan kepadanya (semua role)

Seeder `TaskTemplatesSeeder` menambah 2 template default per perusahaan:

- **ONBOARD_DEFAULT** — 12 task: WELCOME_MEET, CONTRACT_SIGN,
  EMPLOYEE_HANDBOOK, SETUP_LAPTOP, SETUP_HRIS, BPJS_REGISTER, NPWP_VERIFY,
  BANK_ACCOUNT, SUBMIT_DOCS, INDUCTION, TRAINING_ROLE, PROBATION_REVIEW (+90 hari).
- **OFFBOARD_DEFAULT** — 12 task: EXIT_INTERVIEW, RETURN_LAPTOP,
  RETURN_ASSETS, REVOKE_EMAIL, REVOKE_HRIS, PESANGON_CALC, CLEARANCE_FIN,
  BPJS_RESIGN, PAKLARING, TAX_CLEARANCE, KNOWLEDGE_TFR, EMPLOYEE_DEACT.

### Backend

#### Library `OnboardingService` (`Services::onboarding()`)

Method | Output
--- | ---
`applyTemplate($empId, $templateId, $type, $anchorDate)` | Generate `employee_tasks` dari template, idempotent (skip kalau task_key sudah ada)
`resolveAssignee($ownerRole, $employee)` | Petakan role string → user_id konkret
`progress($empId, $type)` | total, completed, mandatory, mandatory_done, overdue, percent
`findOverdue($coId)` | List task PENDING yang lewat tanggal
`notifyAssignee($task)` | Kirim notifikasi ke assignee saat task dibuat

**Owner role yang dikenal**:
- `EMPLOYEE` → user karyawan ybs (`employee.user_id`)
- `SUPERVISOR` → atasan langsung (`employee.supervisor_id`)
- `MANAGER` → manager (`employee.manager_id`)
- `HR` / `HR_ADMIN` → user role 2 di company yang sama
- `FINANCE` → user role 5
- `IT` → user dengan role slug 'it_admin' (fallback ke HR)

#### Controllers

**`TaskTemplateController`** (5 endpoint):
- CRUD `/task-templates` dengan filter `?type=ONBOARD|OFFBOARD`
- Validasi: code/name required, type whitelist, normalisasi `tasks` JSON
- Auto-unset `is_default` lama saat ada template baru di-set default

**`EmployeeTaskController`** (9 endpoint):
- `GET /employee-tasks` — list dengan filter (employee_id, type, status, assigned_to_me, overdue)
- `POST /employee-tasks/generate` — apply template ke karyawan
- `GET /employee-tasks/board?type=ONBOARD` — kanban view: list karyawan
  dengan agregat progress (total/completed/overdue tasks per karyawan)
- `GET /employee-tasks/employee/(:num)?type=` — detail tasks untuk satu karyawan + progress
- `GET/PUT/DELETE /employee-tasks/(:num)` — show/update/soft-delete task
- `POST /employee-tasks/(:num)/complete` — mark COMPLETED (oleh assignee atau HR/manager)
- `POST /employee-tasks` — manual create task ad-hoc

Authorization layered:
- Pegawai biasa tanpa view permission → list hanya yang assigned ke dia
- HR/admin → full akses
- Complete task: assignee atau user dengan `onboarding.manage`
- Update/delete: hanya `onboarding.manage`

Audit log + notifikasi otomatis di setiap aksi.

### Frontend

`frontend/src/views/HR/OnboardingBoard.vue`:

- **Tab switcher**: ONBOARD vs OFFBOARD
- **Kanban grid** per karyawan dengan:
  - Profile mini (nama, NIP, departemen, jabatan)
  - Tanggal join/resign
  - Progress bar berwarna (hijau ≥100%, biru ≥70%, amber ≥40%, oranye <40%)
  - Badge "X terlambat" kalau ada overdue task
  - Badge "Selesai" kalau 100%
- **Generator modal**: pilih karyawan + tipe + template + anchor date.
  Default: ONBOARD pakai `join_date`, OFFBOARD pakai `resign_date`.
- **Detail modal**: tampilkan list task lengkap, progress card (total/done/mandatory/overdue),
  tombol per task untuk Complete (assignee/HR) atau Skip (non-mandatory).
- Highlight task overdue dengan background merah.

Sidebar: Group **HR** → **Onboarding / Offboarding** (visible kalau punya `onboarding.view`).

Router: `/hr/onboarding`.

## Workflow Skenario

### Skenario 1 — HR menerima karyawan baru

1. HR membuat profil karyawan baru di Modul Karyawan dengan `join_date = 2026-06-01`.
2. HR buka **HR → Onboarding / Offboarding**, tab **Onboarding**.
3. Klik **Generate Tasks** → pilih karyawan, type Onboarding, biarkan template & anchor kosong.
4. Sistem:
   - Pakai template **ONBOARD_DEFAULT** (12 task)
   - Anchor = 2026-06-01 (join_date karyawan)
   - WELCOME_MEET due 2026-06-01, BPJS_REGISTER due 2026-06-08,
     PROBATION_REVIEW due 2026-08-30 (+90 hari)
   - HR (role 2) auto-assigned ke task HR. Atasan langsung ke task SUPERVISOR.
     User karyawan ke task EMPLOYEE.
   - Notifikasi otomatis ke setiap assignee
5. Atasan langsung & IT mengerjakan task masing-masing, klik **Selesai** dari menu mereka.
6. Progress board menampilkan progres real-time: 3/12 (25%) hari pertama, 8/12 setelah seminggu, dst.

### Skenario 2 — Karyawan resign

1. HR set `resign_date` di profil karyawan, mis. 2026-12-31.
2. HR buka **Onboarding/Offboarding** tab **Offboarding** → **Generate Tasks**.
3. Sistem pakai template **OFFBOARD_DEFAULT** (12 task), anchor = resign_date.
4. EXIT_INTERVIEW & RETURN_LAPTOP due 2026-12-31; PESANGON_CALC due +7 hari;
   TAX_CLEARANCE due +14 hari.
5. HR koordinasi:
   - PESANGON_CALC link ke modul **Severance** untuk hitung pesangon
   - PAKLARING & TAX_CLEARANCE link ke modul **Payroll Output** untuk Bukti Potong
   - REVOKE_EMAIL ditugaskan ke IT → mereka disable akun email/VPN
6. Setelah semua mandatory selesai, status karyawan otomatis bisa di-set tidak aktif (manual untuk sekarang).

### Skenario 3 — Karyawan complete task sendiri

1. Karyawan login HRIS, lihat notifikasi "Tugas Onboarding baru ditugaskan".
2. Buka **HR → Onboarding/Offboarding** (terbatas hanya yang dia jadi assignee
   karena dia tidak punya `onboarding.view`/`manage`).
3. Lihat list task: SUBMIT_DOCS, BANK_ACCOUNT, EMPLOYEE_HANDBOOK.
4. Setelah upload dokumen, klik tombol Selesai → status COMPLETED.

### Skenario 4 — Auto-detect overdue

`OnboardingService::findOverdue($companyId)` mengembalikan semua task yang
status PENDING dan due_date < hari ini. Bisa dipanggil dari command harian
(reuse `hr:check-expiry`) atau di-show di dashboard. Saat ini board sudah
menampilkan count overdue per karyawan.

## Verifikasi

Smoke test `backend/scripts/test-onboarding-flow.php` lulus 25/25:

```
1. Schema check (4 ✓): tabel + permission + seed templates
2. resolveAssignee: HR, EMPLOYEE, unknown role
3. applyTemplate ONBOARD: 12 task created, due_date dihitung
   (WELCOME_MEET = today, PROBATION_REVIEW = today+90)
4. Idempotent: re-apply → 0 created, 12 skipped
5. progress(): total=12, mandatory=10, percent=0; setelah complete 1 → percent>0
6. applyTemplate OFFBOARD: 12 task created, EXIT_INTERVIEW due = resign date
7. findOverdue() detection works
```

## Migrasi Database & Seeding

```bash
cd backend
php spark migrate
php spark db:seed TaskTemplatesSeeder
```

`task_templates` dapat 2 template per perusahaan; `employee_tasks` kosong
sampai HR generate.

## Integrasi dengan Modul Lain

Modul ini sengaja **tidak** auto-trigger generate task saat:
- Karyawan baru dibuat di EmployeeController (deferred — bisa ditambah di
  iterasi berikutnya supaya HR yang explicit decide kapan generate)
- `resign_date` di-set (deferred)

Alasannya: kasus kontrak HR lebih kompleks dari yang terlihat — kadang
karyawan dibuat di sistem sebelum tanggal join (untuk pre-load), atau HR
ingin generate offboarding sebelum tanggal resign final.

Untuk auto-trigger, tambahkan di `EmployeeController::create`:

```php
if (! empty($insertData['join_date'])) {
    Services::onboarding()->applyTemplate(
        $employeeId, null, 'ONBOARD', $insertData['join_date']
    );
}
```

dan di `EmployeeController::update` saat `resign_date` baru di-set.

## Roadmap Selanjutnya

- Auto-trigger dari EmployeeController (opt-in via setting per company).
- Bulk generate untuk batch hire (mis. 10 fresh graduates sekaligus).
- Reminder otomatis 3 hari sebelum due_date untuk task PENDING.
- Dashboard widget: "Onboarding overdue", "Offboarding pending HR".