param(
    [string]$OutputPath = "output/hotelx_dump_full.sql"
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$resolvedOutput = Join-Path $root $OutputPath
$outputDir = Split-Path -Parent $resolvedOutput

if (!(Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

$initFullPath = Join-Path $root "database/init_full.sql"
if (!(Test-Path $initFullPath)) {
    throw "Missing SQL source manifest: database/init_full.sql"
}

$sources = @()
foreach ($line in Get-Content -Path $initFullPath) {
    if ($line -match "^\s*SOURCE\s+(.+?);") {
        $source = $Matches[1].Trim().Replace("\", "/")
        $source = $source -replace "^\./", ""
        $sources += $source
    }
}

if ($sources.Count -eq 0) {
    throw "No SOURCE entries found in database/init_full.sql"
}

Set-Content -Path $resolvedOutput -Value "-- SuXi OS full dump generated at $(Get-Date -Format s)`r`nSET NAMES utf8mb4;`r`nSET time_zone = '+08:00';`r`n" -Encoding UTF8

foreach ($source in $sources) {
    $sourcePath = Join-Path $root $source
    if (!(Test-Path $sourcePath)) {
        throw "Missing SQL source: $source"
    }
    Add-Content -LiteralPath $resolvedOutput -Value "`r`n-- SOURCE: $source`r`n" -Encoding UTF8
    $sourceContent = Get-Content -LiteralPath $sourcePath -Raw
    Add-Content -LiteralPath $resolvedOutput -Value $sourceContent -Encoding UTF8
}

Write-Host "Generated $resolvedOutput"
