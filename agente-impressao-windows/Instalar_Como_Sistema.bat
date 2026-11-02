@echo off
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0InstalarAgente.ps1" -Modo Sistema
if errorlevel 1 pause
