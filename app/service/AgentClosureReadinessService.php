<?php
declare(strict_types=1);

namespace app\service;

use app\model\KnowledgeBase;

final class AgentClosureReadinessService
{
    public function enrichKnowledgeRows(iterable $rows, array $usageByKnowledgeId = []): array
    {
        $result = [];
        foreach ($rows as $row) {
            $data = $this->rowToArray($row);
            $knowledgeId = $this->intValue($data, 'id');
            $usage = $usageByKnowledgeId[$knowledgeId] ?? [];
            $data['knowledge_readiness'] = $this->buildKnowledgeReadiness(
                $data,
                is_array($usage) ? $usage : []
            );
            $result[] = $data;
        }

        return $result;
    }

    public function buildKnowledgeReadiness(array $row, array $usage = []): array
    {
        $isEnabled = $this->intValue($row, 'is_enabled', KnowledgeBase::STATUS_DISABLED) === KnowledgeBase::STATUS_ENABLED;
        $content = $this->stringValue($row, 'content');
        $keywords = $this->stringValue($row, 'keywords');
        $tags = $this->listValue($row['tags'] ?? []);
        $conversationCount = max(
            $this->intValue($usage, 'conversation_count'),
            $this->intValue($row, 'conversation_count')
        );
        $latestUsedAt = $this->stringValue($usage, 'latest_used_at');
        if ($latestUsedAt === '') {
            $latestUsedAt = $this->stringValue($row, 'latest_used_at');
        }

        if (!$isEnabled) {
            $readiness = $this->readiness('knowledge_disabled', '未启用', 20, false, '启用该知识或归档无效条目', [
                $this->missing('enabled_status', '启用状态', '确认知识是否应启用或归档'),
            ]);
        } else {
            $missing = [];
            if ($this->textLength($content) < 20) {
                $missing[] = $this->missing('content', '知识正文', '补充可回答的正文、适用边界和处理口径');
            }
            if ($keywords === '' && $tags === []) {
                $missing[] = $this->missing('retrieval_keywords', '检索关键词/标签', '补充关键词或标签，保证对话可命中');
            }

            if ($missing !== []) {
                $stage = $this->textLength($content) < 20 ? 'knowledge_missing_content' : 'knowledge_missing_keywords';
                $readiness = $this->readiness(
                    $stage,
                    '待补知识',
                    $stage === 'knowledge_missing_content' ? 30 : 45,
                    false,
                    '补齐正文和检索入口后再观察命中',
                    $missing
                );
            } elseif ($conversationCount > 0) {
                $readiness = $this->readiness('knowledge_active_used', '已启用被引用', 100, true, '持续复核命中质量和回答效果');
            } else {
                $readiness = $this->readiness('knowledge_active_unused', '已启用未引用', 65, false, '观察对话命中，必要时补关键词或调整内容', [
                    $this->missing('conversation_usage', '对话引用', '等待真实对话命中或补充检索关键词'),
                ]);
            }
        }

        $readiness['conversation_count'] = $conversationCount;
        $readiness['latest_used_at'] = $latestUsedAt;
        $readiness['can_edit_knowledge'] = true;

        return $this->withNotice($readiness);
    }

    private function readiness(
        string $stage,
        string $label,
        int $score,
        bool $closedLoop,
        string $nextAction,
        array $missingEvidence = []
    ): array {
        return [
            'stage' => $stage,
            'status_label' => $label,
            'score' => $score,
            'closed_loop' => $closedLoop,
            'next_action' => $nextAction,
            'missing_evidence' => $missingEvidence,
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
        if ($missing === []) {
            $readiness['notice'] = '已具备当前行的闭环证据';
            return $readiness;
        }

        $labels = array_map(static function (array $item): string {
            return (string) ($item['label'] ?? $item['code'] ?? '未命名缺口');
        }, $missing);
        $readiness['notice'] = '仍缺：' . implode('、', array_slice($labels, 0, 4));

        return $readiness;
    }

    private function rowToArray(mixed $row): array
    {
        if (is_array($row)) {
            return $row;
        }
        if (is_object($row) && method_exists($row, 'toArray')) {
            return $row->toArray();
        }

        return (array) $row;
    }

    private function intValue(array $row, string $key, int $default = 0): int
    {
        if (!isset($row[$key]) || $row[$key] === '') {
            return $default;
        }

        return (int) $row[$key];
    }

    private function stringValue(array $row, string $key): string
    {
        if (!isset($row[$key]) || $row[$key] === null) {
            return '';
        }

        return trim((string) $row[$key]);
    }

    private function listValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn(mixed $item): bool => trim((string) $item) !== ''));
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode(trim($value), true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, static fn(mixed $item): bool => trim((string) $item) !== ''));
        }

        $items = preg_split('/[,，;；]+/u', trim($value)) ?: [];
        return array_values(array_filter(array_map('trim', $items), static fn(string $item): bool => $item !== ''));
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
