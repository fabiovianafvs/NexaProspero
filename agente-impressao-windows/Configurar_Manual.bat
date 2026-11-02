@echo off
setlocal
cd /d "%~dp0"
if not exist "config.json" (
    copy /Y "config.example.json" "config.json" >nul
)
start "" notepad.exe "%~dp0config.json"
endlocal
