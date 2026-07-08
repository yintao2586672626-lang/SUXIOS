<?php
declare(strict_types=1);

namespace app\controller\concern;

use think\Response;

trait ReleaseEvidenceConcern
{
    public function releaseEvidenceStatus(): Response
    {
        $this->checkPermission();
        if (!$this->currentUser->isSuperAdmin()) {
            abort(403, 'release evidence status requires super admin');
        }
        $this->checkActionPermission('can_view_online_data');

        $statusPath = $this->releaseEvidenceRepoPath('docs/release_readiness_status.json');
        $issueRegisterPath = $this->releaseEvidenceRepoPath('docs/release_issue_register.md');
        if (!is_file($statusPath)) {
            return $this->error('release readiness status file missing', 500, [
                'path' => 'docs/release_readiness_status.json',
            ]);
        }

        $status = json_decode((string)file_get_contents($statusPath), true);
        if (!is_array($status)) {
            return $this->error('release readiness status file is invalid JSON', 500, [
                'path' => 'docs/release_readiness_status.json',
            ]);
        }

        $blockers = array_values(array_filter(
            is_array($status['blockers'] ?? null) ? $status['blockers'] : [],
            static fn($row): bool => is_array($row) && strtolower((string)($row['status'] ?? '')) !== 'closed'
        ));

        return $this->success([
            'scope' => 'release evidence blockers only; no secrets; does not close release readiness',
            'source_files' => [
                [
                    'path' => 'docs/release_readiness_status.json',
                    'exists' => true,
                    'updated_at' => (string)($status['updated_at'] ?? ''),
                ],
                array_merge([
                    'path' => 'docs/release_issue_register.md',
                    'exists' => is_file($issueRegisterPath),
                ], $this->releaseEvidenceIssueRegisterMeta($issueRegisterPath)),
            ],
            'overall_status' => (string)($status['overall_status'] ?? 'unknown'),
            'release_ready' => ($status['release_readiness_check']['final_release_ready'] ?? false) === true,
            'does_not_close_release_readiness' => true,
            'blocking_requirements' => array_map(
                fn(array $row): array => $this->releaseEvidenceBlockerRow($row),
                $blockers
            ),
            'operator_intake_packet' => [
                'does_not_close_release_readiness' => true,
                'required_external_inputs' => $this->releaseEvidenceRequiredInputs($status),
            ],
            'source_status' => [
                'release_readiness_check' => $status['release_readiness_check'] ?? [],
                'external_state_check' => $status['external_state_check'] ?? [],
                'local_worktree_close_plan' => [
                    'status' => $this->releaseEvidenceWorktreeStatus($status),
                    'open_failures' => $status['external_state_check']['open_failures'] ?? [],
                ],
            ],
        ]);
    }

    private function releaseEvidenceRepoPath(string $relativePath): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    }

    private function releaseEvidenceIssueRegisterMeta(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $text = (string)file_get_contents($path);
        preg_match('/^Updated:\s*(.+)$/m', $text, $updated);
        preg_match('/^Status:\s*(.+)$/m', $text, $status);

        return [
            'updated_at' => trim((string)($updated[1] ?? '')),
            'status' => trim((string)($status[1] ?? '')),
        ];
    }

    private function releaseEvidenceBlockerRow(array $row): array
    {
        $id = (string)($row['id'] ?? '');

        return [
            'id' => $id,
            'status' => (string)($row['status'] ?? 'open'),
            'title' => (string)($row['title'] ?? $id),
            'evidence' => (string)($row['evidence'] ?? ''),
            'next_action' => (string)($row['close_condition'] ?? ''),
            'acceptance_command' => $this->releaseEvidenceAcceptanceCommand($id),
        ];
    }

    private function releaseEvidenceAcceptanceCommand(string $id): string
    {
        return [
            'design-handoff-missing' => 'npm run review:release-design',
            'ota-credential-rotation-attestation-missing' => 'npm run review:release-ota-credentials',
            'local-git-state-open' => 'npm run review:release-pr-candidates; npm run review:release-external-state',
        ][$id] ?? 'npm run review:release-readiness';
    }

    private function releaseEvidenceRequiredInputs(array $status): array
    {
        $externalStateStatus = (string)($status['external_state_check']['status'] ?? '');
        $finalPrInputReady = $externalStateStatus === 'passing_from_clean_verification_worktree';
        $designInputState = $this->releaseEvidenceInputStateFromBlocker($status, 'design-handoff-missing');
        $otaInputState = $this->releaseEvidenceInputStateFromBlocker($status, 'ota-credential-rotation-attestation-missing');

        return [
            [
                'id' => 'design_handoff_manifest',
                'status' => $designInputState['status'],
                'required_file' => '../release-evidence-temp/design_handoff_manifest.json',
                'creation_command' => 'npm run release:create-design-manifest',
                'isolated_review_command' => 'npm run review:release-design',
                'success_condition' => 'real controlled Figma, Canva, Brand Kit, design token path, required flows, owner, review date, and no open issues',
                'success_evidence' => $designInputState['success_evidence'],
                'next_action' => $designInputState['next_action'],
            ],
            [
                'id' => 'ota_credential_rotation_attestation',
                'status' => $otaInputState['status'],
                'required_file' => '../release-evidence-temp/ota_credential_rotation_attestation.json',
                'creation_command' => 'npm run release:create-ota-attestation',
                'isolated_review_command' => 'npm run review:release-ota-credentials',
                'success_condition' => 'credential-free Ctrip and Meituan rotation or invalidation attestation reviewed inside the 30-day release evidence window',
                'success_evidence' => $otaInputState['success_evidence'],
                'next_action' => $otaInputState['next_action'],
            ],
            [
                'id' => 'final_release_pr_and_local_state',
                'status' => $finalPrInputReady ? 'passed' : 'missing',
                'required_result_file' => '../release-evidence-temp/release-external-state-result.json',
                'selection_command' => 'npm run review:release-pr-candidates',
                'isolated_review_command' => 'npm run review:release-external-state',
                'success_condition' => 'selected open final PR, clean or intentionally isolated worktree, and matching local HEAD evidence',
                'success_evidence' => $finalPrInputReady
                    ? 'review:release-external-state passed from a clean checkout matching the selected release PR head'
                    : '',
                'next_action' => $finalPrInputReady
                    ? 'rerun review:release-pr-candidates, review:release-staged-scope, and review:release-external-state after every PR update'
                    : '',
            ],
        ];
    }

    private function releaseEvidenceInputStateFromBlocker(array $status, string $blockerId): array
    {
        foreach ((array)($status['blockers'] ?? []) as $blocker) {
            if (!is_array($blocker) || (string)($blocker['id'] ?? '') !== $blockerId) {
                continue;
            }

            if (strtolower((string)($blocker['status'] ?? '')) === 'closed') {
                return [
                    'status' => 'passed',
                    'success_evidence' => (string)($blocker['evidence'] ?? ''),
                    'next_action' => (string)($blocker['close_condition'] ?? ''),
                ];
            }

            return [
                'status' => 'missing',
                'success_evidence' => '',
                'next_action' => (string)($blocker['close_condition'] ?? ''),
            ];
        }

        return [
            'status' => 'missing',
            'success_evidence' => '',
            'next_action' => '',
        ];
    }

    private function releaseEvidenceWorktreeStatus(array $status): string
    {
        $failures = $status['external_state_check']['open_failures'] ?? [];
        $text = is_array($failures) ? implode("\n", array_map('strval', $failures)) : '';
        if (stripos($text, 'worktree') !== false || stripos($text, 'RELEASE_PR_NUMBER') !== false) {
            return 'blocked_until_clean_or_isolated';
        }

        return (string)($status['external_state_check']['status'] ?? 'unknown');
    }
}
