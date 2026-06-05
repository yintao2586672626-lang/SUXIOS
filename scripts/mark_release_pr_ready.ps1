[CmdletBinding()]
param(
    [string]$EvidenceDir = "..\release-evidence-temp",
    [int]$PrNumber = 2
)

$ErrorActionPreference = "Stop"

$repoRoot = Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "..")
Set-Location -LiteralPath $repoRoot

Write-Host "Running release final handoff gates before marking PR #$PrNumber ready..."
& powershell -NoProfile -ExecutionPolicy Bypass -File "scripts/review_release_final_handoff.ps1" -EvidenceDir $EvidenceDir -PrNumber $PrNumber
$handoffExit = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
if ($handoffExit -ne 0) {
    throw "PR #$PrNumber was not marked ready because final handoff gates failed."
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
