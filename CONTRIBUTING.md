# Contributing to Quantara HRIS

> Standar branch, commit, PR, code style, dan review checklist.
> **Audience:** developer baru + reviewer.

---

## Prinsip

1. **Jangan merusak yang sudah jalan.** Suite test wajib hijau di setiap commit yang akan di-merge ke `main`.
2. **Refactor mengikuti perubahan, bukan menggantikan.** Pemecahan god-controller / view dilakukan sambil mengerjakan fitur terkait, bukan PR refactor besar yang risk regresi.
3. **Audit trail wajib jalan.** Setiap mutation di-log lewat `AuditLogger`. Jangan lewati di shortcut. Hash chain di-verify lewat `php spark audit:verify-chain`.
4. **Konsistensi > kreativitas.** Kalau pattern sudah ada di codebase (`BaseModel`, `SimpleMaster.vue`, `useConfirm`, `useApi`), pakai itu. Jangan bikin pattern baru tanpa ADR di `docs/decisions/`.
5. **Multi-tenant aware.** Semua query domain harus pakai `BaseModel::scoped()` atau filter `company_id` manual. Endpoint super admin yang lintas tenant harus ada permission slug khusus.

---

## Branch & Commit Convention

### Branch naming

```
feat/<scope>-<deskripsi-singkat>      # fitur baru
fix/<scope>-<deskripsi-singkat>       # bug fix
chore/<scope>-<deskripsi-singkat>     # tooling, deps, ci
refactor/<scope>-<deskripsi-singkat>  # restructure tanpa ubah behavior
docs/<scope>-<deskripsi-singkat>      # dokumentasi saja
test/<scope>-<deskripsi-singkat>      # tambah test
```

Contoh: `feat/leave-half-day-payroll`, `fix/employee-decrypt-cache-miss`, `chore/mobile-deps-bump`.

Branch dari `main`. Tidak ada long-lived `develop` — kita pakai trunk-based dengan feature flag kalau fitur belum siap.

### Commit message

Gaya yang diterima: imperative mood, baris pertama ≤ 72 karakter. Body opsional pakai bullet untuk daftar perubahan.

Format:

```
<type>(<scope>): <ringkas>

<body opsional, jelaskan kenapa, bukan apa>

<footer opsional: link issue / breaking change>
```

Type yang dipakai: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`, `security`.

Scope contoh: `auth`, `attendance`, `leave`, `payroll`, `mobile`, `frontend`, `backend`, `ci`, `deps`.

Contoh:

```
feat(leave): half-day deduction di payroll period

- Hitung 0.5 deduction untuk leave is_half_day=1
- Update PayrollService::computeAttendanceAdjustment
- Tambah 4 unit test untuk skenario MORNING/AFTERNOON

Refs: #1234, audit C10
```

```
fix(employee): cache miss saat NIK lookup

EmployeeModel::findByNik balikkan null karena nik_hash
tidak di-trim saat insert.

Closes: #1289
```

Hindari commit "WIP", "fix typo", "update". Squash di PR sebelum merge.

---

## Pull Request

### Checklist sebelum buka PR

**Backend:**
- [ ] `composer test` → semua hijau
- [ ] `composer stan` → no errors (level 0)
- [ ] `composer format:check` → tidak ada style violation (atau jalankan `composer format`)
- [ ] Tambah unit test di `backend/tests/Unit/` untuk perubahan business logic

**Frontend:**
- [ ] `npm test` → semua hijau
- [ ] `npm run lint` → no errors
- [ ] `npm run build` → bundle berhasil dibuat (cek size kalau ada warning)

**Mobile (kalau ada perubahan di `mobile/`):**
- [ ] `npm run tsc` → no error
- [ ] `npm run lint` → no errors
- [ ] `npm test` → semua hijau

**Umum:**
- [ ] Update dokumentasi terkait kalau perubahan mengubah behavior public (`docs/`, `README.md`, `CONTRIBUTING.md`)
- [ ] Tambah / update `docs/openapi.yaml` kalau ada endpoint baru / breaking
- [ ] **PR yang touch controller > 500 baris atau view > 600 baris:** sertakan rencana refactor di issue (lihat ADR 0001)
- [ ] **PR yang touch migration:** review oleh ops/DBA sebelum merge

### Template PR

```markdown
## Ringkasan
<apa yang berubah dalam 1-2 kalimat>

## Konteks
<kenapa perubahan ini perlu — link issue/audit/customer feedback>

## Implementasi
- <bullet pertama>
- <bullet kedua>

## Test
- [x] Unit test di `tests/Unit/...` atau `__tests__/...`
- [ ] Integration / feature test (kalau ada)
- [ ] Manual smoke: <skenario yang dicek manual>

## Risk / Migration
<kalau ada DB migration, breaking change, atau perubahan env / secret rotation>

## Screenshot / video
<untuk perubahan UI / mobile>
```

### Review SLA

| Severity                            | First response | Merge target |
|-------------------------------------|----------------|--------------|
| 🔴 Critical (security/prod fix)     | 2 jam          | 24 jam       |
| 🟠 High (feature/bug)               | 1 hari kerja   | 3 hari kerja |
| 🟡 Medium (refactor)                | 2 hari kerja   | 1 minggu     |
| 🟢 Low (docs/typo)                  | 3 hari kerja   | 2 minggu     |

PR Dependabot wajib di-review dalam 7 hari.

### Required reviewer

- Backend service / controller change → minimal 1 backend dev review
- Frontend view / store change → minimal 1 frontend dev review
- Mobile change → minimal 1 mobile dev review (atau full-stack yang familiar React Native)
- Migration DB → wajib review tambahan dari ops/DBA
- Security-sensitive (auth, encryption, audit, idempotency) → wajib security/lead review

### Rules untuk merge

- **Squash merge** untuk feat/fix/refactor (1 commit per PR di main, history bersih).
- **Merge commit** untuk multi-author PR (preserve history kontribusi).
- Jangan force-push setelah ada review approval — tambah commit baru di atas dan flag reviewer untuk re-approve.

---

## Code Style

### Backend (PHP 8.1+, target 8.2)

- Mengikuti `pint.json` (Laravel Pint config). Run: `composer format`.
- PHPStan level 0 saat ini, target naik ke level 4–6 di Sprint C+ (lihat `docs/AUDIT_FINDINGS_2026-05.md` E7). Tambah baseline kalau perlu, jangan tinggal `// @phpstan-ignore` di file baru.
- Type hint wajib di parameter + return type. Generic phpdoc kalau perlu (`@return list<array<string,mixed>>`).
- Controller ≤ 500 baris (target ≤ 300 jangka panjang). Refactor ke service kalau lewat.
- Service / Library ≤ 800 baris. Pisah per aggregate kalau lewat (lihat ADR 0001 section 5).
- Akses DB lewat Model (`App\Models\*`) untuk kode baru. `Database::connect()->table(...)` di legacy code masih sah selama tenant scope manual dijaga, tapi tidak boleh untuk kode baru.

### Frontend (Vue 3 + JS)

- ESLint + Prettier. Run: `npm run lint:fix && npm run format`.
- `<script setup>` dengan Composition API.
- View ≤ 600 baris. Pecah ke komponen + composable kalau lewat.
- Hardcoded string Indonesia → `t('common.add')` (pakai `useI18n` dari `@/locales`).
- Tailwind utility-first. Hindari custom CSS kecuali untuk animasi / edge case.
- State management: Pinia store untuk auth + UI shared state. Composable untuk logic lokal.

### Mobile (React Native + TypeScript)

- ESLint + Prettier (config dari `@react-native/eslint-config`).
- TypeScript strict mode. Run: `npm run tsc` sebelum commit.
- State: Zustand untuk auth, react-query untuk server state. Tidak pakai Redux.
- Storage: Keychain untuk secret (JWT), MMKV untuk preferensi non-rahasia + outbox.
- Networking: pakai `src/api/client.ts` (axios singleton) — **jangan** bikin axios instance baru di feature module.
- Idempotency-Key wajib di POST mutation kritis (clock-in/out, leave create) — auto-injected oleh interceptor.
- Multi-tenant header `X-Company-Id` untuk super admin — di-set di `useAuthStore`.
- String Indonesia → `t('tab.attendance')` (pakai `@/i18n`).

### Test

- **Backend:** `tests/Unit/` untuk pure function. Tambahkan `tests/Feature/` (in-progress, lihat audit E1/E2) untuk lintas-tabel.
- **Frontend:** `__tests__/` co-located dengan composable / component, atau `tests/` di root.
- **Mobile:** `__tests__/` co-located. Gunakan `@testing-library/react-native` untuk render component.
- Test description: `it('returns null when ...')` — bukan `it('test1', ...)`.
- Jangan bikin test yang depend pada urutan eksekusi (gunakan `beforeEach` reset state).
- Setiap bug fix harus disertai regression test yang reproduce bug sebelum perbaikan.

---

## Memulai Pengembangan Lokal

```bash
# Clone & setup
git clone https://github.com/antowidianto/Quantara-HRIS.git
cd Quantara-HRIS

# Spin up stack dev (mysql, redis, mailhog, backend, frontend)
docker compose up -d

# First-time DB setup
docker compose exec backend php spark migrate
docker compose exec backend php spark db:seed InitialSeeder

# Akses:
#   Frontend: http://localhost:5173
#   Backend:  http://localhost:8080
#   MailHog:  http://localhost:8025
```

Default login: `admin@hris.local` / `Admin@12345` (wajib ganti).

Untuk dev tanpa Docker, lihat `SETUP.md`. Untuk multi-environment workflow (local / staging / production), lihat `docs/ENVIRONMENTS.md` dan pakai `scripts/switch-env.ps1` / `scripts/switch-env.sh`.

### Mobile dev cepat

```bash
cd mobile
npm install
cp .env.example .env       # set API_BASE_URL=http://10.0.2.2:8080 untuk Android emulator
npm run android            # atau: npm run ios (perlu macOS + CocoaPods)
```

Lihat `mobile/README.md` untuk konfigurasi native (FCM, biometric, dll) dan `mobile/TROUBLESHOOTING.md` kalau build gagal.

---

## Architecture Decision Records (ADR)

ADR wajib untuk perubahan arsitektural yang berdampak lintas modul atau menetapkan pattern baru. Lokasi: `docs/decisions/NNNN-<slug>.md`.

Pakai ADR kalau:
- Memperkenalkan library baru yang mengubah cara kita bekerja (mis. ganti state management)
- Mengubah pattern data access (mis. introduce Repository pattern di atas Model)
- Menetapkan rule baru untuk seluruh codebase (mis. controller size limit)
- Mengubah deployment topology

Format singkat: Konteks → Keputusan → Konsekuensi → Acceptance test. Contoh: `docs/decisions/0001-architecture-baseline.md`.

---

## Dapatkan Bantuan

- **Bug ditemukan?** Buka issue dengan reproduksi minimal. Untuk bug yang touching audit, sertakan output `php spark audit:verify-chain`.
- **Fitur baru?** Diskusi dulu di issue, jangan langsung PR. Kita atur prioritas di backlog.
- **Mobile build gagal?** Cek `mobile/TROUBLESHOOTING.md` dulu sebelum buka issue.
- **Security incident?** **Jangan** buka issue publik. Email security@example.com (atau channel internal team).

Lihat juga:
- `docs/ARCHITECTURE.md` — overview stack & data flow
- `docs/AUDIT_FINDINGS_2026-05.md` — backlog perbaikan
- `docs/decisions/` — ADR (Architecture Decision Records)
- `docs/DEPLOYMENT.md` — deploy production
- `docs/BACKUP_DR.md` — backup & disaster recovery
- `docs/IMPROVEMENT_CHECKLIST.md` — sprint backlog & progress
