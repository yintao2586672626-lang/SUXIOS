<?php
declare(strict_types=1);

namespace app\service;

final class CtripCaptureDiagnosisService
{
    public static function buildCaptureCounts(array $payload, int $businessCount = 0, int $trafficCount = 0): array
    {
        $standardRows = is_array($payload['standard_rows'] ?? null) ? $payload['standard_rows'] : [];
        $endpointCandidates = is_array($payload['endpoint_candidates'] ?? null) ? $payload['endpoint_candidates'] : [];
        $p3EvidenceDrafts = is_array($payload['p3_evidence_drafts'] ?? null) ? $payload['p3_evidence_drafts'] : [];
        $pages = is_array($payload['pages'] ?? null) ? $payload['pages'] : [];
        $byDataType = [];
        $bySection = [];
        foreach ($standardRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dataType = strtolower(trim((string)($row['data_type'] ?? ''))) ?: 'unknown';
            $captureSection = strtolower(trim((string)($row['capture_section'] ?? ''))) ?: 'unknown';
            $byDataType[$dataType] = ($byDataType[$dataType] ?? 0) + 1;
            $bySection[$captureSection] = ($bySection[$captureSection] ?? 0) + 1;
        }
        ksort($byDataType);
        ksort($bySection);

        $candidateBySection = [];
        foreach ($endpointCandidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidateSection = strtolower(trim((string)($candidate['candidate_section'] ?? ''))) ?: 'unknown';
            $candidateBySection[$candidateSection] = ($candidateBySection[$candidateSection] ?? 0) + 1;
        }
        ksort($candidateBySection);

        $p3EvidenceBySection = [];
        $p3EvidenceByStatus = [];
        $p3EvidenceReady = 0;
        foreach ($p3EvidenceDrafts as $draft) {
            if (!is_array($draft)) {
                continue;
            }
            $draftSection = strtolower(trim((string)($draft['candidate_section'] ?? ''))) ?: 'unknown';
            $draftStatus = strtolower(trim((string)($draft['evidence_status'] ?? ''))) ?: 'unknown';
            $p3EvidenceBySection[$draftSection] = ($p3EvidenceBySection[$draftSection] ?? 0) + 1;
            $p3EvidenceByStatus[$draftStatus] = ($p3EvidenceByStatus[$draftStatus] ?? 0) + 1;
            if (($draft['catalog_ready'] ?? false) === true || $draftStatus === 'complete_redacted') {
                $p3EvidenceReady++;
            }
        }
        ksort($p3EvidenceBySection);
        ksort($p3EvidenceByStatus);

        $interactionBySection = [];
        $interactionPlanned = 0;
        $interactionClicked = 0;
        $interactionSkipped = 0;
        $interactionError = 0;
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $pageSection = strtolower(trim((string)($page['name'] ?? $page['section'] ?? ''))) ?: 'unknown';
            if (!isset($interactionBySection[$pageSection])) {
                $interactionBySection[$pageSection] = [
                    'pages' => 0,
                    'planned' => 0,
                    'clicked' => 0,
                    'skipped' => 0,
                    'error' => 0,
                ];
            }
            $interactionBySection[$pageSection]['pages']++;
            $interactions = is_array($page['interactions'] ?? null) ? $page['interactions'] : [];
            foreach ($interactions as $interaction) {
                if (!is_array($interaction)) {
                    continue;
                }
                $interactionPlanned++;
                $interactionBySection[$pageSection]['planned']++;
                if (($interaction['clicked'] ?? false) === true) {
                    $interactionClicked++;
                    $interactionBySection[$pageSection]['clicked']++;
                    continue;
                }
                if (trim((string)($interaction['error'] ?? '')) !== '') {
                    $interactionError++;
                    $interactionBySection[$pageSection]['error']++;
                    continue;
                }
                $interactionSkipped++;
                $interactionBySection[$pageSection]['skipped']++;
            }
        }
        ksort($interactionBySection);

        return [
            'business' => $businessCount,
            'traffic' => $trafficCount,
            'standard_rows' => count($standardRows),
            'catalog_facts' => self::countPayloadSection($payload, 'catalog_facts'),
            'responses' => self::countPayloadSection($payload, 'responses'),
            'xhr_urls' => self::countPayloadSection($payload, 'xhr_urls'),
            'pages' => count($pages),
            'interaction_planned' => $interactionPlanned,
            'interaction_clicked' => $interactionClicked,
            'interaction_skipped' => $interactionSkipped,
            'interaction_error' => $interactionError,
            'endpoint_candidates' => count($endpointCandidates),
            'p3_evidence_drafts' => count($p3EvidenceDrafts),
            'p3_evidence_ready' => $p3EvidenceReady,
            'standard_by_data_type' => $byDataType,
            'standard_by_section' => $bySection,
            'interaction_by_section' => $interactionBySection,
            'candidate_by_section' => $candidateBySection,
            'p3_evidence_by_section' => $p3EvidenceBySection,
            'p3_evidence_by_status' => $p3EvidenceByStatus,
        ];
    }

    public static function buildFactRowCountPayload(array $capturedCounts, int $savedCount, int $parsedRowCount): array
    {
        return [
            'saved_fact_row_count' => $savedCount,
            'parsed_fact_row_count' => $parsedRowCount,
            'standard_row_count' => (int)($capturedCounts['standard_rows'] ?? 0),
            'catalog_fact_count' => (int)($capturedCounts['catalog_facts'] ?? 0),
            'legacy_business_row_count' => (int)($capturedCounts['business'] ?? 0),
            'legacy_traffic_row_count' => (int)($capturedCounts['traffic'] ?? 0),
        ];
    }

    public static function buildDiagnosisSummary(array $payload): array
    {
        $metricLabels = self::metricLabels();
        $capturedMetrics = [];

        foreach (is_array($payload['catalog_facts'] ?? null) ? $payload['catalog_facts'] : [] as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            self::addMetricKey($capturedMetrics, (string)($fact['metric_key'] ?? ''));
        }

        foreach (is_array($payload['standard_rows'] ?? null) ? $payload['standard_rows'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            self::addMetricKey($capturedMetrics, (string)($row['metric_key'] ?? ''));
            self::addMetricKey($capturedMetrics, self::metricKeyFromDimension((string)($row['dimension'] ?? '')));
            $rawData = $row['raw_data'] ?? [];
            if (is_string($rawData) && $rawData !== '') {
                $decoded = json_decode($rawData, true);
                $rawData = is_array($decoded) ? $decoded : [];
            }
            if (is_array($rawData['metrics'] ?? null)) {
                foreach (array_keys($rawData['metrics']) as $metricKey) {
                    self::addMetricKey($capturedMetrics, (string)$metricKey);
                }
            }
        }

        ksort($capturedMetrics);

        $groups = [];
        foreach (self::diagnosisGroups() as $groupName => $expectedMetricKeys) {
            $matched = [];
            foreach ($expectedMetricKeys as $metricKey) {
                if (isset($capturedMetrics[$metricKey])) {
                    $matched[] = $metricKey;
                }
            }
            $groups[] = [
                'name' => $groupName,
                'status' => count($matched) > 0 ? 'available' : 'missing',
                'captured_count' => count($matched),
                'expected_count' => count($expectedMetricKeys),
                'captured_metric_keys' => $matched,
                'captured_metrics' => array_map(
                    fn(string $metricKey): array => [
                        'key' => $metricKey,
                        'label' => $metricLabels[$metricKey] ?? $metricKey,
                    ],
                    $matched
                ),
            ];
        }

        $availableGroups = array_values(array_map(
            static fn(array $group): string => (string)$group['name'],
            array_filter($groups, static fn(array $group): bool => ($group['status'] ?? '') === 'available')
        ));
        $missingGroups = array_values(array_map(
            static fn(array $group): string => (string)$group['name'],
            array_filter($groups, static fn(array $group): bool => ($group['status'] ?? '') !== 'available')
        ));

        $counts = self::buildCaptureCounts($payload);
        return [
            'status' => count($availableGroups) > 0 && (int)$counts['standard_rows'] > 0 ? 'ready' : 'not_ready',
            'available_groups' => $availableGroups,
            'missing_groups' => $missingGroups,
            'groups' => $groups,
            'captured_metric_keys' => array_keys($capturedMetrics),
            'captured_metrics' => array_map(
                fn(string $metricKey): array => [
                    'key' => $metricKey,
                    'label' => $metricLabels[$metricKey] ?? $metricKey,
                ],
                array_keys($capturedMetrics)
            ),
        ];
    }

    public static function addMetricKey(array &$capturedMetrics, string $metricKey): void
    {
        $metricKey = strtolower(trim($metricKey));
        if ($metricKey === '') {
            return;
        }
        foreach (preg_split('/[+,\|]/', $metricKey) ?: [] as $part) {
            $part = strtolower(trim((string)$part));
            if ($part !== '') {
                $capturedMetrics[$part] = true;
            }
        }
    }

    public static function metricKeyFromDimension(string $dimension): string
    {
        if (preg_match('/^catalog:[^:]+:[^:]+:([^:]+):/', $dimension, $matches)) {
            return strtolower(trim((string)$matches[1]));
        }
        return '';
    }

    public static function diagnosisGroups(): array
    {
        return [
            '收益销售' => ['order_count', 'room_nights', 'room_nights_last_week', 'quantity_rank', 'order_amount', 'order_amount_last_week', 'amount_rank', 'avg_price', 'avg_price_last_week', 'avg_price_rank', 'close_rate', 'close_rate_last_week', 'close_rate_rank', 'occupancy_rate', 'tensity'],
            '流量转化' => ['visitor_count', 'list_exposure', 'detail_visitor', 'order_page_visitor', 'order_submit_user', 'flow_rate', 'conversion_rate'],
            '竞争圈' => ['rank', 'competitor_average', 'common_view_rate', 'loss_order_count', 'loss_room_nights', 'loss_order_amount'],
            '服务质量/IM' => ['psi_score', 'service_score_rank', 'base_score', 'reward_score', 'deduct_score', 'reply_rate', 'reply_rank', 'five_min_reply_rate', 'manual_reply_rate', 'robot_resolution_rate', 'im_rank', 'session_count', 'manual_session_count', 'robot_session_count', 'im_order_conversion_rate', 'im_score', 'hotel_collect', 'hotel_collect_rank', 'ctrip_comment_count', 'qunar_comment_count', 'elong_comment_count', 'ctrip_rating', 'qunar_rating', 'elong_rating', 'ctrip_rating_rank', 'qunar_rating_rank', 'comment_score_summary', 'comment_store_name', 'comment_date', 'comment_channel', 'comment_score', 'comment_count', 'bad_review_count', 'comment_response_rate', 'review_environment_score', 'review_facility_score', 'review_service_score', 'review_cleanliness_score', 'review_photo_count', 'review_photo_rate', 'rating_competitor_total'],
            '广告推广' => ['ad_impressions', 'ad_clicks', 'ad_cost', 'ad_order_amount', 'ad_orders', 'ad_room_nights', 'ctr', 'cvr', 'roas'],
            '商旅BPI' => ['bpi_score', 'basis_score', 'plus_score', 'minus_score', 'agreement_accept_rate', 'business_room_nights', 'business_amount'],
            '辅助事实' => ['hot_spot_name', 'start_date', 'end_date', 'user_sex', 'avg_user_age', 'user_age', 'user_source', 'user_source_scope', 'source_region', 'source_city', 'user_type', 'travel_time', 'booking_hour', 'hotel_star_preference', 'price_band', 'consumption_power', 'avg_booking_days', 'booking_days', 'avg_stay_days', 'stay_days', 'price_sensitivity', 'booking_method', 'order_hotel_count', 'order_preference', 'preference_frequency', 'strategy', 'benefit_name', 'notice_title'],
        ];
    }

    public static function metricLabels(): array
    {
        return [
            'order_count' => '预订订单数',
            'room_nights' => '离店间夜量',
            'room_nights_last_week' => '离店间夜量上周同期',
            'quantity_rank' => '离店间夜量竞争圈排名',
            'order_amount' => '离店销售额',
            'order_amount_last_week' => '离店销售额上周同期',
            'amount_rank' => '离店销售额竞争圈排名',
            'avg_price' => '平均卖价',
            'avg_price_last_week' => '平均卖价上周同期',
            'avg_price_rank' => '平均卖价竞争圈排名',
            'close_rate' => '成交率',
            'close_rate_last_week' => '成交率上周同期',
            'close_rate_rank' => '成交率竞争圈排名',
            'occupancy_rate' => '出租率',
            'tensity' => '紧张度',
            'visitor_count' => '访客量',
            'list_exposure' => '列表页曝光',
            'detail_visitor' => '详情页访客',
            'order_page_visitor' => '订单页访客',
            'order_submit_user' => '订单提交人数',
            'flow_rate' => '流量转化率',
            'conversion_rate' => '成交/下单转化率',
            'rank' => '竞争圈排名',
            'competitor_average' => '竞争圈平均值',
            'common_view_rate' => '共同浏览率',
            'loss_order_count' => '流失订单数',
            'loss_room_nights' => '流失间夜',
            'loss_order_amount' => '流失订单金额',
            'psi_score' => 'PSI服务质量分',
            'service_score_rank' => 'PSI服务质量分竞争圈排名',
            'base_score' => '基础分',
            'reward_score' => '奖励分',
            'deduct_score' => '减分项',
            'reply_rate' => '5分钟回复率',
            'reply_rank' => '5分钟回复率竞争圈排名',
            'five_min_reply_rate' => '5分钟回复率',
            'manual_reply_rate' => '5分钟人工回复率',
            'robot_resolution_rate' => '机器人解决率',
            'im_rank' => 'IM竞争圈排名',
            'session_count' => '会话量',
            'manual_session_count' => '人工会话量',
            'robot_session_count' => '机器人会话量',
            'im_order_conversion_rate' => 'IM客人转化率',
            'im_score' => 'IM指标',
            'hotel_collect' => '酒店收藏数',
            'hotel_collect_rank' => '酒店收藏数竞争圈排名',
            'ctrip_comment_count' => '携程点评数量',
            'qunar_comment_count' => '去哪儿点评数量',
            'elong_comment_count' => '艺龙点评数量',
            'zx_comment_count' => '智行点评数量',
            'ctrip_rating' => '酒店点评分',
            'qunar_rating' => '去哪儿评分',
            'elong_rating' => '艺龙评分',
            'ctrip_rating_rank' => '携程评分排名',
            'qunar_rating_rank' => '去哪儿评分排名',
            'comment_score_summary' => '酒店点评分',
            'comment_store_name' => '点评门店',
            'comment_date' => '点评日期',
            'comment_channel' => '点评渠道',
            'comment_score' => '点评分',
            'comment_count' => '点评数',
            'bad_review_count' => '差评数',
            'comment_unreply_count' => '未回复点评数',
            'comment_good_rate' => '点评好评率',
            'comment_response_rate' => '点评回复率',
            'review_environment_score' => '点评环境评分',
            'review_facility_score' => '点评设施评分',
            'review_service_score' => '点评服务评分',
            'review_cleanliness_score' => '点评卫生评分',
            'review_photo_count' => '带图点评数',
            'review_photo_rate' => '带图点评率',
            'rating_competitor_total' => '点评竞争圈酒店数',
            'ad_impressions' => '广告曝光',
            'ad_clicks' => '广告点击',
            'ad_cost' => '广告花费',
            'ad_order_amount' => '广告预订金额',
            'ad_orders' => '广告预订订单',
            'ad_room_nights' => '广告预订间夜',
            'ctr' => '点击率',
            'cvr' => '转化率',
            'roas' => 'ROAS',
            'bpi_score' => 'BPI总分',
            'basis_score' => 'BPI基础分',
            'plus_score' => 'BPI加分',
            'minus_score' => 'BPI减分',
            'agreement_accept_rate' => '协议接单率',
            'business_room_nights' => '商旅间夜',
            'business_amount' => '商旅营业额',
            'hot_spot_name' => '热点名称',
            'start_date' => '开始日期',
            'end_date' => '结束日期',
            'user_sex' => '用户性别',
            'avg_user_age' => '平均年龄',
            'user_age' => '用户年龄',
            'user_source' => '客源来源',
            'user_source_scope' => '客源范围',
            'source_region' => '客源省份/地区',
            'source_city' => '客源城市',
            'user_type' => '用户类型',
            'travel_time' => '出行时间',
            'booking_hour' => '24小时预订时段',
            'hotel_star_preference' => '酒店星级偏好',
            'price_band' => '消费档位',
            'consumption_power' => '消费能力',
            'booking_method' => '预订方式',
            'order_hotel_count' => '订购酒店次数',
            'avg_booking_days' => '平均提前预订天数',
            'booking_days' => '提前预订天数区间',
            'avg_stay_days' => '平均连续入住天数',
            'stay_days' => '连续入住天数区间',
            'price_sensitivity' => '价格敏感度',
            'order_preference' => '订购偏好',
            'preference_frequency' => '偏好频次',
            'distribution_share' => '分布占比',
            'strategy' => '提升策略',
            'benefit_name' => '权益名称',
            'notice_title' => '公告/提示',
        ];
    }

    private static function countPayloadSection(array $payload, string $section): int
    {
        return isset($payload[$section]) && is_array($payload[$section]) ? count($payload[$section]) : 0;
    }
}
