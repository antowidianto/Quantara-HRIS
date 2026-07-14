# HRIS — Backup & Disaster Recovery

> Sprint F / G4. SOP backup MySQL + storage `writable/uploads/` plus restore drill.
> **Audience:** ops engineer + on-call. **Update:** 28 Mei 2026.

---

## 1. Apa yang Wajib Di-Backup

| Asset                        | Lokasi                               | Volume Estimate | Kritikalitas |
|------------------------------|--------------------------------------|-----------------|--------------|
| MySQL data                   | volume `mysql_data` / RDS            | 5–50 GB / 5K emp | 🔴 Critical |
| `writable/uploads/`          | volume `backend_writable`            | 10–200 GB        | 🔴 Critical |
| `writable/logs/`             | volume `backend_writable`            | 1–20 GB / bulan  | 🟡 Medium    |
| Audit log archives           | `writable/uploads/audit-archives/`   | grows monthly    | 🔴 Critical (compliance UU PDP) |
| `.env.prod` + secret         | host `/opt/hris/.env.prod`           | < 1 KB           | 🔴 Critical (secret) |
| TLS cert                     | `deploy/tls/`                        | < 1 MB           | 🟠 High |
| Composer/NPM lockfile        | repo Git                             | covered via Git  | 🟢 Low |

**Yang tidak perlu di-backup:**

- Image Docker (re-built dari source via CI).
- `vendor/` & `node_modules/` (regenerate dari lockfile).
- Redis cache (data ephemeral; AOF persistence ada tapi cache miss = OK).
- `writable/cache/` (Path & log cache CI4).

---

## 2. Cadence & Retention

| Backup type         | Cadence       | Retention                   | Storage                    |
|---------------------|---------------|-----------------------------|----------------------------|
| MySQL full dump     | Daily 02:00 ✱ | 7 daily + 4 weekly + 12 monthly | S3 `s3://hris-backup/mysql/` |
| MySQL binlog (PITR) | Streaming     | 7 hari                      | S3 `s3://hris-backup/binlog/` |
| `writable/uploads/` | Daily 03:00 ✱ | 30 hari incremental + 12 monthly full | S3 `s3://hris-backup/uploads/` |
| Audit archives      | Bulanan       | 7 tahun (UU PDP retention)  | S3 cold tier (Glacier)     |
| `.env.prod` + TLS   | Saat berubah  | Last 5 versions             | Vault / 1Password / KMS    |

✱ Waktu malam Asia/Jakarta supaya minim user impact. Sesuaikan kalau zona ops berbeda.

**Encryption at rest:**

- S3 bucket pakai SSE-KMS dengan customer-managed key.
- Local on-prem: `gpg --symmetric --cipher-algo AES256` sebelum upload off-site.

**Encryption in transit:**

- S3 selalu HTTPS.
- On-prem ke off-site: rsync over SSH (jangan plain rsyncd).

---

## 3. Backup Procedures

### 3.1 MySQL — Daily Full Dump

```bash
#!/usr/bin/env bash
# /opt/hris/scripts/backup-mysql.sh — jalankan via cron 02:00 daily.
set -euo pipefail
BACKUP_ROOT=/opt/hris/backups/mysql
S3_BUCKET=s3://hris-backup/mysql
DATE=$(date -u +%Y%m%d)

mkdir -p "$BACKUP_ROOT"

# Dump dari container (single-transaction → konsisten tanpa lock semua tabel)
docker compose -f /opt/hris/docker-compose.prod.yml --env-file /opt/hris/.env.prod \
    exec -T mysql sh -c \
    'mysqldump --single-transaction --quick --lock-tables=false \
        --routines --triggers --events \
        -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
    | gzip > "$BACKUP_ROOT/${DATE}.sql.gz"

# Upload ke S3 dengan SSE-KMS
aws s3 cp "$BACKUP_ROOT/${DATE}.sql.gz" \
    "$S3_BUCKET/${DATE}.sql.gz" \
    --sse aws:kms --sse-kms-key-id "$AWS_KMS_KEY_ID"

# Hapus file lokal yang > 7 hari (S3 sudah pegang)
find "$BACKUP_ROOT" -name "*.sql.gz" -mtime +7 -delete

# Verifikasi: cek size > 1 KB (kalau dump kosong, ada yang salah)
SIZE=$(stat -c %s "$BACKUP_ROOT/${DATE}.sql.gz" 2>/dev/null || echo 0)
if (( SIZE < 1024 )); then
    echo "ERROR: Backup file suspiciously small: ${SIZE} bytes" >&2
    exit 1
fi
echo "Backup OK: $BACKUP_ROOT/${DATE}.sql.gz (${SIZE} bytes)"
```

### 3.2 Uploads — Daily Incremental rsync

```bash
#!/usr/bin/env bash
# /opt/hris/scripts/backup-uploads.sh — cron 03:00 daily.
set -euo pipefail
SOURCE=/var/lib/docker/volumes/hris-prod_backend_writable/_data/uploads
S3_BUCKET=s3://hris-backup/uploads

# Sync incremental (--delete supaya remote mirror source).
# Pakai --exclude untuk skip cache subdir kalau kebetulan disitu.
aws s3 sync "$SOURCE" "$S3_BUCKET/" \
    --delete \
    --exclude "*.tmp" \
    --exclude "*/cache/*" \
    --sse aws:kms --sse-kms-key-id "$AWS_KMS_KEY_ID"
```

### 3.3 MySQL — Binlog Streaming (PITR)

Untuk Recovery Point Objective (RPO) ≤ 5 menit, aktifkan binlog dan stream ke S3:

```bash
# Tambah ke MySQL config di docker-compose.prod.yml command:
#   --log-bin=mysql-bin
#   --binlog-format=ROW
#   --binlog-row-image=FULL
#   --expire-logs-days=7

# Stream binlog (jalan terus sebagai daemon, supervised)
docker compose exec -T mysql mysqlbinlog \
    --read-from-remote-server \
    --raw \
    --to-last-log \
    --result-file=/backups/binlog/ \
    mysql-bin.000001
```

Implementasi production: pakai service managed (RDS automated backup) atau tool seperti `xtrabackup` + `mysqlbinlog --stop-never`.

---

## 4. Restore Procedures

### 4.1 Full restore — MySQL dari dump

```bash
# Asumsi: container baru, mysql sudah up tapi DB masih kosong.
DUMP=/path/to/2026-05-28.sql.gz

# Buat database (kalau belum ada)
docker compose exec -T mysql sh -c \
    'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS $MYSQL_DATABASE"'

# Restore
gunzip -c "$DUMP" | docker compose exec -T mysql sh -c \
    'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'

# Verifikasi count baseline
docker compose exec mysql sh -c \
    'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" \
        -e "SELECT COUNT(*) AS users FROM users;
            SELECT COUNT(*) AS employees FROM employees;
            SELECT COUNT(*) AS audit_logs FROM audit_logs;"'
```

### 4.2 Point-in-Time Recovery (PITR)

```bash
# 1. Restore full dump terdekat sebelum incident
gunzip -c 2026-05-28.sql.gz | docker compose exec -T mysql mysql -uroot -p... hris_db

# 2. Apply binlog dari snapshot ke target time (mis. 30 menit sebelum incident)
docker compose exec -T mysql mysqlbinlog \
    --start-datetime="2026-05-28 02:00:00" \
    --stop-datetime="2026-05-28 14:30:00" \
    /backups/binlog/mysql-bin.000123 \
    /backups/binlog/mysql-bin.000124 \
    | mysql -uroot -p... hris_db
```

### 4.3 Uploads restore

```bash
# Sync balik dari S3 ke volume
TARGET=/var/lib/docker/volumes/hris-prod_backend_writable/_data/uploads
aws s3 sync s3://hris-backup/uploads/ "$TARGET/"

# Fix ownership (php-fpm user di container = www-data, host UID 33 di Alpine)
chown -R 33:33 "$TARGET"
chmod -R 750 "$TARGET"
```

### 4.4 Audit Chain Verification setelah Restore

WAJIB jalankan setelah setiap restore — pastikan hash chain `audit_logs` masih valid:

```bash
docker compose exec backend php spark audit:verify-chain
```

Output `[OK] Hash chain verified` mengonfirmasi tidak ada corruption / tampering antara backup time → now. Kalau gagal, escalate ke security team — bisa berarti ada gap dalam binlog atau tampering.

---

## 5. Disaster Recovery — RTO & RPO

| Scenario                        | RPO target | RTO target | Strategi                                    |
|---------------------------------|------------|------------|---------------------------------------------|
| Single instance crash           | 0          | 5 menit    | Container restart `restart: always`         |
| Volume corruption (mysql_data)  | 5 menit    | 1 jam      | PITR dari binlog + last full dump          |
| Datacenter loss                 | 24 jam     | 4 jam      | Restore di region lain dari S3 backup      |
| Ransomware encrypt host         | 24 jam     | 8 jam      | Provision baru + restore dari off-site dump |
| Database accidentally dropped   | 5 menit    | 2 jam      | PITR ke 1 menit sebelum DROP                |

**RPO** = Recovery Point Objective (berapa banyak data yang OK hilang).
**RTO** = Recovery Time Objective (berapa lama service balik online).

Target di atas asumsi tier "important" (HRIS bukan trading platform). Kalau company demand RTO < 1 jam, naikkan tier ke active-active multi-region.

---

## 6. Restore Drill — Quarterly

**Wajib** dilakukan tiap kuartal. Tanpa drill, backup yang "kelihatan jalan" sering tidak bisa di-restore (corrupted dump, missing binlog gap, IAM permission salah, dst.).

**Format drill (≈ 2 jam):**

1. **Provision instance staging baru** (atau pakai hardware terpisah). Bukan lingkungan production.
2. **Restore dump latest** ke staging.
3. **Apply binlog** sampai 1 jam sebelum drill time.
4. **Run smoke test:**
   - Login dengan akun staging seed
   - List 10 employee, lihat detail (decrypt OK)
   - Buka 1 payslip dari period sebelumnya — angka match dengan dump original
   - `php spark audit:verify-chain` → OK
5. **Bandingkan row count** vs production: `users`, `employees`, `payslips`, `audit_logs` → toleransi ≤ 0.1%.
6. **Catat waktu** total restore (target ≤ 2 jam untuk DB ≤ 50 GB).
7. **Tear down** staging setelah drill.

Hasil drill di-record di issue tracker dengan template:

```
Tanggal: yyyy-mm-dd
Restore source: <S3 path>
Backup age: <X hours>
Restore time: <X minutes>
Smoke test: PASS / FAIL
Data integrity: PASS / FAIL
Audit chain: PASS / FAIL
Notes:
```

Setelah evidence di-review, rekam hasilnya ke ledger operasional. Status `PASSED` akan ditolak kecuali seluruh pemeriksaan record count, hash, attachment, dan login dinyatakan lengkap:

```bash
php spark db:restore-drill-record <backup-run-id> PASSED isolated-dr-2026q3 87 https://tracker.example/dr/2026q3 \
  --counts --hashes --attachments --login
```

Metric `hris_restore_drill_age_seconds` tetap `-1` sampai evidence drill sukses pertama direkam; keberadaan command/metric tidak menggantikan pelaksanaan drill.

---

## 7. Monitoring & Alerting

Backup yang berjalan tapi gagal silent adalah skenario terburuk. Setup alert:

| Metric                          | Alert kalau ...                       | Severity |
|---------------------------------|----------------------------------------|----------|
| Last successful MySQL backup    | > 25 jam                              | 🔴 Critical |
| Backup file size                | < 50% rolling 7-day average           | 🟠 High |
| S3 upload latency               | > 10 menit (untuk dump < 5 GB)        | 🟡 Medium |
| Binlog stream lag               | > 5 menit                              | 🔴 Critical |
| Audit chain verify              | Fail                                   | 🔴 Critical (security incident) |
| Drill test (quarterly)          | Tidak dilaksanakan dalam 100 hari     | 🟠 High |

Alerting backend:

- Slack/PagerDuty untuk Critical
- Email untuk High/Medium
- Dashboard Grafana untuk trend

---

## 8. Compliance Notes (UU PDP)

- **Retention audit logs**: 7 tahun (Pasal 16 UU 27/2022). `ArchiveAuditLogs` command (lihat `app/Commands/ArchiveAuditLogs.php`) memindahkan logs > 1 tahun ke `writable/uploads/audit-archives/` dalam format JSON Lines yang masih bisa di-grep. Backup folder ini WAJIB.
- **Right to erasure**: data subject yang request hapus tidak boleh menghapus audit trail (compliance trumps individual erasure). Anonimisasi via `DataPrivacyService::anonymizeEmployee()` mengganti PII dengan placeholder tetapi mempertahankan ID untuk audit reference.
- **Data location**: kalau company subject ke residency requirement (BUMN, finansial), pastikan S3 region di `ap-southeast-3` (Jakarta) atau on-prem Indonesia.

---

## 9. Quick Reference

| Saya butuh ...                       | Lihat section |
|--------------------------------------|---------------|
| Setup backup script pertama kali     | §3            |
| Restore dari kemarin malam           | §4.1          |
| Restore ke point-in-time spesifik    | §4.2          |
| Drill quarterly                      | §6            |
| Setup monitoring                     | §7            |
| Compliance audit prep                | §8            |
| Verifikasi hash chain audit_logs     | §4.4          |
