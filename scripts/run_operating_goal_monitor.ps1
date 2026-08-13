param(
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [string]$ProjectPath = '',
    [int]$HotelId = 0,
    [int]$HotelLimit = 100
)

$ErrorActionPreference = 'Stop'
$ProjectPath = if ($ProjectPath -eq '') { Split-Path -Parent $PSScriptRoot } else { $ProjectPath }
$ProjectPath = (Resolve-Path -LiteralPath $ProjectPath).Path

if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw "PHP executable was not found: $PhpPath"
}
$ThinkPath = Join-Path $ProjectPath 'think'
if (-not (Test-Path -LiteralPath $ThinkPath -PathType Leaf)) {
    throw "SUXIOS command entry was not found: $ThinkPath"
}
if ($HotelId -lt 0 -or $HotelLimit -lt 1 -or $HotelLimit -gt 500) {
    throw 'HotelId must be zero or positive and HotelLimit must be 1..500.'
}
$CommandArguments = @(
    $ThinkPath,
    'operation:goal-intervention-monitor',
    '--execute',
    "--hotel-limit=$HotelLimit"
)
if ($HotelId -gt 0) {
    $CommandArguments += "--hotel-id=$HotelId"
}

Push-Location -LiteralPath $ProjectPath
try {
    & $PhpPath @CommandArguments
    if ($LASTEXITCODE -ne 0) {
        throw "Operating goal monitor returned exit code $LASTEXITCODE."
    }
} finally {
    Pop-Location
}
