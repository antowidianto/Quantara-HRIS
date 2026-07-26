# Role Dashboard Builder

## Tujuan

Dashboard Builder memberi Super Admin satu halaman no-code untuk menyusun dashboard monitoring bagi role tertentu. Template dapat berlaku global untuk seluruh tenant atau menjadi override bagi satu tenant. Pengguna target melihat template berstatus `PUBLISHED` di `/dashboard`; jika belum ada template yang cocok, aplikasi tetap menampilkan dashboard personal lama.

## Alur penggunaan

1. Buka **Admin → Dashboard Builder**.
2. Buat template, isi nama, role penerima, dan cakupan tenant.
3. Tambahkan komponen dari katalog, kemudian atur judul, lebar, warna, serta urutannya dengan drag-and-drop atau tombol naik/turun.
4. Gunakan **Preview** untuk melihat data tenant aktual dalam modal lebar.
5. **Simpan Draft** untuk menyimpan tanpa memengaruhi pengguna.
6. **Publish** untuk mengaktifkan template. Template published sebelumnya pada kombinasi role dan tenant yang sama otomatis diarsipkan.

Saat template aktif diedit, aplikasi membuat draft salinan. Template aktif tidak berubah atau hilang selama draft disusun; template aktif juga tidak dapat dihapus sebelum ada versi pengganti.

Prioritas resolusi dashboard adalah:

1. template published untuk `company_id + role`;
2. template published global untuk role;
3. dashboard personal bawaan aplikasi.

## Katalog data awal

| Kelompok | Komponen |
|---|---|
| Karyawan | karyawan aktif, kontrak berakhir 30 hari, komposisi status kerja |
| Absensi | kehadiran hari ini, cuti menunggu |
| Workflow | total approval menunggu, approval per modul |
| Payroll | payroll berjalan, distribusi status periode |
| Rekrutmen | lowongan aktif, pipeline kandidat |
| Layanan HR | tiket HR terbuka |
| Navigasi | akses cepat ke modul yang diizinkan |

Katalog ini sengaja code-owned. Konfigurasi dashboard hanya menyimpan `metric_key`, judul, lebar, warna, dan posisi; konfigurasi tidak menerima SQL, nama tabel, URL API, HTML, atau expression dari pengguna.

## Keamanan

- Seluruh endpoint builder dilindungi `jwt + superadmin`; controller juga memeriksa Super Admin sebagai defense-in-depth.
- Mutasi create, update, publish, dan delete memakai idempotency key.
- Endpoint dashboard pengguna memakai ownership account dan menentukan role dari sesi server, bukan role dari request.
- Semua agregasi menggunakan `company_id`. Jika tabel sumber tidak memiliki kolom tenant, komponen mengembalikan nilai kosong alih-alih membaca lintas tenant.
- Widget dengan `required_permission` dibuang di server saat dashboard dirender untuk pengguna.
- Shortcut juga difilter berdasarkan permission efektif sehingga tombol menuju modul terlarang tidak ditampilkan.
- Preview hanya tersedia bagi Super Admin. Perubahan template dan publish masuk audit log severity `HIGH`.
- Versi konfigurasi disimpan di `dashboard_template_versions` untuk jejak perubahan.

## Dark mode dan responsivitas

Renderer dan builder memakai kelas `dark:*` pada surface, border, teks, input, chart, modal, dan state kosong. Layout widget memakai grid 12 kolom pada desktop dan otomatis menjadi satu kolom pada layar kecil. Kanvas, katalog, daftar template, serta preview memiliki overflow vertikal sendiri agar halaman tetap mudah dipakai dengan data panjang.

## Endpoint

| Method | Endpoint | Fungsi |
|---|---|---|
| GET | `/api/v1/admin/dashboard-builder/catalog` | katalog metric aman |
| GET | `/api/v1/admin/dashboard-builder/templates` | seluruh template |
| POST | `/api/v1/admin/dashboard-builder/templates` | membuat draft |
| PUT | `/api/v1/admin/dashboard-builder/templates/{id}` | menyimpan versi baru |
| POST | `/api/v1/admin/dashboard-builder/templates/{id}/publish` | mengaktifkan template |
| DELETE | `/api/v1/admin/dashboard-builder/templates/{id}` | soft delete |
| POST | `/api/v1/admin/dashboard-builder/preview` | render preview |
| GET | `/api/v1/dashboard-config/me` | dashboard efektif pengguna |

## Pengembangan katalog

Komponen baru harus ditambahkan sebagai resolver code-owned di `RoleDashboardService`, diberi permission minimum, memakai filter tenant eksplisit, dan memiliki bentuk data salah satu dari `stat`, `progress`, `bar`, `donut`, atau `links`. Jangan menambahkan query builder generik dari payload dashboard.
