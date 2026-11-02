@echo off
setlocal
cd /d "%~dp0"
set "LOG=%~dp0configurador_erro.log"
if exist "%LOG%" del /q "%LOG%" >nul 2>&1

powershell.exe -NoProfile -STA -ExecutionPolicy Bypass -File "%~dp0ConfigurarAgente.ps1" -ConfigPath "%~dp0config.json" 1>"%LOG%" 2>&1
set "RC=%ERRORLEVEL%"

if not "%RC%"=="0" (
    echo.
    echo Nao foi possivel abrir o configurador do Agente de Impressao.
    echo O erro foi gravado em:
    echo %LOG%
    echo.
    type "%LOG%"
    echo.
    echo Pressione uma tecla para fechar.
    pause >nul
) else (
    if exist "%LOG%" del /q "%LOG%" >nul 2>&1
)
endlocal
