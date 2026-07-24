<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Read-only access to the structured revenue-operations knowledge pack.
 *
 * This service does not generate advice, write OTA data, or promote a case
 * reference into current-hotel facts. Case entries are returned only when the
 * caller explicitly supplies the matching case_key.
 */
final class RevenueOperationsKnowledgeService
{
    public const SOURCE = 'revenue_operations_decision_support';
    public const CASE_SCOPE = 'case_reference';

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function load(array $filters = []): array
    {
        if (!$this->tableExists('knowledge_units') || !$this->tableExists('knowledge_chunks')) {
            return $this->unavailableContext($filters, 'missing_table', [
                $this->gap(
                    'revenue_operations_knowledge_tables_missing',
                    '知识表缺失',
                    '恢复 knowledge_units 与 knowledge_chunks 后再读取收益运营知识。'
                ),
            ]);
        }

        $hotelId = max(0, (int)($filters['hotel_id'] ?? 0));
        $unitColumns = $this->tableColumns('knowledge_units');
        $unitFields = ['unit_id', 'name', 'source', 'status', 'description'];
        if (isset($unitColumns['hotel_id'])) {
            $unitFields[] = 'hotel_id';
        }
        if (isset($unitColumns['created_by'])) {
            $unitFields[] = 'created_by';
        }

        $unitQuery = Db::name('knowledge_units')
            ->field(implode(',', $unitFields))
            ->where('source', self::SOURCE)
            ->where('status', 'done');

        if (isset($unitColumns['hotel_id']) && isset($unitColumns['created_by'])) {
            if ($hotelId > 0) {
                $unitQuery->where(function ($scope) use ($hotelId): void {
                    $scope->where('hotel_id', $hotelId)
                        ->whereOr(function ($global): void {
                            $global->where('hotel_id', 0)->where('created_by', 0);
                        });
                });
            } else {
                $unitQuery->where('hotel_id', 0)->where('created_by', 0);
            }
        } else {
            $unitQuery->whereRaw('1 = 0');
        }

        $unitRows = $unitQuery->order('unit_id', 'asc')->limit(20)->select()->toArray();
        $unitIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['unit_id'] ?? 0),
            $unitRows
        )));

        if ($unitIds === []) {
            return $this->unavailableContext($filters, 'empty', [
                $this->gap(
                    'revenue_operations_knowledge_not_seeded',
                    '收益运营知识未入库',
                    '执行对应知识种子迁移后再读取。'
                ),
            ]);
        }

        $chunkRows = Db::name('knowledge_chunks')
            ->field('chunk_id,unit_id,type,content')
            ->whereIn('unit_id', $unitIds)
            ->order('chunk_id', 'asc')
            ->limit(300)
            ->select()
            ->toArray();

        return $this->buildContextFromRows($unitRows, $chunkRows, $filters);
    }

    /**
     * Pure builder used by tests and future callers that already hold rows.
     *
     * @param array<int, array<string, mixed>> $unitRows
     * @param array<int, array<string, mixed>> $chunkRows
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function buildContextFromRows(array $unitRows, array $chunkRows, array $filters = []): array
    {
        $hotelId = max(0, (int)($filters['hotel_id'] ?? 0));
        $caseKey = trim((string)($filters['case_key'] ?? ''));
        $types = $this->normalizeList($filters['types'] ?? $filters['knowledge_types'] ?? []);
        $limit = max(1, min(100, (int)($filters['limit'] ?? 50)));

        $unitMap = [];
        foreach ($unitRows as $row) {
            $unitId = (int)($row['unit_id'] ?? 0);
            $unitHotelId = max(0, (int)($row['hotel_id'] ?? 0));
            if ($unitId <= 0
                || trim((string)($row['source'] ?? '')) !== self::SOURCE
                || trim((string)($row['status'] ?? '')) !== 'done') {
                continue;
            }
            if ($unitHotelId === 0
                && (!array_key_exists('created_by', $row) || (int)$row['created_by'] !== 0)) {
                continue;
            }
            if ($hotelId > 0 && !in_array($unitHotelId, [0, $hotelId], true)) {
                continue;
            }
            if ($hotelId === 0 && $unitHotelId !== 0) {
                continue;
            }
            $unitMap[$unitId] = $row;
        }

        if ($unitMap === []) {
            return $this->unavailableContext($filters, 'empty', [
                $this->gap(
                    'revenue_operations_knowledge_scope_empty',
                    '当前范围没有可用收益运营知识',
                    '确认知识已入库且 hotel_id、source、status 范围正确。'
                ),
            ]);
        }

        $entries = [];
        $dataGaps = [];
        $excludedCaseReferenceCount = 0;
        $matchedCaseReferenceCount = 0;

        foreach ($chunkRows as $row) {
            $unitId = (int)($row['unit_id'] ?? 0);
            if (!isset($unitMap[$unitId])) {
                continue;
            }

            $type = trim((string)($row['type'] ?? ''));
            if ($types !== [] && !in_array($type, $types, true)) {
                continue;
            }

            $content = $this->decodeContent($row['content'] ?? null);
            if ($content === null) {
                $dataGaps[] = $this->gap(
                    'invalid_revenue_operations_knowledge_chunk',
                    '知识片段格式无效',
                    '修复 knowledge_chunks#' . (int)($row['chunk_id'] ?? 0) . ' 的 JSON 内容。'
                );
                continue;
            }

            $scope = trim((string)($content['scope'] ?? ''));
            $evidenceLevel = trim((string)($content['evidence_level'] ?? ''));
            $sourceRefs = is_array($content['source_refs'] ?? null) ? $content['source_refs'] : [];
            if ($scope === '' || $evidenceLevel === '' || $sourceRefs === []) {
                $dataGaps[] = $this->gap(
                    'revenue_operations_knowledge_traceability_missing',
                    '知识片段缺少追溯字段',
                    '为 knowledge_chunks#' . (int)($row['chunk_id'] ?? 0) . ' 补齐 scope、evidence_level 与 source_refs。'
                );
                continue;
            }

            if ($scope === self::CASE_SCOPE) {
                $entryCaseKey = trim((string)($content['case_key'] ?? ''));
                if ($caseKey === '' || $entryCaseKey === '' || $entryCaseKey !== $caseKey) {
                    $excludedCaseReferenceCount++;
                    continue;
                }
                $matchedCaseReferenceCount++;
            }

            $unit = $unitMap[$unitId];
            $entries[] = [
                'chunk_id' => (int)($row['chunk_id'] ?? 0),
                'unit_id' => $unitId,
                'unit_name' => trim((string)($unit['name'] ?? '')),
                'unit_hotel_id' => max(0, (int)($unit['hotel_id'] ?? 0)),
                'knowledge_type' => $type,
                'scope' => $scope,
                'evidence_level' => $evidenceLevel,
                'source_refs' => array_values($sourceRefs),
                'content' => $content,
            ];

            if (count($entries) >= $limit) {
                break;
            }
        }

        if ($caseKey !== '' && $matchedCaseReferenceCount === 0) {
            $dataGaps[] = $this->gap(
                'revenue_operations_case_reference_not_found',
                '指定案例不存在',
                '确认 case_key=' . $caseKey . ' 已作为 case_reference 知识片段入库。'
            );
        }

        $status = $entries === [] ? 'empty' : ($dataGaps === [] ? 'available' : 'partial');

        return [
            'status' => $status,
            'source' => self::SOURCE,
            'hotel_id' => $hotelId,
            'case_key' => $caseKey,
            'knowledge_types' => $types,
            'unit_count' => count($unitMap),
            'entry_count' => count($entries),
            'excluded_case_reference_count' => $excludedCaseReferenceCount,
            'entries' => $entries,
            'data_gaps' => $dataGaps,
            'protected_boundary' => 'generic knowledge may explain methods and action structure; case_reference requires explicit case_key and never becomes current-hotel fact or an automatic OTA write instruction',
        ];
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function decodeContent($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeList($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,，\n]+/u', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $items[$text] = $text;
            }
        }
        return array_values($items);
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, array<string, string>> $dataGaps
     * @return array<string, mixed>
     */
    private function unavailableContext(array $filters, string $status, array $dataGaps): array
    {
        return [
            'status' => $status,
            'source' => self::SOURCE,
            'hotel_id' => max(0, (int)($filters['hotel_id'] ?? 0)),
            'case_key' => trim((string)($filters['case_key'] ?? '')),
            'knowledge_types' => $this->normalizeList($filters['types'] ?? $filters['knowledge_types'] ?? []),
            'unit_count' => 0,
            'entry_count' => 0,
            'excluded_case_reference_count' => 0,
            'entries' => [],
            'data_gaps' => $dataGaps,
            'protected_boundary' => 'missing knowledge is reported explicitly and is never replaced with fabricated operating advice',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function gap(string $code, string $label, string $nextAction): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'next_action' => $nextAction,
        ];
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        return !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
    }

    /**
     * @return array<string, bool>
     */
    private function tableColumns(string $table): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        $columns = [];
        foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
            if (!empty($row['Field'])) {
                $columns[(string)$row['Field']] = true;
            }
        }
        return $columns;
    }
}
