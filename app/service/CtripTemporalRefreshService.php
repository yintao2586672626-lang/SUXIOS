<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

/**
 * Refreshes the minimum Ctrip temporal flows before a real scheduled send.
 *
 * Profile work is serialized: historical and future are collected at most
 * once per business day when absent, then the current snapshot is collected.
 */
final class CtripTemporalRefreshService
{
    private const TIMEZONE = 'Asia/Shanghai';
    private const CURRENT_OBSERVATION_MAX_AGE_SECONDS = 300;

    /** @var callable|null */
    private $syncRunner;

    /** @var callable|null */
    private $sourceLoader;

    /** @var callable|null */
    private $dailyFlowAttemptLoader;

    public function __construct(
        ?callable $syncRunner = null,
        ?callable $sourceLoader = null,
        private readonly ?CtripTemporalNotificationPayloadService $payloads = null,
        ?callable $dailyFlowAttemptLoader = null
    ) {
        $this->syncRunner = $syncRunner;
        $this->sourceLoader = $sourceLoader;
        $this->dailyFlowAttemptLoader = $dailyFlowAttemptLoader;
    }

    /** @return array<string, mixed> */
    public function refresh(
        mixed $actor,
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        DateTimeImmutable $observedAt
    ): array {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $observedAt = $observedAt->setTimezone($timezone);
        $liveNow = new DateTimeImmutable('now', $timezone);
        if ($businessDate !== $liveNow->format('Y-m-d')
            || abs($liveNow->getTimestamp() - $observedAt->getTimestamp())
                >= self::CURRENT_OBSERVATION_MAX_AGE_SECONDS
        ) {
            return $this->blocked('ctrip_dispatch_observation_not_current');
        }
        $actorId = is_object($actor) ? (int)($actor->id ?? 0) : 0;
        if ($tenantId <= 0 || $hotelId <= 0 || $actorId <= 0) {
            return $this->blocked('ctrip_schedule_actor_scope_missing');
        }

        $source = $this->loadSource($tenantId, $hotelId);
        $sourceId = (int)($source['id'] ?? 0);
        if ($sourceId <= 0) {
            return $this->blocked('ctrip_profile_source_missing');
        }

        try {
            $before = $this->payloadService()->broadcastPreview(
                $tenantId,
                $hotelId,
                $hotelName,
                $businessDate
            );
        } catch (\Throwable) {
            return $this->blocked('ctrip_temporal_preview_read_failed');
        }
        $segments = is_array($before['segments'] ?? null)
            ? $before['segments']
            : [];
        try {
            $attemptedDailyFlows = $this->dailyAttemptedFlows(
                $sourceId,
                $hotelId,
                $businessDate
            );
        } catch (\Throwable) {
            return $this->blocked('ctrip_daily_flow_attempts_read_failed');
        }
        $flows = [];
        $flowResults = [];
        foreach ([
            'past' => 'historical_review',
            'future' => 'future_demand',
        ] as $segmentName => $flow) {
            if (!$this->segmentNeedsDailyRefresh(
                (array)($segments[$segmentName] ?? []),
                $businessDate
            )) {
                continue;
            }
            if (in_array($flow, $attemptedDailyFlows, true)) {
                $flowResults[] = [
                    'flow' => $flow,
                    'status' => 'skipped',
                    'task_id' => 0,
                    'saved_count' => 0,
                    'readback_verified' => false,
                    'reason_code' => 'ctrip_daily_flow_already_attempted',
                ];
                continue;
            }
            $flows[] = $flow;
        }
        $flows[] = 'realtime';

        $workflow = new CtripCollectorWorkflowService();
        $savedCount = 0;
        $realtimeTaskId = 0;
        foreach ($flows as $flow) {
            $flowDate = $flow === 'historical_review'
                ? $liveNow->modify('-1 day')->format('Y-m-d')
                : $businessDate;
            $options = $workflow->applyFlowOptions([
                'collector_flow' => $flow,
                'data_date' => $flowDate,
                'trigger_type' => 'manual_notification_schedule',
                'interactive_browser' => false,
                'snapshot_time' => $liveNow->format('Y-m-d H:i:s'),
                'ctrip_section_concurrency' => 1,
            ]);
            try {
                $result = $this->runSync($actor, $sourceId, $options);
            } catch (\Throwable) {
                $result = [
                    'status' => 'failed',
                    'message' => 'ctrip_temporal_sync_failed',
                    'saved_count' => 0,
                    'readback_verified' => false,
                ];
            }
            $result = is_array($result) ? $result : [];
            $ready = in_array(
                strtolower(trim((string)($result['status'] ?? ''))),
                ['success', 'partial_success', 'completed', 'partial'],
                true
            )
                && ($result['readback_verified'] ?? false) === true
                && (int)($result['saved_count'] ?? 0) > 0;
            $flowResults[] = [
                'flow' => $flow,
                'status' => $ready ? 'ready' : 'blocked',
                'task_id' => (int)($result['task_id'] ?? 0),
                'saved_count' => (int)($result['saved_count'] ?? 0),
                'readback_verified' => ($result['readback_verified'] ?? false) === true,
                'reason_code' => $ready
                    ? 'ctrip_flow_saved_and_read_back'
                    : 'ctrip_flow_readback_missing',
            ];
            if ($ready) {
                $savedCount += (int)$result['saved_count'];
            }
            if ($flow === 'realtime') {
                if (!$ready) {
                    return $this->blocked(
                        'ctrip_current_capture_readback_missing',
                        $flowResults
                    );
                }
                $realtimeTaskId = (int)($result['task_id'] ?? 0);
            }
        }

        $candidate = $this->payloadService()->pagePreview(
            $tenantId,
            $hotelId,
            $hotelName,
            $businessDate
        );
        $present = is_array(
            $candidate['business_preview']['segments']['present'] ?? null
        )
            ? $candidate['business_preview']['segments']['present']
            : [];
        if (($candidate['status'] ?? '') !== 'ready'
            || $realtimeTaskId <= 0
            || (string)($present['batch_id'] ?? '') !== (string)$realtimeTaskId
        ) {
            return $this->blocked(
                'ctrip_current_capture_batch_not_verified',
                $flowResults
            );
        }

        return [
            'status' => 'ready',
            'reason_code' => 'ctrip_current_capture_saved_and_read_back',
            'data_scope' => 'ctrip_ota_channel',
            'target_date' => $businessDate,
            'captured_at' => (string)($present['captured_at'] ?? ''),
            'sync_task_id' => $realtimeTaskId,
            'saved_count' => $savedCount,
            'readback_verified' => true,
            'flows' => $flowResults,
        ];
    }

    /** @return array<string, mixed> */
    private function loadSource(int $tenantId, int $hotelId): array
    {
        if ($this->sourceLoader !== null) {
            $source = call_user_func($this->sourceLoader, $tenantId, $hotelId);
            return is_array($source) ? $source : [];
        }
        $source = Db::name('platform_data_sources')
            ->withoutField('secret_json')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', 'ctrip')
            ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
            ->where('enabled', 1)
            ->order('id', 'desc')
            ->find();
        return is_array($source) ? $source : [];
    }

    /**
     * @return array<int, string>
     */
    private function dailyAttemptedFlows(
        int $sourceId,
        int $hotelId,
        string $businessDate
    ): array {
        if ($this->dailyFlowAttemptLoader !== null) {
            $loaded = call_user_func(
                $this->dailyFlowAttemptLoader,
                $sourceId,
                $hotelId,
                $businessDate
            );
            if (!is_array($loaded)) {
                throw new \RuntimeException(
                    'ctrip_daily_flow_attempt_loader_invalid'
                );
            }
            return $this->normalizeDailyFlows($loaded);
        }

        $rows = Db::name('platform_data_sync_tasks')
            ->where('data_source_id', $sourceId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', 'ctrip')
            ->where('trigger_type', 'manual_notification_schedule')
            ->whereBetween('create_time', [
                $businessDate . ' 00:00:00',
                $businessDate . ' 23:59:59',
            ])
            ->field('stats_json')
            ->select()
            ->toArray();

        $flows = [];
        foreach ($rows as $row) {
            $stats = json_decode((string)($row['stats_json'] ?? ''), true);
            if (!is_array($stats)
                || !array_key_exists('collector_flow', $stats)
            ) {
                throw new \RuntimeException(
                    'ctrip_daily_flow_attempt_row_invalid'
                );
            }
            $flows[] = $stats['collector_flow'] ?? '';
        }
        return $this->normalizeDailyFlows($flows);
    }

    /** @return array<int, string> */
    private function normalizeDailyFlows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $workflow = new CtripCollectorWorkflowService();
        $flows = [];
        foreach ($value as $flow) {
            $normalized = $workflow->normalizeFlow($flow);
            if ($normalized === '') {
                throw new \RuntimeException(
                    'ctrip_daily_flow_attempt_value_invalid'
                );
            }
            if (in_array($normalized, ['historical_review', 'future_demand'], true)) {
                $flows[] = $normalized;
            }
        }
        return array_values(array_unique($flows));
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function runSync(mixed $actor, int $sourceId, array $options): array
    {
        if ($this->syncRunner !== null) {
            $result = call_user_func($this->syncRunner, $actor, $sourceId, $options);
            return is_array($result) ? $result : [];
        }
        return (new PlatformDataSyncService())->syncDataSource(
            $actor,
            $sourceId,
            $options
        );
    }

    /** @param array<string, mixed> $segment */
    private function segmentNeedsDailyRefresh(array $segment, string $businessDate): bool
    {
        if (($segment['status'] ?? 'blocked') === 'blocked') {
            return true;
        }
        $capturedAt = trim((string)($segment['captured_at'] ?? ''));
        return !str_starts_with($capturedAt, $businessDate . ' ');
    }

    private function payloadService(): CtripTemporalNotificationPayloadService
    {
        return $this->payloads ?? new CtripTemporalNotificationPayloadService();
    }

    /**
     * @param array<int, array<string, mixed>> $flows
     * @return array<string, mixed>
     */
    private function blocked(string $reasonCode, array $flows = []): array
    {
        return [
            'status' => 'blocked',
            'reason_code' => $reasonCode,
            'saved_count' => array_sum(array_map(
                static fn(array $flow): int => (int)($flow['saved_count'] ?? 0),
                $flows
            )),
            'readback_verified' => false,
            'flows' => $flows,
        ];
    }
}
