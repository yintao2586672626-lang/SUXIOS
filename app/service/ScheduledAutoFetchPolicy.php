<?php
declare(strict_types=1);

namespace app\service;

final class ScheduledAutoFetchPolicy
{
    /**
     * Keep every currently usable Profile source. When a platform has no
     * usable source because the previous attempt changed it to a degraded
     * state, retain one deterministic source so the bounded retry policy can
     * actually retry it. Duplicate degraded sources are not fanned out.
     *
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    public function retryableProfileSources(array $sources): array
    {
        $activeStatuses = ['ready', 'success', 'partial_success'];
        $degradedStatuses = ['failed', 'waiting_config'];
        $active = [];
        $activePlatforms = [];
        $degradedByPlatform = [];

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $platform = strtolower(trim((string)($source['platform'] ?? '')));
            $status = strtolower(trim((string)($source['status'] ?? '')));
            if (!in_array($platform, ['ctrip', 'meituan'], true)) {
                continue;
            }
            if (in_array($status, $activeStatuses, true)) {
                $active[] = $source;
                $activePlatforms[$platform] = true;
                continue;
            }
            if (!in_array($status, $degradedStatuses, true)) {
                continue;
            }
            $current = $degradedByPlatform[$platform] ?? null;
            if (!is_array($current) || $this->profileSourceIsNewer($source, $current)) {
                $degradedByPlatform[$platform] = $source;
            }
        }

        foreach ($degradedByPlatform as $platform => $source) {
            if (!isset($activePlatforms[$platform])) {
                $active[] = $source;
            }
        }

        return array_values($active);
    }

    /**
     * Build a bounded catch-up window without requiring the dispatcher to hit
     * the configured minute exactly.
     *
     * @return array<int, array{slot_id: string, period: string, data_date: string, executed_key: string, retry_key: string, label: string, executed_message: string, target_platforms?: array<int, string>}>
     */
    public function dueRuns(int $hotelId, array $status, \DateTimeImmutable $now): array
    {
        $historicalTime = $this->normalizeTime((string)($status['historical_schedule_time'] ?? $status['schedule_time'] ?? '10:00')) ?? '10:00';
        $realtimeMinute = $this->normalizeMinute($status['realtime_schedule_minute'] ?? $status['schedule_minute'] ?? 5) ?? 5;
        $realtimeIntervalHours = $this->normalizeIntervalHours($status['realtime_schedule_interval_hours'] ?? $status['realtime_interval_hours'] ?? $status['schedule_interval_hours'] ?? 2);
        $historicalEnabled = array_key_exists('historical_enabled', $status) ? $this->truthy($status['historical_enabled']) : true;
        $realtimeEnabled = array_key_exists('realtime_enabled', $status) ? $this->truthy($status['realtime_enabled']) : true;
        $today = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $currentHour = (int)$now->format('H');
        $currentMinute = (int)$now->format('i');
        $runsBySlot = [];

        foreach (array_reverse((array)($status['failed_records'] ?? [])) as $failedRecord) {
            $pendingRun = $this->pendingRunFromFailure(is_array($failedRecord) ? $failedRecord : [], $hotelId, $now);
            if ($pendingRun !== null) {
                $runsBySlot[$pendingRun['slot_id']] = $pendingRun;
            }
            if (count($runsBySlot) >= 2) {
                break;
            }
        }

        if ($historicalEnabled && $now->format('H:i') >= $historicalTime) {
            $run = [
                'slot_id' => "historical:{$yesterday}",
                'period' => 'historical_daily',
                'data_date' => $yesterday,
                'executed_key' => "online_data_historical_executed_{$hotelId}_{$yesterday}",
                'retry_key' => "online_data_historical_retry_{$hotelId}_{$yesterday}",
                'label' => 'historical',
                'executed_message' => '历史固定数据今天已执行',
            ];
            if (!empty($runsBySlot[$run['slot_id']]['target_platforms'])) {
                $run['target_platforms'] = $runsBySlot[$run['slot_id']]['target_platforms'];
            }
            $runsBySlot[$run['slot_id']] = $run;
        }
        if ($realtimeEnabled
            && $currentMinute >= $realtimeMinute
            && $currentHour % $realtimeIntervalHours === 0
        ) {
            $run = [
                'slot_id' => "realtime:{$today}:{$currentHour}",
                'period' => 'realtime_snapshot',
                'data_date' => $today,
                'executed_key' => "online_data_realtime_executed_{$hotelId}_{$today}_{$currentHour}",
                'retry_key' => "online_data_realtime_retry_{$hotelId}_{$today}_{$currentHour}",
                'label' => 'realtime',
                'executed_message' => "实时快照本 {$realtimeIntervalHours} 小时窗口已执行",
            ];
            if (!empty($runsBySlot[$run['slot_id']]['target_platforms'])) {
                $run['target_platforms'] = $runsBySlot[$run['slot_id']]['target_platforms'];
            }
            $runsBySlot[$run['slot_id']] = $run;
        }

        return array_values($runsBySlot);
    }

    /**
     * @return array{complete: bool, status: string, saved_count: int, failed_platforms: array<int, string>, successful_platforms: array<int, string>}
     */
    public function classifyOutcome(array $result): array
    {
        $savedCount = max(0, (int)($result['saved_count'] ?? 0));
        $failedPlatforms = $this->platformList($result['failed_platforms'] ?? []);
        $successfulPlatforms = $this->platformList($result['successful_platforms'] ?? []);
        foreach ((array)($result['platform_results'] ?? []) as $platformResult) {
            if (!is_array($platformResult) || !empty($platformResult['skipped'])) {
                continue;
            }
            $platform = strtolower(trim((string)($platformResult['platform'] ?? '')));
            if (!in_array($platform, ['ctrip', 'meituan'], true)) {
                continue;
            }
            if (!empty($platformResult['success']) && (int)($platformResult['saved_count'] ?? 0) > 0) {
                $successfulPlatforms[] = $platform;
            } else {
                $failedPlatforms[] = $platform;
            }
        }
        $failedPlatforms = array_values(array_unique($failedPlatforms));
        $successfulPlatforms = array_values(array_unique($successfulPlatforms));
        $successfulPlatforms = array_values(array_diff($successfulPlatforms, $failedPlatforms));
        $producerSucceeded = !empty($result['success']);
        $complete = $producerSucceeded && $savedCount > 0 && $failedPlatforms === [];
        $partial = !$complete && ($savedCount > 0 || $successfulPlatforms !== []);

        return [
            'complete' => $complete,
            'status' => $complete ? 'success' : ($partial ? 'partial_success' : 'failed'),
            'saved_count' => $savedCount,
            'failed_platforms' => $failedPlatforms,
            'successful_platforms' => $successfulPlatforms,
        ];
    }

    public function normalizeMaxAttempts(mixed $value): int
    {
        return is_numeric($value) ? max(1, min(10, (int)$value)) : 3;
    }

    public function normalizeDelayMinutes(mixed $value): int
    {
        return is_numeric($value) ? max(1, min(60, (int)$value)) : 5;
    }

    /** @return array<int, string> */
    public function normalizePlatforms(mixed $platforms): array
    {
        return $this->platformList($platforms);
    }

    public function retryDue(array $state, int $maxAttempts, \DateTimeImmutable $now): bool
    {
        $maxAttempts = $this->normalizeMaxAttempts($maxAttempts);
        if ((int)($state['attempts'] ?? 0) >= $maxAttempts) {
            return false;
        }
        $nextRetryAt = trim((string)($state['next_retry_at'] ?? ''));
        if ($nextRetryAt === '') {
            return true;
        }
        try {
            $nextRetry = new \DateTimeImmutable($nextRetryAt, new \DateTimeZone('Asia/Shanghai'));
        } catch (\Throwable) {
            return true;
        }
        return $nextRetry <= $now;
    }

    /** @return array{attempts: int, max_attempts: int, next_retry_at: ?string, retry_exhausted: bool, last_status: string, last_message: string} */
    public function nextRetryState(
        array $currentState,
        int $maxAttempts,
        int $baseDelayMinutes,
        \DateTimeImmutable $now,
        string $status,
        string $message
    ): array {
        $maxAttempts = $this->normalizeMaxAttempts($maxAttempts);
        $baseDelayMinutes = $this->normalizeDelayMinutes($baseDelayMinutes);
        $attempts = max(0, (int)($currentState['attempts'] ?? 0)) + 1;
        $retryExhausted = $attempts >= $maxAttempts;
        $delayMultiplier = 2 ** min(6, max(0, $attempts - 1));
        $delayMinutes = min(60, $baseDelayMinutes * $delayMultiplier);

        return [
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'next_retry_at' => $retryExhausted ? null : $now->modify("+{$delayMinutes} minutes")->format('Y-m-d H:i:s'),
            'retry_exhausted' => $retryExhausted,
            'last_status' => trim($status),
            'last_message' => mb_substr(trim($message), 0, 300),
        ];
    }

    private function normalizeTime(string $value): ?string
    {
        if (!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', trim($value), $matches)) {
            return null;
        }
        return sprintf('%02d:%02d', (int)$matches[1], (int)$matches[2]);
    }

    private function normalizeMinute(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $minute = (int)$value;
        return $minute >= 0 && $minute <= 59 ? $minute : null;
    }

    private function normalizeIntervalHours(mixed $value): int
    {
        return is_numeric($value) ? max(1, min(24, (int)$value)) : 2;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return !empty($value);
    }

    /** @return array{slot_id: string, period: string, data_date: string, executed_key: string, retry_key: string, label: string, executed_message: string, target_platforms?: array<int, string>}|null */
    private function pendingRunFromFailure(array $record, int $hotelId, \DateTimeImmutable $now): ?array
    {
        if (!empty($record['retry_exhausted'])) {
            return null;
        }
        $slotId = trim((string)($record['slot_id'] ?? ''));
        $dataDate = trim((string)($record['data_date'] ?? ''));
        $targetPlatforms = $this->platformList($record['failed_platforms'] ?? []);
        if (preg_match('/^historical:(\d{4}-\d{2}-\d{2})$/D', $slotId, $matches) === 1
            && $matches[1] === $dataDate
        ) {
            try {
                $slotDate = new \DateTimeImmutable($dataDate . ' 00:00:00', new \DateTimeZone('Asia/Shanghai'));
            } catch (\Throwable) {
                return null;
            }
            $ageSeconds = $now->getTimestamp() - $slotDate->getTimestamp();
            if ($ageSeconds < 0 || $ageSeconds > 7 * 86400) {
                return null;
            }
            $run = [
                'slot_id' => $slotId,
                'period' => 'historical_daily',
                'data_date' => $dataDate,
                'executed_key' => "online_data_historical_executed_{$hotelId}_{$dataDate}",
                'retry_key' => "online_data_historical_retry_{$hotelId}_{$dataDate}",
                'label' => 'historical-retry',
                'executed_message' => '历史补跑窗口已执行',
            ];
            if ($targetPlatforms !== []) {
                $run['target_platforms'] = $targetPlatforms;
            }
            return $run;
        }
        if (preg_match('/^realtime:(\d{4}-\d{2}-\d{2}):(\d{1,2})$/D', $slotId, $matches) === 1
            && $matches[1] === $dataDate
            && (int)$matches[2] >= 0
            && (int)$matches[2] <= 23
        ) {
            $slotHour = (int)$matches[2];
            try {
                $slotTime = new \DateTimeImmutable(sprintf('%s %02d:00:00', $dataDate, $slotHour), new \DateTimeZone('Asia/Shanghai'));
            } catch (\Throwable) {
                return null;
            }
            $ageSeconds = $now->getTimestamp() - $slotTime->getTimestamp();
            if ($ageSeconds < 0 || $ageSeconds > 6 * 3600) {
                return null;
            }
            $run = [
                'slot_id' => $slotId,
                'period' => 'realtime_snapshot',
                'data_date' => $dataDate,
                'executed_key' => "online_data_realtime_executed_{$hotelId}_{$dataDate}_{$slotHour}",
                'retry_key' => "online_data_realtime_retry_{$hotelId}_{$dataDate}_{$slotHour}",
                'label' => 'realtime-retry',
                'executed_message' => '实时快照补跑窗口已执行',
            ];
            if ($targetPlatforms !== []) {
                $run['target_platforms'] = $targetPlatforms;
            }
            return $run;
        }
        return null;
    }

    /** @return array<int, string> */
    private function platformList(mixed $platforms): array
    {
        if (!is_array($platforms)) {
            return [];
        }
        $normalized = [];
        foreach ($platforms as $platform) {
            $platform = strtolower(trim((string)$platform));
            if (in_array($platform, ['ctrip', 'meituan'], true)) {
                $normalized[$platform] = true;
            }
        }
        return array_keys($normalized);
    }

    /** @param array<string, mixed> $candidate @param array<string, mixed> $current */
    private function profileSourceIsNewer(array $candidate, array $current): bool
    {
        $candidateTime = trim((string)($candidate['last_sync_time'] ?? ''));
        $currentTime = trim((string)($current['last_sync_time'] ?? ''));
        if ($candidateTime !== $currentTime) {
            return $candidateTime > $currentTime;
        }
        return (int)($candidate['id'] ?? PHP_INT_MAX) < (int)($current['id'] ?? PHP_INT_MAX);
    }
}
