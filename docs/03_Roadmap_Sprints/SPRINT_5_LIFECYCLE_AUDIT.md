# Sprint 5 — Lifecycle Audit & Cross-Module Wiring

Audit menyeluruh terhadap ketersambungan modul-modul HRIS dan perbaikan
3 gap material yang ditemukan, sebagai pendamping
[Sprint 5 Onboarding Acceleration](./SPRINT_5_ONBOARDING_ACCEL.md).

## Ringkasan Audit

Saya menelusuri tiap titik transisi state di lifecycle karyawan dan
memverifikasi panggilan cross-module. Hasil:

### ✅ Sudah tersambung (verified by code trace)

| Trigger | Side-effect tersambung |
| --- | --- |
| MPP submit | OrganizationStructureService resolve approver (per role_key) |
| MPP approve final | status APPROVED → siap fulfill |
| TA buat job posting dari MPP | OnboardingPlanService.syncFromMpp (planning + assets) |
| Resignation approve oleh HR | set `employees.resign_date` + reason; ManpowerService.autoCreateForResignation; OnboardingService.applyTemplate(OFFBOARD); webhook dispatch |
| Resignation cancel oleh HR | revert resign_date kalau APPROVED sebelumnya |
| Candidate hire | RecruitmentService.convertToEmployee → ProbationService.applyDefaults, OnboardingPlanService.applyPlanningToEmployee, seedInitialLeaveBalances, preAllocateAssetsForEmployee, OnboardingService.applyTemplate(ONBOARD), autoGenerateContract |
| Hire HIRED | ManpowerService.syncForJobPosting → MPP.hired_count++ + auto-FILLED |
| Probation PASSED | status update + notif karyawan |
| Probation EXTENDED | end_date diperpanjang (≤3 bulan UU 13/2003) |
| Termination execute | autoCreateSeverance, set resign_date, applyTemplate(OFFBOARD), notif, audit CRITICAL |
| Offboarding asset clearance | gate paklaring & final settlement |
| Offboarding BPJS deactivate | OnboardingService.completeTaskByKey('BPJS_DEACTIVATE') |
| Offboarding final settlement | link ke payroll periode bulan resign + tracking |
| JIC implement | employees.update + insert employee_contracts amandemen + recompute allowances |
| Performance review finalize | IdpService.bootstrapFromReview |
| MPP create dari resignation approve | autoCreateForResignation (dept/posisi/branch carry-over) |
| BPJS register/deactivate overdue | CheckBpjsCompliance cron escalate ke HR Director |
| Contract expiry H-30 | CheckContractExpiry cron notif HR + manager |
| Probation deadline expired tanpa keputusan | autoPassExpired (UU 13/2003 Pasal 60) |
| Anniversary | CheckAnniversary cron |

### ⚠️ Gap yang ditemukan & sudah di-fix

#### GAP A: Karyawan resign tetap aktif setelah resign_date

**Masalah.** `ResignationController::approve()` dan
`TerminationService::execute()` set `employees.resign_date = X` tapi
`is_active` tetap 1, dan `users.is_active` tidak disentuh. Tidak ada
cron yang melakukan transisi otomatis. Akibatnya:

- Karyawan yang seharusnya sudah keluar tetap muncul di headcount aktif.
- Login user ybs masih bisa lanjut → akses sistem & data sensitif.
- Refresh tokens tidak di-revoke → device karyawan tetap punya akses.
- Payroll periode bulan berikutnya akan ikut menghitung dia.

**Fix.** `backend/app/Commands/DeactivateResigned.php` — cron harian
yang:

1. Cari `employees` dengan `resign_date <= today AND is_active = 1`
2. Set `is_active = 0` di employees & users
3. Revoke semua refresh tokens user (force logout di semua device)
4. Audit per karyawan + digest harian ke HR Manager
5. Idempotent — kalau sudah inactive, skip

**Schedule.**
```
0 5 * * *  cd /path/to/backend && php spark hr:deactivate-resigned >> writable/logs/deactivate.log 2>&1
```

#### GAP B: Probation FAIL tidak trigger severance & offboarding

**Masalah.** `ProbationService::decide('FAILED', ...)` hanya set
`resign_date` + `resign_reason`. Comment di kode bahkan eksplisit
mengatakan "HR wajib lanjut ke modul Severance untuk hitung kompensasi
sesuai PP 35/2021" — tapi tidak ada code yang melakukannya. Sama juga
untuk offboarding tasks.

Akibatnya banyak HR lupa hitung kompensasi probation fail (yang secara
hukum tetap berhak UPMK + sebagian UPH), dan asset/finance/BPJS
clearance tidak ter-track.

**Fix.** Di `ProbationService::decide()`, setelah set status FAILED,
auto-trigger:

1. **Severance DRAFT** — `autoCreateProbationSeverance()` cari reason
   code yang didukung (PROBATION_FAIL → fallback ke FAILED_PROBATION
   atau MUTUAL), hitung via `SeveranceCalculator`, insert sebagai DRAFT
   supaya HR bisa review angka & finalize. Audit CRITICAL.
2. **Offboarding tasks** — `OnboardingService::applyTemplate('OFFBOARD')`
   biar asset clearance, BPJS deactivation, paklaring, dst ter-track.

Best-effort: kegagalan satu sub-step tidak abort, tapi audit log mencatat
(HR bisa lihat & lengkapi manual).

#### GAP C: JIC implement tidak notify atasan baru

**Masalah.** `JicService::notifyImplemented()` hanya kirim notifikasi
ke karyawan ybs. Saat mutasi/promosi mengubah `supervisor_id` /
`manager_id`:

- Atasan baru tidak tahu dia sekarang punya bawahan baru → approval
  cuti/lembur/attendance lambat ditangani.
- Atasan lama tidak tahu dia tidak lagi punya tanggung jawab → handover
  approval pending tertinggal.

**Fix.** Tambah `notifyNewReporters()` di `JicService` yang:

- Bandingkan `old_supervisor_id` vs `new_supervisor_id`,
  `old_manager_id` vs `new_manager_id`.
- Untuk atasan baru: kirim notif "Anda menjadi {Atasan/Manager} untuk X"
  + link ke profil karyawan, marked important.
- Untuk atasan lama: kirim notif "X tidak lagi di reporting line Anda"
  supaya bisa handover approval pending.
- De-dup ketika supervisor=manager untuk hindari notif ganda.
- Best-effort: kegagalan satu pengirim tidak block lainnya.

## Verifikasi

```
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.
............ (580 tests, 1349 assertions, 0 failures, 1 pre-existing warning)
OK, but there were issues!
Tests: 580, Assertions: 1349, Warnings: 1.
```

Semua test existing pass. Warning yang muncul adalah pre-existing
(unrelated dengan changes ini).

## Skema cron sekarang (lengkap)

| Time | Command | Tujuan |
| --- | --- | --- |
| 05:00 daily | `hr:deactivate-resigned` | **NEW** — auto-deactivate karyawan resign |
| 05:30 daily | `hr:check-probation` | Auto-pass probation expired tanpa keputusan |
| 06:00 daily | `hr:apply-jic` | Apply JIC APPROVED yang sudah jatuh tempo |
| 06:15 daily | `hr:apply-transfers` | Apply employee transfers |
| 06:30 daily | `hr:check-bpjs` | BPJS register/deactivate SLA overdue |
| 07:00 daily | `hr:check-expiry` | Kontrak/probation/dokumen expiring |
| 07:15 daily | `hr:check-cert-expiry` | Sertifikat training/profesi expiring |
| 07:30 daily | `hr:check-anniversary` | Ulang tahun masa kerja |
| 08:00 daily | `hr:check-offer-expiry` | Offer letter expiring |
| 09:00 daily | `email:process` | Email queue worker |
| 23:00 weekly Sunday | `hr:run-audit` | Audit retention & summary |
| 00:30 yearly 1 Jan | `hr:run-leave-year-end` | Leave carry-over |

## Apa yang masih berstatus "manual" (by design)

Beberapa transisi sengaja dibiarkan manual karena membutuhkan judgment
HR atau dokumen fisik:

- **Severance finalize** — angka dari kalkulator harus di-review HR
  Manager sebelum di-finalize & di-pay.
- **Asset return BAST** — perlu tanda tangan fisik karyawan saat
  serah terima, baru HR klik "Return Asset".
- **BPJS deactivation** — HR submit form di e-Dabu/SiPP eksternal,
  baru klik tombol di HRIS untuk mark `bpjs_deactivated_at`.
- **Paklaring generate** — gate behind asset clearance + final settlement
  paid; HR yang tentukan timing.
- **Final settlement payslip merge** — komponen severance + sisa cuti
  + gaji prorata di-merge manual ke payslip oleh HR (pesan
  manual_action_required di response).

Ini adalah keputusan desain, bukan gap. Akan dipertimbangkan sebagai
roadmap masa depan kalau memang ada permintaan business untuk lebih
otomatisasi.