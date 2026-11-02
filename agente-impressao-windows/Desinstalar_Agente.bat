@echo off
setlocal
cd /d "%~dp0"
call "%~dp0Parar_Agente.bat" >nul
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$k='HKCU:\Software\Microsoft\Windows\CurrentVersion\Run'; Remove-ItemProperty -Path $k -Name 'NexaProsperoAgenteImpressao' -ErrorAction SilentlyContinue; Remove-Item '%~dp0agente.pid','%~dp0agente.lock' -Force -ErrorAction SilentlyContinue"
schtasks /End /TN "Nexa Prospero - Agente de Impressao" >nul 2>&1
schtasks /Delete /TN "Nexa Prospero - Agente de Impressao" /F >nul 2>&1
echo Agente removido da inicializacao do usuario.
echo Se uma tarefa do sistema tiver sido instalada anteriormente, execute este arquivo como Administrador para remove-la.
pause
endlocal
