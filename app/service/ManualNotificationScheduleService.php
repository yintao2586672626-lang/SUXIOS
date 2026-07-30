<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

/**
 * Cloud-scheduler entry for saved notifications.
 *
 * Scheduled operating-target reports always resolve the exact current
 * Asia/Shanghai business date and must pass the same data gate as an immediate
 * test. A dispatch is claimed before any external side effect.
 */
final class ManualNotificationScheduleService
{
    public const MODE_TEST = 'test';
    public const MODE_FORMAL = 'formal';
    private const TIMEZONE = 'Asia/Shanghai';
    private const DUE_GRACE_SECONDS = 300;

    /** @var callable|null */
    private $sender;

    /** @var callable|null */
    private $meituanTemporalRefresher;

    /** @var callable|null */
    private $ctripTemporalRefresher;

    /** @var array<string, array<string, mixed>> */
    private array $meituanPreparationCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $ctripPreparationCache = [];

    public function __construct(
        ?callable $sender = null,
        private readonly ?OperatingTargetNotificationPayloadService $operatingTargetPayloads = null,
        private readonly ?ManualNotificationDispatchLedgerService $ledger = null,
        private readonly ?WechatRobotDeliveryService $deliveries = null,
        private readonly ?ManualNotificationScheduleRuleService $scheduleRuleService = null,
        private readonly ?OperatingDailyReportPayloadService $operatingDailyPayloads = null,
        private readonly ?ManualNotificationBusinessPayloadService $businessMessagePayloads = null,
        ?callable $meituanTemporalRefresher = null,
        private readonly ?CtripTemporalNotificationPayloadService $ctripTemporalPayloads = null,
        ?callable $ctripTemporalRefresher = null
    ) {
        $this->sender = $sender;
        $this->meituanTemporalRefresher = $meituanTemporalRefresher;
        $this->ctripTemporalRefresher = $ctripTemporalRefresher;
    }

    /** @return array<string, mixed> */
    public function runDue(
        DateTimeImmutable $observedAt,
        bool $dispatch = false,
        string $mode = self::MODE_TEST,
        int $limit = 100,
        int $scopeHotelId = 0,
        int $scopeRobotId = 0
    ): array {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, [self::MODE_TEST, self::MODE_FORMAL], true)) {
            throw new \InvalidArgumentException('manual_notification_schedule_mode_invalid');
        }
        $now = $observedAt->setTimezone(new DateTimeZone(self::TIMEZONE));
        $limit = max(1, min(500, $limit));
        $scopeHotelId = max(0, $scopeHotelId);
        $scopeRobotId = max(0, $scopeRobotId);
        if ($scopeRobotId > 0 && $scopeHotelId <= 0) {
            throw new \InvalidArgumentException('manual_notification_schedule_scope_pair_required');
        }
        $runId = $this->startRun($mode, $dispatch, $scopeHotelId, $scopeRobotId, $now);

        try {
            $recoveredUnknownCount = $dispatch
                ? $this->dispatchLedger()->recoverExpiredSending(
                    $now,
                    $mode,
                    $scopeHotelId
                )
                : 0;
            $query = Db::name('manual_notifications')
                ->where('enabled', 1)
                ->where('schedule_status', 'schedule_enabled')
                ->where('send_method', $mode === self::MODE_FORMAL ? 'wecom_formal' : 'wecom_test')
                ->whereIn(
                    'trigger_type',
                    ['daily_fixed_time', 'hourly_on_the_hour', 'interval_minutes']
                )
                ->order('id', 'asc');
            if ($scopeHotelId > 0) {
                $query->where('hotel_id', $scopeHotelId);
            }
            if ($scopeRobotId > 0) {
                $query->where('test_robot_id', $scopeRobotId);
            }
            $rows = $query->select()->toArray();

            $results = [];
            $dueCount = 0;
            $sentCount = 0;
            $failedCount = 0;
            $blockedCount = 0;
            foreach ($rows as $row) {
                $window = $this->scheduleRules()->dueWindow(
                    $row,
                    $now,
                    self::DUE_GRACE_SECONDS
                );
                if ($window === null) {
                    continue;
                }
                if ($dueCount >= $limit) {
                    break;
                }
                $dueCount++;
                $result = $this->processDueRecord(
                    $row,
                    $window,
                    $now,
                    $dispatch,
                    $mode,
                    $scopeRobotId,
                    $runId
                );
                $status = (string)($result['status'] ?? '');
                if ($status === 'sent') {
                    $sentCount++;
                } elseif (in_array($status, ['failed', 'outcome_unknown'], true)) {
                    $failedCount++;
                } elseif ($status === 'blocked') {
                    $blockedCount++;
                }
                $results[] = $result;
            }

            $runStatus = $failedCount > 0 || $recoveredUnknownCount > 0
                ? 'failed'
                : ($blockedCount > 0 ? 'blocked' : 'completed');
            $summary = [
                'status' => !$dispatch
                    ? 'preview'
                    : ($runStatus === 'completed'
                        ? 'dispatch_checked'
                        : ($runStatus === 'failed' ? 'dispatch_failed' : 'dispatch_blocked')),
                'mode' => $mode,
                'dispatch_requested' => $dispatch,
                'timezone' => self::TIMEZONE,
                'observed_at' => $now->format('Y-m-d H:i:s'),
                'candidate_count' => count($rows),
                'due_count' => $dueCount,
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'blocked_count' => $blockedCount,
                'recovered_unknown_count' => $recoveredUnknownCount,
                'schedule_run_id' => $runId,
                'results' => $results,
            ];
            $this->recordScopeObservations($runId, $rows, $results, $mode, $dispatch, $now);
            $this->finishRun($runId, $runStatus, $summary, $now);
            return $summary;
        } catch (\Throwable $exception) {
            $this->finishRun($runId, 'failed', [
                'error_code' => 'manual_notification_schedule_failed',
                'error_message' => $this->safeText($exception->getMessage(), 180),
            ], $now);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function processDueRecord(
        array $row,
        string $window,
        DateTimeImmutable $now,
        bool $dispatch,
        string $mode,
        int $scopeRobotId,
        ?int $scheduleRunId
    ): array {
        $notificationId = (int)($row['id'] ?? 0);
        $hotelId = (int)($row['hotel_id'] ?? 0);
        $tenantId = (int)($row['tenant_id'] ?? 0);
        $businessDate = $this->scheduleRules()->resolveBusinessDate($row, $now);
        $base = [
            'notification_id' => $notificationId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'trigger_type' => (string)($row['trigger_type'] ?? ''),
            'dispatch_window' => $window,
            'mode' => $mode,
            'schedule_run_id' => $scheduleRunId,
        ];

        $identity = $this->resolveTargetIdentity($row, $mode);
        if (($identity['eligible'] ?? false) === true
            && $scopeRobotId > 0
            && (int)($identity['robot_id'] ?? 0) !== $scopeRobotId
        ) {
            $identity = [
                'eligible' => false,
                'reason_code' => 'scoped_robot_identity_mismatch',
            ];
        }
        $sourcePreparation = ($identity['eligible'] ?? false) === true
            ? $this->prepareMeituanSource($row, $businessDate, $now, $dispatch)
            : [
                'status' => 'skipped',
                'reason_code' => 'target_identity_not_ready',
            ];
        $base['source_preparation'] = $sourcePreparation;
        $ctripPreparation = ($identity['eligible'] ?? false) === true
            ? $this->prepareCtripSource($row, $businessDate, $now, $dispatch)
            : [
                'status' => 'skipped',
                'reason_code' => 'target_identity_not_ready',
            ];
        $base['ctrip_source_preparation'] = $ctripPreparation;
        $candidate = $this->deliveryCandidate(
            $row,
            $businessDate,
            $mode
        );
        if (($sourcePreparation['status'] ?? '') === 'blocked') {
            $reasonCode = (string)($sourcePreparation['reason_code']
                ?? 'meituan_current_capture_not_ready');
            $candidate = [
                'status' => 'blocked',
                'reason_code' => $reasonCode,
                'business_date' => $businessDate,
                'payload' => null,
                'formal_send_gate' => [
                    'allowed' => false,
                    'status' => 'formal_send_blocked',
                    'blockers' => [[
                        'code' => $reasonCode,
                        'message' => '美团当次采集未完成保存回读，本轮未发送。',
                    ]],
                ],
            ];
        }
        if (($ctripPreparation['status'] ?? '') === 'blocked') {
            $reasonCode = (string)($ctripPreparation['reason_code']
                ?? 'ctrip_current_capture_not_ready');
            $candidate = [
                'status' => 'blocked',
                'reason_code' => $reasonCode,
                'business_date' => $businessDate,
                'payload' => null,
                'formal_send_gate' => [
                    'allowed' => false,
                    'status' => 'formal_send_blocked',
                    'blockers' => [[
                        'code' => $reasonCode,
                        'message' => '携程当次采集未完成保存回读，本轮未发送。',
                    ]],
                ],
            ];
        }
        if (($identity['eligible'] ?? false) === true
            && ($candidate['status'] ?? '') === 'ready'
        ) {
            $testedContract = $this->testedRenderContractGate(
                $row,
                $candidate,
                (int)($identity['robot_id'] ?? 0),
                (string)($identity['robot_name'] ?? '')
            );
            if (($testedContract['eligible'] ?? false) !== true) {
                $candidate = [
                    ...$candidate,
                    'status' => 'blocked',
                    'reason_code' => (string)($testedContract['reason_code']
                        ?? 'business_message_retest_required'),
                    'payload' => null,
                    'formal_send_gate' => [
                        'allowed' => false,
                        'status' => 'formal_send_blocked',
                        'blockers' => [[
                            'code' => (string)($testedContract['reason_code']
                                ?? 'business_message_retest_required'),
                            'message' => (string)($testedContract['message']
                                ?? '当前动态消息合同尚未完成真实测试。'),
                        ]],
                    ],
                ];
            }
        }
        if (!$dispatch) {
            if (($identity['eligible'] ?? false) !== true) {
                return $base + [
                    'status' => 'blocked',
                    'reason_code' => (string)($identity['reason_code'] ?? 'target_binding_missing'),
                    'payload' => null,
                ];
            }
            if (($candidate['status'] ?? '') !== 'ready') {
                return $base + [
                    'status' => 'blocked',
                    'reason_code' => (string)($candidate['reason_code'] ?? 'report_gate_blocked'),
                    'payload' => null,
                    'report_gate' => $candidate['formal_send_gate'] ?? null,
                ];
            }
            return $base + [
                'status' => 'preview',
                'reason_code' => 'dispatch_not_requested',
                'target_robot_id' => (int)$identity['robot_id'],
                'target_robot_name' => (string)$identity['robot_name'],
                'payload' => $candidate['payload'],
            ];
        }

        $blockedReason = null;
        $blockedMessage = null;
        if (($identity['eligible'] ?? false) !== true) {
            $blockedReason = (string)($identity['reason_code'] ?? 'target_binding_missing');
            $blockedMessage = '企业微信机器人身份、作用域或酒店归属未通过校验。';
        } elseif (($candidate['status'] ?? '') !== 'ready' || !is_array($candidate['payload'] ?? null)) {
            $blockedReason = (string)($candidate['reason_code'] ?? 'report_gate_blocked');
            $blockedMessage = $this->candidateBlockerMessage($candidate);
        } elseif ($this->sender === null) {
            $blockedReason = 'explicit_sender_missing';
            $blockedMessage = '云端调度未注入真实发送器。';
        }

        $robotId = (int)($identity['robot_id'] ?? $row['test_robot_id'] ?? 0);
        $robotName = trim((string)($identity['robot_name'] ?? $row['test_robot_name'] ?? ''));
        $claim = $this->dispatchLedger()->claim(
            $notificationId,
            $tenantId,
            $hotelId,
            $window,
            $mode,
            (string)($row['trigger_type'] ?? ''),
            'scheduled',
            $robotId,
            $robotName,
            $businessDate,
            $candidate,
            $now,
            $blockedReason === null ? 'claimed' : 'blocked',
            $blockedReason ?? 'dispatch_claimed',
            $blockedMessage,
            $scheduleRunId
        );
        $claimedDispatch = $claim['dispatch'];
        if ($claim['claimed'] === false) {
            $existingStatus = (string)($claimedDispatch['status'] ?? 'unknown');
            if (in_array($existingStatus, ['failed', 'outcome_unknown', 'blocked'], true)) {
                return $base + [
                    'status' => $existingStatus,
                    'reason_code' => (string)($claimedDispatch['result_code'] ?? ('dispatch_status_' . $existingStatus)),
                    'dispatch_id' => (int)$claimedDispatch['id'],
                    'existing_status' => $existingStatus,
                    'delivery_attempted' => false,
                ];
            }
            return $base + [
                'status' => 'skipped',
                'reason_code' => 'dispatch_window_already_claimed',
                'dispatch_id' => (int)$claimedDispatch['id'],
                'existing_status' => $existingStatus,
                'delivery_attempted' => false,
            ];
        }
        if ($blockedReason !== null) {
            return $base + [
                'status' => 'blocked',
                'reason_code' => $blockedReason,
                'dispatch_id' => (int)$claimedDispatch['id'],
                'report_gate' => $candidate['formal_send_gate'] ?? null,
            ];
        }

        $attempt = $this->dispatchLedger()->beginAttempt(
            (int)$claimedDispatch['id'],
            $now
        );
        if (($attempt['allowed'] ?? false) !== true) {
            return $base + [
                'status' => 'blocked',
                'reason_code' => (string)$attempt['reason_code'],
                'dispatch_id' => (int)$claimedDispatch['id'],
            ];
        }

        $delivery = [];
        $exception = null;
        try {
            $delivery = call_user_func(
                $this->sender,
                $hotelId,
                $robotId,
                $candidate['payload'],
                [
                    'notification_id' => $notificationId,
                    'dispatch_id' => (int)$claimedDispatch['id'],
                    'dispatch_window' => $window,
                    'business_date' => $businessDate,
                    'mode' => $mode,
                    'request_kind' => 'scheduled',
                    'tenant_id' => $tenantId,
                    'robot_name' => $robotName,
                    'owner_user_id' => (int)($row['created_by'] ?? 0),
                ]
            );
            $delivery = is_array($delivery) ? $delivery : [];
        } catch (\Throwable $error) {
            $exception = $error;
        }
        $finished = $this->dispatchLedger()->finishAttempt(
            (int)$claimedDispatch['id'],
            (int)$attempt['attempt_id'],
            $delivery,
            $now,
            $exception
        );
        return $base + [
            'dispatch_id' => (int)$finished['id'],
            'status' => (string)$finished['status'],
            'reason_code' => (string)$finished['result_code'],
            'delivery_attempted' => true,
            'target_robot_id' => $robotId,
            'target_robot_name' => $robotName,
            'payload_fingerprint' => $finished['payload_fingerprint'] ?? null,
            'operating_target_record_id' => $finished['operating_target_record_id'] ?? null,
            'snapshot_revision_no' => $finished['snapshot_revision_no'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function prepareMeituanSource(
        array $row,
        string $businessDate,
        DateTimeImmutable $observedAt,
        bool $dispatch
    ): array {
        if (trim((string)($row['source_scope'] ?? '')) !== 'meituan') {
            return [
                'status' => 'not_required',
                'reason_code' => 'non_meituan_plan',
            ];
        }
        if ((string)($row['business_date_rule'] ?? 'today') !== 'today') {
            return [
                'status' => 'not_required',
                'reason_code' => 'historical_business_date_uses_verified_snapshot',
            ];
        }
        if (!$dispatch) {
            return [
                'status' => 'preview_only',
                'reason_code' => 'current_capture_runs_before_real_dispatch',
            ];
        }

        $hotelId = (int)($row['hotel_id'] ?? 0);
        $cacheKey = $hotelId . '|' . $businessDate;
        if (isset($this->meituanPreparationCache[$cacheKey])) {
            return $this->meituanPreparationCache[$cacheKey] + ['reused_in_run' => true];
        }

        try {
            $result = $this->meituanTemporalRefresher !== null
                ? call_user_func(
                    $this->meituanTemporalRefresher,
                    $row,
                    $businessDate,
                    $observedAt
                )
                : $this->refreshMeituanTemporalSource(
                    $row,
                    $businessDate,
                    $observedAt
                );
            $result = is_array($result) ? $result : [];
        } catch (\Throwable) {
            $result = [
                'status' => 'blocked',
                'reason_code' => 'meituan_current_capture_failed',
            ];
        }

        if (($result['status'] ?? '') === 'ready') {
            if (($result['readback_verified'] ?? false) !== true
                || (int)($result['saved_count'] ?? 0) <= 0
            ) {
                $result = [
                    ...$result,
                    'status' => 'blocked',
                    'reason_code' => 'meituan_current_capture_readback_missing',
                ];
            }
        } elseif (($result['status'] ?? '') !== 'blocked') {
            $result = [
                ...$result,
                'status' => 'blocked',
                'reason_code' => (string)($result['reason_code']
                    ?? 'meituan_current_capture_not_ready'),
            ];
        }

        $this->meituanPreparationCache[$cacheKey] = $result;
        return $result;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function refreshMeituanTemporalSource(
        array $row,
        string $businessDate,
        DateTimeImmutable $observedAt
    ): array {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $observedAt = $observedAt->setTimezone($timezone);
        $liveNow = new DateTimeImmutable('now', $timezone);
        if ($businessDate !== $liveNow->format('Y-m-d')
            || abs($liveNow->getTimestamp() - $observedAt->getTimestamp())
                >= self::DUE_GRACE_SECONDS
        ) {
            return [
                'status' => 'blocked',
                'reason_code' => 'meituan_dispatch_observation_not_current',
            ];
        }

        $hotelId = (int)($row['hotel_id'] ?? 0);
        $actorId = (int)($row['created_by'] ?? 0);
        if ($hotelId <= 0 || $actorId <= 0) {
            return [
                'status' => 'blocked',
                'reason_code' => 'meituan_schedule_actor_scope_missing',
            ];
        }
        $actor = User::where('id', $actorId)->where('status', 1)->find();
        if (!$actor) {
            return [
                'status' => 'blocked',
                'reason_code' => 'meituan_schedule_actor_missing',
            ];
        }

        $service = new MeituanTemporalService();
        $refresh = $service->refresh($actor, $hotelId, $businessDate);
        if (($refresh['status'] ?? '') === 'blocked') {
            return [
                'status' => 'blocked',
                'reason_code' => (string)($refresh['reason_code']
                    ?? 'meituan_current_capture_blocked'),
            ];
        }

        $todayTask = null;
        foreach ((array)($refresh['tasks'] ?? []) as $task) {
            if (is_array($task) && ($task['segment'] ?? '') === 'today') {
                $todayTask = $task;
                break;
            }
        }
        if (!is_array($todayTask)
            || !in_array(
                (string)($todayTask['status'] ?? ''),
                ['completed', 'partial'],
                true
            )
            || ($todayTask['readback_verified'] ?? false) !== true
            || (int)($todayTask['saved_count'] ?? 0) <= 0
        ) {
            return [
                'status' => 'blocked',
                'reason_code' => (string)($todayTask['reason_code']
                    ?? 'meituan_current_capture_readback_missing'),
            ];
        }

        $summary = $service->summary($actor, $hotelId, $businessDate);
        $today = is_array($summary['today'] ?? null) ? $summary['today'] : [];
        $capturedAt = trim((string)($today['captured_at'] ?? ''));
        try {
            $capturedTime = $capturedAt === ''
                ? null
                : new DateTimeImmutable($capturedAt, $timezone);
        } catch (\Throwable) {
            $capturedTime = null;
        }
        if (($summary['source_state']['status'] ?? '') !== 'ready'
            || !in_array((string)($today['status'] ?? ''), ['ready', 'partial'], true)
            || (string)($today['target_date'] ?? '') !== $businessDate
            || !$capturedTime
            || abs($liveNow->getTimestamp() - $capturedTime->getTimestamp()) > 900
        ) {
            return [
                'status' => 'blocked',
                'reason_code' => 'meituan_current_summary_not_verified',
            ];
        }

        return [
            'status' => 'ready',
            'reason_code' => 'meituan_current_capture_saved_and_read_back',
            'data_scope' => 'ota_channel',
            'target_date' => $businessDate,
            'captured_at' => $capturedAt,
            'summary_status' => (string)($today['status'] ?? ''),
            'sync_task_id' => (int)($todayTask['sync_task_id'] ?? 0),
            'saved_count' => (int)($todayTask['saved_count'] ?? 0),
            'readback_verified' => true,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function prepareCtripSource(
        array $row,
        string $businessDate,
        DateTimeImmutable $observedAt,
        bool $dispatch
    ): array {
        if (!ManualNotificationService::isCtripTemporalReportType(
            (string)($row['template_type'] ?? '')
        )) {
            return [
                'status' => 'not_required',
                'reason_code' => 'non_ctrip_temporal_plan',
            ];
        }
        if ((string)($row['business_date_rule'] ?? 'today') !== 'today') {
            return [
                'status' => 'not_required',
                'reason_code' => 'historical_business_date_uses_verified_snapshot',
            ];
        }
        if (!$dispatch) {
            return [
                'status' => 'preview_only',
                'reason_code' => 'current_capture_runs_before_real_dispatch',
            ];
        }

        $hotelId = (int)($row['hotel_id'] ?? 0);
        $cacheKey = $hotelId . '|' . $businessDate;
        if (isset($this->ctripPreparationCache[$cacheKey])) {
            return $this->ctripPreparationCache[$cacheKey] + ['reused_in_run' => true];
        }

        $actorId = (int)($row['created_by'] ?? 0);
        $actor = $actorId > 0
            ? User::where('id', $actorId)->where('status', 1)->find()
            : null;
        if (!$actor) {
            $result = [
                'status' => 'blocked',
                'reason_code' => 'ctrip_schedule_actor_missing',
            ];
        } else {
            try {
                $result = $this->ctripTemporalRefresher !== null
                    ? call_user_func(
                        $this->ctripTemporalRefresher,
                        $row,
                        $businessDate,
                        $observedAt,
                        $actor
                    )
                    : (new CtripTemporalRefreshService())->refresh(
                        $actor,
                        (int)($row['tenant_id'] ?? 0),
                        $hotelId,
                        $this->hotelName($hotelId),
                        $businessDate,
                        $observedAt
                    );
                $result = is_array($result) ? $result : [];
            } catch (\Throwable) {
                $result = [
                    'status' => 'blocked',
                    'reason_code' => 'ctrip_current_capture_failed',
                ];
            }
        }

        if (($result['status'] ?? '') === 'ready') {
            if (($result['readback_verified'] ?? false) !== true
                || (int)($result['saved_count'] ?? 0) <= 0
            ) {
                $result = [
                    ...$result,
                    'status' => 'blocked',
                    'reason_code' => 'ctrip_current_capture_readback_missing',
                ];
            }
        } elseif (($result['status'] ?? '') !== 'blocked') {
            $result = [
                ...$result,
                'status' => 'blocked',
                'reason_code' => (string)($result['reason_code']
                    ?? 'ctrip_current_capture_not_ready'),
            ];
        }

        $this->ctripPreparationCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{eligible:bool,reason_code?:string,robot_id?:int,robot_name?:string}
     */
    private function resolveTargetIdentity(array $row, string $mode): array
    {
        $tenantId = (int)($row['tenant_id'] ?? 0);
        $hotelId = (int)($row['hotel_id'] ?? 0);
        $sendMethod = trim((string)($row['send_method'] ?? ''));
        $expectedSendMethod = $mode === self::MODE_FORMAL ? 'wecom_formal' : 'wecom_test';
        if ($sendMethod !== $expectedSendMethod) {
            return [
                'eligible' => false,
                'reason_code' => $mode === self::MODE_FORMAL
                    ? 'formal_mode_send_method_mismatch'
                    : 'test_mode_send_method_mismatch',
            ];
        }
        $robotId = (int)($row['test_robot_id'] ?? 0);
        $robotName = trim((string)($row['test_robot_name'] ?? ''));
        if ($robotId <= 0 || $robotName === '') {
            return ['eligible' => false, 'reason_code' => 'target_binding_missing'];
        }
        return $this->deliveryService()->resolvePlanRobot(
            $tenantId,
            $hotelId,
            $robotId,
            $robotName,
            (int)($row['created_by'] ?? 0),
            $mode
        );
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function deliveryCandidate(
        array $row,
        string $businessDate,
        string $mode
    ): array {
        $hotelId = (int)($row['hotel_id'] ?? 0);
        $tenantId = (int)($row['tenant_id'] ?? 0);
        $hotelName = $this->hotelName($hotelId);
        if (ManualNotificationService::isDynamicReportType(
            (string)($row['template_type'] ?? '')
        )) {
            $templateType = (string)($row['template_type'] ?? '');
            $candidate = ManualNotificationService::isCtripTemporalReportType(
                $templateType
            )
                ? $this->ctripTemporalPayloads()->build(
                    $tenantId,
                    $hotelId,
                    $hotelName,
                    $businessDate,
                    'scheduled_test'
                )
                : (ManualNotificationService::isBusinessFactReportType(
                    $templateType
                )
                    ? $this->businessPayloads()->build(
                    $tenantId,
                    $hotelId,
                    $hotelName,
                    $businessDate,
                    $templateType,
                    'scheduled_test'
                    )
                    : (ManualNotificationService::isOperatingDailyReportType(
                        $templateType
                    )
                        ? $this->dailyPayloads()->build(
                            $tenantId,
                            $hotelId,
                            $hotelName,
                            $businessDate,
                            'scheduled_test',
                            (string)($row['source_scope'] ?? 'combined'),
                            $this->contentSections($row['content_sections'] ?? null),
                            ManualNotificationService::operatingDailyTemplateMode(
                                $templateType
                            ),
                            (string)($row['title'] ?? ''),
                            (string)($row['body'] ?? '')
                        )
                        : $this->targetPayloads()->build(
                            $tenantId,
                            $hotelId,
                            $hotelName,
                            $businessDate,
                            'scheduled_test'
                        )));
            return $mode === self::MODE_FORMAL
                ? $this->formalizeDynamicCandidate($candidate)
                : $candidate;
        }
        $payload = $this->buildStaticPayload($row, $businessDate, $hotelName, $mode);
        return [
            'status' => 'ready',
            'reason_code' => 'static_notification_ready',
            'business_date' => $businessDate,
            'payload_fingerprint' => hash('sha256', $this->json($payload)),
            'operating_target_record_id' => 0,
            'snapshot_revision_no' => 0,
            'formal_send_gate' => null,
            'payload' => $payload,
        ];
    }

    /** @param array<string, mixed> $candidate @return array<string, mixed> */
    private function formalizeDynamicCandidate(array $candidate): array
    {
        if (!is_array($candidate['payload'] ?? null)) {
            return $candidate;
        }
        $payload = $candidate['payload'];
        if (strtolower(trim((string)($payload['msgtype'] ?? ''))) !== 'markdown'
            || !is_array($payload['markdown'] ?? null)
            || !array_key_exists('content', $payload['markdown'])
        ) {
            return $candidate;
        }
        $content = (string)($payload['markdown']['content'] ?? '');
        $content = str_replace(
            [
                '> 当前模式：企业微信测试群定时真实投递',
                '> 正式发送门禁：允许（仍需另行取得正式发送授权）',
            ],
            [
                '> 当前模式：企业微信正式群定时真实投递',
                '> 正式发送门禁：已通过，且本次由持久化正式计划授权',
            ],
            $content
        );
        $payload['markdown']['content'] = $content;
        $candidate['payload'] = $payload;
        $candidate['payload_fingerprint'] = hash('sha256', $this->json($payload));
        return $candidate;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{msgtype:string,markdown:array{content:string}}
     */
    private function buildStaticPayload(
        array $row,
        string $businessDate,
        string $hotelName,
        string $mode
    ): array {
        $variables = [
            '{酒店名称}' => $hotelName,
            '{经营日期}' => $businessDate,
            '{统计时间}' => trim((string)($row['planned_send_at'] ?? '')) ?: '待配置',
            '{数据状态}' => '定时发送已启用',
        ];
        $title = strtr(trim((string)($row['title'] ?? '')), $variables);
        $body = strtr(trim((string)($row['body'] ?? '')), $variables);
        $modeLabel = $mode === self::MODE_FORMAL
            ? '企业微信正式群定时真实投递'
            : '企业微信测试群定时真实投递';
        $scopeNote = $mode === self::MODE_FORMAL
            ? '本次仅发送已保存正文；调度层未补写或推导业务事实。'
            : '未取得的数据未使用0或旧日数据补齐；本次仅发送测试群。';
        return [
            'msgtype' => 'markdown',
            'markdown' => ['content' => implode("\n", [
                '# 宿析OS｜' . $this->safeText($title, 120),
                '> 调度模式：' . $modeLabel,
                '> 酒店：' . $this->safeText($hotelName, 80) . '（ID ' . (int)$row['hotel_id'] . '）',
                '> 业务日期：' . $businessDate,
                '',
                $this->safeMultiline($body, 5000),
                '',
                '> ' . $scopeNote,
            ])],
        ];
    }

    private function hotelName(int $hotelId): string
    {
        if (!$this->tableExists('hotels')) {
            return '未取得';
        }
        $name = trim((string)(Db::name('hotels')->where('id', $hotelId)->value('name') ?? ''));
        return $name !== '' ? $name : '未取得';
    }

    /** @param array<string, mixed> $candidate */
    private function candidateBlockerMessage(array $candidate): string
    {
        $messages = [];
        foreach ((array)($candidate['formal_send_gate']['blockers'] ?? []) as $blocker) {
            if (is_array($blocker) && trim((string)($blocker['message'] ?? '')) !== '') {
                $messages[] = trim((string)$blocker['message']);
            }
        }
        return $messages === []
            ? '经营目标报告门禁未通过。'
            : implode('；', array_slice($messages, 0, 3));
    }

    private function startRun(
        string $mode,
        bool $dispatch,
        int $scopeHotelId,
        int $scopeRobotId,
        DateTimeImmutable $now
    ): ?int {
        if (!$this->tableExists('manual_notification_schedule_runs')) {
            return null;
        }
        $timestamp = $now->format('Y-m-d H:i:s');
        $values = [
            'runner_mode' => $mode,
            'dispatch_requested' => $dispatch ? 1 : 0,
            'scope_hotel_id' => $scopeHotelId > 0 ? $scopeHotelId : null,
            'observed_at' => $timestamp,
            'status' => 'running',
            'candidate_count' => 0,
            'due_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'blocked_count' => 0,
            'result_summary_json' => null,
            'started_at' => $timestamp,
            'finished_at' => null,
            'create_time' => $timestamp,
            'update_time' => $timestamp,
        ];
        if ($this->tableHasColumn('manual_notification_schedule_runs', 'scope_robot_id')) {
            $values['scope_robot_id'] = $scopeRobotId > 0 ? $scopeRobotId : null;
        }
        if ($this->tableHasColumn('manual_notification_schedule_runs', 'scope_tenant_id')) {
            $scopeTenantId = $scopeHotelId > 0 && $this->tableExists('hotels')
                ? (int)(Db::name('hotels')->where('id', $scopeHotelId)->value('tenant_id') ?? 0)
                : 0;
            $values['scope_tenant_id'] = $scopeTenantId > 0 ? $scopeTenantId : null;
        }
        $id = (int)Db::name('manual_notification_schedule_runs')->insertGetId($values);
        return $id > 0 ? $id : null;
    }

    /** @param array<string, mixed> $summary */
    private function finishRun(
        ?int $runId,
        string $status,
        array $summary,
        DateTimeImmutable $now
    ): void {
        if ($runId === null || $runId <= 0) {
            return;
        }
        $timestamp = $now->format('Y-m-d H:i:s');
        $publicResults = [];
        foreach ((array)($summary['results'] ?? []) as $result) {
            if (!is_array($result)) {
                continue;
            }
            $publicResults[] = [
                'notification_id' => (int)($result['notification_id'] ?? 0),
                'dispatch_id' => (int)($result['dispatch_id'] ?? 0),
                'business_date' => (string)($result['business_date'] ?? ''),
                'dispatch_window' => (string)($result['dispatch_window'] ?? ''),
                'status' => (string)($result['status'] ?? ''),
                'reason_code' => (string)($result['reason_code'] ?? ''),
            ];
        }
        $resultSummary = [
            'error_code' => (string)($summary['error_code'] ?? ''),
            'error_message' => (string)($summary['error_message'] ?? ''),
            'recovered_unknown_count' => (int)($summary['recovered_unknown_count'] ?? 0),
            'results' => $publicResults,
        ];
        Db::name('manual_notification_schedule_runs')->where('id', $runId)->update([
            'status' => $status,
            'candidate_count' => (int)($summary['candidate_count'] ?? 0),
            'due_count' => (int)($summary['due_count'] ?? 0),
            'sent_count' => (int)($summary['sent_count'] ?? 0),
            'failed_count' => (int)($summary['failed_count'] ?? 0),
            'blocked_count' => (int)($summary['blocked_count'] ?? 0),
            'result_summary_json' => $this->json($resultSummary),
            'finished_at' => $timestamp,
            'update_time' => $timestamp,
        ]);
    }

    /**
     * Preserve a per-run heartbeat for every exact saved plan scope, including
     * the many timer minutes where no notification is due. Dispatch rows remain
     * immutable delivery provenance and are not reused as health heartbeats.
     *
     * @param array<int, array<string,mixed>> $rows
     * @param array<int, array<string,mixed>> $results
     */
    private function recordScopeObservations(
        ?int $runId,
        array $rows,
        array $results,
        string $mode,
        bool $dispatch,
        DateTimeImmutable $now
    ): void {
        if ($runId === null
            || $runId <= 0
            || !$this->tableExists('manual_notification_schedule_run_scopes')
        ) {
            return;
        }

        $groups = [];
        $notificationScopes = [];
        foreach ($rows as $row) {
            $tenantId = (int)($row['tenant_id'] ?? 0);
            $hotelId = (int)($row['hotel_id'] ?? 0);
            $robotId = (int)($row['test_robot_id'] ?? 0);
            $notificationId = (int)($row['id'] ?? 0);
            if ($tenantId <= 0 || $hotelId <= 0 || $robotId <= 0 || $notificationId <= 0) {
                continue;
            }
            $key = $tenantId . ':' . $hotelId . ':' . $robotId;
            $notificationScopes[$notificationId] = $key;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'robot_id' => $robotId,
                    'candidate_count' => 0,
                    'due_count' => 0,
                    'sent_count' => 0,
                    'failed_count' => 0,
                    'blocked_count' => 0,
                ];
            }
            $groups[$key]['candidate_count']++;
        }

        foreach ($results as $result) {
            $notificationId = (int)($result['notification_id'] ?? 0);
            $key = $notificationScopes[$notificationId] ?? null;
            if (!is_string($key) || !isset($groups[$key])) {
                continue;
            }
            $groups[$key]['due_count']++;
            $status = strtolower(trim((string)($result['status'] ?? '')));
            if ($status === 'sent') {
                $groups[$key]['sent_count']++;
            } elseif (in_array($status, ['failed', 'outcome_unknown'], true)) {
                $groups[$key]['failed_count']++;
            } elseif ($status === 'blocked') {
                $groups[$key]['blocked_count']++;
            } elseif ($dispatch && !in_array($status, ['preview', 'skipped'], true)) {
                $groups[$key]['failed_count']++;
            }
        }

        $timestamp = $now->format('Y-m-d H:i:s');
        $values = [];
        foreach ($groups as $group) {
            $status = $group['failed_count'] > 0
                ? 'failed'
                : ($group['blocked_count'] > 0 ? 'blocked' : 'completed');
            $values[] = [
                'schedule_run_id' => $runId,
                'tenant_id' => (int)$group['tenant_id'],
                'hotel_id' => (int)$group['hotel_id'],
                'robot_id' => (int)$group['robot_id'],
                'runner_mode' => $mode,
                'dispatch_requested' => $dispatch ? 1 : 0,
                'observed_at' => $timestamp,
                'status' => $status,
                'candidate_count' => (int)$group['candidate_count'],
                'due_count' => (int)$group['due_count'],
                'sent_count' => (int)$group['sent_count'],
                'failed_count' => (int)$group['failed_count'],
                'blocked_count' => (int)$group['blocked_count'],
                'create_time' => $timestamp,
                'update_time' => $timestamp,
            ];
        }
        if ($values !== []) {
            Db::name('manual_notification_schedule_run_scopes')->insertAll($values);
        }
    }

    private function targetPayloads(): OperatingTargetNotificationPayloadService
    {
        return $this->operatingTargetPayloads ?? new OperatingTargetNotificationPayloadService();
    }

    private function dailyPayloads(): OperatingDailyReportPayloadService
    {
        return $this->operatingDailyPayloads ?? new OperatingDailyReportPayloadService();
    }

    private function businessPayloads(): ManualNotificationBusinessPayloadService
    {
        return $this->businessMessagePayloads
            ?? new ManualNotificationBusinessPayloadService();
    }

    private function ctripTemporalPayloads(): CtripTemporalNotificationPayloadService
    {
        return $this->ctripTemporalPayloads
            ?? new CtripTemporalNotificationPayloadService();
    }

    /**
     * Existing static plans must not inherit permission to send a newly
     * introduced dynamic business payload. A successful immediate test of the
     * same render contract and robot is required before scheduled delivery.
     *
     * @return array{eligible:bool,reason_code?:string,message?:string}
     */
    private function testedRenderContractGate(
        array $row,
        array $candidate,
        int $robotId,
        string $robotName
    ): array {
        $templateType = (string)($row['template_type'] ?? '');
        if (!ManualNotificationService::requiresTestedRenderContract($templateType)) {
            return ['eligible' => true];
        }
        $contractVersion = trim((string)(
            $candidate['render_contract_version']
            ?? ''
        ));
        $planUpdatedAt = trim((string)($row['update_time'] ?? ''));
        if ($contractVersion === ''
            || preg_match(
                '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
                $planUpdatedAt
            ) !== 1
            || $robotId <= 0
            || trim($robotName) === ''
            || !$this->tableExists('manual_notification_schedule_dispatches')
            || !$this->tableHasColumn(
                'manual_notification_schedule_dispatches',
                'render_contract_version'
            )
            || !$this->tableHasColumn(
                'manual_notification_schedule_dispatches',
                'request_kind'
            )
        ) {
            return [
                'eligible' => false,
                'reason_code' => 'business_message_retest_required',
                'message' => '动态业务消息合同尚未完成同机器人真实测试；定时发送已阻断。',
            ];
        }
        $tested = Db::name('manual_notification_schedule_dispatches')
            ->where('notification_id', (int)($row['id'] ?? 0))
            ->where('tenant_id', (int)($row['tenant_id'] ?? 0))
            ->where('hotel_id', (int)($row['hotel_id'] ?? 0))
            ->where('delivery_mode', 'test')
            ->where('request_kind', 'immediate_test')
            ->where('robot_id', $robotId)
            ->where('robot_name', trim($robotName))
            ->where('render_contract_version', $contractVersion)
            ->where('status', 'sent')
            ->where('dispatched_at', '>=', $planUpdatedAt)
            ->order('dispatched_at', 'desc')
            ->order('id', 'desc')
            ->find();
        if (!is_array($tested)) {
            return [
                'eligible' => false,
                'reason_code' => 'business_message_retest_required',
                'message' => '当前动态业务消息合同未在该机器人真实测试成功；旧静态计划不会自动继承发送权限。',
            ];
        }
        return ['eligible' => true];
    }

    private function dispatchLedger(): ManualNotificationDispatchLedgerService
    {
        return $this->ledger ?? new ManualNotificationDispatchLedgerService();
    }

    private function deliveryService(): WechatRobotDeliveryService
    {
        return $this->deliveries ?? new WechatRobotDeliveryService();
    }

    private function scheduleRules(): ManualNotificationScheduleRuleService
    {
        return $this->scheduleRuleService ?? new ManualNotificationScheduleRuleService();
    }

    /** @return list<string> */
    private function contentSections(mixed $value): array
    {
        $parts = is_array($value) ? $value : explode(',', (string)$value);
        $sections = [];
        foreach ($parts as $part) {
            $key = trim((string)$part);
            if ($key !== '') {
                $sections[$key] = $key;
            }
        }
        return array_values($sections);
    }

    private function tableExists(string $table): bool
    {
        if (preg_match('/^[a-z0-9_]+$/', $table) !== 1) {
            return false;
        }
        try {
            Db::query('SELECT 1 FROM `' . $table . '` WHERE 1 = 0');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            return in_array($column, Db::getTableInfo($table, 'fields'), true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function safeText(string $value, int $limit): string
    {
        $value = preg_replace(
            '/(key|token|secret|cookie|password|authorization|webhook)\s*[=:]\s*[^\s,;]+/iu',
            '$1=<redacted>',
            trim($value)
        ) ?? '';
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    private function safeMultiline(string $value, int $limit): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value) ?? '';
        return mb_substr(trim($value), 0, $limit, 'UTF-8');
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($json)) {
            throw new \RuntimeException('manual_notification_schedule_json_failed');
        }
        return $json;
    }
}
