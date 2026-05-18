param(
    [string]$Database = "hotelx",
    [string]$ReportPath = "output/sql_schema_integrity_report.md",
    [switch]$ForceImport
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$php = $null

foreach ($candidate in @("C:\xampp\php\php.exe", "D:\xampp\php\php.exe", "C:\php\php.exe")) {
    if (Test-Path $candidate) {
        $php = $candidate
        break
    }
}

if (-not $php) {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) {
        $php = $cmd.Source
    }
}

if (-not $php) {
    throw "PHP executable not found"
}

$args = @(
    "scripts\verify_sql_schema_contract.php",
    "--database=$Database",
    "--validate-import",
    "--migrate",
    "--report=$ReportPath"
)

if ($ForceImport) {
    $args += "--import"
    $args += "--force-import"
}

Push-Location $root
try {
    & $php @args
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
