#!/usr/bin/env bash
# HRIS — Production deployment script (Sprint F / G7).
#
# Tujuan: zero-downtime deploy + safe migration + rollback path.
#
# Usage:
#   ./scripts/deploy.sh <version> [--rollback]
#   ./scripts/deploy.sh 1.2.3
#   ./scripts/deploy.sh 1.2.2 --rollback
#
# Variabel ENV yang dibaca (override default):
#   HRIS_REGISTRY      registry image (default: registry.example.com/hris)
#   HRIS_PROJECT_DIR   path checkout (default: /opt/hris)
#   HRIS_ENV_FILE      env file (default: .env.prod)
#   COMPOSE_FILE       compose file (default: docker-compose.prod.yml)
#   SLACK_WEBHOOK_URL  optional webhook untuk notifikasi
#
# Mekanisme zero-downtime:
#   1. Pull image versi baru (tidak interrupt service jalan)
#   2. Backup DB (pre-deploy snapshot)
#   3. Run migration (idempotent — CI4 spark migrate aman re-run)
#   4. Recreate backend + cron container (php-fpm reload otomatis)
#   5. Recreate frontend-build → re-populate dist volume
#   6. Reload nginx (graceful, tidak drop koneksi aktif)
#   7. Smoke test /healthz endpoint
#   8. Tag previous version sebagai rollback point di file
#
# Saat smoke test gagal, script auto-rollback ke versi sebelumnya.

set -euo pipefail

# ─── Defaults & arg parsing ─────────────────────────────────────────
HRIS_REGISTRY="${HRIS_REGISTRY:-registry.example.com/hris}"
HRIS_PROJECT_DIR="${HRIS_PROJECT_DIR:-/opt/hris}"
HRIS_ENV_FILE="${HRIS_ENV_FILE:-.env.prod}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
HEALTH_URL="${HEALTH_URL:-http://localhost/healthz}"
HEALTH_RETRIES="${HEALTH_RETRIES:-30}"
HEALTH_INTERVAL="${HEALTH_INTERVAL:-2}"
ROLLBACK_FILE="${HRIS_PROJECT_DIR}/.last-version"

VERSION="${1:-}"
MODE="deploy"
if [[ "${2:-}" == "--rollback" ]]; then
    MODE="rollback"
fi

if [[ -z "$VERSION" ]]; then
    echo "Usage: $0 <version> [--rollback]" >&2
    echo "Example: $0 1.2.3" >&2
    exit 2
fi

# ─── Helpers ────────────────────────────────────────────────────────
log() {
    echo "[$(date -u +%FT%TZ)] $*"
}

err() {
    log "ERROR: $*" >&2
}

notify() {
    local message="$1"
    if [[ -n "${SLACK_WEBHOOK_URL:-}" ]]; then
        curl -fsS -X POST -H 'Content-Type: application/json' \
            -d "{\"text\":\"[HRIS deploy] ${message}\"}" \
            "$SLACK_WEBHOOK_URL" >/dev/null 2>&1 || true
    fi
}

compose() {
    docker compose -f "$COMPOSE_FILE" --env-file "$HRIS_ENV_FILE" "$@"
}

# ─── Sanity checks ──────────────────────────────────────────────────
cd "$HRIS_PROJECT_DIR" || { err "HRIS_PROJECT_DIR=$HRIS_PROJECT_DIR tidak ada"; exit 1; }

if [[ ! -f "$COMPOSE_FILE" ]]; then
    err "Compose file $COMPOSE_FILE tidak ditemukan di $HRIS_PROJECT_DIR"
    exit 1
fi

if [[ ! -f "$HRIS_ENV_FILE" ]]; then
    err "Env file $HRIS_ENV_FILE tidak ditemukan. Buat dulu dari env-templates/."
    exit 1
fi

# Pastikan docker daemon hidup
docker info >/dev/null 2>&1 || { err "Docker daemon tidak responsif"; exit 1; }

# ─── Pre-deploy snapshot DB ─────────────────────────────────────────
backup_db() {
    local snapshot_dir="${HRIS_PROJECT_DIR}/backups/mysql/pre-deploy-$(date -u +%Y%m%d-%H%M%S)"
    mkdir -p "$snapshot_dir"
    log "Backup DB ke $snapshot_dir/dump.sql.gz"
    if compose exec -T mysql sh -c \
        'mysqldump --single-transaction --quick --lock-tables=false --routines --triggers \
            -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
        | gzip > "$snapshot_dir/dump.sql.gz"; then
        log "Backup OK ($(du -h "$snapshot_dir/dump.sql.gz" | awk '{print $1}'))"
    else
        err "Backup gagal — abort deploy"
        exit 3
    fi
}

# ─── Healthcheck loop ───────────────────────────────────────────────
wait_healthy() {
    local label="$1"
    log "Smoke test $label di $HEALTH_URL ..."
    for ((i=1; i<=HEALTH_RETRIES; i++)); do
        if curl -fsS --max-time 3 "$HEALTH_URL" >/dev/null 2>&1; then
            log "$label OK setelah ${i} attempt"
            return 0
        fi
        sleep "$HEALTH_INTERVAL"
    done
    err "$label gagal setelah $((HEALTH_RETRIES * HEALTH_INTERVAL))s"
    return 1
}

# ─── Deploy / rollback ──────────────────────────────────────────────
do_deploy() {
    local target="$1"

    log "Mode: $MODE → $target (registry: $HRIS_REGISTRY)"
    notify ":rocket: Deploy dimulai → version=$target"

    log "Pull image $HRIS_REGISTRY/backend:$target dan frontend:$target ..."
    docker pull "$HRIS_REGISTRY/backend:$target"
    docker pull "$HRIS_REGISTRY/frontend:$target"

    if [[ "$MODE" == "deploy" ]]; then
        backup_db
    else
        log "Rollback mode — skip backup (snapshot pre-deploy yang bermasalah masih tersedia)"
    fi

    # Export version supaya compose pick up via ${HRIS_VERSION}
    export HRIS_VERSION="$target"
    export HRIS_REGISTRY

    # Run migration (idempotent). Pakai container backend versi baru tapi
    # one-shot run, bukan recreate utama. CI4 spark migrate handle "no-op
    # kalau sudah migrated".
    log "Run migration (php spark migrate) ..."
    if ! compose run --rm --no-deps backend php spark migrate -n; then
        err "Migration gagal. Service belum di-recreate, posisi aman untuk investigate."
        notify ":warning: Migration gagal di $target — service masih versi lama"
        exit 4
    fi

    # Recreate backend + cron + frontend-build → nginx auto pick volume baru
    log "Recreate backend + cron + frontend-build ..."
    compose up -d --remove-orphans backend cron frontend-build

    # Tunggu frontend-build selesai (one-shot)
    log "Tunggu frontend-build selesai ..."
    compose wait frontend-build || log "frontend-build exit (expected)"

    # Reload nginx (graceful — tidak drop koneksi aktif)
    log "Reload nginx ..."
    compose exec -T nginx nginx -t && compose exec -T nginx nginx -s reload

    # Smoke test
    if wait_healthy "Healthcheck"; then
        log "Deploy SUCCESS — version=$target"
        notify ":white_check_mark: Deploy success → $target"

        # Catat version sukses untuk rollback path
        local previous=""
        [[ -f "$ROLLBACK_FILE" ]] && previous=$(cat "$ROLLBACK_FILE" || echo "")
        echo "$target" > "$ROLLBACK_FILE"
        if [[ -n "$previous" && "$previous" != "$target" ]]; then
            log "Rollback target tersedia: $previous (jalankan: $0 $previous --rollback)"
        fi
    else
        if [[ "$MODE" == "deploy" ]]; then
            err "Healthcheck gagal — auto rollback"
            notify ":rotating_light: Healthcheck gagal di $target — auto rollback"
            local prev
            prev=$(cat "$ROLLBACK_FILE" 2>/dev/null || echo "")
            if [[ -n "$prev" && "$prev" != "$target" ]]; then
                log "Rollback ke $prev"
                MODE="rollback" do_deploy "$prev"
            else
                err "Tidak ada version sebelumnya untuk rollback. Manual investigation needed."
                exit 5
            fi
        else
            err "Rollback ke $target juga gagal. Kondisi kritis — page on-call."
            notify ":sos: Rollback ke $target juga gagal"
            exit 6
        fi
    fi
}

do_deploy "$VERSION"