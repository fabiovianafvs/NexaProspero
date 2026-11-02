[CmdletBinding()]
param(
    [string]$ConfigPath
)

$ErrorActionPreference = 'Stop'
$ScriptFile = $MyInvocation.MyCommand.Path
$ScriptRoot = if ([string]::IsNullOrWhiteSpace($ScriptFile)) { (Get-Location).Path } else { Split-Path -Parent $ScriptFile }
if ([string]::IsNullOrWhiteSpace($ConfigPath)) { $ConfigPath = Join-Path $ScriptRoot 'config.json' }
$AgentPath = Join-Path $ScriptRoot 'AgenteImpressao.ps1'
$PidPath = Join-Path $ScriptRoot 'agente.pid'
$FatalLogPath = Join-Path $ScriptRoot 'logs\inicio_fatal.log'
$StatusPath = Join-Path $ScriptRoot 'status_agente.json'

function Ensure-LogDirectory {
    $dir = Split-Path -Parent $FatalLogPath
    if (-not (Test-Path $dir)) { New-Item -Path $dir -ItemType Directory -Force | Out-Null }
}

function Write-FatalStatus([string]$Message) {
    try {
        $payload = [ordered]@{
            state = 'ERRO_FATAL'
            version = '1.10.1'
            error = $Message
            updated_at = (Get-Date).ToString('o')
            process_id = $PID
            config_path = $ConfigPath
        }
        $payload | ConvertTo-Json -Depth 5 | Set-Content -Path $StatusPath -Encoding UTF8
    } catch {}
}

try {
    Ensure-LogDirectory
    # Remove somente o erro fatal antigo. Se esta inicializacao falhar, um novo
    # diagnostico sera gravado abaixo.
    Remove-Item -Path $FatalLogPath -Force -ErrorAction SilentlyContinue
    Set-Content -Path $PidPath -Value ([string]$PID) -Encoding ASCII
    if (-not (Test-Path $AgentPath)) { throw "Arquivo principal nao encontrado: $AgentPath" }

    # Invoca o agente dentro deste processo. Erros fatais de leitura, Add-Type
    # ou sintaxe do arquivo chamado sao capturados e gravados abaixo.
    & $AgentPath -ConfigPath $ConfigPath
} catch {
    Ensure-LogDirectory
    $message = $_.Exception.Message
    $detail = $_ | Out-String
    $entry = @(
        ('=' * 72),
        ((Get-Date).ToString('yyyy-MM-dd HH:mm:ss') + ' - FALHA FATAL AO INICIAR O AGENTE'),
        ('Mensagem: ' + $message),
        $detail
    ) -join [Environment]::NewLine
    Add-Content -Path $FatalLogPath -Value $entry -Encoding UTF8
    Write-FatalStatus $message
    exit 1
} finally {
    try {
        if (Test-Path $PidPath) {
            $savedPid = (Get-Content -Path $PidPath -Raw -ErrorAction SilentlyContinue).Trim()
            if ($savedPid -eq [string]$PID) { Remove-Item -Path $PidPath -Force -ErrorAction SilentlyContinue }
        }
    } catch {}
}
