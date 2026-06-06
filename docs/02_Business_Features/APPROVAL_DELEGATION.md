# Approval Delegation

Dokumen ini menjelaskan modul **Delegasi Approval** yang ditambahkan di
iterasi roadmap fase 1 item #3.

## Latar Belakang

`ApprovalEngine` sudah generik (chain + step + assignment), tapi tidak ada
mekanisme delegasi. Saat manager / supervisor cuti panjang, semua request
yang seharusnya dia approve akan **macet** sampai dia kembali.

Sekarang setiap user bisa mendaftarkan **delegate** untuk rentang tanggal
tertentu, opsional dibatasi per modul (LEAVE / OVERTIME / dll). Saat delegasi
aktif, delegate bisa approve atas namanya, dan jejak audit jelas (dicatat
sebagai "DELEGATE_OF_<original_user_id>" di `approval_decisions.approver_role`).

## Apa yang Berubah

### Database

Migration `2024-01-01-000013_CreateApprovalDelegations.php`:

| Tabel `approval_delegations` | |
|---|---|
| `user_id` | User yang mendelegasikan |
| `delegate_to_user_id` | User pengganti |
| `module` | NULL = semua modul; atau LEAVE/OVERTIME/... |
| `date_from` / `date_to` | Rentang aktif |
| `reason` | Alasan delegasi (opsional) |
| `is_active` | 0/1 |

3 permission baru:
- `delegation.view` — lihat semua delegasi (admin/HR)
- `delegation.manage` — kelola delegasi orang lain (admin/HR)
- `delegation.manage_self` — kelola delegasi sendiri (semua user)

Auto-grant: role 1 (super admin) & 2 (HR admin) dapat semua;
role 3 (manager), 4 (employee), 5 (finance) hanya `manage_self`.

### Backend

#### `ApprovalEngine` patch
- Method baru `expandWithDelegates($userIds, $companyId, $module)` —
  expand daftar approver dengan delegate aktif. **User asli tetap di list**
  agar dia bisa approve sendiri kalau tetap punya akses (sample case:
  cuti tapi sempat buka aplikasi).
- Method baru `findOriginalForDelegate($userId, $approverIds, ...)` —
  cek apakah user yang sedang approve adalah delegate untuk salah satu
  approver resmi.
- `resolveCurrentApprovers` selalu meng-expand approver dengan delegate,
  termasuk fallback HR.
- `decide()` saat eksekusi:
  - Verifikasi approver tetap pakai expanded list (delegate masuk).
  - Kalau pelaku adalah delegate → catatan dimulai dengan
    `[Mendelegasi mewakili <Nama Asli>]`.
  - Kolom `approval_decisions.approver_role` di-set
    `DELEGATE_OF_<user_id>`.

#### Controller `ApprovalDelegationController`
| Method | Endpoint |
|---|---|
| `index` | `GET /api/v1/approval-delegations` (`?mine=1`, `?active=1`, `?module=`) |
| `show` | `GET /api/v1/approval-delegations/(:num)` |
| `create` | `POST /api/v1/approval-delegations` |
| `update` | `PUT /api/v1/approval-delegations/(:num)` |
| `delete` | `DELETE /api/v1/approval-delegations/(:num)` |

Aturan authorization:
- Pegawai biasa (tanpa `delegation.view`): list hanya berisi delegasi
  miliknya ATAU di mana dia jadi delegate.
- HR/admin (`delegation.view` / `delegation.manage`): bisa lihat & ubah
  semua, dan boleh membuatkan delegasi atas nama user lain.

Validasi:
- `delegate_to_user_id` ≠ `user_id` (tolak self-delegation)
- `date_to` ≥ `date_from`
- Modul harus valid (LEAVE/OVERTIME/...) atau kosong (semua modul)
- Tolak duplikasi: kombinasi `(user, delegate, module)` dengan rentang
  tanggal yang overlap

Setiap aksi tercatat di audit log (`module=APPROVAL`).

### Frontend

`frontend/src/views/Approvals/MyDelegations.vue` — halaman 3-tab:
1. **Saya Delegasikan** — daftar delegasi yang saya buat
2. **Mewakili Orang Lain** — delegasi di mana saya jadi pengganti
3. **Semua Delegasi** — hanya tampil kalau punya `delegation.view`

Setiap row menampilkan badge status:
- **Aktif** — `is_active=1` dan tanggal hari ini ∈ [date_from, date_to]
- **Terjadwal / Lewat** — aktif tapi di luar rentang tanggal
- **Non-aktif** — `is_active=0`

Aksi: edit, toggle on/off, hapus.

Sidebar `AppLayout.vue` ditambah:
- Group "Saya" → "Delegasi Approval"

Router: `/approvals/delegations`.

## Skenario Penggunaan

### Skenario 1 — Manager cuti seminggu

Manager Andi akan cuti tanggal 1-7 Juni dan ingin Beni menggantikan dia
untuk approve cuti & lembur.

1. Andi buka **Saya** → **Delegasi Approval** → **Buat Delegasi**
2. Pilih:
   - Pengganti: Beni
   - Berlaku dari: 1 Juni 2026
   - Berlaku sampai: 7 Juni 2026
   - Modul: kosongkan (= semua modul)
   - Alasan: "Cuti tahunan"
3. Simpan.

Dari 1-7 Juni:
- Setiap request cuti/lembur yang biasanya nunggu Andi sekarang juga
  muncul di inbox Beni.
- Kalau Beni approve, decision tercatat: approver_user_id=Beni,
  approver_role="DELEGATE_OF_<andi_id>", note diawali "[Mendelegasi mewakili Andi]".
- Andi tetap bisa approve sendiri kalau iseng buka HRIS dari hp.

Setelah 7 Juni delegasi otomatis tidak aktif (date_to lewat).

### Skenario 2 — HR atur delegasi orang yang sakit mendadak

HR Admin Rina mendapat info bahwa supervisor Beni terkena sakit dan
absen 3 hari. Beni sendiri tidak sempat membuat delegasi.

1. Rina buka **Saya** → **Delegasi Approval** → **Buat Delegasi**
2. Karena Rina punya `delegation.manage`, dropdown "Pendelegasi" muncul
   — pilih Beni
3. Pilih pengganti: Citra
4. Set rentang tanggal sakit
5. Simpan

### Skenario 3 — Audit jejak

HR ingin tahu siapa yang sebenarnya menyetujui cuti karyawan X.

1. Buka detail approval_request, lihat `approval_decisions`
2. Decision row akan tampil:
   - `approver_user_id` = Beni (yang klik approve)
   - `approver_role` = `DELEGATE_OF_5` (Andi adalah user #5)
   - `note` = "[Mendelegasi mewakili Andi] Setuju, sesuai SOP"

## Verifikasi

Smoke test `backend/scripts/test-delegation-flow.php` lulus 7/7:

```
1. Schema check
  ✓ Tabel approval_delegations ada
  ✓ 3 permission delegasi terdaftar

2. Approval request setup
  Approver awal: 2, 4, 7, 11
  ✓ Ada approver resmi
  ✓ Delegasi ditambahkan
  Approver setelah delegasi: 2, 4, 7, 11, 3   ← user #3 (delegate) di-append
  ✓ Original approver masih bisa
  ✓ Delegate masuk approver list
  ✓ canApprove(delegate) = true
  ✓ canApprove(original) = true
```

## Migrasi Database Existing

```bash
cd backend
php spark migrate
```

Tidak butuh seeder; tabel kosong dan permission auto-grant ke role.