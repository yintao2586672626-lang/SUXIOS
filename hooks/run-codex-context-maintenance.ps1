param(
    [switch] $Apply,
    [switch] $WaitForCodexExit,
    [switch] $ForceCloseCodex,
    [switch] $RestartCodex,
    [ValidateRange(5, 120)] [int] $CloseDelaySeconds = 20,
    [ValidateRange(1, 365)] [int] $ArchiveOlderThanDays = 10,
    [ValidateRange(1, 365)] [int] $WorktreeOlderThanDays = 7,
    [string] $PythonPath = '',
    [string] $CodexPath = '',
    [string] $StatusPath = 'C:\cb\codex-context-maintenance-status.json'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
[Console]::InputEncoding = [Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
$OutputEncoding = [Text.UTF8Encoding]::new($false)

$hotelRoot = Split-Path -Parent $PSScriptRoot
$workspaceRoot = Split-Path -Parent $hotelRoot
$userProfile = [Environment]::GetFolderPath('UserProfile')
$codexHome = Join-Path $userProfile '.codex'
$globalConfig = Join-Path $codexHome 'config.toml'
$keepFastScript = Join-Path $codexHome 'skills\keep-codex-fast\scripts\keep_codex_fast.py'
$globalSkillRoot = Join-Path $userProfile '.agents\skills'
$codexDesktopProcessNames = @('ChatGPT', 'codex', 'codex-code-mode-host')
$codexDesktopAppId = 'OpenAI.Codex_2p2nqsd0c76g0!App'

if ([string]::IsNullOrWhiteSpace($PythonPath)) {
    $PythonPath = (Get-Command python.exe -ErrorAction Stop).Source
}
if ([string]::IsNullOrWhiteSpace($CodexPath)) {
    $CodexPath = (Get-Command codex.exe -ErrorAction Stop).Source
}

function Write-JsonFile {
    param(
        [Parameter(Mandatory = $true)] [string] $Path,
        [Parameter(Mandatory = $true)] [object] $Value
    )

    $directory = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }

    $json = $Value | ConvertTo-Json -Depth 8
    [IO.File]::WriteAllText($Path, $json, [Text.UTF8Encoding]::new($false))
}

function Get-DedupeMappings {
    $mappings = [System.Collections.Generic.List[object]]::new()
    $routerAlias = Join-Path $globalSkillRoot '_gstack-command\SKILL.md'
    $routerCanonical = Join-Path $globalSkillRoot 'gstack\SKILL.md'

    if ((Test-Path -LiteralPath $routerAlias) -and (Test-Path -LiteralPath $routerCanonical)) {
        $mappings.Add([pscustomobject]@{
            disable = $routerAlias
            retain = $routerCanonical
            reason = 'duplicate_gstack_router'
        })
    }

    Get-ChildItem -LiteralPath $globalSkillRoot -Directory |
        Where-Object { $_.Name -like 'gstack-*' } |
        Sort-Object Name |
        ForEach-Object {
            $disable = Join-Path $_.FullName 'SKILL.md'
            if (-not (Test-Path -LiteralPath $disable)) {
                return
            }

            $shortName = $_.Name.Substring(7)
            $canonicalCandidates = @(
                (Join-Path $globalSkillRoot "gstack\$shortName\SKILL.md"),
                (Join-Path $globalSkillRoot "gstack\$($_.Name)\SKILL.md"),
                (Join-Path $globalSkillRoot "$shortName\SKILL.md")
            )
            $retain = $canonicalCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1

            if ($null -ne $retain) {
                $mappings.Add([pscustomobject]@{
                    disable = $disable
                    retain = $retain
                    reason = 'duplicate_gstack_alias'
                })
            }
        }

    $unique = @($mappings | Sort-Object disable -Unique)
    foreach ($mapping in $unique) {
        if (-not (Test-Path -LiteralPath $mapping.disable -PathType Leaf)) {
            throw "Dedupe source disappeared: $($mapping.disable)"
        }
        if (-not (Test-Path -LiteralPath $mapping.retain -PathType Leaf)) {
            throw "Canonical Skill missing: $($mapping.retain)"
        }
    }

    return $unique
}

function New-SkillsTomlBlock {
    param([Parameter(Mandatory = $true)] [array] $Mappings)

    $lines = [System.Collections.Generic.List[string]]::new()
    $lines.Add('[skills]')
    $lines.Add('config = [')
    foreach ($mapping in $Mappings) {
        $path = ([string] $mapping.disable).Replace('\', '/')
        $lines.Add("  { path = `"$path`", enabled = false },")
    }
    $lines.Add(']')
    return $lines -join "`n"
}

function Get-PromptSkillSummary {
    param([string] $Override = '')

    Push-Location -LiteralPath $workspaceRoot
    try {
        $arguments = [System.Collections.Generic.List[string]]::new()
        $arguments.Add('debug')
        $arguments.Add('prompt-input')
        if (-not [string]::IsNullOrWhiteSpace($Override)) {
            $arguments.Add('-c')
            $arguments.Add($Override)
        }
        $arguments.Add('SUXIOS_CONTEXT_MAINTENANCE_VERIFY')

        $raw = & $CodexPath @arguments 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw 'codex debug prompt-input failed'
        }

        $items = ($raw -join "`n") | ConvertFrom-Json
        $texts = foreach ($item in @($items)) {
            foreach ($content in @($item.content)) {
                [string] $content.text
            }
        }
        $skillText = @($texts | Where-Object { $_ -like '<skills_instructions>*' } | Select-Object -First 1)[0]
        return [pscustomobject]@{
            chars = $skillText.Length
            entries = ([regex]::Matches($skillText, '(?m)^- [^\r\n]+\(file: ')).Count
            gstack_aliases = ([regex]::Matches($skillText, '(?m)^- gstack-')).Count
            canonical_router_present = [bool] ($skillText -match '(?m)^- gstack:')
            canonical_autoplan_present = [bool] ($skillText -match '(?m)^- autoplan:')
        }
    }
    finally {
        Pop-Location
    }
}

function Get-DryRunResult {
    $mappings = @(Get-DedupeMappings)
    $inlineEntries = $mappings | ForEach-Object {
        $path = ([string] $_.disable).Replace('\', '/')
        "{path=`"$path`",enabled=false}"
    }
    $override = 'skills.config=[' + ($inlineEntries -join ',') + ']'
    $before = Get-PromptSkillSummary
    $after = Get-PromptSkillSummary -Override $override

    return [pscustomobject]@{
        mode = 'dry-run'
        writes = $false
        codex_processes = @(Get-Process -Name codex -ErrorAction SilentlyContinue).Count
        disable_candidates = $mappings.Count
        retained_canonical_skills = @($mappings | Select-Object -ExpandProperty retain -Unique).Count
        before = $before
        projected_after = $after
        projected_entry_reduction = $before.entries - $after.entries
        project_guard = 'hooks/verify-codex-context-budget.ps1'
    }
}

function Wait-ForLocalCodexExit {
    param(
        [Parameter(Mandatory = $true)] [string] $StatusPath,
        [ValidateRange(1, 180)] [int] $TimeoutMinutes = 60
    )

    $deadline = (Get-Date).AddMinutes($TimeoutMinutes)
    while ((Get-Date) -lt $deadline) {
        $processes = @(Get-Process -Name codex -ErrorAction SilentlyContinue)
        if ($processes.Count -eq 0) {
            return
        }

        $waitingStatus = [ordered]@{
            status = 'waiting_for_codex_exit'
            observed_at = (Get-Date).ToString('o')
            codex_process_count = $processes.Count
        }
        Write-JsonFile -Path $StatusPath -Value $waitingStatus
        Start-Sleep -Seconds 2
    }

    throw "Timed out waiting for Codex Desktop to exit after $TimeoutMinutes minutes."
}

function Stop-CodexDesktopProcesses {
    param(
        [Parameter(Mandatory = $true)] [string] $StatusPath,
        [ValidateRange(5, 120)] [int] $DelaySeconds
    )

    Write-JsonFile -Path $StatusPath -Value ([ordered]@{
        status = 'closing_codex_desktop'
        observed_at = (Get-Date).ToString('o')
        close_delay_seconds = $DelaySeconds
        process_count = @(Get-Process -Name $codexDesktopProcessNames -ErrorAction SilentlyContinue).Count
    })
    Start-Sleep -Seconds $DelaySeconds

    $processes = @(Get-Process -Name $codexDesktopProcessNames -ErrorAction SilentlyContinue)
    foreach ($process in $processes) {
        try {
            $null = $process.CloseMainWindow()
        }
        catch {
            # Some helper processes do not own a conventional window.
        }
    }
    Start-Sleep -Seconds 3

    $deadline = (Get-Date).AddSeconds(30)
    while ((Get-Date) -lt $deadline) {
        $remaining = @(Get-Process -Name $codexDesktopProcessNames -ErrorAction SilentlyContinue)
        if ($remaining.Count -eq 0) {
            return
        }

        $remaining | Stop-Process -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 1
    }

    $remaining = @(Get-Process -Name $codexDesktopProcessNames -ErrorAction SilentlyContinue)
    if ($remaining.Count -gt 0) {
        throw "Unable to close all Codex Desktop processes; remaining count: $($remaining.Count)."
    }
}

if (-not $Apply) {
    $result = Get-DryRunResult
    $result | ConvertTo-Json -Depth 6
    exit 0
}

if (-not $WaitForCodexExit) {
    throw 'Apply requires -WaitForCodexExit so global state is never changed while Codex is running.'
}
if (($ForceCloseCodex -or $RestartCodex) -and -not $Apply) {
    throw '-ForceCloseCodex and -RestartCodex are apply-only options.'
}

if (-not (Test-Path -LiteralPath $globalConfig -PathType Leaf)) {
    throw "Global config not found: $globalConfig"
}
if (-not (Test-Path -LiteralPath $keepFastScript -PathType Leaf)) {
    throw "keep-codex-fast script not found: $keepFastScript"
}
if (-not (Test-Path -LiteralPath $PythonPath -PathType Leaf)) {
    throw "Python executable not found: $PythonPath"
}
if (-not (Test-Path -LiteralPath $CodexPath -PathType Leaf)) {
    throw "Codex executable not found: $CodexPath"
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = "C:\cb\kcf-$stamp"
$status = [ordered]@{
    status = 'waiting_for_codex_exit'
    started_at = (Get-Date).ToString('o')
    backup_root = $backupRoot
    archive_older_than_days = $ArchiveOlderThanDays
    worktree_older_than_days = $WorktreeOlderThanDays
}
Write-JsonFile -Path $StatusPath -Value $status

try {
    # The bundled 0.143-era process detector does not recognize this Desktop build.
    # Wait on the locally verified process name before invoking any mutating maintenance.
    if ($ForceCloseCodex) {
        Stop-CodexDesktopProcesses -StatusPath $StatusPath -DelaySeconds $CloseDelaySeconds
    }
    Wait-ForLocalCodexExit -StatusPath $StatusPath

    $status.status = 'applying_archival_and_log_rotation'
    Write-JsonFile -Path $StatusPath -Value $status

    & $PythonPath $keepFastScript --apply --backup-root $backupRoot --archive-older-than-days $ArchiveOlderThanDays --worktree-older-than-days $WorktreeOlderThanDays
    if ($LASTEXITCODE -ne 0) {
        throw "keep-codex-fast apply failed with exit code $LASTEXITCODE"
    }

    $status.status = 'applying_global_skill_dedupe'
    Write-JsonFile -Path $StatusPath -Value $status

    $mappings = @(Get-DedupeMappings)
    $configBackup = Join-Path $backupRoot 'config.before-global-skill-dedupe.toml'
    Copy-Item -LiteralPath $globalConfig -Destination $configBackup

    $configText = (Get-Content -LiteralPath $globalConfig -Raw).Replace("`r`n", "`n").TrimEnd()
    if ($configText -match '(?m)^\[skills\]\s*$') {
        throw 'Global [skills] config already exists; refusing an automatic merge.'
    }

    $skillsBlock = New-SkillsTomlBlock -Mappings $mappings
    $newConfig = $configText + "`n`n" + $skillsBlock + "`n"
    $tempConfig = Join-Path $codexHome 'config.context-slimming.tmp'
    [IO.File]::WriteAllText($tempConfig, $newConfig, [Text.UTF8Encoding]::new($false))
    [IO.File]::Replace($tempConfig, $globalConfig, (Join-Path $backupRoot 'config.replace-backup.toml'))

    try {
        Push-Location -LiteralPath $workspaceRoot
        $mcpRaw = & $CodexPath mcp list --json 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw 'Global config failed Codex parsing after Skill dedupe.'
        }
        $null = $mcpRaw | ConvertFrom-Json
    }
    finally {
        Pop-Location
    }

    $promptSummary = Get-PromptSkillSummary
    $manifest = [pscustomobject]@{
        applied_at = (Get-Date).ToString('o')
        mappings = $mappings
        prompt_summary = $promptSummary
        config_backup = $configBackup
    }
    Write-JsonFile -Path (Join-Path $backupRoot 'global-skill-dedupe-manifest.json') -Value $manifest

    $status.status = 'complete'
    $status.completed_at = (Get-Date).ToString('o')
    $status.disabled_skill_aliases = $mappings.Count
    $status.prompt_summary = $promptSummary
    Write-JsonFile -Path $StatusPath -Value $status
    $status | ConvertTo-Json -Depth 6
}
catch {
    if (Test-Path -LiteralPath (Join-Path $backupRoot 'config.before-global-skill-dedupe.toml')) {
        Copy-Item -LiteralPath (Join-Path $backupRoot 'config.before-global-skill-dedupe.toml') -Destination $globalConfig -Force
    }
    $status.status = 'failed'
    $status.failed_at = (Get-Date).ToString('o')
    $status.error = $_.Exception.Message
    Write-JsonFile -Path $StatusPath -Value $status
    throw
}
finally {
    if ($RestartCodex) {
        try {
            Start-Process -FilePath 'explorer.exe' -ArgumentList "shell:AppsFolder\$codexDesktopAppId"
            $status.restart_requested_at = (Get-Date).ToString('o')
        }
        catch {
            $status.restart_error = $_.Exception.Message
        }
        Write-JsonFile -Path $StatusPath -Value $status
    }
}
