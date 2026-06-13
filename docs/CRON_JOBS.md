# Quantara HRIS — Cron Jobs Reference

Dokumentasi ini berisi **semua command Spark CLI** yang harus dijalankan secara
periodik di server. Mencakup operasional HR (kontrak, cuti, sertifikat), keamanan
(audit, backup, encryption rotation), SaaS billing (subscription, quota,
dunning), dan customer success (health scores).

> **Konvensi**: Semua command dijalankan dari `backend/` directory dengan
> `php spark <name>`. Set timezone server ke `Asia/Jakarta` supaya semua jadwal
> sinkron dengan PMK 168, BPJS deadline, payroll cycle Indonesia.

---

## Daftar Isi

1. [Setup Awal](#setup-awal)
2. [Kategori Command](#kategori-command)
3. [Local Development (Windows / macOS / Linux)](#local-development)
4. [Crontab Lengkap (Linux Production)](#crontab-lengkap-linux)
5. [Windows Task Scheduler](#windows-task-scheduler)
6. [Docker / Kubernetes](#docker--kubernetes)
7. [Reference Per Command](#reference-per-command)
8. [Troubleshooting](#troubleshooting)
9. [Monitoring & Alerting](#monitoring--alerting)

---

## Setup Awal

### Prasyarat

```bash
# Pastikan timezone server benar (krusial untuk payroll cutoff & BPJS)
sudo timedatectl set-timezone Asia/Jakarta
date    # verifikasi

# Pastikan PHP CLI bisa akses backend
which php       # /usr/bin/php8.2 (atau versi yang sama dengan production)
cd /var/www/quantara-hris/backend
php spark --version   # verifikasi spark bisa load env
```

### Variabel `.env` yang dipakai cron

Beberapa command butuh variabel khusus. Tambahkan ke `backend/.env` production:

```ini
# Backup encryption (S2.3)
BACKUP_GPG_RECIPIENT=ops@quantara.io
BACKUP_S3_BUCKET=quantara-backups-prod
BACKUP_RETENTION_DAILY_DAYS=30
BACKUP_RETENTION_WEEKLY_WEEKS=12
BACKUP_RETENTION_MONTHLY_MONTHS=24

# Webhook & email queue
QUEUE_BATCH_SIZE=50
WEBHOOK_RETRY_BACKOFF=2

# UU PDP retention (S2.3)
PDP_RETENTION_YEARS=7

# Encryption rotation
ENCRYPTION_ACTIVE_KEY_VERSION=v2
```

### Struktur log

Semua command menulis ke `backend/writable/logs/log-YYYY-MM-DD.log` plus
output stdout. Disarankan redirect stdout ke `cron-out/<name>.log` untuk
memudahkan debugging.

```bash
mkdir -p /var/log/quantara-cron
chown www-data:www-data /var/log/quantara-cron
```

---

## Kategori Command

| Kategori | Frekuensi tipikal | Critical? | Jumlah command |
| --- | --- | --- | --- |
| **HR Operasional** (kontrak, cuti, anniversary) | Daily 06:00 | High | 9 |
| **Recruitment** (offer, MPP) | Daily 07:00 | Medium | 2 |
| **Compliance** (BPJS, audit, PDP) | Daily 03:00 + weekly | High | 4 |
| **Security** (encryption, audit chain, tenant scan) | Weekly + on-demand | Critical | 3 |
| **Backup & DR** (db backup, verify) | Daily 02:00 + weekly | Critical | 2 |
| **SaaS Billing** (subscription, quota, dunning, renewals, trial reminders) | Daily + monthly | Critical | 5 |
| **Customer Success** | Weekly | Medium | 1 |
| **Helpdesk SLA** | Hourly | High | 1 |
| **Queue & Email** | Continuous (daemon) | Critical | 3 |
| **Maintenance** (cleanup, cache, sync) | Daily + on-demand | Low | 4 |

Total: **35 command**.

---

## Local Development

Untuk dev/staging Anda **tidak perlu setup crontab/systemd**. Ada 3 cara
menjalankan cron secara lokal: manual on-demand, wrapper script semi-otomatis,
atau Task Scheduler/launchd untuk yang ingin "production-like".

### Cara 1 — Manual on-demand (paling simpel, untuk debugging)

Cukup buka terminal di `backend/` lalu jalankan command yang ingin di-test:

**Windows (PowerShell):**

```powershell
cd "D:\Apps Project\hris-project-1\backend"

# Test 1 command spesifik
php spark hr:check-expiry
php spark billing:dunning
php spark cs:compute-health-scores

# Lihat semua command yang tersedia
php spark list
php spark help hr:check-expiry
```

**macOS / Linux (bash):**

```bash
cd ~/projects/hris-project-1/backend
php spark hr:check-expiry
```

Karena semua command di-design **idempotent** (aman di-run berulang) dan
**stateful** (cek `last_run_at` atau status table), dipanggil multiple kali
tidak bikin duplikat data. Cocok untuk verify behavior step-by-step.

### Cara 2 — Wrapper script "run all due jobs" (recommended untuk dev)

Saya sudah buat script `backend/scripts/run-cron.ps1` (Windows) dan
`backend/scripts/run-cron.sh` (Unix) yang menjalankan **semua** cron sesuai
jadwal mereka. Jalankan saja sekali dalam sehari (atau set Task Scheduler /
launchd / cron host untuk panggil hourly), dan script auto-decide mana yang
harus jalan berdasarkan jam saat ini.

**Windows (PowerShell):**

```powershell
cd "D:\Apps Project\hris-project-1\backend"

# Run semua cron yang due saat ini (dispatch berdasarkan jam saat ini)
.\scripts\run-cron.ps1

# Force run semua daily jobs (skip schedule check)
.\scripts\run-cron.ps1 -Force

# Run kategori spesifik saja
.\scripts\run-cron.ps1 -Category billing
.\scripts\run-cron.ps1 -Category hr-daily
```

**macOS / Linux:**

```bash
cd backend
chmod +x scripts/run-cron.sh
./scripts/run-cron.sh
./scripts/run-cron.sh --force
./scripts/run-cron.sh --category billing
```

Output script: tabel ringkasan jam start/end, exit code, dan path log per
command. Cocok untuk demo ke stakeholder atau debug regression.

> **Catatan**: script ini **bukan untuk produksi**. Production wajib pakai
> systemd cron (Linux) atau Kubernetes CronJob untuk reliability + observability.

### Cara 3 — Windows Task Scheduler (untuk dev "production-like")

Bila Anda mau benar-benar otomatis di Windows tanpa server Linux, lihat
section [Windows Task Scheduler](#windows-task-scheduler) di bawah. Pilih
salah satu pendekatan:

- **Single hourly task** yang panggil `run-cron.ps1` (paling simpel, 1 task)
- **Per-command task** untuk granular control (33 task — overkill untuk dev)

### Verifikasi cron lokal benar-benar jalan

Setelah panggil command, cek 3 indikator:

1. **Audit log** — semua cron write ke `audit_logs`. Buka HRIS lalu masuk ke
   `Admin → Audit Logs`, filter `module=CRON` atau pakai SQL:

   ```sql
   SELECT action, severity, description, created_at
   FROM audit_logs
   WHERE module IN ('CRON','BACKUP','BILLING','TENANT_PROVISIONING')
   ORDER BY created_at DESC
   LIMIT 20;
   ```

2. **Log file** — `backend/writable/logs/log-YYYY-MM-DD.log` punya entry baru.

3. **Side-effect tabel** — mis. setelah `hr:check-expiry`, cek
   `notifications` table ada row baru kategori `contract_expiry`.

### Skip jobs yang butuh service eksternal

Beberapa command butuh service eksternal yang tidak ada di local dev:

| Command | Butuh | Workaround dev |
| --- | --- | --- |
| `db:backup-encrypted` | mysqldump + GPG + AWS CLI + S3 bucket | Skip atau pakai `BACKUP_S3_BUCKET=` kosong + flag `--local-only` (kalau didukung) |
| `db:backup-verify` | S3 bucket | Skip |
| `email:process` / `webhooks:process` | SMTP / public webhook URL | Pakai Mailtrap, Mailpit, ngrok |
| `queue:work` | Bisa jalan local, tapi tidak persistent | Run manual `php spark queue:work --once` untuk test |

`run-cron.ps1` / `run-cron.sh` sudah skip command yang butuh service eksternal
secara default. Pakai flag `-IncludeBackup` kalau memang sudah setup S3 dev.

---

## Crontab Lengkap (Linux Production)

Edit crontab user `www-data`:

```bash
sudo -u www-data crontab -e
```

Paste blok berikut. Sesuaikan path absolut `/var/www/quantara-hris/backend`
dengan installasi Anda.

```cron
# ─────────────────────────────────────────────────────────────────────────────
# Quantara HRIS — Production Cron Schedule
# Timezone: Asia/Jakarta (server). Pastikan timezone benar dengan `date`.
# Path absolut harus dipakai supaya cron bisa locate php + spark.
# ─────────────────────────────────────────────────────────────────────────────

# Common
HRIS_DIR=/var/www/quantara-hris/backend
PHP=/usr/bin/php
LOG_DIR=/var/log/quantara-cron
MAILTO=ops@quantara.io

# ═════════════════════════════════════════════════════════════════════════════
# HOURLY — Helpdesk SLA & queue health
# ═════════════════════════════════════════════════════════════════════════════

# Cek SLA helpdesk: warning H-2 jam + eskalasi overdue tickets
0 * * * * cd $HRIS_DIR && $PHP spark helpdesk:check-sla >> $LOG_DIR/helpdesk-sla.log 2>&1

# ═════════════════════════════════════════════════════════════════════════════
# EVERY 15 MINUTES — Email & webhook delivery (kalau queue worker tidak running)
# Skip ini bila Anda jalanin queue:work sebagai daemon (lihat Daemon section).
# ═════════════════════════════════════════════════════════════════════════════

# */15 * * * * cd $HRIS_DIR && $PHP spark email:process    >> $LOG_DIR/email-queue.log    2>&1
# */15 * * * * cd $HRIS_DIR && $PHP spark webhooks:process >> $LOG_DIR/webhook-queue.log 2>&1

# ═════════════════════════════════════════════════════════════════════════════
# DAILY — HR Operasional (06:00 — sebelum jam kerja, supaya notif sampai pagi)
# ═════════════════════════════════════════════════════════════════════════════

# Cek kontrak/probation/dokumen yang akan berakhir
0 6 * * * cd $HRIS_DIR && $PHP spark hr:check-expiry         >> $LOG_DIR/check-expiry.log         2>&1

# Probation PKWTT yang akan/sudah selesai (auto-reminder + auto-pass kalau lewat)
5 6 * * * cd $HRIS_DIR && $PHP spark hr:check-probation      >> $LOG_DIR/check-probation.log      2>&1

# Sertifikat (BPJS Kesehatan, K3, Jamsostek operator) yang expire
10 6 * * * cd $HRIS_DIR && $PHP spark hr:check-cert-expiry   >> $LOG_DIR/check-cert.log           2>&1

# Notif H-30 cuti yang melebihi cap carry-over
15 6 * * * cd $HRIS_DIR && $PHP spark hr:notify-carry-over-cap >> $LOG_DIR/carry-over.log         2>&1

# Birthday + work anniversary push notif
20 6 * * * cd $HRIS_DIR && $PHP spark hr:check-anniversary   >> $LOG_DIR/anniversary.log          2>&1

# Apply JIC (promosi/demosi/mutasi) yang APPROVED & effective_date <= hari ini
25 6 * * * cd $HRIS_DIR && $PHP spark hr:apply-jic           >> $LOG_DIR/apply-jic.log            2>&1

# Apply mutasi karyawan yang APPROVED & effective_date <= hari ini
30 6 * * * cd $HRIS_DIR && $PHP spark hr:apply-transfers     >> $LOG_DIR/apply-transfers.log      2>&1

# Deactivate karyawan resign yang resign_date sudah lewat
35 6 * * * cd $HRIS_DIR && $PHP spark hr:deactivate-resigned >> $LOG_DIR/deactivate-resign.log    2>&1

# ═════════════════════════════════════════════════════════════════════════════
# DAILY — Recruitment (07:00 — setelah HR operasional, sebelum hiring kickoff)
# ═════════════════════════════════════════════════════════════════════════════

# Auto-expire candidate offers yang lewat response_deadline
0 7 * * * cd $HRIS_DIR && $PHP spark hr:check-offer-expiry   >> $LOG_DIR/check-offer.log          2>&1

# Reminder + eskalasi MPP overdue
5 7 * * * cd $HRIS_DIR && $PHP spark hr:check-mpp-overdue    >> $LOG_DIR/check-mpp.log            2>&1

# ═════════════════════════════════════════════════════════════════════════════
# DAILY — Compliance & Backup (02:00 — off-hours, low traffic)
# ═════════════════════════════════════════════════════════════════════════════

# Backup database (mysqldump | gzip | GPG | S3). CRITICAL — set alert on fail.
0 2 * * * cd $HRIS_DIR && $PHP spark db:backup-encrypted --cleanup >> $LOG_DIR/db-backup.log 2>&1

# Cek SLA BPJS register/deactivate yang lewat 14 hari kerja
30 3 * * * cd $HRIS_DIR && $PHP spark hr:check-bpjs          >> $LOG_DIR/check-bpjs.log           2>&1

# Auto-archive audit_logs > 1 tahun (set archived_at, jangan delete)
45 3 * * * cd $HRIS_DIR && $PHP spark hr:archive-audit       >> $LOG_DIR/archive-audit.log        2>&1

# Cleanup attachment selfie absensi sesuai retention per company
50 3 * * * cd $HRIS_DIR && $PHP spark hr:cleanup-attachments >> $LOG_DIR/cleanup-attach.log       2>&1

# Purge idempotency keys yang sudah expired (TTL 24h)
55 3 * * * cd $HRIS_DIR && $PHP spark idempotency:purge      >> $LOG_DIR/purge-idem.log           2>&1

# ═════════════════════════════════════════════════════════════════════════════
# DAILY — SaaS Billing (00:00, 08:00, 09:00)
# ═════════════════════════════════════════════════════════════════════════════

# Subscription auto-renewal + trial expiry → PAST_DUE + hard-cancel PAST_DUE>30d
0 0 * * * cd $HRIS_DIR && $PHP spark billing:check-renewals  >> $LOG_DIR/billing-renewals.log     2>&1

# Subscription dunning: email reminder H+1, suspend H+10, hard-cancel H+30
0 8 * * * cd $HRIS_DIR && $PHP spark billing:dunning         >> $LOG_DIR/billing-dunning.log      2>&1

# Trial expiry reminder email H-7/H-3/H-1 (dedup via subscription_events)
0 9 * * * cd $HRIS_DIR && $PHP spark billing:trial-expiry-notify >> $LOG_DIR/billing-trial-notify.log 2>&1

# ═════════════════════════════════════════════════════════════════════════════
# WEEKLY — Security audit & verification
# ═════════════════════════════════════════════════════════════════════════════

# Verifikasi backup integritas (file exists + sha256 + gzip valid) — Senin 04:00
0 4 * * 1 cd $HRIS_DIR && $PHP spark db:backup-verify --all-recent >> $LOG_DIR/backup-verify.log 2>&1

# Verify hash chain audit_logs (deteksi tamper) — Senin 04:30
30 4 * * 1 cd $HRIS_DIR && $PHP spark audit:verify-chain     >> $LOG_DIR/audit-chain.log          2>&1

# Multi-tenant leak scan (heuristic static scan) — Senin 05:00
0 5 * * 1 cd $HRIS_DIR && $PHP spark security:scan-tenant-leaks --strict >> $LOG_DIR/tenant-leak.log 2>&1

# Customer health scores recompute — Senin 05:30
30 5 * * 1 cd $HRIS_DIR && $PHP spark cs:compute-health-scores >> $LOG_DIR/cs-health.log         2>&1

# System audit (storage, DB, security, operasional) — Senin 06:00
0 6 * * 1 cd $HRIS_DIR && $PHP spark hr:audit                >> $LOG_DIR/system-audit.log         2>&1

# Sync permission baru ke role default (kalau ada permission slug baru di code)
30 6 * * 1 cd $HRIS_DIR && $PHP spark hr:sync-permissions    >> $LOG_DIR/sync-perm.log            2>&1

# ═════════════════════════════════════════════════════════════════════════════
# MONTHLY — Period-based jobs
# ═════════════════════════════════════════════════════════════════════════════

# Reset usage_meters monthly (S1.4) — tanggal 1 jam 00:30
30 0 1 * * cd $HRIS_DIR && $PHP spark billing:reset-monthly-meters >> $LOG_DIR/billing-reset.log 2>&1

# Carry-over saldo cuti tahun lalu → tahun berjalan (1 Januari 00:30)
30 0 1 1 * cd $HRIS_DIR && $PHP spark hr:leave-yearend       >> $LOG_DIR/leave-yearend.log        2>&1

# Carry-over saldo cuti berbasis tanggal join (UU 13/2003 Pasal 79) — daily check
0 1 * * * cd $HRIS_DIR && $PHP spark hr:leave-anniversary-carry >> $LOG_DIR/leave-anniv.log 2>&1

# ═════════════════════════════════════════════════════════════════════════════
# QUARTERLY / RARE — Manual or low-frequency
# ═════════════════════════════════════════════════════════════════════════════

# UU PDP data purge — anonymize karyawan resign > 7 tahun (1 Januari + 1 Juli)
0 2 1 1,7 * cd $HRIS_DIR && $PHP spark hr:pdp-data-purge --confirm >> $LOG_DIR/pdp-purge.log 2>&1

# Encryption key rotation — re-encrypt PII pakai key v terbaru (jadwal manual)
# Disarankan lakukan setelah update ENCRYPTION_ACTIVE_KEY_VERSION di .env.
# 0 3 15 * * cd $HRIS_DIR && $PHP spark hr:rotate-encryption  >> $LOG_DIR/encrypt-rotate.log 2>&1

# Cache warmup (lookup tables) — setelah deploy. Bisa juga jam off-peak.
0 5 * * * cd $HRIS_DIR && $PHP spark cache:warm              >> $LOG_DIR/cache-warm.log           2>&1
```

---

## Daemon: Queue Worker

`queue:work` adalah daemon yang seharusnya **selalu running** untuk memproses
job async (kirim email, webhook outbound, export data, dll). **Bukan cron**,
tapi service. Pakai `systemd` di Linux.

### Setup systemd unit

Buat file `/etc/systemd/system/quantara-queue.service`:

```ini
[Unit]
Description=Quantara HRIS Queue Worker
After=mysql.service redis.service
Wants=mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/quantara-hris/backend
ExecStart=/usr/bin/php spark queue:work --daemon --queue=default --sleep=3 --tries=3
Restart=always
RestartSec=5
StartLimitInterval=0

# Resource limits
LimitNOFILE=65536
MemoryMax=512M

# Logging — capture stdout/stderr
StandardOutput=append:/var/log/quantara-cron/queue-worker.log
StandardError=append:/var/log/quantara-cron/queue-worker.err.log

[Install]
WantedBy=multi-user.target
```

Enable + start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable quantara-queue
sudo systemctl start quantara-queue
sudo systemctl status quantara-queue   # cek running

# Tail log
sudo journalctl -u quantara-queue -f
```

Untuk multi-queue (high-prio + low-prio):

```bash
# Buat unit kedua: quantara-queue-low.service dengan --queue=low,bulk
# Worker akan compete consume jobs.
```

### Restart worker setelah deploy

Code yang berubah tidak otomatis ter-load oleh daemon. Restart wajib:

```bash
sudo systemctl restart quantara-queue
```

Atau pakai `--max-jobs=1000` supaya worker auto-exit setelah X job dan systemd
restart-nya, sehingga code fresh.

---

## Windows Task Scheduler

Untuk dev/staging di Windows, pakai Task Scheduler. Contoh untuk
`hr:check-expiry` daily 06:00:

```powershell
# PowerShell admin
$Action = New-ScheduledTaskAction `
    -Execute "php" `
    -Argument "spark hr:check-expiry" `
    -WorkingDirectory "D:\Apps Project\hris-project-1\backend"

$Trigger = New-ScheduledTaskTrigger -Daily -At 6:00am

$Principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -RunLevel Highest

Register-ScheduledTask `
    -TaskName "Quantara - Check Contract Expiry" `
    -Action $Action `
    -Trigger $Trigger `
    -Principal $Principal `
    -Description "Cek kontrak/probation/dokumen yang akan berakhir"
```

Repeat untuk command lain. Atau pakai **wrapper script** `scripts/cron-all.ps1`
yang dipanggil hourly + dispatch sub-command berdasarkan jam.

---

## Docker / Kubernetes

### docker-compose

Tambahkan service `cron` di `docker-compose.prod.yml`:

```yaml
services:
  cron:
    image: quantara-hris-backend:latest
    command: ["crond", "-f", "-l", "8"]
    volumes:
      - ./backend:/var/www/backend
      - ./crontab:/etc/cron.d/quantara
    environment:
      - APP_ENV=production
    depends_on:
      - mysql
      - redis
    restart: unless-stopped

  queue-worker:
    image: quantara-hris-backend:latest
    command: ["php", "/var/www/backend/spark", "queue:work", "--daemon"]
    deploy:
      replicas: 2
    restart: unless-stopped
```

File `crontab` (text):

```cron
0 6 * * * cd /var/www/backend && php spark hr:check-expiry
# ... etc
```

### Kubernetes CronJob (recommended for prod)

Setiap command jadi `CronJob` resource sendiri supaya bisa monitor exit code
per command, retry policy spesifik, dan resource limits berbeda.

Contoh `helm/cronjobs/db-backup.yaml`:

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: quantara-db-backup
spec:
  schedule: "0 2 * * *"
  timeZone: "Asia/Jakarta"
  concurrencyPolicy: Forbid
  startingDeadlineSeconds: 600
  successfulJobsHistoryLimit: 3
  failedJobsHistoryLimit: 5
  jobTemplate:
    spec:
      backoffLimit: 1
      activeDeadlineSeconds: 3600
      template:
        spec:
          restartPolicy: OnFailure
          containers:
            - name: backup
              image: quantara-hris-backend:latest
              command: ["php", "/var/www/backend/spark", "db:backup-encrypted", "--cleanup"]
              env:
                - name: BACKUP_S3_BUCKET
                  valueFrom:
                    secretKeyRef:
                      name: quantara-backup
                      key: s3-bucket
              resources:
                requests:
                  memory: 512Mi
                  cpu: 200m
                limits:
                  memory: 1Gi
                  cpu: 1000m
```

Folder `helm/cronjobs/` punya pattern serupa per command.

---

## Reference Per Command

### HR Operasional (9)

| Command | Schedule | Purpose | Failure Impact |
| --- | --- | --- | --- |
| `hr:check-expiry` | Daily 06:00 | Notif kontrak/probation/dokumen yang akan expire H-30/H-7 | HR tidak aware → karyawan kehilangan akses tanpa peringatan |
| `hr:check-probation` | Daily 06:05 | Reminder probation PKWTT + auto-pass kalau lewat tanggal | Status PKWTT salah, payroll comp salah |
| `hr:check-cert-expiry` | Daily 06:10 | Sertifikat (K3, BPJS Kesehatan operator) expire | Kepatuhan operasional terganggu |
| `hr:notify-carry-over-cap` | Daily 06:15 | Notif H-30 ke karyawan yang sisa cuti > cap | Karyawan kaget cuti hangus |
| `hr:check-anniversary` | Daily 06:20 | Push notif birthday + work anniversary | Engagement turun (kosmetik tapi dipakai) |
| `hr:apply-jic` | Daily 06:25 | Apply JIC APPROVED dengan effective_date ≤ today | Promosi/demosi tidak otomatis aktif → payroll lama |
| `hr:apply-transfers` | Daily 06:30 | Apply mutasi APPROVED dengan effective_date ≤ today | Branch/dept stale, attendance salah scope |
| `hr:deactivate-resigned` | Daily 06:35 | Deactivate user yang resign_date ≤ today | **CRITICAL** — ex-karyawan masih punya akses sistem (security risk) |
| `hr:leave-anniversary-carry` | Daily 01:00 | Carry-over saldo cuti berbasis tanggal join (UU 13/2003 Pasal 79) | Saldo cuti karyawan salah → klaim hukum |

### Recruitment (2)

| Command | Schedule | Purpose | Failure Impact |
| --- | --- | --- | --- |
| `hr:check-offer-expiry` | Daily 07:00 | Auto-EXPIRED offer yang lewat response_deadline | Offer stuck SENT, slot rekrut tertahan |
| `hr:check-mpp-overdue` | Daily 07:05 | Reminder MPP yang mendekati / melewati vacancy_started_at | Vacancy tidak ter-fill, manpower planning macet |

### Compliance (4)

| Command | Schedule | Purpose | Failure Impact |
| --- | --- | --- | --- |
| `hr:check-bpjs` | Daily 03:30 | SLA register/deactivate BPJS lewat 14 hari kerja | Denda BPJS, klaim ditolak |
| `hr:archive-audit` | Daily 03:45 | Archive audit_logs > retention | Tabel membengkak → query lambat |
| `hr:cleanup-attachments` | Daily 03:50 | Hapus selfie absensi lama sesuai retention per company | Storage penuh, biaya naik |
| `hr:pdp-data-purge` | 1 Jan + 1 Jul 02:00 | Anonymize PII karyawan resign > 7 tahun (UU PDP) | Pelanggaran UU PDP, denda hingga Rp 6M |

### Security (3)

| Command | Schedule | Purpose | Failure Impact |
| --- | --- | --- | --- |
| `audit:verify-chain` | Weekly Mon 04:30 | Verifikasi hash chain audit_logs (anti-tamper) | Audit log bisa di-tampering tanpa terdeteksi |
| `security:scan-tenant-leaks` | Weekly Mon 05:00 | Static scan multi-tenant isolation leak | Risk data leak antar tenant (catastrophic) |
| `hr:rotate-encryption` | Manual / quarterly | Re-encrypt PII pakai active key version baru | Old key compromise tetap baca data lama |

### Backup & DR (2)

| Command | Schedule | Purpose | Failure Impact |
| --- | --- | --- | --- |
| `db:backup-encrypted` | Daily 02:00 | mysqldump → gzip → GPG → S3 + cleanup expired | **CRITICAL** — data loss risk, RPO breach |
| `db:backup-verify` | Weekly Mon 04:00 | Verify file exists + sha256 + gzip integrity | Backup ada tapi corrupt → tidak bisa restore |

### SaaS Billing (5)

| Command | Schedule | Purpose | Failure Impact |
| --- | --- | --- | --- |
| `billing:check-renewals` | Daily 00:00 | Auto-renew ACTIVE + handle trial expiry → PAST_DUE + hard-cancel PAST_DUE>30d | **CRITICAL** — auto-renewal macet, MRR turun, trial tidak ter-convert |
| `billing:trial-expiry-notify` | Daily 09:00 | Email reminder H-7/H-3/H-1 sebelum trial habis | Trial-to-paid conversion turun, target ≥25% missed |
| `billing:dunning` | Daily 08:00 | Subscription progressive dunning (H+1..H+30) | Tenant tertunggak tidak diberitahu, cash flow turun |
| `billing:reset-monthly-meters` | Tanggal 1 jam 00:30 | Reset usage_meters monthly + roll period | Quota perhitungan salah, false positive QUOTA_EXCEEDED |
| `cs:compute-health-scores` | Weekly Mon 05:30 | Compute health score per tenant (S3.4) | Tim CS tidak tahu tenant berisiko churn |

### Helpdesk (1)

| Command | Schedule | Purpose | Failure Impact |
| --- | --- | --- | --- |
| `helpdesk:check-sla` | Hourly | Warning H-2 jam + eskalasi ticket overdue | SLA breach tidak terdeteksi |

### Queue & Email (3 — sebagai daemon!)

| Command | Schedule | Purpose | Failure Impact |
| --- | --- | --- | --- |
| `queue:work` | **Daemon** (systemd) | Process async jobs (email, webhook, export, dll) | Email/webhook stuck di queue, user UX rusak |
| `email:process` | Backup cron / 15 menit | Process email queue (kalau queue:work down) | Email tidak terkirim |
| `webhooks:process` | Backup cron / 15 menit | Process webhook delivery + retry | Webhook integrasi macet |

### Maintenance (5)

| Command | Schedule | Purpose | Failure Impact |
| --- | --- | --- | --- |
| `idempotency:purge` | Daily 03:55 | Hapus idempotency keys expired (TTL 24h) | Tabel membengkak (cosmetic) |
| `cache:warm` | Daily 05:00 | Pre-populate cache untuk lookup table | Cold-start lambat after cache flush |
| `hr:sync-permissions` | Weekly Mon 06:30 | Sync permission slug baru ke role default | Permission baru tidak ter-grant ke role existing |
| `hr:audit` | Weekly Mon 06:00 | System audit (storage, DB, security, operasional) | Drift terdeteksi terlambat |
| `hr:leave-yearend` | 1 Januari 00:30 | Carry-over saldo cuti tahun lalu | Saldo cuti karyawan salah |

---

## Troubleshooting

### Cron tidak jalan?

```bash
# 1. Cek service cron
sudo systemctl status cron

# 2. Cek crontab user yang benar
sudo -u www-data crontab -l

# 3. Cek log cron
sudo grep CRON /var/log/syslog | tail -20

# 4. Test command manual sebagai user www-data
sudo -u www-data bash -c "cd /var/www/quantara-hris/backend && php spark hr:check-expiry"
```

### Permission denied pada writable/

```bash
sudo chown -R www-data:www-data /var/www/quantara-hris/backend/writable
sudo chmod -R 775 /var/www/quantara-hris/backend/writable
```

### "Cache key contains reserved characters" / cache error

Restart cache backend (Redis):

```bash
sudo systemctl restart redis
# Atau flush specific keys
redis-cli FLUSHDB
```

### Backup S3 gagal (`db:backup-encrypted`)

```bash
# Test AWS credentials
aws s3 ls s3://quantara-backups-prod/

# Test GPG
echo "test" | gpg --encrypt --recipient ops@quantara.io

# Manual run dengan verbose
cd /var/www/quantara-hris/backend
php spark db:backup-encrypted --verbose
```

### Queue worker memory growing

`queue:work` long-running daemon bisa leak. Solusinya systemd `MemoryMax=512M`
+ flag `--max-jobs=1000` supaya worker auto-restart setelah X job:

```ini
ExecStart=/usr/bin/php spark queue:work --daemon --max-jobs=1000
```

### Schedule overlap (concurrency)

Untuk command yang lama (mis. `db:backup-encrypted`), tambahkan flock supaya
tidak overlap:

```cron
0 2 * * * /usr/bin/flock -n /tmp/backup.lock php spark db:backup-encrypted >> $LOG_DIR/db-backup.log 2>&1
```

Di K8s pakai `concurrencyPolicy: Forbid` (sudah ada di contoh di atas).

---

## Monitoring & Alerting

### 1. Healthcheck endpoints (sudah built-in)

- `GET /api/v1/health` — basic liveness (DB ping)
- `GET /api/v1/health/deep` — comprehensive (super admin only)
- `GET /api/v1/metrics` — Prometheus exposition

Configure Uptimerobot / Pingdom monitor `/health` interval 1 menit, alert
Slack `#alerts-prod` saat 2 consecutive fail.

### 2. Cron-specific alerting

Tambahkan wrapper script `scripts/cron-with-alert.sh`:

```bash
#!/bin/bash
# Usage: cron-with-alert.sh <command-name> <slack-webhook> -- spark hr:check-expiry
NAME=$1; WEBHOOK=$2; shift 2; shift   # skip --
START=$(date +%s)
OUTPUT=$("$@" 2>&1)
EXIT=$?
DUR=$(($(date +%s) - START))

if [ $EXIT -ne 0 ]; then
  curl -s -X POST -H 'Content-type: application/json' --data "{
    \"text\": \"❌ Cron *${NAME}* failed (exit ${EXIT}, ${DUR}s)\n\`\`\`${OUTPUT:0:500}\`\`\`\"
  }" "$WEBHOOK" > /dev/null
  exit $EXIT
fi
echo "$OUTPUT"
```

Lalu di crontab:

```cron
0 2 * * * /opt/scripts/cron-with-alert.sh "db-backup" $SLACK_WEBHOOK -- php spark db:backup-encrypted --cleanup
```

### 3. Prometheus metrics yang relevan

Tambahkan scrape Prometheus → `/api/v1/metrics`. Alert critical:

```yaml
# prometheus/alerts.yml
groups:
  - name: quantara-cron
    rules:
      - alert: BackupOldOver36h
        expr: time() - hris_last_backup_at_seconds > 129600
        for: 5m
        annotations:
          summary: "DB backup tidak running > 36 jam"

      - alert: AuditCriticalSpike
        expr: hris_audit_log_critical_24h > 50
        annotations:
          summary: "Critical audit events > 50 dalam 24 jam"

      - alert: QueueBacklog
        expr: hris_jobs_count{status="pending"} > 1000
        for: 10m
        annotations:
          summary: "Queue backlog > 1000 jobs (worker mati?)"
```

### 4. Audit log dashboard

Semua command write ke `audit_logs` table dengan severity `NOTICE`/`WARN`/`ERROR`.
Query reguler:

```sql
SELECT severity, COUNT(*) AS cnt, MAX(created_at) AS last
FROM audit_logs
WHERE module IN ('CRON','BACKUP','BILLING','TENANT_PROVISIONING')
  AND created_at >= NOW() - INTERVAL 24 HOUR
GROUP BY severity;
```

Surface di `/admin/audit-logs?severity=ERROR` (super admin console).

---

## Deployment Checklist

Sebelum deploy ke production server baru, verifikasi:

- [ ] Server timezone = `Asia/Jakarta` (`timedatectl`)
- [ ] PHP CLI version match production (`php -v`)
- [ ] `backend/.env` punya semua variabel di section "Setup Awal"
- [ ] `writable/` permission `775` owner `www-data:www-data`
- [ ] `/var/log/quantara-cron/` exists dengan owner `www-data`
- [ ] AWS CLI configured + S3 bucket accessible (`aws s3 ls $BUCKET`)
- [ ] GPG key recipient sudah di-import (`gpg --list-keys`)
- [ ] Redis / cache backend running (`redis-cli ping`)
- [ ] MySQL credential di `.env` punya `LOCK TABLES` permission untuk mysqldump
- [ ] Crontab installed (`sudo -u www-data crontab -l | wc -l` ≥ 25)
- [ ] systemd unit `quantara-queue` enabled + active (`systemctl is-active`)
- [ ] Test 1 command: `sudo -u www-data php spark hr:check-expiry`
- [ ] Verify audit log row tertulis untuk command tsb di `audit_logs`
- [ ] Slack `#alerts-prod` webhook tested (kirim dummy)
- [ ] Prometheus scrape `/metrics` succeeded (cek Grafana dashboard)
- [ ] Backup pertama berhasil (manual run `db:backup-encrypted`, verify upload S3)

---

## Update Log

| Tanggal | Versi | Perubahan |
| --- | --- | --- |
| 2026-08-10 | 1.0 | Dokumen awal — 33 command HR + SaaS + Compliance + Backup |

---

> **Catatan**: Untuk command baru yang belum di-roll ke prod, append ke section
> reference + crontab. Pastikan dokumentasi cron-doc dan code commit dalam
> satu PR supaya tim ops bisa review jadwal sekaligus.