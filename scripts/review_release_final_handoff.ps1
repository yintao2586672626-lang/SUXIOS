[CmdletBinding()]
param(
    [string]$EvidenceDir = "..\release-evidence-temp",
    [int]$PrNumber = 2,
    [switch]$AfterPrReady
)

$ErrorActionPreference = "Stop"

function Invoke-ReleaseStep {
    param(
        [string]$Name,
        [scriptblock]$Command
    )

    Write-Host ""
    Write-Host "== $Name =="
    & $Command
    $code = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
    if ($code -ne 0) {
        throw "Release handoff step failed: $Name (exit $code)"
    }
}

$repoRoot = Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "..")
Set-Location -LiteralPath $repoRoot

$resolvedEvidenceDir = Resolve-Path -LiteralPath $EvidenceDir

if (-not $env:RELEASE_ENV_FILE) {
    $env:RELEASE_ENV_FILE = Join-Path $resolvedEvidenceDir "production.env"
}

if (-not $env:LLM_CONNECTIVITY_ATTESTATION_FILE) {
    $env:LLM_CONNECTIVITY_ATTESTATION_FILE = Join-Path $resolvedEvidenceDir "llm-attestation.json"
}

if (-not $env:DESIGN_HANDOFF_MANIFEST_FILE) {
    $env:DESIGN_HANDOFF_MANIFEST_FILE = Join-Path $resolvedEvidenceDir "design_handoff_manifest.json"
}

if (-not $env:OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE) {
    $env:OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE = Join-Path $resolvedEvidenceDir "ota_credential_rotation_attestation.json"
}

$env:RELEASE_PR_NUMBER = [string]$PrNumber

Write-Host "Release evidence dir: $resolvedEvidenceDir"
Write-Host "RELEASE_ENV_FILE: $env:RELEASE_ENV_FILE"
Write-Host "LLM_CONNECTIVITY_ATTESTATION_FILE: $env:LLM_CONNECTIVITY_ATTESTATION_FILE"
Write-Host "DESIGN_HANDOFF_MANIFEST_FILE: $env:DESIGN_HANDOFF_MANIFEST_FILE"
Write-Host "OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE: $env:OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE"
Write-Host "RELEASE_PR_NUMBER: $env:RELEASE_PR_NUMBER"

Invoke-ReleaseStep "design handoff" { npm.cmd run review:release-design }
Invoke-ReleaseStep "OTA credential rotation" { npm.cmd run review:release-ota-credentials }
Invoke-ReleaseStep "evidence bundle" { npm.cmd run review:release-evidence }
Invoke-ReleaseStep "release readiness" { npm.cmd run review:release-readiness }
Invoke-ReleaseStep "git worktree status" {
    $statusLines = @(git status --short --branch)
    $statusLines | ForEach-Object { Write-Host $_ }
    $changedLines = @($statusLines | Where-Object { -not $_.StartsWith("## ") })
    if ($changedLines.Count -gt 0) {
        throw "Local worktree is not clean."
    }
}
Invoke-ReleaseStep "backup tracking check" {
    $trackedBackups = @(git ls-files database/backups)
    $trackedBackups | ForEach-Object { Write-Host $_ }
    if ($trackedBackups.Count -gt 0) {
        throw "database/backups contains git-tracked files."
    }
}

if ($AfterPrReady) {
    Invoke-ReleaseStep "external state after PR ready" { npm.cmd run review:release-external-state }
    Write-Host ""
    Write-Host "Final release handoff gates passed. PR #$PrNumber is ready for merge if GitHub still shows mergeable and green."
} else {
    Write-Host ""
    Write-Host "Pre-ready release gates passed. Mark PR #$PrNumber ready for review, wait for CI to stay green, then rerun:"
    Write-Host "powershell -NoProfile -ExecutionPolicy Bypass -File scripts/review_release_final_handoff.ps1 -AfterPrReady"
}
