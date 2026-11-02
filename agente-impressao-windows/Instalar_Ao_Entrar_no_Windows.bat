@echo off
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0InstalarAgente.ps1" -Modo Usuario
if errorlevel 1 pause
