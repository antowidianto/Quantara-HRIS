# Audit Lifecycle End-to-End: MPP → Recruitment → Hire → OD → Onboarding → Offboarding

**Tanggal audit:** 26 Mei 2026
**Cakupan:** Seluruh siklus karyawan dari permintaan headcount sampai exit
**Standar referensi:** UU 13/2003 jo UU 6/2023 (Cipta Kerja), PP 35/2021,
PP 36/2021, Permenaker 5/2023 (P3MI), peraturan perpajakan PER-PJ 168/PJ/2023
(TER PPh21), Permenkes 6/2023 (BPJS Kesehatan), PP 44/2015 jo PP 82/2019
(BPJS Ketenagakerjaan), UU PDP 27/2022.

---

## Ringkasan Eksekutif

Sistem **sudah punya fondasi yang kuat** untuk semua fase lifecycle:
- ✅ MPP dengan workflow approval multi-step (Budget vs Unbudget)
- ✅ Career Portal + ATS (Applicant Tracking System) lengkap dengan online test
- ✅ Hire flow yang langsung capture data payroll-essential (PTKP, NPWP, BPJS, rekening bank)
- ✅ Auto-create user account + welcome email + onboarding tasks
- ✅ Resignation request dengan dual approval (Supervisor → HR)
- ✅ Auto-MPP replacement saat resign disetujui
- ✅ Severance calculator sesuai PP 35/2021
- ✅ Disciplinary records (SP1/SP2/SP3) yang feed ke proses PHK
- ✅ Employee Transfer (mutasi) dengan workflow approval terpisah
- ✅ Performance Management (OKR + Review) terintegrasi

Namun ada **gap operasional** yang perlu diperbaiki supaya benar-benar sesuai
realitas HR perusahaan Indonesia. Total **18 gap** ditemukan, dikelompokkan
per fase di bawah ini.

---

## Fase 1: MPP (Manpower Planning) — Permintaan Headcount

**File terkait:**
`backend/app/Libraries/ManpowerService.php`,
`backend/app/Controllers/Api/V1/ManpowerController.php`,
`frontend/src/views/Recruitment/MppList.vue`

### Yang sudah benar
- Dua tipe permintaan: BUDGET (fast-track 1 step approval) dan UNBUDGET (2 step + wajib justifikasi)
- Approval otomatis di-resolve dari `organization_structures` (siapa HRD Manager / Director realtime)
- Replacement vs New Position vs Expansion dibedakan via `reason` enum
- Saat resignation disetujui, MPP DRAFT pengganti auto-dibuat dengan `replacement_for_employee_id`
- Linkage ke `job_postings` lewat tabel pivot — satu MPP bisa di-split ke beberapa lowongan (mis. per cabang)

### Gap yang ditemukan

**[MPP-1] Budget cycle / annual budget tidak dilink ke MPP**
- *Realitas:* HR di Indonesia biasanya budgeting headcount tahunan via RAPKB (Rencana Anggaran Pegawai Karyawan & Bonus)
- *Sekarang:* `request_type=BUDGET` cuma flag, tidak terkait ke periode budget tahunan
- *Dampak:* Tidak ada visibility "MPP ini sudah lewat alokasi budget Q3" atau "headcount tahun berjalan vs realisasi"
- *Rekomendasi:* Tambah tabel `manpower_budget_periods` (tahun, dept, allocated_hc) + auto-link MPP BUDGET ke periode aktif. Reject MPP BUDGET kalau alokasi sudah habis.

**[MPP-2] Tidak ada "approval HR Director untuk over-budget"**
- *Realitas:* Kalau MPP UNBUDGET disetujui, biasanya juga harus revisi RAPKB dengan persetujuan Direksi & Komisaris
- *Sekarang:* Cuma 2 step (HRD_MANAGER → DIRECTOR), tidak ada link ke dokumen revisi anggaran
- *Rekomendasi:* Field opsional `budget_revision_doc_path` di MPP UNBUDGET, di-validate wajib di step DIRECTOR

**[MPP-3] Tidak ada SLA / aging untuk MPP yang menggantung**
- *Realitas:* MPP yang nyangkut di SUBMITTED >2 minggu sering jadi sumber masalah (HRD lupa review, candidate keburu ambil offer lain)
- *Sekarang:* Tidak ada notifikasi reminder atau escalation
- *Rekomendasi:* Cron daily yang kirim reminder ke approver kalau MPP SUBMITTED >7 hari, escalate ke supervisor approver kalau >14 hari

---

## Fase 2: Recruitment (ATS / Pipeline)

**File terkait:**
`backend/app/Libraries/RecruitmentService.php`,
`backend/app/Controllers/Api/V1/RecruitmentController.php`,
`frontend/src/views/Recruitment/PipelineBoard.vue`,
`backend/app/Libraries/CareerService.php`

### Yang sudah benar
- Career Portal publik (slug company) untuk apply tanpa login
- Pipeline Kanban: NEW → SCREENING → ONLINE_TEST → INTERVIEW_HR → INTERVIEW_USER → OFFER → HIRED
- Online test dengan auto-grading (MCQ, TRUE_FALSE, NUMERIC) + DISC questionnaire
- Gate online test sebelum boleh maju ke INTERVIEW_HR/OFFER/HIRED (sudah diimplementasi sprint ini)
- Reject dengan alasan tercatat di `candidate_stages`
- Form hire sekaligus capture data payroll-essential (PTKP, NPWP, BPJS, rekening bank)

### Gap yang ditemukan

**[REC-1] Tidak ada offer letter / offering management**
- *Realitas:* Stage OFFER di banyak perusahaan butuh: terbitkan surat penawaran, kandidat negosiasi, kandidat tanda tangan / tolak, baru baru ke HIRED
- *Sekarang:* OFFER cuma stage UI tanpa dokumen / tracking penawaran gaji
- *Dampak:* Tidak ada catatan "kandidat A ditawari Rp10jt, dia minta Rp12jt, akhirnya deal di Rp11jt", semua di luar sistem
- *Rekomendasi:* Tabel `candidate_offers` (offer_number, salary, allowance_breakdown, joining_bonus, response_deadline, status: SENT/ACCEPTED/REJECTED/EXPIRED, signed_pdf_path). Generate dari template `contract_templates` type=OFFER_LETTER. Auto-progress ke HIRED kalau ACCEPTED.

**[REC-2] Background check / referensi tidak di-track**
- *Realitas:* Untuk posisi staff & atas, HR biasanya call referensi minimal 2 atasan sebelumnya. Untuk posisi finance/IT critical kadang BG check via vendor.
- *Sekarang:* Tidak ada placeholder untuk record ini
- *Rekomendasi:* Tabel `candidate_reference_checks` (referee_name, referee_phone, relation, called_at, called_by, notes, recommendation: STRONG_YES/YES/NEUTRAL/NO/RED_FLAG)

**[REC-3] Medical Check Up (MCU) tidak terintegrasi**
- *Realitas:* MCU wajib pre-employment di hampir semua perusahaan formal. Hasilnya menentukan FIT TO WORK / TEMPORARY UNFIT / UNFIT.
- *Sekarang:* Tidak ada — biasanya HR pakai email + folder Drive
- *Rekomendasi:* Tabel `candidate_mcu_records` (mcu_provider, scheduled_at, completed_at, result: FIT/CONDITIONAL/UNFIT, fit_with_notes, mcu_report_path, expires_at). Hire flow harus check MCU FIT sebelum boleh convert ke employee (kecuali HR override dengan justifikasi).

**[REC-4] Headcount fulfillment tidak block double-hire**
- *Realitas:* Kalau MPP minta 2 orang dan sudah HIRED 2, HR tidak boleh hire kandidat ke-3 dari posting yang sama
- *Sekarang:* `syncHiredCount()` ada tapi tidak di-block di `convertToEmployee` (hanya update status FILLED)
- *Rekomendasi:* Di `RecruitmentService::convertToEmployee()`, sebelum insert employee, recheck total HIRED+converted di posting. Throw error kalau sudah >= headcount kecuali HR punya permission override.

---

## Fase 3: Hire / Join (Convert Candidate → Employee)

**File terkait:**
`backend/app/Libraries/RecruitmentService.php` (`convertToEmployee`),
`backend/app/Libraries/OnboardingService.php` (`autoCompleteFromCandidate`),
`frontend/src/views/Recruitment/PipelineBoard.vue` (hire modal)

### Yang sudah benar
- Auto-generate `employee_number` per company per tahun
- Carry-over field PII dari candidate (NIK, KK, NPWP, BPJS, rekening bank, alamat, pendidikan)
- Encrypt at-rest untuk NPWP & rekening bank
- Auto-create user account dengan must_change_password=1
- Auto-generate kontrak kerja dari template default (PKWT/PKWTT)
- Welcome email berisi credential ke karyawan baru
- 4 onboarding task auto-completed kalau data sudah lengkap dari Career Portal: SUBMIT_DOCS, NPWP_VERIFY, BPJS_REGISTER, SETUP_HRIS
- Update job_posting status FILLED + sync MPP hired_count

### Gap yang ditemukan

**[HIRE-1] Probation period tidak dihitung otomatis**
- *Realitas:* PKWTT wajib probation max 3 bulan (UU 13/2003 Pasal 60). Kalau lewat tanpa keputusan, otomatis jadi karyawan tetap.
- *Sekarang:* Field `probation_end_date` di hire form ada tapi opsional, tidak ada default kalau employment_status=PKWTT
- *Rekomendasi:* Auto-fill probation_end_date = join_date + 3 bulan kalau PKWTT. Tambah `probation_status` (ONGOING/PASSED/FAILED/EXTENDED) yang harus di-update HR sebelum/saat probation_end_date. Cron daily kirim reminder H-14 ke HR + supervisor.

**[HIRE-2] Kontrak PKWT tidak validate pasal Cipta Kerja**
- *Realitas:* PKWT max 5 tahun total (PP 35/2021 Pasal 8), bisa diperpanjang/dibarui dgn jeda. Kalau exceed → otomatis jadi PKWTT.
- *Sekarang:* `contract_end_date` cuma 1 tahun default, tidak ada validasi total durasi
- *Rekomendasi:* Tabel `employee_contracts` sudah ada — tambah service yang sum total durasi PKWT karyawan. Reject perpanjangan kalau > 5 tahun.

**[HIRE-3] Tidak ada "preboarding" period (signed offer → join_date)**
- *Realitas:* Antara accept offer dan first day kerja biasanya 2-4 minggu. Selama itu HR perlu kirim reminder, IT siapkan equipment, supervisor siapkan welcome plan.
- *Sekarang:* Onboarding tasks generated di `convertToEmployee` dengan due_date = join_date + offset, tapi tidak ada task yang bisa due BEFORE join_date
- *Rekomendasi:* Allow `due_offset_days` negatif di template task (mis. SETUP_LAPTOP due H-3, IT_ACCESS_PREP due H-7). Cron kirim reminder ke owner_role saat task due.

**[HIRE-4] BPJS auto-register tidak di-trigger**
- *Realitas:* Karyawan baru wajib didaftarkan ke BPJS Kesehatan & TK dalam 30 hari sejak join (PP 86/2013 + UU SJSN). Telat = denda.
- *Sekarang:* Cuma ada task BPJS_REGISTER dengan auto-complete kalau sudah punya nomor. Tidak ada tracking SLA.
- *Rekomendasi:* Task BPJS_REGISTER yang belum completed di H+25 harus escalate ke HR Manager. Kalau di H+30 belum, masuk audit log severity CRITICAL.

---

## Fase 4: OD (Organizational Development)

**File terkait:**
`backend/app/Controllers/Api/V1/EmployeeTransferController.php`,
`backend/app/Controllers/Api/V1/PerformanceController.php`,
`backend/app/Controllers/Api/V1/TrainingController.php`,
`backend/app/Controllers/Api/V1/JicController.php`

### Yang sudah benar
- Employee Transfer (mutasi) dengan workflow approval — CONSENT_REQUIRED supaya karyawan acknowledge mutasi
- Performance Management dengan cycle (Q/Y/M/Custom), goals (KPI/OKR), check-ins berkala, formal review per cycle
- Training records dengan budget tracking, attendance, certificate
- Job Information Change (perubahan jabatan/gaji/dept dengan approval chain)
- Career Path (lewat positions tier + employee_transfers history)

### Gap yang ditemukan

**[OD-1] Performance review tidak feed ke promotion / salary adjustment**
- *Realitas:* Performance review bagus → biasanya trigger kenaikan gaji tahunan (merit increase) dan/atau promotion. Itu siklus penuh OD.
- *Sekarang:* `performance_reviews.recommendation` & `recommended_increase_percent` ada, tapi tidak generate `job_information_change` atau salary update
- *Rekomendasi:* Tombol "Action: Generate Salary Adjustment" di review FINALIZED yang otomatis create JIC draft dengan increase % yang direkomendasikan + reference ke review_id

**[OD-2] Training tidak link ke performance gap / IDP**
- *Realitas:* Hasil review identifikasi training need → masuk Individual Development Plan (IDP) → enroll di program training → record completion → impact ke review berikutnya
- *Sekarang:* Training & Performance jalan terpisah, tidak ada IDP
- *Rekomendasi:* Tabel `individual_development_plans` (employee_id, cycle_id, gaps[], recommended_trainings[], status). Auto-create dari review.improvements field.

**[OD-3] Succession planning tidak ada**
- *Realitas:* Untuk posisi senior/strategis HR identifikasi 1-3 successor — biasanya hasil 9-box grid talent review
- *Sekarang:* Tidak ada — `9_box_grid` tidak di-track
- *Rekomendasi:* Tabel `succession_plans` (position_id, primary_successor_employee_id, secondary_successor_employee_id, readiness: READY_NOW/1YR/2YR, last_reviewed_at). Optional tahap 2.

**[OD-4] Career path / job grading tidak transparan ke karyawan**
- *Realitas:* Karyawan ingin tahu "kalau saya promosi jadi senior, gaji nya berapa, KPI nya apa, butuh berapa tahun"
- *Sekarang:* `positions.tier` ada tapi tidak ada UI untuk karyawan lihat path-nya
- *Rekomendasi:* Endpoint `/api/v1/employees/me/career-path` yang return position chain (current → next tier → tier+2) plus min/max salary range per tier

---

## Fase 5: Onboarding (Hari Pertama → 90 Hari)

**File terkait:**
`backend/app/Libraries/OnboardingService.php`,
`backend/app/Controllers/Api/V1/EmployeeTaskController.php`,
`frontend/src/views/HR/OnboardingBoard.vue`

### Yang sudah benar
- Template-based task generation (12 task default ONBOARD)
- Owner role mapping (HR/IT/SUPERVISOR/EMPLOYEE/FINANCE)
- Idempotent re-apply (skip task yang sudah ada via task_key)
- Auto-complete 4 task kalau data lengkap dari Career Portal
- Notifikasi assignee saat task ditugaskan
- Progress dashboard per karyawan
- Overdue task tracking

### Gap yang ditemukan

**[ONB-1] Tidak ada checkpoint review (30 / 60 / 90 hari)**
- *Realitas:* Best practice onboarding ada checkpoint formal: 30-day check-in, 60-day review, 90-day evaluation (akhir probation)
- *Sekarang:* Cuma daftar task linier, tidak ada milestone review
- *Rekomendasi:* Tambah type task `MILESTONE` dengan field `milestone_type: 30_DAY/60_DAY/90_DAY/PROBATION_END`. Otomatis generate dari template + link ke probation_status.

**[ONB-2] Buddy / mentor system tidak ada**
- *Realitas:* Banyak perusahaan assign senior employee jadi "buddy" untuk karyawan baru — bantu navigasi sosial & kerja teknis
- *Sekarang:* Tidak ada
- *Rekomendasi:* Field `buddy_employee_id` di employees. Task `INTRODUCE_BUDDY` yang owner=HR, assignee bisa pilih siapa. Setelah set, generate task otomatis untuk buddy: weekly check-in selama 4 minggu pertama.

**[ONB-3] Induksi / orientasi perusahaan tidak structured**
- *Realitas:* Hari pertama karyawan biasanya: tour kantor, intro tim, training tools (HRIS, sistem kerja, safety), brief code of conduct, sign tata tertib
- *Sekarang:* 1 task generic "INDUKSI" tanpa breakdown
- *Rekomendasi:* Sub-tasks: TOUR_OFFICE, MEET_TEAM, TRAINING_TOOLS, READ_HANDBOOK, SIGN_COC, SAFETY_BRIEFING. Bisa di-customize per company.

---

## Fase 6: Offboarding (Resignation → Exit)

**File terkait:**
`backend/app/Controllers/Api/V1/ResignationController.php`,
`backend/app/Controllers/Api/V1/SeveranceController.php`,
`backend/app/Libraries/OnboardingService.php` (template OFFBOARD)

### Yang sudah benar
- Self-service resignation form dengan 30-day notice period validasi (UU 13/2003 Pasal 162)
- Dual approval: Supervisor → HR
- Auto-set `employees.resign_date` saat HR approve
- Auto-generate offboarding tasks dari template
- Auto-create MPP DRAFT untuk pengganti
- Cancel resignation oleh HR auto-revert resign_date
- Severance calculator sesuai PP 35/2021 (uang pesangon, penghargaan masa kerja, UPH)

### Gap yang ditemukan

**[OFF-1] Exit interview tidak digital**
- *Realitas:* Best practice: exit interview structured (turnover root cause, suggestions, would-rehire flag) — penting untuk HR analytics
- *Sekarang:* Tidak ada — biasanya hand-written form
- *Rekomendasi:* Tabel `exit_interviews` (resignation_id, conducted_by, would_rehire, primary_reason, secondary_reasons[], satisfaction_score 1-10, suggestions, notes). Task EXIT_INTERVIEW di OFFBOARD template auto-link ke form ini.

**[OFF-2] Surat keterangan kerja / paklaring tidak auto-generate**
- *Realitas:* Karyawan resign WAJIB dapat paklaring (UU 13/2003 Pasal 153) — sebagai dokumen kerja untuk perusahaan baru. Termasuk durasi, jabatan terakhir, & pernyataan tidak ada kewajiban tersisa.
- *Sekarang:* Tidak ada generator
- *Rekomendasi:* Template baru `contract_templates.type=PAKLARING`. Generate otomatis di hari resign_date dengan substitusi {{full_name}}, {{join_date}}, {{resign_date}}, {{position}}, {{signature_block}}.

**[OFF-3] Final settlement (gaji terakhir + pesangon) tidak link ke payroll**
- *Realitas:* Resign → final settlement = gaji prorata + sisa cuti dikonversi + pesangon (kalau berhak). Harus dibayar paling lambat 7 hari setelah resign_date (UU 13/2003 Pasal 156 ayat 4).
- *Sekarang:* `severance_calculations` ada tapi tidak otomatis include di payroll period berikutnya
- *Rekomendasi:* Saat severance status=PAID, otomatis insert `payroll_entries` di periode aktif dengan tax treatment khusus (severance dipotong PPh21 dengan tarif progressif terpisah, bukan TER bulanan)

**[OFF-4] Asset return tidak block exit clearance**
- *Realitas:* Karyawan tidak boleh dapat paklaring / final settlement kalau aset belum dikembalikan (laptop, ID card, kendaraan). Itu standard exit clearance.
- *Sekarang:* Task RETURN_LAPTOP ada tapi tidak block apa pun
- *Rekomendasi:* Field `assets_returned_clearance` di resignation_requests. Generate paklaring & disburse final settlement TIDAK BISA kalau ada asset yang status masih ASSIGNED ke employee ybs.

**[OFF-5] PHK (terminasi sepihak) workflow tidak ada**
- *Realitas:* Beda dari resignation (voluntary), PHK = pemutusan oleh perusahaan dengan alasan sah (PP 35/2021 Pasal 36-38: efisiensi, bangkrut, force majeure, pelanggaran berat dll). Wajib bipartite/tripartite, surat peringatan SP1-3 (kecuali pelanggaran berat), kalkulasi pesangon dengan multiplier sesuai alasan.
- *Sekarang:* `disciplinary_records` ada (SP1/SP2/SP3/TERMINATION) tapi tidak ada workflow terminasi formal
- *Rekomendasi:* Kontroller baru `TerminationController` yang link disciplinary chain (SP3 last resort) → severance calculation dengan multiplier sesuai PP 35/2021 → PHK letter generation → resign_date set. Beda flag `termination_type: VOLUNTARY/MUTUAL/UNILATERAL/RETIREMENT/CONTRACT_END`.

**[OFF-6] BPJS deactivation tidak ditrack**
- *Realitas:* Saat resign, HR wajib report BPJS Kesehatan & TK dalam 7 hari (e-Dabu untuk Kesehatan, SiPP untuk TK). Telat → karyawan masih dipotong iuran walau sudah keluar.
- *Sekarang:* Tidak ada task di template OFFBOARD default
- *Rekomendasi:* Task `BPJS_DEACTIVATE` mandatory di OFFBOARD, owner=HR, due H+5 dari resign_date.

---

## Cross-Cutting Issues (Berlaku Semua Fase)

**[CC-1] Audit trail UU PDP tidak comprehensive di semua fase**
- *Realitas:* UU 27/2022 PDP wajibkan log akses ke data pribadi (NIK, NPWP, gaji, dst), retention 5 tahun, hak akses subjek data
- *Sekarang:* `AuditLogger` ada tapi tidak semua read PII di-log (mis. lihat detail employee)
- *Rekomendasi:* Add audit log di setiap endpoint yang return PII penuh (employees show, payroll detail, severance detail)

**[CC-2] Notification preferences tidak granular per event lifecycle**
- *Realitas:* Karyawan bisa mau "email saja untuk payroll", "in-app saja untuk MPP approval", "WA untuk approval mendesak"
- *Sekarang:* `notification_preferences` per channel global, bukan per event type
- *Rekomendasi:* Granular per event_type × channel matrix

**[CC-3] Workflow tidak handle "delegation saat liburan / cuti"**
- *Realitas:* Approver yang sedang cuti harus bisa delegasikan otomatis ke alternate (atasan / peer)
- *Sekarang:* `ApprovalDelegationController` ada tapi belum di-wire ke MPP/Resignation/Transfer
- *Rekomendasi:* Wire di semua resolver `expected_approver_user_id` untuk cek delegation aktif sebelum return user_id

**[CC-4] Tidak ada centralized "Employee 360 view"**
- *Realitas:* HR butuh quick view: profile + status PHK aktif + cuti tahun ini + payroll YTD + training + performance + asset assigned + SP aktif + dokumen lengkap
- *Sekarang:* Tersebar di banyak page, harus klik satu-satu
- *Rekomendasi:* Endpoint `/api/v1/employees/{id}/360` yang aggregate semua data + page Vue baru `EmployeeProfile360.vue`

**[CC-5] Tidak ada periodic compliance reports siap cetak**
- *Realitas:* HR butuh laporan: Wajib Lapor Ketenagakerjaan (WLKP) tahunan, laporan BPJS bulanan, daftar PHK setahun, daftar PKWT yang akan habis
- *Sekarang:* Reports terpencar
- *Rekomendasi:* Page baru `Reports/Compliance.vue` dengan template laporan siap export PDF/Excel sesuai format pemerintah

---

## Prioritas Rekomendasi

Saya susun rekomendasi by impact × effort:

### Critical (perlu segera, blocker untuk go-live atau audit)
1. **[OFF-2] Paklaring auto-generate** — wajib UU, sering jadi keluhan ex-karyawan
2. **[OFF-3] Final settlement → payroll** — wajib UU, telat = sengketa hukum
3. **[OFF-4] Asset return clearance** — basic governance, sering jadi loss
4. **[HIRE-4] BPJS auto-register SLA** — wajib UU SJSN, denda finansial
5. **[OFF-6] BPJS deactivate task** — mengurangi keluhan karyawan ex

### High (significant improvement)
6. **[REC-1] Offer letter management** — fix gap besar di stage OFFER
7. **[HIRE-1] Probation auto-tracking** — sering jadi sumber masalah legal
8. **[ONB-1] 30/60/90-day milestones** — best practice modern HR
9. **[OFF-5] PHK formal workflow** — paling kompleks tapi penting
10. **[CC-3] Approval delegation wiring** — sudah ada infrastruktur, tinggal wire

### Medium (nice to have, can wait)
11. **[REC-3] MCU integration** — kalau scope mencakup posisi yang butuh MCU
12. **[OD-1] Review → JIC auto** — closing the loop
13. **[OD-2] IDP** — formalisasi training planning
14. **[OFF-1] Exit interview digital** — analytics improvement
15. **[CC-4] Employee 360 view** — UX improvement

### Low (future enhancement)
16. **[MPP-1] Budget periods linkage**
17. **[OD-3] Succession planning**
18. **[CC-5] Compliance reports**

---

## Saran Pendekatan Implementasi

Daripada implement semua sekaligus (akan mengubah 30+ file & risk regression),
saya sarankan dipecah jadi 4 sprint:

**Sprint 1 — Compliance Critical (1-2 minggu)**
- Paklaring generator + template
- Final settlement → payroll integration
- Asset return clearance gate
- BPJS SLA tracking + escalation

**Sprint 2 — Recruitment Polish (1 minggu)**
- Offer letter management
- Probation auto-tracking
- Milestone tasks (30/60/90 day)

**Sprint 3 — OD Loop (1-2 minggu)**
- Review → JIC auto-generate
- IDP framework
- Approval delegation wiring di semua module

**Sprint 4 — Termination & Polish (1-2 minggu)**
- PHK formal workflow
- Exit interview digital
- Employee 360 view
- Compliance reports

---

## Catatan Teknis

- Semua tabel baru pakai pattern soft-delete + audit timestamps yang sudah ada
- Migration baru harus idempotent (`tableExists` check) seperti migration existing
- Permissions baru harus di-seed di `SyncPermissions` command + grant ke role yang tepat
- Validasi UU tetap di backend (defense in depth) — frontend cuma untuk UX
- Webhook outbound event baru (`employee.probation_passed`, `paklaring.generated`,
  `phk.completed`) buat integrasi sistem eksternal (mis. payroll bank, SiPP)

---

**Disusun oleh:** Cline (AI pair programmer)
**Tanggal:** 26 Mei 2026
**File source utama yang sudah dibaca dalam audit ini:**
- `backend/app/Libraries/ManpowerService.php`
- `backend/app/Libraries/RecruitmentService.php`
- `backend/app/Libraries/OnboardingService.php`
- `backend/app/Controllers/Api/V1/ManpowerController.php`
- `backend/app/Controllers/Api/V1/RecruitmentController.php`
- `backend/app/Controllers/Api/V1/ResignationController.php`
- `backend/app/Controllers/Api/V1/SeveranceController.php`
- `backend/app/Controllers/Api/V1/EmployeeTransferController.php`
- `backend/app/Controllers/Api/V1/PerformanceController.php`
- `backend/app/Controllers/Api/V1/DisciplinaryController.php`
- `backend/app/Database/Migrations/2024-01-01-000016_CreateTaskChecklists.php`