# HRIS Project — Setup & Cara Menjalankan

Dokumen ini berisi cara setup environment dan menjalankan aplikasi HRIS untuk **development di Windows**. Environment sudah dibuatkan, dokumen ini sebagai referensi lanjutan.

---

## 1. Status Environment Saat Ini

✅ **Sudah terinstal & terkonfigurasi**:

| Komponen      | Versi      | Catatan                                                  |
| ------------- | ---------- | -------------------------------------------------------- |
| PHP           | 8.5.5      | `C:\Users\kento\devtools\php\php.exe`                    |
| Composer      | 2.9.7      | `C:\Users\kento\devtools\composer\`                      |
| Node.js       | 24.15.0    | `C:\Users\kento\devtools\nodejs\`                        |
| npm           | 11.13.0    | Bawaan Node                                              |
| MariaDB       | 12.2       | `C:\Program Files\MariaDB 12.2\` (terinstal, opsional)   |
| Backend deps  | ✅         | `backend/vendor/`                                        |
| Frontend deps | ✅         | `frontend/node_modules/`                                 |
| Database      | ✅ SQLite  | `backend/writable/hris.sqlite` (sudah di-migrate & seed) |

> **Catatan database**: Untuk development saat ini menggunakan **SQLite3** (lebih simpel, tanpa server). MariaDB sudah terinstal sebagai opsi cadangan jika ingin pindah. Lihat bagian "Pindah ke MariaDB" di bawah.

---

## 2. Cara Menjalankan Aplikasi

Anda perlu **dua terminal**: satu untuk backend, satu untuk frontend.

### Terminal 1 — Backend (CodeIgniter 4 API)

```powershell
cd "D:\Apps Project\hris-project-1\backend"
php spark serve --port 8080
```

Output yang diharapkan:

```
CodeIgniter development server started on http://localhost:8080
Press Control-C to stop.
```

API tersedia di: **http://localhost:8080/api/v1**

Test cepat (di terminal lain):

```powershell
curl http://localhost:8080/api/v1/health
```

### Terminal 2 — Frontend (Vue 3 + Vite)

```powershell
cd "D:\Apps Project\hris-project-1\frontend"
npm run dev
```

Output yang diharapkan:

```
  VITE v5.x.x  ready in xxx ms

  ➜  Local:   http://localhost:5173/
```

Buka browser ke: **http://localhost:5173**

### Login Default

```
Email:    admin@hris.local
Password: Admin@12345
```

⚠️ **Wajib ganti password setelah login pertama** (terutama jika project akan dipakai multi-user).

---

## 3. Struktur Layanan

```
┌──────────────────────┐         ┌──────────────────────┐
│  Frontend (Vite)     │  HTTP   │  Backend (CI4)       │
│  http://localhost:5173│ ─────► │  http://localhost:8080│
│  Vue 3 + Pinia       │         │  PHP 8.5 + JWT       │
└──────────────────────┘         └──────────┬───────────┘
                                            │
                                            ▼
                                  ┌─────────────────────┐
                                  │  SQLite (file)      │
                                  │  writable/hris.sqlite│
                                  └─────────────────────┘
```

CORS sudah dikonfigurasi: backend mengizinkan origin `http://localhost:5173`.

---

## 4. Perintah Database yang Sering Dipakai

Semua dijalankan dari `backend/`:

```powershell
cd "D:\Apps Project\hris-project-1\backend"

# Migrasi (membuat/update skema tabel)
php spark migrate

# Rollback semua migrasi
php spark migrate:rollback -b 0

# Cek status migrasi
php spark migrate:status

# FRESH: drop semua tabel + jalankan migrasi ulang dari nol
# CATATAN: --fresh hanya menampilkan "Migrations complete" tanpa list detail.
# Itu BUKAN error — itu memang perilakunya. Setelah --fresh, tabel kosong,
# jadi WAJIB jalankan seeder lagi supaya admin & data demo kembali.
php spark migrate --fresh
php spark db:seed InitialSeeder

# Refresh (alias lain untuk fresh)
php spark migrate:refresh
php spark db:seed InitialSeeder

# Jalankan seeder saja (kalau perlu data demo lagi)
php spark db:seed InitialSeeder
```

---

## 5. Pindah ke MariaDB (opsional)

Kalau nanti mau pakai MariaDB (mis. untuk simulasi production), MariaDB sudah terinstal sebagai service.

### Langkah-langkah

1. **Set password root MariaDB** (saat ini belum diset; service jalan dengan auth Windows):
   - Buka PowerShell sebagai **Administrator**
   - Stop service: `Stop-Service MariaDB`
   - Reset via `--init-file`, atau gunakan tool seperti HeidiSQL/DBeaver yang bisa connect lokal lewat named pipe sebagai admin
   - Set password root, restart service: `Start-Service MariaDB`

2. **Buat database**:
   ```sql
   CREATE DATABASE hris_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. **Edit `backend/.env`**, ganti baris-baris database:
   ```
   database.default.hostname = localhost
   database.default.database = hris_db
   database.default.username = root
   database.default.password = 'PASSWORD_ANDA'
   database.default.DBDriver = MySQLi
   database.default.port = 3306
   ```

4. **Jalankan migrasi & seeder ulang**:
   ```powershell
   cd "D:\Apps Project\hris-project-1\backend"
   php spark migrate
   php spark db:seed InitialSeeder
   ```

### Service MariaDB (untuk referensi)

```powershell
# Status
Get-Service MariaDB

# Stop / Start (perlu admin)
Stop-Service MariaDB
Start-Service MariaDB

# Path ke mysql client
& 'C:\Program Files\MariaDB 12.2\bin\mysql.exe' -uroot -p
```

---

## 6. Troubleshooting

### Backend: "PHP Deprecated: Constant E_STRICT is deprecated"
Aman diabaikan. Hanya warning karena PHP 8.5; tidak menghalangi aplikasi.

### Backend: error "Unable to open database file"
- Pastikan `backend/.env` punya: `database.default.database = hris.sqlite` (nama file saja, **bukan** path absolut). CI4 akan otomatis menaruh di `writable/`.
- Pastikan folder `backend/writable/` writable (mode default sudah benar di Windows).

### Frontend: "Cannot find module" atau error import
```powershell
cd "D:\Apps Project\hris-project-1\frontend"
npm install
```

### Port 8080 / 5173 sudah dipakai
- Backend pakai port lain: `php spark serve --port 8081`, lalu update `frontend/.env` → `VITE_API_BASE_URL=http://localhost:8081`
- Frontend pakai port lain: `npm run dev -- --port 5174`, lalu update `backend/.env` → `cors.allowedOrigins = 'http://localhost:5174'`

### Stop semua proses PHP yang nyangkut
```powershell
Get-Process -Name 'php' -ErrorAction SilentlyContinue | Stop-Process -Force
```

### Reset total database (mulai dari nol)
```powershell
cd "D:\Apps Project\hris-project-1\backend"
Remove-Item .\writable\hris.sqlite -Force -ErrorAction SilentlyContinue
php spark migrate
php spark db:seed InitialSeeder
```

---

## 7. File-file Penting yang Sudah Dibuat/Diubah

| File                       | Perubahan                                                          |
| -------------------------- | ------------------------------------------------------------------ |
| `backend/.env`             | Driver di-set ke SQLite3, database `hris.sqlite`                   |
| `frontend/.env`            | Dibuat dari `.env.example`                                         |
| `backend/writable/hris.sqlite` | Database SQLite hasil migrate & seed                          |

`.env` di backend dan frontend **tidak boleh** di-commit ke git (sudah di-`.gitignore`).

---

## 8. Build Production (referensi)

Build frontend untuk production:
```powershell
cd "D:\Apps Project\hris-project-1\frontend"
npm run build
```
Output ada di `frontend/dist/`.

Untuk deployment lengkap, lihat `docs/DEPLOYMENT.md`.

---

Selamat development! 🚀