<?php
declare(strict_types=1);

namespace app\service;

use app\service\operation\ExecutionOutcomeService;
use app\service\operation\ExecutionFlowReadService;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;
use Throwable;

class OperationManagementService
{
    private RevenuePricingRecommendationService $pricingRecommendationService;
    private ExecutionOutcomeService $executionOutcomeService;
    private ExecutionFlowReadService $executionFlowReadService;
    private OperationOptimizationReviewService $operationOptimizationReviewService;

    private const EXECUTION_CREDENTIAL_KEYS = [
        'authorization' => true,
        'authorizationheader' => true,
        'authdata' => true,
        'authtoken' => true,
        'accesstoken' => true,
        'refreshtoken' => true,
        'token' => true,
        'cookie' => true,
        'cookies' => true,
        'cookieobj' => true,
        'cookieheader' => true,
        'setcookie' => true,
        'password' => true,
        'passwd' => true,
        'secret' => true,
        'secretjson' => true,
        'clientsecret' => true,
        'apisecret' => true,
        'spidertoken' => true,
        'mtgsig' => true,
        'sessionid' => true,
        'sessiontoken' => true,
    ];

    private const DATA_PENDING = '待接入真实数据';
    private const DATA_OK = 'ok';
    private const DISCLAIMER = '该结果基于历史数据和规则估算，仅用于运营参考。';

    public function __construct(
        ?RevenuePricingRecommendationService $pricingRecommendationService = null,
        ?ExecutionOutcomeService $executionOutcomeService = null,
        ?ExecutionFlowReadService $executionFlowReadService = null,
        ?OperationOptimizationReviewService $operationOptimizationReviewService = null
    )
    {
        $this->pricingRecommendationService = $pricingRecommendationService ?? new RevenuePricingRecommendationService();
        $this->executionOutcomeService = $executionOutcomeService ?? new ExecutionOutcomeService();
        $this->executionFlowReadService = $executionFlowReadService
            ?? new ExecutionFlowReadService($this->executionOutcomeService);
        $this->operationOptimizationReviewService = $operationOptimizationReviewService
            ?? new OperationOptimizationReviewService();
    }

    /**
     * @return list<int>
     */
    private function scopeHotelIdsForSelection(array $hotelIds, ?int $hotelId): array
    {
        $hotelIds = array_values(array_unique(array_filter(
            array_map('intval', $hotelIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($hotelId === null || $hotelId <= 0) {
            if (count($hotelIds) > 1) {
                throw new \InvalidArgumentException('A single permitted hotel must be selected for hotel-scoped operation analysis');
            }
            return $hotelIds;
        }
        if (!in_array($hotelId, $hotelIds, true)) {
            throw new \InvalidArgumentException('Selected hotel is outside the permitted hotel scope');
        }

        return [$hotelId];
    }

    public function fullData(array $hotelIds, ?int $hotelId, string $date): array
    {
        $hotelIds = $this->scopeHotelIdsForSelection($hotelIds, $hotelId);
        $summary = $this->buildSummary($hotelIds, $hotelId, $date);
        $ota = $this->buildOta($hotelIds, $date);
        $serviceQuality = $this->buildServiceQuality($hotelIds, $date);
        $competitors = $this->buildCompetitors($hotelIds, $date, $summary);
        $holiday = $this->buildHoliday($date);
        $abnormalFlags = [];

        if (($ota['exposure'] ?? null) !== null
            && ($ota['visitors'] ?? null) !== null
            && (float)$ota['exposure'] <= 0
            && (float)$ota['visitors'] <= 0
            && ($ota['orders'] ?? 0) > 0
        ) {
            $abnormalFlags[] = '曝光/访客为0但订单大于0，疑似采集异常';
        }

        foreach ([
            '经营日报' => $summary,
            'OTA数据' => $ota,
            '竞对数据' => $competitors,
            '服务质量数据' => $serviceQuality,
        ] as $module => $data) {
            if ($module === 'OTA数据' && ($data['data_status'] ?? '') !== self::DATA_OK) {
                $channel = $this->operatingSnapshotChannel($summary);
                $channelLabel = $this->otaChannelLabel($channel);
                $abnormalFlags[] = '本店' . $channelLabel . '漏斗缺失：曝光/访客未返回可信证据';
                continue;
            }
            if ($module === '经营日报' && ($data['data_status'] ?? '') !== self::DATA_OK) {
                $gapMessages = array_values(array_filter(array_map(
                    static fn(mixed $gap): string => is_array($gap) ? trim((string)($gap['message'] ?? '')) : '',
                    (array)($data['data_gaps'] ?? [])
                )));
                $abnormalFlags[] = '经营数据不完整：' . ($gapMessages !== [] ? implode('；', $gapMessages) : '必需字段或来源未确认');
                continue;
            }
            if (($data['data_status'] ?? '') === self::DATA_PENDING) {
                $abnormalFlags[] = $module . '为空，待接入真实数据';
            }
        }

        return [
            'summary' => $summary,
            'ota' => $ota,
            'competitors' => $competitors,
            'service_quality' => $serviceQuality,
            'holiday' => $holiday,
            'abnormal_flags' => array_values(array_unique($abnormalFlags)),
        ];
    }

    public function rootCause(array $hotelIds, ?int $hotelId, string $date, string $problemType): array
    {
        $hotelIds = $this->scopeHotelIdsForSelection($hotelIds, $hotelId);
        $fullData = $this->fullData($hotelIds, $hotelId, $date);
        $avg7 = $this->averageOnlineMetrics($hotelIds, $date, 7);
        $avg30 = $this->averageOnlineMetrics($hotelIds, $date, 30);

        return $this->buildRootCauseResult($fullData, $avg7, $avg30, $problemType);
    }

    private function buildRootCauseResult(array $fullData, array $avg7, array $avg30, string $problemType): array
    {
        $todayOta = $fullData['ota'] ?? [];
        $summary = $fullData['summary'] ?? [];
        $competitors = $fullData['competitors'] ?? [];
        $serviceQuality = $fullData['service_quality'] ?? [];
        $holiday = $fullData['holiday'] ?? [];
        $rootCauses = [];

        if (($todayOta['orders'] ?? 0) > 0
            && ($todayOta['exposure'] ?? null) !== null
            && ($todayOta['visitors'] ?? null) !== null
            && (float)$todayOta['exposure'] <= 0
            && (float)$todayOta['visitors'] <= 0
        ) {
            $rootCauses[] = $this->cause('data_abnormal', '数据采集异常', 1, 0.95, '曝光/访客为0但订单大于0', '优先检查OTA采集配置、Cookie状态和字段映射', [
                'status' => 'available',
                'type' => 'same_day_cross_field_consistency',
                'metric' => 'exposure_visitors_orders',
                'measured_value' => ['exposure' => (float)$todayOta['exposure'], 'visitors' => (float)$todayOta['visitors'], 'orders' => (float)$todayOta['orders']],
                'comparison_rule' => 'exposure <= 0 and visitors <= 0 while orders > 0',
                'reference_scope' => 'same_hotel_same_platform_same_business_date',
            ]);
        }

        $todayFunnelComparable = ($todayOta['data_status'] ?? '') === self::DATA_OK;
        $avg7FunnelComparable = ($avg7['data_status'] ?? '') === self::DATA_OK;
        $avg30FunnelComparable = ($avg30['data_status'] ?? '') === self::DATA_OK;

        if ($todayFunnelComparable && $avg7FunnelComparable && ($avg7['exposure'] ?? 0) > 0 && ($todayOta['exposure'] ?? 0) < $avg7['exposure'] * 0.7) {
            $rootCauses[] = $this->cause('traffic_down', '曝光下降', 2, 0.82, '今日曝光低于7日均值30%以上', '检查渠道排名、标题图片和活动流量入口', [
                'status' => 'available', 'type' => 'historical_average', 'metric' => 'exposure',
                'measured_value' => (float)$todayOta['exposure'], 'reference_value' => (float)$avg7['exposure'],
                'history_window' => 7, 'comparison_rule' => 'measured_value < reference_value * 0.7',
                'reference_scope' => 'same_hotel_same_platform',
            ]);
        }

        if ($todayFunnelComparable && $avg30FunnelComparable && ($avg30['view_rate'] ?? 0) > 0 && ($todayOta['view_rate'] ?? 0) < $avg30['view_rate'] * 0.8) {
            $rootCauses[] = $this->cause('view_conversion_low', '浏览转化差', 3, 0.78, '浏览/曝光低于历史均值20%以上', '优化首图、卖点、价格展示和可售房型', [
                'status' => 'available', 'type' => 'historical_average', 'metric' => 'view_rate',
                'measured_value' => (float)$todayOta['view_rate'], 'reference_value' => (float)$avg30['view_rate'],
                'history_window' => 30, 'comparison_rule' => 'measured_value < reference_value * 0.8',
                'reference_scope' => 'same_hotel_same_platform',
            ]);
        }

        if ($todayFunnelComparable && $avg30FunnelComparable && ($avg30['order_rate'] ?? 0) > 0 && ($todayOta['order_rate'] ?? 0) < $avg30['order_rate'] * 0.8) {
            $rootCauses[] = $this->cause('order_conversion_low', '订单转化差', 4, 0.78, '订单/访客低于历史均值20%以上', '检查价格竞争力、取消政策、库存和促销', [
                'status' => 'available', 'type' => 'historical_average', 'metric' => 'order_rate',
                'measured_value' => (float)$todayOta['order_rate'], 'reference_value' => (float)$avg30['order_rate'],
                'history_window' => 30, 'comparison_rule' => 'measured_value < reference_value * 0.8',
                'reference_scope' => 'same_hotel_same_platform',
            ]);
        }

        if (($competitors['data_status'] ?? '') === self::DATA_OK
            && ($competitors['comparability_status'] ?? '') === 'eligible'
            && ($competitors['avg_our_public_price'] ?? 0) > 0
            && ($competitors['avg_price'] ?? 0) > 0
            && $competitors['avg_our_public_price'] > $competitors['avg_price'] * 1.1
        ) {
            $rootCauses[] = $this->cause('price_high', '价格偏高', 5, 0.75, '本店价格高于竞对均价10%以上', '按房型检查价差，必要时做小幅跟价或活动补贴', [
                'status' => 'available', 'type' => 'competitor_average', 'metric' => 'ota_public_display_price',
                'measured_value' => (float)$competitors['avg_our_public_price'], 'reference_value' => (float)$competitors['avg_price'],
                'comparison_rule' => 'measured_value > reference_value * 1.1',
                'reference_scope' => 'same_platform_stay_dates_room_rate_meal_cancel_payment_tax_currency_guest_mix',
                'comparison_key' => (string)($competitors['comparison_key'] ?? ''),
            ]);
        }

        $psiScore = (float)($serviceQuality['avg_psi_score'] ?? 0);
        $serviceScore = (float)($serviceQuality['avg_service_score'] ?? 0);
        if ($this->serviceQualityThresholdEligible($serviceQuality) && (($psiScore > 0 && $psiScore < 80) || ($serviceScore > 0 && $serviceScore < 80))) {
            $rootCauses[] = $this->cause('service_quality_low', '服务质量偏低', 6, 0.72, 'OTA服务质量或PSI低于80分', '优先复核服务质量扣分项、履约问题和影响转化的服务节点', [
                'status' => 'available', 'type' => 'fixed_threshold', 'metric' => 'service_quality_score',
                'measured_value' => ['psi_score' => $psiScore, 'service_score' => $serviceScore],
                'reference_value' => 80, 'comparison_rule' => '0 < measured_value < 80',
                'reference_scope' => 'ota_service_quality_rule',
            ]);
        }

        if (($holiday['days_left'] ?? 999) < 15 && ($holiday['data_status'] ?? '') === self::DATA_OK) {
            $rootCauses[] = $this->cause('holiday_near', '节假日临近', 7, 0.68, '距离节假日小于15天', '提前确认库存、底价、活动和高需求日调价节奏', [
                'status' => 'available', 'type' => 'fixed_threshold', 'metric' => 'holiday_days_left',
                'measured_value' => (int)$holiday['days_left'], 'reference_value' => 15,
                'comparison_rule' => 'measured_value < 15', 'reference_scope' => 'holiday_calendar',
            ]);
        }

        usort($rootCauses, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        if (empty($rootCauses)) {
            if (($summary['data_status'] ?? '') === self::DATA_OK
                && ($todayOta['data_status'] ?? '') !== self::DATA_OK
            ) {
                $channel = $this->operatingSnapshotChannel($summary);
                $channelLabel = $this->otaChannelLabel($channel);
                return [
                    'main_problem' => ($channel !== '' ? $channel : 'ota') . '_funnel_missing',
                    'problem_level' => 'data_insufficient',
                    'conclusion' => '本店经营快照已返回，但' . $channelLabel . '曝光/访客漏斗缺失，暂不能分析流量与转化的可能影响因素',
                    'candidate_factors' => [],
                    'root_causes' => [],
                    'legacy_field_note' => 'root_causes 为兼容旧客户端保留，语义等同 candidate_factors，不代表已证明根因',
                    'next_actions' => ['补齐本店' . $channelLabel . '曝光、访客及转化漏斗证据'],
                ];
            }
            return [
                'main_problem' => $problemType ?: 'unknown',
                'problem_level' => 'data_insufficient',
                'conclusion' => '数据不足，建议先补齐采集数据',
                'candidate_factors' => [],
                'root_causes' => [],
                'legacy_field_note' => 'root_causes 为兼容旧客户端保留，语义等同 candidate_factors，不代表已证明根因',
                'next_actions' => ['补齐OTA曝光、访客、订单、竞对价格、广告和服务质量数据'],
            ];
        }

        return [
            'main_problem' => $rootCauses[0]['title'],
            'problem_level' => count($rootCauses) >= 3 ? 'high' : 'medium',
            'conclusion' => '规则识别到' . count($rootCauses) . '个可能影响因素；仅为关联线索，不构成因果证明',
            'analysis_scope' => '规则诊断线索；需结合原始数据和业务现场复核',
            'candidate_factors' => $rootCauses,
            'root_causes' => $rootCauses,
            'legacy_field_note' => 'root_causes 为兼容旧客户端保留，语义等同 candidate_factors，不代表已证明根因',
            'next_actions' => array_values(array_unique(array_column($rootCauses, 'suggestion'))),
        ];
    }

    public function alerts(array $hotelIds, ?int $hotelId, bool $canExecute = false): array
    {
        if ($hotelId === null || $hotelId <= 0) {
            throw new \InvalidArgumentException('运营预警必须选择单个有权限的酒店');
        }
        $hotelIds = $this->scopeHotelIdsForSelection($hotelIds, $hotelId);

        $persisted = $this->tableExists('operation_alerts');
        $generated = $this->generateRuleAlerts([$hotelId], $hotelId);
        if ($persisted) {
            if ($generated !== []) {
                $this->persistRuleAlerts($generated);
            }

            $rows = Db::name('operation_alerts')
                ->where('hotel_id', $hotelId)
                ->whereNull('deleted_at')
                ->order('id', 'desc')
                ->limit(100)
                ->select()
                ->toArray();
            $alerts = array_map([$this, 'normalizeAlertRow'], $rows);
        } else {
            $alerts = $generated;
        }

        return [
            'list' => $this->attachAlertExecutionBridges($alerts, $persisted, $canExecute),
            'unread_count' => count(array_filter($alerts, static fn(array $row): bool => ($row['status'] ?? '') !== 'read')),
            'data_status' => empty($alerts) ? '暂无预警' : self::DATA_OK,
            'selected_hotel_id' => $hotelId,
            'generated_for_date' => date('Y-m-d'),
            'scope' => 'single_hotel',
            'capabilities' => [
                'can_execute' => $canExecute,
                'can_mark_read' => $canExecute,
            ],
        ];
    }

    public function markAlertsRead(array $ids, array $hotelIds): int
    {
        if (!$this->tableExists('operation_alerts')) {
            return 0;
        }

        return Db::name('operation_alerts')
            ->whereIn('id', $ids)
            ->whereIn('hotel_id', $hotelIds)
            ->update([
                'status' => 'read',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function createExecutionIntentFromAlert(int $alertId, array $hotelIds, int $createdBy): array
    {
        if ($alertId <= 0) {
            throw new \InvalidArgumentException('operation alert id is invalid');
        }
        if (!$this->tableExists('operation_alerts')) {
            throw new \RuntimeException('operation_alerts table does not exist, run database migration first');
        }

        $hotelIds = array_values(array_unique(array_filter(
            array_map('intval', $hotelIds),
            static fn(int $id): bool => $id > 0
        )));
        $row = Db::name('operation_alerts')
            ->where('id', $alertId)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at')
            ->find();
        if (!$row) {
            throw new \RuntimeException('operation alert not found: 预警不存在或无权限');
        }

        $alert = $this->normalizeAlertRow($row);
        $unavailableReason = $this->alertExecutionEvidenceUnavailableReason($alert);
        if ($unavailableReason !== '') {
            throw new \InvalidArgumentException($unavailableReason);
        }
        $hotelId = (int)$alert['hotel_id'];
        $idempotencyKey = 'operation_alert_' . md5('v1|' . $hotelId . '|' . $alertId);
        $intent = $this->createExecutionIntent(
            $hotelIds,
            $hotelId,
            $this->buildAlertExecutionIntentInput($alert),
            $createdBy,
            false,
            $idempotencyKey,
            true
        );

        Db::name('operation_alerts')
            ->where('id', $alertId)
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at')
            ->update([
                'status' => 'read',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        $alert['status'] = 'read';
        $alert['task_bridge'] = $this->alertExecutionBridgeFromIntent($intent);

        return [
            'alert' => $alert,
            'execution_intent' => $intent,
            'reused_existing_intent' => ($intent['idempotent_replay'] ?? false) === true,
            'execution_policy' => 'pending_human_approval_no_automatic_ota_write',
        ];
    }

    public function strategySimulation(array $hotelIds, ?int $hotelId, array $input): array
    {
        $hotelIds = $this->scopeHotelIdsForSelection($hotelIds, $hotelId);
        $strategyType = (string)($input['strategy_type'] ?? '');
        $adjustAmount = (float)($input['adjust_amount'] ?? 0);
        $discountRate = (float)($input['discount_rate'] ?? 0);
        $baseline = $this->baseline($hotelIds, 30);
        if (($baseline['data_status'] ?? '') !== self::DATA_OK || (int)($baseline['actual_days'] ?? 0) <= 0) {
            $emptyScenario = [
                'avg_orders' => null,
                'avg_revenue' => null,
                'avg_conversion' => null,
            ];
            return [
                'simulated' => false,
                'status' => 'insufficient_data',
                'strategy_type' => $strategyType,
                'strategy_name' => $this->strategyName($strategyType),
                'baseline' => $baseline,
                'rule_scenario' => $emptyScenario,
                'forecast' => $emptyScenario,
                'legacy_field_note' => 'forecast 为兼容旧客户端保留，内容等同 rule_scenario，不是经营预测',
                'impact' => [
                    'orders_change' => null,
                    'revenue_change' => null,
                    'conversion_change' => null,
                ],
                'risk' => ['level' => 'unknown', 'basis' => 'not_assessed', 'message' => '缺少可比历史基线，风险未评估'],
                'recommendation' => '缺少可比历史基线，暂无法估算策略影响。请先补齐并核验历史经营数据。',
                'disclaimer' => '缺少完整历史基线，本次未生成规则情景；不得作为预测或执行依据。',
            ];
        }
        $ruleScenario = $baseline;
        $risk = ['level' => 'unknown', 'basis' => 'not_assessed', 'message' => '现有规则未形成风险等级证据，风险待人工评估'];
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
                    $risk = ['level' => 'medium_high', 'basis' => 'fixed_rule_threshold', 'message' => '固定规则阈值提示：降价超过10元，可能影响价格体系；实际风险需人工核验'];
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
                    $risk = ['level' => 'medium', 'basis' => 'fixed_rule_threshold', 'message' => '固定规则阈值提示：涨价6-10元可能影响订单；实际影响需人工核验'];
                } else {
                    $orderFactor -= 0.1;
                    $revenueFactor -= 0.02;
                    $risk = ['level' => 'high', 'basis' => 'fixed_rule_threshold', 'message' => '固定规则阈值提示：涨价超过10元可能放大价格敏感风险；实际风险需人工核验'];
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

        $ruleScenario['avg_orders'] = round((float)$baseline['avg_orders'] * max(0, $orderFactor), 2);
        $ruleScenario['avg_revenue'] = round((float)$baseline['avg_revenue'] * max(0, $revenueFactor), 2);
        $ruleScenario['avg_conversion'] = $baseline['avg_conversion'] === null
            ? null
            : round((float)$baseline['avg_conversion'] * (1 + $conversionLift), 2);

        return [
            'simulated' => true,
            'status' => 'rule_scenario',
            'strategy_type' => $strategyType,
            'strategy_name' => $this->strategyName($strategyType),
            'baseline' => $baseline,
            'rule_scenario' => $ruleScenario,
            'forecast' => $ruleScenario,
            'legacy_field_note' => 'forecast 为兼容旧客户端保留，内容等同 rule_scenario，不是经营预测',
            'impact' => [
                'orders_change' => round((float)$ruleScenario['avg_orders'] - (float)$baseline['avg_orders'], 2),
                'revenue_change' => round((float)$ruleScenario['avg_revenue'] - (float)$baseline['avg_revenue'], 2),
                'conversion_change' => $ruleScenario['avg_conversion'] === null || $baseline['avg_conversion'] === null
                    ? null
                    : round((float)$ruleScenario['avg_conversion'] - (float)$baseline['avg_conversion'], 2),
            ],
            'risk' => $risk,
            'recommendation' => $this->buildSimulationRecommendation($strategyType, $risk['level']),
            'disclaimer' => '该结果由历史基线乘以固定规则系数生成，是规则情景而非经营预测。风险等级只在规则命中时给出，执行前需人工复核。',
        ];
    }

    public function createAction(array $hotelIds, ?int $hotelId, array $input): int
    {
        $now = date('Y-m-d H:i:s');
        $hotelIds = $this->scopeHotelIdsForSelection($hotelIds, $hotelId);
        $selectedHotelId = (int)($hotelId ?: ($hotelIds[0] ?? 0));
        if ($selectedHotelId <= 0) {
            throw new \InvalidArgumentException('A permitted hotel must be selected');
        }
        $hotelIds = [$selectedHotelId];
        $before = $this->baseline($hotelIds, 7, (string)$input['start_date']);

        $data = [
            'hotel_id' => $selectedHotelId,
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
        ];

        return (int)Db::name('operation_action_tracks')->insertGetId(
            $this->withHotelTenantId($data, 'operation_action_tracks', $selectedHotelId)
        );
    }

    public function actionTracking(array $hotelIds, ?int $hotelId): array
    {
        if (!$this->tableExists('operation_action_tracks')) {
            return [
                'actions' => [],
                'effect_validation' => $this->buildEffectValidationSummary(
                    [],
                    ['total' => 0, 'adopted' => 0, 'data_status' => self::DATA_PENDING],
                    ['reviewed' => 0, 'accurate' => 0, 'data_status' => self::DATA_PENDING],
                    [['code' => 'operation_action_tracks_missing', 'message' => '策略动作追踪表不存在']]
                ),
                'data_status' => self::DATA_PENDING,
            ];
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

        return [
            'actions' => $actions,
            'effect_validation' => $this->buildEffectValidation($hotelIds, $hotelId, $actions),
        ];
    }

    public function executionFlow(array $hotelIds, ?int $hotelId, array $filters = []): array
    {
        if (!$this->tableExists('operation_execution_intents')) {
            return [
                'summary' => $this->buildExecutionFlowSummary([]),
                'stages' => $this->buildExecutionFlowStages([]),
                'list' => [],
                'data_status' => self::DATA_PENDING,
                'data_gaps' => [['code' => 'operation_execution_intents_missing', 'message' => 'execution intent table missing']],
                'matched_total' => null,
                'returned_count' => 0,
                'truncated' => false,
                'statistics' => [
                    'execution_total_loaded' => false,
                    'task_status_loaded' => false,
                    'evidence_loaded' => false,
                    'roi_loaded' => false,
                ],
            ];
        }

        $query = Db::name('operation_execution_intents')->whereNull('deleted_at');
        if ($hotelId !== null && $hotelId > 0) {
            $query->where('hotel_id', $hotelId);
        } elseif (!empty($hotelIds)) {
            $query->whereIn('hotel_id', $hotelIds);
        }
        $intentId = (int)($filters['intent_id'] ?? 0);
        if ($intentId > 0) {
            $query->where('id', $intentId);
        }
        foreach (['source_module', 'platform', 'object_type', 'action_type', 'status'] as $field) {
            $value = trim((string)($filters[$field] ?? ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        $platforms = $filters['platforms'] ?? [];
        if (is_string($platforms)) {
            $platforms = preg_split('/[\s,]+/', $platforms) ?: [];
        }
        $platforms = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            is_array($platforms) ? $platforms : []
        ))));
        if ($platforms !== []) {
            $query->whereIn('platform', $platforms);
        }

        $targetDate = substr(trim((string)($filters['target_date'] ?? '')), 0, 10);
        if ($targetDate !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) !== 1) {
                throw new \InvalidArgumentException('target_date must use YYYY-MM-DD');
            }
            $query->whereRaw(
                '(date_start <= ? AND (((date_end IS NULL OR date_end = ? OR date_end = ?) AND date_start = ?) OR date_end >= ?))',
                [$targetDate, '', '0000-00-00', $targetDate, $targetDate]
            );
        }

        $limit = max(1, min(500, (int)($filters['limit'] ?? 100)));
        $matchedTotal = (int)(clone $query)->count();
        $intentRows = $query->order('id', 'desc')->limit($limit)->select()->toArray();
        $truncated = $matchedTotal > count($intentRows);
        if (empty($intentRows)) {
            $summary = $this->buildExecutionFlowSummary([]);
            return [
                'summary' => $summary,
                'stages' => $this->buildExecutionFlowStages($summary),
                'list' => [],
                'data_status' => self::DATA_OK,
                'data_gaps' => [],
                'matched_total' => 0,
                'returned_count' => 0,
                'truncated' => false,
                'statistics' => [
                    'execution_total_loaded' => true,
                    'task_status_loaded' => true,
                    'evidence_loaded' => true,
                    'roi_loaded' => true,
                ],
            ];
        }

        $intentIds = array_map(static fn(array $row): int => (int)$row['id'], $intentRows);
        $tasksByIntent = [];
        $evidenceByIntent = [];
        $dataGaps = [];
        if ($truncated) {
            $dataGaps[] = [
                'code' => 'operation_execution_flow_truncated',
                'message' => "execution flow returned {$limit} of {$matchedTotal} matched intents",
            ];
        }

        $taskTableLoaded = $this->tableExists('operation_execution_tasks');
        $evidenceTableLoaded = $this->tableExists('operation_execution_evidence');
        if ($taskTableLoaded) {
            $taskRows = Db::name('operation_execution_tasks')
                ->whereIn('intent_id', $intentIds)
                ->whereNull('deleted_at')
                ->order('id', 'desc')
                ->select()
                ->toArray();
            $taskIntentMap = [];
            foreach ($taskRows as $taskRow) {
                $intentId = (int)($taskRow['intent_id'] ?? 0);
                $taskId = (int)($taskRow['id'] ?? 0);
                $tasksByIntent[$intentId][] = $taskRow;
                if ($taskId > 0) {
                    $taskIntentMap[$taskId] = $intentId;
                }
            }

            if (!empty($taskIntentMap)) {
                if ($evidenceTableLoaded) {
                    $evidenceRows = Db::name('operation_execution_evidence')
                        ->whereIn('task_id', array_keys($taskIntentMap))
                        ->whereNull('deleted_at')
                        ->order('id', 'desc')
                        ->select()
                        ->toArray();
                    foreach ($evidenceRows as $evidenceRow) {
                        $taskId = (int)($evidenceRow['task_id'] ?? 0);
                        $intentId = $taskIntentMap[$taskId] ?? 0;
                        if ($intentId > 0) {
                            $evidenceByIntent[$intentId][] = $evidenceRow;
                        }
                    }
                } else {
                    $dataGaps[] = ['code' => 'operation_execution_evidence_missing', 'message' => 'execution evidence table missing'];
                }
            }
        } else {
            $dataGaps[] = ['code' => 'operation_execution_tasks_missing', 'message' => 'execution task table missing'];
        }
        if (!$evidenceTableLoaded
            && !in_array('operation_execution_evidence_missing', array_column($dataGaps, 'code'), true)
        ) {
            $dataGaps[] = ['code' => 'operation_execution_evidence_missing', 'message' => 'execution evidence table missing'];
        }

        $items = [];
        foreach ($intentRows as $intentRow) {
            $intentId = (int)$intentRow['id'];
            $items[] = $this->buildExecutionFlowItem(
                $intentRow,
                $tasksByIntent[$intentId] ?? [],
                $evidenceByIntent[$intentId] ?? []
            );
        }
        $identityGapCount = array_sum(array_map(
            static fn(array $item): int => (int)($item['identity']['gap_count'] ?? 0),
            $items
        ));
        if ($identityGapCount > 0) {
            $dataGaps[] = [
                'code' => 'operation_execution_identity_mismatch_excluded',
                'message' => $identityGapCount . ' task/evidence rows were excluded because parent hotel or tenant identity did not match',
            ];
        }

        $summary = $this->buildExecutionFlowSummary($items);

        return [
            'summary' => $summary,
            'stages' => $this->buildExecutionFlowStages($summary),
            'list' => $items,
            'data_status' => $dataGaps === [] ? self::DATA_OK : 'partial',
            'data_gaps' => $dataGaps,
            'matched_total' => $matchedTotal,
            'returned_count' => count($items),
            'truncated' => $truncated,
            'statistics' => [
                'execution_total_loaded' => true,
                'task_status_loaded' => $taskTableLoaded && !$truncated,
                'evidence_loaded' => $taskTableLoaded && $evidenceTableLoaded && !$truncated,
                'roi_loaded' => $taskTableLoaded && $evidenceTableLoaded && !$truncated,
            ],
        ];
    }

    public function buildExecutionFlowItem(array $intentRow, array $taskRows = [], array $evidenceRows = []): array
    {
        $intent = $this->normalizeExecutionIntentRow($intentRow);
        $tasks = array_map([$this, 'normalizeExecutionTaskRow'], $taskRows);
        $evidence = array_map([$this, 'normalizeExecutionEvidenceRow'], $evidenceRows);

        return $this->executionFlowReadService->buildItem($intent, $tasks, $evidence);
    }

    public function buildExecutionFlowSummary(array $items): array
    {
        return $this->executionFlowReadService->buildSummary($items);
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

    public function buildPriceSuggestionExecutionIntentInput(array $suggestion, array $overrides = []): array
    {
        $this->assertExecutionPayloadHasNoCredentialMaterial([$suggestion, $overrides]);
        if ((int)($suggestion['status'] ?? 0) !== \app\model\PriceSuggestion::STATUS_APPROVED) {
            throw new \InvalidArgumentException('price suggestion must be approved before creating an execution intent');
        }
        $enrichedRows = $this->pricingRecommendationService->enrichSuggestionRows([$suggestion]);
        $suggestion = is_array($enrichedRows[0] ?? null) ? $enrichedRows[0] : [];
        $decisionRecommendation = is_array($suggestion['decision_recommendation'] ?? null)
            ? $suggestion['decision_recommendation']
            : [];
        $decisionQuality = is_array($decisionRecommendation['decision_quality'] ?? null)
            ? $decisionRecommendation['decision_quality']
            : [];
        if (($decisionRecommendation['can_create_execution_intent'] ?? false) !== true
            || ($decisionQuality['contract_version'] ?? '') !== AiDecisionQualityService::CONTRACT_VERSION
            || ($decisionQuality['execution_ready'] ?? false) !== true
        ) {
            $blockedReason = trim((string)($decisionRecommendation['blocked_reason'] ?? ''));
            throw new \InvalidArgumentException($blockedReason !== ''
                ? $blockedReason
                : 'price suggestion has not passed the AI decision quality v2 gate');
        }

        $sourceBusinessDate = $this->normalizeExecutionDate((string)($suggestion['suggestion_date'] ?? date('Y-m-d')));
        $requestedExecutionDate = trim((string)($overrides['execution_date'] ?? ''));
        $executionDate = $this->normalizeExecutionDate(
            $requestedExecutionDate !== '' ? $requestedExecutionDate : date('Y-m-d')
        );
        if ($executionDate < date('Y-m-d')) {
            throw new \InvalidArgumentException('计划执行日期不能早于今天');
        }
        $factors = $this->arrayValue($suggestion['factors'] ?? []);
        $manualReview = $this->latestManualReviewFromFactors($factors);
        $originalSuggestedPrice = (float)($suggestion['suggested_price'] ?? 0);
        $targetPrice = $this->manualApprovedPriceFromReview($manualReview) ?? $originalSuggestedPrice;
        $requestedPlatform = strtolower(trim((string)($overrides['platform'] ?? $overrides['channel'] ?? '')));
        if ($requestedPlatform !== '' && $requestedPlatform !== 'ctrip') {
            throw new \InvalidArgumentException('price suggestion platform must remain bound to ctrip');
        }

        return [
            'source_module' => 'price_suggestion',
            'source_record_id' => (int)($suggestion['id'] ?? 0),
            'hotel_id' => (int)($suggestion['hotel_id'] ?? 0),
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => $executionDate,
            'date_end' => $executionDate,
            'current_value' => [
                'current_price' => (float)($suggestion['current_price'] ?? 0),
                'room_type_id' => (int)($suggestion['room_type_id'] ?? 0),
            ],
            'target_value' => [
                'target_price' => $targetPrice,
                'min_price' => (float)($suggestion['min_price'] ?? 0),
                'max_price' => (float)($suggestion['max_price'] ?? 0),
                'room_type_key' => trim((string)($overrides['room_type_key'] ?? '')),
                'rate_plan_key' => trim((string)($overrides['rate_plan_key'] ?? '')),
                'room_type_id' => (int)($suggestion['room_type_id'] ?? 0),
            ],
            'evidence' => [
                'reason' => (string)($suggestion['reason'] ?? ''),
                'factors' => $factors,
                'competitor_data' => $this->arrayValue($suggestion['competitor_data'] ?? []),
                'original_suggested_price' => $originalSuggestedPrice,
                'approved_price' => $targetPrice,
                'manual_review' => $manualReview === [] ? null : $manualReview,
                'manual_review_storage' => $manualReview === [] ? null : 'price_suggestions.factors.manual_review_versions',
                'decision_recommendation' => $decisionRecommendation,
                'source_business_date' => $sourceBusinessDate,
                'execution_date' => $executionDate,
                'auto_write_ota' => false,
            ],
            'expected_metric' => trim((string)($overrides['expected_metric'] ?? 'orders')),
            'expected_delta' => (float)($overrides['expected_delta'] ?? 0),
            'risk_level' => trim((string)($overrides['risk_level'] ?? 'medium')),
        ];
    }

    public function buildExecutionIntentPayload(array $hotelIds, ?int $hotelId, array $input, int $createdBy): array
    {
        $this->assertExecutionPayloadHasNoCredentialMaterial($input);
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0)));
        $requestedHotelId = (int)($input['hotel_id'] ?? 0);
        if ($requestedHotelId <= 0) {
            $requestedHotelId = (int)($hotelId ?? 0);
        }
        if ($requestedHotelId <= 0 && count($hotelIds) === 1) {
            $requestedHotelId = $hotelIds[0];
        }
        if ($requestedHotelId <= 0 && count($hotelIds) > 1) {
            throw new \InvalidArgumentException('hotel_id is required when multiple hotels are permitted');
        }
        $selectedHotelId = $requestedHotelId;
        if ($selectedHotelId <= 0 || !in_array($selectedHotelId, $hotelIds, true)) {
            throw new \InvalidArgumentException('hotel_id is not permitted');
        }

        $objectType = trim((string)($input['object_type'] ?? ''));
        $targetValue = $this->arrayValue($input['target_value'] ?? []);
        $currentValue = $this->arrayValue($input['current_value'] ?? []);
        $evidence = $this->buildExecutionIntentEvidence($input);
        $effectiveDate = trim((string)($input['effective_date'] ?? $input['date_start'] ?? $input['start_date'] ?? ''));
        if ($objectType === 'price') {
            $this->assertPriceExecutionIntentIsComplete($input, $targetValue, $evidence, $effectiveDate);
        }
        $blockedReasons = $this->executionIntentBlockedReasons($objectType, $input, $targetValue, $evidence);
        if ($objectType === 'price' && $blockedReasons !== []) {
            throw new \InvalidArgumentException(implode('; ', $blockedReasons));
        }
        $status = $objectType === 'price'
            ? 'pending_approval'
            : ($blockedReasons ? 'blocked' : (in_array((string)($input['status'] ?? ''), ['draft', 'pending_approval'], true) ? (string)$input['status'] : 'pending_approval'));
        $dateStart = $effectiveDate !== '' ? $effectiveDate : date('Y-m-d');
        $dateEnd = trim((string)($input['date_end'] ?? $input['end_date'] ?? $dateStart));

        return [
            'source_module' => trim((string)($input['source_module'] ?? 'manual')),
            'source_record_id' => (int)($input['source_record_id'] ?? 0),
            'hotel_id' => $selectedHotelId,
            'platform' => strtolower(trim((string)($input['platform'] ?? ''))),
            'object_type' => $objectType,
            'action_type' => trim((string)($input['action_type'] ?? '')),
            'date_start' => $this->normalizeExecutionDate($dateStart),
            'date_end' => $this->normalizeExecutionDate($dateEnd !== '' ? $dateEnd : $dateStart),
            'current_value' => $currentValue,
            'target_value' => $targetValue,
            'evidence' => $evidence,
            'expected_metric' => trim((string)($input['expected_metric'] ?? $targetValue['target_metric'] ?? '')),
            'expected_delta' => (float)($input['expected_delta'] ?? 0),
            'risk_level' => trim((string)($input['risk_level'] ?? 'medium')),
            'status' => $status,
            'blocked_reason' => implode('; ', $blockedReasons),
            'created_by' => $createdBy,
        ];
    }

    private function assertPriceExecutionIntentIsComplete(array $input, array $targetValue, array $evidence, string $effectiveDate): void
    {
        if (trim((string)($input['platform'] ?? '')) === '') {
            throw new \InvalidArgumentException('platform is required');
        }
        if (trim((string)($input['action_type'] ?? '')) === '') {
            throw new \InvalidArgumentException('action_type is required');
        }
        foreach (['room_type_key', 'rate_plan_key'] as $field) {
            if (trim((string)($targetValue[$field] ?? '')) === '') {
                throw new \InvalidArgumentException($field . ' is required');
            }
        }
        if (!array_key_exists('target_price', $targetValue)
            || !is_numeric($targetValue['target_price'])
            || (float)$targetValue['target_price'] <= 0
        ) {
            throw new \InvalidArgumentException('target_price must be a positive number');
        }
        if ($effectiveDate === '') {
            throw new \InvalidArgumentException('effective_date is required');
        }
        if (!$this->hasMeaningfulExecutionEvidence($evidence)) {
            throw new \InvalidArgumentException('evidence is required');
        }
    }

    private function hasMeaningfulExecutionEvidence(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasMeaningfulExecutionEvidence($item)) {
                    return true;
                }
            }
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== null && $value !== false;
    }

    private function buildExecutionIntentEvidence(array $input): array
    {
        $evidence = $this->arrayValue($input['evidence'] ?? $input['evidence_json'] ?? []);
        foreach ([
            'evidence_refs',
            'data_gaps',
            'source_policy',
            'ai_governance',
            'protected_boundary',
            'action_item_id',
            'action_item_status',
            'diagnosis_summary',
        ] as $field) {
            if (array_key_exists($field, $evidence) || !array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if (is_array($value) ? $value !== [] : trim((string)$value) !== '') {
                $evidence[$field] = $value;
            }
        }

        $decisionRecommendation = is_array($evidence['decision_recommendation'] ?? null)
            ? $evidence['decision_recommendation']
            : [];
        if ($decisionRecommendation !== []) {
            $evidence['decision_quality'] = is_array($decisionRecommendation['decision_quality'] ?? null)
                ? $decisionRecommendation['decision_quality']
                : [];
            $evidence['decision_recommendation_digest'] = $this->decisionRecommendationDigest($decisionRecommendation);
        }

        return $evidence;
    }

    /** @param array<string, mixed> $recommendation */
    private function decisionRecommendationDigest(array $recommendation): string
    {
        $stable = [];
        foreach ([
            'title',
            'action',
            'priority',
            'action_type',
            'object_type',
            'platform',
            'expected_metric',
            'evidence_refs',
            'source_refs',
            'data_basis',
            'expected_effect',
            'risk',
            'decision_quality',
            'trusted_decision',
            'can_create_execution_intent',
        ] as $field) {
            if (array_key_exists($field, $recommendation)) {
                $stable[$field] = $recommendation[$field];
            }
        }

        return hash('sha256', json_encode(
            $this->canonicalizeDecisionValue($stable),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ) ?: '{}');
    }

    private function canonicalizeDecisionValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalizeDecisionValue($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeDecisionValue($item);
        }
        return $value;
    }

    public function buildExecutionTaskUpdate(array $task, array $intent, array $input, int $operatorId): array
    {
        $this->assertExecutionPayloadHasNoCredentialMaterial($input);
        if (($intent['status'] ?? '') !== 'approved') {
            throw new \InvalidArgumentException('intent must be approved before execution');
        }

        $currentStatus = trim((string)($task['status'] ?? ''));
        if (in_array($currentStatus, ['executed', 'failed'], true)) {
            throw new \InvalidArgumentException('terminal execution task cannot transition');
        }

        $status = trim((string)($input['status'] ?? 'executed'));
        if (!in_array($status, ['executing', 'blocked', 'executed', 'failed'], true)) {
            throw new \InvalidArgumentException('execution status is not supported');
        }

        $evidence = $this->arrayValue($input['evidence'] ?? []);
        $isTemporalForecast = strtolower(trim((string)($intent['source_module'] ?? '')))
            === TemporalInsightService::OPERATION_SOURCE_MODULE;
        if ($isTemporalForecast && $this->containsForbiddenTemporalPriceInstruction($input)) {
            throw new \InvalidArgumentException(
                'forecast operation execution must remain manual-only and cannot contain an automatic price instruction'
            );
        }
        if ($status === 'executed' && $isTemporalForecast) {
            $platformResponse = $this->arrayValue($evidence['platform_response'] ?? []);
            $operatorEvidence = $this->arrayValue($platformResponse['operator_execution_evidence'] ?? []);
            $executionMode = strtolower(trim((string)(
                $platformResponse['mode']
                ?? $operatorEvidence['mode']
                ?? ''
            )));
            if ($executionMode !== 'manual_operation_execution'
                || ($platformResponse['automatic_price_write'] ?? null) !== false
            ) {
                throw new \InvalidArgumentException(
                    'forecast operation execution must remain manual-only and cannot contain an automatic price instruction'
                );
            }
            $nextReviewDate = trim((string)(
                $platformResponse['next_review_date']
                ?? $operatorEvidence['next_review_date']
                ?? ''
            ));
            $completedAction = trim((string)(
                $platformResponse['completed_action']
                ?? $operatorEvidence['completed_action']
                ?? ''
            ));
            if ($completedAction === '') {
                throw new \InvalidArgumentException(
                    'forecast operation task requires the completed manual action before execution can be recorded'
                );
            }
            $minimumReviewDate = (new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextReviewDate) !== 1
                || $nextReviewDate < $minimumReviewDate
            ) {
                throw new \InvalidArgumentException(
                    'forecast operation task requires a next-day review date before execution can be recorded'
                );
            }
        }
        if ($currentStatus === 'blocked'
            && in_array($status, ['executed', 'failed'], true)
            && empty($evidence)
        ) {
            throw new \InvalidArgumentException('duplicate execution replay remains blocked until evidence is supplied');
        }
        if (in_array($status, ['executed', 'failed'], true) && empty($evidence)) {
            $requestedStatus = $status;
            $status = 'blocked';
            $defaultBlockedReason = $requestedStatus === 'failed'
                ? 'execution failure evidence missing'
                : 'execution evidence missing';
            $input['blocked_reason'] = trim((string)($input['blocked_reason'] ?? $defaultBlockedReason));
        }
        if ($status === $currentStatus) {
            throw new \InvalidArgumentException('execution task status must transition');
        }

        $now = date('Y-m-d H:i:s');
        $taskUpdate = [
            'status' => $status,
            'operator_id' => $operatorId,
            'blocked_reason' => $status === 'blocked' ? trim((string)($input['blocked_reason'] ?? 'execution blocked')) : '',
            'updated_at' => $now,
        ];

        if (in_array($status, ['executed', 'failed'], true)) {
            $taskUpdate['executed_at'] = $now;
        }
        if (array_key_exists('current_value', $input)) {
            $taskUpdate['current_value'] = $this->arrayValue($input['current_value']);
        }
        if (array_key_exists('target_value', $input)) {
            $taskUpdate['target_value'] = $this->arrayValue($input['target_value']);
        }

        $evidencePayload = null;
        if (!empty($evidence)) {
            $evidencePayload = [
                'task_id' => (int)($task['id'] ?? 0),
                'evidence_type' => trim((string)($input['evidence_type'] ?? $evidence['evidence_type'] ?? 'manual')),
                'before' => $this->arrayValue($evidence['before'] ?? []),
                'after' => $this->arrayValue($evidence['after'] ?? []),
                'attachment_path' => trim((string)($evidence['attachment_path'] ?? '')),
                'platform_response' => $this->buildExecutionEvidencePlatformResponse($evidence),
                'remark' => trim((string)($evidence['remark'] ?? '')),
                'created_by' => $operatorId,
                'created_at' => $now,
            ];
        }

        return ['task' => $taskUpdate, 'evidence' => $evidencePayload];
    }

    private function containsForbiddenTemporalPriceInstruction(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            $normalizedKey = preg_replace('/(?<!^)[A-Z]/', '_$0', (string)$key) ?? (string)$key;
            $normalizedKey = strtolower(str_replace(['-', ' '], '_', $normalizedKey));
            if (in_array($normalizedKey, [
                'target_price',
                'approved_price',
                'suggested_price',
                'execution_price',
            ], true)) {
                return true;
            }
            if (in_array($normalizedKey, [
                'automatic_price_write',
                'auto_price_write',
                'automatic_ota_write',
                'auto_write_ota',
            ], true) && $item !== false) {
                return true;
            }
            if ($this->containsForbiddenTemporalPriceInstruction($item)) {
                return true;
            }
        }

        return false;
    }

    public function createExecutionIntent(
        array $hotelIds,
        ?int $hotelId,
        array $input,
        int $createdBy,
        bool $trustedExpansionSource = false,
        ?string $trustedIdempotencyKey = null,
        bool $trustedReservedSource = false
    ): array {
        $this->ensureExecutionTables();
        $payload = $this->buildExecutionIntentPayload($hotelIds, $hotelId, $input, $createdBy);
        $reservedSources = [
            'ai_daily_report',
            'revenue_research',
            'price_suggestion',
            'ota_diagnosis_saved',
            'ota_diagnosis',
            'strategy_simulation',
            'quant_simulation',
            'daily_workbench_patrol',
            'operation_alert',
            'operating_target',
            'knowledge_sop',
            TemporalInsightService::OPERATION_SOURCE_MODULE,
            OperationOptimizationExecutionBridgeService::SOURCE_MODULE,
        ];
        if (in_array((string)$payload['source_module'], $reservedSources, true) && !$trustedReservedSource) {
            throw new \InvalidArgumentException('reserved execution source must be created from its scoped source endpoint');
        }
        if ($trustedReservedSource && in_array((string)$payload['source_module'], ['strategy_simulation', 'quant_simulation'], true)) {
            $this->assertSimulationIntentSourceIsCurrent($payload);
        }
        $usesExpansionSource = $payload['source_module'] === 'expansion' || $payload['object_type'] === 'expansion';
        if ($usesExpansionSource
            && (!$trustedExpansionSource || $payload['source_module'] !== 'expansion' || $payload['object_type'] !== 'expansion')
        ) {
            throw new \InvalidArgumentException('expansion execution intent must be created from the scoped expansion record endpoint');
        }
        $trustedIdempotencyKey = $this->normalizeTrustedExecutionIntentIdempotencyKey($trustedIdempotencyKey);
        $idempotencyKey = null;
        $usesExpansionIdempotency = false;
        if ($trustedExpansionSource && $payload['source_module'] === 'expansion' && $payload['object_type'] === 'expansion') {
            if ($trustedIdempotencyKey !== null) {
                throw new \InvalidArgumentException('expansion execution intent cannot override its idempotency key');
            }
            if ((int)$payload['source_record_id'] <= 0) {
                throw new \InvalidArgumentException('source_record_id is required for expansion execution intent');
            }
            $usesExpansionIdempotency = true;
            $idempotencyKey = $this->expansionExecutionIntentIdempotencyKey($payload);
            $existingIntent = $this->replayExpansionExecutionIntent($idempotencyKey, $payload, $hotelIds);
            if ($existingIntent !== null) {
                return $existingIntent;
            }
        } elseif ($trustedReservedSource && $payload['source_module'] === 'price_suggestion') {
            if ($trustedIdempotencyKey !== null) {
                throw new \InvalidArgumentException('price suggestion execution intent cannot override its idempotency key');
            }
            if ((int)$payload['source_record_id'] <= 0) {
                throw new \InvalidArgumentException('source_record_id is required for price suggestion execution intent');
            }
            $idempotencyKey = $this->priceSuggestionExecutionIntentIdempotencyKey($payload);
            $existingIntent = $this->replayTrustedExecutionIntent($idempotencyKey, $payload, $hotelIds);
            if ($existingIntent !== null) {
                return $existingIntent;
            }
        } elseif ($trustedReservedSource && $payload['source_module'] === 'knowledge_sop') {
            if ($trustedIdempotencyKey !== null) {
                throw new \InvalidArgumentException('knowledge SOP execution intent cannot override its idempotency key');
            }
            $idempotencyKey = $this->knowledgeSopExecutionIntentIdempotencyKey($payload);
            $existingIntent = $this->replayTrustedExecutionIntent($idempotencyKey, $payload, $hotelIds);
            if ($existingIntent !== null) {
                return $existingIntent;
            }
        } elseif ($trustedIdempotencyKey !== null) {
            $idempotencyKey = $trustedIdempotencyKey;
            $existingIntent = $this->replayTrustedExecutionIntent($idempotencyKey, $payload, $hotelIds);
            if ($existingIntent !== null) {
                return $existingIntent;
            }
        }
        $now = date('Y-m-d H:i:s');

        $insert = [
            'source_module' => $payload['source_module'],
            'source_record_id' => $payload['source_record_id'],
            'hotel_id' => $payload['hotel_id'],
            'platform' => $payload['platform'],
            'object_type' => $payload['object_type'],
            'action_type' => $payload['action_type'],
            'date_start' => $payload['date_start'],
            'date_end' => $payload['date_end'],
            'current_value_json' => json_encode($payload['current_value'], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode($payload['target_value'], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode($payload['evidence'], JSON_UNESCAPED_UNICODE),
            'expected_metric' => $payload['expected_metric'],
            'expected_delta' => $payload['expected_delta'],
            'risk_level' => $payload['risk_level'],
            'blocked_reason' => $payload['blocked_reason'],
            'status' => $payload['status'],
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($idempotencyKey !== null) {
            $insert['idempotency_key'] = $idempotencyKey;
        }

        try {
            $id = (int)Db::name('operation_execution_intents')->insertGetId(
                $this->withHotelTenantId($insert, 'operation_execution_intents', (int)$payload['hotel_id'])
            );
        } catch (Throwable $e) {
            if ($idempotencyKey !== null) {
                $existingIntent = $usesExpansionIdempotency
                    ? $this->replayExpansionExecutionIntent($idempotencyKey, $payload, $hotelIds)
                    : $this->replayTrustedExecutionIntent($idempotencyKey, $payload, $hotelIds);
                if ($existingIntent !== null) {
                    return $existingIntent;
                }
            }
            throw $e;
        }

        return $this->executionIntentDetail($id, $hotelIds);
    }

    public function syncDailyWorkbenchPatrolAction(array $hotelIds, array $input, int $userId): array
    {
        $this->assertExecutionPayloadHasNoCredentialMaterial($input);
        $this->ensureExecutionTables();

        $hotelIds = array_values(array_unique(array_map('intval', $hotelIds)));
        $hotelId = (int)($input['hotel_id'] ?? 0);
        if ($hotelId <= 0 || !in_array($hotelId, $hotelIds, true)) {
            throw new \InvalidArgumentException('hotel_id is not permitted');
        }

        $runId = trim((string)($input['run_id'] ?? ''));
        $actionCode = trim((string)($input['action_code'] ?? ''));
        $questionKey = trim((string)($input['question_key'] ?? ''));
        $status = strtolower(trim((string)($input['status'] ?? '')));
        if ($runId === '' || ($actionCode === '' && $questionKey === '')) {
            throw new \InvalidArgumentException('patrol run_id and action identity are required');
        }
        if (!in_array($status, ['pending', 'in_progress', 'done', 'skipped', 'review_needed'], true)) {
            throw new \InvalidArgumentException('patrol action status is not supported');
        }

        $sourceRecordId = $this->dailyWorkbenchPatrolSourceRecordId($runId, $hotelId, $actionCode, $questionKey);
        $intent = $this->findDailyWorkbenchPatrolIntent($hotelId, $sourceRecordId);
        if ($intent === null) {
            $intent = $this->createExecutionIntent(
                $hotelIds,
                $hotelId,
                $this->buildDailyWorkbenchPatrolExecutionIntentInput($input, $sourceRecordId),
                $userId,
                false,
                null,
                true
            );
        } else {
            $intent = $this->executionIntentDetail((int)$intent['id'], $hotelIds);
        }

        $task = $this->latestExecutionTask(is_array($intent['tasks'] ?? null) ? $intent['tasks'] : []);
        $taskId = (int)($task['id'] ?? 0);
        $taskStatus = (string)($task['status'] ?? '');
        $executionEvidenceRows = [];
        if ($taskId > 0 && $taskStatus === 'executed') {
            $executionEvidenceRows = Db::name('operation_execution_evidence')
                ->where('task_id', $taskId)
                ->whereNull('deleted_at')
                ->order('id', 'desc')
                ->select()
                ->toArray();
        }
        $normalizedExecutionEvidence = array_map([$this, 'normalizeExecutionEvidenceRow'], $executionEvidenceRows);
        $executionEvidenceTruth = $this->buildExecutionEvidenceTruth($intent, $task, $normalizedExecutionEvidence);
        $executionOutcomeTruth = $this->buildExecutionOutcomeTruth($intent, $task, $normalizedExecutionEvidence);
        $executionTruthContext = $this->buildExecutionTruthContext(
            $intent,
            $task,
            $executionEvidenceTruth,
            (string)($task['result_status'] ?? 'observing'),
            $executionOutcomeTruth
        );
        $executionEvidenceCount = count($executionEvidenceRows);
        $executionClaimed = $taskStatus === 'executed'
            && ($executionTruthContext['status'] ?? '') === 'verified';
        $syncStatus = 'synced_intent';
        $requiredNextAction = '';
        if ($status === 'done' && $executionClaimed) {
            $syncStatus = 'synced_executed_source_verified';
        } elseif ($status === 'done') {
            $syncStatus = 'synced_pending_execution_evidence';
            $requiredNextAction = (string)($intent['status'] ?? '') === 'approved'
                ? 'execute_task_and_attach_source_verified_business_metric_readback'
                : 'approve_intent_then_execute_and_attach_source_verified_business_metric_readback';
        }

        return [
            'status' => $syncStatus,
            'workbench_status' => $status,
            'source_module' => 'daily_workbench_patrol',
            'source_record_id' => $sourceRecordId,
            'intent_id' => (int)($intent['id'] ?? 0),
            'intent_status' => (string)($intent['status'] ?? ''),
            'task_id' => $taskId,
            'task_status' => $taskStatus,
            'execution_claimed' => $executionClaimed,
            'execution_evidence_count' => $executionEvidenceCount,
            'evidence_truth' => $executionEvidenceTruth,
            'outcome_truth' => $executionOutcomeTruth,
            'truth_context' => $executionTruthContext,
            'required_next_action' => $requiredNextAction,
            'metric_scope' => 'ota_channel',
            'source_policy' => 'workbench_status_only_no_automatic_approval_or_execution',
        ];
    }

    public function executionIntents(array $hotelIds, ?int $hotelId, array $filters = []): array
    {
        if (!$this->tableExists('operation_execution_intents')) {
            return ['list' => [], 'data_status' => self::DATA_PENDING];
        }

        $query = Db::name('operation_execution_intents')->whereNull('deleted_at');
        if ($hotelId !== null && $hotelId > 0) {
            $query->where('hotel_id', $hotelId);
        } elseif (!empty($hotelIds)) {
            $query->whereIn('hotel_id', $hotelIds);
        }
        foreach (['platform', 'object_type', 'status'] as $field) {
            $value = trim((string)($filters[$field] ?? ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        $rows = $query->order('id', 'desc')->limit(100)->select()->toArray();
        return [
            'list' => array_map([$this, 'normalizeExecutionIntentRow'], $rows),
            'data_status' => self::DATA_OK,
        ];
    }

    public function readExecutionIntent(int $id, array $hotelIds): array
    {
        $this->ensureExecutionTables();
        return $this->executionIntentDetail($id, $hotelIds);
    }

    public function readExecutionIntentByIdempotencyKey(string $idempotencyKey, array $hotelIds): ?array
    {
        $this->ensureExecutionTables();
        $idempotencyKey = $this->normalizeTrustedExecutionIntentIdempotencyKey($idempotencyKey) ?? '';
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds))));
        if ($idempotencyKey === '' || $hotelIds === []) {
            return null;
        }

        try {
            $row = Db::name('operation_execution_intents')
                ->where('idempotency_key', $idempotencyKey)
                ->whereIn('hotel_id', $hotelIds)
                ->whereNull('deleted_at')
                ->field('id')
                ->find();
        } catch (Throwable $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'unknown column')
                || str_contains($message, 'no such column')
                || str_contains($message, 'undefined column')
            ) {
                throw new \RuntimeException(
                    'operation_execution_intents.idempotency_key is unavailable; run the 20260716 execution-intent idempotency migration first',
                    500,
                    $e
                );
            }
            throw $e;
        }

        return is_array($row)
            ? $this->executionIntentDetail((int)($row['id'] ?? 0), $hotelIds)
            : null;
    }

    /** @return array{intent:array<string,mixed>,attempt:int,idempotency_key:string}|null */
    public function readLatestOtaDiagnosisExecutionIntentAttempt(string $baseKey, array $hotelIds): ?array
    {
        $this->ensureExecutionTables();
        $baseKey = $this->normalizeOtaDiagnosisExecutionIntentBaseKey($baseKey);
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds))));
        if ($hotelIds === []) {
            return null;
        }

        try {
            $rows = Db::name('operation_execution_intents')
                ->where('idempotency_key', 'like', $baseKey . ':attempt:%')
                ->whereIn('hotel_id', $hotelIds)
                ->whereNull('deleted_at')
                ->field('id,idempotency_key')
                ->order('id', 'desc')
                ->limit(100)
                ->select()
                ->toArray();
        } catch (Throwable $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'unknown column')
                || str_contains($message, 'no such column')
                || str_contains($message, 'undefined column')
            ) {
                throw new \RuntimeException(
                    'operation_execution_intents.idempotency_key is unavailable; run the 20260716 execution-intent idempotency migration first',
                    500,
                    $e
                );
            }
            throw $e;
        }

        $selected = null;
        $selectedAttempt = 0;
        $pattern = '/^' . preg_quote($baseKey, '/') . ':attempt:([1-9][0-9]*)$/D';
        foreach ($rows as $row) {
            $key = trim((string)($row['idempotency_key'] ?? ''));
            if (preg_match($pattern, $key, $matches) !== 1) {
                continue;
            }
            $attempt = (int)$matches[1];
            if ($attempt > $selectedAttempt) {
                $selected = $row;
                $selectedAttempt = $attempt;
            }
        }
        if (!is_array($selected) || $selectedAttempt <= 0) {
            return null;
        }

        return [
            'intent' => $this->executionIntentDetail((int)($selected['id'] ?? 0), $hotelIds),
            'attempt' => $selectedAttempt,
            'idempotency_key' => (string)$selected['idempotency_key'],
        ];
    }

    /** @param array<string, mixed> $schedule */
    public function reschedulePendingExecutionIntent(
        int $id,
        array $hotelIds,
        array $schedule,
        int $updatedBy
    ): array {
        $this->ensureExecutionTables();
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds))));
        if ($id <= 0 || $hotelIds === [] || $updatedBy <= 0) {
            throw new \InvalidArgumentException('execution intent reschedule scope is invalid');
        }
        $schedule = $this->normalizePendingExecutionIntentSchedule($schedule);
        $this->assertExecutionPayloadHasNoCredentialMaterial($schedule);
        $now = date('Y-m-d H:i:s');

        Db::transaction(function () use ($id, $hotelIds, $schedule, $updatedBy, $now): void {
            $row = Db::name('operation_execution_intents')
                ->where('id', $id)
                ->whereIn('hotel_id', $hotelIds)
                ->whereNull('deleted_at')
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                throw new \RuntimeException('execution intent not found');
            }
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if (!in_array($status, ['draft', 'pending_approval'], true)) {
                throw new \InvalidArgumentException('only draft or pending_approval execution intents can be rescheduled');
            }
            $taskCount = (int)Db::name('operation_execution_tasks')
                ->where('intent_id', $id)
                ->where('hotel_id', (int)$row['hotel_id'])
                ->whereNull('deleted_at')
                ->count();
            if ($taskCount > 0) {
                throw new \InvalidArgumentException('execution intent already has a task and cannot be rescheduled');
            }

            $targetValue = $this->decodeJson((string)($row['target_value_json'] ?? ''));
            $evidence = $this->decodeJson((string)($row['evidence_json'] ?? ''));
            foreach (['assignee_id', 'due_at', 'review_at'] as $field) {
                $targetValue[$field] = $schedule[$field];
            }
            $targetValue['workflow_schedule'] = $schedule;
            $evidence['workflow_schedule'] = $schedule;
            $evidence['schedule_updated_by'] = $updatedBy;
            $evidence['schedule_updated_at'] = $now;
            $affected = (int)Db::name('operation_execution_intents')
                ->where('id', $id)
                ->where('hotel_id', (int)$row['hotel_id'])
                ->where('status', $status)
                ->whereNull('deleted_at')
                ->update([
                    'target_value_json' => json_encode($targetValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ]);
            if ($affected !== 1) {
                throw new \InvalidArgumentException('execution intent state changed; refresh before rescheduling');
            }
        });

        return $this->executionIntentDetail($id, $hotelIds);
    }

    public function readExecutionTask(int $id, array $hotelIds): array
    {
        $this->ensureExecutionTables();
        return $this->executionTaskDetail($id, $hotelIds);
    }

    public function approveExecutionIntent(int $id, bool $approved, string $remark, int $userId, array $hotelIds): array
    {
        $this->assertExecutionPayloadHasNoCredentialMaterial($remark);
        $this->ensureExecutionTables();
        $now = date('Y-m-d H:i:s');
        $status = $approved ? 'approved' : 'rejected';
        Db::transaction(function () use (
            $id,
            $status,
            $userId,
            $now,
            $remark,
            $approved,
            $hotelIds
        ): void {
            $intent = $this->executionIntentRow($id, $hotelIds, true);
            if (!$intent) {
                throw new \RuntimeException('execution intent not found');
            }
            if (($intent['status'] ?? '') === 'blocked') {
                throw new \InvalidArgumentException('blocked execution intent cannot be approved');
            }
            if (($intent['status'] ?? '') !== 'pending_approval') {
                throw new \InvalidArgumentException('execution intent must be pending_approval before review');
            }
            if ($approved) {
                $this->assertExecutionPayloadHasNoCredentialMaterial([
                    $this->decodeJson((string)($intent['current_value_json'] ?? '')),
                    $this->decodeJson((string)($intent['target_value_json'] ?? '')),
                    $this->decodeJson((string)($intent['evidence_json'] ?? '')),
                ]);
                $this->assertAiDecisionIntentReadyForApproval($this->normalizeExecutionIntentRow($intent));
            }

            $affected = (int)Db::name('operation_execution_intents')
                ->where('id', $id)
                ->where('hotel_id', (int)$intent['hotel_id'])
                ->where('status', 'pending_approval')
                ->whereNull('deleted_at')
                ->update([
                'status' => $status,
                'approved_by' => $userId,
                'approved_at' => $now,
                'review_remark' => $remark,
                'updated_at' => $now,
            ]);
            if ($affected !== 1) {
                throw new \InvalidArgumentException('execution intent state changed; refresh before review');
            }

            if ($approved) {
                $taskExists = (int)Db::name('operation_execution_tasks')
                    ->where('intent_id', $id)
                    ->where('hotel_id', (int)$intent['hotel_id'])
                    ->whereNull('deleted_at')
                    ->count();
                if ($taskExists === 0) {
                    Db::name('operation_execution_tasks')->insert($this->withHotelTenantId([
                        'intent_id' => $id,
                        'hotel_id' => (int)$intent['hotel_id'],
                        'execution_mode' => 'manual',
                        'target_value_json' => (string)($intent['target_value_json'] ?? '{}'),
                        'current_value_json' => (string)($intent['current_value_json'] ?? '{}'),
                        'status' => 'pending_execute',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], 'operation_execution_tasks', (int)$intent['hotel_id']));
                }
            }
        });

        return $this->executionIntentDetail($id, $hotelIds);
    }

    /** @param array<string, mixed> $intent */
    private function assertAiDecisionIntentReadyForApproval(array $intent): void
    {
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        if ($sourceModule === 'knowledge_sop') {
            (new KnowledgeSopExecutionProvenanceService())->assertIntentCurrent($intent, true);
            return;
        }
        if ($sourceModule === 'ota_diagnosis') {
            $this->assertPublicPageDiagnosisIntentReadyForApproval($intent);
            return;
        }
        if (in_array($sourceModule, ['strategy_simulation', 'quant_simulation'], true)) {
            $this->assertSimulationIntentSourceIsCurrent($intent);
            return;
        }
        if ($sourceModule === 'operating_target') {
            $this->assertOperatingTargetIntentSourceIsCurrent($intent);
            return;
        }
        if ($sourceModule === TemporalInsightService::OPERATION_SOURCE_MODULE) {
            (new TemporalInsightService())->assertOperationRecommendationIntentCurrent($intent);
            return;
        }
        if (!in_array($sourceModule, [
            'ai_daily_report',
            'revenue_research',
            'price_suggestion',
            'ota_diagnosis_saved',
        ], true)) {
            return;
        }

        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $recommendation = is_array($evidence['decision_recommendation'] ?? null)
            ? $evidence['decision_recommendation']
            : [];
        $decisionQuality = is_array($recommendation['decision_quality'] ?? null)
            ? $recommendation['decision_quality']
            : [];
        $storedDigest = strtolower(trim((string)($evidence['decision_recommendation_digest'] ?? '')));
        if (($recommendation['can_create_execution_intent'] ?? false) !== true
            || ($decisionQuality['contract_version'] ?? '') !== AiDecisionQualityService::CONTRACT_VERSION
            || ($decisionQuality['execution_ready'] ?? false) !== true
            || preg_match('/^[a-f0-9]{64}$/D', $storedDigest) !== 1
            || !hash_equals($storedDigest, $this->decisionRecommendationDigest($recommendation))
        ) {
            throw new \InvalidArgumentException('AI decision quality v2 provenance is required before approval');
        }

        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
        if ($hotelId <= 0 || $sourceRecordId <= 0) {
            throw new \InvalidArgumentException('AI decision source identity is required before approval');
        }

        if ($sourceModule === 'ota_diagnosis_saved') {
            if (!$this->hasVerifiedOtaDiagnosisProvenance($intent)) {
                throw new \InvalidArgumentException('saved OTA diagnosis provenance is no longer valid');
            }
            return;
        }

        if ($sourceModule === 'price_suggestion') {
            $suggestion = \app\model\PriceSuggestion::where('id', $sourceRecordId)
                ->where('hotel_id', $hotelId)
                ->find();
            if (!$suggestion || (int)$suggestion->status !== \app\model\PriceSuggestion::STATUS_APPROVED) {
                throw new \InvalidArgumentException('approved price suggestion source is no longer valid');
            }
            $rows = $this->pricingRecommendationService->enrichSuggestionRows([$suggestion->toArray()]);
            $currentRecommendation = is_array($rows[0]['decision_recommendation'] ?? null)
                ? $rows[0]['decision_recommendation']
                : [];
            if ($currentRecommendation === []
                || !hash_equals($storedDigest, $this->decisionRecommendationDigest($currentRecommendation))
            ) {
                throw new \InvalidArgumentException('price suggestion decision provenance changed; create a new execution intent');
            }
            return;
        }

        if ($sourceModule === 'ai_daily_report') {
            $actionIndex = filter_var($evidence['action_index'] ?? null, FILTER_VALIDATE_INT);
            $report = Db::name('ai_daily_reports')
                ->where('id', $sourceRecordId)
                ->where('hotel_id', $hotelId)
                ->whereNull('deleted_at')
                ->find();
            if ($actionIndex === false || $actionIndex < 0 || !is_array($report)) {
                throw new \InvalidArgumentException('AI daily report source is no longer valid');
            }
            $reports = (new AiDailyReportService())->enrichReportRows([$report], [$hotelId], $hotelId);
            $actions = is_array($reports[0]['recommended_actions'] ?? null)
                ? $reports[0]['recommended_actions']
                : [];
            $currentRecommendation = is_array($actions[$actionIndex] ?? null) ? $actions[$actionIndex] : [];
            if ($currentRecommendation === []
                || !hash_equals($storedDigest, $this->decisionRecommendationDigest($currentRecommendation))
            ) {
                throw new \InvalidArgumentException('AI daily report decision provenance changed; create a new execution intent');
            }
            return;
        }

        if (($evidence['execution_ready'] ?? false) !== true
            || ($evidence['research_readiness_stage'] ?? '') !== 'research_ready_for_execution'
            || ($evidence['metric_scope'] ?? '') !== 'ota_channel'
        ) {
            throw new \InvalidArgumentException('revenue research provenance is no longer execution ready');
        }
    }

    /** @param array<string, mixed> $intent */
    private function assertOperatingTargetIntentSourceIsCurrent(array $intent): void
    {
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
        $targetDate = trim((string)($intent['date_start'] ?? ''));
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $storedDigest = strtolower(trim((string)($evidence['operating_target_source_digest'] ?? '')));
        if ($hotelId <= 0
            || $sourceRecordId <= 0
            || $targetDate === ''
            || ($evidence['operating_target_provenance_contract'] ?? '')
                !== OperatingTargetExecutionProvenanceService::CONTRACT_VERSION
            || preg_match('/^[a-f0-9]{64}$/D', $storedDigest) !== 1
        ) {
            throw new \InvalidArgumentException(
                'operating target execution provenance is required before approval'
            );
        }

        $tenantId = $this->tenantIdForHotel($hotelId);
        $sourceRow = Db::name('operating_target_daily_records')
            ->where('id', $sourceRecordId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('target_date', $targetDate)
            ->lock(true)
            ->find();
        if (!is_array($sourceRow)) {
            throw new \InvalidArgumentException(
                'operating target source is missing; create a new execution intent'
            );
        }
        $this->afterOperatingTargetSourceLockedForApproval($intent, $sourceRow);

        $current = (new OperatingTargetService())->current(
            $tenantId,
            $hotelId,
            $targetDate
        );
        $record = is_array($current['record'] ?? null) ? $current['record'] : null;
        if ($record === null || (int)($record['id'] ?? 0) !== $sourceRecordId) {
            throw new \InvalidArgumentException(
                'operating target source is missing; create a new execution intent'
            );
        }
        $currentDigest = (new OperatingTargetExecutionProvenanceService())->digest($record);
        if (!hash_equals($storedDigest, $currentDigest)) {
            throw new \InvalidArgumentException(
                'operating target source changed; create a new execution intent'
            );
        }
        $facts = is_array($record['facts'] ?? null) ? $record['facts'] : [];
        $calculation = is_array($record['calculation'] ?? null) ? $record['calculation'] : [];
        if (!in_array((string)($facts['quality_status'] ?? ''), ['verified', 'manual_confirmed'], true)
            || (string)($calculation['status'] ?? '') === 'blocked'
        ) {
            throw new \InvalidArgumentException(
                'operating target facts are no longer actionable'
            );
        }
    }

    /**
     * Transaction-bound extension point for lock-boundary verification.
     *
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $sourceRow
     */
    protected function afterOperatingTargetSourceLockedForApproval(array $intent, array $sourceRow): void
    {
    }

    /** @param array<string, mixed> $intent */
    private function assertSimulationIntentSourceIsCurrent(array $intent): void
    {
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $table = match ($sourceModule) {
            'strategy_simulation' => 'strategy_simulation_records',
            'quant_simulation' => 'quant_simulation_records',
            default => '',
        };
        if ($table === '' || $sourceRecordId <= 0 || $hotelId <= 0 || !$this->tableExists($table)) {
            throw new \InvalidArgumentException('simulation source identity is no longer valid');
        }

        $row = Db::name($table)
            ->where('id', $sourceRecordId)
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($row)
            || (int)($row['tenant_id'] ?? 0) <= 0
            || (int)$row['tenant_id'] !== $this->tenantIdForHotel($hotelId)
        ) {
            throw new \InvalidArgumentException('simulation source record is missing or outside the hotel tenant scope');
        }

        $record = $sourceModule === 'strategy_simulation'
            ? $this->strategySimulationRecordForExecution($row)
            : $this->quantSimulationRecordForExecution($row);
        $readinessService = new SimulationExecutionReadinessService();
        $currentInput = $sourceModule === 'strategy_simulation'
            ? $readinessService->buildStrategyExecutionIntentInput($record, [
                'hotel_id' => $hotelId,
                'date_start' => (string)($intent['date_start'] ?? ''),
                'date_end' => (string)($intent['date_end'] ?? ''),
            ])
            : $readinessService->buildQuantExecutionIntentInput($record, [
                'hotel_id' => $hotelId,
                'date_start' => (string)($intent['date_start'] ?? ''),
                'date_end' => (string)($intent['date_end'] ?? ''),
            ]);
        $storedEvidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $currentEvidence = is_array($currentInput['evidence'] ?? null) ? $currentInput['evidence'] : [];
        $storedPayloadDigest = strtolower(trim((string)($storedEvidence['simulation_payload_digest'] ?? '')));
        $storedSourceDigest = strtolower(trim((string)($storedEvidence['source_record_digest'] ?? '')));
        $currentPayloadDigest = strtolower(trim((string)($currentEvidence['simulation_payload_digest'] ?? '')));
        $currentSourceDigest = strtolower(trim((string)($currentEvidence['source_record_digest'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $storedPayloadDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $storedSourceDigest) !== 1
            || !hash_equals($storedSourceDigest, $currentSourceDigest)
            || !hash_equals($storedPayloadDigest, $currentPayloadDigest)
            || !hash_equals($storedPayloadDigest, $readinessService->simulationPayloadDigest($intent))
            || !in_array((string)($currentEvidence['readiness_stage'] ?? ''), ['review_ready', 'approved_pending_execution', 'execution_ready'], true)
            || !empty($currentEvidence['data_gaps'])
        ) {
            throw new \InvalidArgumentException('simulation source or readiness changed; create a new execution intent');
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function strategySimulationRecordForExecution(array $row): array
    {
        $scores = $this->decodeJson((string)($row['score_json'] ?? ''));

        return [
            'id' => (int)($row['id'] ?? 0),
            'record_id' => (int)($row['id'] ?? 0),
            'project_name' => (string)($row['project_name'] ?? ''),
            'total_score' => (int)($scores['total_score'] ?? 0),
            'input' => $this->decodeJson((string)($row['input_json'] ?? '')),
            'scores' => is_array($scores['items'] ?? null) ? $scores['items'] : $scores,
            'recommendation' => $this->decodeJson((string)($row['recommendation_json'] ?? '')),
            'risk' => $this->decodeJson((string)($row['risk_json'] ?? '')),
            'data_snapshot' => $this->decodeJson((string)($row['data_snapshot_json'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function quantSimulationRecordForExecution(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'record_id' => (int)($row['id'] ?? 0),
            'project_name' => (string)($row['project_name'] ?? ''),
            'monthly_net_cashflow' => (float)($row['monthly_net_cashflow'] ?? 0),
            'payback_months' => $row['payback_months'] ?? null,
            'risk_level' => (string)($row['risk_level'] ?? ''),
            'input' => $this->decodeJson((string)($row['input_json'] ?? '')),
            'result' => $this->decodeJson((string)($row['result_json'] ?? '')),
            'scenarios' => $this->decodeJson((string)($row['scenarios_json'] ?? '')),
            'risk_hints' => $this->decodeJson((string)($row['risk_hints_json'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $intent */
    private function assertPublicPageDiagnosisIntentReadyForApproval(array $intent): void
    {
        if (!$this->hasVerifiedPublicPageDiagnosisProvenance($intent, ['intent_id' => (int)($intent['id'] ?? 0)])) {
            throw new \InvalidArgumentException('public-page diagnosis provenance is invalid');
        }

        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $platform = strtolower(trim((string)($intent['platform'] ?? '')));
        $businessDate = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
        try {
            $profiles = match ($platform) {
                'ctrip' => (new CtripPublicHotelProfileService())->listDiagnosisProfiles($hotelId, $businessDate),
                'meituan' => (new MeituanPublicPageEvidenceService())->listDiagnosisProfiles($hotelId, $businessDate),
                default => [],
            };
            $diagnosisService = new OtaPublicPageDiagnosisService();
            $diagnosis = $diagnosisService->build($hotelId, $platform, $businessDate, $profiles);
            $timezone = new \DateTimeZone('Asia/Shanghai');
            $today = new \DateTimeImmutable('today', $timezone);
            $currentDraft = $diagnosisService->buildExecutionIntentDraft($diagnosis, [
                'assignee_id' => max(1, (int)($intent['created_by'] ?? 0)),
                'due_at' => $today->modify('+1 day')->setTime(18, 0)->format('Y-m-d H:i:s'),
                'review_at' => $today->modify('+2 days')->setTime(10, 0)->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('public-page diagnosis source cannot be read back for approval', 0, $exception);
        }

        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $currentInput = is_array($currentDraft['input'] ?? null) ? $currentDraft['input'] : [];
        $currentEvidence = is_array($currentInput['evidence'] ?? null) ? $currentInput['evidence'] : [];
        if ((int)($currentDraft['source_record_id'] ?? 0) !== (int)($intent['source_record_id'] ?? 0)
            || (string)($currentInput['action_type'] ?? '') !== (string)($intent['action_type'] ?? '')
            || (string)($currentInput['expected_metric'] ?? '') !== (string)($intent['expected_metric'] ?? '')
            || !hash_equals(
                strtolower(trim((string)($evidence['task_identity_fingerprint'] ?? ''))),
                strtolower(trim((string)($currentEvidence['task_identity_fingerprint'] ?? '')))
            )
        ) {
            throw new \InvalidArgumentException('public-page diagnosis source changed; create a new execution intent');
        }
    }

    public function executeExecutionTask(int $taskId, array $hotelIds, array $input, int $operatorId): array
    {
        $this->ensureExecutionTables();
        $task = $this->executionTaskRow($taskId, $hotelIds);
        if (!$task) {
            throw new \RuntimeException('execution task not found');
        }

        $intent = $this->executionIntentRow((int)$task['intent_id'], $hotelIds);
        if (!$intent) {
            throw new \RuntimeException('execution intent not found');
        }
        $this->assertExecutionTaskIntentIdentity($task, $intent);
        $normalizedIntent = $this->normalizeExecutionIntentRow($intent);
        $this->assertExecutionTaskAssignee($intent, $operatorId);
        $this->assertExecutionPayloadHasNoCredentialMaterial([
            $this->decodeJson((string)($task['current_value_json'] ?? '')),
            $this->decodeJson((string)($task['target_value_json'] ?? '')),
            $this->decodeJson((string)($intent['evidence_json'] ?? '')),
        ]);

        $built = $this->buildExecutionTaskUpdate($task, $intent, $input, $operatorId);
        $taskUpdate = $built['task'];
        $dbUpdate = $taskUpdate;
        foreach (['current_value', 'target_value'] as $jsonField) {
            if (array_key_exists($jsonField, $dbUpdate)) {
                $dbUpdate[$jsonField . '_json'] = json_encode($dbUpdate[$jsonField], JSON_UNESCAPED_UNICODE);
                unset($dbUpdate[$jsonField]);
            }
        }

        $expectedTaskStatus = (string)($task['status'] ?? '');
        Db::transaction(function () use (
            $taskId,
            $dbUpdate,
            $built,
            $taskUpdate,
            $task,
            $intent,
            $normalizedIntent,
            $expectedTaskStatus
        ): void {
            if (strtolower(trim((string)($normalizedIntent['source_module'] ?? ''))) === 'knowledge_sop'
                && in_array((string)($taskUpdate['status'] ?? ''), ['executing', 'executed'], true)
            ) {
                (new KnowledgeSopExecutionProvenanceService())->assertIntentCurrent($normalizedIntent, true);
            }
            $affected = (int)Db::name('operation_execution_tasks')
                ->where('id', $taskId)
                ->where('hotel_id', (int)$task['hotel_id'])
                ->where('status', $expectedTaskStatus)
                ->whereNull('deleted_at')
                ->update($dbUpdate);
            if ($affected !== 1) {
                throw new \InvalidArgumentException('execution task state changed; refresh before execution');
            }
            if ($built['evidence'] !== null) {
                $this->insertExecutionEvidence($built['evidence']);
            }

            if (($taskUpdate['status'] ?? '') === 'executed'
                && empty($task['action_track_id'])
                && $this->tableExists('operation_action_tracks')
            ) {
                $actionTrackId = $this->createActionTrackForExecution($intent, $taskId);
                Db::name('operation_execution_tasks')->where('id', $taskId)->update(['action_track_id' => $actionTrackId]);
            }
        });

        return $this->executionTaskDetail($taskId, $hotelIds);
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $intent */
    private function assertExecutionTaskIntentIdentity(array $task, array $intent): void
    {
        if (!$this->executionFlowReadService->taskMatchesIntent($intent, $task)) {
            throw new \InvalidArgumentException(
                'execution task identity does not match its hotel, tenant, or parent intent'
            );
        }
    }

    /** @param array<string,mixed> $intent */
    private function assertExecutionTaskAssignee(array $intent, int $operatorId): void
    {
        $target = $this->decodeJson((string)($intent['target_value_json'] ?? ''));
        $schedule = $this->arrayValue($target['workflow_schedule'] ?? []);
        $assigneeId = (int)($schedule['assignee_id'] ?? $target['assignee_id'] ?? 0);
        if ($assigneeId > 0 && $assigneeId !== $operatorId) {
            throw new \InvalidArgumentException('execution task can only be executed by its assignee');
        }
    }

    public function addExecutionEvidence(int $taskId, array $hotelIds, array $input, int $userId): array
    {
        $this->assertExecutionPayloadHasNoCredentialMaterial($input);
        $this->ensureExecutionTables();
        $task = $this->executionTaskRow($taskId, $hotelIds);
        if (!$task) {
            throw new \RuntimeException('execution task not found');
        }
        $intent = $this->executionIntentRow((int)($task['intent_id'] ?? 0), $hotelIds);
        if ($intent === null) {
            throw new \RuntimeException('execution intent not found');
        }
        $this->assertExecutionTaskIntentIdentity($task, $intent);
        $evidence = $this->arrayValue($input['evidence'] ?? $input);
        if (empty($evidence)) {
            throw new \InvalidArgumentException('execution evidence is required');
        }
        $evidenceType = strtolower(trim((string)($input['evidence_type'] ?? $evidence['evidence_type'] ?? 'manual')));
        $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
        $isFailedTaskCompensation = $evidenceType === 'compensation_receipt' && $taskStatus === 'failed';
        if ($taskStatus !== 'executed' && !$isFailedTaskCompensation) {
            throw new \InvalidArgumentException('execution task must be executed before evidence can be added');
        }

        $payload = [
            'task_id' => $taskId,
            'evidence_type' => $evidenceType,
            'before' => $this->arrayValue($evidence['before'] ?? []),
            'after' => $this->arrayValue($evidence['after'] ?? []),
            'attachment_path' => trim((string)($evidence['attachment_path'] ?? '')),
            'platform_response' => $this->buildExecutionEvidencePlatformResponse($evidence),
            'remark' => trim((string)($evidence['remark'] ?? '')),
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if ($payload['evidence_type'] === 'compensation_receipt') {
            $this->assertCompensationReceiptIsCurrentAndComplete($task, $payload['platform_response']);
        }
        $this->insertExecutionEvidence($payload);

        return $this->executionTaskDetail($taskId, $hotelIds);
    }

    /** @param array<string, mixed> $task @param array<string, mixed> $receipt */
    private function assertCompensationReceiptIsCurrentAndComplete(array $task, array $receipt): void
    {
        foreach (['partial', 'applied', 'unapplied', 'affected_scope', 'compensation_status', 'manual_required', 'event_at'] as $field) {
            if (!array_key_exists($field, $receipt)) {
                throw new \InvalidArgumentException('compensation receipt missing required field: ' . $field);
            }
        }

        if ($receipt['partial'] !== true
            || !is_array($receipt['applied'])
            || $receipt['applied'] === []
            || !is_array($receipt['unapplied'])
            || $receipt['unapplied'] === []
            || !is_array($receipt['affected_scope'])
            || !is_bool($receipt['manual_required'])
        ) {
            throw new \InvalidArgumentException('compensation receipt is incomplete');
        }

        $scope = $receipt['affected_scope'];
        foreach (['platform', 'hotel_id', 'business_date'] as $field) {
            if (!array_key_exists($field, $scope) || trim((string)$scope[$field]) === '') {
                throw new \InvalidArgumentException('compensation receipt affected_scope is incomplete');
            }
        }
        if ((int)$scope['hotel_id'] !== (int)($task['hotel_id'] ?? 0)) {
            throw new \InvalidArgumentException('compensation receipt hotel_id is not permitted');
        }
        if (!in_array((string)$receipt['compensation_status'], ['success', 'failure'], true)) {
            throw new \InvalidArgumentException('compensation receipt status is not supported');
        }
        if (($receipt['compensation_status'] === 'success' && $receipt['manual_required'] !== false)
            || ($receipt['compensation_status'] === 'failure' && $receipt['manual_required'] !== true)
        ) {
            throw new \InvalidArgumentException('compensation receipt status and manual_required are inconsistent');
        }

        $receiptIdentity = trim((string)($receipt['receipt_id'] ?? $receipt['case_id'] ?? ''));
        if ($receiptIdentity === '') {
            throw new \InvalidArgumentException('compensation receipt identity is required');
        }

        $intent = Db::name('operation_execution_intents')
            ->where('id', (int)($task['intent_id'] ?? 0))
            ->where('hotel_id', (int)($task['hotel_id'] ?? 0))
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($intent)) {
            throw new \InvalidArgumentException('compensation receipt execution intent is missing');
        }
        if (strtolower(trim((string)$scope['platform'])) !== strtolower(trim((string)($intent['platform'] ?? '')))) {
            throw new \InvalidArgumentException('compensation receipt platform does not match the execution intent');
        }
        $businessDate = substr(trim((string)$scope['business_date']), 0, 10);
        $dateStart = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
        $dateEnd = substr(trim((string)($intent['date_end'] ?? $dateStart)), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate) !== 1
            || ($dateStart !== '' && $businessDate < $dateStart)
            || ($dateEnd !== '' && $businessDate > $dateEnd)
        ) {
            throw new \InvalidArgumentException('compensation receipt business_date is outside the execution intent');
        }

        $eventText = trim((string)$receipt['event_at']);
        $eventDate = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $eventText);
        $eventErrors = \DateTimeImmutable::getLastErrors();
        if ($eventDate === false || ($eventErrors !== false && ($eventErrors['warning_count'] > 0 || $eventErrors['error_count'] > 0))) {
            throw new \InvalidArgumentException('compensation receipt event_at is invalid');
        }
        $eventAt = $eventDate->getTimestamp();
        if ($eventAt > time() + 300) {
            throw new \InvalidArgumentException('compensation receipt event_at cannot be in the future');
        }

        $rows = Db::name('operation_execution_evidence')
            ->where('task_id', (int)($task['id'] ?? 0))
            ->where('evidence_type', 'compensation_receipt')
            ->whereNull('deleted_at')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $existing = $this->decodeJson((string)($row['platform_response_json'] ?? ''));
            $existingIdentity = trim((string)($existing['receipt_id'] ?? $existing['case_id'] ?? ''));
            if ($existingIdentity !== '' && hash_equals($existingIdentity, $receiptIdentity)) {
                throw new \InvalidArgumentException('duplicate compensation receipt');
            }
            $existingEventAt = strtotime(trim((string)($existing['event_at'] ?? '')));
            if ($existingEventAt !== false && $eventAt <= $existingEventAt) {
                throw new \InvalidArgumentException('stale or duplicate compensation receipt');
            }
        }
    }

    public function reviewExecutionTask(int $taskId, array $hotelIds, array $input = [], int $reviewerId = 0): array
    {
        $this->assertExecutionPayloadHasNoCredentialMaterial($input);
        $this->ensureExecutionTables();
        $task = $this->executionTaskRow($taskId, $hotelIds);
        if (!$task) {
            throw new \RuntimeException('execution task not found');
        }
        $intentRow = $this->executionIntentRow((int)($task['intent_id'] ?? 0), $hotelIds);
        if ($intentRow === null) {
            throw new \RuntimeException('execution intent not found');
        }
        $this->assertExecutionTaskIntentIdentity($task, $intentRow);
        if (($task['status'] ?? '') !== 'executed') {
            throw new \InvalidArgumentException('execution task must be executed before review');
        }
        $manualResultStatus = strtolower(trim((string)($input['result_status'] ?? $input['review_status'] ?? '')));
        $manualSummary = trim((string)($input['result_summary'] ?? $input['review_summary'] ?? ''));
        $hasManualStatus = array_key_exists('result_status', $input) || array_key_exists('review_status', $input);
        $hasManualSummary = array_key_exists('result_summary', $input) || array_key_exists('review_summary', $input);
        $terminalResultStatus = (string)($task['result_status'] ?? '');
        if (in_array($terminalResultStatus, ['success', 'near_success', 'failed'], true)) {
            if ($hasManualStatus
                && $hasManualSummary
                && $manualResultStatus === $terminalResultStatus
                && $manualSummary === (string)($task['result_summary'] ?? '')
            ) {
                return $this->executionTaskDetail($taskId, $hotelIds);
            }
            throw new \InvalidArgumentException('terminal execution review cannot transition');
        }
        $evidenceQuery = Db::name('operation_execution_evidence')
            ->where('task_id', $taskId)
            ->whereNull('deleted_at');
        if (array_key_exists('tenant_id', $task)) {
            $evidenceQuery->where('tenant_id', (int)$task['tenant_id']);
        }
        $evidenceRows = $evidenceQuery
            ->order('id', 'desc')
            ->select()
            ->toArray();
        if ($evidenceRows === []) {
            throw new \InvalidArgumentException('execution evidence is required before review');
        }
        $reviewAvailableOn = $this->executionReviewAvailableOn($evidenceRows);
        if ($reviewAvailableOn !== '' && date('Y-m-d') < $reviewAvailableOn) {
            throw new \InvalidArgumentException('execution review is not available before ' . $reviewAvailableOn);
        }
        $reviewReadbackEvidence = $this->normalizeExecutionReviewReadbackEvidence($input, $task, $reviewerId);
        $expectedResultStatus = (string)($task['result_status'] ?? 'observing');
        $expectedResultSummary = (string)($task['result_summary'] ?? '');
        if ($manualResultStatus === '' && $manualSummary !== '') {
            $manualResultStatus = 'observing';
        }
        if ($manualResultStatus !== '' && !in_array($manualResultStatus, ['observing', 'success', 'near_success', 'failed'], true)) {
            throw new \InvalidArgumentException('review result_status must be observing, success, near_success, or failed');
        }

        $normalizedIntent = $this->normalizeExecutionIntentRow($intentRow);
        $normalizedTask = $this->normalizeExecutionTaskRow($task);
        $isOperationOptimizer = strtolower(trim((string)($normalizedIntent['source_module'] ?? '')))
            === OperationOptimizationExecutionBridgeService::SOURCE_MODULE;
        $isTemporalForecast = strtolower(trim((string)($normalizedIntent['source_module'] ?? '')))
            === TemporalInsightService::OPERATION_SOURCE_MODULE;
        if (in_array($manualResultStatus, ['success', 'near_success'], true)
            || $isOperationOptimizer
            || $isTemporalForecast
        ) {
            $this->syncSourceVerifiedMetricReadback($normalizedTask, $normalizedIntent);
            $evidenceQuery = Db::name('operation_execution_evidence')
                ->where('task_id', $taskId)
                ->whereNull('deleted_at');
            if (array_key_exists('tenant_id', $task)) {
                $evidenceQuery->where('tenant_id', (int)$task['tenant_id']);
            }
            $evidenceRows = $evidenceQuery
                ->order('id', 'desc')
                ->select()
                ->toArray();
        }
        $reviewEvidenceTruth = $this->buildExecutionEvidenceTruth(
            $normalizedIntent,
            $normalizedTask,
            array_map([$this, 'normalizeExecutionEvidenceRow'], $evidenceRows)
        );
        $reviewOutcomeTruth = $this->buildExecutionOutcomeTruth(
            $normalizedIntent,
            $normalizedTask,
            array_map([$this, 'normalizeExecutionEvidenceRow'], $evidenceRows)
        );
        $hasSourceVerifiedReviewEvidence = ($reviewEvidenceTruth['source_verified'] ?? false) === true;
        if ($isOperationOptimizer
            && in_array($manualResultStatus, ['success', 'near_success', 'failed'], true)
            && !$hasSourceVerifiedReviewEvidence
        ) {
            throw new \InvalidArgumentException(
                'same-hotel, same-platform, same-object and same-length OTA readback is required before terminal review'
            );
        }
        if ($isOperationOptimizer
            && $manualResultStatus === 'failed'
            && !in_array((string)($reviewOutcomeTruth['status'] ?? ''), ['adverse', 'missed'], true)
        ) {
            throw new \InvalidArgumentException(
                'failed review requires a source-verified metric outcome that did not improve'
            );
        }
        $actionTrackId = (int)($task['action_track_id'] ?? 0);

        Db::transaction(function () use (
            $taskId,
            $task,
            $manualResultStatus,
            $manualSummary,
            $actionTrackId,
            $expectedResultStatus,
            $expectedResultSummary,
            $reviewReadbackEvidence,
            $hasSourceVerifiedReviewEvidence,
            $reviewOutcomeTruth
        ): void {
            $summary = 'waiting for action tracking data';
            $resultStatus = 'observing';
            if ($manualResultStatus !== '' || $manualSummary !== '') {
                $resultStatus = $manualResultStatus !== '' ? $manualResultStatus : 'observing';
                $summary = $manualSummary !== '' ? $manualSummary : 'manual review recorded from daily workbench patrol';
            } elseif ($actionTrackId > 0 && $this->finishAction($actionTrackId, [(int)$task['hotel_id']])) {
                $action = Db::name('operation_action_tracks')->where('id', $actionTrackId)->find();
                if ($action) {
                    $summary = (string)($action['result_summary'] ?? $summary);
                    $resultStatus = (string)($action['result_status'] ?? $resultStatus);
                }
            }

            if (in_array($resultStatus, ['success', 'near_success'], true) && !$hasSourceVerifiedReviewEvidence) {
                throw new \InvalidArgumentException('source-verified business metric readback is required before success review');
            }
            if (in_array($resultStatus, ['success', 'near_success'], true)
                && !$this->executionPositiveOutcomeAllowsStatus($reviewOutcomeTruth, $resultStatus)
            ) {
                $reason = trim((string)($reviewOutcomeTruth['failure_reason'] ?? 'positive_outcome_unverified'));
                throw new \InvalidArgumentException(
                    'target-aligned source-verified metric outcome is required before success review: ' . $reason
                );
            }
            if ($reviewReadbackEvidence !== null) {
                $this->insertExecutionEvidence($reviewReadbackEvidence);
            }

            $this->assertExecutionPayloadHasNoCredentialMaterial($summary);
            $affected = (int)Db::name('operation_execution_tasks')
                ->where('id', $taskId)
                ->where('hotel_id', (int)$task['hotel_id'])
                ->where('status', 'executed')
                ->where('result_status', $expectedResultStatus)
                ->where('result_summary', $expectedResultSummary)
                ->whereNull('deleted_at')
                ->update([
                    'result_status' => $resultStatus,
                    'result_summary' => $summary,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if ($affected !== 1) {
                throw new \InvalidArgumentException('execution task state changed; refresh before review');
            }
        });

        return $this->executionTaskDetail($taskId, $hotelIds);
    }

    /**
     * Positive reviews may only use a server-side readback of already persisted OTA facts.
     * Operator screenshots remain useful evidence, but they cannot mint source-verified truth.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $intent
     */
    private function syncSourceVerifiedMetricReadback(array $task, array $intent): void
    {
        $taskId = (int)($task['id'] ?? 0);
        $isPublicPageDiagnosis = strtolower(trim((string)($intent['source_module'] ?? ''))) === 'ota_diagnosis'
            && strtolower(trim((string)($intent['expected_metric'] ?? ''))) === 'public_page_verified_field_count';
        if ($taskId <= 0 || (!$isPublicPageDiagnosis && !$this->tableExists('online_daily_data'))) {
            return;
        }

        $existingRows = Db::name('operation_execution_evidence')
            ->where('task_id', $taskId)
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $normalizedExistingRows = array_map([$this, 'normalizeExecutionEvidenceRow'], $existingRows);
        if (($this->buildExecutionEvidenceTruth($intent, $task, $normalizedExistingRows)['source_verified'] ?? false) === true) {
            return;
        }

        $payload = $this->buildSourceVerifiedMetricReadbackPayload($task, $intent);
        if ($payload === null) {
            return;
        }

        Db::transaction(function () use ($payload, $taskId, $task, $intent): void {
            $this->insertExecutionEvidence($payload);
            $persistedRows = Db::name('operation_execution_evidence')
                ->where('task_id', $taskId)
                ->whereNull('deleted_at')
                ->order('id', 'desc')
                ->select()
                ->toArray();
            $truth = $this->buildExecutionEvidenceTruth(
                $intent,
                $task,
                array_map([$this, 'normalizeExecutionEvidenceRow'], $persistedRows)
            );
            if (($truth['source_verified'] ?? false) !== true) {
                throw new \RuntimeException('system source readback evidence failed strict database readback');
            }
        });
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $intent
     * @return array<string, mixed>|null
     */
    private function buildSourceVerifiedMetricReadbackPayload(array $task, array $intent): ?array
    {
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $intentPlatform = strtolower(trim((string)($intent['platform'] ?? '')));
        $platform = $intentPlatform === 'all_ota'
            ? 'ota'
            : $this->normalizeOtaChannel($intentPlatform);
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        $expectedMetric = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $objectType = strtolower(trim((string)($intent['object_type'] ?? '')));
        $dateStart = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
        $dateEnd = substr(trim((string)($intent['date_end'] ?? $dateStart)), 0, 10);
        $executedAt = trim((string)($task['executed_at'] ?? ''));
        $executedTimestamp = strtotime($executedAt);
        if ($hotelId <= 0
            || !in_array($platform, ['ctrip', 'meituan', 'ota'], true)
            || $expectedMetric === ''
            || $objectType === ''
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd) !== 1
            || $executedTimestamp === false
        ) {
            return null;
        }

        if ($sourceModule === OperationOptimizationExecutionBridgeService::SOURCE_MODULE) {
            return $this->operationOptimizationReviewService
                ->buildSourceVerifiedMetricReadbackPayload($task, $intent);
        }

        if ($sourceModule === TemporalInsightService::OPERATION_SOURCE_MODULE) {
            return $this->buildTemporalForecastSourceVerifiedReadbackPayload(
                $task,
                $intent,
                $intentPlatform
            );
        }

        if ($sourceModule === 'ota_diagnosis'
            && $expectedMetric === 'public_page_verified_field_count'
            && $objectType === 'data_collection'
        ) {
            return $this->buildPublicPageSourceVerifiedReadbackPayload($task, $intent);
        }

        $isPatrolGapTask = $sourceModule === 'daily_workbench_patrol';
        if (!$isPatrolGapTask && $sourceModule !== 'ota_diagnosis_saved') {
            return null;
        }
        if (!$this->hasVerifiedExecutionSourceProvenance($intent, $task)) {
            return null;
        }

        $baselineDate = date('Y-m-d', $executedTimestamp);
        $reviewDate = date('Y-m-d', strtotime($baselineDate . ' +1 day'));
        if ($isPatrolGapTask) {
            if ($expectedMetric !== 'ota_operation_closure' || $objectType !== 'data_collection') {
                return null;
            }
            $baselineRows = [];
            $reviewRows = $this->trustedExecutionReadbackRows(
                $this->onlineRows([$hotelId], $dateStart, $dateEnd),
                $platform,
                $executedTimestamp
            );
            if (!$this->trustedExecutionReadbackPlatformCoverage($reviewRows, $platform)) {
                return null;
            }
            $beforeValue = 0.0;
            $afterValue = (float)count($reviewRows);
            $baselineDate = $dateStart;
            $reviewDate = $dateEnd;
        } else {
            if (date('Y-m-d') < $reviewDate) {
                return null;
            }
            $baselineRows = $this->trustedExecutionReadbackRows(
                $this->onlineRows([$hotelId], $baselineDate, $baselineDate),
                $platform
            );
            $reviewRows = $this->trustedExecutionReadbackRows(
                $this->onlineRows([$hotelId], $reviewDate, $reviewDate),
                $platform,
                $executedTimestamp
            );
            if (!$this->trustedExecutionReadbackPlatformCoverage($baselineRows, $platform)
                || !$this->trustedExecutionReadbackPlatformCoverage($reviewRows, $platform)
            ) {
                return null;
            }
            $beforeValue = $this->executionReadbackMetricValue($expectedMetric, $baselineRows, $hotelId, $baselineDate);
            $afterValue = $this->executionReadbackMetricValue($expectedMetric, $reviewRows, $hotelId, $reviewDate);
            if ($beforeValue === null || $afterValue === null) {
                return null;
            }
        }

        $sourceRows = array_values(array_merge($baselineRows, $reviewRows));
        $sourceIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $sourceRows
        ))));
        if ($sourceIds === [] || $reviewRows === []) {
            return null;
        }
        sort($sourceIds, SORT_NUMERIC);
        $readbackTimestamp = 0;
        foreach ($reviewRows as $row) {
            $readbackTimestamp = max($readbackTimestamp, $this->executionReadbackRowTimestamp($row));
        }
        if ($readbackTimestamp <= 0) {
            return null;
        }

        return [
            'task_id' => (int)($task['id'] ?? 0),
            'evidence_type' => 'source_verified_metric_readback',
            'before' => [$expectedMetric => $beforeValue],
            'after' => [$expectedMetric => $afterValue],
            'attachment_path' => '',
            'platform_response' => [
                'verification_authority' => 'system_readback',
                'source' => 'online_daily_data',
                'source_ref' => 'online_daily_data#' . implode(',', $sourceIds),
                'system_hotel_id' => $hotelId,
                'platform' => $platform,
                'object_type' => $objectType,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'baseline_date' => $baselineDate,
                'review_date' => $reviewDate,
                'metric_key' => $expectedMetric,
                'database_written' => true,
                'readback_verified' => true,
                'readback_count' => count($reviewRows),
                'readback_at' => date('Y-m-d H:i:s', $readbackTimestamp),
                'validation_status' => 'verified',
                'failure_reason' => '',
                'causality_claimed' => false,
                'measurement_policy' => $isPatrolGapTask
                    ? 'target_date_gap_closure_verified_after_execution'
                    : 'execution_date_baseline_to_next_day_follow_up',
            ],
            'remark' => 'system-generated readback from persisted and strictly re-read OTA facts',
            'created_by' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $intent
     * @return array<string, mixed>|null
     */
    private function buildTemporalForecastSourceVerifiedReadbackPayload(
        array $task,
        array $intent,
        string $intentPlatform
    ): ?array {
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $forecastPointId = (int)($intent['source_record_id'] ?? 0);
        $metricKey = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $targetDate = substr(trim((string)($intent['date_end'] ?? '')), 0, 10);
        $objectType = strtolower(trim((string)($intent['object_type'] ?? '')));
        $actionType = strtolower(trim((string)($intent['action_type'] ?? '')));
        $currentValue = is_array($intent['current_value'] ?? null)
            ? $intent['current_value']
            : [];
        $targetValue = is_array($intent['target_value'] ?? null)
            ? $intent['target_value']
            : [];
        $evidence = is_array($intent['evidence'] ?? null)
            ? $intent['evidence']
            : [];
        $refs = array_values(array_filter(
            is_array($evidence['evidence_refs'] ?? null) ? $evidence['evidence_refs'] : [],
            'is_array'
        ));
        $forecastRef = $refs[0] ?? [];
        if ($hotelId <= 0
            || $forecastPointId <= 0
            || $intentPlatform !== 'all_ota'
            || $objectType !== 'operation_checklist'
            || $actionType !== 'manual_forecast_review'
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) !== 1
            || date('Y-m-d') < date('Y-m-d', strtotime($targetDate . ' +1 day'))
            || (int)($forecastRef['row_id'] ?? 0) !== $forecastPointId
            || (string)($forecastRef['metric_key'] ?? '') !== $metricKey
            || (string)($forecastRef['target_date'] ?? '') !== $targetDate
            || (string)($currentValue['metric_key'] ?? '') !== $metricKey
            || (string)($currentValue['target_date'] ?? '') !== $targetDate
            || (string)($targetValue['target_metric'] ?? '') !== $metricKey
            || ($targetValue['automatic_price_write'] ?? null) !== false
            || ($evidence['automatic_price_write'] ?? null) !== false
            || ($evidence['review_required'] ?? null) !== true
        ) {
            return null;
        }

        try {
            $actual = (new TemporalInsightService())->forecastActualReadback(
                $forecastPointId,
                $hotelId,
                $metricKey,
                $targetDate
            );
        } catch (Throwable) {
            return null;
        }
        if (($actual['status'] ?? '') !== 'ready'
            || (string)($actual['forecast_run_id'] ?? '') !== (string)($forecastRef['forecast_run_id'] ?? '')
            || (string)($actual['metric_key'] ?? '') !== $metricKey
            || (string)($actual['target_date'] ?? '') !== $targetDate
        ) {
            return null;
        }
        $expectedPredicted = $currentValue['predicted_value'] ?? null;
        if (!is_numeric($expectedPredicted)
            || !is_numeric($actual['predicted_value'] ?? null)
            || abs((float)$expectedPredicted - (float)$actual['predicted_value']) > 0.0001
        ) {
            return null;
        }
        $executedAt = strtotime(trim((string)($task['executed_at'] ?? '')));
        $readbackAt = strtotime(trim((string)($actual['readback_at'] ?? '')));
        if ($executedAt === false || $readbackAt === false || $readbackAt < $executedAt) {
            return null;
        }

        return $this->buildTemporalForecastEvidencePayload($task, $intent, $actual);
    }

    /**
     * Pure payload composer kept separate so the evidence contract can be
     * tested without fabricating database rows.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $actual
     * @return array<string, mixed>|null
     */
    private function buildTemporalForecastEvidencePayload(
        array $task,
        array $intent,
        array $actual
    ): ?array {
        $taskId = (int)($task['id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $metricKey = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $platform = strtolower(trim((string)($intent['platform'] ?? '')));
        $objectType = strtolower(trim((string)($intent['object_type'] ?? '')));
        $dateStart = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
        $dateEnd = substr(trim((string)($intent['date_end'] ?? '')), 0, 10);
        $sourceIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): int => max(0, (int)$value),
            is_array($actual['source_row_ids'] ?? null) ? $actual['source_row_ids'] : []
        ))));
        sort($sourceIds, SORT_NUMERIC);
        $predicted = $actual['predicted_value'] ?? null;
        $actualValue = $actual['actual_value'] ?? null;
        $readbackAt = trim((string)($actual['readback_at'] ?? ''));
        if ($taskId <= 0
            || $hotelId <= 0
            || $metricKey === ''
            || $platform !== 'all_ota'
            || $objectType !== 'operation_checklist'
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd) !== 1
            || ($actual['status'] ?? '') !== 'ready'
            || (int)($actual['system_hotel_id'] ?? 0) !== $hotelId
            || (string)($actual['metric_key'] ?? '') !== $metricKey
            || (string)($actual['target_date'] ?? '') !== $dateEnd
            || !is_numeric($predicted)
            || !is_numeric($actualValue)
            || $sourceIds === []
            || $readbackAt === ''
            || strtotime($readbackAt) === false
        ) {
            return null;
        }

        return [
            'task_id' => $taskId,
            'evidence_type' => 'source_verified_metric_readback',
            'before' => [$metricKey => (float)$predicted],
            'after' => [$metricKey => (float)$actualValue],
            'attachment_path' => '',
            'platform_response' => [
                'verification_authority' => 'system_readback',
                'source' => 'temporal_forecast_actual_readback',
                'source_ref' => 'temporal_forecast_snapshots#'
                    . (int)($actual['forecast_point_id'] ?? 0)
                    . '|online_daily_data#'
                    . implode(',', $sourceIds),
                'system_hotel_id' => $hotelId,
                'platform' => $platform,
                'object_type' => $objectType,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'baseline_date' => (string)($intent['date_start'] ?? $dateStart),
                'review_date' => substr($readbackAt, 0, 10),
                'metric_key' => $metricKey,
                'forecast_point_id' => (int)($actual['forecast_point_id'] ?? 0),
                'forecast_run_id' => (string)($actual['forecast_run_id'] ?? ''),
                'target_date' => $dateEnd,
                'predicted_value' => (float)$predicted,
                'lower_bound' => is_numeric($actual['lower_bound'] ?? null)
                    ? (float)$actual['lower_bound']
                    : null,
                'upper_bound' => is_numeric($actual['upper_bound'] ?? null)
                    ? (float)$actual['upper_bound']
                    : null,
                'actual_value' => (float)$actualValue,
                'within_range' => ($actual['within_range'] ?? null) === true,
                'absolute_error' => is_numeric($actual['absolute_error'] ?? null)
                    ? (float)$actual['absolute_error']
                    : null,
                'database_written' => true,
                'readback_verified' => true,
                'readback_count' => max(1, (int)($actual['readback_count'] ?? count($sourceIds))),
                'readback_at' => $readbackAt,
                'validation_status' => 'verified',
                'failure_reason' => '',
                'causality_claimed' => false,
                'effect_evidence_status' => 'observed_not_attributed',
                'measurement_policy' => 'forecast_target_actual_next_day_readback',
                'automatic_price_write' => false,
            ],
            'remark' => 'system-generated next-day target actual; observation only, no causal claim and no automatic price write',
            'created_by' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Public-page evidence is re-read only when a positive review is requested.
     * It uses the bound self page and the task's exact business date; no history
     * scan, competitor fallback, client-supplied proof, or OTA write is involved.
     *
     * @param array<string,mixed> $task
     * @param array<string,mixed> $intent
     * @return array<string,mixed>|null
     */
    private function buildPublicPageSourceVerifiedReadbackPayload(array $task, array $intent): ?array
    {
        if (!$this->hasVerifiedPublicPageDiagnosisProvenance($intent, $task)) {
            return null;
        }
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $platform = $this->normalizeOtaChannel((string)($intent['platform'] ?? ''));
        $businessDate = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
        $executedTimestamp = strtotime(trim((string)($task['executed_at'] ?? '')));
        if ($hotelId <= 0
            || $platform !== 'ctrip'
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate) !== 1
            || $businessDate !== substr(trim((string)($intent['date_end'] ?? '')), 0, 10)
            || $executedTimestamp === false
        ) {
            // Meituan manual observations remain source_observed until an
            // independently verified consumer-page collector is connected.
            return null;
        }

        try {
            $profiles = (new CtripPublicHotelProfileService())->listDiagnosisProfiles($hotelId, $businessDate);
        } catch (Throwable) {
            return null;
        }
        if (count($profiles) !== 1 || !is_array($profiles[0] ?? null)) {
            return null;
        }
        $profile = $profiles[0];
        $otaHotelId = trim((string)($profile['ota_hotel_id'] ?? ''));
        $snapshotId = (int)($profile['snapshot_id'] ?? 0);
        $captureStatus = strtolower(trim((string)($profile['capture_status'] ?? '')));
        $sourceValidationStatus = strtolower(trim((string)($profile['source_validation_status'] ?? '')));
        $collectedAt = trim((string)($profile['collected_at'] ?? $profile['last_seen_at'] ?? ''));
        $collectedTimestamp = strtotime($collectedAt);
        if ($otaHotelId === ''
            || $snapshotId <= 0
            || (string)($profile['role'] ?? '') !== 'self'
            || (string)($profile['data_date'] ?? '') !== $businessDate
            || !in_array($captureStatus, ['available', 'partial'], true)
            || $sourceValidationStatus !== 'source_verified'
            || ($profile['persistence_readback_verified'] ?? false) !== true
            || $collectedTimestamp === false
            || $collectedTimestamp < $executedTimestamp
        ) {
            return null;
        }

        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $expectedHotelIds = [];
        foreach ((array)($evidence['sources'] ?? []) as $source) {
            if (!is_array($source) || !in_array(strtolower(trim((string)($source['role'] ?? ''))), ['', 'self'], true)) {
                continue;
            }
            $candidate = trim((string)($source['platform_hotel_id'] ?? ''));
            if ($candidate !== '') {
                $expectedHotelIds[$candidate] = true;
            }
        }
        if ($expectedHotelIds === [] || !isset($expectedHotelIds[$otaHotelId])) {
            return null;
        }
        try {
            $canonicalUrl = CtripPublicHotelProfileService::publicUrl($otaHotelId);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (!hash_equals($canonicalUrl, trim((string)($profile['source_url'] ?? '')))) {
            return null;
        }

        $diagnosis = (new OtaPublicPageDiagnosisService())->build(
            $hotelId,
            $platform,
            $businessDate,
            [$profile]
        );
        $coverage = is_array($diagnosis['evidence_coverage'] ?? null) ? $diagnosis['evidence_coverage'] : [];
        $currentValue = is_array($intent['current_value'] ?? null) ? $intent['current_value'] : [];
        $beforeValue = max(0, (int)($currentValue['verified_field_count'] ?? 0));
        $afterValue = max(0, (int)($coverage['verified_field_count'] ?? 0));
        $expectedMetric = 'public_page_verified_field_count';
        $sourceRef = 'ota_ctrip_entity_snapshots#' . $snapshotId;

        return [
            'task_id' => (int)($task['id'] ?? 0),
            'evidence_type' => 'source_verified_metric_readback',
            'before' => [$expectedMetric => (float)$beforeValue],
            'after' => [$expectedMetric => (float)$afterValue],
            'attachment_path' => '',
            'platform_response' => [
                'verification_authority' => 'system_readback',
                'source' => 'ota_ctrip_entity_snapshots',
                'source_ref' => $sourceRef,
                'source_url' => $canonicalUrl,
                'system_hotel_id' => $hotelId,
                'platform_hotel_id' => $otaHotelId,
                'platform' => $platform,
                'object_type' => 'data_collection',
                'date_start' => $businessDate,
                'date_end' => $businessDate,
                'baseline_date' => $businessDate,
                'review_date' => $businessDate,
                'metric_key' => $expectedMetric,
                'database_written' => true,
                'readback_verified' => true,
                'readback_count' => 1,
                'readback_at' => date('Y-m-d H:i:s', $collectedTimestamp),
                'validation_status' => 'verified',
                'source_validation_status' => 'source_verified',
                'failure_reason' => '',
                'causality_claimed' => false,
                'measurement_policy' => 'bound_self_public_page_recollected_after_execution',
            ],
            'remark' => 'system-generated readback from the bound self public page snapshot',
            'created_by' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $task */
    private function hasVerifiedPublicPageDiagnosisProvenance(array $intent, array $task): bool
    {
        $currentValue = is_array($intent['current_value'] ?? null) ? $intent['current_value'] : [];
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $identityVersion = strtolower(trim((string)($evidence['identity_version'] ?? '')));
        $identityProof = $identityVersion === OtaPublicPageDiagnosisService::EXECUTION_IDENTITY_VERSION
            ? trim((string)($evidence['task_identity_fingerprint'] ?? ''))
            : trim((string)($evidence['diagnosis_fingerprint'] ?? ''));

        return (int)($intent['id'] ?? 0) > 0
            && (int)($task['intent_id'] ?? 0) === (int)($intent['id'] ?? 0)
            && (int)($intent['source_record_id'] ?? 0) > 0
            && strtolower(trim((string)($intent['source_module'] ?? ''))) === 'ota_diagnosis'
            && strtolower(trim((string)($intent['object_type'] ?? ''))) === 'data_collection'
            && in_array(strtolower(trim((string)($intent['action_type'] ?? ''))), [
                'complete_public_page_evidence',
                'review_public_page_evidence',
            ], true)
            && strtolower(trim((string)($intent['expected_metric'] ?? ''))) === 'public_page_verified_field_count'
            && strtolower(trim((string)($currentValue['diagnosis_type'] ?? ''))) === 'ota_public_page_evidence'
            && strtolower(trim((string)($targetValue['collection_scope'] ?? ''))) === 'ota_public_page_evidence'
            && strtolower(trim((string)($evidence['metric_scope'] ?? ''))) === 'ota_channel_public_page'
            && preg_match('/^[a-f0-9]{64}$/D', $identityProof) === 1
            && is_array($evidence['sources'] ?? null)
            && $evidence['sources'] !== [];
    }

    /** @param array<string, mixed> $intent @param array<string, mixed> $task */
    private function hasVerifiedExecutionSourceProvenance(array $intent, array $task): bool
    {
        $intentId = (int)($intent['id'] ?? 0);
        if ($intentId <= 0 || (int)($task['intent_id'] ?? 0) !== $intentId) {
            return false;
        }

        return match (strtolower(trim((string)($intent['source_module'] ?? '')))) {
            'daily_workbench_patrol' => $this->hasVerifiedDailyWorkbenchPatrolProvenance($intent, $task),
            'ota_diagnosis_saved' => $this->hasVerifiedOtaDiagnosisProvenance($intent),
            default => false,
        };
    }

    /** @param array<string, mixed> $intent @param array<string, mixed> $task */
    private function hasVerifiedDailyWorkbenchPatrolProvenance(array $intent, array $task): bool
    {
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
        $intentId = (int)($intent['id'] ?? 0);
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $runId = '';
        foreach ((array)($evidence['evidence_refs'] ?? []) as $reference) {
            $sourceRef = is_scalar($reference)
                ? trim((string)$reference)
                : trim((string)($reference['source_ref'] ?? $reference['ref'] ?? ''));
            if (str_starts_with($sourceRef, 'daily_workbench_patrol#')) {
                $runId = substr($sourceRef, strlen('daily_workbench_patrol#'));
                break;
            }
        }
        if ($hotelId <= 0 || $sourceRecordId <= 0 || $intentId <= 0 || $runId === '') {
            return false;
        }

        try {
            $snapshot = (new DailyWorkbenchPatrolService())->findByRunIdForHotel($runId, $hotelId);
        } catch (Throwable) {
            return false;
        }
        if (!is_array($snapshot)) {
            return false;
        }
        $scope = is_array($snapshot['scope'] ?? null) ? $snapshot['scope'] : [];
        $targetDate = substr(trim((string)($scope['target_date'] ?? '')), 0, 10);
        if ($targetDate === ''
            || $targetDate !== substr(trim((string)($intent['date_start'] ?? '')), 0, 10)
            || $targetDate !== substr(trim((string)($intent['date_end'] ?? '')), 0, 10)
        ) {
            return false;
        }

        $items = is_array($snapshot['action_tracking']['items'] ?? null)
            ? $snapshot['action_tracking']['items']
            : [];
        foreach ($items as $item) {
            if (!is_array($item) || (int)($item['hotel_id'] ?? 0) !== $hotelId) {
                continue;
            }
            $execution = is_array($item['operation_execution'] ?? null) ? $item['operation_execution'] : [];
            if ((int)($execution['intent_id'] ?? 0) !== $intentId
                || (int)($execution['source_record_id'] ?? 0) !== $sourceRecordId
            ) {
                continue;
            }
            $linkedTaskId = (int)($execution['task_id'] ?? 0);
            if ($linkedTaskId > 0 && $linkedTaskId !== (int)($task['id'] ?? 0)) {
                return false;
            }
            $actionCode = trim((string)($item['action_code'] ?? ''));
            $questionKey = trim((string)($item['question_key'] ?? ''));
            $actionIdentity = $actionCode !== '' ? $actionCode : $questionKey;
            return $actionIdentity !== ''
                && $actionIdentity === trim((string)($intent['action_type'] ?? ''))
                && $this->dailyWorkbenchPatrolSourceRecordId($runId, $hotelId, $actionCode, $questionKey) === $sourceRecordId;
        }

        return false;
    }

    public function hasOtaDiagnosisExecutionReference(int $hotelId, int $sourceRecordId): bool
    {
        if ($hotelId <= 0 || $sourceRecordId <= 0 || !$this->tableExists('operation_execution_intents')) {
            return false;
        }

        try {
            return Db::name('operation_execution_intents')
                ->where('hotel_id', $hotelId)
                ->where('source_module', 'ota_diagnosis_saved')
                ->where('source_record_id', $sourceRecordId)
                ->whereNull('deleted_at')
                ->count() > 0;
        } catch (Throwable) {
            // Once the relation table exists, an unreadable state must not erase
            // provenance that an execution task may still depend on.
            return true;
        }
    }

    /** @param array<string, mixed> $intent */
    private function hasVerifiedOtaDiagnosisProvenance(array $intent): bool
    {
        $recordId = (int)($intent['source_record_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $intentId = (int)($intent['id'] ?? 0);
        if ($recordId <= 0 || $hotelId <= 0 || $intentId <= 0 || !$this->tableExists('agent_logs')) {
            return false;
        }
        try {
            $row = Db::name('agent_logs')
                ->where('id', $recordId)
                ->where('hotel_id', $hotelId)
                ->where('action', 'ota_diagnosis')
                ->where('agent_type', 2)
                ->find();
        } catch (Throwable) {
            return false;
        }
        if (!is_array($row)) {
            return false;
        }
        $contextValue = $row['context_data'] ?? [];
        $context = is_array($contextValue) ? $contextValue : $this->decodeJson((string)$contextValue);
        $snapshot = is_array($context['diagnosis_result'] ?? null) ? $context['diagnosis_result'] : [];
        if (($context['record_status'] ?? '') === 'superseded'
            || ($snapshot['record_status'] ?? '') === 'superseded'
            || ($snapshot['decision_status'] ?? $snapshot['decision_closure']['status'] ?? '') !== 'action_required'
        ) {
            return false;
        }
        $snapshotHotelId = (int)($snapshot['hotel']['id'] ?? $hotelId);
        $dateRange = is_array($snapshot['date_range'] ?? null) ? $snapshot['date_range'] : [];
        if ($snapshotHotelId !== $hotelId
            || $this->normalizeOtaChannel((string)($snapshot['platform'] ?? '')) !== $this->normalizeOtaChannel((string)($intent['platform'] ?? ''))
            || substr(trim((string)($dateRange['start_date'] ?? '')), 0, 10) !== substr(trim((string)($intent['date_start'] ?? '')), 0, 10)
            || substr(trim((string)($dateRange['end_date'] ?? $dateRange['start_date'] ?? '')), 0, 10) !== substr(trim((string)($intent['date_end'] ?? '')), 0, 10)
        ) {
            return false;
        }

        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $actionIndex = filter_var($evidence['action_index'] ?? null, FILTER_VALIDATE_INT);
        $actions = array_values(array_filter((array)($snapshot['action_items'] ?? []), 'is_array'));
        if ($actionIndex === false || $actionIndex < 0 || !is_array($actions[$actionIndex] ?? null)) {
            return false;
        }
        $action = $actions[$actionIndex];
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $intentRefs = array_values(array_unique(array_filter(array_map('strval', (array)($evidence['evidence_refs'] ?? [])))));
        $actionRefs = array_values(array_unique(array_filter(array_map('strval', (array)($action['evidence_refs'] ?? [])))));
        sort($intentRefs, SORT_STRING);
        sort($actionRefs, SORT_STRING);
        $actionItemId = trim((string)($evidence['action_item_id'] ?? ''));
        $idempotencyKey = trim((string)($evidence['action_idempotency_key'] ?? ''));
        $decisionQuality = is_array($action['decision_quality'] ?? null)
            ? $action['decision_quality']
            : [];

        return ($action['execution_ready'] ?? false) === true
            && ($action['can_request_execution_intent'] ?? false) === true
            && ($action['can_create_execution_intent'] ?? false) === true
            && ($decisionQuality['contract_version'] ?? '') === AiDecisionQualityService::CONTRACT_VERSION
            && ($decisionQuality['execution_ready'] ?? false) === true
            && (int)($action['execution_intent_id'] ?? 0) === $intentId
            && $idempotencyKey !== ''
            && hash_equals($idempotencyKey, trim((string)($action['execution_idempotency_key'] ?? '')))
            && ($actionItemId === '' || hash_equals($actionItemId, trim((string)($action['id'] ?? ''))))
            && trim((string)($targetValue['action_text'] ?? '')) === trim((string)($action['action'] ?? ''))
            && $intentRefs !== []
            && $intentRefs === $actionRefs;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function trustedExecutionReadbackRows(array $rows, string $platform, ?int $minimumTimestamp = null): array
    {
        return array_values(array_filter($rows, function (array $row) use ($platform, $minimumTimestamp): bool {
            if (!$this->isTrustedSelfOtaFactRow($row)) {
                return false;
            }
            $channel = $this->normalizeOtaChannel((string)($row['source'] ?? $row['platform'] ?? ''));
            if ($platform === 'ota') {
                if (!in_array($channel, ['ctrip', 'meituan'], true)) {
                    return false;
                }
            } elseif ($channel !== $platform) {
                return false;
            }
            if ($minimumTimestamp !== null
                && $this->executionReadbackRowTimestamp($row) < ($minimumTimestamp - 300)
            ) {
                return false;
            }
            return true;
        }));
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function trustedExecutionReadbackPlatformCoverage(array $rows, string $platform): bool
    {
        $channels = [];
        foreach ($rows as $row) {
            $channel = $this->normalizeOtaChannel((string)($row['source'] ?? $row['platform'] ?? ''));
            if ($channel !== '') {
                $channels[$channel] = true;
            }
        }
        return $platform === 'ota'
            ? isset($channels['ctrip'], $channels['meituan'])
            : isset($channels[$platform]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function executionReadbackMetricValue(string $metric, array $rows, int $hotelId, string $date): ?float
    {
        $summary = $this->buildSummaryFromRows([], $rows, [$hotelId], $hotelId, $date);
        $ota = $this->buildOtaFromRows($rows);
        $quality = $this->buildServiceQualityFromRows($rows);
        $value = match ($metric) {
            'revenue', 'avg_revenue', 'amount', 'income' => $summary['revenue'] ?? null,
            'orders', 'avg_orders', 'order_count', 'book_order_num' => $summary['orders'] ?? $ota['orders'] ?? null,
            'room_nights', 'avg_room_nights' => $summary['room_nights'] ?? null,
            'adr', 'avg_adr' => $summary['adr'] ?? null,
            'occ', 'occupancy', 'avg_occ' => $summary['occ'] ?? null,
            'detail_rate', 'view_rate', 'flow_rate' => $ota['view_rate'] ?? $ota['flow_rate'] ?? null,
            'conversion', 'conversion_rate', 'order_rate' => $ota['order_rate'] ?? null,
            'avg_psi_score' => (int)($quality['psi_sample_count'] ?? 0) > 0
                ? ($quality['avg_psi_score'] ?? null)
                : null,
            default => null,
        };
        return is_numeric($value) ? (float)$value : null;
    }

    /** @param array<string, mixed> $row */
    private function executionReadbackRowTimestamp(array $row): int
    {
        return $this->trustedOnlineCollectionTimestamp($row);
    }

    private function normalizeExecutionReviewReadbackEvidence(array $input, array $task, int $reviewerId): ?array
    {
        $raw = $this->arrayValue($input['readback_evidence'] ?? []);
        if ($raw === []) {
            return null;
        }
        if ($this->executionReadbackFlagIsTrue($raw['source_verified'] ?? false)
            || strtolower(trim((string)($raw['verification_status'] ?? ''))) === 'source_verified'
        ) {
            throw new \InvalidArgumentException('source_verified cannot be submitted by the client; only operator_attested is supported');
        }

        $operatorAttested = $raw['operator_attested'] ?? $raw['readback_verified'] ?? false;
        if (!$this->executionReadbackFlagIsTrue($operatorAttested)) {
            throw new \InvalidArgumentException('readback_evidence.operator_attested must be true');
        }

        $sourceRef = trim((string)($raw['source_ref'] ?? $raw['receipt_path'] ?? ''));
        if ($sourceRef === '') {
            throw new \InvalidArgumentException('readback_evidence.source_ref is required');
        }
        $attestedAt = trim(str_replace('T', ' ', (string)($raw['operator_attested_at'] ?? $raw['readback_verified_at'] ?? '')));
        $timestamp = strtotime($attestedAt);
        if ($attestedAt === '' || $timestamp === false) {
            throw new \InvalidArgumentException('readback_evidence.operator_attested_at must be a valid date-time');
        }
        if ($timestamp > time() + 300) {
            throw new \InvalidArgumentException('readback_evidence.operator_attested_at cannot be in the future');
        }
        if ($reviewerId <= 0) {
            throw new \InvalidArgumentException('operator attestation requires an authenticated reviewer');
        }
        $taskId = (int)($task['id'] ?? 0);
        if ($taskId <= 0) {
            throw new \InvalidArgumentException('operator attestation requires a persisted execution task');
        }
        $executedTimestamp = strtotime(trim((string)($task['executed_at'] ?? '')));
        if ($executedTimestamp !== false && $timestamp < $executedTimestamp - 300) {
            throw new \InvalidArgumentException('operator attestation must be recorded after task execution');
        }
        $attestedAt = date('Y-m-d H:i:s', $timestamp);
        $remark = trim((string)($raw['remark'] ?? 'operator attested that the OTA platform result was manually re-read'));

        return [
            'task_id' => $taskId,
            'evidence_type' => 'operator_attested_platform_readback',
            'before' => [],
            'after' => [],
            'attachment_path' => $sourceRef,
            'platform_response' => [
                'mode' => 'operator_attested',
                'verification_status' => 'operator_attested',
                'operator_attested' => true,
                'operator_attested_at' => $attestedAt,
                'source_verified' => false,
                'source_validation_status' => 'not_source_verified',
                'source_ref' => $sourceRef,
                'evidence_boundary' => 'operator_attested_platform_readback_no_ota_write',
            ],
            'remark' => $remark,
            'created_by' => $reviewerId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function executionEvidenceHasOperatorAttestation(array $rows, array $task): bool
    {
        $taskId = (int)($task['id'] ?? 0);
        $executedTimestamp = strtotime(trim((string)($task['executed_at'] ?? '')));
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $createdTimestamp = strtotime(trim((string)($row['created_at'] ?? '')));
            if ((int)($row['task_id'] ?? 0) !== $taskId
                || strtolower(trim((string)($row['evidence_type'] ?? ''))) !== 'operator_attested_platform_readback'
                || (int)($row['created_by'] ?? 0) <= 0
                || $createdTimestamp === false
            ) {
                continue;
            }
            $response = $this->decodeJson((string)($row['platform_response_json'] ?? ''));
            if (strtolower(trim((string)($response['mode'] ?? ''))) !== 'operator_attested'
                || strtolower(trim((string)($response['verification_status'] ?? ''))) !== 'operator_attested'
                || strtolower(trim((string)($response['source_validation_status'] ?? ''))) !== 'not_source_verified'
                || !$this->executionReadbackFlagIsTrue($response['operator_attested'] ?? false)
                || !array_key_exists('source_verified', $response)
                || $response['source_verified'] !== false
            ) {
                continue;
            }
            $attestedAt = trim((string)($response['operator_attested_at'] ?? ''));
            $attestedTimestamp = strtotime($attestedAt);
            $sourceRef = trim((string)($response['source_ref'] ?? $row['attachment_path'] ?? ''));
            if ($attestedAt !== ''
                && $attestedTimestamp !== false
                && $attestedTimestamp <= time() + 300
                && $attestedTimestamp <= $createdTimestamp + 300
                && ($executedTimestamp === false || $attestedTimestamp >= $executedTimestamp - 300)
                && $sourceRef !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    private function executionReadbackFlagIsTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true);
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

    private function withHotelTenantId(array $data, string $table, int $hotelId): array
    {
        if ($this->tableHasColumn($table, 'tenant_id')) {
            $data['tenant_id'] = $this->tenantIdForHotel($hotelId);
        }

        return $data;
    }

    private function withExecutionTaskTenantId(array $data, string $table, int $taskId): array
    {
        if ($this->tableHasColumn($table, 'tenant_id')
            || $this->sqliteTableHasColumn($table, 'tenant_id')
        ) {
            $data['tenant_id'] = $this->tenantIdForExecutionTask($taskId);
        }

        return $data;
    }

    private function tenantIdForHotel(int $hotelId): int
    {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('hotel_id is invalid for tenant scope');
        }

        try {
            $tenantId = (int)(Db::name('hotels')->where('id', $hotelId)->value('tenant_id') ?? 0);
        } catch (Throwable $e) {
            throw new \RuntimeException('hotel tenant scope cannot be resolved', 0, $e);
        }
        if ($tenantId <= 0) {
            throw new \RuntimeException('hotel tenant_id is missing or invalid');
        }

        return $tenantId;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
            $columns = array_fill_keys(array_map(static fn(array $row): string => (string)$row['Field'], $rows), true);
            return $cache[$key] = isset($columns[$column]);
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }

    private function sqliteTableHasColumn(string $table, string $column): bool
    {
        try {
            $rows = Db::query('PRAGMA table_info(`' . str_replace('`', '', $table) . '`)');
            foreach ($rows as $row) {
                if ((string)($row['name'] ?? '') === $column) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }

    private function tenantIdForExecutionTask(int $taskId): int
    {
        if ($taskId <= 0) {
            return 0;
        }

        try {
            $row = Db::name('operation_execution_tasks')->where('id', $taskId)->find();
            if (!$row) {
                throw new \RuntimeException('execution task not found for tenant scope');
            }

            $storedTenantId = (int)($row['tenant_id'] ?? 0);
            try {
                $tenantId = $this->tenantIdForHotel((int)($row['hotel_id'] ?? 0));
            } catch (\RuntimeException $e) {
                if ($storedTenantId > 0 && !$this->tableExists('hotels')) {
                    return $storedTenantId;
                }
                throw $e;
            }
            if ($storedTenantId > 0 && $storedTenantId !== $tenantId) {
                throw new \RuntimeException('execution task tenant_id does not match hotel tenant scope');
            }

            return $tenantId;
        } catch (Throwable $e) {
            if ($e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \RuntimeException('execution task tenant scope cannot be resolved', 0, $e);
        }
    }

    private function buildExecutionFlowStages(array $summary): array
    {
        return $this->executionFlowReadService->buildStages($summary);
    }

    private function latestExecutionTask(array $tasks): array
    {
        return $this->executionFlowReadService->latestTask($tasks);
    }

    private function dailyWorkbenchPatrolSourceRecordId(string $runId, int $hotelId, string $actionCode, string $questionKey): int
    {
        return (int)sprintf('%u', crc32($runId . '|' . $hotelId . '|' . $actionCode . '|' . $questionKey));
    }

    private function findDailyWorkbenchPatrolIntent(int $hotelId, int $sourceRecordId): ?array
    {
        $row = Db::name('operation_execution_intents')
            ->where('source_module', 'daily_workbench_patrol')
            ->where('source_record_id', $sourceRecordId)
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at')
            ->find();

        return is_array($row) ? $row : null;
    }

    private function buildDailyWorkbenchPatrolExecutionIntentInput(array $input, int $sourceRecordId): array
    {
        $targetDate = trim((string)($input['target_date'] ?? date('Y-m-d')));
        $actionCode = trim((string)($input['action_code'] ?? ''));
        $questionKey = trim((string)($input['question_key'] ?? ''));
        $platform = strtolower(trim((string)($input['platform'] ?? 'ota')));
        $actionIdentity = $actionCode !== '' ? $actionCode : $questionKey;
        $actionText = trim((string)($input['action_text'] ?? $input['action'] ?? $actionIdentity));
        $entry = trim((string)($input['entry'] ?? ''));
        $status = strtolower(trim((string)($input['status'] ?? 'pending')));
        $priority = strtolower(trim((string)($input['priority'] ?? 'medium')));
        $riskLevel = $priority === 'high' ? 'high' : ($priority === 'low' ? 'low' : 'medium');
        $dataGaps = array_values(array_filter(array_map('strval', (array)($input['data_gaps'] ?? $input['blocking_missing_codes'] ?? []))));
        if ($dataGaps === [] && $questionKey !== '') {
            $dataGaps[] = $questionKey;
        }
        if ($dataGaps === [] && $actionCode !== '') {
            $dataGaps[] = $actionCode;
        }

        return [
            'source_module' => 'daily_workbench_patrol',
            'source_record_id' => $sourceRecordId,
            'hotel_id' => (int)($input['hotel_id'] ?? 0),
            'platform' => $platform !== '' ? $platform : 'ota',
            'object_type' => 'data_collection',
            'action_type' => $actionIdentity,
            'date_start' => $targetDate !== '' ? $targetDate : date('Y-m-d'),
            'date_end' => $targetDate !== '' ? $targetDate : date('Y-m-d'),
            'current_value' => [
                'patrol_action_status' => $status,
                'source' => 'daily_workbench_patrol',
            ],
            'target_value' => [
                'collection_scope' => 'daily_workbench_patrol_action',
                'target_date' => $targetDate !== '' ? $targetDate : date('Y-m-d'),
                'action_text' => $actionText,
                'entry' => $entry,
                'question_key' => $questionKey,
            ],
            'evidence' => [
                'evidence_refs' => [
                    'daily_workbench_patrol#' . (string)($input['run_id'] ?? ''),
                    '/api/online-data/daily-workbench-patrols',
                    '/api/online-data/daily-workbench',
                ],
                'data_gaps' => $dataGaps,
                'source_policy' => 'read_existing_daily_workbench_patrol_snapshot_only',
                'protected_boundary' => 'Operation execution record is created from patrol snapshot; it does not change OTA acquisition logic or fields.',
                'action_item_id' => $actionIdentity,
                'action_item_status' => $status,
                'diagnosis_summary' => $actionText,
                'metric_scope' => 'ota_channel',
            ],
            'expected_metric' => 'ota_operation_closure',
            'expected_delta' => 0,
            'risk_level' => $riskLevel,
            'status' => 'pending_approval',
        ];
    }

    /**
     * Review/readback evidence can be newer than the financial evidence used for ROI.
     * Keep the newest row for display, but calculate ROI from the newest row that
     * contains both before and after revenue facts.
     */
    private function latestExecutionRoiEvidence(array $taskEvidence): array
    {
        return $this->executionOutcomeService->latestExecutionRoiEvidence($taskEvidence);
    }

    private function buildExecutionEvidenceTruth(array $intent, array $task, array $evidenceRows): array
    {
        return $this->executionOutcomeService->buildExecutionEvidenceTruth($intent, $task, $evidenceRows);
    }

    private function assessExecutionEvidenceTruth(array $intent, array $task, array $evidence): array
    {
        return $this->executionOutcomeService->assessExecutionEvidenceTruth($intent, $task, $evidence);
    }

    private function buildExecutionOutcomeTruth(array $intent, array $task, array $evidenceRows): array
    {
        return $this->executionOutcomeService->buildExecutionOutcomeTruth($intent, $task, $evidenceRows);
    }

    private function executionPositiveOutcomeAllowsStatus(array $outcomeTruth, string $reviewStatus): bool
    {
        return $this->executionOutcomeService->executionPositiveOutcomeAllowsStatus($outcomeTruth, $reviewStatus);
    }

    private function buildExecutionTruthContext(
        array $intent,
        array $task,
        array $evidenceTruth,
        string $reviewStatus,
        array $outcomeTruth = []
    ): array
    {
        return $this->executionOutcomeService->buildExecutionTruthContext(
            $intent,
            $task,
            $evidenceTruth,
            $reviewStatus,
            $outcomeTruth
        );
    }

    private function buildExecutionRoi(
        array $intent,
        array $task,
        array $latestEvidence,
        array $evidenceTruth,
        array $outcomeTruth = []
    ): array
    {
        return $this->executionOutcomeService->buildExecutionRoi(
            $intent,
            $task,
            $latestEvidence,
            $evidenceTruth,
            $outcomeTruth
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildExecutionOperatorEvidenceSummary(array $platformResponse): array
    {
        return $this->executionOutcomeService->buildExecutionOperatorEvidenceSummary($platformResponse);
    }

    /**
     * @param list<string> $summaryKeys
     * @return array<string, mixed>
     */
    private function summarizeExecutionOperatorEvidence(array $evidence, array $summaryKeys): array
    {
        return $this->executionOutcomeService->summarizeExecutionOperatorEvidence($evidence, $summaryKeys);
    }

    private function firstNumericMetric(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if ($value === '' || $value === null) {
                continue;
            }
            if (is_numeric($value)) {
                return (float)$value;
            }
        }

        return null;
    }

    private function executionIntentBlockedReasons(string $objectType, array $input, array $targetValue, array $evidence): array
    {
        $reasons = [];
        foreach (['platform', 'object_type', 'action_type'] as $field) {
            if (trim((string)($input[$field] ?? '')) === '') {
                $reasons[] = $field . ' missing';
            }
        }
        if (empty($targetValue)) {
            $reasons[] = 'target_value missing';
        }
        if (!$this->hasMeaningfulExecutionEvidence($evidence)) {
            $reasons[] = 'evidence missing';
        }

        if ($objectType === 'price') {
            foreach (['room_type_key', 'rate_plan_key', 'target_price'] as $field) {
                if (!array_key_exists($field, $targetValue) || trim((string)$targetValue[$field]) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
            if (array_key_exists('target_price', $targetValue)
                && (!is_numeric($targetValue['target_price']) || (float)$targetValue['target_price'] <= 0)
            ) {
                $reasons[] = 'target_price must be positive';
            }
        } elseif ($objectType === 'inventory') {
            if (trim((string)($targetValue['room_type_key'] ?? '')) === '') {
                $reasons[] = 'room_type_key missing';
            }
            if (!array_key_exists('target_inventory', $targetValue) && trim((string)($targetValue['sell_status'] ?? '')) === '') {
                $reasons[] = 'target_inventory or sell_status missing';
            }
        } elseif ($objectType === 'campaign') {
            foreach (['campaign_type', 'target_metric'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
        } elseif ($objectType === 'room_product') {
            foreach (['room_type_key', 'target_metric'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
        } elseif ($objectType === 'data_collection') {
            if (trim((string)($targetValue['collection_scope'] ?? '')) === '' && trim((string)($targetValue['target_date'] ?? '')) === '') {
                $reasons[] = 'collection_scope or target_date missing';
            }
            if (empty($evidence['evidence_refs']) && empty($evidence['data_gaps'])) {
                $reasons[] = 'ota evidence refs or data_gaps missing';
            }
        } elseif ($objectType === 'operation_checklist') {
            foreach (['title', 'action_text'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
            if (!is_array($targetValue['steps'] ?? null) || $targetValue['steps'] === []) {
                $reasons[] = 'steps missing';
            }
            if (!is_array($targetValue['acceptance_criteria'] ?? null) || $targetValue['acceptance_criteria'] === []) {
                $reasons[] = 'acceptance_criteria missing';
            }
            if (empty($evidence['evidence_refs'])) {
                $reasons[] = 'knowledge evidence refs missing';
            }
        } elseif ($objectType === 'investment') {
            foreach (['project_name', 'tracking_status', 'target_metric'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
            $sourceModule = trim((string)($input['source_module'] ?? ''));
            if (in_array($sourceModule, ['strategy_simulation', 'quant_simulation'], true)) {
                $readinessStage = trim((string)($evidence['readiness_stage'] ?? ''));
                if ($readinessStage === '') {
                    $reasons[] = 'simulation_readiness_stage missing';
                } elseif (!in_array($readinessStage, ['review_ready', 'approved_pending_execution', 'execution_ready'], true)) {
                    $reasons[] = $readinessStage;
                }
                if (!empty($evidence['data_gaps'])) {
                    $reasons[] = 'simulation_readiness_gaps_pending';
                }
            }
        } elseif ($objectType === 'opening') {
            foreach (['project_name', 'tracking_status', 'target_metric'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
        } elseif ($objectType === 'expansion') {
            foreach (['project_name', 'tracking_status', 'target_metric'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
            $readinessStage = trim((string)($evidence['readiness_stage'] ?? ''));
            if ($readinessStage === '') {
                $reasons[] = 'expansion_readiness_stage missing';
            } elseif (!in_array($readinessStage, ['review_ready', 'approved_pending_tracking'], true)) {
                $reasons[] = 'expansion_readiness_stage ' . $readinessStage;
            }
        } elseif ($objectType === 'revenue_research') {
            foreach (['research_product', 'action_text', 'target_metric'] as $field) {
                if (trim((string)($targetValue[$field] ?? '')) === '') {
                    $reasons[] = $field . ' missing';
                }
            }
            $readinessStage = trim((string)($evidence['research_readiness_stage'] ?? ''));
            if ($readinessStage === '') {
                $reasons[] = 'research_readiness_stage missing';
            } elseif ($readinessStage !== 'research_ready_for_execution') {
                $reasons[] = $readinessStage;
            }
            if (!empty($evidence['data_gaps'])) {
                $reasons[] = 'research_data_gaps_pending';
            }
        } elseif ($objectType !== '') {
            $reasons[] = 'object_type not supported';
        }

        return array_values(array_unique($reasons));
    }

    private function assertExecutionPayloadHasNoCredentialMaterial(mixed $value, int $depth = 0): void
    {
        if ($depth > 32) {
            throw new \InvalidArgumentException('Operation execution payload nesting is too deep.');
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($this->isExecutionCredentialKey((string)$key)
                    && !$this->isEmptyOrRedactedCredentialValue($item)) {
                    throw new \InvalidArgumentException('Operation execution payload contains reusable credential material.');
                }
                $this->assertExecutionPayloadHasNoCredentialMaterial($item, $depth + 1);
            }
            return;
        }

        if (is_object($value)) {
            $this->assertExecutionPayloadHasNoCredentialMaterial(get_object_vars($value), $depth + 1);
            return;
        }

        if (is_string($value) && $this->containsExecutionCredentialText($value)) {
            throw new \InvalidArgumentException('Operation execution payload contains reusable credential material.');
        }
    }

    private function isExecutionCredentialKey(string $key): bool
    {
        $normalized = strtolower((string)(preg_replace('/[^a-z0-9]/i', '', $key) ?? ''));
        return isset(self::EXECUTION_CREDENTIAL_KEYS[$normalized]);
    }

    private function isEmptyOrRedactedCredentialValue(mixed $value): bool
    {
        if ($value === null || $value === false || $value === 0 || $value === [] || $value === '') {
            return true;
        }
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), [
            '***',
            '[redacted]',
            'redacted',
            '[masked]',
            'masked',
            'missing',
            'unavailable',
            'expired',
            'invalid',
            'revoked',
            'unknown',
            'none',
            'null',
            'empty',
            'omitted',
            'not_configured',
        ], true);
    }

    private function containsExecutionCredentialText(string $value): bool
    {
        if (trim($value) === '') {
            return false;
        }

        $safeStatus = '(?:\*{3,}|\[?redacted\]?|\[?masked\]?|missing|unavailable|expired|invalid|revoked|unknown|none|null|empty|omitted|not_configured)';
        if (preg_match('/\b(?:authorization|cookie|set-cookie)\s*:\s*(?!' . $safeStatus . '\b)[^\r\n]+/iu', $value) === 1) {
            return true;
        }

        return preg_match(
            '/["\']?(?:authorization|auth_data|auth_token|access_token|refresh_token|token|cookies?|password|client_secret|api_secret|spidertoken|mtgsig)["\']?\s*[:=]\s*["\']?(?!' . $safeStatus . '(?:["\']|\b))[^\s,;}"\']+/iu',
            $value
        ) === 1;
    }

    private function sanitizeLegacyExecutionValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            return '[redacted]';
        }

        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                if ($this->isExecutionCredentialKey((string)$key)) {
                    $safe[$key] = '[redacted]';
                    continue;
                }
                $safe[$key] = $this->sanitizeLegacyExecutionValue($item, $depth + 1);
            }
            return $safe;
        }

        if (is_object($value)) {
            return '[redacted]';
        }

        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        $trimmed = trim($value);
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return json_encode(
                    $this->sanitizeLegacyExecutionValue($decoded, $depth + 1),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) ?: '{}';
            }
        }

        return $this->sanitizeLegacyExecutionText($value);
    }

    private function sanitizeLegacyExecutionText(string $value): string
    {
        $value = preg_replace(
            '/(\b(?:authorization|cookie|set-cookie)\s*:\s*)[^\r\n]+/iu',
            '$1[redacted]',
            $value
        ) ?? $value;

        return preg_replace(
            '/(["\']?(?:authorization|auth_data|auth_token|access_token|refresh_token|token|cookies?|password|client_secret|api_secret|spidertoken|mtgsig)["\']?\s*[:=]\s*)["\']?[^\s,;}"\']+["\']?/iu',
            '$1[redacted]',
            $value
        ) ?? $value;
    }

    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $factors
     * @return array<string, mixed>
     */
    private function latestManualReviewFromFactors(array $factors): array
    {
        if (is_array($factors['manual_review'] ?? null)) {
            return $factors['manual_review'];
        }

        $versions = is_array($factors['manual_review_versions'] ?? null)
            ? array_values($factors['manual_review_versions'])
            : [];
        $last = end($versions);

        return is_array($last) ? $last : [];
    }

    /**
     * @param array<string, mixed> $review
     */
    private function manualApprovedPriceFromReview(array $review): ?float
    {
        if (($review['action'] ?? '') !== 'approve_with_changes') {
            return null;
        }

        $price = $review['approved_price'] ?? null;
        if (is_string($price)) {
            $price = preg_replace('/[^\d.\-]/', '', $price) ?? '';
        }
        if ($price === null || $price === '' || !is_numeric($price)) {
            return null;
        }

        $number = round((float)$price, 2);

        return $number > 0 ? $number : null;
    }

    private function normalizeExecutionDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('计划执行日期格式无效');
        }

        return date('Y-m-d', $timestamp);
    }

    private function ensureExecutionTables(): void
    {
        foreach (['operation_execution_intents', 'operation_execution_tasks', 'operation_execution_evidence'] as $table) {
            if (!$this->tableExists($table)) {
                throw new \RuntimeException($table . ' table does not exist, run database migration first');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function expansionExecutionIntentIdempotencyKey(array $payload): string
    {
        return 'expansion:v1:' . (int)$payload['source_record_id'];
    }

    /** @param array<string, mixed> $payload */
    private function priceSuggestionExecutionIntentIdempotencyKey(array $payload): string
    {
        return 'price_suggestion:v1:' . (int)$payload['source_record_id'];
    }

    /** @param array<string, mixed> $payload */
    private function knowledgeSopExecutionIntentIdempotencyKey(array $payload): string
    {
        $evidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
        $provenance = is_array($evidence['knowledge_provenance'] ?? null)
            ? $evidence['knowledge_provenance']
            : [];
        $targetValue = is_array($payload['target_value'] ?? null) ? $payload['target_value'] : [];
        $unitId = (int)($provenance['knowledge_unit_id'] ?? 0);
        $chunkId = (int)($provenance['knowledge_chunk_id'] ?? 0);
        $hotelId = (int)($payload['hotel_id'] ?? 0);
        $platform = strtolower(trim((string)($payload['platform'] ?? '')));
        $contentDigest = strtolower(trim((string)($provenance['content_digest'] ?? '')));
        $unitAuthorityDigest = strtolower(trim((string)($provenance['unit_authority_digest'] ?? '')));

        if (($provenance['contract_version'] ?? '') !== KnowledgeSopExecutionProvenanceService::CONTRACT_VERSION
            || $unitId <= 0
            || $chunkId <= 0
            || $chunkId !== (int)($payload['source_record_id'] ?? 0)
            || $hotelId <= 0
            || $hotelId !== (int)($provenance['target_hotel_id'] ?? 0)
            || $platform === ''
            || $platform !== strtolower(trim((string)($provenance['resolved_platform'] ?? '')))
            || preg_match('/^[a-f0-9]{64}$/D', $contentDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $unitAuthorityDigest) !== 1
        ) {
            throw new \InvalidArgumentException('knowledge SOP idempotency provenance is invalid');
        }

        $identity = [
            'contract_version' => KnowledgeSopExecutionProvenanceService::CONTRACT_VERSION,
            'knowledge_unit_id' => $unitId,
            'knowledge_chunk_id' => $chunkId,
            'content_digest' => $contentDigest,
            'unit_authority_digest' => $unitAuthorityDigest,
            'target_hotel_id' => $hotelId,
            'resolved_platform' => $platform,
            'action_type' => trim((string)($payload['action_type'] ?? '')),
            'date_start' => trim((string)($payload['date_start'] ?? '')),
            'date_end' => trim((string)($payload['date_end'] ?? '')),
            'assignee_id' => (int)($targetValue['assignee_id'] ?? 0),
        ];
        $encoded = json_encode(
            $identity,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return 'knowledge_sop_' . md5($encoded);
    }

    private function normalizeTrustedExecutionIntentIdempotencyKey(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^(?:ota_diagnosis_action_[a-f0-9]{32}:attempt:[1-9][0-9]*|operation_alert_[a-f0-9]{32}|operating_target_[a-f0-9]{32}|operation_optimizer_[a-f0-9]{32})$/D', $value) !== 1) {
            throw new \InvalidArgumentException('trusted execution-intent idempotency key is invalid');
        }
        return $value;
    }

    private function normalizeOtaDiagnosisExecutionIntentBaseKey(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^ota_diagnosis_action_[a-f0-9]{32}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('OTA diagnosis execution-intent base key is invalid');
        }
        return $value;
    }

    /** @param array<string, mixed> $schedule @return array<string, mixed> */
    private function normalizePendingExecutionIntentSchedule(array $schedule): array
    {
        $assigneeId = (int)($schedule['assignee_id'] ?? 0);
        if ($assigneeId <= 0) {
            throw new \InvalidArgumentException('assignee_id is required');
        }
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $parse = static function (mixed $value, string $field) use ($timezone): \DateTimeImmutable {
            $value = trim(str_replace('T', ' ', (string)$value));
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/D', $value) !== 1) {
                throw new \InvalidArgumentException($field . ' must use YYYY-MM-DD HH:MM[:SS]');
            }
            $value = strlen($value) === 16 ? $value . ':00' : $value;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date === false
                || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
                || $date->format('Y-m-d H:i:s') !== $value
            ) {
                throw new \InvalidArgumentException($field . ' must be a valid date-time');
            }
            return $date;
        };
        $dueAt = $parse($schedule['due_at'] ?? '', 'due_at');
        $reviewAt = $parse($schedule['review_at'] ?? '', 'review_at');
        if ($dueAt <= new \DateTimeImmutable('now', $timezone)) {
            throw new \InvalidArgumentException('due_at must be later than the current time');
        }
        if ($reviewAt <= $dueAt) {
            throw new \InvalidArgumentException('review_at must be later than due_at');
        }

        return [
            'assignee_id' => $assigneeId,
            'due_at' => $dueAt->format('Y-m-d H:i:s'),
            'review_at' => $reviewAt->format('Y-m-d H:i:s'),
            'source_policy' => 'human_assigned_schedule_requires_manual_approval_and_readback_review',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function replayTrustedExecutionIntent(string $idempotencyKey, array $payload, array $hotelIds): ?array
    {
        try {
            $row = Db::name('operation_execution_intents')
                ->where('idempotency_key', $idempotencyKey)
                ->whereNull('deleted_at')
                ->field('id,source_module,source_record_id,hotel_id,platform,object_type,action_type')
                ->find();
        } catch (Throwable $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'unknown column')
                || str_contains($message, 'no such column')
                || str_contains($message, 'undefined column')
            ) {
                throw new \RuntimeException(
                    'operation_execution_intents.idempotency_key is unavailable; run the 20260716 execution-intent idempotency migration first',
                    500,
                    $e
                );
            }
            throw $e;
        }

        if (!$row) {
            return null;
        }
        foreach (['source_module', 'source_record_id', 'hotel_id', 'platform', 'object_type', 'action_type'] as $field) {
            if ((string)($row[$field] ?? '') !== (string)($payload[$field] ?? '')) {
                throw new \RuntimeException('execution-intent idempotency key is already linked to a different request', 409);
            }
        }

        $intent = $this->executionIntentDetail((int)$row['id'], $hotelIds);
        $intent['idempotent_replay'] = true;
        return $intent;
    }

    /** @param array<string, mixed> $payload */
    private function replayExpansionExecutionIntent(string $idempotencyKey, array $payload, array $hotelIds): ?array
    {
        try {
            $row = Db::name('operation_execution_intents')
                ->where('idempotency_key', $idempotencyKey)
                ->where('source_module', 'expansion')
                ->where('object_type', 'expansion')
                ->where('source_record_id', (int)$payload['source_record_id'])
                ->whereNull('deleted_at')
                ->field('id,hotel_id')
                ->find();
        } catch (Throwable $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'unknown column')
                || str_contains($message, 'no such column')
                || str_contains($message, 'undefined column')
            ) {
                throw new \RuntimeException(
                    'operation_execution_intents.idempotency_key is unavailable; run the 20260716 execution-intent idempotency migration first',
                    500,
                    $e
                );
            }

            throw $e;
        }

        if (!$row) {
            return null;
        }
        if ((int)$row['hotel_id'] !== (int)$payload['hotel_id']) {
            throw new \RuntimeException('expansion record is already linked to an execution intent for a different hotel', 409);
        }

        return $this->executionIntentDetail((int)$row['id'], $hotelIds);
    }

    private function executionIntentRow(int $id, array $hotelIds, bool $lock = false): ?array
    {
        if ($id <= 0 || empty($hotelIds)) {
            return null;
        }

        $query = Db::name('operation_execution_intents')
            ->where('id', $id)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();

        return is_array($row) ? $row : null;
    }

    private function executionTaskRow(int $id, array $hotelIds): ?array
    {
        if ($id <= 0 || empty($hotelIds)) {
            return null;
        }

        $row = Db::name('operation_execution_tasks')
            ->where('id', $id)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at')
            ->find();

        return is_array($row) ? $row : null;
    }

    private function executionIntentDetail(int $id, array $hotelIds): array
    {
        $row = $this->executionIntentRow($id, $hotelIds);
        if (!$row) {
            throw new \RuntimeException('execution intent not found');
        }

        $intent = $this->normalizeExecutionIntentRow($row);
        $taskQuery = Db::name('operation_execution_tasks')
            ->where('intent_id', $id)
            ->where('hotel_id', (int)$row['hotel_id'])
            ->whereNull('deleted_at');
        if (array_key_exists('tenant_id', $row)) {
            $taskQuery->where('tenant_id', (int)$row['tenant_id']);
        }
        $tasks = $taskQuery
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $intent['tasks'] = array_map([$this, 'normalizeExecutionTaskRow'], $tasks);

        return $intent;
    }

    private function executionTaskDetail(int $id, array $hotelIds): array
    {
        $row = $this->executionTaskRow($id, $hotelIds);
        if (!$row) {
            throw new \RuntimeException('execution task not found');
        }

        $task = $this->normalizeExecutionTaskRow($row);
        $intentRow = $this->executionIntentRow((int)($task['intent_id'] ?? 0), $hotelIds);
        if ($intentRow === null) {
            throw new \RuntimeException('execution task parent intent not found');
        }
        $this->assertExecutionTaskIntentIdentity($row, $intentRow);

        $evidenceQuery = Db::name('operation_execution_evidence')
            ->where('task_id', $id)
            ->whereNull('deleted_at');
        if (array_key_exists('tenant_id', $row)) {
            $evidenceQuery->where('tenant_id', (int)$row['tenant_id']);
        }
        $evidenceRows = $evidenceQuery
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $task['evidence'] = array_map([$this, 'normalizeExecutionEvidenceRow'], $evidenceRows);
        $task['evidence_summary'] = $this->buildSafeExecutionEvidenceSummary($task['evidence']);
        $intent = $this->normalizeExecutionIntentRow($intentRow);
        $task['evidence_truth'] = $this->buildExecutionEvidenceTruth($intent, $task, $task['evidence']);
        $task['outcome_truth'] = $this->buildExecutionOutcomeTruth($intent, $task, $task['evidence']);
        $task['truth_context'] = $this->buildExecutionTruthContext(
            $intent,
            $task,
            $task['evidence_truth'],
            (string)($task['result_status'] ?? 'observing'),
            $task['outcome_truth']
        );
        $task['review_available_on'] = $this->executionReviewAvailableOn($task['evidence']);
        $task['review_is_available'] = $task['review_available_on'] === '' || $task['review_available_on'] <= date('Y-m-d');

        return $task;
    }

    private function executionReviewAvailableOn(array $evidenceRows): string
    {
        return $this->executionFlowReadService->reviewAvailableOn($evidenceRows);
    }

    /**
     * Keep a non-sensitive receipt visible after protected-response redaction removes
     * the raw evidence payload for non-super-admin operators.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{count: int, types: array<int, string>, latest_type: string, latest_at: string}
     */
    private function buildSafeExecutionEvidenceSummary(array $rows): array
    {
        return $this->executionFlowReadService->buildSafeEvidenceSummary($rows);
    }

    private function normalizeExecutionIntentRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['hotel_id'] = (int)$row['hotel_id'];
        $row['source_record_id'] = (int)($row['source_record_id'] ?? 0);
        $row['expected_delta'] = (float)($row['expected_delta'] ?? 0);
        $row['current_value'] = $this->decodeJson((string)($row['current_value_json'] ?? ''));
        $row['target_value'] = $this->decodeJson((string)($row['target_value_json'] ?? ''));
        $row['evidence'] = $this->decodeJson((string)($row['evidence_json'] ?? ''));
        unset($row['idempotency_key'], $row['current_value_json'], $row['target_value_json'], $row['evidence_json']);

        $sanitized = $this->sanitizeLegacyExecutionValue($row);
        return is_array($sanitized) ? $sanitized : [];
    }

    private function normalizeExecutionTaskRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['intent_id'] = (int)$row['intent_id'];
        $row['hotel_id'] = (int)$row['hotel_id'];
        $row['operator_id'] = (int)($row['operator_id'] ?? 0);
        $row['action_track_id'] = (int)($row['action_track_id'] ?? 0);
        $row['current_value'] = $this->decodeJson((string)($row['current_value_json'] ?? ''));
        $row['target_value'] = $this->decodeJson((string)($row['target_value_json'] ?? ''));
        unset($row['current_value_json'], $row['target_value_json']);

        $sanitized = $this->sanitizeLegacyExecutionValue($row);
        return is_array($sanitized) ? $sanitized : [];
    }

    private function normalizeExecutionEvidenceRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['task_id'] = (int)$row['task_id'];
        $row['created_by'] = (int)($row['created_by'] ?? 0);
        $row['before'] = $this->decodeJson((string)($row['before_json'] ?? ''));
        $row['after'] = $this->decodeJson((string)($row['after_json'] ?? ''));
        $row['platform_response'] = $this->decodeJson((string)($row['platform_response_json'] ?? ''));
        unset($row['before_json'], $row['after_json'], $row['platform_response_json']);

        $sanitized = $this->sanitizeLegacyExecutionValue($row);
        return is_array($sanitized) ? $sanitized : [];
    }

    private function insertExecutionEvidence(array $payload): void
    {
        $this->assertExecutionPayloadHasNoCredentialMaterial($payload);
        $taskId = (int)$payload['task_id'];
        Db::name('operation_execution_evidence')->insert($this->withExecutionTaskTenantId([
            'task_id' => $taskId,
            'evidence_type' => (string)$payload['evidence_type'],
            'before_json' => json_encode($payload['before'] ?? [], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode($payload['after'] ?? [], JSON_UNESCAPED_UNICODE),
            'attachment_path' => (string)($payload['attachment_path'] ?? ''),
            'platform_response_json' => json_encode($payload['platform_response'] ?? [], JSON_UNESCAPED_UNICODE),
            'remark' => (string)($payload['remark'] ?? ''),
            'created_by' => (int)($payload['created_by'] ?? 0),
            'created_at' => (string)($payload['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'operation_execution_evidence', $taskId));
    }

    private function buildExecutionEvidencePlatformResponse(array $evidence): array
    {
        $platformResponse = $this->arrayValue($evidence['platform_response'] ?? []);
        foreach (['operator_execution_evidence', 'operator_roi_evidence'] as $key) {
            $operatorEvidence = $this->arrayValue($evidence[$key] ?? []);
            if ($operatorEvidence !== []) {
                $platformResponse[$key] = $operatorEvidence;
            }
        }

        return $platformResponse;
    }

    private function createActionTrackForExecution(array $intent, int $taskId): int
    {
        $target = $this->decodeJson((string)($intent['target_value_json'] ?? ''));
        $dateStart = (string)($intent['date_start'] ?? date('Y-m-d'));
        $hotelId = (int)$intent['hotel_id'];
        $before = $this->baseline([$hotelId], 7, $dateStart);

        return (int)Db::name('operation_action_tracks')->insertGetId($this->withHotelTenantId([
            'hotel_id' => $hotelId,
            'action_type' => (string)($intent['action_type'] ?? ''),
            'action_title' => 'execution_task_' . $taskId . '_' . (string)($intent['object_type'] ?? 'operation'),
            'start_date' => $dateStart,
            'end_date' => !empty($intent['date_end']) ? (string)$intent['date_end'] : null,
            'target_metric' => (string)($intent['expected_metric'] ?? $target['target_metric'] ?? ''),
            'target_change_rate' => (float)($intent['expected_delta'] ?? 0),
            'before_data_json' => json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_data_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'result_status' => 'observing',
            'result_summary' => '',
            'remark' => 'created from operation execution task',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'operation_action_tracks', $hotelId));
    }

    private function buildEffectValidation(array $hotelIds, ?int $hotelId, array $actions): array
    {
        $dataGaps = [];
        $priceSuggestionStats = $this->priceSuggestionAdoptionStats($hotelIds, $hotelId, 30, $dataGaps);
        $alertAccuracyStats = $this->alertAccuracyStats($hotelIds, $hotelId, 30, $dataGaps);

        return $this->buildEffectValidationSummary($actions, $priceSuggestionStats, $alertAccuracyStats, $dataGaps);
    }

    private function buildEffectValidationSummary(array $actions, array $priceSuggestionStats, array $alertAccuracyStats, array $dataGaps): array
    {
        $reviewedStatuses = ['success', 'near_success', 'failed'];
        $hitStatuses = ['success', 'near_success'];
        $counts = [
            'total' => count($actions),
            'reviewed' => 0,
            'observing' => 0,
            'success' => 0,
            'near_success' => 0,
            'failed' => 0,
        ];
        $revenue = ['before' => 0.0, 'after' => 0.0, 'sample_count' => 0];
        $conversion = ['before' => 0.0, 'after' => 0.0, 'sample_count' => 0];
        $pricing = ['reviewed' => 0, 'hit' => 0];

        foreach ($actions as $action) {
            $result = is_array($action['result'] ?? null) ? $action['result'] : [];
            $status = (string)($result['status'] ?? $action['result_status'] ?? 'observing');
            if (in_array($status, $reviewedStatuses, true)) {
                $counts['reviewed']++;
                $counts[$status]++;
            } else {
                $counts['observing']++;
            }

            if ((string)($action['action_type'] ?? '') === 'price_adjust' && in_array($status, $reviewedStatuses, true)) {
                $pricing['reviewed']++;
                if (in_array($status, $hitStatuses, true)) {
                    $pricing['hit']++;
                }
            }

            $before = is_array($action['before'] ?? null) ? $action['before'] : [];
            $after = is_array($action['after'] ?? null) ? $action['after'] : [];
            if (($before['data_status'] ?? '') === self::DATA_OK && ($after['data_status'] ?? '') === self::DATA_OK) {
                $beforeRevenue = (float)($before['avg_revenue'] ?? 0);
                $afterRevenue = (float)($after['avg_revenue'] ?? 0);
                if ($beforeRevenue > 0) {
                    $revenue['before'] += $beforeRevenue;
                    $revenue['after'] += $afterRevenue;
                    $revenue['sample_count']++;
                }

                $beforeConversion = (float)($before['avg_conversion'] ?? 0);
                $afterConversion = (float)($after['avg_conversion'] ?? 0);
                if ($beforeConversion > 0) {
                    $conversion['before'] += $beforeConversion;
                    $conversion['after'] += $afterConversion;
                    $conversion['sample_count']++;
                }
            }
        }

        $metrics = [
            $this->effectRateMetric(
                'revenue_lift_rate',
                '收益提升',
                $revenue['after'] - $revenue['before'],
                $revenue['before'],
                (int)$revenue['sample_count'],
                '(执行后日均收入 - 执行前日均收入) / 执行前日均收入'
            ),
            $this->effectRateMetric(
                'conversion_lift_rate',
                '转化提升',
                $conversion['after'] - $conversion['before'],
                $conversion['before'],
                (int)$conversion['sample_count'],
                '(执行后平均转化率 - 执行前平均转化率) / 执行前平均转化率'
            ),
            $this->effectRateMetric(
                'pricing_hit_rate',
                '调价命中率',
                (float)$pricing['hit'],
                (float)$pricing['reviewed'],
                (int)$pricing['reviewed'],
                '调价动作中复盘结果为有效或接近有效的数量 / 已复盘调价动作数量'
            ),
            $this->effectRateMetric(
                'suggestion_adoption_rate',
                '建议采纳率',
                (float)($priceSuggestionStats['adopted'] ?? 0),
                (float)($priceSuggestionStats['total'] ?? 0),
                (int)($priceSuggestionStats['total'] ?? 0),
                '已批准或已应用的定价建议数量 / 近30天定价建议总数'
            ),
            $this->effectRateMetric(
                'alert_accuracy_rate',
                '预警准确率',
                (float)($alertAccuracyStats['accurate'] ?? 0),
                (float)($alertAccuracyStats['reviewed'] ?? 0),
                (int)($alertAccuracyStats['reviewed'] ?? 0),
                '标记为准确的预警数量 / 已复盘准确性的预警数量'
            ),
        ];

        $readyCount = count(array_filter($metrics, static fn(array $metric): bool => ($metric['status'] ?? '') === 'ready'));
        $status = $readyCount === count($metrics) ? 'ready' : ($readyCount > 0 ? 'partial' : 'data_gap');

        return [
            'status' => $status,
            'period' => [
                'price_suggestion_days' => 30,
                'alert_accuracy_days' => 30,
            ],
            'action_counts' => $counts,
            'metrics' => $metrics,
            'data_gaps' => array_values($dataGaps),
        ];
    }

    private function effectRateMetric(string $key, string $label, float $numerator, float $denominator, int $sampleCount, string $formula): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $denominator > 0 ? round($numerator / $denominator * 100, 2) : null,
            'unit' => '%',
            'status' => $denominator > 0 ? 'ready' : 'insufficient_data',
            'sample_count' => $sampleCount,
            'numerator' => round($numerator, 2),
            'denominator' => round($denominator, 2),
            'formula' => $formula,
        ];
    }

    private function priceSuggestionAdoptionStats(array $hotelIds, ?int $hotelId, int $days, array &$dataGaps): array
    {
        if (!$this->tableExists('price_suggestions')) {
            $dataGaps[] = ['code' => 'price_suggestions_missing', 'message' => '定价建议表不存在'];
            return ['total' => 0, 'adopted' => 0, 'data_status' => self::DATA_PENDING];
        }

        $start = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days'));
        $end = date('Y-m-d');
        try {
            $query = Db::name('price_suggestions')->field('status')->whereBetween('suggestion_date', [$start, $end]);
            if ($hotelId !== null && $hotelId > 0) {
                $query->where('hotel_id', $hotelId);
            } elseif (!empty($hotelIds)) {
                $query->whereIn('hotel_id', $hotelIds);
            }
            $rows = $query->select()->toArray();
        } catch (Throwable $e) {
            $dataGaps[] = ['code' => 'price_suggestions_read_failed', 'message' => '定价建议统计读取失败'];
            return ['total' => 0, 'adopted' => 0, 'data_status' => 'read_failed'];
        }

        $adopted = 0;
        foreach ($rows as $row) {
            if (in_array((int)($row['status'] ?? 0), [2, 4], true)) {
                $adopted++;
            }
        }

        if (empty($rows)) {
            $dataGaps[] = ['code' => 'price_suggestions_no_samples', 'message' => '近30天没有定价建议样本'];
        }

        return ['total' => count($rows), 'adopted' => $adopted, 'data_status' => empty($rows) ? 'empty' : self::DATA_OK];
    }

    private function alertAccuracyStats(array $hotelIds, ?int $hotelId, int $days, array &$dataGaps): array
    {
        if (!$this->tableExists('operation_alerts')) {
            $dataGaps[] = ['code' => 'operation_alerts_missing', 'message' => '运营预警表不存在'];
            return ['reviewed' => 0, 'accurate' => 0, 'data_status' => self::DATA_PENDING];
        }

        $start = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days'));
        $end = date('Y-m-d');
        try {
            $query = Db::name('operation_alerts')
                ->field('raw_data')
                ->whereNull('deleted_at')
                ->whereBetween('related_date', [$start, $end]);
            if ($hotelId !== null && $hotelId > 0) {
                $query->where('hotel_id', $hotelId);
            } elseif (!empty($hotelIds)) {
                $query->whereIn('hotel_id', $hotelIds);
            }
            $rows = $query->select()->toArray();
        } catch (Throwable $e) {
            $dataGaps[] = ['code' => 'operation_alerts_read_failed', 'message' => '预警准确率统计读取失败'];
            return ['reviewed' => 0, 'accurate' => 0, 'data_status' => 'read_failed'];
        }

        $reviewed = 0;
        $accurate = 0;
        foreach ($rows as $row) {
            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $label = $this->alertAccuracyLabel($raw);
            if ($label === null) {
                continue;
            }
            $reviewed++;
            if ($label) {
                $accurate++;
            }
        }

        if (empty($rows)) {
            $dataGaps[] = ['code' => 'operation_alerts_no_samples', 'message' => '近30天没有预警样本'];
        } elseif ($reviewed === 0) {
            $dataGaps[] = ['code' => 'operation_alerts_accuracy_label_missing', 'message' => '预警缺少准确/误报复盘标签'];
        }

        return ['reviewed' => $reviewed, 'accurate' => $accurate, 'data_status' => $reviewed > 0 ? self::DATA_OK : 'unlabeled'];
    }

    private function alertAccuracyLabel(array $raw): ?bool
    {
        if (array_key_exists('is_accurate', $raw) && is_bool($raw['is_accurate'])) {
            return $raw['is_accurate'];
        }

        foreach (['accuracy_status', 'review_status', 'accuracy', 'verification_result'] as $key) {
            $value = strtolower(trim((string)($raw[$key] ?? '')));
            if ($value === '') {
                continue;
            }
            if (in_array($value, ['accurate', 'hit', 'true_positive', 'valid', '准确', '命中'], true)) {
                return true;
            }
            if (in_array($value, ['false_positive', 'false_alarm', 'invalid', 'inaccurate', '误报', '不准确'], true)) {
                return false;
            }
        }

        return null;
    }

    private function buildSummary(array $hotelIds, ?int $hotelId, string $date): array
    {
        return $this->buildSummaryFromRows(
            $this->dailyReportRows($hotelIds, $date, $date),
            $this->onlineRows($hotelIds, $date, $date),
            $hotelIds,
            $hotelId,
            $date
        );
    }

    private function buildSummaryFromRows(array $daily, array $online, array $hotelIds, ?int $hotelId, string $date): array
    {
        $base = [
            'hotel_id' => $hotelId ?: ($hotelIds[0] ?? null),
            'date' => $date,
            'revenue' => null,
            'orders' => null,
            'room_nights' => null,
            'adr' => null,
            'occ' => null,
            'revpar' => null,
            'data_status' => 'missing',
            'source_status' => 'missing',
            'source_scope' => 'unknown',
            'metric_scopes' => [
                'revenue' => [],
                'orders' => [],
                'room_nights' => [],
            ],
            'data_gaps' => [
                ['code' => 'operation_revenue_missing', 'message' => '经营收入字段未返回'],
                ['code' => 'operation_orders_missing', 'message' => '订单字段未返回'],
                ['code' => 'operation_room_nights_missing', 'message' => '间夜字段未返回'],
            ],
            'optional_data_gaps' => [],
            'evidence_refs' => [],
        ];

        $canonicalOnlineFacts = $this->canonicalOnlineOperatingFacts($online);
        if (empty($daily) && empty($canonicalOnlineFacts)) {
            return $base;
        }

        $totals = ['revenue' => 0.0, 'orders' => 0.0, 'room_nights' => 0.0];
        $metricPresent = ['revenue' => false, 'orders' => false, 'room_nights' => false];
        $metricScopes = ['revenue' => [], 'orders' => [], 'room_nights' => []];
        $dailyRevenueCoverage = [];
        $dailyRoomNightCoverage = [];
        $roomCount = 0.0;
        $roomCountPresent = false;
        $sourceKinds = [];
        $sourceMissing = false;

        foreach ($daily as $row) {
            $reportData = $this->decodeJson((string)($row['report_data'] ?? ''));
            $dailyMetricKeys = [];
            if ($this->dailyRevenueIsPresent($row, $reportData)) {
                $totals['revenue'] += $this->extractRevenue($row, $reportData);
                $metricPresent['revenue'] = true;
                $metricScopes['revenue']['whole_hotel_daily_report'] = true;
                $this->markDailyMetricCoverage($dailyRevenueCoverage, $row);
                $dailyMetricKeys[] = 'revenue';
            }
            if ($this->dailyRoomNightsArePresent($reportData)) {
                $totals['room_nights'] += $this->extractRoomNights($row, $reportData);
                $metricPresent['room_nights'] = true;
                $metricScopes['room_nights']['whole_hotel_daily_report'] = true;
                $this->markDailyMetricCoverage($dailyRoomNightCoverage, $row);
                $dailyMetricKeys[] = 'room_nights';
            }
            $dailyOrders = $this->extractDailyOrders($row, $reportData);
            if ($dailyOrders !== null) {
                $totals['orders'] += $dailyOrders;
                $metricPresent['orders'] = true;
                $metricScopes['orders']['whole_hotel_daily_report'] = true;
                $dailyMetricKeys[] = 'orders';
            }
            $rowRoomCount = $this->extractSalableRoomCount($row, $reportData);
            if ($rowRoomCount > 0) {
                $roomCount += $rowRoomCount;
                $roomCountPresent = true;
                $dailyMetricKeys[] = 'available_rooms';
            }
            if ($this->numericMetricValue($row['occupancy_rate'] ?? null) !== null) {
                $base['occ'] = max((float)($base['occ'] ?? 0), (float)$row['occupancy_rate']);
                $dailyMetricKeys[] = 'occupancy_rate';
            }
            $sourceKinds['daily_reports'] = true;
            $base['evidence_refs'][] = [
                'source_ref' => 'daily_reports#' . (int)($row['id'] ?? 0),
                'source_record_id' => (int)($row['id'] ?? 0),
                'source' => 'daily_reports',
                'platform' => '',
                'data_date' => (string)($row['report_date'] ?? $date),
                'data_type' => 'whole_hotel_daily_report',
                'validation_status' => (string)($row['validation_status'] ?? 'recorded'),
                'ingestion_method' => (string)($row['ingestion_method'] ?? 'daily_report'),
                'updated_at' => (string)($row['update_time'] ?? $row['create_time'] ?? ''),
                'metric_keys' => array_values(array_unique($dailyMetricKeys)),
            ];
        }

        foreach ($canonicalOnlineFacts as $item) {
            $row = is_array($item['source_row'] ?? null) ? $item['source_row'] : [];
            $fact = is_array($item['fact'] ?? null) ? $item['fact'] : [];
            $onlineMetricKeys = [];
            $fieldFactMetricKeys = [];
            $onlineOrders = $this->numericMetricValue($fact['order_count'] ?? null);
            if ((string)($fact['metric_semantic_scope'] ?? '') === 'ota_daily_generic') {
                $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
                $rawOrders = $this->firstNumericMetric($raw, ['bookOrderNum', 'book_order_num', 'orders']);
                if ($rawOrders !== null) {
                    $onlineOrders = $onlineOrders === null ? $rawOrders : max($onlineOrders, $rawOrders);
                }
            }
            if ($onlineOrders !== null) {
                $totals['orders'] += $onlineOrders;
                $metricPresent['orders'] = true;
                $metricScopes['orders']['ota_channel'] = true;
                $onlineMetricKeys[] = 'book_order_num';
                $fieldFactMetricKeys[] = 'order_count';
            }
            if (!$this->hasDailyMetricForOnlineRow($dailyRevenueCoverage, $row)
                && ($onlineRevenue = $this->numericMetricValue($fact['revenue'] ?? null)) !== null) {
                $totals['revenue'] += $onlineRevenue;
                $metricPresent['revenue'] = true;
                $metricScopes['revenue']['ota_channel'] = true;
                $onlineMetricKeys[] = 'amount';
                $fieldFactMetricKeys[] = 'order_amount';
            }
            if (!$this->hasDailyMetricForOnlineRow($dailyRoomNightCoverage, $row)
                && ($onlineRoomNights = $this->numericMetricValue($fact['room_nights'] ?? null)) !== null) {
                $totals['room_nights'] += $onlineRoomNights;
                $metricPresent['room_nights'] = true;
                $metricScopes['room_nights']['ota_channel'] = true;
                $onlineMetricKeys[] = 'quantity';
                $fieldFactMetricKeys[] = 'room_nights';
            }
            if ($onlineMetricKeys === []) {
                continue;
            }
            $source = $this->normalizeOtaChannel((string)($row['source'] ?? ''));
            $platform = $this->normalizeOtaChannel((string)($row['platform'] ?? ''));
            if ($source === '' && $platform === '') {
                $sourceMissing = true;
            } else {
                $sourceKinds['ota_channel'] = true;
            }
            $base['evidence_refs'][] = [
                'source_ref' => 'online_daily_data#' . (int)($row['id'] ?? 0),
                'source_record_id' => (int)($row['id'] ?? 0),
                'source' => $source,
                'platform' => $platform,
                'data_date' => (string)($row['data_date'] ?? ''),
                'data_type' => (string)($row['data_type'] ?? ''),
                'validation_status' => (string)($row['validation_status'] ?? ''),
                'ingestion_method' => (string)($row['ingestion_method'] ?? ''),
                'data_period' => (string)($row['data_period'] ?? ''),
                'is_final' => array_key_exists('is_final', $row) ? (int)$row['is_final'] : null,
                'snapshot_time' => (string)($row['snapshot_time'] ?? ''),
                'updated_at' => (string)($row['update_time'] ?? ''),
                'metric_keys' => array_values(array_unique($onlineMetricKeys)),
                'field_fact_metric_keys' => array_values(array_unique($fieldFactMetricKeys)),
                'calculation_basis' => (string)($fact['calculation_basis'] ?? 'ota_daily_standard_fact'),
                'metric_semantic_scope' => (string)($fact['metric_semantic_scope'] ?? ''),
            ];
        }

        $base['revenue'] = $metricPresent['revenue'] ? round($totals['revenue'], 2) : null;
        $base['orders'] = $metricPresent['orders'] ? (int)round($totals['orders']) : null;
        $base['room_nights'] = $metricPresent['room_nights'] ? round($totals['room_nights'], 2) : null;
        $base['adr'] = $metricPresent['revenue'] && $metricPresent['room_nights'] && $base['room_nights'] > 0
            ? round((float)$base['revenue'] / (float)$base['room_nights'], 2)
            : null;
        if ($base['occ'] === null && $roomCountPresent && $metricPresent['room_nights']) {
            $base['occ'] = round(((float)$base['room_nights'] / $roomCount) * 100, 2);
        }
        $base['revpar'] = $roomCountPresent && $metricPresent['revenue']
            ? round((float)$base['revenue'] / $roomCount, 2)
            : null;

        $dataGaps = [];
        foreach ([
            'revenue' => ['operation_revenue_missing', '经营收入字段未返回'],
            'orders' => ['operation_orders_missing', '订单字段未返回'],
            'room_nights' => ['operation_room_nights_missing', '间夜字段未返回'],
        ] as $metric => [$code, $message]) {
            if (!$metricPresent[$metric]) {
                $dataGaps[] = ['code' => $code, 'message' => $message];
            }
        }
        if ($sourceMissing) {
            $dataGaps[] = ['code' => 'operation_source_missing', 'message' => '存在未标明 OTA 渠道来源的经营记录'];
        }
        if ($base['adr'] === null) {
            $base['optional_data_gaps'][] = ['code' => 'operation_adr_not_calculable', 'message' => '收入或间夜缺失，或间夜为0，ADR不可计算'];
        }
        if ($base['occ'] === null) {
            $base['optional_data_gaps'][] = ['code' => 'operation_occ_not_calculable', 'message' => '入住率或可售房量未返回，OCC不可计算'];
        }
        if ($base['revpar'] === null) {
            $base['optional_data_gaps'][] = ['code' => 'operation_revpar_not_calculable', 'message' => '收入或可售房量未返回，RevPAR不可计算'];
        }

        $base['metric_scopes'] = array_map(static fn(array $scopes): array => array_keys($scopes), $metricScopes);
        $base['source_scope'] = isset($sourceKinds['daily_reports'], $sourceKinds['ota_channel'])
            ? 'mixed_whole_hotel_and_ota_channel'
            : (isset($sourceKinds['daily_reports'])
                ? 'whole_hotel_daily_report'
                : (isset($sourceKinds['ota_channel']) ? 'ota_channel' : 'unknown'));
        $base['source_status'] = $sourceMissing ? 'partial' : 'clear';
        $base['data_gaps'] = $dataGaps;
        $base['data_status'] = $dataGaps === [] ? self::DATA_OK : 'partial';

        return $base;
    }

    private function buildOta(array $hotelIds, string $date): array
    {
        return $this->buildOtaFromRows($this->onlineRows($hotelIds, $date, $date));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildOtaFromRows(array $rows): array
    {
        $base = [
            'exposure' => null,
            'visitors' => null,
            'views' => null,
            'orders' => null,
            'view_rate' => null,
            'order_rate' => null,
            'order_filling' => null,
            'order_submit' => null,
            'flow_rate' => null,
            'fill_submit_rate' => null,
            'data_status' => self::DATA_PENDING,
            'funnel_status' => 'missing',
            'missing_metrics' => ['exposure', 'visitors'],
            'source_scope' => 'ota_channel',
            'evidence_refs' => [],
        ];

        $rows = $this->latestOnlineFlowRows($rows);
        if (empty($rows)) {
            return $base;
        }

        foreach ($rows as $row) {
            $metrics = $this->onlineFlowMetrics($row);
            if ($this->onlineRowHasNumericMetric($row, ['list_exposure', 'exposure', 'show_num', 'showNum', 'impression'])) {
                $base['exposure'] = (int)($base['exposure'] ?? 0) + (int)$metrics['exposure'];
            }
            if ($this->onlineRowHasNumericMetric($row, ['visitors', 'visitor_num', 'visitorNum', 'qunarDetailVisitors', 'detail_exposure'])) {
                $base['visitors'] = (int)($base['visitors'] ?? 0) + (int)$metrics['visitors'];
            }
            if ($this->onlineRowHasNumericMetric($row, ['detail_exposure', 'views', 'total_detail_num', 'totalDetailNum', 'detailVisitors'])) {
                $base['views'] = (int)($base['views'] ?? 0) + (int)$metrics['views'];
            }
            if ($this->onlineRowHasNumericMetric($row, ['order_submit_num', 'book_order_num', 'bookOrderNum', 'orders'])) {
                $base['orders'] = (int)($base['orders'] ?? 0) + (int)$metrics['orders'];
                $base['order_submit'] = (int)($base['order_submit'] ?? 0) + (int)$metrics['orders'];
            }
            if ($this->onlineRowHasNumericMetric($row, ['order_filling_num', 'orderFillingNum', 'order_page_visitor'])) {
                $base['order_filling'] = (int)($base['order_filling'] ?? 0) + (int)$metrics['order_filling'];
            }
            $base['evidence_refs'][] = [
                'source_ref' => 'online_daily_data#' . (int)($row['id'] ?? 0),
                'source_record_id' => (int)($row['id'] ?? 0),
                'source' => strtolower(trim((string)($row['source'] ?? ''))),
                'platform' => strtolower(trim((string)($row['platform'] ?? ''))),
                'endpoint_id' => $this->onlineEndpointIdFromDimension((string)($row['dimension'] ?? '')),
                'data_date' => (string)($row['data_date'] ?? ''),
                'validation_status' => (string)($row['validation_status'] ?? ''),
                'ingestion_method' => (string)($row['ingestion_method'] ?? ''),
                'data_period' => (string)($row['data_period'] ?? ''),
                'is_final' => array_key_exists('is_final', $row) ? (int)$row['is_final'] : null,
                'snapshot_time' => (string)($row['snapshot_time'] ?? ''),
                'updated_at' => (string)($row['update_time'] ?? ''),
                'metric_keys' => ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'],
                'reported_flow_rate' => $metrics['reported_flow_rate'],
            ];
        }

        $base['view_rate'] = $base['exposure'] !== null && $base['exposure'] > 0 && $base['views'] !== null
            ? round($base['views'] / $base['exposure'] * 100, 2)
            : null;
        $base['order_rate'] = $base['visitors'] !== null && $base['visitors'] > 0 && $base['orders'] !== null
            ? round($base['orders'] / $base['visitors'] * 100, 2)
            : null;
        $base['flow_rate'] = $base['view_rate'];
        $base['fill_submit_rate'] = (int)($base['order_filling'] ?? 0) > 0
            ? round((int)($base['order_submit'] ?? 0) / (int)$base['order_filling'] * 100, 2)
            : null;
        $base['missing_metrics'] = array_values(array_filter([
            $base['exposure'] === null ? 'exposure' : null,
            $base['visitors'] === null ? 'visitors' : null,
        ]));
        $base['data_status'] = $base['exposure'] !== null && ($base['visitors'] !== null || $base['views'] !== null)
            ? self::DATA_OK
            : 'partial';
        $base['funnel_status'] = $base['data_status'] === self::DATA_OK ? 'available' : 'missing';

        return $base;
    }

    /**
     * Resolve operating values through the shared OTA standard ETL. This keeps
     * Ctrip checkout versus booking fields and Meituan business-card versus
     * order-list aggregates as alternative evidence families, never additive
     * or "latest non-empty row wins" values.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{fact:array<string,mixed>,source_row:array<string,mixed>}>
     */
    private function canonicalOnlineOperatingFacts(array $rows): array
    {
        $candidates = array_values(array_filter($rows, function (mixed $row): bool {
            if (!is_array($row)) {
                return false;
            }
            $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
            if ($dataType !== '' && !in_array($dataType, [
                'business',
                'business_overview',
                'overview',
                'operation',
                'order',
                'orders',
            ], true)) {
                return false;
            }
            return $this->isTrustedSelfOtaFactRow($row)
                || $this->isMetricScopedPartialOtaCandidate($row);
        }));
        if ($candidates === []) {
            return [];
        }

        $rowsById = [];
        foreach ($candidates as $row) {
            $rowId = (int)($row['id'] ?? 0);
            if ($rowId > 0) {
                $rowsById[$rowId] = $row;
            }
        }
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($candidates);
        $result = [];
        foreach ((array)($dataset['fact_ota_daily'] ?? []) as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $trace = is_array($fact['source_trace'] ?? null) ? $fact['source_trace'] : [];
            $rowId = (int)($trace['row_id'] ?? 0);
            $sourceRow = $rowsById[$rowId] ?? null;
            if (!is_array($sourceRow)) {
                continue;
            }
            $fieldFactMetricKeys = $this->operatingFactFieldMetricKeys($fact);
            if ($fieldFactMetricKeys === []) {
                continue;
            }
            if (!$this->isTrustedSelfOtaFactRow($sourceRow)
                && !$this->metricScopedFieldFactsReady($sourceRow, $fieldFactMetricKeys)
            ) {
                continue;
            }
            $result[] = ['fact' => $fact, 'source_row' => $sourceRow];
        }

        usort($result, static function (array $left, array $right): int {
            $leftFact = is_array($left['fact'] ?? null) ? $left['fact'] : [];
            $rightFact = is_array($right['fact'] ?? null) ? $right['fact'] : [];
            return [
                (string)($leftFact['date_key'] ?? ''),
                (string)($leftFact['platform_key'] ?? ''),
                (int)($leftFact['source_trace']['row_id'] ?? 0),
            ] <=> [
                (string)($rightFact['date_key'] ?? ''),
                (string)($rightFact['platform_key'] ?? ''),
                (int)($rightFact['source_trace']['row_id'] ?? 0),
            ];
        });
        return $result;
    }

    /** @param array<string, mixed> $row */
    private function isMetricScopedPartialOtaCandidate(array $row): bool
    {
        if (OnlineDataTrustStatusService::classifyValidationStatus($row['validation_status'] ?? '') !== 'partial'
            || trim((string)($row['source_trace_id'] ?? '')) === ''
        ) {
            return false;
        }
        $trustedProbe = $row;
        $trustedProbe['validation_status'] = 'normal';
        return $this->isTrustedSelfOtaFactRow($trustedProbe);
    }

    /**
     * @param array<string, mixed> $fact
     * @return array<int, string>
     */
    private function operatingFactFieldMetricKeys(array $fact): array
    {
        $keys = [];
        if ($this->numericMetricValue($fact['revenue'] ?? null) !== null) {
            $keys[] = 'order_amount';
        }
        if ($this->numericMetricValue($fact['room_nights'] ?? null) !== null) {
            $keys[] = 'room_nights';
        }
        if ($this->numericMetricValue($fact['order_count'] ?? null) !== null) {
            $keys[] = 'order_count';
        }
        return array_values(array_unique($keys));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $metricKeys
     */
    private function metricScopedFieldFactsReady(array $row, array $metricKeys): bool
    {
        $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
        $status = OnlineDataFieldFactService::buildMetricStatus($row, $raw, $metricKeys);
        return (string)($status['status'] ?? '') === 'ready'
            && (array)($status['missing_requested_metric_keys'] ?? []) === [];
    }

    /**
     * A traffic collection can persist several field rows for the same snapshot.
     * Keep only the latest verified snapshot for each hotel/channel/date so one
     * platform response is never counted multiple times.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function latestOnlineFlowRows(array $rows): array
    {
        $selected = [];
        foreach ($rows as $row) {
            if (!$this->isTrustedSelfOtaFactRow($row)) {
                continue;
            }
            $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
            if ($dataType !== '' && !in_array($dataType, ['traffic', 'flow', 'traffic_flow', 'traffic_overview'], true)) {
                continue;
            }
            $endpointId = $this->onlineEndpointIdFromDimension((string)($row['dimension'] ?? ''));
            if ($endpointId !== '' && !in_array($endpointId, ['business_flow_transform', 'traffic_flow_transform'], true)) {
                continue;
            }
            if (!$this->hasOnlineFlowEvidence($row)) {
                continue;
            }
            if ($endpointId === '') {
                $metrics = $this->onlineFlowMetrics($row);
                if ((float)$metrics['exposure'] <= 0
                    && (float)$metrics['visitors'] <= 0
                    && (float)$metrics['views'] <= 0
                    && (float)$metrics['order_filling'] <= 0
                    && (float)$metrics['orders'] <= 0
                ) {
                    continue;
                }
            }
            $date = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $hotelId = (string)($row['system_hotel_id'] ?? $row['hotel_id'] ?? '');
            $source = strtolower(trim((string)($row['source'] ?? ''))) ?: 'unknown';
            $platform = $this->normalizeOtaChannel((string)($row['platform'] ?? ''));
            $key = $hotelId . '|' . $source . '|' . $platform . '|' . $date;
            $current = $selected[$key] ?? null;
            $rowRank = $this->onlineFlowRowRank($row);
            $currentRank = is_array($current) ? $this->onlineFlowRowRank($current) : -1;
            if ($current === null
                || $rowRank > $currentRank
                || ($rowRank === $currentRank && $this->onlineRowTimestamp($row) > $this->onlineRowTimestamp($current))
                || ($rowRank === $currentRank
                    && $this->onlineRowTimestamp($row) === $this->onlineRowTimestamp($current)
                    && (int)($row['id'] ?? 0) > (int)($current['id'] ?? 0))) {
                $selected[$key] = $row;
            }
        }
        return array_values($selected);
    }

    /** @param array<string, mixed> $row */
    private function hasTrustedOnlineValidationStatus(array $row): bool
    {
        $status = strtolower(trim((string)($row['validation_status'] ?? '')));
        return in_array($status, [
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
        ], true);
    }

    /** @param array<string, mixed> $row */
    private function hasTrustedOnlineIngestionMethod(array $row): bool
    {
        $rawValue = $row['raw_data'] ?? [];
        $raw = is_array($rawValue) ? $rawValue : $this->decodeJson((string)$rawValue);
        foreach ([
            $row['ingestion_method'] ?? null,
            $row['source_method'] ?? null,
            $raw['ingestion_method'] ?? null,
            $raw['_ingestion_method'] ?? null,
            $raw['source_method'] ?? null,
        ] as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $method = strtolower(trim((string)$value));
            if ($method === '') {
                continue;
            }
            return !in_array($method, [
                'legacy',
                'manual',
                'manual_import',
                'manual_override',
                'user_provided',
                'user_provided_unverified',
                'import_csv',
                'import_json',
            ], true);
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private function hasTrustedOnlineCollectionTimestamp(array $row): bool
    {
        return $this->trustedOnlineCollectionTimestamp($row) > 0;
    }

    /** @param array<string, mixed> $row */
    private function trustedOnlineCollectionTimestamp(array $row): int
    {
        $rawValue = $row['raw_data'] ?? [];
        $raw = is_array($rawValue) ? $rawValue : $this->decodeJson((string)$rawValue);
        $meta = is_array($raw['meta'] ?? null) ? $raw['meta'] : [];
        $capture = is_array($raw['capture_evidence'] ?? null) ? $raw['capture_evidence'] : [];
        $timestamps = [];
        foreach ([
            $row['collected_at'] ?? null,
            $row['snapshot_time'] ?? null,
            $row['received_at'] ?? null,
            $raw['collected_at'] ?? null,
            $raw['collectedAt'] ?? null,
            $raw['captured_at'] ?? null,
            $raw['capturedAt'] ?? null,
            $raw['fetched_at'] ?? null,
            $raw['fetch_time'] ?? null,
            $meta['collected_at'] ?? null,
            $meta['captured_at'] ?? null,
            $capture['collected_at'] ?? null,
            $capture['captured_at'] ?? null,
        ] as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim((string)$value);
            if (preg_match(
                '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/D',
                $text
            ) !== 1) {
                continue;
            }
            $timestamp = strtotime($text);
            if ($timestamp !== false && $timestamp > 0) {
                $timestamps[] = $timestamp;
            }
        }

        return $timestamps === [] ? 0 : min($timestamps);
    }

    /** @param array<string, mixed> $row */
    private function hasNoBlockingOnlineRowState(array $row): bool
    {
        foreach (['status', 'save_status'] as $field) {
            $status = strtolower(trim((string)($row[$field] ?? '')));
            if (in_array($status, [
                'failed',
                'fail',
                'error',
                'collection_failed',
                'capture_failed',
                'permission_denied',
                'binding_missing',
                'blocked',
                'rejected',
                'login_required',
                'unverified',
                'stale',
                'warning',
                'partial',
                'partial_success',
            ], true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function isTrustedSelfOtaFactRow(array $row): bool
    {
        if (!$this->hasTrustedOtaEvidenceEnvelope($row)) {
            return false;
        }

        $compareType = strtolower(trim((string)($row['compare_type'] ?? '')));
        if ($compareType !== '' && $compareType !== 'self') {
            return false;
        }

        if (array_key_exists('hotel_id', $row)) {
            $otaHotelId = trim((string)$row['hotel_id']);
            if ($otaHotelId !== '' && is_numeric($otaHotelId) && (float)$otaHotelId <= 0) {
                return false;
            }
        }

        $source = $this->normalizeOtaChannel((string)($row['source'] ?? ''));
        $platform = $this->normalizeOtaChannel((string)($row['platform'] ?? ''));
        $knownChannels = ['ctrip', 'meituan', 'qunar'];
        if (($source !== '' && !in_array($source, $knownChannels, true))
            || ($platform !== '' && !in_array($platform, $knownChannels, true))
            || ($source === '' && $platform === '')
            || ($source !== '' && $platform !== '' && $source !== $platform)
        ) {
            return false;
        }

        $dataDate = trim((string)($row['data_date'] ?? ''));
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dataDate);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $dataDate) {
            return false;
        }

        $today = date('Y-m-d');
        if ($dataDate > $today) {
            return false;
        }
        $period = strtolower(trim((string)($row['data_period'] ?? '')));
        if ($period === 'next_30_days') {
            return false;
        }
        if ($dataDate === $today && $period !== '' && $period !== 'realtime_snapshot') {
            return false;
        }
        if ($dataDate < $today && $period === 'realtime_snapshot') {
            return false;
        }
        if ($dataDate === $today && array_key_exists('is_final', $row) && (int)$row['is_final'] === 1) {
            return false;
        }
        if ($dataDate < $today
            && $period === 'historical_daily'
            && array_key_exists('is_final', $row)
            && (int)$row['is_final'] !== 1
        ) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function hasTrustedOtaEvidenceEnvelope(array $row): bool
    {
        return $this->hasTrustedOnlineValidationStatus($row)
            && $this->hasNoBlockingOnlineRowState($row)
            && (int)($row['system_hotel_id'] ?? 0) > 0
            && (int)($row['data_source_id'] ?? 0) > 0
            && (int)($row['readback_verified'] ?? 0) === 1
            && $this->hasTrustedOnlineIngestionMethod($row)
            && $this->hasTrustedOnlineCollectionTimestamp($row);
    }

    private function normalizeOtaChannel(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            '携程', 'trip', 'trip.com', 'ebooking' => 'ctrip',
            '美团', 'meituan hotel' => 'meituan',
            '去哪儿', 'qunar.com' => 'qunar',
            default => $value,
        };
    }

    /** @param array<string, mixed> $summary */
    private function operatingSnapshotChannel(array $summary): string
    {
        $channels = [];
        foreach ((array)($summary['evidence_refs'] ?? []) as $evidenceRef) {
            if (!is_array($evidenceRef)) {
                continue;
            }
            $source = trim((string)($evidenceRef['source'] ?? ''));
            $platform = trim((string)($evidenceRef['platform'] ?? ''));
            $channel = $this->normalizeOtaChannel($source !== '' ? $source : $platform);
            if (in_array($channel, ['ctrip', 'meituan', 'qunar'], true)) {
                $channels[] = $channel;
            }
        }
        $channels = array_values(array_unique($channels));

        return count($channels) === 1 ? $channels[0] : '';
    }

    private function otaChannelLabel(string $channel): string
    {
        return match ($channel) {
            'ctrip' => '携程',
            'meituan' => '美团',
            'qunar' => '去哪儿',
            default => 'OTA',
        };
    }

    /** @param array<string, mixed> $row */
    private function hasOnlineFlowEvidence(array $row): bool
    {
        $keys = [
            'list_exposure', 'exposure', 'show_num', 'showNum', 'impression',
            'detail_exposure', 'visitors', 'visitor_num', 'visitorNum', 'qunarDetailVisitors',
            'views', 'total_detail_num', 'totalDetailNum', 'detailVisitors',
            'order_filling_num', 'orderFillingNum', 'order_page_visitor',
            'order_submit_num', 'book_order_num', 'bookOrderNum', 'orders',
        ];
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '' && is_numeric($row[$key])) {
                return true;
            }
        }
        $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
        foreach ($keys as $key) {
            if (array_key_exists($key, $raw) && $raw[$key] !== null && $raw[$key] !== '' && is_numeric($raw[$key])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $row @param array<int, string> $keys */
    private function onlineRowHasNumericMetric(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '' && is_numeric($row[$key])) {
                return true;
            }
        }
        $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
        foreach ($keys as $key) {
            if (array_key_exists($key, $raw) && $raw[$key] !== null && $raw[$key] !== '' && is_numeric($raw[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{exposure: float, visitors: float, views: float, order_filling: float, orders: float, reported_flow_rate: ?float}
     */
    private function onlineFlowMetrics(array $row): array
    {
        $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
        $metric = function (array $keys) use ($row, $raw): float {
            $value = $this->firstNumericValue($row, $keys);
            if ($value === null) {
                $value = $this->firstNumericValue($raw, $keys, 0);
            }
            return max(0.0, (float)($value ?? 0));
        };
        $exposure = $metric(['list_exposure', 'exposure', 'show_num', 'showNum', 'impression']);
        $views = $metric(['detail_exposure', 'views', 'total_detail_num', 'totalDetailNum', 'detailVisitors']);
        $visitors = $metric(['visitors', 'visitor_num', 'visitorNum', 'qunarDetailVisitors', 'detail_exposure']);
        if ($views <= 0 && $visitors > 0) {
            $views = $visitors;
        }
        if ($visitors <= 0 && $views > 0) {
            $visitors = $views;
        }
        return [
            'exposure' => $exposure,
            'visitors' => $visitors,
            'views' => $views,
            'order_filling' => $metric(['order_filling_num', 'orderFillingNum', 'order_page_visitor']),
            'orders' => $metric(['order_submit_num', 'book_order_num', 'bookOrderNum', 'orders']),
            'reported_flow_rate' => $this->firstNumericValue(
                $row,
                ['flow_rate', 'flowRate'],
                $this->firstNumericValue($raw, ['flow_rate', 'flowRate'])
            ),
        ];
    }

    private function onlineEndpointIdFromDimension(string $dimension): string
    {
        if (preg_match('/^catalog:[^:]+:([^:]+)/', trim($dimension), $matches)) {
            return (string)($matches[1] ?? '');
        }
        return '';
    }

    /** @param array<string, mixed> $row */
    private function onlineFlowRowRank(array $row): int
    {
        $metrics = $this->onlineFlowMetrics($row);
        $rank = 0;
        if ($metrics['exposure'] > 0) {
            $rank += 40;
        }
        if ($metrics['views'] > 0 || $metrics['visitors'] > 0) {
            $rank += 30;
        }
        if ($metrics['orders'] > 0) {
            $rank += 20;
        }
        if ($metrics['exposure'] > 0 && $metrics['views'] > 0 && $metrics['orders'] > 0) {
            $rank += 100;
        }
        if (str_contains(strtolower((string)($row['dimension'] ?? '')), 'flow_transform')) {
            $rank += 10;
        }
        return $rank;
    }

    /** @param array<string, mixed> $row */
    private function onlineRowTimestamp(array $row): int
    {
        foreach (['update_time', 'create_time'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }
        return 0;
    }

    private function buildCompetitors(array $hotelIds, string $date, array $summary): array
    {
        $base = [
            'avg_price' => null,
            'avg_our_public_price' => null,
            'avg_score' => null,
            'price_gap' => null,
            'price_gap_percent' => null,
            'score_gap' => null,
            'rank_position' => null,
            'data_status' => self::DATA_PENDING,
            'comparability_status' => 'insufficient_evidence',
            'comparison_scope' => 'ota_public_rate_to_ota_public_rate',
            'comparison_key' => '',
            'visible_row_count' => 0,
            'decision_eligible_row_count' => 0,
            'excluded_from_decision_count' => 0,
            'quality_gaps' => [],
            'meituan_rank_summary' => $this->buildMeituanRankSummary($hotelIds, $date),
        ];

        if ($this->tableExists('competitor_analysis')) {
            try {
                $rows = Db::name('competitor_analysis')
                    ->whereIn('hotel_id', $hotelIds)
                    ->where('analysis_date', $date)
                    ->field('id,hotel_id,competitor_hotel_id,room_type_id,competitor_room_type_id,analysis_date,our_price,competitor_price,price_difference,price_index,ota_platform,competitor_data,create_time,update_time')
                    ->select()
                    ->toArray();
                if (!empty($rows)) {
                    $base['visible_row_count'] = count($rows);
                    $groups = [];
                    $gapCounts = [];
                    foreach ($rows as $row) {
                        $assessment = $this->competitorAnalysisComparability($row);
                        if (($assessment['eligible'] ?? false) !== true) {
                            foreach (($assessment['reasons'] ?? []) as $reason) {
                                $gapCounts[$reason] = ($gapCounts[$reason] ?? 0) + 1;
                            }
                            continue;
                        }
                        $key = (string)$assessment['comparison_key'];
                        $groups[$key]['our_prices'][] = (float)$row['our_price'];
                        $groups[$key]['competitor_prices'][] = (float)$row['competitor_price'];
                        $groups[$key]['latest'] = max(
                            (string)($groups[$key]['latest'] ?? ''),
                            (string)($assessment['captured_at'] ?? '')
                        );
                    }

                    $eligibleCount = array_sum(array_map(
                        static fn(array $group): int => count($group['competitor_prices'] ?? []),
                        $groups
                    ));
                    $base['decision_eligible_row_count'] = $eligibleCount;
                    $base['excluded_from_decision_count'] = max(0, count($rows) - $eligibleCount);
                    $base['quality_gaps'] = array_map(
                        static fn(string $code, int $count): array => ['code' => $code, 'row_count' => $count],
                        array_keys($gapCounts),
                        array_values($gapCounts)
                    );

                    if ($groups === []) {
                        $base['data_status'] = 'data_gap';
                        return $base;
                    }

                    uasort($groups, static function (array $left, array $right): int {
                        $countCompare = count($right['competitor_prices'] ?? []) <=> count($left['competitor_prices'] ?? []);
                        return $countCompare !== 0
                            ? $countCompare
                            : strcmp((string)($right['latest'] ?? ''), (string)($left['latest'] ?? ''));
                    });
                    $comparisonKey = (string)array_key_first($groups);
                    $group = $groups[$comparisonKey];
                    $base['avg_our_public_price'] = $this->avg($group['our_prices'] ?? []);
                    $base['avg_price'] = $this->avg($group['competitor_prices'] ?? []);
                    $base['price_gap'] = round($base['avg_our_public_price'] - $base['avg_price'], 2);
                    $base['price_gap_percent'] = $base['avg_price'] > 0
                        ? round($base['price_gap'] / $base['avg_price'] * 100, 2)
                        : null;
                    $base['comparison_key'] = $comparisonKey;
                    $base['comparability_status'] = 'eligible';
                    $base['data_status'] = self::DATA_OK;
                    return $base;
                }
            } catch (Throwable $e) {
                return $base;
            }
        }

        return $base;
    }

    /** @return array{eligible:bool,reasons:array<int,string>,comparison_key:string,captured_at:string} */
    private function competitorAnalysisComparability(array $row): array
    {
        $context = $this->arrayValue($row['competitor_data'] ?? []);
        foreach (['comparison_context', 'rate_context', 'source'] as $nestedKey) {
            $nested = $this->arrayValue($context[$nestedKey] ?? []);
            if ($nested !== []) {
                $context = array_merge($context, $nested);
            }
        }

        $context += [
            'platform' => $row['ota_platform'] ?? null,
            'captured_at' => $row['update_time'] ?? $row['create_time'] ?? '',
        ];
        $reasons = [];
        if (!is_numeric($row['our_price'] ?? null) || (float)$row['our_price'] <= 0
            || !is_numeric($row['competitor_price'] ?? null) || (float)$row['competitor_price'] <= 0
        ) {
            $reasons[] = 'public_price_missing';
        }

        $requiredStrings = [
            'platform', 'check_in_date', 'check_out_date', 'room_type_key', 'rate_plan_key',
            'breakfast', 'cancellation_policy', 'payment_mode', 'price_basis', 'currency',
            'availability', 'source_method', 'source_ref', 'captured_at', 'validation_status',
        ];
        foreach ($requiredStrings as $field) {
            if (!$this->competitorContextHasValue($context, $field)) {
                $reasons[] = $field . '_missing';
            }
        }
        if (!array_key_exists('tax_fee_included', $context)) {
            $reasons[] = 'tax_fee_included_missing';
        }
        if (!is_numeric($context['adults'] ?? null) || (int)$context['adults'] <= 0) {
            $reasons[] = 'adults_missing';
        }
        if (!is_numeric($context['children'] ?? null) || (int)$context['children'] < 0) {
            $reasons[] = 'children_missing';
        }
        if (!$this->competitorContextReadbackVerified($context['readback_verified'] ?? null)) {
            $reasons[] = 'readback_unverified';
        }
        if (!in_array(strtolower(trim((string)($context['validation_status'] ?? ''))), ['normal', 'available', 'ok', 'valid', 'verified'], true)) {
            $reasons[] = 'validation_failed';
        }
        if (!in_array(strtolower(trim((string)($context['availability'] ?? ''))), ['available', 'bookable'], true)) {
            $reasons[] = 'not_publicly_bookable';
        }

        $checkIn = trim((string)($context['check_in_date'] ?? ''));
        $checkOut = trim((string)($context['check_out_date'] ?? ''));
        if ($checkIn !== '' && $checkOut !== ''
            && (strtotime($checkIn) === false || strtotime($checkOut) === false || strtotime($checkOut) <= strtotime($checkIn))
        ) {
            $reasons[] = 'stay_date_invalid';
        }

        $keyFields = [
            'platform', 'check_in_date', 'check_out_date', 'room_type_key', 'rate_plan_key',
            'breakfast', 'cancellation_policy', 'payment_mode', 'tax_fee_included', 'price_basis',
            'currency', 'adults', 'children',
        ];
        $keyValues = [];
        foreach ($keyFields as $field) {
            $keyValues[] = strtolower(trim((string)($context[$field] ?? '')));
        }

        return [
            'eligible' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
            'comparison_key' => hash('sha256', implode('|', $keyValues)),
            'captured_at' => trim((string)($context['captured_at'] ?? '')),
        ];
    }

    private function competitorContextHasValue(array $context, string $field): bool
    {
        return array_key_exists($field, $context)
            && $context[$field] !== null
            && trim((string)$context[$field]) !== '';
    }

    private function competitorContextReadbackVerified(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'verified'], true);
    }

    private function buildMeituanRankSummary(array $hotelIds, string $date): array
    {
        $base = $this->emptyMeituanRankSummary();
        if (empty($hotelIds)) {
            $base['rank_missing_reason'] = 'hotel scope is empty';
            return $base;
        }

        $start = date('Y-m-d', strtotime($date . ' -120 days'));
        $rows = array_values(array_filter(
            $this->onlineRows($hotelIds, $start, $date),
            fn(array $row): bool => $this->isMeituanBusinessRankRow($row)
        ));
        if (empty($rows)) {
            return $base;
        }

        $latestDataDate = '';
        foreach ($rows as $row) {
            $rowDate = (string)($row['data_date'] ?? '');
            if ($rowDate !== '' && ($latestDataDate === '' || strcmp($rowDate, $latestDataDate) > 0)) {
                $latestDataDate = $rowDate;
            }
        }

        $latestDateRows = array_values(array_filter($rows, static fn(array $row): bool => (string)($row['data_date'] ?? '') === $latestDataDate));
        $latestFetchedAt = $this->maxOnlineRowFetchedAt($latestDateRows);
        $batchRows = $latestFetchedAt !== ''
            ? array_values(array_filter($latestDateRows, fn(array $row): bool => $this->onlineRowFetchedAt($row) === $latestFetchedAt))
            : $latestDateRows;
        if (empty($batchRows)) {
            $batchRows = $latestDateRows;
        }

        $base['source_evidence'] = $this->summarizeMeituanRankSourceEvidence($batchRows);
        $targetPoiId = $this->resolveMeituanTargetPoiId($hotelIds);
        $hotels = $this->buildMeituanRankHotels($batchRows, $targetPoiId);

        if (empty($hotels)) {
            $base['record_count'] = count($batchRows);
            $base['latest_data_date'] = $latestDataDate;
            $base['latest_fetched_at'] = $latestFetchedAt;
            $base['rank_missing_reason'] = 'Meituan rows exist, but no restorable hotel ranking row was found.';
            return $base;
        }

        uasort($hotels, static function (array $a, array $b): int {
            $rankA = !empty($a['rank_values']) ? min($a['rank_values']) : PHP_INT_MAX;
            $rankB = !empty($b['rank_values']) ? min($b['rank_values']) : PHP_INT_MAX;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            return strcmp((string)$a['hotel_name'], (string)$b['hotel_name']);
        });

        $rankedHotels = array_values(array_filter($hotels, static fn(array $hotel): bool => !empty($hotel['rank_values'])));
        $selfHotel = null;
        foreach ($hotels as $hotel) {
            if (!empty($hotel['is_self'])) {
                $selfHotel = $hotel;
                break;
            }
        }

        $topHotel = $rankedHotels[0] ?? null;
        $selfRank = is_array($selfHotel) && !empty($selfHotel['rank_values']) ? min($selfHotel['rank_values']) : null;
        $topRank = is_array($topHotel) && !empty($topHotel['rank_values']) ? min($topHotel['rank_values']) : null;
        $previousRank = null;
        if ($selfRank !== null) {
            foreach (array_reverse($rankedHotels) as $hotel) {
                $candidateRank = min($hotel['rank_values']);
                if ($candidateRank < $selfRank) {
                    $previousRank = $candidateRank;
                    break;
                }
            }
        }

        $tagSummary = $this->summarizeMeituanPlatformTags($hotels);
        $rankStatus = !empty($rankedHotels) ? 'ok' : 'missing';
        $rankMissingReason = '';
        if ($rankStatus === 'missing') {
            $rankMissingReason = 'Meituan ranking rows exist, but rank/ranking fields were not returned.';
        } elseif ($targetPoiId === '') {
            $rankStatus = 'self_unbound';
            $rankMissingReason = 'Meituan POI/Store ID is not bound, so self position cannot be confirmed.';
        } elseif (!is_array($selfHotel)) {
            $rankStatus = 'self_missing';
            $rankMissingReason = 'Target POI was not found in the latest Meituan ranking batch.';
        } elseif ($selfRank === null) {
            $rankStatus = 'self_rank_missing';
            $rankMissingReason = 'Self row exists, but rank/ranking field was not returned.';
        }

        $trend = $this->summarizeMeituanRankTrend(is_array($selfHotel) ? $selfHotel['rank_history'] : []);
        $base['data_status'] = self::DATA_OK;
        $base['latest_data_date'] = $latestDataDate;
        $base['latest_fetched_at'] = $latestFetchedAt;
        $base['record_count'] = count($batchRows);
        $base['sample_count'] = count($batchRows);
        $base['hotel_count'] = count($hotels);
        $base['rank_status'] = $rankStatus;
        $base['rank_missing_reason'] = $rankMissingReason;
        $base['self_position_text'] = $selfRank !== null ? ('第' . $selfRank) : '未返回';
        $base['top_hotel_name'] = is_array($topHotel) ? (string)$topHotel['hotel_name'] : '未返回';
        $base['top_rank'] = $topRank;
        $base['gap_to_previous_text'] = $selfRank !== null && $previousRank !== null
            ? ('排名差 ' . ($selfRank - $previousRank) . ' 名；平台未返回指标差额')
            : '未返回';
        $base['top1_gap_text'] = $selfRank !== null && $topRank !== null
            ? ($selfRank === $topRank ? '本店为TOP1' : ('落后TOP1 ' . ($selfRank - $topRank) . ' 名；平台未返回指标差额'))
            : '未返回';
        $base['rank_gap_metric_status'] = 'missing';
        $base['rank_trend_status'] = $trend['status'];
        $base['rank_trend_text'] = $trend['text'];
        $base['platform_tag_status'] = $tagSummary['status'];
        $base['platform_tag_text'] = $tagSummary['text'];
        $base['vip_count'] = $tagSummary['vip_count'];
        $base['tag_returned_count'] = $tagSummary['returned_count'];
        $base['returned_empty_count'] = $tagSummary['returned_empty_count'];
        $base['not_returned_count'] = $tagSummary['not_returned_count'];
        $base['target_poi_bound'] = $targetPoiId !== '';
        $previousBatchRows = $this->previousMeituanRankBatchRows($rows, $latestDataDate, $latestFetchedAt);
        $currentChangeSnapshot = $this->summarizeMeituanRankBatchSnapshot($hotels, $latestDataDate, $latestFetchedAt, count($batchRows));
        $previousChangeSnapshot = !empty($previousBatchRows)
            ? $this->summarizeMeituanRankBatchSnapshot(
                $this->buildMeituanRankHotels($previousBatchRows, $targetPoiId),
                (string)($previousBatchRows[0]['data_date'] ?? ''),
                $this->maxOnlineRowFetchedAt($previousBatchRows),
                count($previousBatchRows)
            )
            : [];
        $changeMonitor = $this->summarizeMeituanRankBatchChanges($currentChangeSnapshot, $previousChangeSnapshot);

        $base['previous_data_date'] = (string)($previousChangeSnapshot['data_date'] ?? '');
        $base['previous_fetched_at'] = (string)($previousChangeSnapshot['fetched_at'] ?? '');
        $base['change_monitor_status'] = $changeMonitor['status'];
        $base['change_missing_reason'] = $changeMonitor['missing_reason'];
        $base['change_alerts'] = $changeMonitor['alerts'];
        $base['source_ref'] = 'online_daily_data.raw_data.platformTags/platformTagStatus/rank';

        return $base;
    }

    private function emptyMeituanRankSummary(): array
    {
        return [
            'data_status' => self::DATA_PENDING,
            'source_ref' => 'online_daily_data.raw_data',
            'privacy_scope' => 'Platform hotel tags and ranking aggregates only; excludes guest privacy, order phone, room status and room-source mapping.',
            'latest_data_date' => '',
            'latest_fetched_at' => '',
            'previous_data_date' => '',
            'previous_fetched_at' => '',
            'record_count' => 0,
            'sample_count' => 0,
            'hotel_count' => 0,
            'rank_status' => 'missing',
            'rank_missing_reason' => 'No Meituan competitor ranking rows found for permitted hotels up to report date.',
            'self_position_text' => '未返回',
            'top_hotel_name' => '未返回',
            'top_rank' => null,
            'gap_to_previous_text' => '未返回',
            'top1_gap_text' => '未返回',
            'rank_gap_metric_status' => 'missing',
            'rank_trend_status' => 'missing',
            'rank_trend_text' => '平台未返回可比榜单历史',
            'platform_tag_status' => 'not_returned',
            'platform_tag_text' => '平台标签未返回，不推断VIP',
            'vip_count' => 0,
            'tag_returned_count' => 0,
            'returned_empty_count' => 0,
            'not_returned_count' => 0,
            'target_poi_bound' => false,
            'source_evidence' => [
                'status' => 'missing',
                'row_count' => 0,
                'complete_row_count' => 0,
                'source_row_ids' => [],
                'source_trace_ids' => [],
                'source_methods' => [],
                'missing_fields' => ['source_rows'],
            ],
            'change_monitor_status' => 'missing',
            'change_missing_reason' => 'No comparable previous Meituan ranking batch found.',
            'change_alerts' => [],
        ];
    }

    private function buildMeituanRankHotels(array $batchRows, string $targetPoiId): array
    {
        $hotels = [];
        foreach ($batchRows as $row) {
            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $poiId = $this->firstStringValue($raw, ['poiId', 'poi_id', 'hotelId', 'hotel_id'], (string)($row['hotel_id'] ?? ''));
            $hotelName = $this->firstStringValue($raw, ['poiName', 'poi_name', 'hotelName', 'hotel_name', 'shopName', 'name'], (string)($row['hotel_name'] ?? ''));
            if ($poiId === '' && $hotelName === '') {
                continue;
            }

            $key = $poiId !== '' ? $poiId : $hotelName;
            if (!isset($hotels[$key])) {
                $hotels[$key] = [
                    'poi_id' => $poiId,
                    'hotel_name' => $hotelName,
                    'is_self' => $targetPoiId !== '' && $poiId !== '' && $poiId === $targetPoiId,
                    'rank_values' => [],
                    'rank_history' => [],
                    'platform_tags' => [],
                    'platform_tag_status' => 'not_returned',
                    'has_vip_tag' => false,
                    'metrics' => [],
                ];
            }

            $rank = (int)($this->firstNumericValue($raw, ['rank', 'ranking', 'rankNo', 'rankIndex']) ?? 0);
            $rankType = $this->firstStringValue($raw, ['rankType', 'rank_type'], '');
            $dateRange = $this->firstStringValue($raw, ['dateRange', 'date_range'], '');
            $metricField = $this->classifyMeituanRankMetric(
                (string)($row['dimension'] ?? $raw['dimension'] ?? $raw['_dimName'] ?? ''),
                (string)($raw['aiMetricName'] ?? $raw['ai_metric_name'] ?? $raw['_aiMetricName'] ?? ''),
                $rankType
            );
            $metricValue = $this->firstNumericValue($raw, ['dataValue', 'data_value', 'value', 'metricValue'], $row['data_value'] ?? null);

            if ($rank > 0) {
                $hotels[$key]['rank_values'][] = $rank;
                $hotels[$key]['rank_history'][] = [
                    'rank' => $rank,
                    'rank_type' => $rankType,
                    'date_range' => $dateRange,
                    'metric' => $metricField,
                    'value' => $metricValue,
                ];
            }
            if ($metricField !== '' && $metricValue !== null) {
                $hotels[$key]['metrics'][$metricField] = (float)$metricValue;
            }

            $tagInfo = $this->meituanPlatformTagInfo($raw);
            $hotels[$key]['platform_tags'] = $this->mergeStringValues($hotels[$key]['platform_tags'], $tagInfo['tags']);
            if ($tagInfo['status'] !== 'not_returned') {
                $hotels[$key]['platform_tag_status'] = $tagInfo['status'];
            }
            if (!empty($tagInfo['has_vip'])) {
                $hotels[$key]['has_vip_tag'] = true;
            }
        }

        uasort($hotels, static function (array $a, array $b): int {
            $rankA = !empty($a['rank_values']) ? min($a['rank_values']) : PHP_INT_MAX;
            $rankB = !empty($b['rank_values']) ? min($b['rank_values']) : PHP_INT_MAX;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            return strcmp((string)$a['hotel_name'], (string)$b['hotel_name']);
        });

        return $hotels;
    }

    private function previousMeituanRankBatchRows(array $rows, string $latestDataDate, string $latestFetchedAt): array
    {
        $batches = [];
        foreach ($rows as $row) {
            $dataDate = (string)($row['data_date'] ?? '');
            if ($dataDate === '' || ($latestDataDate !== '' && strcmp($dataDate, $latestDataDate) > 0)) {
                continue;
            }

            $fetchedAt = $this->onlineRowFetchedAt($row);
            if ($dataDate === $latestDataDate) {
                if ($latestFetchedAt === '' || $fetchedAt === '' || strcmp($fetchedAt, $latestFetchedAt) >= 0) {
                    continue;
                }
            }

            $key = $dataDate . '|' . $fetchedAt;
            if (!isset($batches[$key])) {
                $batches[$key] = [
                    'data_date' => $dataDate,
                    'fetched_at' => $fetchedAt,
                    'rows' => [],
                ];
            }
            $batches[$key]['rows'][] = $row;
        }

        if (empty($batches)) {
            return [];
        }

        usort($batches, static function (array $a, array $b): int {
            $dateCompare = strcmp((string)$b['data_date'], (string)$a['data_date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return strcmp((string)$b['fetched_at'], (string)$a['fetched_at']);
        });

        return $batches[0]['rows'] ?? [];
    }

    private function summarizeMeituanRankBatchSnapshot(array $hotels, string $dataDate, string $fetchedAt, int $recordCount): array
    {
        $rankedHotels = array_values(array_filter($hotels, static fn(array $hotel): bool => !empty($hotel['rank_values'])));
        $selfHotel = null;
        foreach ($hotels as $hotel) {
            if (!empty($hotel['is_self'])) {
                $selfHotel = $hotel;
                break;
            }
        }

        $topHotel = $rankedHotels[0] ?? null;
        $selfRank = is_array($selfHotel) && !empty($selfHotel['rank_values']) ? min($selfHotel['rank_values']) : null;
        $topRank = is_array($topHotel) && !empty($topHotel['rank_values']) ? min($topHotel['rank_values']) : null;
        $tagSummary = $this->summarizeMeituanPlatformTags($hotels);

        return [
            'data_date' => $dataDate,
            'fetched_at' => $fetchedAt,
            'record_count' => $recordCount,
            'hotel_count' => count($hotels),
            'has_rank_evidence' => !empty($rankedHotels),
            'has_top1_evidence' => is_array($topHotel) && $topRank !== null,
            'has_self_rank_evidence' => $selfRank !== null,
            'top_hotel_name' => is_array($topHotel) ? (string)($topHotel['hotel_name'] ?? '') : '',
            'top_poi_id' => is_array($topHotel) ? (string)($topHotel['poi_id'] ?? '') : '',
            'top_rank' => $topRank,
            'self_rank' => $selfRank,
            'platform_tag_status' => $tagSummary['status'],
            'has_platform_tag_evidence' => $tagSummary['status'] !== 'not_returned',
            'vip_count' => $tagSummary['vip_count'],
            'tag_returned_count' => $tagSummary['returned_count'],
            'returned_empty_count' => $tagSummary['returned_empty_count'],
        ];
    }

    private function summarizeMeituanRankBatchChanges(array $current, array $previous): array
    {
        if (empty($previous)) {
            return [
                'status' => 'missing',
                'missing_reason' => 'No comparable previous Meituan ranking batch found.',
                'alerts' => [],
            ];
        }

        $alerts = [];
        $missingReasons = [];
        $hasComparableEvidence = false;

        $currentTopKey = (string)(($current['top_poi_id'] ?? '') ?: ($current['top_hotel_name'] ?? ''));
        $previousTopKey = (string)(($previous['top_poi_id'] ?? '') ?: ($previous['top_hotel_name'] ?? ''));
        if (($current['has_top1_evidence'] ?? false) && ($previous['has_top1_evidence'] ?? false) && $currentTopKey !== '' && $previousTopKey !== '') {
            $hasComparableEvidence = true;
            if ($currentTopKey !== $previousTopKey) {
                $alerts[] = [
                    'type' => 'top1_changed',
                    'level' => 'medium',
                    'title' => 'Meituan TOP1 changed',
                    'message' => 'Meituan competitor TOP1 changed from ' . (string)($previous['top_hotel_name'] ?? '') . ' to ' . (string)($current['top_hotel_name'] ?? '') . '.',
                    'current' => ['top_hotel_name' => $current['top_hotel_name'] ?? '', 'top_rank' => $current['top_rank'] ?? null],
                    'previous' => ['top_hotel_name' => $previous['top_hotel_name'] ?? '', 'top_rank' => $previous['top_rank'] ?? null],
                ];
            }
        } else {
            $missingReasons[] = 'TOP1 rank fields are not comparable.';
        }

        if (($current['has_self_rank_evidence'] ?? false) && ($previous['has_self_rank_evidence'] ?? false)) {
            $hasComparableEvidence = true;
            $currentRank = (int)($current['self_rank'] ?? 0);
            $previousRank = (int)($previous['self_rank'] ?? 0);
            if ($currentRank > 0 && $previousRank > 0 && $currentRank !== $previousRank) {
                $direction = $currentRank < $previousRank ? 'up' : 'down';
                $delta = abs($currentRank - $previousRank);
                $alerts[] = [
                    'type' => 'self_rank_changed',
                    'level' => $direction === 'down' ? 'medium' : 'low',
                    'title' => 'Meituan self rank changed',
                    'message' => 'Meituan self rank changed from ' . $previousRank . ' to ' . $currentRank . ' (' . $direction . ' ' . $delta . ').',
                    'direction' => $direction,
                    'delta' => $delta,
                    'current' => ['self_rank' => $currentRank],
                    'previous' => ['self_rank' => $previousRank],
                ];
            }
        } else {
            $missingReasons[] = 'Self rank fields are not comparable.';
        }

        $currentTagStatus = (string)($current['platform_tag_status'] ?? '');
        $previousTagStatus = (string)($previous['platform_tag_status'] ?? '');
        if ($currentTagStatus !== '' && $previousTagStatus !== '') {
            if ($currentTagStatus !== 'not_returned' || $previousTagStatus !== 'not_returned') {
                $hasComparableEvidence = true;
            }
            if ($currentTagStatus !== $previousTagStatus) {
                $hasComparableEvidence = true;
                $alerts[] = [
                    'type' => 'platform_tag_status_changed',
                    'level' => 'low',
                    'title' => 'Meituan platform tag status changed',
                    'message' => 'Meituan platform tag return status changed from ' . $previousTagStatus . ' to ' . $currentTagStatus . '; missing tags do not imply non-VIP.',
                    'current' => ['platform_tag_status' => $currentTagStatus],
                    'previous' => ['platform_tag_status' => $previousTagStatus],
                ];
            }
        }

        if (($current['has_platform_tag_evidence'] ?? false) && ($previous['has_platform_tag_evidence'] ?? false)) {
            $hasComparableEvidence = true;
            $currentVipCount = (int)($current['vip_count'] ?? 0);
            $previousVipCount = (int)($previous['vip_count'] ?? 0);
            if ($currentVipCount !== $previousVipCount) {
                $alerts[] = [
                    'type' => 'vip_count_changed',
                    'level' => 'low',
                    'title' => 'Meituan VIP tag count changed',
                    'message' => 'Meituan VIP-tagged competitor count changed from ' . $previousVipCount . ' to ' . $currentVipCount . '.',
                    'delta' => $currentVipCount - $previousVipCount,
                    'current' => ['vip_count' => $currentVipCount],
                    'previous' => ['vip_count' => $previousVipCount],
                ];
            }
        } else {
            $missingReasons[] = 'VIP/platform tag fields are not comparable; no VIP inference is made.';
        }

        if (!$hasComparableEvidence) {
            return [
                'status' => 'missing',
                'missing_reason' => implode(' ', array_values(array_unique($missingReasons))),
                'alerts' => [],
            ];
        }

        return [
            'status' => !empty($alerts) ? 'changed' : 'ok',
            'missing_reason' => implode(' ', array_values(array_unique($missingReasons))),
            'alerts' => $alerts,
        ];
    }

    private function isMeituanBusinessRankRow(array $row): bool
    {
        if (!$this->hasTrustedOtaEvidenceEnvelope($row)) {
            return false;
        }
        $source = strtolower((string)($row['source'] ?? ''));
        $platform = strtolower((string)($row['platform'] ?? ''));
        $dataType = strtolower((string)($row['data_type'] ?? ''));
        return ($source === 'meituan' || $platform === 'meituan') && ($dataType === '' || $dataType === 'business');
    }

    /** @return array<string, mixed> */
    private function summarizeMeituanRankSourceEvidence(array $rows): array
    {
        $completeRowCount = 0;
        $rowIds = [];
        $traceIds = [];
        $sourceMethods = [];
        $missingFields = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawValue = $row['raw_data'] ?? [];
            $raw = is_array($rawValue) ? $rawValue : $this->decodeJson((string)$rawValue);
            $capture = is_array($raw['capture_evidence'] ?? null) ? $raw['capture_evidence'] : [];
            $meta = is_array($raw['meta'] ?? null) ? $raw['meta'] : [];
            $traceId = '';
            foreach ([
                $row['source_trace_id'] ?? null,
                $raw['source_trace_id'] ?? null,
                $capture['source_trace_id'] ?? null,
                $meta['source_trace_id'] ?? null,
            ] as $value) {
                if (is_scalar($value) && trim((string)$value) !== '') {
                    $traceId = trim((string)$value);
                    break;
                }
            }
            $sourceMethod = '';
            foreach ([
                $row['ingestion_method'] ?? null,
                $row['source_method'] ?? null,
                $raw['ingestion_method'] ?? null,
                $raw['_ingestion_method'] ?? null,
                $raw['source_method'] ?? null,
            ] as $value) {
                if (is_scalar($value) && trim((string)$value) !== '') {
                    $sourceMethod = strtolower(trim((string)$value));
                    break;
                }
            }

            $rowMissing = [];
            $rowId = (int)($row['id'] ?? 0);
            if ($rowId <= 0) {
                $rowMissing[] = 'source_row_id';
            }
            if ($traceId === '') {
                $rowMissing[] = 'source_trace_id';
            }
            if ($sourceMethod === '') {
                $rowMissing[] = 'source_method';
            }
            if ($this->trustedOnlineCollectionTimestamp($row) <= 0) {
                $rowMissing[] = 'collected_at';
            }
            if ($rowMissing === []) {
                $completeRowCount++;
            } else {
                $missingFields = array_merge($missingFields, $rowMissing);
            }
            if ($rowId > 0) {
                $rowIds[] = $rowId;
            }
            if ($traceId !== '') {
                $traceIds[] = $traceId;
            }
            if ($sourceMethod !== '') {
                $sourceMethods[] = $sourceMethod;
            }
        }

        $rowCount = count($rows);
        return [
            'status' => $rowCount > 0 && $completeRowCount === $rowCount ? 'verified' : 'unverified',
            'row_count' => $rowCount,
            'complete_row_count' => $completeRowCount,
            'source_row_ids' => array_values(array_unique($rowIds)),
            'source_trace_ids' => array_values(array_unique($traceIds)),
            'source_methods' => array_values(array_unique($sourceMethods)),
            'missing_fields' => array_values(array_unique($missingFields)),
        ];
    }

    private function resolveMeituanTargetPoiId(array $hotelIds): string
    {
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0)));
        if ($hotelIds === [] || !$this->tableExists('platform_data_sources')) {
            return '';
        }

        $identityColumn = '';
        foreach (['platform_hotel_id', 'poi_id', 'store_id'] as $candidate) {
            if ($this->tableHasColumn('platform_data_sources', $candidate)) {
                $identityColumn = $candidate;
                break;
            }
        }
        if ($identityColumn === '') {
            return '';
        }

        try {
            $rows = Db::name('platform_data_sources')
                ->where('platform', 'meituan')
                ->whereIn('system_hotel_id', $hotelIds)
                ->where('enabled', 1)
                ->whereIn('status', ['ready', 'success', 'partial_success'])
                ->field(['system_hotel_id', $identityColumn])
                ->order('update_time', 'desc')
                ->select()
                ->toArray();
        } catch (Throwable $e) {
            return '';
        }

        $identities = [];
        foreach ($rows as $row) {
            $value = trim((string)($row[$identityColumn] ?? ''));
            if ($value !== '' && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $value) === 1) {
                $identities[$value] = true;
            }
        }

        return count($identities) === 1 ? (string)array_key_first($identities) : '';
    }

    private function meituanPlatformTagInfo(array $raw): array
    {
        $tags = [];
        foreach (['platformTags', 'tags', 'tagList', 'badgeList', 'benefitTags', 'titleTags', 'identityTags'] as $key) {
            $tags = $this->mergeStringValues($tags, $this->stringListValue($raw[$key] ?? []));
        }
        foreach (['platformTagText', 'vipTag', 'memberTag', 'rightsTag', 'platformTag', 'crownLevel', 'crownTag'] as $key) {
            $tags = $this->mergeStringValues($tags, $this->stringListValue($raw[$key] ?? []));
        }

        $hasVip = !empty($raw['hasVipTag']) || !empty($raw['isVip']) || !empty($raw['vipFlag']) || !empty($raw['memberFlag']) || $this->hasMeituanVipTag($tags);
        $status = (string)($raw['platformTagStatus'] ?? '');
        if ($status === '') {
            if (!empty($tags)) {
                $status = 'returned';
            } elseif (array_key_exists('platformTags', $raw) || array_key_exists('tags', $raw) || array_key_exists('tagList', $raw)) {
                $status = 'returned_empty';
            } else {
                $status = 'not_returned';
            }
        }

        return [
            'tags' => $tags,
            'status' => $status,
            'has_vip' => $hasVip,
        ];
    }

    private function summarizeMeituanPlatformTags(array $hotels): array
    {
        $returned = 0;
        $returnedEmpty = 0;
        $notReturned = 0;
        $vip = 0;
        foreach ($hotels as $hotel) {
            $tags = is_array($hotel['platform_tags'] ?? null) ? $hotel['platform_tags'] : [];
            if (!empty($tags)) {
                $returned++;
            } elseif (($hotel['platform_tag_status'] ?? '') === 'returned_empty') {
                $returnedEmpty++;
            } else {
                $notReturned++;
            }
            if (!empty($hotel['has_vip_tag']) || $this->hasMeituanVipTag($tags)) {
                $vip++;
            }
        }

        $status = $returned > 0 ? 'returned' : ($returnedEmpty > 0 ? 'returned_empty' : 'not_returned');
        $text = match ($status) {
            'returned' => 'VIP ' . $vip . '家 / 平台标签返回 ' . $returned . '家',
            'returned_empty' => '平台返回空标签 ' . $returnedEmpty . '家，不推断VIP',
            default => '平台标签未返回，不推断VIP',
        };

        return [
            'status' => $status,
            'text' => $text,
            'returned_count' => $returned,
            'returned_empty_count' => $returnedEmpty,
            'not_returned_count' => $notReturned,
            'vip_count' => $vip,
        ];
    }

    private function summarizeMeituanRankTrend(array $history): array
    {
        $ranks = array_values(array_filter($history, static fn(array $item): bool => (int)($item['rank'] ?? 0) > 0));
        if (count($ranks) < 2) {
            return ['status' => 'missing', 'text' => '平台未返回可比榜单历史'];
        }

        usort($ranks, static function (array $a, array $b): int {
            $order = ['0' => 0, '1' => 1, '7' => 2, '30' => 3, '' => 9];
            $rangeA = (string)($a['date_range'] ?? '');
            $rangeB = (string)($b['date_range'] ?? '');
            return ($order[$rangeA] ?? 8) <=> ($order[$rangeB] ?? 8);
        });

        $current = (int)($ranks[0]['rank'] ?? 0);
        $previous = (int)($ranks[1]['rank'] ?? 0);
        if ($current <= 0 || $previous <= 0) {
            return ['status' => 'missing', 'text' => '平台未返回可比榜单历史'];
        }
        if ($current === $previous) {
            return ['status' => 'flat', 'text' => '排名持平'];
        }
        if ($current < $previous) {
            return ['status' => 'up', 'text' => '上升' . ($previous - $current) . '名'];
        }
        return ['status' => 'down', 'text' => '下降' . ($current - $previous) . '名'];
    }

    private function classifyMeituanRankMetric(string $dimension, string $metricName, string $rankType): string
    {
        $combined = mb_strtolower($dimension . '|' . $metricName . '|' . $rankType, 'UTF-8');
        if ($rankType === 'P_XS' || str_contains($combined, '销售') || str_contains($combined, 'sales')) {
            return str_contains($combined, '间夜') || str_contains($combined, 'roomnight') ? 'salesRoomNights' : 'sales';
        }
        if ($rankType === 'P_LL' || str_contains($combined, '流量') || str_contains($combined, '曝光') || str_contains($combined, '浏览')) {
            return str_contains($combined, '浏览') || str_contains($combined, 'view') ? 'views' : 'exposure';
        }
        if ($rankType === 'P_ZH' || str_contains($combined, '转化') || str_contains($combined, 'conversion')) {
            return str_contains($combined, '支付') || str_contains($combined, 'pay') ? 'payConversion' : 'viewConversion';
        }
        if ($rankType === 'P_RZ' || str_contains($combined, '入住')) {
            return str_contains($combined, '房费') || str_contains($combined, '收入') || str_contains($combined, 'revenue') ? 'roomRevenue' : 'roomNights';
        }
        return '';
    }

    private function firstStringValue(array $data, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = trim((string)$data[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return trim($default);
    }

    private function firstNumericValue(array $data, array $keys, mixed $default = null): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_numeric($data[$key])) {
                return (float)$data[$key];
            }
        }
        return is_numeric($default) ? (float)$default : null;
    }

    private function stringListValue(mixed $value): array
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    foreach (['name', 'text', 'label', 'title', 'tagName', 'tag'] as $key) {
                        if (trim((string)($item[$key] ?? '')) !== '') {
                            $result[] = trim((string)$item[$key]);
                            break;
                        }
                    }
                    continue;
                }
                $text = trim((string)$item);
                if ($text !== '' && $text !== '未返回') {
                    $result[] = $text;
                }
            }
            return array_values(array_unique($result));
        }

        $text = trim((string)$value);
        if ($text === '' || $text === '未返回') {
            return [];
        }
        return array_values(array_filter(array_map('trim', preg_split('/[\/,，;；|]+/u', $text) ?: [])));
    }

    private function mergeStringValues(array $left, array $right): array
    {
        return array_values(array_unique(array_filter(array_merge($left, $right), static fn(string $value): bool => trim($value) !== '')));
    }

    private function hasMeituanVipTag(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (preg_match('/vip|会员|皇冠|权益|甄选|优选/iu', (string)$tag) === 1) {
                return true;
            }
        }
        return false;
    }

    private function onlineRowFetchedAt(array $row): string
    {
        return (string)($row['update_time'] ?? $row['create_time'] ?? '');
    }

    private function maxOnlineRowFetchedAt(array $rows): string
    {
        $max = '';
        foreach ($rows as $row) {
            $time = $this->onlineRowFetchedAt($row);
            if ($time !== '' && ($max === '' || strcmp($time, $max) > 0)) {
                $max = $time;
            }
        }
        return $max;
    }

    private function buildServiceQuality(array $hotelIds, string $date): array
    {
        return $this->buildServiceQualityFromRows($this->onlineRows($hotelIds, $date, $date));
    }

    private function buildServiceQualityFromRows(array $rows): array
    {
        $base = [
            'avg_psi_score' => null,
            'avg_service_score' => null,
            'sample_count' => 0,
            'psi_sample_count' => 0,
            'service_score_sample_count' => 0,
            'data_status' => self::DATA_PENDING,
            'score_scale' => 'unknown',
            'threshold_80_eligible' => false,
            'data_gaps' => [],
        ];

        $psiScores = [];
        $serviceScores = [];
        foreach ($rows as $row) {
            $dataType = strtolower((string)($row['data_type'] ?? ''));
            if (!in_array($dataType, ['quality', 'service', 'service_quality', 'psi'], true)) {
                continue;
            }
            if (!$this->isTrustedSelfOtaFactRow($row)) {
                continue;
            }

            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $psi = $this->nestedOnlineMetric($raw, ['psiScore', 'psi_score', 'psi', 'serviceQualityScore', 'qualityScore']);
            if ($psi === null && str_contains(strtolower((string)($row['dimension'] ?? '')), ':psi_score')) {
                $psi = $this->firstNumericMetric($row, ['data_value']);
            }
            $serviceScore = $this->nestedOnlineMetric($raw, ['serviceScore', 'service_score', 'dayReportServiceScore', 'service_score_value']);

            if ($psi !== null && $psi > 0) {
                $psiScores[] = $psi;
                $base['psi_sample_count']++;
            }
            if ($serviceScore !== null && $serviceScore > 0) {
                $serviceScores[] = $serviceScore;
                $base['service_score_sample_count']++;
            }
            if (($psi !== null && $psi > 0) || ($serviceScore !== null && $serviceScore > 0)) {
                $base['sample_count']++;
            }
        }

        if ($base['sample_count'] <= 0) {
            return $base;
        }

        $base['avg_psi_score'] = $psiScores !== [] ? $this->avg($psiScores) : null;
        $base['avg_service_score'] = $serviceScores !== [] ? $this->avg($serviceScores) : null;
        $scores = array_merge($psiScores, $serviceScores);
        $base['threshold_80_eligible'] = $this->scoresUseHundredPointScale($scores);
        $base['score_scale'] = $base['threshold_80_eligible'] ? '0_100' : 'unknown';
        $base['data_status'] = $base['threshold_80_eligible'] ? self::DATA_OK : 'partial';
        $base['data_gaps'] = $base['threshold_80_eligible'] ? [] : ['service_quality_scale_unknown'];

        return $base;
    }

    /** @param array<string, mixed> $raw @param array<int, string> $keys */
    private function nestedOnlineMetric(array $raw, array $keys): ?float
    {
        $payloads = [$raw];
        foreach ([
            $raw['row'] ?? null,
            $raw['raw_data'] ?? null,
            $raw['row']['raw_data'] ?? null,
        ] as $payload) {
            if (is_array($payload)) {
                $payloads[] = $payload;
            }
        }

        foreach ($payloads as $payload) {
            $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
            $value = $this->firstNumericMetric($metrics, $keys);
            if ($value === null) {
                $value = $this->firstNumericMetric($payload, $keys);
            }
            if ($value !== null) {
                return $value;
            }

            foreach ((array)($payload['facts'] ?? []) as $fact) {
                if (!is_array($fact)) {
                    continue;
                }
                $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
                if (!in_array($metricKey, array_map('strtolower', $keys), true)) {
                    continue;
                }
                $factValue = $fact['value'] ?? null;
                if (is_numeric($factValue)) {
                    return (float)$factValue;
                }
            }
        }

        return null;
    }

    /** @param array<int, mixed> $scores */
    private function scoresUseHundredPointScale(array $scores): bool
    {
        $scores = array_values(array_filter($scores, static fn($value): bool => is_numeric($value) && (float)$value > 0));
        if ($scores === []) {
            return false;
        }
        foreach ($scores as $score) {
            $score = (float)$score;
            if ($score <= 10 || $score > 100) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $serviceQuality */
    private function serviceQualityThresholdEligible(array $serviceQuality): bool
    {
        if (array_key_exists('threshold_80_eligible', $serviceQuality)) {
            return $serviceQuality['threshold_80_eligible'] === true;
        }
        return $this->scoresUseHundredPointScale([
            $serviceQuality['avg_psi_score'] ?? null,
            $serviceQuality['avg_service_score'] ?? null,
        ]);
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
        $rows = $this->latestOnlineFlowRows($this->onlineRows($hotelIds, $start, $end));
        if (empty($rows)) {
            return [];
        }

        $byDate = [];
        foreach ($rows as $row) {
            $day = (string)$row['data_date'];
            $metrics = $this->onlineFlowMetrics($row);
            $byDate[$day]['exposure'] = ($byDate[$day]['exposure'] ?? 0) + $metrics['exposure'];
            $byDate[$day]['visitors'] = ($byDate[$day]['visitors'] ?? 0) + $metrics['visitors'];
            $byDate[$day]['views'] = ($byDate[$day]['views'] ?? 0) + $metrics['views'];
            $byDate[$day]['orders'] = ($byDate[$day]['orders'] ?? 0) + $metrics['orders'];
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
            'data_status' => $exposure > 0 && ($visitors > 0 || $views > 0) ? self::DATA_OK : 'partial',
        ];
    }

    private function baseline(array $hotelIds, int $days, ?string $endDate = null): array
    {
        $end = $endDate ? date('Y-m-d', strtotime($endDate . ' -1 day')) : date('Y-m-d');
        $start = date('Y-m-d', strtotime($end . ' -' . ($days - 1) . ' days'));
        $daily = $this->dailyReportRows($hotelIds, $start, $end);
        $onlineRows = $this->onlineRows($hotelIds, $start, $end);
        $dailyByDate = [];
        $onlineByDate = [];
        $dates = [];
        foreach ($daily as $row) {
            $date = substr(trim((string)($row['report_date'] ?? '')), 0, 10);
            if ($date !== '') {
                $dailyByDate[$date][] = $row;
                $dates[$date] = true;
            }
        }
        foreach ($onlineRows as $row) {
            $date = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            if ($date !== '') {
                $onlineByDate[$date][] = $row;
                $dates[$date] = true;
            }
        }

        $metricValues = ['orders' => [], 'revenue' => [], 'room_nights' => []];
        $sourceScopes = [];
        $incompleteDates = [];
        $actualDates = [];
        foreach (array_keys($dates) as $date) {
            $summary = $this->buildSummaryFromRows(
                $dailyByDate[$date] ?? [],
                $onlineByDate[$date] ?? [],
                $hotelIds,
                count($hotelIds) === 1 ? (int)$hotelIds[0] : null,
                $date
            );
            if (($summary['evidence_refs'] ?? []) === []) {
                continue;
            }
            $actualDates[$date] = true;
            $sourceScopes[(string)($summary['source_scope'] ?? 'unknown')] = true;
            if (($summary['data_status'] ?? '') !== self::DATA_OK) {
                $incompleteDates[] = $date;
            }
            foreach (array_keys($metricValues) as $metric) {
                if ($summary[$metric] !== null && is_numeric($summary[$metric])) {
                    $metricValues[$metric][] = (float)$summary[$metric];
                }
            }
        }

        $conversionValues = [];
        $flowByDate = [];
        foreach ($this->latestOnlineFlowRows($onlineRows) as $row) {
            $day = (string)($row['data_date'] ?? '');
            if ($day === '') {
                continue;
            }
            $metrics = $this->onlineFlowMetrics($row);
            $flowByDate[$day]['visitors'] = ($flowByDate[$day]['visitors'] ?? 0) + $metrics['visitors'];
            $flowByDate[$day]['orders'] = ($flowByDate[$day]['orders'] ?? 0) + $metrics['orders'];
        }
        foreach ($flowByDate as $metric) {
            $visitors = (float)($metric['visitors'] ?? 0);
            if ($visitors > 0) {
                $conversionValues[] = (float)($metric['orders'] ?? 0) / $visitors * 100;
            }
        }

        $count = count($actualDates);
        $dataGaps = [];
        foreach ([
            'orders' => ['baseline_orders_incomplete', '订单'],
            'revenue' => ['baseline_revenue_incomplete', '收入'],
            'room_nights' => ['baseline_room_nights_incomplete', '间夜'],
        ] as $metric => [$code, $label]) {
            if (count($metricValues[$metric]) < $count) {
                $dataGaps[] = [
                    'code' => $code,
                    'message' => $label . '仅覆盖 ' . count($metricValues[$metric]) . '/' . $count . ' 个有效日期',
                ];
            }
        }
        if ($incompleteDates !== []) {
            $dataGaps[] = [
                'code' => 'baseline_daily_summary_partial',
                'message' => count($incompleteDates) . ' 个日期存在必需字段或来源缺口',
            ];
        }

        return [
            'days' => $days,
            'actual_days' => $count,
            'avg_orders' => $metricValues['orders'] !== [] ? round(array_sum($metricValues['orders']) / count($metricValues['orders']), 2) : null,
            'avg_revenue' => $metricValues['revenue'] !== [] ? round(array_sum($metricValues['revenue']) / count($metricValues['revenue']), 2) : null,
            'avg_room_nights' => $metricValues['room_nights'] !== [] ? round(array_sum($metricValues['room_nights']) / count($metricValues['room_nights']), 2) : null,
            'avg_conversion' => $conversionValues !== [] ? round(array_sum($conversionValues) / count($conversionValues), 2) : null,
            'metric_sample_days' => [
                'orders' => count($metricValues['orders']),
                'revenue' => count($metricValues['revenue']),
                'room_nights' => count($metricValues['room_nights']),
                'conversion' => count($conversionValues),
            ],
            'source_scopes' => array_keys($sourceScopes),
            'data_gaps' => $dataGaps,
            'data_status' => $count === 0 ? 'missing' : ($dataGaps === [] ? self::DATA_OK : 'partial'),
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
                $query->whereIn('system_hotel_id', array_map('intval', $hotelIds));
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
        $otaPlatform = $this->operatingSnapshotChannel((array)($full['ota'] ?? []));
        $otaPlatform = $otaPlatform !== '' ? $otaPlatform : 'ota';
        $otaSourceRefs = [];
        foreach ((array)($full['ota']['evidence_refs'] ?? []) as $evidenceRef) {
            if (!is_array($evidenceRef)) {
                continue;
            }
            $sourceRef = trim((string)($evidenceRef['source_ref'] ?? ''));
            if ($sourceRef !== '') {
                $otaSourceRefs[] = $sourceRef;
            }
        }
        $otaSourceRefs = array_values(array_unique($otaSourceRefs));

        foreach ($full['abnormal_flags'] as $flag) {
            $alerts[] = $this->alert(
                $id++,
                $hotelId ?: ($hotelIds[0] ?? 0),
                'data_abnormal',
                'high',
                '数据异常',
                $flag,
                $date,
                null,
                [
                    'metric_key' => 'ota_data_quality_anomaly',
                    'observed_value' => $flag,
                    'comparison_rule' => 'operation_data_consistency_check_triggered',
                    'platform' => $otaPlatform,
                    'data_date' => $date,
                    'date_basis' => 'source_data_date',
                    'source_refs' => $otaSourceRefs,
                ]
            );
        }
        if (($full['ota']['exposure'] ?? 0) <= 0 && ($full['ota']['data_status'] ?? '') === self::DATA_OK) {
            $alerts[] = $this->alert($id++, $hotelId ?: ($hotelIds[0] ?? 0), 'traffic_zero', 'high', '流量为0', 'OTA曝光为0，请检查采集和渠道状态', $date, null, [
                'metric_key' => 'ota_exposure',
                'threshold_value' => 0,
                'observed_value' => $full['ota']['exposure'] ?? null,
                'comparison_rule' => 'observed_value <= threshold_value',
                'platform' => $otaPlatform,
                'data_date' => $date,
                'date_basis' => 'source_data_date',
                'source_refs' => $otaSourceRefs,
            ]);
        }
        if (($full['ota']['order_rate'] ?? 0) > 0 && ($full['ota']['order_rate'] ?? 0) < 3) {
            $alerts[] = $this->alert($id++, $hotelId ?: ($hotelIds[0] ?? 0), 'conversion_low', 'medium', '转化偏低', '订单/访客转化率低于3%', $date, null, [
                'metric_key' => 'ota_conversion_rate',
                'threshold_value' => 3,
                'observed_value' => $full['ota']['order_rate'],
                'comparison_rule' => '0 < observed_value < threshold_value',
                'platform' => $otaPlatform,
                'data_date' => $date,
                'date_basis' => 'source_data_date',
                'source_refs' => $otaSourceRefs,
            ]);
        }
        if (($full['competitors']['comparability_status'] ?? '') === 'eligible'
            && ($full['competitors']['price_gap'] ?? 0) > 10
        ) {
            $alerts[] = $this->alert($id++, $hotelId ?: ($hotelIds[0] ?? 0), 'price_high', 'medium', '价格偏高', '本店价格高于竞对均价', $date, null, [
                'metric_key' => 'ota_competitor_price_gap_amount',
                'threshold_value' => 10,
                'observed_value' => $full['competitors']['price_gap'],
                'comparison_rule' => 'observed_value > threshold_value',
                'platform' => 'ota',
                'data_date' => $date,
                'date_basis' => 'analysis_date',
                'comparison_key' => (string)($full['competitors']['comparison_key'] ?? ''),
            ]);
        }
        $meituanSummary = $full['competitors']['meituan_rank_summary'] ?? [];
        if (is_array($meituanSummary)) {
            $meituanChangeAlerts = $this->meituanCompetitorChangeRuleAlerts($meituanSummary, $hotelId ?: ($hotelIds[0] ?? 0), $date, $id);
            $alerts = array_merge($alerts, $meituanChangeAlerts);
            $id += count($meituanChangeAlerts);
        }
        $psiScore = (float)($full['service_quality']['avg_psi_score'] ?? 0);
        $serviceScore = (float)($full['service_quality']['avg_service_score'] ?? 0);
        if ($this->serviceQualityThresholdEligible((array)($full['service_quality'] ?? [])) && (($psiScore > 0 && $psiScore < 80) || ($serviceScore > 0 && $serviceScore < 80))) {
            $observedServiceScore = $psiScore > 0 && $serviceScore > 0 ? min($psiScore, $serviceScore) : max($psiScore, $serviceScore);
            $alerts[] = $this->alert($id++, $hotelId ?: ($hotelIds[0] ?? 0), 'service_quality_low', 'medium', '服务质量偏低', 'OTA服务质量或PSI低于80分', $date, null, [
                'metric_key' => 'ota_service_quality_score',
                'threshold_value' => 80,
                'observed_value' => $observedServiceScore,
                'comparison_rule' => '0 < observed_value < threshold_value',
                'platform' => 'ota',
                'data_date' => $date,
                'date_basis' => 'source_data_date',
            ]);
        }
        if (($full['holiday']['days_left'] ?? 999) < 15 && ($full['holiday']['data_status'] ?? '') === self::DATA_OK) {
            $alerts[] = $this->alert($id++, $hotelId ?: ($hotelIds[0] ?? 0), 'holiday_near', 'low', '节假日临近', '距离下个节假日不足15天', $date, null, [
                'metric_key' => 'ota_holiday_days_left',
                'threshold_value' => 15,
                'observed_value' => $full['holiday']['days_left'],
                'comparison_rule' => 'observed_value < threshold_value',
                'platform' => 'ota',
                'data_date' => $date,
                'date_basis' => 'calendar_date',
            ]);
        }

        return $alerts;
    }

    private function meituanCompetitorChangeRuleAlerts(array $summary, int $hotelId, string $date, int $startId): array
    {
        $signals = is_array($summary['change_alerts'] ?? null) ? $summary['change_alerts'] : [];
        if (empty($signals) || $hotelId <= 0) {
            return [];
        }

        $alerts = [];
        $id = $startId;
        foreach ($signals as $signal) {
            if (!is_array($signal)) {
                continue;
            }

            $signalType = strtolower(trim((string)($signal['type'] ?? '')));
            $signalType = trim((string)preg_replace('/[^a-z0-9_]+/i', '_', $signalType), '_');
            if ($signalType === '') {
                continue;
            }

            $ruleAlert = $this->alert(
                $id++,
                $hotelId,
                'meituan_competitor_' . $signalType,
                (string)($signal['level'] ?? 'medium'),
                (string)($signal['title'] ?? 'Meituan competitor ranking change'),
                (string)($signal['message'] ?? 'Meituan competitor ranking changed.'),
                $date,
                'Review Meituan TOP1, self rank, VIP/platform tags and batch evidence; keep missing fields explicit and do not infer VIP.'
            );
            $ruleAlert['raw_data'] = [
                'metric_key' => 'meituan_competitor_rank_signal',
                'observed_value' => $signalType,
                'comparison_rule' => 'current_snapshot_state_changed_from_previous_snapshot',
                'platform' => 'meituan',
                'data_date' => $date,
                'date_basis' => 'source_data_date',
                'change_signal_type' => $signalType,
                'change_monitor_status' => (string)($summary['change_monitor_status'] ?? ''),
                'change_missing_reason' => (string)($summary['change_missing_reason'] ?? ''),
                'latest_data_date' => (string)($summary['latest_data_date'] ?? ''),
                'latest_fetched_at' => (string)($summary['latest_fetched_at'] ?? ''),
                'previous_data_date' => (string)($summary['previous_data_date'] ?? ''),
                'previous_fetched_at' => (string)($summary['previous_fetched_at'] ?? ''),
                'privacy_scope' => (string)($summary['privacy_scope'] ?? ''),
                'source_ref' => (string)($summary['source_ref'] ?? ''),
            ];
            $alerts[] = $ruleAlert;
        }

        return $alerts;
    }

    private function persistRuleAlerts(array $alerts): array
    {
        $now = date('Y-m-d H:i:s');
        $rows = [];

        foreach ($alerts as $alert) {
            $hotelId = (int)($alert['hotel_id'] ?? 0);
            $type = (string)($alert['alert_type'] ?? '');
            $date = (string)($alert['related_date'] ?? date('Y-m-d'));
            if ($hotelId <= 0 || $type === '') {
                continue;
            }

            $rawData = is_array($alert['raw_data'] ?? null) ? $alert['raw_data'] : [];
            $actionSuggestion = $this->normalizeAlertSuggestion($alert);
            if ($actionSuggestion !== '') {
                $rawData['action_suggestion'] = $actionSuggestion;
            }

            $payload = [
                'hotel_id' => $hotelId,
                'alert_type' => $type,
                'level' => (string)($alert['level'] ?? 'low'),
                'title' => (string)($alert['title'] ?? ''),
                'message' => (string)($alert['message'] ?? ''),
                'source' => (string)($alert['source'] ?? 'rule'),
                'related_date' => $date,
                'raw_data' => json_encode($rawData, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ];
            $payload = $this->withHotelTenantId($payload, 'operation_alerts', $hotelId);

            $existing = Db::name('operation_alerts')
                ->where('hotel_id', $hotelId)
                ->where('alert_type', $type)
                ->where('source', $payload['source'])
                ->where('related_date', $date)
                ->whereNull('deleted_at')
                ->find();

            if ($existing) {
                Db::name('operation_alerts')->where('id', (int)$existing['id'])->update($payload);
                $rows[] = Db::name('operation_alerts')->where('id', (int)$existing['id'])->find();
                continue;
            }

            $payload['status'] = 'unread';
            $payload['created_at'] = $now;
            $id = (int)Db::name('operation_alerts')->insertGetId($payload);
            $rows[] = Db::name('operation_alerts')->where('id', $id)->find();
        }

        return array_values(array_map([$this, 'normalizeAlertRow'], array_filter($rows)));
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
            return ['status' => 'success', 'message' => '观察期指标达到目标阈值；不代表已证明动作因果', 'actual_change_rate' => round($actualRate, 2)];
        }
        if ($actualRate >= $targetRate * 0.7) {
            return ['status' => 'near_success', 'message' => '观察期指标达到目标阈值的70%以上；不代表已证明动作因果', 'actual_change_rate' => round($actualRate, 2)];
        }

        return ['status' => 'failed', 'message' => '观察期指标低于目标阈值的70%；不代表已证明动作因果', 'actual_change_rate' => round($actualRate, 2)];
    }

    private function normalizeAlertRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['hotel_id'] = (int)$row['hotel_id'];
        $row['raw_data'] = $this->decodeJson((string)($row['raw_data'] ?? ''));
        $row['action_suggestion'] = $this->normalizeAlertSuggestion($row);
        return $row;
    }

    /** @param array<int,array<string,mixed>> $alerts */
    private function attachAlertExecutionBridges(array $alerts, bool $persisted, bool $canExecute = true): array
    {
        if ($alerts === []) {
            return [];
        }

        $executionReady = $persisted
            && $this->tableExists('operation_execution_intents')
            && $this->tableExists('operation_execution_tasks')
            && $this->tableExists('operation_execution_evidence');
        $intentByAlertKey = [];
        if ($executionReady) {
            $alertIds = [];
            $alertHotelIds = [];
            $eligibleAlertKeys = [];
            foreach ($alerts as $alert) {
                $alertId = (int)($alert['id'] ?? 0);
                $alertHotelId = (int)($alert['hotel_id'] ?? 0);
                if ($alertId <= 0 || $alertHotelId <= 0) {
                    continue;
                }
                $alertIds[$alertId] = true;
                $alertHotelIds[$alertHotelId] = true;
                $eligibleAlertKeys[$alertHotelId . ':' . $alertId] = true;
            }
            if ($alertIds !== []) {
                try {
                    $rows = Db::name('operation_execution_intents')
                        ->where('source_module', 'operation_alert')
                        ->whereIn('source_record_id', array_keys($alertIds))
                        ->whereIn('hotel_id', array_keys($alertHotelIds))
                        ->whereNull('deleted_at')
                        ->field('id,source_record_id,hotel_id,status,blocked_reason,created_at,updated_at')
                        ->order('id', 'desc')
                        ->select()
                        ->toArray();
                    foreach ($rows as $row) {
                        $sourceRecordId = (int)($row['source_record_id'] ?? 0);
                        $intentHotelId = (int)($row['hotel_id'] ?? 0);
                        $key = $intentHotelId . ':' . $sourceRecordId;
                        if (isset($eligibleAlertKeys[$key]) && !isset($intentByAlertKey[$key])) {
                            $intentByAlertKey[$key] = $row;
                        }
                    }
                } catch (Throwable $e) {
                    $intentByAlertKey = [];
                }
            }
        }

        foreach ($alerts as &$alert) {
            $alertId = (int)($alert['id'] ?? 0);
            $alertHotelId = (int)($alert['hotel_id'] ?? 0);
            $intent = $intentByAlertKey[$alertHotelId . ':' . $alertId] ?? null;
            if (is_array($intent)) {
                $alert['task_bridge'] = $this->alertExecutionBridgeFromIntent($intent);
                continue;
            }
            $evidenceUnavailableReason = $this->alertExecutionEvidenceUnavailableReason($alert);
            $unavailableReason = !$persisted
                ? '预警尚未持久化，不能创建可跟踪任务'
                : (!$executionReady
                    ? '运营执行表未初始化，暂不能创建可跟踪任务'
                    : (!$canExecute ? '当前账号只有查看权限，不能创建运营任务' : $evidenceUnavailableReason));
            $alert['task_bridge'] = [
                'can_convert' => $executionReady
                    && $canExecute
                    && $alertId > 0
                    && $evidenceUnavailableReason === '',
                'linked' => false,
                'intent_id' => 0,
                'intent_status' => '',
                'blocked_reason' => '',
                'unavailable_reason' => $unavailableReason,
            ];
        }
        unset($alert);

        return $alerts;
    }

    /** @param array<string,mixed> $alert */
    private function alertExecutionEvidenceUnavailableReason(array $alert): string
    {
        $alertId = (int)($alert['id'] ?? 0);
        $hotelId = (int)($alert['hotel_id'] ?? 0);
        if ($alertId <= 0 || $hotelId <= 0) {
            return '预警缺少可跟踪的酒店或记录ID';
        }

        $relatedDate = trim((string)($alert['related_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $relatedDate) !== 1
            || strtotime($relatedDate) === false
        ) {
            return '预警缺少有效证据日期，不能转为执行任务';
        }

        $type = strtolower(trim((string)($alert['alert_type'] ?? '')));
        $source = strtolower(trim((string)($alert['source'] ?? '')));
        $rawData = is_array($alert['raw_data'] ?? null) ? $alert['raw_data'] : [];
        if ($source !== 'rule') {
            return '';
        }
        if (str_starts_with($type, 'meituan_competitor_')) {
            return trim((string)($rawData['change_signal_type'] ?? '')) !== ''
                ? ''
                : '预警缺少美团竞对变化证据，不能转为执行任务';
        }
        if ($type === 'data_abnormal') {
            return trim((string)($rawData['metric_key'] ?? '')) !== ''
                && trim((string)($rawData['observed_value'] ?? '')) !== ''
                ? ''
                : '预警缺少数据异常证据，不能转为执行任务';
        }
        if (!in_array($type, ['traffic_zero', 'conversion_low', 'price_high', 'service_quality_low', 'holiday_near'], true)) {
            return '';
        }

        foreach (['metric_key', 'threshold_value', 'observed_value', 'comparison_rule'] as $field) {
            if (!array_key_exists($field, $rawData) || trim((string)$rawData[$field]) === '') {
                return '预警缺少实际阈值或观测值，不能转为执行任务';
            }
        }

        return '';
    }

    /** @param array<string,mixed> $intent */
    private function alertExecutionBridgeFromIntent(array $intent): array
    {
        return [
            'can_convert' => false,
            'linked' => (int)($intent['id'] ?? 0) > 0,
            'intent_id' => (int)($intent['id'] ?? 0),
            'intent_status' => (string)($intent['status'] ?? ''),
            'blocked_reason' => (string)($intent['blocked_reason'] ?? ''),
            'unavailable_reason' => '',
        ];
    }

    /** @param array<string,mixed> $alert */
    private function buildAlertExecutionIntentInput(array $alert): array
    {
        $type = strtolower(trim((string)($alert['alert_type'] ?? 'unknown')));
        $suggestion = $this->normalizeAlertSuggestion($alert);
        $title = trim((string)($alert['title'] ?? '运营预警'));
        $message = trim((string)($alert['message'] ?? ''));
        $date = trim((string)($alert['related_date'] ?? ''));
        $rawData = is_array($alert['raw_data'] ?? null) ? $alert['raw_data'] : [];
        $rawPlatform = $this->normalizeOtaChannel((string)($rawData['platform'] ?? $rawData['source'] ?? ''));
        $platform = str_starts_with($type, 'meituan_')
            ? 'meituan'
            : (in_array($rawPlatform, ['ctrip', 'meituan', 'qunar'], true) ? $rawPlatform : 'ota');
        $actionType = match (true) {
            $type === 'traffic_zero' => 'verify_traffic_and_channel_state',
            $type === 'conversion_low' => 'review_conversion_funnel',
            $type === 'price_high' => 'review_competitor_price_position',
            str_starts_with($type, 'meituan_competitor_') => 'review_meituan_competitor_change',
            $type === 'service_quality_low' => 'review_service_quality',
            $type === 'holiday_near' => 'prepare_holiday_operation',
            default => 'review_operation_alert',
        };
        $expectedMetric = match (true) {
            $type === 'traffic_zero' => 'ota_exposure',
            $type === 'conversion_low' => 'ota_conversion_rate',
            $type === 'price_high' => 'ota_competitor_price_gap',
            str_starts_with($type, 'meituan_competitor_') => 'meituan_competitor_rank_signal',
            $type === 'service_quality_low' => 'ota_service_quality',
            $type === 'holiday_near' => 'ota_holiday_readiness',
            default => 'ota_data_quality',
        };
        $safeContext = [];
        foreach ([
            'metric_key', 'threshold', 'threshold_value', 'observed_value', 'comparison_value',
            'comparison_rule', 'platform', 'data_date', 'date_basis', 'comparison_key',
            'change_signal_type', 'change_monitor_status', 'change_missing_reason',
            'latest_data_date', 'latest_fetched_at', 'previous_data_date', 'previous_fetched_at',
            'privacy_scope', 'source_ref',
        ] as $field) {
            $value = $rawData[$field] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                $safeContext[$field] = $value;
            }
        }
        $sourceRefs = ['operation_alert#' . (int)($alert['id'] ?? 0)];
        $rawSourceRefs = $rawData['source_refs'] ?? [];
        if (is_string($rawSourceRefs)) {
            $rawSourceRefs = [$rawSourceRefs];
        }
        if (is_array($rawSourceRefs)) {
            foreach ($rawSourceRefs as $sourceRef) {
                $sourceRef = trim((string)$sourceRef);
                if ($sourceRef !== '' && strlen($sourceRef) <= 200) {
                    $sourceRefs[] = $sourceRef;
                }
            }
        }
        $singleSourceRef = trim((string)($rawData['source_ref'] ?? ''));
        if ($singleSourceRef !== '' && strlen($singleSourceRef) <= 200) {
            $sourceRefs[] = $singleSourceRef;
        }
        $sourceRefs = array_values(array_unique($sourceRefs));
        $actionText = $suggestion !== '' ? $suggestion : '核对预警证据，确认影响范围后安排处理。';

        return [
            'source_module' => 'operation_alert',
            'source_record_id' => (int)($alert['id'] ?? 0),
            'hotel_id' => (int)($alert['hotel_id'] ?? 0),
            'platform' => $platform,
            'object_type' => 'operation_checklist',
            'action_type' => $actionType,
            'date_start' => $date,
            'date_end' => $date,
            'current_value' => [
                'alert_type' => $type,
                'alert_level' => (string)($alert['level'] ?? 'medium'),
                'alert_status' => (string)($alert['status'] ?? 'unread'),
                'observed_message' => $message,
            ],
            'target_value' => [
                'title' => $title,
                'action_text' => $actionText,
                'steps' => [
                    '核对门店、平台、证据日期和阈值口径',
                    $actionText,
                    '记录执行人、执行时间和同口径回读证据',
                ],
                'acceptance_criteria' => [
                    '已记录预警成立、误报或证据不足的人工判断',
                    '如实施动作，已保留执行记录且未把建议冒充为 OTA 已执行',
                    '后续复盘保持同门店、同平台、同指标和同日期口径',
                ],
                'metric_scope' => 'ota_channel',
            ],
            'evidence' => [
                'evidence_refs' => $sourceRefs,
                'diagnosis_summary' => $message,
                'alert_context' => $safeContext,
                'source_policy' => 'persisted_operation_alert_to_pending_human_task',
                'protected_boundary' => '创建待审批运营任务，不自动批准、不自动执行、不写 OTA。',
                'metric_scope' => 'ota_channel',
                'auto_write_ota' => false,
            ],
            'expected_metric' => trim((string)($rawData['metric_key'] ?? '')) ?: $expectedMetric,
            'expected_delta' => 0,
            'risk_level' => in_array((string)($alert['level'] ?? ''), ['high', 'medium', 'low'], true)
                ? (string)$alert['level']
                : 'medium',
            'status' => 'pending_approval',
        ];
    }

    private function alert(
        int $id,
        int $hotelId,
        string $type,
        string $level,
        string $title,
        string $message,
        string $date,
        ?string $actionSuggestion = null,
        array $rawData = []
    ): array
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
            'action_suggestion' => $actionSuggestion ?? $this->operationAlertSuggestion($type, $message),
            'raw_data' => $rawData,
        ];
    }

    private function normalizeAlertSuggestion(array $alert): string
    {
        $rawData = is_array($alert['raw_data'] ?? null) ? $alert['raw_data'] : [];
        $suggestion = trim((string)($alert['action_suggestion'] ?? $rawData['action_suggestion'] ?? $rawData['suggestion'] ?? ''));
        if ($suggestion !== '') {
            return $suggestion;
        }

        return $this->operationAlertSuggestion((string)($alert['alert_type'] ?? ''), (string)($alert['message'] ?? ''));
    }

    private function operationAlertSuggestion(string $type, string $message): string
    {
        return match ($type) {
            'data_abnormal' => '先复核OTA采集任务、Cookie状态和字段映射，确认异常日期后再补抓数据。',
            'traffic_zero' => '先检查OTA后台是否仍有曝光，再核对采集账号、Cookie和渠道上下架状态。',
            'conversion_low' => '优先复盘详情页首图、价格展示、可售房型和取消政策，必要时做小幅促销测试。',
            'price_high' => '按房型对比竞对可订价，先对高差价房型做小幅跟价或活动补贴。',
            'service_quality_low' => '先复核OTA服务质量扣分项、履约问题和关键服务节点，再跟踪转化率是否恢复。',
            'holiday_near' => '提前确认节假日库存、底价和活动节奏，避免临近日期低价或无房。',
            default => $message !== ''
                ? '先确认影响范围和责任模块，再安排负责人处理并在次日复盘数据变化。'
                : '',
        };
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
