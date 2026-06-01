<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

class KnowledgeMaterialIngestionService
{
    private const LINK_LIKE_MODES = ['video', 'link', 'url'];
    private const SINGLE_DOCUMENT_MODES = ['document', 'doc', 'docx'];

    public function __construct(private ?LlmClient $llmClient = null)
    {
        $this->llmClient = $llmClient ?? new LlmClient();
    }

    /**
     * @return array<int, string>
     */
    public function splitRawMaterials(string $raw, string $mode): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $mode = strtolower(trim($mode));
        if (in_array($mode, self::SINGLE_DOCUMENT_MODES, true)) {
            return [$raw];
        }

        $parts = in_array($mode, self::LINK_LIKE_MODES, true)
            ? preg_split('/\r?\n/u', $raw)
            : preg_split('/\n\s*\n/u', $raw);

        $materials = [];
        foreach ($parts ?: [] as $part) {
            $text = trim((string)$part);
            if ($text !== '') {
                $materials[] = $text;
            }
        }

        return $materials;
    }

    /**
     * @param array<string, mixed> $material
     * @return array<string, mixed>
     */
    public function distillMaterial(array $material): array
    {
        $mode = mb_substr(strtolower(trim((string)($material['mode'] ?? 'text'))), 0, 40);
        $source = mb_substr(trim((string)($material['source'] ?? $mode)), 0, 50);
        $content = trim((string)($material['content'] ?? ''));
        $hotelId = (int)($material['hotel_id'] ?? 0);
        $hotelName = trim((string)($material['hotel_name'] ?? ''));
        $modelKey = trim((string)($material['model_key'] ?? 'deepseek_chat'));

        if ($mode === '') {
            $mode = 'text';
        }
        if ($source === '') {
            $source = $mode;
        }
        if ($content === '') {
            throw new RuntimeException('knowledge material content is empty');
        }
        if ($hotelId <= 0) {
            throw new RuntimeException('knowledge material must bind to a valid hotel_id');
        }
        if ($modelKey === '') {
            $modelKey = 'deepseek_chat';
        }

        $messages = [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    '你是宿析OS的门店知识蒸馏助手。',
                    '只读取用户提交的资料，蒸馏成可被 OTA 数据分析、收益分析和运营复盘引用的门店知识。',
                    '不要编造资料未写明的门店事实；资料未说明的房价、库存、早餐、设施、评分、政策等必须写入 boundaries。',
                    '区分事实、分析提示和行动建议，结论必须可追溯到原文。',
                    '输出必须是 JSON，不要输出 Markdown。',
                ]),
            ],
            [
                'role' => 'user',
                'content' => implode("\n", [
                    '门店ID：' . $hotelId,
                    '门店名称：' . ($hotelName !== '' ? $hotelName : '未填写'),
                    '资料类型：' . $mode,
                    '资料来源：' . $source,
                    '原始资料：',
                    $content,
                ]),
            ],
        ];

        $data = $this->llmClient->createJsonResponse($messages, $this->schema(), $modelKey);

        $title = mb_substr(trim((string)($data['title'] ?? '')), 0, 120);
        $summary = trim((string)($data['summary'] ?? ''));
        if ($title === '') {
            $title = $this->defaultTitle($mode, $content);
        }
        if ($summary === '') {
            $summary = 'AI 已读取资料，但未返回摘要。';
        }

        return [
            'title' => $title,
            'summary' => $summary,
            'hotel_id' => $hotelId,
            'hotel_name' => $hotelName,
            'material_type' => $mode,
            'source' => $source,
            'raw_text' => $content,
            'hotel_profile' => is_array($data['hotel_profile'] ?? null) ? $data['hotel_profile'] : [],
            'facts' => $this->normalizeStringList($data['facts'] ?? []),
            'analysis_hints' => $this->normalizeStringList($data['analysis_hints'] ?? []),
            'actions' => $this->normalizeStringList($data['actions'] ?? []),
            'boundaries' => $this->normalizeStringList($data['boundaries'] ?? []),
            'keywords' => $this->normalizeStringList($data['keywords'] ?? [], 12),
            'confidence_score' => $this->normalizeScore($data['confidence_score'] ?? null),
            'model_key' => $modelKey,
            'distilled_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title', 'summary', 'facts', 'analysis_hints', 'actions', 'boundaries', 'keywords', 'confidence_score'],
            'properties' => [
                'title' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'hotel_profile' => ['type' => 'object'],
                'facts' => ['type' => 'array', 'items' => ['type' => 'string']],
                'analysis_hints' => ['type' => 'array', 'items' => ['type' => 'string']],
                'actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'boundaries' => ['type' => 'array', 'items' => ['type' => 'string']],
                'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                'confidence_score' => ['type' => 'number'],
            ],
        ];
    }

    private function defaultTitle(string $mode, string $content): string
    {
        $firstLine = trim((string)preg_split('/\r?\n/u', $content)[0]);
        if ($firstLine !== '') {
            return mb_substr($firstLine, 0, 60);
        }

        return ($mode !== '' ? $mode : 'text') . '资料蒸馏';
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeStringList($value, int $limit = 20): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : preg_split('/[,，\n]+/u', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = mb_substr(trim((string)$item), 0, 160);
            if ($text !== '') {
                $items[$text] = $text;
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return array_values($items);
    }

    private function normalizeScore($value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float)$value));
    }
}
