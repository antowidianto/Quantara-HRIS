# Sprint B — Architecture Refactor (Phase 1)

> Eksekusi: 28 Mei 2026  
> Branch: `system-improvement-2`  
> Referensi audit: `docs/AUDIT_FINDINGS_2026-05.md` Section 🅐 (A1, A2, A3, A4, A5)  
> Dokumen ADR: `docs/decisions/0001-architecture-baseline.md`

Sprint B fokus pada **fondasi arsitektur jangka panjang**. Tidak menyentuh fitur baru, tidak break controller existing. Target: bikin pattern yang tim bisa adopsi bertahap di sprint-sprint berikutnya.

## Yang Diimplementasikan

### A1 — Layer Model dengan Multi-Tenant Scope (✅ Done, partial)

Fondasi paling penting Sprint B. Kondisi sebelumnya: 491 panggilan `Database::connect()->table(...)` langsung di Controllers, tanpa abstraksi.

**Komponen:**
- **`App\Models\BaseModel`** — abstract foundation:
  - Auto multi-tenant scope (`scoped()`, `findScoped()`, `createScoped()`, `updateScoped()`, `deleteScoped()`)
  - Auto encrypt/decrypt sensitive fields (`$encryptedFields = ['nik', 'npwp', 'bank_account']`)
  - Soft delete + timestamps default
  - Anti tenant-jump: `updateScoped()` strip `company_id` dari payload
  - Graceful decrypt fallback kalau key rotated / data corrupt

- **5 Model konkret untuk tabel inti:**
  - `CompanyModel` (multiTenant=false, tabel root)
  - `BranchModel`
  - `DepartmentModel`
  - `UserModel` (multiTenant=false, tabel global; helper `findByEmail()`)
  - `EmployeeModel` (full encryption + `findByNumber()` + `findByNik()` via hash lookup)

- **`EmployeeModel::encryptIncoming()`** — extend parent: auto set `nik_hash = sha256(nik)` saat NIK plaintext di-passing. `findByNik($nik)` lookup via hash tanpa harus decrypt seluruh row.

**Pendekatan backward-compatible:**
- Existing 65+ controller tetap pakai `Database::connect()->table(...)` — tidak break
- Model adalah _supplemental layer_ — controller baru/yang di-touch boleh migrate bertahap
- ADR 0001 men-document migration plan + size limits (controller ≤ 500 baris, service ≤ 800 baris)

### Test — `EmployeeModelHashTest.php` (4 tests, +1 skipped)

Pure-function test untuk hash logic NIK:
- Hash deterministik
- Format SHA256 hex 64 chars
- Trim whitespace sebelum hash
- Skip kalau CI4 framework tidak boot di unit context (graceful)

Total backend tests: **758** (sebelumnya 754, semua hijau).

### A4 — Permission Filter Activation (Plan only)

Audit menemukan `PermissionFilter` ada tapi tidak dipasang di Routes. Sprint B men-document migration plan di ADR 0001 Section 4:
- Phase B+1: enumerate semua endpoint write, mapping ke slug permission
- Phase B+2: apply filter ke 30% endpoint paling kritis
- Phase B+3: target 100%

**Tidak di-eksekusi sekarang** karena:
- Risk regresi tinggi tanpa Feature test infrastructure (sprint berikut)
- Beberapa controller sudah punya cek `tenant->hasPermission()` internal — perlu audit per-method untuk hindari double check yang membingungkan
- Slug permission yang ter-seed lewat 4 migrasi (#047, #058, dll) perlu di-cross-check dengan slug yang dirujuk filter

### A3 — Modular Routes (Plan only)

ADR 0001 Section 3 men-document target:
```
app/Config/Routes.php          # main, group `api/v1`, include sub-files
app/Config/Routes/auth.php
app/Config/Routes/master.php
app/Config/Routes/employees.php
... per domain
```

Tidak di-eksekusi karena:
- Routes posisi-sensitif (`/picker` harus di atas `/(:num)`)
- Risk regresi tinggi, butuh integration test memadai
- Lebih efisien dilakukan setelah Sprint B+1 (Feature test bootstrap)

### A2 — Pecah God Controller (Plan only)

`JicController` (1.504 baris), `BonusController` (1.191), `LeaveController` (1.059) tidak di-pecah di sini. ADR 0001 Section 2 set target:
- Sample refactor `JicController` → `JicController` (CRUD ≈300 baris) + `JicWorkflowController` (≈400 baris) di Sprint B+1
- BonusController + LeaveController di Sprint C

### A5 — Service Decomposition (Plan only)

`RecruitmentService` 1.186 baris dan `TerminationService` 1.033 baris di-document di ADR 0001 Section 5 dengan rencana decompose. Sprint B+2 target.

## Verifikasi

```
✅ PHPUnit Unit suite — 758 tests, 1719 assertions (+4 dari Sprint B)
✅ EmployeeModelHashTest — 4 tests pass, 1 skipped (butuh CI4 boot)
⚠ PHPStan — 1 error pre-existing di PulseSurveyService (tidak dari Sprint B)
```

Tidak ada test yang break dari adopsi Model — pattern fully additive.

## File yang Diubah / Ditambah

**Backend Models (NEW):**
- `app/Models/BaseModel.php`
- `app/Models/CompanyModel.php`
- `app/Models/BranchModel.php`
- `app/Models/DepartmentModel.php`
- `app/Models/UserModel.php`
- `app/Models/EmployeeModel.php`

**Tests:**
- `tests/Unit/EmployeeModelHashTest.php` (NEW, 4 tests)

**Docs:**
- `docs/decisions/0001-architecture-baseline.md` (NEW — ADR penting yang tim harus baca)
- `docs/SPRINT_B_ARCHITECTURE.md` (file ini)

**Tidak diubah:**
- Tidak ada controller yang di-touch
- Tidak ada migration baru
- Tidak ada Routes/Filters config yang diubah

## Cara Apply ke Environment Existing

Sprint B **tidak butuh migration** — semua perubahan additive di code.

```bash
git pull
composer install   # tidak ada dep baru
composer ci        # verify tests pass
```

## Yang Belum & Carry-over ke Sprint B+1 / C

| Item | Status | Rencana |
|---|---|---|
| `tests/Feature/` infrastructure (SQLite test DB + base TestCase) | Belum | Sprint B+1 awal |
| Refactor sample `EmployeeController` pakai `EmployeeModel` | Belum | Sprint B+1 — sebagai precedent |
| Apply `permission:` filter di 30% endpoint write | Belum | Sprint B+2 |
| Modularisasi `Routes.php` per domain | Belum | Sprint B+2 (setelah Feature test) |
| Pecah `JicController` (1.504 baris) | Belum | Sprint C |
| Pecah `RecruitmentService` (1.186 baris) | Belum | Sprint C |
| 5 model lanjutan (Position, JobGrade, LeaveRequest, OvertimeRequest, Payslip) | Belum | Sprint B+1 |
| Custom PHPStan rule untuk reject `Database::connect()` di Controllers baru | Belum | Sprint C+ |

## Risk & Rollback

**Risk: minimal.** Semua perubahan additive — tidak ada signature method existing yang berubah, tidak ada migrasi baru, tidak ada Routes baru.

**Rollback plan:** hapus folder `app/Models/` + test baru. Semua Sprint B kode independen dari kode existing.

## Skor Setelah Sprint B (kumulatif Sprint A+B)

| Dimensi | Awal | Sprint A | Sprint B | Target Q4-2026 |
|---|---|---|---|---|
| Arsitektur & Code Quality | 5 / 10 | 5 / 10 | **5.8 / 10** | 8 / 10 |
| Keamanan | 7 / 10 | 7.8 / 10 | 7.8 / 10 | 9 / 10 |
| Testing & QA | 4 / 10 | 4.3 / 10 | **4.5 / 10** | 7.5 / 10 |
| DevOps & Operasional | 5.5 / 10 | 6 / 10 | 6 / 10 | 8 / 10 |
| Dokumentasi | 9 / 10 | 9 / 10 | **9.2 / 10** (ADR baru) | 9 / 10 |

Sprint B adalah _foundation sprint_ — kemajuan skor kecil tapi precedent jelas untuk sprint berikutnya. Setelah Sprint B+1 (Feature test infra + sample controller refactor pakai Model), skor Arsitektur akan naik signifikan karena pattern bisa di-replicate.

## Pesan Penutup

ADR 0001 perlu di-baca dan di-disetujui tim sebelum Sprint B+1 dimulai. Ini bukan dokumen ceremonial — pattern di sini akan jadi referensi PR review untuk semua sprint berikutnya. Kalau ada disagreement (mis. mau Eloquent-style ORM, atau DTO layer formal), lebih baik diskusi sekarang sebelum momentum migrate.

Pesan utama untuk developer: **mulai sekarang, controller baru atau perubahan controller existing yang touching tabel di 5 Model yang sudah dibuat → pakai Model, jangan `Database::connect()` lagi**. Pattern legacy boleh tetap untuk tabel yang belum punya Model, tapi tidak untuk yang sudah ada.