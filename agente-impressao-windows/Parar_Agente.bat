@echo off
setlocal
cd /d "%~dp0"
set "TASK=Nexa Prospero - Agente de Impressao"
schtasks /End /TN "%TASK%" >nul 2>&1
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$pidFile='%~dp0agente.pid'; if(Test-Path $pidFile){$p=0; [void][int]::TryParse((Get-Content $pidFile -Raw).Trim(),[ref]$p); if($p -gt 0){Stop-Process -Id $p -Force -ErrorAction SilentlyContinue}}; Get-CimInstance Win32_Process -ErrorAction SilentlyContinue ^| Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -and ($_.CommandLine -like '*AgenteImpressao.ps1*' -or $_.CommandLine -like '*ExecutarAgente.ps1*') } ^| ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }; Remove-Item '%~dp0agente.pid','%~dp0agente.lock' -Force -ErrorAction SilentlyContinue" >nul 2>&1
echo Solicitacao de parada enviada.
timeout /t 2 /nobreak >nul
endlocal
