param(
  [string]$OutputPath = "",
  [string]$Reviewer = $env:RELEASE_REVIEWER,
  [string]$PrNumber = $env:RELEASE_PR_NUMBER
)

if ([string]::IsNullOrWhiteSpace($Reviewer)) {
  $Reviewer = $env:USERNAME
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

function Invoke-NativeCommand {
  param(
    [Parameter(Mandatory = $true)][string]$Command,
    [Parameter(Mandatory = $true)][string[]]$Arguments
  )

  $commandLine = "$Command $($Arguments -join ' ')"
  try {
    $startInfo = [System.Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = $Command
    $startInfo.Arguments = (($Arguments | ForEach-Object {
      $argument = [string]$_
      if ($argument -match '[\s"]') {
        '"' + ($argument -replace '"', '\"') + '"'
      } else {
        $argument
      }
    }) -join ' ')
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true

    $process = [System.Diagnostics.Process]::Start($startInfo)
    $stdout = $process.StandardOutput.ReadToEnd()
    $stderr = $process.StandardError.ReadToEnd()
    $process.WaitForExit()

    [pscustomobject]@{
      command = $commandLine
      exit_code = $process.ExitCode
      stdout = $stdout.Trim()
      stderr = $stderr.Trim()
    }
  } catch {
    [pscustomobject]@{
      command = $commandLine
      exit_code = 1
      stdout = ""
      stderr = $_.Exception.Message
    }
  }
}

$gitBackups = Invoke-ExternalCommand -Command "git" -Arguments @("ls-files", "database/backups")
$gitStatus = Invoke-ExternalCommand -Command "git" -Arguments @("status", "--short", "--branch")
$gitHead = Invoke-ExternalCommand -Command "git" -Arguments @("rev-parse", "HEAD")
$gitIndexLockPath = Join-Path (Get-Location) ".git/index.lock"
$gitIndexLockItem = Get-Item -LiteralPath $gitIndexLockPath -ErrorAction SilentlyContinue

$prView = $null
if ([string]::IsNullOrWhiteSpace($PrNumber)) {
  $prView = [pscustomobject]@{
    command = "gh pr view <missing-release-pr-number> --json number,url,state,isDraft,headRefOid,mergeable,statusCheckRollup"
    exit_code = 1
    stdout = ""
    stderr = "RELEASE_PR_NUMBER is required. Run npm run review:release-pr-candidates and set RELEASE_PR_NUMBER to the selected open final release PR before collecting external-state evidence."
  }
} else {
  $prArgs = @("pr", "view", $PrNumber, "--json", "number,url,state,isDraft,headRefOid,mergeable,statusCheckRollup")
  $prView = Invoke-NativeCommand -Command "gh" -Arguments $prArgs
}
$prJson = $null
if ($prView.exit_code -eq 0) {
  try {
    $prJson = ($prView.stdout | ConvertFrom-Json)
  } catch {
    $prJson = $null
  }
}

$evidence = [ordered]@{
  reviewed_at = (Get-Date -Format "yyyy-MM-ddTHH:mm:ssK")
  reviewer = $Reviewer
  target_release_pr_number = $(if ([string]::IsNullOrWhiteSpace($PrNumber)) { $null } else { [string]$PrNumber })
  commands = [ordered]@{
    git_ls_files_database_backups = $gitBackups
    git_index_lock = [pscustomobject]@{
      path = ".git/index.lock"
      exists = [bool]$gitIndexLockItem
      length = $(if ($gitIndexLockItem) { $gitIndexLockItem.Length } else { $null })
      last_write_time = $(if ($gitIndexLockItem) { $gitIndexLockItem.LastWriteTime.ToString("yyyy-MM-ddTHH:mm:ssK") } else { $null })
    }
    git_rev_parse_head = $gitHead
    git_status_short_branch = $gitStatus
    gh_pr_view = [pscustomobject]@{
      command = $prView.command
      exit_code = $prView.exit_code
      json = $prJson
      stdout = $(if ($prView.exit_code -eq 0) { "" } else { $prView.stdout })
      stderr = $prView.stderr
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
