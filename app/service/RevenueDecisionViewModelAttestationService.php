<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Proves that the persisted revenue cockpit model is the presentation of the
 * currently authorized strict facts, not an arbitrary client-authored report.
 *
 * The browser owns layout only. Every persisted field, including presentation
 * text and CSS classes, is recalculated here and compared before a snapshot can
 * be appended.
 */
final class RevenueDecisionViewModelAttestationService
{
    private const CONTRACT_VERSION = 'revenue_daily_cockpit.v2';

    /** @var list<string> */
    private const SECTION_KEYS = [
        'data_completeness',
        'core_metrics',
        'traffic_conversion',
        'comparable_change',
        'anomaly_reasons',
        'opportunity_ranking',
        'data_gaps',
        'suggested_actions',
    ];

    /** @var array<string,array{title:string,subtitle:string}> */
    private const SECTION_META = [
        'data_completeness' => [
            'title' => '1. 数据是否完整',
            'subtitle' => '按来源独立判断；部分数据、读取失败和未验证不会显示成正常。',
        ],
        'core_metrics' => [
            'title' => '2. 核心收入与订单指标',
            'subtitle' => 'PMS 与各 OTA 独立展示；不同来源或单位禁止静默合并。',
        ],
        'traffic_conversion' => [
            'title' => '3. 渠道流量和转化',
            'subtitle' => '只形成所选 OTA 渠道结论，不扩大为全酒店流量事实。',
        ],
        'comparable_change' => [
            'title' => '4. 同口径变化',
            'subtitle' => '',
        ],
        'anomaly_reasons' => [
            'title' => '5. 异常原因',
            'subtitle' => '只陈述已验证字段能支持的异常或阻断，不把缺失推断为正常。',
        ],
        'opportunity_ranking' => [
            'title' => '6. 八类经营机会排序',
            'subtitle' => '有同口径信号的机会按透明规则排序；证据缺口进入补证队列且不伪造机会分。',
        ],
        'data_gaps' => [
            'title' => '7. 数据缺口',
            'subtitle' => '逐项保留缺失、未验证、读取失败和无法比较状态。',
        ],
        'suggested_actions' => [
            'title' => '8. 其他核查动作',
            'subtitle' => '建议只读，必须由用户主动选择后才能进入待审批流程。',
        ],
    ];

    /** @var list<string> */
    private const TOP_LEVEL_KEYS = [
        'contractVersion',
        'status',
        'statusLabel',
        'statusClass',
        'headline',
        'summary',
        'dateNotice',
        'scopeBoundary',
        'selectedPlatform',
        'selectedPlatformLabel',
        'businessDate',
        'previousDate',
        'sameWeekdayDate',
        'dateDistance',
        'tenantId',
        'hotelId',
        'hotelName',
        'sections',
        'visibleSections',
        'opportunities',
        'comparisonFrames',
        'anomalyChains',
        'sourceRecords',
        'metricDefinitions',
        'missingItems',
        'evidenceSummary',
        'canAskQuestion',
        'canCreatePendingApproval',
        'canSaveSnapshot',
        'actionDisabledReason',
    ];

    /** @var array<string,array{title:string,business_order:int,possible_cause:string,action:string}> */
    private const OPPORTUNITIES = [
        'traffic_entry_shortage' => [
            'title' => '流量进入不足',
            'business_order' => 1,
            'possible_cause' => '可能与平台需求、搜索排名、投放、可售库存或活动曝光有关；当前只把它列为核查方向。',
            'action' => '核对同平台曝光来源、排名、投放、活动和可售库存，确认事实后再决定是否进入运营执行。',
        ],
        'detail_conversion_shortage' => [
            'title' => '详情页转化不足',
            'business_order' => 2,
            'possible_cause' => '可能与首图、卖点、价格权益匹配或流量意图有关；当前变化不能证明任何单一原因。',
            'action' => '按同平台、同日期核对列表到详情路径、首图卖点和价格权益，先补齐可解释证据。',
        ],
        'submit_payment_conversion_shortage' => [
            'title' => '提交 / 支付转化不足',
            'business_order' => 3,
            'possible_cause' => '可能与房态、价格权益、支付前阻断或用户意图有关；提交下降不能替代支付事实。',
            'action' => '分别核对详情到提交、提交到支付的分子分母及失败节点；缺少支付事实时不得把问题归到支付。',
        ],
        'cancellation_anomaly' => [
            'title' => '取消异常',
            'business_order' => 4,
            'possible_cause' => '可能与价格变化、取消政策、客群结构或履约体验有关；当前仅为同口径变化信号。',
            'action' => '核对取消订单基数、取消原因、政策、客群与价格变化，确认是否需要人工干预。',
        ],
        'price_competition_position' => [
            'title' => '价格竞争位置',
            'business_order' => 5,
            'possible_cause' => '没有同房型、同权益、同取消政策和同日期的竞价事实时，价格位置未知。',
            'action' => '补齐同平台、同房型、同权益、同取消政策和同入住日的本店与竞对价格样本后再判断。',
        ],
        'bookability_gap' => [
            'title' => '可订性缺口',
            'business_order' => 6,
            'possible_cause' => '后台保存成功不等于游客侧可订；缺少搜索、详情和预订前检查时保持未知。',
            'action' => '以同平台、同入住日、同住客条件完成游客侧搜索、详情和预订前检查，并保存断点证据。',
        ],
        'service_promise_risk' => [
            'title' => '服务承诺风险',
            'business_order' => 7,
            'possible_cause' => '只有承诺事实、履约事实、影响订单与单位损失都可信时才计算风险；否则只列补证。',
            'action' => '核对平台承诺、实际履约、影响订单和损失口径，缺少任一事实时不计算金额。',
        ],
        'promotion_incrementality_evidence' => [
            'title' => '促销增量证据不足',
            'business_order' => 8,
            'possible_cause' => '平台归因不等于促销增量；没有活动阶段、对照、前趋势和样本门槛时不能形成因果结论。',
            'action' => '补齐同活动阶段、对照组、前趋势、样本量和来源质量，再评估促销增量；当前不宣称因果。',
        ],
    ];

    /** @var array<string,list<array{0:string,1:string,2:string}>> */
    private const CORE_DEFINITIONS = [
        'dingdandao_pms' => [
            ['room_revenue', '全酒店住宿房费', 'CNY'],
            ['sold_room_nights', '全酒店已售间夜', 'room_nights'],
            ['occupancy_rate_percent', '全酒店入住率', 'percent'],
            ['adr', '全酒店住宿 ADR', 'CNY'],
            ['revpar', '全酒店住宿 RevPAR', 'CNY'],
        ],
        'ctrip_ota' => [
            ['revenue', '携程渠道订单金额', 'CNY'],
            ['orders', '携程渠道订单', 'orders'],
            ['room_nights', '携程渠道间夜', 'room_nights'],
            ['adr', '携程订单金额 / 间夜', 'CNY'],
        ],
        'meituan_ota' => [
            ['revenue', '美团渠道订单金额', 'CNY'],
            ['orders', '美团渠道订单', 'orders'],
            ['room_nights', '美团渠道间夜', 'room_nights'],
            ['adr', '美团订单金额 / 间夜', 'CNY'],
        ],
    ];

    /** @var list<array{0:string,1:string,2:string}> */
    private const TRAFFIC_DEFINITIONS = [
        ['list_exposure', '列表曝光', 'exposures'],
        ['detail_exposure', '详情曝光', 'exposures'],
        ['flow_rate_percent', '进店转化率', 'percent'],
        ['submit_rate_percent', '提交转化率', 'percent'],
        ['payment_conversion_percent', '支付转化率', 'percent'],
        ['cancellation_rate_percent', '取消率', 'percent'],
    ];

    /**
     * @param array<string,mixed> $model
     * @param array<string,mixed> $overview
     * @param array<string,mixed> $comparisons
     * @param array<string,mixed> $evidenceContext
     * @return array<string,mixed>
     */
    public function attest(
        array $model,
        array $overview,
        array $comparisons,
        array $evidenceContext,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform
    ): array {
        $previousDate = (string)($comparisons['previous_date'] ?? '');
        $sameWeekdayDate = (string)($comparisons['same_weekday_date'] ?? '');
        $previousOverview = is_array($comparisons['previous_overview'] ?? null)
            ? $comparisons['previous_overview']
            : [];
        $sameWeekdayOverview = is_array($comparisons['same_weekday_overview'] ?? null)
            ? $comparisons['same_weekday_overview']
            : [];
        $this->assertIdentity(
            $model,
            $overview,
            $tenantId,
            $hotelId,
            $businessDate,
            $platform,
            $previousDate,
            $sameWeekdayDate
        );
        $this->assertNoForbiddenClaims($model);
        $this->assertOnlyKeys($model, self::TOP_LEVEL_KEYS, 'top');

        $sections = $model['visibleSections'];
        if ($this->canonicalJson((array)($model['sections'] ?? [])) !== $this->canonicalJson($sections)) {
            $this->invalid('sections_visible_sections_drift');
        }
        $sectionMap = [];
        foreach ($sections as $index => $section) {
            $sectionKey = self::SECTION_KEYS[$index];
            if (!is_array($section)
                || (string)($section['key'] ?? '') !== $sectionKey
                || !is_array($section['cards'] ?? null)
                || !array_is_list($section['cards'])
            ) {
                $this->invalid('section_contract');
            }
            $this->assertOnlyKeys(
                $section,
                ['key', 'title', 'subtitle', 'cards'],
                'section:' . (string)$section['key']
            );
            $sectionMeta = self::SECTION_META[$sectionKey];
            if ($sectionKey === 'comparable_change') {
                $sectionMeta['subtitle'] = '当前 ' . $businessDate
                    . ' 分别核对前一可比营业日、同星期和同活动阶段；覆盖不一致会单独提示。';
            }
            $this->assertFields($section, [
                'key' => $sectionKey,
                'title' => $sectionMeta['title'],
                'subtitle' => $sectionMeta['subtitle'],
            ], 'section:' . $sectionKey);
            $sectionMap[$sectionKey] = $section['cards'];
        }

        $trustedCurrentPms = $this->hasTrustedPmsRef($evidenceContext);
        $current = $this->factCards($overview, $businessDate, $platform, $trustedCurrentPms);
        $previous = $this->factCards(
            $previousOverview,
            $previousDate,
            $platform,
            $this->trustedPmsSource($previousOverview, $previousDate)
        );
        $sameWeekday = $this->factCards(
            $sameWeekdayOverview,
            $sameWeekdayDate,
            $platform,
            $this->trustedPmsSource($sameWeekdayOverview, $sameWeekdayDate)
        );

        $actualSources = $this->assertCardList(
            $sectionMap['data_completeness'],
            $current['sources'],
            'source'
        );
        $actualCore = $this->assertCardList(
            $sectionMap['core_metrics'],
            $current['core'],
            'core_metric'
        );
        $actualTraffic = $this->assertCardList(
            $sectionMap['traffic_conversion'],
            $current['traffic'],
            'traffic_metric'
        );

        $comparisonCards = $this->comparisonCards(
            $current,
            $previous,
            $sameWeekday,
            $businessDate,
            $previousDate,
            $sameWeekdayDate,
            $platform
        );
        $actualComparisons = $this->assertCardList(
            $sectionMap['comparable_change'],
            $comparisonCards,
            'comparison'
        );

        $opportunities = $this->opportunities(
            $current,
            $previous,
            $sameWeekday,
            $businessDate,
            $previousDate,
            $sameWeekdayDate,
            $platform
        );
        $actualOpportunities = $this->assertCardList(
            $sectionMap['opportunity_ranking'],
            $opportunities,
            'opportunity'
        );
        if ($this->canonicalJson((array)($model['opportunities'] ?? []))
            !== $this->canonicalJson($actualOpportunities)
        ) {
            $this->invalid('opportunity_projection_drift');
        }

        $rawGaps = $this->rawGaps($overview);
        $anomalies = $this->anomalyCards($rawGaps, $current, $businessDate, $platform);
        $actualAnomalies = $this->assertCardList($sectionMap['anomaly_reasons'], $anomalies, 'anomaly');

        $factCardsByKey = [];
        foreach (array_merge($actualSources, $actualCore, $actualTraffic) as $card) {
            $factCardsByKey[(string)$card['key']] = $card;
        }
        $gaps = $this->gapCards($factCardsByKey, $rawGaps, $businessDate);
        $actualGaps = $this->assertCardList($sectionMap['data_gaps'], $gaps, 'gap');

        $actions = $this->actionCards($rawGaps, $businessDate);
        $actualActions = $this->assertCardList($sectionMap['suggested_actions'], $actions, 'action');

        $sourceRecords = $this->sourceRecords($overview, $current, $businessDate, $platform);
        $this->assertCanonicalEqual(
            (array)($model['sourceRecords'] ?? []),
            $sourceRecords,
            'source_records'
        );
        $metricDefinitions = $this->clientMetricDefinitions();
        $this->assertCanonicalEqual(
            (array)($model['metricDefinitions'] ?? []),
            $metricDefinitions,
            'metric_definitions'
        );

        $coverageCards = array_values(array_filter(
            $comparisonCards,
            static fn(array $card): bool => str_starts_with((string)$card['key'], 'coverage:')
        ));
        $comparisonFrames = $this->comparisonFrames(
            $businessDate,
            $previousDate,
            $sameWeekdayDate,
            $coverageCards
        );
        $this->assertCanonicalEqual(
            (array)($model['comparisonFrames'] ?? []),
            $comparisonFrames,
            'comparison_frames'
        );
        $anomalyChains = array_map(static fn(array $card): array => [
            'anomalyId' => 'anomaly-chain:' . (string)$card['opportunityKey'],
            'opportunityKey' => (string)$card['opportunityKey'],
            'factChange' => (string)$card['factChange'],
            'possibleCause' => (string)$card['possibleCause'],
            'evidenceSupport' => (string)$card['evidenceSupport'],
            'missingEvidence' => (array)$card['missingEvidence'],
            'recommendedCheckAction' => (string)$card['recommendedCheckAction'],
            'interpretationKind' => (string)$card['interpretationKind'],
            'relationshipType' => (string)$card['relationshipType'],
            'correlationStatus' => (string)$card['correlationStatus'],
            'causalityClaimed' => false,
        ], $opportunities);
        $this->assertCanonicalEqual(
            (array)($model['anomalyChains'] ?? []),
            $anomalyChains,
            'anomaly_chains'
        );

        $missingItems = [];
        foreach ($sections as $section) {
            foreach ($section['cards'] as $card) {
                if (in_array((string)($card['status'] ?? ''), [
                    'readback_verified', 'derived_verified', 'verified', 'ready', 'ok', 'no_signal',
                ], true)) {
                    continue;
                }
                $reasonCode = (string)($card['reasonCode'] ?? '');
                $sourceKey = (string)($card['sourceKey'] ?? '');
                $missingItems[] = [
                    'sectionKey' => (string)$section['key'],
                    'cardKey' => (string)($card['key'] ?? ''),
                    'label' => (string)($card['label'] ?? ''),
                    'status' => (string)($card['status'] ?? ''),
                    'reasonCode' => $reasonCode !== '' ? $reasonCode : 'unknown',
                    'sourceKey' => $sourceKey !== '' ? $sourceKey : 'cockpit_rule',
                ];
            }
        }
        $this->assertCanonicalEqual(
            (array)($model['missingItems'] ?? []),
            $missingItems,
            'missing_items'
        );

        $requiredSourcesReady = $this->requiredOtaSourcesReady($current, $platform);
        $completeSourceCount = count(array_filter(
            $current['sources'],
            static fn(array $card): bool => (string)$card['status'] === 'readback_verified'
        ));
        $missingFactCount = count(array_filter(
            array_merge($current['sources'], $current['core'], $current['traffic']),
            static fn(array $card): bool => !in_array((string)$card['missingState'], ['有值', '完整'], true)
        ));
        $status = $completeSourceCount === count($current['sources']) && $missingFactCount === 0
            ? 'ready'
            : 'partial';
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $dateDistance = $this->dateDistance($businessDate, $today);
        $oldDateNotice = $dateDistance === null
            ? '与今天的差异待确认。'
            : ($dateDistance === 0
                ? '业务日就是今天。'
                : ($dateDistance > 0
                    ? '业务日比今天早 ' . $dateDistance . ' 天，页面展示的是最新严格可用历史事实。'
                    : '业务日晚于今天 ' . abs($dateDistance) . ' 天，请复核日期。'));
        $scopeNotice = trim((string)($comparisons['scope_notice'] ?? ''));
        $expectedTop = [
            'status' => $status,
            'statusLabel' => $status === 'ready' ? '数据完整可读' : '部分数据可读',
            'statusClass' => $this->statusClass($status),
            'headline' => $status === 'ready'
                ? '当前经营状态已有完整可追溯视图'
                : '当前经营状态可读，但仍有明确数据缺口',
            'summary' => $completeSourceCount . '/' . count($current['sources']) . ' 个当前来源完成严格回读；缺失项保持为空。',
            'dateNotice' => $scopeNotice . ($scopeNotice !== '' ? ' ' : '') . $oldDateNotice,
            'scopeBoundary' => 'PMS 只形成全酒店住宿事实；携程/美团订单金额只形成各自 OTA 渠道结论；不同来源收入不相加，订单金额也不相加，order_amount 不冒充已核验房费收入。',
            'selectedPlatformLabel' => (string)($comparisons['selected_platform_label'] ?? $this->platformLabel($platform)),
            'hotelName' => (string)($overview['three_source_fact_layer']['hotel']['name'] ?? ''),
            'canAskQuestion' => $hotelId > 0 && $businessDate !== '',
            'canCreatePendingApproval' => $requiredSourcesReady,
            'canSaveSnapshot' => $requiredSourcesReady && $tenantId > 0 && $hotelId > 0,
            'actionDisabledReason' => $requiredSourcesReady
                ? ''
                : '所选 OTA 范围尚未同时返回可追溯记录ID和严格回读状态，不能生成待审批行动。',
        ];
        $this->assertFields($model, $expectedTop, 'top_semantics');
        $this->assertFields($model, [
            'dateDistance' => $dateDistance,
        ], 'date_distance');
        $evidenceSummary = [
            'strictGate' => (string)($overview['cockpit_strict_evidence']['strict_gate']
                ?? 'history_success+validation_verified+readback_verified'),
            'sourceRecordCount' => count($sourceRecords),
            'opportunityCount' => count($opportunities),
            'wholeHotelConclusionAllowed' => count(array_filter(
                $sourceRecords,
                static fn(array $record): bool => (string)$record['factScope'] === 'whole_hotel_accommodation'
            )) > 0,
            'otaPlatformsSeparate' => true,
            'pageDownloadSharedViewModel' => true,
            'causalityClaimed' => false,
        ];
        $this->assertCanonicalEqual(
            (array)($model['evidenceSummary'] ?? []),
            $evidenceSummary,
            'evidence_summary'
        );

        $authoritativeSections = [];
        $cardsBySection = [
            'data_completeness' => $actualSources,
            'core_metrics' => $actualCore,
            'traffic_conversion' => $actualTraffic,
            'comparable_change' => $actualComparisons,
            'anomaly_reasons' => $actualAnomalies,
            'opportunity_ranking' => $actualOpportunities,
            'data_gaps' => $actualGaps,
            'suggested_actions' => $actualActions,
        ];
        foreach (self::SECTION_KEYS as $sectionKey) {
            $sectionMeta = self::SECTION_META[$sectionKey];
            if ($sectionKey === 'comparable_change') {
                $sectionMeta['subtitle'] = '当前 ' . $businessDate
                    . ' 分别核对前一可比营业日、同星期和同活动阶段；覆盖不一致会单独提示。';
            }
            $authoritativeSections[] = [
                'key' => $sectionKey,
                'title' => $sectionMeta['title'],
                'subtitle' => $sectionMeta['subtitle'],
                'cards' => $cardsBySection[$sectionKey],
            ];
        }
        $authoritativeModel = [
            'contractVersion' => self::CONTRACT_VERSION,
            'status' => $expectedTop['status'],
            'statusLabel' => $expectedTop['statusLabel'],
            'statusClass' => $expectedTop['statusClass'],
            'headline' => $expectedTop['headline'],
            'summary' => $expectedTop['summary'],
            'dateNotice' => $expectedTop['dateNotice'],
            'scopeBoundary' => $expectedTop['scopeBoundary'],
            'selectedPlatform' => $platform,
            'selectedPlatformLabel' => $expectedTop['selectedPlatformLabel'],
            'businessDate' => $businessDate,
            'previousDate' => $previousDate,
            'sameWeekdayDate' => $sameWeekdayDate,
            'dateDistance' => $dateDistance,
            'tenantId' => $tenantId,
            'hotelId' => $hotelId,
            'hotelName' => $expectedTop['hotelName'],
            'sections' => $authoritativeSections,
            'visibleSections' => $authoritativeSections,
            'opportunities' => $actualOpportunities,
            'comparisonFrames' => $comparisonFrames,
            'anomalyChains' => $anomalyChains,
            'sourceRecords' => $sourceRecords,
            'metricDefinitions' => $metricDefinitions,
            'missingItems' => $missingItems,
            'evidenceSummary' => $evidenceSummary,
            'canAskQuestion' => $expectedTop['canAskQuestion'],
            'canCreatePendingApproval' => $expectedTop['canCreatePendingApproval'],
            'canSaveSnapshot' => $expectedTop['canSaveSnapshot'],
            'actionDisabledReason' => $expectedTop['actionDisabledReason'],
        ];
        $this->assertCanonicalEqual($model, $authoritativeModel, 'authoritative_model');

        return $this->canonicalize($authoritativeModel);
    }

    /** @param array<string,mixed> $model @param array<string,mixed> $overview */
    private function assertIdentity(
        array $model,
        array $overview,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform,
        string $previousDate,
        string $sameWeekdayDate
    ): void {
        $factLayer = is_array($overview['three_source_fact_layer'] ?? null)
            ? $overview['three_source_fact_layer']
            : [];
        $hotel = is_array($factLayer['hotel'] ?? null) ? $factLayer['hotel'] : [];
        $strict = is_array($overview['cockpit_strict_evidence'] ?? null)
            ? $overview['cockpit_strict_evidence']
            : [];
        if ((string)($model['contractVersion'] ?? '') !== self::CONTRACT_VERSION
            || (int)($model['tenantId'] ?? 0) !== $tenantId
            || (int)($model['hotelId'] ?? 0) !== $hotelId
            || (string)($model['businessDate'] ?? '') !== $businessDate
            || (string)($model['selectedPlatform'] ?? '') !== $platform
            || (string)($model['previousDate'] ?? '') !== $previousDate
            || (string)($model['sameWeekdayDate'] ?? '') !== $sameWeekdayDate
            || (int)($hotel['tenant_id'] ?? 0) !== $tenantId
            || (int)($hotel['system_hotel_id'] ?? 0) !== $hotelId
            || (string)($factLayer['business_date'] ?? '') !== $businessDate
            || (string)($strict['contract_version'] ?? '') !== 'revenue_cockpit_strict_evidence.v1'
            || (int)($strict['tenant_id'] ?? 0) !== $tenantId
            || (int)($strict['hotel_id'] ?? 0) !== $hotelId
            || (string)($strict['business_date'] ?? '') !== $businessDate
            || !is_array($model['visibleSections'] ?? null)
            || !array_is_list($model['visibleSections'])
            || count($model['visibleSections']) !== count(self::SECTION_KEYS)
            || !is_array($model['opportunities'] ?? null)
            || !array_is_list($model['opportunities'])
        ) {
            $this->invalid('identity');
        }
    }

    /** @param array<string,mixed> $overview @return array<string,mixed> */
    private function factCards(
        array $overview,
        string $businessDate,
        string $platform,
        bool $trustedPms
    ): array {
        $selectedPlatforms = $this->selectedPlatforms($platform);
        $sourceKeys = array_merge(
            ['dingdandao_pms'],
            array_map(static fn(string $item): string => $item . '_ota', $selectedPlatforms)
        );
        $factLayer = is_array($overview['three_source_fact_layer'] ?? null)
            ? $overview['three_source_fact_layer']
            : [];
        $sources = is_array($factLayer['sources'] ?? null) ? $factLayer['sources'] : [];
        $strictPlatforms = is_array($overview['cockpit_strict_evidence']['platforms'] ?? null)
            ? $overview['cockpit_strict_evidence']['platforms']
            : [];
        $sourceCards = [];
        $sourceReady = [];
        foreach ($sourceKeys as $sourceKey) {
            $source = is_array($sources[$sourceKey] ?? null) ? $sources[$sourceKey] : [];
            $otaPlatform = str_ends_with($sourceKey, '_ota')
                ? substr($sourceKey, 0, -4)
                : '';
            $strict = $otaPlatform !== '' && is_array($strictPlatforms[$otaPlatform] ?? null)
                ? $strictPlatforms[$otaPlatform]
                : [];
            $strictReady = $otaPlatform !== ''
                ? (($strict['source_strict_readback'] ?? false) === true
                    && $this->positiveIds($strict['accepted_row_ids'] ?? []) !== [])
                : $trustedPms;
            $status = (string)($source['data_status'] ?? 'missing');
            $ready = $status === 'readback_verified' && $strictReady;
            $displayStatus = $status === 'readback_verified' && !$strictReady
                ? 'not_verified'
                : $status;
            $meta = $this->sourceMeta($sourceKey);
            $sourceCard = [
                'key' => 'source:' . $sourceKey,
                'kind' => 'source',
                'label' => $meta['label'],
                'display' => $this->statusText($displayStatus),
                'value' => null,
                'unit' => 'status',
                'unitLabel' => '来源状态',
                'sourceKey' => $sourceKey,
                'sourceLabel' => $meta['label'],
                'businessDate' => (string)($source['business_date'] ?? $businessDate),
                'status' => $displayStatus,
                'statusLabel' => $this->statusText($displayStatus),
                'statusClass' => $this->statusClass($displayStatus),
                'scope' => $meta['scope'],
                'scopeLabel' => $meta['scope_label'],
                'missingState' => $ready ? '完整' : '不完整',
                'reasonCode' => $ready ? '' : ($status === 'readback_verified'
                    ? 'cockpit_strict_source_readback_missing'
                    : $sourceKey . '_not_readback_verified'),
                'reasonText' => $ready
                    ? '来源身份、业务日期、保存记录和严格回读均已通过。'
                    : ($status === 'readback_verified'
                        ? '来源总览虽标记已回读，但承载当前指标的保存行未通过驾驶舱严格事实闸门。'
                        : $this->reasonText($sourceKey . '_not_readback_verified')),
                'evidenceLines' => $this->sourceEvidence($source, $sourceKey, $businessDate, $strict),
            ];
            $sourceCards[] = $sourceCard;
            $sourceReady[$sourceKey] = $ready;
        }

        $core = [];
        $traffic = [];
        $metrics = [];
        foreach ($sourceKeys as $sourceKey) {
            foreach (self::CORE_DEFINITIONS[$sourceKey] ?? [] as [$metricKey, $label, $unit]) {
                $card = $this->metricCard(
                    is_array($sources[$sourceKey] ?? null) ? $sources[$sourceKey] : [],
                    $sourceKey,
                    $metricKey,
                    $label,
                    $unit,
                    $businessDate,
                    $strictPlatforms,
                    $trustedPms
                );
                $core[] = $card;
                $metrics[(string)$card['key']] = $card;
            }
        }
        foreach ($selectedPlatforms as $otaPlatform) {
            $sourceKey = $otaPlatform . '_ota';
            foreach (self::TRAFFIC_DEFINITIONS as [$metricKey, $baseLabel, $unit]) {
                $card = $this->metricCard(
                    is_array($sources[$sourceKey] ?? null) ? $sources[$sourceKey] : [],
                    $sourceKey,
                    $metricKey,
                    $this->platformLabel($otaPlatform) . $baseLabel,
                    $unit,
                    $businessDate,
                    $strictPlatforms,
                    $trustedPms
                );
                $traffic[] = $card;
                $metrics[(string)$card['key']] = $card;
            }
        }
        return [
            'sources' => $sourceCards,
            'core' => $core,
            'traffic' => $traffic,
            'metrics' => $metrics,
            'source_ready' => $sourceReady,
        ];
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $strictPlatforms @return array<string,mixed> */
    private function metricCard(
        array $source,
        string $sourceKey,
        string $metricKey,
        string $label,
        string $unit,
        string $businessDate,
        array $strictPlatforms,
        bool $trustedPms
    ): array {
        $facts = is_array($source['facts'] ?? null) ? $source['facts'] : [];
        $statuses = is_array($source['fact_statuses'] ?? null) ? $source['fact_statuses'] : [];
        $factStatus = is_array($statuses[$metricKey] ?? null) ? $statuses[$metricKey] : [];
        $status = (string)($factStatus['status'] ?? '');
        $raw = $this->number($facts[$metricKey] ?? null);
        $canonicalReady = in_array($status, ['readback_verified', 'derived_verified', 'verified'], true)
            && $raw !== null;
        $otaPlatform = str_ends_with($sourceKey, '_ota') ? substr($sourceKey, 0, -4) : '';
        $strict = $otaPlatform !== '' && is_array($strictPlatforms[$otaPlatform] ?? null)
            ? $strictPlatforms[$otaPlatform]
            : [];
        $strictMetric = is_array($strict['metrics'][$metricKey] ?? null)
            ? $strict['metrics'][$metricKey]
            : [];
        $strictReady = $otaPlatform !== ''
            ? (($strictMetric['strict_readback'] ?? false) === true)
            : $trustedPms;
        $ready = $canonicalReady && $strictReady;
        $strictMismatch = $canonicalReady && !$strictReady;
        $displayStatus = $ready
            ? ($status !== '' ? $status : 'verified')
            : ($strictMismatch ? 'not_verified' : ($factStatus !== [] ? $status : 'missing'));
        $reasonCode = $ready
            ? ''
            : ($strictMismatch
                ? 'cockpit_strict_metric_readback_missing'
                : (string)($factStatus['reason'] ?? ($sourceKey . '_' . $metricKey . '_missing')));
        $meta = $this->sourceMeta($sourceKey);
        $evidenceLines = $this->sourceEvidence($source, $sourceKey, $businessDate, $strict, $metricKey);
        $evidenceLines[] = '指标状态：' . $this->statusText($displayStatus)
            . ($reasonCode !== '' ? ' · ' . $reasonCode : '');
        if (trim((string)($factStatus['formula'] ?? '')) !== '') {
            $evidenceLines[] = '公式：' . (string)$factStatus['formula'];
        }
        if (trim((string)($factStatus['caliber'] ?? '')) !== '') {
            $evidenceLines[] = '口径说明：' . (string)$factStatus['caliber'];
        }
        $card = [
            'key' => $sourceKey . ':' . $metricKey,
            'kind' => 'metric',
            'label' => $label,
            'display' => $ready ? $this->displayValue($raw, $unit) : '—',
            'value' => $ready ? $raw : null,
            'unit' => $unit,
            'unitLabel' => $this->unitLabel($unit),
            'sourceKey' => $sourceKey,
            'sourceLabel' => $meta['label'],
            'businessDate' => (string)($source['business_date'] ?? $businessDate),
            'status' => $displayStatus,
            'statusLabel' => $this->statusText($displayStatus),
            'statusClass' => $this->statusClass($displayStatus),
            'scope' => $meta['scope'],
            'scopeLabel' => $meta['scope_label'],
            'missingState' => $ready ? '有值' : '缺失或未验证',
            'reasonCode' => $reasonCode,
            'reasonText' => $ready
                ? '该指标命中同酒店、同来源、同业务日的验证状态。'
                : ($strictMismatch
                    ? '指标来源行未通过 history_status=success、validation_status=verified、readback_verified=1 的驾驶舱严格事实闸门，数值保持为空。'
                    : $this->reasonText($reasonCode)),
            'evidenceLines' => $evidenceLines,
        ];
        return $card;
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $previous @param array<string,mixed> $sameWeekday @return list<array<string,mixed>> */
    private function comparisonCards(
        array $current,
        array $previous,
        array $sameWeekday,
        string $businessDate,
        string $previousDate,
        string $sameWeekdayDate,
        string $platform
    ): array {
        $cards = [];
        foreach ($current['metrics'] as $key => $card) {
            $cards[] = $this->comparisonCard(
                $card,
                $previous['metrics'][$key] ?? null,
                $previousDate,
                'previous_comparable',
                '前一可比营业日'
            );
        }
        foreach ($current['metrics'] as $key => $card) {
            $cards[] = $this->comparisonCard(
                $card,
                $sameWeekday['metrics'][$key] ?? null,
                $sameWeekdayDate,
                'same_weekday',
                '同星期'
            );
        }
        foreach ($this->selectedPlatforms($platform) as $otaPlatform) {
            $sourceKey = $otaPlatform . '_ota';
            $currentCoverage = $this->coverage($current['metrics'], $sourceKey);
            foreach ([
                ['previous_comparable', '前一可比营业日', $previousDate, $previous['metrics']],
                ['same_weekday', '同星期', $sameWeekdayDate, $sameWeekday['metrics']],
            ] as [$basis, $label, $date, $metrics]) {
                $baselineCoverage = $this->coverage($metrics, $sourceKey);
                $sameCoverage = $date !== ''
                    && $currentCoverage['ready'] === $baselineCoverage['ready']
                    && $currentCoverage['ready_keys'] === $baselineCoverage['ready_keys'];
                $cards[] = $this->textCard(
                    'coverage:' . $basis . ':' . $otaPlatform,
                    'comparison',
                    $this->platformLabel($otaPlatform) . ' · ' . $label . '覆盖差异',
                    $date !== ''
                        ? '当前 ' . $currentCoverage['ready'] . '/' . $currentCoverage['total']
                            . ' 项；基期 ' . $baselineCoverage['ready'] . '/' . $baselineCoverage['total']
                            . ' 项' . ($sameCoverage ? '，覆盖一致' : '，覆盖不一致')
                        : '—',
                    $sourceKey,
                    $businessDate,
                    $date !== '' ? ($sameCoverage ? 'verified' : 'partial') : 'not_calculable',
                    $date !== '' && !$sameCoverage
                        ? 'comparison_coverage_mismatch'
                        : ($date !== '' ? '' : $basis . '_missing'),
                    $date !== ''
                        ? ($sameCoverage
                            ? '当前期与基期可用指标集合一致。'
                            : '当前期与基期可用指标集合不同；变化值仍展示，但不得直接解释为经营变化。')
                        : '没有' . $label . '严格回读日期，比较保持为空。',
                    [
                        '当前可用指标：' . ($currentCoverage['ready_keys'] !== []
                            ? implode('、', $currentCoverage['ready_keys']) : '无'),
                        '基期可用指标：' . ($baselineCoverage['ready_keys'] !== []
                            ? implode('、', $baselineCoverage['ready_keys']) : '无'),
                        '覆盖规则：指标集合不一致必须提示，禁止把缺字段造成的变化当作经营变化。',
                    ]
                );
            }
        }
        $cards[] = $this->textCard(
            'comparison:campaign_stage',
            'comparison',
            '同活动阶段比较',
            '—',
            'cockpit_rule',
            $businessDate,
            'not_calculable',
            'campaign_stage_identity_missing',
            '当前严格 OTA 日事实未携带活动 ID、阶段和阶段日期窗，不能静默选择其他日期作为同活动阶段。',
            [
                '当前业务日：' . $businessDate,
                '尚缺证据：活动 ID、活动阶段、阶段起止日及基期来源记录。',
                '边界：缺少活动阶段身份时比较为未知，不形成促销增量或因果结论。',
            ]
        );
        return $cards;
    }

    /** @param array<string,mixed> $current @param array<string,mixed>|null $baseline @return array<string,mixed> */
    private function comparisonCard(
        array $current,
        ?array $baseline,
        string $baselineDate,
        string $basis,
        string $basisLabel
    ): array {
        $comparable = $current['value'] !== null
            && is_array($baseline)
            && ($baseline['value'] ?? null) !== null
            && (string)$current['sourceKey'] === (string)$baseline['sourceKey']
            && (string)$current['unit'] === (string)$baseline['unit'];
        $delta = $comparable
            ? round((float)$current['value'] - (float)$baseline['value'], 2)
            : null;
        $ratio = $comparable && (float)$baseline['value'] !== 0.0
            ? round(((float)$delta / abs((float)$baseline['value'])) * 100, 2)
            : null;
        $display = !$comparable
            ? '—'
            : (($delta > 0 ? '+' : '') . $this->displayValue($delta, (string)$current['unit'])
                . ($ratio === null
                    ? '（基期为 0）'
                    : '（' . ($ratio > 0 ? '+' : '') . number_format($ratio, 2, '.', '') . '%）'));
        $status = $comparable ? 'verified' : 'not_calculable';
        return [
            'key' => ($basis === 'previous_comparable' ? 'compare' : 'compare-' . $basis)
                . ':' . (string)$current['key'],
            'kind' => 'comparison',
            'label' => (string)$current['label'] . ' · ' . $basisLabel . ' ' . ($baselineDate !== '' ? $baselineDate : '缺失'),
            'display' => $display,
            'value' => $delta,
            'unit' => (string)$current['unit'],
            'unitLabel' => (string)$current['unitLabel'],
            'sourceKey' => (string)$current['sourceKey'],
            'sourceLabel' => (string)$current['sourceLabel'],
            'businessDate' => (string)$current['businessDate'],
            'status' => $status,
            'statusLabel' => $comparable ? '同来源同单位可比' : '不可同口径比较',
            'statusClass' => $this->statusClass($status),
            'scope' => (string)$current['scope'],
            'scopeLabel' => (string)$current['scopeLabel'],
            'missingState' => $comparable ? '有值' : '缺少同口径基期',
            'reasonCode' => $comparable ? '' : 'same_source_comparison_missing',
            'reasonText' => $comparable
                ? '只比较 ' . (string)$current['sourceLabel'] . ' 的同酒店、同平台、同一指标与同一单位，没有跨来源合并。'
                : '当前值或' . $basisLabel . '的同来源、同单位指标缺失，变化保持为空。',
            'comparisonBasis' => $basis,
            'baselineDate' => $baselineDate,
            'formula' => $comparable ? 'current_value - baseline_value' : '',
            'causalityClaimed' => false,
            'evidenceLines' => [
                '当前：' . ((string)$current['businessDate'] !== '' ? (string)$current['businessDate'] : '日期待确认')
                    . ' · ' . ((string)$current['display'] !== '' ? (string)$current['display'] : '—')
                    . ' · ' . ((string)$current['statusLabel'] !== '' ? (string)$current['statusLabel'] : '状态待确认'),
                '基期：' . ($baselineDate !== '' ? $baselineDate : '无')
                    . ' · ' . (is_array($baseline) && trim((string)($baseline['display'] ?? '')) !== ''
                        ? (string)$baseline['display'] : '—')
                    . ' · ' . (is_array($baseline) && trim((string)($baseline['statusLabel'] ?? '')) !== ''
                        ? (string)$baseline['statusLabel'] : '状态待确认'),
                '比较基准：' . $basisLabel,
                '比较规则：同酒店、同平台、同来源、同指标、同单位；禁止跨来源或跨单位静默合并。',
                '证据分类：当前值与基期值为平台事实；差值和百分比为公式计算；不形成因果结论。',
            ],
        ];
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $previous @param array<string,mixed> $sameWeekday @return list<array<string,mixed>> */
    private function opportunities(
        array $current,
        array $previous,
        array $sameWeekday,
        string $businessDate,
        string $previousDate,
        string $sameWeekdayDate,
        string $platform
    ): array {
        $cards = [];
        foreach (self::OPPORTUNITIES as $key => $definition) {
            $signals = [];
            foreach ($this->selectedPlatforms($platform) as $otaPlatform) {
                $signals[] = $this->opportunitySignal(
                    $key,
                    $definition,
                    $otaPlatform,
                    $current,
                    $previous,
                    $sameWeekday,
                    $businessDate,
                    $previousDate,
                    $sameWeekdayDate
                );
            }
            $actionable = array_values(array_filter(
                $signals,
                static fn(array $signal): bool => (string)$signal['state'] === 'actionable'
            ));
            usort($actionable, static fn(array $left, array $right): int =>
                (float)($right['priorityScore'] ?? 0) <=> (float)($left['priorityScore'] ?? 0));
            $investigations = array_values(array_filter(
                $signals,
                static fn(array $signal): bool => (string)$signal['state'] === 'evidence_investigation'
            ));
            $blocked = array_values(array_filter(
                $signals,
                static fn(array $signal): bool => (string)$signal['state'] === 'blocked'
            ));
            $state = $actionable !== []
                ? 'actionable'
                : ($investigations !== []
                    ? 'evidence_investigation'
                    : ($blocked !== [] ? 'blocked' : 'no_signal'));
            $primary = $actionable[0] ?? $investigations[0] ?? $blocked[0] ?? $signals[0] ?? [];
            $score = $state === 'actionable' ? (float)$primary['priorityScore'] : null;
            $reasonCodes = $this->unique(array_merge(...array_map(
                static fn(array $signal): array => (array)$signal['reasonCodes'],
                $signals
            )));
            $missingEvidence = $this->unique(array_merge(...array_map(
                static fn(array $signal): array => (array)$signal['missingEvidence'],
                $signals
            )));
            $platformSummary = implode('；', array_map(
                fn(array $signal): string => $this->platformLabel((string)$signal['platform'])
                    . '：' . (string)$signal['display'],
                $signals
            ));
            $evidenceLines = [];
            foreach ($signals as $signal) {
                $signalLabel = $this->platformLabel((string)$signal['platform']);
                $evidenceLines[] = $signalLabel . '事实变化：' . (string)$signal['factChange'];
                $evidenceLines[] = $signalLabel . '证据支持：' . (string)$signal['evidenceSupport'];
                $evidenceLines[] = $signalLabel . '尚缺证据：'
                    . ((array)$signal['missingEvidence'] !== []
                        ? implode('、', (array)$signal['missingEvidence']) : '无');
            }
            $evidenceLines[] = '证据分类：平台事实 → 公式计算 → 模型解释；相关性未检验；因果结论未声明；缺失保持未知。';
            $evidenceLines[] = '核查动作：' . $definition['action'];
            $cards[] = [
                'key' => 'opportunity:' . $key,
                'kind' => 'opportunity',
                'opportunityKey' => $key,
                'title' => $definition['title'],
                'label' => $definition['title'],
                'display' => $platformSummary !== '' ? $platformSummary : '缺少平台范围',
                'value' => $score,
                'unit' => 'priority_score',
                'unitLabel' => '透明机会优先分',
                'sourceKey' => 'cockpit_rule',
                'sourceLabel' => '可信收益机会规则',
                'businessDate' => $businessDate,
                'status' => $state,
                'statusLabel' => $this->statusText($state),
                'statusClass' => $this->statusClass($state),
                'state' => $state,
                'scope' => 'selected_ota_platforms_only',
                'scopeLabel' => '所选 OTA 平台独立判断',
                'missingState' => in_array($state, ['actionable', 'no_signal'], true)
                    ? '有同口径事实'
                    : '缺少必要证据',
                'reasonCode' => implode('|', $reasonCodes),
                'reasonText' => (string)($primary['possibleCause'] ?? $definition['possible_cause']),
                'businessOrder' => $definition['business_order'],
                'priorityScore' => $score,
                'priorityBand' => $score === null
                    ? ($state === 'no_signal' ? 'monitor' : 'evidence_first')
                    : ($score >= 80 ? 'high' : 'medium'),
                'evidenceLevel' => (string)($primary['evidenceLevel'] ?? 'missing_required_fact'),
                'platformSignals' => $signals,
                'factChange' => (string)($primary['factChange'] ?? '未知'),
                'possibleCause' => (string)($primary['possibleCause'] ?? $definition['possible_cause']),
                'evidenceSupport' => (string)($primary['evidenceSupport'] ?? '证据不足'),
                'missingEvidence' => $missingEvidence,
                'recommendedCheckAction' => $definition['action'],
                'formula' => (string)($primary['formula'] ?? ''),
                'interpretationKind' => 'model_explanation',
                'relationshipType' => (string)($primary['relationshipType'] ?? 'not_established'),
                'correlationStatus' => 'not_tested',
                'causalityClaimed' => false,
                'causalityStatus' => 'not_claimed',
                'unknownStatePreserved' => !in_array($state, ['actionable', 'no_signal'], true),
                'canCreatePendingApproval' => in_array($state, ['actionable', 'evidence_investigation'], true)
                    && count(array_filter(
                        $signals,
                        static fn(array $signal): bool => (string)$signal['evidenceLevel'] !== 'missing_required_fact'
                    )) > 0,
                'evidenceLines' => $evidenceLines,
            ];
        }
        $stateOrder = [
            'actionable' => 0,
            'evidence_investigation' => 1,
            'no_signal' => 2,
            'blocked' => 3,
            'unknown' => 4,
        ];
        usort($cards, static function (array $left, array $right) use ($stateOrder): int {
            $state = ($stateOrder[(string)$left['state']] ?? 9)
                <=> ($stateOrder[(string)$right['state']] ?? 9);
            if ($state !== 0) {
                return $state;
            }
            if ((string)$left['state'] === 'actionable') {
                $score = (float)($right['priorityScore'] ?? 0) <=> (float)($left['priorityScore'] ?? 0);
                if ($score !== 0) {
                    return $score;
                }
            }
            $order = (int)$left['businessOrder'] <=> (int)$right['businessOrder'];
            return $order !== 0 ? $order : strcmp((string)$left['opportunityKey'], (string)$right['opportunityKey']);
        });
        foreach ($cards as $index => &$card) {
            $rank = $index + 1;
            $card['rank'] = $rank;
            $card['display'] = '第 ' . $rank . ' 位 · ' . (string)$card['display'];
        }
        unset($card);
        return $cards;
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $current @param array<string,mixed> $previous @param array<string,mixed> $sameWeekday @return array<string,mixed> */
    private function opportunitySignal(
        string $key,
        array $definition,
        string $platform,
        array $current,
        array $previous,
        array $sameWeekday,
        string $businessDate,
        string $previousDate,
        string $sameWeekdayDate
    ): array {
        $sourceKey = $platform . '_ota';
        $sourceReady = ($current['source_ready'][$sourceKey] ?? false) === true;
        $compare = fn(string $metric): array => $this->opportunityBaseline(
            $sourceKey . ':' . $metric,
            $current,
            $previous,
            $sameWeekday,
            $previousDate,
            $sameWeekdayDate
        );
        $state = 'evidence_investigation';
        $score = null;
        $factChange = '缺少形成该判断所需的同口径事实或基期。';
        $evidenceSupport = $sourceReady
            ? '当前平台来源已通过严格回读，但该机会仍缺必要指标或比较条件。'
            : '当前平台来源未通过严格回读，不能形成经营机会判断。';
        $missing = [];
        $reasonCodes = [];
        $formula = '';
        $baselineBasis = 'missing';
        $baselineDate = '';
        $display = '待补证';
        $platformLabel = $this->platformLabel($platform);

        if ($key === 'traffic_entry_shortage') {
            $signal = $compare('list_exposure');
            $baselineBasis = $signal['basis'];
            $baselineDate = $signal['baseline_date'];
            $formula = 'delta_percent = (current_list_exposure - baseline_list_exposure) / abs(baseline_list_exposure) * 100';
            if ($signal['comparable'] && $signal['delta_percent'] !== null) {
                $actionable = $signal['delta_percent'] <= -15;
                $state = $actionable ? 'actionable' : 'no_signal';
                $score = $actionable ? min(100, round(60 + abs($signal['delta_percent']), 2)) : null;
                $factChange = $platformLabel . '列表曝光较' . $signal['basis_label'] . ' '
                    . ($signal['delta_percent'] > 0 ? '+' : '') . number_format($signal['delta_percent'], 2, '.', '') . '%。';
                $evidenceSupport = '当前 ' . $signal['current']['display'] . '；基期 '
                    . $signal['baseline']['display'] . '；阈值为下降 15% 或以上。';
                $display = $actionable ? '曝光下降达到核查阈值' : '未命中曝光下降阈值';
            } else {
                $reasonCodes[] = 'list_exposure_same_caliber_baseline_missing';
                $missing[] = '当前与基期列表曝光';
            }
        } elseif ($key === 'detail_conversion_shortage') {
            $signal = $compare('flow_rate_percent');
            $baselineBasis = $signal['basis'];
            $baselineDate = $signal['baseline_date'];
            $formula = 'delta_pp = current_flow_rate_percent - baseline_flow_rate_percent';
            if ($signal['comparable']) {
                $actionable = $signal['delta'] <= -2;
                $state = $actionable ? 'actionable' : 'no_signal';
                $score = $actionable ? min(100, round(62 + abs($signal['delta']) * 6, 2)) : null;
                $factChange = $platformLabel . '列表到详情转化较' . $signal['basis_label'] . ' '
                    . ($signal['delta'] > 0 ? '+' : '') . number_format($signal['delta'], 2, '.', '') . ' 个百分点。';
                $evidenceSupport = '当前 ' . $signal['current']['display'] . '；基期 '
                    . $signal['baseline']['display'] . '；阈值为下降 2 个百分点或以上。';
                $display = $actionable ? '详情转化下降达到核查阈值' : '未命中详情转化下降阈值';
            } else {
                $reasonCodes[] = 'flow_rate_same_caliber_baseline_missing';
                $missing[] = '当前与基期列表到详情转化率';
            }
        } elseif ($key === 'submit_payment_conversion_shortage') {
            $submit = $compare('submit_rate_percent');
            $payment = $compare('payment_conversion_percent');
            $baselineBasis = $submit['comparable'] ? $submit['basis'] : $payment['basis'];
            $baselineDate = $submit['comparable'] ? $submit['baseline_date'] : $payment['baseline_date'];
            $formula = 'submit_delta_pp = current_submit_rate - baseline_submit_rate; payment must be assessed separately';
            if ($submit['comparable']) {
                $actionable = $submit['delta'] <= -1;
                $state = $actionable ? 'actionable' : ($payment['comparable'] ? 'no_signal' : 'evidence_investigation');
                $score = $actionable ? min(100, round(64 + abs($submit['delta']) * 7, 2)) : null;
                $factChange = $platformLabel . '提交转化较' . $submit['basis_label'] . ' '
                    . ($submit['delta'] > 0 ? '+' : '') . number_format($submit['delta'], 2, '.', '')
                    . ' 个百分点；支付转化' . ($payment['comparable'] ? '已有独立基期' : '仍缺独立事实') . '。';
                $evidenceSupport = '提交当前 ' . $submit['current']['display'] . '；提交基期 '
                    . $submit['baseline']['display'] . '。';
                if (!$payment['comparable']) {
                    $missing[] = '提交到支付的分子、分母和同口径基期';
                    $reasonCodes[] = 'payment_conversion_missing';
                }
                $display = $actionable
                    ? '提交转化下降达到核查阈值'
                    : ($payment['comparable'] ? '未命中转化下降阈值' : '支付转化待补证');
            } else {
                $reasonCodes[] = 'submit_conversion_same_caliber_baseline_missing';
                $missing[] = '当前与基期提交转化率';
                $missing[] = '提交到支付的分子、分母和同口径基期';
            }
        } elseif ($key === 'cancellation_anomaly') {
            $signal = $compare('cancellation_rate_percent');
            $baselineBasis = $signal['basis'];
            $baselineDate = $signal['baseline_date'];
            $formula = 'delta_pp = current_cancellation_rate_percent - baseline_cancellation_rate_percent';
            if ($signal['comparable']) {
                $actionable = $signal['delta'] >= 3;
                $state = $actionable ? 'actionable' : 'no_signal';
                $score = $actionable ? min(100, round(66 + $signal['delta'] * 6, 2)) : null;
                $factChange = $platformLabel . '取消率较' . $signal['basis_label'] . ' '
                    . ($signal['delta'] > 0 ? '+' : '') . number_format($signal['delta'], 2, '.', '') . ' 个百分点。';
                $evidenceSupport = '当前 ' . $signal['current']['display'] . '；基期 '
                    . $signal['baseline']['display'] . '；阈值为上升 3 个百分点或以上。';
                $display = $actionable ? '取消率上升达到核查阈值' : '未命中取消异常阈值';
            } else {
                $reasonCodes[] = 'cancellation_same_caliber_baseline_missing';
                $missing[] = '当前与基期取消率及毛订单基数';
            }
        } elseif ($key === 'price_competition_position') {
            $reasonCodes[] = 'comparable_competitor_price_missing';
            $missing[] = '同房型、同权益、同取消政策、同入住日的本店与竞对价格';
        } elseif ($key === 'bookability_gap') {
            $reasonCodes[] = 'guest_side_bookability_path_missing';
            $missing[] = '同住客条件的搜索、详情和预订前检查证据';
        } elseif ($key === 'service_promise_risk') {
            $reasonCodes[] = 'service_promise_effect_facts_missing';
            $missing[] = '平台承诺、履约事实、影响订单和单位损失';
        } elseif ($key === 'promotion_incrementality_evidence') {
            $reasonCodes[] = 'promotion_causal_design_missing';
            $missing[] = '同活动阶段、对照组、前趋势、样本量和来源质量';
            $factChange = '当前平台事实只能描述活动期表现，不能证明促销造成了增量。';
            $evidenceSupport = $sourceReady
                ? '平台事实已严格回读；因果识别所需设计证据未提供。'
                : '平台事实与因果识别证据均未达到门槛。';
        }
        if (!$sourceReady) {
            $state = 'blocked';
            $score = null;
            $reasonCodes[] = 'strict_platform_source_not_ready';
            array_unshift($missing, '同酒店、同平台、同营业日的严格回读来源');
        }
        if ($missing === [] && $state === 'no_signal') {
            $missing[] = '原因证据未检验；“未命中阈值”不等于经营一定正常';
        }
        return [
            'platform' => $platform,
            'businessDate' => $businessDate,
            'state' => $state,
            'priorityScore' => $score,
            'priorityBand' => $score === null
                ? ($state === 'no_signal' ? 'monitor' : 'evidence_first')
                : ($score >= 80 ? 'high' : 'medium'),
            'evidenceLevel' => $sourceReady
                ? (in_array($state, ['actionable', 'no_signal'], true)
                    ? 'strict_fact_formula'
                    : 'strict_fact_partial')
                : 'missing_required_fact',
            'display' => $display,
            'factChange' => $factChange,
            'possibleCause' => $definition['possible_cause'],
            'evidenceSupport' => $evidenceSupport,
            'missingEvidence' => $missing,
            'recommendedCheckAction' => $definition['action'],
            'reasonCodes' => $this->unique($reasonCodes),
            'formula' => $formula,
            'baselineBasis' => $baselineBasis,
            'baselineDate' => $baselineDate,
            'relationshipType' => $state === 'actionable' ? 'same_caliber_change_signal' : 'not_established',
            'correlationStatus' => 'not_tested',
            'causalityClaimed' => false,
            'causalityStatus' => 'not_claimed',
            'externalActionAllowed' => false,
        ];
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $previous @param array<string,mixed> $sameWeekday @return array<string,mixed> */
    private function opportunityBaseline(
        string $metricKey,
        array $current,
        array $previous,
        array $sameWeekday,
        string $previousDate,
        string $sameWeekdayDate
    ): array {
        $currentCard = $current['metrics'][$metricKey] ?? null;
        $candidates = [
            [$sameWeekday['metrics'][$metricKey] ?? null, 'same_weekday', '同星期', $sameWeekdayDate],
            [$previous['metrics'][$metricKey] ?? null, 'previous_comparable', '前一可比营业日', $previousDate],
        ];
        $baseline = $candidates[0];
        foreach ($candidates as $candidate) {
            if (is_array($currentCard)
                && ($currentCard['value'] ?? null) !== null
                && is_array($candidate[0])
                && ($candidate[0]['value'] ?? null) !== null
            ) {
                $baseline = $candidate;
                break;
            }
        }
        $baselineCard = is_array($baseline[0]) ? $baseline[0] : null;
        $comparable = is_array($currentCard)
            && ($currentCard['value'] ?? null) !== null
            && is_array($baselineCard)
            && ($baselineCard['value'] ?? null) !== null;
        $delta = $comparable
            ? round((float)$currentCard['value'] - (float)$baselineCard['value'], 4)
            : null;
        $deltaPercent = $comparable && (float)$baselineCard['value'] !== 0.0
            ? round(((float)$delta / abs((float)$baselineCard['value'])) * 100, 2)
            : null;
        return [
            'current' => $currentCard,
            'baseline' => $baselineCard,
            'basis' => (string)$baseline[1],
            'basis_label' => (string)$baseline[2],
            'baseline_date' => (string)$baseline[3],
            'comparable' => $comparable,
            'delta' => $delta,
            'delta_percent' => $deltaPercent,
        ];
    }

    /** @param list<array<string,mixed>> $rawGaps @param array<string,mixed> $current @return list<array<string,mixed>> */
    private function anomalyCards(array $rawGaps, array $current, string $businessDate, string $platform): array
    {
        $cards = [];
        foreach ($rawGaps as $index => $gap) {
            $code = (string)($gap['code'] ?? ('fact_gap_' . ($index + 1)));
            $sourceKey = (string)($gap['source'] ?? 'cockpit_rule');
            $display = trim((string)($gap['display_reason'] ?? $gap['message'] ?? ''));
            $nextAction = trim((string)($gap['next_action'] ?? ''));
            $cards[] = $this->textCard(
                'anomaly:' . $code . ':' . (string)($gap['source'] ?? $index),
                'anomaly',
                '事实异常 / 阻断',
                $display !== '' ? $display : $this->reasonText($code),
                $sourceKey,
                $businessDate,
                (string)($gap['status'] ?? 'partial'),
                $code,
                $nextAction !== '' ? $nextAction : $this->reasonText($code),
                [
                    '异常代码：' . $code,
                    '来源：' . (string)($gap['source'] ?? '三源事实层'),
                    '业务日期：' . $businessDate,
                    '处理建议：' . ($nextAction !== '' ? $nextAction : '补齐同店同日事实并重新回读。'),
                ]
            );
        }
        foreach ($this->selectedPlatforms($platform) as $otaPlatform) {
            $prefix = $otaPlatform . '_ota:';
            $revenue = $current['metrics'][$prefix . 'revenue']['value'] ?? null;
            $orders = $current['metrics'][$prefix . 'orders']['value'] ?? null;
            $nights = $current['metrics'][$prefix . 'room_nights']['value'] ?? null;
            if ($revenue !== null && (float)$revenue > 0 && $orders !== null && (float)$orders === 0.0) {
                $cards[] = $this->textCard(
                    'anomaly:' . $otaPlatform . ':revenue_positive_orders_zero',
                    'anomaly',
                    $this->platformLabel($otaPlatform) . '收入与订单矛盾',
                    $this->reasonText('revenue_positive_orders_zero'),
                    $otaPlatform . '_ota',
                    $businessDate,
                    'partial',
                    'revenue_positive_orders_zero'
                );
            }
            if ($revenue !== null && (float)$revenue > 0 && $nights !== null && (float)$nights === 0.0) {
                $cards[] = $this->textCard(
                    'anomaly:' . $otaPlatform . ':revenue_positive_room_nights_zero',
                    'anomaly',
                    $this->platformLabel($otaPlatform) . '收入与间夜矛盾',
                    $this->reasonText('revenue_positive_room_nights_zero'),
                    $otaPlatform . '_ota',
                    $businessDate,
                    'partial',
                    'revenue_positive_room_nights_zero'
                );
            }
        }
        if ($cards === []) {
            $cards[] = $this->textCard(
                'anomaly:none_verified',
                'anomaly',
                '异常判断',
                '当前已验证事实未命中可判定异常',
                'cockpit_rule',
                $businessDate,
                'verified',
                '',
                '这不代表经营一定正常，只代表当前已回读字段未命中确定性异常规则。'
            );
        }
        return $cards;
    }

    /** @param array<string,array<string,mixed>> $facts @param list<array<string,mixed>> $rawGaps @return list<array<string,mixed>> */
    private function gapCards(array $facts, array $rawGaps, string $businessDate): array
    {
        $cards = [];
        $seen = [];
        foreach ($facts as $card) {
            if (in_array((string)($card['missingState'] ?? ''), ['有值', '完整'], true)) {
                continue;
            }
            $key = 'gap:' . (string)$card['key'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $cards[] = $this->textCard(
                $key,
                'gap',
                (string)$card['label'] . '缺口',
                trim((string)($card['reasonText'] ?? '')) !== ''
                    ? (string)$card['reasonText'] : '该卡片缺少同店同日严格回读事实。',
                (string)$card['sourceKey'],
                $businessDate,
                (string)$card['status'],
                (string)$card['reasonCode'],
                '补齐相同酒店、来源与业务日的保存记录并完成精确回读；不使用 0、旧日或其他来源代替。',
                (array)($card['evidenceLines'] ?? [])
            );
        }
        foreach ($rawGaps as $index => $gap) {
            $code = (string)($gap['code'] ?? ('fact_gap_' . ($index + 1)));
            $key = 'gap:fact-layer:' . $code . ':' . (string)($gap['source'] ?? $index);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $display = trim((string)($gap['display_reason'] ?? $gap['message'] ?? ''));
            $nextAction = trim((string)($gap['next_action'] ?? ''));
            $cards[] = $this->textCard(
                $key,
                'gap',
                (string)($gap['category'] ?? '三源事实缺口'),
                $display !== '' ? $display : $this->reasonText($code),
                (string)($gap['source'] ?? 'cockpit_rule'),
                $businessDate,
                (string)($gap['status'] ?? 'partial'),
                $code,
                $nextAction !== '' ? $nextAction : '补齐同店同日来源并完成严格回读。'
            );
        }
        if ($cards === []) {
            $cards[] = $this->textCard(
                'gap:none',
                'gap',
                '数据缺口',
                '当前可见卡片未发现缺失或未验证字段',
                'cockpit_rule',
                $businessDate,
                'verified',
                '',
                '仅代表当前筛选范围和可见字段，不扩大为其他平台或全酒店完整性结论。'
            );
        }
        return $cards;
    }

    /** @param list<array<string,mixed>> $rawGaps @return list<array<string,mixed>> */
    private function actionCards(array $rawGaps, string $businessDate): array
    {
        $cards = [];
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $distance = $this->dateDistance($businessDate, $today);
        if ($distance !== null && $distance > 0) {
            $cards[] = $this->textCard(
                'action:refresh-current-date',
                'action',
                '优先补齐今天的数据',
                '当前最近严格可用日为 ' . $businessDate . '，比今天早 ' . $distance
                    . ' 天；先复核今天是否已采集、保存并严格回读。',
                'cockpit_rule',
                $businessDate,
                'partial',
                '',
                '入口只提示补数，不自动启动采集。'
            );
        }
        foreach (array_slice($rawGaps, 0, 4) as $index => $gap) {
            $code = (string)($gap['code'] ?? ('fact_gap_' . ($index + 1)));
            $cards[] = $this->textCard(
                'action:' . $code . ':' . $index,
                'action',
                '处理 ' . (string)($gap['category'] ?? $gap['source'] ?? '数据缺口'),
                (string)($gap['next_action'] ?? '补齐对应来源并完成同店同日保存回读。'),
                (string)($gap['source'] ?? 'cockpit_rule'),
                $businessDate,
                'partial',
                $code,
                '建议动作必须经过人工复核；本页不会自动采集、审批或执行。'
            );
        }
        if ($cards === []) {
            $cards[] = $this->textCard(
                'action:daily-review',
                'action',
                '完成当日人工复核',
                '核对收入、订单、流量转化与变化后，再决定是否生成待审批行动。',
                'cockpit_rule',
                $businessDate,
                'verified',
                '',
                '建议只读，不自动写 OTA 或创建执行任务。'
            );
        }
        return $cards;
    }

    /** @param list<string> $evidenceLines @return array<string,mixed> */
    private function textCard(
        string $key,
        string $kind,
        string $label,
        string $display,
        string $sourceKey = 'cockpit_rule',
        string $businessDate = '',
        string $status = 'partial',
        string $reasonCode = '',
        string $reasonText = '',
        array $evidenceLines = []
    ): array {
        $meta = $this->sourceMeta($sourceKey);
        $resolvedDisplay = $display !== '' ? $display : '—';
        return [
            'key' => $key,
            'kind' => $kind,
            'label' => $label,
            'display' => $resolvedDisplay,
            'value' => null,
            'unit' => 'text',
            'unitLabel' => '说明',
            'sourceKey' => $sourceKey,
            'sourceLabel' => $meta['label'],
            'businessDate' => $businessDate,
            'status' => $status,
            'statusLabel' => $this->statusText($status),
            'statusClass' => $this->statusClass($status),
            'scope' => $meta['scope'],
            'scopeLabel' => $meta['scope_label'],
            'missingState' => $kind === 'gap' ? '数据缺口' : '说明',
            'reasonCode' => $reasonCode,
            'reasonText' => $reasonText !== '' ? $reasonText : $resolvedDisplay,
            'evidenceLines' => $evidenceLines !== [] ? array_values($evidenceLines) : [
                '来源：' . $meta['label'],
                '业务日期：' . ($businessDate !== '' ? $businessDate : '待确认'),
                '边界：只生成解释或待审批入口，不自动执行。',
            ],
        ];
    }

    /** @param array<string,mixed> $overview @param array<string,mixed> $current @return list<array<string,mixed>> */
    private function sourceRecords(array $overview, array $current, string $businessDate, string $platform): array
    {
        $strict = is_array($overview['cockpit_strict_evidence']['platforms'] ?? null)
            ? $overview['cockpit_strict_evidence']['platforms']
            : [];
        $records = [];
        foreach ($this->selectedPlatforms($platform) as $otaPlatform) {
            $ready = ($current['source_ready'][$otaPlatform . '_ota'] ?? false) === true;
            $records[] = [
                'sourceKey' => $otaPlatform . '_ota',
                'table' => 'online_daily_data',
                'platform' => $otaPlatform,
                'businessDate' => $businessDate,
                'rowIds' => $this->positiveIds($strict[$otaPlatform]['accepted_row_ids'] ?? []),
                'readbackStatus' => $ready ? 'readback_verified' : 'not_verified',
                'factScope' => 'ota_channel',
            ];
        }
        $sources = is_array($overview['three_source_fact_layer']['sources'] ?? null)
            ? $overview['three_source_fact_layer']['sources']
            : [];
        $pms = is_array($sources['dingdandao_pms'] ?? null) ? $sources['dingdandao_pms'] : [];
        $recordId = (int)($pms['source']['record_id'] ?? 0);
        $pmsCard = array_values(array_filter(
            $current['sources'],
            static fn(array $card): bool => (string)$card['sourceKey'] === 'dingdandao_pms'
        ))[0] ?? [];
        if ((string)($pmsCard['status'] ?? '') === 'readback_verified' && $recordId > 0) {
            $records[] = [
                'sourceKey' => 'dingdandao_pms',
                'table' => 'dingdandao_operating_target_captures',
                'platform' => 'dingdandao_pms',
                'businessDate' => $businessDate,
                'rowIds' => [$recordId],
                'readbackStatus' => 'readback_verified',
                'factScope' => 'whole_hotel_accommodation',
            ];
        }
        return $records;
    }

    /** @param list<array<string,mixed>> $coverageCards @return list<array<string,mixed>> */
    private function comparisonFrames(
        string $businessDate,
        string $previousDate,
        string $sameWeekdayDate,
        array $coverageCards
    ): array {
        $warning = static function (array $cards, string $basis): bool {
            foreach ($cards as $card) {
                if (str_contains((string)$card['key'], $basis)
                    && (string)$card['status'] === 'partial'
                ) {
                    return true;
                }
            }
            return false;
        };
        return [
            [
                'key' => 'previous_comparable',
                'label' => '前一可比营业日',
                'currentDate' => $businessDate,
                'baselineDate' => $previousDate,
                'status' => $previousDate !== '' ? 'available' : 'missing',
                'sameHotel' => true,
                'samePlatform' => true,
                'coverageWarning' => $warning($coverageCards, 'previous_comparable'),
            ],
            [
                'key' => 'same_weekday',
                'label' => '同星期',
                'currentDate' => $businessDate,
                'baselineDate' => $sameWeekdayDate,
                'status' => $sameWeekdayDate !== '' ? 'available' : 'missing',
                'sameHotel' => true,
                'samePlatform' => true,
                'coverageWarning' => $warning($coverageCards, 'same_weekday'),
            ],
            [
                'key' => 'same_campaign_stage',
                'label' => '同活动阶段',
                'currentDate' => $businessDate,
                'baselineDate' => '',
                'status' => 'missing_campaign_identity',
                'sameHotel' => true,
                'samePlatform' => true,
                'coverageWarning' => true,
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function clientMetricDefinitions(): array
    {
        return [
            'revenue' => ['label' => 'OTA渠道订单金额', 'unit' => 'CNY', 'sourceMeaning' => 'order_amount', 'scope' => 'per_ota_platform', 'missingPolicy' => 'null'],
            'orders' => ['label' => 'OTA渠道订单', 'unit' => 'orders', 'scope' => 'per_ota_platform', 'missingPolicy' => 'null'],
            'room_nights' => ['label' => 'OTA渠道间夜', 'unit' => 'room_nights', 'scope' => 'per_ota_platform', 'missingPolicy' => 'null'],
            'adr' => ['label' => 'OTA订单金额 / 间夜', 'unit' => 'CNY', 'formula' => 'order_amount / room_nights', 'scope' => 'per_ota_platform', 'missingPolicy' => 'null'],
            'list_exposure' => ['label' => '列表曝光', 'unit' => 'exposures', 'scope' => 'per_ota_platform', 'missingPolicy' => 'null'],
            'detail_exposure' => ['label' => '详情访问/曝光', 'unit' => 'exposures', 'scope' => 'per_ota_platform', 'missingPolicy' => 'null', 'uvClaimed' => false],
            'flow_rate_percent' => ['label' => '列表到详情转化率', 'unit' => 'percent', 'scope' => 'per_ota_platform', 'missingPolicy' => 'null'],
            'submit_rate_percent' => ['label' => '详情到提交转化率', 'unit' => 'percent', 'scope' => 'per_ota_platform', 'missingPolicy' => 'null'],
            'payment_conversion_percent' => ['label' => '提交到支付转化率', 'unit' => 'percent', 'scope' => 'per_ota_platform', 'missingPolicy' => 'null'],
            'cancellation_rate_percent' => ['label' => '取消率', 'unit' => 'percent', 'scope' => 'per_ota_platform', 'missingPolicy' => 'null'],
            'bookability' => ['label' => '游客侧可订性', 'unit' => 'status', 'scope' => 'per_ota_platform', 'missingPolicy' => 'unknown'],
        ];
    }

    /** @param list<array<string,mixed>> $actual @param list<array<string,mixed>> $expected @return list<array<string,mixed>> */
    private function assertCardList(array $actual, array $expected, string $scope): array
    {
        if (!array_is_list($actual) || count($actual) !== count($expected)) {
            $this->invalid($scope . '_card_count');
        }
        $seen = [];
        $attested = [];
        foreach ($expected as $index => $expectedCard) {
            $actualCard = $actual[$index] ?? null;
            if (!is_array($actualCard)) {
                $this->invalid($scope . '_card_shape');
            }
            $key = (string)($actualCard['key'] ?? '');
            if ($key === '' || isset($seen[$key]) || $key !== (string)($expectedCard['key'] ?? '')) {
                $this->invalid($scope . '_card_identity');
            }
            $seen[$key] = true;
            $this->assertOnlyKeys($actualCard, array_keys($expectedCard), $scope . ':' . $key);
            $this->assertFields($actualCard, $expectedCard, $scope . ':' . $key);
            $attested[] = $expectedCard;
        }
        return $attested;
    }

    /** @param array<string,mixed> $actual @param list<string> $allowed */
    private function assertOnlyKeys(array $actual, array $allowed, string $scope): void
    {
        $allowedLookup = array_fill_keys($allowed, true);
        foreach (array_keys($actual) as $field) {
            if (!isset($allowedLookup[(string)$field])) {
                $this->invalid($scope . ':unexpected_field');
            }
        }
    }

    /** @param array<string,mixed> $actual @param array<string,mixed> $expected */
    private function assertFields(array $actual, array $expected, string $scope): void
    {
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $actual) || !$this->sameValue($actual[$field], $value)) {
                $this->invalid($scope . ':' . $field);
            }
        }
    }

    private function sameValue(mixed $actual, mixed $expected): bool
    {
        if ((is_int($expected) || is_float($expected))
            && (is_int($actual) || is_float($actual))
        ) {
            return abs((float)$actual - (float)$expected) < 0.0000001;
        }
        return $this->canonicalJson($actual) === $this->canonicalJson($expected);
    }

    private function assertCanonicalEqual(array $actual, array $expected, string $scope): void
    {
        if ($this->canonicalJson($actual) !== $this->canonicalJson($expected)) {
            $this->invalid($scope . ':' . $this->firstDifferencePath($actual, $expected));
        }
    }

    private function firstDifferencePath(mixed $actual, mixed $expected, string $path = '$'): string
    {
        if (get_debug_type($actual) !== get_debug_type($expected)) {
            return $path . ':type';
        }
        if (!is_array($actual)) {
            return $this->sameValue($actual, $expected) ? $path : $path . ':value';
        }
        $actualKeys = array_keys($actual);
        $expectedKeys = array_keys($expected);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            foreach ($expectedKeys as $key) {
                if (!array_key_exists($key, $actual)) {
                    return $path . '.' . (string)$key . ':missing';
                }
            }
            foreach ($actualKeys as $key) {
                if (!array_key_exists($key, $expected)) {
                    return $path . '.' . (string)$key . ':unexpected';
                }
            }
            return $path . ':keys';
        }
        foreach ($expected as $key => $expectedItem) {
            $actualItem = $actual[$key];
            if ($this->canonicalJson($actualItem) !== $this->canonicalJson($expectedItem)) {
                return $this->firstDifferencePath(
                    $actualItem,
                    $expectedItem,
                    $path . '.' . (string)$key
                );
            }
        }
        return $path;
    }

    private function assertNoForbiddenClaims(mixed $value): void
    {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            $name = strtolower((string)$key);
            if (($name === 'causalityclaimed' || $name === 'externalactionallowed') && $item !== false) {
                $this->invalid('forbidden_claim:' . $name);
            }
            if ($name === 'causalitystatus' && (string)$item !== 'not_claimed') {
                $this->invalid('forbidden_claim:causality_status');
            }
            if ($name === 'relationshiptype' && str_contains(strtolower((string)$item), 'causal')) {
                $this->invalid('forbidden_claim:causal_relationship');
            }
            if (preg_match('/^automatic(?:approval|execution|pricing|otawrite)$/', str_replace('_', '', $name)) === 1
                && $item === true
            ) {
                $this->invalid('forbidden_claim:automatic_action');
            }
            $this->assertNoForbiddenClaims($item);
        }
    }

    /** @param array<string,mixed> $context */
    private function hasTrustedPmsRef(array $context): bool
    {
        foreach ((array)($context['evidence_refs'] ?? []) as $ref) {
            if (is_array($ref)
                && (string)($ref['table'] ?? '') === 'dingdandao_operating_target_captures'
                && (string)($ref['fact_scope'] ?? '') === 'whole_hotel_accommodation'
                && ($ref['readback_verified'] ?? false) === true
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $overview */
    private function trustedPmsSource(array $overview, string $businessDate): bool
    {
        if ($businessDate === '') {
            return false;
        }
        $pms = is_array($overview['three_source_fact_layer']['sources']['dingdandao_pms'] ?? null)
            ? $overview['three_source_fact_layer']['sources']['dingdandao_pms']
            : [];
        $source = is_array($pms['source'] ?? null) ? $pms['source'] : [];
        return (string)($pms['data_status'] ?? '') === 'readback_verified'
            && (string)($pms['business_date'] ?? '') === $businessDate
            && (string)($pms['actual_business_date'] ?? '') === $businessDate
            && (string)($source['table'] ?? '') === 'dingdandao_operating_target_captures'
            && (string)($source['data_date'] ?? '') === $businessDate
            && (string)($source['readback_status'] ?? '') === 'readback_verified'
            && (int)($source['record_id'] ?? 0) > 0;
    }

    /** @return list<string> */
    private function selectedPlatforms(string $platform): array
    {
        return $platform === 'all_ota' ? ['ctrip', 'meituan'] : [$platform];
    }

    /** @param array<string,mixed> $overview @return list<array<string,mixed>> */
    private function rawGaps(array $overview): array
    {
        return array_values(array_filter(
            (array)($overview['three_source_fact_layer']['analysis_gaps'] ?? []),
            static fn(mixed $gap): bool => is_array($gap)
        ));
    }

    /** @param array<string,array<string,mixed>> $metrics @return array{ready:int,total:int,ready_keys:list<string>} */
    private function coverage(array $metrics, string $sourceKey): array
    {
        $scoped = array_values(array_filter(
            $metrics,
            static fn(array $card): bool => (string)$card['sourceKey'] === $sourceKey
        ));
        $keys = [];
        foreach ($scoped as $card) {
            if (($card['value'] ?? null) !== null) {
                $parts = explode(':', (string)$card['key']);
                $keys[] = (string)end($parts);
            }
        }
        sort($keys, SORT_STRING);
        return ['ready' => count($keys), 'total' => count($scoped), 'ready_keys' => $keys];
    }

    /** @param array<string,mixed> $current */
    private function requiredOtaSourcesReady(array $current, string $platform): bool
    {
        foreach ($this->selectedPlatforms($platform) as $selected) {
            if (($current['source_ready'][$selected . '_ota'] ?? false) !== true) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $strict @return list<string> */
    private function sourceEvidence(
        array $source,
        string $sourceKey,
        string $businessDate,
        array $strict,
        string $metricKey = ''
    ): array {
        $meta = $this->sourceMeta($sourceKey);
        $provenance = is_array($source['source'] ?? null) ? $source['source'] : [];
        $rowIds = is_array($provenance['row_ids'] ?? null)
            ? array_values(array_filter(array_map('intval', $provenance['row_ids']), static fn(int $id): bool => $id > 0))
            : ((int)($provenance['record_id'] ?? 0) > 0 ? [(int)$provenance['record_id']] : []);
        $traceIds = is_array($provenance['source_trace_ids'] ?? null)
            ? array_values(array_filter($provenance['source_trace_ids']))
            : [];
        $strictMetric = $metricKey !== '' && is_array($strict['metrics'][$metricKey] ?? null)
            ? $strict['metrics'][$metricKey]
            : null;
        $accepted = is_array($strictMetric)
            ? $this->positiveIds($strictMetric['accepted_row_ids'] ?? [])
            : $this->positiveIds($strict['accepted_row_ids'] ?? []);
        $rejected = is_array($strictMetric)
            ? $this->positiveIds($strictMetric['rejected_row_ids'] ?? [])
            : $this->positiveIds($strict['rejected_row_ids'] ?? []);
        return [
            '来源：' . $meta['label'] . ' · ' . (string)($provenance['table'] ?? $meta['expected_table'] ?? '来源表待确认'),
            '业务日期：' . (string)($source['business_date'] ?? $provenance['data_date'] ?? $businessDate ?: '待确认')
                . ' · 实际日期：' . (string)($source['actual_business_date'] ?? $provenance['data_date'] ?? '待确认'),
            '保存记录：' . ($rowIds !== []
                ? implode('、', array_map(static fn(int $id): string => '#' . $id, $rowIds))
                : '未返回可追溯记录ID'),
            '严格回读：' . $this->statusText((string)($provenance['readback_status'] ?? $source['data_status'] ?? '')),
            '驾驶舱严格事实闸门：' . ($accepted !== []
                ? implode('、', array_map(static fn(int $id): string => '#' . $id, $accepted))
                : '未命中可用记录')
                . ($rejected !== []
                    ? '；拒绝 ' . implode('、', array_map(static fn(int $id): string => '#' . $id, $rejected))
                    : ''),
            '来源追踪：' . ($traceIds !== [] ? count($traceIds) . ' 条 trace' : '未返回 trace'),
            '口径：' . $meta['scope_label'],
        ];
    }

    /** @return array{label:string,scope:string,scope_label:string,expected_table:string} */
    private function sourceMeta(string $sourceKey): array
    {
        return match ($sourceKey) {
            'dingdandao_pms' => ['label' => 'PMS（订单来了）', 'scope' => 'whole_hotel_accommodation', 'scope_label' => 'PMS 全酒店住宿口径', 'expected_table' => 'dingdandao_operating_target_captures'],
            'ctrip_ota' => ['label' => '携程 OTA', 'scope' => 'ota_channel', 'scope_label' => '携程 OTA 渠道口径', 'expected_table' => 'online_daily_data'],
            'meituan_ota' => ['label' => '美团 OTA', 'scope' => 'ota_channel', 'scope_label' => '美团 OTA 渠道口径', 'expected_table' => 'online_daily_data'],
            'cockpit_rule' => ['label' => '经营驾驶舱规则', 'scope' => 'advisory_only', 'scope_label' => '只读建议口径', 'expected_table' => ''],
            default => ['label' => $sourceKey !== '' ? $sourceKey : '来源待确认', 'scope' => 'unknown', 'scope_label' => '口径待确认', 'expected_table' => ''],
        };
    }

    private function statusText(string $status): string
    {
        return match (strtolower($status)) {
            'readback_verified' => '已严格回读',
            'derived_verified' => '已验证派生',
            'verified' => '已验证',
            'partial_readback_verified' => '部分指标已回读',
            'partial' => '部分数据',
            'missing' => '缺失',
            'not_verified' => '未验证',
            'not_calculable' => '不可计算',
            'evidence_investigation' => '待补证核查',
            'actionable' => '发现可核查机会',
            'no_signal' => '未发现同口径信号',
            'blocked' => '证据阻断',
            'unknown' => '未知',
            'analysis_blocked' => '分析受阻',
            'read_failed' => '读取失败',
            'failed' => '加载失败',
            'ready', 'ok' => '可用',
            default => '状态待确认',
        };
    }

    private function statusClass(string $status): string
    {
        $status = strtolower($status);
        if (in_array($status, ['readback_verified', 'derived_verified', 'verified', 'ready', 'ok'], true)) {
            return 'border-emerald-200 bg-emerald-50 text-emerald-700';
        }
        if (in_array($status, ['read_failed', 'failed', 'error', 'blocked'], true)) {
            return 'border-rose-200 bg-rose-50 text-rose-700';
        }
        if ($status === 'actionable') {
            return 'border-amber-300 bg-amber-50 text-amber-900';
        }
        return 'border-amber-200 bg-amber-50 text-amber-800';
    }

    private function reasonText(string $reason): string
    {
        return match ($reason) {
            '' => '数据已命中当前口径。',
            'online_daily_data_empty' => '目标经营日期没有可用 OTA 入库数据。',
            'source_not_loaded' => '未找到对应渠道的数据源或入库状态。',
            'metric_scope_mismatch' => '指标事实与当前酒店、平台或业务日期不一致。',
            'metric_truth_unverified' => '指标有值，但尚未完成来源事实和精确回读验证。',
            'metric_truth_partial' => '指标只有部分来源事实通过验证。',
            'metric_truth_collection_failed' => '指标所需的平台事实采集失败。',
            'overview_scope_mismatch' => 'Revenue AI 总览与请求的酒店或业务日期不一致。',
            'room_revenue_missing' => '暂缺已验证房费收入；订单 GMV、结算金额和参考底价不能替代。',
            'room_revenue_partial' => '只有部分 OTA 事实具备已验证房费收入。',
            'room_nights_missing' => '暂缺已验证间夜，不能用订单数、物理房间数或默认值替代。',
            'order_count_missing' => '暂缺语义明确且已回读的订单数。',
            'available_room_nights_missing' => '暂缺可信 OTA 渠道可售房晚分母，不能计算或外推全酒店 RevPAR。',
            'available_room_nights_partial' => '只有部分 OTA 事实具备可售房晚。',
            'adr_denominator_zero' => 'OTA 间夜为 0，ADR 不可计算。',
            'commission_fields_missing' => '暂缺同一成交口径的佣金金额或佣金率。',
            'commission_fields_partial' => '只有部分 OTA 事实具备同口径佣金字段。',
            'net_revenue_fields_missing' => '暂缺平台净收入，且没有同口径佣金事实可安全派生。',
            'net_revenue_fields_partial' => '只有部分 OTA 事实具备净收入。',
            'cancellation_fields_missing' => '暂缺平台取消订单数或取消率。',
            'cancellation_fields_partial' => '只有部分 OTA 事实具备取消字段。',
            'cancellation_order_base_missing' => '已有取消字段，但缺少同口径订单基数。',
            'cancel_room_nights_missing' => '暂缺取消订单对应的真实取消间夜。',
            'cancel_room_nights_partial' => '只有部分 OTA 事实具备取消间夜。',
            'competitor_price_fields_missing' => '暂缺竞对价格字段。',
            'competitor_price_fields_partial' => '只有部分 OTA 事实具备条件对齐的本店价与竞对价。',
            'source_status_missing' => '未找到平台数据源状态。',
            'source_status_unknown' => '未命中明确同步状态。',
            'waiting_config' => '平台数据源仍待授权或配置。',
            'source_disabled' => '平台数据源已禁用。',
            'sync_failed' => '平台同步失败。',
            'AUTH_EXPIRED' => '登录或授权已失效。',
            'CAPTCHA_REQUIRED' => '需要验证码或人工登录确认。',
            'PAGE_CHANGED' => '平台页面结构变化，采集解析需复核。',
            'FIELD_MISSING' => '关键字段缺失。',
            'PARSER_MISMATCH' => '解析器与平台返回结构不匹配。',
            'NETWORK_ERROR' => '平台请求网络异常。',
            'RATE_LIMITED' => '平台请求被限流。',
            'DATE_NOT_AVAILABLE' => '目标经营日期未命中可用入库数据。',
            'DATA_STALE' => '平台数据过期，目标经营日期没有新入库证据。',
            'blocked_by_data_credibility' => 'OTA 数据可信门未通过，收益计算被阻断。',
            'source_rows_missing' => '缺少可追溯的 OTA 来源行。',
            'source_update_time_missing' => '缺少 OTA 来源更新时间。',
            'metric_value_missing' => '指标值缺失。',
            'whole_hotel_scope_not_proved' => '尚未证明全酒店口径，只能保留 OTA 渠道边界。',
            'dingdandao_pms_not_readback_verified' => 'PMS 全酒店住宿事实尚未完成同店同日回读验证。',
            'three_source_ota_facts_partial' => '携程或美团目标日渠道事实尚未完成回读验证。',
            'cross_source_denominator_or_ota_facts_missing' => 'PMS 全酒店可售间夜或 OTA 渠道分子缺失，跨源指标不可计算。',
            'source_fact_missing' => '对应来源事实缺失，保持为空。',
            'revenue_positive_orders_zero' => 'OTA 收入大于 0 但订单数为 0，需复核来源字段。',
            'revenue_positive_room_nights_zero' => 'OTA 收入大于 0 但间夜为 0，需复核来源字段。',
            'data_not_complete' => '当前数据未达到完整口径。',
            'ZERO_CONFIRMED' => '渠道明确确认目标经营日期无数据。',
            default => $reason !== '' ? $reason : '数据缺口待确认。',
        };
    }

    private function displayValue(float|int|null $value, string $unit): string
    {
        if ($value === null) {
            return '—';
        }
        $decimals = in_array($unit, ['CNY', 'percent'], true) ? 2 : 0;
        $formatted = number_format((float)$value, $decimals, '.', ',');
        return match ($unit) {
            'CNY' => '¥' . $formatted,
            'percent' => $formatted . '%',
            'orders' => $formatted . ' 单',
            'room_nights' => $formatted . ' 间夜',
            'exposures' => $formatted . ' 次',
            default => $formatted,
        };
    }

    private function unitLabel(string $unit): string
    {
        return match ($unit) {
            'CNY' => '人民币',
            'percent' => '百分比',
            'orders' => '订单数',
            'room_nights' => '间夜数',
            'exposures' => '曝光次数',
            'status' => '状态',
            'text' => '说明',
            default => $unit,
        };
    }

    private function platformLabel(string $platform): string
    {
        return match ($platform) {
            'ctrip' => '携程',
            'meituan' => '美团',
            'all_ota' => '携程 + 美团',
            default => 'OTA',
        };
    }

    private function number(mixed $value): float|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $number = $value + 0;
        return is_int($number) ? $number : (float)$number;
    }

    /** @return list<int> */
    private function positiveIds(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        return array_values(array_filter(
            array_map('intval', $values),
            static fn(int $id): bool => $id > 0
        ));
    }

    /** @param list<mixed> $values @return list<mixed> */
    private function unique(array $values): array
    {
        $seen = [];
        $result = [];
        foreach ($values as $value) {
            $key = is_scalar($value) || $value === null
                ? get_debug_type($value) . ':' . (string)$value
                : $this->canonicalJson($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $value;
        }
        return $result;
    }

    private function dateDistance(string $left, string $right): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $left) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $right) !== 1
        ) {
            return null;
        }
        $leftTime = strtotime($left . ' UTC');
        $rightTime = strtotime($right . ' UTC');
        return $leftTime === false || $rightTime === false
            ? null
            : (int)round(($rightTime - $leftTime) / 86400);
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function invalid(string $detail): never
    {
        throw new InvalidArgumentException('revenue_decision_snapshot_view_model_unattested:' . $detail);
    }
}
