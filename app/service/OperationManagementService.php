<?php
declare(strict_types=1);

namespace app\service;

use app\model\SystemConfig;
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
        $serviceQuality = $this->buildServiceQuality($hotelIds, $date);
        $competitors = $this->buildCompetitors($hotelIds, $date, $summary);
        $holiday = $this->buildHoliday($date);
        $abnormalFlags = [];

        if (($ota['exposure'] ?? 0) <= 0 && ($ota['visitors'] ?? 0) <= 0 && ($ota['orders'] ?? 0) > 0) {
            $abnormalFlags[] = '曝光/访客为0但订单大于0，疑似采集异常';
        }

        foreach ([
            '经营日报' => $summary,
            'OTA数据' => $ota,
            '竞对数据' => $competitors,
            '服务质量数据' => $serviceQuality,
        ] as $module => $data) {
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

        $psiScore = (float)($serviceQuality['avg_psi_score'] ?? 0);
        $serviceScore = (float)($serviceQuality['avg_service_score'] ?? 0);
        if (($serviceQuality['data_status'] ?? '') === self::DATA_OK && (($psiScore > 0 && $psiScore < 80) || ($serviceScore > 0 && $serviceScore < 80))) {
            $rootCauses[] = $this->cause('service_quality_low', '服务质量偏低', 6, 0.72, 'OTA服务质量或PSI低于80分', '优先复核服务质量扣分项、履约问题和影响转化的服务节点');
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
                'next_actions' => ['补齐OTA曝光、访客、订单、竞对价格、广告和服务质量数据'],
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
        if (!empty($generated) && $this->tableExists('operation_alerts')) {
            $generated = $this->persistRuleAlerts($generated);
        }

        return [
            'list' => $generated,
            'unread_count' => count(array_filter($generated, static fn(array $row): bool => ($row['status'] ?? '') !== 'read')),
            'data_status' => empty($generated) ? '暂无预警' : self::DATA_OK,
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

        $selectedHotelId = (int)($hotelId ?: ($hotelIds[0] ?? 0));
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
            $this->withTenantId($data, 'operation_action_tracks', $selectedHotelId)
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
            ];
        }

        $query = Db::name('operation_execution_intents')->whereNull('deleted_at');
        if ($hotelId !== null && $hotelId > 0) {
            $query->where('hotel_id', $hotelId);
        } elseif (!empty($hotelIds)) {
            $query->whereIn('hotel_id', $hotelIds);
        }
        foreach (['platform', 'object_type', 'action_type', 'status'] as $field) {
            $value = trim((string)($filters[$field] ?? ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        $intentRows = $query->order('id', 'desc')->limit(100)->select()->toArray();
        if (empty($intentRows)) {
            $summary = $this->buildExecutionFlowSummary([]);
            return [
                'summary' => $summary,
                'stages' => $this->buildExecutionFlowStages($summary),
                'list' => [],
                'data_status' => self::DATA_OK,
                'data_gaps' => [],
            ];
        }

        $intentIds = array_map(static fn(array $row): int => (int)$row['id'], $intentRows);
        $tasksByIntent = [];
        $evidenceByIntent = [];
        $dataGaps = [];

        if ($this->tableExists('operation_execution_tasks')) {
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
                if ($this->tableExists('operation_execution_evidence')) {
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

        $items = [];
        foreach ($intentRows as $intentRow) {
            $intentId = (int)$intentRow['id'];
            $items[] = $this->buildExecutionFlowItem(
                $intentRow,
                $tasksByIntent[$intentId] ?? [],
                $evidenceByIntent[$intentId] ?? []
            );
        }

        $summary = $this->buildExecutionFlowSummary($items);

        return [
            'summary' => $summary,
            'stages' => $this->buildExecutionFlowStages($summary),
            'list' => $items,
            'data_status' => self::DATA_OK,
            'data_gaps' => $dataGaps,
        ];
    }

    public function buildExecutionFlowItem(array $intentRow, array $taskRows = [], array $evidenceRows = []): array
    {
        $intent = $this->normalizeExecutionIntentRow($intentRow);
        $tasks = array_map([$this, 'normalizeExecutionTaskRow'], $taskRows);
        usort($tasks, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));

        $evidence = array_map([$this, 'normalizeExecutionEvidenceRow'], $evidenceRows);
        usort($evidence, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));

        $task = $this->latestExecutionTask($tasks);
        $taskId = (int)($task['id'] ?? 0);
        $taskEvidence = $taskId > 0
            ? array_values(array_filter($evidence, static fn(array $row): bool => (int)($row['task_id'] ?? 0) === $taskId))
            : $evidence;
        $latestEvidence = $taskEvidence[0] ?? [];
        $reviewStatus = (string)($task['result_status'] ?? 'observing');
        $stage = $this->executionFlowStage($intent, $task, count($taskEvidence), $reviewStatus);
        $sourceModule = (string)($intent['source_module'] ?? 'manual');
        $sourceRecordId = (int)($intent['source_record_id'] ?? 0);

        return [
            'id' => (int)$intent['id'],
            'hotel_id' => (int)$intent['hotel_id'],
            'stage' => $stage,
            'recommendation' => [
                'source' => $sourceModule . '#' . $sourceRecordId,
                'source_module' => $sourceModule,
                'source_record_id' => $sourceRecordId,
                'platform' => (string)($intent['platform'] ?? ''),
                'object_type' => (string)($intent['object_type'] ?? ''),
                'action_type' => (string)($intent['action_type'] ?? ''),
                'date_start' => (string)($intent['date_start'] ?? ''),
                'date_end' => (string)($intent['date_end'] ?? ''),
                'expected_metric' => (string)($intent['expected_metric'] ?? ''),
                'expected_delta' => (float)($intent['expected_delta'] ?? 0),
                'risk_level' => (string)($intent['risk_level'] ?? ''),
                'current_value' => $intent['current_value'] ?? [],
                'target_value' => $intent['target_value'] ?? [],
                'evidence' => $intent['evidence'] ?? [],
                'created_at' => (string)($intent['created_at'] ?? ''),
            ],
            'approval' => [
                'status' => (string)($intent['status'] ?? ''),
                'approved_by' => (int)($intent['approved_by'] ?? 0),
                'approved_at' => (string)($intent['approved_at'] ?? ''),
                'remark' => (string)($intent['review_remark'] ?? ''),
                'blocked_reason' => (string)($intent['blocked_reason'] ?? ''),
            ],
            'execution' => [
                'task_id' => $taskId,
                'mode' => (string)($task['execution_mode'] ?? ''),
                'status' => (string)($task['status'] ?? 'pending_create'),
                'operator_id' => (int)($task['operator_id'] ?? 0),
                'executed_at' => (string)($task['executed_at'] ?? ''),
                'blocked_reason' => (string)($task['blocked_reason'] ?? ''),
                'target_value' => $task['target_value'] ?? [],
                'current_value' => $task['current_value'] ?? [],
            ],
            'evidence' => [
                'count' => count($taskEvidence),
                'latest' => $latestEvidence,
            ],
            'review' => [
                'status' => $reviewStatus,
                'summary' => (string)($task['result_summary'] ?? ''),
                'action_track_id' => (int)($task['action_track_id'] ?? 0),
            ],
            'roi' => $this->buildExecutionRoi($intent, $task, $latestEvidence),
            'next_action' => $this->buildExecutionNextAction($stage, $intent, $task),
        ];
    }

    public function buildExecutionFlowSummary(array $items): array
    {
        $stageCounts = [
            'recommendation' => 0,
            'approval' => 0,
            'execution' => 0,
            'evidence' => 0,
            'review' => 0,
            'reviewed' => 0,
            'blocked' => 0,
            'rejected' => 0,
            'failed' => 0,
        ];
        $roiValues = [];
        $profitable = 0;
        $approved = 0;
        $executed = 0;
        $evidenceReady = 0;
        $totalIncrementalRevenue = 0.0;
        $totalCost = 0.0;
        $totalProfit = 0.0;

        foreach ($items as $item) {
            $stage = (string)($item['stage'] ?? 'recommendation');
            if (!array_key_exists($stage, $stageCounts)) {
                $stageCounts[$stage] = 0;
            }
            $stageCounts[$stage]++;

            if (($item['approval']['status'] ?? '') === 'approved') {
                $approved++;
            }
            if (($item['execution']['status'] ?? '') === 'executed') {
                $executed++;
            }
            if ((int)($item['evidence']['count'] ?? 0) > 0) {
                $evidenceReady++;
            }
            if (($item['roi']['status'] ?? '') === 'ready') {
                $value = (float)($item['roi']['value'] ?? 0);
                $roiValues[] = $value;
                $totalIncrementalRevenue += (float)($item['roi']['incremental_revenue'] ?? 0);
                $totalCost += (float)($item['roi']['cost'] ?? 0);
                $totalProfit += (float)($item['roi']['profit'] ?? 0);
                if ((float)($item['roi']['profit'] ?? 0) > 0) {
                    $profitable++;
                }
            }
        }

        $total = count($items);
        $roiReady = count($roiValues);

        return [
            'total' => $total,
            'stage_counts' => $stageCounts,
            'bottleneck' => $this->buildExecutionBottleneck($stageCounts),
            'approved' => $approved,
            'executed' => $executed,
            'evidence_ready' => $evidenceReady,
            'roi_ready' => $roiReady,
            'avg_roi' => $roiReady > 0 ? round(array_sum($roiValues) / $roiReady, 2) : null,
            'approval_rate' => $total > 0 ? round($approved / $total * 100, 2) : null,
            'execution_rate' => $total > 0 ? round($executed / $total * 100, 2) : null,
            'evidence_rate' => $total > 0 ? round($evidenceReady / $total * 100, 2) : null,
            'roi_ready_rate' => $total > 0 ? round($roiReady / $total * 100, 2) : null,
            'profitable' => $profitable,
            'profitable_rate' => $roiReady > 0 ? round($profitable / $roiReady * 100, 2) : null,
            'total_incremental_revenue' => round($totalIncrementalRevenue, 2),
            'total_cost' => round($totalCost, 2),
            'total_profit' => round($totalProfit, 2),
            'money_status' => $this->executionMoneyStatus($roiReady, $totalProfit),
        ];
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
        $date = $this->normalizeExecutionDate((string)($suggestion['suggestion_date'] ?? date('Y-m-d')));

        return [
            'source_module' => 'price_suggestion',
            'source_record_id' => (int)($suggestion['id'] ?? 0),
            'hotel_id' => (int)($suggestion['hotel_id'] ?? 0),
            'platform' => strtolower(trim((string)($overrides['platform'] ?? $overrides['channel'] ?? ''))),
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => $date,
            'date_end' => $date,
            'current_value' => [
                'current_price' => (float)($suggestion['current_price'] ?? 0),
                'room_type_id' => (int)($suggestion['room_type_id'] ?? 0),
            ],
            'target_value' => [
                'target_price' => (float)($suggestion['suggested_price'] ?? 0),
                'min_price' => (float)($suggestion['min_price'] ?? 0),
                'max_price' => (float)($suggestion['max_price'] ?? 0),
                'room_type_key' => trim((string)($overrides['room_type_key'] ?? '')),
                'rate_plan_key' => trim((string)($overrides['rate_plan_key'] ?? '')),
                'room_type_id' => (int)($suggestion['room_type_id'] ?? 0),
            ],
            'evidence' => [
                'reason' => (string)($suggestion['reason'] ?? ''),
                'factors' => $this->arrayValue($suggestion['factors'] ?? []),
                'competitor_data' => $this->arrayValue($suggestion['competitor_data'] ?? []),
            ],
            'expected_metric' => trim((string)($overrides['expected_metric'] ?? 'orders')),
            'expected_delta' => (float)($overrides['expected_delta'] ?? 0),
            'risk_level' => trim((string)($overrides['risk_level'] ?? 'medium')),
        ];
    }

    public function buildExecutionIntentPayload(array $hotelIds, ?int $hotelId, array $input, int $createdBy): array
    {
        $selectedHotelId = (int)($input['hotel_id'] ?? $hotelId ?? ($hotelIds[0] ?? 0));
        if ($selectedHotelId <= 0 || !in_array($selectedHotelId, array_map('intval', $hotelIds), true)) {
            throw new \InvalidArgumentException('hotel_id is not permitted');
        }

        $objectType = trim((string)($input['object_type'] ?? ''));
        $targetValue = $this->arrayValue($input['target_value'] ?? []);
        $currentValue = $this->arrayValue($input['current_value'] ?? []);
        $evidence = $this->arrayValue($input['evidence'] ?? $input['evidence_json'] ?? []);
        $blockedReasons = $this->executionIntentBlockedReasons($objectType, $input, $targetValue, $evidence);
        $status = $blockedReasons ? 'blocked' : (in_array((string)($input['status'] ?? ''), ['draft', 'pending_approval'], true) ? (string)$input['status'] : 'pending_approval');

        return [
            'source_module' => trim((string)($input['source_module'] ?? 'manual')),
            'source_record_id' => (int)($input['source_record_id'] ?? 0),
            'hotel_id' => $selectedHotelId,
            'platform' => strtolower(trim((string)($input['platform'] ?? ''))),
            'object_type' => $objectType,
            'action_type' => trim((string)($input['action_type'] ?? '')),
            'date_start' => $this->normalizeExecutionDate((string)($input['date_start'] ?? $input['start_date'] ?? date('Y-m-d'))),
            'date_end' => $this->normalizeExecutionDate((string)($input['date_end'] ?? $input['end_date'] ?? $input['date_start'] ?? $input['start_date'] ?? date('Y-m-d'))),
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

    public function buildExecutionTaskUpdate(array $task, array $intent, array $input, int $operatorId): array
    {
        if (($intent['status'] ?? '') !== 'approved') {
            throw new \InvalidArgumentException('intent must be approved before execution');
        }

        $status = trim((string)($input['status'] ?? 'executed'));
        if (!in_array($status, ['executing', 'blocked', 'executed', 'failed'], true)) {
            throw new \InvalidArgumentException('execution status is not supported');
        }

        $evidence = $this->arrayValue($input['evidence'] ?? []);
        if ($status === 'executed' && empty($evidence)) {
            $status = 'blocked';
            $input['blocked_reason'] = trim((string)($input['blocked_reason'] ?? 'execution evidence missing'));
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
                'platform_response' => $this->arrayValue($evidence['platform_response'] ?? []),
                'remark' => trim((string)($evidence['remark'] ?? '')),
                'created_by' => $operatorId,
                'created_at' => $now,
            ];
        }

        return ['task' => $taskUpdate, 'evidence' => $evidencePayload];
    }

    public function createExecutionIntent(array $hotelIds, ?int $hotelId, array $input, int $createdBy): array
    {
        $this->ensureExecutionTables();
        $payload = $this->buildExecutionIntentPayload($hotelIds, $hotelId, $input, $createdBy);
        $now = date('Y-m-d H:i:s');

        $id = (int)Db::name('operation_execution_intents')->insertGetId($this->withTenantId([
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
        ], 'operation_execution_intents', (int)$payload['hotel_id']));

        return $this->executionIntentDetail($id, $hotelIds);
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

    public function approveExecutionIntent(int $id, bool $approved, string $remark, int $userId, array $hotelIds): array
    {
        $this->ensureExecutionTables();
        $intent = $this->executionIntentRow($id, $hotelIds);
        if (!$intent) {
            throw new \RuntimeException('execution intent not found');
        }
        if ($approved && ($intent['status'] ?? '') === 'blocked') {
            throw new \InvalidArgumentException('blocked execution intent cannot be approved');
        }

        $now = date('Y-m-d H:i:s');
        $status = $approved ? 'approved' : 'rejected';
        Db::name('operation_execution_intents')->where('id', $id)->update([
            'status' => $status,
            'approved_by' => $userId,
            'approved_at' => $now,
            'review_remark' => $remark,
            'updated_at' => $now,
        ]);

        if ($approved) {
            $taskExists = (int)Db::name('operation_execution_tasks')->where('intent_id', $id)->whereNull('deleted_at')->count();
            if ($taskExists === 0) {
                Db::name('operation_execution_tasks')->insert($this->withTenantId([
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

        return $this->executionIntentDetail($id, $hotelIds);
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

        $built = $this->buildExecutionTaskUpdate($task, $intent, $input, $operatorId);
        $taskUpdate = $built['task'];
        $dbUpdate = $taskUpdate;
        foreach (['current_value', 'target_value'] as $jsonField) {
            if (array_key_exists($jsonField, $dbUpdate)) {
                $dbUpdate[$jsonField . '_json'] = json_encode($dbUpdate[$jsonField], JSON_UNESCAPED_UNICODE);
                unset($dbUpdate[$jsonField]);
            }
        }

        Db::name('operation_execution_tasks')->where('id', $taskId)->update($dbUpdate);
        if ($built['evidence'] !== null) {
            $this->insertExecutionEvidence($built['evidence']);
        }

        if (($taskUpdate['status'] ?? '') === 'executed' && empty($task['action_track_id']) && $this->tableExists('operation_action_tracks')) {
            $actionTrackId = $this->createActionTrackForExecution($intent, $taskId);
            Db::name('operation_execution_tasks')->where('id', $taskId)->update(['action_track_id' => $actionTrackId]);
        }

        return $this->executionTaskDetail($taskId, $hotelIds);
    }

    public function addExecutionEvidence(int $taskId, array $hotelIds, array $input, int $userId): array
    {
        $this->ensureExecutionTables();
        $task = $this->executionTaskRow($taskId, $hotelIds);
        if (!$task) {
            throw new \RuntimeException('execution task not found');
        }

        $evidence = $this->arrayValue($input['evidence'] ?? $input);
        if (empty($evidence)) {
            throw new \InvalidArgumentException('execution evidence is required');
        }

        $payload = [
            'task_id' => $taskId,
            'evidence_type' => trim((string)($input['evidence_type'] ?? $evidence['evidence_type'] ?? 'manual')),
            'before' => $this->arrayValue($evidence['before'] ?? []),
            'after' => $this->arrayValue($evidence['after'] ?? []),
            'attachment_path' => trim((string)($evidence['attachment_path'] ?? '')),
            'platform_response' => $this->arrayValue($evidence['platform_response'] ?? []),
            'remark' => trim((string)($evidence['remark'] ?? '')),
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->insertExecutionEvidence($payload);

        return $this->executionTaskDetail($taskId, $hotelIds);
    }

    public function reviewExecutionTask(int $taskId, array $hotelIds): array
    {
        $this->ensureExecutionTables();
        $task = $this->executionTaskRow($taskId, $hotelIds);
        if (!$task) {
            throw new \RuntimeException('execution task not found');
        }

        $summary = 'waiting for action tracking data';
        $resultStatus = 'observing';
        $actionTrackId = (int)($task['action_track_id'] ?? 0);
        if ($actionTrackId > 0 && $this->finishAction($actionTrackId, [(int)$task['hotel_id']])) {
            $action = Db::name('operation_action_tracks')->where('id', $actionTrackId)->find();
            if ($action) {
                $summary = (string)($action['result_summary'] ?? $summary);
                $resultStatus = (string)($action['result_status'] ?? $resultStatus);
            }
        }

        Db::name('operation_execution_tasks')->where('id', $taskId)->update([
            'result_status' => $resultStatus,
            'result_summary' => $summary,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->executionTaskDetail($taskId, $hotelIds);
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

    private function withTenantId(array $data, string $table, int $tenantId): array
    {
        if ($this->tableHasColumn($table, 'tenant_id')) {
            $data['tenant_id'] = $tenantId > 0 ? $tenantId : null;
        }

        return $data;
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

    private function tenantIdForExecutionTask(int $taskId): int
    {
        if ($taskId <= 0) {
            return 0;
        }

        try {
            $row = Db::name('operation_execution_tasks')->where('id', $taskId)->field('tenant_id,hotel_id')->find();
            if (!$row) {
                return 0;
            }

            $tenantId = (int)($row['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                return $tenantId;
            }

            return max(0, (int)($row['hotel_id'] ?? 0));
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function buildExecutionFlowStages(array $summary): array
    {
        $counts = $summary['stage_counts'] ?? [];
        return [
            ['key' => 'recommendation', 'label' => '建议动作', 'count' => (int)($counts['recommendation'] ?? 0)],
            ['key' => 'approval', 'label' => '审批', 'count' => (int)($counts['approval'] ?? 0)],
            ['key' => 'execution', 'label' => '执行', 'count' => (int)($counts['execution'] ?? 0)],
            ['key' => 'evidence', 'label' => '执行证据', 'count' => (int)($counts['evidence'] ?? 0)],
            ['key' => 'review', 'label' => '效果复盘', 'count' => (int)($counts['review'] ?? 0)],
            ['key' => 'reviewed', 'label' => 'ROI确认', 'count' => (int)($counts['reviewed'] ?? 0)],
        ];
    }

    private function buildExecutionNextAction(string $stage, array $intent, array $task): array
    {
        return match ($stage) {
            'approval' => [
                'key' => 'approve_intent',
                'label' => '审批执行意图',
                'priority' => 'high',
                'target_id' => (int)($intent['id'] ?? 0),
            ],
            'execution' => [
                'key' => empty($task) ? 'wait_task_create' : 'record_execution',
                'label' => empty($task) ? '等待生成执行任务' : '记录执行结果',
                'priority' => empty($task) ? 'medium' : 'high',
                'target_id' => (int)($task['id'] ?? 0),
            ],
            'evidence' => [
                'key' => 'record_evidence',
                'label' => '补充执行证据',
                'priority' => 'high',
                'target_id' => (int)($task['id'] ?? 0),
            ],
            'review' => [
                'key' => 'review_effect',
                'label' => '触发效果复盘',
                'priority' => 'medium',
                'target_id' => (int)($task['id'] ?? 0),
            ],
            'blocked' => [
                'key' => 'resolve_blocker',
                'label' => '处理阻塞原因',
                'priority' => 'high',
                'target_id' => (int)($intent['id'] ?? 0),
            ],
            'failed' => [
                'key' => 'review_failure',
                'label' => '复核失败原因',
                'priority' => 'high',
                'target_id' => (int)($task['id'] ?? 0),
            ],
            default => [
                'key' => 'none',
                'label' => '无需操作',
                'priority' => 'low',
                'target_id' => 0,
            ],
        };
    }

    private function buildExecutionBottleneck(array $stageCounts): array
    {
        $stage = '';
        $count = 0;
        foreach (['approval', 'execution', 'evidence', 'review', 'blocked', 'failed'] as $candidate) {
            $value = (int)($stageCounts[$candidate] ?? 0);
            if ($value > $count) {
                $stage = $candidate;
                $count = $value;
            }
        }

        return [
            'stage' => $stage,
            'count' => $count,
            'label' => $this->executionStageLabel($stage),
        ];
    }

    private function executionStageLabel(string $stage): string
    {
        return [
            'approval' => '审批',
            'execution' => '执行',
            'evidence' => '执行证据',
            'review' => '效果复盘',
            'reviewed' => 'ROI确认',
            'blocked' => '阻塞',
            'failed' => '失败',
        ][$stage] ?? '';
    }

    private function executionMoneyStatus(int $roiReady, float $totalProfit): string
    {
        if ($roiReady <= 0) {
            return 'no_roi';
        }
        if ($totalProfit > 0) {
            return 'profit_positive';
        }
        if ($totalProfit < 0) {
            return 'profit_negative';
        }

        return 'break_even';
    }

    private function latestExecutionTask(array $tasks): array
    {
        if (empty($tasks)) {
            return [];
        }

        foreach (['executed', 'executing', 'pending_execute', 'blocked', 'failed'] as $status) {
            foreach ($tasks as $task) {
                if ((string)($task['status'] ?? '') === $status) {
                    return $task;
                }
            }
        }

        return $tasks[0];
    }

    private function executionFlowStage(array $intent, array $task, int $evidenceCount, string $reviewStatus): string
    {
        $intentStatus = (string)($intent['status'] ?? '');
        if ($intentStatus === 'blocked') {
            return 'blocked';
        }
        if ($intentStatus === 'rejected') {
            return 'rejected';
        }
        if (!in_array($intentStatus, ['approved'], true)) {
            return 'approval';
        }

        if (empty($task)) {
            return 'execution';
        }

        $taskStatus = (string)($task['status'] ?? '');
        if ($taskStatus === 'blocked') {
            return 'blocked';
        }
        if ($taskStatus === 'failed') {
            return 'failed';
        }
        if ($taskStatus !== 'executed') {
            return 'execution';
        }
        if ($evidenceCount <= 0) {
            return 'evidence';
        }
        if (in_array($reviewStatus, ['success', 'near_success', 'failed'], true)) {
            return 'reviewed';
        }

        return 'review';
    }

    private function buildExecutionRoi(array $intent, array $task, array $latestEvidence): array
    {
        if (empty($latestEvidence)) {
            return ['status' => 'data_gap', 'message' => 'execution evidence missing'];
        }

        $before = $this->arrayValue($latestEvidence['before'] ?? []);
        $after = $this->arrayValue($latestEvidence['after'] ?? []);
        $beforeRevenue = $this->firstNumericMetric($before, ['revenue', 'avg_revenue', 'amount', 'income']);
        $afterRevenue = $this->firstNumericMetric($after, ['revenue', 'avg_revenue', 'amount', 'income']);
        if ($beforeRevenue === null || $afterRevenue === null) {
            return ['status' => 'data_gap', 'message' => 'revenue evidence missing'];
        }

        $platformResponse = $this->arrayValue($latestEvidence['platform_response'] ?? []);
        $targetValue = $this->arrayValue($task['target_value'] ?? []);
        if (empty($targetValue)) {
            $targetValue = $this->arrayValue($intent['target_value'] ?? []);
        }
        $cost = $this->firstNumericMetric($after, ['cost', 'ad_cost', 'spend', 'budget']);
        $cost ??= $this->firstNumericMetric($platformResponse, ['cost', 'ad_cost', 'spend', 'budget']);
        $cost ??= $this->firstNumericMetric($targetValue, ['cost', 'ad_cost', 'spend', 'budget']);
        if ($cost === null || $cost <= 0) {
            return ['status' => 'data_gap', 'message' => 'cost evidence missing'];
        }

        $incrementalRevenue = $afterRevenue - $beforeRevenue;
        $profit = $incrementalRevenue - $cost;

        return [
            'status' => 'ready',
            'value' => round($profit / $cost * 100, 2),
            'unit' => '%',
            'before_revenue' => round($beforeRevenue, 2),
            'after_revenue' => round($afterRevenue, 2),
            'incremental_revenue' => round($incrementalRevenue, 2),
            'cost' => round($cost, 2),
            'profit' => round($profit, 2),
            'formula' => '(after_revenue - before_revenue - cost) / cost',
        ];
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
        if (empty($evidence)) {
            $reasons[] = 'evidence missing';
        }

        if ($objectType === 'price') {
            foreach (['room_type_key', 'rate_plan_key', 'target_price'] as $field) {
                if (!array_key_exists($field, $targetValue) || trim((string)$targetValue[$field]) === '') {
                    $reasons[] = $field . ' missing';
                }
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
        } elseif ($objectType !== '') {
            $reasons[] = 'object_type not supported';
        }

        return array_values(array_unique($reasons));
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

    private function normalizeExecutionDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('execution date is invalid');
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

    private function executionIntentRow(int $id, array $hotelIds): ?array
    {
        if ($id <= 0 || empty($hotelIds)) {
            return null;
        }

        $row = Db::name('operation_execution_intents')
            ->where('id', $id)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at')
            ->find();

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
        $tasks = Db::name('operation_execution_tasks')
            ->where('intent_id', $id)
            ->whereNull('deleted_at')
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
        $evidenceRows = Db::name('operation_execution_evidence')
            ->where('task_id', $id)
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $task['evidence'] = array_map([$this, 'normalizeExecutionEvidenceRow'], $evidenceRows);

        return $task;
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
        unset($row['current_value_json'], $row['target_value_json'], $row['evidence_json']);

        return $row;
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

        return $row;
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

        return $row;
    }

    private function insertExecutionEvidence(array $payload): void
    {
        $taskId = (int)$payload['task_id'];
        Db::name('operation_execution_evidence')->insert($this->withTenantId([
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
        ], 'operation_execution_evidence', $this->tenantIdForExecutionTask($taskId)));
    }

    private function createActionTrackForExecution(array $intent, int $taskId): int
    {
        $target = $this->decodeJson((string)($intent['target_value_json'] ?? ''));
        $dateStart = (string)($intent['date_start'] ?? date('Y-m-d'));
        $hotelId = (int)$intent['hotel_id'];
        $before = $this->baseline([$hotelId], 7, $dateStart);

        return (int)Db::name('operation_action_tracks')->insertGetId($this->withTenantId([
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
            'revenue' => 0,
            'orders' => 0,
            'room_nights' => 0,
            'adr' => 0,
            'occ' => 0,
            'revpar' => 0,
            'data_status' => self::DATA_PENDING,
        ];

        if (empty($daily) && empty($online)) {
            return $base;
        }

        $roomCount = 0;
        foreach ($daily as $row) {
            $reportData = $this->decodeJson((string)($row['report_data'] ?? ''));
            $base['revenue'] += $this->extractRevenue($row, $reportData);
            $base['room_nights'] += $this->extractRoomNights($row, $reportData);
            $roomCount += $this->extractSalableRoomCount($row, $reportData);
            $base['occ'] = max($base['occ'], (float)($row['occupancy_rate'] ?? 0));
        }

        $dailyFinancialKeys = $this->buildDailyFinancialKeys($daily);
        foreach ($online as $row) {
            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $base['orders'] += (int)($row['book_order_num'] ?? 0);
            if (!$this->hasDailyFinancialForOnlineRow($dailyFinancialKeys, $row)) {
                $base['revenue'] += (float)($row['amount'] ?? 0);
                $base['room_nights'] += (float)($row['quantity'] ?? $row['data_value'] ?? 0);
            }
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

    private function buildCompetitors(array $hotelIds, string $date, array $summary): array
    {
        $base = [
            'avg_price' => 0,
            'avg_score' => 0,
            'price_gap' => 0,
            'score_gap' => 0,
            'rank_position' => null,
            'data_status' => self::DATA_PENDING,
            'meituan_rank_summary' => $this->buildMeituanRankSummary($hotelIds, $date),
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
        $base['score_gap'] = 0;
        $base['rank_position'] = !empty($ranks) ? min($ranks) : null;
        $base['data_status'] = self::DATA_OK;

        return $base;
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

        $targetPoiId = $this->resolveMeituanTargetPoiId($hotelIds);
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
        ];
    }

    private function isMeituanBusinessRankRow(array $row): bool
    {
        $source = strtolower((string)($row['source'] ?? ''));
        $platform = strtolower((string)($row['platform'] ?? ''));
        $dataType = strtolower((string)($row['data_type'] ?? ''));
        return ($source === 'meituan' || $platform === 'meituan') && ($dataType === '' || $dataType === 'business');
    }

    private function resolveMeituanTargetPoiId(array $hotelIds): string
    {
        $hotelIdSet = array_fill_keys(array_map('strval', array_map('intval', $hotelIds)), true);
        try {
            $raw = SystemConfig::getValue('meituan_config_list', '[]');
            $list = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        } catch (Throwable $e) {
            return '';
        }
        if (!is_array($list)) {
            return '';
        }

        foreach ($list as $config) {
            if (!is_array($config)) {
                continue;
            }
            $configHotelId = (string)($config['system_hotel_id'] ?? $config['hotel_id'] ?? '');
            if ($configHotelId === '' || !isset($hotelIdSet[(string)(int)$configHotelId])) {
                continue;
            }
            foreach (['poi_id', 'poiId', 'store_id', 'storeId'] as $key) {
                $value = trim((string)($config[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
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
            'avg_psi_score' => 0,
            'avg_service_score' => 0,
            'sample_count' => 0,
            'data_status' => self::DATA_PENDING,
        ];

        $psiScores = [];
        $serviceScores = [];
        foreach ($rows as $row) {
            $dataType = strtolower((string)($row['data_type'] ?? ''));
            if (!in_array($dataType, ['quality', 'service', 'service_quality', 'psi'], true)) {
                continue;
            }

            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $psi = $this->firstNumericMetric($raw, ['psiScore', 'psi_score', 'psi', 'serviceQualityScore', 'qualityScore']);
            if ($psi === null) {
                $psi = $this->firstNumericMetric($row, ['data_value']);
            }
            $serviceScore = $this->firstNumericMetric($raw, ['serviceScore', 'service_score', 'dayReportServiceScore', 'service_score_value']);

            if ($psi !== null && $psi > 0) {
                $psiScores[] = $psi;
            }
            if ($serviceScore !== null && $serviceScore > 0) {
                $serviceScores[] = $serviceScore;
            }
            if (($psi !== null && $psi > 0) || ($serviceScore !== null && $serviceScore > 0)) {
                $base['sample_count']++;
            }
        }

        if ($base['sample_count'] <= 0) {
            return $base;
        }

        $base['avg_psi_score'] = $this->avg($psiScores);
        $base['avg_service_score'] = $this->avg($serviceScores);
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
            $roomNights += $this->extractRoomNights($row, $reportData);
        }

        $dailyFinancialKeys = $this->buildDailyFinancialKeys($daily);
        foreach ($online as $row) {
            $dates[(string)$row['data_date']] = true;
            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $orders += (float)($row['book_order_num'] ?? $raw['bookOrderNum'] ?? 0);
            if (!$this->hasDailyFinancialForOnlineRow($dailyFinancialKeys, $row) && (float)($row['amount'] ?? 0) > 0) {
                $revenue += (float)$row['amount'];
            }
            if (!$this->hasDailyFinancialForOnlineRow($dailyFinancialKeys, $row)) {
                $roomNights += (float)($row['quantity'] ?? 0);
            }
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
        $psiScore = (float)($full['service_quality']['avg_psi_score'] ?? 0);
        $serviceScore = (float)($full['service_quality']['avg_service_score'] ?? 0);
        if (($full['service_quality']['data_status'] ?? '') === self::DATA_OK && (($psiScore > 0 && $psiScore < 80) || ($serviceScore > 0 && $serviceScore < 80))) {
            $alerts[] = $this->alert($id++, $hotelId ?: ($hotelIds[0] ?? 0), 'service_quality_low', 'medium', '服务质量偏低', 'OTA服务质量或PSI低于80分', $date);
        }
        if (($full['holiday']['days_left'] ?? 999) < 15 && ($full['holiday']['data_status'] ?? '') === self::DATA_OK) {
            $alerts[] = $this->alert($id++, $hotelId ?: ($hotelIds[0] ?? 0), 'holiday_near', 'low', '节假日临近', '距离下个节假日不足15天', $date);
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
            $payload = $this->withTenantId($payload, 'operation_alerts', $hotelId);

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
        $row['action_suggestion'] = $this->normalizeAlertSuggestion($row);
        return $row;
    }

    private function alert(int $id, int $hotelId, string $type, string $level, string $title, string $message, string $date, ?string $actionSuggestion = null): array
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
            'raw_data' => [],
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

    private function cause(string $type, string $title, int $priority, float $confidence, string $evidence, string $suggestion): array
    {
        $detail = $this->causeDetail($type);
        return [
            'type' => $type,
            'title' => $title,
            'priority' => $priority,
            'confidence' => $confidence,
            'evidence' => $evidence,
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
                'impact' => '采集口径异常会导致漏斗和转化率失真，先不要直接做价格、库存或投放决策。',
                'check_points' => ['确认OTA配置是否绑定当前酒店', '检查Cookie或授权是否过期', '核对曝光、访客、订单字段映射和抓取日期'],
                'action_steps' => ['重新同步当天OTA数据', '对比OTA后台原始值与系统入库值', '修正字段映射后重新执行根因分析'],
            ],
            'traffic_down' => [
                'impact' => '曝光下降处在漏斗最前端，会直接压缩访客和订单上限，优先判断是排名、活动还是供给展示问题。',
                'check_points' => ['查看近7日曝光曲线和排名变化', '检查标题、首图、房型可售状态', '确认活动流量入口是否下线或预算不足'],
                'action_steps' => ['先恢复可售房型和基础曝光入口', '优化首图标题并补齐活动位', '次日复看曝光、访客和订单是否同步恢复'],
            ],
            'view_conversion_low' => [
                'impact' => '浏览转化低说明曝光能进来但详情页承接弱，常见原因是图片、卖点、价格展示或可售房型不匹配。',
                'check_points' => ['复核首图、房型图和核心卖点是否清晰', '对比同圈层竞品的价格与权益展示', '检查可售房型、早餐、取消政策等关键卖点'],
                'action_steps' => ['优先调整首图和房型展示顺序', '补充高频客群关注的卖点和权益', '观察浏览转化率是否在2到3天内回升'],
            ],
            'order_conversion_low' => [
                'impact' => '订单转化低说明访客已进入购买阶段但未下单，重点排查价格竞争力、库存限制和预订政策阻力。',
                'check_points' => ['对比本店ADR与竞对均价', '检查取消政策、连住限制和库存余量', '确认促销、会员价和渠道价是否正常生效'],
                'action_steps' => ['按房型做小幅跟价或权益补偿', '放开低风险库存和过严预订限制', '同步跟踪订单转化、ADR和RevPAR，避免只追单量'],
            ],
            'price_high' => [
                'impact' => '价格偏高会削弱访客下单意愿，但不能只看均价，需要结合房型、权益、评分和节假日窗口判断。',
                'check_points' => ['按房型对齐竞品价格和权益', '确认高价是否由节假日、库存紧张或高评分支撑', '检查是否存在单渠道异常高价'],
                'action_steps' => ['先处理明显高于竞品的房型', '用优惠权益替代直接降价时同步观察转化', '保留高需求日期的价格保护线'],
            ],
            'service_quality_low' => [
                'impact' => '服务质量或PSI偏低会削弱OTA流量承接和订单转化，尤其在价格没有优势时更容易放大流失。',
                'check_points' => ['查看服务质量分和PSI扣分项', '核对履约、房态、库存和接口异常是否集中出现', '对比低分日期的曝光、访客和订单转化变化'],
                'action_steps' => ['先处理可控的履约和房态问题', '把服务质量扣分项拆成门店任务并指定负责人', '次日复看服务质量、转化率和订单是否恢复'],
            ],
            'holiday_near' => [
                'impact' => '节假日临近会改变需求和价格弹性，库存、底价和活动节奏需要提前锁定。',
                'check_points' => ['确认节假日库存、底价和连住策略', '对比竞对节假日价格带', '检查活动、预售和高需求日调价是否已生效'],
                'action_steps' => ['先锁定高需求日底价和保留房量', '分阶段拉升价格并监控订单节奏', '节后复盘ADR、OCC和RevPAR表现'],
            ],
        ];

        return $details[$type] ?? [
            'impact' => '该根因会影响经营结果，需要结合经营、OTA、竞对和服务质量数据复核。',
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
            $value = $this->metricNumber($reportData[$key] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }

        $rooms = $this->sumReportFields($reportData, [
            'xb_rooms', 'mt_rooms', 'fliggy_rooms', 'dy_rooms', 'tc_rooms', 'qn_rooms', 'zx_rooms',
            'booking_rooms', 'agoda_rooms', 'expedia_rooms',
            'walkin_rooms', 'member_exp_rooms', 'web_exp_rooms', 'group_rooms', 'protocol_rooms', 'wechat_rooms',
            'free_rooms', 'gold_card_rooms', 'black_gold_rooms', 'hourly_rooms',
        ]);
        if ($rooms > 0) {
            return $rooms;
        }

        return $this->metricNumber($row['guest_count'] ?? 0);
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
        if ($type === 'holiday_strategy') {
            return '建议结合节假日库存和竞对价格分阶段执行';
        }
        return '建议先小范围执行，并持续跟踪订单、收入和转化变化';
    }
}
