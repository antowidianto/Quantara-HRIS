# Sprint B+1 & D+1 — Architecture Phase 2 + Cache Layer

> **Status:** selesai 28 Mei 2026.
> **Test count:** 838 → **847** (+9 dari LookupCacheTest).
> **Lihat juga:** `docs/SPRINT_B_ARCHITECTURE.md`, `docs/SPRINT_D_PERFORMANCE.md`, `docs/decisions/0001-architecture-baseline.md`.

---

## 1. Sprint B+1 — Models Lengkap (A1)

Lima Model lanjutan ditambahkan menyusul fondasi Sprint B (`BaseModel`, `CompanyModel`, `BranchModel`, `DepartmentModel`, `UserModel`, `EmployeeModel`). Sekarang **10 dari 10** tabel inti sudah punya Model layer.

| File                                       | Tabel        | Catatan                                                                             |
|--------------------------------------------|--------------|-------------------------------------------------------------------------------------|
| `app/Models/PositionModel.php`             | `positions`  | `findByCode()`, `listActive()`, `countEmployees()` untuk delete-confirm UI.         |
| `app/Models/JobGradeModel.php`             | `job_grades` | `checkSalaryBand()` untuk validasi offer letter (in/out band ± min/max).            |
| `app/Models/LeaveRequestModel.php`         | `leaves`     | Override `findScoped` (no soft-delete), `overlappingFor()` anti double-booking.     |
| `app/Models/OvertimeRequestModel.php`      | `overtimes`  | `pendingPayoutInRange()` + `markPaidIn()` idempotent untuk payroll calculate.       |
| `app/Models/PayslipModel.php`              | `payslips`   | `findByPeriodEmployee()`, `sumTakeHomePay()`, `markPaid()` batch.                   |

Semua mengikuti convention `BaseModel`:

- Multi-tenant scope otomatis lewat `scoped()` builder.
- `findScoped()` / `createScoped()` / `updateScoped()` / `deleteScoped()` untuk operasi standar.
- Tabel transaction (leaves, overtimes, payslips) **tidak pakai soft-delete** — history finansial wajib immutable. Override `protected $useSoftDeletes = false` + `protected $deletedField = ''`.

### Bonus fix Sprint B regression

Sprint B sebelumnya menyisakan 2 PHPStan error yang baru ke-detect saat Sprint B+1 jalankan analisa lokal:

- `App\Models\CompanyModel::$multiTenant overriding BaseModel::$multiTenant (bool) should also have native type bool`
- `App\Models\UserModel::$multiTenant overriding BaseModel::$multiTenant (bool) should also have native type bool`

Fix: ubah `protected $multiTenant = false` → `protected bool $multiTenant = false`. PHPStan sekarang `[OK] No errors` untuk seluruh `app/Models`.

### Apa yang masih open

Pemecahan god-controller (`JicController` 1504 baris dst.) masih carry-over ke Sprint C+ — butuh integration test infra dulu (E1/E2). Wiring Model layer ke controller existing juga di-defer karena risk tinggi tanpa test harness. Pattern Model sudah siap dipakai di kode baru / saat refactor incremental.

---

## 2. Sprint D+1 — Cache Layer untuk Lookup Stabil (D4)

### LookupCache library

Wrapper read-through di atas `CacheService` yang sudah punya key builder (`keyUmp`, `keyHolidays`, dll). Setiap method:

- Return langsung dari cache kalau hit (sentinel `__null__` supaya null tetap di-cache, no thundering herd).
- Loader callback query DB dengan tabel + WHERE clause yang sesuai.
- TTL convention per resource:

| Resource              | TTL       | Alasan                                                |
|-----------------------|-----------|-------------------------------------------------------|
| UMP / UMK             | 1 hari    | Update sekali per tahun (Pergub akhir tahun).         |
| TER rates PMK 168     | 1 minggu  | Stabil sepanjang tahun pajak.                         |
| BPJS rates per company| 1 hari    | Jarang diubah (manual via admin UI).                  |
| Holidays per company  | 1 jam     | Bisa berubah mid-year (cuti bersama dadakan).         |
| Role permissions      | 1 jam     | Permission jarang berubah; admin invalidate manual.   |
| Payroll settings      | 15 menit  | Admin kadang test-tweak setting.                      |

Setiap resource punya `forget*()` companion untuk invalidation eksplisit saat admin update master. `forgetAllRolePermissions()` dan `forgetAllUmpForYear()` pakai `deleteMatching()` prefix wildcard untuk bulk clear.

### Service factory

`Services::lookup()` di `app/Config/Services.php` — shared instance default. Sub-resource pattern di controller payroll/leave/attendance (carry-over Sprint D+2):

```php
$lookup = service('lookup');
$rate   = $lookup->getUmp($provinceId, $year);          // cache hit setelah call pertama
$days   = $lookup->getHolidays($companyId, $year);
$perms  = $lookup->getRolePermissions($roleId);
```

### Test coverage

`tests/Unit/LookupCacheTest.php` — 9 test, 23 assertions:

- Read-through pattern (loader hanya 1× per cache key)
- Null caching via sentinel
- Invalidate per-resource forces re-query
- Holidays per-company isolated by key
- Role permissions extract slug list dari JOIN
- Multiple resources punya cache key terpisah (3 query untuk 3 resource, 0 query untuk replay)
- `forgetAllRolePermissions()` clear semua role
- Cache prefix `hris:` tidak nabrak key lain di cache backend

### CircuitBreaker factory di Services

Sebagai sambungan dari Sprint D Phase 1, `Services::circuitBreaker(string $service)` ditambah. Per-service shared instance dengan threshold/cooldown dari env (`circuitBreaker.{service}.threshold`, `circuitBreaker.{service}.cooldown`, default 5/60 detik). Caller di depan WhatsApp/OCR/AI pakai:

```php
$cb = Services::circuitBreaker('whatsapp');
$cb->call(fn() => $client->send($payload));
```

Wiring konkret ke service eksternal masih open — Sprint D+2.

---

## 3. Verifikasi

- `vendor\bin\phpunit --filter LookupCacheTest --no-coverage` → **OK (9 tests, 23 assertions)** dalam 0.051s.
- `vendor\bin\phpunit --no-coverage` (suite penuh) → **OK, 847 tests, 1907 assertions, 1 warning, 1 skipped**.
- `vendor\bin\phpstan analyse app/Models app/Libraries/LookupCache.php` → **[OK] No errors** di level 0.

---

## 4. Skor Per Dimensi (Update)

| Dimensi                       | Sprint D | Sprint B+1/D+1 | Target Q4-2026 |
|-------------------------------|----------|----------------|----------------|
| Arsitektur & Code Quality     | 5 / 10   | **5.5 / 10**   | 8 / 10         |
| Performa & Skalabilitas       | 6.5 / 10 | **7 / 10**     | 8 / 10         |
| Testing & QA                  | 5.2 / 10 | **5.4 / 10**   | 7.5 / 10       |

Sisa dimensi tidak berubah.

---

## 5. Carry-Over

| Item | Catatan |
|------|---------|
| Wiring Model ke controller existing (`EmployeeController`, `LeaveController`, `PayrollService`) | Tunggu integration test infra (E1/E2) supaya refactor bisa diverifikasi. |
| Wiring `LookupCache` ke `BpjsCalculator`, `LeaveBalanceService`, `EmployeeController::index` | Endpoint-level audit dengan EXPLAIN dulu (D1/D2 carry-over Sprint D+2). |
| Wiring `CircuitBreaker` ke `WhatsAppService`/`OcrService`/`AiAssistantService`/`WebhookDispatcher` | Sprint D+2 — sekalian bikin admin dashboard ops untuk snapshot circuit state. |
| Pecah `JicController` (1504 baris) | Sprint C/E — butuh feature test dulu. |
| Modularisasi `Routes.php` (1053 baris) | Sprint C/E — risk regresi tinggi tanpa integration test. |

---

## 6. Catatan Reviewer

- **`LookupCache` constructor menerima `?object $db = null`** (duck-typed) bukan `BaseConnection` strict. Tujuan: gampang di-stub di unit test tanpa boot CI4 framework. Production tetap dapat real `BaseConnection` dari `Config\Database::connect()`.
- **Test stub `LookupCacheTest::buildLookup()`** pakai `stdClass` untuk counter, bukan array. Array kena copy-on-write di property promotion antar nested anonymous class — increment hilang. Pelajaran: kalau perlu shared mutable state antar anonymous class di test, pakai object reference.
- **PHPStan baseline tidak ditambahkan** untuk Model baru — semuanya clean di level 0. Saat Sprint C+ menaikkan ke level 4-6, ekspektasinya sebagian assertion akan butuh phpdoc tambahan (sudah ditulis sebagian di kelas baru).
- **TTL pendek (`TTL_15_MIN`) untuk PayrollSettings** karena admin kadang test-tweak. Kalau di production data ini stabil, naikkan ke `TTL_HOUR` atau `TTL_DAY` untuk hit ratio lebih tinggi — dijaminstabil oleh manual `forgetPayrollSettings()` di controller admin.