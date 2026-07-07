[CmdletBinding()]
param(
    [string]$EvidenceDir = "..\release-evidence-temp",
    [string]$PrNumber = $env:RELEASE_PR_NUMBER,
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

function Require-ReleasePrNumber {
    param(
        [string]$Value,
        [string]$Context
    )

    if ([string]::IsNullOrWhiteSpace($Value)) {
        throw "RELEASE_PR_NUMBER is required for $Context. Run npm run review:release-pr-candidates and set RELEASE_PR_NUMBER to the selected open final release PR."
    }

    return [string]$Value
}

$repoRoot = Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "..")
Set-Location -LiteralPath $repoRoot

$resolvedEvidenceDir = Resolve-Path -LiteralPath $EvidenceDir

$env:RELEASE_EVIDENCE_DIR = [string]$resolvedEvidenceDir

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

if (-not $env:CODEX_SECURITY_SCAN_DIR) {
    $evidenceSecurityScanDir = Join-Path $resolvedEvidenceDir "codex-security/latest"
    if (Test-Path -LiteralPath $evidenceSecurityScanDir) {
        $env:CODEX_SECURITY_SCAN_DIR = $evidenceSecurityScanDir
    }
}

if (-not $env:RELEASE_EVIDENCE_RESULT_FILE) {
    $env:RELEASE_EVIDENCE_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-evidence-result.json"
}

if (-not $env:RELEASE_READINESS_RESULT_FILE) {
    $env:RELEASE_READINESS_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-readiness-result.json"
}

if (-not $env:RELEASE_PR_CANDIDATES_RESULT_FILE) {
    $env:RELEASE_PR_CANDIDATES_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-pr-candidates-result.json"
}

if (-not $env:RELEASE_STAGED_SCOPE_RESULT_FILE) {
    $env:RELEASE_STAGED_SCOPE_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-staged-scope-result.json"
}

if (-not $env:RELEASE_EXTERNAL_STATE_FILE) {
    $env:RELEASE_EXTERNAL_STATE_FILE = Join-Path $resolvedEvidenceDir "release-external-state-evidence.json"
}

if (-not $env:RELEASE_EXTERNAL_STATE_RESULT_FILE) {
    $env:RELEASE_EXTERNAL_STATE_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-external-state-result.json"
}

if (-not $env:RELEASE_EVIDENCE_GAP_PACK_FILE) {
    $env:RELEASE_EVIDENCE_GAP_PACK_FILE = Join-Path $resolvedEvidenceDir "release-evidence-gap-pack.json"
}

if (-not [string]::IsNullOrWhiteSpace($PrNumber)) {
    $env:RELEASE_PR_NUMBER = [string]$PrNumber
}

Write-Host "Release evidence dir: $resolvedEvidenceDir"
Write-Host "RELEASE_EVIDENCE_DIR: $env:RELEASE_EVIDENCE_DIR"
Write-Host "RELEASE_ENV_FILE: $env:RELEASE_ENV_FILE"
Write-Host "LLM_CONNECTIVITY_ATTESTATION_FILE: $env:LLM_CONNECTIVITY_ATTESTATION_FILE"
Write-Host "DESIGN_HANDOFF_MANIFEST_FILE: $env:DESIGN_HANDOFF_MANIFEST_FILE"
Write-Host "OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE: $env:OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE"
Write-Host "CODEX_SECURITY_SCAN_DIR: $env:CODEX_SECURITY_SCAN_DIR"
Write-Host "RELEASE_EVIDENCE_RESULT_FILE: $env:RELEASE_EVIDENCE_RESULT_FILE"
Write-Host "RELEASE_READINESS_RESULT_FILE: $env:RELEASE_READINESS_RESULT_FILE"
Write-Host "RELEASE_PR_CANDIDATES_RESULT_FILE: $env:RELEASE_PR_CANDIDATES_RESULT_FILE"
Write-Host "RELEASE_STAGED_SCOPE_RESULT_FILE: $env:RELEASE_STAGED_SCOPE_RESULT_FILE"
Write-Host "RELEASE_EXTERNAL_STATE_FILE: $env:RELEASE_EXTERNAL_STATE_FILE"
Write-Host "RELEASE_EXTERNAL_STATE_RESULT_FILE: $env:RELEASE_EXTERNAL_STATE_RESULT_FILE"
Write-Host "RELEASE_EVIDENCE_GAP_PACK_FILE: $env:RELEASE_EVIDENCE_GAP_PACK_FILE"
if ([string]::IsNullOrWhiteSpace($env:RELEASE_PR_NUMBER)) {
    Write-Host "RELEASE_PR_NUMBER: <not set>"
} else {
    Write-Host "RELEASE_PR_NUMBER: $env:RELEASE_PR_NUMBER"
}

Invoke-ReleaseStep "design handoff" { npm.cmd run review:release-design }
Invoke-ReleaseStep "OTA credential rotation" { npm.cmd run review:release-ota-credentials }
Invoke-ReleaseStep "evidence bundle" { npm.cmd run review:release-evidence }
if ($AfterPrReady) {
    $PrNumber = Require-ReleasePrNumber -Value $PrNumber -Context "after-PR-ready final handoff"
    $env:RELEASE_PR_NUMBER = [string]$PrNumber
    Invoke-ReleaseStep "release PR candidates after PR ready" { npm.cmd run review:release-pr-candidates }
    Invoke-ReleaseStep "release staged scope after PR ready" { npm.cmd run review:release-staged-scope }
    Invoke-ReleaseStep "collect external state after PR ready" {
        powershell -NoProfile -ExecutionPolicy Bypass -File scripts/collect_release_external_state.ps1 -OutputPath $env:RELEASE_EXTERNAL_STATE_FILE -PrNumber $PrNumber
    }
    Invoke-ReleaseStep "external state after PR ready" { npm.cmd run review:release-external-state }
    Invoke-ReleaseStep "release readiness" { npm.cmd run review:release-readiness }
} else {
    Invoke-ReleaseStep "release staged scope pre-ready" { npm.cmd run review:release-staged-scope }
    Invoke-ReleaseStep "release readiness pre-ready" {
        $env:RELEASE_READINESS_ALLOW_PENDING_EXTERNAL_STATE = "1"
        try {
            npm.cmd run review:release-readiness
        } finally {
            Remove-Item Env:\RELEASE_READINESS_ALLOW_PENDING_EXTERNAL_STATE -ErrorAction SilentlyContinue
        }
    }
}
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
    Write-Host ""
    Write-Host "Final release handoff gates passed. PR #$PrNumber is ready for merge if GitHub still shows mergeable and green."
} else {
    Write-Host ""
    if ([string]::IsNullOrWhiteSpace($PrNumber)) {
        Write-Host "Pre-ready release gates passed. Run npm run review:release-pr-candidates, set RELEASE_PR_NUMBER to the selected open final release PR, then run npm run release:mark-pr-ready."
    } else {
        Write-Host "Pre-ready release gates passed. Mark PR #$PrNumber ready for review, wait for CI to stay green, then rerun:"
        Write-Host "powershell -NoProfile -ExecutionPolicy Bypass -File scripts/review_release_final_handoff.ps1 -EvidenceDir `"$resolvedEvidenceDir`" -PrNumber $PrNumber -AfterPrReady"
    }
}
