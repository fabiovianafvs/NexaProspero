# Nexa/Prospero - Agente de Impressao Automatica
# Compatível com Windows PowerShell 5.1+
[CmdletBinding()]
param(
    [string]$ConfigPath,
    [switch]$Once
)

# Resolve a pasta depois do bloco param. Em algumas versoes do Windows
# PowerShell, $PSScriptRoot ainda esta vazio durante a avaliacao do valor
# padrao de um parametro.
$ScriptFile = $MyInvocation.MyCommand.Path
$ScriptRoot = if ([string]::IsNullOrWhiteSpace($ScriptFile)) {
    (Get-Location).Path
} else {
    Split-Path -Parent $ScriptFile
}
if ([string]::IsNullOrWhiteSpace($ConfigPath)) {
    $ConfigPath = Join-Path $ScriptRoot 'config.json'
}

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12 } catch {}
$AgentVersion = '1.10.1'
$LogPath = Join-Path $ScriptRoot 'logs\agente.log'
$PendingAckPath = Join-Path $ScriptRoot 'confirmacoes_pendentes.json'
$StatusPath = Join-Path $ScriptRoot 'status_agente.json'
$LockPath = Join-Path $ScriptRoot 'agente.lock'
$script:CurrentJobId = 0
$script:LastGroupsSignature = ''
$script:LastGroupsSyncAt = [datetime]::MinValue

function Initialize-Log {
    $dir = Split-Path -Parent $LogPath
    if (-not (Test-Path $dir)) { New-Item -Path $dir -ItemType Directory -Force | Out-Null }
    if (Test-Path $LogPath) {
        $item = Get-Item $LogPath -ErrorAction SilentlyContinue
        if ($item -and $item.Length -gt 5MB) {
            $old = Join-Path $dir 'agente-anterior.log'
            Move-Item $LogPath $old -Force
        }
    }
}

function Write-Log([string]$Message, [string]$Level = 'INFO') {
    Initialize-Log
    $line = '{0:yyyy-MM-dd HH:mm:ss} [{1}] {2}' -f (Get-Date), $Level.ToUpperInvariant(), $Message
    Add-Content -Path $LogPath -Value $line -Encoding UTF8
    if ($Host.Name -notlike '*ServerRemoteHost*') { Write-Host $line }
}


function Write-AgentStatus([string]$State, [string]$ErrorMessage = '', $Config = $null, [int]$JobsProcessed = 0) {
    try {
        $groups = @()
        $station = $env:COMPUTERNAME
        $server = ''
        if ($null -ne $Config) {
            $station = [string](Get-ObjectValue $Config 'StationName' $station)
            $server = [string](Get-ObjectValue $Config 'ServerUrl' '')
            $mappings = @(Get-ObjectValue $Config 'Mappings' @())
            $groups = @($mappings | ForEach-Object { ([string](Get-ObjectValue $_ 'Group' '')).Trim() } | Where-Object { $_ } | Select-Object -Unique)
        }
        $payload = [ordered]@{
            state = $State
            version = $AgentVersion
            station = $station
            server_url = $server
            groups = $groups
            jobs_processed = $JobsProcessed
            error = $ErrorMessage
            updated_at = (Get-Date).ToString('o')
            process_id = $PID
            config_path = $ConfigPath
        }
        $payload | ConvertTo-Json -Depth 6 | Set-Content -Path $StatusPath -Encoding UTF8
    } catch {}
}

function Get-AgentWebError([System.Management.Automation.ErrorRecord]$ErrorRecord) {
    $statusCode = 0
    $responseText = ''
    try {
        $response = $ErrorRecord.Exception.Response
        if ($null -ne $response) {
            try { $statusCode = [int]$response.StatusCode } catch {}
            try {
                $stream = $response.GetResponseStream()
                if ($null -ne $stream) {
                    $reader = New-Object IO.StreamReader($stream)
                    try { $responseText = $reader.ReadToEnd() } finally { $reader.Dispose() }
                }
            } catch {}
        }
    } catch {}
    if ($statusCode -eq 401 -or $statusCode -eq 403) {
        return 'A chave do agente foi recusada pelo servidor. Gere uma nova chave no sistema, copie a chave completa para o configurador, salve e ative novamente o agente.'
    }
    if ($responseText) {
        try {
            $json = $responseText | ConvertFrom-Json
            if ($json.error) { return [string]$json.error }
        } catch {}
    }
    $message = [string]$ErrorRecord.Exception.Message
    if ([string]::IsNullOrWhiteSpace($message)) { $message = 'Falha na comunicacao com o servidor.' }
    return $message
}

function Invoke-AgentRest {
    param(
        [Parameter(Mandatory=$true)][string]$Method,
        [Parameter(Mandatory=$true)][string]$Uri,
        [Parameter(Mandatory=$true)]$Config,
        $Body = $null,
        [string]$ContentType = ''
    )
    try {
        $params = @{
            Method = $Method
            Uri = $Uri
            Headers = (Get-AgentHeaders $Config)
            TimeoutSec = [int]$Config.RequestTimeoutSeconds
        }
        if ($null -ne $Body) { $params.Body = $Body }
        if ($ContentType) { $params.ContentType = $ContentType }
        return Invoke-RestMethod @params
    } catch {
        throw (Get-AgentWebError $_)
    }
}


function Normalize-ServerUrl([string]$Url) {
    $value = ([string]$Url).Trim()
    if (-not $value) { return '' }
    try {
        $uri = [Uri]$value
        $hostName = $uri.Host.ToLowerInvariant()
        if ($uri.Scheme -eq 'http' -and ($hostName -eq 'nexaprospero.com.br' -or $hostName.EndsWith('.nexaprospero.com.br'))) {
            $builder = New-Object System.UriBuilder -ArgumentList $uri
            $builder.Scheme = 'https'
            if ($builder.Port -eq 80) { $builder.Port = -1 }
            return $builder.Uri.AbsoluteUri.TrimEnd('/')
        }
    } catch {}
    return $value
}

function Read-AgentConfig {
    if (-not (Test-Path $ConfigPath)) { throw "Arquivo de configuracao nao encontrado: $ConfigPath" }
    $cfg = Get-Content -Path $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
    if (-not $cfg.ServerUrl -or -not $cfg.Token) { throw 'Informe ServerUrl e Token no configurador.' }
    $normalizedUrl = Normalize-ServerUrl ([string]$cfg.ServerUrl)
    if ($normalizedUrl -ne [string]$cfg.ServerUrl) {
        Write-Log "Endereco da API ajustado automaticamente para HTTPS: $normalizedUrl" 'WARN'
        $cfg.ServerUrl = $normalizedUrl
    }
    if (-not $cfg.StationName) { $cfg | Add-Member -NotePropertyName StationName -NotePropertyValue $env:COMPUTERNAME -Force }
    if (-not $cfg.PollSeconds) { $cfg | Add-Member -NotePropertyName PollSeconds -NotePropertyValue 2 -Force }
    if (-not $cfg.RequestTimeoutSeconds) { $cfg | Add-Member -NotePropertyName RequestTimeoutSeconds -NotePropertyValue 20 -Force }
    if ($null -eq $cfg.Mappings) { $cfg | Add-Member -NotePropertyName Mappings -NotePropertyValue @() -Force }
    return $cfg
}

function Get-AgentHeaders($Config) {
    return @{
        'Authorization'   = 'Bearer ' + [string]$Config.Token
        'X-Agent-Token'  = [string]$Config.Token
        'X-Agent-Machine' = [string]$Config.StationName
        'X-Agent-Version' = $AgentVersion
        'Accept'          = 'application/json'
    }
}

function Get-ApiUri([string]$BaseUrl, [string]$Action, [string]$Groups = '') {
    $sep = if ($BaseUrl.Contains('?')) { '&' } else { '?' }
    $uri = $BaseUrl.Trim() + $sep + 'action=' + [Uri]::EscapeDataString($Action)
    if ($Groups) { $uri += '&groups=' + [Uri]::EscapeDataString($Groups) }
    return $uri
}

function Invoke-AgentGet($Config, [string]$Action, [string]$Groups = '') {
    $uri = Get-ApiUri ([string]$Config.ServerUrl) $Action $Groups
    return Invoke-AgentRest -Method 'Get' -Uri $uri -Config $Config
}

function Invoke-AgentFinish($Config, [int]$JobId, [bool]$Printed, [string]$ErrorMessage = '', [string]$PrinterName = '') {
    $uri = Get-ApiUri ([string]$Config.ServerUrl) 'finish'
    $okValue = if ($Printed) { '1' } else { '0' }
    $body = @{
        job_id = $JobId
        ok = $okValue
        error = $ErrorMessage
        printer = $PrinterName
    }
    $response = Invoke-AgentRest -Method 'Post' -Uri $uri -Config $Config -ContentType 'application/x-www-form-urlencoded; charset=utf-8' -Body $body
    $responseOk = $false
    if ($null -ne $response) {
        $okProperty = $response.PSObject.Properties['ok']
        if ($null -ne $okProperty) { $responseOk = [bool]$okProperty.Value }
    }
    if (-not $responseOk) {
        $message = 'O servidor nao confirmou a conclusao da impressao.'
        if ($null -ne $response) {
            $errorProperty = $response.PSObject.Properties['error']
            if ($null -ne $errorProperty -and -not [string]::IsNullOrWhiteSpace([string]$errorProperty.Value)) {
                $message = [string]$errorProperty.Value
            }
        }
        throw $message
    }
    return $response
}


function Sync-AgentGroups($Config, $Mappings) {
    $groups = @($Mappings | ForEach-Object { ([string]$_.Group).Trim() } | Where-Object { $_ } | Select-Object -Unique)
    if ($groups.Count -eq 0) { return }
    $signature = (($groups | ForEach-Object { $_.ToUpperInvariant() } | Sort-Object) -join '|')
    $expired = ((Get-Date) - $script:LastGroupsSyncAt).TotalSeconds -ge 60
    if ($signature -eq $script:LastGroupsSignature -and -not $expired) { return }
    $uri = Get-ApiUri ([string]$Config.ServerUrl) 'sync_groups'
    $body = @{ groups = ($groups -join ',') }
    $response = Invoke-AgentRest -Method 'Post' -Uri $uri -Config $Config -ContentType 'application/x-www-form-urlencoded; charset=utf-8' -Body $body
    if (-not $response.ok) { throw ([string]$response.error) }
    $script:LastGroupsSignature = $signature
    $script:LastGroupsSyncAt = Get-Date
    Write-Log ('Setores sincronizados com o servidor: ' + ($groups -join ', '))
}

function Read-PendingConfirmations {
    if (-not (Test-Path $PendingAckPath)) { return @() }
    try {
        $raw = Get-Content -Path $PendingAckPath -Raw -Encoding UTF8
        if ([string]::IsNullOrWhiteSpace($raw)) { return @() }
        $data = $raw | ConvertFrom-Json
        return @($data | Where-Object { $_ -and [int]$_.job_id -gt 0 })
    } catch {
        Write-Log ('Nao foi possivel ler as confirmacoes pendentes: ' + $_.Exception.Message) 'ERROR'
        return @()
    }
}

function Save-PendingConfirmations($Entries) {
    $items = @($Entries)
    if ($items.Count -eq 0) {
        if (Test-Path $PendingAckPath) { Remove-Item -Path $PendingAckPath -Force -ErrorAction SilentlyContinue }
        return
    }
    $json = $items | ConvertTo-Json -Depth 5
    Set-Content -Path $PendingAckPath -Value $json -Encoding UTF8
}

function Add-PendingConfirmation($Job, [string]$PrinterName = '') {
    $jobId = [int]$Job.id
    $items = @(Read-PendingConfirmations | Where-Object { [int]$_.job_id -ne $jobId })
    $items += [pscustomobject]@{
        job_id = $jobId
        group = [string]$Job.group
        comanda = [string]$Job.comanda
        printed_at = (Get-Date).ToString('o')
        printer = $PrinterName
    }
    Save-PendingConfirmations $items
}

function Remove-PendingConfirmation([int]$JobId) {
    $items = @(Read-PendingConfirmations | Where-Object { [int]$_.job_id -ne $JobId })
    Save-PendingConfirmations $items
}

function Confirm-PrintedJob($Config, [int]$JobId, [string]$PrinterName = '') {
    $response = Invoke-AgentFinish $Config $JobId $true '' $PrinterName
    $status = [string](Get-ObjectValue (Get-ObjectValue $response 'result' $null) 'status' '')
    if ($status.ToUpperInvariant() -ne 'IMPRESSO') {
        throw "O servidor respondeu sem marcar o trabalho #$JobId como IMPRESSO."
    }
    Remove-PendingConfirmation $JobId
    return $response
}

function Process-PendingConfirmations($Config) {
    $entries = @(Read-PendingConfirmations)
    if ($entries.Count -eq 0) { return $true }
    $allConfirmed = $true
    foreach ($entry in $entries) {
        $jobId = [int]$entry.job_id
        try {
            Confirm-PrintedJob $Config $jobId ([string](Get-ObjectValue $entry 'printer' '')) | Out-Null
            Write-Log "Confirmacao pendente do trabalho #$jobId enviada com sucesso."
        } catch {
            $allConfirmed = $false
            Write-Log ("O trabalho #$jobId ja foi enviado a impressora, mas a confirmacao ainda falhou. Ele nao sera impresso novamente enquanto a confirmacao estiver pendente. Erro: " + $_.Exception.Message) 'WARN'
        }
    }
    return $allConfirmed
}

if (-not ('Nexa.RawPrinter' -as [type])) {
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
namespace Nexa {
  public static class RawPrinter {
    [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Unicode)]
    public class DOC_INFO_1 {
      [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
      [MarshalAs(UnmanagedType.LPWStr)] public string pOutputFile;
      [MarshalAs(UnmanagedType.LPWStr)] public string pDataType;
    }
    [DllImport("winspool.drv", EntryPoint="OpenPrinterW", SetLastError=true, CharSet=CharSet.Unicode)]
    public static extern bool OpenPrinter(string printerName, out IntPtr printer, IntPtr defaults);
    [DllImport("winspool.drv", SetLastError=true)] public static extern bool ClosePrinter(IntPtr printer);
    [DllImport("winspool.drv", EntryPoint="StartDocPrinterW", SetLastError=true, CharSet=CharSet.Unicode)]
    public static extern int StartDocPrinter(IntPtr printer, int level, [In] DOC_INFO_1 docInfo);
    [DllImport("winspool.drv", SetLastError=true)] public static extern bool EndDocPrinter(IntPtr printer);
    [DllImport("winspool.drv", SetLastError=true)] public static extern bool StartPagePrinter(IntPtr printer);
    [DllImport("winspool.drv", SetLastError=true)] public static extern bool EndPagePrinter(IntPtr printer);
    [DllImport("winspool.drv", SetLastError=true)] public static extern bool WritePrinter(IntPtr printer, byte[] bytes, int count, out int written);
    public static void Send(string printerName, byte[] bytes, string documentName) {
      IntPtr handle;
      if (!OpenPrinter(printerName, out handle, IntPtr.Zero)) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error(), "Nao foi possivel abrir a impressora " + printerName);
      try {
        DOC_INFO_1 doc = new DOC_INFO_1(); doc.pDocName = documentName; doc.pDataType = "RAW";
        if (StartDocPrinter(handle, 1, doc) == 0) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error(), "Falha ao iniciar o documento");
        try {
          if (!StartPagePrinter(handle)) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error(), "Falha ao iniciar a pagina");
          try {
            int written;
            if (!WritePrinter(handle, bytes, bytes.Length, out written) || written != bytes.Length) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error(), "Falha ao enviar dados para a impressora");
          } finally { EndPagePrinter(handle); }
        } finally { EndDocPrinter(handle); }
      } finally { ClosePrinter(handle); }
    }
  }
}
'@
}


function Get-ObjectValue($Object, [string]$Name, $DefaultValue = $null) {
    if ($null -eq $Object) { return $DefaultValue }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property -or $null -eq $property.Value) { return $DefaultValue }
    return $property.Value
}

function Normalize-Text([string]$Text) {
    if ($null -eq $Text) { return '' }
    return $Text.Replace([char]0x2013, '-').Replace([char]0x2014, '-').Replace([char]0x2018, "'").Replace([char]0x2019, "'").Replace([char]0x201C, '"').Replace([char]0x201D, '"').Replace([char]0x00A0, ' ')
}

function Wrap-Text([string]$Text, [int]$Width, [string]$Prefix = '') {
    $Text = (Normalize-Text $Text).Trim()
    if ($Width -lt 10) { $Width = 32 }
    $available = [Math]::Max(8, $Width - $Prefix.Length)
    if (-not $Text) { return @($Prefix) }
    $lines = New-Object System.Collections.Generic.List[string]
    foreach ($paragraph in ($Text -split "`r?`n")) {
        $remaining = $paragraph.Trim()
        if (-not $remaining) { $lines.Add($Prefix); continue }
        while ($remaining.Length -gt $available) {
            $cut = $remaining.LastIndexOf(' ', $available)
            if ($cut -lt 1) { $cut = $available }
            $lines.Add($Prefix + $remaining.Substring(0, $cut).Trim())
            $remaining = $remaining.Substring($cut).Trim()
        }
        $lines.Add($Prefix + $remaining)
    }
    return $lines.ToArray()
}

function Center-Text([string]$Text, [int]$Width) {
    $Text = (Normalize-Text $Text).Trim()
    if ($Text.Length -ge $Width) { return $Text.Substring(0, $Width) }
    return (' ' * [Math]::Floor(($Width - $Text.Length) / 2)) + $Text
}

function Format-Qty($Value) {
    $n = [double]$Value
    if ([Math]::Abs($n - [Math]::Round($n)) -lt 0.0001) { return ([int][Math]::Round($n)).ToString() }
    return $n.ToString('0.###', [Globalization.CultureInfo]::GetCultureInfo('pt-BR'))
}

function Get-Encoding([int]$CodePage) {
    try { return [Text.Encoding]::GetEncoding($CodePage) }
    catch { return [Text.Encoding]::GetEncoding(850) }
}

function Add-ByteArray([IO.MemoryStream]$Stream, [byte[]]$Bytes) { $Stream.Write($Bytes, 0, $Bytes.Length) }
function Add-EscText([IO.MemoryStream]$Stream, [Text.Encoding]$Encoding, [string]$Text) { Add-ByteArray $Stream ($Encoding.GetBytes((Normalize-Text $Text) + "`n")) }

function Build-EscPosTicket($Job, $Mapping) {
    $columns = [int](Get-ObjectValue $Mapping 'Columns' 48)
    if ($columns -lt 24 -or $columns -gt 64) { $columns = 48 }
    $cp = [int](Get-ObjectValue $Mapping 'Encoding' 850)
    $table = [int](Get-ObjectValue $Mapping 'EscPosTable' 2)
    $encoding = Get-Encoding $cp
    $stream = New-Object IO.MemoryStream
    try {
        Add-ByteArray $stream ([byte[]](0x1B,0x40))                 # Inicializa
        Add-ByteArray $stream ([byte[]](0x1B,0x74,[byte]$table))   # Tabela de caracteres
        Add-ByteArray $stream ([byte[]](0x1B,0x61,0x01))           # Centralizado
        Add-ByteArray $stream ([byte[]](0x1B,0x45,0x01))           # Negrito
        $groupTitle = ([string]$Job.group).ToUpperInvariant()
        Add-EscText $stream $encoding (Center-Text -Text $groupTitle -Width $columns)
        Add-ByteArray $stream ([byte[]](0x1D,0x21,0x11))           # Duplo tamanho
        # A impressora já está em alinhamento central. Não adiciona espaços antes do título em modo duplo,
        # pois cada espaço ocupa duas colunas e poderia empurrar o nome da comanda para a linha seguinte.
        Add-EscText $stream $encoding ('COMANDA ' + [string]$Job.comanda)
        Add-ByteArray $stream ([byte[]](0x1D,0x21,0x00))
        Add-ByteArray $stream ([byte[]](0x1B,0x45,0x00))
        if ($Job.company_name) { Add-EscText $stream $encoding (Center-Text -Text ([string]$Job.company_name) -Width $columns) }
        Add-ByteArray $stream ([byte[]](0x1B,0x61,0x00))           # Esquerda
        Add-EscText $stream $encoding ('-' * $columns)
        if ($Job.customer_name) { foreach ($line in (Wrap-Text -Text ('CLIENTE: ' + [string]$Job.customer_name) -Width $columns)) { Add-EscText $stream $encoding $line } }
        if ($Job.customer_phone) { Add-EscText $stream $encoding ('CELULAR: ' + [string]$Job.customer_phone) }
        $when = Get-Date
        if ($Job.created_at) { try { $when = [datetime]::Parse([string]$Job.created_at) } catch {} }
        Add-EscText $stream $encoding ('PEDIDO: ' + $when.ToString('dd/MM/yyyy HH:mm:ss'))
        Add-EscText $stream $encoding ('SOLICITACAO: #' + [string]$Job.id)
        Add-EscText $stream $encoding ('-' * $columns)
        foreach ($item in @($Job.items)) {
            $qty = Format-Qty $item.qty
            Add-ByteArray $stream ([byte[]](0x1B,0x45,0x01))
            foreach ($line in (Wrap-Text -Text ($qty + 'x ' + ([string]$item.name).ToUpperInvariant()) -Width $columns)) { Add-EscText $stream $encoding $line }
            Add-ByteArray $stream ([byte[]](0x1B,0x45,0x00))
            if ($item.observation) {
                foreach ($line in (Wrap-Text -Text ([string]$item.observation) -Width $columns -Prefix '  > ')) { Add-EscText $stream $encoding $line }
            }
            Add-EscText $stream $encoding ('.' * $columns)
        }
        Add-ByteArray $stream ([byte[]](0x1B,0x61,0x01))
        Add-EscText $stream $encoding 'FIM DO PEDIDO'
        Add-EscText $stream $encoding ''
        Add-EscText $stream $encoding ''
        Add-EscText $stream $encoding ''
        if ([bool](Get-ObjectValue $Mapping 'OpenDrawer' $false)) { Add-ByteArray $stream ([byte[]](0x1B,0x70,0x00,0x19,0xFA)) }
        if ([bool](Get-ObjectValue $Mapping 'Cut' $true)) { Add-ByteArray $stream ([byte[]](0x1D,0x56,0x42,0x00)) }
        return $stream.ToArray()
    } finally { $stream.Dispose() }
}

function Send-ToWindowsPrinter([string]$PrinterName, [byte[]]$Bytes, [string]$DocumentName) {
    if (-not $PrinterName) { throw 'Nome da impressora do Windows nao informado.' }
    [Nexa.RawPrinter]::Send($PrinterName, $Bytes, $DocumentName)
}

function Send-ToTcpPrinter([string]$Destination, [byte[]]$Bytes) {
    if (-not $Destination) { throw 'Endereco TCP da impressora nao informado.' }
    $parts = $Destination.Trim() -split ':'
    $hostName = $parts[0]
    $port = if ($parts.Count -gt 1 -and $parts[1]) { [int]$parts[1] } else { 9100 }
    $client = New-Object Net.Sockets.TcpClient
    try {
        $result = $client.BeginConnect($hostName, $port, $null, $null)
        if (-not $result.AsyncWaitHandle.WaitOne(10000, $false)) { throw "Tempo esgotado ao conectar em $hostName`:$port" }
        $client.EndConnect($result)
        $stream = $client.GetStream()
        try { $stream.Write($Bytes, 0, $Bytes.Length); $stream.Flush() } finally { $stream.Dispose() }
    } finally { $client.Close() }
}

function Find-Mapping($Config, [string]$Group) {
    foreach ($mapping in @($Config.Mappings)) {
        if ([string]::Equals(([string]$mapping.Group).Trim(), $Group.Trim(), [StringComparison]::OrdinalIgnoreCase)) { return $mapping }
    }
    return $null
}

function Process-Job($Config, $Job) {
    $script:CurrentJobId = [int]$Job.id
    $mapping = Find-Mapping $Config ([string]$Job.group)
    if ($null -eq $mapping) { throw "Nenhuma impressora foi mapeada para o setor '$($Job.group)'." }
    $mode = ([string](Get-ObjectValue $mapping 'Mode' 'WINDOWS')).Trim().ToUpperInvariant()
    $bytes = Build-EscPosTicket $Job $mapping
    $docName = 'Comanda ' + [string]$Job.comanda + ' - ' + [string]$Job.group + ' #' + [string]$Job.id
    $destination = [string](Get-ObjectValue $mapping 'Destination' '')
    Write-Log "Imprimindo trabalho #$($Job.id), setor '$($Job.group)', comanda '$($Job.comanda)', destino '$destination' ($mode)."
    if ($mode -eq 'TCP') { Send-ToTcpPrinter $destination $bytes }
    else { Send-ToWindowsPrinter $destination $bytes $docName }

    # A impressao fisica ja foi enviada. Grave a confirmacao local antes de
    # comunicar o servidor para impedir reimpressoes em caso de queda de rede.
    Add-PendingConfirmation $Job $destination
    $script:CurrentJobId = 0

    $result = Confirm-PrintedJob $Config ([int]$Job.id) $destination
    Write-Log "Trabalho #$($Job.id) confirmado como impresso."
    return $result
}

$lockStream = $null
try {
    try {
        $lockStream = [IO.File]::Open($LockPath, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    } catch {
        Write-Log 'Ja existe uma instancia do agente em execucao ou o arquivo de bloqueio esta em uso.' 'WARN'
        Write-AgentStatus 'JA_EXECUTANDO' 'Ja existe outra instancia do agente em execucao.'
        throw 'Ja existe outra instancia do agente em execucao.'
    }
    Write-AgentStatus 'INICIANDO'
    Write-Log "Agente iniciado. Versao $AgentVersion. Configuracao: $ConfigPath"
    while ($true) {
        $cfg = $null
        try {
            $cfg = Read-AgentConfig
            $mappings = @($cfg.Mappings | Where-Object { $_.Group -and $_.Destination })
            if ($mappings.Count -eq 0) { throw 'Nenhum setor/impressora foi configurado.' }
            Write-AgentStatus 'ONLINE' '' $cfg 0
            Sync-AgentGroups $cfg $mappings

            # Reenvia confirmacoes de trabalhos que ja chegaram ao spooler.
            # Uma confirmacao pendente nao deve bloquear os outros setores do
            # mesmo pedido: cada trabalho possui seu proprio ID e reserva.
            Process-PendingConfirmations $cfg | Out-Null

            # Retira os trabalhos em lotes por pedido. O servidor devolve
            # todos os grupos de produção daquele pedido (BOLOS, BEBIDAS,
            # COZINHA etc.) na mesma resposta, e cada trabalho é tratado de
            # forma independente. Uma falha em uma impressora não impede as
            # demais impressões do mesmo pedido.
            $groups = (($mappings | ForEach-Object { ([string]$_.Group).Trim() } | Select-Object -Unique) -join ',')
            $processedThisCycle = 0
            $maxJobsPerCycle = 50
            while ($processedThisCycle -lt $maxJobsPerCycle) {
                $response = Invoke-AgentGet $cfg 'next_batch' $groups
                if (-not $response.ok) { throw ([string]$response.error) }

                $jobs = @()
                $jobsProperty = $response.PSObject.Properties['jobs']
                if ($null -ne $jobsProperty -and $null -ne $jobsProperty.Value) {
                    $jobs = @($jobsProperty.Value)
                } elseif ($null -ne $response.job) {
                    $jobs = @($response.job)
                }
                if ($jobs.Count -eq 0) { break }

                Write-Log ("Lote recebido com {0} trabalho(s): {1}" -f $jobs.Count, (($jobs | ForEach-Object { ([string]$_.group) + ' #' + ([string]$_.id) }) -join ', '))

                foreach ($job in $jobs) {
                    if ($processedThisCycle -ge $maxJobsPerCycle) { break }
                    $jobId = [int]$job.id
                    try {
                        $pendingIds = @(Read-PendingConfirmations | ForEach-Object { [int]$_.job_id })
                        if ($pendingIds -contains $jobId) {
                            # O papel já foi enviado anteriormente. Apenas
                            # confirma no servidor; nunca imprime novamente.
                            Confirm-PrintedJob $cfg $jobId ([string](Get-ObjectValue $job 'printer_hint' '')) | Out-Null
                            Write-Log "Trabalho #$jobId ja estava no spooler e foi apenas confirmado."
                        } else {
                            Process-Job $cfg $job | Out-Null
                        }
                    } catch {
                        $message = $_.Exception.Message
                        $pendingIdsAfter = @(Read-PendingConfirmations | ForEach-Object { [int]$_.job_id })
                        if ($pendingIdsAfter -contains $jobId) {
                            # A impressão física foi enviada e somente a
                            # confirmação falhou. Mantém o registro local e não
                            # devolve o trabalho à fila, evitando duplicidade.
                            Write-Log ("O trabalho #$jobId foi enviado a impressora, mas ainda aguarda confirmacao: " + $message) 'WARN'
                        } else {
                            Write-Log ('Falha no trabalho #{0}: {1}' -f $jobId, $message) 'ERROR'
                            try {
                                $finish = Invoke-AgentFinish $cfg $jobId $false $message
                                if ($finish.result.retry) { Write-Log "Nova tentativa do trabalho #$jobId foi agendada." 'WARN' }
                                else { Write-Log "Trabalho #$jobId foi enviado para a lista de erros." 'ERROR' }
                            } catch {
                                Write-Log ('Nao foi possivel comunicar a falha do trabalho #' + $jobId + ': ' + $_.Exception.Message) 'ERROR'
                            }
                        }
                        $script:CurrentJobId = 0
                    }
                    $processedThisCycle++
                }
            }
            if ($processedThisCycle -gt 0) {
                Write-Log "$processedThisCycle trabalho(s) processado(s) no ciclo."
            }
            Write-AgentStatus 'ONLINE' '' $cfg $processedThisCycle
        } catch {
            $message = $_.Exception.Message
            Write-Log $message 'ERROR'
            Write-AgentStatus 'ERRO' $message $cfg 0
            if ($cfg -and $script:CurrentJobId -gt 0) {
                try {
                    $finish = Invoke-AgentFinish $cfg $script:CurrentJobId $false $message
                    if ($finish.result.retry) { Write-Log "Nova tentativa do trabalho #$script:CurrentJobId foi agendada." 'WARN' }
                    else { Write-Log "Trabalho #$script:CurrentJobId foi enviado para a lista de erros." 'ERROR' }
                } catch { Write-Log ('Nao foi possivel comunicar a falha ao servidor: ' + $_.Exception.Message) 'ERROR' }
                $script:CurrentJobId = 0
            }
        }
        if ($Once) { break }
        $seconds = 2
        try { if ($cfg -and [int]$cfg.PollSeconds -gt 0) { $seconds = [Math]::Min(30, [Math]::Max(1, [int]$cfg.PollSeconds)) } } catch {}
        Start-Sleep -Seconds $seconds
    }
} finally {
    Write-AgentStatus 'PARADO'
    if ($null -ne $lockStream) { try { $lockStream.Dispose() } catch {} }
    try { if (Test-Path $LockPath) { Remove-Item -Path $LockPath -Force -ErrorAction SilentlyContinue } } catch {}
    Write-Log 'Agente encerrado.'
}
