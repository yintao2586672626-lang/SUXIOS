param(
  [switch]$Apply,
  [switch]$IncludeDependencies,
  [switch]$IncludeSensitiveBackups
)

$ErrorActionPreference = "Stop"

$workspace = (Resolve-Path ".").Path

function Resolve-InWorkspace {
  param([Parameter(Mandatory = $true)][string]$Path)

  if (!(Test-Path -LiteralPath $Path)) {
    return $null
  }

  $resolved = (Resolve-Path -LiteralPath $Path).Path
  if (!($resolved -eq $workspace -or $resolved.StartsWith($workspace + [IO.Path]::DirectorySeparatorChar))) {
    throw "Refusing path outside workspace: $resolved"
  }

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

function Get-ProfileCacheTargets {
  param([Parameter(Mandatory = $true)][string]$StoragePath)

  $targets = @()
  if (!(Test-Path -LiteralPath $StoragePath -PathType Container)) {
    return $targets
  }

  $cacheRelativePaths = @(
    "Default\Cache",
    "Default\Code Cache",
    "Default\Service Worker\CacheStorage",
    "Default\Service Worker\ScriptCache",
    "Default\GPUCache",
    "Default\DawnGraphiteCache",
    "Default\DawnWebGPUCache",
    "GrShaderCache",
    "ShaderCache",
    "GraphiteDawnCache"
  )

  foreach ($profile in Get-ChildItem -LiteralPath $StoragePath -Force -Directory -ErrorAction SilentlyContinue) {
    if (!($profile.Name -like "ctrip_profile_*" -or $profile.Name -like "meituan_profile_*")) {
      continue
    }

    foreach ($relativePath in $cacheRelativePaths) {
      $candidate = Join-Path $profile.FullName $relativePath
      if (Test-Path -LiteralPath $candidate) {
        $targets += $candidate
      }
    }

    $targets += Get-ChildItem -LiteralPath $profile.FullName -Force -Recurse -File -Filter "BrowserMetrics*" -ErrorAction SilentlyContinue |
      ForEach-Object { $_.FullName }
  }

  return $targets
}

$candidatePaths = @(
  "output",
  "runtime",
  "test-results",
  ".pytest_cache",
  ".gstack"
)

if (Test-Path -LiteralPath "storage") {
  $candidatePaths += Get-ChildItem -LiteralPath "storage" -Force -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -like "ctrip_profile_phpunit*" -or $_.Name -like "meituan_profile_phpunit*" } |
    ForEach-Object { $_.FullName }
  $candidatePaths += Get-ProfileCacheTargets -StoragePath "storage"
  $candidatePaths += Get-ChildItem -LiteralPath "storage" -Force -File -Filter "*.log" -ErrorAction SilentlyContinue |
    ForEach-Object { $_.FullName }
}

if (Test-Path -LiteralPath "reports") {
  foreach ($assetDir in @("reports/ctrip_capture_assets", "reports/meituan_capture_assets")) {
    $candidatePaths += $assetDir
  }

  foreach ($pattern in @(
    "ctrip_browser_capture_*.json",
    "meituan_browser_capture_*.json",
    "ctrip_capture_target_*.json"
  )) {
    $candidatePaths += Get-ChildItem -LiteralPath "reports" -Force -File -Filter $pattern -ErrorAction SilentlyContinue |
      ForEach-Object { $_.FullName }
  }
}

if ($IncludeDependencies) {
  $candidatePaths += @("node_modules", "vendor")
}

if ($IncludeSensitiveBackups) {
  $candidatePaths += @("database/backups")
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
