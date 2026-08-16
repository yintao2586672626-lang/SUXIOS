<?php
declare(strict_types=1);

namespace app\service\operation;

use app\service\SourceBackedExecutionIntentIdentityService;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;
use Throwable;

trait OperationAlertConcern
{
    public function alerts(array $hotelIds, ?int $hotelId, bool $canExecute = false): array
    {
        if ($hotelId === null || $hotelId <= 0) {
            throw new \InvalidArgumentException('operation alerts require one permitted hotel');
        }
        $hotelIds = $this->scopeHotelIdsForSelection($hotelIds, $hotelId);
        $persisted = $this->tableExists('operation_alerts');
        if (!$persisted) {
            return $this->operationAlertSchemaGapResponse(
                $this->operationAlertMigrationGap(
                    'operation_alerts_missing',
                    'operation alerts table is unavailable'
                ),
                $hotelId
            );
        }
        if (($gap = $this->operationAlertPersistenceSchemaGap()) !== null) {
            return $this->operationAlertSchemaGapResponse($gap, $hotelId);
        }

        try {
            $expectedTenantId = $this->operationAlertExpectedTenantId($hotelId);
            $generated = array_map(
                static function (array $alert) use ($expectedTenantId): array {
                    $alert['tenant_id'] = $expectedTenantId;
                    return $alert;
                },
                $this->generateRuleAlerts([$hotelId], $hotelId)
            );
            if ($generated !== []) {
                $this->persistRuleAlerts($generated);
            }
            $query = Db::name('operation_alerts')->where('hotel_id', $hotelId)->whereNull('deleted_at');
            $rows = $this->scopeOperationAlertQueryToCurrentTenant($query)
                ->order('id', 'desc')->limit(100)->select()->toArray();
        } catch (Throwable) {
            $gap = $this->operationAlertMigrationGap('operation_alerts_tenant_read_failed',
                'current-tenant operation alerts could not be read or safely persisted');
            return $this->operationAlertSchemaGapResponse($gap, $hotelId);
        }
        $alerts = array_map([$this, 'normalizeAlertRow'], $rows);
        return [
            'list' => $this->attachAlertExecutionBridges($alerts, $persisted, $canExecute),
            'unread_count' => count(array_filter($alerts, static fn(array $row): bool => ($row['status'] ?? '') !== 'read')),
            'data_status' => empty($alerts) ? '暂无预警' : self::DATA_OK, 'selected_hotel_id' => $hotelId,
            'generated_for_date' => $this->operationShanghaiToday(), 'scope' => 'single_hotel',
            'capabilities' => ['can_execute' => $canExecute, 'can_mark_read' => $canExecute],
        ];
    }

    public function markAlertsRead(array $ids, array $hotelIds): int
    {
        if (!$this->tableExists('operation_alerts')) {
            throw new \RuntimeException('migration_required: operation alerts table is unavailable');
        }
        if (($gap = $this->operationAlertTenantSchemaGap()) !== null) {
            throw new \RuntimeException($gap['message']);
        }
        $updatedAt = $this->operationShanghaiNow();
        return (int)$this->withOperationAlertMutationAuthorization($ids, $hotelIds,
            static function (array $alerts) use ($updatedAt): int {
                $authorizedIds = array_map(static fn(array $alert): int => (int)$alert['id'], $alerts);
                if ($authorizedIds === []) {
                    return 0;
                }
                return (int)Db::name('operation_alerts')->whereIn('id', $authorizedIds)->update([
                    'status' => 'read', 'updated_at' => $updatedAt,
                ]);
            });
    }

    public function createExecutionIntentFromAlert(int $alertId, array $hotelIds, int $createdBy): array
    {
        if ($alertId <= 0) {
            throw new \InvalidArgumentException('operation alert id is invalid');
        }
        if (!$this->tableExists('operation_alerts')) {
            throw new \RuntimeException('operation_alerts table does not exist, run database migration first');
        }

        return $this->withOperationAlertMutationAuthorization([$alertId], $hotelIds,
            function (array $rows) use ($alertId, $hotelIds, $createdBy): array {
                $row = $rows[0] ?? null;
                if (!is_array($row)) {
                    throw new \RuntimeException('operation alert not found in the current tenant scope');
                }
                $alert = $this->normalizeAlertRow($row);
                $unavailableReason = $this->alertExecutionEvidenceUnavailableReason($alert);
                if ($unavailableReason !== '') {
                    throw new \InvalidArgumentException($unavailableReason);
                }
                $hotelId = (int)$alert['hotel_id'];
                $intent = $this->createExecutionIntent(
                    $hotelIds, $hotelId, $this->buildAlertExecutionIntentInput($alert),
                    $createdBy, false, null, true
                );
                Db::name('operation_alerts')->where('id', $alertId)->update([
                    'status' => 'read', 'updated_at' => $this->operationShanghaiNow(),
                ]);
                $alert['status'] = 'read';
                $alert['task_bridge'] = $this->alertExecutionBridgeFromIntent($intent);
                return [
                    'alert' => $alert,
                    'execution_intent' => $intent,
                    'reused_existing_intent' => ($intent['idempotent_replay'] ?? false) === true,
                    'execution_policy' => 'pending_human_approval_no_automatic_ota_write',
                ];
            });
    }

    private function generateRuleAlerts(array $hotelIds, ?int $hotelId): array
    {
        $date = $this->operationShanghaiToday();
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
        if (($gap = $this->operationAlertPersistenceSchemaGap()) !== null) {
            throw new \RuntimeException($gap['message']);
        }
        if ($alerts === []) {
            return [];
        }

        return Db::transaction(function () use ($alerts): array {
            $hotelIds = array_values(array_unique(array_filter(array_map(
                static fn(array $alert): int => (int)($alert['hotel_id'] ?? 0),
                $alerts
            ))));
            sort($hotelIds);
            if ($hotelIds === []) {
                return [];
            }
            try {
                $hotels = Db::name('hotels')->whereIn('id', $hotelIds)
                    ->order('id', 'asc')->lock(true)->select()->toArray();
            } catch (Throwable $exception) {
                throw new \RuntimeException(
                    'migration_required: operation alert hotel tenant scope cannot be locked',
                    0,
                    $exception
                );
            }
            $tenantByHotel = [];
            foreach ($hotels as $hotel) {
                $lockedHotelId = (int)($hotel['id'] ?? 0);
                $lockedTenantId = (int)($hotel['tenant_id'] ?? 0);
                if ($lockedHotelId > 0 && $lockedTenantId > 0) {
                    $tenantByHotel[$lockedHotelId] = $lockedTenantId;
                }
            }
            if (count($tenantByHotel) !== count($hotelIds)) {
                throw new \RuntimeException('operation alert hotel tenant scope is incomplete');
            }

            $now = $this->operationShanghaiNow();
            $rows = [];
            foreach ($alerts as $alert) {
                $hotelId = (int)($alert['hotel_id'] ?? 0);
                $type = trim((string)($alert['alert_type'] ?? ''));
                if ($hotelId <= 0 || $type === '') {
                    continue;
                }
                $tenantId = (int)($tenantByHotel[$hotelId] ?? 0);
                $expectedTenantId = (int)($alert['tenant_id'] ?? $tenantId);
                if ($tenantId <= 0 || $expectedTenantId <= 0 || $expectedTenantId !== $tenantId) {
                    throw new \RuntimeException(
                        'operation alert expected tenant changed before persistence; retry with fresh evidence'
                    );
                }
                $source = trim((string)($alert['source'] ?? 'rule')) ?: 'rule';
                $date = $this->operationStrictShanghaiDate(
                    (string)($alert['related_date'] ?? $this->operationShanghaiToday()),
                    'operation alert related_date'
                )->format('Y-m-d');
                $rawData = is_array($alert['raw_data'] ?? null) ? $alert['raw_data'] : [];
                $actionSuggestion = $this->normalizeAlertSuggestion($alert);
                if ($actionSuggestion !== '') {
                    $rawData['action_suggestion'] = $actionSuggestion;
                }
                $payload = [
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'alert_type' => $type,
                    'monitor_dedupe_key' => hash('sha256', implode('|', [
                        $tenantId, $hotelId, $type, $source, $date,
                    ])),
                    'level' => (string)($alert['level'] ?? 'low'),
                    'title' => (string)($alert['title'] ?? ''),
                    'message' => (string)($alert['message'] ?? ''),
                    'source' => $source,
                    'related_date' => $date,
                    'raw_data' => json_encode($rawData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ];
                $existing = $this->findExactOperationAlertWinner($payload, true);
                if (is_array($existing)) {
                    if (!hash_equals(
                        SourceBackedExecutionIntentIdentityService::operationAlertSnapshotDigest($existing),
                        SourceBackedExecutionIntentIdentityService::operationAlertSnapshotDigest($payload)
                    )) {
                        $payload['status'] = 'unread';
                    }
                    $rows[] = $this->updateAndReadExactOperationAlertWinner($existing, $payload);
                    continue;
                }

                $insert = $payload;
                $insert['status'] = 'unread';
                $insert['created_at'] = $now;
                try {
                    Db::name('operation_alerts')->insertGetId($insert);
                } catch (Throwable $exception) {
                    if (!$this->operationAlertDuplicateKeyError($exception)) {
                        throw $exception;
                    }
                }
                $winner = $this->findExactOperationAlertWinner($payload, true);
                if (!is_array($winner)) {
                    throw new \RuntimeException('operation alert exact duplicate winner could not be read back');
                }
                $rows[] = $this->updateAndReadExactOperationAlertWinner($winner, $payload);
            }

            return array_values(array_map([$this, 'normalizeAlertRow'], $rows));
        });
    }

    private function afterData(array $row, ?\DateTimeInterface $now = null): array
    {
        $window = $this->operationActionEffectWindow($row, $now);
        $hotelIds = [(int)$row['hotel_id']];
        return $this->baseline($hotelIds, $window['days'], $window['as_of_date']);
    }

    private function evaluateActionResult(
        array $row,
        array $before,
        array $after,
        ?\DateTimeInterface $now = null
    ): array
    {
        $window = $this->operationActionEffectWindow($row, $now);
        $shanghaiToday = $this->operationShanghaiDateTime($now)->setTime(0, 0);
        $start = $this->operationStrictShanghaiDate($window['start_date'], 'operation action start_date');
        $elapsedDays = (int)$start->diff($shanghaiToday)->format('%r%a');
        if ($elapsedDays < 3) {
            return ['status' => 'observing', 'message' => '执行时间不足3天'];
        }
        $targetMetric = (string)($row['target_metric'] ?: 'avg_orders');
        $comparability = $this->assessComparableActionEffectEvidence($targetMetric, $before, $after);
        if (!$comparability['comparable']) {
            return [
                'status' => 'observing',
                'message' => $comparability['message'],
                'gap_code' => $comparability['gap_code'],
                'data_gaps' => [[
                    'code' => $comparability['gap_code'],
                    'message' => $comparability['message'],
                ]],
            ];
        }
        $metric = 'avg_' . $comparability['metric'];
        $beforeValue = (float)($before[$metric] ?? 0);
        $afterValue = (float)($after[$metric] ?? 0);
        if (($row['target_change_rate'] ?? null) === null) {
            return ['status' => 'observing', 'message' => '目标变化率尚未量化'];
        }
        $targetRate = (float)$row['target_change_rate'];
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

    /** @return array{start_date:string,end_date:string,as_of_date:string,days:int} */
    private function operationActionEffectWindow(array $row, ?\DateTimeInterface $now = null): array
    {
        $start = $this->operationStrictShanghaiDate(
            (string)($row['start_date'] ?? ''),
            'operation action start_date'
        );
        $rawEnd = trim((string)($row['end_date'] ?? ''));
        $configuredEnd = $rawEnd === ''
            ? $this->operationShanghaiDateTime($now)->setTime(0, 0)
            : $this->operationStrictShanghaiDate($rawEnd, 'operation action end_date');
        if ($configuredEnd < $start) {
            throw new \InvalidArgumentException('operation action end_date must not be before start_date');
        }
        $today = $this->operationShanghaiDateTime($now)->setTime(0, 0);
        $sevenDayEnd = $start->modify('+6 days');
        $end = min($configuredEnd, $today, $sevenDayEnd);
        if ($end < $start) {
            $end = $start;
        }

        return [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'as_of_date' => $end->modify('+1 day')->format('Y-m-d'),
            'days' => ((int)$start->diff($end)->format('%a')) + 1,
        ];
    }

    private function operationAlertPersistenceSchemaGap(): ?array
    {
        if (($gap = $this->operationAlertTenantSchemaGap()) !== null) {
            return $gap;
        }
        if (!$this->tableExists('operation_alerts')) {
            return $this->operationAlertMigrationGap(
                'operation_alerts_missing',
                'operation alerts table is unavailable'
            );
        }
        if (!$this->executionTenantSchemaHasColumn('operation_alerts', 'monitor_dedupe_key')) {
            return $this->operationAlertMigrationGap(
                'operation_alerts_dedupe_schema_missing',
                'operation alerts must expose monitor_dedupe_key with a unique index'
            );
        }
        if (!$this->operationAlertDedupeHasUniqueIndex()) {
            return $this->operationAlertMigrationGap(
                'operation_alerts_dedupe_unique_index_missing',
                'operation alerts monitor_dedupe_key must have a unique index'
            );
        }

        return null;
    }

    private function operationAlertDedupeHasUniqueIndex(): bool
    {
        try {
            $indexes = Db::query('PRAGMA index_list(`operation_alerts`)');
            foreach ($indexes as $index) {
                if ((int)($index['unique'] ?? 0) !== 1) {
                    continue;
                }
                $indexName = str_replace('`', '', (string)($index['name'] ?? ''));
                if ($indexName === '') {
                    continue;
                }
                $columns = array_map(
                    static fn(array $column): string => (string)($column['name'] ?? ''),
                    Db::query('PRAGMA index_info(`' . $indexName . '`)')
                );
                if ($columns === ['monitor_dedupe_key']) {
                    return true;
                }
            }
            return false;
        } catch (Throwable) {
            // The production database is MySQL; SQLite is used by focused tests.
        }

        try {
            return $this->operationAlertMySqlIndexRowsHaveExactDedupeUnique(
                Db::query('SHOW INDEX FROM `operation_alerts`')
            );
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function operationAlertMySqlIndexRowsHaveExactDedupeUnique(array $rows): bool
    {
        $indexes = [];
        foreach ($rows as $row) {
            $name = (string)($row['Key_name'] ?? $row['key_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $indexes[$name][] = [
                'column' => (string)($row['Column_name'] ?? $row['column_name'] ?? ''),
                'non_unique' => (int)($row['Non_unique'] ?? $row['non_unique'] ?? 1),
                'sequence' => (int)($row['Seq_in_index'] ?? $row['seq_in_index'] ?? 0),
                'prefix' => $row['Sub_part'] ?? $row['sub_part'] ?? null,
            ];
        }
        foreach ($indexes as $parts) {
            if (count($parts) !== 1) {
                continue;
            }
            $part = $parts[0];
            if ($part['non_unique'] === 0
                && $part['sequence'] === 1
                && $part['column'] === 'monitor_dedupe_key'
                && ($part['prefix'] === null || $part['prefix'] === '')
            ) {
                return true;
            }
        }

        return false;
    }

    private function operationAlertExpectedTenantId(int $hotelId): int
    {
        try {
            $tenantId = (int)(Db::name('hotels')->where('id', $hotelId)->value('tenant_id') ?? 0);
        } catch (Throwable $exception) {
            throw new \RuntimeException(
                'migration_required: operation alert hotel tenant scope cannot be read',
                0,
                $exception
            );
        }
        if ($tenantId <= 0) {
            throw new \RuntimeException('operation alert hotel has no authoritative tenant');
        }

        return $tenantId;
    }

    /** @param array<string,mixed> $identity */
    private function findExactOperationAlertWinner(array $identity, bool $lock = false): ?array
    {
        $query = Db::name('operation_alerts')
            ->where('monitor_dedupe_key', (string)$identity['monitor_dedupe_key'])
            ->where('tenant_id', (int)$identity['tenant_id'])
            ->where('hotel_id', (int)$identity['hotel_id'])
            ->where('alert_type', (string)$identity['alert_type'])
            ->where('source', (string)$identity['source'])
            ->where('related_date', (string)$identity['related_date'])
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $winner @param array<string,mixed> $payload */
    private function updateAndReadExactOperationAlertWinner(array $winner, array $payload): array
    {
        $id = (int)($winner['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('operation alert exact duplicate winner has no valid id');
        }
        Db::name('operation_alerts')
            ->where('id', $id)
            ->where('monitor_dedupe_key', (string)$payload['monitor_dedupe_key'])
            ->where('tenant_id', (int)$payload['tenant_id'])
            ->where('hotel_id', (int)$payload['hotel_id'])
            ->where('alert_type', (string)$payload['alert_type'])
            ->where('source', (string)$payload['source'])
            ->where('related_date', (string)$payload['related_date'])
            ->whereNull('deleted_at')
            ->update($payload);
        $readback = $this->findExactOperationAlertWinner($payload, true);
        if (!is_array($readback) || (int)($readback['id'] ?? 0) !== $id) {
            throw new \RuntimeException('operation alert exact duplicate winner readback changed');
        }

        return $readback;
    }

    private function operationAlertDuplicateKeyError(Throwable $exception): bool
    {
        $code = strtoupper(trim((string)$exception->getCode()));
        $message = strtolower($exception->getMessage());
        return in_array($code, ['23000', '23505'], true)
            || str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint');
    }

    private function operationShanghaiNow(?\DateTimeInterface $now = null): string
    {
        return $this->operationShanghaiDateTime($now)->format('Y-m-d H:i:s');
    }

    private function operationShanghaiDateTime(?\DateTimeInterface $now = null): DateTimeImmutable
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        return $now === null
            ? new DateTimeImmutable('now', $timezone)
            : DateTimeImmutable::createFromInterface($now)->setTimezone($timezone);
    }

    private function operationStrictShanghaiDate(string $value, string $field): DateTimeImmutable
    {
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Shanghai'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $parsed->format('Y-m-d') !== $value
        ) {
            throw new \InvalidArgumentException($field . ' must be a valid YYYY-MM-DD calendar date');
        }

        return $parsed;
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
            $alertIds = $alertHotelIds = $alertTenantIds = $eligibleAlertKeys = [];
            foreach ($alerts as $alert) {
                $alertId = (int)($alert['id'] ?? 0);
                $alertTenantId = (int)($alert['tenant_id'] ?? 0);
                $alertHotelId = (int)($alert['hotel_id'] ?? 0);
                if ($alertId <= 0 || $alertTenantId <= 0 || $alertHotelId <= 0) {
                    continue;
                }
                $alertIds[$alertId] = true;
                $alertTenantIds[$alertTenantId] = true;
                $alertHotelIds[$alertHotelId] = true;
                $eligibleAlertKeys[$alertTenantId . ':' . $alertHotelId . ':' . $alertId] = true;
            }
            if ($alertIds !== []) {
                try {
                    $rows = Db::name('operation_execution_intents')
                        ->where('source_module', 'operation_alert')->whereIn('source_record_id', array_keys($alertIds))
                        ->whereIn('tenant_id', array_keys($alertTenantIds))->whereIn('hotel_id', array_keys($alertHotelIds))
                        ->whereNull('deleted_at')
                        ->field('id,source_record_id,tenant_id,hotel_id,status,blocked_reason,evidence_json,created_at,updated_at')
                        ->order('id', 'desc')->select()->toArray();
                    foreach ($rows as $row) {
                        $sourceRecordId = (int)($row['source_record_id'] ?? 0);
                        $intentTenantId = (int)($row['tenant_id'] ?? 0);
                        $intentHotelId = (int)($row['hotel_id'] ?? 0);
                        $key = $intentTenantId . ':' . $intentHotelId . ':' . $sourceRecordId;
                        $matchingAlert = null;
                        foreach ($alerts as $candidateAlert) {
                            if ((int)($candidateAlert['id'] ?? 0) === $sourceRecordId
                                && (int)($candidateAlert['tenant_id'] ?? 0) === $intentTenantId
                                && (int)($candidateAlert['hotel_id'] ?? 0) === $intentHotelId
                            ) {
                                $matchingAlert = $candidateAlert;
                                break;
                            }
                        }
                        $evidence = json_decode((string)($row['evidence_json'] ?? ''), true);
                        $storedDigest = is_array($evidence)
                            ? strtolower(trim((string)($evidence['source_snapshot_digest'] ?? '')))
                            : '';
                        $currentDigest = is_array($matchingAlert)
                            ? SourceBackedExecutionIntentIdentityService::operationAlertSnapshotDigest($matchingAlert)
                            : '';
                        if (isset($eligibleAlertKeys[$key])
                            && !isset($intentByAlertKey[$key])
                            && preg_match('/^[a-f0-9]{64}$/D', $storedDigest) === 1
                            && hash_equals($storedDigest, $currentDigest)
                        ) {
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
            $alertTenantId = (int)($alert['tenant_id'] ?? 0);
            $alertHotelId = (int)($alert['hotel_id'] ?? 0);
            $intent = $intentByAlertKey[$alertTenantId . ':' . $alertHotelId . ':' . $alertId] ?? null;
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
        if (strtolower(trim((string)($rawData['execution_bridge_policy'] ?? ''))) === 'alert_only') {
            return '该信号只用于经营监控和停止/回滚提醒，不能直接转换为 OTA 执行任务';
        }
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
                'source_snapshot_digest' => SourceBackedExecutionIntentIdentityService::operationAlertSnapshotDigest($alert),
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
}
