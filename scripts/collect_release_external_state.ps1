param(
  [string]$OutputPath = "",
  [string]$Reviewer = $env:RELEASE_REVIEWER,
  [string]$PrNumber = $env:RELEASE_PR_NUMBER
)

if ([string]::IsNullOrWhiteSpace($Reviewer)) {
  $Reviewer = $env:USERNAME
}

if ([string]::IsNullOrWhiteSpace($PrNumber)) {
  $PrNumber = "1"
}

function Invoke-ExternalCommand {
  param(
    [Parameter(Mandatory = $true)][string]$Command,
    [Parameter(Mandatory = $true)][string[]]$Arguments
  )

  $output = & $Command @Arguments 2>&1
  $exitCode = $LASTEXITCODE

  [pscustomobject]@{
    command = "$Command $($Arguments -join ' ')"
    exit_code = $exitCode
    stdout = (($output | Out-String).Trim())
  }
}

$gitBackups = Invoke-ExternalCommand -Command "git" -Arguments @("ls-files", "database/backups")
$gitStatus = Invoke-ExternalCommand -Command "git" -Arguments @("status", "--short", "--branch")
$gitIndexLockPath = Join-Path (Get-Location) ".git/index.lock"
$gitIndexLockItem = Get-Item -LiteralPath $gitIndexLockPath -ErrorAction SilentlyContinue

$prArgs = @("pr", "view", $PrNumber, "--json", "number,url,headRefOid,mergeable,statusCheckRollup")
$prOutput = & gh @prArgs 2>&1
$prExitCode = $LASTEXITCODE
$prJson = $null
if ($prExitCode -eq 0) {
  try {
    $prJson = ($prOutput | Out-String | ConvertFrom-Json)
  } catch {
    $prJson = $null
  }
}

$evidence = [ordered]@{
  reviewed_at = (Get-Date -Format "yyyy-MM-ddTHH:mm:ssK")
  reviewer = $Reviewer
  commands = [ordered]@{
    git_ls_files_database_backups = $gitBackups
    git_index_lock = [pscustomobject]@{
      path = ".git/index.lock"
      exists = [bool]$gitIndexLockItem
      length = $(if ($gitIndexLockItem) { $gitIndexLockItem.Length } else { $null })
      last_write_time = $(if ($gitIndexLockItem) { $gitIndexLockItem.LastWriteTime.ToString("yyyy-MM-ddTHH:mm:ssK") } else { $null })
    }
    git_status_short_branch = $gitStatus
    gh_pr_view = [pscustomobject]@{
      command = "gh $($prArgs -join ' ')"
      exit_code = $prExitCode
      json = $prJson
      stdout = $(if ($prExitCode -eq 0) { "" } else { (($prOutput | Out-String).Trim()) })
    }
  }
}

$json = $evidence | ConvertTo-Json -Depth 20

if ([string]::IsNullOrWhiteSpace($OutputPath)) {
  $json
} else {
  $resolved = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($OutputPath)
  $directory = Split-Path -Parent $resolved
  if (![string]::IsNullOrWhiteSpace($directory)) {
    New-Item -ItemType Directory -Force -Path $directory | Out-Null
  }
  $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
  [System.IO.File]::WriteAllText($resolved, "$json`n", $utf8NoBom)
  Write-Host "Wrote release external-state evidence to $resolved"
}
