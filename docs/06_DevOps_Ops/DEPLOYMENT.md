# HRIS — Production Deployment Guide

> Sprint F / G7. End-to-end SOP build, ship, deploy, rollback.
> **Audience:** ops engineer, release manager.

---

## 1. Diagram Alur Deploy

```
┌──────────────┐    ┌──────────────┐    ┌──────────────┐
│  Source      │───▶│  CI Build    │───▶│  Container   │
│  (Git tag)   │    │  & Test      │    │  Registry    │
└──────────────┘    └──────────────┘    └──────┬───────┘
                                                │
                                                ▼
                              ┌─────────────────────────────────┐
                              │  scripts/deploy.sh <version>    │
                              │  (run di production host)       │
                              └─────────────────────────────────┘
                                  │
                ┌─────────────────┼──────────────────┐
                ▼                 ▼                  ▼
        1. Pull image     2. Backup DB       3. Run migration
                ▼
        4. Recreate      5. Reload nginx    6. Smoke test
           backend+cron     (graceful)         /healthz
                ▼
        7. SUCCESS  /  FAIL → auto rollback
```

---

## 2. Prasyarat Production Host

- Linux (Ubuntu 22.04+ / Debian 12+ / Amazon Linux 2023).
- Docker Engine 24+ dan Docker Compose v2 plugin.
- 4 vCPU + 8 GB RAM minimum (5K karyawan), 8 vCPU + 16 GB RAM rekomendasi.
- Disk: 200 GB SSD (50 GB OS + 100 GB volume + 50 GB backups buffer).
- Akses outbound 443 ke registry + S3 backup + Slack webhook.
- TLS cert valid (Let's Encrypt via certbot, atau ACM/load balancer di depan).
- Time sync NTP aktif (audit log timestamp + 2FA TOTP butuh akurat).

---

## 3. First-Time Setup

### 3.1 Provision direktori

```bash
sudo mkdir -p /opt/hris/{backups,deploy,logs}
sudo mkdir -p /opt/hris/backups/mysql /opt/hris/deploy/{conf.d,tls}
sudo chown -R $(whoami):$(whoami) /opt/hris
cd /opt/hris
```

### 3.2 Pull repo & checkout tag

```bash
git clone https://github.com/antowidianto/Quantara-HRIS.git .
git checkout v1.0.0   # tag release yang mau di-deploy pertama kali
```

### 3.3 Buat `.env.prod`

Salin dari template lalu isi nilai production:

```bash
cp backend/env-templates/production.env.example .env.prod
chmod 600 .env.prod
```

Wajib di-set (jangan biarkan default):

- `app.baseURL=https://hris.example.com`
- `encryption.key=<base64:32-byte>` (`openssl rand -base64 32`)
- `jwt.secret=<128 hex>` (`openssl rand -hex 64`)
- `MYSQL_ROOT_PASSWORD`, `MYSQL_USER`, `MYSQL_PASSWORD`
- `REDIS_PASSWORD`
- `cors.allowedOrigins=https://hris.example.com`
- SMTP credentials (Mailgun / SES / company SMTP)

Validasi:

```bash
docker run --rm -v /opt/hris/.env.prod:/.env hris/backend:latest \
    php -r 'require "/var/www/html/vendor/autoload.php"; \
            $env = file_get_contents("/.env"); \
            assert(strpos($env, "encryption.key=") !== false);'
```

### 3.4 Setup TLS

```bash
# Option A — Let's Encrypt (gratis, auto-renew)
sudo certbot certonly --standalone -d hris.example.com
sudo cp /etc/letsencrypt/live/hris.example.com/fullchain.pem /opt/hris/deploy/tls/cert.pem
sudo cp /etc/letsencrypt/live/hris.example.com/privkey.pem /opt/hris/deploy/tls/key.pem
sudo chmod 644 /opt/hris/deploy/tls/cert.pem
sudo chmod 600 /opt/hris/deploy/tls/key.pem

# Option B — pakai cert dari load balancer / corporate CA
# Copy cert.pem dan key.pem manual ke /opt/hris/deploy/tls/
```

Sertakan auto-renew di cron host (`/etc/cron.d/certbot`):

```cron
0 3 * * * root certbot renew --quiet --post-hook "docker compose -f /opt/hris/docker-compose.prod.yml exec nginx nginx -s reload"
```

### 3.5 Nginx config

Buat `deploy/nginx.conf` dan `deploy/conf.d/hris.conf`. Skeleton ada di `docker-compose.prod.yml` reference. Penting:

- `server_tokens off`
- HSTS: `add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;`
- CSP: `add_header Content-Security-Policy "default-src 'self'; ..." always;`
- Endpoint `/healthz` return 200 dari nginx untuk healthcheck
- `/api/` → `fastcgi_pass backend:9000;`
- Static SPA: `try_files $uri $uri/ /index.html;`

### 3.6 Cron config

Salin `deploy/cron.txt` (lihat `docs/CRON_JOBS.md` untuk daftar lengkap). Format:

```cron
# Jam HRIS dijalankan dalam UTC di container — tambahkan offset sesuai zona ops.
# Format: m h dom mon dow command

# Daily 02:00 WIB = 19:00 UTC (hari sebelumnya)
0 19 * * * cd /var/www/html && php spark hr:archive-audit-logs
0 20 * * * cd /var/www/html && php spark hr:check-anniversary
0 21 * * * cd /var/www/html && php spark hr:check-probation-deadline

# (tambah baris untuk tiap command — total 20+)
```

---

## 4. Deploy Pertama Kali

### 4.1 Build & push image dari CI

CI workflow (lihat `.github/workflows/`) seharusnya:

1. Run `phpunit` + `vitest` + `phpstan` + `eslint`
2. `docker build -t $REGISTRY/hris/backend:$TAG -f backend/Dockerfile backend/`
3. `docker build -t $REGISTRY/hris/frontend:$TAG -f frontend/Dockerfile frontend/`
4. Push ke registry private.

### 4.2 Deploy lewat script

```bash
ssh ops@hris-prod-1
cd /opt/hris
export HRIS_REGISTRY=registry.example.com/hris
./scripts/deploy.sh 1.0.0
```

Script akan:

1. Pull image versi 1.0.0
2. Backup DB (skip kalau first-time, DB masih kosong)
3. Run migration (CI4 spark migrate, idempotent)
4. Recreate backend + cron + frontend-build
5. Reload nginx
6. Smoke test `/healthz`

Kalau sukses, output terakhir:

```
[2026-05-28T13:42:11Z] Deploy SUCCESS — version=1.0.0
```

### 4.3 First-time seeding

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    exec backend php spark db:seed InitialSeeder
```

Catat password admin pertama yang di-print (atau ubah segera lewat UI).

### 4.4 Smoke test manual

```bash
curl -fsS https://hris.example.com/healthz
# expected: {"status":"ok","version":"1.0.0",...}

curl -fsS https://hris.example.com/api/v1/health
# expected: {"db":"ok","cache":"ok","time":"..."}
```

Login UI di `https://hris.example.com` dan verifikasi:

- Dashboard load
- Master data (departments, positions) bisa di-CRUD
- Submit 1 leave → lihat di pending list approver

---

## 5. Deploy Update Selanjutnya

Routine deploy dengan version tag baru:

```bash
ssh ops@hris-prod-1
cd /opt/hris
git fetch --tags
git checkout v1.0.1  # untuk dapat compose file & deploy script versi baru
./scripts/deploy.sh 1.0.1
```

`deploy.sh` akan auto-backup DB dan run migration. Aman re-run kalau gagal di tengah (idempotent).

---

## 6. Rollback

### 6.1 Auto rollback (built-in)

`deploy.sh` otomatis rollback kalau healthcheck setelah deploy gagal:

```
[2026-05-28T13:42:11Z] ERROR: Healthcheck gagal setelah 60s
[2026-05-28T13:42:11Z] Rollback ke 1.0.0
[2026-05-28T13:42:35Z] Deploy SUCCESS — version=1.0.0
```

### 6.2 Manual rollback

Kalau perlu rollback setelah deploy "sukses" tapi ada bug functional:

```bash
cat /opt/hris/.last-version
# misal output: 1.0.1

./scripts/deploy.sh 1.0.0 --rollback
```

Rollback skip backup DB — asumsi DB schema 1.0.0 dan 1.0.1 kompatibel (CI4 migration aman backward selama tidak DROP kolom yang dipakai versi lama).

### 6.3 Rollback dengan migration breaking

Kalau migration 1.0.1 men-DROP kolom yang dibutuhkan 1.0.0:

1. Restore DB dari backup pre-deploy:
   ```bash
   ls /opt/hris/backups/mysql/pre-deploy-*
   gunzip -c /opt/hris/backups/mysql/pre-deploy-20260528-134005/dump.sql.gz | \
       docker compose -f docker-compose.prod.yml --env-file .env.prod \
       exec -T mysql mysql -uroot -p... hris_db
   ```

2. Lalu deploy versi lama:
   ```bash
   ./scripts/deploy.sh 1.0.0 --rollback
   ```

3. **Wajib:** verifikasi audit chain setelah restore:
   ```bash
   docker compose exec backend php spark audit:verify-chain
   ```

---

## 7. Monitoring & Health

### 7.1 Health endpoints

- **Nginx:** `GET /healthz` — return `200 OK` dari nginx static block.
- **Backend:** `GET /api/v1/health` — return `{db, cache, queue}` status.
- **Cron:** PID 1 = `crond`. Healthcheck container cek `pgrep crond`.
- **Backup:** dashboard alert kalau last_backup_age > 25 jam (lihat `docs/BACKUP_DR.md` §7).

### 7.2 Logs

```bash
# Tail log per service
docker compose -f docker-compose.prod.yml logs -f backend
docker compose -f docker-compose.prod.yml logs -f cron
docker compose -f docker-compose.prod.yml logs -f nginx

# Cek error count 1 jam terakhir
docker compose logs --since 1h backend | grep -iE "error|exception" | wc -l
```

Log retention via `json-file` rotation di `docker-compose.prod.yml` (max-size 20MB × 10 file = 200MB per service). Kirim ke central logging (ELK / CloudWatch / Loki) lewat docker logging driver di production.

### 7.3 Resource

```bash
docker stats
# cek CPU + memory; alert kalau backend > 80% CPU sustained 5 menit
```

---

## 8. Common Operations

### 8.1 Run ad-hoc spark command

```bash
docker compose exec backend php spark <command>

# Contoh:
docker compose exec backend php spark hr:check-bpjs-compliance
docker compose exec backend php spark audit:verify-chain
docker compose exec backend php spark hr:rotate-encryption --dry-run
```

### 8.2 Database shell

```bash
docker compose exec mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" hris_db
```

### 8.3 Tail PHP error log

```bash
docker compose exec backend tail -f writable/logs/log-$(date +%Y-%m-%d).log
```

### 8.4 Flush cache (Redis)

```bash
docker compose exec redis redis-cli -a "$REDIS_PASSWORD" FLUSHDB
# Atau lebih targeted via spark (sesuai prefix):
docker compose exec backend php spark cache:clear
```

### 8.5 Re-issue admin password

```bash
docker compose exec backend php spark db:seed ResetAdminPasswordSeeder
# (Pastikan seeder ini ada / bikin custom seeder satu kali)
```

---

## 9. Maintenance Mode

Untuk deploy migration breaking yang butuh app down:

```bash
# Aktifkan maintenance mode (set flag di Redis / file)
docker compose exec backend php spark maintenance:on --message "Upgrade ke v2.0.0, kembali 30 menit"

# Verifikasi
curl -I https://hris.example.com
# expected: 503 Service Unavailable

# Lakukan migration
docker compose exec backend php spark migrate

# Matikan maintenance mode
docker compose exec backend php spark maintenance:off
```

(Implementasi `maintenance:on/off` command belum ada — carry-over Sprint F+1.)

---

## 10. Disaster Scenarios

| Scenario                              | Action                                                |
|---------------------------------------|-------------------------------------------------------|
| Backend container loop crash          | `docker compose logs --tail 200 backend` → fix → `docker compose up -d backend` |
| MySQL data corrupt                    | Lihat `docs/BACKUP_DR.md` §4 (restore + PITR)         |
| Disk full                             | Hapus log lama, naik kapasitas volume, `docker system prune` |
| Compromised secret (.env leak)        | Rotate semua secret, regenerate JWT key (force-logout semua user), audit `audit_logs` |
| Audit chain verify fail               | **Security incident.** Eskalasi ke security lead, freeze deploy, investigasi tampering |

---

## 11. Quick Reference

| Saya butuh ...                          | Command                                                |
|-----------------------------------------|--------------------------------------------------------|
| Deploy versi baru                       | `./scripts/deploy.sh <version>`                       |
| Rollback ke versi sebelumnya            | `./scripts/deploy.sh <previous> --rollback`           |
| Lihat versi sekarang                    | `cat /opt/hris/.last-version`                         |
| Backup DB sekarang                      | `./scripts/backup-mysql.sh` (lihat BACKUP_DR §3.1)    |
| Reload nginx tanpa downtime             | `docker compose exec nginx nginx -s reload`           |
| Restart 1 service                       | `docker compose restart backend`                      |
| Smoke test                              | `curl -fsS https://hris.example.com/healthz`          |
| Audit chain verify                      | `docker compose exec backend php spark audit:verify-chain` |