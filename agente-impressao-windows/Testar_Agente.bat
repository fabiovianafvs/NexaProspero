@echo off
setlocal
cd /d "%~dp0"
echo O agente sera executado em primeiro plano para mostrar qualquer erro.
echo Feche esta janela para parar o teste.
echo.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0ExecutarAgente.ps1" -ConfigPath "%~dp0config.json"
echo.
echo O agente foi encerrado. Consulte logs\inicio_fatal.log e logs\agente.log.
pause
endlocal
