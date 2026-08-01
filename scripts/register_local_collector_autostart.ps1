param(
    [string]$Server = 'https://www.glslsuxi.cn',
    [int]$Port = 48761,
    [string]$TaskName = 'SUXIOS Local Collector',
    [switch]$Remove
)

$ErrorActionPreference = 'Stop'
$starter = Join-Path $PSScriptRoot 'start_local_collector.ps1'
if (-not (Test-Path -LiteralPath $starter)) {
    throw 'Local collector starter was not found.'
}

if ($Remove) {
    & schtasks.exe /Delete /TN $TaskName /F | Out-Null
    Write-Output "Removed task: $TaskName"
    exit 0
}

$powershell = Join-Path $PSHOME 'powershell.exe'
$taskCommand = '"{0}" -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "{1}" -Server "{2}" -Port {3}' -f $powershell, $starter, $Server, $Port
& schtasks.exe /Create /TN $TaskName /TR $taskCommand /SC ONLOGON /RL LIMITED /F | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to register the local collector startup task.'
}
Write-Output "Registered task: $TaskName"
