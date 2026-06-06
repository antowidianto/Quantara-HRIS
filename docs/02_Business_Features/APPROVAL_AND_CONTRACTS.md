# Approval Engine & Contract Generator

Dokumen ini menjelaskan tiga fitur baru yang saling melengkapi:

1. **Lokasi Kerja granular** (work-locations) — di luar struktur cabang.
2. **Approval Engine** — alur persetujuan multi-step yang konfigurable.
3. **Contract Templates & Generator** — template kontrak HTML dengan
   placeholder & klausul peraturan perusahaan.

---

## 1. Lokasi Kerja (`work_locations`)

Tabel `work_locations` melengkapi `branches` dengan tipe yang lebih spesifik:

| type            | use case                          |
| --------------- | --------------------------------- |
| HEAD_OFFICE     | Kantor pusat                      |
| BRANCH_OFFICE   | Cabang                            |
| SUB_OFFICE      | Sub-cabang                        |
| PROJECT_SITE    | Lokasi proyek konstruksi/lapangan |
| CLIENT_OFFICE   | Penempatan di kantor klien        |
| WAREHOUSE       | Gudang                            |
| FACTORY         | Pabrik                            |
| REMOTE / WFH    | Tanpa kantor fisik                |
| OTHER           | Sisanya                           |

Setiap lokasi punya koordinat & geofence sendiri, jadi flag
`allow_clock_here` dipakai validasi clock-in.

Penugasan karyawan disimpan di `employee_work_locations`. Tipe penugasan:
`PRIMARY`, `SECONDARY`, `TEMPORARY`, `PROJECT`, `REMOTE`. Saat menyimpan
penugasan PRIMARY baru, sistem otomatis menutup penugasan PRIMARY sebelumnya
(set `date_to`) dan memperbarui `employees.work_location_id`.

### Endpoint

```
GET    /api/v1/work-locations              # paginate, filter type & branch
POST   /api/v1/work-locations
GET    /api/v1/work-locations/{id}
PUT    /api/v1/work-locations/{id}
DELETE /api/v1/work-locations/{id}

GET    /api/v1/employees/{id}/work-locations           # riwayat penugasan
POST   /api/v1/employees/{id}/work-locations           # tugaskan
```

### UI

Menu **Master Data → Lokasi Kerja**.

---

## 2. Approval Engine

Alur persetujuan kini bisa didefinisikan per perusahaan, per modul, dan per
segmen karyawan (departemen, cabang, job grade).

### Konsep

```
approval_chains
 └── approval_chain_steps   (urut step_order, dengan tipe approver)
 └── approval_assignments   (rule: chain X berlaku untuk dept/branch/grade)

approval_requests
 └── approval_decisions     (jejak setiap step + keputusan)
```

#### Modul yang didukung

`LEAVE`, `OVERTIME`, `ATTENDANCE_CHANGE`, `REIMBURSEMENT`, `LOAN`, `GENERIC`.

#### Tipe approver per step

`SUPERVISOR`, `MANAGER`, `DEPARTMENT_HEAD`, `BRANCH_HEAD`, `HR`, `FINANCE`,
`ROLE`, `SPECIFIC_USER`, `SPECIFIC_EMPLOYEE`.

#### Strategi fallback

Saat approver pada satu step tidak ditemukan (mis. supervisor belum di-set):

- `SKIP` — lewati step.
- `BLOCK` — request ditahan, butuh konfigurasi approver baru.
- `HR_FALLBACK` (default) — limpahkan ke HR.

#### Conditional steps

Setiap step bisa punya kondisi numerik berdasarkan field di `meta`:
mis. `amount > 1000000` → step Finance hanya aktif jika nominal di atas 1jt.

### Cara kerja runtime

1. Saat user submit Cuti / Lembur / dst, controller memanggil
   `ApprovalEngine::startRequest($module, $sourceTable, $sourceId, $employeeId, $meta)`.
2. Engine pilih chain yang paling cocok lewat `approval_assignments` (priority
   terendah menang). Jika tak ada assignment khusus → pakai chain `is_default`.
3. Engine resolve approver per step (skip yang non-applicable atau bertepatan
   dengan pemohon jika `allow_skip_if_self`), buat baris `approval_requests`
   dan `approval_decisions` dengan status `PENDING`.
4. Approver melihat permintaan di **/approvals/inbox**. Saat ia klik
   Setujui/Tolak, controller memanggil `ApprovalEngine::approve(...)`/
   `reject(...)`. Engine lanjut step berikut atau finalkan request.
5. Saat finalisasi, controller modul (LeaveController, OvertimeController, …)
   mengupdate state entitas asli (`leaves.status` → APPROVED/REJECTED, dll.).

### Endpoint

```
GET    /api/v1/approval-chains                    # ?module=
POST   /api/v1/approval-chains                    # body: { code, name, module, steps:[...] }
GET    /api/v1/approval-chains/{id}               # termasuk steps & assignments
PUT    /api/v1/approval-chains/{id}
DELETE /api/v1/approval-chains/{id}

GET    /api/v1/approval-assignments               # ?module=
POST   /api/v1/approval-assignments               # { chain_id, module, department_id?, branch_id?, job_grade_id?, priority }
DELETE /api/v1/approval-assignments/{id}

GET    /api/v1/approval-requests/inbox            # ?module=
GET    /api/v1/approval-requests/{id}             # detail + decisions
POST   /api/v1/approval-requests/{id}/approve     # { note }
POST   /api/v1/approval-requests/{id}/reject      # { note } — note WAJIB
```

### UI

- **Master Data → Konfigurasi Approval** (`/master/approval-chains`):
  drag-style step builder, panel assignment rules.
- **Saya → Inbox Approval** (`/approvals/inbox`):
  list di kiri, detail + tombol Setujui/Tolak di kanan.

### Hook di Leave & Overtime

`LeaveController::create` & `OvertimeController::create` otomatis memanggil
`ApprovalEngine::startRequest`. `approve` / `reject` memanggil engine, lalu
mengupdate row asal saat approval engine memberitahu request final.

Jika tidak ada chain yang cocok, sistem fallback ke perilaku lama (single
approver: HR atau atasan langsung).

---

## 3. Contract Templates & Generator

Template kontrak adalah HTML yang dapat diparameterisasi dengan placeholder
`{{path.to.field}}`. Saat di-generate untuk seorang karyawan, sistem
melakukan substitusi dan menyimpan **snapshot HTML** ke
`generated_contracts.rendered_html` agar dokumen yang sudah dirilis tidak
ikut berubah saat template diedit nanti.

### Tipe kontrak

`PKWT`, `PKWTT`, `PROBATION`, `PKHL`, `MAGANG`, `OUTSOURCING`, `GENERIC`.

### Placeholder yang tersedia

Diperoleh dinamis dari `ContractRenderer::availableVariables()`. Beberapa
contoh penting:

| placeholder                       | isi                              |
| --------------------------------- | -------------------------------- |
| `{{employee.full_name}}`          | Nama lengkap                     |
| `{{employee.nik}}`                | NIK karyawan                     |
| `{{employee.ktp_number}}`         | Nomor KTP                        |
| `{{employee.npwp}}`               | NPWP                             |
| `{{position.name}}`               | Jabatan                          |
| `{{department.name}}`             | Departemen                       |
| `{{branch.name}}`                 | Cabang                           |
| `{{work_location.name}}`          | Lokasi kerja primer              |
| `{{contract.start_date}}`         | Mulai kontrak (jika diberikan)   |
| `{{contract.end_date}}`           | Akhir kontrak                    |
| `{{salary.basic}}`                | Gaji pokok                       |
| `{{company.name}}`                | Nama PT                          |
| `{{today}}`                       | Tanggal hari ini                 |
| `{{regulations}}`                 | HTML dari semua peraturan aktif  |
| `{{regulations.LEAVE}}`           | HTML dari peraturan kategori cuti|

### Filter

Pipa `|` setelah variabel mentransformasi nilai:

| filter        | contoh                                     |
| ------------- | ------------------------------------------ |
| `currency`    | `{{salary.basic|currency}}` → `Rp 6.500.000` |
| `terbilang`   | `{{salary.basic|terbilang}}` → "enam juta lima ratus ribu rupiah" |
| `date_id`     | `{{contract.start_date|date_id}}` → `1 Januari 2026` |
| `upper`       | huruf besar                                |
| `lower`       | huruf kecil                                |
| `title`       | Title Case                                 |

### Endpoint

```
GET    /api/v1/contract-templates                   # ?contract_type=
GET    /api/v1/contract-templates/variables         # daftar placeholder & filter
POST   /api/v1/contract-templates
GET    /api/v1/contract-templates/{id}
PUT    /api/v1/contract-templates/{id}
DELETE /api/v1/contract-templates/{id}
POST   /api/v1/contract-templates/{id}/preview      # { employee_id, employee_contract_id? }
POST   /api/v1/contract-templates/{id}/generate     # { employee_id, subject?, contract_number? }

GET    /api/v1/generated-contracts                  # paginate, ?status= ?employee_id=
GET    /api/v1/generated-contracts/{id}
GET    /api/v1/generated-contracts/{id}/html        # full HTML printable
PUT    /api/v1/generated-contracts/{id}/status      # { status: DRAFT|SENT|SIGNED|REVOKED }
DELETE /api/v1/generated-contracts/{id}
```

### Peraturan Perusahaan

Tabel `company_regulations` menyimpan klausul/pasal yang aktif. Kategorinya:
`GENERAL, WORKING_HOURS, LEAVE, SALARY, OVERTIME, DISCIPLINE, RESIGNATION,
CONFIDENTIALITY, INTELLECTUAL_PROPERTY, BENEFITS, TERMINATION, OTHER`.

Saat HTML kontrak mengandung placeholder `{{regulations}}`, `ContractRenderer`
membangun HTML berurut sesuai `category` & `order_no` dari klausul aktif.
Bila template `include_regulations = 1` tapi placeholder tidak ada, peraturan
otomatis ditambahkan di akhir body.

### Endpoint Peraturan

```
GET    /api/v1/regulations                          # paginate
GET    /api/v1/regulations/preview                  # ?category= → { html }
POST   /api/v1/regulations
GET    /api/v1/regulations/{id}
PUT    /api/v1/regulations/{id}
DELETE /api/v1/regulations/{id}
```

### UI

- **Master Data → Peraturan Perusahaan** (`/master/regulations`):
  editor klausul dengan kategori, urutan, & tanggal berlaku.
- **Master Data → Template Kontrak** (`/master/contract-templates`):
  full-screen editor (Body / Header / Footer / Settings / Preview tab) +
  side panel daftar variabel klikable.
- **HR → Kontrak Karyawan** (`/employees/contracts`):
  daftar kontrak yang sudah digenerate. Klik mata = buka HTML printable di
  tab baru, ikon pesawat = mark as SENT, ikon medali = mark as SIGNED, ikon
  larangan = REVOKED.

### Field tambahan di Employee

Migration `000010` menambahkan kolom KTP/keluarga & approval di `employees`:

`ktp_number`, `ktp_address`, `kk_number`, `mother_name`, `marital_status`,
`number_of_children`, `religion`, `bpjs_kesehatan_no`, `bpjs_tk_no`,
`work_location_id`, `supervisor_employee_id`, `manager_employee_id`,
`hr_user_id`, `finance_user_id`, plus `npwp`, `npwp_address`, `bank_name`,
`bank_account_no`, dst.

`EmployeeModal` punya tab tambahan untuk mengelola data tersebut, plus tab
Approval & Kontrak (di sana HR bisa langsung men-generate kontrak baru
dari template untuk karyawan yang dibuka).

---

## Seeder & Demo Data

Jalankan ulang seeder agar data master baru terisi:

```bash
cd backend
php spark migrate
php spark db:seed SeedAll
```

`ApprovalAndContractSeeder` mengisi:

- Sebuah chain default per modul (LEAVE, OVERTIME, ATTENDANCE_CHANGE) berisi
  step Atasan → HR.
- 3 klausul peraturan contoh (Working Hours, Leave, Confidentiality).
- 1 template kontrak PKWT lengkap dengan placeholder + `{{regulations}}`.

---

## Audit Trail

Semua aksi (create/update/delete chain, regulation, template; approve/reject
request; generate contract) otomatis dicatat lewat `AuditLogger`. Cari di
**Admin → Audit Log** dengan filter modul `MASTER`, `APPROVAL`, atau
`EMPLOYEE` untuk jejaknya.