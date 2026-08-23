<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use think\facade\Db;

/** Hotel-scoped hybrid retrieval over verified operating memories. */
final class OperatingMemoryRetrievalService
{
    public const CONTRACT_VERSION = 'operating_memory_retrieval.v1';
    public const METHOD = 'metadata_filtered_lexical_memory_v1';
    public const HYBRID_METHOD = 'metadata_filtered_hybrid_memory_v1';

    private Closure $embedder;

    public function __construct(?callable $embedder = null)
    {
        $this->embedder = Closure::fromCallable($embedder ?? static fn(array $texts): array => (
            new OllamaEmbeddingService()
        )->embed($texts));
    }

    /** @return array<string,mixed> */
    public function retrieve(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $question,
        string $dateStart,
        string $dateEnd,
        int $limit = 5
    ): array {
        $base = [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'no_match',
            'method' => self::METHOD,
            'matched_count' => 0,
            'returned_count' => 0,
            'excluded_count' => 0,
            'reason' => null,
            'embedding' => [
                'status' => 'not_attempted',
                'model' => LocalAiRuntimeService::EMBEDDING_MODEL,
                'dimension' => null,
                'local_only' => true,
            ],
            'items' => [],
        ];
        if ($tenantId <= 0 || $hotelId <= 0 || trim($question) === '') {
            $base['status'] = 'unavailable';
            $base['reason'] = 'invalid_scope_or_question';
            return $base;
        }
        try {
            $query = Db::name(OperatingMemoryService::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->whereBetween('business_date', [$dateStart, $dateEnd])
                ->where('quality_status', 'verified')
                ->whereIn('usage_level', ['reference', 'decision_support'])
                ->where('lifecycle_status', 'active')
                ->whereNull('deleted_at');
            $query->where('source_scope', 'ota_channel');
            if ($platform === 'all_ota') {
                $query->whereIn('platform', ['ctrip', 'meituan', 'all_ota']);
            } else {
                $query->where('platform', $platform);
            }
            $rows = $query->field('id,memory_layer,title,summary,quality_status,usage_level,business_date,platform')
                ->order('id', 'desc')->limit(80)->select()->toArray();
        } catch (\Throwable) {
            $base['status'] = 'unavailable';
            $base['reason'] = 'operating_memory_table_missing_or_unreadable';
            return $base;
        }

        $terms = $this->terms($question);
        $lexicalScores = [];
        foreach ($rows as $index => $row) {
            $title = (string)($row['title'] ?? '');
            $summary = (string)($row['summary'] ?? '');
            $score = $this->score($title, $terms, 4.0) + $this->score($summary, $terms, 2.0);
            $lexicalScores[(int)$row['id']] = $score;
        }
        $semanticScores = [];
        $semanticMeta = ['status' => 'unavailable', 'model' => LocalAiRuntimeService::EMBEDDING_MODEL, 'dimension' => null, 'local_only' => true];
        $semanticRows = array_slice($rows, 0, 30);
        if ($semanticRows !== []) {
            try {
                $documents = array_map(static fn(array $row): string => mb_substr(
                    trim((string)($row['title'] ?? '')) . "\n" . trim((string)($row['summary'] ?? '')),
                    0,
                    800
                ), $semanticRows);
                $embedded = ($this->embedder)(array_merge([$question], $documents));
                $vectors = is_array($embedded['embeddings'] ?? null) ? $embedded['embeddings'] : [];
                if (count($vectors) !== count($semanticRows) + 1) {
                    throw new \RuntimeException('embedding_count_mismatch');
                }
                $queryVector = array_shift($vectors);
                foreach ($semanticRows as $index => $row) {
                    $semanticScores[(int)$row['id']] = max(0.0, $this->cosine((array)$queryVector, (array)$vectors[$index]));
                }
                $semanticMeta = [
                    'status' => 'ready',
                    'model' => (string)($embedded['model'] ?? LocalAiRuntimeService::EMBEDDING_MODEL),
                    'dimension' => (int)($embedded['dimension'] ?? count((array)$queryVector)),
                    'local_only' => true,
                ];
            } catch (\Throwable) {
                $semanticMeta['status'] = 'fallback_lexical';
            }
        }
        $method = $semanticMeta['status'] === 'ready' ? self::HYBRID_METHOD : self::METHOD;
        $scored = [];
        foreach ($rows as $index => $row) {
            $id = (int)$row['id'];
            $lexical = (float)($lexicalScores[$id] ?? 0.0);
            $semantic = (float)($semanticScores[$id] ?? 0.0);
            if ($lexical <= 0.0 && $semantic < 0.35) {
                continue;
            }
            $combined = $method === self::HYBRID_METHOD
                ? (0.40 * min(1.0, $lexical / 24.0)) + (0.60 * $semantic)
                : min(1.0, $lexical / 24.0);
            $scored[] = [
                'score' => round($combined + max(0, 0.008 - ($index * 0.0001)), 6),
                'lexical_score' => round($lexical, 4),
                'semantic_score' => $method === self::HYBRID_METHOD ? round($semantic, 6) : null,
                'row' => $row,
            ];
        }
        usort($scored, static fn(array $left, array $right): int => ($right['score'] <=> $left['score'])
            ?: ((int)$right['row']['id'] <=> (int)$left['row']['id']));
        $matchedCount = count($scored);
        $items = array_map(static fn(array $item): array => [
            'ref' => 'hotel_operating_memories#' . (int)$item['row']['id'],
            'memory_layer' => (string)$item['row']['memory_layer'],
            'title' => (string)$item['row']['title'],
            'summary' => (string)$item['row']['summary'],
            'quality_status' => (string)$item['row']['quality_status'],
            'usage_level' => (string)$item['row']['usage_level'],
            'business_date' => $item['row']['business_date'] ?? null,
            'platform' => (string)$item['row']['platform'],
            'retrieval_score' => (float)$item['score'],
            'lexical_score' => (float)$item['lexical_score'],
            'semantic_score' => $item['semantic_score'],
            'retrieval_method' => $item['semantic_score'] === null ? self::METHOD : self::HYBRID_METHOD,
        ], array_slice($scored, 0, max(1, min(10, $limit))));

        return array_replace($base, [
            'status' => $items === [] ? 'no_match' : 'matched',
            'method' => $method,
            'matched_count' => $matchedCount,
            'returned_count' => count($items),
            'excluded_count' => max(0, count($rows) - $matchedCount),
            'embedding' => $semanticMeta,
            'items' => $items,
        ]);
    }

    /** @return list<string> */
    private function terms(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $terms = [];
        if (preg_match_all('/[a-z0-9_]{2,}/u', $text, $matches)) {
            $terms = array_merge($terms, $matches[0]);
        }
        $chinese = preg_replace('/[^\x{4e00}-\x{9fff}]+/u', '', $text) ?? '';
        $length = mb_strlen($chinese);
        for ($size = 2; $size <= 3; $size++) {
            for ($index = 0; $index <= $length - $size; $index++) {
                $terms[] = mb_substr($chinese, $index, $size);
            }
        }
        return array_values(array_unique(array_filter($terms, static fn(string $term): bool => mb_strlen($term) >= 2)));
    }

    /** @param list<string> $terms */
    private function score(string $text, array $terms, float $weight): float
    {
        $text = mb_strtolower($text);
        $score = 0.0;
        foreach ($terms as $term) {
            if (mb_strpos($text, $term) !== false) {
                $score += $weight * min(3, max(1, mb_strlen($term) - 1));
            }
        }
        return $score;
    }

    /** @param list<float|int> $left @param list<float|int> $right */
    private function cosine(array $left, array $right): float
    {
        if ($left === [] || count($left) !== count($right)) {
            return 0.0;
        }
        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;
        foreach ($left as $index => $value) {
            $a = (float)$value;
            $b = (float)$right[$index];
            $dot += $a * $b;
            $leftNorm += $a * $a;
            $rightNorm += $b * $b;
        }
        if ($leftNorm <= 0.0 || $rightNorm <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
    }
}
