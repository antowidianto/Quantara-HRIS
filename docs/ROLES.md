# Role Catalog — HRIS Enterprise

Dokumen ini menjelaskan struktur role yang tersedia di sistem, lengkap
dengan posisi setara di organisasi perusahaan menengah-besar Indonesia,
permission yang dimiliki, dan rekomendasi siapa yang sebaiknya di-assign
ke role tersebut.

## Konvensi

- Role dengan `is_system = 1` adalah role sistem yang **tidak boleh dihapus**
  via UI. Permission-nya boleh di-customize per company di Settings → Roles.
- Role dengan `company_id != NULL` adalah role custom milik tenant tertentu
  (HR Admin tiap perusahaan boleh bikin sendiri kalau struktur berbeda).
- Permission menggunakan format `{module}.{action}` (mis. `leave.approve`).
  Cek `InitialSeeder` + migration permission untuk daftar lengkap.

## Daftar Role Sistem

### A. Role Dasar (dari `InitialSeeder`)

Role-role ini sudah ada sejak Sprint 1, tetap dipertahankan untuk
backward compatibility dengan data lama.

| ID | Slug | Nama | Skenario Pakai |
|----|------|------|----------------|
| 1  | `super_admin` | Super Admin | Operator platform / vendor SaaS. Akses semua tenant. |
| 2  | `hr_admin`    | HR Admin    | HR generalis di perusahaan kecil-menengah (< 200 karyawan) yang merangkap semua fungsi HR. |
| 3  | `manager`     | Manager     | Manager line tradisional. Setara `department_head` versi simpel. |
| 4  | `employee`    | Employee    | Karyawan biasa. Self-service untuk absensi, leave, OT, payslip. |
| 5  | `finance`     | Finance     | Tim finance generalis. Setara `compensation_benefits` + `payroll_admin` digabung. |

### B. Role Enterprise (dari migration `000047_SeedEnterpriseRoles`)

Untuk perusahaan menengah-besar (1000+ karyawan, multi-cabang,
regulated industry seperti manufaktur, BUMN, financial services), gunakan
role-role spesialis berikut.

#### B.1 Executive / C-Level

| ID | Slug | Posisi Setara | Lihat | Edit / Approve |
|----|------|---------------|-------|----------------|
| 10 | `ceo` | Direktur Utama, Presiden Direktur | Semua data agregat (employee, attendance, payroll, performance). Read-only di sebagian besar modul. | Approve manpower planning final, approve bonus run, approve career strategy. |
| 11 | `cfo` | Direktur Keuangan | Semua data finance: payroll, reimbursement, loan, bonus, severance, audit trail. | Final approver payroll & finalize period. Approver tertinggi reimbursement, loan, business trip, OT. Owner dari budget training. |
| 12 | `hr_director` | CHRO, HR Director | Seluruh data HR & payroll. | Approve disciplinary, severance, transfer, JIC (job info change), dan kebijakan HR (regulation, contract template). Manage employee penuh. |

#### B.2 HR Specialists

| ID | Slug | Posisi Setara | Skenario |
|----|------|---------------|----------|
| 13 | `hr_business_partner` | HRBP | HR strategic per business unit / divisi. Mediator antara manajemen & karyawan, owner performance review, disciplinary, dan recruitment di unit-nya. |
| 14 | `recruiter` | Talent Acquisition Specialist | Spesialis rekrutmen end-to-end: post lowongan via career portal, seleksi, onboarding pre-employment, hingga hire. Tidak akses payroll/performance. |
| 15 | `learning_development` | L&D Specialist, Training Manager | Manage program training, sertifikat, budget pelatihan, registrasi peserta. Read-only ke performance untuk gap analysis. |
| 16 | `compensation_benefits` | C&B Specialist, Remuneration Manager | Design struktur gaji, bonus scheme, allowance, BPJS rate, PPh21 setting. Tidak finalize payroll, tapi setup formula. |

#### B.3 Operational Payroll & Finance

| ID | Slug | Posisi Setara | Skenario |
|----|------|---------------|----------|
| 17 | `payroll_admin` | Payroll Officer, Payroll Administrator | Operasional bulanan: kalkulasi payroll, generate payslip, distribusi BPJS, e-bupot PPh21. Prepare untuk approval Finance Manager / CFO, tidak finalize sendiri. |

#### B.4 Line Management (Operasional)

| ID | Slug | Posisi Setara | Scope |
|----|------|---------------|-------|
| 18 | `branch_manager` | Kepala Cabang, Site Manager, Plant Manager | Multi-departemen di satu cabang/site. Approve attendance, leave, OT, business trip, reimbursement, loan. |
| 19 | `department_head` | Kepala Departemen, Manager Departemen | Single departemen lintas cabang (mis. Engineering, Sales). Approve permintaan tim, owner KPI departemen. |
| 20 | `supervisor` | Supervisor, Team Lead, Shift Leader | Front-line. Approve attendance harian & OT tim kecil (5-15 orang). Tidak akses agregat HR/payroll. |

#### B.5 Compliance & Risk

| ID | Slug | Posisi Setara | Skenario |
|----|------|---------------|----------|
| 22 | `internal_auditor` | Internal Auditor, SPI | **Read-only** lintas seluruh modul (audit trail, payroll, attendance, finance). Tidak boleh edit apa pun. Untuk SOX/ISO/SPI compliance review. |
| 23 | `legal_compliance` | Legal Officer, Compliance Officer | Manage contract template, peraturan perusahaan (regulation), review disciplinary & severance. Penjaga compliance UU Ketenagakerjaan, UU PPh, UU PDP. |
| 24 | `data_privacy_officer` | DPO, Privacy Officer | Pelindung data pribadi sesuai UU PDP (UU 27/2022). Akses ke privacy module: consent management, data retention, audit access ke PII. Tidak edit data operasional. |

#### B.6 IT & System

| ID | Slug | Posisi Setara | Skenario |
|----|------|---------------|----------|
| 21 | `it_admin` | IT System Administrator, Sysadmin | Manage user accounts, mesin absen (devices), webhook integration, security settings. **Tidak akses data HR atau payroll personal**. Manage asset IT (laptop, device karyawan). |

## Mapping Role ke Approval Workflow

Role-role ini siap dipakai di modul `approval_chains` (sprint 8+).
Contoh konfigurasi praktik umum:

### Leave Approval (cuti)
```
Step 1  → Supervisor (level 1)
Step 2  → Department Head (level 2, kalau cuti > 3 hari)
Step 3  → HR Business Partner (level 3, kalau cuti > 7 hari atau cuti besar)
```

### Reimbursement Approval
```
Step 1  → Department Head (jika < 5jt)
Step 2  → Finance / CFO (jika ≥ 5jt atau melibatkan business trip)
```

### Bonus Run Approval
```
Calculator → Compensation & Benefits (`bonus.manage`)
Approver   → CFO atau HR Director (`bonus.approve`)
```

### Manpower Planning (MPP)
```
Submitter   → HR Business Partner / Department Head
Approver L1 → Department Head (kalau bukan submitter)
Approver L2 → HR Director
Final       → CEO (untuk role strategic atau senior C-level positions)
```

### Severance / PHK
```
Initiator → HR Business Partner
Reviewer  → Legal & Compliance
Approver  → HR Director + CFO (joint approval)
```

## Kapan Pakai Role Apa?

### Skenario: Perusahaan kecil-menengah (< 200 karyawan)
Cukup pakai role dasar: `hr_admin`, `manager`, `employee`, `finance`.
HR Admin merangkap recruiter, comp & ben, payroll.

### Skenario: Perusahaan menengah (200-1000 karyawan)
Mulai pisahkan fungsi HR:
- `hr_admin` → split ke `hr_business_partner` (data) dan
  `compensation_benefits` (payroll & bonus setup)
- Tambah `recruiter` kalau ada tim TA terpisah
- `branch_manager` untuk yang multi-cabang

### Skenario: Perusahaan besar / enterprise (1000+ karyawan)
Adopsi struktur lengkap:
- C-level: `ceo`, `cfo`, `hr_director`
- HR specialist: `hr_business_partner`, `recruiter`,
  `learning_development`, `compensation_benefits`, `payroll_admin`
- Line management: `branch_manager`, `department_head`, `supervisor`
- Compliance: `internal_auditor`, `legal_compliance`, `data_privacy_officer`
- IT: `it_admin`

## Cara Apply

Migration `2024-01-01-000047_SeedEnterpriseRoles` sudah idempotent.
Jalankan satu kali:

```bash
cd backend
php spark migrate
```

Atau via Docker dev compose:
```bash
docker compose exec backend php spark migrate
```

Setelah migrate:
1. Buka **Settings → Roles** (perlu permission `role.manage`)
2. Verifikasi 15+ role terdaftar (5 dasar + 15 enterprise)
3. Customize permission per role kalau perlu (mis. nonaktifkan
   `attendance.view_all_branches` dari `branch_manager` kalau scope
   harus strict ke 1 cabang)
4. Assign user ke role yang sesuai via **Users → Edit User → Role**

## Customization

Setiap perusahaan boleh bikin role custom (`is_system = 0`,
`company_id = {tenant_id}`) lewat UI atau seeder per-tenant.
Contoh role custom yang umum:

- `regional_manager` (untuk perusahaan retail multi-provinsi)
- `factory_supervisor` (manufacturing)
- `branch_finance` (split finance dari HQ)
- `intern_supervisor` (program magang)

Tinggal copy permission set dari role terdekat (mis. `supervisor`)
sebagai baseline, lalu add/remove sesuai kebutuhan.

## Catatan Compliance

- **UU 13/2003 Pasal 81–93** (cuti, izin) → owner: `hr_director`,
  `hr_business_partner`. Permission: `leave.*`.
- **UU PPh 36/2008 + PMK 168/2023 (TER)** → owner: `compensation_benefits`,
  `payroll_admin`. Permission: `payroll.*`, `settings.manage`.
- **UU 27/2022 (PDP)** → owner: `data_privacy_officer`, `legal_compliance`.
  Permission: privacy module + `audit.view`.
- **PP 35/2021 (PHK)** → owner: `hr_director`, `legal_compliance`.
  Permission: `severance.*`, `disciplinary.*`.
- **SOX / ISO 27001** → owner: `internal_auditor`. Read-only mandatory,
  tidak boleh ada permission write/approve di role ini.