[CmdletBinding()]
param(
    [string]$EvidenceDir = "..\release-evidence-temp",
    [string]$PrNumber = $env:RELEASE_PR_NUMBER
)

$ErrorActionPreference = "Stop"

function Invoke-ReleaseCurrentStep {
    param(
        [string]$Name,
        [scriptblock]$Command
    )

    Write-Host ""
    Write-Host "== $Name =="
    try {
        & $Command | ForEach-Object { Write-Host $_ }
        $code = if ($null -eq $LASTEXITCODE) { 0 } else { $LASTEXITCODE }
    } catch {
        Write-Host $_.Exception.Message
        $code = 1
    }
    return [pscustomobject]@{
        name = $Name
        exit_code = $code
    }
}

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "..")).ProviderPath
$evidencePath = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($EvidenceDir)
New-Item -ItemType Directory -Force -Path $evidencePath | Out-Null
$resolvedEvidenceDir = (Resolve-Path -LiteralPath $evidencePath).ProviderPath

$env:RELEASE_EVIDENCE_DIR = $resolvedEvidenceDir
if ([string]::IsNullOrWhiteSpace($PrNumber)) {
    Remove-Item Env:\RELEASE_PR_NUMBER -ErrorAction SilentlyContinue
} else {
    $env:RELEASE_PR_NUMBER = [string]$PrNumber
}
$env:RELEASE_ENV_FILE = Join-Path $resolvedEvidenceDir "production.env"
$env:LLM_CONNECTIVITY_ATTESTATION_FILE = Join-Path $resolvedEvidenceDir "llm-attestation.json"
$env:DESIGN_HANDOFF_MANIFEST_FILE = Join-Path $resolvedEvidenceDir "design_handoff_manifest.json"
$env:OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE = Join-Path $resolvedEvidenceDir "ota_credential_rotation_attestation.json"
if (-not $env:CODEX_SECURITY_SCAN_DIR) {
    $evidenceSecurityScanDir = Join-Path $resolvedEvidenceDir "codex-security/latest"
    if (Test-Path -LiteralPath $evidenceSecurityScanDir) {
        $env:CODEX_SECURITY_SCAN_DIR = $evidenceSecurityScanDir
    }
}
$env:RELEASE_EVIDENCE_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-evidence-current-result.json"
$env:RELEASE_READINESS_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-readiness-current-result.json"
$env:RELEASE_EVIDENCE_DRAFT_REVIEW_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-evidence-draft-review-current-result.json"
$env:RELEASE_EVIDENCE_PROMOTION_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-evidence-promotion-current-result.json"
$env:RELEASE_PR_CANDIDATES_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-pr-candidates-current-result.json"
$env:RELEASE_STAGED_SCOPE_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-staged-scope-current-result.json"
$env:RELEASE_EXTERNAL_STATE_FILE = Join-Path $resolvedEvidenceDir "release-external-state-current-evidence.json"
$env:RELEASE_EXTERNAL_STATE_RESULT_FILE = Join-Path $resolvedEvidenceDir "release-external-state-current-result.json"
$env:WORKTREE_QUARANTINE_DIR = Join-Path $resolvedEvidenceDir "worktree-quarantine-current"
$env:WORKTREE_QUARANTINE_MANIFEST_FILE = Join-Path $env:WORKTREE_QUARANTINE_DIR "manifest.json"
$env:RELEASE_EVIDENCE_GAP_PACK_FILE = Join-Path $resolvedEvidenceDir "release-evidence-gap-pack-current.json"
$env:RELEASE_EVIDENCE_GAP_PACK_MARKDOWN_FILE = Join-Path $resolvedEvidenceDir "release-evidence-gap-pack-current.md"
$env:RELEASE_OPERATOR_INTAKE_SOURCE_FILE = $env:RELEASE_EVIDENCE_GAP_PACK_FILE
$env:RELEASE_OPERATOR_INTAKE_PACKET_FILE = Join-Path $resolvedEvidenceDir "release-operator-intake-packet-current.json"
$env:RELEASE_OPERATOR_INTAKE_PACKET_MARKDOWN_FILE = Join-Path $resolvedEvidenceDir "release-operator-intake-packet-current.md"

Write-Host "Repo root: $repoRoot"
Write-Host "Evidence dir: $resolvedEvidenceDir"
Write-Host "RELEASE_EVIDENCE_DIR: $env:RELEASE_EVIDENCE_DIR"
if ([string]::IsNullOrWhiteSpace($env:RELEASE_PR_NUMBER)) {
    Write-Host "RELEASE_PR_NUMBER: <not set>"
} else {
    Write-Host "RELEASE_PR_NUMBER: $env:RELEASE_PR_NUMBER"
}

Push-Location -LiteralPath $repoRoot
try {
    $results = @()
    $results += Invoke-ReleaseCurrentStep "release evidence intake behavior contract" {
        npm.cmd run verify:release-evidence-intake
    }
    $results += Invoke-ReleaseCurrentStep "release readiness behavior contract" {
        npm.cmd run verify:release-readiness-contract
    }
    $results += Invoke-ReleaseCurrentStep "release evidence current result" {
        npm.cmd run review:release-evidence
    }
    $results += Invoke-ReleaseCurrentStep "release evidence draft review current result" {
        npm.cmd run review:release-evidence-drafts
    }
    $results += Invoke-ReleaseCurrentStep "release evidence draft promotion current result" {
        npm.cmd run promote:release-evidence-drafts
    }
    $results += Invoke-ReleaseCurrentStep "release PR candidates current result" {
        npm.cmd run review:release-pr-candidates
    }
    $results += Invoke-ReleaseCurrentStep "release staged scope current result" {
        npm.cmd run review:release-staged-scope
    }
    $results += Invoke-ReleaseCurrentStep "worktree quarantine current result" {
        $quarantineDir = [System.IO.Path]::GetFullPath($env:WORKTREE_QUARANTINE_DIR)
        $evidenceRoot = [System.IO.Path]::GetFullPath($resolvedEvidenceDir)
        $evidenceRootWithSeparator = $evidenceRoot.TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
        if (-not $quarantineDir.StartsWith($evidenceRootWithSeparator, [System.StringComparison]::OrdinalIgnoreCase)) {
            throw "Refusing to reset worktree quarantine outside evidence dir: $quarantineDir"
        }
        if (Test-Path -LiteralPath $quarantineDir) {
            Remove-Item -LiteralPath $quarantineDir -Recurse -Force
        }
        npm.cmd run export:worktree-quarantine -- "--output=$quarantineDir"
    }
    $results += Invoke-ReleaseCurrentStep "collect release external state current evidence" {
        if ([string]::IsNullOrWhiteSpace($PrNumber)) {
            powershell -NoProfile -ExecutionPolicy Bypass -File scripts/collect_release_external_state.ps1 -OutputPath $env:RELEASE_EXTERNAL_STATE_FILE
        } else {
            powershell -NoProfile -ExecutionPolicy Bypass -File scripts/collect_release_external_state.ps1 -OutputPath $env:RELEASE_EXTERNAL_STATE_FILE -PrNumber $PrNumber
        }
    }
    $results += Invoke-ReleaseCurrentStep "release external state current result" {
        npm.cmd run review:release-external-state
    }
    $results += Invoke-ReleaseCurrentStep "release readiness current result" {
        npm.cmd run review:release-readiness
    }
    $results += Invoke-ReleaseCurrentStep "release evidence gap pack current result" {
        npm.cmd run report:release-evidence-gap-pack
    }
    $results += Invoke-ReleaseCurrentStep "release evidence gap pack verification current result" {
        npm.cmd run verify:release-evidence-gap-pack
    }
    $results += Invoke-ReleaseCurrentStep "release operator intake packet current result" {
        npm.cmd run export:release-operator-intake
    }
    $results += Invoke-ReleaseCurrentStep "release operator intake packet verification current result" {
        npm.cmd run verify:release-operator-intake
    }
    $results += Invoke-ReleaseCurrentStep "release evidence gap pack parse check" {
        $gapPackPath = $env:RELEASE_EVIDENCE_GAP_PACK_FILE
        if (-not (Test-Path -LiteralPath $gapPackPath)) {
            throw "Missing release evidence gap pack: $gapPackPath"
        }
        $gapPackMarkdownPath = $env:RELEASE_EVIDENCE_GAP_PACK_MARKDOWN_FILE
        if (-not (Test-Path -LiteralPath $gapPackMarkdownPath)) {
            throw "Missing release evidence gap pack Markdown companion: $gapPackMarkdownPath"
        }

        $gapPack = Get-Content -LiteralPath $gapPackPath -Raw | ConvertFrom-Json
        $gapPackMarkdown = Get-Content -LiteralPath $gapPackMarkdownPath -Raw
        foreach ($requiredPhrase in @(
            "Status: not release-ready",
            "Do not treat this gap pack as release-ready evidence",
            "Operator Intake Packet",
            "Required operator-controlled inputs",
            "staging/isolation plan",
            "candidate_release_scope",
            "needs_explicit_operator_decision",
            "must_remain_local_by_default",
            "Current PR connector open PR count",
            "Current PR connector checked at",
            "Configured release PR number",
            "Selected release PR head",
            "External-state local HEAD",
            "External-state expected PR head",
            "Final PR head match status",
            "mcp__codex_apps__github._get_users_recent_prs_in_repo"
        )) {
            if (-not $gapPackMarkdown.Contains($requiredPhrase)) {
                throw "release evidence gap pack Markdown companion is missing required phrase: $requiredPhrase"
            }
        }

        if (-not $gapPack.readiness_close_sequence -or @($gapPack.readiness_close_sequence).Count -lt 1) {
            throw "release evidence gap pack is missing readiness_close_sequence"
        }
        if (-not $gapPack.operator_intake_packet -or -not $gapPack.operator_intake_packet.required_external_inputs) {
            throw "release evidence gap pack is missing operator_intake_packet.required_external_inputs"
        }
        if ($gapPack.operator_intake_packet.does_not_close_release_readiness -ne $true) {
            throw "release evidence gap pack operator_intake_packet must not close release readiness"
        }
        if (-not $gapPack.operator_intake_packet.worktree_staging_summary) {
            throw "release evidence gap pack operator_intake_packet is missing worktree_staging_summary"
        }
        if (-not $gapPack.operator_intake_packet.final_pr_head_status) {
            throw "release evidence gap pack operator_intake_packet is missing final_pr_head_status"
        }
        if ($gapPack.operator_intake_packet.final_pr_head_status.does_not_close_release_readiness -ne $true) {
            throw "release evidence gap pack final_pr_head_status must not close release readiness"
        }
        if ($gapPack.operator_intake_packet.final_pr_head_status.PSObject.Properties.Name -notcontains "configured_release_pr_number") {
            throw "release evidence gap pack final_pr_head_status is missing configured_release_pr_number"
        }
        $gapPackWorktreeSummary = $gapPack.operator_intake_packet.worktree_staging_summary
        if ($gapPackWorktreeSummary.does_not_close_release_readiness -ne $true) {
            throw "release evidence gap pack worktree_staging_summary must not close release readiness"
        }
        if (-not $gapPackWorktreeSummary.bucket_counts) {
            throw "release evidence gap pack worktree_staging_summary is missing bucket_counts"
        }
        foreach ($requiredBucket in @(
            "candidate_release_scope",
            "needs_explicit_operator_decision",
            "must_remain_local_by_default"
        )) {
            if ($gapPackWorktreeSummary.bucket_counts.PSObject.Properties.Name -notcontains $requiredBucket) {
                throw "release evidence gap pack worktree_staging_summary.bucket_counts is missing $requiredBucket"
            }
        }
        if (-not $gapPack.source_status.release_readiness_status.current_pr) {
            throw "release evidence gap pack is missing source_status.release_readiness_status.current_pr"
        }
        $gapPackCurrentPrConnector = $gapPack.source_status.release_readiness_status.current_pr.connector_evidence
        if (-not $gapPackCurrentPrConnector -or [string]$gapPackCurrentPrConnector.path -ne "docs/release_github_handoff_evidence.json") {
            throw "release evidence gap pack is missing GitHub current PR connector evidence"
        }
        if ($null -eq $gapPackCurrentPrConnector.pull_requests_count) {
            throw "release evidence gap pack GitHub connector evidence is missing pull_requests_count"
        }
        $gapPackOpenPrCount = [int]$gapPackCurrentPrConnector.pull_requests_count
        if ($gapPackOpenPrCount -lt 0) {
            throw "release evidence gap pack GitHub connector open PR count must be non-negative"
        }
        $gapPackOperatorInputIds = @($gapPack.operator_intake_packet.required_external_inputs | ForEach-Object { [string]$_.id })
        foreach ($requiredInputId in @(
            "design_handoff_manifest",
            "ota_credential_rotation_attestation",
            "final_release_pr_and_local_state"
        )) {
            if ($gapPackOperatorInputIds -notcontains $requiredInputId) {
                throw "release evidence gap pack operator_intake_packet is missing $requiredInputId"
            }
        }

        if (-not (Test-Path -LiteralPath $env:RELEASE_OPERATOR_INTAKE_PACKET_FILE)) {
            throw "Missing release operator intake packet: $env:RELEASE_OPERATOR_INTAKE_PACKET_FILE"
        }
        if (-not (Test-Path -LiteralPath $env:RELEASE_OPERATOR_INTAKE_PACKET_MARKDOWN_FILE)) {
            throw "Missing release operator intake packet Markdown companion: $env:RELEASE_OPERATOR_INTAKE_PACKET_MARKDOWN_FILE"
        }
        $operatorIntake = Get-Content -LiteralPath $env:RELEASE_OPERATOR_INTAKE_PACKET_FILE -Raw | ConvertFrom-Json
        $operatorIntakeMarkdown = Get-Content -LiteralPath $env:RELEASE_OPERATOR_INTAKE_PACKET_MARKDOWN_FILE -Raw
        if ($operatorIntake.release_ready -ne $false -or $operatorIntake.does_not_close_release_readiness -ne $true) {
            throw "release operator intake packet must not close release readiness"
        }
        if (-not $operatorIntake.operator_intake_packet -or -not $operatorIntake.operator_intake_packet.required_external_inputs) {
            throw "release operator intake packet is missing required_external_inputs"
        }
        if (-not $operatorIntake.operator_intake_packet.worktree_staging_summary) {
            throw "release operator intake packet is missing worktree_staging_summary"
        }
        if (-not $operatorIntake.operator_intake_packet.final_pr_head_status) {
            throw "release operator intake packet is missing final_pr_head_status"
        }
        if ($operatorIntake.operator_intake_packet.final_pr_head_status.does_not_close_release_readiness -ne $true) {
            throw "release operator intake packet final_pr_head_status must not close release readiness"
        }
        if ($operatorIntake.operator_intake_packet.final_pr_head_status.PSObject.Properties.Name -notcontains "configured_release_pr_number") {
            throw "release operator intake packet final_pr_head_status is missing configured_release_pr_number"
        }
        $operatorWorktreeSummary = $operatorIntake.operator_intake_packet.worktree_staging_summary
        if ($operatorWorktreeSummary.does_not_close_release_readiness -ne $true) {
            throw "release operator intake packet worktree_staging_summary must not close release readiness"
        }
        if (-not $operatorWorktreeSummary.bucket_counts) {
            throw "release operator intake packet worktree_staging_summary is missing bucket_counts"
        }
        foreach ($requiredBucket in @(
            "candidate_release_scope",
            "needs_explicit_operator_decision",
            "must_remain_local_by_default"
        )) {
            if ($operatorWorktreeSummary.bucket_counts.PSObject.Properties.Name -notcontains $requiredBucket) {
                throw "release operator intake packet worktree_staging_summary.bucket_counts is missing $requiredBucket"
            }
        }
        if (-not $operatorIntake.current_pr_status -or -not $operatorIntake.current_pr_status.connector_evidence) {
            throw "release operator intake packet is missing current_pr_status.connector_evidence"
        }
        if ([string]$operatorIntake.current_pr_status.connector_evidence.path -ne "docs/release_github_handoff_evidence.json") {
            throw "release operator intake packet current PR connector evidence must point to docs/release_github_handoff_evidence.json"
        }
        if ($null -eq $operatorIntake.current_pr_status.connector_evidence.pull_requests_count) {
            throw "release operator intake packet current PR connector evidence is missing pull_requests_count"
        }
        $operatorOpenPrCount = [int]$operatorIntake.current_pr_status.connector_evidence.pull_requests_count
        if ($operatorOpenPrCount -lt 0) {
            throw "release operator intake packet current PR connector open PR count must be non-negative"
        }
        $operatorInputIds = @($operatorIntake.operator_intake_packet.required_external_inputs | ForEach-Object { [string]$_.id })
        foreach ($requiredInputId in @(
            "design_handoff_manifest",
            "ota_credential_rotation_attestation",
            "final_release_pr_and_local_state"
        )) {
            if ($operatorInputIds -notcontains $requiredInputId) {
                throw "release operator intake packet is missing $requiredInputId"
            }
        }
        foreach ($requiredPhrase in @(
            "Release Operator Intake Packet",
            "This packet is an external-evidence intake checklist only",
            "Required operator-controlled inputs",
            "Current PR Handoff Evidence",
            "Connector open PR count",
            "Connector checked at",
            "| Connector open PR count |",
            "Current gh open PR count",
            "Configured release PR number",
            "Selected release PR head",
            "External-state local HEAD",
            "External-state expected PR head",
            "Final PR head match status",
            "Worktree Staging/Isolation Summary",
            "candidate_release_scope",
            "needs_explicit_operator_decision",
            "must_remain_local_by_default",
            "mcp__codex_apps__github._get_users_recent_prs_in_repo"
        )) {
            if (-not $operatorIntakeMarkdown.Contains($requiredPhrase)) {
                throw "release operator intake packet Markdown is missing required phrase: $requiredPhrase"
            }
        }

        if (-not $gapPack.source_status -or -not $gapPack.source_status.latest_release_draft_review_result) {
            throw "release evidence gap pack is missing source_status.latest_release_draft_review_result"
        }
        if (-not $gapPack.source_status.latest_release_promotion_result) {
            throw "release evidence gap pack is missing source_status.latest_release_promotion_result"
        }
        $promotionResult = $gapPack.source_status.latest_release_promotion_result
        if ([string]$promotionResult.path -ne $env:RELEASE_EVIDENCE_PROMOTION_RESULT_FILE) {
            throw "release evidence gap pack promotion result path is not the current result file"
        }
        if ($promotionResult.does_not_close_release_readiness -ne $true) {
            throw "release evidence gap pack promotion result must not close release readiness"
        }
        if ([string]$promotionResult.status -eq "passed" -and $promotionResult.can_promote -ne $true) {
            throw "release evidence gap pack promotion result is inconsistent"
        }

        $commands = @($gapPack.readiness_close_sequence | ForEach-Object { $_.command })
        foreach ($requiredCommand in @(
            "npm run review:release-design",
            "npm run review:release-ota-credentials",
            "npm run review:release-pr-candidates",
            "npm run review:release-staged-scope",
            "npm run review:release-external-state",
            "npm run review:release-readiness"
        )) {
            if ($commands -notcontains $requiredCommand) {
                throw "release evidence gap pack readiness_close_sequence is missing $requiredCommand"
            }
        }

        if (-not $gapPack.source_status -or -not $gapPack.source_status.local_worktree_close_plan) {
            throw "release evidence gap pack is missing source_status.local_worktree_close_plan"
        }

        $worktreePlan = $gapPack.source_status.local_worktree_close_plan
        if (-not $worktreePlan.quarantine_bundle) {
            throw "release evidence gap pack is missing local_worktree_close_plan.quarantine_bundle"
        }
        if ([string]$worktreePlan.quarantine_bundle.path -ne $env:WORKTREE_QUARANTINE_MANIFEST_FILE) {
            throw "release evidence gap pack quarantine manifest path is not the current manifest file"
        }
        $worktreePlanProperties = @($worktreePlan.PSObject.Properties.Name)
        foreach ($requiredProperty in @("status", "changed_entries", "categories", "acceptance_commands", "isolation_evidence", "staging_plan")) {
            if ($worktreePlanProperties -notcontains $requiredProperty) {
                throw "release evidence gap pack local_worktree_close_plan is missing $requiredProperty"
            }
        }

        $worktreePlanStatus = [string]$worktreePlan.status
        if ($worktreePlanStatus -notin @("clean", "blocked_until_clean_or_isolated")) {
            throw "release evidence gap pack local_worktree_close_plan has unexpected status $worktreePlanStatus"
        }

        if ($worktreePlan.quarantine_bundle) {
            $quarantineStatus = [string]$worktreePlan.quarantine_bundle.status
            if ($quarantineStatus -eq "stale_changed_path_mismatch") {
                throw "release evidence gap pack worktree quarantine bundle is stale; rerun npm run export:worktree-quarantine"
            }
        }

        $changedEntries = [int]$worktreePlan.changed_entries
        if ($changedEntries -gt 0 -and $worktreePlanStatus -ne "blocked_until_clean_or_isolated") {
            throw "release evidence gap pack local_worktree_close_plan must be blocked_until_clean_or_isolated when changed entries exist"
        }
        if ($changedEntries -eq 0 -and $worktreePlanStatus -ne "clean") {
            throw "release evidence gap pack local_worktree_close_plan must be clean when there are no changed entries"
        }
        if ($changedEntries -gt 0 -and [int]$worktreePlan.quarantine_bundle.changed_paths -ne $changedEntries) {
            throw "release evidence gap pack quarantine changed_paths must match current changed_entries"
        }

        $isolationEvidence = $worktreePlan.isolation_evidence
        $isolationEvidenceProperties = @($isolationEvidence.PSObject.Properties.Name)
        foreach ($requiredProperty in @("status", "quarantine_matches_current", "still_blocks_release", "required_next_step")) {
            if ($isolationEvidenceProperties -notcontains $requiredProperty) {
                throw "release evidence gap pack local_worktree_close_plan.isolation_evidence is missing $requiredProperty"
            }
        }
        if ($changedEntries -gt 0 -and $isolationEvidence.still_blocks_release -ne $true) {
            throw "release evidence gap pack isolation_evidence must keep dirty worktree as release-blocking"
        }
        if ($changedEntries -eq 0 -and $isolationEvidence.still_blocks_release -ne $false) {
            throw "release evidence gap pack isolation_evidence must not block release when the worktree is clean"
        }
        if ([string]$isolationEvidence.status -eq "current_dirty_state_preserved_not_release_closure" -and $isolationEvidence.quarantine_matches_current -ne $true) {
            throw "release evidence gap pack isolation_evidence cannot claim current preservation without a matching quarantine bundle"
        }

        $stagingPlan = $worktreePlan.staging_plan
        $stagingPlanProperties = @($stagingPlan.PSObject.Properties.Name)
        foreach ($requiredProperty in @("status", "counts", "buckets", "close_condition", "review_commands", "forbidden_actions")) {
            if ($stagingPlanProperties -notcontains $requiredProperty) {
                throw "release evidence gap pack local_worktree_close_plan.staging_plan is missing $requiredProperty"
            }
        }
        if ($changedEntries -gt 0 -and [string]$stagingPlan.status -ne "requires_review_before_release_pr") {
            throw "release evidence gap pack staging_plan must require review while the worktree is dirty"
        }
        $stagingForbiddenActions = @($stagingPlan.forbidden_actions)
        if (($stagingForbiddenActions -join "`n") -notmatch "Do not stage this plan automatically") {
            throw "release evidence gap pack staging_plan must forbid automatic staging"
        }

        $worktreePlanCommands = @($worktreePlan.acceptance_commands)
        foreach ($requiredCommand in @(
            "git status --short --branch",
            "npm run review:release-external-state",
            "npm run review:release-readiness"
        )) {
            if ($worktreePlanCommands -notcontains $requiredCommand) {
                throw "release evidence gap pack local_worktree_close_plan acceptance_commands is missing $requiredCommand"
            }
        }

        $localGitRequirement = @($gapPack.blocking_requirements | Where-Object { $_.id -eq "local-git-state-open" } | Select-Object -First 1)
        if (-not $localGitRequirement -or -not $localGitRequirement.worktree_close_plan) {
            throw "release evidence gap pack is missing local-git-state-open worktree_close_plan"
        }
        if ([string]$localGitRequirement.worktree_close_plan.status -ne $worktreePlanStatus) {
            throw "release evidence gap pack worktree close plan status is inconsistent"
        }

        "release evidence gap pack parse check passed"
    }

    Write-Host ""
    Write-Host "Current release evidence outputs:"
    foreach ($file in @(
        $env:RELEASE_EVIDENCE_RESULT_FILE,
        $env:RELEASE_EVIDENCE_DRAFT_REVIEW_RESULT_FILE,
        $env:RELEASE_EVIDENCE_PROMOTION_RESULT_FILE,
        $env:RELEASE_READINESS_RESULT_FILE,
        $env:RELEASE_PR_CANDIDATES_RESULT_FILE,
        $env:RELEASE_STAGED_SCOPE_RESULT_FILE,
        $env:WORKTREE_QUARANTINE_MANIFEST_FILE,
        $env:RELEASE_EXTERNAL_STATE_FILE,
        $env:RELEASE_EXTERNAL_STATE_RESULT_FILE,
        $env:RELEASE_EVIDENCE_GAP_PACK_FILE,
        $env:RELEASE_EVIDENCE_GAP_PACK_MARKDOWN_FILE,
        $env:RELEASE_OPERATOR_INTAKE_PACKET_FILE,
        $env:RELEASE_OPERATOR_INTAKE_PACKET_MARKDOWN_FILE
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
