param(
    [string]$EvidenceDir,
    [switch]$ApplyEvidenceFiles,
    [switch]$MarkPrReady,
    [switch]$AfterPrReady
)

$ErrorActionPreference = 'Stop'

function Invoke-ReleaseCommand {
    param(
        [string]$Name,
        [scriptblock]$Command
    )

    Write-Host ""
    Write-Host "== $Name =="
    & $Command
    $code = $LASTEXITCODE
    if ($null -ne $code -and $code -ne 0) {
        throw "$Name failed with exit code $code"
    }
}

$RepoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).ProviderPath
if ([string]::IsNullOrWhiteSpace($EvidenceDir)) {
    $EvidenceDir = (Resolve-Path -LiteralPath (Join-Path $RepoRoot '..\release-evidence-temp')).ProviderPath
} else {
    $EvidenceDir = (Resolve-Path -LiteralPath $EvidenceDir).ProviderPath
}

$lockPath = Join-Path $RepoRoot '.git\index.lock'
$productionEnv = Join-Path $EvidenceDir 'production.env'
$llmAttestation = Join-Path $EvidenceDir 'llm-attestation.json'
$designManifestEvidence = Join-Path $EvidenceDir 'design_handoff_manifest.json'
$otaAttestationEvidence = Join-Path $EvidenceDir 'ota_credential_rotation_attestation.json'

Write-Host "Repo root: $RepoRoot"
Write-Host "Evidence dir: $EvidenceDir"

$env:RELEASE_EVIDENCE_DIR = $EvidenceDir
if (-not $env:CODEX_SECURITY_SCAN_DIR) {
    $evidenceSecurityScanDir = Join-Path $EvidenceDir 'codex-security/latest'
    if (Test-Path -LiteralPath $evidenceSecurityScanDir) {
        $env:CODEX_SECURITY_SCAN_DIR = $evidenceSecurityScanDir
    }
}

if (Test-Path -LiteralPath $lockPath) {
    $activeGitProcesses = Get-Process -ErrorAction SilentlyContinue | Where-Object {
        $_.ProcessName -in @('git', 'gh')
    } | Select-Object Id, ProcessName, StartTime

    if ($activeGitProcesses) {
        $processSummary = ($activeGitProcesses | ForEach-Object {
            "$($_.ProcessName):$($_.Id)"
        }) -join ', '
        throw ".git\index.lock exists while git-related processes are active ($processSummary). Stop or wait for those processes before continuing."
    }

    Write-Host "Removing stale git index lock after confirming no active git/gh process: $lockPath"
    Remove-Item -LiteralPath $lockPath -Force
}

if (-not (Test-Path -LiteralPath $productionEnv)) {
    throw "Missing production env evidence: $productionEnv"
}
if (-not (Test-Path -LiteralPath $llmAttestation)) {
    throw "Missing LLM attestation evidence: $llmAttestation"
}

if ($ApplyEvidenceFiles) {
    $docsDir = Join-Path $RepoRoot 'docs'
    if (Test-Path -LiteralPath $designManifestEvidence) {
        Copy-Item -LiteralPath $designManifestEvidence -Destination (Join-Path $docsDir 'design_handoff_manifest.json') -Force
        Write-Host "Applied design handoff manifest from evidence dir."
    } else {
        Write-Host "Design handoff manifest not found in evidence dir; leaving repo file unchanged."
    }

    if (Test-Path -LiteralPath $otaAttestationEvidence) {
        Copy-Item -LiteralPath $otaAttestationEvidence -Destination (Join-Path $docsDir 'ota_credential_rotation_attestation.json') -Force
        Write-Host "Applied OTA credential rotation attestation from evidence dir."
    } else {
        Write-Host "OTA credential rotation attestation not found in evidence dir; leaving repo file unchanged."
    }
}

$env:RELEASE_ENV_FILE = $productionEnv
$env:LLM_CONNECTIVITY_ATTESTATION_FILE = $llmAttestation
$env:DESIGN_HANDOFF_MANIFEST_FILE = $designManifestEvidence
$env:OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE = $otaAttestationEvidence
$env:RELEASE_PR_NUMBER = '2'

Push-Location -LiteralPath $RepoRoot
try {
    Invoke-ReleaseCommand "release evidence preflight" {
        npm.cmd run review:release-evidence
    }

    Invoke-ReleaseCommand "design handoff" {
        npm.cmd run review:release-design
    }

    Invoke-ReleaseCommand "OTA credential rotation" {
        npm.cmd run review:release-ota-credentials
    }

    Invoke-ReleaseCommand "release readiness" {
        npm.cmd run review:release-readiness
    }

    Invoke-ReleaseCommand "collect PR #2 external state" {
        npm.cmd run collect:release-external-state
    }

    $env:RELEASE_EXTERNAL_STATE_FILE = 'docs/release_external_state_evidence.local.json'
    Invoke-ReleaseCommand "release external state" {
        npm.cmd run review:release-external-state
    }

    if ($MarkPrReady) {
        Invoke-ReleaseCommand "guarded PR ready transition" {
            npm.cmd run release:mark-pr-ready
        }
    }

    if ($AfterPrReady) {
        Invoke-ReleaseCommand "final handoff after PR ready" {
            powershell -NoProfile -ExecutionPolicy Bypass -File scripts/review_release_final_handoff.ps1 -EvidenceDir $EvidenceDir -AfterPrReady
        }
    }
} finally {
    Pop-Location
}

Write-Host ""
Write-Host "Release handoff checks completed."
