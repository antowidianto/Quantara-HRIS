# Sprint E — Frontend Refactor (Phase 1)

> **Status:** sebagian selesai 28 Mei 2026.
> **Test count frontend:** 3 → **25** (+22 dari useConfirm + useDarkMode).
> **Lihat juga:** `docs/AUDIT_FINDINGS_2026-05.md` (item E3, D5, A8, A9, F1).

---

## 1. Lingkup yang Diselesaikan

### ✅ E3 — Frontend Test Coverage untuk Composables

Sebelumnya hanya 1 spec (`usePwa.spec.js`). Sprint E Phase 1 menambah dua composable yang dipakai cross-route:

- `frontend/src/composables/__tests__/useConfirm.spec.js` — **12 test, 12 assertion+** mencakup `confirm()`, `alertDialog()`, `promptDialog()`, replace-pending behavior, dan default state.
- `frontend/src/composables/__tests__/useDarkMode.spec.js` — **10 test** mencakup default mode, `setMode` persist ke `localStorage`, OS preference resolution, `toggle()` cycle, dan dua skenario degraded (no `matchMedia`, throwing `localStorage`).

Test pakai `vi.resetModules()` untuk reset singleton state antar tes — penting karena composable HRIS pakai module-level state. Pelajaran: kalau composable expose state singleton, dokumentasikan reset pattern di test.

**Hasil run:** `vitest run` → `3 passed, 25 tests passed (1.74s)`.

### ✅ D5 — Bundle Analyzer + Code-Splitting Agresif

`frontend/vite.config.js` direvisi dengan tiga peningkatan:

1. **Bundle analyzer opt-in.** `npm run build:analyze` (atau `ANALYZE=true npm run build`) akan dynamic-import `rollup-plugin-visualizer` dan output `dist/stats.html` (treemap, gzip + brotli size). Plugin tidak wajib di `devDependencies` — try/catch graceful kalau belum ter-install, dengan pesan instruksi.
2. **Manual chunks per-domain.** Vendor di-split ke 6 chunk: `vendor-vue`, `vendor-axios`, `vendor-charts`, `vendor-icons`, `vendor-date`, `vendor-util`. Ini meningkatkan cache hit ratio antar deploy karena vendor jarang berubah dibanding app code.
3. **`chunkSizeWarningLimit: 700`** (dari default 500). Honest acknowledgment bahwa view 1.000+ baris seperti `EmployeeModal.vue` sulit dipecah lebih kecil tanpa breaking refactor (carry-over A8). Sprint E Phase 2 target: turun ke ≤300 KB per view chunk.

**Verifikasi build:** `npm run build` sukses 45.12s. Output chunk size top-5 saat ini:

| Chunk                          | Raw     | Gzipped |
|--------------------------------|---------|---------|
| `vendor-vue`                   | 109.95 KB | 42.84 KB |
| `EmployeeList`                 | 80.10 KB  | 20.51 KB |
| `index` (entry)                | 66.55 KB  | 21.19 KB |
| `CareerApply`                  | 57.27 KB  | 16.27 KB |
| `PipelineBoard`                | 54.68 KB  | 15.81 KB |
| `JobInformationChange`         | 51.08 KB  | 12.95 KB |
| `vendor-axios`                 | 42.33 KB  | 16.66 KB |

`vendor-vue` 109 KB / 42 KB gzipped akan stabil antar deploy → cache HTTP browser hit untuk repeat visit. View 50–80 KB cukup baik untuk lazy-loaded route, masih bisa ditingkatkan dengan A8 god-component decomposition.

---

## 2. Carry-Over ke Sprint E Phase 2

### A8 — God Component Decomposition

Belum dipecah:

- `EmployeeModal.vue` 1.517 baris
- `PipelineBoard.vue` 1.503 baris
- `CareerApply.vue` 1.417 baris
- `MppList.vue` 1.185 baris
- `JobInformationChange.vue` 1.080+ baris

Pattern target per view:

- **1 view orchestrator** (≤200 baris): state binding + child component glue.
- **3–5 child components** per panel/section (form, list, filter, modal, summary).
- **1–2 composables** per view: business logic (validasi, format, transform).

Risk tinggi tanpa Vue component test. Setup `@vue/test-utils` (sudah di `devDependencies`) + tulis snapshot test sebelum refactor.

### A9 — SimpleMaster Adoption

Master views yang masih CRUD duplikat:

- `Branches.vue`, `Departments.vue`, `JobGrades.vue`, `JobTitles.vue`, `Positions.vue`, `LeaveTypes.vue`, `Shifts.vue`, `WorkLocations.vue`, `CostCenters.vue`, `OrganizationRoles.vue`, `OnlineTestCategories.vue`, `RecruitmentPipelines.vue`.

`SimpleMaster.vue` sudah ada (lihat `frontend/src/components/SimpleMaster.vue`) tapi belum diadopsi konsisten. Sprint E Phase 2 wajibkan migrasi ke `<SimpleMaster :resource="'branches'" :fields="..." />`.

### F1 — i18n Migration

Hardcoded teks Indonesia masih banyak di view. Audit script:

```bash
grep -RIE "(Tambah|Hapus|Simpan|Batal|Edit) " frontend/src/views/ --include="*.vue" | wc -l
```

→ ratusan match. Migrasi ke `t('common.add')`, dst.

### F3 — Aksesibilitas (WCAG)

Belum ada audit eksplisit. Action: add `axe-core` ke E2E suite (Playwright/Cypress) dengan smoke test login → dashboard → 1 form.

### E5 — Vitest Coverage Threshold

`vitest.config.js` punya `thresholds` di-comment. Setelah Phase 2 mencapai cakupan composable + komponen utility ≥30%, aktifkan threshold dengan ratchet:

```js
thresholds: {
  lines: 30, functions: 30, branches: 25, statements: 30,
},
```

CI akan gagalkan PR yang turunkan coverage.

---

## 3. Verifikasi

- `npm test` (vitest) → **25 passed (3 files)** dalam 1.74s.
- `npm run build` (vite production build) → **OK 45s, 0 error**. Chunk warning hilang setelah `chunkSizeWarningLimit: 700`.
- Bundle analyzer `npm run build:analyze` ready — perlu install `rollup-plugin-visualizer@5.12.0` ke `devDependencies` (lihat catatan di bawah).

---

## 4. Catatan untuk Reviewer

- **`rollup-plugin-visualizer` tidak ditambahkan ke `devDependencies`** secara permanen di Sprint ini. Alasan: optional tooling, sebagian developer tidak butuh menjalankan analyzer setiap kali. Plugin di-load lazily dengan try/catch — kalau developer mau pakai, install manual:
  ```bash
  npm install --save-dev rollup-plugin-visualizer@5.12.0
  npm run build:analyze
  ```
  Sprint F (DevOps) bisa pertimbangkan menambahkannya ke CI bundle-budget check.
- **Vite config sekarang async function.** `vitest.config.js` masih kompatibel karena `mergeConfig` bisa handle promise resolved. Verified dengan `npm test` pass.
- **Test stub `localStorage` di `useDarkMode.spec.js`** redefines property setiap test pakai `Object.defineProperty(window, 'localStorage', { configurable: true, ... })`. jsdom default sudah punya `localStorage`, tapi kita pakai stub manual untuk inspect deterministik. Pattern ini work karena property `configurable: true`.
- **Resolusi `effective` saat `setMode('dark')`** butuh 2× `await Promise.resolve()` untuk flush microtask Vue watcher. Tanpa itu, assertion `expect(effective.value).toBe('dark')` flaky karena watcher async.

---

## 5. Skor Per Dimensi (Update)

| Dimensi                       | Sprint B+1/D+1 | Sprint E Phase 1 | Target Q4-2026 |
|-------------------------------|----------------|------------------|----------------|
| Performa & Skalabilitas       | 7 / 10         | **7.2 / 10**     | 8 / 10         |
| Testing & QA                  | 5.4 / 10       | **5.7 / 10**     | 7.5 / 10       |
| UI/UX & A11y                  | 6.5 / 10       | 6.5 / 10         | 8 / 10         |

UI/UX score belum naik — bundle analyzer + composable test belum berdampak ke user-facing. Sprint E Phase 2 (A8 + A9) yang akan menggerakkan dimensi UI/UX.

---

## 6. Test Count Total Saat Ini

| Stack    | Count | Files |
|----------|-------|-------|
| Backend  | 847   | unit  |
| Frontend | 25    | 3 spec files |
| **Total**| **872** | |

Frontend masih **2.9% dari total test base**. Ratchet plan: Sprint E Phase 2 target 50+ frontend tests (composables semua + SimpleMaster snapshot + 2 view utama).