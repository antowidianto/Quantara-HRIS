# Indonesian Stakeholder Seed — PT Quantara Nusantara Sejahtera

Dokumen ini menjelaskan **struktur organisasi default** yang dihasilkan oleh
`IndonesianStakeholderSeeder` dan memetakan akun login untuk setiap level,
mulai dari **Komisaris Utama** sampai **Junior Staff**. Tujuannya supaya
semua skenario bisnis HRIS Quantara dapat disimulasikan ujung-ke-ujung
dengan data yang realistis.

> **TL;DR**
>
> ```bash
> cd backend
> php spark migrate
> php spark db:seed InitialSeeder           # roles, permissions, PTKP/TER
> php spark db:seed IndonesianMastersSeeder # UMP/UMK 2026, libur nasional
> php spark db:seed IndonesianStakeholderSeeder
> ```
>
> - Semua data karyawan, user, dan kandidat sebelumnya **dihapus**.
> - Master sistem (roles, permissions, PTKP, TER, UMP, libur, dst.) **tetap**.
> - 68 karyawan dibuat dengan reporting line lengkap.
> - Default password semua akun: **`Quantara@2026`** (must_change=1).

---

## 1. Profil Perusahaan

| Field | Value |
| --- | --- |
| Code | `QNS` |
| Nama | PT Quantara Nusantara Sejahtera |
| Legal name | PT Quantara Nusantara Sejahtera, Tbk. |
| NPWP | 01.234.567.8-901.000 |
| Alamat HQ | Wisma Quantara, Jl. Jenderal Sudirman Kav. 52-53, Jakarta Selatan |
| Timezone | Asia/Jakarta |
| Currency | IDR |
| Fiscal year start | Januari |

### Cabang

| Code | Nama | Provinsi | Geofence |
| --- | --- | --- | --- |
| `JKT` | Jakarta Pusat (Head Office) | DKI Jakarta | 250 m |
| `SBY` | Cabang Surabaya | Jawa Timur | 250 m |
| `BDG` | Cabang Bandung | Jawa Barat | 250 m |
| `MDN` | Cabang Medan | Sumatera Utara | 250 m |
| `MKS` | Cabang Makassar | Sulawesi Selatan | 250 m |

---

## 2. Struktur Organisasi

Mengikuti **UU 40/2007 tentang Perseroan Terbatas**: struktur dua jalur
(BOC = Dewan Komisaris, BOD = Dewan Direksi), dengan tambahan jalur
operasional GM → Manager → Asst. Manager → Supervisor → Staff.

```
                    ┌─────────────────────────┐
                    │  Dewan Komisaris (BOC)  │
                    │   Komisaris Utama       │ ◄── EMP-0001 Sumarno Hadiwijaya
                    │   Komisaris Independen  │ ◄── EMP-0002 Diah Pertiwi
                    └────────────┬────────────┘
                                 │ pengawasan & advice
                                 ▼
                    ┌─────────────────────────┐
                    │  Direktur Utama (CEO)   │ ◄── EMP-0010 Bambang Soewignyo
                    └────────────┬────────────┘
       ┌────────────┬────────────┼────────────┬────────────┬────────────┐
       ▼            ▼            ▼            ▼            ▼            ▼
   Dir.Keu      Dir.SDM      Dir.Ops     Dir.IT       Dir.Mkt      Dir.Lgl
   (CFO)        (CHRO)       (COO)       (CTO)        (CMO)        (CLO)
   EMP-0011     EMP-0012     EMP-0013    EMP-0014     EMP-0015     EMP-0016
       │            │            │            │            │            │
       ▼            ▼            ▼            ▼            ▼            ▼
   GM Fin      GM HR        GM Ops      GM IT        GM Mkt       Mgr Legal
   EMP-0020    EMP-0021     EMP-0022    EMP-0023     EMP-0024     EMP-0054
       │            │            │
       │            │            ├──► Branch Manager Surabaya (EMP-0030)
       │            │            ├──► Branch Manager Bandung  (EMP-0031)
       │            │            ├──► Branch Manager Medan    (EMP-0032)
       │            │            └──► Branch Manager Makassar (EMP-0033)
       │            │
       │            └──► Manager × 5 (Recruitment, L&D, C&B, IR, HR Ops/Payroll)
       │                          │
       │                          └──► Supervisor → Senior Staff → Staff
       │
       └──► Manager × 3 (Accounting, Treasury, Tax)
                              │
                              └──► Asst Mgr → Senior → Staff
```

### Job Grades

| Code | Nama | Level | Gaji range (IDR) |
| --- | --- | ---: | --- |
| `BOC` | Komisaris | 11 | 80 000 000 – 200 000 000 |
| `DIR` | Direktur (BOD) | 10 | 60 000 000 – 150 000 000 |
| `VP` | Vice President / GM | 8 | 35 000 000 – 60 000 000 |
| `MGR` | Manager | 7 | 22 000 000 – 38 000 000 |
| `ASM` | Assistant Manager | 6 | 14 000 000 – 22 000 000 |
| `SPV` | Supervisor | 5 | 10 000 000 – 15 000 000 |
| `SRS` | Senior Staff | 4 | 8 000 000 – 12 000 000 |
| `STF` | Staff | 3 | 5 500 000 – 8 500 000 |
| `JRS` | Junior Staff | 2 | 4 500 000 – 6 500 000 |

### Departments (24 total)

Top-level direktorat: `BOC`, `BOD`, `FIN`, `HR`, `OPS`, `IT`, `MKT`, `LGL`.

Sub-departemen:
- **Finance:** `FIN-ACC`, `FIN-TRS`, `FIN-TAX`
- **HR:** `HR-REC`, `HR-LND`, `HR-CNB`, `HR-IR`, `HR-OPS`
- **Operations:** `OPS-OPR`, `OPS-LOG`, `OPS-PRC`
- **IT:** `IT-DEV`, `IT-INF`, `IT-SUP`
- **Marketing:** `MKT-MKT`, `MKT-SLS`

---

## 3. Daftar Akun Login Per Peran

Default password untuk **semua** akun: `Quantara@2026` (wajib ganti saat login pertama).

### Komisaris (BOC)

| Email | Nama | Role | Atasan |
| --- | --- | --- | --- |
| `komisaris.utama@quantara.id` | Sumarno Hadiwijaya | `super_admin` | — |
| `komisaris.independen@quantara.id` | Diah Pertiwi | `internal_auditor` | Komisaris Utama |

### Direksi (BOD)

| Email | Nama | Posisi | Role |
| --- | --- | --- | --- |
| `direktur.utama@quantara.id` | Bambang Soewignyo | Direktur Utama (CEO) | `ceo` |
| `direktur.keuangan@quantara.id` | Hendra Wijaya | Direktur Keuangan (CFO) | `cfo` |
| `direktur.sdm@quantara.id` | Ratna Kusumawardhani | Direktur SDM (CHRO) | `hr_director` |
| `direktur.operasional@quantara.id` | Agung Pranata | Direktur Operasional (COO) | `department_head` |
| `direktur.ti@quantara.id` | Iwan Setiawan | Direktur TI (CTO) | `it_admin` |
| `direktur.pemasaran@quantara.id` | Maria Christina | Direktur Pemasaran (CMO) | `department_head` |
| `direktur.legal@quantara.id` | Yusuf Mahendra | Direktur Hukum (CLO) | `legal_compliance` |

### General Manager (Kepala Divisi)

| Email | Nama | Divisi | Role |
| --- | --- | --- | --- |
| `dewi.anggraini@quantara.id` | Dewi Anggraini | GM Finance & Accounting | `finance` |
| `surya.pradana@quantara.id` | Surya Pradana | GM Human Resources | `hr_business_partner` |
| `lestari.handayani@quantara.id` | Lestari Handayani | GM Operations | `department_head` |
| `reza.adiputra@quantara.id` | Reza Adiputra | GM IT | `it_admin` |
| `indah.permatasari@quantara.id` | Indah Permatasari | GM Marketing & Sales | `department_head` |

### Branch Managers (Kepala Cabang)

| Email | Nama | Cabang | Role |
| --- | --- | --- | --- |
| `joko.susilo@quantara.id` | Joko Susilo | Surabaya | `branch_manager` |
| `eka.setiawati@quantara.id` | Eka Setiawati | Bandung | `branch_manager` |
| `marbun.simatupang@quantara.id` | Marbun Simatupang | Medan | `branch_manager` |
| `rahmat.tahir@quantara.id` | Rahmat Tahir | Makassar | `branch_manager` |

### Departmental Managers, Asst Managers, Supervisors, Staff

Lihat tabel lengkap di kode seeder `backend/app/Database/Seeds/IndonesianStakeholderSeeder.php` (method `stakeholderRoster()`).
Setiap karyawan memiliki:
- `employee_number` urutan `EMP-0001` … `EMP-0121`
- Email otomatis `<first>.<last>@quantara.id`
- NIK 16 digit ter-encrypt + hash
- NPWP 15 digit ter-encrypt
- BPJS Kesehatan & TK number generated
- Bank BCA + nomor rekening 10 digit ter-encrypt
- PTKP status realistis (TK0 – K3)
- Salary sesuai job grade band

### Bootstrap Admin (Akun darurat)

| Email | Password | Catatan |
| --- | --- | --- |
| `admin@quantara.id` | `Quantara@2026` | Super admin tanpa employee. Aman kalau Direktur Utama lupa password. |

---

## 4. Peran dalam Skenario Bisnis

| Skenario HRIS | Aktor utama | Path eksekusi |
| --- | --- | --- |
| **Manpower Planning Plan (MPP) tahunan** | CHRO `direktur.sdm@quantara.id` → CEO `direktur.utama@quantara.id` approve | `MppList → MppDetail` |
| **Job posting + portal kandidat** | Recruitment Manager `anita.rahayu@quantara.id` | `Recruitment → PipelineBoard` |
| **Online test screening** | Recruiter `dani.firmansyah@quantara.id` (senior) | `OnlineTests → assign` |
| **Convert hired → onboarding** | HR Operations Manager `yuni.astuti@quantara.id` | `Recruitment → Hire → OnboardingPlan` |
| **Cuti tahunan** (Staff request, Mgr approve) | `mira.maharani@quantara.id` → `anita.rahayu@quantara.id` | `Leaves → request → Mgr approve` |
| **Lembur weekend** | `hadi.pranata@quantara.id` (BE Dev) → SPV `arif.hidayat@quantara.id` | `Overtimes → request` |
| **Reimbursement medical** | Staff → Atasan → Finance `andi…` (FIN-TRS) | `Reimbursement` |
| **Loan/kasbon** | Staff → Atasan → CFO | `Loan` |
| **Payroll bulanan + PPh 21 TER** | Payroll Manager `yuni.astuti@quantara.id` | `Payroll → Period → calculate → finalize` |
| **THR Idul Fitri** | C&B Manager `hendrawan.lubis@quantara.id` | `Payroll → ThrPlanning` |
| **SP / Disciplinary action** | IR Manager `sutrisno.ismail@quantara.id` | `Disciplinary` |
| **Resignation / clearance** | Staff resign → Atasan → HR Ops → Paklaring | `Resignation → Termination → ExitInterview` |
| **Performance review siklus** | CHRO buka cycle → semua karyawan + atasan isi → calibration | `Performance → Cycles` |
| **Talent succession plan** | CHRO `ratna…` + CEO | `HR/SuccessionPlanning` |
| **Pulse survey** | CHRO buka, semua karyawan jawab anonim | `HR/PulseSurvey` |
| **Audit & compliance** | Komisaris Independen `diah.pertiwi…` (`internal_auditor`) | `HR/ComplianceReports` |
| **Cost center & budget** | CFO + GM Finance | `Master → CostCenters` |

---

## 5. Cara Pakai

### Reset penuh (drop & build ulang dari nol)

```bash
cd backend
php spark migrate:rollback -b 0      # drop semua tabel (opsional)
php spark migrate                    # rebuild schema
php spark db:seed InitialSeeder      # roles, permissions, PTKP/TER
php spark db:seed IndonesianMastersSeeder
php spark db:seed IndonesianStakeholderSeeder
```

### Ganti dataset existing tanpa drop tabel

```bash
cd backend
php spark db:seed IndonesianStakeholderSeeder
```

Seeder ini sudah idempotent + safe-overwrite: men-`TRUNCATE` semua tabel
operasional (employees, users, candidates, attendances, leaves, payroll,
dst.), lalu rebuild dari roster default.

> **Yang dipertahankan**: `roles`, `permissions`, `role_permissions`, `ptkp_rates`,
> `ter_rates`, `pph21_brackets`, `regional_minimum_wages`, `holidays`, `migrations`.

### Verifikasi hasil

```bash
cd backend
php scripts/verify-stakeholder-seed.php
```

Output mencantumkan row counts, top stakeholder + atasan, dan kepala
departemen ter-link.

---

## 6. Fitur yang Sudah Mendukung Simulasi Penuh

Sistem HRIS Quantara saat ini mendukung end-to-end skenario di bawah ini
dengan dataset di atas:

- **Recruitment + ATS:** Job posting, kandidat portal, online test (TPA/English/DISC), pipeline 7 stage, scoring, offer, hire-to-onboarding.
- **Onboarding & Probation:** Task template, deadline tracker, evaluasi end-of-probation.
- **Attendance:** Geofence clock-in, QR/face placeholder, anti-cheat events, koreksi, request change.
- **Shift roster:** 4 shift default + pola 5-2 / 4-2 / 3-shift di RosterSeeder.
- **Leave management:** 10 jenis cuti sesuai UU 13/2003 + UU 6/2023, balance reset tahunan, carry-over cap.
- **WFH request:** Approval chain + integrasi attendance.
- **Overtime:** Plan + request + auto-calculation (PP 35/2021).
- **Reimbursement & loan:** OCR receipt + multi-step approval.
- **Business trip:** SPPD + ekspedisi expense.
- **Payroll:** PPh 21 TER (PMK 168/2023), BPJS 5 program, THR, slip gaji.
- **Bonus engine:** Pool + rule (per company, per dept, per individual).
- **Performance:** OKR/KPI, peer review, calibration, IDP cascade.
- **Talent management:** Competency framework, succession plan, talent marketplace.
- **Disciplinary:** SP1/SP2/SP3 + termination workflow.
- **Resignation & paklaring:** Auto-generate paklaring + clearance checklist.
- **Compliance reporting:** WLKP, BPJS report, PPh 21 1721-A1.
- **Cost center:** Multi-level + budget + variance analysis.
- **Approval engine:** Chain + delegation + condition + SLA escalation.
- **Notifications:** Email, WhatsApp, in-app.
- **AI copilot:** Chat assistant (Horizon 4).
- **Pulse survey:** Anonim, sentiment aggregate.
- **Workforce simulation:** Scenario forecasting.
- **Compensation intel:** Benchmark gaji.

---

## 7. Fitur yang Masih Direkomendasikan (Gap untuk simulasi 100%)

Beberapa skenario masih membutuhkan tambahan supaya simulasi end-to-end
sempurna. Listing di bawah disusun by priority.

### 7.1 — High priority (block skenario besar)

#### a. **Annual GMS / RUPS approval flow**
- **Konteks:** UU 40/2007 mewajibkan keputusan strategis (perubahan struktur
  Direksi, persetujuan laporan tahunan, dividen) lewat Rapat Umum Pemegang
  Saham. Sistem belum punya entry point untuk Komisaris menyetujui agenda
  tertentu (mis. *promote* karyawan ke level Direksi).
- **Saran implementasi:**
  - Tabel `gms_resolutions` (id, agenda, attachments, status: DRAFT/PENDING/APPROVED/REJECTED, approval line dari Komisaris Utama).
  - Approval chain `GMS` baru di `approval_chains` dengan `approver_type = SPECIFIC_EMPLOYEE` (Komisaris Utama).
  - UI: `frontend/src/views/Governance/GmsResolutions.vue`.

#### b. **PKB / Collective Labor Agreement digital**
- **Konteks:** Sebagian besar PT Indonesia punya PKB yang harus didaftarkan
  ke Disnaker tiap 2 tahun. Saat ini hanya `company_regulations` (klausul
  generik), belum ada konsep PKB versi + masa berlaku + persetujuan serikat.
- **Saran:** Extend `company_regulations` dengan kolom `is_pkb` + tabel
  `pkb_revisions` (version, signed_at, file_path, valid_from, valid_to).

#### c. **Audit trail untuk akses Komisaris/Auditor**
- **Konteks:** Internal auditor (Komisaris Independen) butuh akses
  read-only ke seluruh modul, tapi setiap akses harus ter-log. Saat ini
  `audit_logs` mencatat write actions saja.
- **Saran:** Tambah middleware `LogReadAccess` untuk role `internal_auditor`
  + view dashboard `HR/AuditTrail.vue`.

#### d. **Dashboard executive (CEO/Komisaris)**
- **Konteks:** Direksi & Komisaris butuh executive dashboard rangkuman
  (headcount, turnover, payroll cost, P&L per cabang, OKR completion).
- **Saran:** `frontend/src/views/Dashboard/ExecutiveDashboard.vue` dengan
  endpoint baru `/api/v1/dashboard/executive` yang aggregate dari berbagai
  service.

### 7.2 — Medium priority

#### e. **Multi-level approval untuk salary adjustment / promosi**
- **Konteks:** Promosi level Manager → VP biasanya butuh approval CFO
  (budget) + CHRO (talent) + CEO (final). Saat ini `JobInformationChange`
  hanya 1-step approval.
- **Saran:** Hubungkan `job_information_changes` ke `approval_chains`
  module `GENERIC` dengan threshold by job_grade.

#### f. **Komite (committee) management**
- **Konteks:** PT besar punya komite Audit, Remunerasi, Nominasi yang
  beranggotakan Komisaris + eksternal. Belum ada konsep di sistem.
- **Saran:** Tabel `committees` + `committee_members` (employee_id boleh null untuk non-employee).

#### g. **Stock option / ESOP**
- **Konteks:** Direksi & Senior management sering punya ESOP. Tidak ada
  sekarang.
- **Saran:** Tabel `stock_grants` (employee_id, grant_date, vest_schedule,
  strike_price). UI: `Compensation/StockOptions.vue`.

#### h. **Per-grade approval threshold matrix**
- **Konteks:** Saat ini approval threshold per nominal hard-coded di
  service. Lebih baik configurable per role.
- **Saran:** Extend `approval_chain_steps.condition_value` dengan UI matrix
  (mis. reimbursement >5jt butuh CFO approve).

### 7.3 — Low priority (polish)

#### i. **Visualisasi org chart interaktif**
- **Saran:** `frontend/src/views/Organization/OrgChart.vue` dengan library
  `vue3-organization-chart`. Endpoint sudah tersedia
  (`/api/v1/employees?include=manager`).

#### j. **Letter generator (Surat Tugas, SK, dll)**
- **Konteks:** Sudah ada `contract_templates` tapi terbatas ke kontrak
  kerja. Surat Tugas, SK Mutasi, SK Promosi belum ada template.
- **Saran:** Extend `contract_templates.contract_type` ENUM dengan
  `LETTER_TASK`, `LETTER_PROMOTION`, `LETTER_TRANSFER`, `LETTER_WARNING`.

#### k. **Multi-currency untuk anak perusahaan luar negeri**
- **Konteks:** PT Tbk sering punya anak usaha LN. `companies.currency`
  sudah ada, tapi service payroll belum currency-aware.
- **Saran:** Audit semua service payroll untuk currency awareness.

#### l. **Dashboard untuk Komisaris Independen**
- **Saran:** `frontend/src/views/Governance/AuditDashboard.vue` (GCG
  metrics, related-party transactions, dst.).

---

## 8. Catatan Keamanan

1. **Password default** `Quantara@2026` mengandung huruf besar/kecil/angka/symbol — memenuhi policy `must_change_password=1`. Saat user pertama kali login, sistem akan mem-force ganti password.
2. **NIK & NPWP** ter-encrypt dengan `Encrypter` CodeIgniter (key di `app/Config/Encryption.php`). Hash SHA-256 disimpan di `nik_hash` untuk lookup tanpa decrypt.
3. **Bank account** ter-encrypt. `bank_account_holder` plaintext untuk verifikasi visual saat HR membuat pembayaran.
4. **Audit log** otomatis tercatat untuk operasi write di setiap modul (lihat `app/Filters/AuditFilter.php`).

---

## 9. Maintenance & Customization

### Mengubah daftar stakeholder

Edit method `stakeholderRoster()` di
`backend/app/Database/Seeds/IndonesianStakeholderSeeder.php`. Field per row:

```php
[
    'code'     => 'EMP-0XYZ',          // unique employee_number
    'first'    => 'NamaDepan',
    'last'     => 'NamaBelakang',
    'gender'   => 'M' | 'F',
    'religion' => 'Islam' | 'Kristen' | 'Katolik' | 'Hindu' | 'Buddha' | 'Konghucu',
    'ptkp'     => 'TK0'..'K3',
    'pos'      => 'POS-XXX',           // FK ke positions.code
    'branch'   => 'JKT'|'SBY'|'BDG'|'MDN'|'MKS',
    'manager'  => 'EMP-0XYZ' | null,   // atasan langsung (employee_number)
    'salary'   => 7500000,             // basic salary IDR
    'role'     => 'super_admin'|'ceo'|'cfo'|'hr_director'|'recruiter'|...,
],
```

### Menambah position baru

Edit array `$rows` di method `seedPositions()`. Tier menentukan auto-resolve direct report:
- `MANAGER` (paling tinggi di departemennya)
- `ASST_MANAGER`
- `SUPERVISOR`
- `STAFF`

### Menambah departemen baru

Edit `$tops` (top-level direktorat) atau `$subs` (sub-departemen) di
`seedDepartments()`. Pastikan `parent_id` mapping benar.

---

## 10. Referensi

- `backend/app/Database/Seeds/IndonesianStakeholderSeeder.php` — implementasi seeder.
- `backend/scripts/verify-stakeholder-seed.php` — script verifikasi.
- `backend/app/Database/Migrations/2024-01-01-000047_SeedEnterpriseRoles.php` — daftar role enterprise.
- `backend/app/Database/Migrations/2024-01-01-000010_EnhanceEmployeeAndApprovalAndContract.php` — kolom KTP, mother_name, supervisor_id.
- UU 40/2007 tentang Perseroan Terbatas
- UU 13/2003, UU 6/2023 (Cipta Kerja), PP 35/2021 — ketenagakerjaan
- PMK 168/2023 — TER PPh 21