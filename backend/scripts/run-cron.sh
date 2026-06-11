#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Quantara HRIS — local cron runner (macOS / Linux / WSL)
#
# Menjalankan semua command Spark sesuai jadwal mereka. Cocok untuk dev
# atau "manual ops mode" tanpa setup crontab/systemd.
#
# Logic dispatch:
#   - tanpa flag         → cek jam saat ini, run yang due
#   - --force            → run SEMUA daily command (skip schedule check)
#   - --category <name>  → run kategori spesifik saja
#   - --list             → tampilkan semua command + jadwal
#   - --dry-run          → tampil saja, tidak eksekusi
#   - --include-backup   → include command yang butuh S3/GPG (default skip)
#
# Usage:
#   ./scripts/run-cron.sh
#   ./scripts/run-cron.sh --force
#   ./scripts/run-cron.sh --category billing
#   ./scripts/run-cron.sh --list
#
# Dokumentasi: docs/CRON_JOBS.md
# ─────────────────────────────────────────────────────────────────────────────

set -uo pipefail

# Resolve project root (parent of scripts/)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$BACKEND_DIR"

LOG_DIR="$BACKEND_DIR/writable/cron-out"
mkdir -p "$LOG_DIR"

# ─── Args parser ─────────────────────────────────────────────────
FORCE=0
DRY_RUN=0
INCLUDE_BACKUP=0
LIST=0
CATEGORY=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --force)           FORCE=1; shift ;;
        --dry-run)         DRY_RUN=1; shift ;;
        --include-backup)  INCLUDE_BACKUP=1; shift ;;
        --list)            LIST=1; shift ;;
        --category)        CATEGORY="$2"; shift 2 ;;
        -h|--help)
            sed -n '/^# ─/,/^# ─/p' "$0" | head -40
            exit 0 ;;
        *)
            echo "Unknown arg: $1" >&2
            exit 2 ;;
    esac
done

# ─── Color helpers ───────────────────────────────────────────────
if [[ -t 1 ]]; then
    C_CYAN='\033[36m'; C_GREEN='\033[32m'; C_RED='\033[31m'
    C_YELLOW='\033[33m'; C_GRAY='\033[90m'; C_BOLD='\033[1m'; C_RESET='\033[0m'
else
    C_CYAN=''; C_GREEN=''; C_RED=''; C_YELLOW=''; C_GRAY=''; C_BOLD=''; C_RESET=''
fi

# ─── Definisi cron jobs ──────────────────────────────────────────
# Format pipe-separated: name|command|hour|dow|dom|category|external
# hour: 0-23 atau '*'
# dow:  0-6 (0=Sun..6=Sat) atau '*'
# dom:  1-31 atau '*'
# external: 1 = butuh service eksternal (skip default), 0 = aman dev
JOBS=(
    # Hourly
    "helpdesk-sla|helpdesk:check-sla|*|*|*|hourly|0"

    # Daily 02:00 — Backup (external)
    "db-backup|db:backup-encrypted --cleanup|2|*|*|backup|1"

    # Daily 01:00 — Cuti carry-over
    "leave-anniversary-carry|hr:leave-anniversary-carry|1|*|*|hr-daily|0"

    # Daily 03:30..03:55 — Compliance & cleanup
    "check-bpjs|hr:check-bpjs|3|*|*|compliance|0"
    "archive-audit|hr:archive-audit|3|*|*|compliance|0"
    "cleanup-attach|hr:cleanup-attachments|3|*|*|compliance|0"
    "purge-idempotency|idempotency:purge|3|*|*|maintenance|0"

    # Daily 05:00 — Cache warmup
    "cache-warm|cache:warm|5|*|*|maintenance|0"

    # Daily 06:00..06:35 — HR Operasional (9 command)
    "check-expiry|hr:check-expiry|6|*|*|hr-daily|0"
    "check-probation|hr:check-probation|6|*|*|hr-daily|0"
    "check-cert-expiry|hr:check-cert-expiry|6|*|*|hr-daily|0"
    "notify-carry-over|hr:notify-carry-over-cap|6|*|*|hr-daily|0"
    "check-anniversary|hr:check-anniversary|6|*|*|hr-daily|0"
    "apply-jic|hr:apply-jic|6|*|*|hr-daily|0"
    "apply-transfers|hr:apply-transfers|6|*|*|hr-daily|0"
    "deactivate-resigned|hr:deactivate-resigned|6|*|*|hr-daily|0"

    # Daily 07:00 — Recruitment
    "check-offer-expiry|hr:check-offer-expiry|7|*|*|recruitment|0"
    "check-mpp-overdue|hr:check-mpp-overdue|7|*|*|recruitment|0"

    # Daily 08:00 — SaaS Billing
    "billing-dunning|billing:dunning|8|*|*|billing|0"

    # Weekly Monday 04:00..06:30 — Security & audit
    "backup-verify|db:backup-verify --all-recent|4|1|*|backup|1"
    "audit-verify-chain|audit:verify-chain|4|1|*|security|0"
    "scan-tenant-leaks|security:scan-tenant-leaks|5|1|*|security|0"
    "cs-health-scores|cs:compute-health-scores|5|1|*|cs|0"
    "hr-audit|hr:audit|6|1|*|security|0"
    "sync-permissions|hr:sync-permissions|6|1|*|maintenance|0"

    # Monthly tanggal 1
    "billing-reset-meters|billing:reset-monthly-meters|0|*|1|billing|0"

    # Yearly 1 Jan
    "leave-yearend|hr:leave-yearend|0|*|1|yearly|0"
)

# ─── Helper: parse 1 job string ──────────────────────────────────
parse_job() {
    local IFS='|'
    read -r J_NAME J_CMD J_HOUR J_DOW J_DOM J_CAT J_EXT <<< "$1"
}

# ─── Helper: format schedule ─────────────────────────────────────
format_schedule() {
    local hour="$1" dow="$2" dom="$3"
    local hour_str="$([ "$hour" = "*" ] && echo "*" || printf "%02d:xx" "$hour")"
    local dow_names=(Min Sen Sel Rab Kam Jum Sab)
    local dow_str="$([ "$dow" = "*" ] && echo "every day" || echo "${dow_names[$dow]}")"
    local dom_str="$([ "$dom" = "*" ] && echo "" || echo " (tanggal $dom)")"
    echo "$hour_str $dow_str$dom_str"
}

# ─── --list mode ─────────────────────────────────────────────────
if [[ $LIST -eq 1 ]]; then
    echo
    echo -e "${C_CYAN}╔══════════════════════════════════════════════════════════════════════╗${C_RESET}"
    echo -e "${C_CYAN}║          Quantara HRIS — Cron Schedule (Local Dev Mode)             ║${C_RESET}"
    echo -e "${C_CYAN}╚══════════════════════════════════════════════════════════════════════╝${C_RESET}"
    echo

    # Sort by category then hour
    printf '%s\n' "${JOBS[@]}" | sort -t'|' -k6,6 -k3,3 | while IFS='' read -r line; do
        parse_job "$line"
        local ext_mark=""
        [[ "$J_EXT" = "1" ]] && ext_mark=" [needs S3/GPG]"
        printf " [%-12s] %-25s → spark %s%s\n" "$J_CAT" "$J_NAME" "$J_CMD" "$ext_mark"
        printf "${C_GRAY}              schedule: %s${C_RESET}\n" "$(format_schedule "$J_HOUR" "$J_DOW" "$J_DOM")"
    done

    echo
    echo -e "${C_CYAN}Total: ${#JOBS[@]} job${C_RESET}"
    exit 0
fi

# ─── Filter due jobs ─────────────────────────────────────────────
NOW_HOUR=$(date +%-H)
NOW_DOW=$(date +%w)      # 0=Sun..6=Sat (sama dengan PowerShell DayOfWeek)
NOW_DOM=$(date +%-d)
NOW_FMT=$(date +'%Y-%m-%d %H:%M')

TO_RUN=()
for job in "${JOBS[@]}"; do
    parse_job "$job"

    # Filter kategori
    if [[ -n "$CATEGORY" && "$J_CAT" != "$CATEGORY" ]]; then continue; fi

    # Skip external default
    if [[ "$J_EXT" = "1" && $INCLUDE_BACKUP -eq 0 ]]; then continue; fi

    # Mode --force
    if [[ $FORCE -eq 1 ]]; then
        TO_RUN+=("$job")
        continue
    fi

    # Schedule check
    [[ "$J_HOUR" != "*" && "$J_HOUR" != "$NOW_HOUR" ]] && continue
    [[ "$J_DOW"  != "*" && "$J_DOW"  != "$NOW_DOW"  ]] && continue
    [[ "$J_DOM"  != "*" && "$J_DOM"  != "$NOW_DOM"  ]] && continue

    TO_RUN+=("$job")
done

if [[ ${#TO_RUN[@]} -eq 0 ]]; then
    echo
    echo -e "${C_YELLOW}Tidak ada cron yang due saat ini ($NOW_FMT). Pakai --force atau --list.${C_RESET}"
    exit 0
fi

# ─── Execute ─────────────────────────────────────────────────────
START_ALL=$(date +%s)
declare -a RESULTS_NAME RESULTS_STATUS RESULTS_DUR RESULTS_LOG

mode_str="auto-schedule"
[[ $DRY_RUN -eq 1 ]] && mode_str="DRY-RUN"
[[ $FORCE -eq 1 ]] && mode_str="FORCE (all due)"

echo
echo -e "${C_CYAN}╔══════════════════════════════════════════════════════════════════════╗${C_RESET}"
printf "${C_CYAN}║  Quantara Cron Runner  ·  %-40s  ║${C_RESET}\n" "$NOW_FMT"
printf "${C_CYAN}║  Jobs to run: %-3d                 Mode: %-25s║${C_RESET}\n" "${#TO_RUN[@]}" "$mode_str"
echo -e "${C_CYAN}╚══════════════════════════════════════════════════════════════════════╝${C_RESET}"
echo

FAILED=0
for job in "${TO_RUN[@]}"; do
    parse_job "$job"
    start=$(date +%s)
    log_file="$LOG_DIR/$(date +%Y-%m-%d)_${J_NAME}.log"

    printf " ${C_BOLD}▸ %-25s${C_RESET} ${C_GRAY}[%s]${C_RESET} ${C_CYAN}spark %s${C_RESET}\n" "$J_NAME" "$J_CAT" "$J_CMD"

    if [[ $DRY_RUN -eq 1 ]]; then
        echo -e "   ${C_YELLOW}(dry-run, skipped)${C_RESET}"
        RESULTS_NAME+=("$J_NAME"); RESULTS_STATUS+=("SKIP")
        RESULTS_DUR+=("-"); RESULTS_LOG+=("-")
        continue
    fi

    # Execute via php spark
    php spark $J_CMD > "$log_file" 2> "$log_file.err"
    exit_code=$?
    end=$(date +%s)
    dur=$((end - start))

    if [[ $exit_code -eq 0 ]]; then
        echo -e "   ${C_GREEN}✓ done in ${dur}s${C_RESET}"
        RESULTS_STATUS+=("OK")
    else
        echo -e "   ${C_RED}✗ FAILED (exit ${exit_code}, ${dur}s) → ${log_file}.err${C_RESET}"
        RESULTS_STATUS+=("FAIL($exit_code)")
        FAILED=$((FAILED + 1))
    fi
    RESULTS_NAME+=("$J_NAME")
    RESULTS_DUR+=("${dur}s")
    RESULTS_LOG+=("$log_file")
done

# ─── Summary ─────────────────────────────────────────────────────
END_ALL=$(date +%s)
TOTAL_DUR=$((END_ALL - START_ALL))

echo
echo -e "${C_CYAN}╔══════════════════════════════════════════════════════════════════════╗${C_RESET}"
printf "${C_CYAN}║  Summary  ·  total %-3d jobs  ·  elapsed %ds                          ║${C_RESET}\n" "${#TO_RUN[@]}" "$TOTAL_DUR"
echo -e "${C_CYAN}╚══════════════════════════════════════════════════════════════════════╝${C_RESET}"

# Print result table
printf "%-25s %-12s %-10s %s\n" "Job" "Status" "Duration" "Log"
printf "%-25s %-12s %-10s %s\n" "---" "------" "--------" "---"
for i in "${!RESULTS_NAME[@]}"; do
    printf "%-25s %-12s %-10s %s\n" \
        "${RESULTS_NAME[$i]}" "${RESULTS_STATUS[$i]}" "${RESULTS_DUR[$i]}" "${RESULTS_LOG[$i]}"
done

echo
if [[ $FAILED -gt 0 ]]; then
    echo -e "${C_RED}⚠ ${FAILED} job(s) gagal — cek log di $LOG_DIR${C_RESET}"
    exit 1
fi
echo -e "${C_GREEN}✓ semua job sukses${C_RESET}"
exit 0