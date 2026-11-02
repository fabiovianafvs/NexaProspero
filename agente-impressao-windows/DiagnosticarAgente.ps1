[CmdletBinding()]
param([string]$ConfigPath)

$ErrorActionPreference = 'Continue'
try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12 } catch {}
$ScriptFile = $MyInvocation.MyCommand.Path
$ScriptRoot = if ([string]::IsNullOrWhiteSpace($ScriptFile)) { (Get-Location).Path } else { Split-Path -Parent $ScriptFile }
if ([string]::IsNullOrWhiteSpace($ConfigPath)) { $ConfigPath = Join-Path $ScriptRoot 'config.json' }
$taskName = 'Nexa Prospero - Agente de Impressao'
$runValueName = 'NexaProsperoAgenteImpressao'
$runKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run'
$pidPath = Join-Path $ScriptRoot 'agente.pid'
$statusPath = Join-Path $ScriptRoot 'status_agente.json'
$logPath = Join-Path $ScriptRoot 'logs\agente.log'
$fatalLogPath = Join-Path $ScriptRoot 'logs\inicio_fatal.log'
$errors = New-Object System.Collections.Generic.List[string]

function Line([string]$Text='') { Write-Host $Text }
function Ok([string]$Text) { Write-Host ('[OK] ' + $Text) -ForegroundColor Green }
function Warn([string]$Text) { Write-Host ('[AVISO] ' + $Text) -ForegroundColor Yellow }
function Fail([string]$Text) { Write-Host ('[ERRO] ' + $Text) -ForegroundColor Red; $script:errors.Add($Text) }
function Normalize-Url([string]$Url) {
    $value = ([string]$Url).Trim()
    if (-not $value) { return '' }
    try {
        $uri = [Uri]$value
        if ($uri.Scheme -eq 'http' -and ($uri.Host -eq 'nexaprospero.com.br' -or $uri.Host.EndsWith('.nexaprospero.com.br'))) {
            $b = New-Object System.UriBuilder -ArgumentList $uri
            $b.Scheme = 'https'
            if ($b.Port -eq 80) { $b.Port = -1 }
            return $b.Uri.AbsoluteUri.TrimEnd('/')
        }
    } catch {}
    return $value
}
function Test-ScriptSyntax([string]$Path, [string]$Label) {
    if (-not (Test-Path $Path)) { Fail ("Arquivo ausente: $Label - $Path"); return }
    try {
        $tokens = $null
        $parseErrors = $null
        [void][System.Management.Automation.Language.Parser]::ParseFile($Path, [ref]$tokens, [ref]$parseErrors)
        if (@($parseErrors).Count -gt 0) {
            foreach ($parseError in @($parseErrors)) { Fail ("Erro de sintaxe em ${Label}: $($parseError.Message) Linha $($parseError.Extent.StartLineNumber)") }
        } else { Ok ("Sintaxe valida: $Label") }
    } catch { Fail ("Nao foi possivel validar ${Label}: $($_.Exception.Message)") }
}
function Test-ProcessId([int]$ProcessId) {
    if ($ProcessId -le 0) { return $false }
    try { return $null -ne (Get-Process -Id $ProcessId -ErrorAction Stop) } catch { return $false }
}

Line 'DIAGNOSTICO DO AGENTE DE IMPRESSAO NEXA/PROSPERO V1.10'
Line '========================================================'
Line ('Pasta: ' + $ScriptRoot)
Line ('Configuracao: ' + $ConfigPath)
Line

Test-ScriptSyntax (Join-Path $ScriptRoot 'ExecutarAgente.ps1') 'inicializador'
Test-ScriptSyntax (Join-Path $ScriptRoot 'AgenteImpressao.ps1') 'agente principal'
Test-ScriptSyntax (Join-Path $ScriptRoot 'InstalarAgente.ps1') 'ativador'

$cfg = $null
if (-not (Test-Path $ConfigPath)) {
    Fail 'config.json nao encontrado. Execute Configurar_Agente.bat.'
} else {
    try {
        $cfg = Get-Content -Path $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
        Ok 'config.json lido corretamente.'
    } catch { Fail ('config.json invalido: ' + $_.Exception.Message) }
}

if ($cfg) {
    $cfg.ServerUrl = Normalize-Url ([string]$cfg.ServerUrl)
    if (-not $cfg.ServerUrl) { Fail 'Endereco da API nao informado.' } else { Ok ('API: ' + $cfg.ServerUrl) }
    if (-not $cfg.Token -or -not ([string]$cfg.Token).StartsWith('nexa_')) { Fail 'Chave do agente ausente ou invalida.' }
    else { Ok ('Chave carregada: ' + ([string]$cfg.Token).Substring(0,[Math]::Min(10,([string]$cfg.Token).Length)) + '...') }
    $mappings = @($cfg.Mappings | Where-Object { $_.Group -and $_.Destination })
    if ($mappings.Count -eq 0) { Fail 'Nenhum setor/impressora foi configurado.' }
    else {
        Ok ("$($mappings.Count) setor(es) configurado(s).")
        $installed = @()
        try { $installed = @(Get-CimInstance Win32_Printer | Select-Object -ExpandProperty Name) } catch { Warn ('Nao foi possivel listar impressoras: ' + $_.Exception.Message) }
        foreach ($m in $mappings) {
            $group = [string]$m.Group
            $mode = ([string]$m.Mode).ToUpperInvariant()
            $dest = [string]$m.Destination
            if ($mode -eq 'WINDOWS') {
                $found = @($installed | Where-Object { [string]::Equals([string]$_,$dest,[StringComparison]::OrdinalIgnoreCase) }).Count -gt 0
                if ($found) { Ok ("$group -> WINDOWS -> $dest") } else { Fail ("${group}: impressora '$dest' nao encontrada no Windows deste usuario.") }
            } elseif ($mode -eq 'TCP') {
                Ok ("$group -> TCP -> $dest")
            } else { Fail ("${group}: tipo '$mode' invalido.") }
        }
    }
}

Line
$startupFound = $false
try {
    $runValue = (Get-ItemProperty -Path $runKey -Name $runValueName -ErrorAction Stop).$runValueName
    if ($runValue) {
        $startupFound = $true
        Ok ('Inicializacao automatica do usuario configurada: ' + [string]$runValue)
    }
} catch {}

$taskFound = $false
try {
    Import-Module ScheduledTasks -ErrorAction Stop
    $task = Get-ScheduledTask -TaskName $taskName -ErrorAction Stop
    $taskFound = $true
    $info = Get-ScheduledTaskInfo -TaskName $taskName -ErrorAction SilentlyContinue
    Ok ("Tarefa encontrada. Estado: $($task.State). Usuario: $($task.Principal.UserId)")
    if ($info) { Line ("Ultima execucao: $($info.LastRunTime) | Resultado: $($info.LastTaskResult)") }
} catch {}
if (-not $startupFound -and -not $taskFound) { Fail 'Inicializacao automatica nao encontrada. Execute Ativar_Agente.bat.' }

$running = $false
if (Test-Path $pidPath) {
    try {
        $savedPid = 0
        [void][int]::TryParse((Get-Content -Path $pidPath -Raw -ErrorAction SilentlyContinue).Trim(), [ref]$savedPid)
        if (Test-ProcessId $savedPid) {
            $running = $true
            Ok ("Agente em execucao. PID: $savedPid")
        } else { Warn ("O arquivo agente.pid aponta para um processo encerrado: $savedPid") }
    } catch {}
}
if (-not $running) {
    try {
        $runningProcesses = @(
            Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
                Where-Object {
                    ($_.Name -ieq 'powershell.exe' -or $_.Name -ieq 'pwsh.exe') -and
                    $_.CommandLine -and
                    ($_.CommandLine -like '*AgenteImpressao.ps1*' -or $_.CommandLine -like '*ExecutarAgente.ps1*')
                }
        )
        if ($runningProcesses.Count -gt 0) {
            $running = $true
            Ok ('Agente em execucao. PID(s): ' + (($runningProcesses | ForEach-Object { [string]$_.ProcessId }) -join ', '))
        }
    } catch {}
}
if (-not $running) { Fail 'O processo do agente nao esta em execucao. Execute Ativar_Agente.bat.' }

if ($cfg -and $cfg.ServerUrl -and $cfg.Token) {
    Line
    try {
        $sep = if ([string]$cfg.ServerUrl -match '\?') {'&'} else {'?'}
        $headers = @{ Authorization='Bearer '+[string]$cfg.Token; 'X-Agent-Token'=[string]$cfg.Token; 'X-Agent-Machine'=[string]$cfg.StationName; 'X-Agent-Version'='1.10.1'; Accept='application/json' }
        $ping = Invoke-RestMethod -Method Get -Uri ([string]$cfg.ServerUrl+$sep+'action=ping') -Headers $headers -TimeoutSec 20
        if ($ping.ok) {
            Ok ("Servidor autenticou o agente '$($ping.agent.name)' da empresa '$($ping.company.name)'.")
            Ok ('Setores autorizados no servidor: ' + (@($ping.agent.groups) -join ', '))
        } else { Fail ([string]$ping.error) }
    } catch {
        $code = 0
        try { $code = [int]$_.Exception.Response.StatusCode } catch {}
        if ($code -eq 401 -or $code -eq 403) { Fail 'A chave foi recusada. Gere uma nova chave no sistema e cole a chave completa no configurador.' }
        else { Fail ('Falha ao acessar a API: ' + $_.Exception.Message) }
    }
}

if (Test-Path $statusPath) {
    Line
    try {
        $status = Get-Content $statusPath -Raw -Encoding UTF8 | ConvertFrom-Json
        Line ('Status local: ' + [string]$status.state + ' | Atualizado: ' + [string]$status.updated_at)
        if ($status.error) { Warn ('Ultimo erro: ' + [string]$status.error) }
    } catch { Warn ('Nao foi possivel ler status_agente.json: ' + $_.Exception.Message) }
}

if (Test-Path $fatalLogPath) {
    Line
    Line 'ULTIMA FALHA FATAL DE INICIALIZACAO:'
    Get-Content $fatalLogPath -Tail 20 | ForEach-Object { Line $_ }
}
if (Test-Path $logPath) {
    Line
    Line 'ULTIMAS LINHAS DO LOG NORMAL:'
    Get-Content $logPath -Tail 20 | ForEach-Object { Line $_ }
}

Line
if ($errors.Count -eq 0) {
    Ok 'Diagnostico concluido sem erros. O agente deve ficar ONLINE em poucos segundos.'
    exit 0
}
Write-Host ("[ERRO] Diagnostico encontrou $($errors.Count) problema(s).") -ForegroundColor Red
exit 1
