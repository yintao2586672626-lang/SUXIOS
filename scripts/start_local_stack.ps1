param(
    [string]$BindHost = "127.0.0.1",
    [int]$Port = 8080,
    [int]$BackendPort = 8081,
    [ValidateRange(3, 16)]
    [int]$PhpWorkerCount = 3,
    [string]$DbHost = "127.0.0.1",
    [int]$DbPort = 3306,
    [string]$DbName = "hotelx",
    [string]$DbUser = "root",
    [string]$DbPass = "",
    [int]$MySqlWaitSeconds = 20,
    [int]$PhpWaitSeconds = 15,
    [switch]$DatabaseOnly,
    [switch]$NoBrowser
)

$ErrorActionPreference = "Stop"

if ($env:Path) {
    $ProcessSearchPath = $env:Path
    # Codex shells may expose both PATH and Path. Start-Process builds a
    # case-insensitive environment dictionary and fails when both are present.
    [System.Environment]::SetEnvironmentVariable("PATH", $null, "Process")
    [System.Environment]::SetEnvironmentVariable("Path", $ProcessSearchPath, "Process")
}

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$LogDir = Join-Path $RepoRoot "runtime\codex"
$BaseUrl = "http://$BindHost`:$Port/"
$HealthPath = "/api/health"
$HealthUrl = "http://$BindHost`:$Port$HealthPath"
$BackendPorts = @($BackendPort..($BackendPort + $PhpWorkerCount - 1))
$BackendUrls = @($BackendPorts | ForEach-Object { "http://$BindHost`:$($_)" })
$StaticProbeUrl = "http://$BindHost`:$Port/vue.global.prod.js?v=startup-static-probe"
$OriginServerPath = Join-Path $RepoRoot "scripts\local_origin_server.mjs"
$WecomAibotWorkerPath = Join-Path $RepoRoot "scripts\wecom_aibot_worker.mjs"
$PublicRoot = Join-Path $RepoRoot "public"
$PublicEntryPath = Join-Path $PublicRoot "app-main.min.js"

function Get-Sha256FileDigest {
    param([Parameter(Mandatory = $true)][string]$LiteralPath)

    $stream = $null
    $sha = $null
    try {
        $stream = [System.IO.File]::OpenRead($LiteralPath)
        $sha = [System.Security.Cryptography.SHA256]::Create()
        return ([System.BitConverter]::ToString($sha.ComputeHash($stream))).Replace("-", "").ToLowerInvariant()
    } finally {
        if ($sha) { $sha.Dispose() }
        if ($stream) { $stream.Dispose() }
    }
}

function Get-ProjectIdentity {
    $head = "unavailable"
    $dirtyState = "unavailable"
    $gitCommand = Get-Command "git" -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($gitCommand) {
        $headValue = & $gitCommand.Source -C $RepoRoot rev-parse HEAD 2>$null
        if ($LASTEXITCODE -eq 0 -and $headValue) {
            $head = ([string]($headValue | Select-Object -First 1)).Trim()
        }
        $status = @(& $gitCommand.Source -C $RepoRoot status --porcelain=v1 2>$null)
        if ($LASTEXITCODE -eq 0) {
            $dirtyState = if ($status.Count -gt 0) { "dirty" } else { "clean" }
        }
    }
    if (-not (Test-Path -LiteralPath $PublicEntryPath)) {
        throw "Authenticated public entry is missing: $PublicEntryPath"
    }
    # Keep startup independent from PowerShell module auto-loading. Some npm-launched
    # Windows PowerShell processes cannot resolve Get-FileHash even though the
    # Microsoft.PowerShell.Utility module is installed.
    $publicDigest = Get-Sha256FileDigest -LiteralPath $PublicEntryPath
    return [pscustomobject]@{
        RepoRealPath = $RepoRoot
        Head = $head
        DirtyState = $dirtyState
        PublicEntrySha256 = $publicDigest
    }
}

if (($BackendPort + $PhpWorkerCount - 1) -gt 65535) {
    throw "PHP worker port range exceeds 65535."
}

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

$PhpRuntimeArgs = @(
    "-d", "realpath_cache_size=4096K",
    "-d", "realpath_cache_ttl=600"
)
$PhpDir = Split-Path -Parent $PhpExe
$PhpOpcacheDll = Resolve-FirstExisting @(
    (Join-Path $PhpDir "ext\php_opcache.dll"),
    "C:\xampp\php\ext\php_opcache.dll",
    "D:\xampp\php\ext\php_opcache.dll"
)
if ($PhpOpcacheDll) {
    $PhpRuntimeArgs += @(
        "-d", "zend_extension=$PhpOpcacheDll",
        "-d", "opcache.enable_cli=1",
        "-d", "opcache.memory_consumption=128",
        "-d", "opcache.max_accelerated_files=20000",
        "-d", "opcache.validate_timestamps=1",
        "-d", "opcache.revalidate_freq=2"
    )
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
        throw "Database '$DbName' was not found. Run php scripts/init_database.php first."
    }

    $coreTableSql = @"
SELECT COUNT(*)
FROM information_schema.TABLES
WHERE TABLE_SCHEMA='$DbName'
  AND TABLE_NAME IN ('users','roles','hotels','daily_reports','system_config');
"@
    $coreTableCount = [int]((Invoke-MySql $coreTableSql | Select-Object -First 1) -as [int])
    if ($coreTableCount -ne 5) {
        throw "Database '$DbName' is missing core tables. Initialize a fresh database with php scripts/init_database.php."
    }

    Write-Host "[OK] Database '$DbName' and core tables are ready"
}

function Assert-DatabaseVersion {
    # Keep the version probe aligned with the explicit startup parameters and
    # pass credentials through the child environment rather than command args.
    $env:DB_TYPE = "mysql"
    $env:DB_HOST = $DbHost
    $env:DB_PORT = [string]$DbPort
    $env:DB_NAME = $DbName
    $env:DB_USER = $DbUser
    $env:DB_PASS = $DbPass

    $schemaArgs = $PhpRuntimeArgs + @("think", "db:check")
    $schemaOutput = & $PhpExe @schemaArgs 2>&1
    $schemaExitCode = $LASTEXITCODE
    foreach ($line in $schemaOutput) {
        Write-Host $line
    }
    if ($schemaExitCode -ne 0) {
        if ($schemaExitCode -eq 2) {
            throw "Database schema upgrade required. Follow the command shown above before starting SUXIOS."
        }
        throw "Database schema check failed. Fix the connection or checker error shown above before starting SUXIOS."
    }
}

function Invoke-OtaRetentionPreview {
    Write-Host "[INFO] Previewing 30-day OTA credential/Profile retention (read-only)..."
    $maintenanceArgs = $PhpRuntimeArgs + @(
        "think",
        "online-data:cleanup-dormant-profiles",
        "--retention-days=30",
        "--dry-run"
    )
    $maintenanceOutput = & $PhpExe @maintenanceArgs 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "OTA retention preview did not complete; credentials and Profiles were kept unchanged."
        return
    }

    $summaryLine = $maintenanceOutput | Select-Object -Last 1
    try {
        $summary = $summaryLine | ConvertFrom-Json
        Write-Host (
            "[OK] OTA retention preview: profiles eligible={0}, credentials eligible={1}, errors={2}" -f `
                [int]$summary.profiles_expired,
                [int]$summary.credentials_expired,
                [int]$summary.errors
        )
    } catch {
        Write-Host "[OK] OTA retention preview completed"
    }
}

function Test-SuxiosHealthPayload {
    param([string]$Content)

    try {
        $payload = $Content | ConvertFrom-Json -ErrorAction Stop
        return [string]$payload.status -eq "ok" `
            -and [string]$payload.checks.application -eq "ok" `
            -and [string]$payload.checks.database -eq "ok" `
            -and [string]$payload.checks.database_schema -eq "ok"
    } catch {
        return $false
    }
}

function Test-HttpHealth {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $HealthUrl -TimeoutSec 2
        $reportedWorkerCount = [int]([string]$response.Headers["X-SUXIOS-Backend-Pool-Size"] -as [int])
        return $response.StatusCode -eq 200 `
            -and (Test-SuxiosHealthPayload -Content $response.Content) `
            -and $reportedWorkerCount -eq $PhpWorkerCount
    } catch {
        return $false
    }
}

function Test-BackendHttpHealth {
    param([int]$TargetPort)

    $targetHealthUrl = "http://$BindHost`:$TargetPort$HealthPath"
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $targetHealthUrl -TimeoutSec 2
        return $response.StatusCode -eq 200 -and (Test-SuxiosHealthPayload -Content $response.Content)
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

function Test-RuntimeIdentity {
    $client = $null
    $sha = $null
    try {
        $client = New-Object System.Net.WebClient
        $bytes = $client.DownloadData("${BaseUrl}app-main.min.js?v=startup-identity-probe")
        $sha = [System.Security.Cryptography.SHA256]::Create()
        $actualDigest = ([System.BitConverter]::ToString($sha.ComputeHash($bytes))).Replace("-", "").ToLowerInvariant()
        return $actualDigest -eq $ProjectIdentity.PublicEntrySha256
    } catch {
        return $false
    } finally {
        if ($sha) { $sha.Dispose() }
        if ($client) { $client.Dispose() }
    }
}

function Test-PortListening {
    param([int]$TargetPort)

    $listener = Get-NetTCPConnection -LocalPort $TargetPort -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
    return $null -ne $listener
}

function Start-ThinkPhp {
    if ((Test-HttpHealth) -and (Test-StaticAsset)) {
        if (-not (Test-RuntimeIdentity)) {
            throw "Local origin on $BaseUrl is healthy but serves a different public/app-main.min.js digest. Stop the stale stack before continuing."
        }
        $unhealthyExistingPorts = @($BackendPorts | Where-Object { -not (Test-BackendHttpHealth -TargetPort $_) })
        if ($unhealthyExistingPorts.Count -eq 0) {
            Write-Host "[OK] ThinkPHP worker pool is already serving $BaseUrl ($PhpWorkerCount workers)"
            return
        }
        throw "Local origin is already running, but configured PHP workers are not all healthy: $($unhealthyExistingPorts -join ', '). Restart the local stack to apply the worker pool."
    }

    if (Test-PortListening -TargetPort $Port) {
        throw "Port $Port is already in use, but $HealthUrl did not pass."
    }

    foreach ($workerPort in $BackendPorts) {
        if (-not (Test-BackendHttpHealth -TargetPort $workerPort) -and (Test-PortListening -TargetPort $workerPort)) {
            throw "Backend port $workerPort is already in use, but its $HealthPath check did not pass."
        }
    }

    foreach ($workerPort in $BackendPorts) {
        if (Test-BackendHttpHealth -TargetPort $workerPort) {
            Write-Host "[OK] ThinkPHP worker is already serving http://$BindHost`:$workerPort/"
            continue
        }

        $backendStdout = Join-Path $LogDir "think-backend-$workerPort.out.log"
        $backendStderr = Join-Path $LogDir "think-backend-$workerPort.err.log"

        Write-Host "[INFO] Starting hidden ThinkPHP worker on http://$BindHost`:$workerPort/"
        Start-Process `
            -FilePath $PhpExe `
            -ArgumentList ($PhpRuntimeArgs + @("-S", "$BindHost`:$workerPort", "-t", "public", "public/router.php")) `
            -WorkingDirectory $RepoRoot `
            -WindowStyle Hidden `
            -RedirectStandardOutput $backendStdout `
            -RedirectStandardError $backendStderr `
            | Out-Null

    }

    for ($i = 0; $i -lt $PhpWaitSeconds; $i++) {
        $unhealthyPorts = @($BackendPorts | Where-Object { -not (Test-BackendHttpHealth -TargetPort $_) })
        if ($unhealthyPorts.Count -eq 0) { break }
        Start-Sleep -Seconds 1
    }
    $unhealthyPorts = @($BackendPorts | Where-Object { -not (Test-BackendHttpHealth -TargetPort $_) })
    if ($unhealthyPorts.Count -gt 0) {
        throw "ThinkPHP workers did not all become healthy within $PhpWaitSeconds seconds: $($unhealthyPorts -join ', ')."
    }
    foreach ($workerPort in $BackendPorts) {
        Write-Host "[OK] ThinkPHP worker healthy: http://$BindHost`:$workerPort/"
    }

    $originStdout = Join-Path $LogDir "local-origin-$Port.out.log"
    $originStderr = Join-Path $LogDir "local-origin-$Port.err.log"
    Write-Host "[INFO] Starting hidden concurrent local origin on $BaseUrl"
    Start-Process `
        -FilePath $NodeExe `
        -ArgumentList @(
            $OriginServerPath,
            "--host=$BindHost",
            "--port=$Port",
            "--backends=$($BackendUrls -join ',')",
            "--public-root=$PublicRoot"
        ) `
        -WorkingDirectory $RepoRoot `
        -WindowStyle Hidden `
        -RedirectStandardOutput $originStdout `
        -RedirectStandardError $originStderr `
        | Out-Null

    for ($i = 0; $i -lt $PhpWaitSeconds; $i++) {
        if ((Test-HttpHealth) -and (Test-StaticAsset) -and (Test-RuntimeIdentity)) {
            Write-Host "[OK] Concurrent local origin started: $BaseUrl"
            return
        }
        Start-Sleep -Seconds 1
    }

    throw "Concurrent local origin did not become healthy at $HealthUrl with static assets available within $PhpWaitSeconds seconds."
}

function Start-WecomAibot {
    $allowedKeys = @(
        "SUXIOS_WECOM_AIBOT_ID",
        "SUXIOS_WECOM_AIBOT_SECRET",
        "SUXIOS_WECOM_AIBOT_RELAY_TOKEN",
        "SUXIOS_LOCAL_API_BASE"
    )
    $projectEnvPath = Join-Path $RepoRoot ".env"
    if (Test-Path -LiteralPath $projectEnvPath) {
        foreach ($line in Get-Content -LiteralPath $projectEnvPath) {
            $trimmed = [string]$line
            $trimmed = $trimmed.Trim()
            if ([string]::IsNullOrWhiteSpace($trimmed) -or $trimmed.StartsWith("#")) { continue }
            $separator = $trimmed.IndexOf("=")
            if ($separator -le 0) { continue }
            $key = $trimmed.Substring(0, $separator).Trim()
            if ($allowedKeys -notcontains $key) { continue }
            $existingValue = [Environment]::GetEnvironmentVariable($key, "Process")
            if (-not [string]::IsNullOrWhiteSpace($existingValue)) { continue }
            $value = $trimmed.Substring($separator + 1).Trim()
            if ($value.Length -ge 2 `
                -and (($value.StartsWith('"') -and $value.EndsWith('"')) `
                    -or ($value.StartsWith("'") -and $value.EndsWith("'")))) {
                $value = $value.Substring(1, $value.Length - 2)
            }
            [Environment]::SetEnvironmentVariable($key, $value, "Process")
        }
    }
    $botId = [string]$env:SUXIOS_WECOM_AIBOT_ID
    $botSecret = [string]$env:SUXIOS_WECOM_AIBOT_SECRET
    $relayToken = [string]$env:SUXIOS_WECOM_AIBOT_RELAY_TOKEN
    if ([string]::IsNullOrWhiteSpace($botId) `
        -or [string]::IsNullOrWhiteSpace($botSecret) `
        -or $relayToken.Length -lt 32) {
        Write-Host "[SKIP] WeCom AI Bot agent is not configured; local SUXIOS remains available."
        return
    }
    if (-not (Test-Path -LiteralPath $WecomAibotWorkerPath)) {
        Write-Warning "WeCom AI Bot worker script is missing; local SUXIOS remains available."
        return
    }

    $statePath = Join-Path $RepoRoot "runtime\wecom-aibot-state.json"
    if (Test-Path -LiteralPath $statePath) {
        try {
            $state = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json -ErrorAction Stop
            $workerProcess = Get-Process -Id ([int]$state.pid) -ErrorAction SilentlyContinue
            if ($workerProcess -and $workerProcess.ProcessName -match '^node') {
                $workerCommand = Get-CimInstance Win32_Process -Filter "ProcessId = $([int]$state.pid)" -ErrorAction SilentlyContinue
                if ([string]$workerCommand.CommandLine -like '*wecom_aibot_worker.mjs*') {
                    Write-Host "[OK] WeCom AI Bot agent process is already running (state: $([string]$state.status))."
                    return
                }
            }
        } catch {
            Write-Host "[INFO] Existing WeCom AI Bot state is stale; starting a new worker."
        }
    }

    $env:SUXIOS_LOCAL_API_BASE = $BaseUrl.TrimEnd('/')
    $stdout = Join-Path $LogDir "wecom-aibot.out.log"
    $stderr = Join-Path $LogDir "wecom-aibot.err.log"
    Write-Host "[INFO] Starting hidden WeCom AI Bot WebSocket agent."
    Start-Process `
        -FilePath $NodeExe `
        -ArgumentList @($WecomAibotWorkerPath) `
        -WorkingDirectory $RepoRoot `
        -WindowStyle Hidden `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr `
        | Out-Null
}

if (-not (Test-Path (Join-Path $RepoRoot "think"))) {
    throw "Current folder is not the ThinkPHP project root."
}

Start-LocalMySql
Assert-DatabaseReady
Assert-DatabaseVersion
if ($DatabaseOnly) {
    Write-Host "[DONE] Database runtime ready on $DbHost`:$DbPort with schema '$DbName'"
    return
}
$ProjectIdentity = Get-ProjectIdentity
Write-Host "[IDENTITY] repo=$($ProjectIdentity.RepoRealPath) head=$($ProjectIdentity.Head) worktree=$($ProjectIdentity.DirtyState) public_app_main_sha256=$($ProjectIdentity.PublicEntrySha256)"
$NodeExe = Resolve-CommandSource "node"
if (-not $NodeExe) {
    throw "Node.js was not found. Install Node.js or add node.exe to PATH."
}
if (-not (Test-Path -LiteralPath $OriginServerPath)) {
    throw "Concurrent local origin server is missing: $OriginServerPath"
}
Invoke-OtaRetentionPreview
Start-ThinkPhp
Start-WecomAibot

if (-not $NoBrowser) {
    Start-Process $BaseUrl | Out-Null
}

Write-Host "[DONE] Local stack ready: $BaseUrl identity=$($ProjectIdentity.Head):$($ProjectIdentity.PublicEntrySha256)"
