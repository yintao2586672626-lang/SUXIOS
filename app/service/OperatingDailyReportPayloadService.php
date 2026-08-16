<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use think\facade\Db;

/**
 * Builds one exact-date PMS + OTA operating summary.
 *
 * No previous-day, cross-hotel or default-value fallback is permitted here.
 * A delivery candidate exists only when every user-selected field is present.
 */
final class OperatingDailyReportPayloadService
{
    public const RENDER_CONTRACT_VERSION = 'operating_daily_pms_ota_wecom.v2';
    public const TEMPLATE_MODE_COMMON = 'common';
    public const TEMPLATE_MODE_CUSTOM = 'custom';
    private const CUSTOM_TEMPLATE_VARIABLES = [
        '{酒店名称}',
        '{经营日期}',
        '{住宿客房房费}',
        '{已售间夜}',
        '{可售房夜}',
        '{入住率}',
        '{ADR}',
        '{RevPAR}',
        '{携程访客量}',
        '{携程上周同期访客量}',
        '{携程预订订单}',
        '{携程在店间夜}',
        '{携程排名}',
        '{携程竞争圈排名}',
        '{携程竞争圈总量}',
        '{携程起价}',
        '{去哪儿访客量}',
        '{去哪儿竞争圈平均访客量}',
        '{去哪儿预订订单}',
        '{去哪儿下单转化率}',
        '{去哪儿竞争圈平均转化率}',
        '{美团曝光人数}',
        '{美团浏览人数}',
        '{美团曝光浏览转化率}',
        '{美团支付订单}',
        '{美团浏览支付转化率}',
    ];
    private const LEGACY_CUSTOM_TEMPLATE_VARIABLES = [
        '{携程实时访客量}',
        '{携程实时预订订单}',
        '{携程实时在店间夜}',
        '{携程实时排名}',
        '{携程实时起价}',
        '{去哪儿实时访客量}',
        '{去哪儿实时预订订单}',
        '{去哪儿实时下单转化率}',
    ];
    private const CUSTOM_VARIABLE_SECTION_REQUIREMENTS = [
        '{住宿客房房费}' => ['pms_summary'],
        '{已售间夜}' => ['pms_summary'],
        '{可售房夜}' => ['pms_efficiency'],
        '{入住率}' => ['pms_efficiency'],
        '{ADR}' => ['pms_efficiency'],
        '{RevPAR}' => ['pms_efficiency'],
        '{携程访客量}' => ['ctrip_traffic'],
        '{携程实时访客量}' => ['ctrip_traffic'],
        '{携程上周同期访客量}' => ['ctrip_traffic'],
        '{携程预订订单}' => ['ctrip_traffic'],
        '{携程实时预订订单}' => ['ctrip_traffic'],
        '{携程在店间夜}' => ['ctrip_traffic'],
        '{携程实时在店间夜}' => ['ctrip_traffic'],
        '{携程排名}' => ['ctrip_market'],
        '{携程实时排名}' => ['ctrip_market'],
        '{携程竞争圈排名}' => ['ctrip_market'],
        '{携程竞争圈总量}' => ['ctrip_market'],
        '{携程起价}' => ['ctrip_market'],
        '{携程实时起价}' => ['ctrip_market'],
        '{去哪儿访客量}' => ['qunar_traffic'],
        '{去哪儿实时访客量}' => ['qunar_traffic'],
        '{去哪儿竞争圈平均访客量}' => ['qunar_traffic'],
        '{去哪儿预订订单}' => ['qunar_traffic'],
        '{去哪儿实时预订订单}' => ['qunar_traffic'],
        '{去哪儿下单转化率}' => ['qunar_traffic'],
        '{去哪儿实时下单转化率}' => ['qunar_traffic'],
        '{去哪儿竞争圈平均转化率}' => ['qunar_traffic'],
        '{美团曝光人数}' => ['meituan_traffic'],
        '{美团浏览人数}' => ['meituan_traffic', 'meituan_conversion'],
        '{美团曝光浏览转化率}' => ['meituan_traffic'],
        '{美团支付订单}' => ['meituan_conversion'],
        '{美团浏览支付转化率}' => ['meituan_conversion'],
    ];
    private const SOURCE_SCOPES = [
        'combined' => '三源汇总',
        'ctrip' => '携程',
        'meituan' => '美团',
        'dingdandao_pms' => '订单来了 PMS',
    ];
    private const SOURCE_SECTIONS = [
        'combined' => [
            'pms_summary',
            'pms_efficiency',
            'ctrip_traffic',
            'ctrip_market',
            'qunar_traffic',
            'meituan_traffic',
            'meituan_conversion',
        ],
        'ctrip' => ['ctrip_traffic', 'ctrip_market', 'qunar_traffic'],
        'meituan' => ['meituan_traffic', 'meituan_conversion'],
        'dingdandao_pms' => ['pms_summary', 'pms_efficiency'],
    ];
    private const SECTION_FACTS = [
        'pms_summary' => ['pms_room_fee', 'pms_sold_room_nights'],
        'pms_efficiency' => [
            'pms_sellable_room_nights',
            'pms_occupancy',
            'pms_adr',
            'pms_revpar',
        ],
        'ctrip_traffic' => [
            'ctrip_visitors',
            'ctrip_last_week_visitors',
            'ctrip_booking_orders',
            'ctrip_in_house_room_nights',
        ],
        'ctrip_market' => [
            'ctrip_realtime_rank',
            'ctrip_competitor_rank',
            'ctrip_competitor_total',
            'ctrip_starting_price',
        ],
        'qunar_traffic' => [
            'qunar_visitors',
            'qunar_visitor_peer_avg',
            'qunar_booking_orders',
            'qunar_conversion',
            'qunar_conversion_peer_avg',
            'qunar_visitor_lagging',
            'qunar_conversion_lagging',
        ],
        'meituan_traffic' => [
            'meituan_exposure',
            'meituan_viewers',
            'meituan_exposure_view_conversion',
        ],
        'meituan_conversion' => [
            'meituan_viewers',
            'meituan_paid_orders',
            'meituan_view_to_paid_conversion',
        ],
    ];
    private const TRUSTED_OTA_INGESTION_METHODS = [
        'browser_profile',
        'profile_browser',
    ];
    private const TRUSTED_OTA_VALIDATION_STATUSES = [
        'normal',
        'available',
        'verified',
        'valid',
        'confirmed',
        'approved',
        'passed',
        'ok',
        'success',
        'complete',
        'completed',
    ];
    private const BLOCKING_OTA_AUXILIARY_STATUSES = [
        'abnormal',
        'invalid',
        'failed',
        'fail',
        'error',
        'unverified',
        'mismatched',
        'mismatch',
        'collection_failed',
        'capture_failed',
        'permission_denied',
        'binding_missing',
        'stale',
        'partial',
        'quarantined',
    ];
    private const BLOCKING_OTA_FLAG_FRAGMENTS = [
        'mismatch',
        'wrong_hotel',
        'binding',
        'unverified',
        'provenance',
        'permission_denied',
        'collection_failed',
        'capture_failed',
        'parse_failed',
    ];

    /** @var null|callable(int,int,string):array<string,mixed> */
    private $pmsResolver;

    /** @var null|callable(int,int,string,string,string,?string):?array<string,mixed> */
    private $rowResolver;

    /** @var null|callable(int,int,string):array<string,mixed> */
    private $pmsBindingResolver;

    /** @var array<string, string> */
    private array $rowResolutionFailures = [];

    public function __construct(
        private readonly ?DingdandaoOperatingTargetCaptureService $pmsCaptures = null,
        ?callable $pmsResolver = null,
        ?callable $rowResolver = null,
        private readonly ?CollectionResultContractService $collectionResults = null,
        ?callable $pmsBindingResolver = null
    ) {
        $this->pmsResolver = $pmsResolver;
        $this->rowResolver = $rowResolver;
        $this->pmsBindingResolver = $pmsBindingResolver;
    }

    /** @return list<string> */
    public static function customTemplateVariables(): array
    {
        return self::CUSTOM_TEMPLATE_VARIABLES;
    }

    /** @return array{title:string,body:string} */
    public static function defaultCustomTemplate(): array
    {
        return [
            'title' => '今日经营数据汇总｜PMS＋OTA',
            'body' => implode("\n", [
                '门店：{酒店名称}',
                '业务日：{经营日期}',
                '数据说明：OTA 渠道数据为平台采集快照，不代表发送时点状态；以采集时间为准。',
                'PMS｜订单来了',
                '- 住宿客房房费：{住宿客房房费}',
                '- 已售间夜：{已售间夜}',
                '- 可售房夜：{可售房夜}',
                '- 入住率：{入住率}',
                '- ADR：{ADR}',
                '- RevPAR：{RevPAR}',
                '携程｜OTA 渠道（采集快照）',
                '- APP 访客量：{携程访客量}（上周同期 {携程上周同期访客量}）',
                '- 预订订单：{携程预订订单}',
                '- 在店间夜：{携程在店间夜}',
                '- 排名：{携程排名}',
                '- 起价：{携程起价}',
                '去哪儿｜OTA 渠道（采集快照）',
                '- APP 访客量：{去哪儿访客量}（竞争圈平均 {去哪儿竞争圈平均访客量}）',
                '- 预订订单：{去哪儿预订订单}',
                '- APP 下单转化率：{去哪儿下单转化率}（竞争圈平均 {去哪儿竞争圈平均转化率}）',
                '美团｜OTA 渠道（采集快照）',
                '- 曝光人数：{美团曝光人数}',
                '- 浏览人数：{美团浏览人数}',
                '- 曝光→浏览转化率：{美团曝光浏览转化率}',
                '- 支付订单：{美团支付订单}',
                '- 浏览→支付转化率：{美团浏览支付转化率}',
            ]),
        ];
    }

    public static function assertCustomTemplate(string $title, string $body): void
    {
        if (trim($title) === '' || trim($body) === '') {
            throw new \InvalidArgumentException('operating_daily_custom_template_required');
        }
        $content = $title . "\n" . $body;
        preg_match_all('/\{[^{}\r\n]+\}/u', $content, $matches);
        $supportedVariables = array_merge(
            self::CUSTOM_TEMPLATE_VARIABLES,
            self::LEGACY_CUSTOM_TEMPLATE_VARIABLES
        );
        $allowed = array_fill_keys($supportedVariables, true);
        foreach ($matches[0] ?? [] as $token) {
            if (!isset($allowed[$token])) {
                throw new \InvalidArgumentException('operating_daily_custom_variable_invalid');
            }
        }
        $withoutAllowedVariables = strtr(
            $content,
            array_fill_keys($supportedVariables, '')
        );
        if (str_contains($withoutAllowedVariables, '{')
            || str_contains($withoutAllowedVariables, '}')
        ) {
            throw new \InvalidArgumentException('operating_daily_custom_variable_invalid');
        }
    }

    /** @param list<string> $contentSections */
    public static function assertCustomTemplateForSections(
        string $title,
        string $body,
        array $contentSections
    ): void {
        self::assertCustomTemplate($title, $body);
        $selected = array_fill_keys(array_map(
            static fn(mixed $section): string => trim((string)$section),
            $contentSections
        ), true);
        preg_match_all(
            '/\{[^{}\r\n]+\}/u',
            $title . "\n" . $body,
            $matches
        );
        foreach (array_unique($matches[0] ?? []) as $token) {
            $requirements =
                self::CUSTOM_VARIABLE_SECTION_REQUIREMENTS[$token] ?? [];
            if ($requirements === []) {
                continue;
            }
            foreach ($requirements as $section) {
                if (isset($selected[$section])) {
                    continue 2;
                }
            }
            throw new \InvalidArgumentException(
                'operating_daily_custom_variable_section_mismatch'
            );
        }
    }

    public static function assertDynamicCustomTemplate(
        string $title,
        string $body,
        string $businessDateRule
    ): void {
        if (!in_array(
            strtolower(trim($businessDateRule)),
            ['today', 'yesterday'],
            true
        )) {
            return;
        }
        if (preg_match(
            '/(?<!\d)(?:20\d{2}[-\/]\d{1,2}[-\/]\d{1,2}|20\d{2}年\d{1,2}月\d{1,2}日)(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?(?!\d)/u',
            $title . "\n" . $body
        ) === 1) {
            throw new \InvalidArgumentException(
                'operating_daily_dynamic_date_literal_forbidden'
            );
        }
    }

    /** @return array<string, mixed> */
    public function pagePreview(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        string $sourceScope = 'combined',
        array $contentSections = [],
        string $templateMode = self::TEMPLATE_MODE_COMMON,
        string $customTitle = '',
        string $customBody = ''
    ): array {
        $candidate = $this->candidate(
            $tenantId,
            $hotelId,
            $hotelName,
            $businessDate,
            $sourceScope,
            $contentSections,
            $templateMode,
            $customTitle,
            $customBody
        );
        $previewReady = ($candidate['status'] ?? '') === 'ready';
        return array_replace($candidate, [
            'status' => $previewReady
                ? 'preview_ready'
                : 'preview_unavailable',
            'delivery_status' => $previewReady
                ? 'preview_only'
                : 'preview_unavailable',
        ]);
    }

    /** @return array<string, mixed> */
    public function build(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        string $deliveryMode,
        string $sourceScope = 'combined',
        array $contentSections = [],
        string $templateMode = self::TEMPLATE_MODE_COMMON,
        string $customTitle = '',
        string $customBody = ''
    ): array {
        if (!in_array($deliveryMode, ['immediate_test', 'scheduled_test'], true)) {
            throw new \InvalidArgumentException('operating_daily_delivery_mode_invalid');
        }
        return $this->candidate(
            $tenantId,
            $hotelId,
            $hotelName,
            $businessDate,
            $sourceScope,
            $contentSections,
            $templateMode,
            $customTitle,
            $customBody
        );
    }

    /** @return array<string, mixed> */
    private function candidate(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        string $sourceScope,
        array $contentSections,
        string $templateMode,
        string $customTitle,
        string $customBody
    ): array {
        $businessDate = $this->date($businessDate);
        $sourceScope = trim($sourceScope);
        if (!isset(self::SOURCE_SCOPES[$sourceScope])) {
            throw new \InvalidArgumentException('operating_daily_source_scope_invalid');
        }
        if (!in_array(
            $templateMode,
            [self::TEMPLATE_MODE_COMMON, self::TEMPLATE_MODE_CUSTOM],
            true
        )) {
            throw new \InvalidArgumentException('operating_daily_template_mode_invalid');
        }
        $contentSections = $this->normalizeContentSections(
            $sourceScope,
            $contentSections
        );
        if ($templateMode === self::TEMPLATE_MODE_CUSTOM) {
            if ($sourceScope !== 'combined') {
                throw new \InvalidArgumentException('operating_daily_custom_scope_invalid');
            }
            self::assertCustomTemplateForSections(
                $customTitle,
                $customBody,
                $contentSections
            );
        }
        $sectionSet = array_fill_keys($contentSections, true);
        $this->rowResolutionFailures = [];
        $needsPms = isset($sectionSet['pms_summary']) || isset($sectionSet['pms_efficiency']);
        $needsCtripTraffic = isset($sectionSet['ctrip_traffic']) || isset($sectionSet['ctrip_market']);
        $needsCtripRank = isset($sectionSet['ctrip_market']);
        $needsQunar = isset($sectionSet['qunar_traffic']);
        $needsMeituan = isset($sectionSet['meituan_traffic'])
            || isset($sectionSet['meituan_conversion']);
        $ctripTrafficMetricKeys = [];
        if (isset($sectionSet['ctrip_traffic'])) {
            $ctripTrafficMetricKeys = [
                'realtime_visitors',
                'last_week_visitors',
                'booking_order_count',
                'in_house_room_nights',
            ];
        }
        if (isset($sectionSet['ctrip_market'])) {
            $ctripTrafficMetricKeys[] = 'starting_price';
        }
        $ctripTrafficMetricKeys = array_values(array_unique(
            $ctripTrafficMetricKeys
        ));
        $meituanTrafficMetricKeys = [];
        if (isset($sectionSet['meituan_traffic'])) {
            $meituanTrafficMetricKeys = ['list_exposure', 'detail_exposure'];
        }
        if (isset($sectionSet['meituan_conversion'])) {
            $meituanTrafficMetricKeys[] = 'detail_exposure';
            $meituanTrafficMetricKeys[] = 'order_submit_num';
        }
        $meituanTrafficMetricKeys = array_values(array_unique(
            $meituanTrafficMetricKeys
        ));
        $blockers = [];
        $warnings = [];

        if ($tenantId <= 0 || $hotelId <= 0 || trim($hotelName) === '') {
            $blockers[] = $this->blocker('operating_daily_scope_invalid', '门店或租户范围缺失。');
        }

        $pms = $needsPms
            ? $this->resolvePms($tenantId, $hotelId, $businessDate)
            : [];
        $pmsGate = $needsPms
            ? $this->pmsGate(
                $pms,
                $tenantId,
                $hotelId,
                $hotelName,
                $businessDate
            )
            : [
                'allowed' => true,
                'reason_code' => 'pms_not_required',
                'message' => '',
                'binding' => [],
            ];
        if ($needsPms && ($pmsGate['allowed'] ?? false) !== true) {
            $blockers[] = $this->blocker(
                (string)($pmsGate['reason_code']
                    ?? 'operating_daily_pms_not_verified'),
                (string)($pmsGate['message']
                    ?? 'PMS 同店同日事实未通过身份、对账和数据库回读校验。')
            );
        }

        $ctripTraffic = $needsCtripTraffic
            ? $this->resolveRow(
                $tenantId,
                $hotelId,
                $businessDate,
                'ctrip',
                'traffic',
                'realtime:ctrip',
                $ctripTrafficMetricKeys
            )
            : null;
        $ctripRank = $needsCtripRank
            ? $this->resolveRow(
                $tenantId,
                $hotelId,
                $businessDate,
                'ctrip',
                'peer_rank',
                'realtime:ctrip:rank',
                ['realtime_rank', 'competitor_rank', 'competitor_total']
            )
            : null;
        $qunarTraffic = $needsQunar
            ? $this->resolveRow(
                $tenantId,
                $hotelId,
                $businessDate,
                'ctrip',
                'traffic',
                'realtime:qunar',
                [
                    'realtime_visitors',
                    'visitor_peer_avg',
                    'booking_order_count',
                    'order_conversion_rate',
                    'conversion_peer_avg',
                    'visitor_lagging',
                    'conversion_lagging',
                ]
            )
            : null;
        $meituanTraffic = $needsMeituan
            ? $this->resolveRow(
                $tenantId,
                $hotelId,
                $businessDate,
                'meituan',
                'traffic',
                null,
                $meituanTrafficMetricKeys
            )
            : null;
        $meituanBusinessCandidate = $needsMeituan
            ? $this->resolveRow(
                $tenantId,
                $hotelId,
                $businessDate,
                'meituan',
                'business',
                null,
                [
                    'lead_price',
                    'sales_room_nights',
                    'sales_amount',
                    'sales_avg_price',
                ]
            )
            : null;
        $meituanBusiness = $this->sameMeituanSnapshot(
            $meituanBusinessCandidate,
            $meituanTraffic
        )
            ? $meituanBusinessCandidate
            : null;
        if ($needsMeituan
            && is_array($meituanBusinessCandidate)
            && !is_array($meituanBusiness)
        ) {
            $warnings[] = [
                'code' => 'meituan_business_snapshot_mismatch',
                'message' => '美团经营卡片与流量卡片不属于同一采集快照，经营字段未进入本次播报。',
            ];
        }

        $requiredRows = [];
        if ($needsCtripTraffic) {
            $requiredRows['ctrip_traffic'] = $ctripTraffic;
        }
        if ($needsCtripRank) {
            $requiredRows['ctrip_rank'] = $ctripRank;
        }
        if ($needsQunar) {
            $requiredRows['qunar_traffic'] = $qunarTraffic;
        }
        if ($needsMeituan) {
            $requiredRows['meituan_traffic'] = $meituanTraffic;
        }
        foreach ($requiredRows as $key => $row) {
            if (!is_array($row)) {
                $failureCode = $this->rowResolutionFailureFor($key);
                $blockers[] = $this->blocker(
                    $failureCode === ''
                        ? 'operating_daily_' . $key . '_missing'
                        : 'operating_daily_' . $key . '_untrusted',
                    $failureCode === ''
                        ? $key . ' 缺少同店同日、平台采集快照且已回读的数据。'
                        : $key . ' 找到数据，但未通过可信采集、门店绑定、来源追踪或字段事实校验（'
                            . $failureCode . '）。'
                );
            }
        }

        $lineageRows = [];
        if ($needsCtripTraffic) {
            $lineageRows['ctrip_traffic'] = $ctripTraffic;
        }
        if ($needsCtripRank) {
            $lineageRows['ctrip_rank'] = $ctripRank;
        }
        if ($needsQunar) {
            $lineageRows['qunar_traffic'] = $qunarTraffic;
        }
        foreach ($lineageRows as $key => $row) {
            if (is_array($row) && !$this->hasBoundLineage($row)) {
                $blockers[] = $this->blocker(
                    'operating_daily_' . $key . '_lineage_missing',
                    $key . ' 尚未绑定数据源、同步任务和来源追踪。'
                );
            }
        }

        if ($needsMeituan
            && is_array($meituanTraffic)
            && !$this->hasBoundLineage($meituanTraffic)
        ) {
            $blockers[] = $this->blocker(
                'operating_daily_meituan_traffic_lineage_missing',
                '美团同店同日快照尚未绑定数据源、同步任务和来源追踪。'
            );
        }

        $pmsSummary = is_array($pms['summary'] ?? null) ? $pms['summary'] : [];
        $ctripMetrics = $this->rawSection($ctripTraffic, 'metrics');
        $ctripRankMetrics = $this->rawSection($ctripRank, 'rank_metrics');
        $qunarMetrics = $this->rawSection($qunarTraffic, 'metrics');
        $meituanBusinessRaw = $this->raw($meituanBusiness);
        $meituanBusinessMetrics = $this->rawSection($meituanBusiness, 'metrics');
        $meituanTrafficRaw = $this->raw($meituanTraffic);

        $factDerivations = [];
        $facts = [
            'pms_room_fee' => $this->number($pmsSummary, 'total_room_fee'),
            'pms_sold_room_nights' => $this->number($pmsSummary, 'sold_room_nights'),
            'pms_sellable_room_nights' => $this->number($pmsSummary, 'derived_sellable_room_nights'),
            'pms_occupancy' => $this->number($pmsSummary, 'occupancy_rate_percent'),
            'pms_adr' => $this->number($pmsSummary, 'adr'),
            'pms_revpar' => $this->number($pmsSummary, 'revpar'),
            'ctrip_visitors' => $this->number($ctripTraffic ?? [], 'detail_exposure'),
            'ctrip_last_week_visitors' => $this->number($ctripMetrics, 'last_week_visitors'),
            'ctrip_booking_orders' => $this->number($ctripTraffic ?? [], 'book_order_num'),
            'ctrip_in_house_room_nights' => $this->number($ctripTraffic ?? [], 'quantity'),
            'ctrip_realtime_rank' => $this->number($ctripRankMetrics, 'realtime_rank'),
            'ctrip_competitor_rank' => $this->number($ctripRankMetrics, 'competitor_rank'),
            'ctrip_competitor_total' => $this->number($ctripRankMetrics, 'competitor_total'),
            'ctrip_starting_price' => $this->number($ctripMetrics, 'starting_price'),
            'qunar_visitors' => $this->number($qunarTraffic ?? [], 'detail_exposure'),
            'qunar_visitor_peer_avg' => $this->number($qunarMetrics, 'visitor_peer_avg'),
            'qunar_booking_orders' => $this->number($qunarTraffic ?? [], 'book_order_num'),
            'qunar_conversion' => $this->number($qunarTraffic ?? [], 'flow_rate'),
            'qunar_conversion_peer_avg' => $this->number($qunarMetrics, 'conversion_peer_avg'),
            'meituan_exposure' => $this->number($meituanTraffic ?? [], 'list_exposure'),
            'meituan_viewers' => $this->number($meituanTraffic ?? [], 'detail_exposure'),
            'meituan_exposure_view_conversion' => $this->firstPercentNumber(
                [$meituanTrafficRaw],
                [
                    'exposure_to_browse_rate',
                    'intentionPerExposure',
                    'intention_per_exposure',
                    'exposureToBrowseRate',
                    'expose_visit_rate',
                ]
            ),
            'meituan_paid_orders' => $this->number($meituanTraffic ?? [], 'order_submit_num'),
            'meituan_lead_price' => $this->firstNumber(
                [$meituanBusiness ?? [], $meituanBusinessRaw, $meituanBusinessMetrics],
                ['lead_price', 'leadPrice', 'startingPrice']
            ),
            'meituan_sales_room_nights' => $this->firstNumber(
                [$meituanBusiness ?? [], $meituanBusinessRaw, $meituanBusinessMetrics],
                ['quantity', 'sales_room_nights', 'salesRoomNights', 'room_nights']
            ),
            'meituan_sales_amount' => $this->firstNumber(
                [$meituanBusiness ?? [], $meituanBusinessRaw, $meituanBusinessMetrics],
                ['amount', 'sales_amount', 'salesAmount']
            ),
            'meituan_sales_avg_price' => $this->firstNumber(
                [$meituanBusiness ?? [], $meituanBusinessRaw, $meituanBusinessMetrics],
                ['data_value', 'sales_avg_price', 'salesAvgPrice', 'avgPrice']
            ),
        ];
        if ($facts['meituan_exposure_view_conversion'] === null
            && $facts['meituan_exposure'] !== null
            && $facts['meituan_exposure'] > 0
            && $facts['meituan_viewers'] !== null
            && $facts['meituan_viewers'] >= 0
            && $facts['meituan_viewers'] <= $facts['meituan_exposure']
        ) {
            $facts['meituan_exposure_view_conversion'] = round(
                $facts['meituan_viewers'] / $facts['meituan_exposure'] * 100,
                2
            );
            $factDerivations['meituan_exposure_view_conversion'] = [
                'method' => 'meituan_viewers_div_exposure',
                'source_snapshot_key' => 'meituan_traffic',
                'numerator' => 'meituan_viewers',
                'denominator' => 'meituan_exposure',
                'unit' => 'percent',
            ];
            $warnings[] = [
                'code' => 'meituan_exposure_view_conversion_derived',
                'message' => '美团未直接返回曝光到浏览转化率，已按同一快照的浏览人数 ÷ 曝光人数计算。',
            ];
        }
        $facts['meituan_exposure_view_conversion_derived'] = isset(
            $factDerivations['meituan_exposure_view_conversion']
        );
        if ($needsCtripRank
            && $facts['ctrip_starting_price'] !== null
            && $facts['ctrip_starting_price'] <= 0
        ) {
            $blockers[] = $this->blocker(
                'operating_daily_field_invalid:ctrip_starting_price',
                '携程起价必须是平台明确返回的大于 0 的价格；未将 0 当作缺失值发送。'
            );
        }
        $booleanFacts = [
            'qunar_visitor_lagging' => $this->boolean($qunarMetrics, 'visitor_lagging'),
            'qunar_conversion_lagging' => $this->boolean($qunarMetrics, 'conversion_lagging'),
        ];

        $requiredFactKeys = $this->requiredFactKeys($contentSections);
        foreach ($requiredFactKeys as $field) {
            if (in_array(
                $field,
                ['qunar_visitor_lagging', 'qunar_conversion_lagging', 'meituan_view_to_paid_conversion'],
                true
            )) {
                continue;
            }
            $value = $facts[$field] ?? null;
            if ($value === null) {
                $blockers[] = $this->blocker(
                    'operating_daily_field_missing:' . $field,
                    $field . ' 缺失；未使用 0、旧数据或默认值补齐。'
                );
            }
        }
        foreach ($booleanFacts as $field => $value) {
            if (!in_array($field, $requiredFactKeys, true)) {
                continue;
            }
            if ($value === null) {
                $blockers[] = $this->blocker(
                    'operating_daily_field_missing:' . $field,
                    $field . ' 缺少页面领先/落后标记。'
                );
            }
        }

        $meituanViewToPaid = null;
        if (isset($sectionSet['meituan_conversion'])) {
            if ($facts['meituan_viewers'] !== null && $facts['meituan_viewers'] > 0
                && $facts['meituan_paid_orders'] !== null
            ) {
                $meituanViewToPaid = round(
                    $facts['meituan_paid_orders'] / $facts['meituan_viewers'] * 100,
                    2
                );
            } elseif ($facts['meituan_viewers'] !== null) {
                $blockers[] = $this->blocker(
                    'operating_daily_meituan_conversion_not_derivable',
                    '美团浏览人数为 0，无法按支付订单÷浏览人数派生转化率。'
                );
            }
        }
        $facts['meituan_view_to_paid_conversion'] = $meituanViewToPaid;
        $facts += $booleanFacts;
        if (in_array('meituan_view_to_paid_conversion', $requiredFactKeys, true)
            && $facts['meituan_view_to_paid_conversion'] === null
        ) {
            $blockers[] = $this->blocker(
                'operating_daily_field_missing:meituan_view_to_paid_conversion',
                '美团浏览到支付转化率缺失；未使用 0、旧数据或默认值补齐。'
            );
        }
        $selectedFacts = [];
        foreach ($requiredFactKeys as $field) {
            $selectedFacts[$field] = $facts[$field] ?? null;
        }
        if ($sourceScope === 'meituan') {
            foreach ([
                'meituan_lead_price',
                'meituan_sales_room_nights',
                'meituan_sales_amount',
                'meituan_sales_avg_price',
            ] as $field) {
                $selectedFacts[$field] = $facts[$field] ?? null;
            }
        }

        $blockers = $this->uniqueBlockers($blockers);
        $sourceIds = [];
        if ($needsPms) {
            $sourceIds['pms_capture_id'] = (int)($pms['id'] ?? 0);
        }
        if ($needsCtripTraffic) {
            $sourceIds['ctrip_traffic_row_id'] = (int)($ctripTraffic['id'] ?? 0);
        }
        if ($needsCtripRank) {
            $sourceIds['ctrip_rank_row_id'] = (int)($ctripRank['id'] ?? 0);
        }
        if ($needsQunar) {
            $sourceIds['qunar_traffic_row_id'] = (int)($qunarTraffic['id'] ?? 0);
        }
        if ($needsMeituan) {
            $sourceIds['meituan_traffic_row_id'] = (int)($meituanTraffic['id'] ?? 0);
            if (is_array($meituanBusiness)) {
                $sourceIds['meituan_business_row_id'] = (int)($meituanBusiness['id'] ?? 0);
            }
        }
        $sourceRefs = [];
        if ($needsPms
            && is_array($pms)
            && (int)($pms['id'] ?? 0) > 0
        ) {
            $binding = is_array($pmsGate['binding'] ?? null)
                ? $pmsGate['binding']
                : [];
            $sourceRefs['pms'] = [
                'source' => 'dingdandao_pms',
                'record_id' => (int)($pms['id'] ?? 0),
                'business_date' => (string)($pms['business_date'] ?? $businessDate),
                'source_scope' => (string)($pms['source_scope'] ?? ''),
                'capture_method' => (string)($pms['capture_method'] ?? ''),
                'source_trace_id' => (string)($pms['source_trace_id'] ?? ''),
                'provider_hotel_id' => (string)($pms['provider_hotel_id'] ?? ''),
                'provider_hotel_name' => (string)($pms['provider_hotel_name'] ?? ''),
                'bound_provider_hotel_id' => (string)(
                    $binding['expected_provider_hotel_id'] ?? ''
                ),
                'bound_provider_hotel_name' => (string)(
                    $binding['expected_provider_hotel_name'] ?? ''
                ),
            ];
        }
        foreach ([
            'ctrip_traffic' => $ctripTraffic,
            'ctrip_rank' => $ctripRank,
            'qunar_traffic' => $qunarTraffic,
            'meituan_business' => $meituanBusiness,
            'meituan_traffic' => $meituanTraffic,
        ] as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $sourceRefs[$key] = $this->otaSourceReference($row, $businessDate);
        }
        $fingerprintInput = [
            'contract' => self::RENDER_CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'source_scope' => $sourceScope,
            'content_sections' => $contentSections,
            'template_mode' => $templateMode,
            'custom_title' => $templateMode === self::TEMPLATE_MODE_CUSTOM ? $customTitle : '',
            'custom_body' => $templateMode === self::TEMPLATE_MODE_CUSTOM ? $customBody : '',
            'facts' => $selectedFacts,
            'fact_derivations' => $factDerivations,
            'source_ids' => $sourceIds,
            'source_refs' => $sourceRefs,
            'blockers' => array_column($blockers, 'code'),
        ];
        $previewFingerprint = hash('sha256', $this->json($fingerprintInput));
        $gate = [
            'allowed' => $blockers === [],
            'status' => $blockers === [] ? 'formal_send_ready' : 'formal_send_blocked',
            'blockers' => $blockers,
            'warnings' => $warnings,
            'source_scope' => $sourceScope,
            'content_sections' => $contentSections,
        ];
        $payload = $blockers === []
            ? $this->renderPayload(
                trim($hotelName),
                $businessDate,
                $selectedFacts,
                $pms,
                $ctripTraffic,
                $ctripRank,
                $qunarTraffic,
                $meituanBusiness,
                $meituanTraffic,
                $sourceScope,
                $contentSections,
                $templateMode,
                $customTitle,
                $customBody
            )
            : null;

        return [
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'reason_code' => $blockers === []
                ? 'operating_daily_report_ready'
                : 'operating_daily_report_not_ready',
            'business_date' => $businessDate,
            'source_scope' => $sourceScope,
            'source_scope_label' => self::SOURCE_SCOPES[$sourceScope],
            'content_sections' => $contentSections,
            'content_template_mode' => $templateMode,
            'preview_fingerprint' => $previewFingerprint,
            'payload_fingerprint' => $payload === null
                ? $previewFingerprint
                : hash('sha256', $this->json($payload)),
            'render_contract_version' => self::RENDER_CONTRACT_VERSION,
            'formal_send_gate' => $gate,
            'facts' => $selectedFacts,
            'fact_derivations' => $factDerivations,
            'source_snapshot_ids' => $sourceIds,
            'source_snapshot_refs' => $sourceRefs,
            'operating_target_record_id' => 0,
            'snapshot_revision_no' => 0,
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, float|bool|null> $facts
     * @param array<string, mixed> $pms
     * @param array<string, mixed>|null $ctripTraffic
     * @param array<string, mixed>|null $ctripRank
     * @param array<string, mixed>|null $qunarTraffic
     * @param array<string, mixed>|null $meituanBusiness
     * @param array<string, mixed>|null $meituanTraffic
     * @return array{msgtype:string,text:array{content:string}}
     */
    private function renderPayload(
        string $hotelName,
        string $businessDate,
        array $facts,
        array $pms,
        ?array $ctripTraffic,
        ?array $ctripRank,
        ?array $qunarTraffic,
        ?array $meituanBusiness,
        ?array $meituanTraffic,
        string $sourceScope,
        array $contentSections,
        string $templateMode,
        string $customTitle,
        string $customBody
    ): array {
        if ($templateMode === self::TEMPLATE_MODE_CUSTOM) {
            return $this->renderCustomPayload(
                $hotelName,
                $businessDate,
                $facts,
                $customTitle,
                $customBody
            );
        }
        if ($sourceScope === 'combined') {
            return $this->renderCommonCombinedPayload(
                $hotelName,
                $businessDate,
                $facts,
                $contentSections
            );
        }
        if ($sourceScope === 'meituan') {
            return $this->renderMeituanPayload(
                $hotelName,
                $businessDate,
                $facts,
                $meituanBusiness,
                $meituanTraffic,
                $contentSections
            );
        }
        $ctripWindow = $this->observationWindow([$ctripTraffic, $ctripRank], 'ctrip');
        $qunarWindow = $this->observationWindow([$qunarTraffic], 'qunar');
        $otaWindow = $this->mergeWindows([$ctripWindow, $qunarWindow]);
        $pmsTime = $this->time((string)($pms['captured_at'] ?? ''));
        $meituanTime = $this->time((string)($meituanTraffic['snapshot_time'] ?? ''));
        $meituanLineage = $this->hasBoundLineage($meituanTraffic ?? [])
            ? '数据源与同步任务已绑定'
            : '历史采集链路未完整绑定';
        $sectionSet = array_fill_keys($contentSections, true);
        $title = match ($sourceScope) {
            'ctrip' => '携程渠道采集快照',
            'meituan' => '美团渠道采集快照',
            'dingdandao_pms' => '订单来了 PMS 经营播报',
            default => '今日经营数据汇总｜PMS＋OTA',
        };
        $lines = [
            $title,
            '门店：' . $hotelName,
            '业务日：' . $businessDate,
            '来源范围：' . (self::SOURCE_SCOPES[$sourceScope] ?? '未取得'),
            '',
        ];
        if (isset($sectionSet['pms_summary']) || isset($sectionSet['pms_efficiency'])) {
            $lines[] = 'PMS｜订单来了';
            if (isset($sectionSet['pms_summary'])) {
                $lines[] = '- 住宿客房房费：¥'
                    . number_format((float)$facts['pms_room_fee'], 2, '.', ',');
                $lines[] = '- 已售间夜：' . $this->integer($facts['pms_sold_room_nights']);
            }
            if (isset($sectionSet['pms_efficiency'])) {
                $lines[] = '- 可售房夜：'
                    . $this->integer($facts['pms_sellable_room_nights']) . '（派生）';
                $lines[] = '- 入住率：' . $this->percent($facts['pms_occupancy']);
                $lines[] = '- ADR：¥' . number_format((float)$facts['pms_adr'], 2, '.', ',');
                $lines[] = '- RevPAR：¥'
                    . number_format((float)$facts['pms_revpar'], 2, '.', ',');
            }
            $lines[] = '';
        }
        if (isset($sectionSet['ctrip_traffic']) || isset($sectionSet['ctrip_market'])) {
            $lines[] = '携程｜OTA 渠道（采集快照）';
            if (isset($sectionSet['ctrip_traffic'])) {
                $lines[] = '- APP 访客量：' . $this->integer($facts['ctrip_visitors'])
                    . '（上周同期 ' . $this->integer($facts['ctrip_last_week_visitors']) . '）';
                $lines[] = '- 预订订单：' . $this->integer($facts['ctrip_booking_orders']);
                $lines[] = '- 在店间夜：'
                    . $this->integer($facts['ctrip_in_house_room_nights']);
            }
            if (isset($sectionSet['ctrip_market'])) {
                $lines[] = '- 排名：' . $this->integer($facts['ctrip_realtime_rank'])
                    . '；竞争圈排名：' . $this->integer($facts['ctrip_competitor_rank'])
                    . '/' . $this->integer($facts['ctrip_competitor_total']);
                $lines[] = '- 起价：¥'
                    . number_format((float)$facts['ctrip_starting_price'], 2, '.', '')
                    . '（首页渠道卡片）';
            }
            $lines[] = '';
        }
        if (isset($sectionSet['qunar_traffic'])) {
            $lines[] = '去哪儿｜OTA 渠道（采集快照）';
            $lines[] = '- APP 访客量：' . $this->integer($facts['qunar_visitors'])
                . '（竞争圈平均 ' . $this->integer($facts['qunar_visitor_peer_avg'])
                . '，页面标记' . ($facts['qunar_visitor_lagging'] ? '落后' : '领先') . '）';
            $lines[] = '- 预订订单：' . $this->integer($facts['qunar_booking_orders']);
            $lines[] = '- APP 下单转化率：' . $this->percent($facts['qunar_conversion'])
                . '（竞争圈平均 ' . $this->percent($facts['qunar_conversion_peer_avg'])
                . '，页面标记' . ($facts['qunar_conversion_lagging'] ? '落后' : '领先') . '）';
            $lines[] = '';
        }
        if (isset($sectionSet['meituan_traffic']) || isset($sectionSet['meituan_conversion'])) {
            $lines[] = '美团｜OTA 渠道（采集快照）';
            if (isset($sectionSet['meituan_traffic'])) {
                $lines[] = '- 曝光人数：' . $this->integer($facts['meituan_exposure']);
                $lines[] = '- 浏览人数：' . $this->integer($facts['meituan_viewers']);
                $lines[] = '- 曝光→浏览转化率：'
                    . $this->percent($facts['meituan_exposure_view_conversion'])
                    . (($facts['meituan_exposure_view_conversion_derived'] ?? false)
                        ? '（同快照派生）'
                        : '');
            }
            if (isset($sectionSet['meituan_conversion'])) {
                $lines[] = '- 支付订单：' . $this->integer($facts['meituan_paid_orders']);
                $lines[] = '- 浏览→支付转化率：'
                    . $this->percent($facts['meituan_view_to_paid_conversion'])
                    . '（由支付订单÷浏览人数派生）';
            }
            $lines[] = '';
        }
        $lines[] = '来源证据：';
        if (isset($sectionSet['pms_summary']) || isset($sectionSet['pms_efficiency'])) {
            $lines[] = '- PMS：订单来了同店同日采集 #' . (int)($pms['id'] ?? 0)
                . '，' . ($pmsTime !== '' ? $pmsTime : '采集时间缺失') . '，已验真并回读';
        }
        if (isset($sectionSet['ctrip_traffic'])
            || isset($sectionSet['ctrip_market'])
            || isset($sectionSet['qunar_traffic'])
        ) {
            $sourceLabel = isset($sectionSet['qunar_traffic'])
                ? '携程/去哪儿'
                : '携程';
            $lines[] = '- ' . $sourceLabel . '：eBooking 授权页及同源 JSON，'
                . ($otaWindow !== '' ? $otaWindow : '观察时间缺失')
                . '，浏览器辅助采集并完成数据库回读';
        }
        if (isset($sectionSet['meituan_traffic']) || isset($sectionSet['meituan_conversion'])) {
            $lines[] = '- 美团：同日最新保存快照 #' . (int)($meituanTraffic['id'] ?? 0)
                . '，' . ($meituanTime !== '' ? $meituanTime : '采集时间缺失')
                . '，已回读；' . $meituanLineage;
        }
        $lines[] = '';
        if ($sourceScope === 'dingdandao_pms') {
            $lines[] = '事实边界：本条仅含订单来了 PMS 的全酒店住宿客房经营事实；'
                . '不包含餐饮、会议等其他收入。';
        } elseif ($sourceScope === 'ctrip') {
            $lines[] = '快照说明：OTA 渠道数据为平台采集快照，不代表发送时点状态；'
                . '以来源证据中的采集时间为准。';
            $lines[] = 'OTA 渠道边界：本条仅含携程及勾选的去哪儿渠道事实，'
                . '不代表全酒店经营结果。';
        } elseif ($sourceScope === 'meituan') {
            $lines[] = '快照说明：OTA 渠道数据为平台采集快照，不代表发送时点状态；'
                . '以来源证据中的采集时间为准。';
            $lines[] = 'OTA 渠道边界：本条仅含美团渠道事实，不代表全酒店经营结果。';
        } else {
            $lines[] = '快照说明：OTA 渠道数据为平台采集快照，不代表发送时点状态；'
                . '以来源证据中的采集时间为准。';
            $lines[] = 'PMS 说明：可售房夜由已售间夜与入住率派生。';
            $lines[] = 'OTA 渠道边界：携程、去哪儿、美团数据仅代表各自渠道，不代表全酒店；'
                . '全酒店经营事实以 PMS 为准。';
        }

        return [
            'msgtype' => 'text',
            'text' => ['content' => implode("\n", $lines)],
        ];
    }

    /**
     * Keep the user-facing Meituan broadcast as compact as the established
     * Ctrip format. Provenance and delivery state remain available in the
     * structured candidate/dispatch records instead of being repeated here.
     *
     * @param array<string, float|bool|null> $facts
     * @param array<string, mixed>|null $meituanBusiness
     * @param array<string, mixed>|null $meituanTraffic
     * @param list<string> $contentSections
     * @return array{msgtype:string,text:array{content:string}}
     */
    private function renderMeituanPayload(
        string $hotelName,
        string $businessDate,
        array $facts,
        ?array $meituanBusiness,
        ?array $meituanTraffic,
        array $contentSections
    ): array {
        $sectionSet = array_fill_keys($contentSections, true);
        $capturedAt = trim((string)(
            $meituanTraffic['snapshot_time']
            ?? $meituanBusiness['snapshot_time']
            ?? ''
        ));
        if (!$this->validDateTime($capturedAt)) {
            $capturedAt = '未返回';
        }
        $salesRoomNights = $facts['meituan_sales_room_nights'] ?? null;
        $salesAmount = $facts['meituan_sales_amount'] ?? null;
        $salesAvgPrice = $facts['meituan_sales_avg_price'] ?? null;
        $salesAvgDerived = false;
        if ($salesAvgPrice === null
            && $salesAmount !== null
            && $salesRoomNights !== null
            && (float)$salesRoomNights > 0
        ) {
            $salesAvgPrice = round((float)$salesAmount / (float)$salesRoomNights, 2);
            $salesAvgDerived = true;
        }

        $lines = [
            '美团今日实时数据',
            '门店：' . $hotelName,
            '业务日：' . $businessDate,
            '采集完成：' . $capturedAt,
            '',
            '美团｜OTA 渠道',
            '- 引流价：' . $this->optionalMoney($facts['meituan_lead_price'] ?? null),
            '- 销售间夜：' . $this->optionalInteger($salesRoomNights, ' 间夜'),
            '- 销售额：' . $this->optionalMoney($salesAmount),
            '- 销售均价：' . $this->optionalMoney($salesAvgPrice)
                . ($salesAvgDerived ? '（同快照派生）' : ''),
        ];
        if (isset($sectionSet['meituan_traffic'])) {
            $lines[] = '- 曝光人数：' . $this->integer($facts['meituan_exposure']);
            $lines[] = '- 浏览人数：' . $this->integer($facts['meituan_viewers']);
            $lines[] = '- 曝光→浏览转化率：'
                . $this->percent($facts['meituan_exposure_view_conversion'])
                . (($facts['meituan_exposure_view_conversion_derived'] ?? false)
                    ? '（同快照派生）'
                    : '');
        } elseif (isset($sectionSet['meituan_conversion'])) {
            $lines[] = '- 浏览人数：' . $this->integer($facts['meituan_viewers']);
        }
        if (isset($sectionSet['meituan_conversion'])) {
            $platformBrowseToPay = $this->meituanFlowRateIsBrowseToPay($meituanTraffic)
                ? $this->number($meituanTraffic ?? [], 'flow_rate')
                : null;
            $lines[] = '- 支付订单数：' . $this->integer($facts['meituan_paid_orders']);
            $lines[] = '- 浏览→支付转化率：'
                . $this->percent(
                    $platformBrowseToPay ?? $facts['meituan_view_to_paid_conversion']
                )
                . ($platformBrowseToPay === null ? '（支付订单数÷浏览人数）' : '');
        }
        return [
            'msgtype' => 'text',
            'text' => ['content' => implode("\n", $lines)],
        ];
    }

    /**
     * The common template is the user-approved WeChat layout. Data provenance
     * stays enforced by candidate() before rendering; this method changes only
     * the visible message structure.
     *
     * @param array<string, float|bool|null> $facts
     * @param list<string> $contentSections
     * @return array{msgtype:string,text:array{content:string}}
     */
    private function renderCommonCombinedPayload(
        string $hotelName,
        string $businessDate,
        array $facts,
        array $contentSections
    ): array {
        $sectionSet = array_fill_keys($contentSections, true);
        $lines = [
            '今日经营数据汇总｜PMS＋OTA',
            '门店：' . $hotelName,
            '业务日：' . $businessDate,
        ];
        if (isset($sectionSet['pms_summary'])
            || isset($sectionSet['pms_efficiency'])
        ) {
            $lines[] = 'PMS｜订单来了';
            if (isset($sectionSet['pms_summary'])) {
                $lines[] = '- 住宿客房房费：¥'
                    . number_format(
                        (float)$facts['pms_room_fee'],
                        2,
                        '.',
                        ','
                    );
                $lines[] = '- 已售间夜：'
                    . $this->integer($facts['pms_sold_room_nights']);
            }
            if (isset($sectionSet['pms_efficiency'])) {
                $lines[] = '- 可售房夜：'
                    . $this->integer($facts['pms_sellable_room_nights']);
                $lines[] = '- 入住率：'
                    . $this->percent($facts['pms_occupancy']);
                $lines[] = '- ADR：¥'
                    . number_format((float)$facts['pms_adr'], 2, '.', ',');
                $lines[] = '- RevPAR：¥'
                    . number_format((float)$facts['pms_revpar'], 2, '.', ',');
            }
        }
        if (isset($sectionSet['ctrip_traffic'])
            || isset($sectionSet['ctrip_market'])
            || isset($sectionSet['qunar_traffic'])
            || isset($sectionSet['meituan_traffic'])
            || isset($sectionSet['meituan_conversion'])
        ) {
            $lines[] =
                '数据说明：OTA 渠道数据为平台采集快照，不代表发送时点状态；以采集时间为准。';
        }
        if (isset($sectionSet['ctrip_traffic'])
            || isset($sectionSet['ctrip_market'])
        ) {
            $lines[] = '携程｜OTA 渠道（采集快照）';
            if (isset($sectionSet['ctrip_traffic'])) {
                $lines[] = '- APP 访客量：'
                    . $this->integer($facts['ctrip_visitors'])
                    . '（上周同期 '
                    . $this->integer($facts['ctrip_last_week_visitors'])
                    . '）';
                $lines[] = '- 预订订单：'
                    . $this->integer($facts['ctrip_booking_orders']);
                $lines[] = '- 在店间夜：'
                    . $this->integer($facts['ctrip_in_house_room_nights']);
            }
            if (isset($sectionSet['ctrip_market'])) {
                $lines[] = '- 排名：'
                    . $this->integer($facts['ctrip_realtime_rank']);
                $lines[] = '- 起价：¥' . number_format(
                    (float)$facts['ctrip_starting_price'],
                    2,
                    '.',
                    ''
                );
            }
        }
        if (isset($sectionSet['qunar_traffic'])) {
            $lines[] = '去哪儿｜OTA 渠道（采集快照）';
            $lines[] = '- APP 访客量：'
                . $this->integer($facts['qunar_visitors'])
                . '（竞争圈平均 '
                . $this->integer($facts['qunar_visitor_peer_avg'])
                . '）';
            $lines[] = '- 预订订单：'
                . $this->integer($facts['qunar_booking_orders']);
            $lines[] = '- APP 下单转化率：'
                . $this->percent($facts['qunar_conversion'])
                . '（竞争圈平均 '
                . $this->percent($facts['qunar_conversion_peer_avg'])
                . '）';
        }
        if (isset($sectionSet['meituan_traffic'])
            || isset($sectionSet['meituan_conversion'])
        ) {
            $lines[] = '美团｜OTA 渠道（采集快照）';
            if (isset($sectionSet['meituan_traffic'])) {
                $lines[] = '- 曝光人数：'
                    . $this->integer($facts['meituan_exposure']);
                $lines[] = '- 浏览人数：'
                    . $this->integer($facts['meituan_viewers']);
                $lines[] = '- 曝光→浏览转化率：'
                    . $this->percent(
                        $facts['meituan_exposure_view_conversion']
                    )
                    . (($facts['meituan_exposure_view_conversion_derived'] ?? false)
                        ? '（同快照派生）'
                        : '');
            }
            if (isset($sectionSet['meituan_conversion'])) {
                $lines[] = '- 支付订单：'
                    . $this->integer($facts['meituan_paid_orders']);
                $lines[] = '- 浏览→支付转化率：'
                    . $this->percent(
                        $facts['meituan_view_to_paid_conversion']
                    );
            }
        }
        return [
            'msgtype' => 'text',
            'text' => ['content' => implode("\n", $lines)],
        ];
    }

    /**
     * @param array<string, float|bool|null> $facts
     * @return array{msgtype:string,text:array{content:string}}
     */
    private function renderCustomPayload(
        string $hotelName,
        string $businessDate,
        array $facts,
        string $customTitle,
        string $customBody
    ): array {
        self::assertCustomTemplate($customTitle, $customBody);
        $replacements = [
            '{酒店名称}' => $hotelName,
            '{经营日期}' => $businessDate,
            '{住宿客房房费}' => '¥' . number_format((float)$facts['pms_room_fee'], 2, '.', ','),
            '{已售间夜}' => $this->integer($facts['pms_sold_room_nights']),
            '{可售房夜}' => $this->integer($facts['pms_sellable_room_nights']),
            '{入住率}' => $this->percent($facts['pms_occupancy']),
            '{ADR}' => '¥' . number_format((float)$facts['pms_adr'], 2, '.', ','),
            '{RevPAR}' => '¥' . number_format((float)$facts['pms_revpar'], 2, '.', ','),
            '{携程访客量}' => $this->integer($facts['ctrip_visitors']),
            '{携程实时访客量}' => $this->integer($facts['ctrip_visitors']),
            '{携程上周同期访客量}' => $this->integer($facts['ctrip_last_week_visitors']),
            '{携程预订订单}' => $this->integer($facts['ctrip_booking_orders']),
            '{携程实时预订订单}' => $this->integer($facts['ctrip_booking_orders']),
            '{携程在店间夜}' => $this->integer($facts['ctrip_in_house_room_nights']),
            '{携程实时在店间夜}' => $this->integer($facts['ctrip_in_house_room_nights']),
            '{携程排名}' => $this->integer($facts['ctrip_realtime_rank']),
            '{携程实时排名}' => $this->integer($facts['ctrip_realtime_rank']),
            '{携程竞争圈排名}' => $this->integer($facts['ctrip_competitor_rank']),
            '{携程竞争圈总量}' => $this->integer($facts['ctrip_competitor_total']),
            '{携程起价}' => '¥'
                . number_format((float)$facts['ctrip_starting_price'], 2, '.', ''),
            '{携程实时起价}' => '¥'
                . number_format((float)$facts['ctrip_starting_price'], 2, '.', ''),
            '{去哪儿访客量}' => $this->integer($facts['qunar_visitors']),
            '{去哪儿实时访客量}' => $this->integer($facts['qunar_visitors']),
            '{去哪儿竞争圈平均访客量}' => $this->integer($facts['qunar_visitor_peer_avg']),
            '{去哪儿预订订单}' => $this->integer($facts['qunar_booking_orders']),
            '{去哪儿实时预订订单}' => $this->integer($facts['qunar_booking_orders']),
            '{去哪儿下单转化率}' => $this->percent($facts['qunar_conversion']),
            '{去哪儿实时下单转化率}' => $this->percent($facts['qunar_conversion']),
            '{去哪儿竞争圈平均转化率}' => $this->percent(
                $facts['qunar_conversion_peer_avg']
            ),
            '{美团曝光人数}' => $this->integer($facts['meituan_exposure']),
            '{美团浏览人数}' => $this->integer($facts['meituan_viewers']),
            '{美团曝光浏览转化率}' => $this->percent(
                $facts['meituan_exposure_view_conversion']
            ) . (($facts['meituan_exposure_view_conversion_derived'] ?? false)
                ? '（同快照派生）'
                : ''),
            '{美团支付订单}' => $this->integer($facts['meituan_paid_orders']),
            '{美团浏览支付转化率}' => $this->percent(
                $facts['meituan_view_to_paid_conversion']
            ),
        ];
        return [
            'msgtype' => 'text',
            'text' => [
                'content' => trim(strtr($customTitle, $replacements))
                    . "\n"
                    . trim(strtr($customBody, $replacements)),
            ],
        ];
    }

    /** @param list<string> $contentSections @return list<string> */
    private function normalizeContentSections(
        string $sourceScope,
        array $contentSections
    ): array {
        $allowed = self::SOURCE_SECTIONS[$sourceScope] ?? [];
        if ($contentSections === []) {
            return $allowed;
        }
        $allowedSet = array_fill_keys($allowed, true);
        $normalized = [];
        foreach ($contentSections as $section) {
            $section = trim((string)$section);
            if ($section === '' || !isset($allowedSet[$section])) {
                throw new \InvalidArgumentException('operating_daily_content_section_invalid');
            }
            $normalized[$section] = $section;
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('operating_daily_content_sections_required');
        }
        return array_values($normalized);
    }

    /** @param list<string> $contentSections @return list<string> */
    private function requiredFactKeys(array $contentSections): array
    {
        $keys = [];
        foreach ($contentSections as $section) {
            foreach (self::SECTION_FACTS[$section] ?? [] as $field) {
                $keys[$field] = $field;
            }
        }
        return array_values($keys);
    }

    /** @return array<string, mixed> */
    private function resolvePms(int $tenantId, int $hotelId, string $businessDate): array
    {
        if (is_callable($this->pmsResolver)) {
            return (array)call_user_func($this->pmsResolver, $tenantId, $hotelId, $businessDate);
        }
        return ($this->pmsCaptures ?? new DingdandaoOperatingTargetCaptureService())
            ->latest($tenantId, $hotelId, $businessDate);
    }

    /** @return array<string, mixed>|null */
    private function resolveRow(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $source,
        string $dataType,
        ?string $dimension,
        array $requiredMetricKeys = []
    ): ?array {
        $failureKey = $this->rowResolutionKey(
            $source,
            $dataType,
            $dimension
        );
        if (is_callable($this->rowResolver)) {
            $row = call_user_func(
                $this->rowResolver,
                $tenantId,
                $hotelId,
                $businessDate,
                $source,
                $dataType,
                $dimension
            );
            if (!is_array($row)) {
                return null;
            }
            $rejection = $this->otaRowRejectionReason(
                $row,
                $tenantId,
                $hotelId,
                $businessDate,
                $source,
                $dataType,
                $dimension,
                $requiredMetricKeys
            );
            if ($rejection === '') {
                return $row;
            }
            $this->rowResolutionFailures[$failureKey] = $rejection;
            return null;
        }
        try {
            $periodRoles = $this->otaPeriodRoles($businessDate);
            foreach ($periodRoles as [$period, $isFinal]) {
                $query = Db::name('online_daily_data')
                    ->where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->where('data_date', $businessDate)
                    ->where('source', $source)
                    ->where('data_type', $dataType)
                    ->where('data_period', $period)
                    ->where('is_final', $isFinal)
                    ->where('readback_verified', 1);
                if ($dimension !== null) {
                    $query->where('dimension', $dimension);
                } else {
                    $query->where('dimension', '');
                }
                $rows = $query
                    ->order('snapshot_time', 'desc')
                    ->order('id', 'desc')
                    ->limit(20)
                    ->select()
                    ->toArray();
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $rejection = $this->otaRowRejectionReason(
                        $row,
                        $tenantId,
                        $hotelId,
                        $businessDate,
                        $source,
                        $dataType,
                        $dimension,
                        $requiredMetricKeys
                    );
                    if ($rejection === '') {
                        return $row;
                    }
                    $this->rowResolutionFailures[$failureKey] ??= $rejection;
                }
            }
            return null;
        } catch (\Throwable) {
            $this->rowResolutionFailures[$failureKey] =
                'trusted_snapshot_query_failed';
            return null;
        }
    }

    /**
     * @return list<array{0:string,1:int}>
     */
    private function otaPeriodRoles(string $businessDate): array
    {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        if ($businessDate > $today) {
            return [];
        }
        if ($businessDate === $today) {
            return [['realtime_snapshot', 0]];
        }
        return [
            ['historical_daily', 1],
            ['realtime_snapshot', 0],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $requiredMetricKeys
     */
    private function otaRowRejectionReason(
        array $row,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $source,
        string $dataType,
        ?string $dimension,
        array $requiredMetricKeys
    ): string {
        if ((int)($row['tenant_id'] ?? 0) !== $tenantId
            || (int)($row['system_hotel_id'] ?? 0) !== $hotelId
            || trim((string)($row['data_date'] ?? '')) !== $businessDate
            || strtolower(trim((string)(
                $row['source'] ?? $row['platform'] ?? ''
            ))) !== $source
            || strtolower(trim((string)($row['data_type'] ?? ''))) !== $dataType
            || trim((string)($row['dimension'] ?? '')) !== trim((string)$dimension)
        ) {
            return 'snapshot_scope_mismatch';
        }
        if ((int)($row['readback_verified'] ?? 0) !== 1) {
            return 'readback_not_verified';
        }
        $periodAllowed = false;
        foreach ($this->otaPeriodRoles($businessDate) as [$period, $isFinal]) {
            if (strtolower(trim((string)($row['data_period'] ?? ''))) === $period
                && (int)($row['is_final'] ?? -1) === $isFinal
            ) {
                $periodAllowed = true;
                break;
            }
        }
        if (!$periodAllowed) {
            return 'snapshot_period_role_invalid';
        }
        $ingestionMethod = strtolower(trim((string)(
            $row['ingestion_method'] ?? ''
        )));
        if (!in_array(
            $ingestionMethod,
            self::TRUSTED_OTA_INGESTION_METHODS,
            true
        )) {
            return 'ingestion_method_untrusted';
        }
        $validationStatus = strtolower(trim((string)(
            $row['validation_status'] ?? ''
        )));
        if (!in_array(
            $validationStatus,
            self::TRUSTED_OTA_VALIDATION_STATUSES,
            true
        )) {
            return 'validation_status_untrusted';
        }
        foreach (['status', 'save_status'] as $field) {
            $status = strtolower(trim((string)($row[$field] ?? '')));
            if (in_array(
                $status,
                self::BLOCKING_OTA_AUXILIARY_STATUSES,
                true
            )) {
                return $field . '_untrusted';
            }
        }
        $flags = strtolower($this->flattenTrustFlags(
            $row['validation_flags'] ?? []
        ));
        foreach (self::BLOCKING_OTA_FLAG_FRAGMENTS as $fragment) {
            if ($flags !== '' && str_contains($flags, $fragment)) {
                return 'blocking_validation_flag';
            }
        }
        if (!$this->hasBoundLineage($row)) {
            return 'bound_lineage_missing';
        }
        $raw = $this->raw($row);
        $rowTrace = trim((string)($row['source_trace_id'] ?? ''));
        $rawTrace = trim((string)(
            $raw['source_trace_id']
            ?? $raw['capture_evidence']['source_trace_id']
            ?? ''
        ));
        if ($rawTrace === '' || !hash_equals($rowTrace, $rawTrace)) {
            return 'raw_source_trace_mismatch';
        }
        $providerHotelId = trim((string)(
            $row['platform_hotel_id']
            ?? $row['hotel_id']
            ?? $raw['platform_hotel_id']
            ?? $raw['hotel_id']
            ?? $raw['poi_id']
            ?? ''
        ));
        if ($providerHotelId === '') {
            return 'platform_hotel_binding_missing';
        }
        $requiredMetricKeys = array_values(array_unique(array_filter(
            array_map(
                static fn(mixed $value): string =>
                    strtolower(trim((string)$value)),
                $requiredMetricKeys
            ),
            static fn(string $value): bool => $value !== ''
        )));
        if ($requiredMetricKeys !== []) {
            $metricStatus = OnlineDataFieldFactService::buildMetricStatus(
                $row,
                $raw,
                $requiredMetricKeys
            );
            if (($metricStatus['status'] ?? '') !== 'ready') {
                return 'required_field_facts_untrusted';
            }
        }
        return '';
    }

    private function flattenTrustFlags(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                return trim($value);
            }
        }
        if (!is_array($value)) {
            return trim((string)$value);
        }
        $flat = [];
        array_walk_recursive(
            $value,
            static function (mixed $item) use (&$flat): void {
                if (is_scalar($item)) {
                    $flat[] = trim((string)$item);
                }
            }
        );
        return implode(' ', array_filter($flat));
    }

    private function rowResolutionKey(
        string $source,
        string $dataType,
        ?string $dimension
    ): string {
        return strtolower(trim($source))
            . '|'
            . strtolower(trim($dataType))
            . '|'
            . trim((string)$dimension);
    }

    private function rowResolutionFailureFor(string $rowKey): string
    {
        $lookup = match ($rowKey) {
            'ctrip_traffic' => ['ctrip', 'traffic', 'realtime:ctrip'],
            'ctrip_rank' => ['ctrip', 'peer_rank', 'realtime:ctrip:rank'],
            'qunar_traffic' => ['ctrip', 'traffic', 'realtime:qunar'],
            'meituan_traffic' => ['meituan', 'traffic', null],
            default => null,
        };
        if (!is_array($lookup)) {
            return '';
        }
        return $this->rowResolutionFailures[
            $this->rowResolutionKey($lookup[0], $lookup[1], $lookup[2])
        ] ?? '';
    }

    /**
     * @param array<string, mixed> $pms
     * @return array{
     *   allowed:bool,
     *   reason_code:string,
     *   message:string,
     *   binding:array<string,mixed>
     * }
     */
    private function pmsGate(
        array $pms,
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate
    ): array {
        try {
            $binding = $this->resolvePmsBinding(
                $tenantId,
                $hotelId,
                $hotelName
            );
        } catch (\Throwable) {
            return [
                'allowed' => false,
                'reason_code' => 'operating_daily_pms_binding_unavailable',
                'message' => 'PMS 当前门店绑定读取失败，本次未生成发送内容。',
                'binding' => [],
            ];
        }

        $expectedProviderHotelId = trim((string)(
            $binding['expected_provider_hotel_id'] ?? ''
        ));
        $expectedProviderHotelName = trim((string)(
            $binding['expected_provider_hotel_name'] ?? ''
        ));
        if (($binding['configured'] ?? false) !== true
            || $expectedProviderHotelId === ''
            || $expectedProviderHotelName === ''
        ) {
            return [
                'allowed' => false,
                'reason_code' => 'operating_daily_pms_binding_missing',
                'message' => 'PMS 当前门店绑定不完整，请先确认订单来了门店 ID 与名称。',
                'binding' => $binding,
            ];
        }

        if ((int)($pms['id'] ?? 0) <= 0) {
            return [
                'allowed' => false,
                'reason_code' => 'operating_daily_pms_not_verified',
                'message' =>
                    'PMS 同店同日事实未通过身份、对账和数据库回读校验。',
                'binding' => $binding,
            ];
        }

        $capturedProviderHotelId = trim((string)(
            $pms['provider_hotel_id'] ?? ''
        ));
        $capturedProviderHotelName = trim((string)(
            $pms['provider_hotel_name'] ?? ''
        ));
        if ($capturedProviderHotelId === ''
            || !hash_equals(
                $expectedProviderHotelId,
                $capturedProviderHotelId
            )
            || !$this->sameProviderHotelName(
                $expectedProviderHotelName,
                $capturedProviderHotelName
            )
        ) {
            return [
                'allowed' => false,
                'reason_code' =>
                    'operating_daily_pms_provider_identity_mismatch',
                'message' => 'PMS 快照门店与当前订单来了绑定不一致，本次未发送。',
                'binding' => $binding,
            ];
        }

        try {
            $validation = (
                $this->collectionResults
                    ?? new CollectionResultContractService()
            )->validateDingdandaoCaptureClaim($pms, [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'platform_hotel_id' => $expectedProviderHotelId,
            ]);
            if (($validation['allowed'] ?? false) !== true) {
                return [
                    'allowed' => false,
                    'reason_code' => 'operating_daily_pms_not_verified',
                    'message' =>
                        'PMS 同店同日事实未通过身份、对账和数据库回读校验。',
                    'binding' => $binding,
                ];
            }
        } catch (\Throwable) {
            return [
                'allowed' => false,
                'reason_code' => 'operating_daily_pms_not_verified',
                'message' =>
                    'PMS 同店同日事实未通过身份、对账和数据库回读校验。',
                'binding' => $binding,
            ];
        }

        return [
            'allowed' => true,
            'reason_code' => 'operating_daily_pms_verified',
            'message' => '',
            'binding' => $binding,
        ];
    }

    /** @return array<string, mixed> */
    private function resolvePmsBinding(
        int $tenantId,
        int $hotelId,
        string $hotelName
    ): array {
        if (is_callable($this->pmsBindingResolver)) {
            return (array)call_user_func(
                $this->pmsBindingResolver,
                $tenantId,
                $hotelId,
                $hotelName
            );
        }
        return (new DingdandaoPmsIntegrationService())->captureExpectation(
            $tenantId,
            $hotelId,
            $hotelName
        );
    }

    private function sameProviderHotelName(string $left, string $right): bool
    {
        $normalize = static fn(string $value): string =>
            mb_strtolower(
                preg_replace('/\s+/u', '', trim($value)) ?? '',
                'UTF-8'
            );
        $left = $normalize($left);
        $right = $normalize($right);
        return $left !== ''
            && $right !== ''
            && hash_equals($left, $right);
    }

    /** @param array<string, mixed> $row */
    private function hasBoundLineage(array $row): bool
    {
        return (int)($row['data_source_id'] ?? 0) > 0
            && (int)($row['sync_task_id'] ?? 0) > 0
            && trim((string)($row['source_trace_id'] ?? '')) !== '';
    }

    /** @param array<string, mixed> $row @return array<string, int|string|null> */
    private function otaSourceReference(array $row, string $businessDate): array
    {
        return [
            'source' => strtolower(trim((string)($row['source'] ?? $row['platform'] ?? ''))),
            'record_id' => (int)($row['id'] ?? 0),
            'business_date' => (string)($row['data_date'] ?? $businessDate),
            'data_type' => (string)($row['data_type'] ?? ''),
            'dimension' => (string)($row['dimension'] ?? ''),
            'data_source_id' => (int)($row['data_source_id'] ?? 0),
            'sync_task_id' => (int)($row['sync_task_id'] ?? 0),
            'source_trace_id' => (string)($row['source_trace_id'] ?? ''),
        ];
    }

    /** @param array<string, mixed>|null $row @return array<string, mixed> */
    private function raw(?array $row): array
    {
        if (!is_array($row)) {
            return [];
        }
        $value = $row['raw_data'] ?? [];
        $decoded = is_array($value) ? $value : json_decode((string)$value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $normalizedRow = $decoded['row'] ?? null;
        if (!is_array($normalizedRow)) {
            return $decoded;
        }
        $nestedRaw = $normalizedRow['raw_data'] ?? null;
        if (is_string($nestedRaw)) {
            $nestedRaw = json_decode($nestedRaw, true);
        }
        if (!is_array($nestedRaw)) {
            $nestedRaw = [];
        }

        // Persistence keeps normalized collector fields under raw_data.row.
        // Flatten that envelope for report readers while retaining top-level
        // lineage, field facts and source-surface evidence.
        return array_replace($decoded, $nestedRaw, $normalizedRow);
    }

    /** @param array<string, mixed>|null $row @return array<string, mixed> */
    private function rawSection(?array $row, string $key): array
    {
        $raw = $this->raw($row);
        return is_array($raw[$key] ?? null) ? $raw[$key] : [];
    }

    /** @param array<string, mixed>|null $row */
    private function meituanFlowRateIsBrowseToPay(?array $row): bool
    {
        foreach ((array)($this->raw($row)['field_facts'] ?? []) as $fact) {
            if (!is_array($fact)
                || (string)($fact['metric_key'] ?? '') !== 'flow_rate'
            ) {
                continue;
            }
            $sourcePath = strtolower(trim((string)($fact['source_path'] ?? '')));
            return str_contains($sourcePath, 'browse_pay')
                || str_contains($sourcePath, 'payorderperintention')
                || str_contains($sourcePath, 'browsepayrate');
        }
        return false;
    }

    /**
     * Business and traffic cards may update independently. Only let business
     * metrics join the compact broadcast when both rows prove one capture.
     *
     * @param array<string, mixed>|null $business
     * @param array<string, mixed>|null $traffic
     */
    private function sameMeituanSnapshot(?array $business, ?array $traffic): bool
    {
        if (!is_array($business) || !is_array($traffic)) {
            return false;
        }
        $businessTaskId = (int)($business['sync_task_id'] ?? 0);
        $trafficTaskId = (int)($traffic['sync_task_id'] ?? 0);
        if ($businessTaskId > 0 || $trafficTaskId > 0) {
            return $businessTaskId > 0
                && $trafficTaskId > 0
                && $businessTaskId === $trafficTaskId;
        }
        $businessTime = trim((string)($business['snapshot_time'] ?? ''));
        $trafficTime = trim((string)($traffic['snapshot_time'] ?? ''));
        return $this->validDateTime($businessTime)
            && $this->validDateTime($trafficTime)
            && $businessTime === $trafficTime;
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @param list<string> $keys
     */
    private function firstNumber(array $sources, array $keys): ?float
    {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                $value = $this->number($source, $key);
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }

    /**
     * Platform response fields may be percentage-point numbers or explicit
     * percentage strings. This never derives a rate from exposure and views.
     *
     * @param list<array<string, mixed>> $sources
     * @param list<string> $keys
     */
    private function firstPercentNumber(array $sources, array $keys): ?float
    {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $source)) {
                    continue;
                }
                $raw = $source[$key];
                $explicitPercent = is_string($raw) && str_contains($raw, '%');
                if (is_string($raw)) {
                    $raw = str_replace([',', '%', ' '], '', trim($raw));
                }
                if (!is_numeric($raw)) {
                    continue;
                }
                $value = (float)$raw;
                $isNormalizedPercentagePoint = in_array(
                    $key,
                    ['exposure_to_browse_rate', 'exposureToBrowseRate', 'expose_visit_rate'],
                    true
                );
                if (!$explicitPercent
                    && !$isNormalizedPercentagePoint
                    && abs($value) > 0
                    && abs($value) <= 1
                ) {
                    $value *= 100;
                }
                return round($value, 2);
            }
        }
        return null;
    }

    /** @param array<string, mixed> $values */
    private function number(array $values, string $key): ?float
    {
        if (!array_key_exists($key, $values) || !is_numeric($values[$key])) {
            return null;
        }
        return (float)$values[$key];
    }

    /** @param array<string, mixed> $values */
    private function boolean(array $values, string $key): ?bool
    {
        if (!array_key_exists($key, $values) || !is_bool($values[$key])) {
            return null;
        }
        return $values[$key];
    }

    /**
     * @param list<array<string, mixed>|null> $rows
     */
    private function observationWindow(array $rows, string $channel): string
    {
        $times = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ((array)($this->raw($row)['source_surfaces'] ?? []) as $surface) {
                if (!is_array($surface)
                    || (string)($surface['channel'] ?? '') !== $channel
                    || !$this->validDateTime((string)($surface['observed_at'] ?? ''))
                ) {
                    continue;
                }
                $times[] = (string)$surface['observed_at'];
            }
            if ($times === [] && $this->validDateTime((string)($row['snapshot_time'] ?? ''))) {
                $times[] = (string)$row['snapshot_time'];
            }
        }
        if ($times === []) {
            return '';
        }
        sort($times);
        $first = $this->time($times[0]);
        $last = $this->time($times[count($times) - 1]);
        return $first === $last ? $first : $first . '–' . $last;
    }

    /** @param list<string> $windows */
    private function mergeWindows(array $windows): string
    {
        $times = [];
        foreach ($windows as $window) {
            foreach (preg_split('/–/u', $window) ?: [] as $time) {
                if (preg_match('/^\d{2}:\d{2}(?::\d{2})?$/D', $time) === 1) {
                    $times[] = $time;
                }
            }
        }
        if ($times === []) {
            return '';
        }
        sort($times);
        return $times[0] === $times[count($times) - 1]
            ? $times[0]
            : $times[0] . '–' . $times[count($times) - 1];
    }

    private function validDateTime(string $value): bool
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value) instanceof DateTimeImmutable;
    }

    private function time(string $value): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $parsed instanceof DateTimeImmutable ? $parsed->format('H:i:s') : '';
    }

    private function date(string $value): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== trim($value)) {
            throw new \InvalidArgumentException('operating_daily_business_date_invalid');
        }
        return $parsed->format('Y-m-d');
    }

    private function integer(mixed $value): string
    {
        return (string)(int)round((float)$value);
    }

    private function optionalInteger(mixed $value, string $suffix = ''): string
    {
        return $value === null ? '未返回' : $this->integer($value) . $suffix;
    }

    private function optionalMoney(mixed $value): string
    {
        return $value === null
            ? '未返回'
            : '¥' . number_format((float)$value, 2, '.', ',');
    }

    private function percent(mixed $value): string
    {
        $number = number_format((float)$value, 2, '.', '');
        return rtrim(rtrim($number, '0'), '.') . '%';
    }

    /** @return array{code:string,message:string} */
    private function blocker(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /**
     * @param list<array{code:string,message:string}> $blockers
     * @return list<array{code:string,message:string}>
     */
    private function uniqueBlockers(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker['code']] = $blocker;
        }
        return array_values($unique);
    }

    private function json(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($json)) {
            throw new \RuntimeException('operating_daily_json_encode_failed');
        }
        return $json;
    }
}
