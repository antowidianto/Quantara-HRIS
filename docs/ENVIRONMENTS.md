# Environments — 3 Database Center

HRIS mendukung **3 database center** yang bisa diaktifkan dengan satu perintah:

| Env          | Untuk apa                                | Database                                      |
| ------------ | ---------------------------------------- | --------------------------------------------- |
| `local`      | Develop di laptop sendiri                | MySQL/MariaDB di `localhost:3306` (Laragon)   |
| `staging`    | Server staging / pre-production          | DB staging (server pre-prod)                  |
| `production` | Server live / production                 | DB production (managed / dedicated)           |

Tiap env punya konfigurasi lengkap (DB, JWT, CORS, mail, security) yang sudah disiapkan.

---

## Quick Start

### Windows (PowerShell)

```powershell
# Pilih environment
.\scripts\switch-env.ps1 local
.\scripts\switch-env.ps1 staging
.\scripts\switch-env.ps1 production

# Cek env aktif
.\scripts\switch-env.ps1 status
```

### Windows (cmd.exe)

```cmd
scripts\switch-env.cmd local
scripts\switch-env.cmd staging
scripts\switch-env.cmd production
scripts\switch-env.cmd status
```

### Linux / macOS / Git Bash

```bash
chmod +x scripts/switch-env.sh   # sekali saja
./scripts/switch-env.sh local
./scripts/switch-env.sh staging
./scripts/switch-env.sh production
./scripts/switch-env.sh status
```

Saat dijalankan, script akan:

1. Backup `backend/.env` dan `frontend/.env` (kalau ada) → `.env.backup`
2. Copy template env terpilih → `backend/.env` dan `frontend/.env`
3. Tampilkan info env yang sekarang aktif

---

## Struktur Folder

```
hris-project-1/
├── backend/
│   ├── .env                          ← AKTIF (gitignored)
│   ├── .env.backup                   ← backup otomatis (gitignored)
│   └── env-templates/
│       ├── README.md
│       ├── local.env.example         ← committed (siap pakai)
│       ├── staging.env.example       ← committed (placeholder)
│       ├── production.env.example    ← committed (placeholder)
│       ├── local.env                 ← (opsional, gitignored)
│       ├── staging.env               ← (gitignored, secret real)
│       └── production.env            ← (gitignored, secret real)
│
├── frontend/
│   ├── .env                          ← AKTIF (gitignored)
│   ├── .env.backup                   ← backup otomatis (gitignored)
│   └── env-templates/
│       ├── local.env.example
│       ├── staging.env.example
│       └── production.env.example
│
└── scripts/
    ├── switch-env.ps1                ← PowerShell switcher
    ├── switch-env.cmd                ← cmd.exe wrapper
    └── switch-env.sh                 ← bash switcher
```

### Aturan file

| Pattern              | Status         | Boleh di-commit? |
| -------------------- | -------------- | ---------------- |
| `*.env.example`      | Template       | ✅ Ya            |
| `*.env` (di template) | Secret real    | ❌ Tidak         |
| `backend/.env`       | Aktif          | ❌ Tidak         |
| `frontend/.env`      | Aktif          | ❌ Tidak         |
| `*.env.backup`       | Auto backup    | ❌ Tidak         |

Semua aturan ini sudah diatur di `.gitignore`.

---

## Setup Pertama Kali

### Untuk LOCAL (laptop developer)

Tinggal aktifkan, langsung jalan:

```powershell
.\scripts\switch-env.ps1 local
cd backend
php spark migrate
php spark db:seed InitialSeeder
```

### Untuk STAGING (sekali setup per server / per developer yang punya akses)

```powershell
# 1. Copy template ke file aktual (ini gitignored, jadi aman)
copy backend\env-templates\staging.env.example  backend\env-templates\staging.env
copy frontend\env-templates\staging.env.example frontend\env-templates\staging.env

# 2. Edit backend\env-templates\staging.env
#    Ganti semua <REPLACE_*> dengan nilai real:
#      database.default.hostname / username / password
#      jwt.secret, jwt.refreshSecret
#      encryption.key
#      mail.host / username / password
#      app.baseURL, app.frontendUrl, cors.allowedOrigins

# 3. Generate secret kuat
cd backend
php -r "echo bin2hex(random_bytes(64));"     # untuk jwt.secret
php -r "echo bin2hex(random_bytes(64));"     # untuk jwt.refreshSecret
php spark key:generate                       # untuk encryption.key

# 4. Aktifkan
.\scripts\switch-env.ps1 staging
```

### Untuk PRODUCTION

Sama seperti staging, tapi pakai `production.env.example` → `production.env`.

> ⚠ **Production extra**: Boot guard di `backend/app/Config/Boot/production.php` akan **menolak request** kalau:
>
> - `app.baseURL` masih `localhost`
> - `jwt.secret` masih default
> - `encryption.key` masih placeholder
>
> Jadi kalau lupa ganti, server return HTTP 500 dengan pesan jelas. Aman dari accidental deploy dengan secret default.

---

## Tips Operasional

### Cek environment aktif

```powershell
.\scripts\switch-env.ps1 status
```

Output:

```
Backend  (D:\...\backend\.env):
  HRIS Backend — LOCAL (Development) Environment

Frontend (D:\...\frontend\.env):
  HRIS Frontend — LOCAL (Development) Environment
```

### Pindah env tanpa kehilangan data

Switcher otomatis backup `.env` lama ke `.env.backup`. Kalau ada konfigurasi custom yang mau dipertahankan, copy manual dulu sebelum switch:

```powershell
copy backend\.env backend\.env.my-custom
```

### Environment override per developer

Kalau punya credential lokal yang beda dari default (mis. password MySQL non-kosong), copy:

```powershell
copy backend\env-templates\local.env.example backend\env-templates\local.env
```

Lalu edit `local.env` sesuai laptop Anda. Switcher akan utamakan `local.env` dibanding `local.env.example`. File `local.env` sudah gitignored.

### CI/CD

Di pipeline (GitHub Actions / GitLab CI), set secret di environment variables, lalu generate `.env` dari template + replace placeholder. Atau lebih simpel: render `.env` dari secret store langsung tanpa pakai template.

---

## Referensi

- `backend/env-templates/README.md` — detail per file backend
- `backend/app/Config/Boot/production.php` — boot guard logic
- `SETUP.md` — setup awal HRIS
- `docs/DEPLOYMENT.md` — deployment lengkap