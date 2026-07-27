<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

/**
 * Stores collection-only PMS + OTA digest runs.
 *
 * The shared table is reused only as a run ledger. These rows never carry a
 * robot scope, never request dispatch and never represent an external send.
 */
final class SingleHotelCollectionPreviewRunService
{
    private const RUNNER_MODE = 'collection_only';
    private const FINAL_STATUSES = ['completed', 'failed'];

    public function start(int $hotelId, DateTimeImmutable $observedAt): int
    {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('collection_preview_scope_invalid');
        }
        $this->assertTable();
        $now = $this->dateTime($observedAt);
        $id = (int)Db::name('manual_notification_schedule_runs')->insertGetId([
            'runner_mode' => self::RUNNER_MODE,
            'dispatch_requested' => 0,
            'scope_hotel_id' => $hotelId,
            'scope_robot_id' => null,
            'observed_at' => $now,
            'status' => 'running',
            'candidate_count' => 0,
            'due_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'blocked_count' => 0,
            'result_summary_json' => $this->json([
                'status' => 'running',
                'stage' => 'pms_collection',
                'preview_only' => true,
                'dispatch_requested' => false,
                'message_sent' => false,
                'webhook_read' => false,
                'sensitive_values_exposed' => false,
            ]),
            'started_at' => $now,
            'finished_at' => null,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        if ($id <= 0) {
            throw new \RuntimeException('collection_preview_run_create_failed');
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
        string $previewStatus,
        array $summary,
        DateTimeImmutable $finishedAt
    ): array {
        if ($runId <= 0
            || !in_array($status, self::FINAL_STATUSES, true)
            || !in_array($previewStatus, ['ready', 'partial', 'blocked'], true)
        ) {
            throw new \InvalidArgumentException('collection_preview_finish_invalid');
        }
        $now = $this->dateTime($finishedAt);
        $sanitized = $this->sanitizeSummary($summary);
        $sanitized['status'] = $status;
        $sanitized['preview_status'] = $previewStatus;
        $sanitized['preview_only'] = true;
        $sanitized['dispatch_requested'] = false;
        $sanitized['message_sent'] = false;
        $sanitized['webhook_read'] = false;
        $sanitized['sensitive_values_exposed'] = false;

        return Db::transaction(function () use (
            $runId,
            $status,
            $previewStatus,
            $sanitized,
            $now
        ): array {
            $row = Db::name('manual_notification_schedule_runs')
                ->where('id', $runId)
                ->where('runner_mode', self::RUNNER_MODE)
                ->lock(true)
                ->find();
            if (!is_array($row) || (string)($row['status'] ?? '') !== 'running') {
                throw new \RuntimeException('collection_preview_run_not_running');
            }
            Db::name('manual_notification_schedule_runs')
                ->where('id', $runId)
                ->update([
                    'dispatch_requested' => 0,
                    'status' => $status,
                    'candidate_count' => 0,
                    'due_count' => 0,
                    'sent_count' => 0,
                    'failed_count' => $status === 'failed' ? 1 : 0,
                    'blocked_count' => $previewStatus === 'blocked' ? 1 : 0,
                    'result_summary_json' => $this->json($sanitized),
                    'finished_at' => $now,
                    'update_time' => $now,
                ]);
            $readback = Db::name('manual_notification_schedule_runs')
                ->where('id', $runId)
                ->find();
            $decoded = is_array($readback)
                ? json_decode((string)($readback['result_summary_json'] ?? ''), true)
                : null;
            if (!is_array($readback)
                || (string)($readback['runner_mode'] ?? '') !== self::RUNNER_MODE
                || (string)($readback['status'] ?? '') !== $status
                || (int)($readback['dispatch_requested'] ?? 1) !== 0
                || (int)($readback['sent_count'] ?? 1) !== 0
                || ($readback['scope_robot_id'] ?? null) !== null
                || !is_array($decoded)
                || (string)($decoded['preview_status'] ?? '') !== $previewStatus
                || ($decoded['message_sent'] ?? null) !== false
                || ($decoded['webhook_read'] ?? null) !== false
            ) {
                throw new \RuntimeException('collection_preview_run_readback_failed');
            }

            return [
                'run_id' => $runId,
                'runner_mode' => self::RUNNER_MODE,
                'status' => $status,
                'preview_status' => $previewStatus,
                'dispatch_requested' => false,
                'scope_hotel_id' => (int)$readback['scope_hotel_id'],
                'scope_robot_id' => null,
                'sent_count' => 0,
                'failed_count' => (int)$readback['failed_count'],
                'blocked_count' => (int)$readback['blocked_count'],
                'message_sent' => false,
                'webhook_read' => false,
                'finished_at' => (string)$readback['finished_at'],
            ];
        });
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function sanitizeSummary(array $summary): array
    {
        $allowed = [
            'stage',
            'reason_code',
            'business_date',
            'capture_id',
            'operating_target_record_id',
            'operating_target_revision_no',
            'operating_target_status',
            'collection_status',
            'digest_status',
            'preview_status',
            'source_gate_passed',
            'pms_status',
            'ctrip_status',
            'meituan_status',
            'pms_evidence_ready',
            'ctrip_evidence_ready',
            'meituan_evidence_ready',
            'pms_capture_ids',
            'pms_captured_at',
            'ctrip_row_ids',
            'ctrip_data_source_ids',
            'ctrip_source_trace_ids',
            'ctrip_collected_at',
            'meituan_row_ids',
            'meituan_data_source_ids',
            'meituan_source_trace_ids',
            'meituan_traffic_collected_at',
            'meituan_order_collected_at',
            'digest_contract_version',
            'brief_contract_version',
            'preview_fingerprint',
            'gap_codes',
            'blocker_codes',
        ];
        $result = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $summary)) {
                continue;
            }
            $value = $summary[$key];
            if (is_bool($value) || is_int($value) || $value === null) {
                $result[$key] = $value;
                continue;
            }
            if (is_array($value)) {
                $result[$key] = array_values(array_slice(array_filter(array_map(
                    function (mixed $item): int|string|null {
                        if (is_int($item) && $item > 0) {
                            return $item;
                        }
                        if (is_scalar($item)) {
                            $safe = $this->safeText($item, 160);
                            return $safe !== '' ? $safe : null;
                        }
                        return null;
                    },
                    $value
                ), static fn(mixed $item): bool => $item !== null), 0, 40));
                continue;
            }
            $result[$key] = $this->safeText($value, 180);
        }

        return $result;
    }

    private function safeText(mixed $value, int $limit): string
    {
        $value = preg_replace(
            '/(key|token|secret|cookie|password|authorization|webhook)\s*[=:]\s*[^\s,;]+/iu',
            '$1=<redacted>',
            trim((string)$value)
        ) ?? '';

        return mb_strcut($value, 0, $limit, 'UTF-8');
    }

    private function dateTime(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d H:i:s');
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
            throw new \RuntimeException('collection_preview_run_table_missing');
        }
    }
}
