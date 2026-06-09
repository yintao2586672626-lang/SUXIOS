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

$candidatePaths = @(
  "output",
  "runtime",
  "test-results",
  ".pytest_cache",
  ".gstack"
)

if (Test-Path -LiteralPath "storage") {
  $candidatePaths += Get-ChildItem -LiteralPath "storage" -Force -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -like "ctrip_profile_*" -or $_.Name -like "meituan_profile_*" } |
    ForEach-Object { $_.FullName }
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
foreach ($target in ($rows | Sort-Object mb -Descending | ForEach-Object { Join-Path $workspace $_.path })) {
  try {
    if (Test-Path -LiteralPath $target -PathType Container) {
      Get-ChildItem -LiteralPath $target -Recurse -Force -File -ErrorAction SilentlyContinue | ForEach-Object {
        try {
          $_.IsReadOnly = $false
        } catch {
          # Best-effort cleanup; failures are reported below.
        }
      }
    } elseif (Test-Path -LiteralPath $target -PathType Leaf) {
      try {
        (Get-Item -LiteralPath $target -Force).IsReadOnly = $false
      } catch {
        # Best-effort cleanup; failures are reported below.
      }
    }

    Remove-Item -LiteralPath $target -Recurse -Force -ErrorAction Stop
    $removed += 1
  } catch {
    $failed += [pscustomobject]@{
      path = $target.Substring($workspace.Length + 1)
      error = $_.Exception.Message
    }
  }
}

Write-Host "Removed $removed local artifact target(s)."
if ($failed.Count -gt 0) {
  Write-Warning "Some targets could not be removed:"
  $failed | Format-Table -AutoSize
  exit 1
}
