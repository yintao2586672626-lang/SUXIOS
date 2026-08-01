param(
    [string]$BindHost = '127.0.0.1',
    [int]$Port = 8080,
    [int]$MySqlPort = 3306,
    [int]$WaitSeconds = 20
)

$ErrorActionPreference = 'Stop'

if ($env:Path) {
    [System.Environment]::SetEnvironmentVariable('Path', $env:Path, 'Process')
    [System.Environment]::SetEnvironmentVariable('PATH', $null, 'Process')
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runtimeDirectory = Join-Path $projectRoot 'runtime\public-origin-watchdog'
$logPath = Join-Path $runtimeDirectory 'watchdog.log'
$healthUrl = "http://$BindHost`:$Port/api/health"
$rootUrl = "http://$BindHost`:$Port/"

New-Item -ItemType Directory -Force -Path $runtimeDirectory | Out-Null

function Write-WatchdogLog {
    param([string]$Message)

    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -LiteralPath $logPath -Encoding utf8 -Value "[$timestamp] $Message"
}

function Test-TcpListener {
    param([int]$TargetPort)

    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $async = $client.BeginConnect('127.0.0.1', $TargetPort, $null, $null)
        if (-not $async.AsyncWaitHandle.WaitOne(1500)) {
            return $false
        }
        $client.EndConnect($async)
        return $true
    } catch {
        return $false
    } finally {
        $client.Dispose()
    }
}

function Test-OriginReady {
    try {
        $health = Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 4
        $healthPayload = $health.Content | ConvertFrom-Json
        if ($health.StatusCode -ne 200 -or [string]$healthPayload.status -ne 'ok') {
            return $false
        }

        $root = Invoke-WebRequest -Uri $rootUrl -UseBasicParsing -TimeoutSec 4
        return $root.StatusCode -eq 200 `
            -and $root.Content -match '<title[^>]*>[^<]+</title>' `
            -and $root.Content -match 'app-main'
    } catch {
        return $false
    }
}

$mutex = New-Object System.Threading.Mutex($false, 'Global\SUXIOSPublicOriginWatchdog')
$hasMutex = $false

try {
    $hasMutex = $mutex.WaitOne(0)
    if (-not $hasMutex) {
        exit 0
    }

    if (Test-OriginReady) {
        exit 0
    }

    if (Test-TcpListener -TargetPort $Port) {
        throw "Port $Port is occupied but the SUXIOS origin is unhealthy. Refusing to terminate an unknown process."
    }

    $phpExe = @(
        'C:\xampp\php\php.exe',
        'D:\xampp\php\php.exe',
        'C:\php\php.exe'
    ) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
    if (-not $phpExe) {
        throw 'PHP executable was not found.'
    }

    if (-not (Test-TcpListener -TargetPort $MySqlPort)) {
        $mysqlExe = @(
            'C:\xampp\mysql\bin\mysqld.exe',
            'D:\xampp\mysql\bin\mysqld.exe'
        ) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
        $mysqlIni = @(
            'C:\xampp\mysql\bin\my.ini',
            'D:\xampp\mysql\bin\my.ini'
        ) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
        if (-not $mysqlExe -or -not $mysqlIni) {
            throw 'MySQL is not listening and the XAMPP MySQL runtime was not found.'
        }

        $mysqlRoot = Split-Path (Split-Path $mysqlExe -Parent) -Parent
        Start-Process `
            -FilePath $mysqlExe `
            -ArgumentList @("--defaults-file=$mysqlIni", '--standalone') `
            -WorkingDirectory $mysqlRoot `
            -WindowStyle Hidden `
            -RedirectStandardOutput (Join-Path $runtimeDirectory 'mysql.out.log') `
            -RedirectStandardError (Join-Path $runtimeDirectory 'mysql.err.log') |
            Out-Null

        for ($attempt = 0; $attempt -lt $WaitSeconds; $attempt++) {
            if (Test-TcpListener -TargetPort $MySqlPort) {
                break
            }
            Start-Sleep -Seconds 1
        }
        if (-not (Test-TcpListener -TargetPort $MySqlPort)) {
            throw "MySQL did not listen on port $MySqlPort within $WaitSeconds seconds."
        }
    }

    Push-Location $projectRoot
    try {
        & $phpExe scripts\check_database_version.php
        if ($LASTEXITCODE -ne 0) {
            throw 'Database schema is not ready; automatic migration is intentionally disabled.'
        }

        Start-Process `
            -FilePath $phpExe `
            -ArgumentList @(
                '-d', 'realpath_cache_size=4096K',
                '-d', 'realpath_cache_ttl=600',
                '-S', "$BindHost`:$Port",
                '-t', 'public',
                'public/router.php'
            ) `
            -WorkingDirectory $projectRoot `
            -WindowStyle Hidden `
            -RedirectStandardOutput (Join-Path $runtimeDirectory 'php.out.log') `
            -RedirectStandardError (Join-Path $runtimeDirectory 'php.err.log') |
            Out-Null
    } finally {
        Pop-Location
    }

    for ($attempt = 0; $attempt -lt $WaitSeconds; $attempt++) {
        if (Test-OriginReady) {
            Write-WatchdogLog 'Origin recovered and passed root plus health checks.'
            exit 0
        }
        Start-Sleep -Seconds 1
    }

    throw "SUXIOS origin did not become healthy within $WaitSeconds seconds."
} catch {
    Write-WatchdogLog "ERROR: $($_.Exception.Message)"
    Write-Error $_
    exit 1
} finally {
    if ($hasMutex) {
        $mutex.ReleaseMutex()
    }
    $mutex.Dispose()
}
