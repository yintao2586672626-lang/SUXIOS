<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Env;

final class DailyWorkbenchPatrolService
{
    private const SNAPSHOT_DIR = 'phase2_daily_workbench_patrol';
    private const LATEST_FILE = 'latest.json';

    public function write(array $workbenchPayload, array $context = []): array
    {
        $snapshot = $this->buildSnapshot($workbenchPayload, $context);
        $scopeHotelId = $this->snapshotHotelId($snapshot);
        if ($scopeHotelId > 0 && !$this->snapshotWithinHotelScope($snapshot, $scopeHotelId)) {
            throw new \InvalidArgumentException('Daily workbench patrol payload crosses the selected hotel scope.');
        }
        $targetDate = $snapshot['scope']['target_date'];
        $dir = $this->targetDateDir($targetDate);
        $this->ensureDirectory($dir);

        $fileName = $snapshot['run_id'] . '.json';
        $path = $dir . DIRECTORY_SEPARATOR . $fileName;
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Daily workbench patrol snapshot write failed.');
        }

        $latestPath = $this->baseDir() . DIRECTORY_SEPARATOR . self::LATEST_FILE;
        $latest = $snapshot;
        $latest['storage'] = [
            'path' => $this->relativePath($path),
            'latest_path' => $this->relativePath($latestPath),
            'retention_policy' => 'runtime_json_snapshot_only',
        ];
        $latestJson = json_encode($latest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($latestJson === false || file_put_contents($latestPath, $latestJson . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Daily workbench latest patrol index write failed.');
        }

        return $latest;
    }

    public function latest(): ?array
    {
        $path = $this->baseDir() . DIRECTORY_SEPARATOR . self::LATEST_FILE;
        if (!is_file($path)) {
            return null;
        }

        return $this->readSnapshot($path);
    }

    public function latestForHotel(int $hotelId): ?array
    {
        $hotelId = $this->requireHotelId($hotelId);
        foreach ($this->snapshotFiles() as $path) {
            $snapshot = $this->readSnapshot($path);
            if ($snapshot !== null && $this->snapshotWithinHotelScope($snapshot, $hotelId)) {
                return $snapshot;
            }
        }

        return null;
    }

    public function findByRunId(string $runId): ?array
    {
        $runId = $this->safeRunId($runId);
        if ($runId === '') {
            return null;
        }

        $path = $this->findSnapshotPath($runId);
        return $path === null ? null : $this->readSnapshot($path);
    }

    public function findByRunIdForHotel(string $runId, int $hotelId): ?array
    {
        $snapshot = $this->findByRunId($runId);
        return $snapshot !== null && $this->snapshotWithinHotelScope($snapshot, $this->requireHotelId($hotelId))
            ? $snapshot
            : null;
    }

    public function list(int $limit = 10): array
    {
        $limit = max(1, min(30, $limit));
        $files = $this->snapshotFiles();

        $rows = [];
        foreach (array_slice($files, 0, $limit) as $path) {
            $snapshot = $this->readSnapshot($path);
            if ($snapshot === null) {
                continue;
            }
            $rows[] = $this->compactSnapshot($snapshot);
        }

        return $rows;
    }

    public function listForHotel(int $hotelId, int $limit = 10): array
    {
        $hotelId = $this->requireHotelId($hotelId);
        $limit = max(1, min(30, $limit));
        $rows = [];
        foreach ($this->snapshotFiles() as $path) {
            $snapshot = $this->readSnapshot($path);
            if ($snapshot === null || !$this->snapshotWithinHotelScope($snapshot, $hotelId)) {
                continue;
            }
            $rows[] = $this->compactSnapshot($snapshot);
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    public function health(?string $targetDate = null): array
    {
        $targetDate = $this->normalizeDate((string)($targetDate ?? date('Y-m-d')));
        return $this->buildHealth($targetDate, $this->latest());
    }

    public function healthForHotel(int $hotelId, ?string $targetDate = null): array
    {
        $targetDate = $this->normalizeDate((string)($targetDate ?? date('Y-m-d')));
        return $this->buildHealth($targetDate, $this->latestForHotel($hotelId));
    }

    private function buildHealth(string $targetDate, ?array $latest): array
    {
        $checkedAt = date('Y-m-d H:i:s');
        $automation = $this->automationHealth();
        if ($latest === null) {
            return [
                'status' => 'missing',
                'target_date' => $targetDate,
                'latest_run_id' => '',
                'latest_target_date' => '',
                'latest_created_at' => '',
                'latest_trigger_type' => '',
                'is_target_date_ready' => false,
                'is_auto_patrol' => false,
                'next_action' => 'run_patrol_now',
                'message' => 'No daily workbench patrol snapshot exists for the target date.',
                'checked_at' => $checkedAt,
                'metric_scope' => 'ota_channel',
                'source_policy' => 'read_existing_daily_workbench_patrol_snapshots_only',
                'collection_logic_changed' => false,
                'raw_data_exposed' => false,
                'automation' => $automation,
                'automation_configured' => (bool)($automation['cron_token_configured'] ?? false),
                'automation_next_action' => (string)($automation['next_action'] ?? 'configure_cron_token'),
            ];
        }

        $scope = is_array($latest['scope'] ?? null) ? $latest['scope'] : [];
        $latestTargetDate = $this->normalizeDate((string)($scope['target_date'] ?? ''));
        $latestTrigger = (string)($latest['trigger_type'] ?? '');
        $isTargetDateReady = $latestTargetDate === $targetDate;
        $isAutoPatrol = $latestTrigger === 'cron';
        $status = 'stale';
        $nextAction = 'run_patrol_now';
        $message = 'Latest patrol snapshot is not for the target date.';
        if ($isTargetDateReady && $isAutoPatrol) {
            $status = 'auto_ready';
            $nextAction = 'review_actions';
            $message = 'Target date automatic patrol snapshot is ready.';
        } elseif ($isTargetDateReady) {
            $status = 'manual_ready';
            $nextAction = 'review_actions';
            $message = 'Target date patrol snapshot exists, but it was generated manually.';
        }

        return [
            'status' => $status,
            'target_date' => $targetDate,
            'latest_run_id' => (string)($latest['run_id'] ?? ''),
            'latest_target_date' => $latestTargetDate,
            'latest_created_at' => (string)($latest['created_at'] ?? ''),
            'latest_trigger_type' => $latestTrigger,
            'is_target_date_ready' => $isTargetDateReady,
            'is_auto_patrol' => $isAutoPatrol,
            'next_action' => $nextAction,
            'message' => $message,
            'checked_at' => $checkedAt,
            'metric_scope' => 'ota_channel',
            'source_policy' => 'read_existing_daily_workbench_patrol_snapshots_only',
            'collection_logic_changed' => false,
            'raw_data_exposed' => false,
            'automation' => $automation,
            'automation_configured' => (bool)($automation['cron_token_configured'] ?? false),
            'automation_next_action' => (string)($automation['next_action'] ?? 'configure_cron_token'),
        ];
    }

    private function automationHealth(): array
    {
        $cronTokenConfigured = trim((string)Env::get('CRON_TOKEN', '')) !== '';

        return [
            'status' => $cronTokenConfigured ? 'credential_configured' : 'credential_missing',
            'cron_token_configured' => $cronTokenConfigured,
            'scheduler_status' => 'external_scheduler_unverified',
            'next_action' => $cronTokenConfigured ? 'install_or_verify_scheduler' : 'configure_cron_token',
            'message' => $cronTokenConfigured
                ? 'Automatic patrol credential exists; verify Windows Task Scheduler or cron runs the patrol command daily.'
                : 'Automatic patrol credential is not configured; manual patrol snapshots remain available, but daily automatic patrol is not deployable yet.',
            'command' => 'php think online-data:daily-workbench-patrol',
            'script' => 'php scripts/daily_workbench_patrol_cron.php',
            'source_policy' => 'read_environment_configuration_status_only',
            'secret_exposed' => false,
        ];
    }

    public function markdownReport(string $runId = ''): array
    {
        $snapshot = trim($runId) !== '' ? $this->findByRunId($runId) : $this->latest();
        if ($snapshot === null) {
            throw new \RuntimeException('Daily workbench patrol snapshot not found.');
        }

        return [
            'filename' => $this->markdownReportFileName($snapshot),
            'content' => $this->buildMarkdownReport($snapshot),
            'snapshot' => $this->compactSnapshot($snapshot),
        ];
    }

    public function markdownReportForHotel(int $hotelId, string $runId = ''): array
    {
        $snapshot = trim($runId) !== ''
            ? $this->findByRunIdForHotel($runId, $hotelId)
            : $this->latestForHotel($hotelId);
        if ($snapshot === null) {
            throw new \RuntimeException('Daily workbench patrol snapshot not found in the selected hotel scope.');
        }

        return [
            'filename' => $this->markdownReportFileName($snapshot),
            'content' => $this->buildMarkdownReport($snapshot),
            'snapshot' => $this->compactSnapshot($snapshot),
        ];
    }

    public function updateActionStatus(array $input, ?int $userId = null): array
    {
        $runId = $this->safeRunId((string)($input['run_id'] ?? ''));
        if ($runId === '') {
            throw new \InvalidArgumentException('run_id is required.');
        }

        $path = $this->findSnapshotPath($runId);
        if ($path === null) {
            throw new \RuntimeException('Daily workbench patrol snapshot not found.');
        }

        $snapshot = $this->readSnapshot($path);
        if ($snapshot === null) {
            throw new \RuntimeException('Daily workbench patrol snapshot is not readable.');
        }

        $status = $this->normalizeActionStatus((string)($input['status'] ?? ''));
        $hotelId = isset($input['hotel_id']) && is_numeric($input['hotel_id']) ? (int)$input['hotel_id'] : 0;
        $actionCode = trim((string)($input['action_code'] ?? ''));
        $questionKey = trim((string)($input['question_key'] ?? ''));
        $note = $this->safeNote((string)($input['note'] ?? ''));
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('hotel_id is required.');
        }
        if ($actionCode === '' && $questionKey === '') {
            throw new \InvalidArgumentException('action_code or question_key is required.');
        }

        $key = $this->actionTrackingKey($hotelId, $actionCode, $questionKey);
        $items = is_array($snapshot['action_tracking']['items'] ?? null) ? $snapshot['action_tracking']['items'] : [];
        $items[$key] = [
            'hotel_id' => $hotelId,
            'action_code' => $actionCode,
            'question_key' => $questionKey,
            'status' => $status,
            'note' => $note,
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by_user_id' => $userId,
            'review_state' => $status === 'done' ? 'pending_review' : ($status === 'review_needed' ? 'needs_review' : 'open'),
            'source_policy' => 'operator_status_on_runtime_patrol_snapshot_only',
        ];
        if (is_array($input['operation_execution'] ?? null)) {
            $items[$key]['operation_execution'] = $input['operation_execution'];
        }

        $snapshot['action_tracking'] = $this->summarizeActionTracking(
            is_array($snapshot['action_tracking'] ?? null) ? $snapshot['action_tracking'] : [],
            $items
        );
        $snapshot['updated_at'] = date('Y-m-d H:i:s');

        $this->writeSnapshotFile($path, $snapshot);
        $latest = $this->latest();
        if (is_array($latest) && (string)($latest['run_id'] ?? '') === $runId) {
            $latestPath = $this->baseDir() . DIRECTORY_SEPARATOR . self::LATEST_FILE;
            $snapshot['storage'] = [
                'path' => $this->relativePath($path),
                'latest_path' => $this->relativePath($latestPath),
                'retention_policy' => 'runtime_json_snapshot_only',
            ];
            $this->writeSnapshotFile($latestPath, $snapshot);
        }

        return $snapshot;
    }

    public function updateActionStatusForHotel(array $input, int $hotelId, ?int $userId = null): array
    {
        $hotelId = $this->requireHotelId($hotelId);
        if ((int)($input['hotel_id'] ?? 0) !== $hotelId
            || $this->findByRunIdForHotel((string)($input['run_id'] ?? ''), $hotelId) === null
        ) {
            throw new \RuntimeException('Daily workbench patrol snapshot not found in the selected hotel scope.');
        }

        return $this->updateActionStatus($input, $userId);
    }

    public function updateActionReview(array $input, ?int $userId = null): array
    {
        $runId = $this->safeRunId((string)($input['run_id'] ?? ''));
        if ($runId === '') {
            throw new \InvalidArgumentException('run_id is required.');
        }

        $path = $this->findSnapshotPath($runId);
        if ($path === null) {
            throw new \RuntimeException('Daily workbench patrol snapshot not found.');
        }

        $snapshot = $this->readSnapshot($path);
        if ($snapshot === null) {
            throw new \RuntimeException('Daily workbench patrol snapshot is not readable.');
        }

        $hotelId = isset($input['hotel_id']) && is_numeric($input['hotel_id']) ? (int)$input['hotel_id'] : 0;
        $actionCode = trim((string)($input['action_code'] ?? ''));
        $questionKey = trim((string)($input['question_key'] ?? ''));
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('hotel_id is required.');
        }
        if ($actionCode === '' && $questionKey === '') {
            throw new \InvalidArgumentException('action_code or question_key is required.');
        }

        $reviewStatus = strtolower(trim((string)($input['result_status'] ?? $input['review_status'] ?? '')));
        if (!in_array($reviewStatus, ['observing', 'success', 'near_success', 'failed'], true)) {
            throw new \InvalidArgumentException('review result_status must be observing, success, near_success, or failed.');
        }

        $key = $this->actionTrackingKey($hotelId, $actionCode, $questionKey);
        $items = is_array($snapshot['action_tracking']['items'] ?? null) ? $snapshot['action_tracking']['items'] : [];
        if (!is_array($items[$key] ?? null)) {
            throw new \RuntimeException('Daily workbench patrol action status must be tracked before review.');
        }

        $reviewSummary = $this->safeNote((string)($input['result_summary'] ?? $input['review_summary'] ?? ''));
        $operationExecution = is_array($items[$key]['operation_execution'] ?? null) ? $items[$key]['operation_execution'] : [];
        if (is_array($input['operation_execution'] ?? null)) {
            $operationExecution = array_replace($operationExecution, $input['operation_execution']);
        }

        $reviewResult = [
            'result_status' => $reviewStatus,
            'result_summary' => $reviewSummary,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'reviewed_by_user_id' => $userId,
            'source_policy' => 'operator_review_on_runtime_patrol_snapshot_only',
        ];
        $operationExecution['review_status'] = $reviewStatus;
        $operationExecution['review_summary'] = $reviewSummary;
        $operationExecution['reviewed_at'] = $reviewResult['reviewed_at'];

        $items[$key]['operation_execution'] = $operationExecution;
        $items[$key]['review_result'] = $reviewResult;
        $items[$key]['review_state'] = $reviewStatus === 'observing' ? 'observing' : 'reviewed';
        $items[$key]['reviewed_at'] = $reviewResult['reviewed_at'];
        $items[$key]['reviewed_by_user_id'] = $userId;

        $snapshot['action_tracking'] = $this->summarizeActionTracking(
            is_array($snapshot['action_tracking'] ?? null) ? $snapshot['action_tracking'] : [],
            $items
        );
        $snapshot['action_tracking']['review_summary'] = $this->summarizeReviewTracking($items);
        $snapshot['updated_at'] = date('Y-m-d H:i:s');

        $this->writeSnapshotFile($path, $snapshot);
        $latest = $this->latest();
        if (is_array($latest) && (string)($latest['run_id'] ?? '') === $runId) {
            $latestPath = $this->baseDir() . DIRECTORY_SEPARATOR . self::LATEST_FILE;
            $snapshot['storage'] = [
                'path' => $this->relativePath($path),
                'latest_path' => $this->relativePath($latestPath),
                'retention_policy' => 'runtime_json_snapshot_only',
            ];
            $this->writeSnapshotFile($latestPath, $snapshot);
        }

        return $snapshot;
    }

    public function updateActionReviewForHotel(array $input, int $hotelId, ?int $userId = null): array
    {
        $hotelId = $this->requireHotelId($hotelId);
        if ((int)($input['hotel_id'] ?? 0) !== $hotelId
            || $this->findByRunIdForHotel((string)($input['run_id'] ?? ''), $hotelId) === null
        ) {
            throw new \RuntimeException('Daily workbench patrol snapshot not found in the selected hotel scope.');
        }

        return $this->updateActionReview($input, $userId);
    }

    private function buildSnapshot(array $workbenchPayload, array $context): array
    {
        $scope = is_array($workbenchPayload['scope'] ?? null) ? $workbenchPayload['scope'] : [];
        $summary = is_array($workbenchPayload['summary'] ?? null) ? $workbenchPayload['summary'] : [];
        $rows = array_values(array_filter((array)($workbenchPayload['rows'] ?? []), static fn($row): bool => is_array($row)));
        $nextActions = array_values(array_filter((array)($workbenchPayload['next_actions'] ?? []), static fn($row): bool => is_array($row)));
        $targetDate = $this->normalizeDate((string)($scope['target_date'] ?? $context['target_date'] ?? date('Y-m-d')));
        $createdAt = date('Y-m-d H:i:s');
        $runId = 'daily_workbench_' . str_replace('-', '', $targetDate) . '_' . date('His') . '_' . substr(sha1($createdAt . json_encode([
            'hotel_id' => $scope['hotel_id'] ?? null,
            'summary' => $summary,
        ])), 0, 8);

        return [
            'run_id' => $runId,
            'snapshot_type' => 'phase2_daily_workbench_patrol',
            'created_at' => $createdAt,
            'trigger_type' => (string)($context['trigger_type'] ?? 'manual'),
            'created_by_user_id' => isset($context['user_id']) && is_numeric($context['user_id']) ? (int)$context['user_id'] : null,
            'scope' => [
                'metric_scope' => 'ota_channel',
                'target_date' => $targetDate,
                'hotel_id' => $scope['hotel_id'] ?? null,
                'requested_hotel_limit' => (int)($scope['requested_hotel_limit'] ?? count($rows)),
                'returned_hotel_count' => count($rows),
                'source_policy' => 'read_existing_collection_reliability_only',
                'protected_boundary' => 'Snapshot reads existing OTA evidence only; it does not trigger Ctrip or Meituan acquisition.',
            ],
            'summary' => $summary,
            'rows' => $rows,
            'next_actions' => $nextActions,
            'data_status' => is_array($workbenchPayload['data_status'] ?? null) ? $workbenchPayload['data_status'] : [],
            'action_tracking' => [
                'status' => empty($nextActions) ? 'no_action_required' : 'action_required',
                'tracked_action_count' => count($nextActions),
                'high_priority_action_count' => (int)($summary['high_priority_action_count'] ?? 0),
                'status_source' => 'derived_from_phase1_employee_questions_until_persistent_action_execution_records_exist',
                'review_state' => 'pending_review',
                'items' => [],
                'status_summary' => [],
            ],
            'evidence_policy' => [
                'metric_scope' => 'ota_channel',
                'source_policy' => 'read_existing_collection_reliability_only',
                'storage_policy' => 'runtime_json_snapshot_only',
                'collection_logic_changed' => false,
                'raw_data_exposed' => false,
                'sensitive_credentials_exposed' => false,
            ],
        ];
    }

    private function compactSnapshot(array $snapshot): array
    {
        return [
            'run_id' => (string)($snapshot['run_id'] ?? ''),
            'snapshot_type' => (string)($snapshot['snapshot_type'] ?? ''),
            'created_at' => (string)($snapshot['created_at'] ?? ''),
            'trigger_type' => (string)($snapshot['trigger_type'] ?? ''),
            'scope' => is_array($snapshot['scope'] ?? null) ? $snapshot['scope'] : [],
            'summary' => is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [],
            'action_tracking' => is_array($snapshot['action_tracking'] ?? null) ? $snapshot['action_tracking'] : [],
            'evidence_policy' => is_array($snapshot['evidence_policy'] ?? null) ? $snapshot['evidence_policy'] : [],
        ];
    }

    private function buildMarkdownReport(array $snapshot): string
    {
        $scope = is_array($snapshot['scope'] ?? null) ? $snapshot['scope'] : [];
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $tracking = is_array($snapshot['action_tracking'] ?? null) ? $snapshot['action_tracking'] : [];
        $policy = is_array($snapshot['evidence_policy'] ?? null) ? $snapshot['evidence_policy'] : [];
        $rows = array_values(array_filter((array)($snapshot['rows'] ?? []), static fn($row): bool => is_array($row)));
        $actions = array_values(array_filter((array)($snapshot['next_actions'] ?? []), static fn($row): bool => is_array($row)));
        $items = is_array($tracking['items'] ?? null) ? $tracking['items'] : [];

        $lines = [
            '# 宿析OS OTA 每日巡检报告',
            '',
            '## 巡检信息',
            '',
            '| 项目 | 内容 |',
            '| --- | --- |',
            '| 巡检 Run ID | ' . $this->mdCell((string)($snapshot['run_id'] ?? '')) . ' |',
            '| 目标日期 | ' . $this->mdCell((string)($scope['target_date'] ?? '')) . ' |',
            '| 生成时间 | ' . $this->mdCell((string)($snapshot['created_at'] ?? '')) . ' |',
            '| 触发方式 | ' . $this->mdCell((string)($snapshot['trigger_type'] ?? 'manual')) . ' |',
            '| 巡检门店数 | ' . (int)($scope['returned_hotel_count'] ?? count($rows)) . ' |',
            '| 指标范围 | OTA 渠道，不代表全酒店经营事实 |',
            '| 采集逻辑变更 | ' . (($policy['collection_logic_changed'] ?? null) === true ? '是' : '否') . ' |',
            '| 原始数据暴露 | ' . (($policy['raw_data_exposed'] ?? null) === true ? '是' : '否') . ' |',
            '',
            '## 汇总',
            '',
            '| 指标 | 数值 |',
            '| --- | ---: |',
            '| 完整门店 | ' . (int)($summary['complete_hotels'] ?? 0) . ' |',
            '| 未完整门店 | ' . (int)($summary['incomplete_hotels'] ?? 0) . ' |',
            '| 请求失败门店 | ' . (int)($summary['request_failed_hotels'] ?? 0) . ' |',
            '| 需要动作门店 | ' . (int)($summary['action_required_hotels'] ?? 0) . ' |',
            '| 待处理动作 | ' . (int)($summary['next_action_count'] ?? count($actions)) . ' |',
            '| 高优先级动作 | ' . (int)($summary['high_priority_action_count'] ?? 0) . ' |',
            '| 目标日 OTA 源数据行 | ' . (int)($summary['target_date_source_rows'] ?? 0) . ' |',
            '',
            '## 门店巡检',
            '',
            '| 门店 | 状态 | OTA源数据 | 字段缺口 | AI依据 | 运营闭环 |',
            '| --- | --- | ---: | --- | --- | --- |',
        ];

        foreach ($rows as $row) {
            $lines[] = implode(' ', [
                '|',
                $this->mdCell((string)($row['hotel_name'] ?? ('Hotel ID ' . (int)($row['hotel_id'] ?? 0)))),
                '|',
                $this->mdCell((string)($row['status'] ?? 'unknown')),
                '|',
                (int)($row['collection']['target_date_source_rows'] ?? 0),
                '|',
                $this->mdCell($this->joinCodes((array)($row['metric_diagnosis']['data_gap_codes'] ?? []))),
                '|',
                $this->mdCell((string)($row['ai_evidence']['diagnosis_status'] ?? 'unknown')),
                '|',
                $this->mdCell((string)($row['operation_execution']['operation_evidence_status'] ?? 'unknown')),
                '|',
            ]);
        }

        $lines = array_merge($lines, [
            '',
            '## AI 建议解释',
            '',
            '| 门店 | 依据状态 | 解释 | 缺失证据 | 下一步 |',
            '| --- | --- | --- | --- | --- |',
        ]);

        foreach ($rows as $row) {
            $aiEvidence = is_array($row['ai_evidence'] ?? null) ? $row['ai_evidence'] : [];
            $explanation = is_array($aiEvidence['explanation'] ?? null) ? $aiEvidence['explanation'] : [];
            $missingCodes = array_values(array_filter((array)($explanation['missing_codes'] ?? $aiEvidence['blocking_missing_codes'] ?? [])));

            $lines[] = implode(' ', [
                '|',
                $this->mdCell((string)($row['hotel_name'] ?? ('Hotel ID ' . (int)($row['hotel_id'] ?? 0)))),
                '|',
                $this->mdCell((string)($aiEvidence['status'] ?? 'unknown')),
                '|',
                $this->mdCell((string)($explanation['summary'] ?? 'AI suggestion evidence is not proved yet.')),
                '|',
                $this->mdCell($this->joinCodes($missingCodes)),
                '|',
                $this->mdCell((string)($explanation['next_step'] ?? 'Review AI evidence sources, data gaps, and action-item status.')),
                '|',
            ]);
        }

        $lines = array_merge($lines, [
            '',
            '## 动作跟踪与复盘',
            '',
            '| 门店 | 优先级 | 动作 | 跟踪 | 执行记录 | 复盘 |',
            '| --- | --- | --- | --- | --- | --- |',
        ]);

        foreach ($actions as $action) {
            $hotelId = (int)($action['hotel_id'] ?? 0);
            $actionCode = trim((string)($action['action_code'] ?? ''));
            $questionKey = trim((string)($action['question_key'] ?? ''));
            $key = $this->actionTrackingKey($hotelId, $actionCode, $questionKey);
            $tracked = is_array($items[$key] ?? null) ? $items[$key] : [];
            $execution = is_array($tracked['operation_execution'] ?? null) ? $tracked['operation_execution'] : [];
            $review = is_array($tracked['review_result'] ?? null) ? $tracked['review_result'] : [];
            $actionText = trim((string)($action['action'] ?? $action['reason'] ?? ''));
            $executionText = (int)($execution['intent_id'] ?? 0) > 0
                ? '意图#' . (int)($execution['intent_id'] ?? 0) . ' / 任务#' . (int)($execution['task_id'] ?? 0) . ' / ' . (string)($execution['task_status'] ?? $execution['intent_status'] ?? '')
                : '未生成执行记录';
            $reviewText = trim((string)($review['result_status'] ?? $execution['review_status'] ?? '')) ?: '未复盘';
            if (trim((string)($review['result_summary'] ?? $execution['review_summary'] ?? '')) !== '') {
                $reviewText .= '：' . trim((string)($review['result_summary'] ?? $execution['review_summary'] ?? ''));
            }

            $lines[] = implode(' ', [
                '|',
                $this->mdCell((string)($action['hotel_name'] ?? ('Hotel ID ' . $hotelId))),
                '|',
                $this->mdCell((string)($action['priority'] ?? 'medium')),
                '|',
                $this->mdCell($actionText !== '' ? $actionText : ($actionCode !== '' ? $actionCode : $questionKey)),
                '|',
                $this->mdCell((string)($tracked['status'] ?? 'pending')),
                '|',
                $this->mdCell($executionText),
                '|',
                $this->mdCell($reviewText),
                '|',
            ]);
        }

        $reviewSummary = is_array($tracking['review_summary'] ?? null) ? $tracking['review_summary'] : [];
        $lines = array_merge($lines, [
            '',
            '## 复盘汇总',
            '',
            '| 状态 | 数量 |',
            '| --- | ---: |',
            '| 有效 | ' . (int)($reviewSummary['success'] ?? 0) . ' |',
            '| 接近目标 | ' . (int)($reviewSummary['near_success'] ?? 0) . ' |',
            '| 未达标 | ' . (int)($reviewSummary['failed'] ?? 0) . ' |',
            '| 继续观察 | ' . (int)($reviewSummary['observing'] ?? 0) . ' |',
            '',
            '## 使用边界',
            '',
            '- 本报告只使用宿析OS已保存的 OTA 渠道巡检快照、员工六问、运营执行和复盘状态。',
            '- 本报告不触发携程或美团采集，不保存登录凭据、浏览器 Profile 或 OTA 原始响应。',
            '- OTA 渠道指标不能直接等同于全酒店经营事实；如需全酒店口径，必须补充 PMS 或财务证据。',
            '',
        ]);

        return implode("\n", $lines);
    }

    private function markdownReportFileName(array $snapshot): string
    {
        $scope = is_array($snapshot['scope'] ?? null) ? $snapshot['scope'] : [];
        $targetDate = preg_replace('/[^0-9]/', '', (string)($scope['target_date'] ?? date('Ymd'))) ?: date('Ymd');
        $createdAt = preg_replace('/[^0-9]/', '', (string)($snapshot['created_at'] ?? date('YmdHis'))) ?: date('YmdHis');

        return 'suxios_ota_daily_workbench_patrol_' . $targetDate . '_' . substr($createdAt, 8, 6) . '.md';
    }

    private function mdCell(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', trim($value));
        $value = str_replace('|', '\\|', $value);

        return $value !== '' ? $value : '-';
    }

    private function joinCodes(array $codes): string
    {
        $values = array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $codes)));

        return $values === [] ? '-' : implode('、', array_slice($values, 0, 5));
    }

    private function readSnapshot(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeSnapshotFile(string $path, array $snapshot): void
    {
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Daily workbench patrol snapshot update failed.');
        }
    }

    private function findSnapshotPath(string $runId): ?string
    {
        $files = glob($this->baseDir() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $runId . '.json') ?: [];
        return is_file($files[0] ?? '') ? $files[0] : null;
    }

    /** @return array<int, string> */
    private function snapshotFiles(): array
    {
        $files = glob($this->baseDir() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'daily_workbench_*.json') ?: [];
        rsort($files, SORT_STRING);
        return $files;
    }

    private function snapshotHotelId(array $snapshot): int
    {
        $scope = is_array($snapshot['scope'] ?? null) ? $snapshot['scope'] : [];
        return isset($scope['hotel_id']) && is_numeric($scope['hotel_id']) ? (int)$scope['hotel_id'] : 0;
    }

    private function snapshotWithinHotelScope(array $snapshot, int $hotelId): bool
    {
        if ($hotelId <= 0 || $this->snapshotHotelId($snapshot) !== $hotelId) {
            return false;
        }

        foreach (['rows', 'next_actions'] as $field) {
            foreach ((array)($snapshot[$field] ?? []) as $item) {
                if (!is_array($item) || (int)($item['hotel_id'] ?? 0) !== $hotelId) {
                    return false;
                }
            }
        }
        $trackingItems = is_array($snapshot['action_tracking']['items'] ?? null)
            ? $snapshot['action_tracking']['items']
            : [];
        foreach ($trackingItems as $item) {
            if (!is_array($item) || (int)($item['hotel_id'] ?? 0) !== $hotelId) {
                return false;
            }
        }

        return true;
    }

    private function requireHotelId(int $hotelId): int
    {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('hotel_id is required.');
        }

        return $hotelId;
    }

    private function safeRunId(string $value): string
    {
        $value = trim($value);
        return preg_match('/^daily_workbench_\d{8}_\d{6}_[a-f0-9]{8}$/', $value) ? $value : '';
    }

    private function normalizeActionStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $allowed = ['pending', 'in_progress', 'done', 'skipped', 'review_needed'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Action status must be pending, in_progress, done, skipped, or review_needed.');
        }

        return $status;
    }

    private function safeNote(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
        return function_exists('mb_substr') ? mb_substr($value, 0, 500) : substr($value, 0, 500);
    }

    private function actionTrackingKey(int $hotelId, string $actionCode, string $questionKey): string
    {
        $identity = $actionCode !== '' ? $actionCode : $questionKey;
        return $hotelId . '|' . preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', $identity);
    }

    private function summarizeActionTracking(array $tracking, array $items): array
    {
        $summary = [];
        foreach ($items as $item) {
            $status = (string)($item['status'] ?? 'pending');
            $summary[$status] = ($summary[$status] ?? 0) + 1;
        }

        $tracking['items'] = $items;
        $tracking['status_summary'] = $summary;
        $tracking['updated_action_count'] = count($items);
        $tracking['review_state'] = ($summary['done'] ?? 0) > 0 || ($summary['review_needed'] ?? 0) > 0
            ? 'review_ready'
            : ($tracking['review_state'] ?? 'pending_review');
        $tracking['status_source'] = 'operator_status_on_runtime_patrol_snapshot_only';

        return $tracking;
    }

    private function summarizeReviewTracking(array $items): array
    {
        $summary = [
            'observing' => 0,
            'success' => 0,
            'near_success' => 0,
            'failed' => 0,
            'reviewed_count' => 0,
        ];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $review = is_array($item['review_result'] ?? null) ? $item['review_result'] : [];
            $status = (string)($review['result_status'] ?? '');
            if (!array_key_exists($status, $summary)) {
                continue;
            }
            $summary[$status]++;
            if ($status !== 'observing') {
                $summary['reviewed_count']++;
            }
        }

        return $summary;
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : date('Y-m-d');
    }

    private function baseDir(): string
    {
        $dir = rtrim(runtime_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::SNAPSHOT_DIR;
        $this->ensureDirectory($dir);
        return $dir;
    }

    private function targetDateDir(string $targetDate): string
    {
        return $this->baseDir() . DIRECTORY_SEPARATOR . str_replace('-', '', $targetDate);
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create directory: ' . $dir);
        }
    }

    private function relativePath(string $path): string
    {
        $runtime = rtrim(runtime_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $runtime)
            ? 'runtime/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($runtime)))
            : $path;
    }
}
