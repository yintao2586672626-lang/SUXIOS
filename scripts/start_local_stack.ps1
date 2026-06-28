param(
    [string]$BindHost = "127.0.0.1",
    [int]$Port = 8080,
    [string]$DbHost = "127.0.0.1",
    [int]$DbPort = 3306,
    [string]$DbName = "hotelx",
    [string]$DbUser = "root",
    [string]$DbPass = "",
    [int]$MySqlWaitSeconds = 20,
    [int]$PhpWaitSeconds = 15,
    [switch]$NoBrowser
)

$ErrorActionPreference = "Stop"

if ($env:Path) {
    [System.Environment]::SetEnvironmentVariable("Path", $env:Path, "Process")
    [System.Environment]::SetEnvironmentVariable("PATH", $null, "Process")
}

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$LogDir = Join-Path $RepoRoot "runtime\codex"
$BaseUrl = "http://$BindHost`:$Port/"
$HealthPath = "/api/health"
$HealthUrl = "http://$BindHost`:$Port$HealthPath"
$StaticProbeUrl = "http://$BindHost`:$Port/vue.global.prod.js?v=startup-static-probe"

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
Set-Location $RepoRoot

function Resolve-FirstExisting {
    param([string[]]$Paths)

    foreach ($candidate in $Paths) {
        if ($candidate -and (Test-Path $candidate)) {
            return (Resolve-Path $candidate).Path
        }
    }

    return $null
}

function Resolve-CommandSource {
    param([string]$Name)

    $command = Get-Command $Name -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($command) {
        return $command.Source
    }

    return $null
}

$PhpExe = Resolve-FirstExisting @(
    "C:\xampp\php\php.exe",
    "D:\xampp\php\php.exe",
    "C:\php\php.exe"
)
if (-not $PhpExe) {
    $PhpExe = Resolve-CommandSource "php"
}
if (-not $PhpExe) {
    throw "PHP was not found. Install XAMPP or add php.exe to PATH."
}

$MySqlExe = Resolve-FirstExisting @(
    "C:\xampp\mysql\bin\mysql.exe",
    "D:\xampp\mysql\bin\mysql.exe"
)
if (-not $MySqlExe) {
    $MySqlExe = Resolve-CommandSource "mysql"
}
if (-not $MySqlExe) {
    throw "mysql.exe was not found. Install XAMPP or add mysql.exe to PATH."
}

$MySqlDExe = Resolve-FirstExisting @(
    "C:\xampp\mysql\bin\mysqld.exe",
    "D:\xampp\mysql\bin\mysqld.exe"
)
$MySqlIni = Resolve-FirstExisting @(
    "C:\xampp\mysql\bin\my.ini",
    "D:\xampp\mysql\bin\my.ini"
)

function Invoke-MySql {
    param([string]$Sql)

    $mysqlArgs = @("-N", "-B", "-h", $DbHost, "-P", [string]$DbPort, "-u", $DbUser)
    if ($DbPass -ne "") {
        $mysqlArgs += "-p$DbPass"
    }
    $mysqlArgs += @("-e", $Sql)

    & $MySqlExe @mysqlArgs 2>$null
}

function Test-MySql {
    try {
        Invoke-MySql "SELECT 1;" | Out-Null
        return $LASTEXITCODE -eq 0
    } catch {
        return $false
    }
}

function Wait-MySql {
    param([int]$Seconds)

    for ($i = 0; $i -lt $Seconds; $i++) {
        if (Test-MySql) {
            return
        }
        Start-Sleep -Seconds 1
    }

    if (Test-MySql) {
        return
    }

    throw "MySQL did not become available on $DbHost`:$DbPort within $Seconds seconds."
}

function Start-LocalMySql {
    if (Test-MySql) {
        Write-Host "[OK] MySQL is available on $DbHost`:$DbPort"
        return
    }

    if (-not $MySqlDExe -or -not $MySqlIni) {
        throw "MySQL is not running, and XAMPP mysqld.exe/my.ini was not found."
    }

    $mysqlRoot = Split-Path (Split-Path $MySqlDExe -Parent) -Parent
    $stdout = Join-Path $LogDir "mysql-3306.out.log"
    $stderr = Join-Path $LogDir "mysql-3306.err.log"

    Write-Host "[INFO] Starting local MySQL..."
    Start-Process `
        -FilePath $MySqlDExe `
        -ArgumentList @("--defaults-file=$MySqlIni", "--standalone") `
        -WorkingDirectory $mysqlRoot `
        -WindowStyle Hidden `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr `
        | Out-Null

    Wait-MySql -Seconds $MySqlWaitSeconds
    Write-Host "[OK] MySQL started on $DbHost`:$DbPort"
}

function Assert-DatabaseReady {
    $schema = (Invoke-MySql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DbName';" | Select-Object -First 1)
    if ($schema -ne $DbName) {
        throw "Database '$DbName' was not found. Import database/init_full.sql first."
    }

    $coreTableSql = @"
SELECT COUNT(*)
FROM information_schema.TABLES
WHERE TABLE_SCHEMA='$DbName'
  AND TABLE_NAME IN ('users','roles','hotels','daily_reports','system_config');
"@
    $coreTableCount = [int]((Invoke-MySql $coreTableSql | Select-Object -First 1) -as [int])
    if ($coreTableCount -ne 5) {
        throw "Database '$DbName' is missing core tables. Re-import database/init_full.sql."
    }

    Write-Host "[OK] Database '$DbName' and core tables are ready"
}

function Test-HttpHealth {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $HealthUrl -TimeoutSec 2
        return $response.StatusCode -eq 200 -and $response.Content -like "*status*" -and $response.Content -like "*ok*"
    } catch {
        return $false
    }
}

function Test-StaticAsset {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $StaticProbeUrl -TimeoutSec 3
        $contentType = [string]$response.Headers["Content-Type"]
        return $response.StatusCode -eq 200 `
            -and $contentType -like "*javascript*" `
            -and $response.Content -like "*Vue*"
    } catch {
        return $false
    }
}

function Test-PortListening {
    $listener = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
    return $null -ne $listener
}

function Start-ThinkPhp {
    if ((Test-HttpHealth) -and (Test-StaticAsset)) {
        Write-Host "[OK] ThinkPHP is already serving $BaseUrl"
        return
    }

    if (Test-PortListening) {
        throw "Port $Port is already in use, but $HealthUrl did not pass."
    }

    $stdout = Join-Path $LogDir "think-run-$Port.out.log"
    $stderr = Join-Path $LogDir "think-run-$Port.err.log"

    Write-Host "[INFO] Starting ThinkPHP on $BaseUrl"
    Start-Process `
        -FilePath $PhpExe `
        -ArgumentList @("-S", "$BindHost`:$Port", "-t", "public", "public/router.php") `
        -WorkingDirectory $RepoRoot `
        -WindowStyle Hidden `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr `
        | Out-Null

    for ($i = 0; $i -lt $PhpWaitSeconds; $i++) {
        if ((Test-HttpHealth) -and (Test-StaticAsset)) {
            Write-Host "[OK] ThinkPHP started: $BaseUrl"
            return
        }
        Start-Sleep -Seconds 1
    }

    throw "ThinkPHP did not become healthy at $HealthUrl with static assets available within $PhpWaitSeconds seconds."
}

if (-not (Test-Path (Join-Path $RepoRoot "think"))) {
    throw "Current folder is not the ThinkPHP project root."
}

Start-LocalMySql
Assert-DatabaseReady
Start-ThinkPhp

if (-not $NoBrowser) {
    Start-Process $BaseUrl | Out-Null
}

Write-Host "[DONE] Local stack ready: $BaseUrl"
