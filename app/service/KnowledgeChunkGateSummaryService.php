<?php
declare(strict_types=1);

namespace app\service;

final class KnowledgeChunkGateSummaryService
{
    /**
     * @param array<int, array<string, mixed>> $units
     * @param array<int, array<string, mixed>> $chunks
     * @return array<int, array<string, int>>
     */
    public function summarize(array $units, array $chunks): array
    {
        $unitsById = [];
        $summaries = [];
        foreach ($units as $unit) {
            $unitId = (int)($unit['unit_id'] ?? 0);
            if ($unitId <= 0) {
                continue;
            }
            $unitsById[$unitId] = $unit;
            $summaries[$unitId] = [
                'total_count' => 0,
                'retrieval_safe_count' => 0,
                'decision_safe_count' => 0,
                'task_draft_safe_count' => 0,
                'reference_only_count' => 0,
                'known_unknown_count' => 0,
                'blocked_count' => 0,
                'review_due_count' => 0,
                'resolved_conflict_count' => 0,
                'unresolved_conflict_count' => 0,
                'withheld_conflict_chunk_count' => 0,
            ];
        }

        $entriesByUnit = [];
        foreach ($chunks as $chunk) {
            $unitId = (int)($chunk['unit_id'] ?? 0);
            if (!isset($unitsById[$unitId], $summaries[$unitId])) {
                continue;
            }
            $content = $chunk['content'] ?? [];
            if (is_string($content)) {
                $decoded = json_decode($content, true);
                $content = is_array($decoded) ? $decoded : [];
            }
            $content = is_array($content) ? $content : [];
            $summaries[$unitId]['total_count']++;
            $entriesByUnit[$unitId][] = [
                'chunk_id' => (int)($chunk['chunk_id'] ?? 0),
                'content' => $content,
            ];
        }

        $gateService = new KnowledgeDecisionGateService();
        foreach ($entriesByUnit as $unitId => $entries) {
            $resolution = $gateService->resolveConflictingClaims($entries);
            $summaries[$unitId]['resolved_conflict_count'] = (int)($resolution['resolved_conflict_count'] ?? 0);
            $summaries[$unitId]['unresolved_conflict_count'] = (int)($resolution['unresolved_conflict_count'] ?? 0);
            $summaries[$unitId]['withheld_conflict_chunk_count'] = (int)($resolution['excluded_entry_count'] ?? 0);

            foreach ((array)($resolution['entries'] ?? []) as $entry) {
                $content = is_array($entry['content'] ?? null) ? $entry['content'] : [];
                $gate = $gateService->assess($unitsById[$unitId], $content);
                foreach ([
                    'retrieval_safe' => 'retrieval_safe_count',
                    'decision_safe' => 'decision_safe_count',
                    'task_draft_safe' => 'task_draft_safe_count',
                ] as $gateField => $countField) {
                    if (($gate[$gateField] ?? false) === true) {
                        $summaries[$unitId][$countField]++;
                    }
                }
                $status = (string)($gate['status'] ?? KnowledgeDecisionGateService::STATUS_BLOCKED);
                if ($status === KnowledgeDecisionGateService::STATUS_REFERENCE_ONLY) {
                    $summaries[$unitId]['reference_only_count']++;
                } elseif ($status === KnowledgeDecisionGateService::STATUS_KNOWN_UNKNOWN) {
                    $summaries[$unitId]['known_unknown_count']++;
                } elseif ($status === KnowledgeDecisionGateService::STATUS_BLOCKED) {
                    $summaries[$unitId]['blocked_count']++;
                }
                $reasons = array_values((array)($gate['reason_codes'] ?? []));
                if (in_array('knowledge_review_due', $reasons, true)) {
                    $summaries[$unitId]['review_due_count']++;
                }
                if (in_array('knowledge_conflict_unresolved', $reasons, true)) {
                    $summaries[$unitId]['unresolved_conflict_count']++;
                }
            }
            $summaries[$unitId]['unresolved_conflict_count'] = min(
                $summaries[$unitId]['total_count'],
                $summaries[$unitId]['unresolved_conflict_count']
            );
        }

        return $summaries;
    }
}
