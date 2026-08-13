<?php
declare(strict_types=1);

namespace app\service\operation;

trait OperationAlertAnalysisConcern
{
    /**
     * A terminal effect judgment is valid only when both windows describe the
     * same metric identity. Legacy averages without scope/source metadata fail
     * closed because their apparent numeric similarity is not evidence that
     * they measure the same hotel operating fact.
     *
     * @return array{comparable:bool,gap_code:string,message:string,metric:string}
     */
    private function assessComparableActionEffectEvidence(string $targetMetric, array $before, array $after): array
    {
        $metric = match (strtolower(trim($targetMetric))) {
            'orders', 'avg_orders', 'order_count', 'book_order_num' => 'orders',
            'revenue', 'avg_revenue', 'amount', 'income' => 'revenue',
            'room_nights', 'avg_room_nights' => 'room_nights',
            'conversion', 'avg_conversion', 'conversion_rate', 'order_rate' => 'conversion',
            default => strtolower(trim($targetMetric)),
        };
        $failure = static fn(string $code, string $message): array => [
            'comparable' => false,
            'gap_code' => $code,
            'message' => $message,
            'metric' => $metric,
        ];
        if ($metric === '') {
            return $failure('operation_action_effect_metric_missing', 'Target metric identity is missing.');
        }
        if (($before['data_status'] ?? '') !== self::DATA_OK || ($after['data_status'] ?? '') !== self::DATA_OK) {
            return $failure('operation_action_effect_data_incomplete', 'Before and after evidence must both have data_status=ok.');
        }
        foreach ([$before, $after] as $window) {
            if (!isset(
                $window['days'],
                $window['actual_days'],
                $window['window_start_date'],
                $window['window_end_date']
            )) {
                return $failure(
                    'operation_action_effect_window_metadata_missing',
                    'Before and after evidence must both identify the requested and observed calendar windows.'
                );
            }
        }
        $parseWindow = static function (array $window): ?array {
            $requestedDays = filter_var($window['days'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $actualDays = filter_var($window['actual_days'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $timezone = new \DateTimeZone('Asia/Shanghai');
            $startValue = trim((string)$window['window_start_date']);
            $endValue = trim((string)$window['window_end_date']);
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $startValue, $timezone);
            $startErrors = \DateTimeImmutable::getLastErrors();
            $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $endValue, $timezone);
            $endErrors = \DateTimeImmutable::getLastErrors();
            if ($requestedDays === false || $actualDays === false
                || $start === false || $end === false
                || ($startErrors !== false && ((int)$startErrors['warning_count'] > 0 || (int)$startErrors['error_count'] > 0))
                || ($endErrors !== false && ((int)$endErrors['warning_count'] > 0 || (int)$endErrors['error_count'] > 0))
                || $start->format('Y-m-d') !== $startValue
                || $end->format('Y-m-d') !== $endValue
                || $end < $start
            ) {
                return null;
            }

            return [
                'requested_days' => (int)$requestedDays,
                'actual_days' => (int)$actualDays,
                'calendar_days' => ((int)$start->diff($end)->format('%a')) + 1,
                'start' => $start,
                'end' => $end,
            ];
        };
        $beforeWindow = $parseWindow($before);
        $afterWindow = $parseWindow($after);
        if ($beforeWindow === null || $afterWindow === null) {
            return $failure(
                'operation_action_effect_window_metadata_invalid',
                'Before or after observation-window metadata is invalid.'
            );
        }
        if ($beforeWindow['requested_days'] !== $afterWindow['requested_days']
            || $beforeWindow['actual_days'] !== $afterWindow['actual_days']
            || $beforeWindow['calendar_days'] !== $beforeWindow['requested_days']
            || $afterWindow['calendar_days'] !== $afterWindow['requested_days']
            || $beforeWindow['actual_days'] !== $beforeWindow['requested_days']
            || $afterWindow['actual_days'] !== $afterWindow['requested_days']
        ) {
            return $failure(
                'operation_action_effect_window_mismatch',
                'Before and after evidence must use equal and complete calendar observation windows.'
            );
        }
        $valueKey = 'avg_' . $metric;
        if (!is_numeric($before[$valueKey] ?? null) || !is_numeric($after[$valueKey] ?? null)) {
            return $failure('operation_action_effect_metric_sample_missing', 'Before or after target metric sample is missing.');
        }
        $beforeSamples = (int)($before['metric_sample_days'][$metric] ?? 0);
        $afterSamples = (int)($after['metric_sample_days'][$metric] ?? 0);
        if ($beforeSamples <= 0 || $afterSamples <= 0) {
            return $failure('operation_action_effect_metric_sample_missing', 'Before or after target metric has no verified sample day.');
        }
        if ($beforeSamples !== $beforeWindow['actual_days'] || $afterSamples !== $afterWindow['actual_days']) {
            return $failure(
                'operation_action_effect_metric_window_mismatch',
                'Before or after target metric sample days do not cover the complete observation window.'
            );
        }

        $normalizeStrings = static function (mixed $values): array {
            if (!is_array($values)) {
                return [];
            }
            $normalized = array_values(array_unique(array_filter(array_map(
                static fn(mixed $value): string => strtolower(trim((string)$value)),
                $values
            ), static fn(string $value): bool => $value !== '')));
            sort($normalized);
            return $normalized;
        };
        $beforeScopes = $normalizeStrings($before['source_scopes'] ?? null);
        $afterScopes = $normalizeStrings($after['source_scopes'] ?? null);
        if ($beforeScopes === [] || $afterScopes === []) {
            return $failure('operation_action_effect_source_scope_missing', 'Before or after source scope metadata is missing.');
        }
        if ($beforeScopes !== $afterScopes) {
            return $failure('operation_action_effect_identity_drift', 'Before and after source scopes differ.');
        }

        $normalizeIdentities = static function (mixed $identities) use ($metric): array {
            if (!is_array($identities)) {
                return [];
            }
            $normalized = [];
            foreach ($identities as $identity) {
                if (!is_array($identity)) {
                    continue;
                }
                $item = [
                    'metric' => strtolower(trim((string)($identity['metric'] ?? ''))),
                    'scope' => strtolower(trim((string)($identity['scope'] ?? ''))),
                    'platform' => strtolower(trim((string)($identity['platform'] ?? ''))),
                    'source' => strtolower(trim((string)($identity['source'] ?? ''))),
                    'measurement_grain' => strtolower(trim((string)($identity['measurement_grain'] ?? ''))),
                ];
                if ($item['metric'] !== $metric || $item['scope'] === '' || $item['source'] === '' || $item['measurement_grain'] === '') {
                    continue;
                }
                if ($item['scope'] === 'ota_channel' && $item['platform'] === '') {
                    continue;
                }
                $normalized[json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)] = $item;
            }
            ksort($normalized);
            return array_values($normalized);
        };
        $beforeIdentities = $normalizeIdentities($before['metric_identities'][$metric] ?? null);
        $afterIdentities = $normalizeIdentities($after['metric_identities'][$metric] ?? null);
        if (count($beforeIdentities) !== 1 || count($afterIdentities) !== 1) {
            return $failure('operation_action_effect_identity_missing', 'Before or after exact metric identity is missing or ambiguous.');
        }
        if ($beforeIdentities !== $afterIdentities) {
            return $failure('operation_action_effect_identity_drift', 'Before and after metric scope, platform, or source identity differs.');
        }

        return ['comparable' => true, 'gap_code' => '', 'message' => '', 'metric' => $metric];
    }

    private function cause(
        string $type,
        string $title,
        int $priority,
        float $ruleMatchWeight,
        string $evidence,
        string $suggestion,
        array $referenceBasis = []
    ): array
    {
        $detail = $this->causeDetail($type);
        if (!empty($referenceBasis)) {
            $referenceBasis['rule_version'] = 'operation_root_cause.v1';
            $referenceDefinition = array_diff_key($referenceBasis, [
                'measured_value' => true,
                'reference_value' => true,
            ]);
            $referenceBasis['reference_version'] = hash('sha256', json_encode(
                $referenceDefinition,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ) ?: '');
        }
        return [
            'type' => $type,
            'title' => $title,
            'priority' => $priority,
            'rule_match_weight' => $ruleMatchWeight,
            'confidence' => $ruleMatchWeight,
            'confidence_basis' => 'confidence 为兼容旧客户端保留，值等同 rule_match_weight；这是规则匹配权重，不是统计置信度或因果概率',
            'evidence' => $evidence,
            'reference_basis' => $referenceBasis,
            'suggestion' => $suggestion,
            'impact' => $detail['impact'],
            'check_points' => $detail['check_points'],
            'action_steps' => $detail['action_steps'],
        ];
    }

    private function causeDetail(string $type): array
    {
        $details = [
            'data_abnormal' => [
                'impact' => '采集口径异常可能使漏斗和转化率失真，核验前不应用于价格、库存或投放决策。',
                'check_points' => ['确认OTA配置是否绑定当前酒店', '检查Cookie或授权是否过期', '核对曝光、访客、订单字段映射和抓取日期'],
                'action_steps' => ['重新同步当天OTA数据', '对比OTA后台原始值与系统入库值', '修正字段映射后重新执行可能影响因素分析'],
            ],
            'traffic_down' => [
                'impact' => '曝光下降位于漏斗前端，可能缩小访客和订单触达范围；需继续核对排名、活动和供给展示证据。',
                'check_points' => ['查看近7日曝光曲线和排名变化', '检查标题、首图、房型可售状态', '确认活动流量入口是否下线或预算不足'],
                'action_steps' => ['先恢复可售房型和基础曝光入口', '优化首图标题并补齐活动位', '次日复看曝光、访客和订单是否同步恢复'],
            ],
            'view_conversion_low' => [
                'impact' => '浏览转化偏低与详情页承接不足相关，但图片、卖点、价格展示或可售房型是否构成原因仍需逐项核验。',
                'check_points' => ['复核首图、房型图和核心卖点是否清晰', '对比同圈层竞品的价格与权益展示', '检查可售房型、早餐、取消政策等关键卖点'],
                'action_steps' => ['优先调整首图和房型展示顺序', '补充高频客群关注的卖点和权益', '观察浏览转化率是否在2到3天内回升'],
            ],
            'order_conversion_low' => [
                'impact' => '订单转化偏低与价格竞争力、库存限制或预订政策阻力可能相关，现有规则不能确认具体原因。',
                'check_points' => ['对比本店ADR与竞对均价', '检查取消政策、连住限制和库存余量', '确认促销、会员价和渠道价是否正常生效'],
                'action_steps' => ['按房型做小幅跟价或权益补偿', '放开低风险库存和过严预订限制', '同步跟踪订单转化、ADR和RevPAR，避免只追单量'],
            ],
            'price_high' => [
                'impact' => '较高价格可能削弱部分访客的下单意愿，但需结合房型、权益、评分和节假日窗口判断。',
                'check_points' => ['按房型对齐竞品价格和权益', '确认高价是否由节假日、库存紧张或高评分支撑', '检查是否存在单渠道异常高价'],
                'action_steps' => ['先处理明显高于竞品的房型', '用优惠权益替代直接降价时同步观察转化', '保留高需求日期的价格保护线'],
            ],
            'service_quality_low' => [
                'impact' => '服务质量或PSI偏低可能与OTA流量承接和订单转化下降相关，仍需对照扣分项与同期漏斗验证。',
                'check_points' => ['查看服务质量分和PSI扣分项', '核对履约、房态、库存和接口异常是否集中出现', '对比低分日期的曝光、访客和订单转化变化'],
                'action_steps' => ['先处理可控的履约和房态问题', '把服务质量扣分项拆成门店任务并指定负责人', '次日复看服务质量、转化率和订单是否恢复'],
            ],
            'holiday_near' => [
                'impact' => '节假日临近可能改变需求和价格弹性，库存、底价和活动节奏需结合预订进度提前复核。',
                'check_points' => ['确认节假日库存、底价和连住策略', '对比竞对节假日价格带', '检查活动、预售和高需求日调价是否已生效'],
                'action_steps' => ['先锁定高需求日底价和保留房量', '分阶段拉升价格并监控订单节奏', '节后复盘ADR、OCC和RevPAR表现'],
            ],
        ];

        return $details[$type] ?? [
            'impact' => '该因素可能影响经营结果，需要结合经营、OTA、竞对和服务质量数据复核。',
            'check_points' => ['复核关联指标是否完整', '对比近7日和近30日趋势', '确认数据口径和酒店筛选是否一致'],
            'action_steps' => ['先补齐关键数据', '按影响最大指标优先处理', '执行后持续跟踪订单、收入和转化变化'],
        ];
    }

    private function extractRevenue(array $row, array $reportData): float
    {
        $revenue = $this->metricNumber($row['revenue'] ?? 0);
        if ($revenue > 0) {
            return $revenue;
        }
        foreach (['day_revenue', 'total_revenue', 'revenue', 'room_revenue'] as $key) {
            $value = $this->metricNumber($reportData[$key] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }
        return $this->sumReportFields($reportData, [
            'xb_revenue', 'mt_revenue', 'fliggy_revenue', 'dy_revenue', 'tc_revenue', 'qn_revenue', 'zx_revenue',
            'booking_revenue', 'agoda_revenue', 'expedia_revenue',
            'walkin_revenue', 'member_exp_revenue', 'web_exp_revenue', 'group_revenue', 'protocol_revenue', 'wechat_revenue',
            'free_revenue', 'gold_card_revenue', 'black_gold_revenue', 'hourly_revenue',
            'parking_revenue', 'dining_revenue', 'meeting_revenue', 'goods_revenue', 'member_card_revenue', 'other_revenue',
        ]);
    }

    private function extractRoomNights(array $row, array $reportData): float
    {
        foreach (['room_nights', 'occupied_rooms', 'day_total_rooms', 'total_rooms'] as $key) {
            $value = $this->numericMetricValue($reportData[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        $roomFields = [
            'xb_rooms', 'mt_rooms', 'fliggy_rooms', 'dy_rooms', 'tc_rooms', 'qn_rooms', 'zx_rooms',
            'booking_rooms', 'agoda_rooms', 'expedia_rooms',
            'walkin_rooms', 'member_exp_rooms', 'web_exp_rooms', 'group_rooms', 'protocol_rooms', 'wechat_rooms',
            'free_rooms', 'gold_card_rooms', 'black_gold_rooms', 'hourly_rooms',
        ];
        if ($this->hasAnyNumericMetric($reportData, $roomFields)) {
            return $this->sumReportFields($reportData, $roomFields);
        }

        return 0.0;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $reportData */
    private function dailyRevenueIsPresent(array $row, array $reportData): bool
    {
        return $this->hasAnyNumericMetric($row, ['revenue'])
            || $this->hasAnyNumericMetric($reportData, [
                'day_revenue', 'total_revenue', 'revenue', 'room_revenue',
                'xb_revenue', 'mt_revenue', 'fliggy_revenue', 'dy_revenue', 'tc_revenue', 'qn_revenue', 'zx_revenue',
                'booking_revenue', 'agoda_revenue', 'expedia_revenue',
                'walkin_revenue', 'member_exp_revenue', 'web_exp_revenue', 'group_revenue', 'protocol_revenue', 'wechat_revenue',
                'free_revenue', 'gold_card_revenue', 'black_gold_revenue', 'hourly_revenue',
                'parking_revenue', 'dining_revenue', 'meeting_revenue', 'goods_revenue', 'member_card_revenue', 'other_revenue',
            ]);
    }

    /** @param array<string, mixed> $reportData */
    private function dailyRoomNightsArePresent(array $reportData): bool
    {
        return $this->hasAnyNumericMetric($reportData, [
            'room_nights', 'occupied_rooms', 'day_total_rooms', 'total_rooms',
            'xb_rooms', 'mt_rooms', 'fliggy_rooms', 'dy_rooms', 'tc_rooms', 'qn_rooms', 'zx_rooms',
            'booking_rooms', 'agoda_rooms', 'expedia_rooms',
            'walkin_rooms', 'member_exp_rooms', 'web_exp_rooms', 'group_rooms', 'protocol_rooms', 'wechat_rooms',
            'free_rooms', 'gold_card_rooms', 'black_gold_rooms', 'hourly_rooms',
        ]);
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $reportData */
    private function extractDailyOrders(array $row, array $reportData): ?float
    {
        foreach ([
            [$row, ['orders', 'order_count', 'book_order_num']],
            [$reportData, ['orders', 'order_count', 'book_order_num', 'bookOrderNum', 'booking_count', 'bookingCount']],
        ] as [$source, $keys]) {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $source)) {
                    continue;
                }
                $value = $this->numericMetricValue($source[$key]);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data @param array<int, string> $keys */
    private function hasAnyNumericMetric(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $this->numericMetricValue($data[$key]) !== null) {
                return true;
            }
        }

        return false;
    }

    private function numericMetricValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float)$value) ? (float)$value : null;
        }
        if (!is_string($value)) {
            return null;
        }

        $clean = str_replace([',', ' ', "\u{00A0}", '%'], '', trim($value));
        return $clean !== '' && is_numeric($clean) ? (float)$clean : null;
    }

    /** @param array<string, bool> $coverage @param array<string, mixed> $row */
    private function markDailyMetricCoverage(array &$coverage, array $row): void
    {
        $date = substr(trim((string)($row['report_date'] ?? '')), 0, 10);
        if ($date === '') {
            return;
        }
        $hotelId = (int)($row['hotel_id'] ?? 0);
        $coverage[$hotelId > 0 ? $hotelId . ':' . $date : $date] = true;
    }

    /** @param array<string, bool> $coverage @param array<string, mixed> $onlineRow */
    private function hasDailyMetricForOnlineRow(array $coverage, array $onlineRow): bool
    {
        $date = substr(trim((string)($onlineRow['data_date'] ?? '')), 0, 10);
        if ($date === '') {
            return false;
        }
        $systemHotelId = (int)($onlineRow['system_hotel_id'] ?? 0);
        if ($systemHotelId > 0 && isset($coverage[$systemHotelId . ':' . $date])) {
            return true;
        }

        return isset($coverage[$date]);
    }

    private function extractSalableRoomCount(array $row, array $reportData): float
    {
        foreach ([
            $row['room_count'] ?? null,
            $reportData['salable_rooms'] ?? null,
            $reportData['salable_rooms_total'] ?? null,
            $reportData['total_rooms_count'] ?? null,
            $reportData['room_count'] ?? null,
            $reportData['rooms_total'] ?? null,
        ] as $value) {
            $number = $this->metricNumber($value);
            if ($number > 0) {
                return $number;
            }
        }
        return 0.0;
    }

    private function sumReportFields(array $reportData, array $fields): float
    {
        $total = 0.0;
        foreach ($fields as $field) {
            $total += $this->metricNumber($reportData[$field] ?? 0);
        }
        return $total;
    }

    private function metricNumber($value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }

        if (!is_string($value)) {
            return 0.0;
        }

        $clean = str_replace([',', ' ', "\u{00A0}", '%'], '', trim($value));
        return is_numeric($clean) ? (float)$clean : 0.0;
    }

    private function buildDailyFinancialKeys(array $dailyRows): array
    {
        $keys = [];
        foreach ($dailyRows as $row) {
            $date = (string)($row['report_date'] ?? '');
            if ($date === '') {
                continue;
            }
            $hotelId = (int)($row['hotel_id'] ?? 0);
            if ($hotelId > 0) {
                $keys[$hotelId . ':' . $date] = true;
            } else {
                $keys[$date] = true;
            }
        }
        return $keys;
    }

    private function hasDailyFinancialForOnlineRow(array $dailyFinancialKeys, array $onlineRow): bool
    {
        $date = (string)($onlineRow['data_date'] ?? '');
        if ($date === '') {
            return false;
        }
        $systemHotelId = (int)($onlineRow['system_hotel_id'] ?? 0);
        if ($systemHotelId > 0 && isset($dailyFinancialKeys[$systemHotelId . ':' . $date])) {
            return true;
        }
        return isset($dailyFinancialKeys[$date]);
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function avg(array $values): float
    {
        $values = array_values(array_filter($values, static fn($v): bool => is_numeric($v) && (float)$v > 0));
        return empty($values) ? 0.0 : round(array_sum($values) / count($values), 2);
    }

    private function strategyName(string $type): string
    {
        return [
            'price_adjust' => '价格调整',
            'promotion' => '促销活动',
            'room_inventory' => '房量库存',
            'competitor_follow' => '竞对跟价',
            'holiday_strategy' => '节假日策略',
        ][$type] ?? '未知策略';
    }

    private function buildSimulationRecommendation(string $type, string $riskLevel): string
    {
        if ($riskLevel === 'high' || $riskLevel === 'medium_high') {
            return '建议缩小调整幅度，先选择单渠道或少量房型试运行';
        }
        if ($riskLevel === 'unknown') {
            return '规则未形成风险等级证据；请先人工核对价格、库存、竞对和日期环境，再决定是否小范围试行';
        }
        if ($type === 'holiday_strategy') {
            return '建议结合节假日库存和竞对价格分阶段执行';
        }
        return '建议先小范围执行，并持续跟踪订单、收入和转化变化';
    }
}
