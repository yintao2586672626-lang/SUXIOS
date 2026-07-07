[CmdletBinding()]
param(
    [string]$EvidenceDir = "..\release-evidence-temp",
    [string]$PrNumber = $env:RELEASE_PR_NUMBER
)

$ErrorActionPreference = "Stop"

function Get-ReleasePrReadyTarget {
    param(
        [string]$PrNumber
    )

    $viewArgs = @("pr", "view", $PrNumber, "--json", "number,state,isDraft,url")
    $output = & gh @viewArgs 2>&1
    $exitCode = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
    if ($exitCode -ne 0) {
        $message = (($output | Out-String).Trim())
        if ([string]::IsNullOrWhiteSpace($message)) {
            $message = "unknown gh pr view failure"
        }
        throw "Could not inspect configured release PR #$PrNumber before ready transition: $message"
    }

    try {
        $pr = ($output | Out-String | ConvertFrom-Json)
    } catch {
        throw "Could not parse gh pr view JSON for configured release PR #${PrNumber}: $($_.Exception.Message)"
    }

    if ([string]$pr.number -ne [string]$PrNumber) {
        throw "Configured release PR evidence is for #$($pr.number), expected #$PrNumber."
    }

    if ($pr.state -ne "OPEN") {
        throw "Configured release PR #$PrNumber is $($pr.state), not OPEN. Set RELEASE_PR_NUMBER to the actual open final release PR before release handoff."
    }

    return $pr
}

function Invoke-ReleasePrReadyCandidateReview {
    param(
        [string]$EvidenceDir
    )

    if (-not $env:RELEASE_PR_CANDIDATES_RESULT_FILE) {
        $env:RELEASE_PR_CANDIDATES_RESULT_FILE = Join-Path $EvidenceDir "release-pr-ready-candidates-result.json"
    }

    $previousAllowDraft = $env:RELEASE_PR_CANDIDATES_ALLOW_DRAFT
    $env:RELEASE_PR_CANDIDATES_ALLOW_DRAFT = "1"
    try {
        npm.cmd run review:release-pr-candidates
        $candidateExit = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
        if ($candidateExit -ne 0) {
            throw "No selected release PR ready target is available. Run npm run review:release-pr-candidates after creating the final release PR, then set RELEASE_PR_NUMBER to the selected PR."
        }
    } finally {
        if ($null -eq $previousAllowDraft) {
            Remove-Item Env:\RELEASE_PR_CANDIDATES_ALLOW_DRAFT -ErrorAction SilentlyContinue
        } else {
            $env:RELEASE_PR_CANDIDATES_ALLOW_DRAFT = $previousAllowDraft
        }
    }

    try {
        $candidateResult = Get-Content -LiteralPath $env:RELEASE_PR_CANDIDATES_RESULT_FILE -Raw | ConvertFrom-Json
    } catch {
        throw "Could not read release PR ready candidate result at $env:RELEASE_PR_CANDIDATES_RESULT_FILE`: $($_.Exception.Message)"
    }

    if ($candidateResult.command -ne "npm run review:release-pr-candidates") {
        throw "Release PR ready candidate result was not produced by npm run review:release-pr-candidates."
    }
    if ($candidateResult.allow_draft_candidate -ne $true -or $candidateResult.candidate_policy -ne "allow_draft_for_ready_transition") {
        throw "Release PR ready candidate result must be generated with RELEASE_PR_CANDIDATES_ALLOW_DRAFT=1."
    }
    if ($candidateResult.status -ne "passed" -or [int]$candidateResult.summary.failures -ne 0) {
        throw "Release PR ready candidate gate has not passed."
    }

    $selectedPrNumber = [string]$candidateResult.selected_release_pr_number
    if ([string]::IsNullOrWhiteSpace($selectedPrNumber)) {
        throw "Release PR ready candidate result passed but did not record selected_release_pr_number."
    }

    $selectedCandidate = @($candidateResult.candidates | Where-Object {
        [string]$_.number -eq [string]$selectedPrNumber
    } | Select-Object -First 1)
    $selectedHeadSha = [string]$selectedCandidate.headRefOid
    if ($selectedHeadSha -notmatch '^[a-fA-F0-9]{40}$') {
        throw "Release PR ready candidate result passed but did not record a 40-character selected PR headRefOid."
    }

    return [pscustomobject]@{
        Number = $selectedPrNumber
        HeadSha = $selectedHeadSha
    }
}

function Resolve-ReleasePrNumber {
    param(
        [string]$ConfiguredPrNumber,
        [string]$SelectedPrNumber
    )

    if ([string]::IsNullOrWhiteSpace($ConfiguredPrNumber)) {
        return $SelectedPrNumber
    }

    if ([string]$ConfiguredPrNumber -ne [string]$SelectedPrNumber) {
        throw "Configured release PR #$ConfiguredPrNumber does not match selected release PR ready target #$SelectedPrNumber. Set RELEASE_PR_NUMBER to the selected open release PR."
    }

    return $ConfiguredPrNumber
}

function Assert-LocalHeadMatchesReleasePrReadyTarget {
    param(
        [string]$SelectedPrNumber,
        [string]$SelectedHeadSha
    )

    $localHeadOutput = & git rev-parse HEAD 2>&1
    $exitCode = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
    if ($exitCode -ne 0) {
        $message = (($localHeadOutput | Out-String).Trim())
        if ([string]::IsNullOrWhiteSpace($message)) {
            $message = "unknown git rev-parse HEAD failure"
        }
        throw "Could not inspect local HEAD before marking PR #$SelectedPrNumber ready: $message"
    }

    $localHeadSha = (($localHeadOutput | Out-String).Trim())
    if ($localHeadSha -notmatch '^[a-fA-F0-9]{40}$') {
        throw "Local HEAD before PR ready transition is not a 40-character commit sha: $localHeadSha"
    }

    if ($localHeadSha.ToLowerInvariant() -ne $SelectedHeadSha.ToLowerInvariant()) {
        throw "Local HEAD $localHeadSha does not match selected release PR #$SelectedPrNumber head $SelectedHeadSha. Check out the final release PR head before running npm run release:mark-pr-ready."
    }

    Write-Host "Local HEAD matches selected release PR #$SelectedPrNumber head $SelectedHeadSha."
}

$repoRoot = Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "..")
Set-Location -LiteralPath $repoRoot

$resolvedEvidenceDir = if ([System.IO.Path]::IsPathRooted($EvidenceDir)) {
    [System.IO.Path]::GetFullPath($EvidenceDir)
} else {
    [System.IO.Path]::GetFullPath((Join-Path $repoRoot $EvidenceDir))
}
New-Item -ItemType Directory -Force -Path $resolvedEvidenceDir | Out-Null
$env:RELEASE_EVIDENCE_DIR = $resolvedEvidenceDir

$selectedReadyTarget = Invoke-ReleasePrReadyCandidateReview -EvidenceDir $resolvedEvidenceDir
$selectedPrNumber = [string]$selectedReadyTarget.Number
$selectedHeadSha = [string]$selectedReadyTarget.HeadSha
$PrNumber = Resolve-ReleasePrNumber -ConfiguredPrNumber $PrNumber -SelectedPrNumber $selectedPrNumber
$env:RELEASE_PR_NUMBER = [string]$PrNumber

Write-Host "Release PR ready candidate result: $env:RELEASE_PR_CANDIDATES_RESULT_FILE"
Assert-LocalHeadMatchesReleasePrReadyTarget -SelectedPrNumber $PrNumber -SelectedHeadSha $selectedHeadSha

$releasePr = Get-ReleasePrReadyTarget -PrNumber $PrNumber
Write-Host "Configured release PR #$($releasePr.number) is OPEN (draft=$($releasePr.isDraft)); continuing guarded ready flow."

Write-Host "Running release final handoff gates before marking PR #$PrNumber ready..."
& powershell -NoProfile -ExecutionPolicy Bypass -File "scripts/review_release_final_handoff.ps1" -EvidenceDir $EvidenceDir -PrNumber $PrNumber
$handoffExit = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
if ($handoffExit -ne 0) {
    throw "PR #$PrNumber was not marked ready because final handoff gates failed."
}

$releasePr = Get-ReleasePrReadyTarget -PrNumber $PrNumber
if ($releasePr.isDraft -eq $false) {
    Write-Host ""
    Write-Host "PR #$PrNumber is already not draft; skipping gh pr ready."
    Write-Host "Wait for GitHub Actions to remain green, then run:"
    Write-Host "powershell -NoProfile -ExecutionPolicy Bypass -File scripts/review_release_final_handoff.ps1 -EvidenceDir `"$EvidenceDir`" -PrNumber $PrNumber -AfterPrReady"
    exit 0
}

Write-Host ""
Write-Host "Marking PR #$PrNumber ready for review..."
gh pr ready $PrNumber
$readyExit = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
if ($readyExit -ne 0) {
    throw "gh pr ready failed for PR #$PrNumber."
}

Write-Host ""
Write-Host "PR #$PrNumber is no longer draft. Wait for GitHub Actions to remain green, then run:"
Write-Host "powershell -NoProfile -ExecutionPolicy Bypass -File scripts/review_release_final_handoff.ps1 -EvidenceDir `"$EvidenceDir`" -PrNumber $PrNumber -AfterPrReady"
