@echo off
REM ===================================================================
REM switch-env.cmd — Wrapper for switch-env.ps1 (cmd.exe convenience)
REM
REM Usage:
REM   scripts\switch-env.cmd local
REM   scripts\switch-env.cmd staging
REM   scripts\switch-env.cmd production
REM   scripts\switch-env.cmd status
REM ===================================================================

setlocal
set "SCRIPT_DIR=%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%switch-env.ps1" %*
exit /b %ERRORLEVEL%