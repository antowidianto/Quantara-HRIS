@echo off
REM ============================================================
REM Fix konflik port 3306 antara MariaDB (Windows Service) dan
REM Laragon MySQL 8.4. Karena hris_db tersimpan di Laragon MySQL,
REM kita perlu STOP & DISABLE service MariaDB.
REM
REM CARA PAKAI:
REM   Klik kanan file ini -> Run as administrator
REM ============================================================

echo.
echo === Cek status MariaDB service ===
sc.exe query MariaDB
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo Service MariaDB tidak ditemukan, atau script belum dijalankan as Administrator.
    echo Silakan klik kanan file ini dan pilih "Run as administrator".
    pause
    exit /b 1
)

echo.
echo === Stop MariaDB service ===
sc.exe stop MariaDB
echo.

echo === Disable autostart MariaDB ===
sc.exe config MariaDB start= disabled
if %ERRORLEVEL% EQU 0 (
    echo MariaDB service sudah di-disable. Tidak akan auto-start lagi.
) else (
    echo Gagal disable MariaDB service. Pastikan dijalankan as Administrator.
)

echo.
echo === Verifikasi port 3306 ===
timeout /t 2 /nobreak >nul
netstat -ano | findstr :3306
echo.
echo Sekarang seharusnya hanya 1 listener di port 3306 (Laragon MySQL 8.4).
echo Test koneksi dengan: cd backend ^&^& php spark db:table --show
echo.
pause