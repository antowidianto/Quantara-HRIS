# HRIS — Setup Development (Windows / PowerShell).
#
# Otomasi langkah onboarding developer baru:
#   1. Cek dependencies (PHP, Composer, Node, npm)
#   2. Backend: composer install + .env from example + migrate + seed
#   3. Frontend: npm install + .env from example
#
# Idempotent — aman dijalankan berkali-kali.
#
# Usage:
#   pwsh -ExecutionPolicy Bypass -File scripts/setup-dev.ps1
# atau
#   .\scripts\setup-dev.ps1

[CmdletBinding()]
param(
    [switch]$SkipMigrate,
    [switch]$Reset  # drop & recreate database (destructive)
)

$ErrorActionPreference = 'Stop'

# ─── helper ────────────────────────────────────────────────────────
function Write-Step($msg) { Write-Host "`n=== $msg ===" -ForegroundColor Cyan }
function Write-Ok($msg)   { Write-Host "  ✓ $msg" -ForegroundColor Green }
function Write-Skip($msg) { Write-Host "  - $msg" -ForegroundColor DarkGray }
function Write-Warn($msg) { Write-Host "  ! $msg" -ForegroundColor Yellow }
function Test-Cmd($name)  { $null -ne (Get-Command $name -ErrorAction SilentlyContinue) }

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

# ─── 1. Cek dependencies ───────────────────────────────────────────
Write-Step 'Cek tools yang dibutuhkan'
$missing = @()
foreach ($cmd in @('php','composer','node','npm')) {
    if (Test-Cmd $cmd) {
        $ver = (& $cmd --version 2>&1 | Select-Object -First 1)
        Write-Ok "$cmd : $ver"
    } else {
        $missing += $cmd
        Write-Warn "$cmd tidak ditemukan"
    }
}
if ($missing.Count -gt 0) {
    Write-Host ''
    Write-Host "Install dulu: $($missing -join ', ')" -ForegroundColor Red
    Write-Host 'Lihat SETUP.md untuk panduan instalasi.'
    exit 1
}

# ─── 2. Backend ────────────────────────────────────────────────────
Write-Step 'Backend setup'
Push-Location "$root\backend"
try {
    if (-not (Test-Path 'vendor')) {
        Write-Host '  Installing composer dependencies...'
        composer install --no-interaction --prefer-dist
    } else {
        Write-Skip 'vendor/ sudah ada (skip composer install)'
    }

    if (-not (Test-Path '.env')) {
        Copy-Item '.env.example' '.env'
        Write-Ok '.env dibuat dari .env.example'
    } else {
        Write-Skip '.env sudah ada'
    }

    if ($Reset -and (Test-Path 'writable\hris.sqlite')) {
        Remove-Item 'writable\hris.sqlite' -Force
        Write-Warn 'SQLite database dihapus (--Reset)'
    }

    if (-not $SkipMigrate) {
        Write-Host '  Running migrations...'
        php spark migrate
        Write-Host '  Running seeder (InitialSeeder)...'
        php spark db:seed InitialSeeder
        Write-Ok 'Database ter-migrate & ter-seed'
    } else {
        Write-Skip 'Migrate/seed di-skip (--SkipMigrate)'
    }
} finally {
    Pop-Location
}

# ─── 3. Frontend ───────────────────────────────────────────────────
Write-Step 'Frontend setup'
Push-Location "$root\frontend"
try {
    if (-not (Test-Path 'node_modules')) {
        Write-Host '  Installing npm dependencies...'
        npm install --no-audit --no-fund
    } else {
        Write-Skip 'node_modules/ sudah ada (skip npm install)'
    }

    if (-not (Test-Path '.env') -and (Test-Path '.env.example')) {
        Copy-Item '.env.example' '.env'
        Write-Ok '.env dibuat dari .env.example'
    } elseif (-not (Test-Path '.env')) {
        # Buat .env minimal kalau .env.example belum ada
        @"
VITE_API_BASE_URL=http://localhost:8080
VITE_USE_PROXY=false
"@ | Set-Content -Encoding UTF8 '.env'
        Write-Ok '.env dibuat dengan default'
    } else {
        Write-Skip '.env sudah ada'
    }
} finally {
    Pop-Location
}

# ─── Selesai ───────────────────────────────────────────────────────
Write-Step 'Selesai!'
Write-Host ''
Write-Host 'Untuk start aplikasi (butuh 2 terminal):' -ForegroundColor Cyan
Write-Host ''
Write-Host '  Terminal 1 (Backend):' -ForegroundColor Yellow
Write-Host '    cd backend' -ForegroundColor Gray
Write-Host '    php spark serve --port 8080' -ForegroundColor Gray
Write-Host ''
Write-Host '  Terminal 2 (Frontend):' -ForegroundColor Yellow
Write-Host '    cd frontend' -ForegroundColor Gray
Write-Host '    npm run dev' -ForegroundColor Gray
Write-Host ''
Write-Host 'Default login: admin@hris.local / Admin@12345' -ForegroundColor Green
Write-Host 'Frontend: http://localhost:5173' -ForegroundColor Green
Write-Host 'Backend:  http://localhost:8080/api/v1' -ForegroundColor Green