<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;

/** A human-operated evidence workbench; no platform submission is performed here. */
final class ReviewAppealWorkbenchService
{
    public function normalize(array $input, ?array $previous, int $actor): array
    {
        $platform = $input['platform'] ?? '';
        if (!in_array($platform, ['ctrip', 'meituan'], true)) throw new InvalidArgumentException('workbench_appeal_platform_invalid');
        $body = ['platform' => $platform, 'review_date' => OperatingWorkbenchMetricsService::date((string)($input['review_date'] ?? '')),
            'review_reference' => OperatingWorkbenchMetricsService::text($input['review_reference'] ?? '', 300, true),
            'factual_description' => OperatingWorkbenchMetricsService::text($input['factual_description'] ?? '', 2000, true),
            'appeal_reason' => OperatingWorkbenchMetricsService::text($input['appeal_reason'] ?? '', 1000, true),
            'evidence' => [], 'status' => $input['status'] ?? 'draft', 'source_quality' => 'manual_unverified'];
        if (!in_array($body['status'], ['draft', 'evidence_ready', 'reviewed', 'submitted', 'accepted', 'rejected', 'withdrawn'], true)) throw new InvalidArgumentException('workbench_appeal_status_invalid');
        $rows = $input['evidence'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 20) throw new InvalidArgumentException('workbench_appeal_evidence_invalid');
        foreach ($rows as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('workbench_appeal_evidence_invalid');
            $body['evidence'][] = ['description' => OperatingWorkbenchMetricsService::text($row['description'] ?? '', 300, true),
                'source_ref' => OperatingWorkbenchMetricsService::text($row['source_ref'] ?? '', 300, true),
                'business_date' => OperatingWorkbenchMetricsService::date((string)($row['business_date'] ?? ''))];
        }
        if ($body['status'] !== 'draft' && !$body['evidence']) throw new InvalidArgumentException('workbench_appeal_evidence_required');
        $old = $previous['inputs'] ?? null;
        if ($old && ($old['platform'] !== $body['platform'] || $old['review_reference'] !== $body['review_reference'] || $old['review_date'] !== $body['review_date'])) throw new RuntimeException('workbench_appeal_identity_immutable', 409);
        $allowed = ['draft' => ['draft', 'evidence_ready'], 'evidence_ready' => ['draft', 'evidence_ready', 'reviewed'],
            'reviewed' => ['draft', 'reviewed', 'submitted', 'withdrawn'], 'submitted' => ['submitted', 'accepted', 'rejected', 'withdrawn'],
            'accepted' => ['accepted'], 'rejected' => ['rejected'], 'withdrawn' => ['withdrawn']];
        if (!in_array($body['status'], $allowed[$old['status'] ?? 'draft'], true)) throw new RuntimeException('workbench_appeal_transition_invalid', 409);
        $changed = $old && ($old['factual_description'] !== $body['factual_description'] || $old['appeal_reason'] !== $body['appeal_reason'] || $old['evidence'] !== $body['evidence']);
        if ($changed && in_array($old['status'], ['submitted', 'accepted', 'rejected', 'withdrawn'], true)) throw new RuntimeException('workbench_appeal_submitted_evidence_sealed', 409);
        if ($changed && in_array($body['status'], ['reviewed', 'submitted'], true)) throw new RuntimeException('workbench_appeal_changed_requires_review', 409);
        if ($body['status'] === 'reviewed' && ($input['human_review_confirmed'] ?? false) !== true) throw new InvalidArgumentException('workbench_appeal_human_review_required');
        $body['reviewed_by'] = $body['status'] === 'draft' ? null : (($old['reviewed_by'] ?? null) ?: ($body['status'] === 'reviewed' ? $actor : null));
        $body['platform_receipt'] = OperatingWorkbenchMetricsService::text($input['platform_receipt'] ?? '', 300, in_array($body['status'], ['submitted', 'accepted', 'rejected'], true));
        $body['result_reference'] = OperatingWorkbenchMetricsService::text($input['result_reference'] ?? '', 300, in_array($body['status'], ['accepted', 'rejected'], true));
        $body['case_note'] = OperatingWorkbenchMetricsService::text($input['case_note'] ?? '', 1000);
        $body['reusable_case'] = in_array($body['status'], ['accepted', 'rejected'], true) && ($input['reusable_case'] ?? false) === true && $body['case_note'] !== '';
        $body['draft_text'] = "评价引用：{$body['review_reference']}\n事实说明：{$body['factual_description']}\n申请复核理由：{$body['appeal_reason']}\n材料索引："
            . implode('；', array_map(fn($r) => $r['description'] . '（' . $r['business_date'] . '，' . $r['source_ref'] . '）', $body['evidence']))
            . "\n请由负责人核对材料与平台规则后在平台提交。本草稿未验证事实，未自动发送。";
        $body['automatic_appeal'] = false;
        $body['platform_result_verified'] = false;
        return $body;
    }
}
