@echo off
REM ─────────────────────────────────────────────────────────────────────
REM migrate-to-mysql.cmd
REM
REM Helper batch untuk migrasi backend HRIS dari SQLite ke MySQL via
REM Laragon. Lihat docs\MYSQL_MIGRATION.md untuk dokumentasi lengkap.
REM
REM Pre-req:
REM   1. Laragon sudah di-install di C:\laragon
REM   2. Laragon "Start All" sudah dijalankan (MySQL up di port 3306)
REM   3. backend\.env sudah pointing ke MySQL (default sekarang)
REM
REM Usage:
REM   double-click file ini, atau dari cmd:
REM     scripts\migrate-to-mysql.cmd          (fresh start, seed dari awal)
REM     scripts\migrate-to-mysql.cmd --keep   (migrate data dari hris.sqlite)
REM ─────────────────────────────────────────────────────────────────────

setlocal
cd /d "%~dp0\.."

set MYSQL_BIN=C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe
set MODE=fresh
if /I "%~1"=="--keep" set MODE=keep

echo.
echo === HRIS — Migrate to MySQL (Laragon) ===
echo Mode: %MODE%
echo.

REM 1) Health check MySQL
echo [1/5] Cek koneksi MySQL...
"%MYSQL_BIN%" -uroot -h127.0.0.1 -P3306 -e "SELECT 1;" >nul 2>&1
if errorlevel 1 (
    echo.
    echo [ERROR] Tidak bisa connect ke MySQL di 127.0.0.1:3306.
    echo         Buka Laragon ^(C:\laragon\laragon.exe^) lalu klik "Start All".
    echo         Setelah hijau, jalankan ulang script ini.
    pause
    exit /b 1
)
echo       OK
echo.

REM 2) Buat database
echo [2/5] Membuat database hris_db...
"%MYSQL_BIN%" -uroot -h127.0.0.1 -P3306 -e "CREATE DATABASE IF NOT EXISTS hris_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 (
    echo [ERROR] Gagal create database.
    pause
    exit /b 1
)
echo       OK
echo.

REM 3) Run migrate
REM   Migrasi 000010+ menyisipkan auto-grant ke role_permissions yang
REM   referensi role_id 1/2 (Super Admin / HR Admin). Role-row baru
REM   dibuat di InitialSeeder, jadi saat migrate jalan FK belum punya
REM   parent. SQLite permissive — MySQL strict. Solusinya: pakai SET
REM   PERSIST yang affect new connections (MySQL 8.0+). Setelah migrate
REM   selesai, restore ke 1 dengan PERSIST juga.
echo [3/5] Menjalankan migrasi schema...
echo       (FK checks OFF dulu via SET PERSIST — MySQL 8 only)
"%MYSQL_BIN%" -uroot -h127.0.0.1 -P3306 -e "SET PERSIST foreign_key_checks = 0;"
php spark migrate
set MIGRATE_RC=%errorlevel%
echo       Restore FK checks ke ON...
"%MYSQL_BIN%" -uroot -h127.0.0.1 -P3306 -e "SET PERSIST foreign_key_checks = 1;"
if not "%MIGRATE_RC%"=="0" (
    echo [ERROR] php spark migrate gagal ^(rc=%MIGRATE_RC%^). Cek writable\logs\.
    pause
    exit /b 1
)
echo       OK
echo.

REM 4) Seed atau migrate data
if "%MODE%"=="keep" (
    echo [4/5] Memindahkan data dari writable\hris.sqlite...
    if not exist writable\hris.sqlite (
        echo [WARN] writable\hris.sqlite tidak ditemukan. Skip.
    ) else (
        php scripts\migrate-sqlite-to-mysql.php
        if errorlevel 1 (
            echo [ERROR] Migrasi data gagal. Cek output di atas.
            pause
            exit /b 1
        )
    )
) else (
    echo [4/5] Seeding data demo lengkap...
    php spark db:seed App\Database\Seeds\SeedAll
    if errorlevel 1 (
        echo [ERROR] Seeder gagal.
        pause
        exit /b 1
    )
)
echo       OK
echo.

REM 5) Verifikasi
echo [5/5] Verifikasi count tabel...
"%MYSQL_BIN%" -uroot -h127.0.0.1 -P3306 hris_db -e "SELECT 'companies' AS t, COUNT(*) AS rows FROM companies UNION ALL SELECT 'employees', COUNT(*) FROM employees UNION ALL SELECT 'users', COUNT(*) FROM users UNION ALL SELECT 'permissions', COUNT(*) FROM permissions;"
echo.

echo === Selesai ===
echo.
echo Login: admin@example.com / Password123
echo Backend dev server: php spark serve   (di folder backend\)
echo Frontend dev: npm run dev             (di folder frontend\)
echo.
pause
endlocal