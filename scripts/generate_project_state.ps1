param(
    [switch]$Write,
    [switch]$Check
)

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$outputRelativePath = 'vault/current-state.md'
$outputPath = Join-Path $repoRoot ($outputRelativePath -replace '/', '\')

function Invoke-Git {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$Arguments,
        [switch]$AllowFailure
    )

    $previousErrorActionPreference = $ErrorActionPreference
    try {
        # Windows PowerShell turns native stderr into ErrorRecord objects.
        # With the script-wide Stop preference that would throw before an
        # allowed non-zero Git result (for example, no configured upstream)
        # can be classified below.
        $ErrorActionPreference = 'Continue'
        $result = & git -C $repoRoot @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($exitCode -ne 0) {
        if ($AllowFailure) {
            return ''
        }
        throw "git $($Arguments -join ' ') failed: $($result -join [Environment]::NewLine)"
    }
    return (($result | Out-String).Trim())
}

function Get-FileCount {
    param(
        [string]$Path,
        [string]$Filter = '*'
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        return 0
    }
    return @(Get-ChildItem -LiteralPath $Path -Recurse -File -Filter $Filter).Count
}

function Get-ShanghaiTimestamp {
    $zone = [TimeZoneInfo]::FindSystemTimeZoneById('China Standard Time')
    $now = [TimeZoneInfo]::ConvertTimeFromUtc([DateTime]::UtcNow, $zone)
    return $now.ToString('yyyy-MM-ddTHH:mm:ss') + '+08:00'
}

$branch = Invoke-Git -Arguments @('branch', '--show-current')
if ([string]::IsNullOrWhiteSpace($branch)) {
    $branch = 'DETACHED'
}
$head = Invoke-Git -Arguments @('rev-parse', 'HEAD')
$headShort = Invoke-Git -Arguments @('rev-parse', '--short=12', 'HEAD')
$headSubject = Invoke-Git -Arguments @('log', '-1', '--pretty=%s')
$upstream = Invoke-Git -Arguments @('rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{upstream}') -AllowFailure
$ahead = $null
$behind = $null
if (-not [string]::IsNullOrWhiteSpace($upstream)) {
    $divergence = (Invoke-Git -Arguments @('rev-list', '--left-right', '--count', "$upstream...HEAD")) -split '\s+'
    $behind = [int]$divergence[0]
    $ahead = [int]$divergence[1]
}

$statusLines = @(& git -C $repoRoot status --porcelain=v1 --untracked-files=all)
if ($LASTEXITCODE -ne 0) {
    throw 'git status --porcelain=v1 --untracked-files=all failed.'
}
$statusLines = $statusLines | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
$entries = foreach ($line in $statusLines) {
    if ($line.Length -lt 4) {
        continue
    }
    $entryPath = $line.Substring(3).Replace('\', '/')
    [PSCustomObject]@{
        code = $line.Substring(0, 2)
        path = $entryPath
    }
}
$entries = @($entries)
$staged = @($entries | Where-Object { $_.code[0] -ne ' ' -and $_.code[0] -ne '?' }).Count
$unstaged = @($entries | Where-Object { $_.code[1] -ne ' ' -and $_.code[1] -ne '?' }).Count
$untracked = @($entries | Where-Object { $_.code -eq '??' }).Count
$trackedFiles = @((Invoke-Git -Arguments @('ls-files')) -split '\r?\n' | Where-Object { $_ -ne '' }).Count

$state = [ordered]@{
    branch = $branch
    head = $head
    head_short = $headShort
    head_subject = $headSubject
    upstream = if ($upstream) { $upstream } else { $null }
    ahead = $ahead
    behind = $behind
    worktree = [ordered]@{
        state = if ($entries.Count -eq 0) { 'clean' } else { 'dirty' }
        changed_paths = $entries.Count
        staged = $staged
        unstaged = $unstaged
        untracked = $untracked
    }
    index_lock = Test-Path -LiteralPath (Join-Path $repoRoot '.git\index.lock')
    counts = [ordered]@{
        tracked_files = $trackedFiles
        controllers = Get-FileCount -Path (Join-Path $repoRoot 'app\controller') -Filter '*.php'
        models = Get-FileCount -Path (Join-Path $repoRoot 'app\model') -Filter '*.php'
        migrations = Get-FileCount -Path (Join-Path $repoRoot 'database\migrations')
    }
}

# index.lock is a transient coordination file and can appear between refresh
# and check without changing the repository snapshot. Release evidence audits
# it separately, so exclude it from the durable fingerprint while still
# rendering its current state below.
$fingerprintState = [ordered]@{
    branch = $state.branch
    head = $state.head
    head_short = $state.head_short
    head_subject = $state.head_subject
    upstream = $state.upstream
    ahead = $state.ahead
    behind = $state.behind
    worktree = $state.worktree
    counts = $state.counts
}
$stateJson = $fingerprintState | ConvertTo-Json -Depth 5 -Compress
$stateBase64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($stateJson))
$sha256 = [Security.Cryptography.SHA256]::Create()
try {
    $fingerprint = [BitConverter]::ToString(
        $sha256.ComputeHash([Text.Encoding]::UTF8.GetBytes($stateJson))
    ).Replace('-', '').ToLowerInvariant()
} finally {
    $sha256.Dispose()
}

$upstreamState = if ($upstream) {
    "$upstream (ahead $ahead / behind $behind)"
} else {
    'not configured'
}
$worktreeState = if ($state.worktree.state -eq 'clean') {
    'clean'
} else {
    "dirty ($($state.worktree.changed_paths) paths: staged $staged / unstaged $unstaged / untracked $untracked)"
}
$indexLockState = if ($state.index_lock) { 'present' } else { 'absent' }

$rendered = @"
# SUXIOS Current Project State

> Machine generated; do not edit. Run ``npm run state:refresh`` to refresh and ``npm run state:check`` to verify that the snapshot still matches the checkout.
>
> This snapshot proves local repository facts only. It does not prove live OTA data, database contents, running services, external PR state, or release readiness.

Updated: $(Get-ShanghaiTimestamp)
Snapshot fingerprint: ``$fingerprint``
Snapshot state: ``$stateBase64``

## Repository

| Field | Current value |
|---|---|
| Repo root | ``HOTEL/`` |
| Branch | ``$branch`` |
| HEAD | ``$headShort`` - $headSubject |
| Upstream | $upstreamState |
| Worktree | $worktreeState |
| ``.git/index.lock`` | $indexLockState |

## Machine-counted structure

| Field | Count |
|---|---:|
| Git tracked files | $trackedFiles |
| PHP controllers | $($state.counts.controllers) |
| PHP models | $($state.counts.models) |
| Migration files | $($state.counts.migrations) |

## Truth boundaries

- Historical verified facts: ``vault/project-history.md``. Historical facts never override the current Git snapshot.
- Release blocker policy: ``docs/release_issue_register.md``. Rerun its acceptance commands; never infer release readiness from a historical PR or HEAD.
- Product chain: Ctrip/Meituan OTA data -> revenue analysis -> AI decisions -> operations management -> investment decisions. OTA channel facts are not whole-hotel operating facts.
"@

if ($Check) {
    if (-not (Test-Path -LiteralPath $outputPath)) {
        throw "$outputRelativePath is missing; run npm run state:refresh."
    }
    $existing = [IO.File]::ReadAllText($outputPath, [Text.Encoding]::UTF8)
    $match = [regex]::Match($existing, '(?m)^Snapshot fingerprint: `([a-f0-9]{64})`$')
    if (-not $match.Success -or $match.Groups[1].Value -ne $fingerprint) {
        $stateMatch = [regex]::Match($existing, '(?m)^Snapshot state: `([A-Za-z0-9+/=]+)`$')
        $previousState = if ($stateMatch.Success) {
            [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($stateMatch.Groups[1].Value))
        } else {
            'unavailable'
        }
        $previousFingerprint = if ($match.Success) { $match.Groups[1].Value } else { 'unavailable' }
        throw "$outputRelativePath is stale; run npm run state:refresh. expected=$previousFingerprint actual=$fingerprint previous_state=$previousState current_state=$stateJson"
    }
    Write-Output "Project state snapshot is current ($($fingerprint.Substring(0, 12)))."
    exit 0
}

if ($Write) {
    [IO.File]::WriteAllText($outputPath, $rendered + "`n", [Text.UTF8Encoding]::new($false))
    Write-Output "Wrote $outputRelativePath."
} else {
    Write-Output $rendered
}
