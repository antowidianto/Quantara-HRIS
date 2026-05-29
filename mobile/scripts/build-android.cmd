@echo off
REM HRIS Mobile — Android APK build helper (Windows).
REM
REM Usage:
REM   cd mobile
REM   scripts\build-android.cmd debug     ← APK debug, signed dgn debug.keystore (gratis, untuk testing internal)
REM   scripts\build-android.cmd release   ← APK release, butuh keystore production di android/app
REM   scripts\build-android.cmd bundle    ← AAB release (untuk Google Play Store)
REM
REM Prerequisites:
REM   - Node ≥ 18, npm
REM   - JDK 17 (set JAVA_HOME)
REM   - Android SDK + cmdline-tools (set ANDROID_HOME / ANDROID_SDK_ROOT)
REM   - mobile/android/ folder sudah di-generate via init-native.sh atau
REM     `npx @react-native-community/cli init` (lihat README.md mobile)

setlocal enableextensions
set MODE=%1
if "%MODE%"=="" set MODE=debug

if not exist package.json (
  echo ✗ Run dari folder mobile/ root, bukan repo root.
  exit /b 1
)
if not exist android\gradlew.bat (
  echo ✗ Folder android/ belum ter-generate.
  echo   Jalankan dulu: bash scripts/init-native.sh atau di WSL.
  echo   Atau lihat docs/MOBILE_BUILD_APK.md.
  exit /b 1
)

REM ─── Step 1: install JS deps ──────────────────────────────────────
echo.
echo === Step 1/4: npm ci (install JS dependencies) ===
call npm ci
if errorlevel 1 (
  echo ✗ npm ci gagal. Periksa node ^& npm versi.
  exit /b 1
)

REM ─── Step 2: bundle JS ke android assets ──────────────────────────
REM Untuk release build, RN auto-bundle saat assembleRelease. Untuk
REM debug APK yang offline (tanpa metro), kita bundle manual.
echo.
echo === Step 2/4: prepare bundle directories ===
if not exist android\app\src\main\assets mkdir android\app\src\main\assets
if not exist android\app\src\main\res mkdir android\app\src\main\res

if /i "%MODE%"=="debug-offline" (
  echo === Bundle JS untuk debug-offline build ===
  call npx react-native bundle ^
    --platform android ^
    --dev true ^
    --entry-file index.js ^
    --bundle-output android\app\src\main\assets\index.android.bundle ^
    --assets-dest android\app\src\main\res
  if errorlevel 1 exit /b 1
)

REM ─── Step 3: gradle build ─────────────────────────────────────────
cd android

if /i "%MODE%"=="release" (
  echo.
  echo === Step 3/4: assembleRelease ===
  if not exist app\release.keystore (
    if not exist app\my-release-key.keystore (
      echo ⚠ release.keystore tidak ditemukan di android/app/.
      echo   Generate dulu pakai keytool — lihat docs/MOBILE_BUILD_APK.md section "Production keystore".
      cd ..
      exit /b 1
    )
  )
  call gradlew.bat assembleRelease --no-daemon
) else if /i "%MODE%"=="bundle" (
  echo.
  echo === Step 3/4: bundleRelease ^(AAB untuk Play Store^) ===
  call gradlew.bat bundleRelease --no-daemon
) else if /i "%MODE%"=="debug-offline" (
  echo.
  echo === Step 3/4: assembleDebug ^(offline bundle^) ===
  call gradlew.bat assembleDebug --no-daemon
) else (
  echo.
  echo === Step 3/4: assembleDebug ===
  call gradlew.bat assembleDebug --no-daemon
)

set BUILD_RC=%errorlevel%
cd ..

if not "%BUILD_RC%"=="0" (
  echo ✗ Gradle build gagal ^(exit %BUILD_RC%^).
  exit /b %BUILD_RC%
)

REM ─── Step 4: report output ────────────────────────────────────────
echo.
echo === Step 4/4: build artifacts ===
if /i "%MODE%"=="release" (
  echo APK release output:
  dir /b android\app\build\outputs\apk\release\*.apk 2>nul
  echo.
  echo Lokasi: android\app\build\outputs\apk\release\
) else if /i "%MODE%"=="bundle" (
  echo AAB release output:
  dir /b android\app\build\outputs\bundle\release\*.aab 2>nul
  echo.
  echo Lokasi: android\app\build\outputs\bundle\release\
  echo Upload .aab ini ke Google Play Console.
) else (
  echo APK debug output:
  dir /b android\app\build\outputs\apk\debug\*.apk 2>nul
  echo.
  echo Lokasi: android\app\build\outputs\apk\debug\
  echo Untuk install ke device USB:
  echo   adb install -r android\app\build\outputs\apk\debug\app-debug.apk
)

echo.
echo ✓ Build selesai.
endlocal