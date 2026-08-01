param(
    [string]$Server = 'https://www.glslsuxi.cn',
    [int]$Port = 48761,
    [switch]$Visible
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$nodeCommand = Get-Command node.exe -ErrorAction SilentlyContinue
if (-not $nodeCommand) {
    $nodeCommand = Get-Command node -ErrorAction SilentlyContinue
}
if (-not $nodeCommand -or -not $nodeCommand.Source) {
    throw 'Node.js was not found. Install the SUXIOS collector runtime first.'
}
$node = [string]$nodeCommand.Source
$collectorScript = Join-Path $PSScriptRoot 'ota_local_collector.mjs'
$arguments = @($collectorScript, 'serve', "--server=$Server", "--port=$Port")

$existing = Get-CimInstance Win32_Process | Where-Object {
    $_.CommandLine -like "*$collectorScript*serve*--port=$Port*"
} | Select-Object -First 1
if ($existing) {
    Write-Output "SUXIOS collector is already running: $($existing.ProcessId)"
    exit 0
}

$startParams = @{
    FilePath = $node
    ArgumentList = $arguments
    WorkingDirectory = $projectRoot
    PassThru = $true
}
if (-not $Visible) {
    $startParams.WindowStyle = 'Hidden'
}
$process = Start-Process @startParams
Write-Output "SUXIOS collector started: $($process.Id). Return to the website and select Connect this computer."
