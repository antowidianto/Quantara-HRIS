#!/usr/bin/env bash
# HRIS — Setup Development (Linux / macOS / WSL).
#
# Otomasi langkah onboarding developer baru:
#   1. Cek dependencies (PHP, Composer, Node, npm)
#   2. Backend: composer install + .env + migrate + seed
#   3. Frontend: npm install + .env
#
# Idempotent — aman dijalankan berkali-kali.
#
# Usage:
#   bash scripts/setup-dev.sh
#   bash scripts/setup-dev.sh --skip-migrate
#   bash scripts/setup-dev.sh --reset           # destructive: drop SQLite

set -euo pipefail

# ─── flags ─────────────────────────────────────────────────────────
SKIP_MIGRATE=0
RESET=0
for arg in "$@"; do
    case "$arg" in
        --skip-migrate) SKIP_MIGRATE=1 ;;
        --reset)        RESET=1 ;;
        -h|--help)
            grep '^#' "$0" | sed 's/^# \?//'
            exit 0
            ;;
        *) echo "Unknown arg: $arg" >&2; exit 2 ;;
    esac
done

# ─── colors ────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    C_CYAN='\033[36m'; C_GREEN='\033[32m'; C_YEL='\033[33m'
    C_RED='\033[31m'; C_GRAY='\033[90m'; C_OFF='\033[0m'
else
    C_CYAN=''; C_GREEN=''; C_YEL=''; C_RED=''; C_GRAY=''; C_OFF=''
fi

step() { printf "\n${C_CYAN}=== %s ===${C_OFF}\n" "$1"; }
ok()   { printf "  ${C_GREEN}✓${C_OFF} %s\n" "$1"; }
skip() { printf "  ${C_GRAY}-${C_OFF} %s\n" "$1"; }
warn() { printf "  ${C_YEL}!${C_OFF} %s\n" "$1"; }

# ─── locate root ───────────────────────────────────────────────────
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"
cd "$ROOT"

# ─── 1. Cek deps ───────────────────────────────────────────────────
step 'Cek tools yang dibutuhkan'
missing=()
for cmd in php composer node npm; do
    if command -v "$cmd" >/dev/null 2>&1; then
        ver="$("$cmd" --version 2>&1 | head -n1)"
        ok "$cmd : $ver"
    else
        missing+=("$cmd")
        warn "$cmd tidak ditemukan"
    fi
done
if [[ ${#missing[@]} -gt 0 ]]; then
    echo
    printf "${C_RED}Install dulu: %s${C_OFF}\n" "${missing[*]}"
    echo 'Lihat SETUP.md untuk panduan instalasi.'
    exit 1
fi

# ─── 2. Backend ────────────────────────────────────────────────────
step 'Backend setup'
pushd "$ROOT/backend" >/dev/null

if [[ ! -d vendor ]]; then
    echo '  Installing composer dependencies...'
    composer install --no-interaction --prefer-dist
else
    skip 'vendor/ sudah ada (skip composer install)'
fi

if [[ ! -f .env ]]; then
    cp .env.example .env
    ok '.env dibuat dari .env.example'
else
    skip '.env sudah ada'
fi

if [[ $RESET -eq 1 && -f writable/hris.sqlite ]]; then
    rm -f writable/hris.sqlite
    warn 'SQLite database dihapus (--reset)'
fi

if [[ $SKIP_MIGRATE -eq 0 ]]; then
    echo '  Running migrations...'
    php spark migrate
    echo '  Running seeder (InitialSeeder)...'
    php spark db:seed InitialSeeder
    ok 'Database ter-migrate & ter-seed'
else
    skip 'Migrate/seed di-skip (--skip-migrate)'
fi

popd >/dev/null

# ─── 3. Frontend ───────────────────────────────────────────────────
step 'Frontend setup'
pushd "$ROOT/frontend" >/dev/null

if [[ ! -d node_modules ]]; then
    echo '  Installing npm dependencies...'
    npm install --no-audit --no-fund
else
    skip 'node_modules/ sudah ada (skip npm install)'
fi

if [[ ! -f .env && -f .env.example ]]; then
    cp .env.example .env
    ok '.env dibuat dari .env.example'
elif [[ ! -f .env ]]; then
    cat > .env <<EOF
VITE_API_BASE_URL=http://localhost:8080
VITE_USE_PROXY=false
EOF
    ok '.env dibuat dengan default'
else
    skip '.env sudah ada'
fi

popd >/dev/null

# ─── Selesai ───────────────────────────────────────────────────────
step 'Selesai!'
cat <<EOF

${C_CYAN}Untuk start aplikasi (butuh 2 terminal):${C_OFF}

  ${C_YEL}Terminal 1 (Backend):${C_OFF}
    cd backend
    php spark serve --port 8080

  ${C_YEL}Terminal 2 (Frontend):${C_OFF}
    cd frontend
    npm run dev

${C_GREEN}Default login: admin@hris.local / Admin@12345${C_OFF}
${C_GREEN}Frontend: http://localhost:5173${C_OFF}
${C_GREEN}Backend:  http://localhost:8080/api/v1${C_OFF}
EOF