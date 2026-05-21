# HRIS — Monitoring & Observability

Sprint 5-6 menyediakan foundation monitoring untuk production. Tidak menjual
"observability stack" (Datadog/NewRelic) — sebagai gantinya pakai pendekatan
yang **opt-in** dan **gratis di tier dasar**.

## Layer monitoring yang tersedia

| Layer            | Tool default          | Upgrade path           | Status |
|------------------|----------------------|------------------------|--------|
| Application errors | `audit_logs` (CRITICAL) | Sentry / GlitchTip   | Hook ready |
| Daily health-check | `php spark hr:audit`  | Cron + email alert     | Production-ready |
| Uptime            | (nothing built-in)    | UptimeRobot / Healthchecks.io / Better Uptime | Manual setup |
| Database slow query | `audit_logs` retention check | MySQL slow query log | Manual setup |

## Application errors

Setiap unhandled exception di-capture oleh `App\Libraries\ExceptionLogger`
(Sprint 5-6 item MON.2):

1. **audit_logs entry** dengan severity `CRITICAL`. Bisa dilihat di
   `/admin/audit-logs?severity=CRITICAL`.
2. **File log** standar CodeIgniter di `writable/logs/log-YYYY-MM-DD.log`.
3. **Sentry forward** kalau library terpasang & `sentry.dsn` di-set.

### Setup Sentry / GlitchTip (opsional, gratis tier)

```bash
cd backend
composer require sentry/sentry
```

Tambah di `.env`:

```ini
sentry.dsn = https://xxx@xxx.ingest.sentry.io/xxx
sentry.environment = production
sentry.traces_sample_rate = 0.1     # 10% untuk performance traces
sentry.profiles_sample_rate = 0.1
```

Di server self-hosted GlitchTip (alternatif open source Sentry-compatible):

```ini
sentry.dsn = https://xxx@glitchtip.your-domain.com/1
```

Test setup:

```bash
php -r "throw new RuntimeException('Test exception untuk Sentry');"
```

Cek di `https://sentry.io` apakah event muncul. Kalau iya, integrasi sukses.
Kalau tidak, fallback ke audit_logs masih jalan (tidak ada side-effect).

### Capture manual dari kode aplikasi

```php
use App\Libraries\ExceptionLogger;

try {
    $payrollCalculator->calculate($period);
} catch (\Throwable $e) {
    ExceptionLogger::capture($e, [
        'period_id' => $period->id,
        'company_id' => $companyId,
    ]);
    // Continue with fallback or rethrow as needed
}

// Tambah breadcrumb sebelum operasi kritis (Sentry feature)
ExceptionLogger::breadcrumb('Mulai kalkulasi payroll', [
    'period' => $period->code,
    'employees' => count($employees),
]);
```

## Daily health-check

`php spark hr:audit` dijalankan harian via cron sudah ada dari Sprint 1
(lihat `docs/AUDIT.md` section "Audit Otomatis Berkala"). 25 cek otomatis
mencakup storage, database, security, operational, data quality.

Setup cron untuk Sprint 5-6 sudah dengan pattern:
```cron
0 6 * * *  cd /var/www/hris/backend && php spark hr:audit --json --mail --quiet
```

Email akan masuk ke role super admin + HR admin. Exit code:
- `0` = OK
- `1` = ada WARN
- `2` = ada CRITICAL

## Uptime monitoring (eksternal)

Endpoint `/api/v1/health` (sudah ada) return 200 OK saat backend hidup. Pasang
di salah satu service:

### UptimeRobot (gratis 50 monitor)
1. Sign up di https://uptimerobot.com
2. Add monitor → HTTP(s) → URL = `https://your-domain.com/api/v1/health`
3. Interval 5 menit, alert via email/SMS/Slack/Telegram

### Healthchecks.io (gratis 20 monitor)
- Lebih cocok untuk monitor cron `hr:audit`. Setiap kali audit selesai,
  tambahkan line ke akhir command:
```cron
0 6 * * *  cd /var/www/hris/backend && php spark hr:audit --json --mail --quiet && curl -fsS --retry 3 https://hc-ping.com/<your-uuid>
```
- Healthchecks.io bakal alert kalau audit tidak ping dalam 24+1 jam.

### Better Uptime / Pingdom
Sama seperti UptimeRobot tapi paid tier dengan fitur lebih (status page,
multi-region check). Pakai kalau SLA pelanggan butuh 99.9%+.

## Database slow query log (MySQL native)

Tambah ke `my.cnf` / `my.ini`:

```ini
[mysqld]
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 0.5    ; query > 500ms dianggap slow
log_queries_not_using_indexes = 1
```

Restart MySQL. Review weekly:
```bash
mysqldumpslow -t 20 /var/log/mysql/slow.log
```

Atau pakai pt-query-digest (Percona Toolkit) untuk laporan lengkap. Index
yang di-add di Sprint 2 (composite index migrasi #26) sudah cover hot path
utama; slow log bantu temukan kasus baru saat traffic naik.

## Alert escalation

Default pattern:

| Severity | Channel              | Receiver               |
|----------|---------------------|------------------------|
| WARN     | Email harian         | HR Admin               |
| CRITICAL | Email instant + Sentry alert | Super Admin + DevOps |
| Uptime down | UptimeRobot SMS/Telegram | On-call engineer |

Sesuaikan channel sesuai infrastruktur tim. Kalau pakai Slack: webhook URL
di Sentry alert + Healthchecks.io integration.

## Apa yang tidak di-cover Sprint 5-6

- **Distributed tracing** (OpenTelemetry, Tempo, Jaeger) — untuk multi-service
  setup. HRIS saat ini monolith CI4 + Vue, tidak butuh.
- **Metrics dashboard** (Prometheus + Grafana) — kalau perlu nanti, bisa
  expose `/metrics` endpoint via library prometheus-php client.
- **Log aggregation** (ELK / Loki) — kalau team > 1 server, perlu ship log
  ke central storage. Saat ini single-server log file masih cukup.

Saat traffic naik > 50K req/hari atau team grow, evaluate ulang.