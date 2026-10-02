param(
  [switch]$Apply,
  [switch]$IncludeDependencies,
  [switch]$IncludeSensitiveBackups,
  [switch]$IncludeCaptureAssets
)

$ErrorActionPreference = "Stop"

$workspace = (Resolve-Path ".").Path

if ($IncludeSensitiveBackups) {
  throw "Financial and database recovery backups require a separate, reviewed retention operation."
}

function Resolve-InWorkspace {
  param([Parameter(Mandatory = $true)][string]$Path)

  if (!(Test-Path -LiteralPath $Path)) {
    return $null
  }

  $resolved = (Resolve-Path -LiteralPath $Path).Path
  if ($resolved -eq $workspace -or !$resolved.StartsWith($workspace + [IO.Path]::DirectorySeparatorChar)) {
    throw "Refusing path outside workspace: $resolved"
  }

  Assert-NoReparsePoints -Path $resolved
  return $resolved
}

function Measure-Target {
  param([Parameter(Mandatory = $true)][string]$Path)

  $files = @()
  if (Test-Path -LiteralPath $Path -PathType Container) {
    $files = Get-ChildItem -LiteralPath $Path -Recurse -Force -File -ErrorAction SilentlyContinue
  } elseif (Test-Path -LiteralPath $Path -PathType Leaf) {
    $files = @(Get-Item -LiteralPath $Path -Force)
  }

  $bytes = ($files | Measure-Object Length -Sum).Sum
  if ($null -eq $bytes) {
    $bytes = 0
  }

  [pscustomobject]@{
    path = $Path.Substring($workspace.Length + 1)
    files = $files.Count
    mb = [math]::Round($bytes / 1MB, 2)
  }
}

function Remove-TargetBestEffort {
  param([Parameter(Mandatory = $true)][string]$Path)

  $failures = @()

  if (Test-Path -LiteralPath $Path -PathType Container) {
    $files = Get-ChildItem -LiteralPath $Path -Recurse -Force -File -ErrorAction SilentlyContinue |
      Sort-Object FullName -Descending
    foreach ($file in $files) {
      try {
        $file.IsReadOnly = $false
      } catch {
        # Best-effort cleanup; deletion failure below is the actionable signal.
      }
      try {
        Remove-Item -LiteralPath $file.FullName -Force -ErrorAction Stop
      } catch {
        $failures += [pscustomobject]@{
          path = $file.FullName.Substring($workspace.Length + 1)
          error = $_.Exception.Message
        }
      }
    }

    $dirs = Get-ChildItem -LiteralPath $Path -Recurse -Force -Directory -ErrorAction SilentlyContinue |
      Sort-Object FullName -Descending
    foreach ($dir in $dirs) {
      try {
        Remove-Item -LiteralPath $dir.FullName -Force -ErrorAction Stop
      } catch {
        # Non-empty directories are expected when a child file is locked.
      }
    }

    try {
      Remove-Item -LiteralPath $Path -Force -ErrorAction Stop
    } catch {
      # Keep the target directory when a running process still owns a child file.
    }
  } elseif (Test-Path -LiteralPath $Path -PathType Leaf) {
    try {
      (Get-Item -LiteralPath $Path -Force).IsReadOnly = $false
    } catch {
      # Best-effort cleanup; deletion failure below is the actionable signal.
    }
    try {
      Remove-Item -LiteralPath $Path -Force -ErrorAction Stop
    } catch {
      $failures += [pscustomobject]@{
        path = $Path.Substring($workspace.Length + 1)
        error = $_.Exception.Message
      }
    }
  }

  return $failures
}

function Assert-NoReparsePoints {
  param([Parameter(Mandatory = $true)][string]$Path)
  $cursor = Get-Item -LiteralPath $Path -Force
  while ($true) {
    if (($cursor.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
      throw "Refusing linked cleanup path: $($cursor.FullName)"
    }
    $parentPath = [IO.Path]::GetDirectoryName($cursor.FullName)
    if ([string]::IsNullOrEmpty($parentPath)) { break }
    $cursor = Get-Item -LiteralPath $parentPath -Force
  }
  $pending = New-Object 'System.Collections.Generic.Stack[string]'
  $pending.Push($Path)
  while ($pending.Count -gt 0) {
    $current = Get-Item -LiteralPath $pending.Pop() -Force
    if (($current.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
      throw "Refusing linked cleanup entry: $($current.FullName)"
    }
    if ($current.PSIsContainer) {
      foreach ($child in Get-ChildItem -LiteralPath $current.FullName -Force) {
        $pending.Push($child.FullName)
      }
    }
  }
}

$candidatePaths = @(
  "test-results",
  ".pytest_cache",
  ".gstack"
)

# Runtime defaults to durable/unknown state. Only these explicitly disposable
# cache/build targets may be removed by the generic local cleaner.
$runtimeCleanupNames = @(
  "cache",
  "static-gzip",
  "static-html",
  "log",
  "codex-runner-contract",
  "test_ctrip_mapping"
)
foreach ($runtimeName in $runtimeCleanupNames) {
  $runtimeCandidate = Join-Path "runtime" $runtimeName
  if (Test-Path -LiteralPath $runtimeCandidate) {
    $candidatePaths += $runtimeCandidate
  }
}

# Browser profiles, capture evidence, output recovery packs and handoffs are durable.
if (Test-Path -LiteralPath "reports") {
  # Screenshot assets may still be referenced by retained evidence JSON. Keep
  # them by default and require an explicit opt-in for irreversible removal.
  if ($IncludeCaptureAssets) {
    foreach ($assetDir in @("reports/ctrip_capture_assets", "reports/meituan_capture_assets")) {
      $candidatePaths += $assetDir
    }
  }

}

if ($IncludeDependencies) {
  $candidatePaths += @("node_modules", "vendor")
}


$targets = @()
foreach ($candidate in $candidatePaths) {
  $resolved = Resolve-InWorkspace -Path $candidate
  if ($null -ne $resolved) {
    $targets += $resolved
  }
}
$targets = $targets | Sort-Object -Unique

$rows = @()
foreach ($target in $targets) {
  $rows += Measure-Target -Path $target
}

$totalMb = [math]::Round((($rows | Measure-Object mb -Sum).Sum), 2)
$mode = if ($Apply) { "apply" } else { "dry-run" }

Write-Host "Project slimming mode: $mode"
Write-Host "Workspace: $workspace"
Write-Host "Target count: $($targets.Count)"
Write-Host "Estimated reclaim: $totalMb MB"
$rows | Sort-Object mb -Descending | Format-Table -AutoSize

if (!$Apply) {
  Write-Host "No files removed. Re-run with -Apply to clean the listed local artifacts."
  exit 0
}

$removed = 0
$failed = @()
$skippedLocked = @()
foreach ($target in ($rows | Sort-Object mb -Descending | ForEach-Object { Join-Path $workspace $_.path })) {
  try {
    $targetFailures = Remove-TargetBestEffort -Path $target
    if ((Test-Path -LiteralPath $target)) {
      $remaining = Measure-Target -Path $target
      if ($remaining.files -gt 0 -and $remaining.mb -gt 1) {
        $failed += [pscustomobject]@{
          path = $target.Substring($workspace.Length + 1)
          error = "Residual artifact size remains $($remaining.mb) MB after best-effort cleanup."
        }
        $failed += $targetFailures
      } elseif ($targetFailures.Count -gt 0) {
        $skippedLocked += $targetFailures
      }
    } else {
      $removed += 1
    }
  } catch {
    $failed += [pscustomobject]@{
      path = $target.Substring($workspace.Length + 1)
      error = $_.Exception.Message
    }
  }
}

Write-Host "Removed $removed local artifact target(s)."
if ($skippedLocked.Count -gt 0) {
  Write-Warning "Some near-zero-size local artifact files were left in place, likely because a local dev process is still using them:"
  $skippedLocked | Format-Table -AutoSize
}
if ($failed.Count -gt 0) {
  Write-Warning "Some targets could not be removed:"
  $failed | Format-Table -AutoSize
  exit 1
}
