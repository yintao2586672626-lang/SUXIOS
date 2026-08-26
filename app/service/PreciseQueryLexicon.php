<?php
declare(strict_types=1);

namespace app\service;

/**
 * Small, versioned runtime projection of the user's 2,990-term Typeless
 * dictionary. The source dictionary is recognition/reference material only;
 * none of its terms are promoted to hotel facts by this class.
 */
final class PreciseQueryLexicon
{
    public const CONTRACT_VERSION = 'suxi_precise_query_lexicon.v1';
    public const SOURCE_TOTAL_TERMS = 2990;
    public const SOURCE_SHA256 = 'e6fb5e15e711fc1c1e29202dfabe08c7f69daa5ca3cbe9df9ef9a528e6032e53';
    public const SOURCE_CAPTURED_AT = '2026-08-25';

    /**
     * These strings were checked byte-for-byte against the source CSV. Date
     * grammar and question syntax live in separate rules below and are not
     * misreported as extracted Typeless entries.
     *
     * @var list<string>
     */
    private const EXTRACTED_TERMS = [
        '宿析OS', '宿析', 'SUXIOS', 'HOTEL', 'SUXIOS/HOTEL',
        '酒店选择器', '门店选择器', '默认酒店',
        '携程', 'Ctrip', 'eBooking', '生意通', '携程数据',
        '美团', 'Meituan', '美团酒店商家端', '美团酒店管理系统', '美团HMS',
        'OTA', '全渠道运营', '业务日期', '同酒店同日期',
        '曝光量', '列表曝光', '详情曝光', '曝光转化率', '流量转化率',
        '房费收入', '客房收入', '订单量', '支付订单量', '平均房价',
        'ADR', 'OCC', '出租率', 'RevPAR', '佣金率', '取消率', '间夜量',
        '可售客房数', 'Rooms Sold', 'Rooms Available', 'Room Revenue', 'Total Revenue',
        'AI经营日报', 'AI日报', '数据健康', '收益分析中心', '运营优化台',
        '任务执行与复盘', '酒店AI工具箱', '知识中心', '知识与经验',
        'Typeless', 'Typeless词典', 'Typeless新词', '新词导入', '导入CSV',
        '总词库', '个人词典', '经营问答', '经营工作台', '今日经营工作台',
        '线上数据', 'OTA数据与采集', '自动采集设置', 'PMS经营数据',
        '自动化运行监控', '系统设置', '门店管理', '目标与事实', '员工管理',
        '角色权限', '数据配置', '操作记录', '决策审计', '企业微信通知',
        '真实数据', '合成数据', '数据来源状态', '数据新鲜度', '证据链',
        '可信事实', '严格事实', '事实层', '收益语义层', 'OTA语义层',
        '经营语义层', '采集证据', '质量校验', '指标计算', '收益诊断',
        'exact readback', 'exact GET readback', 'exact DB readback', 'strict_readback',
        'readback_verified', 'saved_and_readback_verified', '部分可用',
        '数据质量门禁阻塞', '采集失败', '数据断档', 'reference_only',
        'pending_approval', '人工确认', '数据异常', '当前收入', 'Obsidian', 'Codex', 'ChatGPT',
    ];

    /** @var array<string,list<string>> */
    private const PLATFORM_ALIASES = [
        'all_ota' => ['携程和美团', '携程美团', '两个平台', '两平台', '哪个平台', '全部ota', '全ota', '所有ota'],
        'meituan' => ['美团酒店管理系统', '美团酒店商家端', '美团hms', 'meituan', '美团'],
        'ctrip' => ['携程数据', '生意通', 'ebooking', 'ctrip', '携程'],
    ];

    /** @var array<string,list<string>> */
    private const METRIC_ALIASES = [
        'exposure_to_visit_rate' => ['曝光到访率', '曝光到详情率', '曝光到访问率', '曝光转化率', '流量转化率', '曝光到访', '曝光转化'],
        'detail_exposure' => ['详情访客', '详情访问', '浏览人数', '浏览访客', '来了多少访客', '多少访客', '访客量', '访客数', '访客', '详情曝光'],
        'list_exposure' => ['整体曝光量', '总曝光量', '列表曝光', '曝光人数', '曝光量', '曝光'],
        'room_revenue' => ['全酒店收入', '房费收入', '客房收入', '营业收入', '收入', '营收'],
        'amount' => ['ota成交额', '渠道成交额', '销售额', '成交额', '成交金额'],
        'book_order_num' => ['支付订单量', '支付订单数', '订单量', '订单数', '订单'],
        'quantity' => ['销售间夜', '间夜量', '间夜数', '间夜'],
        'adr' => ['平均房价', 'adr'],
        'occ' => ['入住率', '出租率', 'occ'],
        'revpar' => ['revpar'],
    ];

    /** @var array<string,list<string>> */
    private const SYSTEM_TOPIC_ALIASES = [
        'typeless-dictionary' => ['typeless总词库', 'typeless词库', 'typeless词典', 'typeless新词', '总词库', '个人词典', '新词导入', '导入csv'],
        'ai-daily-report' => ['可信经营播报', '可信播报', '复制播报稿', '经营播报', 'ai经营日报', 'ai日报'],
        'knowledge-search' => ['知识与经验', '知识中心', '知识库', '操作手册', 'sop'],
        'data-health' => ['数据健康', '采集失败', '数据断档', '数据质量门禁阻塞'],
        'revenue-report' => ['收益分析中心', '经营问答'],
        'operation-optimizer' => ['运营优化台'],
        'operations' => ['任务执行与复盘'],
        'agent-toolbox' => ['酒店ai工具箱'],
        'auto-collect' => ['自动采集设置'],
        'automation-monitor' => ['自动化运行监控'],
        'hotel-settings' => ['门店管理'],
        'operating-targets' => ['目标与事实'],
        'team-permissions' => ['员工管理'],
        'role-permissions' => ['角色权限'],
        'data-settings' => ['数据配置'],
        'operation-audit' => ['操作记录'],
        'decision-audit' => ['决策审计'],
        'notifications' => ['企业微信通知'],
    ];

    /** @var list<string> */
    private const NAVIGATION_CUES = [
        '在哪里', '在哪', '哪个页面', '怎么用', '怎么操作', '怎么复制', '怎么更新',
        '下一步点什么', '下一步', '点什么', '打开', '进入', '复制', '导入', '维护', '设置',
    ];

    /** @var list<string> */
    private const TERM_QUESTION_CUES = [
        '是什么意思', '什么意思', '是什么', '指什么', '是酒店指标吗', '是指标吗',
        '属于酒店指标吗', '解释一下', '定义是什么',
    ];

    /** @var array<string,array<string,mixed>> */
    private const REFERENCE_DEFINITIONS = [
        'openness' => [
            'term' => 'Openness',
            'definition' => 'Openness 不是宿析OS酒店经营指标；它只出现在个人语境中的疑似转写说明，不能进入经营事实或指标计算。',
            'source' => '个人工作语境与宿析OS酒店词汇层',
            'category' => 'personal_context_term',
        ],
        'typeless' => [
            'term' => 'Typeless',
            'definition' => 'Typeless 在当前语境中是个人输入词典工具；词条只帮助识别和检索，不代表酒店事实或业务定义。',
            'source' => '2,990条Typeless总词库',
            'category' => 'personal_tool_term',
        ],
        'adr' => [
            'term' => 'ADR',
            'definition' => 'ADR 是平均房价，必须使用同一范围的房费收入除以出租间夜；任一输入缺失时不可计算。',
            'source' => '宿析OS收益语义层',
            'category' => 'hotel_metric_term',
        ],
        'occ' => [
            'term' => 'OCC',
            'definition' => 'OCC 是入住率或出租率，必须使用同一范围的出租间夜除以可售房夜。',
            'source' => '宿析OS收益语义层',
            'category' => 'hotel_metric_term',
        ],
        'revpar' => [
            'term' => 'RevPAR',
            'definition' => 'RevPAR 是每间可售房收入，必须使用同一范围的房费收入除以可售房夜。',
            'source' => '宿析OS收益语义层',
            'category' => 'hotel_metric_term',
        ],
        'ota' => [
            'term' => 'OTA',
            'definition' => 'OTA 是携程、美团等在线旅游平台渠道；OTA数据只代表对应渠道，不能自动扩大为全酒店经营事实。',
            'source' => '宿析OS OTA语义层',
            'category' => 'hotel_channel_term',
        ],
        '曝光转化率' => [
            'term' => '曝光转化率',
            'definition' => '在本入口中指同酒店、同平台、同业务日期的详情访客数除以列表曝光量；任一输入缺失时不可计算。',
            'source' => '宿析OS OTA收益语义层',
            'category' => 'hotel_metric_term',
        ],
    ];

    /** @return list<string> */
    public static function extractedTerms(): array
    {
        return array_values(array_unique(self::EXTRACTED_TERMS, SORT_STRING));
    }

    /** @return array<string,mixed> */
    public static function metadata(): array
    {
        $metadata = (new SemanticGlossaryService())->metadata();
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'semantic_contract_version' => $metadata['contract_version'],
            'glossary_version' => $metadata['glossary_version'],
            'source_total_terms' => $metadata['source_term_count'],
            'runtime_extracted_term_count' => count(self::extractedTerms()),
            'source_sha256' => $metadata['source_sha256'],
            'pack_sha256' => $metadata['pack_sha256'],
            'source_captured_at' => self::SOURCE_CAPTURED_AT,
            'usage_policy' => 'recognition_and_routing_reference_only',
            'business_fact_eligible' => false,
        ];
    }

    public static function normalize(string $value): string
    {
        return SemanticGlossaryService::normalize($value);
    }

    public static function platform(string $query): string
    {
        $resolution = (new SemanticGlossaryService())->resolve($query);
        $resolved = trim((string)($resolution['detected_platform'] ?? $resolution['effective_platform'] ?? ''));
        return in_array($resolved, ['ctrip', 'meituan', 'all_ota'], true)
            ? $resolved
            : self::firstMappedKey($query, self::PLATFORM_ALIASES);
    }

    public static function metric(string $query): string
    {
        $resolution = (new SemanticGlossaryService())->resolve($query);
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $semanticKey = ($resolution['status'] ?? '') === 'matched'
            ? trim((string)($primary['metric_key'] ?? ''))
            : '';
        $compatibility = [
            'ota_exposure_volume' => 'list_exposure',
            'ctrip_detail_visitors' => 'detail_exposure',
            'meituan_detail_visitors' => 'detail_exposure',
            'exposure_to_visit_rate' => 'exposure_to_visit_rate',
            'room_revenue' => 'room_revenue',
            'room_nights' => 'quantity',
            'adr' => 'adr',
            'occ' => 'occ',
            'revpar' => 'revpar',
        ];
        return $compatibility[$semanticKey] ?? self::firstMappedKey($query, self::METRIC_ALIASES);
    }

    public static function systemTopic(string $query): string
    {
        $explicitTopic = self::firstMappedKey($query, self::SYSTEM_TOPIC_ALIASES);
        if ($explicitTopic !== '') {
            return $explicitTopic;
        }
        $resolution = (new SemanticGlossaryService())->resolve($query);
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        return ($resolution['status'] ?? '') === 'matched'
            ? trim((string)($primary['assistant_topic_key'] ?? ''))
            : '';
    }

    public static function isNavigationQuestion(string $query): bool
    {
        return self::systemTopic($query) !== '' && self::containsAny($query, self::NAVIGATION_CUES);
    }

    public static function isTermQuestion(string $query): bool
    {
        return self::containsAny($query, self::TERM_QUESTION_CUES);
    }

    /** @return array<string,mixed>|null */
    public static function referenceDefinition(string $term): ?array
    {
        $normalized = self::normalize($term);
        if (isset(self::REFERENCE_DEFINITIONS[$normalized])) {
            return self::REFERENCE_DEFINITIONS[$normalized];
        }
        foreach (self::REFERENCE_DEFINITIONS as $key => $definition) {
            if ($normalized === self::normalize((string)($definition['term'] ?? $key))) {
                return $definition;
            }
        }
        $resolution = (new SemanticGlossaryService())->resolve($term);
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        if (($resolution['status'] ?? '') !== 'matched' || $primary === []) {
            return null;
        }
        return [
            'term' => (string)$primary['canonical_term'],
            'aliases' => array_values((array)$primary['aliases']),
            'definition' => (string)$primary['definition'],
            'source' => (string)$primary['source_file'],
            'source_sha256' => (string)$primary['source_fingerprint'],
            'category' => (string)$primary['category'],
            'metric_key' => $primary['metric_key'],
            'route_key' => $primary['route_key'],
            'risk_boundary' => (array)$primary['risk_boundary'],
        ];
    }

    /** @param array<string,list<string>> $map */
    private static function firstMappedKey(string $query, array $map): string
    {
        $normalized = self::normalize($query);
        $matches = [];
        foreach ($map as $key => $aliases) {
            foreach ($aliases as $alias) {
                $needle = self::normalize($alias);
                if ($needle !== '' && str_contains($normalized, $needle)) {
                    $matches[] = ['key' => $key, 'length' => mb_strlen($needle)];
                }
            }
        }
        if ($matches === []) {
            return '';
        }
        usort($matches, static fn(array $left, array $right): int => $right['length'] <=> $left['length']);
        return (string)$matches[0]['key'];
    }

    /** @param list<string> $needles */
    private static function containsAny(string $query, array $needles): bool
    {
        $normalized = self::normalize($query);
        foreach ($needles as $needle) {
            $candidate = self::normalize($needle);
            if ($candidate !== '' && str_contains($normalized, $candidate)) {
                return true;
            }
        }
        return false;
    }
}
