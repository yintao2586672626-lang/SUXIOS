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

# init_full.sql is a frozen baseline. Add every later migration dynamically so
# the generated one-file dump remains complete without growing the manifest.
$knownSources = @{}
foreach ($source in $sources) {
    $knownSources[$source.ToLowerInvariant()] = $true
}
$migrationDir = Join-Path $root "database/migrations"
foreach ($migration in Get-ChildItem -LiteralPath $migrationDir -Filter "*.sql" | Sort-Object Name) {
    $relative = "database/migrations/$($migration.Name)"
    if (-not $knownSources.ContainsKey($relative.ToLowerInvariant())) {
        $sources += $relative
        $knownSources[$relative.ToLowerInvariant()] = $true
    }
}

Set-Content -Path $resolvedOutput -Value "-- SuXi OS full dump generated at $(Get-Date -Format s)`r`nSET NAMES utf8mb4;`r`nSET time_zone = '+08:00';`r`n" -Encoding UTF8

# Bootstrap the latest ledger shape before any catalog file is recorded. The
# same migrations remain in the normal catalog and execute idempotently later.
$ledgerBootstrapSources = @(
    "database/migrations/20260722_create_schema_versions.sql",
    "database/migrations/20260722_harden_schema_version_governance.sql",
    "database/migrations/20260722_track_frozen_baseline_sources.sql"
)
foreach ($bootstrapSource in $ledgerBootstrapSources) {
    $bootstrapPath = Join-Path $root $bootstrapSource
    if (!(Test-Path -LiteralPath $bootstrapPath)) {
        throw "Missing ledger bootstrap source: $bootstrapSource"
    }
    Add-Content -LiteralPath $resolvedOutput -Value "`r`n-- LEDGER BOOTSTRAP: $bootstrapSource`r`n" -Encoding UTF8
    Add-Content -LiteralPath $resolvedOutput -Value (Get-Content -LiteralPath $bootstrapPath -Raw) -Encoding UTF8
}

foreach ($source in $sources) {
    $sourcePath = Join-Path $root $source
    if (!(Test-Path $sourcePath)) {
        throw "Missing SQL source: $source"
    }
    Add-Content -LiteralPath $resolvedOutput -Value "`r`n-- SOURCE: $source`r`n" -Encoding UTF8
    $sourceContent = Get-Content -LiteralPath $sourcePath -Raw
    Add-Content -LiteralPath $resolvedOutput -Value $sourceContent -Encoding UTF8
    if ($source -like "database/migrations/*.sql") {
        $migration = [System.IO.Path]::GetFileName($source)
        if ($migration -notmatch '^\d{8}_[a-z0-9_]+\.sql$') {
            throw "Invalid migration filename: $migration"
        }
        $version = [System.IO.Path]::GetFileNameWithoutExtension($migration)
        $checksum = (Get-FileHash -LiteralPath $sourcePath -Algorithm SHA256).Hash.ToLowerInvariant()
        $registration = "INSERT INTO ``schema_versions`` (``migration``, ``version``, ``checksum``, ``execution_kind``, ``executed_at``) VALUES ('$migration', '$version', '$checksum', 'executed', CURRENT_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE ``version`` = VALUES(``version``), ``checksum`` = VALUES(``checksum``), ``execution_kind`` = VALUES(``execution_kind``);"
        Add-Content -LiteralPath $resolvedOutput -Value "`r`n-- REGISTER: $migration`r`n$registration`r`n" -Encoding UTF8
    } else {
        $baselineChecksum = (Get-FileHash -LiteralPath $sourcePath -Algorithm SHA256).Hash.ToLowerInvariant()
        $escapedSource = $source.Replace("'", "''")
        $baselineRegistration = "INSERT INTO ``schema_baseline_sources`` (``source``, ``checksum``, ``registered_at``) VALUES ('$escapedSource', '$baselineChecksum', CURRENT_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE ``checksum`` = VALUES(``checksum``), ``registered_at`` = VALUES(``registered_at``);"
        Add-Content -LiteralPath $resolvedOutput -Value "`r`n-- REGISTER BASELINE: $source`r`n$baselineRegistration`r`n" -Encoding UTF8
    }
}

Write-Host "Generated $resolvedOutput"
