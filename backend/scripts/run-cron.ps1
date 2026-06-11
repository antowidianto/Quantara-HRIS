<#
.SYNOPSIS
  Quantara HRIS - local cron runner (Windows / PowerShell).

.DESCRIPTION
  Menjalankan semua command Spark sesuai jadwal mereka. Cocok untuk dev /
  staging Windows atau "manual ops mode" tanpa setup Task Scheduler.

  Logic dispatch:
    - Tanpa flag    -> cek jam saat ini, run command yang due hari ini
    - -Force        -> jalankan SEMUA daily command (skip schedule check)
    - -Category     -> run kategori spesifik saja (lihat -List)
    - -List         -> list semua command + jadwal, lalu exit
    - -DryRun       -> tampil saja, tidak eksekusi
    - -IncludeBackup-> include command yang butuh S3/GPG (default skip)

  Default behavior skip command yang butuh service eksternal (S3, GPG).
  Pakai -IncludeBackup kalau setup dev sudah punya AWS CLI + S3 dev bucket.

.EXAMPLE
  .\scripts\run-cron.ps1
  .\scripts\run-cron.ps1 -Force
  .\scripts\run-cron.ps1 -Category billing
  .\scripts\run-cron.ps1 -List
  .\scripts\run-cron.ps1 -DryRun

.NOTES
  Dokumentasi lengkap: docs/CRON_JOBS.md
  Note: pakai output ASCII-only (tanpa karakter unicode/box-drawing) supaya
  kompatibel dengan Windows PowerShell 5 default code page (CP-1252).
#>

[CmdletBinding()]
param(
    [switch]$Force,
    [switch]$DryRun,
    [switch]$IncludeBackup,
    [switch]$List,
    [string]$Category = ''
)

# Resolve project root (parent dari folder scripts)
$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$BackendDir = Split-Path -Parent $ScriptRoot
Set-Location $BackendDir

$LogDir = Join-Path $BackendDir 'writable\cron-out'
if (! (Test-Path $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
}

# Definisi semua cron job.
# Tiap entry: name, command, hour, dayOfWeek (0=Min..6=Sab, * = setiap hari),
#             dayOfMonth (* / 1), category, requiresExternal
$Jobs = @(
    # HOURLY
    @{ N='helpdesk-sla'; C='helpdesk:check-sla';
       Hour='*'; Dow='*'; Dom='*'; Cat='hourly'; Ext=$false },

    # DAILY 02:00 - Backup (skip default kecuali -IncludeBackup)
    @{ N='db-backup'; C='db:backup-encrypted --cleanup';
       Hour=2; Dow='*'; Dom='*'; Cat='backup'; Ext=$true },

    # DAILY 03:30..03:55 - Compliance & cleanup
    @{ N='check-bpjs';        C='hr:check-bpjs';
       Hour=3; Dow='*'; Dom='*'; Cat='compliance'; Ext=$false },
    @{ N='archive-audit';     C='hr:archive-audit';
       Hour=3; Dow='*'; Dom='*'; Cat='compliance'; Ext=$false },
    @{ N='cleanup-attach';    C='hr:cleanup-attachments';
       Hour=3; Dow='*'; Dom='*'; Cat='compliance'; Ext=$false },
    @{ N='purge-idempotency'; C='idempotency:purge';
       Hour=3; Dow='*'; Dom='*'; Cat='maintenance'; Ext=$false },

    # DAILY 01:00 - Cuti carry-over berbasis tanggal join
    @{ N='leave-anniversary-carry'; C='hr:leave-anniversary-carry';
       Hour=1; Dow='*'; Dom='*'; Cat='hr-daily'; Ext=$false },

    # DAILY 05:00 - Cache warmup
    @{ N='cache-warm'; C='cache:warm';
       Hour=5; Dow='*'; Dom='*'; Cat='maintenance'; Ext=$false },

    # DAILY 06:00..06:35 - HR Operasional 9 command
    @{ N='check-expiry';        C='hr:check-expiry';
       Hour=6; Dow='*'; Dom='*'; Cat='hr-daily'; Ext=$false },
    @{ N='check-probation';     C='hr:check-probation';
       Hour=6; Dow='*'; Dom='*'; Cat='hr-daily'; Ext=$false },
    @{ N='check-cert-expiry';   C='hr:check-cert-expiry';
       Hour=6; Dow='*'; Dom='*'; Cat='hr-daily'; Ext=$false },
    @{ N='notify-carry-over';   C='hr:notify-carry-over-cap';
       Hour=6; Dow='*'; Dom='*'; Cat='hr-daily'; Ext=$false },
    @{ N='check-anniversary';   C='hr:check-anniversary';
       Hour=6; Dow='*'; Dom='*'; Cat='hr-daily'; Ext=$false },
    @{ N='apply-jic';           C='hr:apply-jic';
       Hour=6; Dow='*'; Dom='*'; Cat='hr-daily'; Ext=$false },
    @{ N='apply-transfers';     C='hr:apply-transfers';
       Hour=6; Dow='*'; Dom='*'; Cat='hr-daily'; Ext=$false },
    @{ N='deactivate-resigned'; C='hr:deactivate-resigned';
       Hour=6; Dow='*'; Dom='*'; Cat='hr-daily'; Ext=$false },

    # DAILY 07:00 - Recruitment
    @{ N='check-offer-expiry'; C='hr:check-offer-expiry';
       Hour=7; Dow='*'; Dom='*'; Cat='recruitment'; Ext=$false },
    @{ N='check-mpp-overdue';  C='hr:check-mpp-overdue';
       Hour=7; Dow='*'; Dom='*'; Cat='recruitment'; Ext=$false },

    # DAILY 08:00 - SaaS Billing
    @{ N='billing-dunning'; C='billing:dunning';
       Hour=8; Dow='*'; Dom='*'; Cat='billing'; Ext=$false },

    # WEEKLY Senin 04:00..06:30 - Security & audit
    @{ N='backup-verify';        C='db:backup-verify --all-recent';
       Hour=4; Dow=1; Dom='*'; Cat='backup'; Ext=$true },
    @{ N='audit-verify-chain';   C='audit:verify-chain';
       Hour=4; Dow=1; Dom='*'; Cat='security'; Ext=$false },
    @{ N='scan-tenant-leaks';    C='security:scan-tenant-leaks';
       Hour=5; Dow=1; Dom='*'; Cat='security'; Ext=$false },
    @{ N='cs-health-scores';     C='cs:compute-health-scores';
       Hour=5; Dow=1; Dom='*'; Cat='cs'; Ext=$false },
    @{ N='hr-audit';             C='hr:audit';
       Hour=6; Dow=1; Dom='*'; Cat='security'; Ext=$false },
    @{ N='sync-permissions';     C='hr:sync-permissions';
       Hour=6; Dow=1; Dom='*'; Cat='maintenance'; Ext=$false },

    # MONTHLY tanggal 1 - Period reset
    @{ N='billing-reset-meters'; C='billing:reset-monthly-meters';
       Hour=0; Dow='*'; Dom=1; Cat='billing'; Ext=$false },

    # 1 Januari - Year-end carry-over
    @{ N='leave-yearend'; C='hr:leave-yearend';
       Hour=0; Dow='*'; Dom=1; Cat='yearly'; Ext=$false }
)

# Helper: format schedule
function Format-Schedule($job) {
    $hour = if ($job.Hour -eq '*') { '*' } else { $job.Hour.ToString('00') + ':xx' }
    $dow  = if ($job.Dow -eq '*') { 'every day' } else { (@('Min','Sen','Sel','Rab','Kam','Jum','Sab')[$job.Dow]) }
    $dom  = if ($job.Dom -eq '*') { '' } else { " (tanggal $($job.Dom))" }
    return "$hour $dow$dom"
}

# -List mode: tampilkan jadwal lalu exit
if ($List) {
    Write-Host ''
    Write-Host '======================================================================' -ForegroundColor Cyan
    Write-Host '  Quantara HRIS - Cron Schedule (Local Dev Mode)' -ForegroundColor Cyan
    Write-Host '======================================================================' -ForegroundColor Cyan
    Write-Host ''
    $Jobs | Sort-Object Cat, Hour | ForEach-Object {
        $extMark = if ($_.Ext) { ' [needs S3/GPG]' } else { '' }
        Write-Host (' [{0,-12}] {1,-25} -> spark {2}{3}' -f $_.Cat, $_.N, $_.C, $extMark)
        Write-Host ('              schedule: ' + (Format-Schedule $_)) -ForegroundColor DarkGray
    }
    Write-Host ''
    Write-Host "Total: $($Jobs.Count) job" -ForegroundColor Cyan
    exit 0
}

# Filter jobs sesuai context
$Now = Get-Date
$NowHour = $Now.Hour
$NowDow  = [int]$Now.DayOfWeek    # 0=Sunday..6=Saturday
$NowDom  = $Now.Day

$ToRun = $Jobs | Where-Object {
    if ($Category -and $_.Cat -ne $Category) { return $false }
    if ($_.Ext -and -not $IncludeBackup) { return $false }
    if ($Force) { return $true }

    $hourOk = ($_.Hour -eq '*') -or ($_.Hour -eq $NowHour)
    if (-not $hourOk) { return $false }
    $dowOk = ($_.Dow -eq '*') -or ($_.Dow -eq $NowDow)
    if (-not $dowOk) { return $false }
    $domOk = ($_.Dom -eq '*') -or ($_.Dom -eq $NowDom)
    if (-not $domOk) { return $false }
    return $true
}

if (-not $ToRun) {
    Write-Host ''
    Write-Host "Tidak ada cron yang due saat ini ($($Now.ToString('yyyy-MM-dd HH:mm'))). Pakai -Force untuk skip schedule, atau -List untuk lihat semua." -ForegroundColor Yellow
    exit 0
}

# Eksekusi
$results = @()
$startAll = Get-Date

$modeStr = if ($DryRun) { 'DRY-RUN' } elseif ($Force) { 'FORCE (all due)' } else { 'auto-schedule' }

Write-Host ''
Write-Host '======================================================================' -ForegroundColor Cyan
Write-Host ("  Quantara Cron Runner  -  {0}" -f $startAll.ToString('yyyy-MM-dd HH:mm:ss')) -ForegroundColor Cyan
Write-Host ("  Jobs to run: {0}    Mode: {1}" -f $ToRun.Count, $modeStr) -ForegroundColor Cyan
Write-Host '======================================================================' -ForegroundColor Cyan
Write-Host ''

foreach ($job in $ToRun) {
    $start = Get-Date
    $logFile = Join-Path $LogDir ("{0:yyyy-MM-dd}_{1}.log" -f $start, $job.N)

    Write-Host (" > {0,-25} " -f $job.N) -NoNewline -ForegroundColor White
    Write-Host ("[{0}]" -f $job.Cat) -NoNewline -ForegroundColor DarkGray
    Write-Host (" spark {0}" -f $job.C) -ForegroundColor DarkCyan

    if ($DryRun) {
        Write-Host '   (dry-run, skipped)' -ForegroundColor Yellow
        $results += [pscustomobject]@{
            Job = $job.N; Status = 'SKIP'; Duration = '-'; Log = '-'
        }
        continue
    }

    $argList = $job.C -split ' '
    $proc = Start-Process -FilePath 'php' `
        -ArgumentList (@('spark') + $argList) `
        -NoNewWindow -PassThru -Wait `
        -RedirectStandardOutput $logFile `
        -RedirectStandardError "$logFile.err"

    $end = Get-Date
    $dur = ($end - $start).TotalSeconds

    if ($proc.ExitCode -eq 0) {
        Write-Host ("   [OK] done in {0:N1}s" -f $dur) -ForegroundColor Green
        $status = 'OK'
    } else {
        Write-Host ("   [FAIL] exit {0}, {1:N1}s -> {2}.err" -f $proc.ExitCode, $dur, $logFile) -ForegroundColor Red
        $status = "FAIL($($proc.ExitCode))"
    }

    $results += [pscustomobject]@{
        Job = $job.N
        Status = $status
        Duration = ('{0:N1}s' -f $dur)
        Log = $logFile
    }
}

# Summary
$totalDur = (Get-Date) - $startAll
Write-Host ''
Write-Host '======================================================================' -ForegroundColor Cyan
Write-Host ("  Summary - total {0} jobs - elapsed {1:N1}s" -f $ToRun.Count, $totalDur.TotalSeconds) -ForegroundColor Cyan
Write-Host '======================================================================' -ForegroundColor Cyan
$results | Format-Table -AutoSize

$failed = $results | Where-Object { $_.Status -like 'FAIL*' }
if ($failed) {
    Write-Host ("[WARN] {0} job(s) gagal - cek log di {1}" -f $failed.Count, $LogDir) -ForegroundColor Red
    exit 1
}
Write-Host '[OK] semua job sukses' -ForegroundColor Green
exit 0