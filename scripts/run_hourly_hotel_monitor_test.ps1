param(
    [int]$HotelId = 80,
    [int]$TestRobotId = 1,
    [string]$PhpPath = 'C:\\xampp\\php\\php.exe'
)

$ErrorActionPreference = 'Stop'
$ProjectPath = Split-Path -Parent $PSScriptRoot
$ThinkPath = Join-Path $ProjectPath 'think'
$VisualCardScript = Join-Path $ProjectPath 'scripts\send_test_wechat_visual_card.php'

if (-not (Test-Path -LiteralPath $PhpPath)) {
    throw "PHP executable was not found: $PhpPath"
}
if (-not (Test-Path -LiteralPath $ThinkPath)) {
    throw "SUXIOS project entry was not found: $ProjectPath"
}
if (-not (Test-Path -LiteralPath $VisualCardScript)) {
    throw "SUXIOS visual-card sender was not found: $VisualCardScript"
}

Push-Location $ProjectPath
try {
    & $PhpPath $ThinkPath 'hotel-monitor:wechat-broadcast' "--hotel-id=$HotelId" "--test-robot-id=$TestRobotId"
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }

    # The text monitor and image card are intentionally separate deliveries:
    # a renderer failure must not hide the text facts/deficits already sent.
    & $PhpPath $VisualCardScript '--hotel-id' $HotelId '--test-robot-id' $TestRobotId
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
