<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Hotel-scoped, read-only knowledge retrieval for operating questions.
 *
 * Metadata scope and the shared knowledge decision gate are applied before a
 * bounded lexical rank. Returned excerpts are untrusted reference material;
 * they never become OTA facts or grant the model any write capability.
 */
final class OperatingQuestionKnowledgeRetrievalService
{
    public const METHOD = 'metadata_filtered_lexical_v1';
    private const MAX_UNITS = 80;
    private const MAX_CHUNKS = 400;
    private const MAX_RESULTS = 5;

    /** @return array<string,mixed> */
    public function retrieve(int $hotelId, int $userId, string $platform, string $question): array
    {
        if ($hotelId <= 0 || trim($question) === '') {
            return $this->result('no_match', [], 0, 0, 'invalid_or_empty_scope');
        }
        if (!$this->tableExists('knowledge_units') || !$this->tableExists('knowledge_chunks')) {
            return $this->result('unavailable', [], 0, 0, 'knowledge_tables_missing');
        }

        $unitColumns = $this->tableColumns('knowledge_units');
        if (!isset($unitColumns['hotel_id'])) {
            return $this->result('unavailable', [], 0, 0, 'knowledge_hotel_scope_missing');
        }
        $unitFields = array_values(array_filter([
            'unit_id', 'hotel_id', 'name', 'source', 'status', 'description',
            isset($unitColumns['created_by']) ? 'created_by' : null,
            isset($unitColumns['lifecycle_status']) ? 'lifecycle_status' : null,
            isset($unitColumns['reviewed_at']) ? 'reviewed_at' : null,
            isset($unitColumns['review_due_at']) ? 'review_due_at' : null,
            isset($unitColumns['current_chunk_id']) ? 'current_chunk_id' : null,
            isset($unitColumns['stable_key']) ? 'stable_key' : null,
        ]));

        $unitQuery = Db::name('knowledge_units')
            ->field(implode(',', $unitFields))
            ->where('status', 'done');
        if (isset($unitColumns['lifecycle_status'])) {
            $unitQuery->where('lifecycle_status', 'active');
        }
        if (isset($unitColumns['created_by'])) {
            $unitQuery->where(function ($scope) use ($hotelId, $userId, $unitColumns): void {
                $scope->where(function ($owned) use ($hotelId, $userId): void {
                    $owned->where('hotel_id', $hotelId)->where('created_by', max(0, $userId));
                })->whereOr(function ($formal) use ($hotelId, $unitColumns): void {
                    $formal->where('hotel_id', $hotelId)->where(function ($formalIdentity) use ($unitColumns): void {
                        $formalIdentity->where('source', 'formal_operating_sop');
                        if (isset($unitColumns['stable_key'])) {
                            $formalIdentity->whereOr('stable_key', '<>', '');
                        }
                    });
                })->whereOr(function ($global): void {
                    $global->where('hotel_id', 0)->where('created_by', 0);
                });
            });
        } else {
            // Legacy global rows have no ownership proof. Keep only the exact
            // hotel container rather than widening across hotels.
            $unitQuery->where('hotel_id', $hotelId);
        }
        $unitRows = $unitQuery
            ->order('unit_id', 'desc')
            ->limit(self::MAX_UNITS)
            ->select()
            ->toArray();
        $unitIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['unit_id'] ?? 0),
            $unitRows
        )));
        if ($unitIds === []) {
            return $this->result('no_match', [], 0, 0, 'metadata_scope_empty');
        }

        $chunkColumns = $this->tableColumns('knowledge_chunks');
        $chunkFields = array_values(array_filter([
            'chunk_id', 'unit_id', 'type', 'content',
            isset($chunkColumns['promotion_candidate_id']) ? 'promotion_candidate_id' : null,
            isset($chunkColumns['operating_sop_version_id']) ? 'operating_sop_version_id' : null,
            isset($chunkColumns['version_no']) ? 'version_no' : null,
            isset($chunkColumns['lifecycle_status']) ? 'lifecycle_status' : null,
            isset($chunkColumns['superseded_by_chunk_id']) ? 'superseded_by_chunk_id' : null,
        ]));
        $chunkRows = Db::name('knowledge_chunks')
            ->field(implode(',', $chunkFields))
            ->whereIn('unit_id', $unitIds)
            ->order('chunk_id', 'desc')
            ->limit(self::MAX_CHUNKS)
            ->select()
            ->toArray();

        return $this->buildFromRows($unitRows, $chunkRows, [
            'hotel_id' => $hotelId,
            'user_id' => max(0, $userId),
            'platform' => $platform,
            'question' => $question,
        ]);
    }

    /**
     * Pure builder used by focused tests and callers that already hold rows.
     *
     * @param list<array<string,mixed>> $unitRows
     * @param list<array<string,mixed>> $chunkRows
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function buildFromRows(array $unitRows, array $chunkRows, array $scope): array
    {
        $hotelId = max(0, (int)($scope['hotel_id'] ?? 0));
        $userId = max(0, (int)($scope['user_id'] ?? 0));
        $platform = $this->normalizePlatform((string)($scope['platform'] ?? ''));
        $terms = $this->searchTerms((string)($scope['question'] ?? ''));
        if ($hotelId <= 0 || $terms === []) {
            return $this->result('no_match', [], 0, 0, 'no_search_terms');
        }

        $units = [];
        foreach ($unitRows as $unit) {
            $unitId = (int)($unit['unit_id'] ?? 0);
            $unitHotelId = max(0, (int)($unit['hotel_id'] ?? 0));
            $createdBy = array_key_exists('created_by', $unit) ? (int)$unit['created_by'] : -1;
            $globalSystemOwned = $unitHotelId === 0
                && array_key_exists('created_by', $unit)
                && $createdBy === 0;
            $formalShared = $unitHotelId === $hotelId
                && (
                    trim((string)($unit['source'] ?? '')) === 'formal_operating_sop'
                    || trim((string)($unit['stable_key'] ?? '')) !== ''
                );
            $userOwned = $unitHotelId === $hotelId && $userId > 0 && $createdBy === $userId;
            if ($unitId <= 0
                || trim((string)($unit['status'] ?? '')) !== 'done'
                || strtolower(trim((string)($unit['lifecycle_status'] ?? 'active'))) !== 'active'
                || (!$globalSystemOwned && !$formalShared && !$userOwned)
            ) {
                continue;
            }
            $units[$unitId] = $unit;
        }
        if ($units === []) {
            return $this->result('no_match', [], 0, 0, 'metadata_scope_empty');
        }

        $gate = new KnowledgeDecisionGateService();
        $candidates = [];
        $excludedCount = 0;
        foreach ($chunkRows as $row) {
            $chunkId = (int)($row['chunk_id'] ?? 0);
            $unitId = (int)($row['unit_id'] ?? 0);
            $unit = $units[$unitId] ?? null;
            if ($chunkId <= 0 || !is_array($unit)) {
                $excludedCount++;
                continue;
            }
            $rowLifecycle = strtolower(trim((string)($row['lifecycle_status'] ?? 'active')));
            if ($rowLifecycle !== '' && $rowLifecycle !== 'active') {
                $excludedCount++;
                continue;
            }
            if ((int)($row['superseded_by_chunk_id'] ?? 0) > 0) {
                $excludedCount++;
                continue;
            }
            $currentChunkId = (int)($unit['current_chunk_id'] ?? 0);
            $isFormal = trim((string)($unit['source'] ?? '')) === 'formal_operating_sop'
                || trim((string)($unit['stable_key'] ?? '')) !== ''
                || (int)($row['promotion_candidate_id'] ?? 0) > 0
                || (int)($row['operating_sop_version_id'] ?? 0) > 0
                || (int)($row['version_no'] ?? 0) > 0;
            if ($isFormal && $currentChunkId > 0 && $currentChunkId !== $chunkId) {
                $excludedCount++;
                continue;
            }

            $content = $this->decodeContent($row['content'] ?? null);
            if ($content === null) {
                $excludedCount++;
                continue;
            }
            $contentScope = strtolower(trim((string)($content['scope'] ?? '')));
            if ($contentScope === 'case_reference') {
                // A historical case needs an explicit case_key contract. A
                // natural-language similarity match is never enough.
                $excludedCount++;
                continue;
            }
            if (!$this->platformMatches($platform, $content['platforms'] ?? [])) {
                $excludedCount++;
                continue;
            }
            $assessment = $gate->assess($unit, $content);
            if (($assessment['retrieval_safe'] ?? false) !== true) {
                $excludedCount++;
                continue;
            }

            $excerpt = $this->excerpt($content);
            if ($excerpt === '') {
                $excludedCount++;
                continue;
            }
            $titleText = mb_strtolower(trim((string)($unit['name'] ?? '')) . ' '
                . trim((string)($unit['description'] ?? '')) . ' '
                . trim((string)($row['type'] ?? '')));
            $contentText = mb_strtolower($excerpt);
            $score = $this->score($terms, $titleText, $contentText);
            if ($score <= 0) {
                continue;
            }

            $gateStatus = (string)($assessment['status'] ?? KnowledgeDecisionGateService::STATUS_REFERENCE_ONLY);
            $candidates[] = [
                'ref' => 'knowledge_chunks#' . $chunkId,
                'unit_ref' => 'knowledge_units#' . $unitId,
                'unit_id' => $unitId,
                'chunk_id' => $chunkId,
                'name' => mb_substr(trim((string)($unit['name'] ?? '')), 0, 240),
                'source' => mb_substr(trim((string)($unit['source'] ?? '')), 0, 80),
                'authority' => (int)($unit['hotel_id'] ?? 0) === 0 ? 'global_system' : 'hotel_scoped',
                'knowledge_type' => mb_substr(trim((string)($row['type'] ?? '')), 0, 80),
                'scope' => mb_substr(trim((string)($content['scope'] ?? '')), 0, 160),
                'platforms' => $this->normalizePlatforms($content['platforms'] ?? []),
                'evidence_grade' => (string)($assessment['evidence_grade'] ?? 'U'),
                'gate_status' => $gateStatus,
                'usage_policy' => $gateStatus === KnowledgeDecisionGateService::STATUS_APPROVED
                    ? 'decision_support'
                    : ($gateStatus === KnowledgeDecisionGateService::STATUS_KNOWN_UNKNOWN ? 'known_unknown' : 'reference_only'),
                'source_refs' => $this->sourceRefs($content['source_refs'] ?? []),
                'retrieval_score' => $score,
                'retrieval_method' => self::METHOD,
                'excerpt' => $excerpt,
                'content' => $content,
            ];
        }

        $resolved = $gate->resolveConflictingClaims($candidates);
        $candidates = array_values((array)($resolved['entries'] ?? []));
        foreach ($candidates as &$candidate) {
            unset($candidate['content']);
        }
        unset($candidate);
        $excludedCount += max(0, (int)($resolved['excluded_entry_count'] ?? 0));

        usort($candidates, static function (array $left, array $right): int {
            $score = (int)$right['retrieval_score'] <=> (int)$left['retrieval_score'];
            if ($score !== 0) {
                return $score;
            }
            $scope = (($right['authority'] ?? '') === 'hotel_scoped' ? 1 : 0)
                <=> (($left['authority'] ?? '') === 'hotel_scoped' ? 1 : 0);
            if ($scope !== 0) {
                return $scope;
            }
            return (int)$left['chunk_id'] <=> (int)$right['chunk_id'];
        });
        $matchedCount = count($candidates);
        $items = array_slice($candidates, 0, self::MAX_RESULTS);

        return $this->result(
            $items === [] ? 'no_match' : 'matched',
            $items,
            $matchedCount,
            $excludedCount,
            $items === [] ? 'lexical_no_match' : ''
        );
    }

    /** @return array<string,mixed> */
    private function result(string $status, array $items, int $matchedCount, int $excludedCount, string $reason): array
    {
        return [
            'status' => $status,
            'method' => self::METHOD,
            'matched_count' => max(0, $matchedCount),
            'returned_count' => count($items),
            'excluded_count' => max(0, $excludedCount),
            'reason' => mb_substr(trim($reason), 0, 100),
            'items' => array_values($items),
        ];
    }

    /** @return array<string,mixed>|null */
    private function decodeContent(mixed $content): ?array
    {
        if (is_array($content)) {
            return $content;
        }
        if (!is_string($content) || trim($content) === '') {
            return null;
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> */
    private function searchTerms(string $question): array
    {
        $question = mb_strtolower(trim($question));
        if ($question === '') {
            return [];
        }
        $terms = [];
        foreach (['收益', '流量', '曝光', '转化', '订单', '价格', '排名', '点评', '携程', '美团', '运营', '诊断', '库存', '广告', '房型', '取消', '佣金', '活动', 'sop', 'adr', 'revpar'] as $term) {
            if (str_contains($question, $term)) {
                $terms[$term] = true;
            }
        }
        $stops = array_fill_keys(['如何', '什么', '怎么', '为什么', '是否', '可以', '应该', '问题', '情况', '当前', '这个', '那个', '酒店', '我们', '请问', '一下', '帮我', '看看'], true);
        $segments = preg_split('/[^\p{L}\p{N}_]+/u', $question) ?: [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            $length = mb_strlen($segment);
            if ($length < 2) {
                continue;
            }
            if ($length <= 16 && !isset($stops[$segment])) {
                $terms[$segment] = true;
            }
            if (preg_match('/^[\x{3400}-\x{9fff}]+$/u', $segment) !== 1) {
                continue;
            }
            for ($size = 2; $size <= min(4, $length); $size++) {
                for ($offset = 0; $offset <= $length - $size; $offset++) {
                    $term = mb_substr($segment, $offset, $size);
                    if (!isset($stops[$term])) {
                        $terms[$term] = true;
                    }
                    if (count($terms) >= 48) {
                        break 2;
                    }
                }
            }
            if (count($terms) >= 48) {
                break;
            }
        }
        return array_slice(array_keys($terms), 0, 48);
    }

    /** @param list<string> $terms */
    private function score(array $terms, string $titleText, string $contentText): int
    {
        $score = 0;
        foreach ($terms as $term) {
            $length = mb_strlen($term);
            if (str_contains($titleText, $term)) {
                $score += 8 + min(6, $length);
            }
            if (str_contains($contentText, $term)) {
                $score += 2 + min(4, $length);
            }
        }
        return $score;
    }

    private function platformMatches(string $requested, mixed $value): bool
    {
        $platforms = $this->normalizePlatforms($value);
        if ($platforms === []) {
            return true;
        }
        $required = $requested === 'all_ota' ? ['ctrip', 'meituan'] : [$requested];
        return array_intersect($required, $platforms) !== [];
    }

    private function normalizePlatform(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            '携程', 'xc', 'xiecheng' => 'ctrip',
            '美团', 'mt' => 'meituan',
            '去哪儿', 'qunar.com' => 'qunar',
            'all', 'ota', 'all-ota' => 'all_ota',
            default => $value,
        };
    }

    /** @return list<string> */
    private function normalizePlatforms(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,，、;；|\/]+/u', trim($value)) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $platforms = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $platform = $this->normalizePlatform((string)$item);
            if (in_array($platform, ['all_ota', ''], true)) {
                return [];
            }
            if (in_array($platform, ['ctrip', 'meituan', 'qunar'], true)) {
                $platforms[$platform] = true;
            }
        }
        return array_keys($platforms);
    }

    /** @return list<string> */
    private function sourceRefs(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $refs = [];
        foreach ($value as $item) {
            if (!is_scalar($item) || is_bool($item)) {
                continue;
            }
            $ref = mb_substr(trim((string)$item), 0, 300);
            if ($ref !== '') {
                $refs[$ref] = true;
            }
            if (count($refs) >= 8) {
                break;
            }
        }
        return array_keys($refs);
    }

    /** @param array<string,mixed> $content */
    private function excerpt(array $content): string
    {
        $parts = [];
        $this->flattenContent($content, '', $parts);
        $text = implode('；', array_values(array_unique($parts)));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = preg_replace(
            '/(?i)(api[_-]?key|authorization|bearer|cookie|password|secret|token)\s*[:=]\s*[^\s;；,，]+/u',
            '$1=****',
            $text
        ) ?? $text;
        return mb_substr(trim($text), 0, 1400);
    }

    /** @param list<string> $parts */
    private function flattenContent(mixed $value, string $key, array &$parts, int $depth = 0): void
    {
        if ($depth > 5 || count($parts) >= 36) {
            return;
        }
        $normalizedKey = strtolower(trim($key));
        if ($normalizedKey !== '' && preg_match('/api[_-]?key|authorization|bearer|cookie|password|secret|token|credential|header|raw_text|document_text|ai_distilled|source_data|model|source_refs/i', $normalizedKey) === 1) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                $this->flattenContent($child, is_string($childKey) ? $childKey : $key, $parts, $depth + 1);
                if (count($parts) >= 36) {
                    break;
                }
            }
            return;
        }
        if (!is_scalar($value) || is_bool($value)) {
            return;
        }
        $text = trim((string)$value);
        if ($text === '' || mb_strlen($text) > 5000) {
            return;
        }
        $label = $key !== '' && !is_numeric($key) ? mb_substr($key, 0, 60) . ': ' : '';
        $parts[] = $label . mb_substr($text, 0, 600);
    }

    private function tableExists(string $table): bool
    {
        try {
            Db::name($table)->limit(1)->select();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,true> */
    private function tableColumns(string $table): array
    {
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . $table . '`');
            $field = 'Field';
        } catch (\Throwable) {
            try {
                $rows = Db::query('PRAGMA table_info(' . $table . ')');
                $field = 'name';
            } catch (\Throwable) {
                return [];
            }
        }
        $columns = [];
        foreach ($rows as $row) {
            $name = trim((string)($row[$field] ?? ''));
            if ($name !== '') {
                $columns[$name] = true;
            }
        }
        return $columns;
    }
}
