# Attendance — Kalender Lampau/Mendatang & Pengajuan Perubahan

Iterasi ini menambahkan tiga hal utama pada modul Absensi:

1. **Tampilan kalender** untuk karyawan yang menggabungkan tanggal lampau,
   hari ini, dan tanggal mendatang dalam satu view, lengkap dengan jadwal
   shift, hari libur, cuti, dan status absen.
2. **Pengajuan perubahan absensi** dengan alur approval yang dijalankan oleh
   HR/Manager. Karyawan bisa mengajukan koreksi clock-in/out, perubahan
   status (mis. Sakit), atau melaporkan absen yang terlewat.
3. **Filter akses ketat berbasis cabang & perusahaan**, sehingga HR satu
   cabang tidak bisa cross-check ke cabang lain (kecuali diberi permission
   `attendance.view_all_branches`), dan tentu saja tidak bisa cross-check ke
   perusahaan lain.

## Permission Baru

| slug | Diberikan ke | Fungsi |
|---|---|---|
| `attendance.view_all_branches` | HR Admin (default), Super Admin | Boleh melihat absensi lintas cabang dalam company yang sama |
| `attendance.request_change` | Employee, Manager | Boleh mengajukan perubahan absensi |
| `attendance.approve_change` | HR Admin, Manager | Boleh menyetujui/menolak pengajuan perubahan |

> Manager **tidak** mendapat `attendance.view_all_branches` secara default,
> jadi mereka hanya bisa approve untuk anggota cabangnya sendiri. Boleh
> ditambahkan via UI Role kalau perusahaan ingin Manager pusat.

## Skema Data

Tabel baru `attendance_change_requests`:

```sql
id, company_id, branch_id, employee_id, attendance_id, work_date,
request_number, change_type (CLOCK_IN|CLOCK_OUT|BOTH|STATUS|MISSED),
current_clock_in, current_clock_out, current_status,
requested_clock_in, requested_clock_out, requested_status,
reason, attachment_path,
status (PENDING|APPROVED|REJECTED|CANCELLED),
reviewer_id, reviewed_at, review_note,
created_at, updated_at
```

`branch_id` di-snapshot saat pengajuan dibuat sehingga audit tidak
terpengaruh ketika karyawan dipindah cabang.

## Endpoint Backend

```
GET    /api/v1/attendance/my              ?start_date=&end_date=  (default: bulan berjalan)
GET    /api/v1/attendance/calendar/{id}   (HR/Manager untuk karyawan tertentu)
GET    /api/v1/attendance                 ?branch_id=&department_id=&status=&q=

GET    /api/v1/attendance/change-requests        ?status=&branch_id=&start_date=&end_date=&mine=1
POST   /api/v1/attendance/change-requests        body: {work_date, change_type, reason, ...}
GET    /api/v1/attendance/change-requests/{id}
POST   /api/v1/attendance/change-requests/{id}/approve   body: {review_note?}
POST   /api/v1/attendance/change-requests/{id}/reject    body: {review_note}
POST   /api/v1/attendance/change-requests/{id}/cancel
```

### Aturan validasi

- `work_date` harus dalam rentang **90 hari ke belakang sampai 30 hari ke
  depan** (configurable di `AttendanceChangeRequestController::MAX_*_DAYS`).
- Hanya boleh ada satu pengajuan **PENDING** per tanggal per karyawan
  (HTTP 409 `DUPLICATE_PENDING` kalau dilanggar).
- Reviewer **tidak boleh** menyetujui pengajuannya sendiri
  (HTTP 422 `SELF_APPROVAL`).
- Reject wajib menyertakan `review_note` (HTTP 422 `REASON_REQUIRED`).

## Aturan Akses (Branch Scoping)

`TenantContext::applyAttendanceScope()` dipanggil oleh setiap endpoint
list/show. Aturannya:

| Role / Permission | Scope yang terlihat |
|---|---|
| Super Admin | seluruh perusahaan & cabang |
| `attendance.view_all_branches` | semua cabang dalam **company yang sama** |
| Tanpa permission lintas cabang | hanya **branch_id** yang sama dengan user |
| Karyawan biasa | endpoint `/my` hanya untuk dirinya sendiri |

Contoh skenario:

- HR cabang Jakarta (role HR Admin tanpa `view_all_branches`) request
  `GET /attendance?branch_id=2` → **HTTP 403 `CROSS_BRANCH_DENIED`**.
- HR pusat (`view_all_branches`) request `GET /attendance?branch_id=2` →
  diizinkan, hasilnya cabang Bandung.
- HR cabang Bandung approve pengajuan dari karyawan cabang Jakarta →
  **HTTP 403 `OUT_OF_SCOPE`** dan record tidak muncul di list-nya.

## Frontend Baru / Diperbarui

```
frontend/src/views/Attendance/MyAttendance.vue          (rewrite — kalender bulan)
frontend/src/views/Attendance/ChangeRequestModal.vue    (form pengajuan)
frontend/src/views/Attendance/MyChangeRequests.vue      (history pengajuan saya)
frontend/src/views/Attendance/ChangeRequestApprovals.vue(daftar approval HR)
frontend/src/views/Attendance/AttendanceList.vue        (rewrite — filter cabang/dept/status)
frontend/src/router/index.js                            (3 route baru)
frontend/src/layouts/AppLayout.vue                      (sidebar item baru)
frontend/src/stores/auth.js                             (simpan branch user dari /auth/me)
```

### MyAttendance (karyawan)

- Layout 3 kolom: ringkasan + kalender bulan (klik tanggal) + panel detail.
- Cell tanggal mendatang menampilkan jadwal shift (mis. "Reguler 09:00–17:00").
- Cell tanggal lampau menampilkan jam clock-in/out aktual + label status
  (Hadir / Terlambat / Tanpa Kabar / Cuti / Libur).
- Hari ini di-highlight; cell yang punya pengajuan PENDING ditandai dengan
  ⚐ + warna sesuai status.
- Tombol **"Ajukan Perubahan Absensi"** membuka modal pengajuan; otomatis
  disable kalau sudah ada PENDING untuk tanggal itu.

### Approval HR/Manager

- Halaman `/attendance/change-requests/approvals` menampilkan tabel filter
  status, cabang (kalau punya `view_all_branches`), tanggal, ID karyawan.
- Modal review: setujui (note opsional, langsung apply ke `attendances`)
  atau tolak (note wajib).

## Jejak Audit

Semua aksi dicatat di `audit_logs` dengan `module=ATTENDANCE`:

- `attendance_change_request.create` — pembuatan pengajuan.
- `attendance_change_request.approve` — persetujuan + apply ke
  `attendances` (`is_corrected=1`, `corrected_by=user_id`,
  `correction_reason=reason`).
- `attendance_change_request.reject` — penolakan dengan alasan.
- `attendance_change_request.cancel` — pembatalan oleh pemilik.

Notifikasi (in-app + email) terkirim ke approver saat pengajuan dibuat,
dan ke karyawan saat pengajuan disetujui/ditolak.

## Test Otomatis

```
php backend/scripts/test-attendance-flow.php   # smoke test schema & permission
php backend/scripts/test-attendance-api.php    # integration test controller
```

Output target: 10 + 15 = **25 assertion lulus** semua. Tes mencakup:

- Tabel & permission terbuat dengan benar.
- HR Admin / Manager / Employee mendapat permission yang sesuai.
- Kalender mengandung tanggal lampau, hari ini, dan tanggal mendatang.
- Tanggal mendatang **tidak** ditandai ABSENT.
- Owner bisa cancel pengajuannya sendiri.
- HR cabang lain ditolak saat coba approve (`OUT_OF_SCOPE`).
- HR cabang lain tidak melihat record di list endpoint.

## Migrasi

```
php spark migrate                          # menambahkan tabel attendance_change_requests
php spark db:seed InitialSeeder            # idempotent, menambahkan permission baru
```

Seeder otomatis memberikan permission baru ke HR Admin (role 2),
Manager (role 3), dan Employee (role 4) sesuai matriks di atas.