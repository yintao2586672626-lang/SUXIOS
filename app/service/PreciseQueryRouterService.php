<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Deterministic entrypoint for operating lookup, system navigation and
 * reference-only terminology. Numeric text is produced only from persisted
 * facts and deterministic calculations; no model is allowed to invent it.
 */
final class PreciseQueryRouterService
{
    public const CONTRACT_VERSION = 'suxi_precise_query_router.v1';
    public const RECORD_CONTRACT_VERSION = 'suxi_precise_query_record.v1';

    /** @var array<string,array<string,string>> */
    private const METRIC_META = [
        'list_exposure' => ['name' => '曝光量', 'unit' => '次'],
        'detail_exposure' => ['name' => '详情访客', 'unit' => '人'],
        'exposure_to_visit_rate' => ['name' => '曝光到访率', 'unit' => '%'],
        'room_revenue' => ['name' => '房费收入', 'unit' => '来源金额单位'],
        'amount' => ['name' => 'OTA成交金额', 'unit' => '来源金额单位'],
        'book_order_num' => ['name' => '订单量', 'unit' => '单'],
        'quantity' => ['name' => '销售间夜', 'unit' => '间夜'],
        'adr' => ['name' => 'OTA ADR', 'unit' => '来源金额单位/间夜'],
        'occ' => ['name' => '入住率', 'unit' => '%'],
        'revpar' => ['name' => 'RevPAR', 'unit' => '来源金额单位/可售房夜'],
    ];

    /** @var Closure(int,string):array<string,mixed>|null */
    private ?Closure $fieldClosureReader;

    /** @var Closure(int,string):array<string,mixed>|null */
    private ?Closure $scopeClosureReader;

    /**
     * @param null|Closure(array<string,mixed>):array<string,mixed> $systemGuideResolver
     * @param null|Closure(int,int,string,string):array<string,mixed> $knowledgeResolver
     * @param null|Closure():DateTimeImmutable $clock
     * @param null|Closure(int,string):array<string,mixed> $fieldClosureReader
     * @param null|Closure(int,string):array<string,mixed> $scopeClosureReader
     */
    public function __construct(
        private readonly ?Closure $systemGuideResolver = null,
        private readonly ?Closure $knowledgeResolver = null,
        private readonly ?Closure $clock = null,
        ?Closure $fieldClosureReader = null,
        ?Closure $scopeClosureReader = null
    ) {
        $this->fieldClosureReader = $fieldClosureReader !== null
            ? $fieldClosureReader
            : ($systemGuideResolver === null && $knowledgeResolver === null && $clock === null
                ? static fn(int $hotelId, string $businessDate): array =>
                    (new DualOtaFieldClosureService())->build($hotelId, $businessDate)
                : null);
        $this->scopeClosureReader = $scopeClosureReader ?? $this->fieldClosureReader;
    }

    /**
     * @param list<int> $accessibleHotelIds
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function route(
        int $tenantId,
        array $accessibleHotelIds,
        int $userId,
        array $payload
    ): array {
        $query = trim((string)($payload['query'] ?? $payload['question'] ?? ''));
        if ($query === '' || mb_strlen($query) > 1000) {
            throw new InvalidArgumentException('精准查数问题不能为空且不能超过1000字');
        }
        $accessibleHotelIds = array_values(array_unique(array_filter(array_map(
            'intval',
            $accessibleHotelIds
        ), static fn(int $id): bool => $id > 0)));
        $currentScope = is_array($payload['current_scope'] ?? null) ? $payload['current_scope'] : [];
        $requestedMode = strtolower(trim((string)($payload['requested_mode'] ?? 'auto')));
        $routeType = $this->classify($query, $requestedMode);

        return match ($routeType) {
            'system_navigation' => $this->routeSystemNavigation(
                $tenantId,
                $accessibleHotelIds,
                $userId,
                $query,
                $payload,
                $currentScope
            ),
            'term_definition' => $this->routeTermDefinition(
                $tenantId,
                $accessibleHotelIds,
                $userId,
                $query,
                $currentScope
            ),
            'operating_query' => $this->routeOperatingQuery(
                $tenantId,
                $accessibleHotelIds,
                $userId,
                $query,
                $currentScope
            ),
            default => $this->persistClarification(
                $tenantId,
                $accessibleHotelIds,
                $userId,
                $query,
                $currentScope,
                '请问你要查经营数据、找系统功能，还是查询一个术语的含义？',
                'route_intent_missing'
            ),
        };
    }

    /** @param list<int> $accessibleHotelIds @return array<string,mixed> */
    public function read(int $id, int $tenantId, array $accessibleHotelIds): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('精准查数问题编号无效');
        }
        $row = Db::name(OperatingQuestionService::TABLE)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('精准查数问题不存在或无权访问');
        }
        $hotelId = max(0, (int)($row['hotel_id'] ?? 0));
        $allowed = array_values(array_unique(array_map('intval', $accessibleHotelIds)));
        if ($hotelId > 0 && !in_array($hotelId, $allowed, true)) {
            throw new RuntimeException('精准查数问题不存在或无权访问');
        }
        if ($hotelId === 0 && (int)($row['tenant_id'] ?? 0) !== $tenantId) {
            throw new RuntimeException('精准查数问题不存在或无权访问');
        }

        $answer = $this->decode($row['answer_json'] ?? null);
        $router = is_array($answer['query_router'] ?? null) ? $answer['query_router'] : [];
        if ((string)($router['contract_version'] ?? '') !== self::CONTRACT_VERSION) {
            throw new RuntimeException('该记录不是宿析精准查数问题');
        }
        $factRefs = $this->stringList($this->decode($row['fact_refs_json'] ?? null));
        $memoryRefs = $this->stringList($this->decode($row['memory_refs_json'] ?? null));
        $knowledgeRefs = $this->stringList($this->decode($row['knowledge_refs_json'] ?? null));
        $executionRefs = $this->stringList($this->decode($row['execution_refs_json'] ?? null));
        $digest = $this->digest([
            'question' => (string)($row['question_text'] ?? ''),
            'answer' => $answer,
            'fact_refs' => $factRefs,
            'memory_refs' => $memoryRefs,
            'knowledge_refs' => $knowledgeRefs,
            'execution_refs' => $executionRefs,
        ]);
        if (!hash_equals((string)($row['content_digest'] ?? ''), $digest)) {
            throw new RuntimeException('精准查数保存内容摘要与回读不一致');
        }

        return $this->unifiedReadback($row, $answer, $factRefs, $knowledgeRefs);
    }

    /** @return array<string,mixed> */
    public function lexiconMetadata(): array
    {
        return PreciseQueryLexicon::metadata();
    }

    private function classify(string $query, string $requestedMode): string
    {
        if ($requestedMode === 'guide') {
            return 'system_navigation';
        }
        if (in_array($requestedMode, ['report', 'action'], true)) {
            return 'operating_query';
        }
        if (PreciseQueryLexicon::isNavigationQuestion($query)) {
            return 'system_navigation';
        }
        if (PreciseQueryLexicon::isTermQuestion($query)) {
            return 'term_definition';
        }
        if (PreciseQueryLexicon::metric($query) !== ''
            || PreciseQueryLexicon::platform($query) !== ''
            || $this->isComparisonQuestion($query)
        ) {
            return 'operating_query';
        }
        return 'clarification';
    }

    /**
     * @param list<int> $accessibleHotelIds
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $currentScope
     * @return array<string,mixed>
     */
    private function routeSystemNavigation(
        int $tenantId,
        array $accessibleHotelIds,
        int $userId,
        string $query,
        array $payload,
        array $currentScope
    ): array {
        $topicKey = PreciseQueryLexicon::systemTopic($query);
        $guidePayload = $payload;
        $guidePayload['query'] = $query;
        $guidePayload['requested_mode'] = 'guide';
        $guidePayload['user_id'] = $userId;
        $guidePayload['deterministic_only'] = true;
        if ($topicKey !== '') {
            $guidePayload['visible_topic_keys'] = [$topicKey];
        }
        $guide = $this->systemGuideResolver !== null
            ? ($this->systemGuideResolver)($guidePayload)
            : (new SystemUsageAssistantService())->guide($guidePayload);
        if (!is_array($guide)) {
            throw new RuntimeException('系统导航没有返回有效结果');
        }
        $resolvedTopic = trim((string)($guide['topic_key'] ?? $topicKey));
        $summary = trim((string)($guide['assistant_message'] ?? ''));
        if ($summary === '') {
            $summary = '已找到对应的宿析OS功能入口和操作边界。';
        }
        $storageHotelId = $this->contextHotelId($currentScope, $accessibleHotelIds);
        $router = $this->routerEnvelope('system_navigation', 'system_navigation', [
            'hotel_id' => null,
            'platform' => null,
            'business_date' => null,
            'metric_key' => null,
            'system_topic_key' => $resolvedTopic !== '' ? $resolvedTopic : null,
            'scope_applicable' => false,
        ], ['matched_system_topic' => $resolvedTopic]);
        $guideRuntime = is_array($guide['runtime'] ?? null) ? $guide['runtime'] : [];
        $guideBoundaries = $this->boundaries();
        $guideBoundaries['llm_attempted'] = ($guideRuntime['model_attempted'] ?? false) === true;
        $guideBoundaries['llm_client_invoked'] = ($guideRuntime['llm_client_invoked'] ?? false) === true;
        $guideBoundaries['external_llm_called'] = is_bool($guideRuntime['external_llm_called'] ?? null)
            ? $guideRuntime['external_llm_called']
            : false;
        $answer = [
            'contract_version' => self::RECORD_CONTRACT_VERSION,
            'mode' => 'deterministic_route_system_usage_service',
            'status' => $resolvedTopic === 'clarify' ? 'clarification_required' : 'navigation_ready',
            'summary' => $summary,
            'query_router' => $router,
            'system_guidance' => $guide,
            'data_gaps' => [],
            'boundaries' => $guideBoundaries,
        ];
        return $this->persistDirect(
            $tenantId,
            $storageHotelId,
            $userId,
            $query,
            '',
            null,
            $answer,
            [],
            [],
            $accessibleHotelIds
        );
    }

    /**
     * @param list<int> $accessibleHotelIds
     * @param array<string,mixed> $currentScope
     * @return array<string,mixed>
     */
    private function routeTermDefinition(
        int $tenantId,
        array $accessibleHotelIds,
        int $userId,
        string $query,
        array $currentScope
    ): array {
        $term = $this->extractTerm($query);
        $storageHotelId = $this->contextHotelId($currentScope, $accessibleHotelIds);
        $platform = PreciseQueryLexicon::platform((string)($currentScope['platform'] ?? ''));
        if (!in_array($platform, ['ctrip', 'meituan', 'all_ota'], true)) {
            $platform = 'all_ota';
        }
        $knowledge = $this->knowledgeResolver !== null
            ? ($this->knowledgeResolver)($storageHotelId, $userId, $platform, $term)
            : (new OperatingQuestionKnowledgeRetrievalService())->retrieve(
                $storageHotelId,
                $userId,
                $platform,
                $term
            );
        $knowledge = is_array($knowledge) ? $knowledge : [];
        $items = array_values(array_filter(
            is_array($knowledge['items'] ?? null) ? $knowledge['items'] : [],
            'is_array'
        ));
        $knowledgeRefs = array_values(array_unique(array_filter(array_map(
            static fn(array $item): string => trim((string)($item['ref'] ?? '')),
            $items
        ))));
        $definition = PreciseQueryLexicon::referenceDefinition($term);
        $source = '';
        if ($definition === null && isset($items[0])) {
            $excerpt = trim((string)($items[0]['excerpt'] ?? ''));
            if ($excerpt !== '') {
                $definition = [
                    'term' => $term,
                    'definition' => $excerpt,
                    'source' => (string)($items[0]['name'] ?? $items[0]['ref'] ?? '知识中心'),
                    'category' => 'knowledge_center_term',
                ];
            }
        }
        if (is_array($definition)) {
            $source = trim((string)($definition['source'] ?? ''));
        }
        $hasDefinition = is_array($definition) && trim((string)($definition['definition'] ?? '')) !== '';
        $definitionText = $hasDefinition
            ? trim((string)$definition['definition'])
            : sprintf('词库识别到“%s”，但知识中心当前没有可核对的定义；它不会进入经营事实。', $term);
        $status = $hasDefinition ? 'reference_only' : 'reference_only_definition_missing';
        $result = [
            'term' => $term,
            'definition' => $definitionText,
            'category' => $hasDefinition ? (string)($definition['category'] ?? 'reference_term') : 'unresolved_reference_term',
            'usage_policy' => 'reference_only',
            'business_fact_eligible' => false,
            'source' => $source !== '' ? $source : null,
            'knowledge_lookup' => array_diff_key($knowledge, ['items' => true]),
            'knowledge_items' => array_slice($items, 0, 3),
        ];
        $router = $this->routerEnvelope('term_definition', 'term_definition', [
            'hotel_id' => null,
            'platform' => null,
            'business_date' => null,
            'metric_key' => null,
            'term' => $term,
            'scope_applicable' => false,
        ], ['term_question_cue']);
        $answer = [
            'contract_version' => self::RECORD_CONTRACT_VERSION,
            'mode' => 'deterministic_reference_lookup',
            'status' => $status,
            'summary' => $definitionText,
            'query_router' => $router,
            'precise_result' => $result,
            'data_gaps' => $hasDefinition ? [] : [[
                'code' => 'knowledge_definition_missing',
                'message' => '知识中心没有返回可核对定义，已保持 reference_only。',
            ]],
            'boundaries' => $this->boundaries(),
        ];
        return $this->persistDirect(
            $tenantId,
            $storageHotelId,
            $userId,
            $query,
            '',
            null,
            $answer,
            [],
            $knowledgeRefs,
            $accessibleHotelIds
        );
    }

    /**
     * @param list<int> $accessibleHotelIds
     * @param array<string,mixed> $currentScope
     * @return array<string,mixed>
     */
    private function routeOperatingQuery(
        int $tenantId,
        array $accessibleHotelIds,
        int $userId,
        string $query,
        array $currentScope
    ): array {
        $hotel = $this->resolveHotel($query, $currentScope, $accessibleHotelIds);
        if (($hotel['error'] ?? '') !== '') {
            throw new RuntimeException((string)$hotel['error']);
        }
        $platform = PreciseQueryLexicon::platform($query);
        if ($platform === '') {
            $platform = PreciseQueryLexicon::platform((string)($currentScope['platform'] ?? ''));
        }
        $metricKey = PreciseQueryLexicon::metric($query);
        $comparison = $this->isComparisonQuestion($query);
        if ($comparison) {
            $platform = 'all_ota';
        }

        $knownScope = [
            'hotel_id' => (int)($hotel['id'] ?? 0) ?: null,
            'hotel_name' => (string)($hotel['name'] ?? '') ?: null,
            'hotel_source' => (string)($hotel['source'] ?? '') ?: null,
            'platform' => $platform !== '' ? $platform : null,
            'metric_key' => $metricKey !== '' ? $metricKey : null,
            'source_scope' => 'ota_channel',
        ];
        if ((int)($hotel['id'] ?? 0) <= 0) {
            return $this->persistClarification(
                $tenantId,
                $accessibleHotelIds,
                $userId,
                $query,
                $currentScope,
                '请先告诉我需要查询哪一家酒店（例如“Hotel 80”）。',
                'hotel_required',
                $knownScope
            );
        }
        if (!in_array($platform, ['ctrip', 'meituan', 'all_ota'], true)) {
            return $this->persistClarification(
                $tenantId,
                $accessibleHotelIds,
                $userId,
                $query,
                $currentScope,
                '这次要查携程还是美团？',
                'platform_required',
                $knownScope
            );
        }

        $tenantId = $this->hotelTenantId((int)$hotel['id']);
        if ($tenantId <= 0) {
            throw new RuntimeException('目标酒店缺少可核对的租户归属');
        }

        $date = $this->resolveBusinessDateRange(
            $query,
            $currentScope,
            $tenantId,
            (int)$hotel['id'],
            $platform
        );
        if (($date['clarifying_question'] ?? '') !== '') {
            return $this->persistClarification(
                $tenantId,
                $accessibleHotelIds,
                $userId,
                $query,
                $currentScope,
                (string)$date['clarifying_question'],
                (string)($date['reason'] ?? 'business_date_required'),
                $knownScope
            );
        }
        $dateStart = (string)($date['date_start'] ?? $date['business_date'] ?? '');
        $dateEnd = (string)($date['date_end'] ?? $dateStart);
        $businessDate = $dateStart === $dateEnd ? $dateStart : '';
        if ($metricKey === '' && !$comparison) {
            return $this->persistClarification(
                $tenantId,
                $accessibleHotelIds,
                $userId,
                $query,
                $currentScope,
                '你要核对曝光、访客、订单、间夜还是成交金额？',
                'metric_required',
                $knownScope + [
                    'business_date' => $businessDate !== '' ? $businessDate : null,
                    'date_start' => $dateStart,
                    'date_end' => $dateEnd,
                ]
            );
        }

        if ($comparison && $dateStart !== $dateEnd) {
            return $this->persistClarification(
                $tenantId,
                $accessibleHotelIds,
                $userId,
                $query,
                $currentScope,
                '跨平台范围比较需要先明确逐日指标和可比口径；当前只支持单平台逐日趋势。',
                'range_comparison_not_supported',
                $knownScope + ['date_start' => $dateStart, 'date_end' => $dateEnd]
            );
        }
        if ($this->isExposureEstimationQuestion($query) && $dateStart !== $dateEnd) {
            return $this->persistClarification(
                $tenantId,
                $accessibleHotelIds,
                $userId,
                $query,
                $currentScope,
                '曝光人数参考估算必须锁定一个目标业务日；历史配对窗口由系统向前读取，不接受范围结果代替目标日。',
                'exposure_estimation_target_date_required',
                $knownScope + ['date_start' => $dateStart, 'date_end' => $dateEnd]
            );
        }
        if ($this->isExposureEstimationQuestion($query) && !in_array($platform, ['ctrip', 'meituan'], true)) {
            return $this->persistClarification(
                $tenantId,
                $accessibleHotelIds,
                $userId,
                $query,
                $currentScope,
                '曝光人数参考估算必须选择携程或美团中的一个平台，不能使用跨平台倍数。',
                'exposure_estimation_platform_required',
                $knownScope + ['date_start' => $dateStart, 'date_end' => $dateEnd]
            );
        }

        $parsedScope = $knownScope + [
            'business_date' => $businessDate !== '' ? $businessDate : null,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'date_source' => (string)($date['source'] ?? ''),
            'comparison_requested' => $comparison,
            'scope_applicable' => true,
        ];
        $router = $this->routerEnvelope(
            'operating_query',
            $comparison ? 'cross_platform_comparison' : 'operating_metric_lookup',
            $parsedScope,
            array_values(array_filter([
                'metric:' . ($metricKey !== '' ? $metricKey : 'unspecified'),
                'platform:' . $platform,
                'date:' . (string)($date['source'] ?? 'explicit'),
            ]))
        );
        if ($this->isExposureEstimationQuestion($query)) {
            $estimate = (new OtaExposureEstimationReferenceService($this->fieldClosureReader))->estimate(
                $tenantId,
                (int)$hotel['id'],
                $platform,
                $dateStart
            );
            return $this->persistExposureEstimationResult(
                $tenantId,
                $accessibleHotelIds,
                $userId,
                $query,
                $platform,
                $dateStart,
                $parsedScope,
                $router,
                $estimate
            );
        }
        $canonicalClosure = $dateStart === $dateEnd && $this->fieldClosureReader !== null
            ? ($this->fieldClosureReader)((int)$hotel['id'], $businessDate)
            : null;
        $canonicalEvidence = is_array($canonicalClosure)
            ? $this->fieldClosureEvidence(
                $canonicalClosure,
                $tenantId,
                (int)$hotel['id'],
                $platform,
                $businessDate,
                $metricKey
            )
            : null;
        if ($comparison && is_array($canonicalEvidence)) {
            $canonicalEvidence['facts'] = [];
            $canonicalEvidence['fact_count'] = 0;
        }

        $questionService = new OperatingQuestionService(
            $canonicalEvidence === null
                ? null
                : static fn(): array => $canonicalEvidence,
            null,
            function (array $payload) use (
                $query,
                $router,
                $parsedScope,
                $metricKey,
                $comparison,
                $canonicalClosure
            ): array {
                return $this->deterministicOperatingAnswer(
                    $query,
                    $payload,
                    $router,
                    $parsedScope,
                    $metricKey,
                    $comparison,
                    $canonicalClosure
                );
            }
        );
        $created = $questionService->create(
            $tenantId,
            (int)$hotel['id'],
            $query,
            $platform,
            $dateStart,
            $dateEnd,
            $userId,
            'deterministic_lookup'
        );
        $question = is_array($created['question'] ?? null) ? $created['question'] : [];
        $id = (int)($question['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('精准查数经营问题没有返回保存编号');
        }
        $readback = $this->read($id, $tenantId, $accessibleHotelIds);
        if ((int)$readback['id'] !== $id
            || (string)$readback['question'] !== $query
            || (string)$readback['route_type'] !== 'operating_query'
        ) {
            throw new RuntimeException('精准查数经营问题保存与回读不一致');
        }
        return $readback;
    }

    /**
     * Project canonical source references into the evidence shape already
     * persisted by OperatingQuestionService. Numeric values remain only on the
     * captured closure used by the deterministic finalizer below.
     *
     * @param array<string,mixed> $closure
     * @return array<string,mixed>
     */
    private function fieldClosureEvidence(
        array $closure,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $businessDate,
        string $queryMetricKey
    ): array {
        if ((string)($closure['contract_version'] ?? '') !== 'dual_ota_field_closure.v1'
            || (string)($closure['consumer_contract']['contract_version'] ?? '')
                !== 'trusted_ota_daily_fact_consumer.v1'
            || (int)($closure['tenant_id'] ?? 0) !== $tenantId
            || (int)($closure['hotel_id'] ?? 0) !== $hotelId
            || (string)($closure['business_date'] ?? '') !== $businessDate
        ) {
            throw new RuntimeException('精准查数可信事实底座范围不一致', 422);
        }
        $selectedPlatforms = $platform === 'all_ota' ? ['ctrip', 'meituan'] : [$platform];
        $targetCanonicalKey = $this->canonicalFieldKeyForMetric($queryMetricKey);
        $factsByRef = [];
        foreach ($selectedPlatforms as $selectedPlatform) {
            $platformRow = is_array($closure['platforms'][$selectedPlatform] ?? null)
                ? $closure['platforms'][$selectedPlatform]
                : [];
            foreach ((array)($platformRow['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $fieldKey = trim((string)($field['metric_key'] ?? $field['key'] ?? ''));
                if ($targetCanonicalKey === '' || $fieldKey !== $targetCanonicalKey) {
                    continue;
                }
                $refs = $this->stringList($field['source_record_refs'] ?? []);
                foreach ($refs as $ref) {
                    if (preg_match('/^online_daily_data#[1-9][0-9]*$/D', $ref) !== 1) {
                        continue;
                    }
                    $fact = $factsByRef[$ref] ?? [
                        'ref' => $ref,
                        'tenant_id' => $tenantId,
                        'system_hotel_id' => $hotelId,
                        'platform' => $selectedPlatform,
                        'source' => $selectedPlatform,
                        'data_date' => $businessDate,
                        'quality_status' => (string)($field['validation_status'] ?? $field['status'] ?? 'unverified'),
                        'history_status' => (string)($field['history_statuses'][0] ?? 'unverified'),
                        'readback_status' => (string)($field['readback_status'] ?? 'not_attempted'),
                        'collected_at' => (string)($field['collected_at'] ?? ''),
                        'field_contracts' => [],
                        'source_contract_identity' => (string)($closure['page_identity'] ?? ''),
                    ];
                    $canonicalKey = $fieldKey;
                    $fact['field_contracts'][$canonicalKey] = [
                        'status' => (string)$field['status'],
                        'unit' => (string)($field['unit'] ?? ''),
                        'source_paths' => (array)($field['source_paths'] ?? []),
                        'capture_ref' => $field['capture_ref'] ?? null,
                        'revenue_analysis_consumable' =>
                            ($field['revenue_analysis_consumable'] ?? false) === true,
                    ];
                    $factsByRef[$ref] = $fact;
                }
            }
        }
        ksort($factsByRef, SORT_NATURAL);
        $facts = array_values($factsByRef);
        return [
            'facts' => $facts,
            'fact_count' => count($facts),
            'memories' => [],
            'diagnoses' => [],
            'knowledge' => [],
            'executions' => [],
            'knowledge_retrieval' => [],
            'memory_retrieval' => [],
            'source_contract' => [
                'contract_version' => 'trusted_ota_daily_fact_consumer.v1',
                'closure_identity' => (string)($closure['page_identity'] ?? ''),
                'metric_values_recalculated' => false,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $router
     * @param array<string,mixed> $parsedScope
     * @return array<string,mixed>
     */
    private function deterministicOperatingAnswer(
        string $query,
        array $payload,
        array $router,
        array $parsedScope,
        string $metricKey,
        bool $comparison,
        ?array $canonicalClosure = null
    ): array {
        $facts = array_values(array_filter(
            is_array($payload['facts'] ?? null) ? $payload['facts'] : [],
            'is_array'
        ));
        $dateStart = (string)($parsedScope['date_start'] ?? $parsedScope['business_date'] ?? '');
        $dateEnd = (string)($parsedScope['date_end'] ?? $dateStart);
        if (!$comparison && $dateStart !== '' && $dateEnd !== '' && $dateStart !== $dateEnd) {
            return $this->rangeMetricResult($facts, $parsedScope, $metricKey, $router);
        }
        if (is_array($canonicalClosure)) {
            return $comparison
                ? $this->blockedCanonicalComparison($query, $router, $parsedScope, $metricKey)
                : $this->singleCanonicalMetricResult(
                    $canonicalClosure,
                    $parsedScope,
                    $metricKey,
                    $router
                );
        }
        if ($comparison) {
            return $this->comparisonAnswer($query, $facts, $router, $parsedScope, $metricKey);
        }
        $resolved = $this->singleMetricResult($facts, $parsedScope, $metricKey);
        return [
            'status' => (string)$resolved['status'],
            'summary' => (string)$resolved['summary'],
            'precise_result' => (array)$resolved['result'],
            'query_router' => $router,
            'used_evidence_refs' => (array)$resolved['used_refs'],
            'data_gaps' => (array)$resolved['data_gaps'],
        ];
    }

    /**
     * Return one exact point per business date. This deliberately performs no
     * period aggregation because metric grain and source coverage can differ
     * by day.
     *
     * @param list<array<string,mixed>> $facts
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $router
     * @return array<string,mixed>
     */
    private function rangeMetricResult(array $facts, array $scope, string $metricKey, array $router): array
    {
        $dateStart = (string)($scope['date_start'] ?? '');
        $dateEnd = (string)($scope['date_end'] ?? '');
        $range = $this->validatedDateRange($dateStart, $dateEnd, 'resolved_range');
        if (($range['clarifying_question'] ?? '') !== '') {
            throw new RuntimeException('精准查数日期范围在保存前发生漂移', 422);
        }
        $meta = self::METRIC_META[$metricKey] ?? ['name' => $metricKey, 'unit' => '来源单位'];
        $cursor = new DateTimeImmutable($dateStart, new DateTimeZone('Asia/Shanghai'));
        $last = new DateTimeImmutable($dateEnd, new DateTimeZone('Asia/Shanghai'));
        $points = [];
        $usedRefs = [];
        $dataGaps = [];
        $availableCount = 0;

        while ($cursor <= $last) {
            $businessDate = $cursor->format('Y-m-d');
            $dayFacts = array_values(array_filter(
                $facts,
                static fn(array $fact): bool => (string)($fact['data_date'] ?? '') === $businessDate
            ));
            $resolved = $this->singleMetricResult(
                $dayFacts,
                $scope + ['business_date' => $businessDate],
                $metricKey
            );
            $result = is_array($resolved['result'] ?? null) ? $resolved['result'] : [];
            $value = is_numeric($result['value'] ?? null) ? 0 + $result['value'] : null;
            $refs = $this->stringList($resolved['used_refs'] ?? []);
            if ($value !== null) {
                $availableCount++;
                $usedRefs = array_merge($usedRefs, $refs);
            } else {
                $dataGaps[] = [
                    'code' => 'range_date_metric_missing',
                    'business_date' => $businessDate,
                    'message' => (string)($result['blocked_reason'] ?? $resolved['summary'] ?? '该日指标未取得'),
                ];
            }
            $points[] = [
                'business_date' => $businessDate,
                'status' => $value === null ? 'missing' : 'available',
                'value' => $value,
                'unit' => $result['unit'] ?? (string)$meta['unit'],
                'source_record' => $result['source_record'] ?? null,
                'verification_status' => (string)($result['verification_status'] ?? 'missing'),
                'readback_status' => (string)($result['readback_status'] ?? 'missing'),
                'blocked_reason' => $value === null ? ($result['blocked_reason'] ?? $resolved['summary'] ?? null) : null,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $usedRefs = array_values(array_unique($usedRefs));
        $total = count($points);
        $missingCount = $total - $availableCount;
        $summary = sprintf(
            '%s｜%s｜%s 至 %s｜%s逐日趋势：%d/%d 天取得可信值；缺失日保持缺失，未做期间汇总。',
            (string)($scope['hotel_name'] ?? '') ?: 'Hotel ' . (int)($scope['hotel_id'] ?? 0),
            $this->platformLabel((string)($scope['platform'] ?? '')),
            $dateStart,
            $dateEnd,
            (string)$meta['name'],
            $availableCount,
            $total
        );
        return [
            'status' => $availableCount === 0
                ? 'blocked_by_missing_metric_range'
                : ($missingCount > 0 ? 'answered_deterministically_range_partial' : 'answered_deterministically_range'),
            'summary' => $summary,
            'precise_result' => [
                'kind' => 'operating_metric_range',
                'hotel' => [
                    'id' => (int)($scope['hotel_id'] ?? 0),
                    'name' => (string)($scope['hotel_name'] ?? '') ?: 'Hotel ' . (int)($scope['hotel_id'] ?? 0),
                ],
                'platform' => [
                    'key' => (string)($scope['platform'] ?? ''),
                    'name' => $this->platformLabel((string)($scope['platform'] ?? '')),
                ],
                'date_range' => ['start_date' => $dateStart, 'end_date' => $dateEnd],
                'metric' => ['key' => $metricKey, 'name' => (string)$meta['name']],
                'points' => $points,
                'available_day_count' => $availableCount,
                'missing_day_count' => $missingCount,
                'aggregation_performed' => false,
                'data_scope' => $this->dataScopeText($scope),
            ],
            'query_router' => $router,
            'used_evidence_refs' => $usedRefs,
            'data_gaps' => $dataGaps,
        ];
    }

    /**
     * @param list<int> $accessibleHotelIds
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $router
     * @param array<string,mixed> $estimate
     * @return array<string,mixed>
     */
    private function persistExposureEstimationResult(
        int $tenantId,
        array $accessibleHotelIds,
        int $userId,
        string $query,
        string $platform,
        string $businessDate,
        array $scope,
        array $router,
        array $estimate
    ): array {
        $estimateStatus = (string)($estimate['status'] ?? 'not_calculable');
        $estimateValue = is_numeric($estimate['estimate']['value'] ?? null)
            ? 0 + $estimate['estimate']['value']
            : null;
        $refs = $this->stringList($estimate['source_refs'] ?? []);
        $reason = trim((string)($estimate['reason'] ?? '当前严格事实不足，不能形成曝光人数参考估算。'));
        $answerStatus = $estimateStatus === 'estimated'
            ? 'blocked_by_estimate_only_reference'
            : 'blocked_by_exposure_estimation_' . $estimateStatus;
        $preciseResult = [
            'kind' => 'exposure_estimation_reference',
            'hotel' => [
                'id' => (int)($scope['hotel_id'] ?? 0),
                'name' => (string)($scope['hotel_name'] ?? '') ?: 'Hotel ' . (int)($scope['hotel_id'] ?? 0),
            ],
            'platform' => ['key' => $platform, 'name' => $this->platformLabel($platform)],
            'business_date' => $businessDate,
            'metric' => ['key' => 'exposure_users_estimate', 'name' => '曝光人数参考估算'],
            'value' => $estimateValue,
            'unit' => 'people',
            'status' => $answerStatus,
            'source_record' => $refs[0] ?? null,
            'source_records' => $refs,
            'verification_status' => $estimateStatus === 'estimated' ? 'derived_estimate' : $estimateStatus,
            'readback_status' => 'estimate_only_not_platform_fact',
            'formula' => $estimate['estimate']['formula'] ?? null,
            'calculation_inputs' => $estimateStatus === 'estimated' ? [[
                'metric_key' => 'detail_visitors',
                'value' => $estimate['estimate']['target_detail_visitors'] ?? null,
                'unit' => 'people',
            ], [
                'metric_key' => 'verified_pair_count',
                'value' => (int)($estimate['accepted_verified_pairs'] ?? 0),
                'unit' => 'days',
            ]] : [],
            'blocked_reason' => $reason,
            'data_scope' => sprintf(
                'OTA渠道；Hotel %d；%s；目标业务日 %s；参考估算独立于平台事实',
                (int)($scope['hotel_id'] ?? 0),
                $this->platformLabel($platform),
                $businessDate
            ),
            'estimate_receipt' => [
                'contract_version' => (string)($estimate['contract_version'] ?? ''),
                'status' => $estimateStatus,
                'accepted_verified_pairs' => (int)($estimate['accepted_verified_pairs'] ?? 0),
                'min_verified_pairs' => (int)($estimate['scope']['min_verified_pairs'] ?? 7),
                'window_days' => (int)($estimate['scope']['window_days'] ?? 14),
                'quality_status' => (string)($estimate['quality_status'] ?? $estimateStatus),
                'decision_eligible' => false,
                'writeback_allowed' => false,
                'platform_fact_status' => 'unchanged',
            ],
            'decision_eligible' => false,
            'writeback_allowed' => false,
            'platform_fact_status' => 'unchanged',
        ];
        $gap = [
            'code' => (string)($estimate['reason_code'] ?? 'exposure_estimation_reference_only'),
            'message' => $reason,
        ];
        $summary = $estimateValue === null
            ? $reason
            : sprintf(
                '%s｜%s｜%s｜曝光人数参考估算：%s 人。%s',
                (string)$preciseResult['hotel']['name'],
                $this->platformLabel($platform),
                $businessDate,
                $this->number((float)$estimateValue),
                $reason
            );
        $answer = [
            'contract_version' => OperatingQuestionService::CONTRACT_VERSION,
            'mode' => 'deterministic_precise_query',
            'status' => $answerStatus,
            'summary' => $summary,
            'precise_result' => $preciseResult,
            'query_router' => $router,
            'used_evidence_refs' => $refs,
            'data_gaps' => [$gap],
            'ai_runtime' => [
                'status' => 'not_called_deterministic',
                'model_key' => 'deterministic_exposure_estimation_reference',
                'model_attempted' => false,
                'llm_client_invoked' => false,
                'external_llm_called' => false,
                'external_llm_call_status' => 'not_attempted',
            ],
            'boundaries' => $this->boundaries(),
        ];
        return $this->persistDirect(
            $tenantId,
            (int)($scope['hotel_id'] ?? 0),
            $userId,
            $query,
            $platform,
            $businessDate,
            $answer,
            $refs,
            [],
            $accessibleHotelIds
        );
    }

    /** @param array<string,mixed> $closure @param array<string,mixed> $scope @param array<string,mixed> $router */
    private function singleCanonicalMetricResult(
        array $closure,
        array $scope,
        string $metricKey,
        array $router
    ): array {
        $canonicalKey = $this->canonicalFieldKeyForMetric($metricKey);
        $platform = strtolower(trim((string)($scope['platform'] ?? '')));
        $meta = self::METRIC_META[$metricKey] ?? ['name' => $metricKey, 'unit' => '来源单位'];
        $base = [
            'kind' => 'operating_metric',
            'hotel' => [
                'id' => (int)($scope['hotel_id'] ?? 0),
                'name' => (string)($scope['hotel_name'] ?? '')
                    ?: 'Hotel ' . (int)($scope['hotel_id'] ?? 0),
            ],
            'platform' => [
                'key' => $platform,
                'name' => $this->platformLabel($platform),
            ],
            'business_date' => (string)($scope['business_date'] ?? ''),
            'metric' => ['key' => $metricKey, 'name' => (string)$meta['name']],
            'canonical_field_key' => $canonicalKey,
            'value' => null,
            'unit' => null,
            'source_record' => null,
            'source_records' => [],
            'collected_at' => null,
            'verification_status' => 'source_missing',
            'readback_status' => 'not_attempted',
            'data_scope' => $this->dataScopeText($scope),
            'formula' => null,
            'calculation_inputs' => [],
            'conflict_status' => 'none',
            'blocked_reason' => null,
            'closure_identity' => (string)($closure['page_identity'] ?? ''),
            'closure_digest' => (string)($closure['closure_digest'] ?? ''),
        ];
        if ($metricKey === 'room_revenue') {
            $reason = '可信事实底座没有已核验的房费收入字段；OTA成交金额、支付金额或结算金额不能替代房费收入。';
            return $this->canonicalBlockedMetric($base, $router, 'room_revenue_semantic_missing', $reason, []);
        }
        if ($canonicalKey === '' || !in_array($platform, ['ctrip', 'meituan'], true)) {
            $reason = in_array($metricKey, ['occ', 'revpar'], true)
                ? '可信 OTA 底座不包含全酒店可售房夜分母，不能计算该指标。'
                : '该指标尚未进入可信 OTA 昨日事实底座。';
            return $this->canonicalBlockedMetric($base, $router, 'canonical_field_unavailable', $reason, []);
        }

        $platformRow = is_array($closure['platforms'][$platform] ?? null)
            ? $closure['platforms'][$platform]
            : [];
        $fieldMap = [];
        foreach ((array)($platformRow['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string)($field['metric_key'] ?? $field['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (isset($fieldMap[$key])) {
                throw new RuntimeException('精准查数可信事实字段重复:' . $platform . ':' . $key, 422);
            }
            $fieldMap[$key] = $field;
        }
        $field = is_array($fieldMap[$canonicalKey] ?? null) ? $fieldMap[$canonicalKey] : [];
        $refs = $this->stringList($field['source_record_refs'] ?? []);
        $allowedStatuses = array_values(array_unique(array_map(
            'strval',
            (array)($closure['consumer_contract']['allowed_fact_statuses'] ?? [])
        )));
        $value = $field['value'] ?? null;
        $ready = $field !== []
            && in_array((string)($field['status'] ?? ''), $allowedStatuses, true)
            && ($field['revenue_analysis_consumable'] ?? false) === true
            && ($field['strict_final_gate'] ?? false) === true
            && (string)($field['readback_status'] ?? '') === 'readback_verified'
            && (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
            && $refs !== [];
        if (!$ready) {
            $reason = trim((string)($field['note'] ?? ''));
            $nextAction = trim((string)($field['next_action'] ?? ''));
            if ($nextAction !== '' && !str_contains($reason, $nextAction)) {
                $reason .= ($reason !== '' ? ' ' : '') . '下一步：' . $nextAction;
            }
            if ($reason === '') {
                $reason = sprintf(
                    '%s %s 没有可消费的%s事实。',
                    $this->platformLabel($platform),
                    (string)($scope['business_date'] ?? ''),
                    (string)$meta['name']
                );
            }
            $base['verification_status'] = (string)($field['validation_status'] ?? $field['status'] ?? 'source_missing');
            $base['readback_status'] = (string)($field['readback_status'] ?? 'not_attempted');
            $base['conflict_status'] = (string)($field['status'] ?? '') === 'caliber_uncertain'
                ? 'caliber_uncertain'
                : 'none';
            $base['conflict_candidates'] = (array)($field['observed_values'] ?? []);
            return $this->canonicalBlockedMetric(
                $base,
                $router,
                (string)($field['status'] ?? 'source_missing'),
                $reason,
                $refs
            );
        }

        $base['value'] = 0 + $value;
        $base['unit'] = (string)($field['unit'] ?? '');
        $base['source_record'] = $refs[0];
        $base['source_records'] = $refs;
        $base['collected_at'] = $field['collected_at'] ?? null;
        $base['verification_status'] = (string)($field['validation_status'] ?? $field['status'] ?? 'verified');
        $base['readback_status'] = (string)$field['readback_status'];
        $base['formula'] = trim((string)($field['basis'] ?? '')) ?: null;
        $base['source_paths'] = (array)($field['source_paths'] ?? []);
        $base['capture_ref'] = $field['capture_ref'] ?? null;
        if ($canonicalKey === 'conversion') {
            foreach ([
                'visits' => ['metric_key' => 'detail_exposure', 'unit' => 'users'],
                'exposure' => ['metric_key' => 'list_exposure', 'unit' => 'users'],
            ] as $inputKey => $inputMeta) {
                $input = is_array($fieldMap[$inputKey] ?? null) ? $fieldMap[$inputKey] : [];
                if (is_numeric($input['value'] ?? null)
                    && ($input['revenue_analysis_consumable'] ?? false) === true
                ) {
                    $base['calculation_inputs'][] = $inputMeta + ['value' => 0 + $input['value']];
                }
            }
        }
        $summary = sprintf(
            '%s｜%s｜%s｜%s：%s %s。来源 %s，验证状态 %s，回读状态 %s。',
            (string)$base['hotel']['name'],
            (string)$base['platform']['name'],
            (string)$base['business_date'],
            (string)$base['metric']['name'],
            $this->number((float)$base['value'], is_float($base['value']) ? 2 : 0),
            (string)$base['unit'],
            implode('、', $refs),
            (string)$base['verification_status'],
            (string)$base['readback_status']
        );
        $gaps = $this->collectionTimeGaps($base);
        return [
            'status' => $gaps === []
                ? 'answered_from_canonical_closure'
                : 'answered_from_canonical_closure_partial_metadata',
            'summary' => $summary,
            'precise_result' => $base,
            'query_router' => $router,
            'used_evidence_refs' => $refs,
            'data_gaps' => $gaps,
        ];
    }

    private function canonicalFieldKeyForMetric(string $metricKey): string
    {
        return match ($metricKey) {
            'list_exposure' => 'exposure',
            'detail_exposure' => 'visits',
            'exposure_to_visit_rate' => 'conversion',
            'amount' => 'revenue',
            'book_order_num' => 'order_count',
            'quantity' => 'room_nights',
            'adr' => 'adr',
            default => '',
        };
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $router @param list<string> $refs */
    private function canonicalBlockedMetric(
        array $base,
        array $router,
        string $code,
        string $reason,
        array $refs
    ): array {
        $base['blocked_reason'] = $reason;
        return [
            'status' => 'blocked_by_canonical_fact_status',
            'summary' => $reason,
            'precise_result' => $base,
            'query_router' => $router,
            'used_evidence_refs' => $refs,
            'data_gaps' => [['code' => $code, 'message' => $reason]],
        ];
    }

    /** @param array<string,mixed> $router @param array<string,mixed> $scope */
    private function blockedCanonicalComparison(
        string $query,
        array $router,
        array $scope,
        string $metricKey
    ): array {
        $reason = '携程与美团字段定义、采集覆盖和平台口径分别保留；精准查数只回答单平台事实，不生成跨平台高低判断。';
        return [
            'status' => 'blocked_by_cross_platform_comparison',
            'summary' => $reason,
            'precise_result' => [
                'kind' => 'cross_platform_comparison',
                'question' => $query,
                'hotel_id' => (int)($scope['hotel_id'] ?? 0),
                'business_date' => (string)($scope['business_date'] ?? ''),
                'metric_key' => $metricKey,
                'value' => null,
                'comparison_winner' => null,
                'blocked_reason' => $reason,
            ],
            'query_router' => $router,
            'used_evidence_refs' => [],
            'data_gaps' => [[
                'code' => 'cross_platform_comparison_not_supported',
                'message' => $reason,
            ]],
        ];
    }

    /**
     * @param list<array<string,mixed>> $facts
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function singleMetricResult(array $facts, array $scope, string $metricKey): array
    {
        $meta = self::METRIC_META[$metricKey] ?? ['name' => $metricKey, 'unit' => '来源单位'];
        $base = [
            'kind' => 'operating_metric',
            'hotel' => [
                'id' => (int)($scope['hotel_id'] ?? 0),
                'name' => (string)($scope['hotel_name'] ?? '') ?: 'Hotel ' . (int)($scope['hotel_id'] ?? 0),
            ],
            'platform' => [
                'key' => (string)($scope['platform'] ?? ''),
                'name' => $this->platformLabel((string)($scope['platform'] ?? '')),
            ],
            'business_date' => (string)($scope['business_date'] ?? ''),
            'metric' => ['key' => $metricKey, 'name' => (string)$meta['name']],
            'value' => null,
            'unit' => (string)$meta['unit'],
            'source_record' => null,
            'collected_at' => null,
            'verification_status' => 'missing',
            'readback_status' => 'missing',
            'data_scope' => $this->dataScopeText($scope),
            'formula' => null,
            'calculation_inputs' => [],
            'conflict_status' => 'none',
            'blocked_reason' => null,
        ];

        if ($metricKey === 'room_revenue') {
            $fact = $this->latestFactWithFields($facts, ['room_revenue']);
            if ($fact === null) {
                $reason = '该范围没有已核验的房费收入字段；OTA成交金额、支付金额或结算金额不能替代收入。';
                return $this->blockedMetric($base, 'room_revenue_semantic_missing', $reason);
            }
            return $this->directMetric($base, $fact, 'room_revenue');
        }
        if ($metricKey === 'exposure_to_visit_rate') {
            $fact = $this->latestFactWithFields($facts, ['list_exposure', 'detail_exposure']);
            if ($fact === null) {
                $hasExposure = $this->latestFactWithFields($facts, ['list_exposure']) !== null;
                $hasVisitor = $this->latestFactWithFields($facts, ['detail_exposure']) !== null;
                $platform = $this->platformLabel((string)($scope['platform'] ?? ''));
                $date = (string)($scope['business_date'] ?? '');
                $reason = !$hasExposure
                    ? sprintf('%s %s 没有可信曝光字段，因此不能计算曝光到访问率。', $platform, $date)
                    : (!$hasVisitor
                        ? sprintf('%s %s 没有可信详情访客字段，因此不能计算曝光到访问率。', $platform, $date)
                        : sprintf('%s %s 的曝光和访客不在同一来源记录，不能跨记录拼接计算。', $platform, $date));
                return $this->blockedMetric($base, 'aligned_exposure_visitor_missing', $reason);
            }
            $values = (array)($fact['metric_values'] ?? []);
            $exposure = (float)$values['list_exposure'];
            $visitors = (float)$values['detail_exposure'];
            if ($exposure <= 0) {
                return $this->blockedMetric($base, 'exposure_denominator_not_positive', '可信曝光分母不是正数，不能计算曝光到访问率。');
            }
            $value = round($visitors / $exposure * 100, 2);
            $base = $this->attachFact($base, $fact);
            $base['value'] = $value;
            $base['formula'] = sprintf(
                '%s ÷ %s × 100%% = %s%%',
                $this->number($visitors),
                $this->number($exposure),
                $this->number($value, 2)
            );
            $base['calculation_inputs'] = [
                ['metric_key' => 'detail_exposure', 'value' => $visitors, 'unit' => '人'],
                ['metric_key' => 'list_exposure', 'value' => $exposure, 'unit' => '次'],
            ];
            $stored = isset($values['flow_rate']) && is_numeric($values['flow_rate'])
                ? (float)$values['flow_rate']
                : null;
            if ($stored !== null && abs($stored - $value) > 0.05) {
                $base['conflict_status'] = 'stored_rate_semantic_mismatch';
            }
            $summary = sprintf(
                '%s｜%s｜%s｜%s：%s%%。按同一来源记录计算：%s。',
                $base['hotel']['name'],
                $base['platform']['name'],
                $base['business_date'],
                $base['metric']['name'],
                $this->number($value, 2),
                $base['formula']
            );
            $gaps = $this->collectionTimeGaps($base);
            if ($base['conflict_status'] !== 'none') {
                $gaps[] = [
                    'code' => 'stored_rate_semantic_mismatch',
                    'message' => '来源记录中的旧转化率与同记录曝光/访客计算值冲突；本回答采用明确公式计算值。',
                ];
            }
            return [
                'status' => $gaps === [] ? 'answered_deterministically' : 'answered_deterministically_partial_metadata',
                'summary' => $summary,
                'result' => $base,
                'used_refs' => [(string)$base['source_record']],
                'data_gaps' => $gaps,
            ];
        }
        if ($metricKey === 'amount') {
            $fact = $this->latestFactWithAnyField($facts, ['sales_amount', 'gross_revenue', 'amount']);
            if ($fact === null) {
                return $this->blockedMetric($base, 'ota_amount_missing', '该范围没有已严格回读的OTA成交金额字段。');
            }
            $field = $this->firstNumericField($fact, ['sales_amount', 'gross_revenue', 'amount']);
            return $this->directMetric($base, $fact, $field);
        }
        if ($metricKey === 'adr') {
            $fact = $this->latestFactWithFields($facts, ['room_revenue', 'quantity']);
            if ($fact === null) {
                return $this->blockedMetric($base, 'adr_inputs_missing', '缺少同一来源记录中的房费收入或销售间夜，不能计算ADR。');
            }
            $values = (array)$fact['metric_values'];
            if ((float)$values['quantity'] <= 0) {
                return $this->blockedMetric($base, 'adr_denominator_not_positive', '销售间夜不是正数，不能计算ADR。');
            }
            $base = $this->attachFact($base, $fact);
            $base['value'] = round((float)$values['room_revenue'] / (float)$values['quantity'], 2);
            $base['formula'] = '房费收入 ÷ 销售间夜';
            return $this->resolvedMetric($base);
        }
        if (in_array($metricKey, ['occ', 'revpar'], true)) {
            return $this->blockedMetric(
                $base,
                $metricKey . '_whole_hotel_denominator_missing',
                $metricKey === 'occ'
                    ? '缺少同范围可售房夜和出租间夜，不能计算入住率。'
                    : '缺少同范围房费收入和可售房夜，不能计算RevPAR。'
            );
        }

        $field = in_array($metricKey, ['list_exposure', 'detail_exposure', 'book_order_num', 'quantity'], true)
            ? $metricKey
            : '';
        if ($field === '') {
            return $this->blockedMetric($base, 'metric_not_supported', '该指标尚未进入宿析精准查数的确定性映射。');
        }
        $fact = $this->latestFactWithFields($facts, [$field]);
        if ($fact === null) {
            $reason = sprintf(
                '%s %s 没有已严格回读的%s字段。',
                $this->platformLabel((string)($scope['platform'] ?? '')),
                (string)($scope['business_date'] ?? ''),
                (string)$meta['name']
            );
            return $this->blockedMetric($base, $field . '_missing', $reason);
        }
        return $this->directMetric($base, $fact, $field);
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $fact @return array<string,mixed> */
    private function directMetric(array $base, array $fact, string $field): array
    {
        $values = (array)($fact['metric_values'] ?? []);
        $base = $this->attachFact($base, $fact);
        $base['value'] = is_numeric($values[$field] ?? null) ? 0 + $values[$field] : null;
        if ($base['value'] === null) {
            return $this->blockedMetric($base, $field . '_missing', '来源记录中没有可核对的数值。');
        }
        return $this->resolvedMetric($base);
    }

    /** @param array<string,mixed> $base @return array<string,mixed> */
    private function resolvedMetric(array $base): array
    {
        $summary = sprintf(
            '%s｜%s｜%s｜%s：%s %s。来源 %s，验证状态 %s，回读状态 %s。',
            (string)$base['hotel']['name'],
            (string)$base['platform']['name'],
            (string)$base['business_date'],
            (string)$base['metric']['name'],
            $this->number((float)$base['value'], is_float($base['value']) ? 2 : 0),
            (string)$base['unit'],
            (string)$base['source_record'],
            (string)$base['verification_status'],
            (string)$base['readback_status']
        );
        $gaps = $this->collectionTimeGaps($base);
        return [
            'status' => $gaps === [] ? 'answered_deterministically' : 'answered_deterministically_partial_metadata',
            'summary' => $summary,
            'result' => $base,
            'used_refs' => [(string)$base['source_record']],
            'data_gaps' => $gaps,
        ];
    }

    /** @param array<string,mixed> $base @return array<string,mixed> */
    private function blockedMetric(array $base, string $code, string $reason): array
    {
        $base['blocked_reason'] = $reason;
        return [
            'status' => 'blocked_by_missing_metric',
            'summary' => $reason,
            'result' => $base,
            'used_refs' => [],
            'data_gaps' => [['code' => $code, 'message' => $reason]],
        ];
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $fact @return array<string,mixed> */
    private function attachFact(array $base, array $fact): array
    {
        $base['source_record'] = trim((string)($fact['ref'] ?? '')) ?: null;
        $base['collected_at'] = trim((string)($fact['collected_at'] ?? '')) ?: null;
        $base['verification_status'] = trim((string)($fact['quality_status'] ?? 'verified')) ?: 'verified';
        $base['readback_status'] = trim((string)($fact['readback_status'] ?? 'readback_verified')) ?: 'readback_verified';
        return $base;
    }

    /** @param array<string,mixed> $base @return list<array<string,string>> */
    private function collectionTimeGaps(array $base): array
    {
        return ($base['collected_at'] ?? null) === null ? [[
            'code' => 'collection_time_missing',
            'message' => '来源记录没有独立采集时间；数据库回读时间不会冒充采集时间。',
        ]] : [];
    }

    /**
     * @param list<array<string,mixed>> $facts
     * @param list<string> $fields
     * @return array<string,mixed>|null
     */
    private function latestFactWithFields(array $facts, array $fields): ?array
    {
        foreach ($facts as $fact) {
            $values = is_array($fact['metric_values'] ?? null) ? $fact['metric_values'] : [];
            $complete = true;
            foreach ($fields as $field) {
                if (!array_key_exists($field, $values) || !is_numeric($values[$field])) {
                    $complete = false;
                    break;
                }
            }
            if ($complete) {
                return $fact;
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $facts @param list<string> $fields @return array<string,mixed>|null */
    private function latestFactWithAnyField(array $facts, array $fields): ?array
    {
        foreach ($facts as $fact) {
            if ($this->firstNumericField($fact, $fields) !== '') {
                return $fact;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $fact @param list<string> $fields */
    private function firstNumericField(array $fact, array $fields): string
    {
        $values = is_array($fact['metric_values'] ?? null) ? $fact['metric_values'] : [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $values) && is_numeric($values[$field])) {
                return $field;
            }
        }
        return '';
    }

    /**
     * @param list<array<string,mixed>> $facts
     * @param array<string,mixed> $router
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function comparisonAnswer(
        string $query,
        array $facts,
        array $router,
        array $scope,
        string $metricKey
    ): array {
        $base = [
            'kind' => 'cross_platform_comparison',
            'hotel' => ['id' => (int)$scope['hotel_id'], 'name' => (string)$scope['hotel_name']],
            'platform' => ['key' => 'all_ota', 'name' => '携程与美团'],
            'business_date' => (string)$scope['business_date'],
            'metric' => [
                'key' => $metricKey !== '' ? $metricKey : null,
                'name' => $metricKey !== '' ? (string)(self::METRIC_META[$metricKey]['name'] ?? $metricKey) : '平台表现',
            ],
            'value' => null,
            'unit' => $metricKey !== '' ? (string)(self::METRIC_META[$metricKey]['unit'] ?? '来源单位') : null,
            'source_record' => null,
            'collected_at' => null,
            'verification_status' => 'blocked',
            'readback_status' => 'partial_or_missing',
            'data_scope' => $this->dataScopeText($scope),
            'formula' => null,
            'calculation_inputs' => [],
            'conflict_status' => 'comparison_not_eligible',
            'blocked_reason' => null,
        ];
        if ($metricKey === '') {
            $reason = '“表现更好”没有单一可核对指标，且不能把曝光、访客、订单和金额混成一个分数；已拒绝比较。';
            $base['blocked_reason'] = $reason;
            return [
                'status' => 'blocked_by_incomparable_scope',
                'summary' => $reason,
                'precise_result' => $base,
                'query_router' => $router,
                'used_evidence_refs' => [],
                'data_gaps' => [['code' => 'comparison_metric_unspecified', 'message' => $reason]],
            ];
        }
        $byPlatform = ['ctrip' => [], 'meituan' => []];
        foreach ($facts as $fact) {
            $platform = strtolower(trim((string)($fact['platform'] ?? '')));
            if (isset($byPlatform[$platform])) {
                $byPlatform[$platform][] = $fact;
            }
        }
        $leftScope = array_replace($scope, ['platform' => 'ctrip']);
        $rightScope = array_replace($scope, ['platform' => 'meituan']);
        $ctrip = $this->singleMetricResult($byPlatform['ctrip'], $leftScope, $metricKey);
        $meituan = $this->singleMetricResult($byPlatform['meituan'], $rightScope, $metricKey);
        if (($ctrip['result']['value'] ?? null) === null || ($meituan['result']['value'] ?? null) === null) {
            $reason = sprintf(
                '%s 缺少携程或美团同酒店、同业务日期、同指标的可信事实，不能比较哪个平台更好。',
                (string)$scope['business_date']
            );
            $base['blocked_reason'] = $reason;
            return [
                'status' => 'blocked_by_cross_platform_evidence',
                'summary' => $reason,
                'precise_result' => $base + ['platform_results' => [
                    'ctrip' => $ctrip['result'],
                    'meituan' => $meituan['result'],
                ]],
                'query_router' => $router,
                'used_evidence_refs' => [],
                'data_gaps' => [[
                    'code' => 'same_scope_cross_platform_metric_missing',
                    'message' => $reason,
                ]],
            ];
        }
        $ctripValue = (float)$ctrip['result']['value'];
        $meituanValue = (float)$meituan['result']['value'];
        $winner = abs($ctripValue - $meituanValue) < 0.000001
            ? '持平'
            : ($ctripValue > $meituanValue ? '携程' : '美团');
        $summary = sprintf(
            '%s｜%s：携程 %s %s，美团 %s %s；数值较高的是%s。这里只比较该指标，不代表整体经营表现。',
            (string)$scope['business_date'],
            (string)(self::METRIC_META[$metricKey]['name'] ?? $metricKey),
            $this->number($ctripValue, 2),
            (string)(self::METRIC_META[$metricKey]['unit'] ?? ''),
            $this->number($meituanValue, 2),
            (string)(self::METRIC_META[$metricKey]['unit'] ?? ''),
            $winner
        );
        $refs = array_values(array_filter([
            (string)($ctrip['result']['source_record'] ?? ''),
            (string)($meituan['result']['source_record'] ?? ''),
        ]));
        return [
            'status' => 'answered_deterministically',
            'summary' => $summary,
            'precise_result' => array_replace($base, [
                'verification_status' => 'verified',
                'readback_status' => 'readback_verified',
                'conflict_status' => 'none',
                'blocked_reason' => null,
                'platform_results' => [
                    'ctrip' => $ctrip['result'],
                    'meituan' => $meituan['result'],
                ],
                'comparison_winner' => $winner,
                'comparison_boundary' => 'metric_only_no_whole_platform_conclusion',
            ]),
            'query_router' => $router,
            'used_evidence_refs' => $refs,
            'data_gaps' => [],
        ];
    }

    /**
     * @param array<string,mixed> $currentScope
     * @param list<int> $accessibleHotelIds
     * @return array<string,mixed>
     */
    private function resolveHotel(string $query, array $currentScope, array $accessibleHotelIds): array
    {
        $hotelId = 0;
        $source = '';
        if (preg_match('/(?:hotel|酒店|门店)\s*#?\s*([1-9][0-9]*)/iu', $query, $matches) === 1) {
            $hotelId = (int)$matches[1];
            $source = 'question_text';
        } else {
            $named = $this->resolveAccessibleHotelName($query, $accessibleHotelIds);
            if (($named['error'] ?? '') !== '') {
                return $named;
            }
            if ((int)($named['id'] ?? 0) > 0) {
                $hotelId = (int)$named['id'];
                $source = 'question_hotel_name';
            } else {
                $contextId = max(0, (int)($currentScope['hotel_id'] ?? 0));
                if ($contextId > 0) {
                    $hotelId = $contextId;
                    $source = 'current_selected_scope';
                }
            }
        }
        if ($hotelId > 0 && !in_array($hotelId, $accessibleHotelIds, true)) {
            return ['id' => 0, 'name' => '', 'source' => $source, 'error' => '无权查询该酒店'];
        }
        return [
            'id' => $hotelId,
            'name' => $hotelId > 0 ? $this->hotelName($hotelId) : '',
            'source' => $source,
            'error' => '',
        ];
    }

    /** @param list<int> $accessibleHotelIds @return array<string,mixed> */
    private function resolveAccessibleHotelName(string $query, array $accessibleHotelIds): array
    {
        if ($accessibleHotelIds === []) {
            return ['id' => 0, 'name' => '', 'source' => '', 'error' => ''];
        }
        $normalizedQuery = $this->normalizeHotelNameText($query);
        if ($normalizedQuery === '') {
            return ['id' => 0, 'name' => '', 'source' => '', 'error' => ''];
        }
        try {
            $rows = Db::name('hotels')
                ->whereIn('id', $accessibleHotelIds)
                ->where('status', 1)
                ->field('id,name')
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return ['id' => 0, 'name' => '', 'source' => '', 'error' => ''];
        }
        $matches = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            $normalizedName = $this->normalizeHotelNameText($name);
            if ($normalizedName === '' || mb_strlen($normalizedName) < 3) {
                continue;
            }
            if (str_contains($normalizedQuery, $normalizedName)) {
                $matches[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'name' => $name,
                    'length' => mb_strlen($normalizedName),
                ];
            }
        }
        if ($matches === []) {
            return ['id' => 0, 'name' => '', 'source' => '', 'error' => ''];
        }
        usort($matches, static fn(array $left, array $right): int => $right['length'] <=> $left['length']);
        $longest = (int)$matches[0]['length'];
        $top = array_values(array_filter(
            $matches,
            static fn(array $row): bool => (int)$row['length'] === $longest
        ));
        if (count($top) !== 1 || (int)$top[0]['id'] <= 0) {
            return [
                'id' => 0,
                'name' => '',
                'source' => 'question_hotel_name',
                'error' => '酒店名称匹配到多个可访问门店，请补充完整名称或酒店ID',
            ];
        }
        return [
            'id' => (int)$top[0]['id'],
            'name' => (string)$top[0]['name'],
            'source' => 'question_hotel_name',
            'error' => '',
        ];
    }

    private function normalizeHotelNameText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return (string)preg_replace('/[\s\p{P}\p{S}]+/u', '', $value);
    }

    /** @param array<string,mixed> $currentScope @return array<string,mixed> */
    private function resolveBusinessDateRange(
        string $query,
        array $currentScope,
        int $tenantId,
        int $hotelId,
        string $platform
    ): array {
        $fullDate = '(20[0-9]{2})[-\/.年](0?[1-9]|1[0-2])[-\/.月](0?[1-9]|[12][0-9]|3[01])日?(?![0-9])';
        if (preg_match('/' . $fullDate . '\s*(?:至|到|~|～|—|–)\s*' . $fullDate . '/u', $query, $matches) === 1) {
            $start = sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
            $end = sprintf('%04d-%02d-%02d', (int)$matches[4], (int)$matches[5], (int)$matches[6]);
            return $this->validatedDateRange($start, $end, 'explicit_full_date_range');
        }
        $today = $this->now();
        if (preg_match(
            '/(?<![0-9])(0?[1-9]|1[0-2])月(0?[1-9]|[12][0-9]|3[01])日?\s*'
            . '(?:至|到|~|～|—|–)\s*'
            . '(0?[1-9]|1[0-2])月(0?[1-9]|[12][0-9]|3[01])日?(?![0-9])/u',
            $query,
            $matches
        ) === 1) {
            $year = (int)$today->format('Y');
            $start = sprintf('%04d-%02d-%02d', $year, (int)$matches[1], (int)$matches[2]);
            $end = sprintf('%04d-%02d-%02d', $year, (int)$matches[3], (int)$matches[4]);
            return $this->validatedDateRange($start, $end, 'explicit_month_day_range_current_year');
        }
        if (preg_match('/最近\s*([2-9]|[12][0-9]|3[01])\s*天/u', $query, $matches) === 1) {
            $days = (int)$matches[1];
            return [
                'date_start' => $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d'),
                'date_end' => $today->format('Y-m-d'),
                'source' => 'asia_shanghai_recent_days',
            ];
        }
        $normalized = PreciseQueryLexicon::normalize($query);
        $contextStart = substr(trim((string)($currentScope['date_start'] ?? '')), 0, 10);
        $contextEnd = substr(trim((string)($currentScope['date_end'] ?? '')), 0, 10);
        if ($contextStart !== ''
            && $contextEnd !== ''
            && $contextStart !== $contextEnd
            && preg_match('/(?:范围|期间|这段时间|这几天|逐日|趋势)/u', $normalized) === 1
        ) {
            return $this->validatedDateRange($contextStart, $contextEnd, 'current_selected_range');
        }
        $single = $this->resolveBusinessDate($query, $currentScope, $tenantId, $hotelId, $platform);
        if (($single['business_date'] ?? '') !== '') {
            $single['date_start'] = (string)$single['business_date'];
            $single['date_end'] = (string)$single['business_date'];
        }
        return $single;
    }

    /** @return array<string,mixed> */
    private function validatedDateRange(string $start, string $end, string $source): array
    {
        $validatedStart = $this->validatedDate($start, $source);
        $validatedEnd = $this->validatedDate($end, $source);
        if (($validatedStart['business_date'] ?? '') !== $start
            || ($validatedEnd['business_date'] ?? '') !== $end
        ) {
            return [
                'clarifying_question' => '日期范围无效，请按“YYYY-MM-DD 至 YYYY-MM-DD”重新说明。',
                'reason' => 'business_date_range_invalid',
            ];
        }
        if ($start > $end) {
            return [
                'clarifying_question' => '日期范围开始日不能晚于结束日。',
                'reason' => 'business_date_range_reversed',
            ];
        }
        $startDate = new DateTimeImmutable($start, new DateTimeZone('Asia/Shanghai'));
        $endDate = new DateTimeImmutable($end, new DateTimeZone('Asia/Shanghai'));
        $dayCount = (int)$startDate->diff($endDate)->days + 1;
        if ($dayCount > 31) {
            return [
                'clarifying_question' => '精准查数单次日期范围最多31天，请缩小范围。',
                'reason' => 'business_date_range_too_large',
            ];
        }
        return ['date_start' => $start, 'date_end' => $end, 'source' => $source];
    }

    /** @param array<string,mixed> $currentScope @return array<string,mixed> */
    private function resolveBusinessDate(
        string $query,
        array $currentScope,
        int $tenantId,
        int $hotelId,
        string $platform
    ): array {
        $today = $this->now();
        if (preg_match('/\b(20[0-9]{2})[-\/.年](0?[1-9]|1[0-2])[-\/.月](0?[1-9]|[12][0-9]|3[01])日?\b/u', $query, $matches) === 1) {
            $date = sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
            return $this->validatedDate($date, 'explicit_full_date');
        }
        if (preg_match('/(?<![0-9])(0?[1-9]|1[0-2])月(0?[1-9]|[12][0-9]|3[01])日/u', $query, $matches) === 1) {
            $date = sprintf('%04d-%02d-%02d', (int)$today->format('Y'), (int)$matches[1], (int)$matches[2]);
            $validated = $this->validatedDate($date, 'explicit_month_day_current_year');
            if (($validated['business_date'] ?? '') !== '' && $date > $today->format('Y-m-d')) {
                return [
                    'clarifying_question' => sprintf('“%s月%s日”是今年还是上一年？', $matches[1], $matches[2]),
                    'reason' => 'month_day_year_ambiguous',
                ];
            }
            return $validated;
        }
        $normalized = PreciseQueryLexicon::normalize($query);
        if (str_contains($normalized, '前天')) {
            return ['business_date' => $today->modify('-2 days')->format('Y-m-d'), 'source' => 'asia_shanghai_relative_day'];
        }
        if (str_contains($normalized, '昨天') || str_contains($normalized, '昨日')) {
            return ['business_date' => $today->modify('-1 day')->format('Y-m-d'), 'source' => 'asia_shanghai_relative_day'];
        }
        if (str_contains($normalized, '今天') || str_contains($normalized, '今日')) {
            return ['business_date' => $today->format('Y-m-d'), 'source' => 'asia_shanghai_relative_day'];
        }
        if (str_contains($normalized, '当天') || str_contains($normalized, '当日')) {
            $contextDate = $this->singleContextDate($currentScope);
            return $contextDate !== ''
                ? ['business_date' => $contextDate, 'source' => 'current_conversation_business_date']
                : ['clarifying_question' => '“当天”指哪一个业务日期？', 'reason' => 'same_day_context_missing'];
        }
        if (str_contains($normalized, '最近一次') || str_contains($normalized, '最新一次') || str_contains($normalized, '最新')) {
            $options = (new OperatingQuestionService(
                null,
                null,
                null,
                $this->scopeClosureReader
            ))->scopeOptions($tenantId, $hotelId);
            foreach ((array)($options['platforms'] ?? []) as $option) {
                if (is_array($option) && (string)($option['platform'] ?? '') === $platform) {
                    $latest = trim((string)($option['latest_verified_date'] ?? ''));
                    if ($latest !== '') {
                        return ['business_date' => $latest, 'source' => 'latest_strict_readback'];
                    }
                }
            }
            return ['clarifying_question' => '当前没有可确认的最近一次严格回读日期，请指定业务日期。', 'reason' => 'latest_strict_date_missing'];
        }
        $contextDate = $this->singleContextDate($currentScope);
        if ($contextDate !== '') {
            return ['business_date' => $contextDate, 'source' => 'current_selected_scope'];
        }
        return ['clarifying_question' => '请告诉我需要查询哪一个业务日期。', 'reason' => 'business_date_required'];
    }

    /** @return array<string,mixed> */
    private function validatedDate(string $date, string $source): array
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Shanghai'));
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return ['clarifying_question' => '这个业务日期无效，请按“YYYY-MM-DD”重新说明。', 'reason' => 'business_date_invalid'];
        }
        return ['business_date' => $date, 'source' => $source];
    }

    /** @param array<string,mixed> $currentScope */
    private function singleContextDate(array $currentScope): string
    {
        $start = substr(trim((string)($currentScope['business_date'] ?? $currentScope['date_start'] ?? '')), 0, 10);
        $end = substr(trim((string)($currentScope['date_end'] ?? $start)), 0, 10);
        if ($start === '' || $start !== $end) {
            return '';
        }
        return ($this->validatedDate($start, 'context')['business_date'] ?? '') === $start ? $start : '';
    }

    /**
     * @param list<int> $accessibleHotelIds
     * @param array<string,mixed> $currentScope
     * @param array<string,mixed> $knownScope
     * @return array<string,mixed>
     */
    private function persistClarification(
        int $tenantId,
        array $accessibleHotelIds,
        int $userId,
        string $query,
        array $currentScope,
        string $question,
        string $reason,
        array $knownScope = []
    ): array {
        $storageHotelId = max(0, (int)($knownScope['hotel_id'] ?? 0));
        if ($storageHotelId <= 0) {
            $storageHotelId = $this->contextHotelId($currentScope, $accessibleHotelIds);
        }
        $router = $this->routerEnvelope('clarification', 'clarification', $knownScope + [
            'scope_applicable' => false,
        ], [$reason]);
        $answer = [
            'contract_version' => self::RECORD_CONTRACT_VERSION,
            'mode' => 'deterministic_clarification',
            'status' => 'clarification_required',
            'summary' => $question,
            'query_router' => $router,
            'precise_result' => [
                'kind' => 'clarification',
                'clarifying_question' => $question,
                'reason' => $reason,
                'known_scope' => $knownScope,
            ],
            'data_gaps' => [['code' => $reason, 'message' => $question]],
            'boundaries' => $this->boundaries(),
        ];
        return $this->persistDirect(
            $tenantId,
            $storageHotelId,
            $userId,
            $query,
            '',
            null,
            $answer,
            [],
            [],
            $accessibleHotelIds
        );
    }

    /**
     * @param array<string,mixed> $answer
     * @param list<string> $factRefs
     * @param list<string> $knowledgeRefs
     * @param list<int> $accessibleHotelIds
     * @return array<string,mixed>
     */
    private function persistDirect(
        int $tenantId,
        int $hotelId,
        int $userId,
        string $query,
        string $platform,
        ?string $businessDate,
        array $answer,
        array $factRefs,
        array $knowledgeRefs,
        array $accessibleHotelIds
    ): array {
        if ($hotelId > 0) {
            $hotelTenantId = $this->hotelTenantId($hotelId);
            if ($hotelTenantId <= 0) {
                throw new RuntimeException('精准查数保存酒店缺少租户归属');
            }
            $tenantId = $hotelTenantId;
        }
        $storageDate = $businessDate ?: $this->now()->format('Y-m-d');
        $factRefs = $this->stringList($factRefs);
        $knowledgeRefs = $this->stringList($knowledgeRefs);
        $digest = $this->digest([
            'question' => $query,
            'answer' => $answer,
            'fact_refs' => $factRefs,
            'memory_refs' => [],
            'knowledge_refs' => $knowledgeRefs,
            'execution_refs' => [],
        ]);
        $requestKey = 'precise-query:v1:' . substr($this->digest([
            $tenantId,
            $hotelId,
            $query,
            $answer['query_router'] ?? [],
            $digest,
        ]), 0, 48);
        $existing = Db::name(OperatingQuestionService::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('request_key', $requestKey)
            ->whereNull('deleted_at')
            ->find();
        if (is_array($existing)) {
            return $this->read((int)$existing['id'], $tenantId, $accessibleHotelIds);
        }
        $now = $this->now()->format('Y-m-d H:i:s');
        try {
            $id = (int)Db::name(OperatingQuestionService::TABLE)->insertGetId([
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'request_key' => $requestKey,
                'question_text' => $query,
                'platform' => $platform,
                'date_start' => $storageDate,
                'date_end' => $storageDate,
                'answer_status' => (string)($answer['status'] ?? 'clarification_required'),
                'answer_summary' => (string)($answer['summary'] ?? ''),
                'answer_json' => $this->encode($answer),
                'fact_refs_json' => $this->encode($factRefs),
                'memory_refs_json' => $this->encode([]),
                'knowledge_refs_json' => $this->encode($knowledgeRefs),
                'execution_refs_json' => $this->encode([]),
                'data_gaps_json' => $this->encode((array)($answer['data_gaps'] ?? [])),
                'content_digest' => $digest,
                'created_by' => max(0, $userId),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $error) {
            if (!$this->isDuplicateRequestConflict($error)) {
                throw $error;
            }
            $concurrent = Db::name(OperatingQuestionService::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('request_key', $requestKey)
                ->whereNull('deleted_at')
                ->find();
            if (!is_array($concurrent)) {
                throw $error;
            }
            if (!hash_equals($digest, (string)($concurrent['content_digest'] ?? ''))) {
                throw new RuntimeException('精准查数幂等键已用于不同内容', 409, $error);
            }
            return $this->read((int)$concurrent['id'], $tenantId, $accessibleHotelIds);
        }
        if ($id <= 0) {
            throw new RuntimeException('精准查数问题保存失败');
        }
        return $this->read($id, $tenantId, $accessibleHotelIds);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $answer
     * @param list<string> $factRefs
     * @param list<string> $knowledgeRefs
     * @return array<string,mixed>
     */
    private function unifiedReadback(array $row, array $answer, array $factRefs, array $knowledgeRefs): array
    {
        $router = (array)$answer['query_router'];
        $routeType = (string)($router['route_type'] ?? '');
        $routeAnswer = $answer['precise_result'] ?? $answer['system_guidance'] ?? [];
        $question = [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'question_text' => (string)$row['question_text'],
            'platform' => (string)$row['platform'],
            'date_start' => (string)$row['date_start'],
            'date_end' => (string)$row['date_end'],
            'answer_status' => (string)$row['answer_status'],
            'answer_summary' => (string)$row['answer_summary'],
            'answer' => $answer,
            'fact_refs' => $factRefs,
            'knowledge_refs' => $knowledgeRefs,
            'data_gaps' => (array)($answer['data_gaps'] ?? []),
            'content_digest' => (string)$row['content_digest'],
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
        $question['persistence_status'] = 'readback_verified';
        $question['analysis_quality_receipt'] = (new HotelDataAnalystQualityReceiptService())->evaluate($question);
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'id' => (int)$row['id'],
            'question' => (string)$row['question_text'],
            'route_type' => $routeType,
            'intent_key' => (string)($router['intent_key'] ?? ''),
            'status' => (string)($answer['status'] ?? $row['answer_status']),
            'answer_summary' => (string)($answer['summary'] ?? $row['answer_summary']),
            'parsed_scope' => (array)($router['parsed_scope'] ?? []),
            'answer' => is_array($routeAnswer) ? $routeAnswer : [],
            'operating_question' => $routeType === 'operating_query' ? $question : null,
            'analysis_quality_receipt' => $routeType === 'operating_query' ? $question['analysis_quality_receipt'] : null,
            'fact_refs' => $factRefs,
            'knowledge_refs' => $knowledgeRefs,
            'data_gaps' => (array)($answer['data_gaps'] ?? []),
            'lexicon' => (array)($router['lexicon'] ?? PreciseQueryLexicon::metadata()),
            'content_digest' => (string)$row['content_digest'],
            'persistence_status' => 'readback_verified',
            'created_at' => (string)($row['created_at'] ?? ''),
            'boundaries' => (array)($answer['boundaries'] ?? $this->boundaries()),
        ];
    }

    /** @param array<string,mixed> $scope @param list<string> $reasons @return array<string,mixed> */
    private function routerEnvelope(string $routeType, string $intentKey, array $scope, array $reasons): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'route_type' => $routeType,
            'intent_key' => $intentKey,
            'classification_method' => 'deterministic_runtime_lexicon_and_grammar',
            'classification_reasons' => array_values(array_unique(array_filter(array_map('strval', $reasons)))),
            'parsed_scope' => $scope,
            'lexicon' => PreciseQueryLexicon::metadata(),
            'number_generation_policy' => 'database_or_deterministic_calculation_only',
            'model_number_generation_allowed' => false,
        ];
    }

    /** @return array<string,bool|string> */
    private function boundaries(): array
    {
        return [
            'external_llm_called' => false,
            'llm_attempted' => false,
            'llm_client_invoked' => false,
            'ota_write' => false,
            'external_message' => false,
            'automatic_execution' => false,
            'knowledge_is_business_fact' => false,
        ];
    }

    /** @param array<string,mixed> $scope */
    private function dataScopeText(array $scope): string
    {
        $dateStart = (string)($scope['date_start'] ?? $scope['business_date'] ?? '');
        $dateEnd = (string)($scope['date_end'] ?? $dateStart);
        if ($dateStart !== '' && $dateEnd !== '' && $dateStart !== $dateEnd) {
            return sprintf(
                'OTA渠道；Hotel %d；%s；业务日期 %s 至 %s；逐日严格回读来源记录，未做期间汇总',
                (int)($scope['hotel_id'] ?? 0),
                $this->platformLabel((string)($scope['platform'] ?? '')),
                $dateStart,
                $dateEnd
            );
        }
        return sprintf(
            'OTA渠道；Hotel %d；%s；业务日期 %s；单日最新严格回读来源记录',
            (int)($scope['hotel_id'] ?? 0),
            $this->platformLabel((string)($scope['platform'] ?? '')),
            $dateStart
        );
    }

    private function platformLabel(string $platform): string
    {
        return match ($platform) {
            'ctrip' => '携程',
            'meituan' => '美团',
            'all_ota' => '携程与美团',
            default => $platform !== '' ? $platform : '未锁定平台',
        };
    }

    private function isComparisonQuestion(string $query): bool
    {
        return preg_match(
            '/哪个平台.*(?:好|高|强)|(?:携程和美团|携程美团).*(?:哪个|谁).*(?:好|高|强)|(?:比较|对比).*(?:携程|美团)/u',
            $query
        ) === 1;
    }

    private function isExposureEstimationQuestion(string $query): bool
    {
        return preg_match(
            '/(?:曝光(?:人数)?).*(?:估算|反推|倒推|漏抓)|(?:估算|反推|倒推|漏抓).*(?:曝光(?:人数)?)/u',
            $query
        ) === 1;
    }

    private function extractTerm(string $query): string
    {
        $term = preg_split(
            '/(?:是什么意思|什么意思|是什么|指什么|是酒店指标吗|是指标吗|属于酒店指标吗|解释一下|定义是什么)/u',
            $query,
            2
        )[0] ?? $query;
        $term = trim((string)preg_replace('/^[“”"\'《》【】\s]+|[“”"\'《》【】？?。！!\s]+$/u', '', $term));
        return mb_substr($term !== '' ? $term : $query, 0, 120);
    }

    /** @param array<string,mixed> $currentScope @param list<int> $accessibleHotelIds */
    private function contextHotelId(array $currentScope, array $accessibleHotelIds): int
    {
        $id = max(0, (int)($currentScope['hotel_id'] ?? 0));
        return $id > 0 && in_array($id, $accessibleHotelIds, true) ? $id : 0;
    }

    private function hotelName(int $hotelId): string
    {
        try {
            $name = trim((string)Db::name('hotels')->where('id', $hotelId)->value('name'));
            return $name !== '' ? $name : 'Hotel ' . $hotelId;
        } catch (\Throwable) {
            return 'Hotel ' . $hotelId;
        }
    }

    private function isDuplicateRequestConflict(\Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());
            if (str_contains($message, 'duplicate entry')
                || str_contains($message, 'unique constraint failed')
            ) {
                return true;
            }
        }
        return false;
    }

    private function hotelTenantId(int $hotelId): int
    {
        return max(0, (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id'));
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeException('精准查数时钟返回值无效');
        }
        return $now->setTimezone(new DateTimeZone('Asia/Shanghai'));
    }

    private function number(float $value, int $digits = 0): string
    {
        if ($digits <= 0 || abs($value - round($value)) < 0.0000001) {
            return number_format($value, 0, '.', ',');
        }
        return rtrim(rtrim(number_format($value, $digits, '.', ','), '0'), '.');
    }

    /** @param list<mixed> $items @return list<string> */
    private function stringList(array $items): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => trim((string)$item),
            $items
        ), static fn(string $item): bool => $item !== '')));
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
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
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
