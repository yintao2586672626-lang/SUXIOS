<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Scans due observing tasks and appends same-scope source readback only. Human
 * operators still decide success, near-success, or failure.
 */
final class OperationScheduledReviewBatchService
{
    public const CONTRACT_VERSION = 'operation_scheduled_review_batch.v1';
    private const CURSOR_TABLE = 'operation_scheduled_review_scan_cursors';
    private const SCAN_BUDGET = 500;

    /** @var callable|null */
    private $candidateLoader;

    /** @var callable|null */
    private $taskReader;

    /** @var callable|null */
    private $reconciler;

    public function __construct(
        ?callable $candidateLoader = null,
        ?callable $taskReader = null,
        ?callable $reconciler = null
    ) {
        $this->candidateLoader = $candidateLoader;
        $this->taskReader = $taskReader;
        $this->reconciler = $reconciler;
    }

    /** @return array<string,mixed> */
    public function run(int $hotelId, int $limit = 50, bool $execute = false): array
    {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('scheduled_review_hotel_id_invalid');
        }
        $limit = max(1, min(100, $limit));
        $scan = $this->candidateScan($hotelId, $limit, $execute);
        $candidateIds = $scan['ids'];
        $rows = $scan['failures'];
        $counts = [
            'due_preview' => 0,
            'source_readback_verified' => 0,
            'source_readback_missing' => 0,
            'already_reviewed' => 0,
            'not_due' => 0,
            'not_eligible' => 0,
            'failed' => 0,
        ];
        $counts['failed'] = count($scan['failures']);

        foreach ($candidateIds as $taskId) {
            try {
                $task = $this->readTask($taskId, $hotelId);
                $row = $this->classifyTask($task, $hotelId, $execute);
                if (($row['status'] ?? '') === 'due_execute') {
                    $row = $this->reconcile($taskId, $hotelId);
                }
            } catch (\Throwable $error) {
                $row = [
                    'status' => 'failed',
                    'task_id' => $taskId,
                    'hotel_id' => $hotelId,
                    'reason_code' => $this->safeReason($error),
                ];
            }
            $status = (string)($row['status'] ?? 'failed');
            if (!array_key_exists($status, $counts)) {
                $status = 'failed';
                $row['status'] = $status;
            }
            $counts[$status]++;
            $rows[] = $row;
        }
        $cursorAdvanced = false;
        if ($execute
            && $counts['failed'] === 0
            && (int)($scan['next_cursor'] ?? 0) > 0
        ) {
            try {
                $this->writeScanCursor($hotelId, (int)$scan['next_cursor']);
                $cursorAdvanced = true;
            } catch (\Throwable $error) {
                $counts['failed']++;
                $rows[] = [
                    'status' => 'failed',
                    'task_id' => null,
                    'hotel_id' => $hotelId,
                    'reason_code' => $this->safeReason($error),
                    'stage' => 'scan_cursor_write',
                ];
            }
        }

        $failureCount = $counts['failed'];
        $processedCount = $counts['source_readback_verified']
            + $counts['source_readback_missing']
            + $counts['already_reviewed'];
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $failureCount > 0
                ? 'partial'
                : ($candidateIds === [] ? 'no_candidates' : 'completed'),
            'mode' => $execute ? 'execute_source_readback' : 'preview',
            'hotel_id' => $hotelId,
            'candidate_count' => count($candidateIds) + count($scan['failures']),
            'scanned_candidate_count' => (int)$scan['scanned_count'],
            'scan_truncated' => ($scan['scan_truncated'] ?? false) === true,
            'next_cursor' => (int)($scan['next_cursor'] ?? 0) ?: null,
            'cursor_advanced' => $cursorAdvanced,
            'processed_count' => $processedCount,
            'counts' => $counts,
            'rows' => $rows,
            'human_outcome_confirmation_required' => true,
            'automatic_outcome_decision' => false,
            'automatic_sop_publish' => false,
            'external_write_count' => 0,
            'message_sent' => false,
        ];
    }

    /** @return array{ids:list<int>,failures:list<array<string,mixed>>,scanned_count:int,scan_truncated:bool,next_cursor:?int,cursor_advanced:bool} */
    private function candidateScan(int $hotelId, int $limit, bool $advanceCursor): array
    {
        if ($this->candidateLoader !== null) {
            $loaded = call_user_func($this->candidateLoader, $hotelId, $limit);
            $loaded = is_array($loaded) ? $loaded : [];
            $rawIds = array_key_exists('ids', $loaded) ? (array)$loaded['ids'] : $loaded;
            $ids = $this->positiveIds($rawIds, $limit);
            $failures = array_values(array_filter(
                array_key_exists('failures', $loaded) ? (array)$loaded['failures'] : [],
                'is_array'
            ));
            return [
                'ids' => $ids,
                'failures' => $failures,
                'scanned_count' => max(count($ids), (int)($loaded['scanned_count'] ?? count($ids))),
                'scan_truncated' => ($loaded['scan_truncated'] ?? false) === true,
                'next_cursor' => (int)($loaded['next_cursor'] ?? 0) ?: null,
                'cursor_advanced' => false,
            ];
        }
        $cursor = $advanceCursor ? $this->readScanCursor($hotelId) : 0;
        $query = static fn() => Db::name('operation_execution_tasks')
            ->alias('task')
            ->join('operation_execution_intents intent', 'intent.id = task.intent_id')
            ->field('task.id')
            ->where('task.hotel_id', $hotelId)
            ->where('intent.hotel_id', $hotelId)
            ->where('task.status', 'executed')
            ->where(function ($query): void {
                $query->whereNull('task.result_status')
                    ->whereOr('task.result_status', '')
                    ->whereOr('task.result_status', 'observing');
            })
            ->whereIn('intent.source_module', [
                'ota_diagnosis_saved',
                OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
                RevenueCockpitActionContract::SOURCE_MODULE,
                OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
            ])
            ->whereNull('task.deleted_at')
            ->whereNull('intent.deleted_at')
            ->order('task.id', 'asc');
        $rows = $query()->where('task.id', '>', $cursor)->limit(self::SCAN_BUDGET)->select()->toArray();
        if (count($rows) < self::SCAN_BUDGET && $cursor > 0) {
            $rows = array_merge(
                $rows,
                $query()->where('task.id', '<=', $cursor)
                    ->limit(self::SCAN_BUDGET - count($rows))
                    ->select()->toArray()
            );
        }
        $dueIds = [];
        $failures = [];
        $scannedCount = 0;
        $rawIds = $this->positiveIds(array_column($rows, 'id'), count($rows));
        $nextCursor = null;
        foreach ($rawIds as $taskId) {
            $scannedCount++;
            $nextCursor = $taskId;
            try {
                $task = $this->readTask($taskId, $hotelId);
                if (($this->classifyTask($task, $hotelId, false)['status'] ?? '') !== 'due_preview') {
                    continue;
                }
                $dueIds[] = $taskId;
                if (count($dueIds) >= $limit) {
                    break;
                }
            } catch (\Throwable $error) {
                $failures[] = [
                    'status' => 'failed',
                    'task_id' => $taskId,
                    'hotel_id' => $hotelId,
                    'reason_code' => $this->safeReason($error),
                    'stage' => 'candidate_readback',
                ];
                continue;
            }
        }
        $scanTruncated = $scannedCount < count($rawIds) || count($rawIds) >= self::SCAN_BUDGET;
        return [
            'ids' => $dueIds,
            'failures' => $failures,
            'scanned_count' => $scannedCount,
            'scan_truncated' => $scanTruncated,
            'next_cursor' => $nextCursor,
            'cursor_advanced' => false,
        ];
    }

    private function readScanCursor(int $hotelId): int
    {
        try {
            return max(0, (int)Db::name(self::CURSOR_TABLE)
                ->where('hotel_id', $hotelId)
                ->value('last_task_id'));
        } catch (\Throwable $error) {
            throw new \RuntimeException('scheduled_review_cursor_read_failed', 0, $error);
        }
    }

    private function writeScanCursor(int $hotelId, int $taskId): void
    {
        $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->where('status', 1)->value('tenant_id');
        if ($tenantId <= 0 || $taskId <= 0) {
            throw new \RuntimeException('scheduled_review_cursor_scope_invalid');
        }
        Db::transaction(static function () use ($tenantId, $hotelId, $taskId): void {
            $existing = Db::name(self::CURSOR_TABLE)->where('hotel_id', $hotelId)->lock(true)->find();
            $values = [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'last_task_id' => $taskId,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if (is_array($existing)) {
                Db::name(self::CURSOR_TABLE)->where('id', (int)$existing['id'])->update($values);
            } else {
                Db::name(self::CURSOR_TABLE)->insert($values);
            }
        });
    }

    /** @return array<string,mixed> */
    private function readTask(int $taskId, int $hotelId): array
    {
        $task = $this->taskReader !== null
            ? call_user_func($this->taskReader, $taskId, $hotelId)
            : (new OperationManagementService())->readExecutionTask($taskId, [$hotelId]);
        if (!is_array($task)) {
            throw new \RuntimeException('scheduled_review_task_read_failed');
        }
        return $task;
    }

    /** @return array<string,mixed> */
    private function classifyTask(array $task, int $hotelId, bool $execute): array
    {
        $taskId = (int)($task['id'] ?? 0);
        if ($taskId <= 0 || (int)($task['hotel_id'] ?? 0) !== $hotelId) {
            throw new \RuntimeException('scheduled_review_task_scope_mismatch');
        }
        $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
        $resultStatus = strtolower(trim((string)($task['result_status'] ?? '')));
        $reviewAt = trim((string)($task['review_available_at'] ?? ''));
        if (in_array($resultStatus, ['success', 'near_success', 'failed'], true)) {
            return [
                'status' => 'already_reviewed',
                'task_id' => $taskId,
                'hotel_id' => $hotelId,
                'review_at' => $reviewAt,
                'result_status' => $resultStatus,
                'next_action' => 'none',
            ];
        }
        if ($taskStatus !== 'executed' || !in_array($resultStatus, ['', 'observing'], true)) {
            return [
                'status' => 'not_eligible',
                'task_id' => $taskId,
                'hotel_id' => $hotelId,
                'review_at' => $reviewAt,
                'reason_code' => 'scheduled_review_task_not_observing',
            ];
        }
        if ($reviewAt === '') {
            return [
                'status' => 'not_eligible',
                'task_id' => $taskId,
                'hotel_id' => $hotelId,
                'review_at' => '',
                'reason_code' => 'scheduled_review_time_missing',
            ];
        }
        if (($task['review_is_available'] ?? false) !== true) {
            return [
                'status' => 'not_due',
                'task_id' => $taskId,
                'hotel_id' => $hotelId,
                'review_at' => $reviewAt,
                'next_action' => 'wait_for_review_window',
            ];
        }
        return [
            'status' => $execute ? 'due_execute' : 'due_preview',
            'task_id' => $taskId,
            'hotel_id' => $hotelId,
            'review_at' => $reviewAt,
            'next_action' => $execute
                ? 'append_same_scope_source_readback'
                : 'run_with_execute_to_append_source_readback',
        ];
    }

    /** @return array<string,mixed> */
    private function reconcile(int $taskId, int $hotelId): array
    {
        $result = $this->reconciler !== null
            ? call_user_func($this->reconciler, $taskId, $hotelId)
            : (new OperationManagementService())->reconcileScheduledExecutionTask(
                $taskId,
                [$hotelId]
            );
        if (!is_array($result)) {
            throw new \RuntimeException('scheduled_review_reconcile_failed');
        }
        $status = (string)($result['status'] ?? 'failed');
        if (!in_array($status, [
            'source_readback_verified',
            'source_readback_missing',
            'already_reviewed',
        ], true)) {
            throw new \RuntimeException('scheduled_review_reconcile_status_invalid');
        }
        return [
            'status' => $status,
            'task_id' => $taskId,
            'hotel_id' => $hotelId,
            'review_at' => (string)($result['review_at'] ?? ''),
            'source_verified' => ($result['source_verified'] ?? false) === true,
            'outcome_status' => (string)($result['outcome_status'] ?? 'unverified'),
            'result_status' => (string)($result['result_status'] ?? 'observing'),
            'next_action' => (string)($result['next_action'] ?? 'human_confirm_review_result'),
        ];
    }

    /** @param array<int,mixed> $values @return list<int> */
    private function positiveIds(array $values, int $limit): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = (int)(is_array($value) ? ($value['id'] ?? 0) : $value);
            if ($id > 0) {
                $ids[$id] = $id;
            }
            if (count($ids) >= $limit) {
                break;
            }
        }
        return array_values($ids);
    }

    private function safeReason(\Throwable $error): string
    {
        $reason = strtolower(trim($error->getMessage()));
        $reason = preg_replace('/[^a-z0-9_-]+/', '_', $reason) ?: '';
        return substr(trim($reason, '_'), 0, 120) ?: 'scheduled_review_failed';
    }
}
