# Cron Jobs (Scheduled Tasks)

Dokumen ini menjelaskan **semua scheduled job** yang dipakai HRIS, kapan
dijalankan, dan bagaimana setup-nya di Linux/Windows/Docker.

> **Catatan singkat untuk perbaikan terakhir (Mei 2026):**
> - `hr:archive-audit` dijadwalkan setiap **Minggu jam 00:00** (sebelumnya 03:00)
>   karena window malam Minggu paling sepi traffic untuk operasi update
>   batch tabel besar.
> - `hr:leave-anniversary-carry` adalah cron baru untuk **carry-over saldo
>   cuti per tanggal join** (UU 13/2003 Pasal 79 ayat 5). Dijalankan harian
>   jam 00:30. Aktif hanya kalau leave_types punya `carry_over_basis = ANNIVERSARY`.

## Daftar Cron Job

| Command                          | Frekuensi              | Tujuan                                                       |
|----------------------------------|------------------------|--------------------------------------------------------------|
| `hr:archive-audit`               | Mingguan, Min 00:00    | Soft-archive audit_logs > 730 hari (UU PDP & ISO 27001)      |
| `audit:verify-chain`             | Mingguan, Min 02:00    | Verifikasi integrity hash chain audit_logs (Sprint A / B6)   |
| `hr:leave-yearend`               | Tahunan, 1 Jan 01:00   | Carry-over saldo cuti dari tahun lama → baru (basis YEAR_END)|
| `hr:leave-anniversary-carry`     | Harian, 00:30          | Reset saldo cuti per tanggal join (basis ANNIVERSARY)        |
| `hr:notify-carry-over-cap`       | Bulanan, tanggal 1     | H-30 reminder kalau sisa cuti hampir hangus                  |
| `hr:check-anniversary`           | Harian, 08:00          | Notif birthday + work anniversary                            |
| `hr:check-expiry`                | Harian, 07:00          | Reminder kontrak/probation/dokumen H-30/14/7                 |
| `hr:check-cert-expiry`           | Harian, 08:00          | Reminder sertifikat training expire                          |
| `hr:check-probation`             | Harian, 08:00          | Reminder probation H-7 + auto-pass                           |
| `hr:check-offer-expiry`          | Harian, 23:50          | Auto-expire candidate offer lewat deadline                   |
| `hr:check-mpp-overdue`           | Harian, 07:00          | Reminder + eskalasi MPP yg vacancy_started_at lewat / dekat  |
| `hr:check-bpjs`                  | Harian, 07:30          | Eskalasi BPJS register/deactivate overdue                    |
| `hr:cleanup-attachments`         | Mingguan, Sab 04:00    | Hapus selfie absensi > retention (90 hari default)           |
| `hr:deactivate-resigned`         | Harian, 05:00          | Set is_active=0 untuk karyawan yg sudah resign               |
| `hr:apply-jic`                   | Harian, 00:30          | Auto-apply JIC yang APPROVED & effective_date ≤ today        |
| `hr:apply-transfers`             | Harian, 06:00          | Auto-apply mutasi yang APPROVED & effective_date ≤ today     |
| `hr:audit`                       | Harian, 06:00          | Health check 25 cek (storage, DB, security, ops)             |
| `hr:rotate-encryption`           | On-demand              | Re-encrypt PII pakai key version baru (saat key rotation)    |
| `hr:sync-permissions`            | On-demand              | Sync permission baru ke role default                         |
| `helpdesk:check-sla`             | Per jam                | Warning H-2 jam SLA ticket + eskalasi overdue                |
| `email:process`                  | Per menit              | Process email queue (retry 1m/5m/30m/2h/6h)                  |
| `webhooks:process`               | Per menit              | Deliver webhook outbound + retry                             |
| `cache:warm`                     | Setelah deploy         | Pre-populate cache lookup tabel jarang berubah               |

## Setup di Linux/Unix (crontab)

Edit crontab user web server:

```bash
crontab -e -u www-data
```

Tambahkan blok lengkap berikut:

```cron
# ─── HRIS HRIS Cron Jobs ─────────────────────────────────────────
# Path bisa diganti sesuai instalasi
HRIS_BIN=cd /var/www/hris/backend && php spark
LOG_DIR=/var/www/hris/backend/writable/logs

# Per menit — message queues
* * * * *      $HRIS_BIN email:process    >> $LOG_DIR/email.log 2>&1
* * * * *      $HRIS_BIN webhooks:process >> $LOG_DIR/webhooks.log 2>&1

# Per jam — SLA helpdesk
0 * * * *      $HRIS_BIN helpdesk:check-sla >> $LOG_DIR/helpdesk-sla.log 2>&1

# Harian
0  5 * * *     $HRIS_BIN hr:deactivate-resigned    >> $LOG_DIR/deactivate.log 2>&1
30 0 * * *     $HRIS_BIN hr:leave-anniversary-carry >> $LOG_DIR/leave-anniversary.log 2>&1
30 0 * * *     $HRIS_BIN hr:apply-jic              >> $LOG_DIR/jic.log 2>&1
0  6 * * *     $HRIS_BIN hr:apply-transfers        >> $LOG_DIR/transfers.log 2>&1
0  6 * * *     $HRIS_BIN hr:audit --json --mail --quiet >> $LOG_DIR/audit.log 2>&1
0  7 * * *     $HRIS_BIN hr:check-expiry           >> $LOG_DIR/expiry.log 2>&1
0  7 * * *     $HRIS_BIN hr:check-mpp-overdue      >> $LOG_DIR/mpp-overdue.log 2>&1
30 7 * * *     $HRIS_BIN hr:check-bpjs             >> $LOG_DIR/bpjs.log 2>&1
0  8 * * *     $HRIS_BIN hr:check-anniversary      >> $LOG_DIR/anniversary.log 2>&1
0  8 * * *     $HRIS_BIN hr:check-cert-expiry      >> $LOG_DIR/cert-expiry.log 2>&1
0  8 * * *     $HRIS_BIN hr:check-probation        >> $LOG_DIR/probation.log 2>&1
50 23 * * *    $HRIS_BIN hr:check-offer-expiry     >> $LOG_DIR/offer-expiry.log 2>&1

# Mingguan — Sabtu malam selfie cleanup, Minggu malam audit archive,
# Minggu pagi verify chain (sebelum operator masuk Senin pagi).
# Verify chain (Sprint A / B6) — alert ke ops kalau ada mismatch.
0 4 * * 6      $HRIS_BIN hr:cleanup-attachments    >> $LOG_DIR/attachments-cleanup.log 2>&1
0 0 * * 0      $HRIS_BIN hr:archive-audit          >> $LOG_DIR/archive-audit.log 2>&1
0 2 * * 0      $HRIS_BIN audit:verify-chain --quiet >> $LOG_DIR/audit-verify-chain.log 2>&1

# Bulanan — H-30 reminder cuti hangus
0 9 1 * *      $HRIS_BIN hr:notify-carry-over-cap  >> $LOG_DIR/carry-over-cap.log 2>&1

# Tahunan — 1 Januari reset saldo cuti basis YEAR_END
0 1 1 1 *      $HRIS_BIN hr:leave-yearend          >> $LOG_DIR/leave-yearend.log 2>&1
```

## Setup di Windows (Task Scheduler)

Untuk **setiap** command di tabel di atas, buat scheduled task baru.
Contoh untuk `hr:archive-audit` (mingguan Min 00:00):

PowerShell (run as admin):

```powershell
schtasks /Create `
  /SC WEEKLY /D SUN /ST 00:00 `
  /TN "HRIS-Archive-Audit" `
  /TR "cmd /c cd /d D:\Apps Project\hris-project-1\backend && php spark hr:archive-audit >> writable\logs\archive-audit.log 2>&1" `
  /RU SYSTEM
```

Untuk `hr:leave-anniversary-carry` (harian 00:30):

```powershell
schtasks /Create `
  /SC DAILY /ST 00:30 `
  /TN "HRIS-Leave-Anniversary" `
  /TR "cmd /c cd /d D:\Apps Project\hris-project-1\backend && php spark hr:leave-anniversary-carry >> writable\logs\leave-anniversary.log 2>&1" `
  /RU SYSTEM
```

Verifikasi dengan:
```powershell
schtasks /Query /TN "HRIS-Archive-Audit" /V /FO LIST
```

## Setup di Docker (host crontab)

Asumsikan service backend bernama `backend` di docker-compose:

```cron
# Per menit
* * * * * docker compose -f /opt/hris/docker-compose.yml exec -T backend php spark email:process

# Mingguan archive audit — Minggu 00:00
0 0 * * 0 docker compose -f /opt/hris/docker-compose.yml exec -T backend php spark hr:archive-audit

# Harian anniversary carry-over — 00:30
30 0 * * * docker compose -f /opt/hris/docker-compose.yml exec -T backend php spark hr:leave-anniversary-carry

# (... cron lain mengikuti pola yang sama ...)
```

## Verifikasi & Monitoring

### Cek manual sebelum production

```bash
# Dry-run (tidak menulis ke DB)
php spark hr:archive-audit --dry-run
php spark hr:leave-anniversary-carry --dry-run
php spark hr:leave-yearend --dry-run
php spark hr:check-mpp-overdue --dry-run
```

### Health check via `hr:audit`

`hr:audit` adalah aggregator yang cek 25 hal — termasuk apakah cron-cron
penting masih jalan rutin. Pasang ke healthchecks.io / Cronitor untuk
alert kalau cron berhenti.

### Logs

Default semua log ada di `backend/writable/logs/{command}.log`. Rotasi
log dipakai (default `Logger::class` CodeIgniter) jadi tidak akan
membengkak unbounded. Untuk production-grade, pakai `logrotate` atau
forward ke central log (Loki / Elasticsearch).

## Troubleshooting

| Gejala                          | Kemungkinan penyebab                                                              |
|---------------------------------|-----------------------------------------------------------------------------------|
| Cron tidak jalan                | Cek `crontab -l` user web server, pastikan PATH php benar                         |
| "Tidak ada yang perlu di-archive" | Normal kalau audit_logs masih segar; cek dengan `--days=30 --dry-run` untuk test |
| Saldo cuti tidak refresh di anniversary | Cek `leave_types.carry_over_basis` — harus ANNIVERSARY (default YEAR_END)         |
| Anniversary processed 2x sehari | Idempotent — cek kolom `anniversary_processed_at` di leave_balances; skip kalau sama hari |
| MPP overdue notif tidak terkirim | Cek `manpower_requests.vacancy_started_at` IS NOT NULL; status harus DRAFT/SUBMITTED/APPROVED/HIRING; user target butuh permission `mpp.approve` / `mpp.fulfill` |

## Lihat juga

- `docs/AUDIT.md` — penjelasan `hr:audit` (25 cek otomatis)
- `docs/LEAVE_BALANCE.md` — detail year-end vs anniversary carry-over
- `docs/MONITORING.md` — uptime monitoring + alert routing