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
    use \app\service\operation\OperationSnapshotConcern;
    use \app\service\operation\OperationAlertConcern;
    use \app\service\operation\OperationExecutionIntentConcern;

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
                'platform_response' => $this->buildExecutionEvidencePlatformResponse($evidence, $task, $intent),
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

    /**
     * Append the scheduled same-scope OTA readback without deciding the human
     * review result. The task stays observing until an operator confirms the
     * outcome; repeated runs are idempotent once source truth is present.
     *
     * @return array<string, mixed>
     */
    public function reconcileScheduledExecutionTask(int $taskId, array $hotelIds): array
    {
        $this->ensureExecutionTables();
        $taskRow = $this->executionTaskRow($taskId, $hotelIds);
        if ($taskRow === null) {
            throw new \RuntimeException('execution task not found');
        }
        $intentRow = $this->executionIntentRow((int)($taskRow['intent_id'] ?? 0), $hotelIds);
        if ($intentRow === null) {
            throw new \RuntimeException('execution intent not found');
        }
        $this->assertExecutionTaskIntentIdentity($taskRow, $intentRow);

        if ((string)($taskRow['status'] ?? '') !== 'executed') {
            throw new \InvalidArgumentException('execution task must be executed before scheduled readback');
        }
        $intent = $this->normalizeExecutionIntentRow($intentRow);
        if (strtolower(trim((string)($intent['source_module'] ?? ''))) !== 'ota_diagnosis_saved') {
            throw new \InvalidArgumentException('scheduled readback currently supports saved OTA diagnosis tasks only');
        }

        $task = $this->normalizeExecutionTaskRow($taskRow);
        $evidenceQuery = Db::name('operation_execution_evidence')
            ->where('task_id', $taskId)
            ->whereNull('deleted_at');
        if (array_key_exists('tenant_id', $taskRow)) {
            $evidenceQuery->where('tenant_id', (int)$taskRow['tenant_id']);
        }
        $evidenceRows = $evidenceQuery
            ->order('id', 'desc')
            ->select()
            ->toArray();
        if ($evidenceRows === []) {
            throw new \InvalidArgumentException('execution evidence is required before scheduled readback');
        }

        $reviewAt = $this->executionReviewAvailableAt(
            $intent,
            array_map([$this, 'normalizeExecutionEvidenceRow'], $evidenceRows)
        );
        if ($reviewAt === '') {
            throw new \InvalidArgumentException('scheduled review time is required before scheduled readback');
        }
        $reviewTimestamp = strtotime($reviewAt);
        if ($reviewTimestamp === false || time() < $reviewTimestamp) {
            throw new \InvalidArgumentException('execution review is not available before ' . $reviewAt);
        }

        $terminalStatus = strtolower(trim((string)($task['result_status'] ?? '')));
        if (in_array($terminalStatus, ['success', 'near_success', 'failed'], true)) {
            $detail = $this->executionTaskDetail($taskId, $hotelIds);
            return [
                'status' => 'already_reviewed',
                'task_id' => $taskId,
                'hotel_id' => (int)($task['hotel_id'] ?? 0),
                'review_at' => $reviewAt,
                'source_verified' => (bool)($detail['evidence_truth']['source_verified'] ?? false),
                'outcome_status' => (string)($detail['outcome_truth']['status'] ?? 'unverified'),
                'result_status' => (string)($detail['result_status'] ?? $terminalStatus),
                'sop_candidate_status' => (string)($detail['sop_candidate']['status'] ?? 'not_ready'),
                'next_action' => 'none',
            ];
        }
        if ($terminalStatus !== '' && $terminalStatus !== 'observing') {
            throw new \InvalidArgumentException('execution task result status is not eligible for scheduled readback');
        }

        $this->syncSourceVerifiedMetricReadback($task, $intent);
        $detail = $this->executionTaskDetail($taskId, $hotelIds);
        $sourceVerified = (bool)($detail['evidence_truth']['source_verified'] ?? false);

        $attemptedAt = date('Y-m-d H:i:s');
        return [
            'status' => $sourceVerified ? 'source_readback_verified' : 'source_readback_missing',
            'task_id' => $taskId,
            'hotel_id' => (int)($task['hotel_id'] ?? 0),
            'review_at' => $reviewAt,
            'attempted_at' => $attemptedAt,
            'reconciled_at' => $sourceVerified ? $attemptedAt : null,
            'source_verified' => $sourceVerified,
            'outcome_status' => (string)($detail['outcome_truth']['status'] ?? 'unverified'),
            'result_status' => (string)($detail['result_status'] ?? 'observing'),
            'sop_candidate_status' => (string)($detail['sop_candidate']['status'] ?? 'not_ready'),
            'next_action' => $sourceVerified
                ? 'human_confirm_review_result'
                : 'collect_same_hotel_platform_metric_readback',
        ];
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
        $isRevenueNodeCheck = $evidenceType === 'revenue_node_check';
        if (!$this->executionEvidenceCanBeAddedAtStatus($evidenceType, $taskStatus)) {
            throw new \InvalidArgumentException('execution task must be executed before evidence can be added');
        }

        $payload = [
            'task_id' => $taskId,
            'evidence_type' => $evidenceType,
            'before' => $this->arrayValue($evidence['before'] ?? []),
            'after' => $this->arrayValue($evidence['after'] ?? []),
            'attachment_path' => trim((string)($evidence['attachment_path'] ?? '')),
            'platform_response' => $this->buildExecutionEvidencePlatformResponse($evidence, $task, $intent),
            'remark' => trim((string)($evidence['remark'] ?? '')),
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if ($payload['evidence_type'] === 'compensation_receipt') {
            $this->assertCompensationReceiptIsCurrentAndComplete($task, $payload['platform_response']);
        }
        if ($isRevenueNodeCheck
            && (($payload['platform_response']['node_record']['contract_version'] ?? '') !== 'operation_revenue_node.v2')
        ) {
            throw new \InvalidArgumentException('revenue node check requires operation_revenue_node.v2 identity');
        }
        $this->insertExecutionEvidence($payload);

        return $this->executionTaskDetail($taskId, $hotelIds);
    }

    private function executionEvidenceCanBeAddedAtStatus(string $evidenceType, string $taskStatus): bool
    {
        if ($evidenceType === 'revenue_node_check') {
            return in_array($taskStatus, ['pending_execute', 'executing', 'executed'], true);
        }

        return $taskStatus === 'executed'
            || ($evidenceType === 'compensation_receipt' && $taskStatus === 'failed');
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
        $normalizedIntent = $this->normalizeExecutionIntentRow($intentRow);
        $normalizedTask = $this->normalizeExecutionTaskRow($task);
        $normalizedEvidenceRows = array_map([$this, 'normalizeExecutionEvidenceRow'], $evidenceRows);
        $reviewAvailableAt = $this->executionReviewAvailableAt($normalizedIntent, $normalizedEvidenceRows);
        if ($reviewAvailableAt !== ''
            && ($reviewAvailableTimestamp = strtotime($reviewAvailableAt)) !== false
            && time() < $reviewAvailableTimestamp
        ) {
            throw new \InvalidArgumentException('execution review is not available before ' . $reviewAvailableAt);
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

        $isOperationOptimizer = strtolower(trim((string)($normalizedIntent['source_module'] ?? '')))
            === OperationOptimizationExecutionBridgeService::SOURCE_MODULE;
        $isTemporalForecast = strtolower(trim((string)($normalizedIntent['source_module'] ?? '')))
            === TemporalInsightService::OPERATION_SOURCE_MODULE;
        $isSavedOtaDiagnosis = strtolower(trim((string)($normalizedIntent['source_module'] ?? '')))
            === 'ota_diagnosis_saved';
        if (in_array($manualResultStatus, ['success', 'near_success'], true)
            || $isOperationOptimizer
            || $isTemporalForecast
            || ($isSavedOtaDiagnosis && $manualResultStatus === 'failed')
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
        if ($isSavedOtaDiagnosis
            && in_array($manualResultStatus, ['success', 'near_success', 'failed'], true)
            && !$hasSourceVerifiedReviewEvidence
        ) {
            throw new \InvalidArgumentException(
                'same-hotel, same-platform and same-metric scheduled OTA readback is required before terminal review'
            );
        }
        if (($isOperationOptimizer || $isSavedOtaDiagnosis)
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

        if ($sourceModule === 'ota_diagnosis_saved') {
            return $this->buildSavedOtaDiagnosisSourceVerifiedReadbackPayload(
                $task,
                $intent,
                $intentPlatform,
                $platform,
                $expectedMetric,
                $objectType,
                $dateStart,
                $dateEnd,
                $executedTimestamp
            );
        }

        $baselineDate = date('Y-m-d', $executedTimestamp);
        $reviewDate = date('Y-m-d', strtotime($baselineDate . ' +1 day'));
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
                'measurement_policy' => 'target_date_gap_closure_verified_after_execution',
            ],
            'remark' => 'system-generated readback from persisted and strictly re-read OTA facts',
            'created_by' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * A saved OTA diagnosis is a single-business-date decision. Its review must
     * preserve that business date as the baseline and use the human-approved
     * review window, rather than silently substituting the execution date and
     * the following calendar day. Historical intent data stays immutable; a
     * later source readback records any baseline reconciliation explicitly.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $intent
     * @return array<string, mixed>|null
     */
    private function buildSavedOtaDiagnosisSourceVerifiedReadbackPayload(
        array $task,
        array $intent,
        string $intentPlatform,
        string $readbackPlatform,
        string $expectedMetric,
        string $objectType,
        string $dateStart,
        string $dateEnd,
        int $executedTimestamp
    ): ?array {
        $taskId = (int)($task['id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $reviewTimestamp = $this->savedOtaDiagnosisReviewTimestamp($intent);
        if ($taskId <= 0
            || $hotelId <= 0
            || $dateStart !== $dateEnd
            || $reviewTimestamp === null
            || $reviewTimestamp <= $executedTimestamp
            || time() < $reviewTimestamp
        ) {
            return null;
        }

        $baselineDate = $dateStart;
        $reviewDate = date('Y-m-d', $reviewTimestamp);
        if ($reviewDate <= $baselineDate) {
            return null;
        }

        $baselineRows = $this->trustedExecutionReadbackRows(
            $this->onlineRows([$hotelId], $baselineDate, $baselineDate),
            $readbackPlatform
        );
        $reviewRows = $this->trustedExecutionReadbackRows(
            $this->onlineRows([$hotelId], $reviewDate, $reviewDate),
            $readbackPlatform,
            $executedTimestamp
        );
        $baselineRows = $this->canonicalExecutionReadbackRows($baselineRows, $expectedMetric);
        $reviewRows = $this->canonicalExecutionReadbackRows($reviewRows, $expectedMetric);
        if (!$this->trustedExecutionReadbackPlatformCoverage($baselineRows, $readbackPlatform)
            || !$this->trustedExecutionReadbackPlatformCoverage($reviewRows, $readbackPlatform)
        ) {
            return null;
        }

        $baselineIds = $this->executionReadbackRowIds($baselineRows);
        $reviewIds = $this->executionReadbackRowIds($reviewRows);
        $declaredIds = $this->savedOtaDiagnosisDeclaredOnlineRowIds($intent);
        if ($baselineIds === []
            || $reviewIds === []
            || $declaredIds === []
        ) {
            return null;
        }

        $beforeValue = $this->executionReadbackMetricValue(
            $expectedMetric,
            $baselineRows,
            $hotelId,
            $baselineDate
        );
        $afterValue = $this->executionReadbackMetricValue(
            $expectedMetric,
            $reviewRows,
            $hotelId,
            $reviewDate
        );
        if ($beforeValue === null || $afterValue === null) {
            return null;
        }

        $readbackTimestamp = 0;
        foreach ($reviewRows as $row) {
            $readbackTimestamp = max($readbackTimestamp, $this->executionReadbackRowTimestamp($row));
        }
        if ($readbackTimestamp < $reviewTimestamp) {
            return null;
        }

        $sourceIds = array_values(array_unique(array_merge($baselineIds, $reviewIds)));
        sort($sourceIds, SORT_NUMERIC);
        $declaredRefs = array_map(
            static fn(int $id): string => 'online_daily_data#' . $id,
            $declaredIds
        );
        $baselineRefs = array_map(
            static fn(int $id): string => 'online_daily_data#' . $id,
            $baselineIds
        );
        $newlyVerifiedBaselineIds = array_values(array_diff($baselineIds, $declaredIds));
        $newlyVerifiedBaselineRefs = array_map(
            static fn(int $id): string => 'online_daily_data#' . $id,
            $newlyVerifiedBaselineIds
        );
        $excludedDeclaredRefs = array_values(array_diff($declaredRefs, $baselineRefs));
        $baselineReferenceStatus = $newlyVerifiedBaselineRefs === []
            ? 'declared_refs_match'
            : 'trusted_same_scope_reconciliation';

        $currentValue = is_array($intent['current_value'] ?? null) ? $intent['current_value'] : [];
        $intentSnapshotValue = $this->executionIntentMetricSnapshotValue($expectedMetric, $currentValue);
        $reconciliationStatus = $intentSnapshotValue === null
            ? 'intent_snapshot_missing'
            : (abs($intentSnapshotValue - $beforeValue) <= 0.0001
                ? 'matched_intent_snapshot'
                : 'source_readback_supersedes_intent_snapshot');

        return [
            'task_id' => $taskId,
            'evidence_type' => 'source_verified_metric_readback',
            'before' => [$expectedMetric => $beforeValue],
            'after' => [$expectedMetric => $afterValue],
            'attachment_path' => '',
            'platform_response' => [
                'verification_authority' => 'system_readback',
                'source' => 'online_daily_data',
                'source_ref' => 'online_daily_data#' . implode(',', $sourceIds),
                'baseline_source_ref' => 'online_daily_data#' . implode(',', $baselineIds),
                'followup_source_ref' => 'online_daily_data#' . implode(',', $reviewIds),
                'declared_baseline_source_refs' => $declaredRefs,
                'reconciled_baseline_source_refs' => $baselineRefs,
                'newly_verified_baseline_source_refs' => $newlyVerifiedBaselineRefs,
                'excluded_declared_source_refs' => $excludedDeclaredRefs,
                'baseline_source_reference_status' => $baselineReferenceStatus,
                'system_hotel_id' => $hotelId,
                'platform' => $intentPlatform,
                'object_type' => $objectType,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'baseline_date' => $baselineDate,
                'review_date' => $reviewDate,
                'scheduled_review_at' => date('Y-m-d H:i:s', $reviewTimestamp),
                'metric_key' => $expectedMetric,
                'database_written' => true,
                'readback_verified' => true,
                'readback_count' => count($reviewRows),
                'readback_at' => date('Y-m-d H:i:s', $readbackTimestamp),
                'validation_status' => 'verified',
                'source_validation_status' => 'source_verified',
                'failure_reason' => '',
                'baseline_reconciliation_status' => $reconciliationStatus,
                'intent_snapshot_value' => $intentSnapshotValue,
                'source_readback_value' => $beforeValue,
                'original_intent_evidence_preserved' => true,
                'historical_intent_mutated' => false,
                'causality_claimed' => false,
                'effect_evidence_status' => 'observed_not_attributed',
                'measurement_policy' => 'diagnosis_business_date_to_scheduled_same_metric_review',
            ],
            'remark' => 'system-generated scheduled same-metric OTA readback; observation only and historical intent remains immutable',
            'created_by' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string, mixed> $intent */
    private function savedOtaDiagnosisReviewTimestamp(array $intent): ?int
    {
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $workflowSchedule = is_array($targetValue['workflow_schedule'] ?? null)
            ? $targetValue['workflow_schedule']
            : [];
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $evidenceSchedule = is_array($evidence['workflow_schedule'] ?? null)
            ? $evidence['workflow_schedule']
            : [];
        foreach ([
            $workflowSchedule['review_at'] ?? null,
            $targetValue['review_at'] ?? null,
            $evidenceSchedule['review_at'] ?? null,
            $evidence['review_at'] ?? null,
        ] as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim(str_replace('T', ' ', (string)$value));
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/D', $text) !== 1) {
                continue;
            }
            if (strlen($text) === 16) {
                $text .= ':00';
            }
            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $text,
                new DateTimeZone('Asia/Shanghai')
            );
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false
                && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))
                && $date->format('Y-m-d H:i:s') === $text
            ) {
                return $date->getTimestamp();
            }
        }

        return null;
    }

    /**
     * Traffic rows are cumulative snapshots, not additive facts. Keep one row
     * per hotel/channel/date: a final historical row wins; otherwise use the
     * newest realtime row. Missing metrics remain missing and are never filled
     * from a superseded snapshot.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function canonicalExecutionReadbackRows(array $rows, string $metric): array
    {
        if (!in_array(strtolower(trim($metric)), [
            'detail_rate',
            'view_rate',
            'flow_rate',
            'conversion',
            'conversion_rate',
            'order_rate',
        ], true)) {
            return array_values($rows);
        }

        $selected = [];
        foreach ($rows as $row) {
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

            $hotelId = (int)($row['system_hotel_id'] ?? 0);
            $channel = $this->normalizeOtaChannel((string)($row['source'] ?? $row['platform'] ?? ''));
            $date = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            if ($hotelId <= 0 || $channel === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
                continue;
            }

            $key = $hotelId . '|' . $channel . '|' . $date;
            $current = $selected[$key] ?? null;
            if (!is_array($current)
                || $this->canonicalExecutionReadbackRowRank($row) > $this->canonicalExecutionReadbackRowRank($current)
            ) {
                $selected[$key] = $row;
            }
        }

        return array_values($selected);
    }

    /** @param array<string, mixed> $row @return array<int, int> */
    private function canonicalExecutionReadbackRowRank(array $row): array
    {
        $period = strtolower(trim((string)($row['data_period'] ?? '')));
        $finalRank = (int)($row['is_final'] ?? 0) === 1 || $period === 'historical_daily' ? 2 : 1;
        $identityRank = strtolower(trim((string)($row['compare_type'] ?? ''))) === 'self' ? 1 : 0;
        return [
            $finalRank,
            $identityRank,
            $this->canonicalExecutionReadbackTimestamp($row),
            (int)($row['id'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $row */
    private function canonicalExecutionReadbackTimestamp(array $row): int
    {
        $snapshotTime = trim((string)($row['snapshot_time'] ?? ''));
        if ($snapshotTime !== '' && ($timestamp = strtotime($snapshotTime)) !== false) {
            return $timestamp;
        }
        $collectedTimestamp = $this->trustedOnlineCollectionTimestamp($row);
        if ($collectedTimestamp > 0) {
            return $collectedTimestamp;
        }
        foreach (['readback_verified_at', 'update_time', 'create_time'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '' && ($timestamp = strtotime($value)) !== false) {
                return $timestamp;
            }
        }
        return 0;
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, int> */
    private function executionReadbackRowIds(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => max(0, (int)($row['id'] ?? 0)),
            $rows
        ))));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<string, mixed> $intent @return array<int, int> */
    private function savedOtaDiagnosisDeclaredOnlineRowIds(array $intent): array
    {
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $ids = [];
        foreach ((array)($evidence['evidence_refs'] ?? []) as $reference) {
            if (is_array($reference)) {
                if (strtolower(trim((string)($reference['table'] ?? ''))) === 'online_daily_data') {
                    $id = (int)($reference['record_id'] ?? $reference['id'] ?? 0);
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
                $reference = $reference['source_ref'] ?? $reference['ref'] ?? '';
            }
            if (!is_scalar($reference)) {
                continue;
            }
            if (preg_match('/^online_daily_data#([0-9]+(?:,[0-9]+)*)$/D', trim((string)$reference), $matches) !== 1) {
                continue;
            }
            foreach (explode(',', (string)$matches[1]) as $id) {
                if ((int)$id > 0) {
                    $ids[] = (int)$id;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<string, mixed> $currentValue */
    private function executionIntentMetricSnapshotValue(string $metric, array $currentValue): ?float
    {
        $keys = match (strtolower(trim($metric))) {
            'revenue', 'avg_revenue', 'amount', 'income' => ['revenue', 'avg_revenue', 'amount', 'income'],
            'orders', 'avg_orders', 'order_count', 'book_order_num' => ['orders', 'avg_orders', 'order_count', 'book_order_num'],
            'room_nights', 'avg_room_nights' => ['room_nights', 'avg_room_nights'],
            'adr', 'avg_adr' => ['adr', 'avg_adr'],
            'occ', 'occupancy', 'avg_occ' => ['occ', 'occupancy', 'avg_occ'],
            'detail_rate', 'view_rate', 'flow_rate' => ['detail_rate', 'view_rate', 'flow_rate'],
            'conversion', 'conversion_rate', 'order_rate' => ['order_rate', 'conversion_rate', 'conversion'],
            'avg_psi_score' => ['avg_psi_score'],
            default => [$metric],
        };
        foreach ($keys as $key) {
            if (array_key_exists($key, $currentValue) && is_numeric($currentValue[$key])) {
                return (float)$currentValue[$key];
            }
        }
        return null;
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
        $task['review_available_at'] = $this->executionReviewAvailableAt($intent, $task['evidence']);
        $task['review_available_on'] = $task['review_available_at'] !== ''
            ? substr($task['review_available_at'], 0, 10)
            : $this->executionReviewAvailableOn($task['evidence']);
        $reviewAvailableTimestamp = $task['review_available_at'] !== ''
            ? strtotime($task['review_available_at'])
            : false;
        $task['review_is_available'] = $task['review_available_on'] === ''
            || ($reviewAvailableTimestamp !== false
                ? time() >= $reviewAvailableTimestamp
                : $task['review_available_on'] <= date('Y-m-d'));
        $task['sop_candidate'] = $this->executionFlowReadService->buildSopCandidate(
            $intent,
            $task,
            $task['evidence_truth'],
            $task['outcome_truth'],
            (string)($task['result_status'] ?? 'observing')
        );

        return $task;
    }

    private function executionReviewAvailableOn(array $evidenceRows): string
    {
        return $this->executionFlowReadService->reviewAvailableOn($evidenceRows);
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<int, array<string, mixed>> $evidenceRows
     */
    private function executionReviewAvailableAt(array $intent, array $evidenceRows): string
    {
        if (strtolower(trim((string)($intent['source_module'] ?? ''))) === 'ota_diagnosis_saved') {
            $scheduledTimestamp = $this->savedOtaDiagnosisReviewTimestamp($intent);
            if ($scheduledTimestamp !== null) {
                return date('Y-m-d H:i:s', $scheduledTimestamp);
            }
        }

        $availableOn = $this->executionReviewAvailableOn($evidenceRows);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $availableOn) === 1
            ? $availableOn . ' 00:00:00'
            : '';
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

    private function buildExecutionEvidencePlatformResponse(
        array $evidence,
        array $task = [],
        array $intent = []
    ): array
    {
        $platformResponse = $this->arrayValue($evidence['platform_response'] ?? []);
        if (array_key_exists('node_record', $platformResponse)) {
            $platformResponse['node_record'] = $this->normalizeExecutionNodeRecord(
                $this->arrayValue($platformResponse['node_record']),
                $task,
                $intent
            );
        }
        foreach (['operator_execution_evidence', 'operator_roi_evidence'] as $key) {
            $operatorEvidence = $this->arrayValue($evidence[$key] ?? []);
            if ($operatorEvidence !== []) {
                $platformResponse[$key] = $operatorEvidence;
            }
        }

        return $platformResponse;
    }

    /** @param array<string, mixed> $record @return array<string, string> */
    private function normalizeExecutionNodeRecord(array $record, array $task = [], array $intent = []): array
    {
        if ($record === []) {
            throw new \InvalidArgumentException('revenue node record is empty');
        }

        $required = [
            'recorded_at',
            'operating_period',
            'source_scope',
            'room_status_alignment',
            'data_quality_status',
            'metric_definition',
            'comparison_basis',
            'progress_status',
            'judgment_basis',
            'success_criteria',
            'stop_condition',
        ];
        foreach ($required as $field) {
            if (trim((string)($record[$field] ?? '')) === '') {
                throw new \InvalidArgumentException('revenue node record missing required field: ' . $field);
            }
        }
        $contractVersion = trim((string)($record['contract_version'] ?? ''));
        if (!in_array($contractVersion, ['operation_revenue_node.v1', 'operation_revenue_node.v2'], true)) {
            throw new \InvalidArgumentException('revenue node record contract version is invalid');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', (string)$record['recorded_at']) !== 1) {
            throw new \InvalidArgumentException('revenue node recorded_at is invalid');
        }

        $enums = [
            'operating_period' => ['weekday', 'weekend', 'holiday', 'special_event'],
            'source_scope' => ['pms_ota_cross_check', 'pms', 'ctrip', 'meituan', 'manual_other'],
            'room_status_alignment' => ['operator_confirmed', 'mismatch', 'unverified'],
            'data_quality_status' => ['manual_confirmed', 'unverified', 'mismatch'],
            'progress_status' => ['normal', 'too_fast', 'too_slow', 'insufficient_evidence'],
        ];
        foreach ($enums as $field => $allowed) {
            if (!in_array((string)$record[$field], $allowed, true)) {
                throw new \InvalidArgumentException('revenue node record field is invalid: ' . $field);
            }
        }

        $normalized = ['contract_version' => $contractVersion];
        if ($contractVersion === 'operation_revenue_node.v2') {
            $systemHotelId = (int)($record['system_hotel_id'] ?? 0);
            $businessDate = trim((string)($record['business_date'] ?? ''));
            $taskHotelId = (int)($task['hotel_id'] ?? 0);
            $intentHotelId = (int)($intent['hotel_id'] ?? 0);
            $intentBusinessDate = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
            if ($systemHotelId <= 0) {
                throw new \InvalidArgumentException('revenue node record system_hotel_id is required');
            }
            if ($businessDate === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate) !== 1) {
                throw new \InvalidArgumentException('revenue node record business_date is required');
            }
            if ($taskHotelId <= 0
                || $intentHotelId <= 0
                || $systemHotelId !== $taskHotelId
                || $systemHotelId !== $intentHotelId
            ) {
                throw new \InvalidArgumentException('revenue node record system_hotel_id does not match execution task');
            }
            if ($intentBusinessDate === '' || $businessDate !== $intentBusinessDate) {
                throw new \InvalidArgumentException('revenue node record business_date does not match execution intent');
            }
            $normalized['system_hotel_id'] = (string)$systemHotelId;
            $normalized['business_date'] = $businessDate;
        }
        foreach (array_merge($required, ['special_event', 'metric_snapshot', 'primary_risk']) as $field) {
            $normalized[$field] = trim((string)($record[$field] ?? ''));
        }

        return $normalized;
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

}
