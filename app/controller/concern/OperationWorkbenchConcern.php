<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\DailyWorkbenchPatrolService;
use app\service\OperationManagementService;
use app\service\Phase3OperationEffectLoopService;
use think\facade\Db;
use think\Response;
use think\exception\HttpException;

trait OperationWorkbenchConcern
{
    public function dailyWorkbench(): Response
    {
        $this->checkPermission();

        try {
            [, $endDate] = $this->resolveDashboardDateRange();
            $hotelId = $this->resolveDashboardHotelId(
                $this->request->get('hotel_id', $this->request->get('system_hotel_id', '')),
                true
            );
            $hotelId = (int)$hotelId;
            $this->requireOperationHotelCapability($hotelId, 'operation.view');

            return $this->success($this->buildDailyWorkbenchPayload($hotelId, $endDate));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->operationWorkbenchInternalError($e, 'daily_workbench_query', 'Daily workbench query failed');
        }
    }

    public function manualFetchEvidence(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $targetDate = $this->resolveDailyWorkbenchPatrolTargetDate(
                $this->request->get('target_date', date('Y-m-d', strtotime('-1 day')))
            );
            $hotels = $this->loadDashboardHotels(null);
            $hotelIds = array_values(array_filter(array_map(
                static fn(array $hotel): int => (int)($hotel['id'] ?? 0),
                array_values(array_filter($hotels, 'is_array'))
            ), static fn(int $hotelId): bool => $hotelId > 0));

            $rows = [];
            if ($hotelIds !== []) {
                $rows = Db::name('online_daily_data')
                    ->field('system_hotel_id,source,data_type,dimension,hotel_id,hotel_name,raw_data,data_period')
                    ->whereIn('system_hotel_id', $hotelIds)
                    ->where('data_date', $targetDate)
                    ->whereIn('source', array_values(array_unique(array_merge(
                        $this->collectionSourceAliases('ctrip'),
                        $this->collectionSourceAliases('meituan')
                    ))))
                    ->select()
                    ->toArray();
            }

            return $this->success([
                'target_date' => $targetDate,
                'rows' => $this->buildManualFetchEvidenceRows($hotels, $rows, $targetDate),
                'source_policy' => 'requested_target_date_online_daily_data_only',
                'raw_data_exposed' => false,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->operationWorkbenchInternalError($e, 'manual_fetch_evidence_query', 'Manual fetch evidence query failed');
        }
    }

    public function dailyWorkbenchPatrols(): Response
    {
        $this->checkPermission();

        try {
            $service = new DailyWorkbenchPatrolService();
            $limit = max(1, min(30, (int)$this->request->get('limit', 10)));
            $hotelId = (int)$this->resolveDashboardHotelId(
                $this->request->get('hotel_id', $this->request->get('system_hotel_id', '')),
                true
            );
            $this->requireOperationHotelCapability($hotelId, 'operation.view');
            $targetDate = $this->resolveDailyWorkbenchPatrolTargetDate(
                $this->request->get('target_date', $this->request->get('end_date', date('Y-m-d')))
            );

            return $this->success([
                'latest' => $service->latestForHotel($hotelId),
                'list' => $service->listForHotel($hotelId, $limit),
                'health' => $service->healthForHotel($hotelId, $targetDate),
                'scope' => [
                    'metric_scope' => 'ota_channel',
                    'hotel_id' => $hotelId,
                    'target_date' => $targetDate,
                    'source_policy' => 'read_existing_daily_workbench_patrol_snapshots_only',
                    'collection_logic_changed' => false,
                    'raw_data_exposed' => false,
                ],
            ]);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->operationWorkbenchInternalError($e, 'daily_workbench_patrol_list', 'Daily workbench patrol list failed');
        }
    }

    public function dailyWorkbenchPatrolReport(): Response
    {
        $this->checkPermission();

        try {
            $runId = trim((string)$this->request->get('run_id', ''));
            $hotelId = (int)$this->resolveDashboardHotelId(
                $this->request->get('hotel_id', $this->request->get('system_hotel_id', '')),
                true
            );
            $this->requireOperationHotelCapability($hotelId, 'operation.view');
            $report = (new DailyWorkbenchPatrolService())->markdownReportForHotel($hotelId, $runId);
            $snapshot = is_array($report['snapshot'] ?? null) ? $report['snapshot'] : [];
            $scope = is_array($snapshot['scope'] ?? null) ? $snapshot['scope'] : [];
            $fileName = (string)($report['filename'] ?? 'suxios_ota_daily_workbench_patrol.md');

            OperationLog::record(
                'online_data',
                'daily_workbench_patrol_report_export',
                'Export daily workbench patrol markdown report: ' . (string)($snapshot['run_id'] ?? ''),
                $this->currentUser->id ?? null,
                isset($scope['hotel_id']) && is_numeric($scope['hotel_id']) ? (int)$scope['hotel_id'] : null,
                null,
                [
                    'audit_type' => 'phase2_daily_workbench_report_export',
                    'run_id' => (string)($snapshot['run_id'] ?? ''),
                    'target_date' => (string)($scope['target_date'] ?? ''),
                    'metric_scope' => 'ota_channel',
                    'collection_logic_changed' => false,
                    'raw_data_exposed' => false,
                ]
            );

            return response((string)($report['content'] ?? ''), 200, [
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . rawurlencode($fileName) . '"',
                'X-SUXIOS-Metric-Scope' => 'ota_channel',
                'X-SUXIOS-Collection-Logic-Changed' => 'false',
                'X-SUXIOS-Raw-Data-Exposed' => 'false',
                'X-SUXIOS-Runtime-Snapshot-Written' => 'false',
                'X-SUXIOS-Operation-Log-Written' => 'true',
            ]);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            return $this->operationWorkbenchInternalError($e, 'daily_workbench_patrol_report', 'Daily workbench patrol report export failed');
        }
    }

    public function runDailyWorkbenchPatrol(): Response
    {
        $this->checkPermission();

        try {
            $data = $this->requestData();
            $targetDate = $this->resolveDailyWorkbenchPatrolTargetDate(
                $data['target_date'] ?? $data['end_date'] ?? $this->request->get('target_date', $this->request->get('end_date', date('Y-m-d')))
            );
            $hotelId = $this->resolveDashboardHotelId(
                $data['hotel_id'] ?? $data['system_hotel_id'] ?? $this->request->get('hotel_id', $this->request->get('system_hotel_id', '')),
                true
            );
            $hotelId = (int)$hotelId;
            $this->requireOperationHotelCapability($hotelId, 'operation.view');
            $limit = $this->resolveDailyWorkbenchPatrolLimit($data['limit'] ?? $this->request->get('limit', 10));
            $payload = $this->buildDailyWorkbenchPayload($hotelId, $targetDate, $limit);
            $snapshot = (new DailyWorkbenchPatrolService())->write($payload, [
                'trigger_type' => 'manual',
                'user_id' => (int)($this->currentUser->id ?? 0) ?: null,
                'target_date' => $targetDate,
            ]);

            OperationLog::record(
                'online_data',
                'daily_workbench_patrol',
                'Generate daily workbench patrol snapshot: ' . (string)$snapshot['run_id'],
                $this->currentUser->id ?? null,
                $hotelId,
                null,
                [
                    'audit_type' => 'phase2_daily_workbench_patrol',
                    'run_id' => (string)$snapshot['run_id'],
                    'target_date' => $targetDate,
                    'metric_scope' => 'ota_channel',
                    'collection_logic_changed' => false,
                    'raw_data_exposed' => false,
                ]
            );

            return $this->success([
                'snapshot' => $snapshot,
                'latest' => $snapshot,
                'health' => (new DailyWorkbenchPatrolService())->healthForHotel($hotelId, $targetDate),
                'write_effects' => [
                    'runtime_snapshot_written' => true,
                    'latest_index_written' => true,
                    'operation_log_written' => true,
                    'ota_collection_triggered' => false,
                    'business_table_written' => false,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->operationWorkbenchInternalError($e, 'daily_workbench_patrol_snapshot', 'Daily workbench patrol snapshot failed');
        }
    }

    public function updateDailyWorkbenchPatrolAction(): Response
    {
        $this->checkPermission();

        try {
            $data = $this->requestData();
            $userId = (int)($this->currentUser->id ?? 0);
            $hotelId = (int)$this->resolveDashboardHotelId(
                $data['hotel_id'] ?? $data['system_hotel_id'] ?? '',
                true
            );
            $this->requireOperationHotelCapability($hotelId, 'operation.execute');
            $data['hotel_id'] = $hotelId;
            $patrolService = new DailyWorkbenchPatrolService();
            $sourceSnapshot = $patrolService->findByRunIdForHotel((string)($data['run_id'] ?? ''), $hotelId);
            if ($sourceSnapshot === null) {
                throw new \RuntimeException('Daily workbench patrol snapshot not found.');
            }

            $actionContext = $this->dailyWorkbenchPatrolActionContext($sourceSnapshot, $data);
            $operationSync = (new OperationManagementService())->syncDailyWorkbenchPatrolAction(
                [$hotelId],
                array_merge($actionContext, $data),
                $userId
            );
            $data['operation_execution'] = $operationSync;

            $snapshot = $patrolService->updateActionStatusForHotel(
                $data,
                $hotelId,
                $userId ?: null
            );

            OperationLog::record(
                'online_data',
                'daily_workbench_patrol_action_update',
                'Update daily workbench patrol action: ' . (string)($data['status'] ?? ''),
                $this->currentUser->id ?? null,
                $hotelId > 0 ? $hotelId : null,
                null,
                [
                    'audit_type' => 'phase2_daily_workbench_action_tracking',
                    'run_id' => (string)($data['run_id'] ?? ''),
                    'hotel_id' => $hotelId,
                    'action_code' => (string)($data['action_code'] ?? ''),
                    'question_key' => (string)($data['question_key'] ?? ''),
                    'status' => (string)($data['status'] ?? ''),
                    'operation_execution' => [
                        'intent_id' => (int)($operationSync['intent_id'] ?? 0),
                        'task_id' => (int)($operationSync['task_id'] ?? 0),
                        'task_status' => (string)($operationSync['task_status'] ?? ''),
                    ],
                    'metric_scope' => 'ota_channel',
                    'collection_logic_changed' => false,
                    'raw_data_exposed' => false,
                ]
            );

            return $this->success([
                'snapshot' => $snapshot,
                'latest' => $snapshot,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            return $this->operationWorkbenchInternalError($e, 'daily_workbench_patrol_action_update', 'Daily workbench patrol action update failed');
        }
    }

    public function reviewDailyWorkbenchPatrolAction(): Response
    {
        $this->checkPermission();

        try {
            $data = $this->requestData();
            $userId = (int)($this->currentUser->id ?? 0);
            $hotelId = (int)$this->resolveDashboardHotelId(
                $data['hotel_id'] ?? $data['system_hotel_id'] ?? '',
                true
            );
            $this->requireOperationHotelCapability($hotelId, 'operation.execute');
            $data['hotel_id'] = $hotelId;
            $patrolService = new DailyWorkbenchPatrolService();
            $sourceSnapshot = $patrolService->findByRunIdForHotel((string)($data['run_id'] ?? ''), $hotelId);
            if ($sourceSnapshot === null) {
                throw new \RuntimeException('Daily workbench patrol snapshot not found.');
            }

            $this->dailyWorkbenchPatrolActionContext($sourceSnapshot, $data);
            $actionCode = trim((string)($data['action_code'] ?? ''));
            $questionKey = trim((string)($data['question_key'] ?? ''));
            $trackingKey = $this->dailyWorkbenchPatrolTrackingKey($hotelId, $actionCode, $questionKey);
            $tracked = $sourceSnapshot['action_tracking']['items'][$trackingKey] ?? null;
            if (!is_array($tracked)) {
                throw new \RuntimeException('Daily workbench patrol action must be marked before review.');
            }

            $operationExecution = is_array($tracked['operation_execution'] ?? null) ? $tracked['operation_execution'] : [];
            $taskId = $this->dailyWorkbenchPatrolReviewTaskId($operationExecution, $data);

            $reviewedTask = (new OperationManagementService())->reviewExecutionTask($taskId, [$hotelId], [
                'result_status' => (string)($data['result_status'] ?? $data['review_status'] ?? ''),
                'result_summary' => (string)($data['result_summary'] ?? $data['review_summary'] ?? ''),
            ], $userId);

            $operationExecution = array_replace($operationExecution, [
                'task_id' => $taskId,
                'task_status' => (string)($reviewedTask['status'] ?? ''),
                'review_status' => (string)($reviewedTask['result_status'] ?? ''),
                'review_summary' => (string)($reviewedTask['result_summary'] ?? ''),
                'reviewed_at' => date('Y-m-d H:i:s'),
                'source_policy' => 'daily_workbench_patrol_to_operation_execution_review',
            ]);

            $reviewInput = array_merge($data, [
                'operation_execution' => $operationExecution,
                'result_status' => (string)($reviewedTask['result_status'] ?? ''),
                'result_summary' => (string)($reviewedTask['result_summary'] ?? ''),
            ]);
            $snapshot = $patrolService->updateActionReviewForHotel($reviewInput, $hotelId, $userId ?: null);

            OperationLog::record(
                'online_data',
                'daily_workbench_patrol_action_review',
                'Review daily workbench patrol action: ' . (string)($reviewedTask['result_status'] ?? ''),
                $this->currentUser->id ?? null,
                $hotelId > 0 ? $hotelId : null,
                null,
                [
                    'audit_type' => 'phase2_daily_workbench_action_review',
                    'run_id' => (string)($data['run_id'] ?? ''),
                    'hotel_id' => $hotelId,
                    'action_code' => $actionCode,
                    'question_key' => $questionKey,
                    'task_id' => $taskId,
                    'result_status' => (string)($reviewedTask['result_status'] ?? ''),
                    'metric_scope' => 'ota_channel',
                    'collection_logic_changed' => false,
                    'raw_data_exposed' => false,
                ]
            );

            return $this->success([
                'snapshot' => $snapshot,
                'latest' => $snapshot,
                'review' => [
                    'task_id' => $taskId,
                    'result_status' => (string)($reviewedTask['result_status'] ?? ''),
                    'result_summary' => (string)($reviewedTask['result_summary'] ?? ''),
                    'metric_scope' => 'ota_channel',
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() === 422 ? 422 : 404);
        } catch (\Throwable $e) {
            return $this->operationWorkbenchInternalError($e, 'daily_workbench_patrol_action_review', 'Daily workbench patrol action review failed');
        }
    }

    public function phase3OperationEffectLoop(): Response
    {
        $this->checkPermission();

        try {
            $hotelId = (int)$this->resolveDashboardHotelId(
                $this->request->get('hotel_id', $this->request->get('system_hotel_id', '')),
                true
            );
            $this->requireOperationHotelCapability($hotelId, 'operation.view');
            $payload = (new Phase3OperationEffectLoopService())->build([
                'run_id' => (string)$this->request->get('run_id', ''),
                'target_date' => (string)$this->request->get('target_date', ''),
                'limit' => $this->request->get('limit', 100),
                'scope_hotel_id' => $hotelId,
            ]);

            return $this->success($payload);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            return $this->operationWorkbenchInternalError($e, 'phase3_operation_effect_loop', 'Phase 3 operation effect loop failed');
        }
    }

    public function phase3OperationEffectLoopLedger(): Response
    {
        $this->checkPermission();

        try {
            $hotelId = (int)$this->resolveDashboardHotelId(
                $this->request->get('hotel_id', $this->request->get('system_hotel_id', '')),
                true
            );
            $this->requireOperationHotelCapability($hotelId, 'operation.view');
            $limit = max(1, min(200, (int)$this->request->get('limit', 50)));
            return $this->success((new Phase3OperationEffectLoopService())->ledgerForHotel($hotelId, $limit));
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->operationWorkbenchInternalError($e, 'phase3_operation_effect_loop_ledger', 'Phase 3 operation effect loop ledger failed');
        }
    }

    public function publishPhase3OperationSop(): Response
    {
        $this->checkPermission();

        try {
            $data = $this->requestData();
            $hotelId = (int)$this->resolveDashboardHotelId(
                $data['hotel_id'] ?? $data['system_hotel_id'] ?? '',
                true
            );
            $this->requireOperationHotelCapability($hotelId, 'operation.execute');
            $data['hotel_id'] = $hotelId;
            $data['scope_hotel_id'] = $hotelId;
            $result = (new Phase3OperationEffectLoopService())->publishSop($data, (int)($this->currentUser->id ?? 0) ?: null);
            $sop = is_array($result['sop'] ?? null) ? $result['sop'] : [];
            OperationLog::record(
                'online_data',
                'phase3_operation_sop_publish',
                'Publish phase3 operation SOP: ' . (string)($sop['id'] ?? ''),
                $this->currentUser->id ?? null,
                isset($sop['hotel_id']) && is_numeric($sop['hotel_id']) ? (int)$sop['hotel_id'] : null,
                null,
                [
                    'audit_type' => 'phase3_operation_effect_loop_sop',
                    'sop_id' => (string)($sop['id'] ?? ''),
                    'source_run_id' => (string)($sop['source_run_id'] ?? ''),
                    'metric_scope' => 'ota_channel',
                    'collection_logic_changed' => false,
                    'raw_data_exposed' => false,
                ]
            );

            return $this->success($result, 'SOP已沉淀');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            return $this->operationWorkbenchInternalError($e, 'phase3_operation_sop_publish', 'Phase 3 operation SOP publish failed');
        }
    }

    public function createPhase3ReplicationPlan(): Response
    {
        $this->checkPermission();

        try {
            $data = $this->requestData();
            $hotelId = (int)$this->resolveDashboardHotelId(
                $data['hotel_id'] ?? $data['system_hotel_id'] ?? '',
                true
            );
            $this->requireOperationHotelCapability($hotelId, 'operation.execute');
            $data['hotel_id'] = $hotelId;
            $data['scope_hotel_id'] = $hotelId;
            $result = (new Phase3OperationEffectLoopService())->createReplicationPlan($data, (int)($this->currentUser->id ?? 0) ?: null);
            $plan = is_array($result['replication_plan'] ?? null) ? $result['replication_plan'] : [];
            OperationLog::record(
                'online_data',
                'phase3_replication_plan_create',
                'Create phase3 replication plan: ' . (string)($plan['id'] ?? ''),
                $this->currentUser->id ?? null,
                isset($plan['source_hotel_id']) && is_numeric($plan['source_hotel_id']) ? (int)$plan['source_hotel_id'] : null,
                null,
                [
                    'audit_type' => 'phase3_operation_effect_loop_replication',
                    'plan_id' => (string)($plan['id'] ?? ''),
                    'source_run_id' => (string)($plan['source_run_id'] ?? ''),
                    'target_hotel_count' => count((array)($plan['target_hotels'] ?? [])),
                    'metric_scope' => 'ota_channel',
                    'collection_logic_changed' => false,
                    'raw_data_exposed' => false,
                ]
            );

            return $this->success($result, '复制计划已生成');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            return $this->operationWorkbenchInternalError($e, 'phase3_replication_plan_create', 'Phase 3 replication plan create failed');
        }
    }

    private function operationWorkbenchInternalError(
        \Throwable $exception,
        string $event,
        string $message
    ): Response {
        \think\facade\Log::error('Operation workbench request failed', [
            'event' => $event,
            'exception_type' => get_debug_type($exception),
        ]);

        return $this->error($message, 500, [
            'reason' => $event . '_failed',
        ]);
    }

    private function dailyWorkbenchPatrolActionContext(array $snapshot, array $data): array
    {
        $hotelId = isset($data['hotel_id']) && is_numeric($data['hotel_id']) ? (int)$data['hotel_id'] : 0;
        $actionCode = trim((string)($data['action_code'] ?? ''));
        $questionKey = trim((string)($data['question_key'] ?? ''));
        if ($hotelId <= 0 || ($actionCode === '' && $questionKey === '')) {
            throw new \InvalidArgumentException('hotel_id and action identity are required.');
        }

        $scope = is_array($snapshot['scope'] ?? null) ? $snapshot['scope'] : [];
        $targetDate = trim((string)($scope['target_date'] ?? $data['target_date'] ?? date('Y-m-d')));
        $matches = static function (array $action) use ($actionCode, $questionKey): bool {
            $candidateActionCode = trim((string)($action['action_code'] ?? ''));
            $candidateQuestionKey = trim((string)($action['question_key'] ?? ''));

            return ($actionCode !== '' && $candidateActionCode === $actionCode)
                || ($questionKey !== '' && $candidateQuestionKey === $questionKey);
        };
        $buildContext = static function (array $action, array $row = []) use ($targetDate, $actionCode, $questionKey): array {
            $dataGaps = [];
            foreach ([
                $action['data_gaps'] ?? [],
                $action['blocking_missing_codes'] ?? [],
                $row['metric_diagnosis']['data_gap_codes'] ?? [],
                $row['ai_evidence']['blocking_missing_codes'] ?? [],
                $row['operation_execution']['blocking_missing_codes'] ?? [],
            ] as $group) {
                foreach ((array)$group as $value) {
                    $value = trim((string)$value);
                    if ($value !== '') {
                        $dataGaps[] = $value;
                    }
                }
            }
            if ($dataGaps === [] && $questionKey !== '') {
                $dataGaps[] = $questionKey;
            }
            if ($dataGaps === [] && $actionCode !== '') {
                $dataGaps[] = $actionCode;
            }

            return [
                'target_date' => trim((string)($action['target_date'] ?? $row['target_date'] ?? $targetDate)),
                'platform' => trim((string)($action['platform'] ?? 'ota')),
                'priority' => trim((string)($action['priority'] ?? 'medium')),
                'action_text' => trim((string)($action['action'] ?? $action['reason'] ?? '')),
                'entry' => trim((string)($action['entry'] ?? '')),
                'data_gaps' => array_values(array_unique($dataGaps)),
            ];
        };

        foreach ((array)($snapshot['rows'] ?? []) as $row) {
            if (!is_array($row) || (int)($row['hotel_id'] ?? 0) !== $hotelId) {
                continue;
            }
            $action = is_array($row['next_action'] ?? null) ? $row['next_action'] : [];
            if ($action !== [] && $matches($action)) {
                return $buildContext($action, $row);
            }
        }

        foreach ((array)($snapshot['next_actions'] ?? []) as $action) {
            if (!is_array($action) || (int)($action['hotel_id'] ?? 0) !== $hotelId) {
                continue;
            }
            if ($matches($action)) {
                return $buildContext($action);
            }
        }

        throw new \InvalidArgumentException('Daily workbench patrol action is not in this snapshot; regenerate the patrol snapshot before updating status.');
    }

    private function dailyWorkbenchPatrolTrackingKey(int $hotelId, string $actionCode, string $questionKey): string
    {
        $identity = $actionCode !== '' ? $actionCode : $questionKey;
        return $hotelId . '|' . preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', $identity);
    }

    private function dailyWorkbenchPatrolReviewTaskId(array $operationExecution, array $data): int
    {
        $runtimeTaskId = isset($operationExecution['task_id']) && is_numeric($operationExecution['task_id'])
            ? (int)$operationExecution['task_id']
            : 0;
        $hasRequestedTaskId = array_key_exists('task_id', $data)
            && $data['task_id'] !== null
            && trim((string)$data['task_id']) !== '';
        $requestedTaskId = 0;
        if ($hasRequestedTaskId) {
            if (!is_numeric($data['task_id']) || (int)$data['task_id'] <= 0) {
                throw new \RuntimeException('Daily workbench patrol review task identity is invalid.', 422);
            }
            $requestedTaskId = (int)$data['task_id'];
        }
        if ($runtimeTaskId > 0 && $requestedTaskId > 0 && $runtimeTaskId !== $requestedTaskId) {
            throw new \RuntimeException('Daily workbench patrol review task identity conflicts with the runtime snapshot.', 422);
        }

        $taskId = $runtimeTaskId > 0 ? $runtimeTaskId : $requestedTaskId;
        if ($taskId <= 0) {
            throw new \RuntimeException('Daily workbench patrol action has no execution task to review.');
        }

        return $taskId;
    }

    private function requireOperationHotelCapability(int $hotelId, string $capability): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        if ($hotelId <= 0 || !$this->currentUser->hasHotelPermission($hotelId, $capability)) {
            abort(403, $capability === 'operation.execute' ? '无权限执行该门店运营操作' : '无权限查看该门店运营闭环');
        }
    }

    public function dailyWorkbenchPatrolCron(): Response
    {
        try {
            $rateLimited = $this->checkPublicEndpointRateLimit('daily_workbench_patrol_cron', 10, 60);
        } catch (\Throwable $exception) {
            return $this->publicEndpointRateLimiterUnavailableResponse(
                'daily_workbench_patrol_cron',
                $exception
            );
        }
        if ($rateLimited !== null) {
            $this->recordPublicEndpointFailure('daily_workbench_patrol_cron', 'rate_limited', 429, $rateLimited);
            return json(['code' => 429, 'message' => 'Too Many Requests'], 429);
        }

        $token = trim((string)$this->request->header('X-Cron-Token', ''));
        $configToken = trim((string)\think\facade\Env::get('CRON_TOKEN', ''));
        if ($configToken === '') {
            $this->recordPublicEndpointFailure('daily_workbench_patrol_cron', 'cron_token_not_configured', 403);
            return json(['code' => 403, 'message' => 'CRON_TOKEN not configured'], 403);
        }

        if ($token === '' || !hash_equals($configToken, $token)) {
            $this->recordPublicEndpointFailure('daily_workbench_patrol_cron', 'invalid_cron_token', 401);
            return json(['code' => 401, 'message' => 'Unauthorized'], 401);
        }

        try {
            $targetDate = $this->resolveDailyWorkbenchPatrolTargetDate(
                $this->request->get('target_date', $this->request->get('end_date', date('Y-m-d')))
            );
            $limit = $this->resolveDailyWorkbenchPatrolLimit($this->request->get('limit', 30));
            $hotelId = $this->resolveDailyWorkbenchPatrolHotelId($this->request->get('hotel_id', ''));
            $hotelQuery = Db::name('hotels')
                ->field('id,tenant_id')
                ->where('status', \app\model\Hotel::STATUS_ENABLED)
                ->order('id', 'asc')
                ->limit($limit);
            if ($hotelId !== null) {
                $hotelQuery->where('id', $hotelId);
            }
            $hotelScopes = $hotelQuery
                ->select()
                ->toArray();
            $patrolService = new DailyWorkbenchPatrolService();
            $snapshots = [];
            $healthByHotel = [];
            foreach ($hotelScopes as $hotelScope) {
                $hotelId = (int)($hotelScope['id'] ?? 0);
                $tenantId = (int)($hotelScope['tenant_id'] ?? 0);
                if ($hotelId <= 0 || $tenantId <= 0) {
                    throw new \RuntimeException('Daily workbench patrol hotel tenant scope is invalid.');
                }

                \app\model\Hotel::runInTenantScope($tenantId, function () use (
                    $hotelId,
                    $tenantId,
                    $targetDate,
                    $patrolService,
                    &$snapshots,
                    &$healthByHotel
                ): void {
                    $payload = $this->buildDailyWorkbenchPayload($hotelId, $targetDate, 1);
                    $hotelPayloads = $this->splitDailyWorkbenchPatrolPayloadsByHotel($payload);
                    if (count($hotelPayloads) !== 1) {
                        throw new \RuntimeException('Daily workbench patrol hotel scope produced no snapshot.');
                    }
                    $hotelPayload = $hotelPayloads[0];
                    $snapshot = $patrolService->write($hotelPayload, [
                        'trigger_type' => 'cron',
                        'target_date' => $targetDate,
                        'hotel_id' => $hotelId,
                    ]);
                    $snapshots[] = [
                        'run_id' => (string)$snapshot['run_id'],
                        'created_at' => (string)$snapshot['created_at'],
                        'target_date' => $targetDate,
                        'tenant_id' => $tenantId,
                        'hotel_id' => $hotelId,
                        'summary' => $snapshot['summary'] ?? [],
                        'evidence_policy' => $snapshot['evidence_policy'] ?? [],
                    ];
                    $healthByHotel[] = [
                        'tenant_id' => $tenantId,
                        'hotel_id' => $hotelId,
                        'health' => $patrolService->healthForHotel($hotelId, $targetDate),
                    ];

                    OperationLog::record(
                        'online_data',
                        'daily_workbench_patrol_cron',
                        'Cron generated hotel-scoped daily workbench patrol snapshot: ' . (string)$snapshot['run_id'],
                        null,
                        $hotelId,
                        null,
                        [
                            'tenant_id' => $tenantId,
                            'audit_type' => 'phase2_daily_workbench_patrol',
                            'run_id' => (string)$snapshot['run_id'],
                            'target_date' => $targetDate,
                            'hotel_id' => $hotelId,
                            'metric_scope' => 'ota_channel',
                            'collection_logic_changed' => false,
                            'raw_data_exposed' => false,
                        ]
                    );
                });
            }

            $firstSnapshot = $snapshots[0] ?? null;
            $firstHealth = is_array($healthByHotel[0]['health'] ?? null)
                ? $healthByHotel[0]['health']
                : [
                    'status' => 'missing',
                    'target_date' => $targetDate,
                    'message' => 'No accessible enabled hotels were available for automatic patrol.',
                    'metric_scope' => 'ota_channel',
                ];

            return json([
                'code' => 200,
                'message' => 'ok',
                'time' => date('Y-m-d H:i:s'),
                'data' => [
                    'snapshot' => $firstSnapshot,
                    'snapshots' => $snapshots,
                    'snapshot_count' => count($snapshots),
                    'health' => $firstHealth,
                    'health_by_hotel' => $healthByHotel,
                ],
            ]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('Daily workbench patrol cron failed', [
                'exception_type' => get_debug_type($e),
            ]);
            $this->recordPublicEndpointFailure(
                'daily_workbench_patrol_cron',
                'execution_failed',
                500,
                ['exception_type' => get_debug_type($e)]
            );
            return json([
                'code' => 500,
                'message' => 'Daily workbench patrol cron failed',
                'data' => ['reason' => 'daily_workbench_patrol_cron_failed'],
            ], 500);
        }
    }

    private function splitDailyWorkbenchPatrolPayloadsByHotel(array $payload): array
    {
        $scope = is_array($payload['scope'] ?? null) ? $payload['scope'] : [];
        $scopedPayloads = [];
        foreach ((array)($payload['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hotelId = (int)($row['hotel_id'] ?? 0);
            if ($hotelId <= 0) {
                continue;
            }
            $rows = [$row];
            $scopedPayloads[$hotelId] = [
                'scope' => array_replace($scope, [
                    'hotel_id' => $hotelId,
                    'requested_hotel_limit' => 1,
                    'returned_hotel_count' => 1,
                ]),
                'summary' => $this->buildDailyWorkbenchSummary($rows),
                'rows' => $rows,
                'next_actions' => $this->buildDailyWorkbenchNextActions($rows),
                'data_status' => [
                    'status' => 'ready',
                    'empty_reason' => null,
                    'raw_data_exposed' => false,
                ],
            ];
        }

        return array_values($scopedPayloads);
    }

    private function buildDailyWorkbenchPayload(?int $hotelId, string $targetDate, ?int $limitOverride = null): array
    {
        $limit = $limitOverride !== null ? max(1, min(30, $limitOverride)) : $this->dailyWorkbenchLimit($hotelId);
        $hotels = $this->loadDashboardHotels($hotelId);
        if ($hotelId === null) {
            $hotels = array_slice($hotels, 0, $limit);
        }

        $rows = [];
        foreach ($hotels as $hotel) {
            if (!is_array($hotel)) {
                continue;
            }
            $currentHotelId = (int)($hotel['id'] ?? 0);
            if ($currentHotelId <= 0) {
                continue;
            }

            try {
                $reliability = $this->withPhase1EmployeeQuestions(
                    $this->buildCollectionReliabilityPayload($currentHotelId, $targetDate, $targetDate)
                );
                $rows[] = $this->buildDailyWorkbenchRow($hotel, $reliability, $targetDate);
            } catch (\Throwable $e) {
                $rows[] = $this->buildDailyWorkbenchErrorRow($hotel, $targetDate, $e);
            }
        }

        return [
            'scope' => [
                'metric_scope' => 'ota_channel',
                'target_date' => $targetDate,
                'hotel_id' => $hotelId,
                'requested_hotel_limit' => $limit,
                'returned_hotel_count' => count($rows),
                'source_policy' => 'read_existing_collection_reliability_only',
                'protected_boundary' => 'Do not change Ctrip/Meituan manual or automatic acquisition logic, fields, mappings, or storage.',
            ],
            'summary' => $this->buildDailyWorkbenchSummary($rows),
            'rows' => $rows,
            'next_actions' => $this->buildDailyWorkbenchNextActions($rows),
            'data_status' => [
                'status' => $rows === [] ? 'empty' : 'ready',
                'empty_reason' => $rows === [] ? 'no_accessible_enabled_hotels_or_no_matching_hotel' : null,
                'raw_data_exposed' => false,
            ],
        ];
    }

    /**
     * Build the narrow read-only evidence used by the manual supplement queue.
     * Ctrip success is proved only by distinct competition-circle hotels for the
     * requested date; unrelated traffic/profile rows must never inflate it.
     *
     * @param array<int, array<string, mixed>> $hotels
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildManualFetchEvidenceRows(array $hotels, array $rows, string $targetDate): array
    {
        $rowsByHotel = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hotelId = (int)($row['system_hotel_id'] ?? 0);
            if ($hotelId <= 0) {
                continue;
            }
            $rowsByHotel[$hotelId][] = $row;
        }

        $result = [];
        foreach ($hotels as $hotel) {
            if (!is_array($hotel)) {
                continue;
            }
            $hotelId = (int)($hotel['id'] ?? 0);
            if ($hotelId <= 0) {
                continue;
            }

            $ctripCompetitionRows = [];
            $meituanRows = [];
            foreach ($rowsByHotel[$hotelId] ?? [] as $row) {
                $source = strtolower(trim((string)($row['source'] ?? '')));
                $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
                $dimension = strtolower(trim((string)($row['dimension'] ?? '')));
                $dataPeriod = strtolower(trim((string)($row['data_period'] ?? '')));
                if (in_array($source, $this->collectionSourceAliases('ctrip'), true)
                    && $dataType === 'competitor'
                    && $dimension === 'competition_circle_hotel') {
                    $ctripCompetitionRows[] = $row;
                    continue;
                }
                if (in_array($source, $this->collectionSourceAliases('meituan'), true)
                    && $dataType !== 'traffic_forecast'
                    && !str_contains($dataPeriod, 'forecast')) {
                    $meituanRows[] = $row;
                }
            }

            $competitionSummary = $this->summarizeCollectionCompetitionCircleRows($ctripCompetitionRows);
            $ctripCount = (int)$competitionSummary['target_date_competition_hotel_count'];
            $meituanCount = count($meituanRows);
            $sourceRows = $ctripCount + $meituanCount;
            $result[] = [
                'hotelId' => (string)$hotelId,
                'hotelName' => trim((string)($hotel['name'] ?? '')),
                'targetDate' => $targetDate,
                'sourceRows' => $sourceRows,
                'fieldHasGap' => false,
                'acquisitionStatusKind' => $sourceRows > 0 ? 'ready' : 'missing',
                'platformRows' => [[
                    'platform' => 'ctrip',
                    'target_date_rows' => $ctripCount,
                    ...$competitionSummary,
                ], [
                    'platform' => 'meituan',
                    'target_date_rows' => $meituanCount,
                    'target_date_competition_hotel_count' => 0,
                    'target_date_competition_self_count' => 0,
                    'target_date_competition_competitor_count' => 0,
                ]],
            ];
        }

        return $result;
    }

    private function dailyWorkbenchLimit(?int $hotelId): int
    {
        if ($hotelId !== null) {
            return 1;
        }

        $rawLimit = (int)$this->request->get('limit', 10);
        return max(1, min(30, $rawLimit > 0 ? $rawLimit : 10));
    }

    private function resolveDailyWorkbenchPatrolTargetDate($value): string
    {
        $targetDate = trim((string)$value);
        if ($targetDate === '') {
            $targetDate = date('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) || strtotime($targetDate) === false) {
            throw new \InvalidArgumentException('Daily workbench patrol target_date must use YYYY-MM-DD.');
        }

        return $targetDate;
    }

    private function resolveDailyWorkbenchPatrolLimit($value): int
    {
        $limit = is_numeric($value) ? (int)$value : 10;
        return max(1, min(30, $limit > 0 ? $limit : 10));
    }

    private function resolveDailyWorkbenchPatrolHotelId($value): ?int
    {
        $hotelId = trim((string)$value);
        if ($hotelId === '') {
            return null;
        }
        if (!ctype_digit($hotelId) || (int)$hotelId <= 0) {
            throw new \InvalidArgumentException('Daily workbench patrol hotel_id must be a positive integer.');
        }

        return (int)$hotelId;
    }

    private function buildDailyWorkbenchRow(array $hotel, array $reliability, string $targetDate): array
    {
        $employee = is_array($reliability['phase1_employee_questions'] ?? null)
            ? $reliability['phase1_employee_questions']
            : [];
        $questions = array_values(array_filter((array)($employee['rows'] ?? []), static fn($row): bool => is_array($row)));
        $questionsByKey = $this->dailyWorkbenchQuestionsByKey($questions);
        $closure = is_array($employee['closure_summary'] ?? null)
            ? $employee['closure_summary']
            : (is_array($employee['summary'] ?? null) ? $employee['summary'] : []);
        $actions = array_values(array_filter((array)($employee['next_required_actions'] ?? []), static fn($row): bool => is_array($row)));
        $topAction = $this->dailyWorkbenchTopAction($actions);
        $collectionSourceSummary = array_values(array_filter(
            (array)($employee['collection_source_summary'] ?? $reliability['collection_source_summary'] ?? []),
            static fn($row): bool => is_array($row)
        ));
        $revenueEvidence = is_array($employee['revenue_metric_evidence'] ?? null)
            ? $employee['revenue_metric_evidence']
            : [];
        $operationEvidence = is_array($employee['operation_execution_evidence'] ?? null)
            ? $employee['operation_execution_evidence']
            : [];
        $aiEvidence = is_array($questionsByKey['ai_evidence']['evidence'] ?? null)
            ? $questionsByKey['ai_evidence']['evidence']
            : [];
        $aiQuestionStatus = $this->dailyWorkbenchQuestionStatus($questionsByKey, 'ai_evidence');
        $aiExplanation = $this->dailyWorkbenchAiExplanation($aiEvidence, $aiQuestionStatus);
        $workflowChain = $this->dailyWorkbenchWorkflowChain(
            $questionsByKey,
            $collectionSourceSummary,
            $revenueEvidence,
            $aiEvidence,
            $operationEvidence,
            $actions
        );

        return [
            'hotel_id' => (int)($hotel['id'] ?? 0),
            'hotel_name' => (string)($hotel['name'] ?? ('Hotel ID ' . (int)($hotel['id'] ?? 0))),
            'target_date' => $targetDate,
            'metric_scope' => 'ota_channel',
            'status' => (string)($closure['status'] ?? 'unknown'),
            'employee_questions' => [
                'count' => count($questions),
                'proved_count' => (int)($closure['proved_count'] ?? 0),
                'missing_count' => (int)($closure['missing_count'] ?? 0),
                'missing_question_keys' => array_values(array_filter(array_map('strval', (array)($closure['missing_question_keys'] ?? [])))),
                'statuses' => [
                    'today_ota_collected' => $this->dailyWorkbenchQuestionStatus($questionsByKey, 'today_ota_collected'),
                    'trusted_fields' => $this->dailyWorkbenchQuestionStatus($questionsByKey, 'trusted_fields'),
                    'missing_fields' => $this->dailyWorkbenchQuestionStatus($questionsByKey, 'missing_fields'),
                    'revenue_traffic_conversion' => $this->dailyWorkbenchQuestionStatus($questionsByKey, 'revenue_traffic_conversion'),
                    'ai_evidence' => $this->dailyWorkbenchQuestionStatus($questionsByKey, 'ai_evidence'),
                    'next_operation_action' => $this->dailyWorkbenchQuestionStatus($questionsByKey, 'next_operation_action'),
                ],
            ],
            'collection' => [
                'status' => $this->dailyWorkbenchQuestionStatus($questionsByKey, 'today_ota_collected'),
                'platforms' => $this->dailyWorkbenchPlatformRows($collectionSourceSummary),
                'target_date_source_rows' => $this->dailyWorkbenchTargetDateRows($collectionSourceSummary),
                'source_policy' => 'read_existing_online_daily_data_only',
            ],
            'field_trust' => [
                'status' => $this->dailyWorkbenchQuestionStatus($questionsByKey, 'trusted_fields'),
                'missing_status' => $this->dailyWorkbenchQuestionStatus($questionsByKey, 'missing_fields'),
                'data_quality_status' => (string)($reliability['data_quality']['status'] ?? 'unknown'),
                'missing_field_count' => (int)($reliability['data_quality']['missing_count'] ?? 0),
            ],
            'metric_diagnosis' => [
                'status' => $this->dailyWorkbenchQuestionStatus($questionsByKey, 'revenue_traffic_conversion'),
                'revenue_metric_status' => (string)($revenueEvidence['status'] ?? 'unknown'),
                'metric_trust_key_count' => count((array)($revenueEvidence['metric_trust_keys'] ?? [])),
                'data_gap_codes' => array_values(array_filter(array_map('strval', (array)($revenueEvidence['data_gap_codes'] ?? [])))),
                'traffic_rows' => (int)($revenueEvidence['traffic_rows'] ?? 0),
                'source_policy' => (string)($revenueEvidence['source_policy'] ?? 'read_existing_ota_standard_revenue_metrics_only'),
            ],
            'ai_evidence' => [
                'status' => $aiQuestionStatus,
                'diagnosis_status' => (string)($aiEvidence['diagnosis_status'] ?? 'unknown'),
                'action_item_status' => (string)($aiEvidence['action_item_status'] ?? 'unknown'),
                'blocking_missing_codes' => array_values(array_filter(array_map('strval', (array)($aiEvidence['blocking_missing_codes'] ?? [])))),
                'source_policy' => (string)($aiEvidence['source_policy'] ?? 'missing_real_ota_diagnosis_response'),
                'explanation' => $aiExplanation,
            ],
            'operation_execution' => [
                'status' => $this->dailyWorkbenchQuestionStatus($questionsByKey, 'next_operation_action'),
                'operation_evidence_status' => (string)($operationEvidence['operation_evidence_status'] ?? 'unknown'),
                'execution_intent_count' => (int)($operationEvidence['execution_intent_count'] ?? 0),
                'execution_flow_item_count' => (int)($operationEvidence['execution_flow_item_count'] ?? 0),
                'completion_signal_count' => (int)($operationEvidence['completion_signal_count'] ?? 0),
                'blocking_missing_codes' => array_values(array_filter(array_map('strval', (array)($operationEvidence['blocking_missing_codes'] ?? [])))),
                'source_policy' => (string)($operationEvidence['source_policy'] ?? 'read_existing_operation_execution_state_only'),
            ],
            'workflow_chain' => $workflowChain,
            'next_action' => $topAction,
            'next_action_count' => count($actions),
            'high_priority_action_count' => count(array_filter($actions, static fn(array $row): bool => (string)($row['priority'] ?? '') === 'high')),
            'source_policy' => 'read_existing_phase1_employee_question_rows_only',
            'protected_boundary' => 'Do not change OTA acquisition; this row summarizes existing evidence only.',
        ];
    }

    private function buildDailyWorkbenchErrorRow(array $hotel, string $targetDate, \Throwable $e): array
    {
        $hotelId = (int)($hotel['id'] ?? 0);
        return [
            'hotel_id' => $hotelId,
            'hotel_name' => (string)($hotel['name'] ?? ('Hotel ID ' . $hotelId)),
            'target_date' => $targetDate,
            'metric_scope' => 'ota_channel',
            'status' => 'request_failed',
            'employee_questions' => [
                'count' => 0,
                'proved_count' => 0,
                'missing_count' => 6,
                'missing_question_keys' => [
                    'today_ota_collected',
                    'trusted_fields',
                    'missing_fields',
                    'revenue_traffic_conversion',
                    'ai_evidence',
                    'next_operation_action',
                ],
                'statuses' => [
                    'today_ota_collected' => 'request_failed',
                    'trusted_fields' => 'request_failed',
                    'missing_fields' => 'request_failed',
                    'revenue_traffic_conversion' => 'request_failed',
                    'ai_evidence' => 'request_failed',
                    'next_operation_action' => 'request_failed',
                ],
            ],
            'collection' => [
                'status' => 'request_failed',
                'platforms' => [],
                'target_date_source_rows' => 0,
                'source_policy' => 'read_existing_online_daily_data_only',
            ],
            'field_trust' => ['status' => 'request_failed', 'missing_status' => 'request_failed'],
            'metric_diagnosis' => ['status' => 'request_failed'],
            'ai_evidence' => [
                'status' => 'request_failed',
                'explanation' => [
                    'summary' => 'AI suggestion evidence is unavailable because the daily workbench row query failed.',
                    'diagnosis_status' => 'request_failed',
                    'action_item_status' => 'request_failed',
                    'missing_codes' => ['workbench_row_query_failed'],
                    'source_policy' => 'read_existing_phase1_employee_question_rows_only',
                    'next_step' => 'Inspect the row query failure and rerun the daily workbench.',
                    'boundary' => 'AI suggestions must cite OTA evidence and data gaps; missing evidence cannot be converted into a confirmed business conclusion.',
                ],
            ],
            'operation_execution' => ['status' => 'request_failed'],
            'workflow_chain' => $this->dailyWorkbenchErrorWorkflowChain(),
            'next_action' => [
                'action_code' => 'phase2_workbench_row_query_failed',
                'priority' => 'high',
                'status' => 'request_failed',
                'action' => 'Inspect the collection reliability query for this hotel and rerun the workbench.',
                'entry' => '/api/online-data/collection-reliability',
                'error_type' => $e::class,
            ],
            'next_action_count' => 1,
            'high_priority_action_count' => 1,
            'source_policy' => 'read_existing_collection_reliability_only',
            'protected_boundary' => 'Failure is exposed as request_failed; no fallback success is generated.',
        ];
    }

    private function dailyWorkbenchWorkflowChain(
        array $questionsByKey,
        array $collectionSourceSummary,
        array $revenueEvidence,
        array $aiEvidence,
        array $operationEvidence,
        array $actions
    ): array {
        $collectionEvidence = $this->dailyWorkbenchQuestionEvidence($questionsByKey, 'today_ota_collected');
        $fieldEvidence = $this->dailyWorkbenchQuestionEvidence($questionsByKey, 'trusted_fields');
        $missingEvidence = $this->dailyWorkbenchQuestionEvidence($questionsByKey, 'missing_fields');
        $metricEvidence = $this->dailyWorkbenchQuestionEvidence($questionsByKey, 'revenue_traffic_conversion');
        $operationQuestionEvidence = $this->dailyWorkbenchQuestionEvidence($questionsByKey, 'next_operation_action');

        return [
            $this->dailyWorkbenchWorkflowStage(
                'today_ota_data',
                '携程/美团今日数据',
                'today_ota_collected',
                $this->dailyWorkbenchQuestionStatus($questionsByKey, 'today_ota_collected'),
                [
                    'target_date_source_rows' => $this->dailyWorkbenchTargetDateRows($collectionSourceSummary),
                    'platform_count' => count($collectionSourceSummary),
                    'missing_platforms' => array_values(array_filter(array_map('strval', (array)($collectionEvidence['missing_platforms'] ?? [])))),
                    'storage_table' => 'online_daily_data',
                    'source_policy' => 'read_existing_online_daily_data_only',
                ],
                $this->dailyWorkbenchQuestionBlockingCodes($questionsByKey, 'today_ota_collected'),
                $actions
            ),
            $this->dailyWorkbenchWorkflowStage(
                'field_trust_and_gaps',
                '字段可信/缺失',
                'trusted_fields',
                $this->dailyWorkbenchFieldWorkflowStatus(
                    $this->dailyWorkbenchQuestionStatus($questionsByKey, 'trusted_fields'),
                    $this->dailyWorkbenchQuestionStatus($questionsByKey, 'missing_fields')
                ),
                [
                    'field_trust_status' => $this->dailyWorkbenchQuestionStatus($questionsByKey, 'trusted_fields'),
                    'missing_field_status' => $this->dailyWorkbenchQuestionStatus($questionsByKey, 'missing_fields'),
                    'field_definition_count' => (int)($fieldEvidence['field_definition_count'] ?? 0),
                    'metric_trust_key_count' => (int)($fieldEvidence['metric_trust_key_count'] ?? 0),
                    'missing_field_codes' => array_values(array_filter(array_map('strval', (array)($missingEvidence['missing_field_codes'] ?? [])))),
                    'data_gap_codes' => array_values(array_filter(array_map('strval', (array)($missingEvidence['data_gap_codes'] ?? [])))),
                    'source_policy' => 'read_existing_field_definitions_and_data_quality_only',
                ],
                array_values(array_unique(array_merge(
                    $this->dailyWorkbenchQuestionBlockingCodes($questionsByKey, 'trusted_fields'),
                    $this->dailyWorkbenchQuestionBlockingCodes($questionsByKey, 'missing_fields')
                ))),
                $actions
            ),
            $this->dailyWorkbenchWorkflowStage(
                'revenue_metrics',
                '收益指标',
                'revenue_traffic_conversion',
                $this->dailyWorkbenchQuestionStatus($questionsByKey, 'revenue_traffic_conversion'),
                [
                    'revenue_metric_status' => (string)($revenueEvidence['status'] ?? 'unknown'),
                    'metric_trust_key_count' => count((array)($revenueEvidence['metric_trust_keys'] ?? [])),
                    'traffic_rows' => (int)($revenueEvidence['traffic_rows'] ?? 0),
                    'data_gap_codes' => array_values(array_unique(array_filter(array_map('strval', array_merge(
                        (array)($metricEvidence['metric_domain_gap_codes'] ?? []),
                        (array)($revenueEvidence['data_gap_codes'] ?? [])
                    ))))),
                    'source_policy' => (string)($revenueEvidence['source_policy'] ?? 'read_existing_ota_standard_revenue_metrics_only'),
                ],
                $this->dailyWorkbenchQuestionBlockingCodes($questionsByKey, 'revenue_traffic_conversion'),
                $actions
            ),
            $this->dailyWorkbenchWorkflowStage(
                'ai_diagnosis',
                'AI诊断',
                'ai_evidence',
                $this->dailyWorkbenchQuestionStatus($questionsByKey, 'ai_evidence'),
                [
                    'diagnosis_status' => (string)($aiEvidence['diagnosis_status'] ?? 'unknown'),
                    'action_item_status' => (string)($aiEvidence['action_item_status'] ?? 'unknown'),
                    'source_policy' => (string)($aiEvidence['source_policy'] ?? 'missing_real_ota_diagnosis_response'),
                ],
                $this->dailyWorkbenchQuestionBlockingCodes($questionsByKey, 'ai_evidence'),
                $actions
            ),
            $this->dailyWorkbenchWorkflowStage(
                'operation_action',
                '执行动作',
                'next_operation_action',
                $this->dailyWorkbenchQuestionStatus($questionsByKey, 'next_operation_action'),
                [
                    'operation_evidence_status' => (string)($operationEvidence['operation_evidence_status'] ?? 'unknown'),
                    'execution_intent_count' => (int)($operationEvidence['execution_intent_count'] ?? 0),
                    'execution_flow_item_count' => (int)($operationEvidence['execution_flow_item_count'] ?? 0),
                    'completion_signal_count' => (int)($operationEvidence['completion_signal_count'] ?? 0),
                    'source_policy' => (string)($operationEvidence['source_policy'] ?? 'read_existing_operation_execution_state_only'),
                ],
                array_values(array_unique(array_merge(
                    $this->dailyWorkbenchQuestionBlockingCodes($questionsByKey, 'next_operation_action'),
                    array_values(array_filter(array_map('strval', (array)($operationQuestionEvidence['operation_blocking_missing_codes'] ?? []))))
                ))),
                $actions
            ),
        ];
    }

    private function dailyWorkbenchWorkflowStage(
        string $key,
        string $label,
        string $questionKey,
        string $status,
        array $evidence,
        array $blockingCodes,
        array $actions
    ): array {
        $action = $this->dailyWorkbenchWorkflowAction($actions, $questionKey);
        return [
            'key' => $key,
            'label' => $label,
            'question_key' => $questionKey,
            'status' => $status,
            'evidence' => $evidence,
            'blocking_gap_codes' => array_values(array_unique(array_filter(array_map('strval', $blockingCodes)))),
            'next_action' => $action,
            'source_policy' => (string)($evidence['source_policy'] ?? 'read_existing_phase1_employee_question_rows_only'),
            'protected_boundary' => 'Read-only workflow decomposition; it does not trigger Ctrip or Meituan collection and does not convert missing evidence into success.',
        ];
    }

    private function dailyWorkbenchQuestionEvidence(array $questionsByKey, string $key): array
    {
        return is_array($questionsByKey[$key]['evidence'] ?? null) ? $questionsByKey[$key]['evidence'] : [];
    }

    private function dailyWorkbenchQuestionBlockingCodes(array $questionsByKey, string $key): array
    {
        if (!isset($questionsByKey[$key]) || !is_array($questionsByKey[$key])) {
            return [];
        }
        $row = $questionsByKey[$key];
        $evidence = is_array($row['evidence'] ?? null) ? $row['evidence'] : [];
        return array_values(array_unique(array_filter(array_map('strval', array_merge(
            (array)($row['blocking_gap_codes'] ?? []),
            (array)($evidence['blocking_missing_codes'] ?? []),
            (array)($evidence['blocking_gap_codes'] ?? [])
        )))));
    }

    private function dailyWorkbenchFieldWorkflowStatus(string $fieldTrustStatus, string $missingFieldStatus): string
    {
        if ($fieldTrustStatus === 'proved' && $missingFieldStatus === 'proved') {
            return 'proved';
        }
        if ($fieldTrustStatus === 'request_failed' || $missingFieldStatus === 'request_failed') {
            return 'request_failed';
        }
        if ($fieldTrustStatus !== 'proved') {
            return $fieldTrustStatus;
        }
        return $missingFieldStatus !== '' ? $missingFieldStatus : 'unknown';
    }

    private function dailyWorkbenchWorkflowAction(array $actions, string $questionKey): ?array
    {
        foreach ($actions as $action) {
            if (!is_array($action) || (string)($action['question_key'] ?? '') !== $questionKey) {
                continue;
            }
            return $this->dailyWorkbenchCompactAction($action);
        }
        return null;
    }

    private function dailyWorkbenchErrorWorkflowChain(): array
    {
        $stages = [
            ['today_ota_data', '携程/美团今日数据', 'today_ota_collected'],
            ['field_trust_and_gaps', '字段可信/缺失', 'trusted_fields'],
            ['revenue_metrics', '收益指标', 'revenue_traffic_conversion'],
            ['ai_diagnosis', 'AI诊断', 'ai_evidence'],
            ['operation_action', '执行动作', 'next_operation_action'],
        ];

        return array_map(static function (array $stage): array {
            return [
                'key' => $stage[0],
                'label' => $stage[1],
                'question_key' => $stage[2],
                'status' => 'request_failed',
                'evidence' => ['source_policy' => 'read_existing_collection_reliability_only'],
                'blocking_gap_codes' => ['workbench_row_query_failed'],
                'next_action' => null,
                'source_policy' => 'read_existing_collection_reliability_only',
                'protected_boundary' => 'Failure is exposed as request_failed; no fallback success is generated.',
            ];
        }, $stages);
    }

    private function dailyWorkbenchQuestionsByKey(array $questions): array
    {
        $byKey = [];
        foreach ($questions as $question) {
            $key = trim((string)($question['key'] ?? ''));
            if ($key !== '') {
                $byKey[$key] = $question;
            }
        }
        return $byKey;
    }

    private function dailyWorkbenchQuestionStatus(array $questionsByKey, string $key): string
    {
        if (!isset($questionsByKey[$key]) || !is_array($questionsByKey[$key])) {
            return 'missing_question';
        }
        $status = trim((string)($questionsByKey[$key]['status'] ?? ''));
        return $status !== '' ? $status : 'unknown';
    }

    private function dailyWorkbenchAiExplanation(array $aiEvidence, string $questionStatus): array
    {
        $blockingCodes = array_values(array_filter(array_map('strval', (array)($aiEvidence['blocking_missing_codes'] ?? []))));
        $diagnosisStatus = (string)($aiEvidence['diagnosis_status'] ?? 'unknown');
        $actionItemStatus = (string)($aiEvidence['action_item_status'] ?? 'unknown');
        $sourcePolicy = (string)($aiEvidence['source_policy'] ?? 'missing_real_ota_diagnosis_response');

        if ($questionStatus === 'proved' && $blockingCodes === []) {
            $summary = 'AI suggestion is backed by OTA evidence, data-gap review, and an action item.';
            $nextStep = 'Track execution and review the result.';
        } elseif ($blockingCodes !== []) {
            $summary = 'AI suggestion is blocked because verified OTA evidence is incomplete.';
            $nextStep = 'Resolve missing evidence before treating the suggestion as executable.';
        } else {
            $summary = 'AI suggestion evidence is not proved yet.';
            $nextStep = 'Review AI evidence sources, data gaps, and action-item status.';
        }

        return [
            'summary' => $summary,
            'diagnosis_status' => $diagnosisStatus,
            'action_item_status' => $actionItemStatus,
            'missing_codes' => $blockingCodes,
            'source_policy' => $sourcePolicy,
            'next_step' => $nextStep,
            'boundary' => 'AI suggestions must cite OTA evidence and data gaps; missing evidence cannot be converted into a confirmed business conclusion.',
        ];
    }

    private function dailyWorkbenchTopAction(array $actions): ?array
    {
        $fallback = null;
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $compact = $this->dailyWorkbenchCompactAction($action);
            $fallback ??= $compact;
            if ((string)($action['status'] ?? '') !== 'blocked') {
                return $compact;
            }
        }
        return $fallback;
    }

    private function dailyWorkbenchCompactAction(array $action): array
    {
        return [
            'action_code' => (string)($action['action_code'] ?? ''),
            'type' => (string)($action['type'] ?? ''),
            'priority' => (string)($action['priority'] ?? ''),
            'status' => (string)($action['status'] ?? ''),
            'platform' => (string)($action['platform'] ?? ''),
            'question_key' => (string)($action['question_key'] ?? ''),
            'reason' => (string)($action['reason'] ?? ''),
            'action' => (string)($action['action'] ?? ''),
            'entry' => (string)($action['entry'] ?? ''),
            'owner' => (string)($action['owner'] ?? ''),
            'protected_boundary' => (string)($action['protected_boundary'] ?? ''),
        ];
    }

    private function dailyWorkbenchPlatformRows(array $collectionSourceSummary): array
    {
        return array_values(array_map(static function (array $row): array {
            return [
                'platform' => (string)($row['platform'] ?? ''),
                'target_date' => (string)($row['target_date'] ?? ''),
                'storage_table' => (string)($row['storage_table'] ?? 'online_daily_data'),
                'target_date_rows' => max(0, (int)($row['target_date_rows'] ?? 0)),
                'target_date_data_types' => array_values(array_filter(array_map('strval', (array)($row['target_date_data_types'] ?? [])))),
                'target_date_competition_hotel_count' => max(0, (int)($row['target_date_competition_hotel_count'] ?? 0)),
                'target_date_competition_self_count' => max(0, (int)($row['target_date_competition_self_count'] ?? 0)),
                'target_date_competition_competitor_count' => max(0, (int)($row['target_date_competition_competitor_count'] ?? 0)),
                'evidence_status' => (string)($row['evidence_status'] ?? 'unknown'),
                'latest_available_reference_only' => (bool)($row['latest_available_reference_only'] ?? true),
                'source_policy' => (string)($row['source_policy'] ?? 'read_existing_online_daily_data_only'),
                'collection_logic_changed' => (bool)($row['collection_logic_changed'] ?? false),
            ];
        }, $collectionSourceSummary));
    }

    private function dailyWorkbenchTargetDateRows(array $collectionSourceSummary): int
    {
        return array_reduce($collectionSourceSummary, static function (int $sum, array $row): int {
            return $sum + max(0, (int)($row['target_date_rows'] ?? 0));
        }, 0);
    }

    private function buildDailyWorkbenchSummary(array $rows): array
    {
        $total = count($rows);
        $complete = count(array_filter($rows, static fn(array $row): bool => (string)($row['status'] ?? '') === 'complete'));
        $requestFailed = count(array_filter($rows, static fn(array $row): bool => (string)($row['status'] ?? '') === 'request_failed'));
        $highPriorityActionCount = array_reduce($rows, static function (int $sum, array $row): int {
            return $sum + max(0, (int)($row['high_priority_action_count'] ?? 0));
        }, 0);
        $nextActionCount = array_reduce($rows, static function (int $sum, array $row): int {
            return $sum + max(0, (int)($row['next_action_count'] ?? 0));
        }, 0);
        $sourceRows = array_reduce($rows, static function (int $sum, array $row): int {
            return $sum + max(0, (int)($row['collection']['target_date_source_rows'] ?? 0));
        }, 0);

        return [
            'status' => $total === 0 ? 'empty' : ($complete === $total ? 'complete' : 'incomplete'),
            'metric_scope' => 'ota_channel',
            'total_hotels' => $total,
            'complete_hotels' => $complete,
            'incomplete_hotels' => max(0, $total - $complete - $requestFailed),
            'request_failed_hotels' => $requestFailed,
            'action_required_hotels' => count(array_filter($rows, static fn(array $row): bool => (int)($row['next_action_count'] ?? 0) > 0)),
            'next_action_count' => $nextActionCount,
            'high_priority_action_count' => $highPriorityActionCount,
            'ai_evidence_missing_hotels' => count(array_filter($rows, static fn(array $row): bool => !in_array((string)($row['ai_evidence']['status'] ?? ''), ['proved'], true))),
            'operation_incomplete_hotels' => count(array_filter($rows, static fn(array $row): bool => !in_array((string)($row['operation_execution']['status'] ?? ''), ['proved'], true))),
            'target_date_source_rows' => $sourceRows,
            'source_policy' => 'read_existing_collection_reliability_only',
        ];
    }

    private function buildDailyWorkbenchNextActions(array $rows): array
    {
        $actions = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_array($row['next_action'] ?? null)) {
                continue;
            }
            $actions[] = array_merge([
                'hotel_id' => (int)($row['hotel_id'] ?? 0),
                'hotel_name' => (string)($row['hotel_name'] ?? ''),
                'target_date' => (string)($row['target_date'] ?? ''),
            ], $row['next_action']);
        }

        usort($actions, static function (array $left, array $right): int {
            $priorityRank = ['high' => 0, 'medium' => 1, 'low' => 2];
            $leftRank = $priorityRank[(string)($left['priority'] ?? '')] ?? 9;
            $rightRank = $priorityRank[(string)($right['priority'] ?? '')] ?? 9;
            return $leftRank <=> $rightRank;
        });

        return $actions;
    }

}
