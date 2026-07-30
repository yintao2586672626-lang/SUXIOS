<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

/**
 * Stores the outer PMS-collection -> report-gate -> WeCom-dispatch run.
 *
 * Detailed delivery attempts remain in the existing dispatch ledger. This
 * record explains why a due run stopped before a delivery attempt.
 */
final class ManualNotificationPipelineRunService
{
    private const MODES = ['test_pipeline'];
    private const STATUSES = ['running', 'completed', 'blocked', 'failed'];

    public function start(
        int $hotelId,
        int $robotId,
        DateTimeImmutable $observedAt
    ): int {
        if ($hotelId <= 0 || $robotId <= 0) {
            throw new \InvalidArgumentException('notification_pipeline_scope_invalid');
        }
        $this->assertTable();
        $now = $observedAt
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d H:i:s');
        $id = (int)Db::name('manual_notification_schedule_runs')->insertGetId([
            'runner_mode' => 'test_pipeline',
            'dispatch_requested' => 0,
            'scope_hotel_id' => $hotelId,
            'scope_robot_id' => $robotId,
            'observed_at' => $now,
            'status' => 'running',
            'candidate_count' => 0,
            'due_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'blocked_count' => 0,
            'result_summary_json' => $this->json([
                'status' => 'running',
                'stage' => 'due_check',
                'sensitive_values_exposed' => false,
            ]),
            'started_at' => $now,
            'finished_at' => null,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        if ($id <= 0) {
            throw new \RuntimeException('notification_pipeline_run_create_failed');
        }
        return $id;
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    public function finish(
        int $runId,
        string $status,
        bool $dispatchRequested,
        array $summary,
        DateTimeImmutable $finishedAt
    ): array {
        if ($runId <= 0 || !in_array($status, self::STATUSES, true) || $status === 'running') {
            throw new \InvalidArgumentException('notification_pipeline_finish_invalid');
        }
        $now = $finishedAt
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d H:i:s');
        $sanitized = $this->sanitizeSummary($summary);
        $sanitized['status'] = $status;
        $sanitized['sensitive_values_exposed'] = false;
        return Db::transaction(function () use (
            $runId,
            $status,
            $dispatchRequested,
            $summary,
            $sanitized,
            $now
        ): array {
            $row = Db::name('manual_notification_schedule_runs')
                ->where('id', $runId)
                ->whereIn('runner_mode', self::MODES)
                ->lock(true)
                ->find();
            if (!is_array($row) || (string)$row['status'] !== 'running') {
                throw new \RuntimeException('notification_pipeline_run_not_running');
            }
            Db::name('manual_notification_schedule_runs')->where('id', $runId)->update([
                'dispatch_requested' => $dispatchRequested ? 1 : 0,
                'status' => $status,
                'candidate_count' => $this->count($summary['candidate_count'] ?? 0),
                'due_count' => $this->count($summary['due_count'] ?? 0),
                'sent_count' => $this->count($summary['sent_count'] ?? 0),
                'failed_count' => $this->count($summary['failed_count'] ?? 0),
                'blocked_count' => $this->count($summary['blocked_count'] ?? 0),
                'result_summary_json' => $this->json($sanitized),
                'finished_at' => $now,
                'update_time' => $now,
            ]);

            $readback = Db::name('manual_notification_schedule_runs')
                ->where('id', $runId)
                ->find();
            if (!is_array($readback)
                || (string)$readback['status'] !== $status
                || (int)$readback['dispatch_requested'] !== ($dispatchRequested ? 1 : 0)
                || (string)$readback['finished_at'] !== $now
            ) {
                throw new \RuntimeException('notification_pipeline_run_readback_failed');
            }
            return [
                'run_id' => $runId,
                'runner_mode' => (string)$readback['runner_mode'],
                'status' => (string)$readback['status'],
                'dispatch_requested' => (int)$readback['dispatch_requested'] === 1,
                'scope_hotel_id' => (int)$readback['scope_hotel_id'],
                'scope_robot_id' => (int)$readback['scope_robot_id'],
                'candidate_count' => (int)$readback['candidate_count'],
                'due_count' => (int)$readback['due_count'],
                'sent_count' => (int)$readback['sent_count'],
                'failed_count' => (int)$readback['failed_count'],
                'blocked_count' => (int)$readback['blocked_count'],
                'finished_at' => (string)$readback['finished_at'],
            ];
        });
    }

    private function count(mixed $value): int
    {
        return min(100000, max(0, (int)$value));
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function sanitizeSummary(array $summary): array
    {
        $allowed = [
            'stage',
            'reason_code',
            'business_date',
            'candidate_count',
            'due_count',
            'sent_count',
            'failed_count',
            'blocked_count',
            'stale_sending_outcome_unknown_count',
            'capture_id',
            'operating_target_record_id',
            'operating_target_revision_no',
            'operating_target_status',
            'collection_status',
            'schedule_run_id',
        ];
        $result = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $summary)) {
                continue;
            }
            $value = $summary[$key];
            if (is_int($value) || is_bool($value) || $value === null) {
                $result[$key] = $value;
                continue;
            }
            if (is_float($value) && is_finite($value)) {
                $result[$key] = $value;
                continue;
            }
            $result[$key] = mb_substr(
                preg_replace(
                    '/(key|token|secret|cookie|password|authorization|webhook)\s*[=:]\s*[^\s,;]+/iu',
                    '$1=<redacted>',
                    trim((string)$value)
                ) ?? '',
                0,
                180,
                'UTF-8'
            );
        }
        return $result;
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function assertTable(): void
    {
        try {
            Db::query(
                'SELECT `scope_robot_id`, `result_summary_json`'
                . ' FROM `manual_notification_schedule_runs` WHERE 1 = 0'
            );
        } catch (\Throwable) {
            throw new \RuntimeException('notification_pipeline_run_table_missing');
        }
    }
}
