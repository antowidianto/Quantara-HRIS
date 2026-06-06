# Resignation Self-Service

Modul **Resignation** memungkinkan karyawan mengajukan surat pengunduran diri secara resmi melalui aplikasi, dengan workflow approval otomatis ke atasan langsung dan HR. Setelah disetujui, sistem otomatis mengubah status karyawan menjadi resign dan men-generate task offboarding.

Modul ini melengkapi modul Onboarding/Offboarding yang sudah ada — sebelumnya HR yang manual men-set `resign_date` dan klik "Generate offboarding tasks". Sekarang karyawan punya entry point self-service yang aman dan terdokumentasi.

## Alur

```
┌──────────────┐    ┌───────────────┐    ┌─────────────┐    ┌──────────────┐
│   Karyawan   │ →  │ PENDING_      │ →  │ PENDING_HR  │ →  │  APPROVED    │
│   Submit     │    │ SUPERVISOR    │    │             │    │              │
└──────────────┘    └───────────────┘    └─────────────┘    └──────────────┘
                          │                     │                   │
                          │                     │                   ├─ set employees.resign_date
                          │                     │                   ├─ generate offboarding tasks
                          │                     │                   ├─ notify karyawan + IT + Finance
                          │                     │
                          ▼                     ▼
                       REJECTED              REJECTED
                       
Karyawan dapat CANCEL kapanpun selama status masih PENDING_*.
HR dapat CANCEL bahkan setelah APPROVED (akan revert resign_date).
```

## Tabel & Permission

Migrasi: `2024-01-01-000028_CreateResignationRequests.php`. Membuat:

- Tabel `resignation_requests` dengan kolom:
  - Pengajuan: `last_working_date`, `effective_date`, `reason_category`, `reason_text`, `attachment_path`, `notice_period_days`
  - Workflow status: `status` (DRAFT/PENDING_SUPERVISOR/PENDING_HR/APPROVED/REJECTED/CANCELLED)
  - Approval: `supervisor_user_id`, `supervisor_action_at`, `supervisor_decision`, `supervisor_note`
  - HR review: `hr_user_id`, `hr_action_at`, `hr_decision`, `hr_note`
  - Trigger result: `offboarding_template_id`, `tasks_generated_count`, `severance_calculation_id`
  - Cancellation: `cancelled_at`, `cancelled_by`, `cancellation_reason`
- 2 permission baru, di-grant ke role `super_admin` (1) dan `hr_admin` (2):
  - `resignation.view` — lihat semua pengajuan di company
  - `resignation.manage` — approve/reject di stage HR + override cancel

## Endpoint API

Semua di-mount di group `jwt` filter (auth required).

| Method | Path | Akses | Deskripsi |
|--------|------|-------|-----------|
| GET    | `/resignations/reasons` | Semua user | Daftar 7 kategori alasan untuk dropdown UI |
| GET    | `/resignations` | Semua user | Default scope = mine. `?as=hr` untuk HR (perm `resignation.view`), `?as=supervisor` untuk atasan |
| GET    | `/resignations/{id}` | Owner / supervisor / HR | Detail pengajuan |
| POST   | `/resignations` | Karyawan | Submit pengajuan baru |
| POST   | `/resignations/{id}/approve` | Supervisor (PENDING_SUPERVISOR) atau HR (PENDING_HR) | Setujui di stage saat ini |
| POST   | `/resignations/{id}/reject` | Sama dengan approve | Tolak (note ≥ 10 char wajib) |
| POST   | `/resignations/{id}/cancel` | Owner (kalau belum APPROVED) atau HR | Batalkan |

### Validasi backend penting

- **Notice period minimum 30 hari** sesuai UU 13/2003 Pasal 162. Karyawan tidak bisa submit dengan `last_working_date < today + 30 hari`. HR bisa override dengan endpoint admin terpisah jika ada kondisi khusus (saat ini belum diekspos di UI; perlu diakses lewat manipulasi DB direct).
- **Tidak boleh ada pengajuan aktif lain**. Status `PENDING_SUPERVISOR | PENDING_HR | APPROVED` count sebagai aktif.
- **Karyawan harus `is_active=1` dan belum resign**. Tidak boleh self-submit kalau `employees.resign_date` sudah ter-set.
- Reason wajib salah satu dari: `PERSONAL`, `CAREER`, `HEALTH`, `FAMILY`, `EDUCATION`, `RETIREMENT`, `OTHER`.

### Side-effect saat HR approve

Endpoint `POST /resignations/{id}/approve` di stage `PENDING_HR` melakukan dalam satu request:

1. Update `resignation_requests.status = APPROVED` dan field audit HR.
2. Set `employees.resign_date = effective_date` (atau `last_working_date` kalau effective tidak di-set) dan `employees.resign_reason = "RESIGN_VOLUNTARY: <kategori> — <reason_text>"`. Format ini di-recognize PayrollService untuk eligibility severance.
3. Panggil `OnboardingService::applyTemplate(employee_id, offboarding_template_id, 'OFFBOARD', last_working_date)`. Template default `OFFBOARD` dipakai kalau HR tidak pilih custom.
4. Setiap task ter-generate otomatis ter-assign ke role yang sesuai (`EMPLOYEE`, `SUPERVISOR`, `HR`, `IT`, `FINANCE`) — menggunakan resolver yang sudah ada di `OnboardingService::resolveAssignee`.
5. Notifikasi ke karyawan: "Pengajuan resignation Anda disetujui".
6. Audit log severity `WARNING` dengan metadata `{ tasks_created, template_id }`.

Kalau step 3 gagal (mis. tidak ada template OFFBOARD aktif), approval **tetap sukses** — `tasks_generated_count = 0` dan response message berisi note `Gagal generate offboarding tasks otomatis...`. HR bisa retry manual lewat menu Onboarding/Offboarding board.

### Side-effect saat HR cancel APPROVED

Edge case yang perlu hati-hati: karyawan menarik resignation setelah disetujui. HR cancel akan:

1. Set `resignation_requests.status = CANCELLED`.
2. Revert `employees.resign_date = NULL` dan `employees.resign_reason = NULL`.
3. Task offboarding yang sudah ter-generate **TIDAK** di-auto-delete supaya audit trail utuh. HR harus bersihkan manual dari board kalau memang perlu.

## Frontend

- `frontend/src/views/HR/MyResignation.vue` — self-service form untuk karyawan. URL `/hr/my-resignation`. Selalu visible di sidebar group "Saya".
- `frontend/src/views/HR/ResignationReview.vue` — review board untuk HR + atasan. URL `/hr/resignations`. Visible di sidebar group "HR" untuk user dengan permission `resignation.view`.
- Tab `as=hr` vs `as=supervisor` di filter bar — backend men-scope query sesuai konteks.

## Notifikasi

Saat status berubah:

- `PENDING_SUPERVISOR`: notify supervisor (action: `resignation.pending_supervisor`)
- `PENDING_HR`: notify semua HR di company (action: `resignation.pending_hr`)
- `APPROVED`: notify karyawan (action: `resignation.approved`)
- `REJECTED`: notify karyawan (action: `resignation.rejected`)

Channel mengikuti notification preferences user (in-app default ON; email/WhatsApp/web push opt-in).

## Testing

Belum ada test script khusus. Manual testing:

1. Login sebagai karyawan biasa → buka **Pengajuan Resignation** di sidebar.
2. Pilih tanggal terakhir kerja (≥ 30 hari dari hari ini), kategori, alasan, lampiran (opsional). Submit.
3. Konfirmasi notifikasi muncul di akun supervisor.
4. Login sebagai supervisor → buka **Resignation Review** dengan filter "Sebagai Atasan" → klik Detail → Setujui dengan note.
5. Login sebagai HR → buka **Resignation Review** dengan filter "HR" → klik Detail → pilih template offboarding (atau biarkan default) → Setujui.
6. Cek `employees.resign_date` ter-set, dan task offboarding muncul di **Onboarding/Offboarding** board.

## Future enhancements

- Severance calculator integration: saat HR approve, otomatis buat draft `severance_calculations` (untuk RESIGN_VOLUNTARY: hanya UPH = sisa cuti × upah harian, no pesangon, no UPMK). Field `severance_calculation_id` sudah ada di schema, tinggal wire-up.
- Exit interview form auto-trigger sebagai task offboarding khusus dengan custom UI.
- HR override notice period < 30 hari dengan reason audit.
- Bulk approve untuk perusahaan dengan banyak resignation di periode tertentu (rare case).
- Email reminder ke approver kalau pending > X hari.