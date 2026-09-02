Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$hotelRoot = Split-Path -Parent $PSScriptRoot
$workspaceRoot = Split-Path -Parent $hotelRoot
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
Assert-True ($rootConfigText -eq $hotelConfigText) 'Workspace and HOTEL context-lean configs have drifted'

$requiredConfigPatterns = @(
    '^project_doc_max_bytes\s*=\s*12000$',
    '^model_auto_compact_token_limit\s*=\s*320000$',
    '^model_auto_compact_token_limit_scope\s*=\s*"total"$',
    '^model_reasoning_effort\s*=\s*"high"$',
    '^tool_output_token_limit\s*=\s*6000$',
    '^max_threads\s*=\s*2$',
    '^max_depth\s*=\s*1$',
    '^job_max_runtime_seconds\s*=\s*1800$'
)

foreach ($pattern in $requiredConfigPatterns) {
    Assert-True ($rootConfigText -match "(?m)$pattern") "Missing context-lean config contract: $pattern"
}

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

$expectedDisabled = @('cloudflare-api', 'cua_repl', 'node_repl', 'openaiDeveloperDocs', 'playwright')
$expectedEnabled = @('codex-security', 'github')

foreach ($directory in @($workspaceRoot, $hotelRoot)) {
    $servers = Get-McpState $directory
    $enabled = @($servers | Where-Object { $_.enabled -ne $false } | ForEach-Object { $_.name } | Sort-Object)
    $disabled = @($servers | Where-Object { $_.enabled -eq $false } | ForEach-Object { $_.name } | Sort-Object)
    Assert-True (($enabled -join ',') -eq (($expectedEnabled | Sort-Object) -join ',')) "Unexpected enabled MCP surface in ${directory}: $($enabled -join ',')"
    Assert-True (($disabled -join ',') -eq (($expectedDisabled | Sort-Object) -join ',')) "Unexpected disabled MCP surface in ${directory}: $($disabled -join ',')"
}

[pscustomobject]@{
    status = 'pass'
    root_agents_bytes = $rootAgentBytes
    hotel_override_bytes = $hotelOverrideBytes
    project_doc_max_bytes = 12000
    auto_compact_tokens = 320000
    max_subagent_threads = 2
    max_subagent_depth = 1
    tool_output_token_limit = 6000
    enabled_mcp_servers = $expectedEnabled
    disabled_mcp_servers = $expectedDisabled
} | ConvertTo-Json -Depth 3
