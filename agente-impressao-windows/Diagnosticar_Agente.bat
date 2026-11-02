@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0DiagnosticarAgente.ps1" -ConfigPath "%~dp0config.json"
echo.
echo Pressione uma tecla para fechar.
pause >nul
endlocal
