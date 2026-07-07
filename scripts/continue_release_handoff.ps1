param(
    [string]$EvidenceDir,
    [string]$PrNumber = $env:RELEASE_PR_NUMBER,
    [switch]$ApplyEvidenceFiles,
    [switch]$MarkPrReady,
    [switch]$AfterPrReady
)

$ErrorActionPreference = 'Stop'

if ($MarkPrReady -and $AfterPrReady) {
    throw "Do not combine -MarkPrReady and -AfterPrReady in one invocation. Mark the PR ready first, wait for GitHub Actions to remain green on the non-draft head, then rerun with -AfterPrReady."
}

if (($MarkPrReady -or $AfterPrReady) -and [string]::IsNullOrWhiteSpace($PrNumber)) {
    throw "RELEASE_PR_NUMBER is required for release handoff PR-ready continuation. Run npm run review:release-pr-candidates and set RELEASE_PR_NUMBER to the selected open final release PR."
}

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

function Resolve-SelectedReleasePrNumber {
    param(
        [string]$ConfiguredPrNumber,
        [string]$CandidateResultPath
    )

    if (-not [string]::IsNullOrWhiteSpace($ConfiguredPrNumber)) {
        return [string]$ConfiguredPrNumber
    }

    if (-not (Test-Path -LiteralPath $CandidateResultPath)) {
        return ''
    }

    $candidateResult = Get-Content -LiteralPath $CandidateResultPath -Raw | ConvertFrom-Json
    $selected = [string]$candidateResult.selected_release_pr_number
    if (-not [string]::IsNullOrWhiteSpace($selected)) {
        Write-Host "Selected RELEASE_PR_NUMBER from release-pr-candidates result: $selected"
        return $selected
    }

    return ''
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
$releaseEvidenceResult = Join-Path $EvidenceDir 'release-evidence-result.json'
$prCandidatesResult = Join-Path $EvidenceDir 'release-pr-candidates-result.json'
$stagedScopeResult = Join-Path $EvidenceDir 'release-staged-scope-result.json'
$externalStateEvidence = Join-Path $EvidenceDir 'release-external-state-evidence.json'
$externalStateResult = Join-Path $EvidenceDir 'release-external-state-result.json'

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
$env:RELEASE_EVIDENCE_RESULT_FILE = $releaseEvidenceResult
$env:RELEASE_PR_CANDIDATES_RESULT_FILE = $prCandidatesResult
$env:RELEASE_STAGED_SCOPE_RESULT_FILE = $stagedScopeResult
$env:RELEASE_EXTERNAL_STATE_FILE = $externalStateEvidence
$env:RELEASE_EXTERNAL_STATE_RESULT_FILE = $externalStateResult
if (-not [string]::IsNullOrWhiteSpace($PrNumber)) {
    $env:RELEASE_PR_NUMBER = [string]$PrNumber
} else {
    Remove-Item Env:\RELEASE_PR_NUMBER -ErrorAction SilentlyContinue
}

Write-Host "Release evidence result: $env:RELEASE_EVIDENCE_RESULT_FILE"
Write-Host "Release PR candidates result: $env:RELEASE_PR_CANDIDATES_RESULT_FILE"
Write-Host "Release staged-scope result: $env:RELEASE_STAGED_SCOPE_RESULT_FILE"
Write-Host "Release external-state evidence: $env:RELEASE_EXTERNAL_STATE_FILE"
Write-Host "Release external-state result: $env:RELEASE_EXTERNAL_STATE_RESULT_FILE"

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

    Invoke-ReleaseCommand "release PR candidates" {
        npm.cmd run review:release-pr-candidates
    }

    $PrNumber = Resolve-SelectedReleasePrNumber -ConfiguredPrNumber $PrNumber -CandidateResultPath $env:RELEASE_PR_CANDIDATES_RESULT_FILE
    if (-not [string]::IsNullOrWhiteSpace($PrNumber)) {
        $env:RELEASE_PR_NUMBER = [string]$PrNumber
    }

    Invoke-ReleaseCommand "release staged scope" {
        npm.cmd run review:release-staged-scope
    }

    if ($MarkPrReady) {
        Invoke-ReleaseCommand "guarded PR ready transition" {
            npm.cmd run release:mark-pr-ready
        }
    } else {
        $PrNumber = Require-ReleasePrNumber -Value $PrNumber -Context "release external-state collection"
        $env:RELEASE_PR_NUMBER = [string]$PrNumber
        Invoke-ReleaseCommand "collect PR #$PrNumber external state" {
            powershell -NoProfile -ExecutionPolicy Bypass -File scripts/collect_release_external_state.ps1 -OutputPath $env:RELEASE_EXTERNAL_STATE_FILE -PrNumber $PrNumber
        }

        Invoke-ReleaseCommand "release external state" {
            npm.cmd run review:release-external-state
        }

        Invoke-ReleaseCommand "release readiness" {
            npm.cmd run review:release-readiness
        }
    }

    if ($AfterPrReady) {
        Invoke-ReleaseCommand "final handoff after PR ready" {
            powershell -NoProfile -ExecutionPolicy Bypass -File scripts/review_release_final_handoff.ps1 -EvidenceDir $EvidenceDir -PrNumber $PrNumber -AfterPrReady
        }
    }
} finally {
    Pop-Location
}

Write-Host ""
Write-Host "Release handoff checks completed."
