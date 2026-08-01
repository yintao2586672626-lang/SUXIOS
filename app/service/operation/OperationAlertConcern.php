<?php
declare(strict_types=1);

namespace app\service\operation;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;
use Throwable;

trait OperationAlertConcern
{
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
