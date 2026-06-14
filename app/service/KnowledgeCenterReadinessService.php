<?php
declare(strict_types=1);

namespace app\service;

final class KnowledgeCenterReadinessService
{
    public function buildUnitReadiness(array $row, int $chunkCount): array
    {
        $status = trim((string)($row['status'] ?? 'pending'));
        $hotelId = (int)($row['hotel_id'] ?? 0);
        $chunkCount = max(0, $chunkCount);

        if ($status === 'error') {
            return $this->withNotice($this->readiness('unit_error', '读取异常', 10, false, '查看异常原因后重新导入或删除', [
                $this->missing('error_status', '异常状态', '重新导入资料或删除无效单元'),
            ], $chunkCount, $hotelId));
        }

        if ($status !== 'done') {
            return $this->withNotice($this->readiness('unit_pending', '待读取', 25, false, '完成 AI 读取并生成知识片段', [
                $this->missing('processed_status', '读取完成状态', '等待读取完成或重新触发导入'),
            ], $chunkCount, $hotelId));
        }

        if ($chunkCount <= 0) {
            return $this->withNotice($this->readiness('unit_done_no_chunks', '缺少片段', 40, false, '补充至少一个可检索知识片段', [
                $this->missing('knowledge_chunks', '知识片段', '补充可检索片段后再用于分析或问答'),
            ], $chunkCount, $hotelId));
        }

        if ($hotelId <= 0) {
            return $this->withNotice($this->readiness('unit_global_scope', '通用范围', 70, false, '绑定门店，或明确保留为通用知识', [
                $this->missing('hotel_scope', '门店范围', '绑定具体门店或保留通用范围说明'),
            ], $chunkCount, $hotelId));
        }

        return $this->withNotice($this->readiness('unit_ready', '可检索', 100, true, '保留片段并按需复核命中质量', [], $chunkCount, $hotelId));
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

    private function withNotice(array $readiness): array
    {
        $missing = $readiness['missing_evidence'] ?? [];
        if (!$missing) {
            $readiness['notice'] = '当前知识单元具备可检索证据';
            return $readiness;
        }

        $labels = array_map(static fn(array $item): string => (string)($item['label'] ?? $item['code'] ?? '未命名缺口'), $missing);
        $readiness['notice'] = '仍缺：' . implode('、', array_slice($labels, 0, 4));

        return $readiness;
    }
}
