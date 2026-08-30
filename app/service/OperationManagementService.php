<?php
declare(strict_types=1);

namespace app\service;

use app\service\operation\ExecutionOutcomeService;
use app\service\operation\ExecutionFlowReadService;
use app\service\operation\OperationEffectReviewService;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;
use Throwable;

class OperationManagementService
{
    use \app\service\operation\OperationSnapshotConcern;
    use \app\service\operation\OperationAlertConcern;
    use \app\service\operation\OperationAlertAnalysisConcern;
    use \app\service\operation\OperationExecutionReceiptConcern;
    use \app\service\operation\OperationEffectReadbackConcern;
    use \app\service\operation\OperationExecutionTenantConcern;
    use \app\service\operation\OperationExecutionPersistenceConcern;
    use \app\service\operation\OperationActionLifecycleConcern;
    use \app\service\operation\OperationExecutionAssigneeConcern;

    private RevenuePricingRecommendationService $pricingRecommendationService;
    private ExecutionOutcomeService $executionOutcomeService;
    private ExecutionFlowReadService $executionFlowReadService;
    private OperationOptimizationReviewService $operationOptimizationReviewService;
    /** @var null|callable(int,int,string,string):array<string,mixed> */
    private $temporalForecastReadbackResolver;

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
        ?OperationOptimizationReviewService $operationOptimizationReviewService = null,
        ?callable $temporalForecastReadbackResolver = null
    )
    {
        $this->pricingRecommendationService = $pricingRecommendationService ?? new RevenuePricingRecommendationService();
        $this->executionOutcomeService = $executionOutcomeService ?? new ExecutionOutcomeService();
        $this->executionFlowReadService = $executionFlowReadService
            ?? new ExecutionFlowReadService($this->executionOutcomeService);
        $this->operationOptimizationReviewService = $operationOptimizationReviewService
            ?? new OperationOptimizationReviewService();
        $this->temporalForecastReadbackResolver = $temporalForecastReadbackResolver;
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

    /** @return array{code:string,message:string,migration_required:bool}|null */
    private function operationAlertTenantSchemaGap(): ?array
    {
        return $this->operationTenantSchemaGap(
            'operation_alerts',
            'operation_alert_tenant_scope_missing',
            'operation alert tenant scope table is unavailable',
            'operation_alerts_tenant_schema_missing',
            'operation alerts must expose tenant_id and hotel_id with authoritative hotel tenant scope'
        );
    }

    /** @return array{code:string,message:string,migration_required:bool}|null */
    private function priceSuggestionTenantSchemaGap(): ?array
    {
        return $this->operationTenantSchemaGap(
            'price_suggestions',
            'price_suggestions_tenant_scope_missing',
            'price suggestion tenant scope table is unavailable',
            'price_suggestions_tenant_schema_missing',
            'price suggestions must expose tenant_id and hotel_id with authoritative hotel tenant scope'
        );
    }

    private function operationTenantSchemaGap(
        string $table, string $scopeCode, string $scopeMessage, string $columnCode, string $columnMessage
    ): ?array {
        if (!$this->tableExists($table)) {
            return null;
        }
        foreach ([$table => ['tenant_id', 'hotel_id'], 'hotels' => ['id', 'tenant_id']] as $name => $columns) {
            if (!$this->tableExists($name)) {
                return $this->operationAlertMigrationGap($scopeCode, $scopeMessage);
            }
            foreach ($columns as $column) {
                if (!$this->executionTenantSchemaHasColumn($name, $column)) {
                    return $this->operationAlertMigrationGap($columnCode, $columnMessage);
                }
            }
        }
        return null;
    }

    private function operationAlertMigrationGap(string $code, string $message): array
    {
        return ['code' => $code, 'message' => 'migration_required: ' . $message, 'migration_required' => true];
    }

    private function scopeOperationAlertQueryToCurrentTenant(mixed $query): mixed
    {
        return $this->scopeOperationTenantQuery($query, 'operation_alerts', 'operation_alert_hotel');
    }

    private function scopePriceSuggestionQueryToCurrentTenant(mixed $query): mixed
    {
        return $this->scopeOperationTenantQuery($query, 'price_suggestions', 'price_suggestion_hotel');
    }

    private function scopeOperationTenantQuery(mixed $query, string $table, string $alias): mixed
    {
        $sourceTable = Db::name($table)->getTable();
        $hotelTable = Db::name('hotels')->getTable();
        return $query->whereExists(static function ($hotelQuery) use ($sourceTable, $hotelTable, $alias): void {
            $hotelQuery->table([$hotelTable => $alias])
                ->whereColumn($alias . '.id', $sourceTable . '.hotel_id')
                ->whereColumn($alias . '.tenant_id', $sourceTable . '.tenant_id')
                ->where($alias . '.tenant_id', '>', 0);
        });
    }

    private function operationAlertSchemaGapResponse(array $gap, int $hotelId): array
    {
        return [
            'list' => [], 'unread_count' => 0, 'data_status' => 'migration_required', 'data_gaps' => [$gap],
            'selected_hotel_id' => $hotelId, 'generated_for_date' => $this->operationShanghaiToday(),
            'scope' => 'single_hotel', 'capabilities' => ['can_execute' => false, 'can_mark_read' => false],
        ];
    }

    private function normalizeAlertRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['tenant_id'] = (int)($row['tenant_id'] ?? 0);
        $row['hotel_id'] = (int)$row['hotel_id'];
        $row['raw_data'] = $this->decodeJson((string)($row['raw_data'] ?? ''));
        $row['action_suggestion'] = $this->normalizeAlertSuggestion($row);
        return $row;
    }

    private function alertExecutionBridgeFromIntent(array $intent): array
    {
        return [
            'can_convert' => false, 'linked' => (int)($intent['id'] ?? 0) > 0,
            'intent_id' => (int)($intent['id'] ?? 0), 'intent_status' => (string)($intent['status'] ?? ''),
            'blocked_reason' => (string)($intent['blocked_reason'] ?? ''), 'unavailable_reason' => '',
        ];
    }

    /** Stable mutation lock order: hotels ascending -> alerts ascending. */
    private function withOperationAlertMutationAuthorization(array $alertIds, array $hotelIds, callable $mutation): mixed
    {
        if (($gap = $this->operationAlertTenantSchemaGap()) !== null) {
            throw new \RuntimeException($gap['message']);
        }
        $alertIds = array_values(array_unique(array_filter(array_map('intval', $alertIds))));
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds))));
        sort($alertIds);
        sort($hotelIds);
        if ($alertIds === [] || $hotelIds === []) {
            return $mutation([]);
        }
        return Db::transaction(function () use ($alertIds, $hotelIds, $mutation): mixed {
            try {
                $hotels = Db::name('hotels')->whereIn('id', $hotelIds)->order('id', 'asc')->lock(true)->select()->toArray();
            } catch (Throwable $exception) {
                throw new \RuntimeException('migration_required: operation alert hotel tenant scope cannot be read', 0, $exception);
            }
            $tenantByHotel = [];
            foreach ($hotels as $hotel) {
                $id = (int)($hotel['id'] ?? 0);
                $tenant = (int)($hotel['tenant_id'] ?? 0);
                if ($id > 0 && $tenant > 0) {
                    $tenantByHotel[$id] = $tenant;
                }
            }
            try {
                $alerts = Db::name('operation_alerts')->whereIn('id', $alertIds)->whereIn('hotel_id', $hotelIds)
                    ->whereNull('deleted_at')->order('id', 'asc')->lock(true)->select()->toArray();
            } catch (Throwable $exception) {
                throw new \RuntimeException('migration_required: current-tenant operation alerts cannot be locked', 0, $exception);
            }
            return $mutation(array_values(array_filter($alerts, static function (array $alert) use ($tenantByHotel): bool {
                $hotelId = (int)($alert['hotel_id'] ?? 0);
                return (int)($alert['tenant_id'] ?? 0) > 0
                    && (int)($alert['tenant_id'] ?? 0) === (int)($tenantByHotel[$hotelId] ?? 0);
            })));
        });
    }

    public function fullData(array $hotelIds, ?int $hotelId, string $date): array
    {
        $hotelIds = $this->scopeHotelIdsForSelection($hotelIds, $hotelId);
        [$daily, $online] = [$this->dailyReportRows($hotelIds, $date, $date), $this->onlineRows($hotelIds, $date, $date)];
        $summary = $this->buildSummaryFromRows($daily, $online, $hotelIds, $hotelId, $date);
        $online = $this->scopeOnlineRowsToCurrentTenant($online, $hotelIds)['rows'];
        [$ota, $serviceQuality] = [$this->buildOtaFromRows($online), $this->buildServiceQualityFromRows($online)];
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
        if (($tenantSchemaGap = $this->operationActionTrackTenantSchemaGap()) !== null) {
            return $this->operationActionTrackSchemaGapResponse($tenantSchemaGap);
        }

        $query = Db::name('operation_action_tracks')->whereNull('deleted_at');
        if ($hotelId !== null && $hotelId > 0) {
            $query->where('hotel_id', $hotelId);
        } elseif (!empty($hotelIds)) {
            $query->whereIn('hotel_id', $hotelIds);
        }

        $rows = $this->scopeOperationActionTrackQueryToCurrentTenant($query)
            ->order('id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
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
                'target_change_rate' => ($row['target_change_rate'] ?? null) === null
                    ? null
                    : (float)$row['target_change_rate'],
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
            return $this->executionFlowSchemaGapResponse(['code' => 'operation_execution_intents_missing', 'message' => 'execution intent table missing']);
        }
        if (($tenantSchemaGap = $this->executionIntentTenantSchemaGap()) !== null) {
            return $this->executionFlowSchemaGapResponse($tenantSchemaGap);
        }
        if (($dependencySchemaGap = $this->executionFlowDependencySchemaGap()) !== null) {
            return $this->executionFlowSchemaGapResponse($dependencySchemaGap);
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

        $query = $this->scopeExecutionIntentQueryToCurrentHotelTenant($query);
        ['assigneeId' => $assigneeId, 'matchedTotal' => $matchedTotal, 'limit' => $limit, 'intentRows' => $intentRows, 'truncated' => $truncated] = $this->prepareExecutionFlowQuery($query, $filters);
        if (empty($intentRows)) {
            return $this->emptyExecutionFlowResponse();
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
        $assigneeScope = $this->scopeExecutionFlowItemsToAssignee($items, $assigneeId, $limit, $matchedTotal, $truncated, $dataGaps);
        ['items' => $items, 'summaryItems' => $summaryItems, 'matchedTotal' => $matchedTotal, 'truncated' => $truncated] = $assigneeScope;
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

        $summary = $this->buildExecutionFlowSummary($summaryItems);

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
        $item = $this->executionFlowReadService->buildItem($intent, $tasks, $evidence);
        $managedIntent = (new OperationActionLifecycleService())->decorateCurrentDatabaseAggregate(array_merge($intent, [
            'tasks' => $tasks,
        ]));
        if (is_array($managedIntent['action_management'] ?? null)) {
            $item['action_management'] = $managedIntent['action_management'];
            $item['lifecycle_status'] = (string)($managedIntent['action_management']['lifecycle']['status'] ?? '');
        }
        return $item;
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

        return (bool)$this->withOperationActionTrackMutationAuthorization(
            $id,
            $hotelIds,
            function (array $row) use ($id): bool {
                $before = $this->decodeJson((string)($row['before_data_json'] ?? ''));
                $after = $this->afterData($row);
                $result = $this->evaluateActionResult($row, $before, $after);
                $summary = '策略已结束，结果状态：' . $result['status'] . '，' . $result['message'];

                Db::name('operation_action_tracks')->where('id', $id)->update([
                    'status' => 'finished',
                    'after_data_json' => json_encode($after, JSON_UNESCAPED_UNICODE),
                    'result_status' => $result['status'],
                    'result_summary' => $summary,
                    'updated_at' => $this->operationShanghaiNow(),
                ]);

                return true;
            }
        );
    }

    public function buildPriceSuggestionExecutionIntentInput(array $suggestion, array $overrides = []): array
    {
        $this->assertExecutionPayloadHasNoCredentialMaterial([$suggestion, $overrides]);
        if ((int)($suggestion['status'] ?? 0) !== \app\model\PriceSuggestion::STATUS_APPROVED) {
            throw new \InvalidArgumentException('price suggestion must be approved before creating an execution intent');
        }
        $sourceSuggestion = $suggestion;
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

        $sourceBusinessDate = $this->normalizeExecutionDate((string)($sourceSuggestion['suggestion_date'] ?? ''));
        $shanghaiToday = $this->operationShanghaiToday();
        $requestedExecutionDate = trim((string)($overrides['execution_date'] ?? ''));
        $executionDate = $this->normalizeExecutionDate(
            $requestedExecutionDate !== '' ? $requestedExecutionDate : $shanghaiToday
        );
        if ($executionDate < $shanghaiToday) {
            throw new \InvalidArgumentException('计划执行日期不能早于今天');
        }
        $factors = $this->arrayValue($suggestion['factors'] ?? []);
        $manualReview = $this->latestManualReviewFromFactors($factors);
        $originalSuggestedPrice = (float)($suggestion['suggested_price'] ?? 0);
        $targetPrice = $this->manualApprovedPriceFromReview($manualReview) ?? $originalSuggestedPrice;
        $otaTargetMapping = (new PriceSuggestionOtaTargetMappingService())->confirmedMapping($sourceSuggestion, $overrides);

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
                'room_type_key' => $otaTargetMapping['room_type_key'],
                'rate_plan_key' => $otaTargetMapping['rate_plan_key'],
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
                'source_snapshot_digest' => SourceBackedExecutionIntentIdentityService::priceSuggestionSnapshotDigest($sourceSuggestion),
                'ota_target_mapping' => $otaTargetMapping,
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
        $executionDateField = array_key_exists('effective_date', $input)
            ? 'effective_date'
            : (array_key_exists('date_start', $input)
                ? 'date_start'
                : (array_key_exists('start_date', $input) ? 'start_date' : null));
        $effectiveDate = $executionDateField === null ? '' : (string)$input[$executionDateField];
        if ($executionDateField !== null && $effectiveDate === '') {
            throw new \InvalidArgumentException('execution date must be a valid YYYY-MM-DD calendar date');
        }
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
        $dateStart = $effectiveDate !== '' ? $effectiveDate : $this->operationShanghaiToday();
        $dateEnd = array_key_exists('date_end', $input)
            ? (string)$input['date_end']
            : (array_key_exists('end_date', $input) ? (string)$input['end_date'] : $dateStart);

        return [
            'source_module' => $this->canonicalExecutionSourceModule($input['source_module'] ?? 'manual'),
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
            'expected_delta' => array_key_exists('expected_delta', $input)
                ? ($input['expected_delta'] === null ? null : (float)$input['expected_delta'])
                : 0.0,
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
        if (!$this->hasNonEmptyValue($evidence)) {
            throw new \InvalidArgumentException('evidence is required');
        }
    }

    private function hasNonEmptyValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasNonEmptyValue($item)) {
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
            'metric_semantic',
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
        $evidenceType = strtolower(trim((string)($input['evidence_type'] ?? $evidence['evidence_type'] ?? 'manual')));
        $normalizedEvidenceContent = [];
        if ($evidence !== []) {
            $this->assertOperatorExecutionEvidenceBoundary($evidenceType, $evidence);
            $normalizedEvidenceContent = [
                'evidence_type' => $evidenceType,
                'before' => $this->arrayValue($evidence['before'] ?? []),
                'after' => $this->arrayValue($evidence['after'] ?? []),
                'attachment_path' => trim((string)($evidence['attachment_path'] ?? '')),
                'platform_response' => $this->buildExecutionEvidencePlatformResponse($evidence, $task, $intent),
                'remark' => trim((string)($evidence['remark'] ?? '')),
                'created_by' => $operatorId,
            ];
        }
        $terminalEvidenceIsMeaningful = $evidence !== []
            && self::isMeaningfulExecutionReceipt($normalizedEvidenceContent, $operatorId);
        $isTemporalForecast = in_array(
            strtolower(trim((string)($intent['source_module'] ?? ''))),
            [
                TemporalInsightService::OPERATION_SOURCE_MODULE,
                TemporalForecastTrialService::OPERATION_SOURCE_MODULE,
            ],
            true
        );
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
            && !$terminalEvidenceIsMeaningful
        ) {
            throw new \InvalidArgumentException('duplicate execution replay remains blocked until meaningful execution evidence is supplied');
        }
        $requestedTerminalStatus = in_array($status, ['executed', 'failed'], true);
        if ($requestedTerminalStatus && !$terminalEvidenceIsMeaningful) {
            $requestedStatus = $status;
            $status = 'blocked';
            $defaultBlockedReason = $requestedStatus === 'failed'
                ? 'meaningful execution failure evidence missing'
                : 'meaningful execution evidence missing';
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
        if ($evidence !== [] && !($requestedTerminalStatus && !$terminalEvidenceIsMeaningful)) {
            $evidencePayload = [
                'task_id' => (int)($task['id'] ?? 0),
                'evidence_type' => $evidenceType,
                'before' => $normalizedEvidenceContent['before'],
                'after' => $normalizedEvidenceContent['after'],
                'attachment_path' => $normalizedEvidenceContent['attachment_path'],
                'platform_response' => $normalizedEvidenceContent['platform_response'],
                'remark' => $normalizedEvidenceContent['remark'],
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
            'feasibility_report',
            'opening',
            'transfer_decision',
            OperatingNetworkService::EXECUTION_SOURCE_MODULE,
            TemporalInsightService::OPERATION_SOURCE_MODULE,
            TemporalForecastTrialService::OPERATION_SOURCE_MODULE,
            OperationOptimizationExecutionBridgeService::SOURCE_MODULE,
            OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
            RevenueCockpitActionContract::SOURCE_MODULE,
            OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
        ];
        if (in_array((string)$payload['source_module'], $reservedSources, true) && !$trustedReservedSource) {
            throw new \InvalidArgumentException('reserved execution source must be created from its scoped source endpoint');
        }
        $usesExpansionSource = $payload['source_module'] === 'expansion' || $payload['object_type'] === 'expansion';
        if ($usesExpansionSource
            && (!$trustedExpansionSource || $payload['source_module'] !== 'expansion' || $payload['object_type'] !== 'expansion')
        ) {
            throw new \InvalidArgumentException('expansion execution intent must be created from the scoped expansion record endpoint');
        }
        $payload['tenant_id'] = $this->tenantIdForHotel((int)$payload['hotel_id']);
        $creation = fn(array $lockedPayload): array => $this->persistExecutionIntentPayload(
            $lockedPayload,
            $hotelIds,
            $createdBy,
            $trustedExpansionSource,
            $trustedReservedSource,
            $trustedIdempotencyKey
        );
        if ($this->sourceBackedExecutionIntentSupports($payload)) {
            return $this->withSourceBackedExecutionIntentCreationAuthorization($payload, $hotelIds, $creation);
        }

        return $creation($payload);
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
            return $this->executionIntentListSchemaGapResponse(['code' => 'operation_execution_intents_missing', 'message' => 'execution intent table missing']);
        }
        if (($tenantSchemaGap = $this->executionIntentTenantSchemaGap()) !== null) {
            return $this->executionIntentListSchemaGapResponse($tenantSchemaGap);
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

        $query = $this->scopeExecutionIntentQueryToCurrentHotelTenant($query);
        $matchedTotal = (int)(clone $query)->count();
        $rows = $query->order('id', 'desc')->limit(100)->select()->toArray();
        $rows = $this->filterCurrentSourceBackedTenantRows($rows);
        return [
            'list' => array_map([$this, 'normalizeExecutionIntentRow'], $rows),
            'data_status' => $matchedTotal > count($rows) ? 'partial' : self::DATA_OK,
            'data_gaps' => $matchedTotal > count($rows) ? [['code' => 'operation_execution_intents_truncated', 'message' => 'execution intent list returned 100 of ' . $matchedTotal . ' matched intents']] : [],
            'matched_total' => $matchedTotal, 'returned_count' => count($rows),
            'truncated' => $matchedTotal > count($rows),
            'statistics' => ['execution_total_loaded' => $matchedTotal <= count($rows)],
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
                ->field('id,tenant_id,source_module,hotel_id')
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

        if (!is_array($row)) {
            return null;
        }
        if ($this->tableExists('hotels') && !$this->sourceBackedIntentTenantIsCurrent($row)
        ) {
            return null;
        }

        return $this->executionIntentDetail((int)($row['id'] ?? 0), $hotelIds);
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
            $query = Db::name('operation_execution_intents')
                ->where('idempotency_key', 'like', $baseKey . ':attempt:%')
                ->whereIn('hotel_id', $hotelIds)
                ->whereNull('deleted_at')
                ->field('id,idempotency_key');
            $rows = $this->scopeExecutionIntentQueryToCurrentHotelTenant($query)
                ->order('id', 'desc')
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

        return $this->withSourceBackedExecutionIntentApprovalAuthorization(
            $id,
            $hotelIds,
            function (array $authorization) use ($id, $hotelIds, $schedule, $updatedBy, $now): array {
                $row = $authorization['intent'];
                if ($this->sourceBackedExecutionIntentSupports($row)) {
                    $this->assertSourceBackedIntentCurrentWithAuthorization(
                        $this->normalizeExecutionIntentRow($row),
                        $authorization
                    );
                }
                $status = strtolower(trim((string)($row['status'] ?? '')));
                if (!in_array($status, ['draft', 'pending_approval'], true)) {
                    throw new \InvalidArgumentException('only draft or pending_approval execution intents can be rescheduled');
                }
                if ((array)($authorization['tasks'] ?? []) !== []) {
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

                return $this->executionIntentDetail($id, $hotelIds);
            }
        );
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
        return $this->withExecutionTaskMutationAuthorization(
            $taskId,
            $hotelIds,
            fn(array $context): array => $this->reconcileScheduledExecutionTaskAuthorized(
                $taskId,
                $hotelIds,
                $context
            )
        );
    }

    /** @param array{task:array<string,mixed>,intent:array<string,mixed>} $context */
    private function reconcileScheduledExecutionTaskAuthorized(int $taskId, array $hotelIds, array $context): array
    {
        $taskRow = $context['task'];
        $intentRow = $context['intent'];

        if ((string)($taskRow['status'] ?? '') !== 'executed') {
            throw new \InvalidArgumentException('execution task must be executed before scheduled readback');
        }
        $intent = $this->normalizeExecutionIntentRow($intentRow);
        $this->assertManagedActionSourceCurrent($intent);
        if (!in_array(strtolower(trim((string)($intent['source_module'] ?? ''))), [
            'ota_diagnosis_saved',
            OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
            RevenueCockpitActionContract::SOURCE_MODULE,
            OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
        ], true)) {
            throw new \InvalidArgumentException('scheduled readback currently supports saved OTA diagnosis and approved managed revenue actions only');
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

        $this->syncSourceVerifiedMetricReadback($task, $intent, $context);
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

    public function approveExecutionIntent(
        int $id,
        bool $approved,
        string $remark,
        int $userId,
        array $hotelIds,
        array $approvalInput = [],
        ?array $trustedAiReview = null
    ): array
    {
        if ($trustedAiReview !== null) {
            throw new \InvalidArgumentException(
                'AI review is advisory only; operation action approval requires an authenticated human confirmation'
            );
        }
        $this->assertExecutionPayloadHasNoCredentialMaterial([$remark, $approvalInput]);
        $this->ensureExecutionTables();
        $now = date('Y-m-d H:i:s');
        $status = $approved ? 'approved' : 'rejected';
        $approveLockedIntent = function (array $intent, ?array $authorization = null) use (
            $id,
            $status,
            $userId,
            $now,
            $remark,
            $approved,
            $hotelIds,
            $approvalInput,
            $trustedAiReview
        ): void {
            $lifecycle = new OperationActionLifecycleService();
            $managedAction = $lifecycle->isManagedIntent($intent);
            $normalizedReviewIntent = $this->normalizeExecutionIntentRow($intent);
            $dailyManagedAction = $lifecycle->isDailyOneThingIntent($normalizedReviewIntent);
            $isAiReview = is_array($trustedAiReview);
            $nextStatus = !$approved && $managedAction
                ? ($dailyManagedAction ? 'blocked' : 'cancelled')
                : $status;
            if (($intent['status'] ?? '') === 'blocked') {
                throw new \InvalidArgumentException('blocked execution intent cannot be approved');
            }
            if (($intent['status'] ?? '') !== 'pending_approval') {
                throw new \InvalidArgumentException('execution intent must be pending_approval before review');
            }
            if ($isAiReview) {
                if (!$managedAction
                    || (string)($normalizedReviewIntent['source_module'] ?? '')
                        !== OperatingQuestionExecutionBridgeService::SOURCE_MODULE
                    || $userId !== 0
                ) {
                    throw new \InvalidArgumentException('AI independent review is limited to managed operating-question actions');
                }
                $this->assertExecutionPayloadHasNoCredentialMaterial([
                    $this->decodeJson((string)($intent['current_value_json'] ?? '')),
                    $this->decodeJson((string)($intent['target_value_json'] ?? '')),
                    $this->decodeJson((string)($intent['evidence_json'] ?? '')),
                    $trustedAiReview,
                ]);
                $this->assertAiDecisionIntentReadyForApproval($normalizedReviewIntent, $authorization);
                OperationActionAiReviewService::assertReviewContract(
                    $trustedAiReview,
                    $normalizedReviewIntent,
                    $approved
                );
            }
            if ($approved) {
                $this->assertExecutionPayloadHasNoCredentialMaterial([
                    $this->decodeJson((string)($intent['current_value_json'] ?? '')),
                    $this->decodeJson((string)($intent['target_value_json'] ?? '')),
                    $this->decodeJson((string)($intent['evidence_json'] ?? '')),
                ]);
                if (!$isAiReview) {
                    $this->assertAiDecisionIntentReadyForApproval($normalizedReviewIntent, $authorization);
                }
                if ($managedAction) {
                    if (!$isAiReview && $userId <= 0) {
                        throw new \InvalidArgumentException('managed operation action approval requires an authenticated user');
                    }
                    if (!$isAiReview) {
                        $lifecycle->assertHumanApprovalConfirmation(
                            $normalizedReviewIntent,
                            $approvalInput,
                            $userId
                        );
                    }
                    $lifecycle->assertNoActiveDuplicate($normalizedReviewIntent);
                }
            } elseif ($managedAction && trim($remark) === '') {
                throw new \InvalidArgumentException('managed operation action cancellation reason is required');
            }

            $targetValueJson = (string)($intent['target_value_json'] ?? '{}');
            $evidenceJson = (string)($intent['evidence_json'] ?? '{}');
            $expectedMetric = (string)($intent['expected_metric'] ?? '');
            $expectedDelta = ($intent['expected_delta'] ?? null) === null
                ? null
                : (float)$intent['expected_delta'];
            $approvalUpdate = [];
            $requiresFrozenEffectTarget = in_array(
                strtolower(trim((string)($intent['source_module'] ?? ''))),
                [
                    'ota_diagnosis_saved',
                    OperatingNetworkService::EXECUTION_SOURCE_MODULE,
                    OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
                    RevenueCockpitActionContract::SOURCE_MODULE,
                    OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
                ],
                true
            );
            if ($approved && $requiresFrozenEffectTarget) {
                $normalizedApprovalIntent = $this->normalizeExecutionIntentRow($intent);
                if ($managedAction) {
                    $targetValue = is_array($normalizedApprovalIntent['target_value'] ?? null)
                        ? $normalizedApprovalIntent['target_value']
                        : [];
                    $evidence = is_array($normalizedApprovalIntent['evidence'] ?? null)
                        ? $normalizedApprovalIntent['evidence']
                        : [];
                    $existingSchedule = is_array($targetValue['workflow_schedule'] ?? null)
                        ? $targetValue['workflow_schedule']
                        : [];
                    $schedule = $this->normalizePendingExecutionIntentSchedule([
                        'assignee_id' => $approvalInput['assignee_id']
                            ?? $existingSchedule['assignee_id']
                            ?? $targetValue['assignee_id']
                            ?? $userId,
                        'due_at' => $approvalInput['due_at'] ?? $existingSchedule['due_at'] ?? '',
                        'review_at' => $approvalInput['review_at'] ?? $existingSchedule['review_at'] ?? '',
                    ]);
                    $schedule['execution_start_at'] = $now;
                    $schedule['execution_end_at'] = (string)$schedule['due_at'];
                    $targetValue['assignee_id'] = (int)$schedule['assignee_id'];
                    $targetValue['workflow_schedule'] = $schedule;
                    $targetValue['execution_window'] = [
                        'start_at' => $now,
                        'end_at' => (string)$schedule['due_at'],
                        'timezone' => 'Asia/Shanghai',
                    ];
                    $evidence['workflow_schedule'] = $schedule;
                    $normalizedApprovalIntent['target_value'] = $targetValue;
                    $normalizedApprovalIntent['evidence'] = $evidence;
                    $approvalInput['review_business_date'] = substr((string)$schedule['review_at'], 0, 10);
                }
                if ($isAiReview) {
                    $approvalInput['_approval_mode'] = 'ai_independent_review';
                    $approvalInput['_ai_review_digest'] = strtolower(trim((string)($trustedAiReview['content_digest'] ?? '')));
                }
                $approvalContract = $this->buildSavedOtaDiagnosisApprovalTarget(
                    $normalizedApprovalIntent,
                    $approvalInput,
                    $userId,
                    $now
                );
                if ($isAiReview) {
                    $approvalContract['target_value']['approval_mode'] = 'ai_independent_review';
                    $approvalContract['target_value']['ai_review_digest'] = (string)$approvalInput['_ai_review_digest'];
                    $approvalContract['evidence']['ai_independent_review'] = $trustedAiReview;
                }
                if ($managedAction) {
                    $approvalAuthority = $isAiReview ? [
                        'mode' => 'ai_independent_review',
                        'decision_authority' => 'independent_ai',
                        'reviewer_contract_version' => OperationActionAiReviewService::CONTRACT_VERSION,
                        'reviewer_digest' => (string)($trustedAiReview['content_digest'] ?? ''),
                        'reviewer_provider' => (string)($trustedAiReview['provider'] ?? ''),
                        'reviewer_model' => (string)($trustedAiReview['model'] ?? ''),
                        'human_confirmation_required' => false,
                    ] : [];
                    $approvedCard = $lifecycle->freezeApprovedCard(
                        (array)($normalizedApprovalIntent['target_value']['action_card'] ?? []),
                        (array)($approvalContract['target_value']['workflow_schedule'] ?? []),
                        (array)($approvalContract['evidence']['approval_target'] ?? []),
                        $userId,
                        $now,
                        $approvalAuthority
                    );
                    $approvalContract['target_value'] = $isAiReview
                        ? $lifecycle->alignIndependentAiTaskProjection(
                            (array)$approvalContract['target_value'],
                            $approvedCard
                        )
                        : array_merge((array)$approvalContract['target_value'], [
                            'action_card' => $approvedCard,
                        ]);
                    $approvalContract['evidence']['action_card'] = $approvedCard;
                    if ($isAiReview) {
                        $approvalContract['evidence']['workflow_schedule'] =
                            (array)($approvalContract['target_value']['workflow_schedule'] ?? []);
                    }
                }
                $targetValueJson = json_encode(
                    $approvalContract['target_value'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $evidenceJson = json_encode(
                    $approvalContract['evidence'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $expectedMetric = (string)$approvalContract['expected_metric'];
                $expectedDelta = $approvalContract['expected_delta'];
                $approvalUpdate = [
                    'target_value_json' => $targetValueJson,
                    'evidence_json' => $evidenceJson,
                    'expected_metric' => $expectedMetric,
                    'expected_delta' => $expectedDelta,
                ];
            } elseif ($isAiReview) {
                $decisionIntent = $this->normalizeExecutionIntentRow($intent);
                $decisionTarget = is_array($decisionIntent['target_value'] ?? null)
                    ? $decisionIntent['target_value']
                    : [];
                $decisionEvidence = is_array($decisionIntent['evidence'] ?? null)
                    ? $decisionIntent['evidence']
                    : [];
                $decisionDigest = strtolower(trim((string)($trustedAiReview['content_digest'] ?? '')));
                $decisionTarget['approval_mode'] = 'ai_independent_review';
                $decisionTarget['ai_review_digest'] = $decisionDigest;
                $decisionEvidence['ai_independent_review'] = $trustedAiReview;
                $targetValueJson = json_encode(
                    $decisionTarget,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $evidenceJson = json_encode(
                    $decisionEvidence,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $approvalUpdate = [
                    'target_value_json' => $targetValueJson,
                    'evidence_json' => $evidenceJson,
                ];
            }

            $affected = (int)Db::name('operation_execution_intents')
                ->where('id', $id)
                ->where('hotel_id', (int)$intent['hotel_id'])
                ->where('status', 'pending_approval')
                ->whereNull('deleted_at')
                ->update(array_merge([
                'status' => $nextStatus,
                'approved_by' => $userId,
                'approved_at' => $now,
                'review_remark' => $remark,
                'updated_at' => $now,
            ], $approvalUpdate));
            if ($affected !== 1) {
                throw new \InvalidArgumentException('execution intent state changed; refresh before review');
            }

            if ($approved) {
                $taskExists = $authorization !== null
                    ? count((array)($authorization['tasks'] ?? []))
                    : (int)Db::name('operation_execution_tasks')
                        ->where('intent_id', $id)
                        ->where('hotel_id', (int)$intent['hotel_id'])
                        ->whereNull('deleted_at')
                        ->count();
                if ($taskExists !== 0) {
                    throw new \InvalidArgumentException(
                        'pending execution intent must have zero tasks before human approval'
                    );
                }
                $taskId = (int)Db::name('operation_execution_tasks')->insertGetId($this->withHotelTenantId([
                    'intent_id' => $id,
                    'hotel_id' => (int)$intent['hotel_id'],
                    'execution_mode' => 'manual',
                    'target_value_json' => $targetValueJson,
                    'current_value_json' => (string)($intent['current_value_json'] ?? '{}'),
                    'status' => 'pending_execute',
                    'created_at' => $now,
                    'updated_at' => $now,
                ], 'operation_execution_tasks', (int)$intent['hotel_id'],
                    $authorization !== null ? (int)($authorization['hotel']['tenant_id'] ?? 0) : null
                ));
                $taskReadback = Db::name('operation_execution_tasks')
                    ->where('id', $taskId)
                    ->where('intent_id', $id)
                    ->where('hotel_id', (int)$intent['hotel_id'])
                    ->whereNull('deleted_at')
                    ->find();
                $taskCount = (int)Db::name('operation_execution_tasks')
                    ->where('intent_id', $id)
                    ->where('hotel_id', (int)$intent['hotel_id'])
                    ->whereNull('deleted_at')
                    ->count();
                if (!is_array($taskReadback)
                    || $taskCount !== 1
                    || (string)($taskReadback['execution_mode'] ?? '') !== 'manual'
                    || (string)($taskReadback['status'] ?? '') !== 'pending_execute'
                    || !hash_equals($targetValueJson, (string)($taskReadback['target_value_json'] ?? ''))
                ) {
                    throw new \RuntimeException('human approval task save/readback cardinality check failed');
                }
            }
            if ($managedAction) {
                $eventIntent = $this->executionIntentDetail($id, $hotelIds);
                $eventTasks = is_array($eventIntent['tasks'] ?? null) ? $eventIntent['tasks'] : [];
                $eventTask = $eventTasks === [] ? [] : $eventTasks[count($eventTasks) - 1];
                $lifecycle->appendEvent(
                    $eventIntent,
                    (int)($eventTask['id'] ?? 0),
                    'pending_approval',
                    $approved ? 'approved' : ($dailyManagedAction ? 'blocked' : 'cancelled'),
                    $isAiReview
                        ? ($approved ? 'ai_review_approved' : 'ai_review_rejected')
                        : ($approved ? 'approved' : ($dailyManagedAction ? 'blocked' : 'cancelled')),
                    $userId,
                    [
                        'remark' => $remark,
                        'approval_mode' => $isAiReview ? 'ai_independent_review' : 'human',
                        'ai_review' => $isAiReview ? $trustedAiReview : null,
                        'fact_reread_status' => ($approved || $isAiReview)
                            ? 'verified_no_drift'
                            : 'not_required_for_cancellation',
                        'action_card' => (array)($eventIntent['target_value']['action_card'] ?? []),
                        'task_ref' => (int)($eventTask['id'] ?? 0) > 0
                            ? 'operation_execution_tasks#' . (int)$eventTask['id']
                            : null,
                        'external_action_performed' => false,
                    ]
                );
            }
            if ($approved && $requiresFrozenEffectTarget) {
                $approvalReadback = $this->executionIntentDetail($id, $hotelIds);
                $this->assertSavedOtaDiagnosisApprovalTargetReadback($approvalReadback);
            }
            if ((string)($intent['source_module'] ?? '') === TemporalForecastTrialService::OPERATION_SOURCE_MODULE) {
                (new TemporalForecastTrialService())->syncApprovalDecision(
                    (int)($intent['source_record_id'] ?? 0),
                    (int)($intent['hotel_id'] ?? 0),
                    $id,
                    $approved,
                    $userId,
                    $now
                );
            }
        };
        $probe = $this->executionIntentRow($id, $hotelIds);
        if (!is_array($probe)) {
            throw new \RuntimeException('execution intent not found');
        }
        $this->withSourceBackedExecutionIntentApprovalAuthorization(
            $id, $hotelIds, static fn(array $authorization): mixed => $approveLockedIntent($authorization['intent'], $authorization)
        );

        $detail = $this->executionIntentDetail($id, $hotelIds);
        if ($approved && in_array(
            strtolower(trim((string)($detail['source_module'] ?? ''))),
            [
                'ota_diagnosis_saved',
                OperatingNetworkService::EXECUTION_SOURCE_MODULE,
                OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
                RevenueCockpitActionContract::SOURCE_MODULE,
                OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
            ],
            true
        )) {
            $this->assertSavedOtaDiagnosisApprovalTargetReadback($detail);
        }

        return $detail;
    }

    /**
     * Correct the current mutable projection of an already AI-approved action
     * when it still carries legacy human-approval wording. Historical events
     * remain untouched; the correction is appended as a same-state event.
     *
     * @param list<int> $hotelIds
     * @return array<string,mixed>
     */
    public function reviseIndependentAiReviewProjection(int $id, array $hotelIds): array
    {
        $this->ensureExecutionTables();
        $changed = false;
        Db::transaction(function () use ($id, $hotelIds, &$changed): void {
            $row = $this->executionIntentRow($id, $hotelIds, true);
            if (!is_array($row) || !$this->sourceBackedIntentTenantIsCurrent($row)) {
                throw new \RuntimeException('execution intent not found in the current tenant scope');
            }
            $intent = $this->normalizeExecutionIntentRow($row);
            $lifecycle = new OperationActionLifecycleService();
            $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
            $target = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
            $review = is_array($evidence['ai_independent_review'] ?? null)
                ? $evidence['ai_independent_review']
                : [];
            if (!$lifecycle->isManagedIntent($intent)
                || strtolower(trim((string)($intent['status'] ?? ''))) !== 'approved'
                || strtolower(trim((string)($review['status'] ?? ''))) !== 'approved'
                || strtolower(trim((string)($target['approval_mode'] ?? ''))) !== 'ai_independent_review'
            ) {
                throw new \InvalidArgumentException('only an approved managed AI-reviewed action can be revised');
            }

            $card = is_array($target['action_card'] ?? null) ? $target['action_card'] : [];
            $revisedCard = $lifecycle->reviseIndependentAiReviewCard($card);
            if (hash_equals(
                strtolower(trim((string)($card['content_digest'] ?? ''))),
                strtolower(trim((string)($revisedCard['content_digest'] ?? '')))
            )) {
                return;
            }

            $taskQuery = Db::name('operation_execution_tasks')
                ->where('intent_id', $id)
                ->where('hotel_id', (int)$intent['hotel_id'])
                ->whereNull('deleted_at')
                ->lock(true);
            if (array_key_exists('tenant_id', $row)) {
                $taskQuery->where('tenant_id', (int)$row['tenant_id']);
            }
            $tasks = $taskQuery->order('id', 'asc')->select()->toArray();
            if (count($tasks) !== 1
                || strtolower(trim((string)($tasks[0]['status'] ?? ''))) !== 'pending_execute'
            ) {
                throw new \InvalidArgumentException(
                    'AI review projection can only be revised before the single local task starts'
                );
            }

            $target = $lifecycle->alignIndependentAiTaskProjection($target, $revisedCard);
            $evidence['action_card'] = $revisedCard;
            $evidence['workflow_schedule'] = (array)($target['workflow_schedule'] ?? []);
            $targetJson = json_encode(
                $target,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $evidenceJson = json_encode(
                $evidence,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $now = date('Y-m-d H:i:s');
            $affected = (int)Db::name('operation_execution_intents')
                ->where('id', $id)
                ->where('hotel_id', (int)$intent['hotel_id'])
                ->where('status', 'approved')
                ->where('target_value_json', (string)($row['target_value_json'] ?? ''))
                ->where('evidence_json', (string)($row['evidence_json'] ?? ''))
                ->whereNull('deleted_at')
                ->update([
                    'target_value_json' => $targetJson,
                    'evidence_json' => $evidenceJson,
                    'updated_at' => $now,
                ]);
            if ($affected !== 1) {
                throw new \InvalidArgumentException('AI review projection changed; refresh before revising');
            }

            $task = $tasks[0];
            $taskAffected = (int)Db::name('operation_execution_tasks')
                ->where('id', (int)$task['id'])
                ->where('intent_id', $id)
                ->where('status', 'pending_execute')
                ->where('target_value_json', (string)($task['target_value_json'] ?? ''))
                ->whereNull('deleted_at')
                ->update([
                    'target_value_json' => $targetJson,
                    'updated_at' => $now,
                ]);
            if ($taskAffected !== 1) {
                throw new \InvalidArgumentException('local operation task changed; refresh before revising');
            }

            $eventIntent = array_merge($intent, [
                'target_value' => $target,
                'evidence' => $evidence,
            ]);
            $lifecycle->appendEvent(
                $eventIntent,
                (int)$task['id'],
                'approved',
                'approved',
                'ai_review_contract_revised',
                0,
                [
                    'reason' => 'remove_legacy_human_approval_wording_from_current_projection',
                    'previous_card_digest' => (string)($card['content_digest'] ?? ''),
                    'action_card' => $revisedCard,
                    'task_ref' => 'operation_execution_tasks#' . (int)$task['id'],
                    'historical_events_rewritten' => false,
                    'external_action_performed' => false,
                ]
            );
            $changed = true;
        });

        $detail = $this->executionIntentDetail($id, $hotelIds);
        if ($changed) {
            $card = (array)($detail['action_management']['action_card'] ?? []);
            $events = (array)($detail['action_management']['lifecycle']['events'] ?? []);
            $latest = $events === [] ? [] : $events[count($events) - 1];
            if ((string)($latest['event_type'] ?? '') !== 'ai_review_contract_revised'
                || (string)($detail['action_management']['lifecycle']['integrity_status'] ?? '') !== 'verified'
                || !hash_equals(
                    strtolower(trim((string)($latest['event_payload']['action_card']['content_digest'] ?? ''))),
                    strtolower(trim((string)($card['content_digest'] ?? '')))
                )
            ) {
                throw new \RuntimeException('AI review projection revision failed exact readback');
            }
        }
        return $detail;
    }


    /** @return array{target_value:array<string,mixed>,evidence:array<string,mixed>,expected_metric:string,expected_delta:?float} */

    /** @param array<string,mixed> $intent */
    private function managedActionDeclaresObservationTarget(array $intent): bool
    {
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        if (!in_array($sourceModule, [
            OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
            RevenueCockpitActionContract::SOURCE_MODULE,
            OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
        ], true)) {
            return false;
        }
        $target = is_array($intent['target_value'] ?? null)
            ? $intent['target_value']
            : $this->decodeJson((string)($intent['target_value_json'] ?? ''));
        $card = is_array($target['action_card'] ?? null) ? $target['action_card'] : [];
        if ((string)($card['contract_version'] ?? '') === OperationActionLifecycleService::CARD_CONTRACT_VERSION) {
            $contract = is_array($card['metric_contract'] ?? null) ? $card['metric_contract'] : [];
            return strtolower(trim((string)($contract['expected_direction'] ?? ''))) === 'observe'
                && strtolower(trim((string)($contract['target_type'] ?? ''))) === 'observation'
                && ($contract['target_value'] ?? null) === null
                && ($contract['expected_delta'] ?? null) === null;
        }
        $evidence = is_array($intent['evidence'] ?? null)
            ? $intent['evidence']
            : $this->decodeJson((string)($intent['evidence_json'] ?? ''));
        $recommendation = is_array($evidence['decision_recommendation'] ?? null)
            ? $evidence['decision_recommendation']
            : [];
        $effect = is_array($recommendation['expected_effect'] ?? null)
            ? $recommendation['expected_effect']
            : [];
        $expectedMetric = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        return strtolower(trim((string)($effect['status'] ?? ''))) === 'verification_target'
            && strtolower(trim((string)($effect['direction'] ?? ''))) === 'verify'
            && strtolower(trim((string)($effect['metric'] ?? ''))) === $expectedMetric
            && $expectedMetric !== '';
    }

    private function assertSavedOtaDiagnosisApprovalTargetReadback(array $intent): void
    {
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $contract = is_array($evidence['approval_target'] ?? null) ? $evidence['approval_target'] : [];
        $approvalMode = strtolower(trim((string)($contract['approval_mode'] ?? 'human')));
        $aiReview = is_array($evidence['ai_independent_review'] ?? null)
            ? $evidence['ai_independent_review']
            : [];
        $aiReviewDigest = strtolower(trim((string)($contract['ai_review_digest'] ?? '')));
        $aiReviewValid = true;
        if ($approvalMode === 'ai_independent_review') {
            try {
                OperationActionAiReviewService::assertReviewContract($aiReview, $intent, true);
            } catch (\Throwable) {
                $aiReviewValid = false;
            }
        }
        $metricKey = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $metricDefinition = is_array($targetValue['metric_definition'] ?? null)
            ? $targetValue['metric_definition']
            : [];
        $metricDefinitionDigest = $metricDefinition === [] || $metricKey === ''
            ? ''
            : $this->savedOtaDiagnosisMetricDefinitionDigest($metricKey, $metricDefinition);
        $targetType = strtolower(trim((string)($contract['target_type'] ?? '')));
        $direction = strtolower(trim((string)($contract['expected_direction'] ?? '')));
        $contractVersion = trim((string)($contract['version'] ?? ''));
        $expectedDeltaStatus = strtolower(trim((string)($contract['expected_delta_status'] ?? '')));
        $isObservation = $contractVersion === 'operation_observation_approval_target.v1'
            && $direction === 'observe'
            && $targetType === 'observation'
            && $expectedDeltaStatus === 'observation_only';
        $isQuantifiedTarget = $contractVersion === 'ota_execution_approval_target.v1'
            && in_array($direction, ['increase', 'decrease'], true)
            && in_array($targetType, ['absolute', 'delta'], true)
            && $expectedDeltaStatus === 'manual_confirmed';
        $contractExpectedDelta = $contract['expected_delta'] ?? null;
        $intentExpectedDelta = $intent['expected_delta'] ?? null;
        $contractTargetValue = $contract['target_value'] ?? null;
        $persistedTargetValue = $targetValue['expected_target'] ?? null;
        $tasks = is_array($intent['tasks'] ?? null) ? $intent['tasks'] : [];
        $taskTargetValue = count($tasks) === 1 && is_array($tasks[0]['target_value'] ?? null)
            ? $tasks[0]['target_value']
            : [];
        $digest = strtolower(trim((string)($contract['content_digest'] ?? '')));
        if ($digest === ''
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || !hash_equals($digest, $this->savedOtaDiagnosisApprovalTargetDigest($contract))
            || !hash_equals($digest, strtolower(trim((string)($evidence['approval_target_digest'] ?? ''))))
            || !hash_equals($digest, strtolower(trim((string)($targetValue['approval_target_digest'] ?? ''))))
            || $metricDefinitionDigest === ''
            || !hash_equals($metricDefinitionDigest, strtolower(trim((string)($contract['metric_definition_digest'] ?? ''))))
            || !hash_equals($metricDefinitionDigest, strtolower(trim((string)($targetValue['metric_definition_digest'] ?? ''))))
            || !hash_equals($metricDefinitionDigest, strtolower(trim((string)($evidence['metric_definition_digest'] ?? ''))))
            || $metricDefinition !== ($evidence['metric_definition'] ?? null)
            || $metricDefinition !== ($contract['metric_definition'] ?? null)
            || (!$isObservation && !$isQuantifiedTarget)
            || (string)($contract['expected_metric'] ?? '') !== (string)($intent['expected_metric'] ?? '')
            || (int)($contract['approved_by'] ?? 0) !== (int)($intent['approved_by'] ?? 0)
            || (string)($contract['approved_at'] ?? '') !== (string)($intent['approved_at'] ?? '')
            || !in_array($approvalMode, ['human', 'ai_independent_review'], true)
            || ($approvalMode === 'ai_independent_review'
                && (!$aiReviewValid
                    || (int)($contract['approved_by'] ?? -1) !== 0
                    || (int)($intent['approved_by'] ?? -1) !== 0
                    || preg_match('/^[a-f0-9]{64}$/D', $aiReviewDigest) !== 1
                    || !hash_equals(
                        $aiReviewDigest,
                        strtolower(trim((string)($aiReview['content_digest'] ?? '')))
                    )
                    || strtolower(trim((string)($targetValue['approval_mode'] ?? '')))
                        !== 'ai_independent_review'
                    || !hash_equals(
                        $aiReviewDigest,
                        strtolower(trim((string)($targetValue['ai_review_digest'] ?? '')))
                    )))
            || (int)($contract['tenant_id'] ?? 0) !== (int)($intent['tenant_id'] ?? 0)
            || (int)($contract['hotel_id'] ?? 0) !== (int)($intent['hotel_id'] ?? 0)
            || ($targetType === 'delta'
                && (!is_numeric($contractExpectedDelta)
                    || !is_numeric($intentExpectedDelta)
                    || abs((float)$contractExpectedDelta - (float)$intentExpectedDelta) > 0.0000001))
            || ($targetType === 'absolute'
                && (!is_numeric($contractTargetValue)
                    || !is_numeric($persistedTargetValue)
                    || abs((float)$contractTargetValue - (float)$persistedTargetValue) > 0.0000001))
            || ($isObservation
                && (!in_array((string)($contract['source_module'] ?? ''), [
                        OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
                        RevenueCockpitActionContract::SOURCE_MODULE,
                        OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
                    ], true)
                    || $contractTargetValue !== null
                    || $contractExpectedDelta !== null
                    || $persistedTargetValue !== null
                    || $intentExpectedDelta !== null))
            || strtolower(trim((string)($targetValue['expected_direction'] ?? ''))) !== $direction
            || strtolower(trim((string)($evidence['expected_direction'] ?? ''))) !== $direction
            || strtolower(trim((string)($targetValue['target_type'] ?? ''))) !== $targetType
            || strtolower(trim((string)($evidence['target_type'] ?? ''))) !== $targetType
            || strtolower(trim((string)($targetValue['expected_delta_status'] ?? ''))) !== $expectedDeltaStatus
            || strtolower(trim((string)($evidence['expected_delta_status'] ?? ''))) !== $expectedDeltaStatus
            || count($tasks) !== 1
            || !hash_equals($digest, strtolower(trim((string)($taskTargetValue['approval_target_digest'] ?? ''))))
            || $taskTargetValue !== $targetValue
        ) {
            throw new \RuntimeException('execution approval target save readback verification failed');
        }
    }

    /** @return array<string,mixed> */
    private function savedOtaDiagnosisMetricDefinition(
        string $metricKey,
        string $platform = '',
        string $sourceModule = ''
    ): array
    {
        $metricKey = strtolower(trim($metricKey));
        $platform = $this->normalizeOtaChannel($platform);
        $sourceModule = strtolower(trim($sourceModule));
        if ($metricKey === 'ctrip_strict_core_fact_count') {
            if ($sourceModule !== OperatingOpportunityLabService::DAILY_SOURCE_MODULE
                || $platform !== 'ctrip'
            ) {
                throw new \InvalidArgumentException(
                    'strict core fact-count review is limited to the Ctrip daily-one-thing data-gap contract'
                );
            }
            return [
                'version' => 'daily_one_thing_metric_definition.v1',
                'platform' => 'ctrip',
                'source_module' => OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
                'metric_key' => $metricKey,
                'semantic_key' => 'ctrip_target_date_strict_core_fact_count',
                'unit' => 'verified_fields',
                'value_type' => 'non_negative_integer',
                'source_table' => 'online_daily_data',
                'source_identity' => ['tenant_id', 'system_hotel_id', 'platform', 'business_date'],
                'source_policy' => 'dual_ota_field_closure_current_receipt_strict_readback',
                'calculation' => 'count_strict_consumable_core_fields',
                'comparison_policy' => 'same_hotel_same_platform_same_target_date_before_vs_later_natural_receipt',
                'causality_claimed' => false,
            ];
        }
        if ($metricKey === 'list_exposure') {
            if ($platform !== 'ctrip') {
                throw new \InvalidArgumentException(
                    'list_exposure same-criterion effect readback is supported only for Ctrip unique-user semantics'
                );
            }
            return [
                'version' => 'ota_execution_metric_definition.v3',
                'platform' => 'ctrip',
                'source_module' => 'ctrip_data_center_flow_transform',
                'source_endpoint_family' => 'ctrip_query_flow_transform_new_v1',
                'source_endpoint_ids' => ['business_flow_transform', 'traffic_flow_transform'],
                'metric_key' => 'list_exposure',
                'semantic_key' => 'ctrip_datacenter_list_exposure_uv',
                'unit' => 'unique_users',
                'value_type' => 'non_negative_integer',
                'source_table' => 'online_daily_data',
                'source_field' => 'list_exposure',
                'source_identity' => ['system_hotel_id', 'platform', 'business_date'],
                'source_policy' => 'trusted_persisted_source_rows_with_metric_scoped_field_fact_readback',
                'field_fact_required' => true,
                'calculation' => 'canonical_daily_snapshot_value',
                'comparison_policy' => 'same_hotel_same_platform_same_semantic_key_baseline_vs_approved_next_calendar_business_date',
                'blocked_aliases' => ['generic_impression_count', 'advertising_impressions'],
            ];
        }
        if ($metricKey === 'detail_exposure') {
            if (!in_array($sourceModule, [
                    OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
                    RevenueCockpitActionContract::SOURCE_MODULE,
                    OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
                ], true)
                || !in_array($platform, ['ctrip', 'meituan'], true)
            ) {
                throw new \InvalidArgumentException(
                    'detail_exposure same-criterion readback is limited to verified Ctrip/Meituan operating questions'
                );
            }
            return [
                'version' => 'ota_execution_metric_definition.v4',
                'platform' => $platform,
                'source_module' => 'operating_question_verified_online_daily_data',
                'metric_key' => 'detail_exposure',
                'semantic_key' => $platform . '_detail_exposure_count',
                'unit' => 'exposure_count',
                'value_type' => 'non_negative_integer',
                'source_table' => 'online_daily_data',
                'source_field' => 'detail_exposure',
                'source_identity' => ['system_hotel_id', 'platform', 'business_date'],
                'source_policy' => 'trusted_persisted_source_rows_with_metric_scoped_field_fact_readback',
                'field_fact_required' => true,
                'calculation' => 'canonical_daily_snapshot_value',
                'comparison_policy' => 'same_hotel_same_platform_same_semantic_key_baseline_vs_approved_review_business_date',
                'blocked_aliases' => ['list_exposure', 'generic_page_views', 'advertising_impressions'],
            ];
        }
        $calculation = match ($metricKey) {
            'revenue', 'avg_revenue', 'amount', 'income' => 'trusted_daily_revenue',
            'orders', 'avg_orders', 'order_count', 'book_order_num' => 'trusted_daily_order_count',
            'room_nights', 'avg_room_nights' => 'trusted_daily_room_nights',
            'adr', 'avg_adr' => 'trusted_daily_revenue_divided_by_room_nights',
            'occ', 'occupancy', 'avg_occ' => 'trusted_daily_sold_room_nights_divided_by_sellable_room_nights',
            'detail_rate', 'view_rate', 'flow_rate' => 'trusted_daily_detail_or_flow_rate',
            'conversion', 'conversion_rate', 'order_rate' => 'trusted_daily_order_conversion_rate',
            'avg_psi_score' => 'trusted_daily_average_psi_score_with_positive_sample_count',
            default => throw new \InvalidArgumentException(
                'saved OTA diagnosis metric is not supported by same-criterion effect readback: ' . $metricKey
            ),
        };

        return [
            'version' => 'ota_execution_metric_definition.v1',
            'metric_key' => $metricKey,
            'source_table' => 'online_daily_data',
            'source_identity' => ['system_hotel_id', 'platform', 'business_date'],
            'source_policy' => 'trusted_persisted_source_rows_with_strict_readback',
            'calculation' => $calculation,
            'comparison_policy' => 'same_hotel_same_platform_same_metric_baseline_vs_approved_review_business_date',
        ];
    }

    /** @param array<string,mixed> $definition */
    private function savedOtaDiagnosisMetricDefinitionDigest(string $metricKey, array $definition): string
    {
        return hash('sha256', json_encode(
            $this->canonicalizeExecutionApprovalTarget([
                'metric_key' => strtolower(trim($metricKey)),
                'definition' => $definition,
            ]),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        ));
    }

    private function savedOtaDiagnosisApprovalTargetDigest(array $contract): string
    {
        unset($contract['content_digest']);
        return hash('sha256', json_encode(
            $this->canonicalizeExecutionApprovalTarget($contract),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalizeExecutionApprovalTarget(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalizeExecutionApprovalTarget($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeExecutionApprovalTarget($item);
        }
        return $value;
    }

    /** @param array<string, mixed> $intent */
    private function assertAiDecisionIntentReadyForApproval(array $intent, ?array $authorization = null): void
    {
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        $intent['source_module'] = $sourceModule;
        if ($sourceModule === 'knowledge_sop') {
            (new KnowledgeSopExecutionProvenanceService())->assertIntentCurrent($intent, true);
            return;
        }
        if ($sourceModule === OperatingQuestionExecutionBridgeService::SOURCE_MODULE) {
            (new OperatingQuestionExecutionBridgeService())->assertIntentCurrent($intent);
            return;
        }
        if ($sourceModule === RevenueCockpitActionContract::SOURCE_MODULE) {
            (new RevenueCockpitIntentProvenanceService())->assertIntentCurrent($intent);
            return;
        }
        if ($sourceModule === OperatingOpportunityLabService::DAILY_SOURCE_MODULE) {
            (new OperatingOpportunityLabService())->assertDailyIntentCurrent($intent);
            return;
        }
        if ($sourceModule === 'ota_diagnosis') {
            $this->assertPublicPageDiagnosisIntentReadyForApproval($intent);
            return;
        }
        if (SourceBackedExecutionIntentIdentityService::supports($intent)) {
            $this->assertSourceBackedIntentCurrentWithAuthorization($intent, $authorization);
            return;
        }
        if ($sourceModule === 'operating_target') {
            $this->assertOperatingTargetIntentSourceIsCurrent($intent);
            return;
        }
        if ($sourceModule === OperatingNetworkService::EXECUTION_SOURCE_MODULE) {
            (new OperatingNetworkService())->assertReplicationExecutionIntentCurrent($intent);
            return;
        }
        if ($sourceModule === TemporalInsightService::OPERATION_SOURCE_MODULE) {
            (new TemporalInsightService())->assertOperationRecommendationIntentCurrent($intent);
            return;
        }
        if ($sourceModule === TemporalForecastTrialService::OPERATION_SOURCE_MODULE) {
            (new TemporalForecastTrialService())->assertOperationIntentCurrent($intent);
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
            if (!$this->hasVerifiedOtaDiagnosisProvenance($intent, true)) {
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

    /** @param array<string,mixed> $intent */
    private function assertManagedActionSourceCurrent(array $intent): void
    {
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        if ($sourceModule === OperatingQuestionExecutionBridgeService::SOURCE_MODULE) {
            (new OperatingQuestionExecutionBridgeService())->assertIntentCurrent($intent);
            return;
        }
        if ($sourceModule === RevenueCockpitActionContract::SOURCE_MODULE) {
            (new RevenueCockpitIntentProvenanceService())->assertIntentCurrent($intent);
            return;
        }
        if ($sourceModule === OperatingOpportunityLabService::DAILY_SOURCE_MODULE) {
            (new OperatingOpportunityLabService())->assertDailyIntentCurrent($intent);
        }
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $input */
    private function assertManagedOperationExecutionEvidence(array $intent, array $input, int $operatorId): void
    {
        if (!in_array(strtolower(trim((string)($intent['source_module'] ?? ''))), [
            OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
            RevenueCockpitActionContract::SOURCE_MODULE,
            OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
        ], true)) {
            return;
        }
        $status = strtolower(trim((string)($input['status'] ?? '')));
        if (!in_array($status, ['executed', 'failed'], true)) {
            return;
        }
        $evidenceType = strtolower(trim((string)($input['evidence_type'] ?? '')));
        $evidence = $this->arrayValue($input['evidence'] ?? []);
        $response = $this->arrayValue($evidence['platform_response'] ?? []);
        $executedBy = trim((string)($response['executed_by'] ?? ''));
        $executedAt = trim((string)($response['executed_at'] ?? ''));
        $executionStatus = strtolower(trim((string)($response['execution_status'] ?? '')));
        $completedAction = trim((string)($response['completed_action'] ?? ''));
        $failureReason = trim((string)($response['failure_reason'] ?? ''));
        if ($operatorId <= 0
            || $evidenceType !== 'manual_operation_execution'
            || strtolower(trim((string)($response['mode'] ?? ''))) !== 'manual_operation_execution'
            || $executedBy === ''
            || $executionStatus !== $status
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $executedAt) !== 1
        ) {
            throw new \InvalidArgumentException('受管运营任务必须记录真实执行人、实际时间和执行状态');
        }
        $executedTimestamp = strtotime($executedAt);
        if ($executedTimestamp === false || $executedTimestamp > time() + 300) {
            throw new \InvalidArgumentException('运营任务实际执行时间无效或晚于当前时间');
        }
        if ($status === 'executed' && $completedAction === '') {
            throw new \InvalidArgumentException('执行成功必须记录已实际完成的操作说明');
        }
        if ($status === 'failed' && $failureReason === '') {
            throw new \InvalidArgumentException('执行失败必须记录真实失败原因');
        }
        if (($response['automatic_execution'] ?? false) === true
            || ($response['automatic_ota_write'] ?? false) === true
            || ($response['automatic_pms_write'] ?? false) === true
        ) {
            throw new \InvalidArgumentException('执行证据不得声明系统自动操作 OTA 或 PMS');
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
        return $this->withExecutionTaskMutationAuthorization(
            $taskId,
            $hotelIds,
            fn(array $context): array => $this->executeExecutionTaskAuthorized(
                $taskId,
                $hotelIds,
                $input,
                $operatorId,
                $context
            )
        );
    }

    /** @param array{task:array<string,mixed>,intent:array<string,mixed>} $context */
    private function executeExecutionTaskAuthorized(
        int $taskId,
        array $hotelIds,
        array $input,
        int $operatorId,
        array $context
    ): array {
        $task = $context['task'];
        $intent = $context['intent'];
        $normalizedIntent = $this->normalizeExecutionIntentRow($intent);
        $this->assertManagedActionSourceCurrent($normalizedIntent);
        $dailyContract = (new OperationActionLifecycleService())->isDailyOneThingIntent($normalizedIntent);
        if ($dailyContract) {
            $currentTaskStatus = strtolower(trim((string)($task['status'] ?? '')));
            $requestedTaskStatus = strtolower(trim((string)($input['status'] ?? 'executed')));
            $allowedDailyTaskTransitions = [
                'pending_execute' => ['executing', 'blocked'],
                'executing' => ['executed', 'failed', 'blocked'],
            ];
            if (!in_array($requestedTaskStatus, $allowedDailyTaskTransitions[$currentTaskStatus] ?? [], true)) {
                throw new \InvalidArgumentException(
                    '每日一件事必须按 pending_execute → executing → executed/failed 顺序推进'
                );
            }
        }
        $this->assertManagedOperationExecutionEvidence($normalizedIntent, $input, $operatorId);
        $this->assertExecutionTaskAssignee($intent, $operatorId);
        $this->assertExecutionPayloadHasNoCredentialMaterial([
            $this->decodeJson((string)($task['current_value_json'] ?? '')),
            $this->decodeJson((string)($task['target_value_json'] ?? '')),
            $this->decodeJson((string)($intent['evidence_json'] ?? '')),
        ]);

        $built = $this->buildExecutionTaskUpdate($task, $intent, $input, $operatorId);
        $taskUpdate = $built['task'];
        $lifecycle = new OperationActionLifecycleService();
        $managedAction = $lifecycle->isManagedIntent($normalizedIntent);
        $lifecycleEvents = $managedAction
            ? $lifecycle->eventsForIntent(
                (int)($normalizedIntent['tenant_id'] ?? 0),
                (int)($normalizedIntent['hotel_id'] ?? 0),
                (int)($normalizedIntent['id'] ?? 0)
            )
            : [];
        $lifecycleFromStatus = $managedAction
            ? $lifecycle->currentStatus(array_merge($normalizedIntent, [
                'tasks' => [$this->normalizeExecutionTaskRow($task)],
            ]), $lifecycleEvents)
            : '';
        $dbUpdate = $taskUpdate;
        foreach (['current_value', 'target_value'] as $jsonField) {
            if (array_key_exists($jsonField, $dbUpdate)) {
                $dbUpdate[$jsonField . '_json'] = json_encode($dbUpdate[$jsonField], JSON_UNESCAPED_UNICODE);
                unset($dbUpdate[$jsonField]);
            }
        }

        $expectedTaskStatus = (string)($task['status'] ?? '');
        (function () use (
            $taskId,
            $dbUpdate,
            $built,
            $taskUpdate,
            $task,
            $intent,
            $normalizedIntent,
            $expectedTaskStatus,
            $operatorId,
            $lifecycle,
            $managedAction,
            $lifecycleFromStatus,
            $dailyContract
        ): void {
            $this->assertExecutionTaskAssignee($intent, $operatorId);
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
            $evidenceId = 0;
            if ($built['evidence'] !== null) {
                $evidenceId = $this->insertExecutionEvidence($built['evidence'], (int)($task['tenant_id'] ?? 0));
            }

            if (($taskUpdate['status'] ?? '') === 'executed'
                && empty($task['action_track_id'])
                && $this->tableExists('operation_action_tracks')
            ) {
                $actionTrackId = $this->createActionTrackForExecution(
                    $intent,
                    $taskId,
                    (int)($task['tenant_id'] ?? 0)
                );
                Db::name('operation_execution_tasks')->where('id', $taskId)->update(['action_track_id' => $actionTrackId]);
            }
            if ($managedAction) {
                $taskStatus = strtolower(trim((string)($taskUpdate['status'] ?? '')));
                if ($dailyContract) {
                    if ($taskStatus === 'executing') {
                        $lifecycle->appendEvent(
                            $normalizedIntent,
                            $taskId,
                            $lifecycleFromStatus,
                            'executing',
                            'started',
                            $operatorId,
                            [
                                'task_ref' => 'operation_execution_tasks#' . $taskId,
                                'task_status' => $taskStatus,
                                'external_action_performed_by_system' => false,
                            ]
                        );
                        return;
                    }
                    if (in_array($taskStatus, ['failed', 'blocked'], true)) {
                        $lifecycle->appendEvent(
                            $normalizedIntent,
                            $taskId,
                            $lifecycleFromStatus,
                            'blocked',
                            'blocked',
                            $operatorId,
                            [
                                'task_ref' => 'operation_execution_tasks#' . $taskId,
                                'task_status' => $taskStatus,
                                'execution_evidence_ref' => $evidenceId > 0
                                    ? 'operation_execution_evidence#' . $evidenceId : null,
                                'blocked_reason' => (string)($taskUpdate['blocked_reason'] ?? ''),
                                'external_action_performed_by_system' => false,
                            ]
                        );
                        return;
                    }
                    if ($taskStatus === 'executed') {
                        if ($evidenceId <= 0) {
                            throw new \InvalidArgumentException('每日一件事记录执行完成时必须绑定真实人工证据');
                        }
                        $fingerprint = $this->executionEvidenceFingerprint((array)$built['evidence']);
                        $lifecycle->appendEvent(
                            $normalizedIntent,
                            $taskId,
                            $lifecycleFromStatus,
                            'evidence_recorded',
                            'evidence_recorded',
                            $operatorId,
                            [
                                'task_ref' => 'operation_execution_tasks#' . $taskId,
                                'evidence_ref' => 'operation_execution_evidence#' . $evidenceId,
                                'evidence_fingerprint' => $fingerprint,
                                'operator_id' => $operatorId,
                                'executed_at' => (string)($taskUpdate['executed_at'] ?? ''),
                                'external_action_performed_by_system' => false,
                            ]
                        );
                        $lifecycle->appendEvent(
                            $normalizedIntent,
                            $taskId,
                            'evidence_recorded',
                            'review_pending',
                            'review_waiting_for_natural_data',
                            $operatorId,
                            [
                                'task_ref' => 'operation_execution_tasks#' . $taskId,
                                'evidence_ref' => 'operation_execution_evidence#' . $evidenceId,
                                'review_at' => (string)($normalizedIntent['target_value']['workflow_schedule']['review_at'] ?? ''),
                                'source_readback_required' => true,
                                'simulated_result_allowed' => false,
                                'external_action_performed_by_system' => false,
                            ]
                        );
                        return;
                    }
                }
                $toStatus = match ($taskStatus) {
                    'executing' => 'in_progress',
                    'executed', 'failed' => 'completed',
                    default => $lifecycleFromStatus,
                };
                $lifecycle->appendEvent(
                    $normalizedIntent,
                    $taskId,
                    $lifecycleFromStatus,
                    $toStatus,
                    match ($taskStatus) {
                        'executing' => 'started',
                        'executed' => 'completed',
                        'failed' => 'completed_with_failure',
                        default => 'blocked',
                    },
                    $operatorId,
                    [
                        'task_ref' => 'operation_execution_tasks#' . $taskId,
                        'task_status' => $taskStatus,
                        'execution_evidence_ref' => $evidenceId > 0
                            ? 'operation_execution_evidence#' . $evidenceId
                            : null,
                        'blocked_reason' => (string)($taskUpdate['blocked_reason'] ?? ''),
                        'external_action_performed_by_system' => false,
                    ]
                );
            }
        })();

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

    /** @param array<string,mixed> $task @param array<string,mixed> $intent */
    private function assertExecutionTaskAllowsOperatorMutation(array $task, array $intent): void
    {
        if (strtolower(trim((string)($intent['source_module'] ?? ''))) === 'canonical_ota_investigation'
            || strtolower(trim((string)($task['execution_mode'] ?? ''))) === 'analysis_only'
            || strtolower(trim((string)($intent['status'] ?? ''))) === 'system_authorized_analysis'
        ) {
            throw new \InvalidArgumentException('system-authorized analysis task is immutable');
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
        return $this->withExecutionTaskMutationAuthorization(
            $taskId,
            $hotelIds,
            fn(array $context): array => $this->addExecutionEvidenceAuthorized(
                $taskId,
                $hotelIds,
                $input,
                $userId,
                $context
            )
        );
    }

    /** @param array{task:array<string,mixed>,intent:array<string,mixed>} $context */
    private function addExecutionEvidenceAuthorized(
        int $taskId,
        array $hotelIds,
        array $input,
        int $userId,
        array $context
    ): array {
        $task = $context['task'];
        $intent = $context['intent'];
        $this->assertExecutionTaskAllowsOperatorMutation($task, $intent);
        $this->assertManagedActionSourceCurrent($this->normalizeExecutionIntentRow($intent));
        $evidence = $this->arrayValue($input['evidence'] ?? $input);
        if (empty($evidence)) {
            throw new \InvalidArgumentException('execution evidence is required');
        }
        $evidenceType = strtolower(trim((string)($input['evidence_type'] ?? $evidence['evidence_type'] ?? 'manual')));
        $this->assertManagedOperationExecutionEvidence(
            $this->normalizeExecutionIntentRow($intent),
            [
                'status' => (string)($task['status'] ?? 'executed'),
                'evidence_type' => $evidenceType,
                'evidence' => $evidence,
            ],
            $userId
        );
        $this->assertOperatorExecutionEvidenceBoundary($evidenceType, $evidence);
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
        if (!$this->hasNonEmptyValue($this->executionEvidenceContent($payload))) {
            throw new \InvalidArgumentException('execution evidence content is required');
        }
        if ($isRevenueNodeCheck
            && (($payload['platform_response']['node_record']['contract_version'] ?? '') !== 'operation_revenue_node.v2')
        ) {
            throw new \InvalidArgumentException('revenue node check requires operation_revenue_node.v2 identity');
        }
        $fingerprint = $this->executionEvidenceFingerprint($payload);
        $write = (function () use (
            $taskId,
            $task,
            $payload,
            $fingerprint
        ): array {
            $lockedTask = $task;
            $lockedStatus = strtolower(trim((string)($lockedTask['status'] ?? '')));
            if (!$this->executionEvidenceCanBeAddedAtStatus((string)$payload['evidence_type'], $lockedStatus)) {
                throw new \InvalidArgumentException('execution task must be executed before evidence can be added');
            }

            $existingId = $this->matchingExecutionEvidenceId($lockedTask, $fingerprint);
            if ($existingId > 0) {
                return ['id' => $existingId, 'created' => false];
            }
            if ($payload['evidence_type'] === 'compensation_receipt') {
                $this->assertCompensationReceiptIsCurrentAndComplete($lockedTask, $payload['platform_response']);
            }

            return [
                'id' => $this->insertExecutionEvidence($payload, (int)($lockedTask['tenant_id'] ?? 0)),
                'created' => true,
            ];
        })();

        $normalizedIntent = $this->normalizeExecutionIntentRow($intent);
        $lifecycle = new OperationActionLifecycleService();
        if ((bool)$write['created'] && $lifecycle->isManagedIntent($normalizedIntent)) {
            $events = $lifecycle->eventsForIntent(
                (int)$normalizedIntent['tenant_id'],
                (int)$normalizedIntent['hotel_id'],
                (int)$normalizedIntent['id']
            );
            $currentStatus = $lifecycle->currentStatus(array_merge($normalizedIntent, [
                'tasks' => [$this->normalizeExecutionTaskRow($task)],
            ]), $events);
            $toStatus = $lifecycle->isDailyOneThingIntent($normalizedIntent)
                && $currentStatus === 'evidence_recorded'
                    ? 'review_pending'
                    : $currentStatus;
            $lifecycle->appendEvent(
                $normalizedIntent,
                $taskId,
                $currentStatus,
                $toStatus,
                'evidence_attached',
                $userId,
                [
                    'task_ref' => 'operation_execution_tasks#' . $taskId,
                    'evidence_ref' => 'operation_execution_evidence#' . (int)$write['id'],
                    'evidence_fingerprint' => $fingerprint,
                    'evidence_type' => $evidenceType,
                    'external_action_performed_by_system' => false,
                ]
            );
        }

        $detail = $this->executionTaskDetail($taskId, $hotelIds);
        $detail['evidence_write'] = [
            'evidence_id' => (int)$write['id'],
            'created' => (bool)$write['created'],
            'replayed' => !(bool)$write['created'],
            'fingerprint' => $fingerprint,
        ];
        return $detail;
    }

    /** @param array<string,mixed> $evidence */
    private function assertOperatorExecutionEvidenceBoundary(string $evidenceType, array $evidence): void
    {
        if (in_array($evidenceType, [
            'manual_finance',
            'manual_roi_evidence',
            'operator_attested_platform_readback',
            'source_verified_metric_readback',
        ], true)) {
            throw new \InvalidArgumentException(
                'effect evidence must be saved by the effect-review workflow, not as execution evidence'
            );
        }

        $effectMetricKeys = [
            'revenue', 'avg_revenue', 'amount', 'income', 'cost', 'roi',
            'orders', 'avg_orders', 'order_count', 'book_order_num',
            'room_nights', 'quantity', 'adr', 'occupancy', 'occ',
            'conversion', 'conversion_rate', 'order_rate', 'detail_rate', 'flow_rate', 'list_exposure',
        ];
        foreach (['before', 'after'] as $side) {
            $metrics = $this->arrayValue($evidence[$side] ?? []);
            foreach ($effectMetricKeys as $key) {
                if (array_key_exists($key, $metrics)) {
                    throw new \InvalidArgumentException(
                        'business outcome metrics must be saved separately from execution evidence'
                    );
                }
            }
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function executionEvidenceContent(array $payload): array
    {
        return [
            'before' => $payload['before'] ?? [],
            'after' => $payload['after'] ?? [],
            'attachment_path' => trim((string)($payload['attachment_path'] ?? '')),
            'platform_response' => $payload['platform_response'] ?? [],
            'remark' => trim((string)($payload['remark'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function executionEvidenceFingerprint(array $payload): string
    {
        $stable = [
            'evidence_type' => strtolower(trim((string)($payload['evidence_type'] ?? 'manual'))),
            ...$this->executionEvidenceContent($payload),
        ];
        return hash('sha256', json_encode(
            $this->canonicalizeDecisionValue($stable),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ) ?: '{}');
    }

    /** @param array<string, mixed> $task */
    private function matchingExecutionEvidenceId(array $task, string $fingerprint): int
    {
        $query = Db::name('operation_execution_evidence')
            ->where('task_id', (int)($task['id'] ?? 0))
            ->whereNull('deleted_at');
        if (array_key_exists('tenant_id', $task)) {
            $query->where('tenant_id', (int)$task['tenant_id']);
        }
        $rows = $query->order('id', 'asc')->select()->toArray();
        foreach ($rows as $row) {
            $normalized = $this->normalizeExecutionEvidenceRow($row);
            if (hash_equals($fingerprint, $this->executionEvidenceFingerprint($normalized))) {
                return (int)($normalized['id'] ?? 0);
            }
        }
        return 0;
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
        return $this->withExecutionTaskMutationAuthorization(
            $taskId,
            $hotelIds,
            fn(array $context): array => $this->reviewExecutionTaskAuthorized(
                $taskId,
                $hotelIds,
                $input,
                $reviewerId,
                $context
            )
        );
    }

    /** @param array{task:array<string,mixed>,intent:array<string,mixed>} $context */
    private function reviewExecutionTaskAuthorized(
        int $taskId,
        array $hotelIds,
        array $input,
        int $reviewerId,
        array $context
    ): array {
        $task = $context['task'];
        $intentRow = $context['intent'];
        $this->assertExecutionTaskAllowsOperatorMutation($task, $intentRow);
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
        $this->assertManagedActionSourceCurrent($normalizedIntent);
        $normalizedTask = $this->normalizeExecutionTaskRow($task);
        $normalizedEvidenceRows = array_map([$this, 'normalizeExecutionEvidenceRow'], $evidenceRows);
        $expectedOperatorId = (int)($normalizedTask['operator_id'] ?? 0);
        $expectedOperatorId = $expectedOperatorId > 0 ? $expectedOperatorId : null;
        $meaningfulEvidenceRows = array_values(array_filter(
            $normalizedEvidenceRows,
            static fn(array $row): bool => self::isMeaningfulExecutionReceipt($row, $expectedOperatorId)
        ));
        if ($meaningfulEvidenceRows === []) {
            throw new \InvalidArgumentException('meaningful execution evidence is required before review');
        }
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
        $effectContractDeclared = $this->intentDeclaresEffectContract($normalizedIntent);
        $intentTarget = is_array($normalizedIntent['target_value'] ?? null)
            ? $normalizedIntent['target_value']
            : [];
        $intentEvidence = is_array($normalizedIntent['evidence'] ?? null)
            ? $normalizedIntent['evidence']
            : [];
        $actionCard = is_array($intentTarget['action_card'] ?? null)
            ? $intentTarget['action_card']
            : (is_array($intentEvidence['action_card'] ?? null) ? $intentEvidence['action_card'] : []);
        $isObservationContract = strtolower(trim((string)(
            $actionCard['metric_contract']['target_type'] ?? ''
        ))) === 'observation';
        if ($isObservationContract && $manualResultStatus !== 'observing') {
            throw new \InvalidArgumentException(
                'observation action review must remain observing and cannot claim terminal success or failure'
            );
        }
        if ($effectContractDeclared && $reviewReadbackEvidence !== null) {
            throw new \InvalidArgumentException(
                'frozen effect review does not accept client-submitted effect evidence; use system source readback'
            );
        }
        if ($effectContractDeclared
            || in_array($manualResultStatus, ['success', 'near_success'], true)
            || $isOperationOptimizer
            || $isTemporalForecast
        ) {
            $this->syncSourceVerifiedMetricReadback($normalizedTask, $normalizedIntent, $context);
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
        $hasMeaningfulOperatorExecutionEvidence = $meaningfulEvidenceRows !== [];
        if ($effectContractDeclared
            && in_array($manualResultStatus, ['success', 'near_success', 'failed'], true)
            && !$hasMeaningfulOperatorExecutionEvidence
        ) {
            throw new \InvalidArgumentException(
                'meaningful operator execution evidence must be saved separately before terminal effect review'
            );
        }
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
        if (($isOperationOptimizer || $effectContractDeclared)
            && $manualResultStatus === 'failed'
            && !in_array((string)($reviewOutcomeTruth['status'] ?? ''), ['adverse', 'missed'], true)
        ) {
            throw new \InvalidArgumentException(
                'failed review requires a source-verified metric outcome that did not improve'
            );
        }
        if ($effectContractDeclared && !$hasSourceVerifiedReviewEvidence) {
            throw new \InvalidArgumentException(
                'frozen effect contract requires same-hotel, same-platform and same-metric source readback'
            );
        }
        $actionTrackId = (int)($task['action_track_id'] ?? 0);
        $reviewedAt = date('Y-m-d H:i:s');

        (function () use (
            $taskId,
            $task,
            $manualResultStatus,
            $manualSummary,
            $actionTrackId,
            $expectedResultStatus,
            $expectedResultSummary,
            $reviewReadbackEvidence,
            $hasSourceVerifiedReviewEvidence,
            $reviewOutcomeTruth,
            $isSavedOtaDiagnosis,
            $effectContractDeclared,
            $normalizedIntent,
            $normalizedTask,
            $evidenceRows,
            $reviewerId,
            $reviewedAt
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
                $this->insertExecutionEvidence($reviewReadbackEvidence, (int)($task['tenant_id'] ?? 0));
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
                    'updated_at' => $reviewedAt,
                ]);
            if ($affected !== 1) {
                throw new \InvalidArgumentException('execution task state changed; refresh before review');
            }

            if ($effectContractDeclared && in_array($resultStatus, ['success', 'near_success', 'failed'], true)) {
                $this->createOperationEffectReview(
                    $normalizedIntent,
                    $normalizedTask,
                    $evidenceRows,
                    $resultStatus,
                    $summary,
                    $reviewerId,
                    $reviewedAt
                );
            }
            $lifecycle = new OperationActionLifecycleService();
            if ($lifecycle->isManagedIntent($normalizedIntent)) {
                $review = $lifecycle->appendReview(
                    $normalizedIntent,
                    array_merge($normalizedTask, [
                        'result_status' => $resultStatus,
                        'result_summary' => $summary,
                    ]),
                    $evidenceRows,
                    $resultStatus,
                    $summary,
                    $reviewerId,
                    $reviewedAt
                );
                $events = $lifecycle->eventsForIntent(
                    (int)$normalizedIntent['tenant_id'],
                    (int)$normalizedIntent['hotel_id'],
                    (int)$normalizedIntent['id']
                );
                $fromStatus = $lifecycle->currentStatus(array_merge($normalizedIntent, [
                    'tasks' => [array_merge($normalizedTask, [
                        'status' => 'executed',
                        'result_status' => $resultStatus,
                    ])],
                ]), $events);
                $lifecycle->appendEvent(
                    $normalizedIntent,
                    $taskId,
                    $fromStatus,
                    'reviewed',
                    $fromStatus === 'reviewed' ? 'review_reassessed' : 'reviewed',
                    $reviewerId,
                    [
                        'task_ref' => 'operation_execution_tasks#' . $taskId,
                        'review_ref' => 'operation_action_reviews#' . (int)$review['id'],
                        'evidence_sufficiency' => (string)($review['evidence_sufficiency'] ?? 'insufficient'),
                        'metric_change_status' => (string)($review['metric_change_status'] ?? 'unknown'),
                        'recommendation' => (string)($review['recommendation'] ?? 'adjust'),
                        'causality_claimed' => false,
                        'external_action_performed_by_system' => false,
                    ]
                );
            }
        })();

        return $this->executionTaskDetail($taskId, $hotelIds);
    }

    /**
     * Positive reviews may only use a server-side readback of already persisted OTA facts.
     * Operator screenshots remain useful evidence, but they cannot mint source-verified truth.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $intent
     */
    private function syncSourceVerifiedMetricReadback(array $task, array $intent, array $context): void
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

        (function () use ($payload, $taskId, $context): void {
            $lockedTaskRow = $context['task'];
            $intent = $this->normalizeExecutionIntentRow($context['intent']);
            $lockedTask = $this->normalizeExecutionTaskRow($lockedTaskRow);
            $currentRows = Db::name('operation_execution_evidence')
                ->where('task_id', $taskId)
                ->whereNull('deleted_at')
                ->order('id', 'desc')
                ->select()
                ->toArray();
            $currentTruth = $this->buildExecutionEvidenceTruth(
                $intent,
                $lockedTask,
                array_map([$this, 'normalizeExecutionEvidenceRow'], $currentRows)
            );
            if (($currentTruth['source_verified'] ?? false) === true) {
                return;
            }
            $this->insertExecutionEvidence($payload, (int)($lockedTaskRow['tenant_id'] ?? 0));
            $persistedRows = Db::name('operation_execution_evidence')
                ->where('task_id', $taskId)
                ->whereNull('deleted_at')
                ->order('id', 'desc')
                ->select()
                ->toArray();
            $truth = $this->buildExecutionEvidenceTruth(
                $intent,
                $lockedTask,
                array_map([$this, 'normalizeExecutionEvidenceRow'], $persistedRows)
            );
            if (($truth['source_verified'] ?? false) !== true) {
                throw new \RuntimeException('system source readback evidence failed strict database readback');
            }
        })();
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

        if ($sourceModule === OperatingNetworkService::EXECUTION_SOURCE_MODULE) {
            return $this->buildOperatingNetworkSourceVerifiedReadbackPayload(
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

        if ($sourceModule === OperatingOpportunityLabService::DAILY_SOURCE_MODULE
            && $expectedMetric === 'ctrip_strict_core_fact_count'
        ) {
            return (new OperatingOpportunityLabService())
                ->buildDailyStrictFactCountReadback($task, $intent);
        }

        $isPatrolGapTask = $sourceModule === 'daily_workbench_patrol';
        $isOperatingQuestion = $sourceModule === OperatingQuestionExecutionBridgeService::SOURCE_MODULE;
        $isRevenueCockpitAction = $sourceModule === RevenueCockpitActionContract::SOURCE_MODULE;
        $isDailyOneThing = $sourceModule === OperatingOpportunityLabService::DAILY_SOURCE_MODULE;
        if (!$isPatrolGapTask
            && $sourceModule !== 'ota_diagnosis_saved'
            && !$isOperatingQuestion
            && !$isRevenueCockpitAction
            && !$isDailyOneThing
        ) {
            return null;
        }
        if (!$this->hasVerifiedExecutionSourceProvenance($intent, $task)) {
            return null;
        }

        if ($sourceModule === 'ota_diagnosis_saved'
            || $isOperatingQuestion
            || $isRevenueCockpitAction
            || $isDailyOneThing
        ) {
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
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $metricUnit = trim((string)(
            $targetValue['action_card']['metric_contract']['unit']
            ?? $targetValue['metric_definition']['unit']
            ?? 'source_defined_value'
        ));

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
                'metric_unit' => $metricUnit,
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

    /** @param array<string, mixed> $row */
    private function executionReadbackRowPlatformIdentity(array $row): string
    {
        if (array_key_exists('platform', $row)) {
            return $this->normalizeOtaChannel((string)$row['platform']);
        }

        return $this->normalizeOtaChannel((string)($row['source'] ?? ''));
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
        $metric = strtolower(trim($metric));
        if (!in_array($metric, [
            'list_exposure',
            'detail_exposure',
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
            $endpointId = $this->onlineEndpointIdFromRow($row);
            if ($metric !== 'detail_exposure'
                && $endpointId !== ''
                && !in_array($endpointId, ['business_flow_transform', 'traffic_flow_transform'], true)
            ) {
                continue;
            }
            if ($metric === 'list_exposure') {
                $channel = $this->executionReadbackRowPlatformIdentity($row);
                $fieldFact = $this->onlineMetricFieldFact($row, 'list_exposure');
                if ($channel !== 'ctrip'
                    || !in_array($endpointId, ['business_flow_transform', 'traffic_flow_transform'], true)
                    || !$this->metricScopedFieldFactsReady($row, ['list_exposure'])
                    || (string)($fieldFact['source_key'] ?? '') !== 'listExposure'
                    || trim((string)($fieldFact['source_path'] ?? '')) === ''
                    || !$this->onlineRowHasNumericMetric($row, ['list_exposure'])
                ) {
                    continue;
                }
                $value = $row['list_exposure'] ?? null;
                if (!is_numeric($value) || (float)$value < 0.0 || floor((float)$value) !== (float)$value) {
                    continue;
                }
            } elseif ($metric === 'detail_exposure') {
                $channel = $this->executionReadbackRowPlatformIdentity($row);
                $fieldFact = $this->onlineMetricFieldFact($row, 'detail_exposure');
                $value = $row['detail_exposure'] ?? null;
                if (!in_array($channel, ['ctrip', 'meituan'], true)
                    || !$this->metricScopedFieldFactsReady($row, ['detail_exposure'])
                    || trim((string)($fieldFact['source_key'] ?? '')) === ''
                    || trim((string)($fieldFact['source_path'] ?? '')) === ''
                    || strtolower(trim((string)($fieldFact['storage_field'] ?? '')))
                        !== 'online_daily_data.detail_exposure'
                    || !is_numeric($value)
                    || (float)$value < 0.0
                    || floor((float)$value) !== (float)$value
                ) {
                    continue;
                }
            }
            if (!$this->hasOnlineFlowEvidence($row)) {
                continue;
            }

            $hotelId = (int)($row['system_hotel_id'] ?? 0);
            $channel = $this->executionReadbackRowPlatformIdentity($row);
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
                    foreach ((array)($reference['row_ids'] ?? []) as $rowId) {
                        if ((int)$rowId > 0) {
                            $ids[] = (int)$rowId;
                        }
                    }
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
        string $intentPlatform, ?\DateTimeInterface $now = null
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
            || $this->operationStrictShanghaiDateOrNull($targetDate) === null
            || $this->operationShanghaiBusinessDate($now) < $this->operationStrictShanghaiDateOrNull($targetDate)->modify('+1 day')->format('Y-m-d')
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
            $actual = $this->temporalForecastReadbackResolver !== null
                ? (array)call_user_func(
                    $this->temporalForecastReadbackResolver,
                    $forecastPointId,
                    $hotelId,
                    $metricKey,
                    $targetDate
                )
                : (new TemporalInsightService())->forecastActualReadback(
                    $forecastPointId,
                    $hotelId,
                    $metricKey,
                    $targetDate
                );
        } catch (Throwable $e) {
            throw new \RuntimeException('temporal_forecast_readback_failed', 503, $e);
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
        $executedAt = $this->operationShanghaiTimestampOrNull((string)($task['executed_at'] ?? ''));
        $readbackAt = $this->operationShanghaiTimestampOrNull((string)($actual['readback_at'] ?? ''));
        if ($executedAt === null || $readbackAt === null || $readbackAt < $executedAt) {
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
            OperatingQuestionExecutionBridgeService::SOURCE_MODULE =>
                (new OperatingQuestionExecutionBridgeService())->isIntentCurrent($intent),
            RevenueCockpitActionContract::SOURCE_MODULE =>
                (new RevenueCockpitIntentProvenanceService())->isIntentCurrent($intent),
            OperatingOpportunityLabService::DAILY_SOURCE_MODULE =>
                $this->dailyOneThingIntentIsCurrent($intent),
            default => false,
        };
    }

    /** @param array<string,mixed> $intent */
    private function dailyOneThingIntentIsCurrent(array $intent): bool
    {
        try {
            (new OperatingOpportunityLabService())->assertDailyIntentCurrent($intent);
            return true;
        } catch (\Throwable) {
            return false;
        }
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
    private function hasVerifiedOtaDiagnosisProvenance(array $intent, bool $requireLatest = false): bool
    {
        $recordId = (int)($intent['source_record_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $intentId = (int)($intent['id'] ?? 0);
        if ($recordId <= 0 || $hotelId <= 0 || $intentId <= 0 || !$this->tableExists('agent_logs')) {
            return false;
        }
        try {
            $query = Db::name('agent_logs')
                ->where('id', $recordId)
                ->where('hotel_id', $hotelId)
                ->where('action', 'ota_diagnosis')
                ->where('agent_type', 2);
            if ($requireLatest) {
                $query->lock(true);
            }
            $row = $query->find();
        } catch (Throwable) {
            return false;
        }
        if (!is_array($row)) {
            return false;
        }
        $contextValue = $row['context_data'] ?? [];
        $context = is_array($contextValue) ? $contextValue : $this->decodeJson((string)$contextValue);
        $snapshot = is_array($context['diagnosis_result'] ?? null) ? $context['diagnosis_result'] : [];
        if (!$this->isVerifiedActiveSavedOtaDiagnosisSnapshot($row, $context, $snapshot)) {
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
        $storedRecommendationDigest = strtolower(trim((string)($evidence['decision_recommendation_digest'] ?? '')));
        $embeddedRecommendation = is_array($evidence['decision_recommendation'] ?? null)
            ? $evidence['decision_recommendation']
            : [];
        $decisionQuality = is_array($action['decision_quality'] ?? null)
            ? $action['decision_quality']
            : [];
        $targetMetric = strtolower(trim((string)($targetValue['target_metric'] ?? '')));
        $expectedMetric = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $actionMetric = strtolower(trim((string)($action['expected_metric'] ?? '')));
        $actionType = trim((string)($intent['action_type'] ?? ''));
        $snapshotMetrics = is_array($snapshot['metrics'] ?? null) ? $snapshot['metrics'] : [];
        $currentValue = is_array($intent['current_value'] ?? null) ? $intent['current_value'] : [];
        $metricValuesMatch = $expectedMetric !== ''
            && array_key_exists($expectedMetric, $snapshotMetrics)
            && array_key_exists($expectedMetric, $currentValue)
            && is_numeric($snapshotMetrics[$expectedMetric])
            && is_numeric($currentValue[$expectedMetric])
            && abs((float)$snapshotMetrics[$expectedMetric] - (float)$currentValue[$expectedMetric]) <= 0.0000001;
        $provenanceValid = ($action['execution_ready'] ?? false) === true
            && ($action['can_request_execution_intent'] ?? false) === true
            && ($action['can_create_execution_intent'] ?? false) === true
            && ($decisionQuality['contract_version'] ?? '') === AiDecisionQualityService::CONTRACT_VERSION
            && ($decisionQuality['execution_ready'] ?? false) === true
            && preg_match('/^[a-f0-9]{64}$/D', $storedRecommendationDigest) === 1
            && $embeddedRecommendation !== []
            && hash_equals($storedRecommendationDigest, $this->decisionRecommendationDigest($embeddedRecommendation))
            && hash_equals($storedRecommendationDigest, $this->decisionRecommendationDigest($action))
            && (int)($action['execution_intent_id'] ?? 0) === $intentId
            && $idempotencyKey !== ''
            && hash_equals($idempotencyKey, trim((string)($action['execution_idempotency_key'] ?? '')))
            && ($actionItemId === '' || hash_equals($actionItemId, trim((string)($action['id'] ?? ''))))
            && trim((string)($targetValue['action_text'] ?? '')) === trim((string)($action['action'] ?? ''))
            && $targetMetric === $expectedMetric
            && $actionMetric === $expectedMetric
            && $actionType !== ''
            && hash_equals($actionType, trim((string)($action['action_type'] ?? '')))
            && $metricValuesMatch
            && $intentRefs !== []
            && $intentRefs === $actionRefs;
        if (!$provenanceValid) {
            return false;
        }

        if ($expectedMetric === 'list_exposure') {
            $semantic = $this->ctripListExposureSemanticBinding();
            $targetSemantic = is_array($targetValue['metric_semantic'] ?? null)
                ? $targetValue['metric_semantic']
                : [];
            $evidenceSemantic = is_array($evidence['metric_semantic'] ?? null)
                ? $evidence['metric_semantic']
                : [];
            $actionSemantic = is_array($action['metric_semantic'] ?? null)
                ? $action['metric_semantic']
                : [];
            $baseline = (float)$currentValue['list_exposure'];
            if ($this->normalizeOtaChannel((string)($intent['platform'] ?? '')) !== 'ctrip'
                || floor($baseline) !== $baseline
                || $baseline < 0.0
                || $semantic !== $targetSemantic
                || $semantic !== $evidenceSemantic
                || $semantic !== $actionSemantic
                || !$this->savedOtaDiagnosisListExposureEvidenceReady($intentRefs, $snapshot['evidence_sources'] ?? [])
                || !$this->savedOtaDiagnosisListExposureEvidenceReady($intentRefs, $evidence['evidence_sources'] ?? [])
            ) {
                return false;
            }
        }

        return !$requireLatest || !$this->hasNewerVerifiedOtaDiagnosisForSameScope(
            $recordId,
            $hotelId,
            $context,
            $snapshot
        );
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $context @param array<string,mixed> $snapshot */
    private function isVerifiedActiveSavedOtaDiagnosisSnapshot(
        array $row,
        array $context,
        array $snapshot,
        bool $requireActionRequired = true
    ): bool
    {
        $saved = is_array($snapshot['saved_record'] ?? null) ? $snapshot['saved_record'] : [];
        $contextDigest = strtolower(trim((string)($context['readback_identity_digest'] ?? '')));
        $savedDigest = strtolower(trim((string)($saved['readback_identity_digest'] ?? '')));
        return ($context['record_status'] ?? '') === 'active'
            && ($snapshot['record_status'] ?? '') === 'active'
            && (!$requireActionRequired
                || ($snapshot['decision_status'] ?? $snapshot['decision_closure']['status'] ?? '') === 'action_required')
            && ($saved['saved'] ?? false) === true
            && ($saved['readback_verified'] ?? false) === true
            && (int)($saved['id'] ?? 0) === (int)($row['id'] ?? 0)
            && preg_match('/^[a-f0-9]{64}$/D', $contextDigest) === 1
            && hash_equals($contextDigest, $savedDigest);
    }

    /** @return array<string,mixed> */
    private function ctripListExposureSemanticBinding(): array
    {
        return [
            'contract_version' => 'ota_metric_semantic_binding.v2',
            'platform' => 'ctrip',
            'source_module' => 'ctrip_data_center_flow_transform',
            'source_endpoint_family' => 'ctrip_query_flow_transform_new_v1',
            'source_endpoint_ids' => ['business_flow_transform', 'traffic_flow_transform'],
            'metric_key' => 'list_exposure',
            'semantic_key' => 'ctrip_datacenter_list_exposure_uv',
            'unit' => 'unique_users',
            'value_type' => 'non_negative_integer',
            'source_table' => 'online_daily_data',
            'source_field' => 'list_exposure',
            'field_fact_required' => true,
            'blocked_aliases' => ['generic_impression_count', 'advertising_impressions'],
        ];
    }

    /** @param array<int,string> $refs */
    private function savedOtaDiagnosisListExposureEvidenceReady(array $refs, mixed $sources): bool
    {
        $semantic = $this->ctripListExposureSemanticBinding();
        $allowedEndpointIds = is_array($semantic['source_endpoint_ids'] ?? null)
            ? $semantic['source_endpoint_ids']
            : [];
        if (!is_array($sources)) {
            return false;
        }
        foreach ($sources as $source) {
            if (!is_array($source)
                || !in_array((string)($source['ref'] ?? ''), $refs, true)
                || strtolower(trim((string)($source['table'] ?? ''))) !== 'online_daily_data'
                || $this->normalizeOtaChannel((string)($source['platform'] ?? '')) !== 'ctrip'
            ) {
                continue;
            }
            $metrics = is_array($source['metrics'] ?? null) ? $source['metrics'] : [];
            $factStatuses = is_array($source['metric_fact_statuses'] ?? null)
                ? $source['metric_fact_statuses']
                : [];
            $status = is_array($factStatuses['list_exposure'] ?? null)
                ? $factStatuses['list_exposure']
                : [];
            $value = $metrics['list_exposure'] ?? null;
            if ((string)($status['status'] ?? '') === 'ready'
                && (array)($status['missing_requested_metric_keys'] ?? ['list_exposure']) === []
                && in_array((string)($source['source_endpoint_id'] ?? ''), $allowedEndpointIds, true)
                && in_array((string)($status['source_endpoint_id'] ?? ''), $allowedEndpointIds, true)
                && (string)($status['source_key'] ?? '') === 'listExposure'
                && trim((string)($status['source_path'] ?? '')) !== ''
                && is_numeric($value)
                && (float)$value >= 0.0
                && floor((float)$value) === (float)$value
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $snapshot */
    private function hasNewerVerifiedOtaDiagnosisForSameScope(
        int $recordId,
        int $hotelId,
        array $context,
        array $snapshot
    ): bool {
        $platform = $this->normalizeOtaChannel((string)($snapshot['platform'] ?? $context['platform'] ?? ''));
        $scope = $this->savedOtaDiagnosisScopeRange($context, $snapshot);
        if ($platform === '' || $scope['start_date'] === '' || $scope['end_date'] === '') {
            return true;
        }
        try {
            $rows = Db::name('agent_logs')
                ->where('hotel_id', $hotelId)
                ->where('agent_type', 2)
                ->where('action', 'ota_diagnosis')
                ->where('id', '>', $recordId)
                ->order('id', 'asc')
                ->lock(true)
                ->select()
                ->toArray();
        } catch (Throwable) {
            return true;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidateContext = is_array($row['context_data'] ?? null)
                ? $row['context_data']
                : $this->decodeJson((string)($row['context_data'] ?? ''));
            $candidateSnapshot = is_array($candidateContext['diagnosis_result'] ?? null)
                ? $candidateContext['diagnosis_result']
                : [];
            if (!$this->isVerifiedActiveSavedOtaDiagnosisSnapshot(
                $row,
                $candidateContext,
                $candidateSnapshot,
                false
            )) {
                continue;
            }
            if ($this->normalizeOtaChannel((string)($candidateSnapshot['platform'] ?? $candidateContext['platform'] ?? '')) === $platform
                && $this->savedOtaDiagnosisScopeRange($candidateContext, $candidateSnapshot) === $scope
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $snapshot @return array{start_date:string,end_date:string} */
    private function savedOtaDiagnosisScopeRange(array $context, array $snapshot): array
    {
        $range = is_array($snapshot['requested_date_range'] ?? null)
            ? $snapshot['requested_date_range']
            : (is_array($context['requested_date_range'] ?? null)
                ? $context['requested_date_range']
                : (is_array($snapshot['date_range'] ?? null)
                    ? $snapshot['date_range']
                    : (array)($context['date_range'] ?? [])));
        $start = substr(trim((string)($range['start_date'] ?? $range['start'] ?? '')), 0, 10);
        $end = substr(trim((string)($range['end_date'] ?? $range['end'] ?? $start)), 0, 10);
        return ['start_date' => $start, 'end_date' => $end];
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
            $channel = $this->executionReadbackRowPlatformIdentity($row);
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
            $channel = $this->executionReadbackRowPlatformIdentity($row);
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
        $metric = strtolower(trim($metric));
        if ($metric === 'detail_exposure') {
            $values = [];
            foreach ($rows as $row) {
                if ((int)($row['system_hotel_id'] ?? 0) !== $hotelId
                    || substr(trim((string)($row['data_date'] ?? '')), 0, 10) !== $date
                    || !is_numeric($row['detail_exposure'] ?? null)
                ) {
                    continue;
                }
                $values[] = (float)$row['detail_exposure'];
            }
            return count($values) === 1 ? $values[0] : null;
        }
        $summary = $this->buildSummaryFromRows([], $rows, [$hotelId], $hotelId, $date);
        $ota = $this->buildOtaFromRows($rows);
        $quality = $this->buildServiceQualityFromRows($rows);
        $value = match ($metric) {
            'revenue', 'avg_revenue', 'amount', 'income' => $summary['revenue'] ?? null,
            'orders', 'avg_orders', 'order_count', 'book_order_num' => $summary['orders'] ?? $ota['orders'] ?? null,
            'room_nights', 'avg_room_nights' => $summary['room_nights'] ?? null,
            'adr', 'avg_adr' => $summary['adr'] ?? null,
            'occ', 'occupancy', 'avg_occ' => $summary['occ'] ?? null,
            'list_exposure' => $ota['exposure'] ?? null,
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
        $inspection = DatabaseSchemaRequirement::inspectTable($table);
        if ($inspection['status'] === DatabaseSchemaRequirement::STATUS_UNREADABLE) {
            throw new \RuntimeException('database_table_probe_failed:' . $table, 503);
        }

        return $inspection['status'] === DatabaseSchemaRequirement::STATUS_PRESENT;
    }

    private function withHotelTenantId(
        array $data,
        string $table,
        int $hotelId,
        ?int $authorizedTenantId = null
    ): array
    {
        if ($this->tableHasColumn($table, 'tenant_id')) {
            $data['tenant_id'] = $authorizedTenantId !== null && $authorizedTenantId > 0
                ? $authorizedTenantId
                : $this->tenantIdForHotel($hotelId);
        }

        return $data;
    }

    private function withExecutionTaskTenantId(
        array $data,
        string $table,
        int $taskId,
        ?int $authorizedTenantId = null
    ): array
    {
        if ($this->tableHasColumn($table, 'tenant_id')) {
            $data['tenant_id'] = $authorizedTenantId !== null && $authorizedTenantId > 0
                ? $authorizedTenantId
                : $this->tenantIdForExecutionTask($taskId);
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
        $inspection = DatabaseSchemaRequirement::inspectTableColumns($table);
        if ($inspection['status'] === DatabaseSchemaRequirement::STATUS_UNREADABLE) {
            throw new \RuntimeException('database_table_columns_probe_failed:' . $table, 503);
        }

        return $inspection['status'] === DatabaseSchemaRequirement::STATUS_PRESENT
            && in_array($column, $inspection['columns'], true);
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
        $targetDate = trim((string)($input['target_date'] ?? ''));
        $targetDate = $targetDate === '' ? $this->operationShanghaiToday() : $this->operationStrictShanghaiDate($targetDate, 'daily workbench target_date')->format('Y-m-d');
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
            'date_start' => $targetDate,
            'date_end' => $targetDate,
            'current_value' => [
                'patrol_action_status' => $status,
                'source' => 'daily_workbench_patrol',
            ],
            'target_value' => [
                'collection_scope' => 'daily_workbench_patrol_action',
                'target_date' => $targetDate,
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
            $before = is_array($action['before'] ?? null) ? $action['before'] : [];
            $after = is_array($action['after'] ?? null) ? $action['after'] : [];
            $comparability = $this->assessComparableActionEffectEvidence(
                (string)($action['target_metric'] ?? ''),
                $before,
                $after
            );
            if (in_array($status, $reviewedStatuses, true) && !$comparability['comparable']) {
                $status = 'observing';
                $dataGaps[] = [
                    'code' => $comparability['gap_code'],
                    'message' => $comparability['message'],
                ];
            }
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

            $revenueComparable = $this->assessComparableActionEffectEvidence('revenue', $before, $after)['comparable'];
            $conversionComparable = $this->assessComparableActionEffectEvidence('conversion', $before, $after)['comparable'];
            if ($revenueComparable || $conversionComparable) {
                $beforeRevenue = (float)($before['avg_revenue'] ?? 0);
                $afterRevenue = (float)($after['avg_revenue'] ?? 0);
                if ($revenueComparable && $beforeRevenue > 0) {
                    $revenue['before'] += $beforeRevenue;
                    $revenue['after'] += $afterRevenue;
                    $revenue['sample_count']++;
                }

                $beforeConversion = (float)($before['avg_conversion'] ?? 0);
                $afterConversion = (float)($after['avg_conversion'] ?? 0);
                if ($conversionComparable && $beforeConversion > 0) {
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
        $dataStatus = array_filter(
            $dataGaps,
            static fn(array $gap): bool => ($gap['migration_required'] ?? false) === true
        ) !== [] ? 'migration_required' : $status;

        return [
            'status' => $status,
            'data_status' => $dataStatus,
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
        if (($gap = $this->priceSuggestionTenantSchemaGap()) !== null) {
            $dataGaps[] = $gap;
            return ['total' => 0, 'adopted' => 0, 'data_status' => 'migration_required'];
        }

        $end = $this->operationShanghaiToday();
        $start = (new DateTimeImmutable($end, new DateTimeZone('Asia/Shanghai')))
            ->modify('-' . max(0, $days - 1) . ' days')
            ->format('Y-m-d');
        try {
            $query = Db::name('price_suggestions')->field('status')->whereBetween('suggestion_date', [$start, $end]);
            if ($hotelId !== null && $hotelId > 0) {
                $query->where('hotel_id', $hotelId);
            } elseif (!empty($hotelIds)) {
                $query->whereIn('hotel_id', $hotelIds);
            }
            $rows = $this->scopePriceSuggestionQueryToCurrentTenant($query)->select()->toArray();
        } catch (Throwable $e) {
            $dataGaps[] = [
                'code' => 'price_suggestions_tenant_read_failed',
                'message' => 'current-tenant price suggestions could not be read',
                'migration_required' => false,
            ];
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
        if (($gap = $this->operationAlertTenantSchemaGap()) !== null) {
            $dataGaps[] = $gap;
            return ['reviewed' => 0, 'accurate' => 0, 'data_status' => 'migration_required'];
        }

        $end = $this->operationShanghaiToday();
        $start = (new DateTimeImmutable($end, new DateTimeZone('Asia/Shanghai')))
            ->modify('-' . max(0, $days - 1) . ' days')
            ->format('Y-m-d');
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
            $rows = $this->scopeOperationAlertQueryToCurrentTenant($query)->select()->toArray();
        } catch (Throwable $e) {
            $dataGaps[] = [
                'code' => 'operation_alerts_tenant_read_failed',
                'message' => 'current-tenant operation alert accuracy could not be read',
                'migration_required' => false,
            ];
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
        $accuracyReview = $raw['accuracy_review'] ?? null;
        if (is_array($accuracyReview) && is_bool($accuracyReview['accurate'] ?? null)) {
            return $accuracyReview['accurate'];
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
