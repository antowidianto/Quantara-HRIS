# Sprint 1 — Compliance Critical (Selesai)

**Tanggal selesai:** 26 Mei 2026
**Cakupan:** 5 item paling kritis dari `docs/LIFECYCLE_AUDIT_2026.md`
**Hasil:** 493 tes hijau (470 existing + 23 baru), tidak ada regresi

---

## Ringkasan Item yang Dikerjakan

| Item | Deskripsi | Status |
|------|-----------|--------|
| OFF-2 | Paklaring auto-generate (UU 13/2003 Pasal 153) | ✅ Implemented |
| OFF-3 | Final settlement → payroll integration (UU 13/2003 Pasal 156) | ✅ Implemented (manual link mode) |
| OFF-4 | Asset return clearance gate | ✅ Implemented |
| OFF-6 | BPJS deactivate task + SLA H+7 tracking | ✅ Implemented |
| HIRE-4 | BPJS register SLA H+30 + escalation | ✅ Implemented |

---

## Yang Dibuat

### Backend

**Database**
- `backend/app/Database/Migrations/2024-01-01-000051_OffboardingClearanceAndPaklaring.php`
  - Tambah field di `resignation_requests`: `assets_returned_at`,
    `assets_clearance_note`, `paklaring_id`, `paklaring_generated_at`,
    `final_settlement_payslip_id`, `final_settlement_amount`,
    `final_settlement_paid_at`, `bpjs_deactivated_at`
  - Extend ENUM `contract_templates.contract_type` dengan `PAKLARING`
    dan `OFFER_LETTER` (untuk Sprint 2)
  - Tambah 3 permission baru: `offboarding.clearance.view`,
    `offboarding.clearance.manage`, `paklaring.generate`

**Services baru**
- `backend/app/Libraries/PaklaringService.php` — generator surat keterangan kerja
  - Pre-condition gate: status APPROVED, asset clearance OK, resign_date sudah lewat
  - Render dari template (kalau ada) atau fallback ke template default minimal
    (HTML A4 standar Bahasa Indonesia, sesuai isi yang diwajibkan UU 13/2003 Pasal 153)
  - Snapshot HTML disimpan di `generated_contracts` (tidak berubah meski
    template di-edit setelahnya)
  - Idempotent: kalau sudah pernah generate, return existing
  - Auto-generate `contract_number`: `PKLR-<YEAR>-<5digit>`
  - Audit log severity NOTICE + notifikasi karyawan via in-app + email

- `backend/app/Libraries/OffboardingService.php` — orchestrator clearance & final settlement
  - `status($resignationId)` — ringkasan semua clearance + ready flags
  - `setAssetClearance(...)` — toggle dengan validasi: kalau ada aset belum balik
    & tidak ada catatan exception ≥10 char → reject. Auto-clear kalau memang
    tidak ada aset assigned
  - `setBpjsDeactivated(...)` — tandai sudah deactivate via e-Dabu/SiPP +
    auto-complete task `BPJS_DEACTIVATE`
  - `linkFinalSettlement(...)` — hitung gaji prorata + sisa cuti + pesangon,
    link ke payslip di payroll periode aktif. Manual mode (HR adjust setelahnya)
    karena auto-merge ke payslip belum siap di Sprint 1
  - `markFinalSettlementPaid(...)` — record bukti bayar; audit CRITICAL
    kalau lewat 7 hari dari resign_date (UU 13/2003 Pasal 156)
  - `findBpjsRegisterOverdue($companyId)` — scanner H+25/H+30
  - `findBpjsDeactivateOverdue($companyId)` — scanner H+7

**Controller baru**
- `backend/app/Controllers/Api/V1/OffboardingController.php` dengan 8 endpoint:
  - `GET /resignations/{id}/offboarding` — status full
  - `POST /resignations/{id}/offboarding/asset-clearance`
  - `POST /resignations/{id}/offboarding/bpjs-deactivated`
  - `POST /resignations/{id}/offboarding/paklaring`
  - `GET /resignations/{id}/offboarding/paklaring/{generatedId}?format=pdf|html` (IDOR-safe)
  - `POST /resignations/{id}/offboarding/final-settlement`
  - `POST /resignations/{id}/offboarding/final-settlement/paid`
  - `GET /offboarding/bpjs-overdue` — dashboard HR Manager

**Cron command**
- `backend/app/Commands/CheckBpjsCompliance.php` (`hr:check-bpjs`)
  - Scan kedua arah: register (HIRE-4) dan deactivate (OFF-6)
  - Severity: WARNING (mendekati SLA), CRITICAL (sudah lewat)
  - Notifikasi ke HR Manager (role 2) + audit log
  - Email kalau CRITICAL (bypass user preferences)
  - Cron contoh: `30 7 * * * cd /path && php spark hr:check-bpjs`

**Modifikasi**
- `backend/app/Config/Services.php` — register `paklaring()` & `offboarding()`
- `backend/app/Config/Routes.php` — registrasi 8 route offboarding
- `backend/app/Controllers/Api/V1/ResignationController.php` — `show()` sekarang
  include `offboarding` data dalam response (1 round-trip)
- `backend/app/Database/Seeds/TaskTemplatesSeeder.php` — OFFBOARD template
  ditambahkan task: `ASSET_CLEARANCE`, `BPJS_DEACTIVATE`, `FINAL_SETTLEMENT`
  dengan deskripsi referensi ke UU yang relevan

### Tests

- `backend/tests/Unit/OffboardingClearanceTest.php` — 23 unit tests / 44 assertions
  - BPJS register severity (warning H+25, critical H+30)
  - BPJS deactivate severity (warning 1-6 hari, critical 7+ hari)
  - Final settlement late detection (UU Pasal 156)
  - Tenure calculation untuk paklaring
  - Prorata salary calculation
  - Asset clearance gate rules (auto-OK, exception note, whitespace)
  - Ready-for-paklaring & ready-for-final-settlement gates

### Frontend

- `frontend/src/views/HR/ResignationReview.vue`
  - `openReview()` sekarang fetch detail dari `GET /resignations/{id}` untuk
    dapatkan offboarding clearance status
  - 5 action handlers baru: `setAssetClearance`, `markBpjsDeactivated`,
    `generatePaklaring`, `downloadPaklaring`, `linkFinalSettlement`,
    `markFinalSettlementPaid`
  - Section UI baru "Offboarding Compliance" yang muncul untuk resignation
    APPROVED, dengan card per gate:
    - Asset Clearance — daftar aset belum balik + override exception note
    - Finance Clearance — info pinjaman/kasbon outstanding (read-only di Sprint 1)
    - BPJS Deactivation — countdown SLA H+7 + tombol mark done
    - Paklaring — tombol terbitkan + download PDF/HTML
    - Final Settlement — link ke payroll + form mark paid dengan amount,
      proof path, note

---

## Cara Pakai (HR Manual)

### 1. Setelah resignation APPROVED

Karyawan akan masuk masa offboarding. Buka **HR → Resignation Review** klik
detail untuk melihat clearance section.

### 2. Asset clearance

Sebelum karyawan exit:
- Karyawan kembalikan laptop / ID card / kendaraan via modul Asset
- HR cek di card "Asset Clearance" — kalau hijau (✓ Cleared), lanjut
- Kalau ada aset hilang dengan polisi report, HR bisa override dengan
  catatan exception ≥10 karakter (mis. "BAP polisi 2026/123 untuk laptop hilang")

### 3. Generate paklaring

Setelah:
- Status = APPROVED
- Asset clearance OK
- resign_date sudah lewat

Tombol "Terbitkan Paklaring" aktif. Klik → sistem generate dokumen,
karyawan dapat notifikasi + link download. PDF langsung print-ready format A4.

### 4. BPJS deactivation (H+7)

Submit deactivate via:
- e-Dabu (BPJS Kesehatan) — https://edabu.bpjs-kesehatan.go.id
- SiPP (BPJS Ketenagakerjaan) — https://sipp.bpjsketenagakerjaan.go.id

Setelah submit, klik "Tandai BPJS Sudah Di-deactivate" di sistem. Cron harian
`hr:check-bpjs` akan kirim escalation kalau lewat batas.

### 5. Final settlement (H+7)

Hitung gaji prorata + sisa cuti + pesangon (kalau ada severance FINALIZED):
1. Klik "Hitung & Link ke Payroll" — sistem estimasi total amount
2. HR adjust komponen di Payroll module (Sprint 1 belum auto-merge)
3. Setelah dibayar (transfer bank atau payroll period), klik "Tandai Sudah
   Dibayar" + isi amount, proof path, note
4. Compliance: pembayaran > H+7 → audit log severity CRITICAL

---

## Verifikasi

```bash
# Backend tests
cd backend
vendor/bin/phpunit --no-coverage
# 493 tests, 1133 assertions, OK

# Audit call scanner (memastikan tidak ada panggilan AuditLogger keliru)
php scripts/find_bad_audit_calls.php
# Tidak ada panggilan shorthand keliru. Aman.

# Migration baru
php spark migrate
# Run 1 migration: OffboardingClearanceAndPaklaring

# Re-seed task templates (kalau perlu update OFFBOARD dengan BPJS_DEACTIVATE)
php spark db:seed TaskTemplatesSeeder

# Test BPJS SLA scanner manual
php spark hr:check-bpjs --dry-run
```

Setup cron harian (Linux):
```cron
30 7 * * * cd /path/to/backend && php spark hr:check-bpjs >> writable/logs/bpjs.log 2>&1
```

---

## Cakupan Compliance UU yang Dipenuhi

- ✅ **UU 13/2003 Pasal 153** — Surat keterangan kerja (paklaring) untuk
  setiap karyawan yang berhenti — fully automated dari template
- ✅ **UU 13/2003 Pasal 156 ayat 4** — Pembayaran pesangon ≤ 7 hari setelah
  resign_date — tracking + audit CRITICAL kalau telat
- ✅ **UU SJSN 40/2004 + PP 86/2013 Pasal 20** — Pendaftaran BPJS ≤ 30 hari
  sejak join_date — daily scanner + escalation H+25 (warning) & H+30 (critical)
- ✅ **Permenkes 6/2023** — Deaktivasi BPJS Kesehatan ≤ H+7 dari resign —
  tracking dengan SLA enforcement
- ✅ **Perdir BPJS-TK** — Deaktivasi BPJS Ketenagakerjaan ≤ H+7 — sama scanner
- ✅ **UU PDP 27/2022** — Audit log untuk akses dokumen sensitif (paklaring
  download tercatat)

---

## Belum Dikerjakan (Sprint 2-4)

Lihat `docs/LIFECYCLE_AUDIT_2026.md` Section "Prioritas Rekomendasi":
- **Sprint 2** — Recruitment polish (offer letter, probation tracking, milestones)
- **Sprint 3** — OD loop (review→JIC, IDP, approval delegation wiring)
- **Sprint 4** — Termination workflow (PHK formal), exit interview digital,
  Employee 360 view, compliance reports (WLKP)

Item Sprint 1 yang belum sempurna & perlu polishing nanti:
- **OFF-3** — Final settlement saat ini manual link mode. Idealnya otomatis
  insert payroll_entries dengan komponen severance dan PPh21 progressive
  terpisah. Butuh extension di PayrollService (planned Sprint 5).
- **Finance clearance** — saat ini read-only (cek dari `loans` &
  `cash_advances`). Tombol "Tandai Lunas" bisa ditambahkan kalau Finance
  module sudah punya endpoint settle yang clean.