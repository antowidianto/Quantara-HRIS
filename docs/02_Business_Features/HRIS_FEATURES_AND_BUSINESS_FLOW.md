# Quantara HRIS — Daftar Fitur & Business Flow End-to-End

Dokumen ringkas seluruh fitur HRIS, dipetakan ke business flow karyawan
dari **MPP → hire → kerja → exit**, plus modul-modul pendukung yang
berjalan paralel sepanjang lifecycle.

> Dokumen ini bersifat narasi business-flow. Untuk detail teknis per-sprint
> atau per-modul, lihat dokumen `SPRINT_*.md` dan dokumen modul (mis.
> `COST_CENTER.md`, `JIC.md`, `RESIGNATION.md`, `BONUS_ENGINE.md`).

---

## Daftar Modul

### A. Master Data & Konfigurasi

- **Companies** — multi-tenant, fiscal year, timezone, settings JSON.
- **Branches** — geofence (lat/lng + radius m) untuk attendance.
- **Departments** — hirarki parent-child + manager_employee_id.
- **Positions** — per-department, terhubung ke job_grade & tier.
- **Job Grades** — band gaji min/max + level.
- **Work Locations** — granular lokasi kerja (mis. office, gudang, site).
- **Shifts & Shift Patterns** — jadwal kerja, cross-midnight aware.
- **Leave Types** — annual/sick/maternity/paternity/menstrual/marriage/
  bereavement/hajj/unpaid/special, dengan `gender_restriction` &
  `is_carry_over_allowed`.
- **Allowance Types & Rates** — mapping per branch × grade.
- **Payroll Components** — pendapatan, potongan, fixed allowances.
- **Holidays** — nasional & cuti bersama.
- **Approval Chains** — multi-step (per kategori transaksi).
- **Organization Roles** (Master Jabatan Kunci) — role_key → karyawan
  untuk lookup approver dinamis.
- **Task Templates** — ONBOARD/OFFBOARD task list per company.
- **Contract Templates** — variabel template untuk render kontrak PKWT/PKWTT.
- **Company Regulations** — klausul yang dipakai contract templates.
- **Roles & Permissions** — RBAC granular per modul.
- **Cost Centers** ⭐ Sprint 6 — dimensi accounting hirarkis untuk tag
  semua biaya HR (gaji, tunjangan, lembur, bonus, training, travel,
  reimbursement, severance, asset). Lihat `COST_CENTER.md`.

### B. Recruitment & Hiring

- **Manpower Planning (MPP)** — pengajuan kebutuhan SDM, klasifikasi
  Budget/Unbudget, multi-step approval, dengan **planning fields**
  (job grade, supervisor, manager) + **planned assets** + **cost center
  tagging** (Sprint 5+6).
- **Career Portal (public)** — landing page perusahaan, daftar lowongan,
  apply form, candidate auth (akun terpisah dari user internal).
- **Job Postings** — DRAFT → OPEN → FILLED, terhubung ke MPP via pivot.
- **Candidates** — pipeline NEW → SCREENING → ONLINE_TEST → INTERVIEW_HR
  → INTERVIEW_USER → OFFER → HIRED.
- **Online Tests** — TPA/English/DISC/technical, auto-grade
  MCQ/TF/Numeric/Likert, manual grade essay.
- **Anti-Cheat Tests** — fullscreen lock, paste detection, tab-switch
  monitoring.
- **Offers** — letter of offer DRAFT → SENT → ACCEPTED/REJECTED/EXPIRED/
  WITHDRAWN.
- **Probation** — auto-track 3 bulan PKWTT (UU 13/2003 Pasal 60),
  reminder H-14, auto-PASS kalau lewat tanpa keputusan,
  **auto-severance + offboarding kalau FAIL** (Sprint 5).

### C. Onboarding & Profil Karyawan

- **Employees** — profil lengkap (encrypted NIK/NPWP/rekening), keluarga,
  pendidikan, dokumen.
- **Generated Contracts** — auto-generate saat hire, e-sign digital,
  status DRAFT → SENT → SIGNED.
- **Employee Tasks (ONBOARD)** — checklist dari template + auto-complete
  kalau data sudah lengkap dari Career Portal (SUBMIT_DOCS, NPWP_VERIFY,
  BPJS_REGISTER, SETUP_HRIS dengan auto-create user account).
- **Asset Pre-Allocation** (Sprint 5) — asset yang di-plan di MPP otomatis
  di-reserve saat hire, BAST tinggal serah terima.
- **Saldo Cuti Auto-Seed** (Sprint 5) — dengan filter gender_restriction.
- **Welcome Email** — credential awal lewat email dengan
  `must_change_password=1`.
- **Cost Center Auto-Tag** (Sprint 6) — saat hire, `employees.cost_center_id`
  di-resolve via chain: posting → MPP → dept → branch → company root.

### D. Operasional Harian Karyawan

- **Attendance** — clock in/out, geofence, manual correction,
  change request.
- **Anomaly Detection** — duplicate device, mock GPS, off-hours pattern,
  HR review board.
- **Shift Assignment & Roster** — drag-drop matrix, generator pattern siklus.
- **Leaves** — pengajuan dengan attachment, multi-day, half-day, team calendar.
- **Overtimes** — PP 35/2021 calculation (1.5x/2x weekday, 2x/3x/4x
  holiday), bisa di-convert ke leave compensation.
- **Reimbursements** — submit, approve, link ke payroll. Cost center
  auto-tag dari profil karyawan.
- **Loans / Cash Advances** — pinjaman/kasbon, lunas via payroll deduction.
- **Business Trips (SPPD)** — request, approve, expenses, settle. Cost
  center auto-tag.
- **Helpdesk Tickets** — internal HR ticketing, assign, status, comment.

### E. Compensation & Benefits

- **Payroll Periods** — DRAFT → CALCULATED → REVIEW → FINALIZED, auto
  include bonus/severance saat finalize, payslip ditag ke cost center
  karyawan.
- **Payslips** — PDF + bukti potong PPh21.
- **Bank Export** — format BCA/Mandiri/BNI/BRI/Permata/CIMB.
- **PPh21 TER** — perhitungan TER 2024+ + GROSS_UP option.
- **PPh21 Bukti Potong** — A1 (tahunan), bulanan, JKK/JKM.
- **BPJS** — Kesehatan (1%/4%) + TK (JHT/JKK/JKM/JP/JKP).
- **THR** — generator otomatis sesuai prorata masa kerja.
- **Allowances** — fixed (bulanan) & variable, auto-recompute saat
  JIC/transfer.
- **Bonus Engine** — annual/KPI/milestone/sign-on/retention scheme,
  run flow DRAFT → CALCULATED → APPROVED → PAID, auto-merge ke payslip.

### F. Performance & Development

- **Performance Cycles** — quarterly/half-yearly/yearly.
- **Goals (OKR/KPI)** — bobot weight, check-in periodic, auto-validation
  bobot total = 100%.
- **Performance Reviews** — self + supervisor, finalize → trigger IDP
  bootstrap.
- **Individual Development Plan (IDP)** — auto-bootstrap dari
  `improvements` field di review.
- **Training & Sertifikasi** — catalog, registrasi dengan approval,
  certificate tracking, expiry reminder.
- **Training Budgets** — budget per dept/year, tracking realisasi
  (cost center category TRAINING).

### G. Disiplin & Mutasi

- **Disciplinary Records** — kategori (verbal, SP1/2/3, suspension),
  surat acknowledge digital.
- **Job Information Change (JIC)** — Promosi/Demosi/Lateral/Salary
  Adjustment/Status Change, multi-tier approval (Supervisor → Dept Head
  → HR → BOD opsional → Employee Consent untuk demosi non-disipliner)
  → IMPLEMENTED, auto-amandemen kontrak, auto-recompute allowance,
  **auto-notif ke atasan baru/lama** (Sprint 5).
- **Employee Transfer** — mutasi lateral antar
  branch/department/posisi/work_location, workflow ringan (HR-only).

### H. Resignation, PHK, & Offboarding

- **Resignation Requests** — self-service, atasan approve → HR approve
  → set resign_date + reason + auto-create MPP replacement DRAFT.
- **Termination (PHK Formal)** — DRAFT → PENDING_BIPARTITE (kondisional)
  → PENDING_HR_DIRECTOR → PENDING_BOD (kondisional) → APPROVED →
  EXECUTED, auto-severance + PHK letter.
- **Severance Calculator** — pesangon + UPMK + UPH per PP 35/2021,
  dengan PPh21 progressive terpisah PER-PJ 16/PJ/2016. Cost center
  tag SEVERANCE.
- **Offboarding Clearance Gates** — Asset clearance + Finance clearance
  + BPJS deactivation.
- **Paklaring** (Surat Keterangan Kerja) — UU 13/2003 Pasal 153, gate
  di belakang asset clearance.
- **Final Settlement** — link severance + sisa cuti + gaji prorata ke
  payroll periode bulan resign.
- **Exit Interview** — 4 mode (in-person/video/phone/written/self-fill),
  eNPS analytics, would_rehire tracking.
- **Auto-Deactivate** (Sprint 5) — cron harian deactivate karyawan saat
  resign_date tercapai (employees.is_active=0, users.is_active=0,
  refresh_tokens revoked).

### I. Compliance, Reporting & Cost Tracking

- **Audit Logs** — tiap state change, severity (DEBUG/INFO/NOTICE/
  WARNING/CRITICAL), retention by company.
- **Compliance Reports** — WLKP tahunan, BPJS bulanan, PKWT expiring,
  PHK list, audit summary, turnover summary.
- **Headcount Reports** — by dept/branch/employment_status, monthly trend.
- **Attendance Reports** — heatmap, summary, late ranking.
- **Payroll Reports** — summary, BPJS export, PPh21 export.
- **Leave Reports** — utilisasi tahunan, top users.
- **Employee 360 View** — aggregator 12 section per karyawan.
- **Reporting Card** — dashboard manager untuk team-nya.
- **Cost Center Budget vs Actual** ⭐ Sprint 6 — laporan keuangan
  per-cost-center: Budget vs Actual + **Forecast** (actual + committed),
  breakdown per kategori (SALARY/ALLOWANCE/BONUS/TRAVEL/TRAINING/
  SEVERANCE/REIMBURSE).

### J. Self-Service & Notification

- **Notifications** — in-app + email + Web Push, preferences per user
  per channel.
- **Notification Templates** — variable substitution per company × event_type.
- **Approval Inbox** — semua pending approval di satu tempat.
- **Approval Delegations** — delegate approval saat absen.
- **My Profile** — self-edit terbatas, ganti password, 2FA, avatar upload.
- **My Payslips** — list + download PDF.
- **My Leave Balance** — saldo cuti tahun berjalan.
- **My Bonuses** — bonus history.
- **My Performance** — goals & reviews.
- **My Resignation** — submit & track.
- **My Transfers** — list mutasi yang dialami.

### K. Security & Privacy

- **Authentication** — JWT + refresh token, 2FA TOTP, backup codes.
- **Password Policy** — R1-R6 (panjang, kompleksitas, common, history,
  rotation, user-specific).
- **Encryption at Rest** — AES-256-GCM dengan key versioning untuk NIK,
  NPWP, rekening bank.
- **UU PDP Compliance** — consent tracking, data export self-service,
  deletion request workflow.
- **Privacy Scope** — employee-level isolation + permission-aware filter
  whitelist.
- **CSP Reporting** — browser report endpoint.
- **Rate Limiting** — bucket per endpoint sensitif (login, password
  reset, 2FA, export).
- **Audit Log Retention** — purge otomatis sesuai retention policy.

### L. Integrations

- **Outbound Webhooks** — HMAC-signed events, retry exponential backoff.
- **Inbound Webhooks** — receive events dari external dengan signature
  verification.
- **Email Queue** — SMTP per company, retry 30s → 6h max 5x.
- **File Storage** — local driver default, S3-ready abstraction.
- **Career Portal API** — public endpoints terpisah dari authenticated API.

### M. Background Jobs (Cron)

| Time | Command | Tujuan |
| --- | --- | --- |
| 05:00 | `hr:deactivate-resigned` | Auto-deactivate karyawan resign |
| 05:30 | `hr:check-probation` | Auto-pass probation expired tanpa keputusan |
| 06:00 | `hr:apply-jic` | Apply JIC APPROVED yang sudah jatuh tempo |
| 06:15 | `hr:apply-transfers` | Apply employee transfers |
| 06:30 | `hr:check-bpjs` | BPJS register/deactivate SLA overdue |
| 07:00 | `hr:check-expiry` | Kontrak/probation/dokumen expiring |
| 07:15 | `hr:check-cert-expiry` | Sertifikat training/profesi expiring |
| 07:30 | `hr:check-anniversary` | Ulang tahun masa kerja |
| 08:00 | `hr:check-offer-expiry` | Offer letter expiring |
| 09:00 | `email:process` | Email queue worker |
| 23:00 Sunday | `hr:run-audit` | Audit retention & summary |
| 00:30 1 Jan | `hr:run-leave-year-end` | Carry-over saldo cuti |

---

## Business Flow End-to-End

### 🎬 PHASE 1 — Planning (Sebelum Hire)

```
Line Manager / Department Head
    │
    │ "Saya butuh 1 Backend Developer untuk tim payment"
    ▼
[Halaman MPP] Pengajuan Baru
    ├── Data dasar: title, headcount, type (Budget/Unbudget),
    │   reason (NEW_POSITION/REPLACEMENT/EXPANSION/SEASONAL),
    │   department, branch, employment_type, salary range
    │   (salary_min, salary_max), target start date, justifikasi.
    │
    ├── Rencana Onboarding (Sprint 5):
    │   • Job Grade target (mempengaruhi gaji & allowance)
    │   • Atasan langsung (supervisor untuk approval cuti/lembur)
    │   • Manager kepegawaian (HR-side approver)
    │   • Daftar asset: laptop dev 16GB, ID card, akses VPN, dst
    │
    └── Cost Center (Sprint 6):
        • Eksplisit pilih dari picker, atau
        • Auto-resolve dari department.default_cost_center_id atau
          branch.default_cost_center_id

    ▼
[Submit MPP]
    ├── Status: DRAFT → SUBMITTED
    ├── ManpowerService.submit() generate approval steps
    │   • BUDGET   → 1 step (HRD Manager)
    │   • UNBUDGET → 2 step (HRD Manager → Director)
    └── OrganizationStructureService resolve approver per role_key
        dari Master Jabatan Kunci

    ▼
[HRD Manager approve]
    ├── (Kalau UNBUDGET) → forward ke Director
    └── ApprovalDelegationResolver cek delegate aktif

    ▼
[Status APPROVED]
    ├── Talent Acquisition lihat MPP siap fulfill
    └── Cost Center ledger: muncul di "Committed" (Sprint 6)
        — perkiraan cost = headcount × midpoint(salary) × bulan_tersisa × 1.13
```

**Yang ter-track ke Cost Center sejak phase ini:**
- `manpower_requests.cost_center_id` di-set
- Cost center forecast (di laporan Budget vs Actual) menambahkan
  estimasi gaji untuk MPP yang APPROVED/HIRING

### 🎬 PHASE 2 — Recruitment

```
Talent Acquisition di halaman MPP
    │
    │ Klik "Buat Lowongan" pada MPP APPROVED
    ▼
[Modal Fulfill]
    ├── Carry-over: title, deskripsi, persyaratan, salary, employment type
    ├── Headcount: bisa di-split per cabang
    └── Submit
    ▼
[Job Posting DRAFT dibuat]
    └── ManpowerService.createJobPostingFromMpp()
        ├── Insert job_postings + pivot manpower_request_postings
        ├── Status MPP: APPROVED → HIRING
        └── OnboardingPlanService.syncFromMpp()
            • Copy job_grade_id, default_supervisor, default_manager
            • Copy planned assets ke job_posting_assets
            • Copy cost_center_id (Sprint 6)

    ▼
[TA edit posting + Publish ke Career Portal]
    └── Status: DRAFT → OPEN → di-listing publik

    ▼
[Pelamar masuk via Career Portal]
    ├── Apply form (CV, cover letter, ekspektasi gaji, available_from)
    ├── Upload dokumen: KTP, NPWP, ijazah, sertifikat
    ├── Isi profil lengkap: alamat, NIK, NPWP (encrypted), BPJS, bank
    ├── Optional: register account untuk track status
    └── Notification: HR + hiring manager

    ▼
[Pipeline pelamar — drag antar stage]
    NEW → SCREENING → ONLINE_TEST → INTERVIEW_HR → INTERVIEW_USER → OFFER

    ├── Online Test (kalau dipakai)
    │   • Auto-grade MCQ, manual grade essay
    │   • Anti-cheat: fullscreen lock, paste detect, tab monitor
    │   • Gate: tidak bisa naik stage sebelum semua test selesai
    │
    └── Offer Letter
        • Generate dari template
        • DRAFT → SENT (email) → ACCEPTED/REJECTED/EXPIRED

    ▼
[Stage HIRED]
    └── HR klik "Hire" di pipeline board
```

### 🎬 PHASE 3 — Hiring & Onboarding

```
HR klik tombol Hire pada candidate ACCEPTED
    │
    ▼
[RecruitmentService.convertToEmployee()]
    ├── Insert employees row
    │   • Data dari candidate + posting + MPP
    │   • Salary dari offer (atau fallback midpoint MPP salary range)
    │   • cost_center_id resolve via chain (override → posting → MPP →
    │     dept → branch → company root) ⭐ Sprint 6
    │   • is_active=1, employment_status=PROBATION (kalau PKWTT) atau
    │     ACTIVE (kalau PKWT/PARTTIME)
    │
    ├── Insert users row (auto-create login account)
    │   • Username = NIK / employee_number / email candidate
    │   • Password random + must_change_password=1
    │   • Email welcome dengan link login
    │
    ├── ProbationService.start() — kalau PKWTT
    │   • probation_end_date = join_date + 3 bulan
    │   • Schedule reminder H-14
    │
    ├── Generated contract auto-create
    │   • Pilih ContractTemplate sesuai employment_type
    │   • Render variable: {nama}, {jabatan}, {gaji}, dst
    │   • Status: DRAFT → SENT (email link sign)
    │   • Karyawan e-sign → SIGNED
    │
    ├── OnboardingService.bootstrap()
    │   • Generate employee_tasks dari TaskTemplate ONBOARD
    │   • Auto-complete tasks yang sudah lengkap (data dari Career):
    │     - SUBMIT_DOCS jika dokumen lengkap
    │     - NPWP_VERIFY jika NPWP terisi
    │     - BPJS_REGISTER kalau eligible
    │   • Reserve planned assets (Sprint 5):
    │     - Insert asset_assignments dari job_posting_assets
    │     - Status RESERVED, BAST tinggal serah terima
    │
    ├── LeaveService.seedBalances()
    │   • Generate leave_balances tahun berjalan (prorata masa kerja)
    │   • Filter gender_restriction (mis. tidak seed maternity untuk pria)
    │
    ├── AllowanceService.recomputeEmployee()
    │   • Resolve allowance dari mapping branch × grade
    │   • Insert employee_allowances aktif
    │
    └── Update MPP
        • hired_count += 1
        • Kalau hired_count == headcount → status HIRING → FILLED
        • Update Cost Center "Committed" — turun karena MPP terisi
          (Sprint 6)
```

**Yang ter-track ke Cost Center setelah hire:**
- `employees.cost_center_id` permanen
- Saat payroll calculate berikutnya, payslip karyawan ini ditag ke
  cost_center_id-nya dan masuk ke laporan "Actual" kategori SALARY +
  OVERTIME + BENEFIT + BONUS

### 🎬 PHASE 4 — Operasional Bulanan

```
Karyawan setiap hari
    ├── Clock-in pagi via mobile/web (geofence check ke branch lat/lng)
    ├── Clock-out sore, lembur kalau lewat shift end
    ├── Pengajuan cuti / lembur / reimbursement / business trip
    └── Lihat saldo cuti, slip gaji, pengumuman

Supervisor / Manager setiap minggu
    ├── Approval inbox: cuti tim, lembur tim, reimbursement
    ├── Roster — pengaturan shift mingguan
    ├── Performance: input goals tim, check-in progress
    └── Dashboard tim — headcount, attendance, anomaly

HR setiap awal bulan (Day -3 sampai 0 payroll)
    │
    ▼
[Pre-Payroll Audit]
    ├── Pending approvals (cuti, lembur, reimbursement, koreksi absen)
    │   harus di-clear / di-skip dengan tahu konsekuensinya
    ├── Anomaly attendance — review & resolve
    └── Cost Center "Committed" report (Sprint 6) — proyeksi forecast
        sisa tahun untuk identifikasi kategori yang akan over-budget

    ▼
[Payroll Calculate]
    └── PayrollService.calculatePeriod()
        ├── Untuk setiap karyawan aktif:
        │   • Resolve cost_center_id (Sprint 6)
        │   • Hitung gaji pokok prorata kalau hire/resign mid-period
        │   • Hitung allowance fixed + variable
        │   • Hitung overtime (PP 35/2021)
        │   • Hitung absent deduction (kalau di-enable)
        │   • Pull reimbursement APPROVED yang belum dibayar
        │   • Pull loan installment yang RUNNING
        │   • Auto-merge bonus run APPROVED dengan
        │     payroll_period_id = period ini (Sprint 17+)
        │   • Hitung BPJS (Kesehatan + JHT + JP + JKK + JKM)
        │   • Hitung PPh21 TER 2024+ (atau December correction)
        │
        ├── Insert payslips (cost_center_id ditag)
        │   • bonus_amount column ter-isi dari bonus run merge
        │
        └── Insert payslip_details (per komponen)

    ▼
[HR Review Slip — opsional perbaikan manual]

    ▼
[Payroll Finalize]
    └── PayrollService.finalize()
        ├── Mark overtime, reimbursement, loan installment paid
        ├── Decrement loan balance, set COMPLETED kalau lunas
        ├── Status period: CALCULATED → PAID
        ├── Notification ke karyawan: slip gaji tersedia
        ├── Generate file bank export (BCA/Mandiri/dll)
        └── Cost Center ledger update — biaya gaji periode ini
            masuk ke "Actual" kategori SALARY/OVERTIME/BENEFIT/BONUS
            (Sprint 6)

    ▼
[Optional: PPh21 export bulanan, BPJS export bulanan]
```

### 🎬 PHASE 5 — Promosi / Mutasi (Selama Bekerja)

```
Trigger: hasil performance review, kebutuhan reorganisasi, atau
business request
    │
    ▼
[HR/Manager submit JIC]
    ├── Pilih tipe: PROMOTION / DEMOTION / LATERAL / SALARY_ADJUSTMENT /
    │   STATUS_CHANGE
    ├── Karyawan target, posisi/grade/branch/department/salary baru
    └── Tanggal effective

    ▼
[Workflow Approval]
    ├── Step 1: Supervisor target karyawan
    ├── Step 2: Department Head target
    ├── Step 3: HR
    ├── Step 4 (kondisional, kalau salary change > threshold): BOD
    ├── Step 5 (kondisional, DEMOTION non-disipliner): Employee Consent
    └── Setiap step: ApprovalDelegationResolver cek delegate aktif

    ▼
[APPROVED]
    └── Tunggu sampai effective_date

    ▼
[JicService.implement() — manual atau auto via cron 06:00]
    ├── Update employees:
    │   • position_id, department_id, branch_id, work_location_id baru
    │   • basic_salary baru (kalau ada)
    │   • supervisor_employee_id, manager_employee_id baru
    │   • Cost center re-resolve kalau dept/branch berubah (Sprint 6)
    │
    ├── AllowanceService.recomputeEmployee()
    │   • Ambil mapping baru (branch_id × job_grade_id)
    │   • Insert employee_allowances baru, deactivate yang lama
    │
    ├── Generate contract amandemen
    │   • Pilih ContractTemplate AMANDEMEN
    │   • Render variable: {salary_lama}, {salary_baru}, {effective_date}
    │   • Karyawan e-sign
    │
    └── Notifikasi (Sprint 5):
        • Karyawan target
        • Atasan baru — "Anda kebagian anggota tim baru: ..."
        • Atasan lama — "Anggota tim Anda dipindah: ..."
        • HR Dept Head dest
```

### 🎬 PHASE 6 — Resignation / PHK & Offboarding

```
═══ Jalur A: Karyawan resign sukarela ═══

Karyawan ajukan resignation
    │
    │ Self-service via /hr/my-resignation
    ▼
[Form Resign]
    ├── Tanggal effective resign
    ├── Reason (PERSONAL / PINDAH KERJA / EDUCATION / dll)
    ├── Notice period sesuai company policy (default 30 hari)
    └── Notes

    ▼
[Submit]
    ├── Notification ke atasan langsung
    └── Status: SUBMITTED

    ▼
[Atasan approve / reject]
    └── Approve → Status: APPROVED_BY_SUPERVISOR

    ▼
[HR approve]
    ├── Set employees.resign_date = effective_date
    ├── Auto-create MPP DRAFT replacement (kalau setting on)
    ├── OffboardingService.bootstrap()
    │   • Generate task OFFBOARD checklist
    │   • Trigger asset clearance, BPJS deactivation
    └── Status: APPROVED

═══ Jalur B: PHK formal (initiated by company) ═══

[HR submit Termination]
    └── Workflow:
        DRAFT → PENDING_BIPARTITE → PENDING_HR_DIRECTOR →
        PENDING_BOD (kondisional) → APPROVED → EXECUTED

═══ Both jalur converge di Offboarding ═══

    ▼
[Severance Calculator]
    ├── Pesangon (PP 35/2021) berdasarkan masa kerja & alasan
    ├── UPMK (Uang Penghargaan Masa Kerja) per masa kerja
    ├── UPH (Uang Pengganti Hak): sisa cuti + biaya pindah, dll
    ├── PPh21 progressive PER-PJ 16/PJ/2016
    ├── DRAFT → APPROVED → PAID
    └── cost_center_id auto-tag (Sprint 6) → kategori SEVERANCE

    ▼
[Offboarding Clearance Gates]
    ├── Asset clearance (semua asset dikembalikan / di-write-off)
    ├── Finance clearance (loan lunas / saldo cuti dibayar)
    ├── BPJS deactivation (gate 14 hari pasca resign — SLA)
    └── Paklaring (UU 13/2003 Pasal 153) — gated di belakang asset
        clearance

    ▼
[Final Settlement]
    └── Link severance + sisa cuti + gaji prorata ke payroll periode
        bulan resign

    ▼
[Exit Interview]
    └── Mode in-person / video / phone / written / self-fill
        eNPS, would_rehire, primary_reason, suggestions

    ▼
[Auto-Deactivate (Sprint 5) cron 05:00 setiap hari]
    └── Untuk karyawan dengan resign_date <= hari ini & is_active=1:
        • employees.is_active = 0
        • users.is_active = 0
        • refresh_tokens revoked semua
        • Notification: "Akun ditutup"
```

---

## Cost Center: Bagaimana Biaya Ter-track Sepanjang Lifecycle (Sprint 6)

```
                    ┌────────────────────────────────────────┐
                    │   Cost Center Master (hirarkis)        │
                    │                                        │
                    │   ROOT (DEFAULT)                       │
                    │     ├── DIRECTORATE-COMMERCIAL         │
                    │     │     ├── DEPT-SALES               │
                    │     │     │     ├── TEAM-JKT           │
                    │     │     │     └── TEAM-SBY           │
                    │     │     └── DEPT-MARKETING           │
                    │     └── DIRECTORATE-OPERATIONS         │
                    │           └── DEPT-IT                  │
                    │                 ├── TEAM-DEV           │
                    │                 └── TEAM-INFRA         │
                    └────────────────┬───────────────────────┘
                                     │
                                     │ Default mapping di:
                                     │ • departments.default_cost_center_id
                                     │ • branches.default_cost_center_id
                                     │
        ┌────────────────────────────┼────────────────────────────┐
        │                            │                            │
        ▼                            ▼                            ▼
   ┌─────────┐               ┌──────────────┐              ┌──────────┐
   │  MPP    │ resolve→     │   Employee    │ resolve→    │ Job      │
   │         │ override→    │               │ chain        │ Posting  │
   │         │ employee →   │               │              │          │
   │         │ dept →       │               │              │          │
   │         │ branch →     │               │              │          │
   │         │ root         │               │              │          │
   └────┬────┘              └──────┬───────┘              └────┬─────┘
        │                          │                            │
        │ COMMITTED:                │ ACTUAL:                    │
        │ headcount × salary ×      │ payslip per period ditag   │
        │ bulan tersisa × 1.13      │ ke cost_center_id          │
        │                          │                            │
        │ Saat APPROVED, biaya     │ Saat finalize, masuk ke    │
        │ MPP masuk ke forecast    │ kategori SALARY/OVERTIME/   │
        │ cost center             │ BENEFIT/BONUS               │
        ▼                          ▼                            ▼
   ┌──────────────────────────────────────────────────────────────┐
   │       Cost Center Budget vs Actual Report (Sprint 6)         │
   │                                                              │
   │  Budget    Actual YTD    Committed       Forecast Total      │
   │  Rp 5.0M   Rp 2.4M       Rp 1.8M         Rp 4.2M (84%) ✓     │
   │                                                              │
   │  Per kategori: SALARY / ALLOWANCE / OVERTIME / BONUS /       │
   │  BENEFIT / TRAINING / TRAVEL / SEVERANCE / REIMBURSE         │
   │                                                              │
   │  Setiap baris menampilkan: Budget | Actual | Committed |     │
   │  Forecast | Forecast vs Budget                               │
   │                                                              │
   │  ⚠ Warning over-budget kalau Forecast > Budget              │
   └──────────────────────────────────────────────────────────────┘
```

**Sumber data Actual** (sudah direalisasi):
- `payslips` (SALARY = basic+gross_earning, OVERTIME, BENEFIT employer
  BPJS, BONUS)
- `reimbursements.amount` (REIMBURSE)
- `business_trips.total_amount` + `business_trip_expenses.amount` (TRAVEL)
- `bonus_runs.total_amount` saat PAID (BONUS — header level)
- `training_budgets.actual_amount` (TRAINING)
- `severance_calculations.total_amount` (SEVERANCE)

**Sumber data Committed** (sudah di-pesan, belum direalisasi):
- `manpower_requests` APPROVED/HIRING dengan headcount sisa
- `employees` dengan join_date dalam tahun tapi belum punya payslip
- `bonus_runs` DRAFT/CALCULATED (belum APPROVED)
- `reimbursements` APPROVED tapi `paid_in_payroll_period_id IS NULL`

**Forecast = Actual + Committed.** Kalau Forecast > Budget, dashboard
menampilkan warning. Finance bisa ambil aksi sebelum periode payroll
berjalan (mis. menunda hire baru, hold bonus, atau reallokasi budget).

---

## Skenario Real World: MPP → Hire → Payroll → Cost Center Tracking

**Skenario:** Perusahaan budget IT 2026 = Rp 6 Milyar (dari `cost_center_budgets`).
Saat ini Mei 2026, mau hire 2 backend developer.

```
Bulan ke-5 (Mei 2026)
─────────────────────
Actual YTD (Jan-Apr): Rp 1.6M (sudah dibayar via 4 payslip cycle)
Committed: Rp 0
Forecast: Rp 1.6M
Utilization: 27%

→ Manager submit MPP "2 Backend Developer"
  Salary range: 12-18jt midpoint = Rp 15jt
  Target start: 1 Juli 2026
  Cost center: CC-IT-DEV (auto-resolve dari dept IT)
  Status: SUBMITTED

→ HRD Manager + Director approve dalam 3 hari
  Status: APPROVED → HIRING

[Cost Center recalculate forecast]
  Committed += 2 × Rp 15jt × 6 bulan (Jul-Des) × 1.13 = Rp 203.4jt
  Forecast: Rp 1.6M + Rp 203.4jt = Rp 1.8M
  Forecast vs Budget: 30%
  ✓ Masih aman

Bulan ke-6 (Juni 2026)
──────────────────────
Hire 1 dev (start 15 Juni). Salary actual: Rp 14jt.

→ RecruitmentService.convertToEmployee()
  - employees.cost_center_id = CC-IT-DEV
  - hired_count MPP: 0 → 1, status MPP: HIRING

[Cost Center recalculate]
  Committed manpower_requests: 1 sisa × 15jt × 6 bulan × 1.13 = Rp 101.7jt
  Committed employees baru: 1 × 14jt × 7 bulan (Jun-Des) × 1.13 = Rp 110.7jt
                          (skip bulan Mei karena belum join)
  Total committed: Rp 212.4jt

Bulan ke-7 (Juli 2026)
──────────────────────
Payslip Juni di-finalisasi. Salary developer baru masuk:
- basic_salary: 14jt
- BPJS employer: ~1.7jt
- Total payslip: 15.7jt → masuk ke ACTUAL kategori SALARY+BENEFIT

[Cost Center recalculate]
  Actual YTD: Rp 1.6M + Rp 0.4M (payroll Mei+Juni) = Rp 2.0M
  Committed manpower_requests: 1 sisa × 15jt × 5 bulan × 1.13 = Rp 84.7jt
  Committed employees: 0 (sudah ada payslip → bukan committed lagi)
  Forecast: Rp 2.0M + Rp 84.7jt = Rp 2.085M
  Utilization actual: 33%, Forecast: 35%
  ✓ Masih aman

Bulan ke-8 (Agustus 2026)
─────────────────────────
Hire 1 dev lagi. MPP FILLED (hired_count = 2 = headcount).

[Cost Center recalculate]
  Committed manpower_requests: 0
  Forecast murni dari karyawan aktif (termasuk 2 dev baru)
```

Manager IT bisa lihat di dashboard:
- Ranjang aman warna hijau (utilization < 80%)
- Breakdown per kategori — lihat SALARY paling berat, sisanya tipis
- Decision support — masih ada room untuk training (TRAINING category
  baru utilization 12%)

---

## Wiring Cost Center per Modul (Inventory)

| Modul | Tag cost_center_id | Field di table | Source |
| --- | --- | --- | --- |
| Master karyawan | ✓ | `employees.cost_center_id` | resolve chain saat hire/transfer |
| Master dept | ✓ | `departments.default_cost_center_id` | manual |
| Master branch | ✓ | `branches.default_cost_center_id` | manual |
| MPP submit | ✓ | `manpower_requests.cost_center_id` | override / auto-resolve |
| Job posting | ✓ | `job_postings.cost_center_id` | carry-over dari MPP |
| Payslip | ✓ | `payslips.cost_center_id` | resolve saat payroll calculate |
| Reimbursement | ✓ | `reimbursements.cost_center_id` | resolve saat create |
| Loan | ✓ | `loans.cost_center_id` | resolve saat create |
| Business trip | ✓ | `business_trips.cost_center_id` | resolve saat create |
| Bonus run | ✓ | `bonus_runs.cost_center_id` | header level, override saat create |
| Severance | ✓ | `severance_calculations.cost_center_id` | resolve saat create |
| Asset | ✓ | `asset_assignments.cost_center_id` | inherit dari karyawan |
| Training | ✓ | `training_budgets.cost_center_id` | per dept/year |

---

## Endpoints Penting

### Cost Center
- `GET /cost-centers/tree` — hirarki visual
- `GET /cost-centers/picker` — dropdown ringan untuk form
- `POST /cost-centers/backfill-employees` — backfill cost_center_id ke
  semua karyawan yang belum di-tag
- `GET /cost-centers/{id}/budget-vs-actual?year=2026` — laporan utama
  dengan budget + actual + **committed** + **forecast**
- `GET /cost-centers/{id}/committed?year=2026` — committed breakdown saja
- `POST /cost-centers/{id}/budgets` — bulk upsert budget per
  ANNUAL/QUARTERLY/MONTHLY × kategori

### MPP
- `POST /manpower-requests` — create dengan optional `cost_center_id` override
- `POST /manpower-requests/{id}/submit` — DRAFT → SUBMITTED
- `POST /manpower-requests/{id}/approve` — step approval
- `POST /manpower-requests/{id}/create-posting` — bikin job posting

### Recruitment
- `POST /candidates/{id}/hire` — convert ke employee, generate contract,
  bootstrap onboarding tasks, allocate planned assets

### Payroll
- `POST /payroll-periods/{id}/calculate` — hitung semua slip
- `GET /payroll-periods/{id}/pending-approvals` — pre-check
- `POST /payroll-periods/{id}/finalize` — lock + bayar

---

## Permissions (RBAC) Per Modul

Format slug: `<modul>.<aksi>`. Daftar lengkap di seeder migration
`InitialSeeder.php` + migration tambahan per sprint:

```
employee.view / update / delete
attendance.view_all / approve_change / review_anomaly
leave.view / approve / reject / manage_types / team_calendar
overtime.view / approve / reject
payroll.view / manage / calculate / finalize
payslip.view_all / view_self
reimbursement.view / approve / create
loan.view / create / create_for_others
disciplinary.view / manage
jic.view / manage
employee_transfer.view / approve
severance.view / manage
termination.view / manage
resignation.view / approve
exit_interview.view / manage
onboarding.view / manage
asset.view / manage
business_trip.view / approve
mpp.view / submit / approve / fulfill
recruitment.view / manage
careers.manage
online_tests.view / manage / grade
helpdesk.view / assign / manage
training.view / register / approve
performance.view / review
goal.view / approve / check_in
review.view / acknowledge
idp.view / manage
bonus.view / manage / approve         ⭐ Sprint 6 (migration 058)
hr.manage                             ⭐ Sprint 6 (migration 058)
privacy.review                        ⭐ Sprint 6 (migration 058)
cost_center.view / manage             ⭐ Sprint 6 (migration 057)
compliance.report.view
audit.view
report.view
settings.manage
user.manage
role.manage
```

Auto-grant default per role bawaan:

| Role | Granted |
| --- | --- |
| super_admin | semua (wildcard di middleware + eksplisit) |
| hr_admin | semua kecuali super-admin only |
| manager | view + approve untuk modul cuti/lembur/transfer/cost_center.view |
| finance | payroll.* + reimbursement.* + loan.* + cost_center.* |
| employee | self-service (view.own) |

---

## File Dokumentasi Terkait

| Dokumen | Topik |
| --- | --- |
| `COST_CENTER.md` | Cost center detail teknis (Sprint 6) |
| `BONUS_ENGINE.md` | Bonus scheme & run (Sprint 16+) |
| `JIC.md` | Job Information Change workflow |
| `EMPLOYEE_TRANSFER.md` | Mutasi lateral |
| `RESIGNATION.md` | Self-service resignation |
| `ONLINE_TEST.md` | Test recruitment |
| `CAREER_PORTAL.md` | Public career portal |
| `ORG_STRUCTURE.md` | Drag-drop org structure |
| `DATA_PRIVACY.md` | UU PDP compliance |
| `AUDIT.md` | Audit log retention |
| `LIFECYCLE_AUDIT_2026.md` | Master tracking sprint per item |
| `SPRINT_1_COMPLIANCE.md` | Offboarding clearance & paklaring |
| `SPRINT_2_RECRUITMENT_POLISH.md` | Probation tracker, offer letter |
| `SPRINT_3_OD_LOOP.md` | IDP closing the loop |
| `SPRINT_4_TERMINATION_POLISH.md` | Termination workflow + Compliance reports |
| `SPRINT_5_LIFECYCLE_AUDIT.md` | Auto-deactivate + JIC notif |
| `SPRINT_5_ONBOARDING_ACCEL.md` | MPP planned assets + auto-onboarding |

---

## Catatan Versi & Kompatibilitas

- Backend: CodeIgniter 4 + PHP 8.5, PHPUnit 10.5, 607 tests / 1396 assertions.
- Frontend: Vue 3 + Vite + Tailwind, build < 16s.
- Database: MySQL 8 (production) / SQLite (test fixtures).
- Multi-tenant: ketat per `company_id` di hampir semua tabel + scope
  enforcement di TenantContext.
- Migrasi idempotent: aman di-rerun, semua `tableExists` &
  `getFieldNames` check dulu sebelum modifikasi.