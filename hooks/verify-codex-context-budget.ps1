Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$hotelRoot = Split-Path -Parent $PSScriptRoot
$contextResolver = Join-Path $PSScriptRoot 'lib\context_root.mjs'
$workspaceRoot = & node --input-type=module -e 'import {pathToFileURL} from "node:url"; const {resolveOuterContextRoot}=await import(pathToFileURL(process.argv[1]).href); process.stdout.write(resolveOuterContextRoot(process.argv[2]));' $contextResolver $hotelRoot
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($workspaceRoot)) {
    throw 'Unable to resolve the authoritative workspace context root'
}
$workspaceRoot = $workspaceRoot.Trim()
$rootAgent = Join-Path $workspaceRoot 'AGENTS.md'
$hotelOverride = Join-Path $hotelRoot 'AGENTS.override.md'
$rootConfig = Join-Path $workspaceRoot '.codex\config.toml'
$hotelConfig = Join-Path $hotelRoot '.codex\config.toml'

function Assert-True {
    param(
        [Parameter(Mandatory = $true)] [bool] $Condition,
        [Parameter(Mandatory = $true)] [string] $Message
    )

    if (-not $Condition) {
        throw $Message
    }
}

foreach ($path in @($rootAgent, $hotelOverride, $rootConfig, $hotelConfig)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) "Missing context-budget artifact: $path"
}

$rootAgentBytes = (Get-Item -LiteralPath $rootAgent).Length
$hotelOverrideBytes = (Get-Item -LiteralPath $hotelOverride).Length
Assert-True ($rootAgentBytes -le 12000) "Root AGENTS.md exceeds 12 KB: $rootAgentBytes bytes"
Assert-True ($hotelOverrideBytes -le 12000) "HOTEL/AGENTS.override.md exceeds 12 KB: $hotelOverrideBytes bytes"

$rootAgentText = Get-Content -LiteralPath $rootAgent -Raw
$hotelOverrideText = Get-Content -LiteralPath $hotelOverride -Raw
Assert-True ($rootAgentText -match 'Default to one accountable agent') 'Root AGENTS.md lost the single-agent default'
Assert-True ($rootAgentText -match 'at most two open') 'Root AGENTS.md lost the two-subagent ceiling'
Assert-True ($rootAgentText -match 'Never fork a long conversation') 'Root AGENTS.md lost the no-full-history rule'
Assert-True ($hotelOverrideText -match 'Maximum two open subagents') 'HOTEL override lost the two-subagent ceiling'
Assert-True ($hotelOverrideText -match '300k\+ context') 'HOTEL override lost the long-thread cutoff'

$rootConfigText = (Get-Content -LiteralPath $rootConfig -Raw).Replace("`r`n", "`n").Trim()
$hotelConfigText = (Get-Content -LiteralPath $hotelConfig -Raw).Replace("`r`n", "`n").Trim()
function Get-ProjectScalar {
    param([string] $Text, [string] $Key)
    $topLevel = ($Text -split '(?m)^\s*\[', 2)[0]
    $match = [regex]::Match($topLevel, ('(?m)^' + [regex]::Escape($Key) + '\s*=\s*([^#\r\n]+)'))
    Assert-True $match.Success "Missing top-level project setting: $Key"
    return $match.Groups[1].Value.Trim()
}

$alignedKeys = @('model', 'project_doc_max_bytes', 'model_auto_compact_token_limit',
    'model_auto_compact_token_limit_scope', 'model_reasoning_effort', 'plan_mode_reasoning_effort',
    'model_verbosity', 'tool_output_token_limit', 'max_threads', 'max_depth', 'job_max_runtime_seconds')
$settings = @{}
foreach ($key in $alignedKeys) {
    $settings[$key] = Get-ProjectScalar $rootConfigText $key
    Assert-True ($settings[$key] -eq (Get-ProjectScalar $hotelConfigText $key)) "Workspace and active project setting differs: $key"
}

# Validate capacity and the applicable concurrency contract, not an obsolete profile's exact values.
foreach ($key in @('project_doc_max_bytes', 'model_auto_compact_token_limit', 'tool_output_token_limit',
        'max_threads', 'max_depth', 'job_max_runtime_seconds')) {
    Assert-True ($settings[$key] -match '^\d+$' -and [long]$settings[$key] -gt 0) "Invalid positive budget: $key"
}
$documentBudget = [long]$settings['project_doc_max_bytes']
Assert-True (($rootAgentBytes + $hotelOverrideBytes) -le $documentBudget) 'Combined root and active instructions exceed the configured document budget'
Assert-True ([int]$settings['max_threads'] -le 2) 'Project exceeds the two-subagent ceiling'
Assert-True ([int]$settings['max_depth'] -le 1) 'Project permits recursive delegation'

function Get-McpState {
    param([Parameter(Mandatory = $true)] [string] $WorkingDirectory)

    Push-Location -LiteralPath $WorkingDirectory
    try {
        $raw = & codex mcp list --json 2>&1
        Assert-True ($LASTEXITCODE -eq 0) "Codex config failed to load from $WorkingDirectory"
        return @($raw | ConvertFrom-Json)
    }
    finally {
        Pop-Location
    }
}

$expectedDisabled = @('cloudflare-api', 'node_repl', 'openaiDeveloperDocs', 'playwright')
$observedMcp = @()

foreach ($directory in @($workspaceRoot, $hotelRoot)) {
    $servers = Get-McpState $directory
    $enabled = @($servers | Where-Object { $_.enabled -ne $false } | ForEach-Object { $_.name } | Sort-Object)
    $disabled = @($servers | Where-Object { $_.enabled -eq $false } | ForEach-Object { $_.name } | Sort-Object)
    foreach ($name in $expectedDisabled) {
        Assert-True ($disabled -contains $name) "Project-disabled MCP server is missing or enabled in ${directory}: $name"
    }
    # Plugin availability can change independently; do not require historical globally enabled servers.
    $observedMcp += [pscustomobject]@{ directory = $directory; enabled = $enabled; disabled = $disabled }
}

[pscustomobject]@{
    status = 'pass'
    root_agents_bytes = $rootAgentBytes
    hotel_override_bytes = $hotelOverrideBytes
    project_doc_max_bytes = $documentBudget
    auto_compact_tokens = [long]$settings['model_auto_compact_token_limit']
    max_subagent_threads = [int]$settings['max_threads']
    max_subagent_depth = [int]$settings['max_depth']
    tool_output_token_limit = [long]$settings['tool_output_token_limit']
    mcp_states = $observedMcp
} | ConvertTo-Json -Depth 4
