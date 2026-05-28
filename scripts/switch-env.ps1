<#
.SYNOPSIS
    Switch HRIS environment (database center) for backend & frontend.

.DESCRIPTION
    Memilih environment untuk backend (CodeIgniter 4) dan frontend (Vite).
    Mendukung 3 database center: local, staging, production.

    Untuk tiap environment, script akan:
      1. Backup .env lama -> .env.backup
      2. Cari source: pertama <env>.env, fallback ke <env>.env.example
      3. Copy ke backend/.env dan frontend/.env

.PARAMETER Environment
    Salah satu dari: local, staging, production, status

.EXAMPLE
    .\scripts\switch-env.ps1 local
    .\scripts\switch-env.ps1 staging
    .\scripts\switch-env.ps1 production
    .\scripts\switch-env.ps1 status
#>

[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('local', 'staging', 'production', 'status', '')]
    [string]$Environment = ''
)

$ErrorActionPreference = 'Stop'

# Paths (relative to this script)
$ScriptDir   = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot    = Resolve-Path (Join-Path $ScriptDir '..')
$BackendDir  = Join-Path $RepoRoot 'backend'
$FrontendDir = Join-Path $RepoRoot 'frontend'
$BackendTpl  = Join-Path $BackendDir 'env-templates'
$FrontendTpl = Join-Path $FrontendDir 'env-templates'

function Write-Header {
    param([string]$Text)
    Write-Host ""
    Write-Host "===============================================================" -ForegroundColor Cyan
    Write-Host "  $Text" -ForegroundColor Cyan
    Write-Host "===============================================================" -ForegroundColor Cyan
}

function Get-EnvLabel {
    param([string]$Path)
    if (-not (Test-Path $Path)) {
        return "  (tidak ada)"
    }
    $line = Select-String -Path $Path -Pattern '^# HRIS .* Environment' |
            Select-Object -First 1
    if ($null -ne $line) {
        return "  " + $line.Line.TrimStart('#',' ')
    }
    $envLine = Select-String -Path $Path -Pattern '^CI_ENVIRONMENT' |
               Select-Object -First 1
    $vapp    = Select-String -Path $Path -Pattern '^VITE_APP_ENV' |
               Select-Object -First 1
    if ($envLine) { return "  " + $envLine.Line.Trim() }
    if ($vapp)    { return "  " + $vapp.Line.Trim() }
    return "  (tidak ada marker)"
}

function Show-Status {
    Write-Header "HRIS Environment Status"

    $backendEnv  = Join-Path $BackendDir '.env'
    $frontendEnv = Join-Path $FrontendDir '.env'

    Write-Host ""
    Write-Host "Backend  ($backendEnv):"  -ForegroundColor Yellow
    Write-Host (Get-EnvLabel $backendEnv) -ForegroundColor Green
    Write-Host ""
    Write-Host "Frontend ($frontendEnv):" -ForegroundColor Yellow
    Write-Host (Get-EnvLabel $frontendEnv) -ForegroundColor Green
    Write-Host ""
    Write-Host "Available environments: local, staging, production" -ForegroundColor Gray
    Write-Host "Pakai: .\scripts\switch-env.ps1 <env>"             -ForegroundColor Gray
    Write-Host ""
}

function Show-Help {
    Write-Header "HRIS Environment Switcher"
    Write-Host ""
    Write-Host "USAGE:"
    Write-Host "  .\scripts\switch-env.ps1 <env>"
    Write-Host ""
    Write-Host "ENVIRONMENTS (database center):"
    Write-Host "  local       Develop di laptop (DB: localhost)"
    Write-Host "  staging     Server staging   (DB: staging server)"
    Write-Host "  production  Server live      (DB: production server)"
    Write-Host "  status      Lihat env aktif sekarang"
    Write-Host ""
    Write-Host "EXAMPLE:"
    Write-Host "  .\scripts\switch-env.ps1 local"
    Write-Host "  .\scripts\switch-env.ps1 staging"
    Write-Host ""
}

function Resolve-Source {
    param(
        [string]$TemplateDir,
        [string]$EnvName
    )
    $real    = Join-Path $TemplateDir "$EnvName.env"
    $example = Join-Path $TemplateDir "$EnvName.env.example"

    if (Test-Path $real)    { return @{ Path = $real;    IsExample = $false } }
    if (Test-Path $example) { return @{ Path = $example; IsExample = $true  } }
    return $null
}

function Switch-One {
    param(
        [string]$Label,
        [string]$TemplateDir,
        [string]$TargetDir,
        [string]$EnvName
    )

    Write-Host ""
    Write-Host "-> $Label" -ForegroundColor Cyan

    $src = Resolve-Source -TemplateDir $TemplateDir -EnvName $EnvName
    if ($null -eq $src) {
        Write-Host "  [X] Tidak ada template '$EnvName.env' atau '$EnvName.env.example' di $TemplateDir" -ForegroundColor Red
        return $false
    }

    $target = Join-Path $TargetDir '.env'
    $backup = Join-Path $TargetDir '.env.backup'

    # Backup existing
    if (Test-Path $target) {
        Copy-Item -Path $target -Destination $backup -Force
        Write-Host "  - Backup: .env -> .env.backup" -ForegroundColor DarkGray
    }

    Copy-Item -Path $src.Path -Destination $target -Force
    $srcName = Split-Path $src.Path -Leaf
    Write-Host "  [OK] Active: $srcName -> .env" -ForegroundColor Green

    if ($src.IsExample -and $EnvName -ne 'local') {
        Write-Host "  [!] Pakai .example -- secret masih placeholder <REPLACE_*>." -ForegroundColor Yellow
        Write-Host "      Untuk pakai secret real, copy:" -ForegroundColor Yellow
        Write-Host "        $TemplateDir\$EnvName.env.example -> $TemplateDir\$EnvName.env" -ForegroundColor Yellow
        Write-Host "      Lalu isi nilainya, dan jalankan ulang script ini." -ForegroundColor Yellow
    }

    return $true
}

# --- Main ----------------------------------------------------------

if ([string]::IsNullOrWhiteSpace($Environment)) {
    Show-Help
    Show-Status
    exit 0
}

if ($Environment -eq 'status') {
    Show-Status
    exit 0
}

Write-Header "Switching HRIS environment -> $Environment"

$okBackend  = Switch-One -Label "Backend  (CodeIgniter 4)" -TemplateDir $BackendTpl  -TargetDir $BackendDir  -EnvName $Environment
$okFrontend = Switch-One -Label "Frontend (Vue 3 + Vite)"  -TemplateDir $FrontendTpl -TargetDir $FrontendDir -EnvName $Environment

Write-Host ""
if ($okBackend -and $okFrontend) {
    Write-Host "[OK] Environment '$Environment' aktif." -ForegroundColor Green

    if ($Environment -eq 'production') {
        Write-Host ""
        Write-Host "[!] PERHATIAN -- environment PRODUCTION:" -ForegroundColor Red
        Write-Host "    * Boot guard akan reject kalau secret masih default."  -ForegroundColor Yellow
        Write-Host "    * Pastikan production.env sudah berisi secret real."   -ForegroundColor Yellow
        Write-Host "    * Backup database sebelum migrate."                    -ForegroundColor Yellow
    }

    Write-Host ""
    Write-Host "Next step:" -ForegroundColor Cyan
    Write-Host "  cd backend; php spark migrate"
    Write-Host "  cd frontend; npm run dev"
    Write-Host ""
    exit 0
} else {
    Write-Host "[X] Switch gagal. Lihat pesan error di atas." -ForegroundColor Red
    exit 1
}