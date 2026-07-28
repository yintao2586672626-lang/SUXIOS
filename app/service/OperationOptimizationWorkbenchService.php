<?php
declare(strict_types=1);

namespace app\service;

final class OperationOptimizationWorkbenchService
{
    private const PLATFORM_NAMES = [
        'ctrip' => '携程',
        'meituan' => '美团',
    ];

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function build(array $dataset, array $context = []): array
    {
        $scope = [
            'hotel_id' => max(0, (int)($context['hotel_id'] ?? 0)),
            'start_date' => $this->date((string)($context['start_date'] ?? '')),
            'end_date' => $this->date((string)($context['end_date'] ?? '')),
            'metric_scope' => 'ota_channel',
            'channel_policy' => 'ctrip_and_meituan_metrics_are_stored_explained_and_displayed_separately',
        ];

        $keywordWorkbench = $this->buildKeywordWorkbench(
            $this->rows($dataset['fact_ota_search_keyword'] ?? []),
            $this->rows($dataset['fact_ota_advertising'] ?? []),
            $scope
        );
        $roomProductMix = $this->buildRoomProductMix(
            $this->rows($dataset['fact_ota_daily'] ?? []),
            $scope
        );
        $channelViews = $this->buildChannelViews(
            $this->rows($dataset['fact_ota_traffic'] ?? []),
            $this->rows($dataset['fact_ota_advertising'] ?? []),
            $this->rows($dataset['fact_ota_search_keyword'] ?? [])
        );

        $availableModules = 0;
        foreach ([$keywordWorkbench, $roomProductMix] as $module) {
            if (in_array((string)($module['status'] ?? ''), ['ready', 'partial'], true)) {
                $availableModules++;
            }
        }
        $actionCount = count(array_filter(
            array_merge(
                $this->rows($keywordWorkbench['rows'] ?? []),
                $this->rows($roomProductMix['rows'] ?? [])
            ),
            static fn(array $row): bool => ($row['recommendation']['can_create_task'] ?? false) === true
        ));
        $moduleStatuses = [
            (string)($keywordWorkbench['status'] ?? 'blocked'),
            (string)($roomProductMix['status'] ?? 'blocked'),
        ];
        $status = count(array_filter(
            $moduleStatuses,
            static fn(string $moduleStatus): bool => $moduleStatus === 'ready'
        )) === 2
            ? 'ready'
            : ($availableModules > 0 ? 'partial' : 'blocked');

        return [
            'status' => $status,
            'metric_scope' => 'ota_channel',
            'scope' => $scope,
            'summary' => [
                'available_modules' => $availableModules,
                'total_modules' => 2,
                'actionable_recommendations' => $actionCount,
                'channel_cards_ready' => count(array_filter(
                    $channelViews,
                    static fn(array $row): bool => ($row['status'] ?? '') === 'ready'
                )),
            ],
            'truth_policy' => [
                'missing_is_not_zero' => true,
                'roi_definition' => '页面所称投产比为广告归因订单额除以广告消费，即 ROAS，不等于酒店利润 ROI。',
                'room_share_basis' => '房型占比仅在已采集且可识别的同平台房型行内计算，不外推为全酒店占比。',
                'execution_boundary' => '建议只可转为待审批的人工执行意图，不自动写回 OTA。',
            ],
            'keyword_workbench' => $keywordWorkbench,
            'room_product_mix' => $roomProductMix,
            'channel_views' => $channelViews,
            'recovery' => $status === 'blocked'
                ? ($keywordWorkbench['recovery'] ?? $roomProductMix['recovery'] ?? null)
                : null,
            'data_quality' => is_array($dataset['data_quality'] ?? null) ? $dataset['data_quality'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $workbench
     * @return array<string, mixed>|null
     */
    public function findRecommendation(array $workbench, string $recommendationId): ?array
    {
        $recommendationId = trim($recommendationId);
        if ($recommendationId === '') {
            return null;
        }

        foreach (['keyword_workbench', 'room_product_mix'] as $moduleKey) {
            foreach ($this->rows($workbench[$moduleKey]['rows'] ?? []) as $row) {
                $recommendation = is_array($row['recommendation'] ?? null) ? $row['recommendation'] : [];
                if (hash_equals((string)($recommendation['id'] ?? ''), $recommendationId)) {
                    return $recommendation;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $searchFacts
     * @param array<int, array<string, mixed>> $advertisingFacts
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildKeywordWorkbench(array $searchFacts, array $advertisingFacts, array $scope): array
    {
        $buckets = [];
        $untrustedCount = 0;

        foreach ($searchFacts as $fact) {
            $keyword = trim((string)($fact['keyword'] ?? ''));
            $platform = $this->platform((string)($fact['platform_key'] ?? ''));
            if ($keyword === '' || $platform === '') {
                continue;
            }
            $key = $platform . '|' . mb_strtolower($keyword);
            $bucket = $this->keywordBucket($buckets[$key] ?? [], $platform, $keyword);
            if (!$this->trusted($fact)) {
                $bucket['untrusted_rows']++;
                $untrustedCount++;
                $buckets[$key] = $bucket;
                continue;
            }

            $bucket['trusted_rows']++;
            $this->addNullable($bucket, 'impressions', $fact['impressions'] ?? null);
            $this->addNullable($bucket, 'clicks', $fact['clicks'] ?? null);
            $this->addNullable($bucket, 'orders', $fact['order_contribution'] ?? null);
            $raw = $this->detail($fact['raw_data'] ?? []);
            $this->addNullable($bucket, 'spend', $this->number($raw, ['spend', 'cost', 'ad_cost', 'adCost', 'todayCost']));
            $this->addNullable($bucket, 'order_amount', $this->number($raw, ['order_amount', 'orderAmount', 'saleAmount', 'revenue']));
            $landing = $this->text($raw, ['landing_room_type', 'landingRoomType', 'room_type_name', 'roomTypeName']);
            if ($landing !== '') {
                $bucket['landing_room_type'] = $landing;
            }
            $bucket['evidence_refs'] = $this->appendEvidenceRefs($bucket['evidence_refs'], $fact);
            $bucket['latest_date'] = max((string)$bucket['latest_date'], (string)($fact['date_key'] ?? ''));
            $buckets[$key] = $bucket;
        }

        foreach ($advertisingFacts as $fact) {
            $platform = $this->platform((string)($fact['platform_key'] ?? ''));
            $raw = $this->detail($fact['raw_data'] ?? []);
            $keyword = $this->text($raw, ['keyword', 'searchKeyword', 'search_keyword', 'searchWord', 'search_word']);
            if ($platform === '' || $keyword === '') {
                continue;
            }
            $key = $platform . '|' . mb_strtolower($keyword);
            $bucket = $this->keywordBucket($buckets[$key] ?? [], $platform, $keyword);
            if (!$this->trusted($fact)) {
                $bucket['untrusted_rows']++;
                $untrustedCount++;
                $buckets[$key] = $bucket;
                continue;
            }

            if (($bucket['advertising_metrics_used'] ?? false) !== true) {
                foreach (['impressions', 'clicks', 'orders', 'spend', 'order_amount'] as $metric) {
                    $bucket[$metric] = null;
                    $bucket[$metric . '_seen'] = false;
                }
                $bucket['advertising_metrics_used'] = true;
            }
            $bucket['trusted_rows']++;
            $this->addNullable($bucket, 'impressions', $fact['impressions'] ?? null);
            $this->addNullable($bucket, 'clicks', $fact['clicks'] ?? null);
            $this->addNullable($bucket, 'orders', $fact['bookings'] ?? null);
            $this->addNullable($bucket, 'spend', $fact['spend'] ?? null);
            $this->addNullable($bucket, 'order_amount', $fact['order_amount'] ?? null);
            $landing = $this->text($raw, ['landing_room_type', 'landingRoomType', 'room_type_name', 'roomTypeName']);
            if ($landing !== '') {
                $bucket['landing_room_type'] = $landing;
            }
            $bucket['evidence_refs'] = $this->appendEvidenceRefs($bucket['evidence_refs'], $fact);
            $bucket['latest_date'] = max((string)$bucket['latest_date'], (string)($fact['date_key'] ?? ''));
            $buckets[$key] = $bucket;
        }

        $rows = [];
        foreach ($buckets as $bucket) {
            if ((int)$bucket['trusted_rows'] <= 0) {
                continue;
            }
            $bucket['ctr'] = $this->ratioPercent($bucket['clicks'], $bucket['impressions']);
            $bucket['roas'] = $this->ratio($bucket['order_amount'], $bucket['spend']);
            $bucket['quality_status'] = (int)$bucket['untrusted_rows'] > 0 ? 'partial' : 'verified';
            $rows[] = $bucket;
        }

        $benchmarks = $this->keywordBenchmarks($rows);
        foreach ($rows as &$row) {
            $row['recommendation'] = $this->keywordRecommendation(
                $row,
                $benchmarks[$row['platform']] ?? [],
                $scope
            );
            unset(
                $row['impressions_seen'],
                $row['clicks_seen'],
                $row['orders_seen'],
                $row['spend_seen'],
                $row['order_amount_seen'],
                $row['advertising_metrics_used'],
                $row['trusted_rows'],
                $row['untrusted_rows']
            );
        }
        unset($row);
        usort($rows, static function (array $left, array $right): int {
            $leftSpend = $left['spend'] ?? -1;
            $rightSpend = $right['spend'] ?? -1;
            return $rightSpend <=> $leftSpend ?: ($right['impressions'] ?? -1) <=> ($left['impressions'] ?? -1);
        });

        $hasCompleteRoas = count(array_filter($rows, static fn(array $row): bool => $row['roas'] !== null)) > 0;
        $status = $rows === [] ? 'blocked' : ($hasCompleteRoas ? 'ready' : 'partial');
        $reason = $rows === []
            ? ($untrustedCount > 0 ? '已有关键词或广告记录，但证据未通过入库回读与来源校验。' : '当前门店和日期范围没有可用的搜索词与广告明细。')
            : ($hasCompleteRoas ? '' : '已有曝光、点击或订单，但缺少同关键词广告消费或归因订单额，不能计算投产比。');

        return [
            'status' => $status,
            'title' => '广告关键词优化台',
            'reason' => $reason,
            'rows' => array_slice($rows, 0, 50),
            'metric_definition' => [
                'ctr' => '点击数 / 曝光数',
                'roas' => '广告归因订单额 / 广告消费',
                'benchmark' => '建议优先使用同门店、同平台、同日期范围内完整关键词的中位数比较。',
            ],
            'recovery' => $status === 'ready' ? null : [
                'code' => $rows === [] ? 'keyword_data_missing' : 'keyword_roas_fields_missing',
                'reason' => $reason,
                'action_label' => '采集美团广告与搜索词',
                'target' => ['page' => 'meituan-ebooking', 'tab' => 'meituan-ads'],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $dailyFacts
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildRoomProductMix(array $dailyFacts, array $scope): array
    {
        $buckets = [];
        $untrustedCount = 0;
        foreach ($dailyFacts as $fact) {
            $platform = $this->platform((string)($fact['platform_key'] ?? ''));
            $roomType = $this->roomTypeName($fact);
            if ($platform === '' || $roomType === '') {
                continue;
            }
            $key = $platform . '|' . mb_strtolower($roomType);
            $bucket = $this->roomBucket($buckets[$key] ?? [], $platform, $roomType);
            if (!$this->trusted($fact)) {
                $bucket['untrusted_rows']++;
                $untrustedCount++;
                $buckets[$key] = $bucket;
                continue;
            }

            $bucket['trusted_rows']++;
            $this->addNullable($bucket, 'room_nights', $fact['room_nights'] ?? null);
            $this->addNullable($bucket, 'revenue', $fact['room_revenue'] ?? $fact['revenue'] ?? null);
            $this->addNullable($bucket, 'orders', $fact['order_count'] ?? null);
            $raw = $this->detail($fact['raw_data'] ?? []);
            $directCancelRate = $this->percent($fact['cancel_rate'] ?? $this->number($raw, ['cancel_rate', 'cancelRate', 'cancellation_rate', 'cancellationRate']));
            if ($directCancelRate !== null) {
                $weight = is_numeric($fact['order_count'] ?? null) && (float)$fact['order_count'] > 0
                    ? (float)$fact['order_count']
                    : 1.0;
                $bucket['cancel_rate_weighted_sum'] += $directCancelRate * $weight;
                $bucket['cancel_rate_weight'] += $weight;
            }
            $conversion = $this->percent($this->number($raw, ['conversion_rate', 'conversionRate', 'cvr', 'orderConversionRate']));
            if ($conversion !== null) {
                $weight = max(1.0, (float)($fact['order_count'] ?? 0));
                $bucket['conversion_weighted_sum'] += $conversion * $weight;
                $bucket['conversion_weight'] += $weight;
            }
            $ourPrice = $this->nullableFloat($fact['our_price'] ?? null);
            $competitorPrice = $this->nullableFloat($fact['competitor_price'] ?? null);
            $gap = $this->nullableFloat($fact['price_gap'] ?? null);
            if ($gap === null && $ourPrice !== null && $competitorPrice !== null) {
                $gap = $ourPrice - $competitorPrice;
            }
            if ($gap !== null && $ourPrice !== null && $competitorPrice !== null) {
                $bucket['price_gap_sum'] += $gap;
                $bucket['price_gap_samples']++;
            }
            $bucket['evidence_refs'] = $this->appendEvidenceRefs($bucket['evidence_refs'], $fact);
            $bucket['latest_date'] = max((string)$bucket['latest_date'], (string)($fact['date_key'] ?? ''));
            $buckets[$key] = $bucket;
        }

        $rows = [];
        foreach ($buckets as $bucket) {
            if ((int)$bucket['trusted_rows'] <= 0) {
                continue;
            }
            $bucket['adr'] = $this->ratio($bucket['revenue'], $bucket['room_nights']);
            $bucket['cancel_rate'] = $bucket['cancel_rate_weight'] > 0
                ? round($bucket['cancel_rate_weighted_sum'] / $bucket['cancel_rate_weight'], 2)
                : null;
            $bucket['conversion'] = $bucket['conversion_weight'] > 0
                ? round($bucket['conversion_weighted_sum'] / $bucket['conversion_weight'], 2)
                : null;
            $bucket['competitor_price_gap'] = $bucket['price_gap_samples'] > 0
                ? round($bucket['price_gap_sum'] / $bucket['price_gap_samples'], 2)
                : null;
            $bucket['competitor_price_samples'] = $bucket['price_gap_samples'];
            $bucket['quality_status'] = (int)$bucket['untrusted_rows'] > 0 ? 'partial' : 'verified';
            $rows[] = $bucket;
        }

        $rows = $this->addRoomShares($rows);
        $benchmarks = $this->roomBenchmarks($rows);
        foreach ($rows as &$row) {
            $row['recommendation'] = $this->roomRecommendation(
                $row,
                $benchmarks[$row['platform']] ?? [],
                $scope
            );
            unset(
                $row['room_nights_seen'],
                $row['revenue_seen'],
                $row['orders_seen'],
                $row['cancel_rate_weighted_sum'],
                $row['cancel_rate_weight'],
                $row['conversion_weighted_sum'],
                $row['conversion_weight'],
                $row['price_gap_sum'],
                $row['price_gap_samples'],
                $row['trusted_rows'],
                $row['untrusted_rows']
            );
        }
        unset($row);
        usort($rows, static fn(array $left, array $right): int => ($right['revenue'] ?? -1) <=> ($left['revenue'] ?? -1));

        $hasShare = count(array_filter($rows, static fn(array $row): bool => $row['revenue_share'] !== null || $row['room_night_share'] !== null)) > 0;
        $status = $rows === [] ? 'blocked' : ($hasShare ? 'ready' : 'partial');
        $reason = $rows === []
            ? ($untrustedCount > 0 ? '已有房型记录，但证据未通过入库回读与来源校验。' : '当前门店和日期范围没有可识别的房型级销量事实。')
            : ($hasShare ? '' : '已识别部分房型事实，但同平台房型行不完整，暂不计算占比。');

        return [
            'status' => $status,
            'title' => '房型产品组合经营',
            'reason' => $reason,
            'rows' => array_slice($rows, 0, 50),
            'metric_definition' => [
                'share_basis' => '同平台、同日期范围内已采集且可识别的房型行',
                'adr' => '房型收入 / 房型间夜',
                'competitor_price_gap' => '本店同房型可比价 - 竞对同房型可比价',
            ],
            'recovery' => $status === 'ready' ? null : [
                'code' => $rows === [] ? 'room_type_facts_missing' : 'room_type_scope_incomplete',
                'reason' => $reason,
                'action_label' => '补采房型订单与价格数据',
                'target' => ['page' => 'online-data', 'tab' => 'platform-auto'],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $trafficFacts
     * @param array<int, array<string, mixed>> $advertisingFacts
     * @param array<int, array<string, mixed>> $keywordFacts
     * @return array<int, array<string, mixed>>
     */
    private function buildChannelViews(array $trafficFacts, array $advertisingFacts, array $keywordFacts): array
    {
        $cards = [];
        foreach (self::PLATFORM_NAMES as $platform => $platformName) {
            $traffic = array_values(array_filter(
                $trafficFacts,
                fn(array $row): bool => $this->platform((string)($row['platform_key'] ?? '')) === $platform && $this->trusted($row)
            ));
            usort($traffic, static fn(array $left, array $right): int => strcmp((string)($right['date_key'] ?? ''), (string)($left['date_key'] ?? '')));
            $latestTraffic = $traffic[0] ?? [];
            $keywordExposure = $this->sumTrustedPlatformMetric($keywordFacts, $platform, 'impressions');
            $marketHeat = $this->nullableFloat($latestTraffic['list_exposure'] ?? null) ?? $keywordExposure;
            $conversion = $this->percent(
                $latestTraffic['flow_rate']
                ?? $latestTraffic['submit_rate']
                ?? null
            );
            $advertising = array_values(array_filter(
                $advertisingFacts,
                fn(array $row): bool => $this->platform((string)($row['platform_key'] ?? '')) === $platform && $this->trusted($row)
            ));
            $spend = $this->sumNullableMetric($advertising, 'spend');
            $orderAmount = $this->sumNullableMetric($advertising, 'order_amount');
            $roas = $this->ratio($orderAmount, $spend);
            $ready = $marketHeat !== null || $conversion !== null || $roas !== null;

            $cards[] = [
                'platform' => $platform,
                'platform_name' => $platformName,
                'status' => $ready ? 'ready' : 'blocked',
                'latest_date' => (string)($latestTraffic['date_key'] ?? ''),
                'questions' => [
                    [
                        'key' => 'market_heat',
                        'label' => '市场热度',
                        'value' => $marketHeat,
                        'unit' => '次曝光',
                        'definition' => $platformName . '返回的曝光事实；不与另一平台相加。',
                    ],
                    [
                        'key' => 'conversion',
                        'label' => '转化',
                        'value' => $conversion,
                        'unit' => '%',
                        'definition' => $platformName . '自身返回的转化口径；不换算成跨平台统一率。',
                    ],
                    [
                        'key' => 'advertising_roas',
                        'label' => '广告投产比',
                        'value' => $roas,
                        'unit' => 'x',
                        'definition' => $platformName . '广告归因订单额 / 广告消费。',
                    ],
                ],
                'recovery' => $ready ? null : [
                    'code' => $platform . '_channel_metrics_missing',
                    'reason' => '当前日期范围没有通过回读校验的' . $platformName . '曝光、转化或广告事实。',
                    'action_label' => '采集' . $platformName . '经营数据',
                    'target' => [
                        'page' => $platform === 'ctrip' ? 'ctrip-ebooking' : 'meituan-ebooking',
                        'tab' => $platform === 'ctrip' ? 'ctrip-ranking' : 'meituan-ranking',
                    ],
                ],
            ];
        }

        return $cards;
    }

    /** @param array<string, mixed> $bucket @return array<string, mixed> */
    private function keywordBucket(array $bucket, string $platform, string $keyword): array
    {
        return $bucket + [
            'platform' => $platform,
            'platform_name' => self::PLATFORM_NAMES[$platform] ?? $platform,
            'keyword' => $keyword,
            'impressions' => null,
            'impressions_seen' => false,
            'clicks' => null,
            'clicks_seen' => false,
            'orders' => null,
            'orders_seen' => false,
            'spend' => null,
            'spend_seen' => false,
            'order_amount' => null,
            'order_amount_seen' => false,
            'landing_room_type' => '',
            'evidence_refs' => [],
            'latest_date' => '',
            'trusted_rows' => 0,
            'untrusted_rows' => 0,
            'advertising_metrics_used' => false,
        ];
    }

    /** @param array<string, mixed> $bucket @return array<string, mixed> */
    private function roomBucket(array $bucket, string $platform, string $roomType): array
    {
        return $bucket + [
            'platform' => $platform,
            'platform_name' => self::PLATFORM_NAMES[$platform] ?? $platform,
            'room_type' => $roomType,
            'room_nights' => null,
            'room_nights_seen' => false,
            'revenue' => null,
            'revenue_seen' => false,
            'orders' => null,
            'orders_seen' => false,
            'room_night_share' => null,
            'revenue_share' => null,
            'cancel_rate_weighted_sum' => 0.0,
            'cancel_rate_weight' => 0.0,
            'conversion_weighted_sum' => 0.0,
            'conversion_weight' => 0.0,
            'price_gap_sum' => 0.0,
            'price_gap_samples' => 0,
            'evidence_refs' => [],
            'latest_date' => '',
            'trusted_rows' => 0,
            'untrusted_rows' => 0,
        ];
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function addRoomShares(array $rows): array
    {
        $byPlatform = [];
        foreach ($rows as $index => $row) {
            $byPlatform[(string)$row['platform']][] = $index;
        }
        foreach ($byPlatform as $indices) {
            $completeRevenue = count($indices) >= 2;
            $completeNights = count($indices) >= 2;
            $revenueTotal = 0.0;
            $nightTotal = 0.0;
            foreach ($indices as $index) {
                $completeRevenue = $completeRevenue && $rows[$index]['revenue'] !== null;
                $completeNights = $completeNights && $rows[$index]['room_nights'] !== null;
                $revenueTotal += (float)($rows[$index]['revenue'] ?? 0);
                $nightTotal += (float)($rows[$index]['room_nights'] ?? 0);
            }
            foreach ($indices as $index) {
                $rows[$index]['revenue_share'] = $completeRevenue && $revenueTotal > 0
                    ? round((float)$rows[$index]['revenue'] / $revenueTotal * 100, 2)
                    : null;
                $rows[$index]['room_night_share'] = $completeNights && $nightTotal > 0
                    ? round((float)$rows[$index]['room_nights'] / $nightTotal * 100, 2)
                    : null;
                $rows[$index]['share_basis'] = 'captured_named_room_types_within_platform';
            }
        }
        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, array<string, mixed>> */
    private function keywordBenchmarks(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $platform = (string)$row['platform'];
            if ($row['ctr'] !== null) {
                $values[$platform]['ctr'][] = (float)$row['ctr'];
            }
            if ($row['roas'] !== null) {
                $values[$platform]['roas'][] = (float)$row['roas'];
            }
        }
        return $this->medianBenchmarks($values);
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, array<string, mixed>> */
    private function roomBenchmarks(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $platform = (string)$row['platform'];
            foreach (['conversion', 'cancel_rate'] as $metric) {
                if ($row[$metric] !== null) {
                    $values[$platform][$metric][] = (float)$row[$metric];
                }
            }
        }
        return $this->medianBenchmarks($values);
    }

    /**
     * @param array<string, array<string, array<int, float>>> $values
     * @return array<string, array<string, mixed>>
     */
    private function medianBenchmarks(array $values): array
    {
        $result = [];
        foreach ($values as $platform => $metrics) {
            foreach ($metrics as $metric => $items) {
                $result[$platform][$metric] = count($items) >= 3 ? $this->median($items) : null;
                $result[$platform][$metric . '_sample_count'] = count($items);
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $benchmark
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function keywordRecommendation(array $row, array $benchmark, array $scope): array
    {
        $code = 'observe';
        $title = '继续观察并补齐广告归因';
        $reason = '当前字段不足以支持保留、降出价、停投或换落地房型的明确判断。';
        $expectedMetric = 'keyword_data_completeness';
        $canCreate = false;

        if ($row['spend'] !== null && (float)$row['spend'] > 0 && $row['orders'] !== null && (int)$row['orders'] === 0 && $row['clicks'] !== null && (int)$row['clicks'] > 0) {
            $code = 'pause_review';
            $title = '暂停投放并人工复核';
            $reason = '该关键词已有消费和点击，但当前范围内没有归因订单。';
            $expectedMetric = 'advertising_roas';
            $canCreate = true;
        } elseif ($row['roas'] !== null && ($benchmark['roas'] ?? null) !== null && (float)$row['roas'] < (float)$benchmark['roas'] * 0.7) {
            $code = 'lower_bid';
            $title = '降低出价并观察排名';
            $reason = '投产比低于同门店、同平台、同期完整关键词中位数的 70%。';
            $expectedMetric = 'advertising_roas';
            $canCreate = true;
        } elseif ($row['ctr'] !== null && ($benchmark['ctr'] ?? null) !== null && (float)$row['ctr'] < (float)$benchmark['ctr'] * 0.7) {
            $code = 'change_landing_room_type';
            $title = '更换房型落地页或首图';
            $reason = '点击率低于同门店、同平台、同期完整关键词中位数的 70%。';
            $expectedMetric = 'keyword_ctr';
            $canCreate = true;
        } elseif ($row['roas'] !== null && ($benchmark['roas'] ?? null) !== null && (float)$row['roas'] >= (float)$benchmark['roas']) {
            $code = 'retain';
            $title = '保留投放并设置复核';
            $reason = '投产比不低于同门店、同平台、同期完整关键词中位数。';
            $expectedMetric = 'advertising_roas';
            $canCreate = true;
        }

        return $this->recommendation(
            'keyword',
            $code,
            $title,
            $reason,
            $canCreate && ($row['quality_status'] ?? '') === 'verified',
            [
                'hotel_id' => (int)($scope['hotel_id'] ?? 0),
                'platform' => (string)$row['platform'],
                'object_type' => 'campaign',
                'action_type' => 'keyword_' . $code,
                'date_start' => (string)($scope['start_date'] ?? ''),
                'date_end' => (string)($scope['end_date'] ?? ''),
                'status' => 'draft',
                'current_value' => [
                    'keyword' => (string)$row['keyword'],
                    'impressions' => $row['impressions'],
                    'clicks' => $row['clicks'],
                    'ctr' => $row['ctr'],
                    'spend' => $row['spend'],
                    'orders' => $row['orders'],
                    'roas' => $row['roas'],
                ],
                'target_value' => [
                    'campaign_type' => 'search_keyword',
                    'keyword' => (string)$row['keyword'],
                    'recommendation' => $code,
                    'landing_room_type' => (string)$row['landing_room_type'],
                    'target_metric' => $expectedMetric,
                    'expected_direction' => 'increase',
                    'expected_delta_status' => 'system_quantified',
                ],
                'evidence' => [
                    'metric_scope' => 'ota_channel',
                    'evidence_refs' => $row['evidence_refs'],
                    'rule_basis' => $reason,
                    'auto_write_ota' => false,
                    'expected_direction' => 'increase',
                    'expected_delta_status' => 'system_quantified',
                ],
                'expected_metric' => $expectedMetric,
                'expected_delta' => 0.01,
                'risk_level' => in_array($code, ['pause_review', 'lower_bid'], true) ? 'medium' : 'low',
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $benchmark
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function roomRecommendation(array $row, array $benchmark, array $scope): array
    {
        $code = 'observe';
        $title = '继续观察房型组合';
        $reason = '当前房型证据不足以形成改价、改图或改权益任务。';
        $expectedMetric = 'room_type_data_completeness';
        $canCreate = false;

        if ($row['competitor_price_gap'] !== null && (float)$row['competitor_price_gap'] > 0) {
            $code = 'price_review';
            $title = '复核房型价差与权益';
            $reason = '本店同房型可比价高于竞对；只创建人工复核任务，不直接给出目标价。';
            $expectedMetric = 'competitor_price_gap';
            $canCreate = true;
        } elseif ($row['cancel_rate'] !== null && ($benchmark['cancel_rate'] ?? null) !== null && (float)$row['cancel_rate'] > (float)$benchmark['cancel_rate'] * 1.3) {
            $code = 'benefit_review';
            $title = '调整权益与退改说明';
            $reason = '取消率高于同门店、同平台、同期完整房型中位数的 30%。';
            $expectedMetric = 'room_type_cancel_rate';
            $canCreate = true;
        } elseif ($row['conversion'] !== null && ($benchmark['conversion'] ?? null) !== null && (float)$row['conversion'] < (float)$benchmark['conversion'] * 0.7) {
            $code = 'image_review';
            $title = '优化房型首图与卖点';
            $reason = '转化率低于同门店、同平台、同期完整房型中位数的 70%。';
            $expectedMetric = 'room_type_conversion';
            $canCreate = true;
        }
        $expectedDirection = $expectedMetric === 'room_type_cancel_rate' || $expectedMetric === 'competitor_price_gap'
            ? 'decrease'
            : 'increase';

        return $this->recommendation(
            'room_type',
            $code,
            $title,
            $reason,
            $canCreate && ($row['quality_status'] ?? '') === 'verified',
            [
                'hotel_id' => (int)($scope['hotel_id'] ?? 0),
                'platform' => (string)$row['platform'],
                'object_type' => 'room_product',
                'action_type' => 'room_type_' . $code,
                'date_start' => (string)($scope['start_date'] ?? ''),
                'date_end' => (string)($scope['end_date'] ?? ''),
                'status' => 'draft',
                'current_value' => [
                    'room_type' => (string)$row['room_type'],
                    'room_nights' => $row['room_nights'],
                    'revenue' => $row['revenue'],
                    'adr' => $row['adr'],
                    'cancel_rate' => $row['cancel_rate'],
                    'conversion' => $row['conversion'],
                    'competitor_price_gap' => $row['competitor_price_gap'],
                ],
                'target_value' => [
                    'room_type_key' => (string)$row['room_type'],
                    'recommendation' => $code,
                    'target_metric' => $expectedMetric,
                    'expected_direction' => $expectedDirection,
                    'expected_delta_status' => 'system_quantified',
                    'manual_review_required' => true,
                ],
                'evidence' => [
                    'metric_scope' => 'ota_channel',
                    'evidence_refs' => $row['evidence_refs'],
                    'rule_basis' => $reason,
                    'auto_write_ota' => false,
                    'expected_direction' => $expectedDirection,
                    'expected_delta_status' => 'system_quantified',
                ],
                'expected_metric' => $expectedMetric,
                'expected_delta' => 0.01,
                'risk_level' => $code === 'price_review' ? 'medium' : 'low',
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function recommendation(
        string $module,
        string $code,
        string $title,
        string $reason,
        bool $canCreateTask,
        array $payload
    ): array {
        $targetValue = is_array($payload['target_value'] ?? null) ? $payload['target_value'] : [];
        $evidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
        $subject = (string)($targetValue['keyword'] ?? $targetValue['room_type_key'] ?? '');
        $actionId = substr(hash('sha256', implode('|', [
            'operation_optimizer_v1',
            (string)($payload['hotel_id'] ?? ''),
            (string)($payload['date_start'] ?? ''),
            (string)($payload['date_end'] ?? ''),
            $module,
            (string)($payload['platform'] ?? ''),
            (string)($payload['object_type'] ?? ''),
            $subject,
            $code,
        ])), 0, 32);
        $targetValue['optimizer_action_id'] = $actionId;
        $evidence['optimizer_action_id'] = $actionId;
        $evidence['optimizer_contract_version'] = 'operation_optimizer_v1';
        $evidence['review_policy'] = [
            'baseline_window' => [
                'start_date' => (string)($payload['date_start'] ?? ''),
                'end_date' => (string)($payload['date_end'] ?? ''),
            ],
            'review_window' => 'first_calendar_day_after_manual_execution',
            'required_scope_keys' => ['hotel_id', 'platform', 'object_type', 'subject', 'expected_metric'],
            'causality_claimed' => false,
        ];
        $payload['source_module'] = 'operation_optimizer';
        $payload['source_record_id'] = 0;
        $payload['status'] = 'pending_approval';
        $payload['target_value'] = $targetValue;
        $payload['evidence'] = $evidence;

        return [
            'id' => $actionId,
            'code' => $code,
            'title' => $title,
            'reason' => $reason,
            'can_create_task' => $canCreateTask,
            'blocked_reason' => $canCreateTask ? '' : '缺少完整或已验证的同口径证据，暂不生成执行任务。',
            'task_payload' => $canCreateTask ? $payload : null,
        ];
    }

    /** @param array<string, mixed> $bucket */
    private function addNullable(array &$bucket, string $field, mixed $value): void
    {
        $number = $this->nullableFloat($value);
        if ($number === null) {
            return;
        }
        $bucket[$field] = round((float)($bucket[$field] ?? 0) + $number, 4);
        $bucket[$field . '_seen'] = true;
    }

    /** @param array<string, mixed> $fact @param array<int, string> $refs @return array<int, string> */
    private function appendEvidenceRefs(array $refs, array $fact): array
    {
        $trace = is_array($fact['source_trace'] ?? null) ? $fact['source_trace'] : [];
        if (isset($trace['row_id']) && trim((string)$trace['row_id']) !== '') {
            $refs[] = 'online_daily_data#' . $trace['row_id'];
        }
        $sourceTraceId = trim((string)($trace['source_trace_id'] ?? ''));
        if ($sourceTraceId !== '') {
            $refs[] = 'source_trace:' . $sourceTraceId;
        }
        return array_values(array_unique($refs));
    }

    /** @param array<string, mixed> $fact */
    private function trusted(array $fact): bool
    {
        $trace = is_array($fact['source_trace'] ?? null) ? $fact['source_trace'] : [];
        return ($trace['stored'] ?? false) === true
            && ($trace['readback_verified'] ?? false) === true
            && ($trace['saved_success'] ?? false) === true;
    }

    /** @param array<string, mixed> $fact */
    private function roomTypeName(array $fact): string
    {
        $raw = $this->detail($fact['raw_data'] ?? []);
        $name = $this->text($raw, [
            'room_type_name',
            'roomTypeName',
            'room_name',
            'roomName',
            'basicRoomTypeName',
            'product_name',
            'productName',
        ]);
        if ($name !== '') {
            return $name;
        }
        $dimension = trim((string)($fact['dimension'] ?? ''));
        if (preg_match('/^(?:room[_ -]?type|room|房型)[:：|\\/-](.+)$/iu', $dimension, $matches) === 1) {
            return trim((string)$matches[1]);
        }
        return (string)($fact['data_type'] ?? '') === 'room_type' ? $dimension : '';
    }

    /** @param mixed $raw @return array<string, mixed> */
    private function detail(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $detail = $raw;
        foreach (['row', 'metrics', 'detail', 'data'] as $key) {
            if (is_array($raw[$key] ?? null) && !array_is_list($raw[$key])) {
                $detail = array_merge($detail, $raw[$key]);
            }
        }
        return $detail;
    }

    /** @param array<string, mixed> $data @param array<int, string> $keys */
    private function text(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || is_array($data[$key])) {
                continue;
            }
            $value = trim((string)$data[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** @param array<string, mixed> $data @param array<int, string> $keys */
    private function number(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $this->nullableFloat($data[$key]);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace([',', '%'], '', trim($value));
        }
        return is_numeric($value) ? (float)$value : null;
    }

    private function ratio(mixed $numerator, mixed $denominator): ?float
    {
        $numerator = $this->nullableFloat($numerator);
        $denominator = $this->nullableFloat($denominator);
        return $numerator !== null && $denominator !== null && $denominator > 0
            ? round($numerator / $denominator, 2)
            : null;
    }

    private function ratioPercent(mixed $numerator, mixed $denominator): ?float
    {
        $numerator = $this->nullableFloat($numerator);
        $denominator = $this->nullableFloat($denominator);
        return $numerator !== null && $denominator !== null && $denominator > 0
            ? round($numerator / $denominator * 100, 2)
            : null;
    }

    private function percent(mixed $value): ?float
    {
        $number = $this->nullableFloat($value);
        if ($number === null || $number < 0) {
            return null;
        }
        if ($number > 0 && $number <= 1) {
            $number *= 100;
        }
        return $number <= 100 ? round($number, 2) : null;
    }

    /** @param array<int, float> $values */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);
        return $count % 2 === 0
            ? round(($values[$middle - 1] + $values[$middle]) / 2, 2)
            : round($values[$middle], 2);
    }

    /** @param array<int, array<string, mixed>> $facts */
    private function sumTrustedPlatformMetric(array $facts, string $platform, string $metric): ?float
    {
        return $this->sumNullableMetric(array_values(array_filter(
            $facts,
            fn(array $row): bool => $this->platform((string)($row['platform_key'] ?? '')) === $platform && $this->trusted($row)
        )), $metric);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sumNullableMetric(array $rows, string $metric): ?float
    {
        $sum = 0.0;
        $seen = false;
        foreach ($rows as $row) {
            $value = $this->nullableFloat($row[$metric] ?? null);
            if ($value === null) {
                continue;
            }
            $sum += $value;
            $seen = true;
        }
        return $seen ? round($sum, 4) : null;
    }

    private function platform(string $value): string
    {
        $value = strtolower(trim($value));
        if (str_contains($value, 'ctrip') || str_contains($value, 'trip.com')) {
            return 'ctrip';
        }
        if (str_contains($value, 'meituan') || str_contains($value, 'dianping')) {
            return 'meituan';
        }
        return isset(self::PLATFORM_NAMES[$value]) ? $value : '';
    }

    private function date(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', trim($value)) === 1 ? trim($value) : '';
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(mixed $rows): array
    {
        return is_array($rows)
            ? array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row)))
            : [];
    }
}
