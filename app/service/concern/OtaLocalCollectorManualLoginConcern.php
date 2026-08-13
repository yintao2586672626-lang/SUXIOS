<?php
declare(strict_types=1);

namespace app\service\concern;

use think\facade\Db;
use Throwable;

trait OtaLocalCollectorManualLoginConcern
{
    /**
     * Recover the target-date request parked on the latest manual session
     * probe. Scheduled probes carry their own dispatcher-fenced recovery and
     * are never adopted here. Every identity edge is matched before the
     * request can be copied to an interactive login task.
     *
     * @return array{probe_task_id:int,resume_collections:array<int,array<string,mixed>>}|null
     */
    private function manualLoginResumeContext(
        int $tenantId,
        int $userId,
        int $deviceId,
        int $accountId,
        int $hotelId,
        string $platform,
        int $beforeTaskId = PHP_INT_MAX
    ): ?array {
        $platform = strtolower(trim($platform));
        if ($tenantId <= 0 || $userId <= 0 || $deviceId <= 0 || $accountId <= 0
            || $hotelId <= 0 || !in_array($platform, self::PLATFORMS, true)
        ) {
            return null;
        }

        $probeQuery = Db::name('ota_local_collector_tasks')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('account_id', $accountId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('task_type', 'session_probe')
            ->whereIn('status', ['login_required', 'verification_required'])
            ->whereNotNull('finished_at');
        if ($beforeTaskId > 0 && $beforeTaskId < PHP_INT_MAX) {
            $probeQuery->where('id', '<', $beforeTaskId);
        }
        $probe = $probeQuery->order('id', 'desc')->find();
        if (!is_array($probe) || $this->taskIdentity($probe) === null) {
            return null;
        }

        $probeId = (int)$probe['id'];
        $probeRequest = $this->decodeJson($probe['request_json'] ?? null);
        if ($this->normalizeDispatcherRunId((string)($probeRequest['dispatcher_run_id'] ?? '')) !== '') {
            return null;
        }
        $resumeCollections = is_array($probeRequest['resume_collections'] ?? null)
            ? array_values($probeRequest['resume_collections'])
            : [];
        if ($resumeCollections === [] || count($resumeCollections) > 20) {
            return null;
        }

        $completedLoginQuery = Db::name('ota_local_collector_tasks')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('account_id', $accountId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('task_type', 'login')
            ->where('status', 'success')
            ->where('id', '>', $probeId);
        if ($beforeTaskId > 0 && $beforeTaskId < PHP_INT_MAX) {
            $completedLoginQuery->where('id', '<', $beforeTaskId);
        }
        if ($completedLoginQuery->count() > 0) {
            return null;
        }

        $canonical = [];
        try {
            foreach ($resumeCollections as $resume) {
                if (!is_array($resume)) {
                    return null;
                }
                $taskType = strtolower(trim((string)($resume['task_type'] ?? '')));
                $resumeHotelId = $this->strictPositiveInt($resume['system_hotel_id'] ?? null);
                $resumeAccountId = $this->strictPositiveInt($resume['account_id'] ?? null);
                $resumeDeviceId = $this->strictPositiveInt($resume['device_id'] ?? null);
                $resumeDate = $this->normalizeDate((string)($resume['data_date'] ?? ''));
                $resumeRequest = is_array($resume['request'] ?? null) ? $resume['request'] : [];
                $ordered = is_array($resumeRequest['ordered_collection'] ?? null)
                    ? $resumeRequest['ordered_collection']
                    : [];
                if (!in_array($taskType, ['collect', 'backfill'], true)
                    || $resumeHotelId !== $hotelId
                    || ($resumeAccountId > 0 && $resumeAccountId !== $accountId)
                    || ($resumeDeviceId > 0 && $resumeDeviceId !== $deviceId)
                    || $resumeDate === ''
                    || $ordered === []
                    || $this->normalizeDate((string)($ordered['target_date'] ?? '')) !== $resumeDate
                    || $this->normalizeDispatcherRunId((string)($resumeRequest['dispatcher_run_id'] ?? '')) !== ''
                ) {
                    return null;
                }
                $this->assertNoSensitiveMaterial($resumeRequest, 'manual_login_resume');

                $existingCollectionQuery = Db::name('ota_local_collector_tasks')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $userId)
                    ->where('device_id', $deviceId)
                    ->where('account_id', $accountId)
                    ->where('system_hotel_id', $hotelId)
                    ->where('platform', $platform)
                    ->whereIn('task_type', ['collect', 'backfill'])
                    ->where('data_date', $resumeDate)
                    ->where('id', '>', $probeId);
                if ($beforeTaskId > 0 && $beforeTaskId < PHP_INT_MAX) {
                    $existingCollectionQuery->where('id', '<', $beforeTaskId);
                }
                if ($existingCollectionQuery->count() > 0) {
                    return null;
                }

                $canonical[] = [
                    'task_type' => $taskType,
                    'account_id' => $accountId,
                    'device_id' => $deviceId,
                    'system_hotel_id' => $hotelId,
                    'data_date' => $resumeDate,
                    'data_type' => $this->safeIdentifier((string)($resume['data_type'] ?? 'business'), 50)
                        ?: 'business',
                    'priority' => max(1, min(100, (int)($resume['priority'] ?? 50))),
                    'request' => $resumeRequest,
                ];
            }
        } catch (Throwable) {
            return null;
        }

        return $canonical === [] ? null : [
            'probe_task_id' => $probeId,
            'resume_collections' => $canonical,
        ];
    }
}
