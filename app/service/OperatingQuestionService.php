<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Persists one hotel-scoped operating question and the exact saved evidence
 * used to answer it. The MVP is deliberately deterministic: it reads local
 * persisted facts, memories, Agent diagnoses, knowledge and execution reviews;
 * it does not call an external model, write OTA data, or send a message.
 */
final class OperatingQuestionService
{
    public const TABLE = 'hotel_operating_questions';
    public const CONTRACT_VERSION = 'hotel_operating_question.v1';

    /** @var list<string> */
    private const PLATFORMS = ['ctrip', 'meituan', 'qunar', 'all_ota'];

    /** @var list<string> */
    private const ALL_OTA_REQUIRED_PLATFORMS = ['ctrip', 'meituan'];

    /**
     * @param null|Closure(int,int,string,string,string,string):array<string,mixed> $evidenceLoader
     */
    public function __construct(private readonly ?Closure $evidenceLoader = null)
    {
    }

    /** @return array<string,mixed> */
    public function create(
        int $tenantId,
        int $hotelId,
        string $question,
        string $platform,
        string $dateStart,
        string $dateEnd,
        int $createdBy
    ): array {
        $this->assertTableReady();
        $this->assertHotelIdentity($tenantId, $hotelId);
        $question = trim($question);
        if ($question === '' || mb_strlen($question) > 1000) {
            throw new InvalidArgumentException('经营问题不能为空且不能超过1000字');
        }
        $platform = $this->normalizePlatform($platform);
        $dateStart = $this->date($dateStart, '开始日期');
        $dateEnd = $this->date($dateEnd, '结束日期');
        if ($dateEnd < $dateStart) {
            throw new InvalidArgumentException('结束日期不能早于开始日期');
        }

        $evidence = $this->evidenceLoader !== null
            ? ($this->evidenceLoader)($tenantId, $hotelId, $platform, $dateStart, $dateEnd, $question)
            : $this->loadEvidence($tenantId, $hotelId, $platform, $dateStart, $dateEnd, $question);
        $evidence = $this->normalizeEvidence($evidence);
        $facts = array_values(array_filter($evidence['facts'], static function (array $fact) use ($platform): bool {
            $factPlatform = strtolower(trim((string)($fact['platform'] ?? '')));
            if ($factPlatform === '') {
                $factPlatform = strtolower(trim((string)($fact['source'] ?? '')));
            }
            return $platform === 'all_ota'
                ? in_array($factPlatform, self::ALL_OTA_REQUIRED_PLATFORMS, true)
                : $factPlatform === $platform;
        }));
        $diagnoses = [];
        $diagnosisRejectionCodes = [];
        foreach ($evidence['diagnoses'] as $diagnosis) {
            $rejectionCode = $this->diagnosisIneligibilityCode(
                $diagnosis,
                $tenantId,
                $hotelId,
                $platform,
                $dateStart,
                $dateEnd
            );
            if ($rejectionCode === '') {
                $diagnoses[] = $diagnosis;
            } elseif ($rejectionCode !== 'platform_mismatch') {
                $diagnosisRejectionCodes[] = $rejectionCode;
            }
        }
        $factPlatformCounts = $this->factPlatformCountsFromEvidence($evidence);
        $factPlatformDates = $this->factPlatformDatesFromEvidence($evidence);
        $requiredDates = $this->dateRange($dateStart, $dateEnd);
        $factCount = $platform === 'all_ota'
            ? array_sum(array_intersect_key($factPlatformCounts, array_fill_keys(self::ALL_OTA_REQUIRED_PLATFORMS, true)))
            : max(count($facts), (int)($evidence['fact_count'] ?? 0));
        $missingFactPlatforms = $platform === 'all_ota'
            ? array_values(array_filter(self::ALL_OTA_REQUIRED_PLATFORMS, static function (string $requiredPlatform) use (
                $factPlatformCounts,
                $factPlatformDates,
                $requiredDates
            ): bool {
                $dates = $factPlatformDates[$requiredPlatform] ?? [];
                return (int)($factPlatformCounts[$requiredPlatform] ?? 0) <= 0 || $dates !== $requiredDates;
            }))
            : [];

        $answerStatus = 'blocked_by_missing_facts';
        $answerSummary = '该酒店、平台和日期范围内没有找到已保存且完成严格回读的经营事实，暂不生成经营结论。';
        $dataGaps = [];
        if ($factCount === 0) {
            $dataGaps[] = [
                'code' => 'saved_verified_fact_missing',
                'message' => '缺少同酒店、同平台、同日期范围的 readback_verified 事实。',
            ];
            if ($platform === 'all_ota') {
                $dataGaps[] = [
                    'code' => 'all_ota_platform_fact_coverage_missing',
                    'message' => '全渠道问题必须同时具备携程和美团的严格回读事实。',
                    'missing_platforms' => self::ALL_OTA_REQUIRED_PLATFORMS,
                ];
            }
        } elseif ($platform === 'all_ota' && $missingFactPlatforms !== []) {
            $answerSummary = sprintf(
                '已读取部分 OTA 严格回读事实，但缺少%s同酒店、同日期范围的事实，不能形成全渠道经营结论。',
                implode('、', array_map([$this, 'platformLabel'], $missingFactPlatforms))
            );
            $dataGaps[] = [
                'code' => 'all_ota_platform_fact_coverage_missing',
                'message' => '全渠道问题必须同时具备携程和美团的严格回读事实。',
                'missing_platforms' => $missingFactPlatforms,
            ];
        } else {
            $types = array_values(array_unique(array_filter(array_map(
                static fn(array $row): string => trim((string)($row['data_type'] ?? '')),
                $facts
            ))));
            $typeText = $types === [] ? '已保存事实' : implode('、', array_slice($types, 0, 6));
            $latestDiagnosis = $diagnoses[0] ?? [];
            $savedConclusion = trim((string)($latestDiagnosis['summary'] ?? ''));
            if ($savedConclusion !== '') {
                $answerStatus = 'answered_from_saved_diagnosis';
                $answerSummary = $savedConclusion;
            } else {
                $answerStatus = 'evidence_ready';
                $answerSummary = sprintf(
                    '已读取%d条同酒店、同平台、同日期范围的严格回读事实，覆盖%s；当前仅形成可追溯证据摘要，未替代指标口径复核或人工经营判断。',
                    $factCount,
                    $typeText
                );
                if ($platform === 'all_ota') {
                    $dataGaps[] = [
                        'code' => $diagnosisRejectionCodes === []
                            ? 'all_ota_saved_diagnosis_missing'
                            : 'all_ota_saved_diagnosis_not_current',
                        'message' => $diagnosisRejectionCodes === []
                            ? '事实已覆盖携程和美团，但没有明确保存为 all_ota 且严格回读的跨渠道诊断；单渠道诊断不会被拼接为全渠道结论。'
                            : '已有跨渠道诊断不是当前同酒店同请求日的 active 精确回读记录，不能用于回答。',
                        'reason_codes' => array_values(array_unique($diagnosisRejectionCodes)),
                    ];
                } else {
                    $dataGaps[] = [
                        'code' => 'saved_agent_diagnosis_missing',
                        'message' => '存在已回读事实，但没有同范围的已保存 Agent 诊断；答案保持证据摘要级。',
                    ];
                }
            }
        }

        $factRefs = $this->refs($facts);
        $memoryRefs = $this->refs($evidence['memories']);
        $knowledgeRefs = $this->refs($evidence['knowledge']);
        $executionRefs = $this->refs($evidence['executions']);
        $diagnosisRefs = $this->refs($diagnoses);
        $answer = [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'deterministic_saved_evidence',
            'status' => $answerStatus,
            'summary' => $answerSummary,
            'scope' => [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'source_scope' => 'ota_channel',
            ],
            'evidence_counts' => [
                'facts' => $factCount,
                'fact_samples' => count($facts),
                'fact_platforms' => $factPlatformCounts,
                'fact_platform_dates' => $factPlatformDates,
                'operating_memories' => count($memoryRefs),
                'saved_agent_diagnoses' => count($diagnosisRefs),
                'knowledge_units' => count($knowledgeRefs),
                'execution_reviews' => count($executionRefs),
            ],
            'fact_samples' => array_slice($facts, 0, 12),
            'diagnosis_refs' => $diagnosisRefs,
            'data_gaps' => $dataGaps,
            'boundaries' => [
                'external_llm_called' => false,
                'ota_write' => false,
                'external_message' => false,
                'automatic_execution' => false,
            ],
        ];
        $digest = $this->digest([
            'question' => $question,
            'answer' => $answer,
            'fact_refs' => $factRefs,
            'memory_refs' => $memoryRefs,
            'knowledge_refs' => $knowledgeRefs,
            'execution_refs' => $executionRefs,
        ]);
        $requestKey = 'operating-question:' . substr($this->digest([
            $tenantId, $hotelId, $platform, $dateStart, $dateEnd, $question, $digest,
        ]), 0, 48);

        $existing = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('request_key', $requestKey)
            ->whereNull('deleted_at')
            ->find();
        $created = false;
        if (is_array($existing)) {
            $id = (int)$existing['id'];
        } else {
            $now = date('Y-m-d H:i:s');
            try {
                $id = (int)Db::name(self::TABLE)->insertGetId([
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'request_key' => $requestKey,
                    'question_text' => $question,
                    'platform' => $platform,
                    'date_start' => $dateStart,
                    'date_end' => $dateEnd,
                    'answer_status' => $answerStatus,
                    'answer_summary' => $answerSummary,
                    'answer_json' => $this->encode($answer),
                    'fact_refs_json' => $this->encode($factRefs),
                    'memory_refs_json' => $this->encode($memoryRefs),
                    'knowledge_refs_json' => $this->encode($knowledgeRefs),
                    'execution_refs_json' => $this->encode($executionRefs),
                    'data_gaps_json' => $this->encode($dataGaps),
                    'content_digest' => $digest,
                    'created_by' => max(0, $createdBy),
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
                if ($id <= 0) {
                    throw new RuntimeException('经营问题保存失败：未取得记录ID');
                }
                $created = true;
            } catch (\Throwable $e) {
                // A double click or retry may race with the same unique request.
                // Recover by reading that exact scoped row; unrelated failures
                // still surface unchanged.
                $concurrent = Db::name(self::TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('request_key', $requestKey)
                    ->whereNull('deleted_at')
                    ->find();
                if (!is_array($concurrent)) {
                    throw $e;
                }
                $id = (int)$concurrent['id'];
            }
        }

        $questionRow = $this->read($id, $tenantId, [$hotelId]);
        if ((int)$questionRow['hotel_id'] !== $hotelId
            || (string)$questionRow['content_digest'] !== $digest
            || (string)$questionRow['question_text'] !== $question
        ) {
            throw new RuntimeException('经营问题已写入但严格回读校验失败');
        }

        return [
            'question' => $questionRow,
            'created' => $created,
            'persistence_status' => 'readback_verified',
            'write_boundaries' => $answer['boundaries'],
        ];
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function read(int $id, int $tenantId, array $hotelIds): array
    {
        $this->assertTableReady();
        $hotelIds = $this->hotelIds($hotelIds);
        if ($id <= 0 || $hotelIds === []) {
            throw new InvalidArgumentException('经营问题ID或酒店范围无效');
        }
        $query = Db::name(self::TABLE)
            ->where('id', $id)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at');
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('operating question not found');
        }
        return $this->normalizeRow($row);
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function list(int $tenantId, array $hotelIds, ?int $hotelId = null): array
    {
        if (!$this->tableExists(self::TABLE)) {
            return [
                'data_status' => 'migration_required',
                'list' => [],
                'count' => 0,
                'data_gaps' => [['code' => 'operating_question_table_missing']],
            ];
        }
        $hotelIds = $this->hotelIds($hotelIds);
        if ($hotelIds === []) {
            throw new InvalidArgumentException('经营问题查询缺少可访问酒店');
        }
        if ($hotelId !== null && !in_array($hotelId, $hotelIds, true)) {
            throw new RuntimeException('无权查看该酒店经营问题');
        }
        $query = Db::name(self::TABLE)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at');
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($hotelId !== null) {
            $query->where('hotel_id', $hotelId);
        }
        $rows = $query->order('id', 'desc')->limit(50)->select()->toArray();
        return [
            'data_status' => 'ok',
            'list' => array_map([$this, 'normalizeRow'], $rows),
            'count' => count($rows),
            'data_gaps' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function loadEvidence(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $dateStart,
        string $dateEnd,
        string $question
    ): array {
        return [
            'facts' => $this->loadFacts($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
            'fact_count' => $this->factCount($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
            'fact_platform_counts' => $this->factPlatformCounts($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
            'fact_platform_dates' => $this->factPlatformDates($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
            'memories' => $this->loadMemories($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
            'diagnoses' => $this->loadDiagnoses($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
            'knowledge' => $this->loadKnowledge($hotelId, $question),
            'executions' => $this->loadExecutions($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function loadFacts(int $tenantId, int $hotelId, string $platform, string $dateStart, string $dateEnd): array
    {
        if (!$this->tableExists('online_daily_data')) {
            return [];
        }
        $query = $this->factQuery($tenantId, $hotelId, $platform, $dateStart, $dateEnd);
        $rows = $query
            ->field('id,data_date,platform,source,data_type,dimension,validation_status,history_status,readback_verified,readback_verified_at,ingestion_method,source_trace_id')
            ->order('data_date', 'desc')
            ->order('id', 'desc')
            ->limit(40)
            ->select()
            ->toArray();
        return array_map(static function (array $row): array {
            $rowPlatform = strtolower(trim((string)($row['platform'] ?? '')));
            if ($rowPlatform === '') {
                $rowPlatform = strtolower(trim((string)($row['source'] ?? '')));
            }
            return [
                'ref' => 'online_daily_data#' . (int)$row['id'],
                'data_date' => (string)$row['data_date'],
                'platform' => $rowPlatform,
                'data_type' => trim((string)($row['data_type'] ?? '')),
                'dimension' => mb_substr(trim((string)($row['dimension'] ?? '')), 0, 180),
                'quality_status' => 'verified',
                'history_status' => (string)($row['history_status'] ?? ''),
                'readback_status' => 'readback_verified',
                'readback_verified_at' => $row['readback_verified_at'] ?? null,
                'ingestion_method' => (string)($row['ingestion_method'] ?? ''),
                'source_trace_id' => (string)($row['source_trace_id'] ?? ''),
            ];
        }, $rows);
    }

    private function factCount(int $tenantId, int $hotelId, string $platform, string $dateStart, string $dateEnd): int
    {
        if (!$this->tableExists('online_daily_data')) {
            return 0;
        }
        return (int)$this->factQuery($tenantId, $hotelId, $platform, $dateStart, $dateEnd)->count();
    }

    private function factQuery(int $tenantId, int $hotelId, string $platform, string $dateStart, string $dateEnd): mixed
    {
        // The generated history_status is the canonical persisted-fact truth
        // gate. A row may be stored and read back while still being partial or
        // unverified (for example legacy/manual ingestion or missing trace and
        // capture time), so readback_verified alone must never promote it.
        if (!$this->columnExists('online_daily_data', 'history_status')) {
            return Db::name('online_daily_data')->whereRaw('1 = 0');
        }
        $query = Db::name('online_daily_data')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->whereBetween('data_date', [$dateStart, $dateEnd])
            ->where('history_status', 'success')
            ->where('readback_verified', 1)
            ->where('validation_status', 'verified');
        if ($platform === 'all_ota') {
            $query->whereRaw(
                "LOWER(COALESCE(NULLIF(`platform`, ''), `source`, '')) IN ('ctrip','meituan')"
            );
        } else {
            $query->whereRaw(
                "LOWER(COALESCE(NULLIF(`platform`, ''), `source`, '')) = :operating_platform",
                ['operating_platform' => $platform]
            );
        }
        return $query;
    }

    /** @return array<string,int> */
    private function factPlatformCounts(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $dateStart,
        string $dateEnd
    ): array {
        $platforms = $platform === 'all_ota' ? self::ALL_OTA_REQUIRED_PLATFORMS : [$platform];
        $counts = [];
        foreach ($platforms as $scopedPlatform) {
            $counts[$scopedPlatform] = $this->tableExists('online_daily_data')
                ? (int)$this->factQuery($tenantId, $hotelId, $scopedPlatform, $dateStart, $dateEnd)->count()
                : 0;
        }
        return $counts;
    }

    /** @return array<string,list<string>> */
    private function factPlatformDates(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $dateStart,
        string $dateEnd
    ): array {
        $platforms = $platform === 'all_ota' ? self::ALL_OTA_REQUIRED_PLATFORMS : [$platform];
        $dates = [];
        foreach ($platforms as $scopedPlatform) {
            $values = $this->tableExists('online_daily_data')
                ? $this->factQuery($tenantId, $hotelId, $scopedPlatform, $dateStart, $dateEnd)->column('data_date')
                : [];
            $dates[$scopedPlatform] = array_values(array_unique(array_filter(array_map('strval', $values))));
            sort($dates[$scopedPlatform], SORT_STRING);
        }
        return $dates;
    }

    /** @return list<array<string,mixed>> */
    private function loadMemories(int $tenantId, int $hotelId, string $platform, string $dateStart, string $dateEnd): array
    {
        if (!$this->tableExists(OperatingMemoryService::TABLE)) {
            return [];
        }
        $query = Db::name(OperatingMemoryService::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->whereBetween('business_date', [$dateStart, $dateEnd])
            ->where('lifecycle_status', 'active')
            ->whereNull('deleted_at');
        if ($platform !== 'all_ota') {
            $query->where('platform', $platform);
        }
        $rows = $query->field('id,memory_layer,title,summary,quality_status,usage_level,business_date,platform')
            ->order('id', 'desc')->limit(20)->select()->toArray();
        return array_map(static fn(array $row): array => [
            'ref' => 'hotel_operating_memories#' . (int)$row['id'],
            'memory_layer' => (string)$row['memory_layer'],
            'title' => (string)$row['title'],
            'summary' => (string)$row['summary'],
            'quality_status' => (string)$row['quality_status'],
            'usage_level' => (string)$row['usage_level'],
            'business_date' => $row['business_date'] ?? null,
            'platform' => (string)$row['platform'],
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function loadDiagnoses(int $tenantId, int $hotelId, string $platform, string $dateStart, string $dateEnd): array
    {
        if (!$this->tableExists('agent_logs')) {
            return [];
        }
        $query = Db::name('agent_logs')
            ->where('hotel_id', $hotelId)
            ->where('action', 'ota_diagnosis')
            ->order('id', 'desc')
            ->limit(30);
        if ($this->columnExists('agent_logs', 'tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }
        $items = [];
        foreach ($query->select()->toArray() as $row) {
            $context = $this->decode($row['context_data'] ?? null);
            $snapshot = is_array($context['diagnosis_result'] ?? null) ? $context['diagnosis_result'] : [];
            $saved = is_array($snapshot['saved_record'] ?? null) ? $snapshot['saved_record'] : [];
            $recordPlatform = strtolower(trim((string)($snapshot['platform'] ?? $context['platform'] ?? '')));
            $candidate = [
                'ref' => 'agent_logs#' . (int)$row['id'],
                'summary' => trim((string)($snapshot['core_conclusion'] ?? $snapshot['diagnosis']['summary'] ?? '')),
                'decision_status' => (string)($snapshot['decision_status'] ?? 'blocked_by_data'),
                'platform' => $recordPlatform,
                'record_status' => (string)($snapshot['record_status'] ?? $context['record_status'] ?? ''),
                'saved' => ($saved['saved'] ?? false) === true,
                'readback_verified' => ($saved['readback_verified'] ?? false) === true,
                'saved_record_status' => (string)($saved['status'] ?? 'active'),
                'readback_identity_digest' => (string)($context['readback_identity_digest'] ?? ''),
                'saved_readback_identity_digest' => (string)($saved['readback_identity_digest'] ?? ''),
                'requested_date_range' => is_array($snapshot['requested_date_range'] ?? null)
                    ? $snapshot['requested_date_range']
                    : (array)($context['requested_date_range'] ?? $snapshot['date_range'] ?? []),
                'effective_date_range' => is_array($snapshot['effective_date_range'] ?? null)
                    ? $snapshot['effective_date_range']
                    : (array)($snapshot['date_range'] ?? []),
                'used_latest_available_data' => ($snapshot['data_summary']['used_latest_available_data'] ?? false) === true,
                'coverage' => is_array($snapshot['coverage'] ?? null) ? $snapshot['coverage'] : [],
                'evidence_refs' => is_array($snapshot['evidence_refs'] ?? null) ? $snapshot['evidence_refs'] : [],
                'validation_status' => (string)($snapshot['validation_status'] ?? ''),
            ];
            if ($this->diagnosisIneligibilityCode(
                $candidate,
                $tenantId,
                $hotelId,
                $platform,
                $dateStart,
                $dateEnd
            ) !== '') {
                continue;
            }
            $candidate['date_start'] = $dateStart;
            $candidate['date_end'] = $dateEnd;
            $candidate['readback_status'] = 'readback_verified';
            $items[] = $candidate;
            if (count($items) >= 5) {
                break;
            }
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function loadKnowledge(int $hotelId, string $question): array
    {
        if (!$this->tableExists('knowledge_units')) {
            return [];
        }
        $sources = ['revenue_operations_decision_support', 'ota_operation_sop_reference', 'ota_daily_operations_ledger_reference'];
        $query = Db::name('knowledge_units')->where('status', 'done')->whereIn('source', $sources);
        if ($this->columnExists('knowledge_units', 'hotel_id')) {
            $query->whereIn('hotel_id', [0, $hotelId]);
        }
        if ($this->columnExists('knowledge_units', 'lifecycle_status')) {
            $query->where('lifecycle_status', 'active');
        }
        $rows = $query->field('unit_id,hotel_id,name,source,status,description')
            ->order('unit_id', 'desc')->limit(12)->select()->toArray();
        $terms = $this->questionTerms($question);
        if ($terms !== []) {
            $rows = array_values(array_filter($rows, static function (array $row) use ($terms): bool {
                $text = mb_strtolower((string)($row['name'] ?? '') . ' ' . (string)($row['description'] ?? ''));
                foreach ($terms as $term) {
                    if (str_contains($text, $term)) {
                        return true;
                    }
                }
                return false;
            }));
        }
        return array_map(static fn(array $row): array => [
            'ref' => 'knowledge_units#' . (int)$row['unit_id'],
            'name' => (string)$row['name'],
            'source' => (string)$row['source'],
            'authority' => (int)($row['hotel_id'] ?? 0) === 0 ? 'system_reference' : 'hotel_scoped',
        ], array_slice($rows, 0, 6));
    }

    /** @return list<array<string,mixed>> */
    private function loadExecutions(int $tenantId, int $hotelId, string $platform, string $dateStart, string $dateEnd): array
    {
        if (!$this->tableExists('operation_execution_tasks') || !$this->tableExists('operation_execution_intents')) {
            return [];
        }
        $query = Db::name('operation_execution_tasks')->alias('t')
            ->join('operation_execution_intents i', 'i.id = t.intent_id')
            ->where('t.tenant_id', $tenantId)
            ->where('i.tenant_id', $tenantId)
            ->where('t.hotel_id', $hotelId)
            ->where('i.hotel_id', $hotelId)
            ->where('t.status', 'executed')
            ->where('t.result_summary', '<>', '')
            ->where('i.date_start', '<=', $dateEnd)
            ->whereRaw('COALESCE(`i`.`date_end`, `i`.`date_start`) >= :execution_date_start', [
                'execution_date_start' => $dateStart,
            ])
            ->whereNull('t.deleted_at')
            ->whereNull('i.deleted_at');
        if ($platform !== 'all_ota') {
            $query->where('i.platform', $platform);
        }
        $rows = $query->field('t.id,t.result_status,t.result_summary,t.executed_at,i.platform,i.action_type,i.expected_metric')
            ->order('t.id', 'desc')->limit(10)->select()->toArray();
        return array_map(static fn(array $row): array => [
            'ref' => 'operation_execution_task#' . (int)$row['id'],
            'result_status' => (string)$row['result_status'],
            'summary' => (string)$row['result_summary'],
            'executed_at' => $row['executed_at'] ?? null,
            'platform' => (string)$row['platform'],
            'action_type' => (string)$row['action_type'],
            'expected_metric' => (string)$row['expected_metric'],
        ], $rows);
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function normalizeEvidence(array $evidence): array
    {
        foreach (['facts', 'memories', 'diagnoses', 'knowledge', 'executions'] as $key) {
            $value = is_array($evidence[$key] ?? null) ? $evidence[$key] : [];
            $evidence[$key] = array_values(array_filter($value, 'is_array'));
        }
        $evidence['fact_count'] = max(0, (int)($evidence['fact_count'] ?? count($evidence['facts'])));
        $evidence['fact_platform_counts'] = $this->factPlatformCountsFromEvidence($evidence);
        $evidence['fact_platform_dates'] = $this->factPlatformDatesFromEvidence($evidence);
        return $evidence;
    }

    /** @param array<string,mixed> $evidence @return array<string,int> */
    private function factPlatformCountsFromEvidence(array $evidence): array
    {
        $counts = [];
        $provided = is_array($evidence['fact_platform_counts'] ?? null)
            ? $evidence['fact_platform_counts']
            : [];
        foreach ($provided as $platform => $count) {
            $normalized = strtolower(trim((string)$platform));
            if (in_array($normalized, self::PLATFORMS, true) && $normalized !== 'all_ota') {
                $counts[$normalized] = max(0, (int)$count);
            }
        }
        $sampleCounts = [];
        foreach ($evidence['facts'] ?? [] as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $platform = strtolower(trim((string)($fact['platform'] ?? '')));
            if ($platform === '') {
                $platform = strtolower(trim((string)($fact['source'] ?? '')));
            }
            if (!in_array($platform, self::PLATFORMS, true) || $platform === 'all_ota') {
                continue;
            }
            $sampleCounts[$platform] = ($sampleCounts[$platform] ?? 0) + 1;
        }
        foreach ($sampleCounts as $platform => $count) {
            $counts[$platform] = max($counts[$platform] ?? 0, $count);
        }
        ksort($counts, SORT_STRING);
        return $counts;
    }

    /** @param array<string,mixed> $evidence @return array<string,list<string>> */
    private function factPlatformDatesFromEvidence(array $evidence): array
    {
        $dates = [];
        $provided = is_array($evidence['fact_platform_dates'] ?? null)
            ? $evidence['fact_platform_dates']
            : [];
        foreach ($provided as $platform => $values) {
            $normalized = strtolower(trim((string)$platform));
            if (!in_array($normalized, self::PLATFORMS, true) || $normalized === 'all_ota') {
                continue;
            }
            $dates[$normalized] = array_values(array_unique(array_filter(array_map(
                'strval',
                is_array($values) ? $values : []
            ))));
            sort($dates[$normalized], SORT_STRING);
        }
        foreach ($evidence['facts'] ?? [] as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $platform = strtolower(trim((string)($fact['platform'] ?? '')));
            if ($platform === '') {
                $platform = strtolower(trim((string)($fact['source'] ?? '')));
            }
            $date = trim((string)($fact['data_date'] ?? ''));
            if (!in_array($platform, self::PLATFORMS, true) || $platform === 'all_ota' || $date === '') {
                continue;
            }
            $dates[$platform][] = $date;
            $dates[$platform] = array_values(array_unique($dates[$platform]));
            sort($dates[$platform], SORT_STRING);
        }
        ksort($dates, SORT_STRING);
        return $dates;
    }

    /** @return list<string> */
    private function dateRange(string $startDate, string $endDate): array
    {
        $dates = [];
        $cursor = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($endDate);
        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
        return $dates;
    }

    /** @param array<string,mixed> $diagnosis */
    private function diagnosisIneligibilityCode(
        array $diagnosis,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $dateStart,
        string $dateEnd
    ): string {
        if (strtolower(trim((string)($diagnosis['platform'] ?? ''))) !== $platform) {
            return 'platform_mismatch';
        }
        if ((string)($diagnosis['record_status'] ?? '') !== 'active'
            || (string)($diagnosis['saved_record_status'] ?? 'active') === 'superseded'
        ) {
            return 'diagnosis_not_active';
        }
        if (($diagnosis['saved'] ?? false) !== true || ($diagnosis['readback_verified'] ?? false) !== true) {
            return 'diagnosis_readback_unverified';
        }
        if (in_array(strtolower(trim((string)($diagnosis['validation_status'] ?? ''))), [
            'invalid_evidence', 'stale', 'unverified', 'superseded',
        ], true)) {
            return 'diagnosis_validation_not_current';
        }
        $requested = $this->normalizeDiagnosisDateRange($diagnosis['requested_date_range'] ?? null);
        $effective = $this->normalizeDiagnosisDateRange($diagnosis['effective_date_range'] ?? null);
        $target = ['start_date' => $dateStart, 'end_date' => $dateEnd];
        if ($requested !== $target) {
            return 'diagnosis_requested_date_mismatch';
        }
        if ($effective !== $target || $effective !== $requested) {
            return 'diagnosis_effective_date_mismatch';
        }
        if (($diagnosis['used_latest_available_data'] ?? false) === true) {
            return 'diagnosis_used_latest_available_data';
        }
        if ($platform !== 'all_ota') {
            return '';
        }
        $readbackIdentityDigest = trim((string)($diagnosis['readback_identity_digest'] ?? ''));
        if ($readbackIdentityDigest === ''
            || $readbackIdentityDigest !== trim((string)($diagnosis['saved_readback_identity_digest'] ?? ''))
        ) {
            return 'all_ota_diagnosis_readback_identity_mismatch';
        }

        $coverage = is_array($diagnosis['coverage'] ?? null) ? $diagnosis['coverage'] : [];
        $required = array_values(array_map('strval', (array)($coverage['required_platforms'] ?? [])));
        $covered = array_values(array_map('strval', (array)($coverage['covered_platforms'] ?? [])));
        sort($required, SORT_STRING);
        sort($covered, SORT_STRING);
        $expected = self::ALL_OTA_REQUIRED_PLATFORMS;
        sort($expected, SORT_STRING);
        if (($coverage['complete'] ?? false) !== true
            || $required !== $expected
            || $covered !== $expected
            || (array)($coverage['missing_platforms'] ?? []) !== []
        ) {
            return 'all_ota_diagnosis_coverage_incomplete';
        }
        $evidenceRefs = is_array($diagnosis['evidence_refs'] ?? null) ? $diagnosis['evidence_refs'] : [];
        foreach (self::ALL_OTA_REQUIRED_PLATFORMS as $requiredPlatform) {
            $platformCoverage = is_array($coverage['per_platform'][$requiredPlatform] ?? null)
                ? $coverage['per_platform'][$requiredPlatform]
                : [];
            if (($platformCoverage['status'] ?? '') !== 'ready'
                || (int)($platformCoverage['tenant_id'] ?? 0) !== $tenantId
                || (int)($platformCoverage['hotel_id'] ?? 0) !== $hotelId
                || $this->normalizeDiagnosisDateRange($platformCoverage['requested_date_range'] ?? null) !== $target
                || $this->normalizeDiagnosisDateRange($platformCoverage['effective_date_range'] ?? null) !== $target
                || ($platformCoverage['used_latest_available_data'] ?? false) === true
                || !$this->hasValidDiagnosisEvidenceRefs($evidenceRefs[$requiredPlatform] ?? null)
                || !$this->hasValidDiagnosisEvidenceRefs($platformCoverage['evidence_refs'] ?? null)
            ) {
                return 'all_ota_diagnosis_platform_scope_invalid';
            }
        }
        return '';
    }

    /** @return array{start_date:string,end_date:string} */
    private function normalizeDiagnosisDateRange(mixed $range): array
    {
        $range = is_array($range) ? $range : [];
        $start = trim((string)($range['start_date'] ?? $range['start'] ?? ''));
        $end = trim((string)($range['end_date'] ?? $range['end'] ?? $start));
        return ['start_date' => $start, 'end_date' => $end];
    }

    private function hasValidDiagnosisEvidenceRefs(mixed $refs): bool
    {
        if (!is_array($refs) || $refs === []) {
            return false;
        }
        foreach ($refs as $ref) {
            if (preg_match('/^online_daily_data#[1-9][0-9]*$/D', trim((string)$ref)) !== 1) {
                return false;
            }
        }
        return true;
    }

    private function platformLabel(string $platform): string
    {
        return match ($platform) {
            'ctrip' => '携程',
            'meituan' => '美团',
            'qunar' => '去哪儿',
            default => $platform,
        };
    }

    /** @param list<array<string,mixed>> $items @return list<string> */
    private function refs(array $items): array
    {
        $refs = [];
        foreach ($items as $item) {
            $ref = trim((string)($item['ref'] ?? ''));
            if (preg_match('/^[a-z0-9_]+#[1-9][0-9]*$/D', $ref) === 1) {
                $refs[$ref] = true;
            }
        }
        return array_keys($refs);
    }

    /** @return list<string> */
    private function questionTerms(string $question): array
    {
        $question = mb_strtolower($question);
        $terms = [];
        foreach (['收益', '流量', '转化', '订单', '价格', '排名', '点评', '携程', '美团', '运营', '诊断'] as $term) {
            if (str_contains($question, $term)) {
                $terms[] = $term;
            }
        }
        return $terms;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeRow(array $row): array
    {
        foreach (['id', 'tenant_id', 'hotel_id', 'created_by'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        foreach ([
            'answer_json' => 'answer',
            'fact_refs_json' => 'fact_refs',
            'memory_refs_json' => 'memory_refs',
            'knowledge_refs_json' => 'knowledge_refs',
            'execution_refs_json' => 'execution_refs',
            'data_gaps_json' => 'data_gaps',
        ] as $jsonField => $publicField) {
            $row[$publicField] = $this->decode($row[$jsonField] ?? null);
            unset($row[$jsonField]);
        }
        return $row;
    }

    private function assertHotelIdentity(int $tenantId, int $hotelId): void
    {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('经营问题缺少租户或酒店身份');
        }
        if (!$this->tableExists('hotels')) {
            throw new RuntimeException('酒店身份表不存在');
        }
        $actualTenant = (int)Db::name('hotels')->where('id', $hotelId)->where('status', 1)->value('tenant_id');
        if ($actualTenant <= 0 || $actualTenant !== $tenantId) {
            throw new RuntimeException('经营问题酒店与租户身份不一致');
        }
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new InvalidArgumentException('经营问题平台范围无效');
        }
        return $platform;
    }

    private function date(string $value, string $label): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($label . '格式无效');
        }
        return $value;
    }

    /** @param list<int> $ids @return list<int> */
    private function hotelIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    private function assertTableReady(): void
    {
        if (!$this->tableExists(self::TABLE)) {
            throw new RuntimeException('经营问题功能尚未启用：请先执行本地数据库迁移');
        }
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

    private function columnExists(string $table, string $column): bool
    {
        try {
            $rows = Db::query(
                'SELECT COUNT(*) AS column_count FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column]
            );
            return (int)($rows[0]['column_count'] ?? 0) > 0;
        } catch (\Throwable) {
            try {
                $rows = Db::query('PRAGMA table_info(' . $table . ')');
                return count(array_filter($rows, static fn(array $row): bool => ($row['name'] ?? '') === $column)) > 0;
            } catch (\Throwable) {
                return false;
            }
        }
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', $this->encode($this->canonicalize($value)));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** @return array<mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }
}
