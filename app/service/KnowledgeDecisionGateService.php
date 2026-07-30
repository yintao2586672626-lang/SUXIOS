<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use Throwable;

/**
 * Shared runtime gate for knowledge retrieval and task-draft creation.
 *
 * The gate does not decide whether a statement is true. It decides how a
 * traceable statement may be used at the requested time:
 * approved decision support, reference only, known unknown, or blocked.
 */
final class KnowledgeDecisionGateService
{
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REFERENCE_ONLY = 'reference_only';
    public const STATUS_KNOWN_UNKNOWN = 'known_unknown';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param array<string, mixed> $unit
     * @param array<string, mixed> $content
     * @param DateTimeImmutable|string|null $asOf
     * @return array<string, mixed>
     */
    public function assess(array $unit, array $content, mixed $asOf = null): array
    {
        $asOfDate = $this->normalizeDate($asOf) ?? new DateTimeImmutable('now');
        $unitLifecycle = $this->normalizeLifecycle($unit['lifecycle_status'] ?? 'active');
        $chunkLifecycle = $this->normalizeLifecycle($content['lifecycle_status'] ?? 'active');
        $scope = strtolower(trim((string)($content['scope'] ?? '')));
        $evidenceLevel = strtolower(trim((string)($content['evidence_level'] ?? '')));
        $sourceRefs = $this->normalizeList($content['source_refs'] ?? []);
        $evidenceGrade = $this->classifyEvidenceGrade($content);
        $reviewedAt = $this->firstDate([
            $content['reviewed_at'] ?? null,
            $unit['reviewed_at'] ?? null,
            $content['accessed_at'] ?? null,
        ]);
        $validFrom = $this->firstDate([
            $content['valid_from'] ?? null,
            $content['effective_from'] ?? null,
            $content['effective_at'] ?? null,
        ]);
        $validUntil = $this->firstDate([
            $content['valid_until'] ?? null,
            $content['expires_at'] ?? null,
            $content['effective_until'] ?? null,
        ]);
        $reviewDueAt = $this->firstDate([
            $content['review_due_at'] ?? null,
            $unit['review_due_at'] ?? null,
        ]);
        $reviewIntervalDays = $this->normalizeReviewInterval(
            $content['review_interval_days'] ?? null,
            $evidenceGrade
        );
        if ($reviewDueAt === null && $reviewedAt !== null && $reviewIntervalDays > 0) {
            $reviewDueAt = $reviewedAt->modify('+' . $reviewIntervalDays . ' days');
        }

        $traceable = $scope !== '' && $evidenceLevel !== '' && $sourceRefs !== [];
        $conflictBoundary = $this->isConflictBoundary($content, $scope, $evidenceLevel);
        $knownUnknownBoundary = $conflictBoundary
            || $scope === 'known_unknown'
            || str_contains($evidenceLevel, 'explicit_unknown')
            || (is_array($content['unknowns'] ?? null) && $content['unknowns'] !== []);
        $requiresCurrentVerification = filter_var(
            $content['requires_current_verification'] ?? false,
            FILTER_VALIDATE_BOOL
        ) === true || str_contains($evidenceLevel, 'requires_current_session');
        $currentVerificationStatus = strtolower(trim((string)(
            $content['current_verification_status']
            ?? $content['live_verification_status']
            ?? ''
        )));
        $hasCurrentVerification = in_array(
            $currentVerificationStatus,
            ['verified', 'verified_current', 'matched', 'available'],
            true
        );

        $reasons = [];
        if ($unitLifecycle !== 'active') {
            $reasons[] = 'knowledge_unit_not_active';
        }
        if ($chunkLifecycle !== 'active') {
            $reasons[] = 'knowledge_chunk_not_active';
        }
        if (!$traceable) {
            $reasons[] = 'knowledge_traceability_missing';
        }
        if ($validFrom !== null && $validFrom > $asOfDate) {
            $reasons[] = 'knowledge_not_yet_effective';
        }
        if ($validUntil !== null && $validUntil < $asOfDate) {
            $reasons[] = 'knowledge_expired';
        }

        $hardBlocked = $reasons !== [];
        $freshnessStatus = 'current';
        if ($validFrom !== null && $validFrom > $asOfDate) {
            $freshnessStatus = 'not_yet_effective';
        } elseif ($validUntil !== null && $validUntil < $asOfDate) {
            $freshnessStatus = 'expired';
        } elseif ($reviewedAt === null && $reviewDueAt === null) {
            $freshnessStatus = 'undated';
            $reasons[] = 'knowledge_review_date_missing';
        } elseif ($reviewDueAt !== null && $reviewDueAt < $asOfDate) {
            $freshnessStatus = 'review_due';
            $reasons[] = 'knowledge_review_due';
        }

        if ($conflictBoundary) {
            $reasons[] = 'knowledge_conflict_unresolved';
        } elseif ($knownUnknownBoundary) {
            $reasons[] = 'knowledge_unknown_explicit';
        }
        if ($requiresCurrentVerification && !$hasCurrentVerification) {
            $reasons[] = 'knowledge_current_verification_required';
        }
        if ($evidenceGrade === 'D') {
            $reasons[] = 'knowledge_evidence_unverified';
        } elseif ($evidenceGrade === 'U') {
            $reasons[] = 'knowledge_evidence_unrated';
        }

        $status = self::STATUS_APPROVED;
        if ($hardBlocked) {
            $status = self::STATUS_BLOCKED;
        } elseif ($knownUnknownBoundary) {
            $status = self::STATUS_KNOWN_UNKNOWN;
        } elseif (
            $freshnessStatus !== 'current'
            || $requiresCurrentVerification
            || in_array($evidenceGrade, ['C', 'D', 'U'], true)
        ) {
            $status = self::STATUS_REFERENCE_ONLY;
        }

        $referenceSafe = !$hardBlocked;
        $retrievalSafe = $referenceSafe
            && ($knownUnknownBoundary || in_array($evidenceGrade, ['A', 'B', 'C'], true));
        $decisionSafe = $status === self::STATUS_APPROVED
            && in_array($evidenceGrade, ['A', 'B'], true)
            && (!$requiresCurrentVerification || $hasCurrentVerification);
        $taskDraftSafe = $referenceSafe
            && !$knownUnknownBoundary
            && $freshnessStatus === 'current'
            && in_array($evidenceGrade, ['A', 'B', 'C'], true)
            && (!$requiresCurrentVerification || $hasCurrentVerification)
            && !$this->blocksTaskDraft($content);

        $reasons = array_values(array_unique($reasons));

        return [
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'evidence_grade' => $evidenceGrade,
            'evidence_level' => $evidenceLevel,
            'freshness_status' => $freshnessStatus,
            'reviewed_at' => $this->formatDate($reviewedAt),
            'review_due_at' => $this->formatDate($reviewDueAt),
            'valid_from' => $this->formatDate($validFrom),
            'valid_until' => $this->formatDate($validUntil),
            'review_interval_days' => $reviewIntervalDays,
            'traceable' => $traceable,
            'conflict_boundary' => $conflictBoundary,
            'known_unknown_boundary' => $knownUnknownBoundary,
            'reference_safe' => $referenceSafe,
            'retrieval_safe' => $retrievalSafe,
            'decision_safe' => $decisionSafe,
            'task_draft_safe' => $taskDraftSafe,
            'reason_codes' => $reasons,
            'primary_reason' => $reasons[0] ?? '',
            'as_of' => $asOfDate->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Resolve only explicitly keyed factual conflicts.
     *
     * A newer date or higher evidence grade alone never silently wins. One
     * claim must be explicitly marked resolved/current/authoritative;
     * otherwise every conflicting factual claim is withheld.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array{
     *   entries: array<int, array<string, mixed>>,
     *   conflicts: array<int, array<string, mixed>>,
     *   resolved_conflict_count: int,
     *   unresolved_conflict_count: int,
     *   excluded_entry_count: int
     * }
     */
    public function resolveConflictingClaims(array $entries): array
    {
        $groups = [];
        foreach ($entries as $index => $entry) {
            $content = is_array($entry['content'] ?? null) ? $entry['content'] : [];
            $conflictKey = trim((string)($content['conflict_key'] ?? ''));
            if ($conflictKey === '' || !array_key_exists('claim_value', $content)) {
                continue;
            }
            $groups[$conflictKey][] = [
                'index' => $index,
                'claim' => $this->canonicalValue($content['claim_value']),
                'resolution_status' => strtolower(trim((string)(
                    $content['resolution_status']
                    ?? $content['conflict_status']
                    ?? ''
                ))),
                'chunk_id' => (int)($entry['chunk_id'] ?? 0),
            ];
        }

        $excluded = [];
        $conflicts = [];
        $resolvedCount = 0;
        $unresolvedCount = 0;

        foreach ($groups as $conflictKey => $candidates) {
            $claims = array_values(array_unique(array_column($candidates, 'claim')));
            if (count($claims) <= 1) {
                continue;
            }

            $resolved = array_values(array_filter(
                $candidates,
                static fn(array $candidate): bool => in_array(
                    $candidate['resolution_status'],
                    ['resolved', 'current', 'authoritative'],
                    true
                )
            ));

            if (count($resolved) === 1) {
                $winner = (int)$resolved[0]['index'];
                foreach ($candidates as $candidate) {
                    if ((int)$candidate['index'] !== $winner) {
                        $excluded[(int)$candidate['index']] = true;
                    }
                }
                $resolvedCount++;
                $conflicts[] = [
                    'conflict_key' => $conflictKey,
                    'status' => 'resolved',
                    'selected_chunk_id' => (int)$resolved[0]['chunk_id'],
                    'withheld_chunk_ids' => array_values(array_map(
                        static fn(array $candidate): int => (int)$candidate['chunk_id'],
                        array_values(array_filter(
                            $candidates,
                            static fn(array $candidate): bool => (int)$candidate['index'] !== $winner
                        ))
                    )),
                ];
                continue;
            }

            foreach ($candidates as $candidate) {
                $excluded[(int)$candidate['index']] = true;
            }
            $unresolvedCount++;
            $conflicts[] = [
                'conflict_key' => $conflictKey,
                'status' => 'unresolved',
                'selected_chunk_id' => null,
                'withheld_chunk_ids' => array_values(array_map(
                    static fn(array $candidate): int => (int)$candidate['chunk_id'],
                    $candidates
                )),
            ];
        }

        $kept = [];
        foreach ($entries as $index => $entry) {
            if (!isset($excluded[$index])) {
                $kept[] = $entry;
            }
        }

        return [
            'entries' => $kept,
            'conflicts' => $conflicts,
            'resolved_conflict_count' => $resolvedCount,
            'unresolved_conflict_count' => $unresolvedCount,
            'excluded_entry_count' => count($excluded),
        ];
    }

    /**
     * @param array<string, mixed> $content
     */
    public function classifyEvidenceGrade(array $content): string
    {
        $explicit = strtoupper(trim((string)($content['evidence_grade'] ?? '')));
        if (in_array($explicit, ['A', 'B', 'C', 'D', 'U'], true)) {
            return $explicit;
        }

        $level = strtolower(trim((string)($content['evidence_level'] ?? '')));
        if ($level === '') {
            return 'U';
        }

        if ($this->containsAny($level, [
            'unverified',
            'synthetic',
            'inferred',
            'unknown',
            'conflict',
            'not_runtime',
            'not_operational_fact',
            'collection_unverified',
            'not_assumed_current',
            'live_recheck_required',
        ])) {
            return 'D';
        }
        if ($this->containsAny($level, [
            'official_current',
            'official_versioned',
            'official_legal',
            'official_public_statistics',
            'official_vendor',
            'official_public_help',
            'official_public_course',
        ])) {
            return 'A';
        }
        if ($this->containsAny($level, [
            'verified',
            'runtime',
            'source_code_reviewed',
            'repository_state_reviewed',
            'repository_integration_contract',
            'integrated',
            'reviewed_correction',
        ])) {
            return 'B';
        }
        if ($this->containsAny($level, [
            'reviewed',
            'derived',
            'adapted',
            'distilled',
            'reference',
            'contract',
            'template',
            'association',
            'vendor',
            'user_provided',
            'decision_guardrail',
            'fact_contract',
        ])) {
            return 'C';
        }

        return 'U';
    }

    private function normalizeLifecycle(mixed $value): string
    {
        $status = strtolower(trim((string)$value));
        return in_array($status, ['active', 'stale', 'quarantined'], true)
            ? $status
            : 'quarantined';
    }

    /**
     * @return array<int, string>
     */
    private function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[,，\n]+/u', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $item): string => is_scalar($item) ? trim((string)$item) : '',
            $value
        ), static fn(string $item): bool => $item !== ''));
    }

    private function normalizeReviewInterval(mixed $value, string $grade): int
    {
        if (is_numeric($value)) {
            return max(1, min(3650, (int)$value));
        }

        return match ($grade) {
            'A', 'B' => 90,
            'C' => 180,
            'D' => 30,
            default => 0,
        };
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstDate(array $values): ?DateTimeImmutable
    {
        foreach ($values as $value) {
            $date = $this->normalizeDate($value);
            if ($date !== null) {
                return $date;
            }
        }
        return null;
    }

    private function normalizeDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_scalar($value) || trim((string)$value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable(trim((string)$value));
        } catch (Throwable) {
            return null;
        }
    }

    private function formatDate(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $content
     */
    private function isConflictBoundary(array $content, string $scope, string $evidenceLevel): bool
    {
        if (in_array($scope, ['version_conflict', 'conflict', 'known_unknown'], true)) {
            return true;
        }

        $conflictStatus = strtolower(trim((string)($content['conflict_status'] ?? '')));
        $decisionStatus = strtolower(trim((string)($content['decision_status'] ?? '')));
        if (in_array($conflictStatus, ['unresolved', 'conflicted'], true)
            || str_contains($decisionStatus, 'unresolved')
            || str_contains($decisionStatus, 'conflict')
        ) {
            return true;
        }
        if (!empty($content['conflicts'])) {
            return true;
        }

        return str_contains($evidenceLevel, 'conflict')
            && trim((string)($content['conflict_key'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $content
     */
    private function blocksTaskDraft(array $content): bool
    {
        $blockedUses = $this->normalizeList($content['blocked_uses'] ?? []);
        return array_intersect($blockedUses, [
            'operation_task_creation',
            'operation_execution',
            'automatic_operation_task',
            'automatic_ota_write',
        ]) !== [];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_APPROVED => '可用于决策支持',
            self::STATUS_REFERENCE_ONLY => '仅供参考',
            self::STATUS_KNOWN_UNKNOWN => '已知未知',
            default => '禁止使用',
        };
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function canonicalValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = $this->sortRecursive($value);
        }
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }
}
