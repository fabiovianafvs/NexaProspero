[CmdletBinding()]
param(
    [ValidateSet('Usuario','Sistema')]
    [string]$Modo = 'Usuario',
    [switch]$Silent
)

$ErrorActionPreference = 'Stop'
$ScriptFile = $MyInvocation.MyCommand.Path
$ScriptRoot = if ([string]::IsNullOrWhiteSpace($ScriptFile)) { (Get-Location).Path } else { Split-Path -Parent $ScriptFile }
Add-Type -AssemblyName System.Windows.Forms
$taskName = 'Nexa Prospero - Agente de Impressao'
$runValueName = 'NexaProsperoAgenteImpressao'
$runKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run'
$configPath = Join-Path $ScriptRoot 'config.json'
$agentPath = Join-Path $ScriptRoot 'AgenteImpressao.ps1'
$launcherPath = Join-Path $ScriptRoot 'ExecutarAgente.ps1'
$vbsPath = Join-Path $ScriptRoot 'ExecutarOculto.vbs'
$pidPath = Join-Path $ScriptRoot 'agente.pid'
$statusPath = Join-Path $ScriptRoot 'status_agente.json'
$fatalLogPath = Join-Path $ScriptRoot 'logs\inicio_fatal.log'
$normalLogPath = Join-Path $ScriptRoot 'logs\agente.log'

if (-not (Test-Path $configPath)) { throw 'Execute Configurar_Agente.bat e salve a configuracao antes de instalar.' }
if (-not (Test-Path $agentPath)) { throw 'Arquivo AgenteImpressao.ps1 nao encontrado.' }
if (-not (Test-Path $launcherPath)) { throw 'Arquivo ExecutarAgente.ps1 nao encontrado.' }
if (-not (Test-Path $vbsPath)) { throw 'Arquivo ExecutarOculto.vbs nao encontrado.' }

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principalCheck = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principalCheck.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Stop-ProcessById([int]$ProcessId) {
    if ($ProcessId -le 0 -or $ProcessId -eq $PID) { return }
    try { Stop-Process -Id $ProcessId -Force -ErrorAction Stop } catch {}
}

function Stop-CurrentAgentProcesses {
    # Primeiro usa o PID gravado pelo proprio agente, evitando depender do WMI.
    if (Test-Path $pidPath) {
        try {
            $savedPid = 0
            [void][int]::TryParse((Get-Content -Path $pidPath -Raw -ErrorAction SilentlyContinue).Trim(), [ref]$savedPid)
            Stop-ProcessById $savedPid
        } catch {}
    }

    # Compatibilidade com versoes anteriores que ainda nao gravavam PID.
    try {
        Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
            Where-Object {
                ($_.Name -ieq 'powershell.exe' -or $_.Name -ieq 'pwsh.exe') -and
                $_.ProcessId -ne $PID -and $_.CommandLine -and
                ($_.CommandLine -like '*AgenteImpressao.ps1*' -or $_.CommandLine -like '*ExecutarAgente.ps1*')
            } |
            ForEach-Object { Stop-ProcessById ([int]$_.ProcessId) }
    } catch {}

    Start-Sleep -Milliseconds 700
    try { Remove-Item -Path $pidPath -Force -ErrorAction SilentlyContinue } catch {}
    try { Remove-Item -Path (Join-Path $ScriptRoot 'agente.lock') -Force -ErrorAction SilentlyContinue } catch {}
}

function Get-LastUsefulError {
    if (Test-Path $statusPath) {
        try {
            $status = Get-Content -Path $statusPath -Raw -Encoding UTF8 | ConvertFrom-Json
            if ($status.error) { return [string]$status.error }
        } catch {}
    }
    if (Test-Path $fatalLogPath) {
        try {
            $lines = @(Get-Content -Path $fatalLogPath -Tail 12 -ErrorAction SilentlyContinue)
            if ($lines.Count -gt 0) { return ($lines -join ' ') }
        } catch {}
    }
    if (Test-Path $normalLogPath) {
        try {
            $lines = @(Get-Content -Path $normalLogPath -Tail 8 -ErrorAction SilentlyContinue)
            if ($lines.Count -gt 0) { return ($lines -join ' ') }
        } catch {}
    }
    return 'Consulte Diagnosticar_Agente.bat e a pasta logs.'
}

function Start-UserAgent {
    $runCommand = 'wscript.exe "' + $vbsPath + '"'
    if (-not (Test-Path $runKey)) { New-Item -Path $runKey -Force | Out-Null }
    New-ItemProperty -Path $runKey -Name $runValueName -Value $runCommand -PropertyType String -Force | Out-Null

    try { Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue } catch {}
    Stop-CurrentAgentProcesses
    try { Remove-Item -Path $statusPath -Force -ErrorAction SilentlyContinue } catch {}

    $arguments = @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-WindowStyle', 'Hidden',
        '-File', ('"' + $launcherPath + '"'),
        '-ConfigPath', ('"' + $configPath + '"')
    ) -join ' '

    $process = Start-Process -FilePath 'powershell.exe' -ArgumentList $arguments -WindowStyle Hidden -PassThru
    $deadline = (Get-Date).AddSeconds(15)
    $state = ''
    do {
        Start-Sleep -Milliseconds 500
        try { $process.Refresh() } catch {}
        if ($process.HasExited) {
            $reason = Get-LastUsefulError
            throw ('O agente encerrou durante a inicializacao. Motivo: ' + $reason)
        }
        if (Test-Path $statusPath) {
            try {
                $status = Get-Content -Path $statusPath -Raw -Encoding UTF8 | ConvertFrom-Json
                $state = [string]$status.state
                if ($state -eq 'ERRO_FATAL') {
                    throw ('Falha fatal ao iniciar o agente: ' + [string]$status.error)
                }
                if ($state -eq 'ONLINE' -or $state -eq 'ERRO' -or $state -eq 'INICIANDO') { break }
            } catch {
                if ($_.Exception.Message -like 'Falha fatal*') { throw }
            }
        }
    } while ((Get-Date) -lt $deadline)

    try { $process.Refresh() } catch {}
    if ($process.HasExited) {
        $reason = Get-LastUsefulError
        throw ('O agente nao permaneceu em execucao. Motivo: ' + $reason)
    }

    return 'Inicializacao automatica do usuario (Registro HKCU)'
}

function Start-SystemAgent {
    if (-not (Test-IsAdministrator)) {
        $args = '-NoProfile -ExecutionPolicy Bypass -File "' + $PSCommandPath + '" -Modo Sistema' + $(if ($Silent) { ' -Silent' } else { '' })
        Start-Process powershell.exe -Verb RunAs -ArgumentList $args
        return 'Solicitada permissao de administrador'
    }

    Import-Module ScheduledTasks
    $argument = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $launcherPath + '" -ConfigPath "' + $configPath + '"'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $argument -WorkingDirectory $ScriptRoot
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -RestartCount 20 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit (New-TimeSpan -Days 3650)
    $task = New-ScheduledTask -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Agente automatico de impressao Nexa/Prospero.'
    Register-ScheduledTask -TaskName $taskName -InputObject $task -Force | Out-Null
    try { Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue } catch {}
    Start-Sleep -Milliseconds 500
    Start-ScheduledTask -TaskName $taskName
    Start-Sleep -Seconds 3
    return 'Tarefa do sistema'
}

$method = if ($Modo -eq 'Sistema') { Start-SystemAgent } else { Start-UserAgent }
$message = "Agente instalado e iniciado com sucesso.`r`nModo: $Modo`r`nInicializacao: $method"
if (-not $Silent) { [System.Windows.Forms.MessageBox]::Show($message,'Agente de Impressao') | Out-Null }
Write-Output $message
