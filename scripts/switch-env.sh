#!/usr/bin/env bash
#
# switch-env.sh — Switch HRIS environment (database center) for backend & frontend.
#
# Usage:
#   ./scripts/switch-env.sh local
#   ./scripts/switch-env.sh staging
#   ./scripts/switch-env.sh production
#   ./scripts/switch-env.sh status
#
# Untuk tiap environment, script:
#   1. Backup .env lama → .env.backup (kalau ada)
#   2. Cari source: pertama <env>.env, fallback ke <env>.env.example
#   3. Copy ke backend/.env dan frontend/.env

set -euo pipefail

# ─── Colors ──────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    C_RESET='\033[0m'; C_CYAN='\033[36m'; C_GREEN='\033[32m'
    C_YELLOW='\033[33m'; C_RED='\033[31m'; C_GRAY='\033[90m'
else
    C_RESET=''; C_CYAN=''; C_GREEN=''; C_YELLOW=''; C_RED=''; C_GRAY=''
fi

# ─── Paths ───────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKEND_DIR="$REPO_ROOT/backend"
FRONTEND_DIR="$REPO_ROOT/frontend"
BACKEND_TPL="$BACKEND_DIR/env-templates"
FRONTEND_TPL="$FRONTEND_DIR/env-templates"

print_header() {
    echo
    echo -e "${C_CYAN}═══════════════════════════════════════════════════════════════${C_RESET}"
    echo -e "${C_CYAN}  $1${C_RESET}"
    echo -e "${C_CYAN}═══════════════════════════════════════════════════════════════${C_RESET}"
}

show_help() {
    print_header "HRIS Environment Switcher"
    cat <<EOF

USAGE:
  ./scripts/switch-env.sh <env>

ENVIRONMENTS (database center):
  local       Develop di laptop (DB: localhost)
  staging     Server staging   (DB: staging server)
  production  Server live      (DB: production server)
  status      Lihat env aktif sekarang

EXAMPLE:
  ./scripts/switch-env.sh local
  ./scripts/switch-env.sh staging

EOF
}

env_label() {
    local path="$1"
    if [[ ! -f "$path" ]]; then
        echo "  (tidak ada)"
        return
    fi
    local hdr
    hdr="$(grep -m1 -E '^# HRIS .* Environment' "$path" 2>/dev/null || true)"
    if [[ -n "$hdr" ]]; then
        echo "  ${hdr#\# }"
        return
    fi
    local ci vapp
    ci="$(grep -m1 -E '^CI_ENVIRONMENT' "$path" 2>/dev/null || true)"
    vapp="$(grep -m1 -E '^VITE_APP_ENV'  "$path" 2>/dev/null || true)"
    if [[ -n "$ci"   ]]; then echo "  $ci";   return; fi
    if [[ -n "$vapp" ]]; then echo "  $vapp"; return; fi
    echo "  (tidak ada marker)"
}

show_status() {
    print_header "HRIS Environment Status"
    echo
    echo -e "${C_YELLOW}Backend  ($BACKEND_DIR/.env):${C_RESET}"
    echo -e "${C_GREEN}$(env_label "$BACKEND_DIR/.env")${C_RESET}"
    echo
    echo -e "${C_YELLOW}Frontend ($FRONTEND_DIR/.env):${C_RESET}"
    echo -e "${C_GREEN}$(env_label "$FRONTEND_DIR/.env")${C_RESET}"
    echo
    echo -e "${C_GRAY}Available environments: local, staging, production${C_RESET}"
    echo -e "${C_GRAY}Pakai: ./scripts/switch-env.sh <env>${C_RESET}"
    echo
}

# Resolve source file: prefer <env>.env, fallback <env>.env.example
# Echoes "<path>|<is_example: 0|1>" or empty if not found.
resolve_source() {
    local tpl_dir="$1" env="$2"
    if [[ -f "$tpl_dir/$env.env" ]]; then
        echo "$tpl_dir/$env.env|0"
    elif [[ -f "$tpl_dir/$env.env.example" ]]; then
        echo "$tpl_dir/$env.env.example|1"
    else
        echo ""
    fi
}

switch_one() {
    local label="$1" tpl_dir="$2" target_dir="$3" env="$4"

    echo
    echo -e "${C_CYAN}→ $label${C_RESET}"

    local resolved src is_example
    resolved="$(resolve_source "$tpl_dir" "$env")"
    if [[ -z "$resolved" ]]; then
        echo -e "  ${C_RED}✗ Tidak ada template '$env.env' atau '$env.env.example' di $tpl_dir${C_RESET}"
        return 1
    fi
    src="${resolved%|*}"
    is_example="${resolved##*|}"

    local target="$target_dir/.env"
    local backup="$target_dir/.env.backup"

    if [[ -f "$target" ]]; then
        cp -f -- "$target" "$backup"
        echo -e "  ${C_GRAY}• Backup: .env → .env.backup${C_RESET}"
    fi

    cp -f -- "$src" "$target"
    echo -e "  ${C_GREEN}✓ Active: $(basename "$src") → .env${C_RESET}"

    if [[ "$is_example" == "1" && "$env" != "local" ]]; then
        echo -e "  ${C_YELLOW}⚠ Pakai .example — secret masih placeholder <REPLACE_*>.${C_RESET}"
        echo -e "  ${C_YELLOW}  Copy: $tpl_dir/$env.env.example → $tpl_dir/$env.env${C_RESET}"
        echo -e "  ${C_YELLOW}  Lalu isi nilainya, jalankan ulang script ini.${C_RESET}"
    fi
    return 0
}

# ─── Main ────────────────────────────────────────────────────────────

ENV="${1:-}"

case "$ENV" in
    "")
        show_help
        show_status
        exit 0
        ;;
    status)
        show_status
        exit 0
        ;;
    local|staging|production)
        ;;
    -h|--help|help)
        show_help
        exit 0
        ;;
    *)
        echo -e "${C_RED}✗ Environment tidak dikenal: '$ENV'${C_RESET}"
        echo "  Pilihan: local, staging, production, status"
        exit 2
        ;;
esac

print_header "Switching HRIS environment → $ENV"

ok_backend=1
ok_frontend=1
switch_one "Backend  (CodeIgniter 4)" "$BACKEND_TPL"  "$BACKEND_DIR"  "$ENV" || ok_backend=0
switch_one "Frontend (Vue 3 + Vite)"  "$FRONTEND_TPL" "$FRONTEND_DIR" "$ENV" || ok_frontend=0

echo
if [[ $ok_backend -eq 1 && $ok_frontend -eq 1 ]]; then
    echo -e "${C_GREEN}✓ Environment '$ENV' aktif.${C_RESET}"

    if [[ "$ENV" == "production" ]]; then
        echo
        echo -e "${C_RED}⚠ PERHATIAN — environment PRODUCTION:${C_RESET}"
        echo -e "${C_YELLOW}   • Boot guard akan reject kalau secret masih default.${C_RESET}"
        echo -e "${C_YELLOW}   • Pastikan production.env sudah berisi secret real.${C_RESET}"
        echo -e "${C_YELLOW}   • Backup database sebelum migrate.${C_RESET}"
    fi

    echo
    echo -e "${C_CYAN}Next step:${C_RESET}"
    echo "  cd backend && php spark migrate"
    echo "  cd frontend && npm run dev"
    echo
    exit 0
else
    echo -e "${C_RED}✗ Switch gagal. Lihat pesan error di atas.${C_RESET}"
    exit 1
fi