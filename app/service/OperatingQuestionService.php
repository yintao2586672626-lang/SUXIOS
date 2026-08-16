<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Persists one hotel-scoped operating question and the exact saved evidence
 * used to answer it. A deterministic evidence answer is always built first;
 * an optional grounded AI generator may explain that same packet without
 * changing scope or gaining any write capability.
 */
final class OperatingQuestionService
{
    public const TABLE = 'hotel_operating_questions';
    public const MODEL_RESPONSE_REGISTRY_TABLE = 'hotel_operating_question_model_responses';
    public const CONTRACT_VERSION = 'hotel_operating_question.v2';
    public const METRIC_INTENT_CONTRACT_VERSION = 'operating_question_metric_intent.v2';

    /** @var list<string> */
    private const PLATFORMS = ['ctrip', 'meituan', 'qunar', 'all_ota'];

    /** @var list<string> */
    private const ALL_OTA_REQUIRED_PLATFORMS = ['ctrip', 'meituan'];

    /** @var list<string> */
    private const FACT_METRIC_FIELDS = [
        'amount',
        'quantity',
        'book_order_num',
        'comment_score',
        'qunar_comment_score',
        'data_value',
        'list_exposure',
        'detail_exposure',
        'flow_rate',
        'order_filling_num',
        'order_submit_num',
    ];

    /** @var list<string> */
    private const SUPPORTED_CURRENCY_CODES = [
        'CNY', 'USD', 'HKD', 'MOP', 'TWD', 'JPY', 'KRW', 'EUR', 'GBP', 'SGD', 'THB', 'MYR', 'AUD', 'CAD',
    ];

    /** @var list<string> */
    private const SUPPORTED_NON_CURRENCY_UNITS = [
        'percent', 'ratio_0_1', 'score_5_point', 'exposure_count', 'order_count',
        'count', 'room_night_count', 'visitor_count',
    ];

    /** @var array<string,list<string>> */
    private const QUESTION_PLATFORM_TERMS = [
        'ctrip' => ['携程', 'ctrip'],
        'meituan' => ['美团', 'meituan'],
        'qunar' => ['去哪儿', '去哪', 'qunar'],
        'feizhu' => ['飞猪', 'fliggy', 'alitrip'],
        'tujia' => ['途家', 'tujia'],
        'tongcheng' => ['同程', '艺龙', 'elong', 'ly.com'],
        'douyin' => ['抖音', 'douyin'],
        'booking' => ['booking.com', 'booking'],
        'agoda' => ['agoda'],
    ];

    /** @var list<string> */
    private const UNSUPPORTED_QUESTION_SEMANTIC_TERMS = [
        '退款', '退订', '退单', '取消', '未支付', '待支付', '未付款', '待付款', '支付失败', '付款失败',
        '失败订单', '拒付', '无效订单', '未成交',
        '净', '税', '扣佣', '佣金', '结算后收入', '到手收入', '利润', '成本',
        '预测', '预计', '预估', '预算', '目标', '剩余', '库存', '可售',
        '平均', '均值', '均价', '人均', '每单', '每间夜', '客单价', '房均', '日均',
        '占比', '比例', '百分之', '同比', '环比', '增长率', '变化率', '成功率', '支付率', '失败率', '增幅', '降幅',
        '除以', '相除', '÷', '总', '合计', '累计', '汇总', '之和', '一共', '加起来', '合起来',
        '趋势', '最高', '最低', '最大', '最小', '排名', 'top',
        '比', '差值', '相差', '差多少', '高于', '低于', '多于', '少于', '超过', '不及', '持平',
        '增长', '下降', '上月', '上周', '上季度', '上年', '去年', '同期', '前期', '历史',
        '昨日', '昨天', '前天', '本周', '本月', '本季度', '今年', '近7天', '近30天',
        '不要看', '不要', '不看', '不用看', '别看', '别回答', '排除', '忽略', '跳过', '而是', '而非',
        '只看', '只问', '仅看', '只告诉', '仅告诉', '改问', '改成', '更正', '纠正', '不对', '错了', '说错了', '并非',
        '改为', '改口', '才对', '算了', '还是', '除了', '只需要', '呃不', '哦不', '噢不',
        '别管', '只说', '我说错了', '说错',
        '不是我要问', '我问的不是', '我要问的是',
        '为什么', '原因', '导致', '怎么回事',
        '减去', '相减', '做差', '减', '加上', '相加', '加和', '求和', '合并计算',
        '乘以', '相乘', '除以', '相除', '独立访客',
    ];

    /** @var list<string> */
    private const UNSUPPORTED_QUESTION_SEMANTIC_PATTERNS = [
        '/(?:^|[，,。；;\s])不是.{0,24}(?:[，,。；;\s]|而是|改成|改问)/u',
        '/(?:总|总共).{0,8}(?:订单|曝光|间夜|房晚|金额|收入|营收|评分|填单|提交)/u',
        '/(?:订单|曝光|间夜|房晚|金额|收入|营收|评分|填单|提交).{0,8}(?:总数|总量|总额)/u',
        '/(?:比|较|相比).{0,12}(?:上月|上周|上季|上年|去年|同期|前(?:一|个)?(?:日|天|周|月))/u',
        '/(?:上月|上周|上季|上年|去年|同期|前(?:一|个)?(?:日|天|周|月)).{0,12}(?:多|少|增加|减少|提升|降低|变化|差)/u',
        '/(?:最近.{0,12}(?:订单|曝光|间夜|房晚|金额|收入|营收|评分|填单|提交|转化率)|(?:订单|曝光|间夜|房晚|金额|收入|营收|评分|填单|提交|转化率).{0,12}最近)/u',
        '~(?:收入|营收|销售额|成交额|订单金额|间夜|房晚|订单数|订单量|曝光量|评分|转化率)\s*[/／*×+＋-]\s*(?:收入|营收|销售额|成交额|订单金额|间夜|房晚|订单数|订单量|曝光量|评分|转化率)~u',
    ];

    /** @var list<string> */
    private const LIST_EXPOSURE_COUNT_SOURCE_KEYS = ['mt_exposure', 'exposure_count', 'exposurecount'];

    /** @var list<string> */
    private const LIST_EXPOSURE_VISITOR_SOURCE_KEYS = [
        'exposure_users', 'exposureusers', 'exposureuv', 'exposure_uv', 'listexposure', 'list_exposure',
    ];

    /** @var list<string> */
    private const DETAIL_EXPOSURE_COUNT_SOURCE_KEYS = [
        'clicks', 'click', 'click_count', 'clickcount', 'page_views', 'pageviews', 'pv', 'views',
    ];

    /** @var list<string> */
    private const DETAIL_EXPOSURE_VISITOR_SOURCE_KEYS = [
        'mt_intention_uv', 'intentionuv', 'intention_uv', 'detailvisitors', 'detail_visitors',
        'detailexposure', 'detail_exposure', 'uniquevisitors', 'unique_visitors',
        'visitor_count', 'visitorcount', 'visitors', 'visitortotal', 'uv',
    ];

    /** @var list<string> */
    private const PAID_ORDER_COUNT_SOURCE_KEYS = [
        'paid_order_count', 'paidordercount', 'payordercnt', 'pay_order_cnt', 'payordercount',
        'pay_order_count', 'pay_order_cnt_uv', 'ordersubmitnum', 'book_order_num', 'bookordernum',
    ];

    /** @var list<string> */
    private const BOOKING_ORDER_COUNT_SOURCE_KEYS = [
        'order_count', 'ordercount', 'book_order_num', 'bookordernum', 'ordernum', 'orderquantity',
        'orders', 'bookings', 'bookingcount',
    ];

    /** @var array<string,list<string>> */
    private const QUESTION_METRIC_KEYWORDS = [
        'amount' => ['收入', '营收', '销售额', '成交额', '订单金额', '房费金额', 'gmv'],
        'quantity' => ['间夜', '房晚'],
        'book_order_num' => [
            '支付订单量', '支付订单数', '预订订单量', '预订订单数', '订单量', '订单数', '预订量', '预订数',
        ],
        'comment_score' => ['点评分', '评论分', '评分'],
        'list_exposure' => [
            '列表曝光用户数', '列表曝光人数', '列表页曝光人数', '列表页曝光量', '列表曝光', '曝光量',
        ],
        'detail_exposure' => [
            '详情曝光用户数', '详情曝光人数', '详情访问用户数', '详情访客数', '详情页访客量', '详情曝光',
        ],
        'flow_rate' => ['浏览到支付转化率', '浏览支付转化率'],
        'order_filling_num' => ['订单填写页访客', '填单访客数', '填单人数', '填单数', '填单'],
        'order_submit_num' => [
            '订单提交用户数', '订单提交人数', '提交人数', '提交订单数', '订单提交数', '提交订单',
        ],
    ];

    /**
     * Positive semantic registry for source field facts. A generic storage
     * column is never by itself a business metric definition.
     *
     * @var array<string,array{definition_id:string,storage_field:string,unit:string,label:string}>
     */
    private const SOURCE_METRIC_DEFINITIONS = [
        'sales_amount' => ['definition_id' => 'ota_paid_order_amount.v1', 'storage_field' => 'amount', 'unit' => 'currency', 'label' => '渠道成交额'],
        'order_amount' => ['definition_id' => 'ota_paid_order_amount.v1', 'storage_field' => 'amount', 'unit' => 'currency', 'label' => '渠道订单金额'],
        'paid_order_amount' => ['definition_id' => 'ota_paid_order_amount.v1', 'storage_field' => 'amount', 'unit' => 'currency', 'label' => '渠道支付订单金额'],
        'ad_spend' => ['definition_id' => 'ota_ad_spend.v1', 'storage_field' => 'amount', 'unit' => 'currency', 'label' => '渠道广告花费'],
        'sales_room_nights' => ['definition_id' => 'ota_paid_room_nights.v1', 'storage_field' => 'quantity', 'unit' => 'room_night_count', 'label' => '渠道间夜'],
        'room_nights' => ['definition_id' => 'ota_paid_room_nights.v1', 'storage_field' => 'quantity', 'unit' => 'room_night_count', 'label' => '渠道间夜'],
        'mt_pay_rooms' => ['definition_id' => 'ota_paid_room_nights.v1', 'storage_field' => 'quantity', 'unit' => 'room_night_count', 'label' => '渠道支付间夜'],
        'paid_order_count' => ['definition_id' => 'ota_paid_order_count.v1', 'storage_field' => 'book_order_num', 'unit' => 'order_count', 'label' => '渠道支付订单数'],
        'booking_order_count' => ['definition_id' => 'ota_booking_order_count.v1', 'storage_field' => 'book_order_num', 'unit' => 'order_count', 'label' => '渠道预订订单数'],
        'mt_pay_orders' => ['definition_id' => 'ota_paid_order_count.v1', 'storage_field' => 'order_submit_num', 'unit' => 'order_count', 'label' => '渠道支付订单数'],
        'comment_score' => ['definition_id' => 'ota_comment_score_5_point.v1', 'storage_field' => 'comment_score', 'unit' => 'score_5_point', 'label' => '渠道点评分'],
        'qunar_comment_score' => ['definition_id' => 'ota_comment_score_5_point.v1', 'storage_field' => 'qunar_comment_score', 'unit' => 'score_5_point', 'label' => '去哪儿点评分'],
        'list_exposure' => ['definition_id' => 'ota_list_exposure.v1', 'storage_field' => 'list_exposure', 'unit' => 'exposure_count', 'label' => '列表曝光'],
        'mt_exposure' => ['definition_id' => 'ota_list_exposure.v1', 'storage_field' => 'list_exposure', 'unit' => 'exposure_count', 'label' => '列表曝光'],
        'exposure_users' => ['definition_id' => 'ota_list_exposure_users.v1', 'storage_field' => 'list_exposure', 'unit' => 'visitor_count', 'label' => '曝光用户数'],
        'exposureuv' => ['definition_id' => 'ota_list_exposure_users.v1', 'storage_field' => 'list_exposure', 'unit' => 'visitor_count', 'label' => '曝光用户数'],
        'detail_exposure' => ['definition_id' => 'ota_detail_exposure.v1', 'storage_field' => 'detail_exposure', 'unit' => 'exposure_count', 'label' => '详情曝光'],
        'detail_visitors' => ['definition_id' => 'ota_detail_visitors.v1', 'storage_field' => 'detail_exposure', 'unit' => 'visitor_count', 'label' => '详情访问用户数'],
        'mt_intention_uv' => ['definition_id' => 'ota_detail_visitors.v1', 'storage_field' => 'detail_exposure', 'unit' => 'visitor_count', 'label' => '详情访问用户数'],
        'browse_to_pay_rate' => ['definition_id' => 'ota_browse_to_pay_rate.v1', 'storage_field' => 'flow_rate', 'unit' => 'rate', 'label' => '浏览到支付转化率'],
        'ad_conversion_rate' => ['definition_id' => 'ota_ad_conversion_rate.v1', 'storage_field' => 'flow_rate', 'unit' => 'rate', 'label' => '广告转化率'],
        'order_filling_num' => ['definition_id' => 'ota_order_filling_count.v1', 'storage_field' => 'order_filling_num', 'unit' => 'order_count', 'label' => '填单数'],
        'order_submit_num' => ['definition_id' => 'ota_order_submit_count.v1', 'storage_field' => 'order_submit_num', 'unit' => 'order_count', 'label' => '提交订单数'],
        'order_filling_visitors' => ['definition_id' => 'ota_order_filling_visitors.v1', 'storage_field' => 'order_filling_num', 'unit' => 'visitor_count', 'label' => '订单填写页访客'],
        'order_submit_users' => ['definition_id' => 'ota_order_submit_users.v1', 'storage_field' => 'order_submit_num', 'unit' => 'visitor_count', 'label' => '订单提交人数'],
    ];

    /** @var array<string,list<string>> */
    private const QUESTION_DEFINITION_IDS = [
        'amount' => ['ota_paid_order_amount.v1'],
        'quantity' => ['ota_paid_room_nights.v1'],
        'book_order_num' => ['ota_booking_order_count.v1'],
        'comment_score' => ['ota_comment_score_5_point.v1'],
        'list_exposure' => ['ota_list_exposure.v1'],
        'detail_exposure' => ['ota_detail_exposure.v1'],
        'flow_rate' => ['ota_browse_to_pay_rate.v1'],
        'order_filling_num' => ['ota_order_filling_count.v1'],
        'order_submit_num' => ['ota_order_submit_count.v1'],
    ];

    /** @var array<string,list<string>> */
    private const QUESTION_KEYWORD_DEFINITION_IDS = [
        '支付订单量' => ['ota_paid_order_count.v1'],
        '支付订单数' => ['ota_paid_order_count.v1'],
        '列表曝光用户数' => ['ota_list_exposure_users.v1'],
        '列表曝光人数' => ['ota_list_exposure_users.v1'],
        '列表页曝光人数' => ['ota_list_exposure_users.v1'],
        '详情曝光用户数' => ['ota_detail_visitors.v1'],
        '详情曝光人数' => ['ota_detail_visitors.v1'],
        '详情访问用户数' => ['ota_detail_visitors.v1'],
        '详情访客数' => ['ota_detail_visitors.v1'],
        '详情页访客量' => ['ota_detail_visitors.v1'],
        '订单填写页访客' => ['ota_order_filling_visitors.v1'],
        '填单访客数' => ['ota_order_filling_visitors.v1'],
        '填单人数' => ['ota_order_filling_visitors.v1'],
        '订单提交用户数' => ['ota_order_submit_users.v1'],
        '订单提交人数' => ['ota_order_submit_users.v1'],
        '提交人数' => ['ota_order_submit_users.v1'],
    ];

    /**
     * @param null|Closure(int,int,string,string,string,string):array<string,mixed> $evidenceLoader
     * @param null|Closure(array<string,mixed>):array<string,mixed> $answerGenerator
     */
    public function __construct(
        private readonly ?Closure $evidenceLoader = null,
        private readonly ?Closure $answerGenerator = null
    )
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
        int $createdBy,
        string $modelKey = OperatingQuestionAiAnswerService::DIRECT_MODEL_KEY
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
        $modelKey = $this->modelKey($modelKey);
        if ($dateEnd < $dateStart) {
            throw new InvalidArgumentException('结束日期不能早于开始日期');
        }

        $evidence = $this->evidenceLoader !== null
            ? ($this->evidenceLoader)($tenantId, $hotelId, $platform, $dateStart, $dateEnd, $question)
            : $this->loadEvidence($tenantId, $hotelId, $platform, $dateStart, $dateEnd, $question, $createdBy);
        $evidence = $this->normalizeEvidence($evidence);
        $facts = array_values(array_map(
            fn(array $fact): array => $this->normalizeFactMetrics($fact),
            array_filter($evidence['facts'], static function (array $fact) use ($platform): bool {
            $factPlatform = strtolower(trim((string)($fact['platform'] ?? '')));
            if ($factPlatform === '') {
                $factPlatform = strtolower(trim((string)($fact['source'] ?? '')));
            }
            return $platform === 'all_ota'
                ? in_array($factPlatform, self::ALL_OTA_REQUIRED_PLATFORMS, true)
                : $factPlatform === $platform;
            })
        ));
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
        $questionMetricContract = $this->resolveQuestionMetricContract($question, $platform, $requiredDates);
        $factCount = $platform === 'all_ota'
            ? array_sum(array_intersect_key($factPlatformCounts, array_fill_keys(self::ALL_OTA_REQUIRED_PLATFORMS, true)))
            : (count($facts) > 0 ? max(count($facts), (int)($evidence['fact_count'] ?? 0)) : 0);
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
        $missingFactDates = $platform !== 'all_ota'
            ? array_values(array_diff($requiredDates, $factPlatformDates[$platform] ?? []))
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
        } elseif ($platform !== 'all_ota' && $missingFactDates !== []) {
            $answerSummary = sprintf(
                '已读取部分%s严格回读事实，但请求日期范围并未逐日覆盖，不能代表完整日期范围生成经营结论。',
                $this->platformLabel($platform)
            );
            $dataGaps[] = [
                'code' => 'platform_date_fact_coverage_missing',
                'message' => '单渠道日期范围问题必须在请求范围内逐日具备严格回读事实。',
                'missing_dates' => $missingFactDates,
            ];
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
            $answerStatus = 'evidence_ready';
            $answerSummary = sprintf(
                '已读取%d条同酒店、同平台、同日期范围的严格回读事实，覆盖%s；当前仅形成可追溯证据摘要，未替代指标口径复核或人工经营判断。',
                $factCount,
                $typeText
            );
            if ($diagnoses !== []) {
                // Legacy diagnosis prose has no per-claim value/unit binding and
                // therefore remains context only. It can never become the
                // factual answer by itself.
                $dataGaps[] = [
                    'code' => 'saved_diagnosis_claim_contract_missing',
                    'message' => '同范围历史诊断没有逐项事实 claim 合同，仅作为上下文保留，不能直接晋级为本次事实答案。',
                ];
            } elseif ($platform === 'all_ota') {
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

        $metricContractGaps = is_array($questionMetricContract['data_gaps'] ?? null)
            ? $questionMetricContract['data_gaps']
            : [];
        $requestedMetricKeys = array_values(array_map(
            static fn(array $metric): string => (string)($metric['metric_key'] ?? ''),
            is_array($questionMetricContract['requested_metrics'] ?? null)
                ? $questionMetricContract['requested_metrics']
                : []
        ));
        $metricCoverageGaps = $metricContractGaps === []
            ? $this->requestedMetricCoverageGaps(
                $facts,
                $platform,
                $requiredDates,
                is_array($questionMetricContract['requested_metrics'] ?? null)
                    ? $questionMetricContract['requested_metrics']
                    : []
            )
            : [];
        $substantiveCoverageGaps = $requestedMetricKeys === [] && $metricContractGaps === []
            ? $this->substantiveCoverageGaps($facts, $platform, $requiredDates)
            : [];
        if ($metricContractGaps !== [] || $metricCoverageGaps !== [] || $substantiveCoverageGaps !== []) {
            $answerStatus = 'blocked_by_missing_facts';
            $dataGaps = array_values(array_merge(
                $dataGaps,
                $metricContractGaps,
                $metricCoverageGaps,
                $substantiveCoverageGaps
            ));
            $answerSummary = $this->metricGapSummary(
                $metricContractGaps,
                $metricCoverageGaps,
                $substantiveCoverageGaps
            );
        }

        $factRefs = $this->refs($facts);
        $memoryRefs = $this->refs($evidence['memories']);
        $knowledgeResources = array_slice($evidence['knowledge'], 0, 5);
        $knowledgeRefs = $this->refs($knowledgeResources);
        $knowledgeUnitRefs = array_values(array_unique(array_filter(array_map(
            static fn(array $item): string => trim((string)($item['unit_ref'] ?? '')),
            $knowledgeResources
        ), static fn(string $ref): bool => preg_match('/^knowledge_units#[1-9][0-9]*$/D', $ref) === 1)));
        $knowledgeRetrieval = is_array($evidence['knowledge_retrieval'] ?? null)
            ? $evidence['knowledge_retrieval']
            : [
                'status' => $knowledgeResources === [] ? 'no_match' : 'matched',
                'method' => 'provided_evidence',
                'matched_count' => count($knowledgeResources),
                'returned_count' => count($knowledgeResources),
                'excluded_count' => 0,
                'reason' => '',
            ];
        $executionRefs = $this->refs($evidence['executions']);
        $diagnosisRefs = $this->refs($diagnoses);
        $recoveryPlan = $this->buildRecoveryPlan(
            $answerStatus,
            $hotelId,
            $platform,
            $dateStart,
            $dateEnd,
            $requiredDates,
            $factPlatformDates
        );
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
                'knowledge_units' => count($knowledgeUnitRefs),
                'knowledge_chunks' => count($knowledgeRefs),
                'execution_reviews' => count($executionRefs),
            ],
            // The AI layer independently proves substantive per-day/platform
            // coverage from this exact packet. Keep enough rows for the normal
            // bounded range instead of silently validating against rows the
            // model never receives.
            'fact_samples' => array_slice($facts, 0, 40),
            'diagnosis_refs' => $diagnosisRefs,
            'knowledge_retrieval' => $knowledgeRetrieval,
            'knowledge_resources' => $knowledgeResources,
            'question_metric_contract' => $questionMetricContract,
            'requested_metric_keys' => $requestedMetricKeys,
            'action_drafts' => [],
            'data_gaps' => $dataGaps,
            'recovery_plan' => $recoveryPlan,
            'boundaries' => [
                'external_llm_called' => false,
                'llm_attempted' => false,
                'ota_write' => false,
                'external_message' => false,
                'automatic_execution' => false,
            ],
        ];
        // Only the already accepted platform facts and current exact diagnoses
        // may enter the model context; rejected rows remain excluded even when
        // a custom evidence loader supplied them.
        $evidence['facts'] = $facts;
        $evidence['diagnoses'] = $diagnoses;
        $evidence['knowledge'] = $knowledgeResources;
        $evidence['knowledge_retrieval'] = $knowledgeRetrieval;
        $answer = $this->applyAiAnswer(
            $answer,
            $evidence,
            $question,
            $tenantId,
            $hotelId,
            $platform,
            $dateStart,
            $dateEnd,
            $createdBy,
            $modelKey
        );
        $answerStatus = (string)$answer['status'];
        $answerSummary = (string)$answer['summary'];
        $dataGaps = is_array($answer['data_gaps'] ?? null) ? array_values($answer['data_gaps']) : [];
        $providerResponseId = $this->providerResponseId($answer['ai_runtime']['provider_response_id'] ?? null);
        $provider = strtolower(trim((string)($answer['ai_runtime']['provider'] ?? '')));
        if ($providerResponseId !== '' && preg_match('/^[a-z0-9_.:-]{2,50}$/D', $provider) !== 1) {
            throw new RuntimeException('经营问答模型响应来源标识无效，拒绝保存');
        }
        if ($providerResponseId !== '' && !$this->tableExists(self::MODEL_RESPONSE_REGISTRY_TABLE)) {
            throw new RuntimeException('经营问答模型响应回放登记表缺失，请先执行数据库迁移');
        }
        $digest = $this->digest([
            'question' => $question,
            'answer' => $answer,
            'fact_refs' => $factRefs,
            'memory_refs' => $memoryRefs,
            'knowledge_refs' => $knowledgeRefs,
            'execution_refs' => $executionRefs,
        ]);
        $requestKey = 'operating-question:v4:' . substr($this->digest([
            $tenantId,
            $hotelId,
            $platform,
            $dateStart,
            $dateEnd,
            $question,
            $modelKey,
            OperatingQuestionAiAnswerService::PROMPT_VERSION,
            OperatingQuestionAiAnswerService::ACTION_DRAFT_CONTRACT_VERSION,
            $providerResponseId,
            microtime(true),
            bin2hex(random_bytes(16)),
        ]), 0, 48);
        $now = date('Y-m-d H:i:s');
        try {
            $questionRow = Db::transaction(function () use (
                $tenantId,
                $hotelId,
                $requestKey,
                $question,
                $platform,
                $dateStart,
                $dateEnd,
                $answerStatus,
                $answerSummary,
                $answer,
                $factRefs,
                $memoryRefs,
                $knowledgeRefs,
                $executionRefs,
                $dataGaps,
                $digest,
                $createdBy,
                $now,
                $provider,
                $providerResponseId
            ): array {
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

                $registryId = 0;
                if ($providerResponseId !== '') {
                    $registryId = (int)Db::name(self::MODEL_RESPONSE_REGISTRY_TABLE)->insertGetId([
                        'provider_response_id' => $providerResponseId,
                        'provider' => $provider,
                        'question_id' => $id,
                        'tenant_id' => $tenantId,
                        'hotel_id' => $hotelId,
                        'question_content_digest' => $digest,
                        'created_at' => $now,
                    ]);
                    if ($registryId <= 0) {
                        throw new RuntimeException('经营问答模型响应登记失败：未取得登记ID');
                    }
                }

                $readback = $this->read($id, $tenantId, [$hotelId]);
                $readbackDigest = $this->digest([
                    'question' => (string)($readback['question_text'] ?? ''),
                    'answer' => is_array($readback['answer'] ?? null) ? $readback['answer'] : [],
                    'fact_refs' => array_values((array)($readback['fact_refs'] ?? [])),
                    'memory_refs' => array_values((array)($readback['memory_refs'] ?? [])),
                    'knowledge_refs' => array_values((array)($readback['knowledge_refs'] ?? [])),
                    'execution_refs' => array_values((array)($readback['execution_refs'] ?? [])),
                ]);
                if ((int)($readback['id'] ?? 0) !== $id
                    || (int)($readback['tenant_id'] ?? 0) !== $tenantId
                    || (int)($readback['hotel_id'] ?? 0) !== $hotelId
                    || (string)($readback['request_key'] ?? '') !== $requestKey
                    || (string)($readback['question_text'] ?? '') !== $question
                    || (string)($readback['platform'] ?? '') !== $platform
                    || (string)($readback['date_start'] ?? '') !== $dateStart
                    || (string)($readback['date_end'] ?? '') !== $dateEnd
                    || (string)($readback['answer_status'] ?? '') !== $answerStatus
                    || (string)($readback['answer_summary'] ?? '') !== $answerSummary
                    || !hash_equals($digest, (string)($readback['content_digest'] ?? ''))
                    || !hash_equals($digest, $readbackDigest)
                    || $this->digest($dataGaps) !== $this->digest((array)($readback['data_gaps'] ?? []))
                    || $providerResponseId !== $this->providerResponseId($readback['answer']['ai_runtime']['provider_response_id'] ?? null)
                ) {
                    throw new RuntimeException('经营问题已写入但严格回读校验失败');
                }

                if ($registryId > 0) {
                    $registry = Db::name(self::MODEL_RESPONSE_REGISTRY_TABLE)->where('id', $registryId)->find();
                    if (!is_array($registry)
                        || (int)($registry['id'] ?? 0) !== $registryId
                        || (string)($registry['provider_response_id'] ?? '') !== $providerResponseId
                        || (string)($registry['provider'] ?? '') !== $provider
                        || (int)($registry['question_id'] ?? 0) !== $id
                        || (int)($registry['tenant_id'] ?? 0) !== $tenantId
                        || (int)($registry['hotel_id'] ?? 0) !== $hotelId
                        || !hash_equals($digest, (string)($registry['question_content_digest'] ?? ''))
                    ) {
                        throw new RuntimeException('经营问答模型响应登记严格回读失败');
                    }
                }
                return $readback;
            });
        } catch (\Throwable $e) {
            if ($providerResponseId !== '' && $this->tableExists(self::MODEL_RESPONSE_REGISTRY_TABLE)) {
                $existingReceipt = Db::name(self::MODEL_RESPONSE_REGISTRY_TABLE)
                    ->where('provider_response_id', $providerResponseId)
                    ->find();
                if (is_array($existingReceipt)) {
                    throw new RuntimeException('provider_response_replay_rejected', 0, $e);
                }
            }
            throw $e;
        }

        return [
            'question' => $questionRow,
            'created' => true,
            'persistence_status' => 'readback_verified',
            'write_boundaries' => $this->writeBoundaries($questionRow),
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
        return $this->assertReadbackDigest($this->normalizeRow($row));
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
        string $question,
        int $createdBy
    ): array {
        $knowledge = (new OperatingQuestionKnowledgeRetrievalService())->retrieve(
            $hotelId,
            max(0, $createdBy),
            $platform,
            $question
        );
        return [
            'facts' => $this->loadFacts($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
            'fact_count' => $this->factCount($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
            'fact_platform_counts' => $this->factPlatformCounts($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
            'fact_platform_dates' => $this->factPlatformDates($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
            'memories' => $this->loadMemories($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
            'diagnoses' => $this->loadDiagnoses($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
            'knowledge' => is_array($knowledge['items'] ?? null) ? $knowledge['items'] : [],
            'knowledge_retrieval' => array_diff_key($knowledge, ['items' => true]),
            'executions' => $this->loadExecutions($tenantId, $hotelId, $platform, $dateStart, $dateEnd),
        ];
    }

    /**
     * Re-read the exact fact rows behind an action before it enters approval.
     * The normal fact query reapplies tenant, hotel, platform, business-date,
     * history, validation and readback gates; invalid or out-of-scope refs are
     * therefore returned as missing rather than trusted from the saved answer.
     *
     * @param list<string> $refs
     * @return list<array<string,mixed>>
     */
    public function readCurrentVerifiedFactsForRefs(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $dateStart,
        string $dateEnd,
        array $refs
    ): array {
        $this->assertHotelIdentity($tenantId, $hotelId);
        $platform = $this->normalizePlatform($platform);
        $dateStart = $this->date($dateStart, '开始日期');
        $dateEnd = $this->date($dateEnd, '结束日期');
        if ($dateEnd < $dateStart) {
            return [];
        }
        $ids = [];
        foreach (array_values(array_unique(array_map('strval', $refs))) as $ref) {
            if (preg_match('/^online_daily_data#([1-9][0-9]*)$/D', trim($ref), $matches) !== 1) {
                return [];
            }
            $ids[] = (int)$matches[1];
        }
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        if ($ids === [] || count($ids) > 40) {
            return [];
        }
        return $this->loadFacts($tenantId, $hotelId, $platform, $dateStart, $dateEnd, $ids);
    }

    /** @param list<int> $factIds @return list<array<string,mixed>> */
    private function loadFacts(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $dateStart,
        string $dateEnd,
        array $factIds = []
    ): array
    {
        if (!$this->tableExists('online_daily_data')) {
            return [];
        }
        $query = $this->factQuery($tenantId, $hotelId, $platform, $dateStart, $dateEnd);
        if ($factIds !== []) {
            $query->whereIn('id', $factIds);
        }
        $fields = [
            'id', 'data_date', 'platform', 'source', 'data_type', 'dimension',
            'validation_status', 'history_status', 'readback_verified', 'readback_verified_at',
            'ingestion_method', 'source_trace_id',
        ];
        foreach (self::FACT_METRIC_FIELDS as $metricField) {
            if ($this->columnExists('online_daily_data', $metricField)) {
                $fields[] = $metricField;
            }
        }
        if ($this->columnExists('online_daily_data', 'raw_data')) {
            $fields[] = 'raw_data';
        }
        $rows = $query
            ->field(implode(',', $fields))
            ->order('data_date', 'desc')
            ->order('id', 'desc')
            ->limit(40)
            ->select()
            ->toArray();
        return array_map(function (array $row): array {
            $rowPlatform = strtolower(trim((string)($row['platform'] ?? '')));
            if ($rowPlatform === '') {
                $rowPlatform = strtolower(trim((string)($row['source'] ?? '')));
            }
            $metricValues = [];
            $metricUnits = [];
            $metricDefinitions = [];
            $metricGaps = [];
            $observedMetricFields = self::observedMetricFields($row['raw_data'] ?? null);
            foreach (self::FACT_METRIC_FIELDS as $metricField) {
                $value = $row[$metricField] ?? null;
                if ($value === null || $value === '' || !is_numeric($value)) {
                    continue;
                }
                // Production metric columns historically default to zero. A
                // zero is usable only when field_facts proves that exact
                // storage field came from the source payload. For older rows
                // without field_facts, only non-zero stored values are kept.
                if ($observedMetricFields !== null) {
                    if (!isset($observedMetricFields[$metricField])) {
                        continue;
                    }
                } elseif ((float)$value === 0.0) {
                    continue;
                }
                $definition = $this->claimableMetricDefinition(
                    $rowPlatform,
                    (string)($row['data_type'] ?? ''),
                    $metricField,
                    $row['raw_data'] ?? null
                );
                if (($definition['claimable'] ?? false) !== true) {
                    $metricGaps[] = [
                        'metric_key' => $metricField,
                        'reason' => (string)($definition['reason'] ?? 'metric_definition_missing'),
                    ];
                    continue;
                }
                if (!$this->metricValueMatchesUnit($value, (string)$definition['unit'])) {
                    $metricGaps[] = [
                        'metric_key' => $metricField,
                        'reason' => 'metric_value_unit_scale_mismatch',
                    ];
                    continue;
                }
                $metricValues[$metricField] = in_array($metricField, [
                    'quantity', 'book_order_num', 'list_exposure', 'detail_exposure',
                    'order_filling_num', 'order_submit_num',
                ], true) ? (int)$value : (float)$value;
                $metricUnits[$metricField] = (string)$definition['unit'];
                $metricDefinitions[$metricField] = $definition;
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
                'metric_values' => $metricValues,
                'metric_units' => $metricUnits,
                'metric_definitions' => $metricDefinitions,
                'metric_gaps' => $metricGaps,
            ];
        }, $rows);
    }

    /**
     * Return null when no field-fact contract exists; otherwise return the
     * exact online_daily_data metric columns proven as captured and persisted.
     *
     * @return array<string,true>|null
     */
    private static function observedMetricFields(mixed $rawData): ?array
    {
        if (is_string($rawData)) {
            $decoded = json_decode($rawData, true);
        } elseif (is_array($rawData)) {
            $decoded = $rawData;
        } else {
            $decoded = null;
        }
        if (!is_array($decoded) || !array_key_exists('field_facts', $decoded) || !is_array($decoded['field_facts'])) {
            return null;
        }

        $allowed = array_fill_keys(self::FACT_METRIC_FIELDS, true);
        $observed = [];
        foreach ($decoded['field_facts'] as $fact) {
            if (!is_array($fact) || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured') {
                continue;
            }
            $storedValuePresent = $fact['stored_value_present'] ?? null;
            if ($storedValuePresent !== true) {
                continue;
            }
            $sourcePath = trim((string)($fact['source_path'] ?? ''));
            $storageField = strtolower(trim((string)($fact['storage_field'] ?? $fact['storage_target'] ?? '')));
            if ($sourcePath === '' || $storageField === '') {
                continue;
            }
            if (str_starts_with($storageField, 'online_daily_data.')) {
                $storageField = substr($storageField, strlen('online_daily_data.'));
            }
            if (isset($allowed[$storageField])) {
                $observed[$storageField] = true;
            }
        }
        return $observed;
    }

    /** @return array<string,mixed> */
    private function claimableMetricDefinition(
        string $platform,
        string $rowDataType,
        string $metricField,
        mixed $rawData
    ): array
    {
        $rowDataType = strtolower(trim($rowDataType));
        $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
        $fieldFacts = is_array($decoded) && is_array($decoded['field_facts'] ?? null)
            ? $decoded['field_facts']
            : [];
        $matchingFacts = 0;
        $invalidReasons = [];
        $candidates = [];
        $semanticSignatures = [];
        foreach ($fieldFacts as $fact) {
            if (!is_array($fact)
                || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured'
                || ($fact['stored_value_present'] ?? null) !== true
            ) {
                continue;
            }
            $storageField = strtolower(trim((string)($fact['storage_field'] ?? $fact['storage_target'] ?? '')));
            if (str_starts_with($storageField, 'online_daily_data.')) {
                $storageField = substr($storageField, strlen('online_daily_data.'));
            }
            if ($storageField !== $metricField) {
                continue;
            }
            $matchingFacts++;
            $factDataType = strtolower(trim((string)($fact['data_type'] ?? $rowDataType)));
            $normalizedMetricKey = strtolower(trim((string)($fact['metric_key'] ?? $fact['field_key'] ?? '')));
            $sourceKey = strtolower(trim((string)($fact['source_key'] ?? '')));
            if ($rowDataType === ''
                || $factDataType === ''
                || $factDataType !== $rowDataType
                || $sourceKey === ''
            ) {
                $invalidReasons[] = 'metric_source_identity_missing';
                continue;
            }
            $sourceMetricKey = $this->semanticSourceMetricKey(
                $platform,
                $factDataType,
                $metricField,
                $normalizedMetricKey,
                $sourceKey
            );
            $definition = self::SOURCE_METRIC_DEFINITIONS[$sourceMetricKey] ?? null;
            if (!is_array($definition) || (string)$definition['storage_field'] !== $metricField) {
                $invalidReasons[] = 'metric_definition_missing';
                continue;
            }
            $unit = $this->definitionUnit($definition, $fact);
            if ($unit === '') {
                $invalidReasons[] = (string)$definition['unit'] === 'currency'
                    ? 'metric_currency_missing'
                    : 'metric_unit_missing';
                continue;
            }
            $sourcePath = trim((string)($fact['source_path'] ?? ''));
            if ($sourcePath === '') {
                $invalidReasons[] = 'metric_source_path_missing';
                continue;
            }
            $factIdentity = [
                'data_type' => $factDataType,
                'metric_key' => $normalizedMetricKey,
                'source_metric_key' => $sourceMetricKey,
                'source_key' => $sourceKey,
                'source_path' => $sourcePath,
                'storage_field' => 'online_daily_data.' . $metricField,
                'status' => 'captured',
                'stored_value_present' => true,
                'unit' => $unit,
            ];
            $candidate = [
                'claimable' => true,
                'definition_id' => (string)$definition['definition_id'],
                'source_metric_key' => $sourceMetricKey,
                'source_data_type' => $factDataType,
                'source_key' => $sourceKey,
                'storage_field' => 'online_daily_data.' . $metricField,
                'source_path_digest' => hash('sha256', $sourcePath),
                'field_fact_digest' => $this->digest($factIdentity),
                'unit' => $unit,
                'unit_status' => 'verified',
                'unit_source' => in_array((string)$definition['unit'], ['currency', 'rate'], true)
                    ? 'field_fact'
                    : 'operating_question_metric_semantics.v1',
                'label' => (string)$definition['label'],
                'platform' => $platform,
            ];
            $candidateIdentity = implode('|', [
                (string)$candidate['definition_id'],
                $sourceMetricKey,
                $unit,
                $sourceKey,
                (string)$candidate['source_path_digest'],
            ]);
            $candidates[$candidateIdentity] = $candidate;
            $semanticSignatures[implode('|', [
                (string)$candidate['definition_id'],
                $sourceMetricKey,
                $unit,
            ])] = true;
        }
        if ($matchingFacts === 0) {
            return ['claimable' => false, 'reason' => 'captured_field_fact_missing'];
        }
        if ($candidates === []) {
            return [
                'claimable' => false,
                'reason' => (string)($invalidReasons[0] ?? 'metric_definition_missing'),
            ];
        }
        if ($invalidReasons !== [] || count($semanticSignatures) !== 1) {
            return ['claimable' => false, 'reason' => 'metric_source_definition_conflict'];
        }
        ksort($candidates, SORT_STRING);
        return reset($candidates);
    }

    private function semanticSourceMetricKey(
        string $platform,
        string $dataType,
        string $metricField,
        string $normalizedMetricKey,
        string $sourceKey
    ): string {
        if ($metricField === 'list_exposure') {
            if ($dataType === 'business'
                && $normalizedMetricKey === 'exposure_users'
                && in_array($platform, ['ctrip', 'meituan'], true)
                && in_array($sourceKey, self::LIST_EXPOSURE_VISITOR_SOURCE_KEYS, true)
            ) {
                return 'exposure_users';
            }
            if ($dataType !== 'traffic') {
                return '';
            }
            if ($platform === 'qunar'
                && $normalizedMetricKey === 'list_exposure'
                && $sourceKey === 'listexposure'
            ) {
                return 'list_exposure';
            }
            if ($platform === 'ctrip'
                && $normalizedMetricKey === 'list_exposure'
                && in_array($sourceKey, self::LIST_EXPOSURE_VISITOR_SOURCE_KEYS, true)
            ) {
                return 'exposure_users';
            }
            if ($platform === 'meituan'
                && in_array($normalizedMetricKey, ['list_exposure', 'mt_exposure'], true)
                && in_array($sourceKey, self::LIST_EXPOSURE_VISITOR_SOURCE_KEYS, true)
            ) {
                return 'exposure_users';
            }
            return $platform === 'meituan'
                && in_array($sourceKey, self::LIST_EXPOSURE_COUNT_SOURCE_KEYS, true)
                && in_array($normalizedMetricKey, ['list_exposure', 'mt_exposure'], true)
                    ? 'mt_exposure'
                    : '';
        }
        if ($metricField === 'detail_exposure') {
            if ($dataType === 'business'
                && $normalizedMetricKey === 'detail_visitors'
                && in_array($platform, ['ctrip', 'meituan'], true)
                && in_array($sourceKey, self::DETAIL_EXPOSURE_VISITOR_SOURCE_KEYS, true)
            ) {
                return 'detail_visitors';
            }
            if ($dataType !== 'traffic') {
                return '';
            }
            if ($platform === 'ctrip'
                && $normalizedMetricKey === 'detail_exposure'
                && in_array($sourceKey, self::DETAIL_EXPOSURE_VISITOR_SOURCE_KEYS, true)
            ) {
                return 'detail_visitors';
            }
            if ($platform === 'meituan'
                && in_array($normalizedMetricKey, ['detail_exposure', 'mt_intention_uv'], true)
                && in_array($sourceKey, self::DETAIL_EXPOSURE_VISITOR_SOURCE_KEYS, true)
            ) {
                return 'detail_visitors';
            }
            return $platform === 'meituan'
                && $normalizedMetricKey === 'detail_exposure'
                && in_array($sourceKey, self::DETAIL_EXPOSURE_COUNT_SOURCE_KEYS, true)
                ? 'detail_exposure'
                : '';
        }
        if ($metricField === 'book_order_num') {
            if (!in_array($dataType, ['business', 'order'], true)) {
                return '';
            }
            if ($normalizedMetricKey === 'paid_order_count'
                && in_array($sourceKey, self::PAID_ORDER_COUNT_SOURCE_KEYS, true)
            ) {
                return 'paid_order_count';
            }
            if ($normalizedMetricKey === 'order_count'
                && in_array($sourceKey, self::BOOKING_ORDER_COUNT_SOURCE_KEYS, true)
            ) {
                return 'booking_order_count';
            }
            return '';
        }
        if ($metricField === 'amount') {
            if ($dataType === 'business'
                && $normalizedMetricKey === 'sales_amount'
                && in_array($sourceKey, ['sales_amount', 'salesamount', 'amount', 'sales', 'pay_amt'], true)
            ) {
                return 'sales_amount';
            }
            if ($dataType === 'order'
                && $normalizedMetricKey === 'order_amount'
                && in_array($sourceKey, ['total_amount', 'totalamount', 'amount', 'payamount', 'pay_amount'], true)
            ) {
                return 'order_amount';
            }
            if ($dataType === 'advertising'
                && $normalizedMetricKey === 'ad_spend'
                && in_array($sourceKey, ['amount', 'todaycost', 'cost', 'ad_cost', 'adcost', 'spend', 'consume', 'consumption'], true)
            ) {
                return 'ad_spend';
            }
            return '';
        }
        if ($metricField === 'quantity') {
            if ($dataType === 'business'
                && $normalizedMetricKey === 'sales_room_nights'
                && in_array($sourceKey, ['sales_room_nights', 'salesroomnights', 'quantity', 'room_nights', 'roomnights', 'pay_roomnight'], true)
            ) {
                return 'sales_room_nights';
            }
            if ($dataType === 'order'
                && $normalizedMetricKey === 'room_nights'
                && in_array($sourceKey, ['room_nights', 'roomnights', 'quantity'], true)
            ) {
                return 'room_nights';
            }
            if ($platform === 'meituan'
                && $dataType === 'traffic'
                && $normalizedMetricKey === 'mt_pay_rooms'
                && in_array($sourceKey, ['mt_pay_rooms', 'pay_rooms', 'payrooms', 'payroomnum', 'pay_room_num', 'roomnights', 'room_nights', 'quantity'], true)
            ) {
                return 'mt_pay_rooms';
            }
            return '';
        }
        if ($metricField === 'comment_score') {
            if ($dataType === 'review'
                && $normalizedMetricKey === 'comment_score'
                && in_array($sourceKey, ['comment_score', 'commentscore', 'score', 'star', 'rating', 'rate', 'totalscore', 'overallscore'], true)
            ) {
                return 'comment_score';
            }
            if ($platform === 'ctrip'
                && in_array($dataType, ['business', 'quality', 'traffic'], true)
                && in_array($normalizedMetricKey, ['ctrip_rating', 'comment_score_summary'], true)
                && in_array($sourceKey, ['ctripratingall', 'hotelrating', 'ratingall'], true)
            ) {
                return 'comment_score';
            }
            return '';
        }
        if ($metricField === 'qunar_comment_score') {
            return $platform === 'qunar'
                && $dataType === 'quality'
                && $normalizedMetricKey === 'qunar_rating'
                && $sourceKey === 'qunarratingall'
                    ? 'qunar_comment_score'
                    : '';
        }
        if ($metricField === 'flow_rate') {
            if (in_array($dataType, ['business', 'traffic'], true)
                && $normalizedMetricKey === 'browse_to_pay_rate'
                && in_array($sourceKey, [
                    'browse_to_pay_rate', 'browsetopayrate', 'browsepayrate', 'browse_pay_rate', 'payorderperintention',
                    'flowrate', 'flow_rate', 'pay_order_cnt_uv',
                ], true)
            ) {
                return 'browse_to_pay_rate';
            }
            if ($dataType === 'advertising'
                && $normalizedMetricKey === 'ad_conversion_rate'
                && in_array($sourceKey, ['conversion_rate', 'conversionrate', 'flowrate', 'orderrate'], true)
            ) {
                return 'ad_conversion_rate';
            }
            return '';
        }
        if ($metricField === 'order_filling_num') {
            return $dataType === 'traffic'
                && $normalizedMetricKey === 'order_filling_num'
                && in_array($sourceKey, ['order_filling_num', 'orderfillingnum', 'ordervisitors'], true)
                    ? 'order_filling_visitors'
                    : '';
        }
        if ($metricField === 'order_submit_num') {
            if ($platform === 'meituan'
                && $dataType === 'traffic'
                && in_array($normalizedMetricKey, ['mt_pay_orders', 'order_submit_num'], true)
                && in_array($sourceKey, [
                    'mt_pay_orders', 'pay_orders', 'payorders', 'payordercnt', 'pay_order_cnt',
                    'payordercount', 'pay_order_count',
                ], true)
            ) {
                return 'mt_pay_orders';
            }
            return $platform === 'ctrip'
                && $dataType === 'traffic'
                && $normalizedMetricKey === 'order_submit_num'
                && in_array($sourceKey, ['order_submit_num', 'ordersubmitnum', 'submit_users', 'submitusers', 'submitnum'], true)
                    ? 'order_submit_users'
                    : '';
        }
        return '';
    }

    /** @param array{definition_id:string,storage_field:string,unit:string,label:string} $definition */
    private function definitionUnit(array $definition, array $fact): string
    {
        $unitKind = (string)$definition['unit'];
        if ($unitKind === 'currency') {
            $candidate = strtoupper(trim((string)(
                $fact['currency_code']
                ?? $fact['currency']
                ?? $fact['stored_unit']
                ?? $fact['normalized_unit']
                ?? ''
            )));
            if (in_array($candidate, ['元', '人民币', 'RMB', 'YUAN'], true)) {
                return 'CNY';
            }
            return in_array($candidate, self::SUPPORTED_CURRENCY_CODES, true) ? $candidate : '';
        }
        if ($unitKind === 'rate') {
            $candidate = strtolower(trim((string)(
                $fact['stored_unit']
                ?? $fact['normalized_unit']
                ?? $fact['metric_unit']
                ?? $fact['unit']
                ?? ''
            )));
            return match ($candidate) {
                '%', 'percent', 'percentage', '百分比' => 'percent',
                'ratio', 'ratio_0_1', '0-1', '比例' => 'ratio_0_1',
                default => '',
            };
        }
        return $this->isRealMetricUnit($unitKind) ? $unitKind : '';
    }

    /** @param array<string,mixed> $fact @return array<string,mixed> */
    private function normalizeFactMetrics(array $fact): array
    {
        $values = is_array($fact['metric_values'] ?? null) ? $fact['metric_values'] : [];
        $units = is_array($fact['metric_units'] ?? null) ? $fact['metric_units'] : [];
        $definitions = is_array($fact['metric_definitions'] ?? null) ? $fact['metric_definitions'] : [];
        $cleanValues = [];
        $cleanUnits = [];
        $cleanDefinitions = [];
        $gaps = is_array($fact['metric_gaps'] ?? null) ? array_values($fact['metric_gaps']) : [];
        foreach ($values as $metricKey => $value) {
            $metricKey = trim((string)$metricKey);
            $unit = trim((string)($units[$metricKey] ?? ''));
            $definition = is_array($definitions[$metricKey] ?? null) ? $definitions[$metricKey] : [];
            if (!in_array($metricKey, self::FACT_METRIC_FIELDS, true)
                || !is_numeric($value)
                || !$this->isRealMetricUnit($unit)
                || !$this->metricDefinitionMatches($metricKey, $unit, $definition)
                || !$this->metricValueMatchesUnit($value, $unit)
            ) {
                $gaps[] = [
                    'metric_key' => $metricKey,
                    'reason' => !$this->isRealMetricUnit($unit)
                        ? 'metric_unit_missing'
                        : (!$this->metricValueMatchesUnit($value, $unit)
                            ? 'metric_value_unit_scale_mismatch'
                            : 'metric_definition_missing'),
                ];
                continue;
            }
            $cleanValues[$metricKey] = $value;
            $cleanUnits[$metricKey] = $unit;
            $cleanDefinitions[$metricKey] = $definition;
        }
        $fact['metric_values'] = $cleanValues;
        $fact['metric_units'] = $cleanUnits;
        $fact['metric_definitions'] = $cleanDefinitions;
        $fact['metric_gaps'] = array_values(array_slice(array_filter($gaps, 'is_array'), 0, 16));
        return $fact;
    }

    /** @param array<string,mixed> $definition */
    private function metricDefinitionMatches(string $metricKey, string $unit, array $definition): bool
    {
        $definitionId = trim((string)($definition['definition_id'] ?? ''));
        $sourceMetricKey = trim((string)($definition['source_metric_key'] ?? ''));
        $sourceDataType = trim((string)($definition['source_data_type'] ?? ''));
        $sourceKey = trim((string)($definition['source_key'] ?? ''));
        $storageField = trim((string)($definition['storage_field'] ?? ''));
        $fieldFactDigest = strtolower(trim((string)($definition['field_fact_digest'] ?? '')));
        $sourcePathDigest = strtolower(trim((string)($definition['source_path_digest'] ?? '')));
        return ($definition['claimable'] ?? false) === true
            && preg_match('/^[a-z0-9_.-]+\.v[1-9][0-9]*$/D', $definitionId) === 1
            && preg_match('/^[a-z0-9_.:-]{1,100}$/D', $sourceMetricKey) === 1
            && preg_match('/^[a-z0-9_.:-]{1,50}$/D', $sourceDataType) === 1
            && preg_match('/^[a-z0-9_.:-]{1,100}$/D', $sourceKey) === 1
            && $storageField === 'online_daily_data.' . $metricKey
            && preg_match('/^[a-f0-9]{64}$/D', $fieldFactDigest) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $sourcePathDigest) === 1
            && (string)($definition['unit_status'] ?? '') === 'verified'
            && hash_equals(trim((string)($definition['unit'] ?? '')), $unit);
    }

    private function isRealMetricUnit(string $unit): bool
    {
        $unit = trim($unit);
        if (preg_match('/^[A-Z]{3}$/D', $unit) === 1) {
            return in_array($unit, self::SUPPORTED_CURRENCY_CODES, true);
        }
        return in_array(strtolower($unit), self::SUPPORTED_NON_CURRENCY_UNITS, true);
    }

    private function metricValueMatchesUnit(mixed $value, string $unit): bool
    {
        if (!is_numeric($value)) {
            return false;
        }
        $number = (float)$value;
        if (in_array($unit, self::SUPPORTED_CURRENCY_CODES, true)) {
            return $number >= 0.0;
        }
        return match ($unit) {
            'percent' => $number >= 0.0 && $number <= 100.0,
            'ratio_0_1' => $number >= 0.0 && $number <= 1.0,
            'score_5_point' => $number >= 0.0 && $number <= 5.0,
            'exposure_count', 'order_count', 'count', 'room_night_count', 'visitor_count' =>
                $number >= 0.0 && floor($number) === $number,
            default => true,
        };
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
            ->where('quality_status', 'verified')
            ->whereIn('usage_level', ['reference', 'decision_support'])
            ->where('lifecycle_status', 'active')
            ->whereNull('deleted_at');
        if ($this->columnExists(OperatingMemoryService::TABLE, 'source_scope')) {
            $query->where('source_scope', 'ota_channel');
        }
        if ($platform === 'all_ota') {
            $query->whereIn('platform', self::ALL_OTA_REQUIRED_PLATFORMS);
        } else {
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
        if ($platform === 'all_ota') {
            $query->whereIn('i.platform', self::ALL_OTA_REQUIRED_PLATFORMS);
        } else {
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
        $evidence['knowledge_retrieval'] = is_array($evidence['knowledge_retrieval'] ?? null)
            ? $evidence['knowledge_retrieval']
            : [];
        $evidence['fact_count'] = max(0, (int)($evidence['fact_count'] ?? count($evidence['facts'])));
        $evidence['fact_platform_counts'] = $this->factPlatformCountsFromEvidence($evidence);
        $evidence['fact_platform_dates'] = $this->factPlatformDatesFromEvidence($evidence);
        return $evidence;
    }

    /** @return array<string,mixed> */
    private function resolveQuestionMetricContract(string $question, string $platform, array $requiredDates): array
    {
        $normalized = mb_strtolower(trim($question));
        $dataGaps = $this->questionScopeConflictGaps($normalized, $platform, $requiredDates);
        foreach ($dataGaps === []
            ? ['revpar', 'occ', 'adr', '入住率', '出租率', '全酒店收入', '酒店总收入', '总收入']
            : [] as $term
        ) {
            if (mb_strpos($normalized, mb_strtolower($term)) !== false) {
                $dataGaps[] = [
                    'code' => 'requested_metric_out_of_scope',
                    'message' => '该问题要求全酒店或 PMS 收益指标，当前经营问答证据仅覆盖 OTA 渠道事实，不能用 OTA 局部字段替代。',
                    'matched_text' => $term,
                ];
                break;
            }
        }
        $unsupportedSemantic = $dataGaps === [] ? $this->unsupportedQuestionSemantic($normalized) : '';
        if ($unsupportedSemantic !== '') {
            $dataGaps[] = [
                'code' => 'question_metric_ambiguous',
                'message' => '问题包含当前未定义的状态、派生计算、比较或否定语义；请改为一个明确的原始 OTA 指标和范围。',
                'matched_text' => $unsupportedSemantic,
            ];
        }
        $hasExposureOrVisitTerm = $this->containsAnyQuestionTerm($normalized, [
            '曝光', '详情访问', '详情浏览',
        ]);
        $hasDifferentCountObject = $this->containsAnyQuestionTerm($normalized, [
            '用户', '访客', '人数', 'uv', '独立访客',
        ]);
        $hasSupportedVisitorMetric = $this->containsAnyQuestionTerm($normalized, [
            '列表曝光用户数', '列表曝光人数', '列表页曝光人数',
            '详情曝光用户数', '详情曝光人数', '详情访问用户数', '详情访客数', '详情页访客量',
        ]);
        if ($dataGaps === []
            && $hasExposureOrVisitTerm
            && $hasDifferentCountObject
            && !$hasSupportedVisitorMetric
        ) {
            $dataGaps[] = [
                'code' => 'question_metric_ambiguous',
                'message' => '问题同时包含曝光/访问和用户、访客或人数口径；当前不能用曝光次数代替访客数，请明确指标定义。',
                'matched_text' => '曝光/访问用户数口径',
            ];
        }
        if ($dataGaps === []
            && mb_strpos($normalized, '广告') !== false
            && $this->containsAnyQuestionTerm($normalized, [
                '曝光', '转化率', '收入', '营收', '销售额', '成交额', '订单', '金额', '花费', '消耗',
            ])
        ) {
            $dataGaps[] = [
                'code' => 'question_metric_ambiguous',
                'message' => '问题包含广告专用口径，当前不能用自然流量曝光、渠道订单或浏览到支付口径代替；请明确广告指标定义。',
                'matched_text' => '广告指标口径',
            ];
        }
        if ($dataGaps === []
            && mb_strpos($normalized, '转化率') !== false
            && mb_strpos($normalized, '浏览到支付转化率') === false
            && mb_strpos($normalized, '浏览支付转化率') === false
        ) {
            $dataGaps[] = [
                'code' => 'question_metric_ambiguous',
                'message' => '“转化率”缺少漏斗分子、分母和数值尺度；请明确例如“浏览到支付转化率”。',
                'matched_text' => '转化率',
            ];
        }
        if ($dataGaps === []
            && mb_strpos($normalized, '曝光') !== false
            && mb_strpos($normalized, '列表曝光') === false
            && mb_strpos($normalized, '曝光量') === false
            && mb_strpos($normalized, '详情曝光') === false
        ) {
            $dataGaps[] = [
                'code' => 'question_metric_ambiguous',
                'message' => '“曝光”未说明列表曝光、详情曝光或广告曝光口径，请先明确指标。',
                'matched_text' => '曝光',
            ];
        }
        if ($dataGaps === []
            && (mb_strpos($normalized, '详情访问') !== false
                || mb_strpos($normalized, '详情浏览') !== false)
            && !$hasSupportedVisitorMetric
        ) {
            $dataGaps[] = [
                'code' => 'question_metric_ambiguous',
                'message' => '“详情访问/浏览”未说明询问曝光次数还是访问用户数；请明确指标计数对象。',
                'matched_text' => mb_strpos($normalized, '详情访问') !== false ? '详情访问' : '详情浏览',
            ];
        }

        $requested = $dataGaps === []
            ? $this->matchedQuestionMetrics($normalized, $platform)
            : [];
        $definitionConflict = $dataGaps === []
            ? $this->requestedMetricDefinitionConflict(array_values($requested))
            : null;
        if (is_array($definitionConflict)) {
            $dataGaps[] = $definitionConflict;
        }
        $unparsedSemantic = $dataGaps === [] && $requested !== []
            ? $this->unparsedMetricLookupSemantic($normalized)
            : '';
        if ($unparsedSemantic !== '') {
            $dataGaps[] = [
                'code' => 'question_metric_ambiguous',
                'message' => '问题中仍有未解析的平台、日期、指标或操作语义；当前不会静默忽略后继续回答，请改为明确的原始 OTA 指标查询。',
                'matched_text' => $unparsedSemantic,
            ];
        }
        $unmatchedMetric = $dataGaps === [] ? $this->unmatchedQuantitativeMetricTerm($normalized) : '';
        if ($dataGaps === []
            && ($unmatchedMetric !== ''
                || ($requested === [] && $this->looksLikeUnresolvedMetricQuestion($normalized)))
        ) {
            $dataGaps[] = [
                'code' => 'question_metric_ambiguous',
                'message' => '问题正在询问一个尚未注册业务定义的量化指标；请明确选择当前支持的原始 OTA 指标。',
                'matched_text' => $unmatchedMetric !== '' ? $unmatchedMetric : '未注册量化指标',
            ];
        }

        $mode = $dataGaps !== [] ? 'blocked' : ($requested === [] ? 'exploratory' : 'metric_lookup');
        return [
            'contract_version' => self::METRIC_INTENT_CONTRACT_VERSION,
            'mode' => $mode,
            'status' => $dataGaps === [] ? 'resolved' : 'blocked',
            'requested_metrics' => array_values($requested),
            'required_platforms' => $platform === 'all_ota' ? self::ALL_OTA_REQUIRED_PLATFORMS : [$platform],
            'required_dates' => array_values($requiredDates),
            'action_draft_allowed' => $mode === 'metric_lookup',
            'data_gaps' => $dataGaps,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function matchedQuestionMetrics(string $question, string $platform): array
    {
        $candidates = [];
        foreach (self::QUESTION_METRIC_KEYWORDS as $metricKey => $keywords) {
            foreach ($keywords as $keyword) {
                $needle = mb_strtolower($keyword);
                $length = mb_strlen($needle);
                $offset = 0;
                while (($position = mb_strpos($question, $needle, $offset)) !== false) {
                    $candidates[] = [
                        'metric_key' => $metricKey,
                        'keyword' => $keyword,
                        'position' => $position,
                        'length' => $length,
                    ];
                    $offset = $position + max(1, $length);
                }
            }
        }
        usort($candidates, static function (array $left, array $right): int {
            $lengthOrder = (int)$right['length'] <=> (int)$left['length'];
            return $lengthOrder !== 0 ? $lengthOrder : (int)$left['position'] <=> (int)$right['position'];
        });

        $selected = [];
        foreach ($candidates as $candidate) {
            $start = (int)$candidate['position'];
            $end = $start + (int)$candidate['length'];
            $overlaps = array_filter($selected, static fn(array $item): bool =>
                $start < (int)$item['end'] && $end > (int)$item['start']
            );
            if ($overlaps !== []) {
                continue;
            }
            $candidate['start'] = $start;
            $candidate['end'] = $end;
            $selected[] = $candidate;
        }
        usort($selected, static fn(array $left, array $right): int =>
            (int)$left['position'] <=> (int)$right['position']
        );

        $requested = [];
        foreach ($selected as $candidate) {
            $metricKey = (string)$candidate['metric_key'];
            $resolvedMetricKey = $metricKey === 'comment_score' && $platform === 'qunar'
                ? 'qunar_comment_score'
                : $metricKey;
            $definitionIds = $this->questionDefinitionIds(
                $metricKey,
                (string)$candidate['keyword'],
                $platform
            );
            $requestIdentity = $resolvedMetricKey . '|' . implode(',', $definitionIds);
            if (isset($requested[$requestIdentity])) {
                continue;
            }
            $requested[$requestIdentity] = [
                'metric_key' => $resolvedMetricKey,
                'definition_ids' => $definitionIds,
                'matched_text' => (string)$candidate['keyword'],
            ];
        }
        return $requested;
    }

    /** @return list<string> */
    private function questionDefinitionIds(string $metricKey, string $keyword, string $platform): array
    {
        $explicit = self::QUESTION_KEYWORD_DEFINITION_IDS[$keyword] ?? null;
        if (is_array($explicit)) {
            return $explicit;
        }
        if ($metricKey === 'list_exposure') {
            if ($platform === 'ctrip') {
                return ['ota_list_exposure_users.v1'];
            }
            if (in_array($platform, ['meituan', 'all_ota'], true)) {
                return ['ota_list_exposure_users.v1', 'ota_list_exposure.v1'];
            }
        }
        if ($metricKey === 'detail_exposure') {
            if ($platform === 'ctrip') {
                return ['ota_detail_visitors.v1'];
            }
            if (in_array($platform, ['meituan', 'all_ota'], true)) {
                return ['ota_detail_visitors.v1', 'ota_detail_exposure.v1'];
            }
        }
        return self::QUESTION_DEFINITION_IDS[$metricKey] ?? [];
    }

    /** @param list<array<string,mixed>> $requestedMetrics @return array<string,mixed>|null */
    private function requestedMetricDefinitionConflict(array $requestedMetrics): ?array
    {
        $definitionsByMetric = [];
        foreach ($requestedMetrics as $requestedMetric) {
            $metricKey = trim((string)($requestedMetric['metric_key'] ?? ''));
            $definitionIds = array_values(array_unique(array_filter(array_map(
                'strval',
                (array)($requestedMetric['definition_ids'] ?? [])
            ))));
            sort($definitionIds, SORT_STRING);
            if ($metricKey === '' || $definitionIds === []) {
                continue;
            }
            $definitionsByMetric[$metricKey][implode('|', $definitionIds)] = true;
        }
        foreach ($definitionsByMetric as $metricKey => $definitionSignatures) {
            if (count($definitionSignatures) <= 1) {
                continue;
            }
            return [
                'code' => 'question_metric_definition_conflict',
                'message' => '问题对同一存储字段提出了多个不同业务定义；当前不会丢弃其中一项，请拆成独立问题。',
                'metric_key' => $metricKey,
                'definition_signatures' => array_keys($definitionSignatures),
            ];
        }
        return null;
    }

    /** @param list<string> $terms */
    private function containsAnyQuestionTerm(string $question, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term !== '' && mb_strpos($question, mb_strtolower($term)) !== false) {
                return true;
            }
        }
        return false;
    }

    private function unsupportedQuestionSemantic(string $question): string
    {
        foreach (self::UNSUPPORTED_QUESTION_SEMANTIC_TERMS as $term) {
            if (mb_strpos($question, mb_strtolower($term)) !== false) {
                return $term;
            }
        }
        foreach (self::UNSUPPORTED_QUESTION_SEMANTIC_PATTERNS as $pattern) {
            if (preg_match($pattern, $question, $matches) === 1) {
                return mb_substr((string)($matches[0] ?? '未支持语义'), 0, 80);
            }
        }
        return '';
    }

    private function looksLikeUnresolvedMetricQuestion(string $question): bool
    {
        return $this->containsAnyQuestionTerm($question, [
            '多少', '几单', '几间', '几晚', '数值', '指标值', '金额', '数量', '率',
            '总额', '总数', '利润', '成本', '佣金', '价格', '单价', '收藏', '点击', '浏览量',
        ]);
    }

    private function unmatchedQuantitativeMetricTerm(string $question): string
    {
        $remaining = mb_strtolower($question);
        $keywords = [];
        foreach (self::QUESTION_METRIC_KEYWORDS as $metricKeywords) {
            foreach ($metricKeywords as $keyword) {
                $keywords[] = mb_strtolower($keyword);
            }
        }
        usort($keywords, static fn(string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        $remaining = str_replace(array_values(array_unique($keywords)), ' ', $remaining);
        foreach (self::QUESTION_PLATFORM_TERMS as $terms) {
            $remaining = str_replace(array_map('mb_strtolower', $terms), ' ', $remaining);
        }
        $remaining = preg_replace('/(?<!\\d)\\d{4}(?:[-\\/.．]\\d{1,2}[-\\/.．]\\d{1,2}|年\\d{1,2}月\\d{1,2}[日号]|\\d{4})(?!\\d)/u', ' ', $remaining) ?? $remaining;
        $remaining = str_replace([
            '请问', '帮我', '帮忙', '查一下', '查询', '看看', '看下', '告诉我',
            '是多少', '有多少', '多少', '怎么样', '如何', '情况', '数据', '指标',
            '今天', '今日', '当天', '指定业务日', '业务日',
            '分别', '各自', '还有', '以及', '并且', '和', '与', '及', '的', '是',
            '？', '?', '。', '，', ',', '、', '；', ';', '：', ':', ' ', "\t", "\r", "\n",
        ], '', $remaining);
        if (preg_match('/[\\p{Han}A-Za-z][\\p{Han}A-Za-z0-9_]{0,15}(?:用户数|访客数|人数|金额|间夜|房晚|数量|总数|总额|量|率|额|价|分)/u', $remaining, $matches) === 1) {
            return mb_substr((string)$matches[0], 0, 40);
        }
        foreach (['收藏', '点赞', '点击', '访客', '用户', '几位', '几笔', '几人', '多少位', '多少笔', 'roi', 'roas'] as $term) {
            if (mb_strpos($remaining, $term) !== false) {
                return $term;
            }
        }
        return '';
    }

    private function unparsedMetricLookupSemantic(string $question): string
    {
        $remaining = mb_strtolower($question);
        $consumed = [];
        foreach (self::QUESTION_METRIC_KEYWORDS as $keywords) {
            foreach ($keywords as $keyword) {
                $consumed[] = mb_strtolower($keyword);
            }
        }
        foreach (self::QUESTION_PLATFORM_TERMS as $terms) {
            foreach ($terms as $term) {
                $consumed[] = mb_strtolower($term);
            }
        }
        $consumed = array_values(array_unique($consumed));
        usort($consumed, static fn(string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        $remaining = str_replace($consumed, ' ', $remaining);
        $remaining = preg_replace('/(?<!\\d)\\d{4}(?:[-\\/.．]\\d{1,2}[-\\/.．]\\d{1,2}|年\\d{1,2}月\\d{1,2}[日号]|\\d{4})(?!\\d)/u', ' ', $remaining) ?? $remaining;
        $fillers = [
            '最需要复核什么', '还应该复核什么', '还需要复核什么', '应该复核什么', '需要复核什么',
            '还应复核什么', '应复核什么',
            '我想知道', '想知道', '请问', '帮我', '帮忙', '查一下', '查询', '看看', '看下', '告诉我', '给我',
            '情况如何', '什么情况', '分别是多少', '各自是多少', '是多少', '有多少', '多少', '怎么样', '如何',
            '指定业务日', '业务日', '本次', '这次', '当前', '分别', '各自', '还有', '以及', '并且', '同时',
            '指标值', '数值', '数据', '指标', '情况', '结果', '答案', '和', '与', '及', '至', '从', '到',
            '请', '的', '是', '吗', '呢', '吧', '什么', '值',
        ];
        usort($fillers, static fn(string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        $remaining = str_replace($fillers, ' ', $remaining);
        $remaining = str_replace([
            '？', '?', '。', '，', ',', '、', '；', ';', '：', ':', '！', '!',
            '（', '）', '(', ')', '【', '】', '[', ']', '“', '”', '‘', '’', '"', "'", ' ', "\t", "\r", "\n",
        ], '', $remaining);
        return $remaining === '' ? '' : mb_substr($remaining, 0, 80);
    }

    /** @param list<string> $requiredDates @return list<array<string,mixed>> */
    private function questionScopeConflictGaps(string $question, string $platform, array $requiredDates): array
    {
        $mentionedPlatforms = [];
        foreach (self::QUESTION_PLATFORM_TERMS as $candidate => $terms) {
            if ($this->containsAnyQuestionTerm($question, $terms)) {
                $mentionedPlatforms[] = $candidate;
            }
        }
        $mentionsAllOta = $this->containsAnyQuestionTerm($question, [
            '全渠道', '全部渠道', '所有渠道', '各渠道', '多渠道', '全网',
            '全ota', '全 ota', '全部ota', '全部 ota', 'all_ota', 'all ota', '携程和美团', '携程与美团',
        ]);
        $expectedPlatforms = $platform === 'all_ota' ? self::ALL_OTA_REQUIRED_PLATFORMS : [$platform];
        $mentionedPlatforms = array_values(array_unique($mentionedPlatforms));
        $expectedForComparison = $expectedPlatforms;
        sort($mentionedPlatforms, SORT_STRING);
        sort($expectedForComparison, SORT_STRING);
        if (($mentionsAllOta && $platform !== 'all_ota')
            || ($mentionedPlatforms !== [] && $mentionedPlatforms !== $expectedForComparison)
        ) {
            return [[
                'code' => 'question_scope_platform_mismatch',
                'message' => '问题文本中的渠道与本次结构化查询范围不一致；请统一选择携程、美团、去哪儿或携程+美团。',
                'mentioned_platforms' => $mentionedPlatforms,
                'required_platforms' => $expectedPlatforms,
            ]];
        }

        if (preg_match(
            '/今天|今日|当天|昨日|昨天|前天|明天|后天|本周|上周|下周|本月|上月|下月|本季度|上季度|下季度|今年|去年|明年|最近|近\\s*\\d+\\s*天|未来/u',
            $question,
            $relativeMatches
        ) === 1) {
            return [[
                'code' => 'question_scope_date_ambiguous',
                'message' => '问题文本包含相对日期，当前不会猜测其与结构化业务日相同；请改用明确的 YYYY-MM-DD 日期。',
                'matched_text' => (string)($relativeMatches[0] ?? '相对日期'),
                'required_dates' => array_values($requiredDates),
            ]];
        }

        if (preg_match('/(?<![0-9年])\\d{1,2}月\\d{1,2}[日号]/u', $question, $partialDateMatches) === 1) {
            return [[
                'code' => 'question_scope_date_ambiguous',
                'message' => '问题文本中的日期缺少年份；当前不会猜测年份，请改用完整的 YYYY-MM-DD 日期。',
                'matched_text' => (string)($partialDateMatches[0] ?? '不完整日期'),
                'required_dates' => array_values($requiredDates),
            ]];
        }

        $dateResult = $this->explicitQuestionDates($question);
        if (($dateResult['invalid'] ?? false) === true) {
            return [[
                'code' => 'question_scope_date_invalid',
                'message' => '问题文本中的绝对日期无效；请使用 YYYY-MM-DD 或 YYYY年M月D日。',
            ]];
        }
        $mentionedDates = array_values((array)($dateResult['dates'] ?? []));
        $expectedDates = array_values(array_unique(array_map('strval', $requiredDates)));
        sort($mentionedDates, SORT_STRING);
        sort($expectedDates, SORT_STRING);
        if ($mentionedDates !== [] && $mentionedDates !== $expectedDates) {
            return [[
                'code' => 'question_scope_date_mismatch',
                'message' => '问题文本中的绝对日期与本次结构化业务日范围不一致；请先统一日期。',
                'mentioned_dates' => $mentionedDates,
                'required_dates' => $expectedDates,
            ]];
        }
        return [];
    }

    /** @return array{dates:list<string>,invalid:bool} */
    private function explicitQuestionDates(string $question): array
    {
        $dates = [];
        $invalid = false;
        foreach ([
            '/(?<!\\d)(\\d{4})[-\\/.．](\\d{1,2})[-\\/.．](\\d{1,2})(?!\\d)/u',
            '/(?<!\\d)(\\d{4})年(\\d{1,2})月(\\d{1,2})[日号]/u',
            '/(?<!\\d)(\\d{4})(\\d{2})(\\d{2})(?!\\d)/u',
        ] as $pattern) {
            preg_match_all($pattern, $question, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $year = (int)($match[1] ?? 0);
                $month = (int)($match[2] ?? 0);
                $day = (int)($match[3] ?? 0);
                if (!checkdate($month, $day, $year)) {
                    $invalid = true;
                    continue;
                }
                $dates[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        $dates = array_values(array_unique($dates));
        sort($dates, SORT_STRING);
        return ['dates' => $dates, 'invalid' => $invalid];
    }

    /**
     * @param list<array<string,mixed>> $facts
     * @param list<string> $requiredDates
     * @param list<array<string,mixed>> $requestedMetrics
     * @return list<array<string,mixed>>
     */
    private function requestedMetricCoverageGaps(
        array $facts,
        string $platform,
        array $requiredDates,
        array $requestedMetrics
    ): array {
        if ($requestedMetrics === []) {
            return [];
        }
        $platforms = $platform === 'all_ota' ? self::ALL_OTA_REQUIRED_PLATFORMS : [$platform];
        $gaps = [];
        foreach ($requestedMetrics as $requested) {
            if (!is_array($requested)) {
                continue;
            }
            $metricKey = trim((string)($requested['metric_key'] ?? ''));
            $definitionIds = array_values(array_map('strval', (array)($requested['definition_ids'] ?? [])));
            foreach ($platforms as $requiredPlatform) {
                foreach ($requiredDates as $requiredDate) {
                    $ready = false;
                    $reasons = [];
                    foreach ($facts as $fact) {
                        if (!is_array($fact)
                            || strtolower(trim((string)($fact['platform'] ?? ''))) !== $requiredPlatform
                            || trim((string)($fact['data_date'] ?? '')) !== $requiredDate
                        ) {
                            continue;
                        }
                        $values = is_array($fact['metric_values'] ?? null) ? $fact['metric_values'] : [];
                        $units = is_array($fact['metric_units'] ?? null) ? $fact['metric_units'] : [];
                        $definitions = is_array($fact['metric_definitions'] ?? null) ? $fact['metric_definitions'] : [];
                        $definitionId = trim((string)($definitions[$metricKey]['definition_id'] ?? ''));
                        if (array_key_exists($metricKey, $values)
                            && is_numeric($values[$metricKey])
                            && $this->isRealMetricUnit((string)($units[$metricKey] ?? ''))
                            && $this->metricValueMatchesUnit($values[$metricKey], (string)($units[$metricKey] ?? ''))
                            && in_array($definitionId, $definitionIds, true)
                        ) {
                            $ready = true;
                            break;
                        }
                        if (array_key_exists($metricKey, $values) && !in_array($definitionId, $definitionIds, true)) {
                            $reasons[] = 'metric_definition_mismatch';
                        }
                        foreach ((array)($fact['metric_gaps'] ?? []) as $gap) {
                            if (is_array($gap) && (string)($gap['metric_key'] ?? '') === $metricKey) {
                                $reasons[] = (string)($gap['reason'] ?? 'metric_fact_missing');
                            }
                        }
                    }
                    if ($ready) {
                        continue;
                    }
                    $reasons = array_values(array_unique(array_filter($reasons)));
                    $unitMissing = array_intersect($reasons, ['metric_currency_missing', 'metric_unit_missing']) !== [];
                    $valueScaleMismatch = in_array('metric_value_unit_scale_mismatch', $reasons, true);
                    $definitionMismatch = in_array('metric_definition_mismatch', $reasons, true);
                    $gaps[] = [
                        'code' => $unitMissing
                            ? 'requested_metric_unit_missing'
                            : ($valueScaleMismatch
                                ? 'requested_metric_value_scale_mismatch'
                                : ($definitionMismatch ? 'requested_metric_definition_mismatch' : 'requested_metric_fact_missing')),
                        'message' => sprintf(
                            '问题明确询问指标 %s，但%s %s 缺少同口径已回读数值、真实单位或业务指标定义。',
                            $metricKey,
                            $this->platformLabel($requiredPlatform),
                            $requiredDate
                        ),
                        'metric_key' => $metricKey,
                        'platform' => $requiredPlatform,
                        'data_date' => $requiredDate,
                        'definition_ids' => $definitionIds,
                        'reason_codes' => $reasons === [] ? ['metric_fact_missing'] : $reasons,
                    ];
                }
            }
        }
        return array_values(array_slice($gaps, 0, 40));
    }

    /** @param list<array<string,mixed>> $facts @param list<string> $requiredDates @return list<array<string,mixed>> */
    private function substantiveCoverageGaps(array $facts, string $platform, array $requiredDates): array
    {
        $platforms = $platform === 'all_ota' ? self::ALL_OTA_REQUIRED_PLATFORMS : [$platform];
        $missing = [];
        foreach ($platforms as $requiredPlatform) {
            foreach ($requiredDates as $requiredDate) {
                $ready = false;
                $reasonCodes = [];
                foreach ($facts as $fact) {
                    if (!is_array($fact)
                        || strtolower(trim((string)($fact['platform'] ?? ''))) !== $requiredPlatform
                        || trim((string)($fact['data_date'] ?? '')) !== $requiredDate
                    ) {
                        continue;
                    }
                    if ((array)($fact['metric_values'] ?? []) !== []) {
                        $ready = true;
                        break;
                    }
                    foreach ((array)($fact['metric_gaps'] ?? []) as $gap) {
                        if (is_array($gap)) {
                            $reasonCodes[] = (string)($gap['reason'] ?? 'metric_fact_missing');
                        }
                    }
                }
                if (!$ready) {
                    $missing[] = [
                        'platform' => $requiredPlatform,
                        'data_date' => $requiredDate,
                        'reason_codes' => array_values(array_unique(array_filter($reasonCodes))) ?: ['metric_fact_missing'],
                    ];
                }
            }
        }
        if ($missing === []) {
            return [];
        }
        return [[
            'code' => 'substantive_fact_coverage_missing',
            'message' => '事实行已回读，但至少一个目标渠道/日期没有同时具备业务指标定义、数值和真实单位，未调用模型。',
            'missing_scopes' => $missing,
        ]];
    }

    /** @param list<array<string,mixed>> ...$gapGroups */
    private function metricGapSummary(array ...$gapGroups): string
    {
        foreach ($gapGroups as $gaps) {
            foreach ($gaps as $gap) {
                $message = is_array($gap) ? trim((string)($gap['message'] ?? '')) : '';
                if ($message !== '') {
                    return $message . ' 当前不生成经营结论或待审批行动草案。';
                }
            }
        }
        return '当前缺少问题所需的同酒店、同渠道、同业务日指标事实，暂不生成经营结论或待审批行动草案。';
    }

    /** @param array<string,mixed> $questionRow @return array<string,mixed> */
    private function writeBoundaries(array $questionRow): array
    {
        $answer = is_array($questionRow['answer'] ?? null) ? $questionRow['answer'] : [];
        $boundaries = is_array($answer['boundaries'] ?? null) ? $answer['boundaries'] : [];
        return [
            'external_llm_called' => is_bool($boundaries['external_llm_called'] ?? null)
                ? $boundaries['external_llm_called']
                : null,
            'external_llm_call_status' => mb_substr(trim((string)($boundaries['external_llm_call_status'] ?? 'not_attempted')), 0, 80),
            'llm_attempted' => ($boundaries['llm_attempted'] ?? false) === true,
            'llm_client_invoked' => ($boundaries['llm_client_invoked'] ?? false) === true,
            'ota_write' => ($boundaries['ota_write'] ?? false) === true,
            'external_message' => ($boundaries['external_message'] ?? false) === true,
            'automatic_execution' => ($boundaries['automatic_execution'] ?? false) === true,
        ];
    }

    /**
     * @param array<string,mixed> $answer
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    private function applyAiAnswer(
        array $answer,
        array $evidence,
        string $question,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $dateStart,
        string $dateEnd,
        int $createdBy,
        string $modelKey
    ): array {
        $answer['ai_runtime'] = [
            'status' => $this->answerGenerator === null ? 'not_enabled' : 'not_called',
            'model_key' => $modelKey,
            'prompt_version' => '',
            'model_attempted' => false,
            'llm_client_invoked' => false,
            'external_llm_called' => false,
            'external_llm_call_status' => 'not_attempted',
            'provider' => '',
            'model' => '',
            'configured_model' => '',
            'response_model' => '',
            'provider_response_id' => '',
            'provider_created_at' => 0,
            'provider_response_fresh' => false,
            'provider_endpoint_origin' => '',
            'provider_endpoint_host' => '',
            'provider_endpoint_official' => false,
            'provider_config_digest' => '',
            'direct_call_nonce' => '',
            'transport_request_id' => '',
            'transport_retry_attempts' => -1,
            'upstream_idempotency_key_sent' => false,
            'http_status' => 0,
            'provider_attempt_count' => 0,
            'idempotent_replay' => false,
            'direct_request_proof' => false,
            'thinking_mode' => '',
            'reasoning_effort' => '',
            'finish_reason' => '',
            'fallback_used' => false,
            'cache_hit' => false,
            'degraded' => false,
            'reason' => '',
            'message' => $this->answerGenerator === null
                ? '当前使用严格回读的证据摘要。'
                : '尚未调用AI模型。',
        ];
        $answer['boundaries']['llm_client_invoked'] = false;
        $answer['boundaries']['external_llm_call_status'] = 'not_attempted';
        if ($this->answerGenerator === null) {
            return $answer;
        }
        if ((string)($answer['status'] ?? '') === 'blocked_by_missing_facts'
            || (int)($answer['evidence_counts']['facts'] ?? 0) <= 0
        ) {
            $answer['ai_runtime']['status'] = 'not_called_missing_facts';
            $answer['ai_runtime']['message'] = '缺少同范围严格回读事实，未调用AI模型。';
            return $answer;
        }

        try {
            $result = ($this->answerGenerator)([
                'question' => $question,
                'scope' => [
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'platform' => $platform,
                    'date_start' => $dateStart,
                    'date_end' => $dateEnd,
                    'source_scope' => 'ota_channel',
                ],
                'answer' => $answer,
                'evidence' => $evidence,
                'model_key' => $modelKey,
                'user_id' => max(0, $createdBy),
            ]);
        } catch (\Throwable) {
            $result = [
                'ok' => false,
                'status' => 'model_unavailable',
                'message' => 'AI模型暂不可用，已保留严格回读的证据摘要。',
                'model_key' => $modelKey,
                'model_attempted' => true,
                'llm_client_invoked' => true,
                'external_llm_called' => null,
                'external_llm_call_status' => 'unknown_after_client_attempt',
                'provider' => '',
                'model' => '',
                'configured_model' => '',
                'response_model' => '',
                'provider_response_id' => '',
                'provider_created_at' => 0,
                'provider_response_fresh' => false,
                'provider_endpoint_origin' => '',
                'provider_endpoint_host' => '',
                'provider_endpoint_official' => false,
                'provider_config_digest' => '',
                'direct_call_nonce' => '',
                'transport_request_id' => '',
                'transport_retry_attempts' => -1,
                'upstream_idempotency_key_sent' => false,
                'http_status' => 0,
                'provider_attempt_count' => 0,
                'idempotent_replay' => false,
                'direct_request_proof' => false,
                'thinking_mode' => '',
                'reasoning_effort' => '',
                'finish_reason' => '',
                'fallback_used' => false,
                'cache_hit' => false,
                'degraded' => false,
            ];
        }

        $externalLlmCalled = is_bool($result['external_llm_called'] ?? null)
            ? $result['external_llm_called']
            : null;
        $answer['ai_runtime'] = [
            'status' => (string)($result['status'] ?? (($result['ok'] ?? false) === true ? 'ready' : 'model_unavailable')),
            'model_key' => (string)($result['model_key'] ?? $modelKey),
            'prompt_version' => (string)($result['prompt_version'] ?? ''),
            'model_attempted' => ($result['model_attempted'] ?? false) === true,
            'llm_client_invoked' => ($result['llm_client_invoked'] ?? false) === true,
            'external_llm_called' => $externalLlmCalled,
            'external_llm_call_status' => mb_substr(trim((string)($result['external_llm_call_status'] ?? (
                $externalLlmCalled === true ? 'unverified_external_response' : 'unknown_after_client_attempt'
            ))), 0, 80),
            'provider' => mb_substr(trim((string)($result['provider'] ?? '')), 0, 50),
            'model' => mb_substr(trim((string)($result['model'] ?? '')), 0, 150),
            'configured_model' => mb_substr(trim((string)($result['configured_model'] ?? '')), 0, 150),
            'response_model' => mb_substr(trim((string)($result['response_model'] ?? '')), 0, 150),
            'provider_response_id' => $this->providerResponseId($result['provider_response_id'] ?? null),
            'provider_created_at' => max(0, (int)($result['provider_created_at'] ?? 0)),
            'provider_response_fresh' => ($result['provider_response_fresh'] ?? false) === true,
            'provider_endpoint_origin' => mb_substr(trim((string)($result['provider_endpoint_origin'] ?? '')), 0, 255),
            'provider_endpoint_host' => strtolower(mb_substr(trim((string)($result['provider_endpoint_host'] ?? '')), 0, 191)),
            'provider_endpoint_official' => ($result['provider_endpoint_official'] ?? false) === true,
            'provider_config_digest' => strtolower(mb_substr(trim((string)($result['provider_config_digest'] ?? '')), 0, 64)),
            'direct_call_nonce' => mb_substr(trim((string)($result['direct_call_nonce'] ?? '')), 0, 64),
            'transport_request_id' => mb_substr(trim((string)($result['transport_request_id'] ?? '')), 0, 64),
            'transport_retry_attempts' => (int)($result['transport_retry_attempts'] ?? -1),
            'upstream_idempotency_key_sent' => ($result['upstream_idempotency_key_sent'] ?? false) === true,
            'http_status' => max(0, (int)($result['http_status'] ?? 0)),
            'provider_attempt_count' => max(0, (int)($result['provider_attempt_count'] ?? 0)),
            'idempotent_replay' => ($result['idempotent_replay'] ?? false) === true,
            'direct_request_proof' => ($result['direct_request_proof'] ?? false) === true,
            'thinking_mode' => mb_substr(trim((string)($result['thinking_mode'] ?? '')), 0, 20),
            'reasoning_effort' => mb_substr(trim((string)($result['reasoning_effort'] ?? '')), 0, 20),
            'finish_reason' => mb_substr(trim((string)($result['finish_reason'] ?? '')), 0, 50),
            'fallback_used' => ($result['fallback_used'] ?? false) === true,
            'cache_hit' => ($result['cache_hit'] ?? false) === true,
            'degraded' => ($result['degraded'] ?? false) === true,
            'reason' => mb_substr(trim((string)($result['reason'] ?? '')), 0, 120),
            'message' => mb_substr(trim((string)($result['message'] ?? '')), 0, 300),
        ];
        $answer['boundaries']['llm_attempted'] = $answer['ai_runtime']['model_attempted'];
        $answer['boundaries']['llm_client_invoked'] = $answer['ai_runtime']['llm_client_invoked'];
        $answer['boundaries']['external_llm_called'] = $answer['ai_runtime']['external_llm_called'];
        $answer['boundaries']['external_llm_call_status'] = $answer['ai_runtime']['external_llm_call_status'];

        $factClaims = $this->validatedGeneratedClaims($result['fact_claims'] ?? null, $answer);
        $claimsDigest = strtolower((string)($result['claims_digest'] ?? ''));
        $claimsDigestReady = $factClaims !== []
            && preg_match('/^[a-f0-9]{64}$/D', $claimsDigest) === 1
            && hash_equals($claimsDigest, OperatingQuestionAiAnswerService::claimsDigest($factClaims));
        $runtime = $answer['ai_runtime'];
        $groundedRuntimeReady = (string)($runtime['status'] ?? '') === 'ready'
            && (string)($answer['question_metric_contract']['contract_version'] ?? '')
                === self::METRIC_INTENT_CONTRACT_VERSION
            && OperatingQuestionAiAnswerService::directCallProofReady($runtime)
            && (string)($runtime['prompt_version'] ?? '') === OperatingQuestionAiAnswerService::PROMPT_VERSION
            && ($runtime['external_llm_called'] ?? false) === true
            && (string)($runtime['external_llm_call_status'] ?? '')
                === OperatingQuestionAiAnswerService::DIRECT_CALL_STATUS;
        if (($result['ok'] ?? false) !== true || !$groundedRuntimeReady || !$claimsDigestReady) {
            if (is_array($result['data_gaps'] ?? null)) {
                $answer['data_gaps'] = array_values(array_merge(
                    is_array($answer['data_gaps'] ?? null) ? $answer['data_gaps'] : [],
                    array_values(array_filter($result['data_gaps'], 'is_array'))
                ));
            }
            if (($result['ok'] ?? false) === true && (!$groundedRuntimeReady || !$claimsDigestReady)) {
                $answer['data_gaps'][] = [
                    'code' => 'grounded_ai_contract_invalid',
                    'message' => '模型响应缺少可核验的直接调用凭据或逐项事实绑定，已拒绝作为可信回答。',
                ];
            }
            if ($answer['ai_runtime']['message'] === '') {
                $answer['ai_runtime']['message'] = 'AI模型未生成可核验回答，已保留严格回读的证据摘要。';
            }
            return $answer;
        }

        $answer['mode'] = 'grounded_ai_saved_evidence';
        $answer['status'] = 'answered_by_grounded_ai';
        $answer['summary'] = $this->renderClaimsSummary($factClaims);
        $answer['key_points'] = array_values(array_map(
            static fn(array $claim): string => (string)$claim['statement'],
            array_slice($factClaims, 0, 8)
        ));
        $answer['missing_information'] = array_values(array_filter(array_map(
            static fn(array $gap): string => mb_substr(trim((string)($gap['message'] ?? '')), 0, 320),
            is_array($answer['data_gaps'] ?? null) ? $answer['data_gaps'] : []
        )));
        $answer['follow_up_questions'] = $this->followUpQuestionsForClaims($factClaims);
        $answer['confidence'] = in_array((string)($result['confidence'] ?? ''), ['low', 'medium', 'high'], true)
            ? (string)$result['confidence']
            : 'low';
        $answer['used_evidence_refs'] = array_values(array_unique(array_map(
            static fn(array $claim): string => (string)$claim['evidence_ref'],
            $factClaims
        )));
        $answer['fact_claims'] = $factClaims;
        $answer['claims_digest'] = $claimsDigest;
        $actionRuntimeReady = in_array($answer['confidence'], ['medium', 'high'], true)
            && $groundedRuntimeReady
            && (string)($answer['question_metric_contract']['contract_version'] ?? '')
                === self::METRIC_INTENT_CONTRACT_VERSION
            && ($answer['question_metric_contract']['action_draft_allowed'] ?? false) === true;
        $answer['action_drafts'] = $actionRuntimeReady
            ? array_values(array_slice(array_filter(
                is_array($result['action_drafts'] ?? null) ? $result['action_drafts'] : [],
                'is_array'
            ), 0, 1))
            : [];
        return $answer;
    }

    /**
     * Revalidate the generator output at the persistence boundary. Production
     * normally receives claims already normalized by the AI answer service,
     * but a substituted generator must not be able to bypass exact readback
     * binding or author its own visible fact sentence.
     *
     * @param array<string,mixed> $answer
     * @return list<array<string,mixed>>
     */
    private function validatedGeneratedClaims(mixed $value, array $answer): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 8) {
            return [];
        }

        $factIndex = [];
        foreach ((array)($answer['fact_samples'] ?? []) as $fact) {
            if (!is_array($fact)
                || preg_match('/^online_daily_data#[1-9][0-9]*$/D', (string)($fact['ref'] ?? '')) !== 1
                || (string)($fact['quality_status'] ?? '') !== 'verified'
                || (string)($fact['history_status'] ?? '') !== 'success'
                || (string)($fact['readback_status'] ?? '') !== 'readback_verified'
                || trim((string)($fact['readback_verified_at'] ?? '')) === ''
                || trim((string)($fact['ingestion_method'] ?? '')) === ''
                || trim((string)($fact['source_trace_id'] ?? '')) === ''
            ) {
                continue;
            }
            $ref = (string)$fact['ref'];
            foreach ((array)($fact['metric_values'] ?? []) as $metricKey => $metricValue) {
                $metricKey = trim((string)$metricKey);
                $unit = (string)($fact['metric_units'][$metricKey] ?? '');
                $definition = is_array($fact['metric_definitions'][$metricKey] ?? null)
                    ? $fact['metric_definitions'][$metricKey]
                    : [];
                if ($metricKey === ''
                    || !is_numeric($metricValue)
                    || !$this->metricDefinitionMatches($metricKey, $unit, $definition)
                ) {
                    continue;
                }
                $factIndex[$ref][$metricKey] = [
                    'value' => (float)$metricValue,
                    'unit' => $unit,
                    'definition' => $definition,
                    'platform' => strtolower(trim((string)($fact['platform'] ?? ''))),
                    'data_date' => trim((string)($fact['data_date'] ?? '')),
                    'readback_verified_at' => trim((string)($fact['readback_verified_at'] ?? '')),
                    'ingestion_method' => trim((string)($fact['ingestion_method'] ?? '')),
                    'source_trace_id' => trim((string)($fact['source_trace_id'] ?? '')),
                ];
            }
        }

        $claims = [];
        $seen = [];
        foreach ($value as $raw) {
            if (!is_array($raw)) {
                return [];
            }
            $ref = (string)($raw['evidence_ref'] ?? '');
            $metricKey = (string)($raw['metric_key'] ?? '');
            $expected = $factIndex[$ref][$metricKey] ?? null;
            if (!is_array($expected)
                || (!is_int($raw['value'] ?? null) && !is_float($raw['value'] ?? null))
                || (float)$raw['value'] !== (float)$expected['value']
                || (string)($raw['unit'] ?? '') !== (string)$expected['unit']
                || (string)($raw['metric_definition_id'] ?? '')
                    !== (string)($expected['definition']['definition_id'] ?? '')
            ) {
                return [];
            }
            $identity = $ref . "\n" . $metricKey . "\n" . (string)$raw['metric_definition_id'];
            if (isset($seen[$identity])) {
                return [];
            }
            $seen[$identity] = true;
            $claim = [
                'evidence_ref' => $ref,
                'metric_key' => $metricKey,
                'metric_definition_id' => (string)$raw['metric_definition_id'],
                'source_metric_key' => (string)($expected['definition']['source_metric_key'] ?? ''),
                'metric_label' => (string)($expected['definition']['label'] ?? $this->metricLabel($metricKey)),
                'value' => (float)$expected['value'],
                'unit' => (string)$expected['unit'],
                'platform' => (string)$expected['platform'],
                'data_date' => (string)$expected['data_date'],
                'binding' => [
                    'storage_field' => (string)($expected['definition']['storage_field'] ?? ''),
                    'source_data_type' => (string)($expected['definition']['source_data_type'] ?? ''),
                    'source_key' => (string)($expected['definition']['source_key'] ?? ''),
                    'source_path_digest' => (string)($expected['definition']['source_path_digest'] ?? ''),
                    'field_fact_digest' => (string)($expected['definition']['field_fact_digest'] ?? ''),
                    'readback_verified_at' => (string)$expected['readback_verified_at'],
                    'ingestion_method' => (string)$expected['ingestion_method'],
                    'source_trace_id_digest' => hash('sha256', (string)$expected['source_trace_id']),
                ],
            ];
            $claim['claim_id'] = 'claim-' . substr(hash('sha256', json_encode(
                $claim,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            )), 0, 16);
            $claim['statement'] = $this->renderClaimStatement($claim);
            if (!hash_equals($this->digest($claim), $this->digest($raw))) {
                return [];
            }
            $claims[] = $claim;
        }

        $metricContract = is_array($answer['question_metric_contract'] ?? null)
            ? $answer['question_metric_contract']
            : [];
        if ((string)($metricContract['contract_version'] ?? '') !== self::METRIC_INTENT_CONTRACT_VERSION) {
            return [];
        }
        if ((string)($metricContract['mode'] ?? '') === 'metric_lookup') {
            foreach ($claims as $claim) {
                if (!$this->claimRequestedByMetricContract($claim, $metricContract)) {
                    return [];
                }
            }
        }
        foreach ((array)($metricContract['requested_metrics'] ?? []) as $requested) {
            if (!is_array($requested)) {
                continue;
            }
            foreach ((array)($metricContract['required_platforms'] ?? []) as $requiredPlatform) {
                foreach ((array)($metricContract['required_dates'] ?? []) as $requiredDate) {
                    $found = array_filter($claims, static fn(array $claim): bool =>
                        (string)$claim['metric_key'] === (string)($requested['metric_key'] ?? '')
                        && in_array((string)$claim['metric_definition_id'], (array)($requested['definition_ids'] ?? []), true)
                        && (string)$claim['platform'] === (string)$requiredPlatform
                        && (string)$claim['data_date'] === (string)$requiredDate
                    );
                    if ($found === []) {
                        return [];
                    }
                }
            }
        }
        return $claims;
    }

    /** @param array<string,mixed> $claim @param array<string,mixed> $contract */
    private function claimRequestedByMetricContract(array $claim, array $contract): bool
    {
        foreach ((array)($contract['requested_metrics'] ?? []) as $requested) {
            if (is_array($requested)
                && (string)($claim['metric_key'] ?? '') === (string)($requested['metric_key'] ?? '')
                && in_array(
                    (string)($claim['metric_definition_id'] ?? ''),
                    array_values(array_map('strval', (array)($requested['definition_ids'] ?? []))),
                    true
                )
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string,mixed>> $claims */
    private function renderClaimsSummary(array $claims): string
    {
        $statements = array_values(array_map(
            static fn(array $claim): string => (string)$claim['statement'],
            array_slice($claims, 0, 8)
        ));
        $suffix = '。';
        return mb_substr('基于同酒店、同渠道、同业务日严格回读事实：' . implode('；', $statements) . $suffix, 0, 1500);
    }

    /** @param array<string,mixed> $claim */
    private function renderClaimStatement(array $claim): string
    {
        return sprintf(
            '%s %s%s为%s%s [%s]',
            (string)($claim['data_date'] ?? ''),
            $this->platformLabel((string)($claim['platform'] ?? '')),
            trim((string)($claim['metric_label'] ?? '')) !== ''
                ? (string)$claim['metric_label']
                : $this->metricLabel((string)($claim['metric_key'] ?? '')),
            $this->formatMetricValue($claim['value'] ?? 0),
            $this->unitLabel((string)($claim['unit'] ?? '')),
            (string)($claim['evidence_ref'] ?? '')
        );
    }

    /** @param list<array<string,mixed>> $claims @return list<string> */
    private function followUpQuestionsForClaims(array $claims): array
    {
        $label = trim((string)($claims[0]['metric_label'] ?? '该指标')) ?: '该指标';
        return [
            '是否需要继续按同一酒店、同一渠道和业务日期复核' . $label . '的已保存来源变化？',
        ];
    }

    private function metricLabel(string $metricKey): string
    {
        return match ($metricKey) {
            'amount' => '渠道金额',
            'quantity' => '渠道数量',
            'book_order_num' => '订单数',
            'comment_score', 'qunar_comment_score' => '点评分',
            'data_value' => '来源指标值',
            'list_exposure' => '列表曝光',
            'detail_exposure' => '详情曝光',
            'flow_rate' => '转化率',
            'order_filling_num' => '填单数',
            'order_submit_num' => '提交订单数',
            default => $metricKey,
        };
    }

    private function formatMetricValue(mixed $value): string
    {
        $number = (float)$value;
        return floor($number) === $number
            ? (string)(int)$number
            : rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
    }

    private function unitLabel(string $unit): string
    {
        return match ($unit) {
            'exposure_count' => '次',
            'order_count' => '单',
            'count' => '个',
            'score' => '分',
            'score_5_point' => '分（5分制）',
            'room_night_count' => '间夜',
            'visitor_count' => '人',
            'percent' => '%',
            'ratio_0_1' => '（0-1比例）',
            'CNY' => '元（CNY）',
            default => ' ' . $unit,
        };
    }

    /** @return list<string> */
    private function textList(mixed $value, int $limit, int $length): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_slice(array_unique(array_filter(array_map(
            static fn(mixed $item): string => mb_substr(trim((string)$item), 0, $length),
            $value
        ))), 0, $limit));
    }

    private function modelKey(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return OperatingQuestionAiAnswerService::DIRECT_MODEL_KEY;
        }
        if (in_array($value, [
            OperatingQuestionAiAnswerService::DIRECT_MODEL_KEY,
            OperatingQuestionAiAnswerService::DIRECT_MODEL_NAME,
            'deepseek_reasoner',
            'deepseek-reasoner',
        ], true)) {
            return OperatingQuestionAiAnswerService::DIRECT_MODEL_KEY;
        }
        throw new InvalidArgumentException('经营问答只允许 DeepSeek V4 Pro 直接模型，已拒绝其他模型或客户端降级选择');
    }

    private function providerResponseId(mixed $value): string
    {
        return is_string($value)
            && strlen($value) <= 191
            && strlen($value) >= 8
            && preg_match('/^[A-Za-z0-9._:-]{8,191}$/D', $value) === 1
                ? $value
                : '';
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

    /**
     * Build a deterministic, read-only route out of a missing-facts answer.
     * The model never chooses these actions and none of them starts collection
     * or writes OTA data. Each action remains bound to the saved question scope.
     *
     * @param list<string> $requiredDates
     * @param array<string,list<string>> $factPlatformDates
     * @return array<string,mixed>
     */
    private function buildRecoveryPlan(
        string $answerStatus,
        int $hotelId,
        string $platform,
        string $dateStart,
        string $dateEnd,
        array $requiredDates,
        array $factPlatformDates
    ): array {
        $scope = [
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
        ];
        if ($answerStatus !== 'blocked_by_missing_facts') {
            return [
                'contract_version' => 'operating_question_recovery.v1',
                'status' => 'not_required',
                'scope' => $scope,
                'missing_items' => [],
                'actions' => [],
                'boundaries' => $this->recoveryBoundaries(),
            ];
        }

        $requiredPlatforms = $platform === 'all_ota'
            ? self::ALL_OTA_REQUIRED_PLATFORMS
            : [$platform];
        $missingItems = [];
        foreach ($requiredPlatforms as $requiredPlatform) {
            $coveredDates = array_values(array_unique(array_map(
                'strval',
                is_array($factPlatformDates[$requiredPlatform] ?? null)
                    ? $factPlatformDates[$requiredPlatform]
                    : []
            )));
            foreach ($requiredDates as $requiredDate) {
                if (in_array($requiredDate, $coveredDates, true)) {
                    continue;
                }
                $missingItems[] = [
                    'platform' => $requiredPlatform,
                    'date' => $requiredDate,
                    'required_gate' => 'history_success+validation_verified+readback_verified',
                ];
            }
        }

        $actions = [];
        $firstMissingDateByPlatform = [];
        foreach ($missingItems as $item) {
            $itemPlatform = (string)$item['platform'];
            $firstMissingDateByPlatform[$itemPlatform] ??= (string)$item['date'];
        }
        foreach ($firstMissingDateByPlatform as $missingPlatform => $missingDate) {
            if (!in_array($missingPlatform, self::ALL_OTA_REQUIRED_PLATFORMS, true)) {
                continue;
            }
            $label = $this->platformLabel($missingPlatform);
            $actions[] = [
                'key' => 'open_data_health',
                'label' => '查看' . $label . '数据健康',
                'read_only' => true,
                'platform' => $missingPlatform,
                'date' => $missingDate,
                'target_page' => 'online-data',
                'target_tab' => 'data-health',
            ];
            $actions[] = [
                'key' => 'open_platform_collection_status',
                'label' => '查看' . $label . '采集状态',
                'read_only' => true,
                'platform' => $missingPlatform,
                'date' => $missingDate,
                'target_page' => $missingPlatform === 'ctrip' ? 'ctrip-ebooking' : 'meituan-ebooking',
                'target_tab' => $missingPlatform === 'ctrip' ? 'data-health' : 'meituan-ranking',
            ];
        }
        $actions[] = [
            'key' => 'recheck',
            'label' => '重新核验证据',
            'read_only' => true,
        ];

        return [
            'contract_version' => 'operating_question_recovery.v1',
            'status' => 'waiting_for_verified_fact',
            'scope' => $scope,
            'missing_items' => $missingItems,
            'actions' => $actions,
            'boundaries' => $this->recoveryBoundaries(),
        ];
    }

    /** @return array<string,bool> */
    private function recoveryBoundaries(): array
    {
        return [
            'model_generated_actions' => false,
            'automatic_collection' => false,
            'ota_write' => false,
            'automatic_execution' => false,
        ];
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

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function assertReadbackDigest(array $row): array
    {
        $answer = is_array($row['answer'] ?? null) ? $row['answer'] : [];
        $stored = strtolower(trim((string)($row['content_digest'] ?? '')));
        $expected = $this->digest([
            'question' => (string)($row['question_text'] ?? ''),
            'answer' => $answer,
            'fact_refs' => array_values((array)($row['fact_refs'] ?? [])),
            'memory_refs' => array_values((array)($row['memory_refs'] ?? [])),
            'knowledge_refs' => array_values((array)($row['knowledge_refs'] ?? [])),
            'execution_refs' => array_values((array)($row['execution_refs'] ?? [])),
        ]);
        $scope = is_array($answer['scope'] ?? null) ? $answer['scope'] : [];
        if (preg_match('/^[a-f0-9]{64}$/D', $stored) !== 1
            || !hash_equals($stored, $expected)
            || (string)($row['answer_status'] ?? '') !== (string)($answer['status'] ?? '')
            || (string)($row['answer_summary'] ?? '') !== (string)($answer['summary'] ?? '')
            || (int)($row['tenant_id'] ?? 0) !== (int)($scope['tenant_id'] ?? 0)
            || (int)($row['hotel_id'] ?? 0) !== (int)($scope['hotel_id'] ?? 0)
            || (string)($row['platform'] ?? '') !== (string)($scope['platform'] ?? '')
            || (string)($row['date_start'] ?? '') !== (string)($scope['date_start'] ?? '')
            || (string)($row['date_end'] ?? '') !== (string)($scope['date_end'] ?? '')
            || $this->canonicalize((array)($row['data_gaps'] ?? []))
                !== $this->canonicalize((array)($answer['data_gaps'] ?? []))
        ) {
            throw new RuntimeException('经营问题按ID回读内容摘要校验失败（question_readback_digest_mismatch）');
        }
        $row['readback_verified'] = true;
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
