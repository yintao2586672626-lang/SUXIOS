[CmdletBinding()]
param(
    [string]$EvidenceDir = "..\release-evidence-temp",
    [int]$PrNumber = 2
)

$ErrorActionPreference = "Stop"

function Invoke-ReleaseCurrentStep {
    param(
        [string]$Name,
        [scriptblock]$Command
    )

    Write-Host ""
    Write-Host "== $Name =="
    & $Command | ForEach-Object { Write-Host $_ }
    $code = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
    return [pscustomobject]@{
        name = $Name
        exit_code = $code
    }
}

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "..")).ProviderPath
$evidencePath = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($EvidenceDir)
New-Item -ItemType Directory -Force -Path $evidencePath | Out-Null
$resolvedEvidenceDir = (Resolve-Path -LiteralPath $evidencePath).ProviderPath

$env:RELEASE_PR_NUMBER = [string]$PrNumber
$env:RELEASE_ENV_FILE = Join-Path $resolvedEvidenceDir "production.env"
$env:LLM_CONNECTIVITY_ATTESTATION_FILE = Join-Path $resolvedEvidenceDir "llm-attestation.json"
$env:RELEASE_EVIDENCE_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-evidence-current-result.json"
$env:RELEASE_READINESS_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-readiness-current-result.json"
$env:RELEASE_EXTERNAL_STATE_FILE = Join-Path $resolvedEvidenceDir "release-external-state-current-evidence.json"
$env:RELEASE_EXTERNAL_STATE_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-external-state-current-result.json"

Write-Host "Repo root: $repoRoot"
Write-Host "Evidence dir: $resolvedEvidenceDir"
Write-Host "RELEASE_PR_NUMBER: $env:RELEASE_PR_NUMBER"

Push-Location -LiteralPath $repoRoot
try {
    $results = @()
    $results += Invoke-ReleaseCurrentStep "release evidence current result" {
        npm.cmd run review:release-evidence
    }
    $results += Invoke-ReleaseCurrentStep "release readiness current result" {
        npm.cmd run review:release-readiness
    }
    $results += Invoke-ReleaseCurrentStep "collect release external state current evidence" {
        powershell -NoProfile -ExecutionPolicy Bypass -File scripts/collect_release_external_state.ps1 -OutputPath $env:RELEASE_EXTERNAL_STATE_FILE -PrNumber $PrNumber
    }
    $results += Invoke-ReleaseCurrentStep "release external state current result" {
        npm.cmd run review:release-external-state
    }

    Write-Host ""
    Write-Host "Current release evidence outputs:"
    foreach ($file in @(
        $env:RELEASE_EVIDENCE_RESULT_FILE,
        $env:RELEASE_READINESS_RESULT_FILE,
        $env:RELEASE_EXTERNAL_STATE_FILE,
        $env:RELEASE_EXTERNAL_STATE_RESULT_FILE
    )) {
        $item = Get-Item -LiteralPath $file -ErrorAction SilentlyContinue
        if ($item) {
            Write-Host "- $($item.FullName) ($($item.Length) bytes)"
        } else {
            Write-Host "- missing: $file"
        }
    }

    $failed = @($results | Where-Object { $_.exit_code -ne 0 })
    if ($failed.Count -gt 0) {
        Write-Host ""
        Write-Host "Current release evidence refresh completed with failing gates:"
        foreach ($item in $failed) {
            Write-Host "- $($item.name): exit $($item.exit_code)"
        }
        exit 1
    }
} finally {
    Pop-Location
}

Write-Host ""
Write-Host "Current release evidence refresh passed."
