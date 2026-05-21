# Organization Development — Easy Setting

Halaman drag-and-drop untuk menata struktur organisasi tanpa harus buka
form CRUD per item. Cocok untuk Org Development / HR Strategy yang sering
restrukturisasi divisi.

## Akses

- Frontend route: `/master/org-structure`
- Sidebar: **Master Data → Struktur Organisasi**
- Permission: `department.view` (sudah ada di sistem)

## Yang Bisa Dilakukan

1. **Tarik department** ke department lain → menjadi sub-department.
2. **Tarik department** ke zona "level paling atas" → root.
3. **Tarik posisi** antar department → re-assign jabatan.
4. **Tarik posisi** ke panel "Posisi Belum Ditugaskan" → unassign.
5. **Klik ikon edit** → ganti kode/nama/deskripsi/manager.
6. **Klik ikon +** di node → tambah sub-department atau posisi baru.
7. **Klik ikon hapus** → hapus department/posisi (dicegah jika masih ada
   sub-dept/posisi/karyawan yang nempel).

Perubahan drag-and-drop dikumpulkan sebagai *pending changes*. Banner
kuning di atas tree menunjukkan jumlah perubahan; user klik
**Simpan Perubahan** untuk commit, atau **Batal** untuk discard + reload.

## Skema Data

Migrasi baru: `2024-01-01-000029_AddPositionTier.php` menambahkan kolom
`positions.tier`. Selain itu fitur reuse kolom existing.

| Tabel        | Kolom                  | Fungsi                                                  |
| ------------ | ---------------------- | ------------------------------------------------------- |
| `departments`| `parent_id`            | Self-reference untuk hirarki                            |
| `departments`| `manager_employee_id`  | Kepala department (1 employee)                          |
| `positions`  | `department_id`        | Posisi nempel di department mana                        |
| `positions`  | `tier`                 | ENUM: MANAGER / ASST_MANAGER / SUPERVISOR / STAFF       |
| `employees`  | `manager_id`           | Atasan langsung — auto-recompute setelah perubahan org  |

## Direct Report (manager_id) — Auto-Recompute

Setelah setiap perubahan struktur (move-department, move-position,
set-manager, edit tier), backend memanggil
`App\Libraries\ReportingChainService` untuk menghitung ulang
`employees.manager_id` semua karyawan terkait. Aturan rantai (cari
atasan dari paling dekat ke paling jauh):

```
STAFF        → SUPERVISOR (dept sama)
             → ASST_MANAGER (dept sama)
             → MANAGER (dept sama)
             → MANAGER (parent dept, naik sampai ketemu)

SUPERVISOR   → ASST_MANAGER (dept sama)
             → MANAGER (dept sama)
             → MANAGER (parent dept, naik)

ASST_MANAGER → MANAGER (dept sama)
             → MANAGER (parent dept, naik)

MANAGER      → MANAGER (parent dept, naik sampai ketemu)
             → kalau tidak ada → manager_id = NULL (top of org)
```

Self-reference dicegah; kalau ada banyak orang di tier yang sama
(mis. dua MANAGER di satu department), pilih `id` paling kecil supaya
hasilnya deterministik.

## API Reference

Semua endpoint scoped ke `company_id` user (multi-tenant), butuh JWT.

### `GET /api/v1/org-structure`

Tree gabungan departments → positions, plus stats.

```json
{
  "departments": [
    {
      "id": 1, "code": "HQ", "name": "Head Office",
      "parent_id": null, "manager_employee_id": 5, "manager_name": "Budi",
      "employee_count": 12, "is_active": 1,
      "positions": [{ "id": 10, "code": "CEO", "name": "Chief Executive" }],
      "children": [{ "id": 2, "name": "HR", "...": "..." }]
    }
  ],
  "unassigned_positions": [],
  "stats": { "total_departments": 8, "total_positions": 25, "total_employees": 134 }
}
```

### `POST /api/v1/org-structure/move-department`

```json
{ "department_id": 5, "new_parent_id": 2 }
```

`new_parent_id: null` = jadikan root. Validasi: tidak boleh self-parent,
tidak boleh ke descendant sendiri (anti-cycle), tidak lintas tenant.

### `POST /api/v1/org-structure/move-position`

```json
{ "position_id": 12, "new_department_id": 3 }
```

`new_department_id: null` = unassign.

### `POST /api/v1/org-structure/set-manager`

```json
{ "department_id": 3, "manager_employee_id": 42 }
```

`manager_employee_id: null` = clear.

### `POST /api/v1/org-structure/bulk-reorder`

Apply banyak pemindahan department dalam satu transaksi. Cycle
divalidasi terhadap **state akhir**, bukan urutan apply.

```json
{
  "moves": [
    { "department_id": 5, "new_parent_id": 2 },
    { "department_id": 7, "new_parent_id": null }
  ]
}
```

### `POST /api/v1/org-structure/recompute-reports`

Manual trigger untuk recompute `employees.manager_id` di seluruh
perusahaan. Biasanya tidak perlu dipanggil — endpoint move-* di atas
sudah memicu recompute otomatis. Berguna untuk maintenance setelah
import bulk data atau jika tier diubah lewat halaman Master Positions
(bukan via Org Structure).

Response:
```json
{ "updated": 12, "unchanged": 88, "errors": [] }
```

## Audit

Setiap move/set-manager menulis 1 entry audit log dengan
`module=MASTER`, `entity=department|position`, before/after diff yang
hanya berisi field yang berubah. Bisa ditelusur via halaman
**Admin → Audit Log**.

## Catatan Implementasi

- Frontend pakai **HTML5 native drag-and-drop** (tanpa dependency baru)
  karena project tidak memuat `vue-draggable`. Payload via
  `dataTransfer` JSON `{ type, id }`.
- Recursive tree component (`DepartmentNode`) di-define inline di blok
  `<script>` non-setup karena Vue 3 SFC `<script setup>` tidak
  mendukung self-reference.
- Optimistic UI: tree di-mutasi langsung saat drop, lalu di-rollback
  via reload kalau backend menolak (mis. cycle).
- Bulk reorder departments dipakai sekali save, tetapi **position moves
  dikirim per item** karena jumlahnya biasanya kecil dan endpoint
  individual sudah cukup.