[CmdletBinding()]
param(
    [string]$Server = '122.51.64.165',
    [string]$User = 'ubuntu',
    [string]$KeyPath = 'C:\Users\Administrator\.ssh\suxios-lighthouse-shanghai.pem',
    [string]$KnownHostsPath = '',
    [string]$Destination = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$hotelRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
if ([string]::IsNullOrWhiteSpace($Destination)) {
    $Destination = Join-Path (Split-Path -Parent $hotelRoot) '.private\cloud-backups'
}
if ($Server -notmatch '^[A-Za-z0-9.-]+$' -or $User -notmatch '^[a-z_][a-z0-9_-]*$') {
    throw 'Server or SSH user contains unsupported characters.'
}

$ssh = 'C:\Windows\System32\OpenSSH\ssh.exe'
$scp = 'C:\Windows\System32\OpenSSH\scp.exe'
if ([string]::IsNullOrWhiteSpace($KnownHostsPath)) {
    $KnownHostsPath = Join-Path (Split-Path -Parent $KeyPath) 'known_hosts'
}
foreach ($requiredPath in @($KeyPath, $KnownHostsPath, $ssh, $scp)) {
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
        throw "Required path is missing: $requiredPath"
    }
}

New-Item -ItemType Directory -Path $Destination -Force | Out-Null
$destinationRoot = (Resolve-Path -LiteralPath $Destination).Path
if ($destinationRoot.StartsWith($hotelRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Cloud database backups must be stored outside the HOTEL Git checkout.'
}

$target = "$User@$Server"
$commonSshOptions = @(
    '-i', $KeyPath,
    '-o', 'BatchMode=yes',
    '-o', 'ConnectTimeout=15',
    '-o', 'ConnectionAttempts=1',
    '-o', 'StrictHostKeyChecking=yes',
    '-o', "UserKnownHostsFile=$KnownHostsPath",
    '-o', 'ServerAliveInterval=10',
    '-o', 'ServerAliveCountMax=2'
)
$sshOptions = @('-n', '-T') + $commonSshOptions
$scpOptions = $commonSshOptions
$remoteBackupDir = '/var/backups/suxios/mysql'
$latestCommand = "sudo find $remoteBackupDir -maxdepth 1 -type f -name 'hotelx_cloud_*.sql.gz' -printf '%f\n' | sort | tail -n 1"
$latestOutput = @(& $ssh @sshOptions $target $latestCommand)
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to locate the newest cloud backup.'
}
$latest = [string]($latestOutput | Select-Object -Last 1)
$latest = $latest.Trim()
if ($latest -notmatch '^hotelx_cloud_[0-9]{8}-[0-9]{6}\.sql\.gz$') {
    throw "Cloud returned an invalid backup filename: $latest"
}

$remoteFile = "$remoteBackupDir/$latest"
$remoteChecksum = "$remoteFile.sha256"
$actualRemoteLine = [string](& $ssh @sshOptions $target "sudo sha256sum '$remoteFile'")
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to hash the newest cloud backup.'
}
$expectedRemoteLine = [string](& $ssh @sshOptions $target "sudo awk '{print `$1}' '$remoteChecksum'")
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to read the cloud backup checksum.'
}
$actualRemoteHash = ($actualRemoteLine.Trim() -split '\s+')[0].ToLowerInvariant()
$expectedRemoteHash = $expectedRemoteLine.Trim().ToLowerInvariant()
if ($actualRemoteHash -notmatch '^[a-f0-9]{64}$' -or $actualRemoteHash -ne $expectedRemoteHash) {
    throw 'Cloud backup checksum verification failed before transfer.'
}

$transferId = [System.Diagnostics.Process]::GetCurrentProcess().Id
$remoteStage = "/var/tmp/suxios-backup-export-$transferId"
$localStage = Join-Path $destinationRoot ('.incoming-' + [Guid]::NewGuid().ToString('N'))
$remoteStaged = $false

New-Item -ItemType Directory -Path $localStage | Out-Null
try {
    $stageCommand = "sudo install -d -o $User -g $User -m 0700 '$remoteStage' && sudo install -o $User -g $User -m 0600 '$remoteFile' '$remoteStage/$latest' && sudo install -o $User -g $User -m 0600 '$remoteChecksum' '$remoteStage/$latest.sha256'"
    & $ssh @sshOptions $target $stageCommand
    if ($LASTEXITCODE -ne 0) {
        throw 'Unable to create the controlled cloud export copy.'
    }
    $remoteStaged = $true

    & $scp @scpOptions "${target}:$remoteStage/$latest" $localStage
    if ($LASTEXITCODE -ne 0) {
        throw 'Unable to download the cloud database backup.'
    }
    & $scp @scpOptions "${target}:$remoteStage/$latest.sha256" $localStage
    if ($LASTEXITCODE -ne 0) {
        throw 'Unable to download the cloud backup checksum.'
    }

    $stagedFile = Join-Path $localStage $latest
    $stagedChecksum = Join-Path $localStage "$latest.sha256"
    $localHash = (Get-FileHash -LiteralPath $stagedFile -Algorithm SHA256).Hash.ToLowerInvariant()
    $localExpected = ((Get-Content -LiteralPath $stagedChecksum -Raw).Trim() -split '\s+')[0].ToLowerInvariant()
    if ($localHash -ne $actualRemoteHash -or $localHash -ne $localExpected) {
        throw 'Downloaded backup checksum verification failed.'
    }

    $finalFile = Join-Path $destinationRoot $latest
    $finalChecksum = Join-Path $destinationRoot "$latest.sha256"
    $finalChecksumExists = Test-Path -LiteralPath $finalChecksum -PathType Leaf
    if ($finalChecksumExists) {
        $finalExpected = ((Get-Content -LiteralPath $finalChecksum -Raw).Trim() -split '\s+')[0].ToLowerInvariant()
        if ($finalExpected -notmatch '^[a-f0-9]{64}$' -or $finalExpected -ne $localHash) {
            throw "The existing local checksum does not match the verified cloud backup: $finalChecksum"
        }
    }
    if (Test-Path -LiteralPath $finalFile) {
        $existingHash = (Get-FileHash -LiteralPath $finalFile -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($existingHash -ne $localHash) {
            throw "A different local file already exists: $finalFile"
        }
        if (-not $finalChecksumExists) {
            Move-Item -LiteralPath $stagedChecksum -Destination $finalChecksum
        }
        $result = 'already_current'
    } else {
        Move-Item -LiteralPath $stagedFile -Destination $finalFile
        if (-not $finalChecksumExists) {
            Move-Item -LiteralPath $stagedChecksum -Destination $finalChecksum
        }
        $result = 'downloaded'
    }

    if (-not (Test-Path -LiteralPath $finalChecksum -PathType Leaf)) {
        throw 'Verified backup checksum was not persisted locally.'
    }

    [pscustomobject]@{
        Status = $result
        Server = $Server
        Backup = $latest
        Destination = $destinationRoot
        Bytes = (Get-Item -LiteralPath $finalFile).Length
        Sha256 = $localHash
    }
}
finally {
    if ($remoteStaged) {
        & $ssh @sshOptions $target "sudo rm -f -- '$remoteStage/$latest' '$remoteStage/$latest.sha256'; sudo rmdir -- '$remoteStage' 2>/dev/null || true" | Out-Null
    }
    if (Test-Path -LiteralPath $localStage) {
        Remove-Item -LiteralPath $localStage -Recurse -Force
    }
}
