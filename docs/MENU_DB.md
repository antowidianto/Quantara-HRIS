# Database-Driven Sidebar Menu

Migration **#076** memindahkan definisi menu sidebar dari hardcode di
`frontend/src/layouts/AppLayout.vue` ke tabel `menus` di database.

## Kenapa pindah?

Sebelumnya menu didefinisikan inline sebagai array `groups` ratusan baris.
Setiap perubahan label, urutan, atau penambahan parent dropdown butuh
rebuild + deploy frontend. Versi database-driven memberi:

1. **Editable runtime.** HR / admin atur menu dari halaman
   `/admin/menus` tanpa rebuild.
2. **Sidebar lebih rapi.** Menu yang relevan dikelompokkan ke `PARENT`
   yang bisa di-collapse, bukan flat list 30+ item di section "HR".
3. **Multi-tenant ready.** Default global (`company_id = NULL`) bisa di
   "fork" per company kalau di kemudian hari ada override khusus.

## Skema tabel `menus`

| Kolom              | Tipe              | Keterangan                                                      |
| ------------------ | ----------------- | --------------------------------------------------------------- |
| `id`               | INT PK            |                                                                 |
| `company_id`       | INT NULL          | NULL = default global; non-null = override per company.         |
| `parent_id`        | INT NULL          | Self-reference. NULL = top-level (umumnya SECTION).             |
| `type`             | ENUM              | `SECTION` \| `PARENT` \| `ITEM`.                                |
| `code`             | VARCHAR(100)      | Slug unik per company.                                          |
| `label`            | VARCHAR(150)      | Label tampilan default.                                         |
| `translation_key`  | VARCHAR(150) NULL | Kalau di-set, frontend pakai i18n key ini sebagai label.        |
| `icon`             | VARCHAR(80) NULL  | Nama Heroicons (mis. `UsersIcon`); whitelist di iconMap.js.     |
| `route_path`       | VARCHAR(255) NULL | Path Vue Router. Wajib untuk ITEM, kosong untuk SECTION/PARENT. |
| `permission`       | VARCHAR(100) NULL | Permission slug; NULL = always visible.                         |
| `super_admin_only` | TINYINT           | `1` = hanya super admin.                                        |
| `sort_order`       | INT               | Urutan ascending dalam siblings yang sama.                      |
| `is_active`        | TINYINT           | `0` = tersembunyi (dipakai admin untuk soft-disable).           |

Unique key: `(company_id, code)`.

## Tipe node

```
SECTION   ← header grup ("HR", "Payroll")
  ITEM    ← link biasa (RouterLink)
  PARENT  ← tombol toggle dengan chevron
    ITEM
    ITEM
```

Frontend (`SidebarMenu.vue`) memilih komponen sesuai `type`. SECTION /
PARENT yang setelah filter permission tidak punya anak ITEM otomatis
di-prune di backend (`MenuController::buildTreeAndPrune`).

## API endpoint

| Method | Path                | Auth                    | Deskripsi                                       |
| ------ | ------------------- | ----------------------- | ----------------------------------------------- |
| GET    | `/api/v1/menus/me`  | JWT                     | Tree menu user (filtered + pruned).             |
| GET    | `/api/v1/menus`     | JWT + `menu.manage`     | List flat + tree untuk admin manage.            |
| GET    | `/api/v1/menus/:id` | JWT + `menu.manage`     | Detail satu menu.                               |
| POST   | `/api/v1/menus`     | JWT + `menu.manage`     | Buat menu baru.                                 |
| PUT    | `/api/v1/menus/:id` | JWT + `menu.manage`     | Update; auto-fork kalau row global di company.  |
| DELETE | `/api/v1/menus/:id` | JWT + `menu.manage`     | Soft delete (cascade ke seluruh keturunan).     |
| POST   | `/api/v1/menus/reorder` | JWT + `menu.manage` | Batch update `sort_order` + `parent_id`.        |

## Multi-tenant override (lazy fork)

- Default tree disimpan dengan `company_id = NULL`. Saat dibuka oleh
  user di company X, backend gabungkan: row local company X meng-override
  row global yang `code`-nya sama.
- HR di company X edit menu global pertama kali → backend "fork" dulu:
  clone row global ke `company_id = X`, lalu apply edit. Default global
  tidak terganggu.
- Anak-anak menu tidak ikut di-fork sampai admin meng-edit-nya juga
  (lazy fork) supaya overhead minimal.

## Frontend integration

1. **Store**: `frontend/src/stores/menu.js` (`useMenuStore`).
   - `load()` panggil `GET /menus/me`, simpan tree.
   - `refresh()` paksa re-fetch (dipakai setelah admin edit menu).
   - `reset()` di-call saat logout.

2. **Renderer**: `frontend/src/components/menu/SidebarMenu.vue`.
   - Self-recursive komponen yang render SECTION / PARENT / ITEM.
   - State expand/collapse parent disimpan di `localStorage` key
     `hris_menu_expanded`.
   - Auto-expand parent kalau salah satu descendant ITEM-nya match path
     aktif (deep-link awareness).

3. **Icon mapping**: `frontend/src/components/menu/iconMap.js`.
   - Whitelist nama → komponen Heroicons supaya tree-shaking jalan dan
     admin tidak bisa inject sembarang import.
   - Nama tidak dikenal fallback ke `Squares2X2Icon`.

4. **Layout**: `frontend/src/layouts/AppLayout.vue` cuma render
   `<SidebarMenu :node="…" />` untuk tiap top-level node dari store.

## Admin UI

`/admin/menus` (membutuhkan permission `menu.manage`).

Fitur:
- Tree view dengan tipe + permission badge.
- CRUD per node (modal form).
- Tombol naik / turun untuk reorder antar siblings.
- Toggle aktif / non-aktif (eye icon).
- Tombol "+" pada SECTION / PARENT untuk cepat menambah anak.

## Default seed

Migration ini juga seed tree default yang mereplikasi menu lama, tetapi
mengelompokkan item HR yang sebelumnya flat ke beberapa parent dropdown:

- **Karyawan** (employees, contracts, probation, IDP, assets)
- **Absensi & Roster** (attendance, roster, change_requests, anomalies)
- **Cuti, Lembur & Klaim** (leaves, overtimes, reimbursements, loans)
- **Lifecycle Karyawan** (onboarding, disciplinary, JIC, transfers, severance, termination, resignations, exit_interviews)
- **Rekrutmen** (MPP, jobs, career portal, online tests, funnel, scoring)
- **Training & Sertifikasi**
- **Performance**
- **Talent & Analytics** (turnover, competency, succession)

Master Data juga dikelompokkan: Organisasi / Rekrutmen / Shift / Cuti &
Payroll / Policy & Template.

Seed cuma berjalan kalau tabel `menus` masih kosong (count rows global = 0)
sehingga aman dijalankan ulang tanpa menimpa edit user.