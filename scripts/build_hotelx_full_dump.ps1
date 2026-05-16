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

$sources = @(
    "hotelx_dump.sql",
    "database/login_logs.sql",
    "database/complaint_tables.sql",
    "database/update_system_config.sql",
    "database/migrations/20250402_create_agent_tables.sql",
    "database/migrations/20250402_enhance_agent_tables.sql",
    "database/migrations/20260509_create_strategy_simulation_tables.sql",
    "database/migrations/20260511_add_ota_traffic_fields.sql",
    "database/migrations/20260511_create_ai_model_configs.sql",
    "database/migrations/20260511_create_missing_business_tables.sql",
    "database/migrations/20260516_create_opening_management_tables.sql",
    "database/migrations/20260516_create_operation_management_tables.sql",
    "database/migrations/20260517_create_quant_simulation_records.sql",
    "database/migrations/20260517_create_expansion_records.sql",
    "database/migrations/20260517_create_transfer_records.sql",
    "database/migrations/20260517_add_international_ota_report_fields.sql"
)

Set-Content -Path $resolvedOutput -Value "-- SuXi OS full dump generated at $(Get-Date -Format s)`r`nSET NAMES utf8mb4;`r`nSET time_zone = '+08:00';`r`n" -Encoding UTF8

foreach ($source in $sources) {
    $sourcePath = Join-Path $root $source
    if (!(Test-Path $sourcePath)) {
        throw "Missing SQL source: $source"
    }
    Add-Content -Path $resolvedOutput -Value "`r`n-- SOURCE: $source`r`n" -Encoding UTF8
    Get-Content -Path $sourcePath -Raw | Add-Content -Path $resolvedOutput -Encoding UTF8
}

Write-Host "Generated $resolvedOutput"
