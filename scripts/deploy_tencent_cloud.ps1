[CmdletBinding()]
param(
    [string]$Server = "122.51.64.165",
    [string]$User = "ubuntu",
    [string]$KeyPath = "C:\Users\Administrator\.ssh\suxios-lighthouse-shanghai.pem",
    [string]$KnownHostsPath = "",
    [string]$ReleaseName = "suxios-auto-$((Get-Date).ToString('yyyyMMdd-HHmmss'))",
    [switch]$StageOnly,
    [switch]$ApplyMigrations,
    [switch]$AllowDirty
)

$ErrorActionPreference = "Stop"
$hotelRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$remoteInstaller = Join-Path $hotelRoot "deploy\cloud\install_release.sh"
$ssh = "C:\windows\System32\OpenSSH\ssh.exe"
$scp = "C:\windows\System32\OpenSSH\scp.exe"

if ($Server -notmatch '^[A-Za-z0-9.-]+$' -or $User -notmatch '^[a-z_][a-z0-9_-]*$') {
    throw 'Server or SSH user contains unsupported characters.'
}
if ([string]::IsNullOrWhiteSpace($KnownHostsPath)) {
    $KnownHostsPath = Join-Path (Split-Path -Parent $KeyPath) 'known_hosts'
}

function Test-ForbiddenArchiveEntry {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Entry
    )

    $normalized = $Entry.Replace('\', '/') -replace '^\./', ''
    $normalized = $normalized.TrimStart('/')
    $forbiddenPrefixes = @(
        '.git',
        '.codex-tmp',
        '.playwright-cli',
        'database/backups',
        'node_modules',
        'vendor',
        'runtime',
        'storage',
        'reports',
        'test-results',
        'output'
    )
    $segments = @($normalized.Split('/') | Where-Object { $_ -ne '' })
    if ($segments | Where-Object {
        $_ -eq '.env' -or $_.StartsWith('.env.', [System.StringComparison]::OrdinalIgnoreCase)
    }) {
        return $true
    }
    $fileName = [System.IO.Path]::GetFileName($normalized)
    if (
        ($normalized -notmatch '/' -and $fileName -match '(?i)\.sql$') -or
        ($fileName -match '(?i)(dump|backup).*\.sql$') -or
        ($fileName -match '(?i)^(id_(rsa|dsa|ecdsa|ed25519)|.*\.(pem|pfx|p12|key))$')
    ) {
        return $true
    }
    foreach ($prefix in $forbiddenPrefixes) {
        if ($normalized -eq $prefix -or $normalized.StartsWith($prefix + '/', [System.StringComparison]::OrdinalIgnoreCase)) {
            return $true
        }
    }
    return $false
}

foreach ($requiredPath in @($KeyPath, $KnownHostsPath, $remoteInstaller, $ssh, $scp)) {
    if (-not (Test-Path -LiteralPath $requiredPath)) {
        throw "Required path is missing: $requiredPath"
    }
}

if ($ReleaseName -notmatch '^suxios-[a-z0-9][a-z0-9._-]{5,80}$') {
    throw "Invalid release name: $ReleaseName"
}

if ($ApplyMigrations) {
    throw "Automatic production migrations are disabled. Review and run schema changes through a separately verified migration procedure before deployment."
}

if ($AllowDirty) {
    throw "Dirty-worktree deployment is disabled. Commit the exact release content before staging or deployment."
}

$gitStatus = & git -C $hotelRoot status --porcelain
if ($LASTEXITCODE -ne 0) {
    throw "Unable to inspect the HOTEL worktree."
}
if ($gitStatus) {
    throw "The HOTEL worktree is dirty. Commit or stash it before staging or deployment."
}

$sourceCommit = (& git -C $hotelRoot rev-parse --verify HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $sourceCommit -notmatch '^[a-f0-9]{40}$') {
    throw "Unable to resolve the exact HOTEL release commit."
}

$deployTempRoot = Join-Path ([System.IO.Path]::GetTempPath()) "suxios-deploy-$ReleaseName"
$archivePath = Join-Path $deployTempRoot "$ReleaseName.tar.gz"
$remoteArchive = "/tmp/$ReleaseName.tar.gz"
$remoteInstallerPath = "/tmp/suxios-install-release-$ReleaseName.sh"
$target = "$User@$Server"
$sshOptions = @(
    "-i", $KeyPath,
    "-o", "BatchMode=yes",
    "-o", "ConnectTimeout=15",
    "-o", "ConnectionAttempts=1",
    "-o", "StrictHostKeyChecking=yes",
    "-o", "UserKnownHostsFile=$KnownHostsPath",
    "-o", "ServerAliveInterval=10",
    "-o", "ServerAliveCountMax=2"
)
$archiveUploaded = $false
$installerUploaded = $false

New-Item -ItemType Directory -Path $deployTempRoot | Out-Null

try {
    & git -C $hotelRoot archive --format=tar.gz --output=$archivePath $sourceCommit
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to build the release archive."
    }

    $archiveEntries = @(& tar.exe -tzf $archivePath)
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to inspect the release archive."
    }
    $forbiddenArchiveEntry = $archiveEntries |
        Where-Object { Test-ForbiddenArchiveEntry -Entry ([string]$_) } |
        Select-Object -First 1
    if ($null -ne $forbiddenArchiveEntry) {
        throw "Release archive contains a forbidden sensitive/runtime path. Upload was refused."
    }

    $sha256 = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()

    & $scp @sshOptions $archivePath "${target}:$remoteArchive"
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to upload the release archive."
    }
    $archiveUploaded = $true

    & $scp @sshOptions $remoteInstaller "${target}:$remoteInstallerPath"
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to upload the remote installer."
    }
    $installerUploaded = $true

    $remoteArgs = @(
        "sudo", "bash", $remoteInstallerPath,
        "--archive", $remoteArchive,
        "--release", $ReleaseName,
        "--sha256", $sha256,
        "--health-host", $Server
    )
    if ($StageOnly) {
        $remoteArgs += "--no-switch"
    }
    & $ssh @sshOptions $target ($remoteArgs -join " ")
    if ($LASTEXITCODE -ne 0) {
        throw "Remote release installation failed."
    }

    [PSCustomObject]@{
        Status = if ($StageOnly) { "staged" } else { "deployed" }
        Server = $Server
        Release = $ReleaseName
        SourceCommit = $sourceCommit
        Sha256 = $sha256
        MigrationsApplied = $false
    }
}
finally {
    $remoteCleanup = @()
    if ($archiveUploaded) {
        $remoteCleanup += $remoteArchive
    }
    if ($installerUploaded) {
        $remoteCleanup += $remoteInstallerPath
    }
    if ($remoteCleanup.Count -gt 0) {
        & $ssh @sshOptions $target ("rm -f -- " + ($remoteCleanup -join " ")) | Out-Null
    }
    if (Test-Path -LiteralPath $deployTempRoot) {
        Remove-Item -LiteralPath $deployTempRoot -Recurse -Force
    }
}
