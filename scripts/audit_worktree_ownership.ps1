[CmdletBinding()]
param(
    [string]$RepositoryPath = '',
    [datetime]$StaleBefore = (Get-Date).Date.AddDays(-30),
    [ValidateSet('Table', 'Json')]
    [string]$Format = 'Table',
    [switch]$IncludeCandidateSizes
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding

if ([string]::IsNullOrWhiteSpace($RepositoryPath)) {
    $RepositoryPath = Join-Path $PSScriptRoot '..'
}

function Invoke-GitText {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )

    $result = & git -C $Path @Arguments 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "git -C '$Path' $($Arguments -join ' ') failed."
    }

    return @($result)
}

function ConvertFrom-WorktreePorcelain {
    param([Parameter(Mandatory = $true)][string[]]$Lines)

    $entries = @()
    $current = $null
    foreach ($line in $Lines) {
        if ($line.StartsWith('worktree ')) {
            if ($null -ne $current) {
                $entries += [pscustomobject]$current
            }
            $current = [ordered]@{
                path = $line.Substring(9)
                head = $null
                branch = $null
                locked = $false
            }
        } elseif ($null -ne $current -and $line.StartsWith('HEAD ')) {
            $current.head = $line.Substring(5)
        } elseif ($null -ne $current -and $line.StartsWith('branch refs/heads/')) {
            $current.branch = $line.Substring(18)
        } elseif ($null -ne $current -and $line.StartsWith('locked')) {
            $current.locked = $true
        }
    }

    if ($null -ne $current) {
        $entries += [pscustomobject]$current
    }

    return $entries
}

if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    throw 'Git is required to inspect worktree state.'
}

$repository = (Resolve-Path -LiteralPath $RepositoryPath -ErrorAction Stop).Path
$topLevel = (Invoke-GitText -Path $repository -Arguments @('rev-parse', '--show-toplevel') | Select-Object -First 1).Trim()
$branchLines = @(Invoke-GitText -Path $repository -Arguments @('branch', '--show-current'))
$branch = if ($branchLines.Count -gt 0) { ([string]$branchLines[0]).Trim() } else { '' }
$head = (Invoke-GitText -Path $repository -Arguments @('rev-parse', 'HEAD') | Select-Object -First 1).Trim()
$activeStatus = @(Invoke-GitText -Path $repository -Arguments @('status', '--porcelain', '--untracked-files=all'))

$upstream = $null
try {
    $upstream = (Invoke-GitText -Path $repository -Arguments @('rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{upstream}') | Select-Object -First 1).Trim()
} catch {
    $upstream = $null
}

$worktreeLines = @(Invoke-GitText -Path $repository -Arguments @('worktree', 'list', '--porcelain') | Where-Object { $_ -ne '' })
$worktreeEntries = @(ConvertFrom-WorktreePorcelain -Lines $worktreeLines)
$rows = foreach ($entry in $worktreeEntries) {
    if (-not (Test-Path -LiteralPath $entry.path)) {
        [pscustomobject]@{
            path = $entry.path
            head = $entry.head
            branch = $entry.branch
            locked = [bool]$entry.locked
            present = $false
            head_date = $null
            changed_entries = $null
            reclaim_candidate = $false
            logical_mib = $null
        }
        continue
    }

    $changedEntries = @(Invoke-GitText -Path $entry.path -Arguments @('status', '--porcelain', '--untracked-files=all')).Count
    $headDate = (Invoke-GitText -Path $entry.path -Arguments @('show', '-s', '--format=%cs', $entry.head) | Select-Object -First 1).Trim()
    $candidate = $changedEntries -eq 0 -and -not $entry.locked -and ([datetime]$headDate -lt $StaleBefore.Date)
    $logicalMiB = $null
    if ($candidate -and $IncludeCandidateSizes) {
        $logicalMiB = [math]::Round(((Get-ChildItem -LiteralPath $entry.path -Recurse -File -Force -ErrorAction SilentlyContinue | Measure-Object -Property Length -Sum).Sum / 1MB), 1)
    }

    [pscustomobject]@{
        path = $entry.path
        head = $entry.head
        branch = $entry.branch
        locked = [bool]$entry.locked
        present = $true
        head_date = $headDate
        changed_entries = $changedEntries
        reclaim_candidate = $candidate
        logical_mib = $logicalMiB
    }
}

$dirtyWorktrees = @($rows | Where-Object { $_.present -and $_.changed_entries -gt 0 })
$candidates = @($rows | Where-Object reclaim_candidate | Sort-Object -Property @{ Expression = 'logical_mib'; Descending = $true }, path)
$activeDecision = if ($activeStatus.Count -gt 0) {
    'read_only_until_target_diff_ownership_is_confirmed'
} elseif ($upstream) {
    'write_allowed_after_target_scope_check'
} else {
    'write_allowed_after_target_scope_check_upstream_unverified'
}

$report = [ordered]@{
    generated_at = (Get-Date).ToString('o')
    repository = $topLevel
    active = [ordered]@{
        branch = $branch
        head = $head
        upstream = $upstream
        changed_entries = $activeStatus.Count
        decision = $activeDecision
    }
    worktrees = [ordered]@{
        registered = $worktreeEntries.Count
        present = @($rows | Where-Object present).Count
        dirty = $dirtyWorktrees.Count
        locked = @($rows | Where-Object locked).Count
        clean_unlocked = @($rows | Where-Object { $_.present -and $_.changed_entries -eq 0 -and -not $_.locked }).Count
        stale_clean_unlocked = $candidates.Count
        stale_clean_unlocked_logical_mib = if ($IncludeCandidateSizes) { [math]::Round((($candidates | Measure-Object -Property logical_mib -Sum).Sum), 1) } else { $null }
    }
    candidates = $candidates
}

if ($Format -eq 'Json') {
    $report | ConvertTo-Json -Depth 6
    exit 0
}

"Active: $($report.active.branch) @ $($report.active.head.Substring(0, 8))"
"Upstream: $(if ($report.active.upstream) { $report.active.upstream } else { 'unverified' })"
"Active changed entries: $($report.active.changed_entries)"
"Decision: $($report.active.decision)"
"Worktrees: $($report.worktrees.registered) registered; $($report.worktrees.dirty) dirty; $($report.worktrees.clean_unlocked) clean/unlocked; $($report.worktrees.stale_clean_unlocked) stale clean/unlocked candidates"
if ($IncludeCandidateSizes) {
    "Candidate logical size: $($report.worktrees.stale_clean_unlocked_logical_mib) MiB (working-tree file sum; actual freed disk can be lower)"
}
if ($candidates.Count -gt 0) {
    'Candidates:'
    $candidates | Select-Object path, branch, head_date, logical_mib | Format-Table -AutoSize
}
