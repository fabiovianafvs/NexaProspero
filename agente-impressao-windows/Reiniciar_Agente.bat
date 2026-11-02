@echo off
setlocal
cd /d "%~dp0"
echo Reiniciando o Agente de Impressao...
call "%~dp0Parar_Agente.bat" >nul
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0InstalarAgente.ps1" -Modo Usuario -Silent
if errorlevel 1 goto :erro
timeout /t 2 /nobreak >nul
echo.
echo Agente iniciado no modo do usuario.
call "%~dp0Diagnosticar_Agente.bat"
exit /b 0
:erro
echo.
echo Nao foi possivel iniciar o agente.
echo O motivo detalhado foi gravado em logs\inicio_fatal.log e status_agente.json.
echo Execute Diagnosticar_Agente.bat.
pause
exit /b 1
