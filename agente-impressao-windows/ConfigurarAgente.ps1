[CmdletBinding()]
param(
    [string]$ConfigPath
)

# O valor padrao de parametros e avaliado antes de $PSScriptRoot estar
# disponivel em algumas instalacoes do Windows PowerShell. Por isso,
# resolvemos a pasta do script somente depois do bloco param.
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

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()


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

function Get-InitialConfig {
    if (Test-Path $ConfigPath) {
        return Get-Content -Path $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
    }
    return [pscustomobject]@{
        ServerUrl = ''
        Token = ''
        StationName = $env:COMPUTERNAME
        PollSeconds = 2
        RequestTimeoutSeconds = 20
        Mappings = @()
    }
}

function Get-InstalledPrinterNames {
    try {
        return @(Get-CimInstance Win32_Printer | Sort-Object Name | Select-Object -ExpandProperty Name)
    } catch {
        try { return @([System.Drawing.Printing.PrinterSettings]::InstalledPrinters) }
        catch { return @() }
    }
}


function Normalize-ColumnsValue($Value) {
    $columns = 48
    [void][int]::TryParse([string]$Value, [ref]$columns)
    # Muitos usuarios informam a largura da bobina (58/80) no campo de
    # caracteres. Convertemos automaticamente para a quantidade correta.
    if ($columns -eq 58) { return 32 }
    if ($columns -eq 80) { return 48 }
    if ($columns -lt 24 -or $columns -gt 64) { return 48 }
    return $columns
}

function Get-WebErrorMessage([System.Management.Automation.ErrorRecord]$ErrorRecord) {
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
        return 'A chave foi recusada. No sistema, clique em Nova chave, copie a chave COMPLETA, cole neste configurador e tente novamente.'
    }
    if ($responseText) {
        try { $json = $responseText | ConvertFrom-Json; if ($json.error) { return [string]$json.error } } catch {}
    }
    return [string]$ErrorRecord.Exception.Message
}

function Invoke-ConfigurationTest($Output) {
    $separator = if ($Output.ServerUrl.Contains('?')) { '&' } else { '?' }
    $headers = @{
        Authorization = 'Bearer ' + $Output.Token
        'X-Agent-Token' = $Output.Token
        'X-Agent-Machine' = $Output.StationName
        'X-Agent-Version' = $AgentVersion
        Accept = 'application/json'
    }
    try {
        $uri = $Output.ServerUrl + $separator + 'action=ping'
        $result = Invoke-RestMethod -Method Get -Uri $uri -Headers $headers -TimeoutSec 20
        if (-not $result.ok) { throw [string]$result.error }
        $groups = @($Output.Mappings | ForEach-Object { ([string]$_.Group).Trim() } | Where-Object { $_ } | Select-Object -Unique)
        if ($groups.Count -gt 0) {
            $syncUri = $Output.ServerUrl + $separator + 'action=sync_groups'
            $sync = Invoke-RestMethod -Method Post -Uri $syncUri -Headers $headers -ContentType 'application/x-www-form-urlencoded; charset=utf-8' -Body @{ groups = ($groups -join ',') } -TimeoutSec 20
            if (-not $sync.ok) { throw [string]$sync.error }
        }
        return [pscustomobject]@{ Result = $result; Groups = $groups }
    } catch {
        throw (Get-WebErrorMessage $_)
    }
}

function Activate-AgentTask {
    $installer = Join-Path $ScriptRoot 'InstalarAgente.ps1'
    if (-not (Test-Path $installer)) { throw 'InstalarAgente.ps1 nao foi encontrado na pasta do agente.' }
    & $installer -Modo Usuario -Silent | Out-Null
}

function Show-Error([string]$Text) {
    [System.Windows.Forms.MessageBox]::Show(
        $Text, 'Agente de Impressao',
        [System.Windows.Forms.MessageBoxButtons]::OK,
        [System.Windows.Forms.MessageBoxIcon]::Error
    ) | Out-Null
}

function Show-Info([string]$Text) {
    [System.Windows.Forms.MessageBox]::Show(
        $Text, 'Agente de Impressao',
        [System.Windows.Forms.MessageBoxButtons]::OK,
        [System.Windows.Forms.MessageBoxIcon]::Information
    ) | Out-Null
}

$cfg = Get-InitialConfig
$form = New-Object System.Windows.Forms.Form
$form.Text = 'Configurar Agente de Impressao Nexa/Prospero'
$form.Size = New-Object System.Drawing.Size(960, 690)
$form.MinimumSize = New-Object System.Drawing.Size(850, 620)
$form.StartPosition = 'CenterScreen'
$form.Font = New-Object System.Drawing.Font('Segoe UI', 9)

$lblUrl = New-Object System.Windows.Forms.Label
$lblUrl.Text = 'Endereco da API'
$lblUrl.Location = New-Object System.Drawing.Point(18, 18)
$lblUrl.AutoSize = $true

$txtUrl = New-Object System.Windows.Forms.TextBox
$txtUrl.Location = New-Object System.Drawing.Point(18, 40)
$txtUrl.Size = New-Object System.Drawing.Size(900, 25)
$txtUrl.Anchor = 'Top,Left,Right'
$txtUrl.Text = Normalize-ServerUrl ([string]$cfg.ServerUrl)

$lblToken = New-Object System.Windows.Forms.Label
$lblToken.Text = 'Chave do agente'
$lblToken.Location = New-Object System.Drawing.Point(18, 76)
$lblToken.AutoSize = $true

$txtToken = New-Object System.Windows.Forms.TextBox
$txtToken.Location = New-Object System.Drawing.Point(18, 98)
$txtToken.Size = New-Object System.Drawing.Size(900, 25)
$txtToken.Anchor = 'Top,Left,Right'
$txtToken.Text = [string]$cfg.Token

$lblStation = New-Object System.Windows.Forms.Label
$lblStation.Text = 'Nome desta estacao'
$lblStation.Location = New-Object System.Drawing.Point(18, 134)
$lblStation.AutoSize = $true

$txtStation = New-Object System.Windows.Forms.TextBox
$txtStation.Location = New-Object System.Drawing.Point(18, 156)
$txtStation.Size = New-Object System.Drawing.Size(390, 25)
$txtStation.Text = [string]$cfg.StationName

$lblPoll = New-Object System.Windows.Forms.Label
$lblPoll.Text = 'Intervalo (segundos)'
$lblPoll.Location = New-Object System.Drawing.Point(430, 134)
$lblPoll.AutoSize = $true

$numPoll = New-Object System.Windows.Forms.NumericUpDown
$numPoll.Location = New-Object System.Drawing.Point(430, 156)
$numPoll.Minimum = 1
$numPoll.Maximum = 30
$numPoll.Value = [Math]::Max(1, [int]$cfg.PollSeconds)

$lblMap = New-Object System.Windows.Forms.Label
$lblMap.Text = 'Mapeamento dos setores'
$lblMap.Location = New-Object System.Drawing.Point(18, 200)
$lblMap.AutoSize = $true
$lblMap.Font = New-Object System.Drawing.Font('Segoe UI', 10, [System.Drawing.FontStyle]::Bold)

$grid = New-Object System.Windows.Forms.DataGridView
$grid.Location = New-Object System.Drawing.Point(18, 228)
$grid.Size = New-Object System.Drawing.Size(900, 300)
$grid.Anchor = 'Top,Bottom,Left,Right'
$grid.AllowUserToAddRows = $true
$grid.AllowUserToDeleteRows = $true
$grid.AutoSizeColumnsMode = 'Fill'
$grid.RowHeadersVisible = $false

$colGroup = New-Object System.Windows.Forms.DataGridViewTextBoxColumn
$colGroup.Name = 'Group'
$colGroup.HeaderText = 'Setor/grupo'
$colGroup.FillWeight = 22

$colMode = New-Object System.Windows.Forms.DataGridViewComboBoxColumn
$colMode.Name = 'Mode'
$colMode.HeaderText = 'Tipo'
[void]$colMode.Items.Add('WINDOWS')
[void]$colMode.Items.Add('TCP')
$colMode.FillWeight = 14

$colDestination = New-Object System.Windows.Forms.DataGridViewTextBoxColumn
$colDestination.Name = 'Destination'
$colDestination.HeaderText = 'Impressora do Windows ou IP:porta'
$colDestination.FillWeight = 38

$colColumns = New-Object System.Windows.Forms.DataGridViewTextBoxColumn
$colColumns.Name = 'Columns'
$colColumns.HeaderText = 'Colunas'
$colColumns.FillWeight = 9

$colEncoding = New-Object System.Windows.Forms.DataGridViewTextBoxColumn
$colEncoding.Name = 'Encoding'
$colEncoding.HeaderText = 'Codificacao'
$colEncoding.FillWeight = 10

$colCut = New-Object System.Windows.Forms.DataGridViewCheckBoxColumn
$colCut.Name = 'Cut'
$colCut.HeaderText = 'Cortar'
$colCut.FillWeight = 7

foreach ($column in @($colGroup, $colMode, $colDestination, $colColumns, $colEncoding, $colCut)) {
    [void]$grid.Columns.Add($column)
}

foreach ($mapping in @($cfg.Mappings)) {
    $index = $grid.Rows.Add()
    $row = $grid.Rows[$index]
    $row.Cells['Group'].Value = [string]$mapping.Group
    $row.Cells['Mode'].Value = if ($mapping.Mode) { [string]$mapping.Mode } else { 'WINDOWS' }
    $row.Cells['Destination'].Value = [string]$mapping.Destination
    $row.Cells['Columns'].Value = [string](Normalize-ColumnsValue $(if ($mapping.Columns) { $mapping.Columns } else { 48 }))
    $row.Cells['Encoding'].Value = if ($mapping.Encoding) { [string]$mapping.Encoding } else { '850' }
    $row.Cells['Cut'].Value = if ($null -ne $mapping.Cut) { [bool]$mapping.Cut } else { $true }
}

$lblPrinters = New-Object System.Windows.Forms.Label
$lblPrinters.Text = 'Impressoras instaladas no Windows'
$lblPrinters.Location = New-Object System.Drawing.Point(18, 544)
$lblPrinters.Anchor = 'Bottom,Left'
$lblPrinters.AutoSize = $true

$cmbPrinters = New-Object System.Windows.Forms.ComboBox
$cmbPrinters.Location = New-Object System.Drawing.Point(18, 566)
$cmbPrinters.Size = New-Object System.Drawing.Size(500, 25)
$cmbPrinters.Anchor = 'Bottom,Left,Right'
$cmbPrinters.DropDownStyle = 'DropDownList'
foreach ($printer in (Get-InstalledPrinterNames)) { [void]$cmbPrinters.Items.Add($printer) }
if ($cmbPrinters.Items.Count -gt 0) { $cmbPrinters.SelectedIndex = 0 }

$btnApply = New-Object System.Windows.Forms.Button
$btnApply.Text = 'Aplicar na linha'
$btnApply.Location = New-Object System.Drawing.Point(530, 564)
$btnApply.Size = New-Object System.Drawing.Size(120, 29)
$btnApply.Anchor = 'Bottom,Right'
$btnApply.Add_Click({
    if ($grid.CurrentRow -and -not $grid.CurrentRow.IsNewRow -and $cmbPrinters.SelectedItem) {
        $grid.CurrentRow.Cells['Mode'].Value = 'WINDOWS'
        $grid.CurrentRow.Cells['Destination'].Value = [string]$cmbPrinters.SelectedItem
    }
})

$lblHint = New-Object System.Windows.Forms.Label
$lblHint.Text = 'USB/rede instalada: WINDOWS + nome da impressora. Ethernet direta: TCP + 192.168.1.50:9100. 32 colunas para 58 mm; 48 para 80 mm.'
$lblHint.Location = New-Object System.Drawing.Point(18, 604)
$lblHint.Size = New-Object System.Drawing.Size(620, 45)
$lblHint.Anchor = 'Bottom,Left,Right'
$lblHint.ForeColor = [System.Drawing.Color]::DimGray

$btnTest = New-Object System.Windows.Forms.Button
$btnTest.Text = 'Testar conexao'
$btnTest.Location = New-Object System.Drawing.Point(660, 614)
$btnTest.Size = New-Object System.Drawing.Size(125, 32)
$btnTest.Anchor = 'Bottom,Right'

$btnSave = New-Object System.Windows.Forms.Button
$btnSave.Text = 'Salvar e ativar'
$btnSave.Location = New-Object System.Drawing.Point(795, 614)
$btnSave.Size = New-Object System.Drawing.Size(123, 32)
$btnSave.Anchor = 'Bottom,Right'
$btnSave.BackColor = [System.Drawing.Color]::FromArgb(30, 64, 175)
$btnSave.ForeColor = [System.Drawing.Color]::White

function Get-CurrentConfiguration {
    $mappings = @()
    foreach ($row in $grid.Rows) {
        if ($row.IsNewRow) { continue }
        $group = ([string]$row.Cells['Group'].Value).Trim()
        $destination = ([string]$row.Cells['Destination'].Value).Trim()
        if (-not $group -and -not $destination) { continue }

        $mode = ([string]$row.Cells['Mode'].Value).Trim().ToUpperInvariant()
        if (-not $mode) { $mode = 'WINDOWS' }
        $columns = Normalize-ColumnsValue ([string]$row.Cells['Columns'].Value)
        $row.Cells['Columns'].Value = [string]$columns
        $encoding = 850
        [void][int]::TryParse([string]$row.Cells['Encoding'].Value, [ref]$encoding)

        $mappings += @{
            Group = $group
            Mode = $mode
            Destination = $destination
            Columns = $columns
            Encoding = $encoding
            EscPosTable = 2
            Cut = [bool]$row.Cells['Cut'].Value
            OpenDrawer = $false
        }
    }

    return @{
        ServerUrl = (Normalize-ServerUrl ([string]$txtUrl.Text.Trim()))
        Token = $txtToken.Text.Trim()
        StationName = $txtStation.Text.Trim()
        PollSeconds = [int]$numPoll.Value
        RequestTimeoutSeconds = 20
        Mappings = $mappings
    }
}

$btnSave.Add_Click({
    try {
        $output = Get-CurrentConfiguration
        $txtUrl.Text = [string]$output.ServerUrl
        if (-not $output.ServerUrl -or -not $output.Token) { throw 'Informe o endereco da API e a chave completa.' }
        if ($output.Mappings.Count -eq 0) { throw 'Cadastre ao menos um setor e uma impressora.' }
        $knownGroups = @{}
        $installed = @(Get-InstalledPrinterNames)
        foreach ($mapping in $output.Mappings) {
            if (-not $mapping.Group -or -not $mapping.Destination) { throw 'Todas as linhas precisam de setor e destino.' }
            $groupKey = ([string]$mapping.Group).Trim().ToUpperInvariant()
            if ($knownGroups.ContainsKey($groupKey)) { throw "O setor '$($mapping.Group)' foi cadastrado mais de uma vez." }
            $knownGroups[$groupKey] = $true
            if ([string]$mapping.Mode -eq 'WINDOWS') {
                $found = @($installed | Where-Object { [string]::Equals([string]$_, [string]$mapping.Destination, [StringComparison]::OrdinalIgnoreCase) }).Count -gt 0
                if (-not $found) { throw "A impressora '$($mapping.Destination)' nao foi encontrada entre as impressoras instaladas no Windows." }
            }
        }
        $output | ConvertTo-Json -Depth 6 | Set-Content -Path $ConfigPath -Encoding UTF8
        $test = Invoke-ConfigurationTest $output
        Activate-AgentTask
        $message = 'Configuracao salva, chave validada e agente ativado.' + [Environment]::NewLine +
                   'Empresa: ' + [string]$test.Result.company.name + [Environment]::NewLine +
                   'Agente: ' + [string]$test.Result.agent.name + [Environment]::NewLine +
                   'Setores: ' + ($test.Groups -join ', ') + [Environment]::NewLine +
                   'Inicializacao: automatica para o usuario atual'
        Show-Info $message
    } catch { Show-Error $_.Exception.Message }
})

$btnTest.Add_Click({
    try {
        $output = Get-CurrentConfiguration
        $txtUrl.Text = [string]$output.ServerUrl
        if (-not $output.ServerUrl -or -not $output.Token) { throw 'Informe o endereco da API e a chave completa.' }
        $test = Invoke-ConfigurationTest $output
        $message = 'Conexao realizada com sucesso.' + [Environment]::NewLine +
                   'Empresa: ' + [string]$test.Result.company.name + [Environment]::NewLine +
                   'Agente: ' + [string]$test.Result.agent.name + [Environment]::NewLine +
                   'Setores sincronizados: ' + ($test.Groups -join ', ')
        Show-Info $message
    } catch { Show-Error $_.Exception.Message }
})

foreach ($control in @(
    $lblUrl, $txtUrl, $lblToken, $txtToken, $lblStation, $txtStation,
    $lblPoll, $numPoll, $lblMap, $grid, $lblPrinters, $cmbPrinters,
    $btnApply, $lblHint, $btnTest, $btnSave
)) {
    [void]$form.Controls.Add($control)
}

try {
    [void]$form.ShowDialog()
} catch {
    Show-Error ('Nao foi possivel abrir o configurador.' + [Environment]::NewLine + [Environment]::NewLine + $_.Exception.Message)
    throw
}
