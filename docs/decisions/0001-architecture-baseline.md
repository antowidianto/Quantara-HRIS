# ADR 0001 — Architecture Baseline (Sprint B)

> **Status:** Accepted, partial rollout starting Sprint B  
> **Tanggal:** 28 Mei 2026  
> **Konteks:** Audit `docs/AUDIT_FINDINGS_2026-05.md` Section 🅐 (A1, A2, A3, A4, A5)

## Konteks

Saat audit menyeluruh dilakukan (Mei 2026), HRIS punya:
- **0 folder Models** — semua akses data lewat `Database::connect()->table(...)` langsung
- **491 panggilan `Database::connect()`** hanya di Controllers
- **Top 10 controller 700–1.500 baris** dengan 20+ method action + helper privat
- **Routes monolitik 1.053 baris** dengan konvensi posisi-sensitif
- **Inkonsistensi otorisasi** — `PermissionFilter` ada tapi tidak dipasang di Routes
- **Service layer raksasa** (RecruitmentService 1.186 baris, CareerService 1.061)

Kondisi ini membuat:
- Onboarding developer baru lambat (harus baca controller 1500-line untuk paham 1 endpoint)
- Test coverage stuck di pure-function lib karena business logic di controller butuh DB
- Setiap perubahan kecil punya blast radius besar (tidak ada layer abstraksi)
- Cross-tenant safety tergantung disiplin developer manual cek `company_id` di setiap query

## Keputusan

### 1. Layer Model wajib untuk akses tabel domain

**Mulai Sprint B**, semua tabel domain HRIS harus punya class Model di `App\Models\*`. Controller baru maupun perubahan controller existing harus migrate ke pakai Model — boleh bertahap, tidak harus semua sekaligus.

**Konvensi:**
- Subclass `App\Models\BaseModel` (dibuat di Sprint B)
- `multiTenant = true` default; set `false` hanya untuk tabel global (companies, roles, audit_logs view super admin, dll)
- Field plaintext sensitif (NIK, NPWP, bank_account) di-list di `$encryptedFields` — auto encrypt/decrypt by BaseModel
- Soft delete + timestamps default true
- Helper utama: `findScoped(id)`, `createScoped(data)`, `updateScoped(id, data)`, `deleteScoped(id)`, `scoped()` query builder

**Backward compatibility:**
- Existing controllers yang masih pakai `Database::connect()->table(...)` TIDAK di-paksa migrate
- Boleh run parallel — kedua pattern sah selama tenant scope dijaga manual di legacy code
- Migration ke Model dilakukan bertahap saat ada PR yang touching controller tersebut

**Scope Sprint B (sudah dibuat):**
- `BaseModel`, `CompanyModel`, `BranchModel`, `DepartmentModel`, `UserModel`, `EmployeeModel`

**Carry-over Sprint B+1:**
- `PositionModel`, `JobGradeModel`, `LeaveRequestModel`, `OvertimeRequestModel`, `PayslipModel`

### 2. Controller maximum size 500 baris

Controller > 500 baris **tidak boleh** ada PR baru tanpa rencana refactor di issue tracker. Pattern target:
- Action sederhana (CRUD master) → 1 controller maksimal 300 baris
- Workflow kompleks (JIC, MPP, Recruitment) → 1 controller per stage atau per concern + 1 service
- Business logic di Service/Library, controller hanya handle HTTP concern (validation, response shape, audit log)

**Contoh decompose JicController (saat ini 1.504 baris):**
- `JicController` (≈300 baris) — list, show, create, update, delete (CRUD basic)
- `JicWorkflowController` (≈400 baris) — submit, approve, reject, cancel, consent, implement
- `JicService` (existing 618 baris, tetap) — business logic
- Masih wajar dipisah lebih lanjut kalau approval flow tumbuh

**Sprint B status:**
- Pattern di-establish via Models — refactor masal controllers ditunda ke Sprint B+1 / Sprint C
- 1 sample refactor disarankan untuk JicController di Sprint berikutnya

### 3. Routes modular per domain

`Routes.php` di-split ke files per domain di `app/Config/Routes/*.php` dan di-include dari main file. Pattern:

```
app/Config/Routes.php          # main, group `api/v1`, include sub-files
app/Config/Routes/auth.php     # /auth/*, /me/privacy/*
app/Config/Routes/master.php   # /branches, /departments, /positions, dll
app/Config/Routes/employees.php
app/Config/Routes/payroll.php
app/Config/Routes/leave.php
app/Config/Routes/attendance.php
app/Config/Routes/recruitment.php
app/Config/Routes/admin.php
... dst
```

**Sprint B status:**
- Belum dipecah — risk regresi tinggi tanpa integration test memadai
- Dilakukan setelah Sprint B+1 (tests/Feature/ infrastructure tersedia)

### 4. PermissionFilter wajib aktif untuk endpoint write

**Aturan baku** (mulai Sprint B+1):
- Setiap route `POST/PUT/DELETE` yang memodifikasi data tenant **harus** punya `['filter' => 'permission:xxx.action']`
- Cek manual `$tenant->hasPermission(...)` di controller boleh tetap (defense in depth) tapi tidak menggantikan filter
- Endpoint `GET` (list/show) boleh tanpa filter kalau payload sudah di-scope tenant; tambah filter kalau permission view spesifik (mis. `audit-logs.view`)

**Migration plan:**
- Sprint B audit: enumerate semua endpoint write di Routes, mapping ke slug permission
- Sprint B+1: apply filter ke 30% endpoint paling kritis (employees, payroll, leave, severance, JIC)
- Sprint B+2: target 100% endpoint write

**Sprint B status:**
- Belum apply — perlu integration test dulu agar filter regression cepat ketahuan
- Filter sendiri sudah ada (`PermissionFilter`); tinggal pasang di Routes

### 5. Service-per-aggregate

Service besar (>800 baris) di-pecah per aggregate root atau per concern:

**RecruitmentService 1.186 baris** → split ke:
- `JobPostingService` (manage posting, publish/unpublish)
- `CandidateService` (CRUD, scoring trigger)
- `InterviewService` (scheduling, evaluation)
- `HireFlowService` (hire → create employee → trigger onboarding)

**TerminationService 1.033 baris** → split ke:
- `TerminationApprovalService` (workflow stages)
- `TerminationExecutionService` (execute → severance + paklaring + clearance)

**Sprint B status:** Tidak di-eksekusi — ini perubahan besar, butuh PR per service supaya review tractable. Direncanakan Sprint B+2 / Sprint C.

## Konsekuensi

### Positif
- Cross-tenant safety lebih kuat — `BaseModel::scoped()` tidak bisa di-bypass tanpa effort sadar
- Encryption transparan — controller tidak lagi manage `nik_encrypted` vs `nik` manual
- Test coverage business logic mulai bisa dicapai (mock Model dependency)
- Pattern hijrah jelas — developer baru tahu "jangan tulis controller dengan Database::connect() lagi"
- Routes modular memudahkan PR review per domain

### Negatif
- Existing 65+ controller tidak migrate sekaligus — ada periode dual-pattern (1–3 sprint)
- Developer harus paham 2 pattern selama transisi
- Rules baru (controller ≤ 500 baris, Service ≤ 800 baris) memerlukan disiplin code review

### Tradeoff yang Diterima
- Tidak Eloquent-style ORM full-featured — kita stay di CI4 Model + manual encryption casts. Lebih sederhana, kompatibel dengan codebase existing.
- Tidak DTO/Resource layer formal — output JSON tetap di-shape ad-hoc di controller. Bisa di-introduce di Sprint berikut kalau OpenAPI generator dipasang.
- Filter `permission:` overhead — 1 query DB extra per request (cek role_permissions). Tidak signifikan untuk app B2B internal.

## Acceptance Test

ADR ini dianggap "live" saat:
- [x] `App\Models\BaseModel` ada dan punya unit test untuk hash chain helper
- [x] 5 model konkret untuk tabel inti tersedia
- [ ] `tests/Feature/` infrastructure di-bootstrap dengan SQLite test DB (Sprint B+1)
- [ ] 1 controller existing (target: `EmployeeController` create/show flow) di-refactor pakai `EmployeeModel` sebagai precedent (Sprint B+1)
- [ ] PHPStan custom rule untuk reject `Database::connect()` di Controllers baru (Sprint C)
- [ ] PR review checklist di CONTRIBUTING.md menyebut ADR ini (Sprint G)

## Status checkpoints

| Sprint | Milestone | Status |
|---|---|---|
| Sprint A | Foundation done — sebelum refactor | ✅ |
| Sprint B (this) | BaseModel + 5 model + ADR | ✅ |
| Sprint B+1 | Feature test infra + refactor 1 controller pakai Model | Belum |
| Sprint B+2 | Apply `permission:` filter di 30% endpoint write | Belum |
| Sprint C | Pecah controller terbesar (JicController, BonusController, LeaveController) | Belum |
| Sprint G | Custom PHPStan rule + CONTRIBUTING.md | Belum |

## Referensi

- `docs/AUDIT_FINDINGS_2026-05.md` — audit awal yang men-trigger ADR ini
- `docs/SPRINT_A_HARDENING.md` — Sprint A foundation
- `backend/app/Models/BaseModel.php` — implementasi BaseModel
- CodeIgniter 4 Model docs: https://codeigniter.com/user_guide/models/model.html