# Backend Environment Templates

Folder ini berisi template `.env` untuk **3 database center**:

| File                       | Untuk apa                                       | Boleh di-commit?                          |
| -------------------------- | ----------------------------------------------- | ----------------------------------------- |
| `local.env.example`        | Develop di laptop sendiri (Laragon/Docker)      | ✅ Ya — sudah aman, tidak ada secret real |
| `staging.env.example`      | Staging server (pre-prod)                       | ✅ Ya — placeholder `<REPLACE_*>`         |
| `production.env.example`   | Production server (live)                        | ✅ Ya — placeholder `<REPLACE_*>`         |
| `local.env`                | Override lokal Anda (jarang dibutuhkan)         | ❌ Gitignored                             |
| `staging.env`              | File aktual dengan secret staging               | ❌ Gitignored                             |
| `production.env`           | File aktual dengan secret production            | ❌ Gitignored                             |

## Cara pakai

### 1. Pilih database center

```powershell
# Windows / PowerShell
..\..\scripts\switch-env.ps1 local
..\..\scripts\switch-env.ps1 staging
..\..\scripts\switch-env.ps1 production
```

```bash
# Linux / macOS / Git Bash
../../scripts/switch-env.sh local
../../scripts/switch-env.sh staging
../../scripts/switch-env.sh production
```

Script akan:

1. Backup `backend/.env` → `backend/.env.backup` (kalau ada)
2. Cari file aktif: pertama `<env>.env`, kalau tidak ada fallback ke `<env>.env.example`
3. Copy ke `backend/.env`
4. Sama untuk frontend

### 2. Setup secret untuk staging/production (sekali saja)

```powershell
# di folder backend/env-templates/
copy staging.env.example staging.env
copy production.env.example production.env

# lalu edit staging.env / production.env, ganti semua <REPLACE_*>
```

Generate secret kuat:

```powershell
# JWT secret (jalankan 2x: untuk jwt.secret dan jwt.refreshSecret)
php -r "echo bin2hex(random_bytes(64));"

# Encryption key
cd backend
php spark key:generate
```

### 3. Cek environment aktif

```powershell
..\..\scripts\switch-env.ps1 status
```

## Aturan keamanan

- `*.env` (tanpa `.example`) **tidak pernah** di-commit. Sudah di-`.gitignore`.
- Untuk team: simpan secret production di password manager (1Password / Bitwarden) atau secret store (Vault, AWS Secrets Manager).
- Rotate `jwt.secret` & `jwt.refreshSecret` setiap kali ada kebocoran credential.