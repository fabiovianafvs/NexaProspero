@echo off
setlocal
cd /d "%~dp0"
echo Ativando o Agente de Impressao para o usuario atual...
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0InstalarAgente.ps1" -Modo Usuario -Silent
if errorlevel 1 (
  echo.
  echo Nao foi possivel ativar o agente.
  echo O motivo detalhado foi gravado em logs\inicio_fatal.log e status_agente.json.
  echo Execute Diagnosticar_Agente.bat para ver o motivo.
  pause
  exit /b 1
)
timeout /t 2 /nobreak >nul
call "%~dp0Diagnosticar_Agente.bat"
endlocal
