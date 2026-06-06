# HRIS — Roadmap Pengembangan Modul

Dokumen ini adalah **checklist** modul yang direkomendasikan untuk dikembangkan
berikutnya, disusun berdasarkan analisis terhadap struktur backend
(`backend/app/Controllers`, `Migrations`, `Libraries`) dan frontend
(`frontend/src/views`, `router/index.js`, `AppLayout.vue`).

Item dengan tanda `🇮🇩` adalah kebutuhan **wajib compliance Indonesia**.
Setiap item sudah dipecah jadi tugas teknis yang siap di-tracking.

---

## 🎯 Fase 1 — Quick Wins (gap di modul existing)

### 1. Shift & Roster per Branch / Lokasi / Departemen 🇮🇩 ✅ SELESAI
> User mention: "setting shift lokasi setiap branch/employee itu tersendiri belum ada".
> Status: Implementasi end-to-end sudah verified via smoke test. Lihat `docs/SHIFT_AND_ROSTER.md`.

#### Backend
- [x] Migration `2024-01-01-000011_EnhanceShiftAssignmentAndRoster.php`
  - [x] Tambah kolom di `shifts`: `branch_id`, `work_location_id`, `department_id`, `is_default`, `color`, `working_days`, `description`
  - [x] Tabel baru `shift_assignments` (mirror `approval_assignments`)
  - [x] Tabel baru `shift_patterns` (cycle_days + pattern_data JSON)
  - [x] Tabel baru `shift_pattern_assignments` dengan anchor_date + rentang aktif
  - [x] Tambah `employees.default_shift_id` + `shift_pattern_id` + `shift_pattern_anchor`
  - [x] Permission baru: `shift.view/manage`, `shift_pattern.view/manage`, `roster.view/manage` (auto-grant role 1 & 2)
- [x] Library `ShiftResolver` — pilih shift dengan prioritas: schedule → employee.default → pattern → assignment → shift_default → fallback
- [x] `ShiftController` — extend dengan field branch/work_location/department/working_days/color/is_default + filter scope
- [x] Controller baru `ShiftAssignmentController` (CRUD + endpoint `preview` untuk debug resolver)
- [x] Controller baru `ShiftPatternController` (CRUD pattern + sub-CRUD pattern assignments)
- [x] Controller baru `RosterController`:
  - [x] `POST /roster/generate` — apply pattern dgn dry_run, overwrite, scope flexible
  - [x] `GET /roster` — matrix Employee×Date dengan filter branch/dept/wl/q
  - [x] `POST /roster/bulk-update` — multi-cell upsert dengan audit
  - [x] `POST /roster/clear` — bersihkan rentang
- [x] `AttendanceController::clockIn` — pakai `ShiftResolver` (sudah)
- [x] Audit logging untuk semua aksi roster (modul `ROSTER`)
- [x] Seeder `RosterSeeder.php` — pola `P_5_2_OFFICE`, `P_3SHIFT_PABRIK`, `P_4_2` + shift OFFICE & 3 shift pabrik
- [x] Update `Routes.php` — 28 endpoint baru terdaftar (verified via `php spark routes`)
- [x] Test script `backend/scripts/test-roster-flow.php` — passed (resolver + bulk update + clear)
- [ ] Modul `SHIFT_SWAP` (deferred — opsional, bisa ditambah nanti dgn reuse ApprovalEngine)
- [x] Branch context di JWT filter (resolve `branch_id` dari employees, dipakai authorization scope per-cabang)

#### Frontend
- [x] `Master/Shifts.vue` — extend: input branch, work_location, department, working_days, color, is_default
- [x] View baru `Master/ShiftAssignments.vue` — list rule + tester preview shift built-in
- [x] View baru `Master/ShiftPatterns.vue` — designer pola dgn quick-fill (5-2, 4-2, all-on, all-off)
- [x] View baru `Roster/RosterMatrix.vue` — matrix Employee×Date, klik cell → editor, generate dari pola, clear range
- [x] Tambah link sidebar di `AppLayout.vue` (Master Data + HR group)
- [x] Daftar route di `router/index.js`
- [ ] View `Attendance/ShiftSwapList.vue` (deferred bersama modul SHIFT_SWAP)

#### Dokumentasi
- [x] `docs/SHIFT_AND_ROSTER.md` — penjelasan resolver, prioritas, format pattern_data, semua endpoint, 3 skenario penggunaan, debugging tips

---

### 2. Leave Balance Management 🇮🇩 ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi. Lihat `docs/LEAVE_BALANCE.md`.

#### Backend
- [x] Migration `2024-01-01-000012_EnhanceLeaveBalance.php`
  - [x] `leave_balances` sudah ada (allocated, carried_over, used, remaining, expires_at)
  - [x] Tambah kolom di `leave_types`: `is_carry_over_allowed`, `carry_over_max_days`, `carry_over_expires_in_months`, `is_prorated`
  - [x] Default ANNUAL otomatis: `is_carry_over_allowed=1`, `max_days=6`, `expires_in_months=6` (UU 13/2003)
  - [x] Permission baru: `leave_balance.view/manage`, `leave.team_calendar`
- [x] Library `LeaveBalanceService` — registered as `Services::leaveBalance()`
  - [x] `computeProratedQuota(employee, type, year)` — proporsional dari join_date
  - [x] `ensureBalance(employeeId, typeId, year)` — auto-create idempotent
  - [x] `runYearEndCarryOver(companyId, fromYear, toYear, dryRun)` — carry-over massal
  - [x] `recalculate(employeeId, year)` — refresh saat hire/promote
  - [x] `summary(employeeId, year)` — output siap untuk UI
- [x] Command `php spark hr:leave-yearend` — `--from`, `--to`, `--company`, `--dry-run`. Audit log per-company.
- [x] `LeaveController::balance` — return `carried_over`, `expires_at`, `is_prorated`, `prorate_factor`, `months_worked`. Authorization: pegawai sendiri ATAU `leave_balance.view`/`leave.view`.
- [x] Endpoint baru `GET /leaves/team-calendar?branch_id=&department_id=&start_date=&end_date=` — siapa cuti di rentang, default 30 hari, max 180 hari.
- [x] Update `Routes.php` (team-calendar registered sebelum resource biar tidak ke-trap sebagai id)

#### Frontend
- [x] `frontend/src/views/Leaves/LeaveBalance.vue` — total cards + breakdown per tipe + carry-over expiry warning
- [x] `frontend/src/views/Leaves/TeamCalendar.vue` — swimlane Employee×Date dengan filter cabang/departemen
- [x] Update router + sidebar (Group "Saya" → Saldo Cuti, Group "HR" → Kalender Cuti Tim)
- [x] Update `LeaveForm.vue` — warning bentrok dgn rekan tampil di card form, fetch `/leaves/team-calendar` dengan debounce 350ms saat user ubah rentang tanggal

#### Dokumentasi & Test
- [x] `docs/LEAVE_BALANCE.md` — penjelasan prorate, carry-over, scenario, command, audit
- [x] Smoke test `backend/scripts/test-leave-balance-flow.php` — passed (4 prorate cases, idempotent, carry-over, summary)

---

### 3. Approval Delegation ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 7/7). Lihat `docs/APPROVAL_DELEGATION.md`.

#### Backend
- [x] Migration `2024-01-01-000013_CreateApprovalDelegations.php` — tabel `approval_delegations` (`user_id`, `delegate_to_user_id`, `module`, `date_from`, `date_to`, `reason`, `is_active`)
- [x] 3 permission baru: `delegation.view`, `delegation.manage`, `delegation.manage_self`. Auto-grant ke role 1/2 (semua), 3/4/5 (manage_self).
- [x] `ApprovalEngine` patch:
  - [x] `expandWithDelegates()` — resolveApprovers selalu meng-expand approver dengan delegate aktif
  - [x] `findOriginalForDelegate()` — deteksi pelaku adalah delegate
  - [x] `decide()` — auto-prefix note "[Mendelegasi mewakili X]" dan set `approver_role=DELEGATE_OF_<id>`
- [x] Controller `ApprovalDelegationController` — CRUD lengkap dengan filter (`mine`, `active`, `module`)
- [x] Authorization: pegawai biasa lihat milik sendiri ATAU di mana dia jadi delegate; admin/HR bisa manage milik orang lain.
- [x] Validasi: tolak self-delegation, validasi rentang tanggal, modul whitelist, anti-duplikasi overlap.
- [x] Audit log per aksi (`module=APPROVAL`).
- [x] Update `Routes.php` (5 endpoint baru).

#### Frontend
- [x] `frontend/src/views/Approvals/MyDelegations.vue` — 3 tab (Saya Delegasikan / Mewakili Orang Lain / Semua), modal create-edit, badge status (Aktif / Terjadwal / Non-aktif), tombol toggle on-off.
- [x] Update router + sidebar (Group "Saya" → "Delegasi Approval").
- [x] Badge "🤝 Delegasi" di timeline `Approvals/Inbox.vue` — deteksi `approver_role` prefix `DELEGATE_OF_` dan tampilkan badge indigo + label "bertindak sebagai delegasi"

#### Dokumentasi & Test
- [x] `docs/APPROVAL_DELEGATION.md`
- [x] Smoke test `backend/scripts/test-delegation-flow.php` — 7 OK / 0 FAIL

---

### 4. Payroll Output (Bank Transfer + Pajak + BPJS) 🇮🇩 ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 26/26). Lihat `docs/PAYROLL_OUTPUT.md`.

#### Backend
- [x] Library `PayrollExporter` (`Services::payrollExporter()`) — single library untuk semua format
  - [x] `bankTransfer()` — 8 bank: BCA, MANDIRI, BNI, BRI, CIMB, BSI, PERMATA, DANAMON. CSV semicolon + UTF-8 BOM.
  - [x] `esptPph21()` — CSV untuk e-SPT DJP, kode 1721-VI bulanan / 1721-A1 final.
  - [x] `bpjsKetenagakerjaan()` — CSV iuran JHT/JP/JKK/JKM untuk SiPP.
  - [x] `bpjsKesehatan()` — CSV iuran 5% untuk e-Dabu.
  - [x] `buktiPotongHtml()` — HTML formal 3-section per payslip; controller convert ke PDF lewat Dompdf.
- [x] Endpoint baru di `PayrollPeriodController`:
  - [x] `GET /payroll-periods/(:num)/export-summary` — preflight: jumlah karyawan, total THP, total PPh21, distribusi bank, daftar karyawan dgn data missing.
  - [x] `GET /payroll-periods/(:num)/bank-export?bank=BCA`
  - [x] `GET /payroll-periods/(:num)/pph21-export?final=1`
  - [x] `GET /payroll-periods/(:num)/bpjs-export?type=TK|KES`
- [x] Endpoint baru di `PayslipController`:
  - [x] `GET /payslips/(:num)/bukti-potong?format=pdf|html`
- [x] Validasi: tolak periode DRAFT untuk semua exporter.
- [x] Encoding: UTF-8 BOM agar Excel di Windows happy.
- [x] Sanitasi: NPWP/NIK digit-only, nama bersih dari delimiter.
- [x] Audit log per aksi (modul `PAYROLL`, severity NOTICE).

#### Frontend
- [x] `Payroll/PeriodDetail.vue` — tombol "Export & Setor" → dialog kaya:
  - [x] 4 kartu ringkasan (karyawan, THP, PPh21, mix bank)
  - [x] Warning data missing (rekening/NPWP/BPJS)
  - [x] Section Bank Transfer (8 bank dropdown)
  - [x] Section e-SPT PPh21 (checkbox final 1721-A1)
  - [x] Section BPJS (TK + KES)
  - [x] Helper download via axios (preserve JWT) + parse error JSON dari blob
- [x] `Payroll/PayslipDetail.vue` — tombol "Bukti Potong PPh21" di samping "Download Slip PDF"

#### Dokumentasi & Test
- [x] `docs/PAYROLL_OUTPUT.md` — penjelasan format file, 3 skenario, catatan kompatibilitas bank/DJP
- [x] Smoke test `backend/scripts/test-payroll-export.php` — 26 OK, 0 FAIL

---

## 🎯 Fase 2 — Modul Wajib Compliance Indonesia

### 5. Surat Peringatan (SP) & Disiplin Karyawan 🇮🇩 ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 27/27). Lihat `docs/DISCIPLINARY.md`.

#### Backend
- [x] Migration `2024-01-01-000014_CreateDisciplinaryRecords.php`:
  - [x] Tabel `disciplinary_categories` (master kategori dgn severity & recommended_level)
  - [x] Tabel `disciplinary_records` (level VERBAL/SP1/SP2/SP3/SUSPEND/TERMINATION, reference_number, subject, description, dates, status, acknowledged, letter_html snapshot, audit fields)
  - [x] Extend enum `contract_templates.contract_type` dengan 'SP' & 'PHK'
- [x] 2 permission baru: `disciplinary.view`, `disciplinary.manage`. Auto-grant ke role 1 & 2.
- [x] Library `DisciplinaryService` (`Services::disciplinary()`):
  - [x] `nextReferenceNumber()` — format `<LEVEL>/<COMPANY_CODE>/<YEAR>/<5-digit>`
  - [x] `defaultExpiresAt()` — SP1/2/3 +6 bulan; lain null
  - [x] `suggestNextLevel()` — eskalasi otomatis SP1→SP2→SP3→TERMINATION
  - [x] `shouldSuggestTermination()` — flag PHK eligible saat ada SP3 aktif
  - [x] `renderLetter()` — pakai contract_templates atau fallback build-in
  - [x] `summary()` — timeline lengkap untuk panel di profil
  - [x] `expireOverdue()` — auto-expire SP yg lewat tanggal
- [x] Controller `DisciplinaryController` — CRUD + revoke + acknowledge + letter PDF/HTML + categories CRUD + employee summary
- [x] Notifikasi otomatis ke akun karyawan saat SP diterbitkan
- [x] Audit log per aksi (modul `HR`, severity WARNING/NOTICE)
- [x] Seeder `DisciplinaryCategoriesSeeder` — 15 kategori standar (terlambat, K3, integrity, theft, drug, dsb.)
- [x] Update `Routes.php` — 13 endpoint baru

#### Frontend
- [x] `frontend/src/views/HR/Disciplinary.vue` — list, filter (cari/level/status), pagination
- [x] Badge level berwarna (SP1 kuning → PHK merah)
- [x] Badge status (Draft/Aktif/Berakhir/Dibatalkan) + indikator acknowledged
- [x] Modal create-edit dgn auto-set expires_at saat level berubah
- [x] Modal revoke dgn validasi alasan ≥ 10 karakter
- [x] Tombol acknowledge untuk karyawan ybs
- [x] Tombol download surat PDF
- [x] Toast warning "memenuhi syarat PHK" kalau target sudah ada SP3 aktif
- [x] Update router + sidebar (Group "HR" → "Surat Peringatan")
- [ ] Badge SP aktif di profil karyawan (deferred — bisa pakai `employee-summary` endpoint nanti)

#### Dokumentasi & Test
- [x] `docs/DISCIPLINARY.md`
- [x] Smoke test `backend/scripts/test-disciplinary-flow.php` — 27 OK / 0 FAIL

---

### 6. Severance Calculator (Pesangon, UPMK, UPH) 🇮🇩 ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 41/41). Lihat `docs/SEVERANCE.md`.

#### Backend
- [x] Migration `2024-01-01-000015_CreateSeveranceCalculations.php`:
  - [x] Tabel `severance_calculations` dengan snapshot lengkap (multiplier, gaji, masa kerja, semua komponen, total) di-freeze saat finalize
  - [x] FK ke `disciplinary_records` (link PHK akibat SP3)
- [x] 2 permission baru: `severance.view`, `severance.manage`. Auto-grant ke role 1/2.
- [x] Library `SeveranceCalculator` (`Services::severance()`):
  - [x] Tabel pesangon Pasal 40 ayat 2 (1-9 bulan)
  - [x] Tabel UPMK Pasal 40 ayat 3 (0-10 bulan untuk masa kerja ≥ 3 thn)
  - [x] **17 alasan PHK** dengan multiplier sesuai PP 35/2021 (EFFICIENCY 0.5×/1.0×, PENSION 1.75×, DEATH 2.0×, RESIGN UPH-only, dll)
  - [x] UPH: cuti tertunda × upah harian + biaya pulang + hak lain
  - [x] Special case END_OF_PKWT: kompensasi proporsional 1bln/12bln masa kerja
  - [x] `renderBreakdown()` — HTML formal 5-section dengan referensi UU lengkap
- [x] Controller `SeveranceController` — 11 endpoint:
  - [x] `GET /severance/reasons` — daftar alasan untuk dropdown UI
  - [x] `POST /severance/calculate` — preview tanpa simpan (auto-default gaji/masa kerja/cuti dari profile)
  - [x] CRUD severance_calculations + workflow `finalize` / `mark-paid` / `cancel`
  - [x] `GET /severance/(:num)/breakdown?format=pdf|html` — dokumen PDF formal
- [x] Auto-default: gaji dari `employee.basic_salary + fixed_allowance`, masa kerja dari `join_date`, sisa cuti dari `leave_balances` ANNUAL.
- [x] Audit log per aksi (severity NOTICE untuk hitung, CRITICAL untuk finalize/markPaid).
- [x] Update `Routes.php` (11 endpoint baru).

#### Frontend
- [x] `frontend/src/views/HR/Severance.vue` — list dgn filter, badge status berwarna, tombol per-row (download PDF/finalize/mark-paid/cancel)
- [x] **Modal Calculator 2-kolom** dengan:
  - [x] Form input lengkap (karyawan, alasan, gaji, masa kerja, sisa cuti, biaya pulang, hak lain, uang pisah, catatan)
  - [x] Live preview real-time saat input berubah
  - [x] Indikator multiplier untuk alasan terpilih + warning untuk RESIGN/END_OF_PKWT
  - [x] Auto-fill dari profile karyawan
  - [x] Tombol Simpan sebagai DRAFT
- [x] Update router + sidebar (Group "HR" → "Pesangon / Severance")

#### Dokumentasi & Test
- [x] `docs/SEVERANCE.md` — penjelasan tabel UU, 17 alasan PHK, 5 skenario, integrasi dgn SP & payroll
- [x] Smoke test `backend/scripts/test-severance-flow.php` — 41 OK, 0 FAIL (verifikasi semua tabel + 5 kasus calculate + render HTML + DB workflow)

---

### 7. Onboarding & Offboarding Checklist ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 25/25). Lihat `docs/ONBOARDING.md`.

#### Backend
- [x] Migration `2024-01-01-000016_CreateTaskChecklists.php`:
  - [x] `task_templates` (`code`, `name`, `type` ONBOARD/OFFBOARD, `tasks` JSON dgn key/owner_role/due_offset_days/mandatory)
  - [x] `employee_tasks` (instance dgn assignee_user_id resolved, status PENDING/IN_PROGRESS/COMPLETED/SKIPPED/CANCELLED, evidence_path)
- [x] 3 permission baru: `onboarding.view` (semua role), `onboarding.manage` (HR), `onboarding.complete_self` (semua)
- [x] Library `OnboardingService` (`Services::onboarding()`):
  - [x] `applyTemplate()` — generate task instance dgn due_date dihitung dari anchor + offset, idempotent via task_key
  - [x] `resolveAssignee()` — petakan owner_role (HR/IT/SUPERVISOR/MANAGER/EMPLOYEE/FINANCE) ke user_id konkret
  - [x] `progress()` — total/completed/mandatory/overdue/percent
  - [x] `findOverdue()` — list task PENDING yang lewat tanggal
  - [x] `notifyAssignee()` — notifikasi otomatis ke pihak yang ditugaskan
- [x] Controller `TaskTemplateController` — CRUD master template
- [x] Controller `EmployeeTaskController` — list, generate, board, employee detail, complete, manual create
- [x] Audit log + notifikasi otomatis
- [x] Seeder `TaskTemplatesSeeder` — 2 template default per perusahaan: ONBOARD_DEFAULT (12 task) + OFFBOARD_DEFAULT (12 task)
- [x] Update `Routes.php` — 14 endpoint baru
- [x] Auto-trigger di `EmployeeController::create` — pakai template ONBOARD default perusahaan, anchor `join_date`. HR bisa override via setting `onboarding_auto_apply=0` atau payload `skip_onboarding=1`. Failure di service di-log warning, tidak menggagalkan create.

#### Frontend
- [x] `frontend/src/views/HR/OnboardingBoard.vue` — kanban grid per karyawan dgn:
  - [x] Tab switcher Onboarding/Offboarding
  - [x] Progress bar berwarna gradient (4 level)
  - [x] Badge overdue & selesai
  - [x] Generator modal (pilih karyawan, type, template, anchor)
  - [x] Detail modal dgn list task lengkap, progress card, tombol Complete/Skip
  - [x] Highlight task overdue
- [x] Update router + sidebar (Group "HR" → "Onboarding / Offboarding")
- [ ] Section di `EmployeeModal.vue` — progress bar checklist (deferred — bisa pakai `/employee-tasks/employee/(:num)` endpoint nanti)
- [x] `frontend/src/views/Master/TaskTemplates.vue` — UI editor template 2-kolom: list di kiri, editor di kanan dengan reorder via tombol naik/turun, validasi 6 owner_role, duplikat-as-new, soft delete via confirm dialog

#### Dokumentasi & Test
- [x] `docs/ONBOARDING.md` — penjelasan template format, owner role mapping, 4 skenario, integrasi dengan modul lain
- [x] Smoke test `backend/scripts/test-onboarding-flow.php` — 25 OK / 0 FAIL

---

## 🎯 Fase 3 — Operasional

### 8. Asset / Inventory Management ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 26/26). Lihat `docs/ASSET_INVENTORY.md`.

#### Backend
- [x] Migration `2024-01-01-000017_CreateAssetsAndAssignments.php`:
  - [x] `assets` — asset_number unik, category (LAPTOP/PHONE/VEHICLE/9 kategori), kondisi & status lifecycle, cached pointer ke assignment aktif
  - [x] `asset_assignments` — riwayat dgn condition_when_assigned/returned, damage_note, snapshot BAST HTML (assign + return)
  - [x] Extend enum `contract_templates.contract_type` dengan 'BAST'
- [x] 2 permission baru: `asset.view` (semua role), `asset.manage` (HR/admin)
- [x] Library `AssetService` (`Services::asset()`):
  - [x] `nextAssetNumber()` — format `AST-YEAR-XXXXX` unik
  - [x] `assignToEmployee()` — atomic, validasi AVAILABLE-only, render BAST snapshot, notif karyawan
  - [x] `returnAsset()` — atomic, smart status (DAMAGED→REPAIR, LOST→LOST, lain→AVAILABLE), render BAST balikan
  - [x] `renderBastHtml()` — Berita Acara formal Indonesia (mode ASSIGN/RETURN) dengan 2 pihak + rincian barang
  - [x] `listForEmployee()` — aset yang dipegang karyawan
- [x] Controller `AssetController` — 10 endpoint (CRUD + assign/return/BAST + assignments query)
- [x] Audit log per aksi (modul `ASSET`).
- [x] Update `Routes.php` (10 endpoint baru).

#### Frontend
- [x] `frontend/src/views/HR/Assets.vue` — list + filter cari/kategori/status/karyawan, badge status berwarna 5-tier
- [x] Action per row: Assign / Return / Download BAST PDF / Edit / Hapus
- [x] Form modal CRUD aset dengan auto-generate asset_number
- [x] Assign modal dengan dropdown karyawan, kondisi, expected_return, tujuan
- [x] Return modal dengan dropdown kondisi (NEW/GOOD/FAIR/POOR/DAMAGED/LOST), warning kontekstual untuk DAMAGED/LOST
- [x] Update router + sidebar (Group "HR" → "Aset Perusahaan")
- [ ] Tab di profil karyawan (deferred — endpoint `/asset-assignments/employee/(:num)` siap pakai)

#### Dokumentasi & Test
- [x] `docs/ASSET_INVENTORY.md` — penjelasan lifecycle, 4 skenario, integrasi dgn Onboarding/Severance
- [x] Smoke test `backend/scripts/test-asset-flow.php` — 26 OK / 0 FAIL

---

### 9. Travel / SPPD (Surat Perjalanan Dinas) ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 33/33). Lihat `docs/BUSINESS_TRIP.md`.

#### Backend
- [x] Migration `2024-01-01-000018_CreateBusinessTrips.php`:
  - [x] `business_trips` — header dgn reference_number unik, lifecycle 8-state, advance/actual/settlement, snapshot SPPD
  - [x] `business_trip_expenses` — child line items dgn 8 kategori (TRANSPORT/ACCOMMODATION/MEAL/dll), is_billable flag
- [x] 4 permission baru: `business_trip.view/manage/approve/settle`. Auto-grant role 1 (semua), 2 (view+manage+approve), 3 (view+approve), 4 (view), 5 finance (view+settle).
- [x] Library `BusinessTripService` (`Services::businessTrip()`):
  - [x] `nextReferenceNumber()` — format `SPPD/<CODE>/<YEAR>/<5digit>`
  - [x] `computeDurationDays()` — inklusif start..end
  - [x] `submit()` / `approve()` / `reject()` / `start()` / `markCompleted()` — state machine
  - [x] `recomputeActual()` — sum dari billable line items
  - [x] `settle()` — auto-create reimbursement kalau actual > advance, settlement_amount untuk kelebihan
  - [x] `cancel()` — kapan saja kecuali terminal
  - [x] `renderSppdHtml()` — Surat Perintah Perjalanan Dinas formal Indonesia
- [x] Controller `BusinessTripController` — 17 endpoint (CRUD + workflow + expenses + SPPD PDF)
- [x] Reuse approval chain (modul `BUSINESS_TRIP`) dengan defensive try/catch
- [x] Settlement: actual > advance → row reimbursement otomatis (category BUSINESS_TRIP)
- [x] Audit log per aksi (modul `BUSINESS_TRIP`).
- [x] Update `Routes.php` (17 endpoint baru).

#### Frontend
- [x] `frontend/src/views/Travel/TripList.vue` — list dgn filter cari/status/karyawan/mine, badge status berwarna 8-tier
- [x] Action per row kontekstual sesuai status (Submit/Approve/Reject/Start/Complete/Settle/Cancel/SPPD PDF)
- [x] Form modal ajukan SPPD dgn validasi tanggal
- [x] Detail modal dgn ringkasan advance/actual/settlement + list expenses + tombol tambah/hapus
- [x] Add expense modal dgn kategori, tanggal, amount, checkbox is_billable
- [x] Reject modal dgn validasi alasan ≥ 5 karakter
- [x] Settle confirm dialog dgn preview selisih dan implikasi (reimburse vs kembalikan)
- [x] Update router + sidebar (Group "HR" → "Perjalanan Dinas")

#### Dokumentasi & Test
- [x] `docs/BUSINESS_TRIP.md` — penjelasan workflow, 4 skenario (normal/tombok/kelebihan/non-billable), integrasi dengan reimbursement
- [x] Smoke test `backend/scripts/test-business-trip-flow.php` — 33 OK / 0 FAIL

---

### 10. Lembur → Cuti Pengganti 🇮🇩 ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 22/22). Lihat `docs/OVERTIME_LEAVE_COMP.md`.

#### Backend
- [x] Migration `2024-01-01-000019_OvertimeLeaveCompensation.php`:
  - [x] Tambah kolom `compensation_type`, `leave_hours_credited`, `leave_balance_id`, `leave_conversion_ratio` di `overtimes`
  - [x] Tambah kolom `source` di `leave_types` (STANDARD/OVERTIME_COMP)
  - [x] Auto-seed leave type CPL "Cuti Pengganti Lembur" per perusahaan
  - [x] Auto-seed 3 settings default: `overtime_to_leave_enabled`, `overtime_to_leave_ratio` (default 1.0), `overtime_to_leave_max_days_year` (default 12)
- [x] `OvertimeController::create` — terima field `compensation_type` (CASH/LEAVE), defensive fallback ke CASH kalau company disable LEAVE
- [x] `OvertimeController::approve` — branching CASH (hitung amount PP 35/2021) vs LEAVE (konversi ke quota CPL)
- [x] LEAVE flow: ratio konfigurable, auto-create leave_balances row, cap max_days_year (capped partial atau 422 kalau penuh)
- [x] Notifikasi karyawan kontekstual sesuai mode
- [x] Audit log mencakup mode di description

#### Frontend
- [x] `Overtimes/OvertimeForm.vue` — toggle radio kartu "💵 Dibayar Uang" vs "🌴 Cuti Pengganti"
- [x] Live calculation total jam saat input start/end
- [x] Preview hari cuti yang akan ditambah (rasio 1:1 default, info dynamic)
- [x] Saldo CPL otomatis muncul di `Leaves/LeaveBalance.vue` (tanpa code change karena view sudah loop semua leave_types)

#### Dokumentasi & Test
- [x] `docs/OVERTIME_LEAVE_COMP.md` — penjelasan ratio, cap, 4 skenario, integrasi dgn modul Leave & Payroll
- [x] Smoke test `backend/scripts/test-overtime-leave-comp-flow.php` — 22 OK / 0 FAIL (LEAVE/CASH branching, ratio non-default, cap behavior)

---

## 🎯 Fase 4 — Strategis (modul besar)

### 11. Recruitment / ATS ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 30/30). Lihat `docs/RECRUITMENT.md`.

#### Backend
- [x] Migration `2024-01-01-000020_CreateRecruitmentTables.php` — 4 tabel: `job_postings`, `candidates`, `candidate_stages`, `candidate_documents`
- [x] 3 permission baru: `recruitment.view/manage/hire`. Auto-grant role 1, 2 (semua), 3 manager (view+manage).
- [x] Library `RecruitmentService` (`Services::recruitment()`):
  - [x] `nextJobReferenceNumber()` & `nextEmployeeNumber()` — generator unik
  - [x] `moveStage()` — pindah stage dengan track history (PENDING→PASSED/FAILED), validasi terminal stages
  - [x] `reject()` — REJECTED dengan alasan
  - [x] `convertToEmployee()` — atomic clone profile, link converted_employee_id, trigger ONBOARD_DEFAULT, auto-FILLED job kalau headcount terpenuhi
  - [x] `pipelineStats()` — counter per stage untuk kanban
- [x] Controller `RecruitmentController` — 17 endpoint (CRUD job + workflow open/close + CRUD candidate + move-stage + reject + hire + stats)
- [x] Audit log per aksi (modul `RECRUITMENT`)
- [x] Update `Routes.php` (17 endpoint baru)

#### Frontend
- [x] `frontend/src/views/Recruitment/JobList.vue` — list posting dgn filter, badge status, counter kandidat, action per row (Buka/Tutup/Edit/Hapus), form modal CRUD lengkap dgn salary range & headcount
- [x] `frontend/src/views/Recruitment/PipelineBoard.vue` — Kanban Trello-style 6 kolom (Baru/Screening/Interview HR/Interview User/Offer/Hired), card kandidat dgn rating bintang, detail modal dgn timeline history stages, add candidate modal, reject modal, hire modal dgn auto-trigger onboarding
- [x] Update router + sidebar (Group "HR" → "Recruitment")
- [ ] Document upload form (deferred — schema siap, perlu endpoint POST + multipart)

#### Dokumentasi & Test
- [x] `docs/RECRUITMENT.md` — penjelasan workflow lengkap, 4 skenario (recruit→hire/reject/manager request/auto-onboarding), integrasi dgn modul Onboarding
- [x] Smoke test `backend/scripts/test-recruitment-flow.php` — 30 OK / 0 FAIL (pipeline + convert + reject + onboarding auto-trigger)

### 12. Performance / OKR ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 42/42). Lihat `docs/PERFORMANCE.md`.

#### Backend
- [x] Migration `2024-01-01-000024_CreatePerformanceTables.php` — 4 tabel: `performance_cycles`, `goals`, `goal_check_ins`, `performance_reviews`
- [x] 7 permission baru: `performance.view/manage`, `goal.view/manage/approve`, `review.conduct/view_team`. Auto-grant role 1, 2 (semua), 3 manager (approve+conduct+view_team), 4-5 (view only)
- [x] Library `PerformanceService` (`Services::performance()`):
  - [x] `validateWeights()` — total bobot per (cycle, employee) = 100
  - [x] `recomputeGoalProgress()` — auto current/target × 100 + auto-derive status
  - [x] `submitCheckIn()` — update goal current_value + create check_in row + recompute
  - [x] **`computeOverallScore()`** — weighted average dari goal scores dengan breakdown
  - [x] `scoreToRating()` — RATING_RUBRIC (90+ OUTSTANDING, 80+ EXCEEDS, 65+ MEETS, 50+ NEEDS_IMPROVEMENT, dst)
  - [x] `submitReview()` — orchestrate submit + auto-score + competency blend (70/30) + propagate goal final_score
  - [x] `acknowledgeReview()` + `finalizeReview()`
  - [x] `cycleStats()` — counter goals/reviews + rating distribution
- [x] Controller `PerformanceController` — 22 endpoint (Cycle CRUD + status, Goal CRUD + approve + check-in + validate-weights, Review CRUD + submit/acknowledge/finalize, My)
- [x] Update `Routes.php` — 22 endpoint baru
- [x] Audit log per state transition

#### Frontend
- [x] `frontend/src/views/Performance/PerformanceList.vue` — daftar cycle dengan status badge + tombol manage
- [x] `frontend/src/views/Performance/CycleDetail.vue` — 4 stat cards + 2 tab (Goals/Reviews) + tabel goals dgn progress bar berwarna + 3 modal (Goal create, Check-in, Review create)
- [x] `frontend/src/views/Performance/MyPerformance.vue` — view karyawan: list goals + reviews dengan tombol Check-in (3 traffic-light state)
- [x] `frontend/src/views/Performance/ReviewDetail.vue` — review form dengan input skor 0-100 per goal + live preview overall score (weighted) + form strengths/improvements/development_plan/recommendation + tombol kontekstual (Save Draft/Submit/Acknowledge/Finalize)
- [x] Update router + sidebar (Group "Saya" → "Goal & Review Saya", Group "HR" → "Performance / OKR")

#### Dokumentasi & Test
- [x] `docs/PERFORMANCE.md` — penjelasan 4 tabel + 22 endpoint + 5 skenario (quarterly cycle/OKR alignment/360°/PIP/promotion candidate) + integrasi dgn modul lain
- [x] Smoke test `backend/scripts/test-performance-flow.php` — 42 OK / 0 FAIL (schema, validate-weights, check-in flow, computeOverallScore weighted, scoreToRating semua tier, submit/acknowledge/finalize, cycle stats)

### 13. Training & Sertifikasi ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 33/33). Lihat `docs/TRAINING.md`.

#### Backend
- [x] Migration `2024-01-01-000023_CreateTrainingTables.php` — 4 tabel: `trainings`, `training_registrations`, `certificates`, `training_budgets`
- [x] 6 permission baru: `training.view/manage/register`, `certificate.view/manage`, `training_budget.manage`. Auto-grant role 1, 2 (semua), 3-5 (view + register + cert.view).
- [x] Library `TrainingService` (`Services::training()`):
  - [x] `nextTrainingCode()` & `nextCertNumber()` — generator unik per tahun
  - [x] `approveRegistration()` — validasi kapasitas + reserve budget
  - [x] `completeRegistration()` — set COMPLETED + auto-issue cert kalau lulus passing score
  - [x] `issueCertificate()` — buat row dgn expires_date dari validity_months training
  - [x] `expireOverdueCertificates()` — set EXPIRED untuk cert lewat tanggal
  - [x] `findExpiringCertificates()` — list cert yang akan habis dalam N hari
  - [x] `stats()` — dashboard counter + budget summary (allocated/used/reserved/available)
- [x] Controller `TrainingController` — 24 endpoint (training CRUD + publish + register + approve/reject/complete/cancel + certificates CRUD + expiring + budgets + stats)
- [x] Command `php spark hr:check-cert-expiry` — cron daily auto-expire + alert 60/30/14/7 hari ke karyawan + HR; anti-spam (skip kalau last alert < 7 hari)
- [x] Update `Routes.php` — 24 endpoint baru
- [x] Audit log per aksi (modul `TRAINING`)

#### Frontend
- [x] `frontend/src/views/Training/TrainingList.vue` — landing page dengan 5 stat cards, budget bar (used/reserved), filter, tabel + tombol Publish, modal Create lengkap
- [x] `frontend/src/views/Training/TrainingDetail.vue` — meta lengkap + tabel peserta dgn action approve/reject/complete + modal Add Participant + modal Complete dengan input score & attendance
- [x] `frontend/src/views/Training/CertificateList.vue` — list cert dgn highlight kuning untuk expiring ≤30 hari + label "X hari lagi", filter mine/status, tombol "Akan Habis", modal Upload Sertifikat Eksternal (kategori PROFESSIONAL/LICENSE/dll)
- [x] Update router + sidebar (Group "HR" → "Training" + "Sertifikat")

#### Dokumentasi & Test
- [x] `docs/TRAINING.md` — penjelasan 4 tabel + 24 endpoint + 5 skenario (compliance/external/manual upload/capacity overflow/gagal lulus) + setup cron Linux/Windows
- [x] Smoke test `backend/scripts/test-training-flow.php` — 33 OK / 0 FAIL (schema, generators, lifecycle, capacity cap, auto-issue cert, gagal lulus, expire/expiring, stats, class structure)

---

## 🎯 Fase 5 — Polish & Anti-Cheat

### 14. Anti-Cheat Absensi ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 45/45). Lihat `docs/ANTICHEAT.md`.

#### Backend
- [x] Migration `2024-01-01-000022_AntiCheatAttendance.php`:
  - [x] Extend `attendances` dgn 13 kolom anti-cheat (accuracy, mock_gps flag, device fingerprint, IP, selfie path, anomaly score, flags JSON, review fields)
  - [x] Tabel baru `attendance_devices` — track device per user dgn first_seen/last_seen/usage_count + is_trusted/is_blocked
  - [x] 2 permission baru: `attendance.review_anomaly`, `attendance.manage_devices` (auto-grant role 1, 2)
  - [x] 5 settings per company: require_selfie, max_gps_accuracy_m, block_mock_gps, warn_new_device, high_anomaly_threshold
- [x] Library `AntiCheatService` (`Services::antiCheat()`):
  - [x] `trackDevice()` — register/bump dengan is_new flag
  - [x] **`computeAnomaly()`** — 7 flags scoring system (MOCK_GPS 40, LOW_ACCURACY 15, NEW_DEVICE 10, BLOCKED_DEVICE 60, IP_MISMATCH 10, GEOFENCE_FAR 15, NO_SELFIE 10) capped 100
  - [x] Block decisions: MOCK_GPS + setting=1, BLOCKED_DEVICE, LOW_ACCURACY ekstrim (>3× threshold)
  - [x] `generateServerFingerprint()` — fallback dari user-agent + accept-language
  - [x] `trustDevice()` / `blockDevice()` — HR action
  - [x] `markReviewed()` + `stats()` untuk dashboard
- [x] Patch `AttendanceController::clockIn` — track device + compute anomaly + simpan ke row + block kalau should_block + audit WARNING
- [x] Controller baru `AnomalyController` — 6 endpoint (list, stats, review, devices, trust, block)
- [x] Update `Routes.php` — 6 endpoint baru

#### Frontend
- [x] Endpoint API siap untuk integrasi UI HR review
- [x] Update `Clock.vue` — selfie capture via `navigator.mediaDevices` + canvas, kirim sebagai `photo_data_url` base64. GPS accuracy ditampilkan + dikirim. Heuristik mock-GPS sederhana (koordinat bulat / accuracy 0 atau >10km) + device fingerprint dari user-agent + viewport + timezone + language. Backend `AttendanceController` decode base64 selfie ke `writable/uploads/selfies/<emp>/...` (max 1.5 MB, JPEG/PNG only) dan teruskan sebagai `photo_path` ke pipeline anomaly.
- [x] Vue page `Attendance/Anomalies.vue` — 2 tab (Anomali / Device), 4 stat cards, filter (skor/reviewed/rentang), tabel anomali dengan badge skor berwarna + flags chip, modal review dengan textarea note (min 5 karakter). Tab Device: list dengan trust/block button + alasan blok wajib.
- [ ] Native plugin Capacitor untuk Android mock GPS detection (deferred — perlu mobile build)

#### Dokumentasi & Test
- [x] `docs/ANTICHEAT.md` — penjelasan 7 flags, 5 skenario (jujur/mock GPS block/device baru/combo high risk/HR trust), strategi client-side
- [x] Smoke test `backend/scripts/test-anticheat-flow.php` — 45 OK / 0 FAIL (schema, fingerprint, trackDevice, 6 computeAnomaly scenarios, trust/block, stats)

### 15. Mobile / PWA ✅ SELESAI
> Status: Implementasi end-to-end + build production verified. Lihat `docs/PWA.md`.

#### Frontend (vanilla, tanpa Workbox)
- [x] `frontend/public/manifest.webmanifest` — manifest valid dgn name, theme_color, display=standalone, icons 192/512/maskable, **4 shortcuts** (Clock/Cuti/Slip Gaji/Approval) untuk long-press menu
- [x] `frontend/public/sw.js` — service worker custom (vanilla JS, no deps):
  - **App shell cache** (cache-first) — versioned cache `hris-shell-v1.0.0` untuk auto-invalidate saat deploy
  - **API GET network-first** dengan fallback cache (max 7 hari) + header `x-from-cache` untuk indicate stale
  - **API mutations (POST/PUT/DELETE) offline queue** ke IndexedDB `hris-pending-mutations` — auto-replay saat online via Background Sync API atau message
  - **Web Push handler** — push event + notificationclick (foundation siap, perlu VAPID server-side)
  - skipWaiting + clients.claim untuk SW update smooth
  - 4xx response drop dari queue (mencegah retry infinite untuk validation error)
- [x] `frontend/src/composables/usePwa.js` — composable Vue: `register`, `isOnline`, `updateAvailable`, `applyUpdate`, `canInstall`, `promptInstall`, `flushQueue`, helper `subscribePush/unsubscribePush`
- [x] `frontend/src/components/PwaBanner.vue` — auto-mounted di App.vue:
  - Banner offline kuning di top
  - Banner install emerald di bottom-left (dismissible, save ke localStorage)
  - Banner update biru di bottom-right
  - Toast otomatis saat online/offline + queue flushed
- [x] `frontend/index.html` — meta tag manifest + apple-touch-icon + apple-mobile-web-app-* untuk iOS Safari
- [x] `frontend/src/App.vue` — mount `<PwaBanner />`
- [ ] Generate icon files real (saat ini placeholder + README dgn instruksi)

#### Verifikasi
- [x] `npm run build` sukses — `dist/manifest.webmanifest` (1.86 KB) + `dist/sw.js` (9.46 KB) ter-copy
- [ ] Manual test di Chrome DevTools Application panel + Lighthouse audit (post-deploy)
- [ ] Test install di Android Chrome (post-deploy)

#### Dokumentasi
- [x] `docs/PWA.md` — penjelasan strategi cache, 4 skenario (install/offline clock-in/update/iOS), setup production (icons + HTTPS + optional VAPID)

#### Roadmap selanjutnya
- Generate real icon files dari design asset
- Web Push end-to-end (VAPID + backend endpoint + minishlink/web-push library)
- Periodic Background Sync untuk auto-refresh data

### 16. HR Helpdesk / Ticket ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 39/39). Lihat `docs/HELPDESK.md`.

#### Backend
- [x] Migration `2024-01-01-000021_CreateHelpdeskTickets.php` — 2 tabel: `hr_tickets`, `hr_ticket_comments`
- [x] 4 permission baru: `helpdesk.view/view_all/handle/internal`. Auto-grant role 1, 2 (semua), 3/4/5 (view-only)
- [x] **SLA otomatis** per priority: URGENT 4j / HIGH 24j / MEDIUM 72j / LOW 168j (1 minggu)
- [x] Lifecycle 5-state: OPEN → IN_PROGRESS → ANSWERED → CLOSED → REOPENED dengan auto state machine
- [x] Generate `ticket_number` unik format `TKT-<YEAR>-<5digit>`
- [x] Komentar thread dgn `is_internal` flag — HR diskusi internal tidak terlihat karyawan
- [x] Controller `HelpdeskController` — 11 endpoint (CRUD + assign + status + close + reopen + comments + stats)
- [x] Authorization scope multi-tenant + view_all/handle/internal differentiation
- [x] Notifikasi otomatis: tiket baru → HR; assign → assignee; reply public → pelapor; status change → pelapor
- [x] Audit log per aksi (modul `HELPDESK`)
- [x] Update `Routes.php` — 11 endpoint baru
- [x] CSAT rating saat close (1-5 stars + note)
- [x] Overdue tracking di endpoint stats

#### Frontend
- [x] `frontend/src/views/Helpdesk/TicketList.vue` — list dgn 5 stats cards (Total/OPEN/IN_PROGRESS/CLOSED/Overdue), filter, badge status & priority berwarna, row highlight merah kalau overdue, form modal create dgn 8 kategori
- [x] `frontend/src/views/Helpdesk/TicketDetail.vue` — 2-column layout dgn header card, thread comments (HR + EMPLOYEE + INTERNAL highlight kuning), reply form dgn checkbox internal (HR-only), action panel (Assign/Status/Close/Reopen), modal Close dgn rating CSAT 5-bintang, modal Assign dgn dropdown HR
- [x] Update router + sidebar (Group "HR" → "HR Helpdesk")
- [ ] File attachment upload (deferred — schema siap, perlu endpoint multipart)

#### Dokumentasi & Test
- [x] `docs/HELPDESK.md` — penjelasan 11 endpoint + 4 skenario (slip gaji / urgent / komentar internal / reopen) + integrasi notif/audit
- [x] Smoke test `backend/scripts/test-helpdesk-flow.php` — 39 OK / 0 FAIL (schema, lifecycle, komentar internal/public, SLA, stats, struktur class)

### 17. Anniversary / Birthday Automation ✅ SELESAI
> Status: Implementasi end-to-end terverifikasi (smoke test 17/17). Lihat `docs/ANNIVERSARY.md`.

#### Backend
- [x] Command `php spark hr:check-anniversary` — cron harian dgn flag `--dry-run`, `--target=birthday|anniversary|both`, `--company=N`
- [x] Birthday detection: cek `date_of_birth.m-d` = today.m-d → notif ke karyawan (🎉) + HR (🎂)
- [x] Anniversary detection: cek `join_date.m-d` = today.m-d AND years ≥ 1 → notif ke karyawan + atasan langsung
- [x] **Milestone highlight** (1/3/5/10/15/20/25/30 tahun) — extra notif `anniversary.milestone_hr` ke HR untuk pertimbangkan reward
- [x] 6 tipe notifikasi terdiferensiasi (birthday.self/team, anniversary.self/team/milestone/milestone_hr)
- [x] Audit log per run mencakup jumlah event hari itu
- [x] Controller `AnniversaryController` — endpoint widget `GET /anniversary/upcoming?days=30` + `GET /anniversary/today` dengan calc nextOccurrence + days_until + milestone flag

#### Frontend
- [x] Endpoint `/anniversary/upcoming` siap untuk widget Dashboard
- [x] Widget di `Dashboard.vue` — kartu "Ulang Tahun & Anniversary 30 Hari ke Depan" dengan grid 3-kolom, ikon Cake/Gift, highlight emas untuk milestone, label "Hari ini!" / "Besok" / "X hari lagi"

#### Dokumentasi & Test
- [x] `docs/ANNIVERSARY.md` — penjelasan command + 6 tipe notif + 4 skenario + setup cron Linux/Windows
- [x] Smoke test `backend/scripts/test-anniversary-flow.php` — 17 OK / 0 FAIL (logic, notif, class structure, controller)

#### Roadmap selanjutnya
- Reward template (master hadiah/voucher per milestone)
- Email integration (selain notif in-app)
- Reminder H-7 sebelum milestone untuk persiapan

---

## Catatan Implementasi

- **Reuse infrastruktur**: `ApprovalEngine` (sudah generik), `AuditLogger`, `MasterCrudController`, `SimpleMaster.vue`, `ContractTemplateController` (untuk generate dokumen apa pun).
- **Permission**: setiap modul baru wajib daftarkan slug di migrasinya + auto-grant ke role 1 (super admin) & 2 (HR admin).
- **Multi-tenant**: semua tabel baru wajib `company_id` + scope di controller pakai `$this->tenant()->applyScope($builder, 'x.company_id')`.
- **Dokumentasi**: tiap fase yang selesai → update `docs/IMPROVEMENTS.md` + buat `docs/<MODULE>.md` jika kompleks.

---

## Cara Memilih Mana Dulu

| Kalau prioritas Anda… | Kerjakan |
|---|---|
| HR ops & compliance dasar | Fase 1 (1, 2, 3) → Fase 2 (5, 6) |
| Output payroll yang sudah dipakai accounting | Fase 1 (4) langsung |
| Disuruh user/atasan urgent | Item #1 (Shift per Branch + Roster) — yang Anda sebut tadi |
| Persiapan IPO / audit | Fase 2 (5, 6, 7) lebih dulu |

Ketik nomor item (mis. "kerjakan #1" atau "kerjakan 1.Backend dulu") dan
saya langsung implement.