#!/usr/bin/env bash
# Mobile native init helper — Sprint M1.
#
# Pemakaian (sekali per developer baru, atau saat reset workspace):
#
#   cd mobile
#   bash scripts/init-native.sh
#
# Apa yang di-execute:
#   1. Cek node + npm + (macOS) cocoapods
#   2. Generate `android/` + `ios/` folder via @react-native-community/cli
#      ke direktori `_tmp/`, lalu pindahkan folder native saja
#      (skip src/, package.json, app.json yang sudah custom).
#   3. Install dependency JS: npm install
#   4. iOS only: cd ios && pod install
#
# Catatan: script ini di-test di macOS + Linux. Untuk Windows pakai
# WSL2 atau jalankan langkah-langkahnya manual via PowerShell.

set -euo pipefail

BLUE='\033[0;34m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

info()  { echo -e "${BLUE}→ $1${NC}"; }
ok()    { echo -e "${GREEN}✓ $1${NC}"; }
warn()  { echo -e "${YELLOW}⚠ $1${NC}"; }
fatal() { echo -e "${RED}✗ $1${NC}"; exit 1; }

[ -f package.json ] || fatal "Run dari folder mobile/ root."

# Sanity check
command -v node >/dev/null 2>&1 || fatal "node not found"
command -v npm  >/dev/null 2>&1 || fatal "npm not found"
NODE_MAJOR=$(node -p "process.versions.node.split('.')[0]")
[ "$NODE_MAJOR" -ge 18 ] || fatal "Node ≥ 18 required (current: $(node -v))"

if [ -d "android" ] && [ -d "ios" ]; then
  warn "Folder android/ dan ios/ sudah ada. Skip native init."
else
  info "Generate native folders via @react-native-community/cli..."

  TMPDIR=$(mktemp -d)
  trap "rm -rf $TMPDIR" EXIT

  npx --yes @react-native-community/cli@latest init HrisMobile \
    --version "0.76.3" \
    --skip-install \
    --directory "$TMPDIR/scaffold" \
    --skip-git-init

  if [ ! -d "android" ]; then
    info "Copy android/ dari scaffold..."
    cp -R "$TMPDIR/scaffold/android" ./android
    ok "android/ created"
  fi

  if [ ! -d "ios" ]; then
    info "Copy ios/ dari scaffold..."
    cp -R "$TMPDIR/scaffold/ios" ./ios
    ok "ios/ created"
  fi

  warn "Manual TODO:"
  warn "  1. Edit android/app/build.gradle: tambah apply from: project(':react-native-config').projectDir + '/dotenv.gradle'"
  warn "  2. Tambah <uses-permission> di android/app/src/main/AndroidManifest.xml (lihat README.md)"
  warn "  3. Tambah usage descriptions di ios/HrisMobile/Info.plist"
  warn "  4. Untuk push notif: download google-services.json + GoogleService-Info.plist dari Firebase Console"
fi

info "npm install..."
npm install
ok "deps installed"

if [[ "$OSTYPE" == "darwin"* ]]; then
  if command -v pod >/dev/null 2>&1; then
    info "pod install (iOS)..."
    (cd ios && pod install)
    ok "Pods installed"
  else
    warn "CocoaPods belum di-install. Run: sudo gem install cocoapods, lalu: cd ios && pod install"
  fi
fi

ok "Native init selesai."
echo ""
echo "Berikutnya:"
echo "  cp .env.example .env"
echo "  npm run android       # untuk Android"
echo "  npm run ios           # untuk iOS (macOS only)"