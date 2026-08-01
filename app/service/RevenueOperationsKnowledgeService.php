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
        if (isset($unitColumns['known_knowns'])) {
            $unitFields[] = 'known_knowns';
        }
        if (isset($unitColumns['known_unknowns'])) {
            $unitFields[] = 'known_unknowns';
        }
        if (isset($unitColumns['truth_profile_version'])) {
            $unitFields[] = 'truth_profile_version';
        }
        if (isset($unitColumns['lifecycle_status'])) {
            $unitFields[] = 'lifecycle_status';
        }
        if (isset($unitColumns['reviewed_at'])) {
            $unitFields[] = 'reviewed_at';
        }
        if (isset($unitColumns['review_due_at'])) {
            $unitFields[] = 'review_due_at';
        }

        $unitQuery = Db::name('knowledge_units')
            ->field(implode(',', $unitFields))
            ->where('source', self::SOURCE)
            ->where('status', 'done');
        if (isset($unitColumns['lifecycle_status'])) {
            $unitQuery->where('lifecycle_status', 'active');
        }

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

        $unitRows = $unitQuery->order('unit_id', 'desc')->limit(101)->select()->toArray();
        $unitFetchTruncated = count($unitRows) > 100;
        if ($unitFetchTruncated) {
            $unitRows = array_slice($unitRows, 0, 100);
        }
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
            ->limit(2001)
            ->select()
            ->toArray();
        $chunkFetchTruncated = count($chunkRows) > 2000;
        if ($chunkFetchTruncated) {
            $chunkRows = array_slice($chunkRows, 0, 2000);
        }

        return $this->buildContextFromRows($unitRows, $chunkRows, $filters + [
            '_unit_fetch_truncated' => $unitFetchTruncated,
            '_chunk_fetch_truncated' => $chunkFetchTruncated,
        ]);
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
        $platforms = $this->normalizePlatforms(array_merge(
            $this->normalizeList($filters['platforms'] ?? []),
            $this->normalizeList($filters['platform'] ?? [])
        ));
        $moduleId = trim((string)($filters['module_id'] ?? ''));
        $limit = max(1, min(100, (int)($filters['limit'] ?? 50)));
        $decisionGate = new KnowledgeDecisionGateService();
        $asOf = $filters['as_of'] ?? null;

        $unitMap = [];
        $unitOrder = [];
        foreach ($unitRows as $row) {
            $unitId = (int)($row['unit_id'] ?? 0);
            $unitHotelId = max(0, (int)($row['hotel_id'] ?? 0));
            $lifecycleStatus = strtolower(trim((string)($row['lifecycle_status'] ?? 'active')));
            if ($unitId <= 0
                || trim((string)($row['source'] ?? '')) !== self::SOURCE
                || trim((string)($row['status'] ?? '')) !== 'done'
                || $lifecycleStatus !== 'active') {
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
            $unitOrder[] = $unitId;
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

        /** @var array<int, array<int, array<string, mixed>>> $entriesByUnit */
        $entriesByUnit = [];
        $dataGaps = [];
        $excludedCaseReferenceCount = 0;
        $matchedCaseReferenceCount = 0;
        $excludedPlatformMismatchCount = 0;
        $excludedModuleMismatchCount = 0;
        $excludedDecisionGateCount = 0;

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

            $lifecycleStatus = strtolower(trim((string)($content['lifecycle_status'] ?? 'active')));
            if ($lifecycleStatus !== 'active') {
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

            $entryPlatforms = $this->normalizePlatforms($content['platforms'] ?? []);
            if ($platforms !== []
                && $entryPlatforms !== []
                && array_intersect($platforms, $entryPlatforms) === []
            ) {
                $excludedPlatformMismatchCount++;
                continue;
            }

            $entryModuleId = trim((string)($content['module_id'] ?? ''));
            if ($moduleId !== '' && $entryModuleId !== $moduleId) {
                $excludedModuleMismatchCount++;
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
            $knowledgeGate = $decisionGate->assess($unit, $content, $asOf);
            $explicitCaseReferenceAllowed = $scope === self::CASE_SCOPE
                && $caseKey !== ''
                && ($knowledgeGate['reference_safe'] ?? false) === true;
            if (($knowledgeGate['retrieval_safe'] ?? false) !== true
                && !$explicitCaseReferenceAllowed
            ) {
                $excludedDecisionGateCount++;
                $dataGaps[] = $this->gateGap((string)($knowledgeGate['primary_reason'] ?? ''));
                continue;
            }
            if (in_array('knowledge_review_due', $knowledgeGate['reason_codes'] ?? [], true)) {
                $dataGaps[] = $this->gateGap('knowledge_review_due');
            }

            $knownKnowns = $this->normalizeList($unit['known_knowns'] ?? []);
            $knownUnknowns = $this->normalizeList($unit['known_unknowns'] ?? []);
            $entry = [
                'chunk_id' => (int)($row['chunk_id'] ?? 0),
                'unit_id' => $unitId,
                'unit_name' => trim((string)($unit['name'] ?? '')),
                'unit_hotel_id' => max(0, (int)($unit['hotel_id'] ?? 0)),
                'knowledge_type' => $type,
                'scope' => $scope,
                'platforms' => $entryPlatforms,
                'module_id' => $entryModuleId,
                'evidence_level' => $evidenceLevel,
                'source_refs' => array_values($sourceRefs),
                'evidence_grade' => (string)($knowledgeGate['evidence_grade'] ?? 'U'),
                'knowledge_gate' => $knowledgeGate,
                'known_knowns' => $knownKnowns,
                'known_unknowns' => $knownUnknowns,
                'truth_profile_version' => trim((string)($unit['truth_profile_version'] ?? '')),
                'content' => $content,
            ];
            $entriesByUnit[$unitId][] = $entry;
        }

        if ($caseKey !== '' && $matchedCaseReferenceCount === 0) {
            $dataGaps[] = $this->gap(
                'revenue_operations_case_reference_not_found',
                '指定案例不存在',
                '确认 case_key=' . $caseKey . ' 已作为 case_reference 知识片段入库。'
            );
        }

        $allEntries = [];
        foreach ($entriesByUnit as $unitEntries) {
            foreach ($unitEntries as $entry) {
                $allEntries[] = $entry;
            }
        }
        $conflictResolution = $decisionGate->resolveConflictingClaims($allEntries);
        $entriesByUnit = [];
        foreach ($conflictResolution['entries'] as $entry) {
            $entriesByUnit[(int)$entry['unit_id']][] = $entry;
        }
        foreach ($conflictResolution['conflicts'] as $conflict) {
            if (($conflict['status'] ?? '') !== 'unresolved') {
                continue;
            }
            $dataGaps[] = $this->gap(
                'knowledge_claim_conflict_unresolved',
                '同一知识键存在未解决冲突',
                '复核 conflict_key=' . (string)($conflict['conflict_key'] ?? '')
                    . ' 并显式标记唯一 resolved 版本后再用于检索。'
            );
        }

        $eligibleEntryCount = array_sum(array_map('count', $entriesByUnit));
        $entries = [];
        $entryOffsets = array_fill_keys($unitOrder, 0);
        while (count($entries) < $limit) {
            $added = false;
            foreach ($unitOrder as $unitId) {
                $offset = (int)($entryOffsets[$unitId] ?? 0);
                if (!isset($entriesByUnit[$unitId][$offset])) {
                    continue;
                }
                $entry = $entriesByUnit[$unitId][$offset];
                $entries[] = $entry;
                $entryOffsets[$unitId] = $offset + 1;
                $added = true;
                if (count($entries) >= $limit) {
                    break;
                }
            }
            if (!$added) {
                break;
            }
        }

        $omittedEntryCount = max(0, $eligibleEntryCount - count($entries));
        if ($omittedEntryCount > 0) {
            $dataGaps[] = $this->gap(
                'revenue_operations_knowledge_truncated',
                '收益运营知识已按容量截断',
                sprintf(
                    '当前有%d条合格知识，本次返回%d条；如需完整审查，请提高limit或按platform/module_id筛选。',
                    $eligibleEntryCount,
                    count($entries)
                )
            );
        }
        if (($filters['_unit_fetch_truncated'] ?? false) === true) {
            $dataGaps[] = $this->gap(
                'revenue_operations_knowledge_unit_fetch_truncated',
                '收益运营知识单元读取达到上限',
                '将知识按模块拆分查询，或提高受控的知识单元读取上限。'
            );
        }
        if (($filters['_chunk_fetch_truncated'] ?? false) === true) {
            $dataGaps[] = $this->gap(
                'revenue_operations_knowledge_chunk_fetch_truncated',
                '收益运营知识片段读取达到上限',
                '将知识按平台或模块拆分查询，避免把未读取片段误判为不存在。'
            );
        }

        $dataGaps = $this->deduplicateGaps($dataGaps);
        $status = $entries === [] ? 'empty' : ($dataGaps === [] ? 'available' : 'partial');
        $selectedUnitIds = array_values(array_unique(array_map(
            static fn(array $entry): int => (int)($entry['unit_id'] ?? 0),
            $entries
        )));
        $decisionSafeEntryCount = count(array_filter(
            $entries,
            static fn(array $entry): bool => ($entry['knowledge_gate']['decision_safe'] ?? false) === true
        ));
        $knownUnknownEntryCount = count(array_filter(
            $entries,
            static fn(array $entry): bool => ($entry['knowledge_gate']['status'] ?? '') === 'known_unknown'
        ));

        return [
            'status' => $status,
            'source' => self::SOURCE,
            'hotel_id' => $hotelId,
            'case_key' => $caseKey,
            'knowledge_types' => $types,
            'platforms' => $platforms,
            'module_id' => $moduleId,
            'unit_count' => count($unitMap),
            'selected_unit_count' => count($selectedUnitIds),
            'entry_count' => count($entries),
            'eligible_entry_count' => $eligibleEntryCount,
            'omitted_entry_count' => $omittedEntryCount,
            'truncated' => $omittedEntryCount > 0
                || ($filters['_unit_fetch_truncated'] ?? false) === true
                || ($filters['_chunk_fetch_truncated'] ?? false) === true,
            'excluded_case_reference_count' => $excludedCaseReferenceCount,
            'excluded_platform_mismatch_count' => $excludedPlatformMismatchCount,
            'excluded_module_mismatch_count' => $excludedModuleMismatchCount,
            'excluded_decision_gate_count' => $excludedDecisionGateCount,
            'resolved_conflict_count' => (int)$conflictResolution['resolved_conflict_count'],
            'unresolved_conflict_count' => (int)$conflictResolution['unresolved_conflict_count'],
            'decision_safe_entry_count' => $decisionSafeEntryCount,
            'known_unknown_entry_count' => $knownUnknownEntryCount,
            'entries' => $entries,
            'data_gaps' => $dataGaps,
            'protected_boundary' => 'only traceable, applicable and temporally eligible knowledge enters retrieval; unresolved version conflicts remain known_unknown; case_reference requires explicit case_key and never becomes current-hotel fact or an automatic OTA write instruction',
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
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[,，\n]+/u', $value);
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
            'platforms' => $this->normalizePlatforms(array_merge(
                $this->normalizeList($filters['platforms'] ?? []),
                $this->normalizeList($filters['platform'] ?? [])
            )),
            'module_id' => trim((string)($filters['module_id'] ?? '')),
            'unit_count' => 0,
            'selected_unit_count' => 0,
            'entry_count' => 0,
            'eligible_entry_count' => 0,
            'omitted_entry_count' => 0,
            'truncated' => false,
            'excluded_case_reference_count' => 0,
            'excluded_platform_mismatch_count' => 0,
            'excluded_module_mismatch_count' => 0,
            'excluded_decision_gate_count' => 0,
            'resolved_conflict_count' => 0,
            'unresolved_conflict_count' => 0,
            'decision_safe_entry_count' => 0,
            'known_unknown_entry_count' => 0,
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

    /**
     * @return array<string, string>
     */
    private function gateGap(string $reason): array
    {
        return match ($reason) {
            'knowledge_unit_not_active', 'knowledge_chunk_not_active' => $this->gap(
                $reason,
                '知识生命周期不可用',
                '只恢复经过复核并显式标记 active 的知识。'
            ),
            'knowledge_expired', 'knowledge_not_yet_effective' => $this->gap(
                $reason,
                '知识不在有效期内',
                '复核当前来源版本和生效日期，不得继续使用过期或尚未生效的规则。'
            ),
            'knowledge_review_due' => $this->gap(
                $reason,
                '知识已到复核日期',
                '重新核对来源版本、适用范围与运行时实现后更新 reviewed_at 和 review_due_at。'
            ),
            'knowledge_evidence_unverified', 'knowledge_evidence_unrated' => $this->gap(
                $reason,
                '知识证据不足',
                '补齐证据等级和当前来源复核；未验证材料只保留在知识中心，不进入默认决策检索。'
            ),
            default => $this->gap(
                $reason !== '' ? $reason : 'knowledge_decision_gate_blocked',
                '知识未通过决策门禁',
                '补齐来源、范围、证据等级和时间状态后再检索。'
            ),
        };
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizePlatforms($value): array
    {
        $aliases = [
            '携程' => 'ctrip',
            'trip.com' => 'ctrip',
            'meituan' => 'meituan',
            '美团' => 'meituan',
            'dianping' => 'dianping',
            '大众点评' => 'dianping',
            '点评' => 'dianping',
            'pms' => 'pms',
            '订单来了' => 'dingdandao',
            'dingdandao' => 'dingdandao',
        ];
        $platforms = [];
        foreach ($this->normalizeList($value) as $item) {
            $normalized = mb_strtolower(trim($item));
            $normalized = $aliases[$normalized] ?? $aliases[$item] ?? $normalized;
            if ($normalized !== '') {
                $platforms[$normalized] = $normalized;
            }
        }
        return array_values($platforms);
    }

    /**
     * @param array<int, array<string, string>> $dataGaps
     * @return array<int, array<string, string>>
     */
    private function deduplicateGaps(array $dataGaps): array
    {
        $unique = [];
        foreach ($dataGaps as $gap) {
            $key = trim((string)($gap['code'] ?? ''))
                . '#'
                . trim((string)($gap['next_action'] ?? ''));
            $unique[$key] = $gap;
        }
        return array_values($unique);
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
