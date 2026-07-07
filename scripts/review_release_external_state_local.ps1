[CmdletBinding()]
param(
    [string]$EvidenceDir = "..\release-evidence-temp",
    [string]$PrNumber = $env:RELEASE_PR_NUMBER
)

$ErrorActionPreference = "Stop"

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "..")).ProviderPath
$resolvedEvidenceDir = if ([System.IO.Path]::IsPathRooted($EvidenceDir)) {
    $EvidenceDir
} else {
    Join-Path $repoRoot $EvidenceDir
}
$resolvedEvidenceDir = [System.IO.Path]::GetFullPath($resolvedEvidenceDir)
New-Item -ItemType Directory -Force -Path $resolvedEvidenceDir | Out-Null

$externalStateEvidence = Join-Path $resolvedEvidenceDir "release-external-state-local-evidence.json"
$externalStateResult = Join-Path $resolvedEvidenceDir "release-external-state-result.json"

$env:RELEASE_EXTERNAL_STATE_FILE = $externalStateEvidence
$env:RELEASE_EXTERNAL_STATE_RESULT_FILE = $externalStateResult
if ([string]::IsNullOrWhiteSpace($PrNumber)) {
    Remove-Item Env:\RELEASE_PR_NUMBER -ErrorAction SilentlyContinue
} else {
    $env:RELEASE_PR_NUMBER = [string]$PrNumber
}

Set-Location -LiteralPath $repoRoot

Write-Host "Repo root: $repoRoot"
Write-Host "Release external-state evidence: $env:RELEASE_EXTERNAL_STATE_FILE"
Write-Host "Release external-state result: $env:RELEASE_EXTERNAL_STATE_RESULT_FILE"
if ([string]::IsNullOrWhiteSpace($env:RELEASE_PR_NUMBER)) {
    Write-Host "RELEASE_PR_NUMBER: <not set>"
} else {
    Write-Host "RELEASE_PR_NUMBER: $env:RELEASE_PR_NUMBER"
}

if ([string]::IsNullOrWhiteSpace($PrNumber)) {
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts/collect_release_external_state.ps1 -OutputPath $env:RELEASE_EXTERNAL_STATE_FILE
} else {
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts/collect_release_external_state.ps1 -OutputPath $env:RELEASE_EXTERNAL_STATE_FILE -PrNumber $PrNumber
}
$collectExit = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
if ($collectExit -ne 0) {
    exit $collectExit
}

npm.cmd run review:release-external-state
$exitCode = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
exit $exitCode
