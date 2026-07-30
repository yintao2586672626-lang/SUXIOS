<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use Throwable;

final class KnowledgeCenterReadinessService
{
    public function buildUnitReadiness(array $row, int $chunkCount): array
    {
        $status = trim((string)($row['status'] ?? 'pending'));
        $lifecycleStatus = strtolower(trim((string)($row['lifecycle_status'] ?? 'active')));
        if (!in_array($lifecycleStatus, ['active', 'stale', 'quarantined'], true)) {
            $lifecycleStatus = 'active';
        }
        $lifecycleReason = trim((string)($row['lifecycle_reason'] ?? ''));
        $knownKnowns = $this->normalizeStatements($row['known_knowns'] ?? []);
        $knownUnknowns = $this->normalizeStatements($row['known_unknowns'] ?? []);
        $truthProfileVersion = trim((string)($row['truth_profile_version'] ?? ''));
        $hotelId = (int)($row['hotel_id'] ?? 0);
        $createdBy = array_key_exists('created_by', $row)
            ? (int)$row['created_by']
            : null;
        $reviewedAt = $this->normalizeDate($row['reviewed_at'] ?? null);
        $reviewDueAt = $this->normalizeDate($row['review_due_at'] ?? null);
        $asOf = $this->normalizeDate($row['_as_of'] ?? null) ?? new DateTimeImmutable('now');
        $chunkCount = max(0, $chunkCount);
        $finalize = fn(array $readiness): array => $this->withTemporalStatus(
            $this->withNotice(
                $readiness,
                $lifecycleStatus,
                $lifecycleReason,
                $knownKnowns,
                $knownUnknowns,
                $truthProfileVersion
            ),
            $reviewedAt,
            $reviewDueAt,
            $asOf
        );

        if ($lifecycleStatus === 'quarantined') {
            return $finalize($this->readiness('unit_quarantined', '已隔离', 0, false, '复核来源和当前合同后重新生成或恢复', [
                $this->missing('lifecycle_quarantined', '生命周期已隔离', '复核隔离原因，按当前数据与决策合同重新生成'),
            ], $chunkCount, $hotelId));
        }

        if ($lifecycleStatus === 'stale') {
            return $finalize($this->readiness('unit_stale', '待复核', 20, false, '复核来源、口径与当前实现后再恢复检索', [
                $this->missing('lifecycle_stale', '知识已过期', '复核来源、指标口径和当前运行时实现'),
            ], $chunkCount, $hotelId));
        }

        if ($status === 'error') {
            return $finalize($this->readiness('unit_error', '读取异常', 10, false, '查看异常原因后重新导入或删除', [
                $this->missing('error_status', '异常状态', '重新导入资料或删除无效单元'),
            ], $chunkCount, $hotelId));
        }

        if ($status !== 'done') {
            return $finalize($this->readiness('unit_pending', '待读取', 25, false, '完成 AI 读取并生成知识片段', [
                $this->missing('processed_status', '读取完成状态', '等待读取完成或重新触发导入'),
            ], $chunkCount, $hotelId));
        }

        if ($chunkCount <= 0) {
            return $finalize($this->readiness('unit_done_no_chunks', '缺少片段', 40, false, '补充至少一个可检索知识片段', [
                $this->missing('knowledge_chunks', '知识片段', '补充可检索片段后再用于分析或问答'),
            ], $chunkCount, $hotelId));
        }

        if ($knownKnowns === [] || $knownUnknowns === [] || $truthProfileVersion === '') {
            $missingTruth = [];
            if ($knownKnowns === []) {
                $missingTruth[] = $this->missing('known_knowns', '已确认知识', '补充有来源、有范围的已确认事实或方法');
            }
            if ($knownUnknowns === []) {
                $missingTruth[] = $this->missing('known_unknowns', '待验证知识', '明确记录仍缺的数据、验证或能力');
            }
            if ($truthProfileVersion === '') {
                $missingTruth[] = $this->missing('truth_profile_version', '真值档案版本', '记录本次知识复核版本');
            }

            return $finalize($this->readiness(
                'unit_truth_map_missing',
                '真值边界待补',
                55,
                false,
                '补齐已确认、待验证和真值档案版本',
                $missingTruth,
                $chunkCount,
                $hotelId
            ));
        }

        if ($reviewDueAt !== null && $reviewDueAt < $asOf) {
            return $finalize($this->readiness(
                'unit_review_due',
                '知识待复核',
                75,
                false,
                '重新核对来源版本、适用范围和当前实现后更新复核日期',
                [
                    $this->missing(
                        'knowledge_review_due',
                        '知识已到复核日期',
                        '完成来源与口径复核后更新 reviewed_at 和 review_due_at'
                    ),
                ],
                $chunkCount,
                $hotelId
            ));
        }

        if ($hotelId <= 0) {
            if ($createdBy === 0) {
                return $finalize($this->readiness(
                    'unit_global_reference',
                    '通用知识可检索',
                    100,
                    true,
                    '按来源版本复核，不绑定为任何单店事实',
                    [],
                    $chunkCount,
                    $hotelId
                ));
            }
            return $finalize($this->readiness('unit_global_scope', '通用范围', 70, false, '绑定门店，或明确保留为通用知识', [
                $this->missing('hotel_scope', '门店范围', '绑定具体门店或保留通用范围说明'),
            ], $chunkCount, $hotelId));
        }

        return $finalize(
            $this->readiness('unit_ready', '可检索', 100, true, '保留片段并按需复核命中质量', [], $chunkCount, $hotelId)
        );
    }

    private function readiness(string $stage, string $label, int $score, bool $closedLoop, string $nextAction, array $missingEvidence, int $chunkCount, int $hotelId): array
    {
        return [
            'stage' => $stage,
            'status_label' => $label,
            'score' => $score,
            'closed_loop' => $closedLoop,
            'next_action' => $nextAction,
            'missing_evidence' => $missingEvidence,
            'chunk_count' => $chunkCount,
            'hotel_id' => $hotelId,
            'can_open_chunks' => true,
            'can_edit_unit' => true,
        ];
    }

    private function missing(string $code, string $label, string $nextAction): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'next_action' => $nextAction,
        ];
    }

    private function withNotice(
        array $readiness,
        string $lifecycleStatus,
        string $lifecycleReason,
        array $knownKnowns,
        array $knownUnknowns,
        string $truthProfileVersion
    ): array
    {
        $readiness['lifecycle_status'] = $lifecycleStatus;
        $readiness['lifecycle_reason'] = $lifecycleReason;
        $readiness['known_known_count'] = count($knownKnowns);
        $readiness['known_unknown_count'] = count($knownUnknowns);
        $readiness['truth_profile_version'] = $truthProfileVersion;
        $readiness['truth_profile_status'] = $knownKnowns !== []
            && $knownUnknowns !== []
            && $truthProfileVersion !== ''
                ? 'mapped'
                : 'missing';
        $missing = $readiness['missing_evidence'] ?? [];
        if (!$missing) {
            $readiness['notice'] = '当前知识单元具备可检索证据';
            return $readiness;
        }

        $labels = array_map(static fn(array $item): string => (string)($item['label'] ?? $item['code'] ?? '未命名缺口'), $missing);
        $readiness['notice'] = '仍缺：' . implode('、', array_slice($labels, 0, 4));

        return $readiness;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStatements(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $item): string => is_scalar($item) ? trim((string)$item) : '',
            $value
        ), static fn(string $item): bool => $item !== ''));
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

    private function withTemporalStatus(
        array $readiness,
        ?DateTimeImmutable $reviewedAt,
        ?DateTimeImmutable $reviewDueAt,
        DateTimeImmutable $asOf
    ): array {
        $readiness['reviewed_at'] = $reviewedAt?->format('Y-m-d H:i:s');
        $readiness['review_due_at'] = $reviewDueAt?->format('Y-m-d H:i:s');
        $readiness['freshness_status'] = $reviewDueAt === null
            ? ($reviewedAt === null ? 'undated' : 'current_no_due_date')
            : ($reviewDueAt < $asOf ? 'review_due' : 'current');
        return $readiness;
    }
}
