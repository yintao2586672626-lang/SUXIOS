<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;
use Throwable;

class OperationManagementService
{
    private const DATA_PENDING = '待接入真实数据';
    private const DATA_OK = 'ok';
    private const DISCLAIMER = '该结果基于历史数据和规则估算，仅用于运营参考。';

    public function fullData(array $hotelIds, ?int $hotelId, string $date): array
    {
        $summary = $this->buildSummary($hotelIds, $hotelId, $date);
        $ota = $this->buildOta($hotelIds, $date);
        $reviews = $this->buildReviews($hotelIds, $date);
        $competitors = $this->buildCompetitors($hotelIds, $date, $summary, $reviews);
        $holiday = $this->buildHoliday($date);
        $abnormalFlags = [];

        if (($ota['exposure'] ?? 0) <= 0 && ($ota['visitors'] ?? 0) <= 0 && ($ota['orders'] ?? 0) > 0) {
            $abnormalFlags[] = '曝光/访客为0但订单大于0，疑似采集异常';
        }

        foreach ([
            '经营日报' => $summary,
            'OTA数据' => $ota,
            '竞对数据' => $competitors,
            '点评数据' => $reviews,
        ] as $module => $data) {
            if (($data['data_status'] ?? '') === self::DATA_PENDING) {
                $abnormalFlags[] = $module . '为空，待接入真实数据';
            }
        }

        return [
            'summary' => $summary,
            'ota' => $ota,
            'competitors' => $competitors,
            'reviews' => $reviews,
            'holiday' => $holiday,
            'abnormal_flags' => array_values(array_unique($abnormalFlags)),
        ];
    }

    public function rootCause(array $hotelIds, ?int $hotelId, string $date, string $problemType): array
    {
        $fullData = $this->fullData($hotelIds, $hotelId, $date);
        $todayOta = $fullData['ota'];
        $summary = $fullData['summary'];
        $competitors = $fullData['competitors'];
        $reviews = $fullData['reviews'];
        $holiday = $fullData['holiday'];
        $avg7 = $this->averageOnlineMetrics($hotelIds, $date, 7);
        $avg30 = $this->averageOnlineMetrics($hotelIds, $date, 30);
        $rootCauses = [];

        if (($todayOta['orders'] ?? 0) > 0 && ($todayOta['exposure'] ?? 0) <= 0 && ($todayOta['visitors'] ?? 0) <= 0) {
            $rootCauses[] = $this->cause('data_abnormal', '数据采集异常', 1, 0.95, '曝光/访客为0但订单大于0', '优先检查OTA采集配置、Cookie状态和字段映射');
        }

        if (($avg7['exposure'] ?? 0) > 0 && ($todayOta['exposure'] ?? 0) < $avg7['exposure'] * 0.7) {
            $rootCauses[] = $this->cause('traffic_down', '曝光下降', 2, 0.82, '今日曝光低于7日均值30%以上', '检查渠道排名、标题图片和活动流量入口');
        }

        if (($avg30['view_rate'] ?? 0) > 0 && ($todayOta['view_rate'] ?? 0) < $avg30['view_rate'] * 0.8) {
            $rootCauses[] = $this->cause('view_conversion_low', '浏览转化差', 3, 0.78, '浏览/曝光低于历史均值20%以上', '优化首图、卖点、价格展示和可售房型');
        }

        if (($avg30['order_rate'] ?? 0) > 0 && ($todayOta['order_rate'] ?? 0) < $avg30['order_rate'] * 0.8) {
            $rootCauses[] = $this->cause('order_conversion_low', '订单转化差', 4, 0.78, '订单/访客低于历史均值20%以上', '检查价格竞争力、取消政策、库存和促销');
        }

        if (($summary['adr'] ?? 0) > 0 && ($competitors['avg_price'] ?? 0) > 0 && $summary['adr'] > $competitors['avg_price'] * 1.1) {
            $rootCauses[] = $this->cause('price_high', '价格偏高', 5, 0.75, '本店价格高于竞对均价10%以上', '按房型检查价差，必要时做小幅跟价或活动补贴');
        }

        if (($reviews['score'] ?? 0) > 0 && ($competitors['avg_score'] ?? 0) > 0 && $reviews['score'] < $competitors['avg_score'] - 0.1) {
            $rootCauses[] = $this->cause('score_low', '评分偏低', 6, 0.72, '本店评分低于竞对均分0.1以上', '优先处理近期差评关键词和可评价订单转化');
        }

        if (($holiday['days_left'] ?? 999) < 15 && ($holiday['data_status'] ?? '') === self::DATA_OK) {
            $rootCauses[] = $this->cause('holiday_near', '节假日临近', 7, 0.68, '距离节假日小于15天', '提前确认库存、底价、活动和高需求日调价节奏');
        }

        usort($rootCauses, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        if (empty($rootCauses)) {
            return [
                'main_problem' => $problemType ?: 'unknown',
                'problem_level' => 'data_insufficient',
                'conclusion' => '数据不足，建议先补齐采集数据',
                'root_causes' => [],
                'next_actions' => ['补齐OTA曝光、访客、订单、竞对价格和点评数据'],
            ];
        }

        return [
            'main_problem' => $rootCauses[0]['title'],
            'problem_level' => count($rootCauses) >= 3 ? 'high' : 'medium',
            'conclusion' => '规则识别到' . count($rootCauses) . '个可能根因，建议按优先级处理',
            'root_causes' => $rootCauses,
            'next_actions' => array_values(array_unique(array_column($rootCauses, 'suggestion'))),
        ];
    }

    public function alerts(array $hotelIds, ?int $hotelId): array
    {
        if ($this->tableExists('operation_alerts')) {
            $query = Db::name('operation_alerts')->whereNull('deleted_at');
            if ($hotelId !== null && $hotelId > 0) {
                $query->where('hotel_id', $hotelId);
            } elseif (!empty($hotelIds)) {
                $query->whereIn('hotel_id', $hotelIds);
            }

            $rows = $query->order('id', 'desc')->limit(100)->select()->toArray();
            if (!empty($rows)) {
                return [
                    'list' => array_map([$this, 'normalizeAlertRow'], $rows),
                    'unread_count' => count(array_filter($rows, static fn(array $row): bool => ($row['status'] ?? '') !== 'read')),
                    'data_status' => self::DATA_OK,
                ];
            }
        }

        $generated = $this->generateRuleAlerts($hotelIds, $hotelId);
        return [
            'list' => $generated,
            'unread_count' => count($generated),
            'data_status' => empty($generated) ? '暂无预警' : self::DATA_OK,
        ];
    }

    public function markAlertsRead(array $ids): int
    {
        if (!$this->tableExists('operation_alerts')) {
            return 0;
        }

        return Db::name('operation_alerts')
            ->whereIn('id', $ids)
            ->update([
                'status' => 'read',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function strategySimulation(array $hotelIds, ?int $hotelId, array $input): array
    {
        $strategyType = (string)($input['strategy_type'] ?? '');
        $adjustAmount = (float)($input['adjust_amount'] ?? 0);
        $discountRate = (float)($input['discount_rate'] ?? 0);
        $baseline = $this->baseline($hotelIds, 30);
        $forecast = $baseline;
        $risk = ['level' => 'low', 'message' => '规则估算风险较低'];
        $conversionLift = 0.0;
        $orderFactor = 1.0;
        $revenueFactor = 1.0;

        if ($strategyType === 'price_adjust') {
            if ($adjustAmount < 0) {
                $drop = abs($adjustAmount);
                if ($drop <= 5) {
                    $conversionLift = 0.02;
                } elseif ($drop <= 10) {
                    $conversionLift = 0.045;
                } else {
                    $conversionLift = 0.07;
                    $risk = ['level' => 'medium_high', 'message' => '降价超过10元，可能伤害价格体系'];
                }
                $orderFactor += $conversionLift;
                $revenueFactor += $conversionLift - min(0.12, $drop / 100);
            } elseif ($adjustAmount > 0) {
                if ($adjustAmount <= 5) {
                    $orderFactor -= 0.02;
                    $revenueFactor += 0.02;
                } elseif ($adjustAmount <= 10) {
                    $orderFactor -= 0.05;
                    $revenueFactor += 0.01;
                    $risk = ['level' => 'medium', 'message' => '涨价6-10元，订单可能明显下降'];
                } else {
                    $orderFactor -= 0.1;
                    $revenueFactor -= 0.02;
                    $risk = ['level' => 'high', 'message' => '涨价超过10元，价格敏感期风险较高'];
                }
            }
        } elseif ($strategyType === 'promotion') {
            $lift = $discountRate > 0 ? min(0.12, $discountRate / 100 * 0.6) : 0.03;
            $orderFactor += $lift;
            $revenueFactor += $lift - min(0.1, $discountRate / 100);
        } elseif ($strategyType === 'competitor_follow') {
            $orderFactor += 0.03;
            $revenueFactor += 0.01;
        } elseif ($strategyType === 'holiday_strategy') {
            $orderFactor += 0.05;
            $revenueFactor += 0.06;
        } elseif ($strategyType === 'room_inventory') {
            $orderFactor += 0.02;
            $revenueFactor += 0.02;
        }

        $forecast['avg_orders'] = round(($baseline['avg_orders'] ?? 0) * max(0, $orderFactor), 2);
        $forecast['avg_revenue'] = round(($baseline['avg_revenue'] ?? 0) * max(0, $revenueFactor), 2);
        $forecast['avg_conversion'] = round(($baseline['avg_conversion'] ?? 0) * (1 + $conversionLift), 2);

        return [
            'simulated' => true,
            'strategy_type' => $strategyType,
            'strategy_name' => $this->strategyName($strategyType),
            'baseline' => $baseline,
            'forecast' => $forecast,
            'impact' => [
                'orders_change' => round(($forecast['avg_orders'] ?? 0) - ($baseline['avg_orders'] ?? 0), 2),
                'revenue_change' => round(($forecast['avg_revenue'] ?? 0) - ($baseline['avg_revenue'] ?? 0), 2),
                'conversion_change' => round(($forecast['avg_conversion'] ?? 0) - ($baseline['avg_conversion'] ?? 0), 2),
            ],
            'risk' => $risk,
            'recommendation' => $this->buildSimulationRecommendation($strategyType, $risk['level']),
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    public function createAction(array $hotelIds, ?int $hotelId, array $input): int
    {
        $now = date('Y-m-d H:i:s');
        $before = $this->baseline($hotelIds, 7, (string)$input['start_date']);

        return (int)Db::name('operation_action_tracks')->insertGetId([
            'hotel_id' => $hotelId ?: ($hotelIds[0] ?? 0),
            'action_type' => (string)$input['action_type'],
            'action_title' => (string)$input['action_title'],
            'start_date' => (string)$input['start_date'],
            'end_date' => !empty($input['end_date']) ? (string)$input['end_date'] : null,
            'target_metric' => (string)($input['target_metric'] ?? ''),
            'target_change_rate' => (float)($input['target_change_rate'] ?? 0),
            'before_data_json' => json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_data_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'result_status' => 'observing',
            'result_summary' => '',
            'remark' => (string)($input['remark'] ?? ''),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function actionTracking(array $hotelIds, ?int $hotelId): array
    {
        if (!$this->tableExists('operation_action_tracks')) {
            return ['actions' => [], 'data_status' => self::DATA_PENDING];
        }

        $query = Db::name('operation_action_tracks')->whereNull('deleted_at');
        if ($hotelId !== null && $hotelId > 0) {
            $query->where('hotel_id', $hotelId);
        } elseif (!empty($hotelIds)) {
            $query->whereIn('hotel_id', $hotelIds);
        }

        $rows = $query->order('id', 'desc')->limit(100)->select()->toArray();
        $actions = [];
        foreach ($rows as $row) {
            $before = $this->decodeJson((string)($row['before_data_json'] ?? ''));
            $after = $this->afterData($row);
            $result = $this->evaluateActionResult($row, $before, $after);
            $actions[] = [
                'id' => (int)$row['id'],
                'action_title' => (string)$row['action_title'],
                'action_type' => (string)$row['action_type'],
                'start_date' => (string)$row['start_date'],
                'end_date' => (string)($row['end_date'] ?? ''),
                'target_metric' => (string)($row['target_metric'] ?? ''),
                'target_change_rate' => (float)($row['target_change_rate'] ?? 0),
                'status' => (string)$row['status'],
                'before' => $before,
                'after' => $after,
                'result' => $result,
                'result_summary' => (string)($row['result_summary'] ?? ''),
            ];
        }

        return ['actions' => $actions];
    }

    public function finishAction(int $id, array $hotelIds): bool
    {
        if (!$this->tableExists('operation_action_tracks')) {
            return false;
        }

        $row = Db::name('operation_action_tracks')->where('id', $id)->whereIn('hotel_id', $hotelIds)->find();
        if (!$row) {
            return false;
        }

        $before = $this->decodeJson((string)($row['before_data_json'] ?? ''));
        $after = $this->afterData($row);
        $result = $this->evaluateActionResult($row, $before, $after);
        $summary = '策略已结束，结果状态：' . $result['status'] . '，' . $result['message'];

        Db::name('operation_action_tracks')->where('id', $id)->update([
            'status' => 'finished',
            'after_data_json' => json_encode($after, JSON_UNESCAPED_UNICODE),
            'result_status' => $result['status'],
            'result_summary' => $summary,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public function tableExists(string $table): bool
    {
        try {
            Db::query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function buildSummary(array $hotelIds, ?int $hotelId, string $date): array
    {
        $base = [
            'hotel_id' => $hotelId ?: ($hotelIds[0] ?? null),
            'date' => $date,
            'revenue' => 0,
            'orders' => 0,
            'room_nights' => 0,
            'adr' => 0,
            'occ' => 0,
            'revpar' => 0,
            'data_status' => self::DATA_PENDING,
        ];

        $daily = $this->dailyReportRows($hotelIds, $date, $date);
        $online = $this->onlineRows($hotelIds, $date, $date);
        if (empty($daily) && empty($online)) {
            return $base;
        }

        $roomCount = 0;
        foreach ($daily as $row) {
            $reportData = $this->decodeJson((string)($row['report_data'] ?? ''));
            $base['revenue'] += $this->extractRevenue($row, $reportData);
            $base['room_nights'] += (float)($reportData['room_nights'] ?? $reportData['occupied_rooms'] ?? $row['guest_count'] ?? 0);
            $roomCount += (int)($row['room_count'] ?? 0);
            $base['occ'] = max($base['occ'], (float)($row['occupancy_rate'] ?? 0));
        }

        foreach ($online as $row) {
            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $base['revenue'] += (float)($row['amount'] ?? 0);
            $base['orders'] += (int)($row['book_order_num'] ?? 0);
            $base['room_nights'] += (float)($row['quantity'] ?? $row['data_value'] ?? 0);
            if (($raw['bookOrderNum'] ?? 0) > 0) {
                $base['orders'] = max($base['orders'], (int)$raw['bookOrderNum']);
            }
        }

        $base['revenue'] = round($base['revenue'], 2);
        $base['orders'] = (int)$base['orders'];
        $base['room_nights'] = round($base['room_nights'], 2);
        $base['adr'] = $base['room_nights'] > 0 ? round($base['revenue'] / $base['room_nights'], 2) : 0;
        if ($base['occ'] <= 0 && $roomCount > 0 && $base['room_nights'] > 0) {
            $base['occ'] = round(($base['room_nights'] / $roomCount) * 100, 2);
        }
        $base['revpar'] = $roomCount > 0 ? round($base['revenue'] / $roomCount, 2) : 0;
        $base['data_status'] = self::DATA_OK;

        return $base;
    }

    private function buildOta(array $hotelIds, string $date): array
    {
        $base = [
            'exposure' => 0,
            'visitors' => 0,
            'views' => 0,
            'orders' => 0,
            'view_rate' => 0,
            'order_rate' => 0,
            'data_status' => self::DATA_PENDING,
        ];

        $rows = $this->onlineRows($hotelIds, $date, $date);
        if (empty($rows)) {
            return $base;
        }

        foreach ($rows as $row) {
            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $base['exposure'] += (int)($raw['exposure'] ?? $raw['showNum'] ?? $raw['impression'] ?? 0);
            $base['visitors'] += (int)($raw['visitors'] ?? $raw['visitorNum'] ?? $raw['qunarDetailVisitors'] ?? 0);
            $base['views'] += (int)($raw['views'] ?? $raw['totalDetailNum'] ?? $raw['detailVisitors'] ?? 0);
            $base['orders'] += (int)($row['book_order_num'] ?? $raw['bookOrderNum'] ?? $raw['orders'] ?? 0);
        }

        if ($base['exposure'] <= 0 && $base['views'] > 0) {
            $base['exposure'] = $base['views'];
        }
        if ($base['visitors'] <= 0 && $base['views'] > 0) {
            $base['visitors'] = $base['views'];
        }

        $base['view_rate'] = $base['exposure'] > 0 ? round($base['views'] / $base['exposure'] * 100, 2) : 0;
        $base['order_rate'] = $base['visitors'] > 0 ? round($base['orders'] / $base['visitors'] * 100, 2) : 0;
        $base['data_status'] = self::DATA_OK;

        return $base;
    }

    private function buildCompetitors(array $hotelIds, string $date, array $summary, array $reviews): array
    {
        $base = [
            'avg_price' => 0,
            'avg_score' => 0,
            'price_gap' => 0,
            'score_gap' => 0,
            'rank_position' => null,
            'data_status' => self::DATA_PENDING,
        ];

        if ($this->tableExists('competitor_analysis')) {
            try {
                $rows = Db::name('competitor_analysis')
                    ->whereIn('hotel_id', $hotelIds)
                    ->where('analysis_date', $date)
                    ->field('our_price,competitor_price,price_difference,price_index,competitor_data')
                    ->select()
                    ->toArray();
                if (!empty($rows)) {
                    $prices = array_filter(array_map(static fn(array $row): float => (float)($row['competitor_price'] ?? 0), $rows), static fn(float $v): bool => $v > 0);
                    $base['avg_price'] = $this->avg($prices);
                    $base['price_gap'] = round((float)($summary['adr'] ?? 0) - $base['avg_price'], 2);
                    $base['data_status'] = self::DATA_OK;
                    return $base;
                }
            } catch (Throwable $e) {
                return $base;
            }
        }

        $rows = $this->onlineRows([], $date, $date);
        $competitorRows = array_filter($rows, static function (array $row) use ($hotelIds): bool {
            $systemId = (int)($row['system_hotel_id'] ?? 0);
            return $systemId === 0 || !in_array($systemId, $hotelIds, true) || ($row['data_type'] ?? '') === 'competitor';
        });
        if (empty($competitorRows)) {
            return $base;
        }

        $prices = [];
        $scores = [];
        $ranks = [];
        foreach ($competitorRows as $row) {
            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $quantity = (float)($row['quantity'] ?? 0);
            $amount = (float)($row['amount'] ?? 0);
            if ($amount > 0 && $quantity > 0) {
                $prices[] = $amount / $quantity;
            }
            $score = max((float)($row['comment_score'] ?? 0), (float)($row['qunar_comment_score'] ?? 0));
            if ($score > 0) {
                $scores[] = $score;
            }
            if (($raw['amountRank'] ?? 0) > 0) {
                $ranks[] = (int)$raw['amountRank'];
            }
        }

        if (empty($prices) && empty($scores) && empty($ranks)) {
            return $base;
        }

        $base['avg_price'] = $this->avg($prices);
        $base['avg_score'] = $this->avg($scores);
        $base['price_gap'] = $base['avg_price'] > 0 ? round((float)($summary['adr'] ?? 0) - $base['avg_price'], 2) : 0;
        $base['score_gap'] = $base['avg_score'] > 0 ? round((float)($reviews['score'] ?? 0) - $base['avg_score'], 2) : 0;
        $base['rank_position'] = !empty($ranks) ? min($ranks) : null;
        $base['data_status'] = self::DATA_OK;

        return $base;
    }

    private function buildReviews(array $hotelIds, string $date): array
    {
        $base = [
            'score' => 0,
            'review_count' => 0,
            'negative_keywords' => [],
            'data_status' => self::DATA_PENDING,
        ];

        $rows = $this->onlineRows($hotelIds, $date, $date);
        if (empty($rows)) {
            return $base;
        }

        $scores = [];
        $keywords = [];
        foreach ($rows as $row) {
            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            foreach ([(float)($row['comment_score'] ?? 0), (float)($row['qunar_comment_score'] ?? 0)] as $score) {
                if ($score > 0) {
                    $scores[] = $score;
                }
            }
            $base['review_count'] += (int)($raw['reviewCount'] ?? $raw['commentCount'] ?? 0);
            foreach (['negativeKeywords', 'negative_keywords', 'bad_keywords'] as $key) {
                if (!empty($raw[$key]) && is_array($raw[$key])) {
                    $keywords = array_merge($keywords, $raw[$key]);
                }
            }
        }

        if (empty($scores) && $base['review_count'] <= 0) {
            return $base;
        }

        $base['score'] = $this->avg($scores);
        $base['negative_keywords'] = array_values(array_unique(array_slice(array_map('strval', $keywords), 0, 10)));
        $base['data_status'] = self::DATA_OK;

        return $base;
    }

    private function buildHoliday(string $date): array
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $today = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone) ?: new DateTimeImmutable('today', $timezone);
        $holidays = [
            ['name' => '元旦', 'start_date' => '2026-01-01', 'end_date' => '2026-01-03'],
            ['name' => '春节', 'start_date' => '2026-02-15', 'end_date' => '2026-02-23'],
            ['name' => '清明节', 'start_date' => '2026-04-04', 'end_date' => '2026-04-06'],
            ['name' => '劳动节', 'start_date' => '2026-05-01', 'end_date' => '2026-05-05'],
            ['name' => '端午节', 'start_date' => '2026-06-19', 'end_date' => '2026-06-21'],
            ['name' => '中秋节', 'start_date' => '2026-09-25', 'end_date' => '2026-09-27'],
            ['name' => '国庆节', 'start_date' => '2026-10-01', 'end_date' => '2026-10-07'],
        ];

        foreach ($holidays as $holiday) {
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $holiday['end_date'], $timezone);
            if ($end >= $today) {
                $start = DateTimeImmutable::createFromFormat('!Y-m-d', $holiday['start_date'], $timezone);
                $daysLeft = $today < $start ? (int)$today->diff($start)->format('%a') : 0;
                return [
                    'next_holiday' => $holiday['name'],
                    'days_left' => $daysLeft,
                    'suggestion' => $daysLeft < 15 ? '节假日临近，建议检查库存、价格和活动节奏' : '保持常规监控',
                    'data_status' => self::DATA_OK,
                ];
            }
        }

        return [
            'next_holiday' => null,
            'days_left' => null,
            'suggestion' => self::DATA_PENDING,
            'data_status' => self::DATA_PENDING,
        ];
    }

    private function averageOnlineMetrics(array $hotelIds, string $date, int $days): array
    {
        $start = date('Y-m-d', strtotime($date . ' -' . $days . ' days'));
        $end = date('Y-m-d', strtotime($date . ' -1 day'));
        $rows = $this->onlineRows($hotelIds, $start, $end);
        if (empty($rows)) {
            return [];
        }

        $byDate = [];
        foreach ($rows as $row) {
            $day = (string)$row['data_date'];
            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $byDate[$day]['exposure'] = ($byDate[$day]['exposure'] ?? 0) + (int)($raw['exposure'] ?? $raw['showNum'] ?? $raw['totalDetailNum'] ?? 0);
            $byDate[$day]['visitors'] = ($byDate[$day]['visitors'] ?? 0) + (int)($raw['visitors'] ?? $raw['qunarDetailVisitors'] ?? $raw['totalDetailNum'] ?? 0);
            $byDate[$day]['views'] = ($byDate[$day]['views'] ?? 0) + (int)($raw['views'] ?? $raw['totalDetailNum'] ?? 0);
            $byDate[$day]['orders'] = ($byDate[$day]['orders'] ?? 0) + (int)($row['book_order_num'] ?? $raw['bookOrderNum'] ?? 0);
        }

        $count = max(1, count($byDate));
        $sum = ['exposure' => 0, 'visitors' => 0, 'views' => 0, 'orders' => 0];
        foreach ($byDate as $metric) {
            foreach ($sum as $key => $value) {
                $sum[$key] += (float)($metric[$key] ?? 0);
            }
        }

        $exposure = $sum['exposure'] / $count;
        $visitors = $sum['visitors'] / $count;
        $views = $sum['views'] / $count;
        $orders = $sum['orders'] / $count;

        return [
            'exposure' => $exposure,
            'visitors' => $visitors,
            'views' => $views,
            'orders' => $orders,
            'view_rate' => $exposure > 0 ? $views / $exposure * 100 : 0,
            'order_rate' => $visitors > 0 ? $orders / $visitors * 100 : 0,
        ];
    }

    private function baseline(array $hotelIds, int $days, ?string $endDate = null): array
    {
        $end = $endDate ? date('Y-m-d', strtotime($endDate . ' -1 day')) : date('Y-m-d');
        $start = date('Y-m-d', strtotime($end . ' -' . ($days - 1) . ' days'));
        $daily = $this->dailyReportRows($hotelIds, $start, $end);
        $online = $this->onlineRows($hotelIds, $start, $end);
        $orders = 0.0;
        $revenue = 0.0;
        $roomNights = 0.0;
        $conversionValues = [];
        $dates = [];

        foreach ($daily as $row) {
            $dates[(string)$row['report_date']] = true;
            $reportData = $this->decodeJson((string)($row['report_data'] ?? ''));
            $revenue += $this->extractRevenue($row, $reportData);
            $roomNights += (float)($reportData['room_nights'] ?? $reportData['occupied_rooms'] ?? $row['guest_count'] ?? 0);
        }

        foreach ($online as $row) {
            $dates[(string)$row['data_date']] = true;
            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $orders += (float)($row['book_order_num'] ?? $raw['bookOrderNum'] ?? 0);
            if ((float)($row['amount'] ?? 0) > 0) {
                $revenue += (float)$row['amount'];
            }
            $roomNights += (float)($row['quantity'] ?? 0);
            $visitors = (float)($raw['visitors'] ?? $raw['qunarDetailVisitors'] ?? $raw['totalDetailNum'] ?? 0);
            if ($visitors > 0) {
                $conversionValues[] = ((float)($row['book_order_num'] ?? $raw['bookOrderNum'] ?? 0)) / $visitors * 100;
            }
        }

        $count = count($dates);
        return [
            'days' => $days,
            'actual_days' => $count,
            'avg_orders' => $count > 0 ? round($orders / $count, 2) : 0,
            'avg_revenue' => $count > 0 ? round($revenue / $count, 2) : 0,
            'avg_room_nights' => $count > 0 ? round($roomNights / $count, 2) : 0,
            'avg_conversion' => $this->avg($conversionValues),
            'data_status' => $count > 0 ? self::DATA_OK : self::DATA_PENDING,
        ];
    }

    private function dailyReportRows(array $hotelIds, string $startDate, string $endDate): array
    {
        if (!$this->tableExists('daily_reports') || empty($hotelIds)) {
            return [];
        }

        try {
            return Db::name('daily_reports')
                ->whereIn('hotel_id', $hotelIds)
                ->whereBetween('report_date', [$startDate, $endDate])
                ->select()
                ->toArray();
        } catch (Throwable $e) {
            return [];
        }
    }

    private function onlineRows(array $hotelIds, string $startDate, string $endDate): array
    {
        if (!$this->tableExists('online_daily_data')) {
            return [];
        }

        try {
            $query = Db::name('online_daily_data')->whereBetween('data_date', [$startDate, $endDate]);
            if (!empty($hotelIds)) {
                $safeHotelIds = implode(',', array_map('intval', $hotelIds));
                $query->whereRaw("(system_hotel_id IN ({$safeHotelIds}) OR system_hotel_id IS NULL)");
            }
            return $query->select()->toArray();
        } catch (Throwable $e) {
            return [];
        }
    }

    private function generateRuleAlerts(array $hotelIds, ?int $hotelId): array
    {
        $date = date('Y-m-d');
        $full = $this->fullData($hotelIds, $hotelId, $date);
        $alerts = [];
        $id = 1;

        foreach ($full['abnormal_flags'] as $flag) {
            $alerts[] = $this->alert($id++, $hotelId ?: ($hotelIds[0] ?? 0), 'data_abnormal', 'high', '数据异常', $flag, $date);
        }
        if (($full['ota']['exposure'] ?? 0) <= 0 && ($full['ota']['data_status'] ?? '') === self::DATA_OK) {
            $alerts[] = $this->alert($id++, $hotelId ?: ($hotelIds[0] ?? 0), 'traffic_zero', 'high', '流量为0', 'OTA曝光为0，请检查采集和渠道状态', $date);
        }
        if (($full['ota']['order_rate'] ?? 0) > 0 && ($full['ota']['order_rate'] ?? 0) < 3) {
            $alerts[] = $this->alert($id++, $hotelId ?: ($hotelIds[0] ?? 0), 'conversion_low', 'medium', '转化偏低', '订单/访客转化率低于3%', $date);
        }
        if (($full['competitors']['price_gap'] ?? 0) > 10) {
            $alerts[] = $this->alert($id++, $hotelId ?: ($hotelIds[0] ?? 0), 'price_high', 'medium', '价格偏高', '本店价格高于竞对均价', $date);
        }
        if (($full['reviews']['score'] ?? 5) > 0 && ($full['reviews']['score'] ?? 5) < 4.5) {
            $alerts[] = $this->alert($id++, $hotelId ?: ($hotelIds[0] ?? 0), 'review_risk', 'medium', '点评风险', '当前评分低于4.5', $date);
        }
        if (($full['holiday']['days_left'] ?? 999) < 15 && ($full['holiday']['data_status'] ?? '') === self::DATA_OK) {
            $alerts[] = $this->alert($id++, $hotelId ?: ($hotelIds[0] ?? 0), 'holiday_near', 'low', '节假日临近', '距离下个节假日不足15天', $date);
        }

        return $alerts;
    }

    private function afterData(array $row): array
    {
        $startDate = (string)$row['start_date'];
        $endDate = (string)($row['end_date'] ?: date('Y-m-d'));
        $hotelIds = [(int)$row['hotel_id']];
        return $this->baseline($hotelIds, max(1, (int)((strtotime($endDate) - strtotime($startDate)) / 86400) + 1), date('Y-m-d', strtotime($endDate . ' +1 day')));
    }

    private function evaluateActionResult(array $row, array $before, array $after): array
    {
        $start = strtotime((string)$row['start_date']);
        if ($start === false || time() - $start < 3 * 86400) {
            return ['status' => 'observing', 'message' => '执行时间不足3天'];
        }
        if (($after['data_status'] ?? '') !== self::DATA_OK) {
            return ['status' => 'observing', 'message' => '暂无后续数据'];
        }

        $targetMetric = (string)($row['target_metric'] ?: 'avg_orders');
        $metricMap = [
            'orders' => 'avg_orders',
            'revenue' => 'avg_revenue',
            'room_nights' => 'avg_room_nights',
            'conversion' => 'avg_conversion',
        ];
        $metric = $metricMap[$targetMetric] ?? $targetMetric;
        $beforeValue = (float)($before[$metric] ?? 0);
        $afterValue = (float)($after[$metric] ?? 0);
        $targetRate = (float)($row['target_change_rate'] ?? 0);
        if ($beforeValue <= 0 || $targetRate <= 0) {
            return ['status' => 'observing', 'message' => '目标或执行前数据不足'];
        }

        $actualRate = (($afterValue - $beforeValue) / $beforeValue) * 100;
        if ($actualRate >= $targetRate) {
            return ['status' => 'success', 'message' => '达到目标', 'actual_change_rate' => round($actualRate, 2)];
        }
        if ($actualRate >= $targetRate * 0.7) {
            return ['status' => 'near_success', 'message' => '达到目标70%以上', 'actual_change_rate' => round($actualRate, 2)];
        }

        return ['status' => 'failed', 'message' => '低于目标70%', 'actual_change_rate' => round($actualRate, 2)];
    }

    private function normalizeAlertRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['hotel_id'] = (int)$row['hotel_id'];
        $row['raw_data'] = $this->decodeJson((string)($row['raw_data'] ?? ''));
        return $row;
    }

    private function alert(int $id, int $hotelId, string $type, string $level, string $title, string $message, string $date): array
    {
        return [
            'id' => $id,
            'hotel_id' => $hotelId,
            'alert_type' => $type,
            'level' => $level,
            'title' => $title,
            'message' => $message,
            'source' => 'rule',
            'status' => 'unread',
            'related_date' => $date,
            'raw_data' => [],
        ];
    }

    private function cause(string $type, string $title, int $priority, float $confidence, string $evidence, string $suggestion): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'priority' => $priority,
            'confidence' => $confidence,
            'evidence' => $evidence,
            'suggestion' => $suggestion,
        ];
    }

    private function extractRevenue(array $row, array $reportData): float
    {
        $revenue = (float)($row['revenue'] ?? 0);
        if ($revenue > 0) {
            return $revenue;
        }
        foreach (['day_revenue', 'total_revenue', 'revenue', 'room_revenue'] as $key) {
            if ((float)($reportData[$key] ?? 0) > 0) {
                return (float)$reportData[$key];
            }
        }
        return 0.0;
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
        if ($type === 'holiday_strategy') {
            return '建议结合节假日库存和竞对价格分阶段执行';
        }
        return '建议先小范围执行，并持续跟踪订单、收入和转化变化';
    }
}
