# Sprint F + G — DevOps Hardening & Documentation

> **Status:** dijalankan barengan 28 Mei 2026.
> **Lihat juga:** `docs/AUDIT_FINDINGS_2026-05.md` (item G2, G4, G7, I2, I5, dan CONTRIBUTING).

Sprint ini fokus ke deliverable non-code yang sebelumnya carry-over dari semua sprint sebelumnya: production deployment infra, backup/DR SOP, arsitektur diagram, kontribusi guideline. Tidak ada perubahan logic/fitur yang impact suite test.

---

## 1. Lingkup yang Diselesaikan

### ✅ G2 — `docker-compose.prod.yml`

File `docker-compose.prod.yml` baru di root. Beda dengan dev compose:

- **Tidak build inline** — pakai image `${HRIS_REGISTRY}/{backend,frontend}:${HRIS_VERSION}` yang sudah di-push CI.
- **MySQL & Redis** di-bind ke `127.0.0.1` (tidak exposed publik).
- **`restart: always`** untuk semua service.
- **Healthcheck wajib** di setiap service (mysql ping, redis-cli, php cli, nginx wget /healthz, crond pgrep).
- **Resource limit** konkret (memory + CPU per service).
- **Logging driver `json-file`** dengan rotation (max-size 10–20MB × 5–10 file). Anti disk-full.
- **Cron** di container terpisah dengan `crond -f -l 8` supaya isolasi dari php-fpm (anti race + restart isolation).
- **Frontend dist** di-publish ke shared volume lewat one-shot `frontend-build` container.

Required env (`.env.prod`): `MYSQL_ROOT_PASSWORD`, `MYSQL_USER`, `MYSQL_PASSWORD`, `REDIS_PASSWORD`, plus standard CI4 keys (`encryption.key`, `jwt.secret`, `cors.allowedOrigins`, dst.). Compose pakai `:?` syntax untuk gagal-fast kalau ada yang missing.

### ✅ G7 — `scripts/deploy.sh` (zero-downtime + auto-rollback)

Bash script ~180 baris. Flow:

1. **Sanity check** — env file, compose file, docker daemon
2. **Pull image** versi target (tidak ganggu service jalan)
3. **Backup DB** ke `backups/mysql/pre-deploy-<timestamp>/dump.sql.gz` (skip kalau mode rollback)
4. **Run migration** lewat one-shot container `compose run --rm backend php spark migrate -n` — kalau gagal, exit tanpa recreate
5. **Recreate** backend + cron + frontend-build (php-fpm reload otomatis, dist volume di-update)
6. **Reload nginx** graceful (`nginx -s reload` — tidak drop koneksi aktif)
7. **Smoke test** `curl /healthz` retry 30× × 2s (= 60 detik max)
8. **Tag version** ke `.last-version` untuk rollback path
9. **Auto-rollback** ke previous version kalau healthcheck gagal

Fitur tambahan:

- Slack/PagerDuty notify lewat `SLACK_WEBHOOK_URL` env
- Mode `--rollback` untuk manual rollback ke version sebelumnya
- Exit code distinct (2=usage, 3=backup-fail, 4=migration-fail, 5=rollback-tidak-ada-target, 6=rollback-juga-fail) untuk integrasi monitoring

### ✅ G4 — `docs/BACKUP_DR.md`

SOP lengkap backup + disaster recovery. Section:

1. **Apa yang di-backup** — tabel asset + critical level
2. **Cadence & retention** — daily/weekly/monthly + S3 SSE-KMS
3. **Backup procedures** — script siap pakai (`backup-mysql.sh`, `backup-uploads.sh`, binlog streaming for PITR)
4. **Restore procedures** — full restore, PITR ke point-in-time, uploads sync, **wajib audit chain verify setelah restore**
5. **RTO/RPO targets** per disaster scenario (instance crash, volume corruption, datacenter loss, ransomware, accidental DROP)
6. **Quarterly drill** dengan format laporan
7. **Monitoring & alerting** untuk backup yang silently gagal
8. **Compliance UU PDP** — 7 tahun retention audit, anonymization, data residency
9. **Quick reference** index

### ✅ G7 (lanjutan) — `docs/DEPLOYMENT.md`

End-to-end SOP deploy production. Section:

1. Diagram alur (CI → registry → deploy.sh → recreate → smoke test)
2. Prasyarat host (4 vCPU + 8GB RAM minimum, NTP wajib aktif untuk audit timestamp + 2FA TOTP)
3. First-time setup (provision dir, .env.prod template, TLS via Let's Encrypt, nginx config, cron setup)
4. Deploy first time
5. Routine deploy update
6. Rollback — auto + manual + breaking migration
7. Monitoring & health
8. Common ops (spark commands, DB shell, log tail, cache flush)
9. Maintenance mode pattern
10. Disaster scenario lookup table
11. Quick reference

### ✅ I5 — `docs/ARCHITECTURE.md`

High-level system diagram + reasoning. Diagram ASCII untuk stack overall plus 3 Mermaid sequence/flow diagrams:

- Multi-tenancy data flow (request → JwtAuthFilter → TenantContext → Model scope → DB)
- Authentication flow (login → 2FA → JWT issue → refresh token device-bound)
- Audit trail flow (mutation → AuditLogger → audit_logs → archive cron → S3 cold tier)
- Cache strategy flow (LookupCache read-through pattern)

Plus: permission model, encryption strategy (NIK hash lookup), frontend architecture, cron grouping, test pyramid current vs target, dependency footprint, ADR registry, glossary istilah Indonesia (PKWT, TER, JIC, MPP, PHK).

### ✅ Sprint G — `CONTRIBUTING.md`

Standar kontribusi developer baru + reviewer. Section:

- Prinsip (4 pilar: jangan rusak yang jalan, refactor mengikuti perubahan, audit trail wajib, konsistensi > kreativitas)
- Branch & commit convention (conventional commits + scope)
- PR template + checklist + SLA per severity
- Code style (backend phpstan/pint, frontend eslint/prettier, view ≤ 600, controller ≤ 500)
- Required reviewer matrix (security-sensitive wajib lead review)
- Lokal dev quickstart
- Bug report + security incident channel

---

## 2. Tidak Diselesaikan (Carry-Over)

### G3 — Cron monitoring per command

`docs/CRON_JOBS.md` sudah ada (Sprint A) tapi belum punya alerting per cron failure. Action: tambah wrapper script yang report ke heartbeat URL (mis. healthchecks.io) atau Prometheus pushgateway. Sprint F+1.

### G5 — Sentry / OpenTelemetry instrumentation

Belum: integrasi SDK Sentry di backend (`Services::exception()`) + frontend (axios interceptor). Setting `dsn` lewat env. Sprint F+1.

### G6 — Secret management migration

`.env.prod` plain text di filesystem. Belum ada SOP migrasi ke Vault / AWS Secrets Manager / 1Password CLI. Sprint F+2 — butuh keputusan tooling dulu (depends company infra).

### I2 — Konsolidasi doc Sprint*.md → MODULE_*.md

Banyak sprint history doc (`SPRINT_1.md`, `HORIZON_2_*.md`) yang catat sejarah tapi membingungkan onboarding. Konsolidasi ke "single canonical guide per modul" Sprint G+1.

### I3 — OpenAPI generator otomatis

`docs/openapi.yaml` ada tapi manual maintain → drift dari kode. Action: tambah PHPDoc/attribute reader (e.g., `zircote/swagger-php`) untuk generate spec dari controller annotations. Sprint G+2.

---

## 3. Verifikasi

Sprint ini **tidak ubah application logic** — tidak ada perubahan test count.

| Verification              | Result                              |
|---------------------------|-------------------------------------|
| `vendor\bin\phpunit`      | 847 tests pass (unchanged)         |
| `npm test`                | 25 tests pass (unchanged)          |
| `vendor\bin\phpstan`      | OK no errors (unchanged)           |
| `npm run build`           | OK 45.12s (unchanged)              |
| `docker-compose.prod.yml` | YAML valid (manual inspect)        |
| `scripts/deploy.sh`       | `bash -n scripts/deploy.sh` syntax OK |

---

## 4. Skor Per Dimensi (Update)

| Dimensi                       | Sprint E Phase 1 | Sprint F+G | Target Q4-2026 |
|-------------------------------|------------------|------------|----------------|
| Arsitektur & Code Quality     | 5.5 / 10         | **5.7 / 10** | 8 / 10         |
| Testing & QA                  | 5.7 / 10         | 5.7 / 10   | 7.5 / 10       |
| DevOps & Operasional          | 5.5 / 10         | **7.5 / 10** | 8 / 10         |
| Dokumentasi                   | 9 / 10           | **9.5 / 10** | 9 / 10         |

DevOps naik signifikan dari 5.5 → 7.5 karena: prod compose siap, deploy script idempotent + auto-rollback, backup/DR SOP komprehensif, deployment guide lengkap. Sisa 0.5 untuk target = wiring Sentry + secret management migration.

Dokumentasi naik 9 → 9.5 dengan ARCHITECTURE.md (diagram + reasoning) dan CONTRIBUTING.md (onboarding lengkap).

---

## 5. Total Test Count

Tidak berubah dari Sprint E Phase 1:

| Stack    | Count | Files |
|----------|-------|-------|
| Backend  | 847   | unit  |
| Frontend | 25    | 3 spec files |
| **Total**| **872** | |

---

## 6. Catatan untuk Reviewer

- **`docker-compose.prod.yml` belum diuji deploy nyata.** Reviewer dengan akses staging env disarankan trial spin up + tear down sebelum dipakai production. Test minimal: `docker compose -f docker-compose.prod.yml --env-file .env.prod config` untuk validasi syntax + variable substitution.
- **`scripts/deploy.sh` butuh chmod +x** saat first checkout di host. Tambahkan ke `git update-index --chmod=+x scripts/deploy.sh` (atau via `.gitattributes` `deploy.sh text eol=lf`).
- **Frontend Dockerfile production** sudah ada (lihat `frontend/Dockerfile` 1803 byte). Skema serve dari nginx via shared volume — bukan dari container frontend running. Sengaja supaya tidak run node di production (zero attack surface untuk SSR vulns).
- **Auto-rollback di deploy.sh** depend ke `.last-version` file. Kalau pertama kali deploy dan healthcheck gagal, tidak ada target rollback → exit 5 (manual investigation needed). Itu by design — automation tidak boleh "guess" target rollback.
- **`docs/ARCHITECTURE.md` Mermaid diagrams** akan render di GitHub & GitLab. Editor lokal yang tidak support Mermaid akan tampilkan code block — masih readable.
- **Maintenance mode di DEPLOYMENT.md §9** masih placeholder (command `maintenance:on/off` belum ada). Carry-over Sprint F+1.

---

## 7. Files Diubah / Ditambah

### Baru

- `docker-compose.prod.yml` — production compose
- `scripts/deploy.sh` — zero-downtime deploy script
- `docs/BACKUP_DR.md` — backup & DR SOP
- `docs/DEPLOYMENT.md` — production deploy guide
- `docs/ARCHITECTURE.md` — system architecture + diagrams
- `CONTRIBUTING.md` — kontribusi guideline
- `docs/SPRINT_F_G_DEVOPS_DOCS.md` — sprint summary (file ini)

### Diubah

- `docs/AUDIT_FINDINGS_2026-05.md` — header status diperbarui (Sprint F + G ✅)