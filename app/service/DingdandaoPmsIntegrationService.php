<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Maintains the hotel-scoped Dingdandao identity binding and orchestrates
 * verified PMS fact delivery through the existing WeCom robot sender.
 */
final class DingdandaoPmsIntegrationService
{
    private const INTEGRATION_TABLE = 'dingdandao_pms_integrations';
    private const DISPATCH_TABLE = 'dingdandao_pms_push_dispatches';
    private const SHARED_NOTIFICATION_SCOPE = 'admin_shared';
    private const STALE_PENDING_AFTER_SECONDS = 600;
    private const STALE_SENDING_AFTER_SECONDS = 180;
    private const RETRYABLE_DELIVERY_STATUSES = ['failed', 'partial', 'binding_missing'];

    /** @var callable|null */
    private $delivery;

    /** @var callable|null */
    private $afterGate;

    /** @var callable|null */
    private $beforeCaptureStateLock;

    /** @var callable|null */
    private $afterDelivery;

    public function __construct(
        ?callable $delivery = null,
        ?callable $afterGate = null,
        ?callable $beforeCaptureStateLock = null,
        ?callable $afterDelivery = null
    )
    {
        $this->delivery = $delivery;
        $this->afterGate = $afterGate;
        $this->beforeCaptureStateLock = $beforeCaptureStateLock;
        $this->afterDelivery = $afterDelivery;
    }

    /** @return array<string,mixed> */
    public function status(
        int $tenantId,
        int $hotelId,
        int $userId,
        string $businessDate = ''
    ): array {
        $this->assertScope($tenantId, $hotelId, $userId);
        $config = $this->configRow($tenantId, $hotelId);
        $capture = $this->captureForStatus($tenantId, $hotelId, $businessDate);
        $latestCapture = trim($businessDate) === ''
            ? $capture
            : $this->captureForStatus($tenantId, $hotelId, '');
        $robots = $this->sharedRobots($tenantId, $hotelId);
        $profile = $this->profileStatus($hotelId, $userId);
        $dispatch = $this->latestDispatch($tenantId, $hotelId);
        $masterData = $this->stableMasterData(
            $tenantId,
            $hotelId,
            $config,
            $latestCapture ?? $capture
        );

        return [
            'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
            'provider_label' => '订单来了 PMS',
            'source_url' => DingdandaoOperatingTargetCaptureService::SOURCE_URL,
            'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
            'field_coverage' => [
                'total_room_fee',
                'adr',
                'occupancy_rate_percent',
                'revpar',
                'sold_room_nights',
                'average_daily_room_nights',
                'room_fee_details',
            ],
            'config' => $this->publicConfig($config, $latestCapture ?? $capture, $masterData),
            'stable_master_data' => $masterData,
            'profile' => $profile,
            'robots' => $robots,
            'capture' => $capture,
            'latest_capture' => $latestCapture,
            'latest_dispatch' => $dispatch,
            'fact_gate' => $this->factGate($config, $capture),
            'push_gate' => $this->pushGate($config, $capture, $robots),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(
        int $tenantId,
        int $hotelId,
        int $userId,
        array $input,
        string $businessDate = ''
    ): array {
        $this->assertScope($tenantId, $hotelId, $userId);
        $this->assertTablesReady();

        $providerHotelId = $this->textOrNull($input['provider_hotel_id'] ?? null, 120);
        $providerHotelName = $this->textOrNull($input['provider_hotel_name'] ?? null, 160);
        $enabled = $this->boolValue($input['status'] ?? false);
        $autoPush = $this->boolValue($input['auto_push_enabled'] ?? false);
        $robotId = $this->positiveIntOrNull($input['robot_id'] ?? null);

        if ($enabled && $providerHotelName === null) {
            throw new \InvalidArgumentException('dingdandao_pms_binding_required');
        }
        if ($autoPush && (!$enabled || $robotId === null)) {
            throw new \InvalidArgumentException('dingdandao_pms_push_binding_required');
        }
        if ($robotId !== null) {
            $this->assertSharedRobot($tenantId, $hotelId, $robotId);
        }
        if ($autoPush
            && !$this->sharedRobotTestVerified(
                $this->sharedRobots($tenantId, $hotelId),
                $robotId
            )
        ) {
            throw new \InvalidArgumentException('dingdandao_pms_robot_test_required');
        }

        $now = date('Y-m-d H:i:s');
        Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $userId,
            $providerHotelId,
            $providerHotelName,
            $robotId,
            $enabled,
            $autoPush,
            $now
        ): void {
            $existing = Db::name(self::INTEGRATION_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('provider', DingdandaoOperatingTargetCaptureService::PROVIDER)
                ->lock(true)
                ->find();
            $values = [
                'provider_hotel_id' => $providerHotelId,
                'provider_hotel_name' => $providerHotelName,
                'source_url' => DingdandaoOperatingTargetCaptureService::SOURCE_URL,
                'robot_id' => $robotId,
                'status' => $enabled ? 1 : 0,
                'auto_push_enabled' => $autoPush ? 1 : 0,
                'updated_by' => $userId,
                'update_time' => $now,
            ];
            if (is_array($existing)) {
                Db::name(self::INTEGRATION_TABLE)
                    ->where('id', (int)$existing['id'])
                    ->update($values);
                return;
            }
            $id = (int)Db::name(self::INTEGRATION_TABLE)->insertGetId($values + [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
                'created_by' => $userId,
                'create_time' => $now,
            ]);
            if ($id <= 0) {
                throw new \RuntimeException('dingdandao_pms_config_save_failed');
            }
        });

        return $this->status($tenantId, $hotelId, $userId, $businessDate);
    }

    /** @return array{expected_provider_hotel_id:?string,expected_provider_hotel_name:string,configured:bool} */
    public function captureExpectation(
        int $tenantId,
        int $hotelId,
        string $systemHotelName
    ): array {
        $config = $this->configRow($tenantId, $hotelId);
        $configured = is_array($config)
            && (int)($config['status'] ?? 0) === 1
            && trim((string)($config['provider_hotel_name'] ?? '')) !== '';
        return [
            'expected_provider_hotel_id' => $configured
                ? $this->textOrNull($config['provider_hotel_id'] ?? null, 120)
                : null,
            'expected_provider_hotel_name' => $configured
                ? (string)$config['provider_hotel_name']
                : trim($systemHotelName),
            'configured' => $configured,
        ];
    }

    /** @return array<string,mixed> */
    public function prefill(
        int $tenantId,
        int $hotelId,
        int $userId,
        string $businessDate
    ): array {
        $this->assertScope($tenantId, $hotelId, $userId);
        $prefill = (new DingdandaoOperatingTargetCaptureService())
            ->prefill($tenantId, $hotelId, $businessDate);
        $capture = is_array($prefill['capture'] ?? null)
            ? $prefill['capture']
            : null;
        $config = $this->configRow($tenantId, $hotelId);
        if (is_array($capture) && (int)($capture['id'] ?? 0) > 0) {
            $config = $this->recordCaptureState($config, $capture);
        }
        $gate = $this->factGate($config, $capture);
        if (($gate['allowed'] ?? false) !== true) {
            return [
                'status' => 'blocked',
                'prefill' => null,
                'capture' => $capture,
                'gaps' => $gate['blockers'],
                'fact_gate' => $gate,
            ];
        }
        $prefill['fact_gate'] = $gate;
        return $prefill;
    }

    /**
     * @param array<string,mixed> $capture
     * @return array{allowed:bool,status:string,blockers:list<array{code:string,message:string}>}
     */
    public function factGateForCapture(
        int $tenantId,
        int $hotelId,
        int $userId,
        array $capture,
        bool $lockConfig = false
    ): array {
        $this->assertScope($tenantId, $hotelId, $userId);
        if ($lockConfig && $this->tableExists(self::INTEGRATION_TABLE)) {
            $config = Db::name(self::INTEGRATION_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('provider', DingdandaoOperatingTargetCaptureService::PROVIDER)
                ->lock(true)
                ->find();
            return $this->factGate(is_array($config) ? $config : null, $capture);
        }
        return $this->factGate($this->configRow($tenantId, $hotelId), $capture);
    }

    /**
     * Keeps the current PMS identity binding locked until the verified capture
     * has been promoted into the same-hotel operating-target record.
     *
     * @return array<string,mixed>
     */
    public function syncVerifiedCapture(
        int $tenantId,
        int $hotelId,
        int $userId,
        int $captureId
    ): array {
        $this->assertScope($tenantId, $hotelId, $userId);
        if (!$this->tableExists(self::INTEGRATION_TABLE)) {
            throw new \RuntimeException('dingdandao_pms_tables_missing');
        }
        if ($captureId <= 0) {
            throw new \InvalidArgumentException('dingdandao_target_sync_scope_invalid');
        }

        return (new DingdandaoOperatingTargetSyncService())
            ->syncVerifiedCapture($tenantId, $hotelId, $userId, $captureId);
    }

    /**
     * @param array<string,mixed> $capture
     * @return array<string,mixed>
     */
    public function dispatchVerifiedCapture(
        int $tenantId,
        int $hotelId,
        int $userId,
        string $hotelName,
        array $capture,
        string $triggerType = 'capture',
        bool $retryFailed = false
    ): array {
        $this->assertScope($tenantId, $hotelId, $userId);
        $this->assertTablesReady();
        $triggerType = strtolower(trim($triggerType));
        if (!in_array($triggerType, ['capture', 'manual'], true)) {
            throw new \InvalidArgumentException('dingdandao_pms_push_trigger_invalid');
        }

        $authoritativeCapture = $this->authoritativeCaptureForDispatch(
            $tenantId,
            $hotelId,
            $capture
        );
        if ($authoritativeCapture === null) {
            return [
                'delivery_status' => 'blocked',
                'delivery_attempted' => false,
                'trigger_type' => $triggerType,
                'blockers' => [
                    $this->blocker(
                        'pms_capture_authoritative_readback_missing',
                        '推送前未能从数据库回读同门店、同日期、同指纹的订单来了事实。'
                    ),
                ],
            ];
        }
        $capture = $authoritativeCapture;
        $config = $this->configRow($tenantId, $hotelId);
        $config = $this->recordCaptureState($config, $capture);
        $robots = $this->sharedRobots($tenantId, $hotelId);
        $gate = $this->pushGate($config, $capture, $robots, $triggerType);
        if (($gate['allowed'] ?? false) !== true) {
            return [
                'delivery_status' => 'blocked',
                'delivery_attempted' => false,
                'trigger_type' => $triggerType,
                'blockers' => $gate['blockers'],
            ];
        }

        $integrationId = (int)$config['id'];
        $robotId = (int)$config['robot_id'];
        $selectedRobot = current(array_values(array_filter(
            $robots,
            static fn(array $robot): bool => (int)($robot['id'] ?? 0) === $robotId
        )));
        $robotName = is_array($selectedRobot) ? trim((string)($selectedRobot['name'] ?? '')) : '';
        return $this->dispatchUnderLockedPolicy(
            $tenantId,
            $hotelId,
            $userId,
            $hotelName,
            $capture,
            $triggerType,
            $retryFailed,
            $integrationId,
            $robotId,
            $robotName
        );
    }

    /**
     * Locks the policy in one fixed order (integration, exact robot, dispatch)
     * and keeps those locks through the final delivery side effect.
     *
     * @param array<string,mixed> $capture
     * @return array<string,mixed>
     */
    private function dispatchUnderLockedPolicy(
        int $tenantId,
        int $hotelId,
        int $userId,
        string $hotelName,
        array $capture,
        string $triggerType,
        bool $retryFailed,
        int $expectedIntegrationId,
        int $expectedRobotId,
        string $expectedRobotName
    ): array {
        $claim = $this->claimDispatchDurably(
            $tenantId,
            $hotelId,
            $userId,
            $capture,
            $triggerType,
            $retryFailed,
            $expectedIntegrationId,
            $expectedRobotId
        );
        if (($claim['claimed'] ?? false) !== true) {
            return (array)($claim['result'] ?? []);
        }

        if ($this->afterGate !== null) {
            call_user_func(
                $this->afterGate,
                $tenantId,
                $hotelId,
                $expectedIntegrationId,
                $expectedRobotId
            );
        }

        try {
            return $this->deliverUnderLockedPolicy(
                $tenantId,
                $hotelId,
                $hotelName,
                $capture,
                $triggerType,
                $expectedIntegrationId,
                $expectedRobotId,
                $expectedRobotName,
                (int)$claim['dispatch_id'],
                (string)$claim['claimed_at'],
                (int)$claim['attempt_count']
            );
        } catch (\Throwable) {
            return $this->markClaimOutcomeUnknown(
                (int)$claim['dispatch_id'],
                (string)$claim['claimed_at'],
                (int)$claim['attempt_count']
            ) + ['delivery_attempted' => true];
        }
    }

    /**
     * @param array<string,mixed> $capture
     * @return array<string,mixed>
     */
    private function claimDispatchDurably(
        int $tenantId,
        int $hotelId,
        int $userId,
        array $capture,
        string $triggerType,
        bool $retryFailed,
        int $expectedIntegrationId,
        int $expectedRobotId
    ): array {
        return Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $userId,
            $capture,
            $triggerType,
            $retryFailed,
            $expectedIntegrationId,
            $expectedRobotId
        ): array {
            $lockedConfig = Db::name(self::INTEGRATION_TABLE)
                ->where('id', $expectedIntegrationId)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('provider', DingdandaoOperatingTargetCaptureService::PROVIDER)
                ->lock(true)
                ->find();
            if (!is_array($lockedConfig)) {
                return [
                    'claimed' => false,
                    'result' => [
                        'delivery_status' => 'blocked',
                        'delivery_attempted' => false,
                        'trigger_type' => $triggerType,
                        'blockers' => [
                            $this->blocker(
                                'pms_integration_changed',
                                '订单来了 PMS 绑定已变更，请按最新配置重新发起推送。'
                            ),
                        ],
                    ],
                ];
            }

            $captureId = (int)$capture['id'];
            $existing = Db::name(self::DISPATCH_TABLE)
                ->where('integration_id', $expectedIntegrationId)
                ->where('capture_id', $captureId)
                ->where('robot_id', $expectedRobotId)
                ->lock(true)
                ->find();
            if (is_array($existing)) {
                $existingStatus = strtolower(trim((string)($existing['delivery_status'] ?? 'pending')));
                if ($existingStatus === 'sent') {
                    return [
                        'claimed' => false,
                        'result' => $this->dispatchWithoutAttempt($existing),
                    ];
                }
                if ($existingStatus === 'sending') {
                    $claimedAt = trim((string)($existing['claimed_at'] ?? ''));
                    $claimedTimestamp = $claimedAt === '' ? false : strtotime($claimedAt);
                    if ($claimedTimestamp !== false
                        && $claimedTimestamp <= time() - self::STALE_SENDING_AFTER_SECONDS
                    ) {
                        Db::name(self::DISPATCH_TABLE)
                            ->where('id', (int)$existing['id'])
                            ->where('delivery_status', 'sending')
                            ->where('claimed_at', $claimedAt)
                            ->where('attempt_count', (int)($existing['attempt_count'] ?? 0))
                            ->update([
                                'delivery_status' => 'outcome_unknown',
                                'error_summary' => 'delivery_attempt_lease_expired_outcome_unknown',
                                'update_time' => date('Y-m-d H:i:s'),
                            ]);
                        $existing = Db::name(self::DISPATCH_TABLE)
                            ->where('id', (int)$existing['id'])
                            ->find();
                    }
                    return [
                        'claimed' => false,
                        'result' => $this->dispatchWithoutAttempt(
                            is_array($existing) ? $existing : []
                        ),
                    ];
                }
                if ($existingStatus === 'pending') {
                    $claimedAt = trim((string)($existing['claimed_at'] ?? ''));
                    $claimedTimestamp = $claimedAt === '' ? false : strtotime($claimedAt);
                    if ($claimedTimestamp !== false
                        && $claimedTimestamp <= time() - self::STALE_PENDING_AFTER_SECONDS
                    ) {
                        Db::name(self::DISPATCH_TABLE)
                            ->where('id', (int)$existing['id'])
                            ->where('delivery_status', 'pending')
                            ->where('claimed_at', $claimedAt)
                            ->where('attempt_count', (int)($existing['attempt_count'] ?? 0))
                            ->update([
                                'delivery_status' => 'outcome_unknown',
                                'error_summary' => 'durable_claim_lease_expired_outcome_unknown',
                                'update_time' => date('Y-m-d H:i:s'),
                            ]);
                        $existing = Db::name(self::DISPATCH_TABLE)
                            ->where('id', (int)$existing['id'])
                            ->find();
                    }
                    return [
                        'claimed' => false,
                        'result' => $this->dispatchWithoutAttempt(
                            is_array($existing) ? $existing : []
                        ),
                    ];
                }
                if (!$retryFailed || !$this->canRetryDispatch($existing)) {
                    return [
                        'claimed' => false,
                        'result' => $this->dispatchWithoutAttempt($existing),
                    ];
                }
            }

            $now = date('Y-m-d H:i:s');
            $claimTimestamp = $now;
            if (is_array($existing)) {
                $dispatchId = (int)$existing['id'];
                $existingStatus = (string)($existing['delivery_status'] ?? 'pending');
                $existingAttemptCount = (int)($existing['attempt_count'] ?? 0);
                $claimAttemptCount = $existingAttemptCount + 1;
                $claim = Db::name(self::DISPATCH_TABLE)
                    ->where('id', $dispatchId)
                    ->where('delivery_status', $existingStatus)
                    ->where('attempt_count', $existingAttemptCount);
                if ($existingStatus === 'pending') {
                    $existingClaimedAt = trim((string)($existing['claimed_at'] ?? ''));
                    if ($existingClaimedAt === '') {
                        $claim->whereNull('claimed_at');
                    } else {
                        $claim->where('claimed_at', $existingClaimedAt);
                    }
                }
                $claimed = $claim->update([
                    'trigger_type' => $triggerType,
                    'delivery_status' => 'pending',
                    'attempt_count' => $claimAttemptCount,
                    'error_summary' => null,
                    'claimed_at' => $now,
                    'created_by' => $userId,
                    'update_time' => $now,
                ]);
                if ($claimed !== 1) {
                    $current = Db::name(self::DISPATCH_TABLE)->where('id', $dispatchId)->find();
                    if (!is_array($current)) {
                        throw new \RuntimeException('dingdandao_pms_push_claim_failed');
                    }
                    return [
                        'claimed' => false,
                        'result' => $this->dispatchWithoutAttempt($current),
                    ];
                }
            } else {
                $claimAttemptCount = 1;
                try {
                    $dispatchId = (int)Db::name(self::DISPATCH_TABLE)->insertGetId([
                        'integration_id' => $expectedIntegrationId,
                        'tenant_id' => $tenantId,
                        'hotel_id' => $hotelId,
                        'capture_id' => $captureId,
                        'business_date' => (string)$capture['business_date'],
                        'source_fingerprint' => (string)$capture['source_fingerprint'],
                        'robot_id' => $expectedRobotId,
                        'trigger_type' => $triggerType,
                        'delivery_status' => 'pending',
                        'attempt_count' => $claimAttemptCount,
                        'claimed_at' => $now,
                        'created_by' => $userId,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                } catch (\Throwable) {
                    $claimed = Db::name(self::DISPATCH_TABLE)
                        ->where('integration_id', $expectedIntegrationId)
                        ->where('capture_id', $captureId)
                        ->where('robot_id', $expectedRobotId)
                        ->find();
                    if (!is_array($claimed)) {
                        throw new \RuntimeException('dingdandao_pms_push_claim_failed');
                    }
                    return [
                        'claimed' => false,
                        'result' => $this->dispatchWithoutAttempt($claimed),
                    ];
                }
            }

            if ($dispatchId <= 0) {
                throw new \RuntimeException('dingdandao_pms_push_claim_failed');
            }

            return [
                'claimed' => true,
                'dispatch_id' => $dispatchId,
                'claimed_at' => $claimTimestamp,
                'attempt_count' => $claimAttemptCount,
            ];
        });
    }

    /**
     * @param array<string,mixed> $capture
     * @return array<string,mixed>
     */
    private function deliverUnderLockedPolicy(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        array $capture,
        string $triggerType,
        int $expectedIntegrationId,
        int $expectedRobotId,
        string $expectedRobotName,
        int $dispatchId,
        string $claimTimestamp,
        int $claimAttemptCount
    ): array {
        return Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $hotelName,
            $capture,
            $triggerType,
            $expectedIntegrationId,
            $expectedRobotId,
            $expectedRobotName,
            $dispatchId,
            $claimTimestamp,
            $claimAttemptCount
        ): array {
            $lockedConfig = Db::name(self::INTEGRATION_TABLE)
                ->where('id', $expectedIntegrationId)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('provider', DingdandaoOperatingTargetCaptureService::PROVIDER)
                ->lock(true)
                ->find();
            $lockedRobot = Db::name('competitor_wechat_robot')
                ->where('id', $expectedRobotId)
                ->lock(true)
                ->find();
            $lockedDispatch = Db::name(self::DISPATCH_TABLE)
                ->where('id', $dispatchId)
                ->where('delivery_status', 'pending')
                ->where('claimed_at', $claimTimestamp)
                ->where('attempt_count', $claimAttemptCount)
                ->where('integration_id', $expectedIntegrationId)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('capture_id', (int)$capture['id'])
                ->where('robot_id', $expectedRobotId)
                ->where('trigger_type', $triggerType)
                ->lock(true)
                ->find();
            if (!is_array($lockedDispatch)) {
                $current = Db::name(self::DISPATCH_TABLE)->where('id', $dispatchId)->find();
                return $this->dispatchWithoutAttempt(
                    is_array($current) ? $current : []
                );
            }

            $lockedCapture = $this->authoritativeCaptureForDispatch(
                $tenantId,
                $hotelId,
                $capture,
                true
            );
            $lockedRobots = [];
            if ($this->lockedRobotMatchesSharedScope($lockedRobot, $tenantId, $hotelId)) {
                $lockedRobots[] = [
                    'id' => (int)$lockedRobot['id'],
                    'hotel_id' => (int)$lockedRobot['store_id'],
                    'name' => (string)$lockedRobot['name'],
                    'status' => (int)$lockedRobot['status'],
                    'last_tested_at' => $lockedRobot['last_tested_at'] ?? null,
                    'last_test_status' => $lockedRobot['last_test_status'] ?? null,
                ];
            }
            $lockedGate = $this->pushGate(
                is_array($lockedConfig) ? $lockedConfig : null,
                $lockedCapture,
                $lockedRobots,
                $triggerType
            );
            $blockers = (array)($lockedGate['blockers'] ?? []);
            if ($lockedCapture === null) {
                $blockers[] = $this->blocker(
                    'pms_capture_authoritative_readback_missing',
                    '推送前未能从数据库回读同门店、同日期、同指纹的订单来了事实。'
                );
            } elseif (
                !hash_equals(
                    (string)($lockedDispatch['source_fingerprint'] ?? ''),
                    (string)$lockedCapture['source_fingerprint']
                )
                || (string)($lockedDispatch['business_date'] ?? '')
                    !== (string)$lockedCapture['business_date']
            ) {
                $blockers[] = $this->blocker(
                    'pms_capture_dispatch_evidence_changed',
                    '推送认领记录与数据库采集事实不一致，已阻断外发。'
                );
            }
            $currentRobotId = is_array($lockedConfig)
                ? (int)($lockedConfig['robot_id'] ?? 0)
                : 0;
            $currentRobotName = is_array($lockedRobot)
                ? trim((string)($lockedRobot['name'] ?? ''))
                : '';
            if ($currentRobotId !== $expectedRobotId
                || $expectedRobotName === ''
                || !hash_equals($expectedRobotName, $currentRobotName)
            ) {
                $blockers[] = $this->blocker(
                    'pms_wecom_robot_changed',
                    '企业微信共享机器人已变更，请按最新配置重新发起推送。'
                );
            }
            if ($blockers !== []) {
                $codes = implode(',', array_column($this->uniqueBlockers($blockers), 'code'));
                Db::name(self::DISPATCH_TABLE)
                    ->where('id', $dispatchId)
                    ->where('delivery_status', 'pending')
                    ->where('claimed_at', $claimTimestamp)
                    ->where('attempt_count', $claimAttemptCount)
                    ->update([
                        'delivery_status' => 'failed',
                        'error_summary' => mb_strcut(
                            'policy_changed_before_send:' . $codes,
                            0,
                            500,
                            'UTF-8'
                        ),
                        'update_time' => date('Y-m-d H:i:s'),
                    ]);
                return [
                    'delivery_status' => 'blocked',
                    'delivery_attempted' => false,
                    'trigger_type' => $triggerType,
                    'blockers' => $this->uniqueBlockers($blockers),
                ];
            }

            $capture = $lockedCapture;
            $marked = Db::name(self::DISPATCH_TABLE)
                ->where('id', $dispatchId)
                ->where('delivery_status', 'pending')
                ->where('claimed_at', $claimTimestamp)
                ->where('attempt_count', $claimAttemptCount)
                ->update([
                    'delivery_status' => 'sending',
                    'error_summary' => 'delivery_outcome_pending',
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            if ($marked !== 1) {
                $current = Db::name(self::DISPATCH_TABLE)->where('id', $dispatchId)->find();
                return $this->dispatchWithoutAttempt(
                    is_array($current) ? $current : []
                );
            }

            $captureId = (int)$capture['id'];
            $payload = $this->buildPayload($capture, $hotelName);
            $delivery = $this->delivery !== null
                ? (array)call_user_func(
                    $this->delivery,
                    $hotelId,
                    $expectedRobotId,
                    $payload,
                    ['capture_id' => $captureId, 'business_date' => (string)$capture['business_date']]
                )
                : (new WechatRobotDeliveryService())->deliverToPlanRobot(
                    $tenantId,
                    $hotelId,
                    $expectedRobotId,
                    $expectedRobotName,
                    0,
                    'formal',
                    $payload
                );
            if ($this->afterDelivery !== null) {
                call_user_func($this->afterDelivery, $delivery, $dispatchId);
            }

            $deliveryStatus = strtolower(trim((string)($delivery['delivery_status'] ?? 'failed')));
            if (!in_array($deliveryStatus, ['sent', 'partial', 'failed', 'binding_missing'], true)) {
                $deliveryStatus = 'failed';
            }
            $errorSummary = $this->deliveryError($delivery);
            $deliveredAt = $deliveryStatus === 'sent' ? date('Y-m-d H:i:s') : null;
            $receipt = [
                'delivery_status' => $deliveryStatus,
                'robot_count' => (int)($delivery['robot_count'] ?? 0),
                'sent_count' => (int)($delivery['sent_count'] ?? 0),
                'failed_count' => (int)($delivery['failed_count'] ?? 0),
                'failures' => array_values(array_filter(
                    (array)($delivery['failures'] ?? []),
                    'is_array'
                )),
            ];
            $finishedAt = date('Y-m-d H:i:s');
            $finalized = Db::name(self::DISPATCH_TABLE)
                ->where('id', $dispatchId)
                ->where('delivery_status', 'sending')
                ->where('claimed_at', $claimTimestamp)
                ->where('attempt_count', $claimAttemptCount)
                ->update([
                    'delivery_status' => $deliveryStatus,
                    'delivery_receipt_json' => $this->json($receipt),
                    'error_summary' => $errorSummary,
                    'delivered_at' => $deliveredAt,
                    'update_time' => $finishedAt,
                ]);
            if ($finalized !== 1) {
                throw new \RuntimeException('dingdandao_pms_push_finalize_failed');
            }
            Db::name(self::INTEGRATION_TABLE)
                ->where('id', $expectedIntegrationId)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('provider', DingdandaoOperatingTargetCaptureService::PROVIDER)
                ->update([
                    'last_push_business_date' => (string)$capture['business_date'],
                    'last_push_status' => $deliveryStatus,
                    'last_push_at' => $deliveredAt ?? $finishedAt,
                    'last_push_error' => $errorSummary,
                    'update_time' => $finishedAt,
                ]);

            $stored = Db::name(self::DISPATCH_TABLE)->where('id', $dispatchId)->find();
            return $this->publicDispatch(is_array($stored) ? $stored : []) + [
                'delivery_attempted' => true,
            ];
        });
    }

    /**
     * Treats the caller value as a locator only. The payload always comes
     * from the exact persisted and readback-verified hotel capture.
     *
     * @param array<string,mixed> $locator
     * @return array<string,mixed>|null
     */
    private function authoritativeCaptureForDispatch(
        int $tenantId,
        int $hotelId,
        array $locator,
        bool $lock = false
    ): ?array {
        if (!$this->tableExists('dingdandao_operating_target_captures')) {
            return null;
        }
        $captureId = $this->positiveIntOrNull($locator['id'] ?? null);
        $businessDate = trim((string)($locator['business_date'] ?? ''));
        $fingerprint = strtolower(trim((string)($locator['source_fingerprint'] ?? '')));
        if ($captureId === null
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
        ) {
            return null;
        }

        $query = Db::name('dingdandao_operating_target_captures')
            ->where('id', $captureId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('provider', DingdandaoOperatingTargetCaptureService::PROVIDER)
            ->where('business_date', $businessDate)
            ->where('source_fingerprint', $fingerprint)
            ->where('readback_status', 'readback_verified');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            return null;
        }
        $capture = (new DingdandaoOperatingTargetCaptureService())
            ->read($tenantId, $hotelId, $captureId);
        if ((int)($capture['id'] ?? 0) !== $captureId
            || (int)($capture['tenant_id'] ?? 0) !== $tenantId
            || (int)($capture['hotel_id'] ?? 0) !== $hotelId
            || (string)($capture['business_date'] ?? '') !== $businessDate
            || !hash_equals(
                $fingerprint,
                strtolower(trim((string)($capture['source_fingerprint'] ?? '')))
            )
            || (string)($capture['readback_status'] ?? '') !== 'readback_verified'
        ) {
            return null;
        }
        return $capture;
    }

    /** @return array<string,mixed> */
    private function markClaimOutcomeUnknown(
        int $dispatchId,
        string $claimTimestamp,
        int $attemptCount
    ): array {
        Db::name(self::DISPATCH_TABLE)
            ->where('id', $dispatchId)
            ->where('delivery_status', 'pending')
            ->where('claimed_at', $claimTimestamp)
            ->where('attempt_count', $attemptCount)
            ->update([
                'delivery_status' => 'outcome_unknown',
                'error_summary' => 'delivery_transaction_failed_outcome_unknown',
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        $stored = Db::name(self::DISPATCH_TABLE)->where('id', $dispatchId)->find();
        return $this->publicDispatch(is_array($stored) ? $stored : []);
    }

    /** @param array<string,mixed>|null $config @param array<string,mixed> $capture */
    private function recordCaptureState(?array $config, array $capture): ?array
    {
        if (!is_array($config)) {
            return null;
        }
        if ($this->beforeCaptureStateLock !== null) {
            call_user_func($this->beforeCaptureStateLock, $config, $capture);
        }
        return Db::transaction(function () use ($config, $capture): ?array {
            $lockedConfig = Db::name(self::INTEGRATION_TABLE)
                ->where('id', (int)$config['id'])
                ->where('tenant_id', (int)($config['tenant_id'] ?? 0))
                ->where('hotel_id', (int)($config['hotel_id'] ?? 0))
                ->where('provider', DingdandaoOperatingTargetCaptureService::PROVIDER)
                ->lock(true)
                ->find();
            if (!is_array($lockedConfig)) {
                return null;
            }
            $providerHotelId = trim((string)($lockedConfig['provider_hotel_id'] ?? ''));
            $capturedProviderHotelId = trim((string)($capture['provider_hotel_id'] ?? ''));
            $canLearnProviderHotelId = $providerHotelId === ''
                && $capturedProviderHotelId !== ''
                && (int)($lockedConfig['status'] ?? 0) === 1
                && $this->sameText(
                    (string)($lockedConfig['provider_hotel_name'] ?? ''),
                    (string)($capture['provider_hotel_name'] ?? '')
                )
                && (string)($capture['identity_status'] ?? '') === 'matched'
                && (string)($capture['quality_status'] ?? '') === 'verified'
                && (string)($capture['reconciliation_status'] ?? '') === 'matched'
                && (string)($capture['readback_status'] ?? '') === 'readback_verified';
            $update = [
                'last_capture_id' => (int)($capture['id'] ?? 0) ?: null,
                'last_capture_business_date' => $capture['business_date'] ?? null,
                'last_capture_status' => $capture['quality_status']
                    ?? $capture['capture_status']
                    ?? 'unverified',
                'last_readback_status' => $capture['readback_status'] ?? 'unverified',
                'update_time' => date('Y-m-d H:i:s'),
            ];
            if ($canLearnProviderHotelId) {
                $update['provider_hotel_id'] = $capturedProviderHotelId;
            }
            Db::name(self::INTEGRATION_TABLE)
                ->where('id', (int)$lockedConfig['id'])
                ->update($update);
            return array_merge($lockedConfig, $update);
        });
    }

    /**
     * @param array<string,mixed>|null $config
     * @param array<string,mixed>|null $capture
     * @return array{allowed:bool,status:string,blockers:list<array{code:string,message:string}>}
     */
    private function factGate(?array $config, ?array $capture): array
    {
        $blockers = [];
        if (!is_array($config) || (int)($config['status'] ?? 0) !== 1) {
            $blockers[] = $this->blocker(
                'pms_integration_disabled',
                '请先启用订单来了门店绑定。'
            );
        }
        if (!is_array($capture) || (int)($capture['id'] ?? 0) <= 0) {
            $blockers[] = $this->blocker(
                'pms_capture_missing',
                '当前门店、当前日期没有订单来了采集记录。'
            );
        } else {
            foreach ([
                'quality_status' => 'verified',
                'capture_status' => 'verified',
                'identity_status' => 'matched',
                'reconciliation_status' => 'matched',
                'readback_status' => 'readback_verified',
            ] as $field => $expected) {
                if ((string)($capture[$field] ?? '') !== $expected) {
                    $blockers[] = $this->blocker(
                        'pms_' . $field . '_blocked',
                        '订单来了数据尚未通过身份、日期、明细对账与数据库回读门禁。'
                    );
                    break;
                }
            }

            $providerHotelId = trim((string)($config['provider_hotel_id'] ?? ''));
            $captureProviderHotelId = trim((string)($capture['provider_hotel_id'] ?? ''));
            if ($providerHotelId !== ''
                && ($captureProviderHotelId === ''
                    || !hash_equals($providerHotelId, $captureProviderHotelId))
            ) {
                $blockers[] = $this->blocker(
                    'pms_provider_hotel_id_mismatch',
                    '订单来了门店ID与当前维护绑定不一致。'
                );
            }
            if (!$this->sameText(
                (string)($config['provider_hotel_name'] ?? ''),
                (string)($capture['provider_hotel_name'] ?? '')
            )) {
                $blockers[] = $this->blocker(
                    'pms_provider_hotel_name_mismatch',
                    '订单来了门店名称与当前维护绑定不一致。'
                );
            }
        }

        $blockers = $this->uniqueBlockers($blockers);
        return [
            'allowed' => $blockers === [],
            'status' => $blockers === [] ? 'verified_fact_ready' : 'blocked',
            'blockers' => $blockers,
        ];
    }

    /**
     * @param array<string,mixed>|null $config
     * @param array<string,mixed>|null $capture
     * @param list<array<string,mixed>> $robots
     * @return array{allowed:bool,status:string,blockers:list<array{code:string,message:string}>}
     */
    private function pushGate(
        ?array $config,
        ?array $capture,
        array $robots,
        string $triggerType = 'manual'
    ): array {
        $blockers = [];
        if (!is_array($config) || (int)($config['status'] ?? 0) !== 1) {
            $blockers[] = $this->blocker('pms_integration_disabled', '请先启用订单来了门店绑定。');
        }
        if ($triggerType === 'capture' && (int)($config['auto_push_enabled'] ?? 0) !== 1) {
            $blockers[] = $this->blocker('pms_auto_push_disabled', '验证后自动推送尚未启用。');
        }
        $robotId = (int)($config['robot_id'] ?? 0);
        if ($robotId <= 0 || !in_array($robotId, array_column($robots, 'id'), true)) {
            $blockers[] = $this->blocker('pms_wecom_robot_missing', '请选择当前门店已启用的企业微信共享机器人。');
        } elseif (!$this->sharedRobotTestVerified($robots, $robotId)) {
            $blockers[] = $this->blocker(
                'pms_wecom_robot_test_required',
                '当前共享机器人尚未完成真实测试，或 Webhook 已变更；请先在企业微信推送页测试送达。'
            );
        }
        if (!is_array($capture) || (int)($capture['id'] ?? 0) <= 0) {
            $blockers[] = $this->blocker('pms_capture_missing', '当前门店、当前日期没有订单来了采集记录。');
        } else {
            $checks = [
                'quality_status' => 'verified',
                'capture_status' => 'verified',
                'identity_status' => 'matched',
                'reconciliation_status' => 'matched',
                'readback_status' => 'readback_verified',
            ];
            foreach ($checks as $field => $expected) {
                if ((string)($capture[$field] ?? '') !== $expected) {
                    $blockers[] = $this->blocker(
                        'pms_' . $field . '_blocked',
                        '订单来了数据尚未通过身份、日期、明细对账与数据库回读门禁。'
                    );
                    break;
                }
            }
            $providerHotelId = trim((string)($config['provider_hotel_id'] ?? ''));
            if ($providerHotelId === ''
                || !hash_equals($providerHotelId, trim((string)($capture['provider_hotel_id'] ?? '')))
            ) {
                $blockers[] = $this->blocker('pms_provider_hotel_id_mismatch', '订单来了门店ID与维护绑定不一致。');
            }
            if (!$this->sameText(
                (string)($config['provider_hotel_name'] ?? ''),
                (string)($capture['provider_hotel_name'] ?? '')
            )) {
                $blockers[] = $this->blocker('pms_provider_hotel_name_mismatch', '订单来了门店名称与维护绑定不一致。');
            }
        }

        return [
            'allowed' => $blockers === [],
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'blockers' => $this->uniqueBlockers($blockers),
        ];
    }

    /** @param array<string,mixed> $capture @return array{msgtype:string,markdown:array{content:string}} */
    private function buildPayload(array $capture, string $hotelName): array
    {
        $summary = is_array($capture['summary'] ?? null) ? $capture['summary'] : [];
        $region = is_array($capture['county_context'] ?? null)
            ? $capture['county_context']
            : [];
        $regionSummary = is_array($region['summary'] ?? null)
            ? $region['summary']
            : [];
        $trend = is_array($capture['trend'] ?? null) ? $capture['trend'] : [];
        $forward = is_array($capture['forward_room_status'] ?? null)
            ? $capture['forward_room_status']
            : [];
        $number = static function (mixed $value, int $decimals = 2): string {
            if ($value === null || $value === '' || is_bool($value) || !is_numeric($value)) {
                return '未取得';
            }
            $value = (float)$value;
            if (!is_finite($value) || $value < 0) {
                return '未取得';
            }
            return number_format($value, $decimals, '.', ',');
        };
        $comparison = static function (mixed $hotelValue, mixed $regionValue): string {
            if (!is_numeric($hotelValue) || !is_numeric($regionValue)
                || (float)$regionValue <= 0
            ) {
                return '暂无法比较';
            }
            $delta = (((float)$hotelValue / (float)$regionValue) - 1) * 100;
            return '本店较区域 ' . ($delta >= 0 ? '↑' : '↓')
                . number_format(abs($delta), 2, '.', ',') . '%';
        };
        $trendDefinitions = [
            'total_room_fee' => ['总房费', '¥', ''],
            'adr' => ['平均房价 ADR', '¥', ''],
            'occupancy_rate_percent' => ['入住率 OCC', '', '%'],
            'revpar' => ['平均客房收益 RevPAR', '¥', ''],
            'sold_room_nights' => ['间夜数', '', ' 间夜'],
        ];
        $trendLines = [];
        $trendDates = [];
        foreach ($trendDefinitions as $key => [$label, $prefix, $suffix]) {
            $points = is_array($trend[$key] ?? null)
                ? array_slice($trend[$key], -7)
                : [];
            $values = [];
            foreach ($points as $point) {
                $date = trim((string)($point['date'] ?? ''));
                $value = $point['value'] ?? null;
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                    || !is_numeric($value)
                    || (float)$value < 0
                ) {
                    continue;
                }
                $trendDates[] = $date;
                $values[] = mb_substr($date, 5, 5)
                    . ' ' . $prefix . $number($value) . $suffix;
            }
            $trendLines[] = '- ' . $label . '：'
                . ($values === [] ? '未取得（不补 0）' : implode(' → ', $values));
        }
        sort($trendDates);
        $trendRange = $trendDates === []
            ? '日期未取得'
            : mb_substr($trendDates[0], 5, 5)
                . ' 至 ' . mb_substr($trendDates[count($trendDates) - 1], 5, 5);
        $lines = [
            '# 宿析OS 订单来了 PMS 经营事实',
            '> 门店：' . $this->safeText($hotelName, 80),
            '> 经营日期：' . $this->safeText((string)($capture['business_date'] ?? '未取得'), 20),
            '> 来源：订单来了住宿数据中心；区域数据仅作诊断参考',
            '> 数据状态：身份、明细对账与数据库回读均已验证',
            '',
            '**住宿经营事实**',
            '- 客房收入：¥' . $number($summary['total_room_fee'] ?? null),
            '- 已售间夜：' . $number($summary['sold_room_nights'] ?? null) . ' 间夜',
            '- ADR：¥' . $number($summary['adr'] ?? null),
            '- OCC：' . $number($summary['occupancy_rate_percent'] ?? null) . '%',
            '- RevPAR：¥' . $number($summary['revpar'] ?? null),
            '- 可售房夜：' . $number($summary['derived_sellable_room_nights'] ?? null) . ' 间夜',
            '',
        ];
        $lines[] = '**远期房态（累计窗口）**';
        $lines[] = '> 仅展示未来 3/7/14/21 天累计；来源为订单来了 PMS。';
        $forwardLines = [];
        if (($forward['fact_scope'] ?? '') === 'whole_hotel_forward_room_status'
            && ($forward['data_status'] ?? '') === 'verified'
            && ($forward['readback_status'] ?? '') === 'readback_verified'
        ) {
            $allowedHorizons = [3 => true, 7 => true, 14 => true, 21 => true];
            $horizonRows = [];
            foreach ((array)($forward['horizons'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $days = (int)($row['horizon_days'] ?? 0);
                if (!isset($allowedHorizons[$days])
                    || (string)($row['quality_status'] ?? '') !== 'verified'
                ) {
                    continue;
                }
                $horizonRows[$days] = $row;
            }
            foreach (array_keys($allowedHorizons) as $days) {
                $row = $horizonRows[$days] ?? null;
                if (!is_array($row)) {
                    continue;
                }
                $metrics = [];
                foreach ([
                    ['booked_room_nights', '已订', ' 间夜', 0],
                    ['remaining_sellable_room_nights', '剩余可售', ' 间夜', 0],
                    ['occupancy_rate_percent', 'OCC ', '%', 2],
                    ['adr', 'ADR ¥', '', 2],
                    ['revpar', 'RevPAR ¥', '', 2],
                ] as [$key, $prefix, $suffix, $decimals]) {
                    $value = $row[$key] ?? null;
                    if (!is_numeric($value) || (float)$value < 0) {
                        continue;
                    }
                    $metrics[] = $prefix . $number($value, $decimals) . $suffix;
                }
                if ($metrics === []) {
                    continue;
                }
                $dateFrom = trim((string)($row['date_from'] ?? ''));
                $dateTo = trim((string)($row['date_to'] ?? ''));
                $range = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)
                    ? '（' . mb_substr($dateFrom, 5, 5)
                        . ' 至 ' . mb_substr($dateTo, 5, 5) . '）'
                    : '';
                $forwardLines[] = '- ' . $days . ' 天' . $range
                    . '：' . implode('｜', $metrics);
            }
        }
        $lines[] = $forwardLines === []
            ? '- 状态：远期数据未取得或未通过回读（不补 0）'
            : implode("\n", $forwardLines);
        $lines[] = '';
        $regionName = $this->safeText((string)($region['region_name'] ?? '区域未取得'), 120);
        $lines[] = '**区域参考（' . $regionName . '）**';
        $lines[] = '> 区域均值是诊断基准，不是本店经营事实。';
        if (($region['fact_scope'] ?? '') === 'county_diagnostic_only'
            && ($region['data_status'] ?? '') === 'readable_separate'
        ) {
            $regionalMetrics = [
                ['门店平均总房费', 'total_room_fee', '¥', ''],
                ['平均房价 ADR', 'adr', '¥', ''],
                ['入住率 OCC', 'occupancy_rate_percent', '', '%'],
                ['平均客房收益 RevPAR', 'revpar', '¥', ''],
                ['门店平均累计出租间夜数', 'sold_room_nights', '', ' 间夜'],
                ['门店平均每日间夜数', 'average_daily_room_nights', '', ' 间夜'],
            ];
            foreach ($regionalMetrics as [$label, $key, $prefix, $suffix]) {
                $lines[] = '- ' . $label . '：' . $prefix
                    . $number($regionSummary[$key] ?? null) . $suffix
                    . '（' . $comparison(
                        $summary[$key] ?? null,
                        $regionSummary[$key] ?? null
                    ) . '）';
            }
        } else {
            $lines[] = '- 状态：区域数据未取得或不完整（不补 0）';
        }
        $lines[] = '';
        $lines[] = '**经营指标趋势（' . $trendRange . '）**';
        array_push($lines, ...$trendLines);
        $lines[] = '';
        $lines[] = '**核验依据**';
        $lines[] = '- 订单来了门店：'
            . $this->safeText((string)($capture['provider_hotel_name'] ?? '未取得'), 100);
        $lines[] = '- 房费明细：' . (int)($capture['detail_row_count'] ?? 0)
            . ' 行；合计 ¥' . $number($capture['detail_room_fee_total'] ?? null);
        $lines[] = '- 采集时间：'
            . $this->safeText((string)($capture['captured_at'] ?? '未取得'), 30);
        $lines[] = '';
        $lines[] = '> 口径：订单来了住宿数据中心客房收入；不包含未返回的餐饮、会议等非房收入。';
        $lines[] = '> 本消息只读取已保存并回读的数据，不重新采集、不修改 PMS 或 OTA。';
        return [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => mb_strcut(implode("\n", $lines), 0, 3800, 'UTF-8'),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function sharedRobots(int $tenantId, int $hotelId): array
    {
        if (!$this->tableExists('competitor_wechat_robot')) {
            return [];
        }
        $query = Db::name('competitor_wechat_robot')
            ->where('store_id', $hotelId)
            ->where('status', 1)
            ->where(function ($query): void {
                $query->whereNull('owner_user_id')->whereOr('owner_user_id', 0);
            })
            ->where(function ($query): void {
                $query->whereNull('notification_scope')
                    ->whereOr('notification_scope', '')
                    ->whereOr('notification_scope', self::SHARED_NOTIFICATION_SCOPE);
            })
            ->order('id', 'asc');
        $fields = $this->tableFields('competitor_wechat_robot');
        if (isset($fields['tenant_id'])) {
            $query->where('tenant_id', $tenantId);
        }
        $fieldList = 'id,store_id,name,status,last_tested_at,last_test_status';
        if (isset($fields['tenant_id'])) {
            $fieldList .= ',tenant_id';
        }
        $rows = $query
            ->field($fieldList)
            ->select()
            ->toArray();
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'tenant_id' => array_key_exists('tenant_id', $row)
                ? (int)$row['tenant_id']
                : null,
            'hotel_id' => (int)$row['store_id'],
            'name' => (string)$row['name'],
            'status' => (int)$row['status'],
            'last_tested_at' => $row['last_tested_at'] ?? null,
            'last_test_status' => $row['last_test_status'] ?? null,
        ], $rows);
    }

    private function assertSharedRobot(int $tenantId, int $hotelId, int $robotId): void
    {
        $matched = array_filter(
            $this->sharedRobots($tenantId, $hotelId),
            static fn(array $robot): bool => (int)$robot['id'] === $robotId
        );
        if ($matched === []) {
            throw new \InvalidArgumentException('dingdandao_pms_robot_invalid');
        }
    }

    /** @param array<string,mixed>|null $robot */
    private function lockedRobotMatchesSharedScope(
        ?array $robot,
        int $tenantId,
        int $hotelId
    ): bool
    {
        if (!is_array($robot)
            || (int)($robot['store_id'] ?? 0) !== $hotelId
            || (int)($robot['status'] ?? 0) !== 1
            || (int)($robot['owner_user_id'] ?? 0) !== 0
            || (array_key_exists('tenant_id', $robot)
                && (int)($robot['tenant_id'] ?? 0) !== $tenantId)
        ) {
            return false;
        }
        return in_array(
            trim((string)($robot['notification_scope'] ?? '')),
            ['', self::SHARED_NOTIFICATION_SCOPE],
            true
        );
    }

    /** @param list<array<string,mixed>> $robots */
    private function sharedRobotTestVerified(array $robots, ?int $robotId): bool
    {
        if ($robotId === null || $robotId <= 0) {
            return false;
        }
        foreach ($robots as $robot) {
            if ((int)($robot['id'] ?? 0) !== $robotId) {
                continue;
            }
            return trim((string)($robot['last_tested_at'] ?? '')) !== ''
                && in_array(
                    strtolower(trim((string)($robot['last_test_status'] ?? ''))),
                    ['success', 'sent'],
                    true
                );
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    private function configRow(int $tenantId, int $hotelId): ?array
    {
        if (!$this->tableExists(self::INTEGRATION_TABLE)) {
            return null;
        }
        $row = Db::name(self::INTEGRATION_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('provider', DingdandaoOperatingTargetCaptureService::PROVIDER)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function captureForStatus(int $tenantId, int $hotelId, string $businessDate): ?array
    {
        if (!$this->tableExists('dingdandao_operating_target_captures')) {
            return null;
        }
        $query = Db::name('dingdandao_operating_target_captures')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->order('id', 'desc');
        if (trim($businessDate) !== '') {
            $query->where('business_date', $this->date($businessDate));
        }
        $row = $query->find();
        if (!is_array($row)) {
            return null;
        }
        return (new DingdandaoOperatingTargetCaptureService())
            ->read($tenantId, $hotelId, (int)$row['id']);
    }

    /** @return array<string,mixed>|null */
    private function latestDispatch(int $tenantId, int $hotelId): ?array
    {
        if (!$this->tableExists(self::DISPATCH_TABLE)) {
            return null;
        }
        $row = Db::name(self::DISPATCH_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->order('id', 'desc')
            ->find();
        return is_array($row) ? $this->publicDispatch($row) : null;
    }

    /** @return array<string,mixed>|null */
    private function profileStatus(int $hotelId, int $userId): ?array
    {
        try {
            $status = (new CloudBrowserProfileService())->status($hotelId, $userId, 'dingdandao');
            $profile = $status['profiles'][0] ?? null;
            return is_array($profile) ? $profile : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed>|null $config
     * @param array<string,mixed>|null $capture
     * @return array<string,mixed>
     */
    private function stableMasterData(
        int $tenantId,
        int $hotelId,
        ?array $config,
        ?array $capture
    ): array {
        $hotel = $this->hotelMasterRow($tenantId, $hotelId);
        $hotelUpdatedAt = $this->textOrNull($hotel['update_time'] ?? null, 32);

        $systemHotelName = $this->textOrNull($hotel['name'] ?? null, 160);
        $systemHotelCode = $this->textOrNull($hotel['code'] ?? null, 80);
        $address = $this->textOrNull($hotel['address'] ?? null, 255);
        $addressSource = $address === null ? 'missing' : 'suxios_hotel_master';
        $addressSourceLabel = $address === null ? '酒店主档尚未维护' : '宿析OS酒店主档';
        $addressStatus = $address === null ? 'missing' : 'master';
        $addressUpdatedAt = $address === null ? null : $hotelUpdatedAt;

        $city = $this->textOrNull($hotel['city'] ?? null, 80);
        $citySource = $city === null ? 'missing' : 'suxios_hotel_master';
        $citySourceLabel = $city === null ? '酒店主档尚未维护' : '宿析OS酒店主档';
        $cityStatus = $city === null ? 'missing' : 'master';
        $cityUpdatedAt = $city === null ? null : $hotelUpdatedAt;

        $roomCount = $this->hotelRoomCount($hotel);
        $roomCountSource = 'suxios_hotel_master';
        $roomCountSourceLabel = '宿析OS酒店主档';
        $roomCountStatus = 'master';
        $roomCountUpdatedAt = $hotelUpdatedAt;
        if ($roomCount === null) {
            $roomCount = $this->enabledRoomTypeCount($tenantId, $hotelId);
            $roomCountSource = 'suxios_room_types';
            $roomCountSourceLabel = '宿析OS已启用房型合计';
            $roomCountStatus = 'master';
            $roomCountUpdatedAt = null;
        }
        if ($roomCount === null) {
            $roomCountSource = 'missing';
            $roomCountSourceLabel = '酒店主档或已启用房型尚未维护';
            $roomCountStatus = 'missing';
            $roomCountUpdatedAt = null;
        }

        $providerHotelId = $this->textOrNull($config['provider_hotel_id'] ?? null, 120);
        $providerHotelIdSource = 'dingdandao_verified_binding';
        $providerHotelIdSourceLabel = '订单来了已保存绑定';
        $providerHotelIdStatus = 'fixed';
        $providerHotelIdUpdatedAt = $this->textOrNull($config['update_time'] ?? null, 32);
        if ($providerHotelId === null && $this->captureCanAutofillProviderId($capture)) {
            $providerHotelId = $this->textOrNull($capture['provider_hotel_id'] ?? null, 120);
            $providerHotelIdSource = 'dingdandao_verified_capture';
            $providerHotelIdSourceLabel = '订单来了可信采集回读';
            $providerHotelIdStatus = $providerHotelId === null ? 'missing' : 'verified';
            $providerHotelIdUpdatedAt = $this->textOrNull($capture['captured_at'] ?? null, 32);
        }
        if ($providerHotelId === null) {
            $providerHotelIdSource = 'missing';
            $providerHotelIdSourceLabel = '等待订单来了可信采集回读';
            $providerHotelIdStatus = 'missing';
            $providerHotelIdUpdatedAt = null;
        }

        $items = [
            $this->masterDataItem(
                'system_hotel_id',
                '宿析内部ID',
                $hotelId,
                'suxios_scope',
                '当前授权门店',
                'fixed',
                null
            ),
            $this->masterDataItem(
                'system_hotel_code',
                '宿析门店编号',
                $systemHotelCode,
                $systemHotelCode === null ? 'missing' : 'suxios_hotel_master',
                $systemHotelCode === null ? '酒店主档尚未维护' : '宿析OS酒店主档',
                $systemHotelCode === null ? 'missing' : 'master',
                $hotelUpdatedAt
            ),
            $this->masterDataItem(
                'system_hotel_name',
                '宿析门店名称',
                $systemHotelName,
                $systemHotelName === null ? 'missing' : 'suxios_hotel_master',
                $systemHotelName === null ? '酒店主档尚未维护' : '宿析OS酒店主档',
                $systemHotelName === null ? 'missing' : 'master',
                $hotelUpdatedAt
            ),
            $this->masterDataItem(
                'city',
                '所在城市',
                $city,
                $citySource,
                $citySourceLabel,
                $cityStatus,
                $cityUpdatedAt
            ),
            $this->masterDataItem(
                'address',
                '门店位置',
                $address,
                $addressSource,
                $addressSourceLabel,
                $addressStatus,
                $addressUpdatedAt
            ),
            $this->masterDataItem(
                'physical_room_count',
                '物理房量',
                $roomCount,
                $roomCountSource,
                $roomCountSourceLabel,
                $roomCountStatus,
                $roomCountUpdatedAt,
                '间'
            ),
            $this->masterDataItem(
                'provider_hotel_id',
                '订单来了门店ID',
                $providerHotelId,
                $providerHotelIdSource,
                $providerHotelIdSourceLabel,
                $providerHotelIdStatus,
                $providerHotelIdUpdatedAt
            ),
        ];
        $values = [];
        $missing = [];
        foreach ($items as $item) {
            $values[(string)$item['key']] = $item['value'];
            if (($item['status'] ?? '') === 'missing') {
                $missing[] = (string)$item['key'];
            }
        }

        return [
            'items' => $items,
            'values' => $values,
            'missing_fields' => $missing,
            'dynamic_fields_excluded' => [
                'business_date',
                'sellable_room_nights',
                'sold_room_nights',
                'occupancy_rate_percent',
                'adr',
                'revpar',
                'total_room_fee',
            ],
            'notice' => '地址与物理房量属于长期稳定主数据，但仍可能因搬迁、改造或停用房调整；不会自动替代某日可售房夜。',
        ];
    }

    /** @return array<string,mixed>|null */
    private function hotelMasterRow(int $tenantId, int $hotelId): ?array
    {
        $fields = $this->tableFields('hotels');
        if (!isset($fields['id'])) {
            return null;
        }
        $selected = array_values(array_intersect(
            ['id', 'tenant_id', 'name', 'code', 'address', 'city', 'room_count', 'rooms_total', 'update_time'],
            array_keys($fields)
        ));
        $query = Db::name('hotels')->where('id', $hotelId);
        if (isset($fields['tenant_id'])) {
            $query->where('tenant_id', $tenantId);
        }
        $row = $query->field(implode(',', $selected))->find();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed>|null $hotel */
    private function hotelRoomCount(?array $hotel): ?int
    {
        if (!is_array($hotel)) {
            return null;
        }
        foreach (['room_count', 'rooms_total'] as $key) {
            $value = $this->positiveIntOrNull($hotel[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function enabledRoomTypeCount(int $tenantId, int $hotelId): ?int
    {
        $fields = $this->tableFields('room_types');
        if (!isset($fields['hotel_id'], $fields['room_count'])) {
            return null;
        }
        $query = Db::name('room_types')
            ->where('hotel_id', $hotelId)
            ->where('room_count', '>', 0);
        if (isset($fields['tenant_id'])) {
            $query->where('tenant_id', $tenantId);
        }
        if (isset($fields['is_enabled'])) {
            $query->where('is_enabled', 1);
        }
        $total = (int)$query->sum('room_count');
        return $total > 0 ? $total : null;
    }

    /** @param array<string,mixed>|null $capture */
    private function captureCanAutofillProviderId(?array $capture): bool
    {
        return is_array($capture)
            && trim((string)($capture['provider_hotel_id'] ?? '')) !== ''
            && (string)($capture['identity_status'] ?? '') === 'matched'
            && (string)($capture['capture_status'] ?? '') === 'verified'
            && (string)($capture['quality_status'] ?? '') === 'verified'
            && (string)($capture['reconciliation_status'] ?? '') === 'matched'
            && (string)($capture['readback_status'] ?? '') === 'readback_verified';
    }

    /** @return array<string,mixed> */
    private function masterDataItem(
        string $key,
        string $label,
        mixed $value,
        string $source,
        string $sourceLabel,
        string $status,
        ?string $updatedAt,
        string $unit = ''
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'unit' => $unit,
            'source' => $source,
            'source_label' => $sourceLabel,
            'status' => $status,
            'status_label' => match ($status) {
                'fixed' => '固定标识',
                'verified' => '已验证',
                'master' => '主档值',
                'reference' => '参考候选',
                default => '待补',
            },
            'updated_at' => $updatedAt,
        ];
    }

    /** @return array<string,bool> */
    private function tableFields(string $table): array
    {
        try {
            $fields = Db::getTableInfo($table, 'fields');
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($fields)) {
            return [];
        }
        return array_fill_keys(array_map('strval', $fields), true);
    }

    /**
     * @param array<string,mixed>|null $config
     * @param array<string,mixed>|null $capture
     * @param array<string,mixed> $masterData
     */
    private function publicConfig(?array $config, ?array $capture, array $masterData): array
    {
        $values = is_array($masterData['values'] ?? null) ? $masterData['values'] : [];
        $providerHotelId = $this->textOrNull($config['provider_hotel_id'] ?? null, 120)
            ?? $this->textOrNull($values['provider_hotel_id'] ?? null, 120);
        $providerHotelName = $this->textOrNull($config['provider_hotel_name'] ?? null, 160)
            ?? $this->textOrNull($capture['provider_hotel_name'] ?? null, 160)
            ?? $this->textOrNull($values['system_hotel_name'] ?? null, 160);
        return [
            'id' => (int)($config['id'] ?? 0),
            'configured' => is_array($config),
            'provider_hotel_id' => $providerHotelId,
            'provider_hotel_name' => $providerHotelName,
            'robot_id' => $this->positiveIntOrNull($config['robot_id'] ?? null),
            'status' => (int)($config['status'] ?? 0) === 1,
            'auto_push_enabled' => (int)($config['auto_push_enabled'] ?? 0) === 1,
            'last_capture_id' => $this->positiveIntOrNull($config['last_capture_id'] ?? null),
            'last_capture_business_date' => $config['last_capture_business_date'] ?? null,
            'last_capture_status' => $config['last_capture_status'] ?? null,
            'last_readback_status' => $config['last_readback_status'] ?? null,
            'last_push_business_date' => $config['last_push_business_date'] ?? null,
            'last_push_status' => $config['last_push_status'] ?? null,
            'last_push_at' => $config['last_push_at'] ?? null,
            'last_push_error' => $config['last_push_error'] ?? null,
            'updated_at' => $config['update_time'] ?? null,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicDispatch(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'capture_id' => (int)($row['capture_id'] ?? 0),
            'business_date' => $row['business_date'] ?? null,
            'robot_id' => (int)($row['robot_id'] ?? 0),
            'trigger_type' => (string)($row['trigger_type'] ?? ''),
            'delivery_status' => (string)($row['delivery_status'] ?? 'pending'),
            'attempt_count' => (int)($row['attempt_count'] ?? 0),
            'error_summary' => $row['error_summary'] ?? null,
            'claimed_at' => $row['claimed_at'] ?? null,
            'delivered_at' => $row['delivered_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function dispatchWithoutAttempt(array $row): array
    {
        $result = $this->publicDispatch($row);
        if (($result['delivery_status'] ?? '') === 'sent') {
            $result['delivery_status'] = 'already_sent';
        }
        $result['delivery_attempted'] = false;
        return $result;
    }

    /** @param array<string,mixed> $row */
    private function canRetryDispatch(array $row): bool
    {
        $status = strtolower(trim((string)($row['delivery_status'] ?? 'pending')));
        return in_array($status, self::RETRYABLE_DELIVERY_STATUSES, true);
    }

    /** @param array<string,mixed> $delivery */
    private function deliveryError(array $delivery): ?string
    {
        $failures = array_values(array_filter((array)($delivery['failures'] ?? []), 'is_array'));
        $parts = [];
        foreach ($failures as $failure) {
            $reason = trim((string)($failure['reason'] ?? ''));
            if ($reason !== '') {
                $parts[] = $reason;
            }
        }
        if ($parts === [] && trim((string)($delivery['reason'] ?? '')) !== '') {
            $parts[] = (string)$delivery['reason'];
        }
        return $parts === [] ? null : $this->safeText(implode('；', $parts), 500);
    }

    /** @return array{code:string,message:string} */
    private function blocker(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /** @param list<array{code:string,message:string}> $blockers @return list<array{code:string,message:string}> */
    private function uniqueBlockers(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker['code']] = $blocker;
        }
        return array_values($unique);
    }

    private function assertScope(int $tenantId, int $hotelId, int $userId): void
    {
        if ($tenantId <= 0 || $hotelId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('dingdandao_pms_scope_invalid');
        }
    }

    private function assertTablesReady(): void
    {
        if (!$this->tableExists(self::INTEGRATION_TABLE)
            || !$this->tableExists(self::DISPATCH_TABLE)
            || !$this->tableExists('dingdandao_operating_target_captures')
        ) {
            throw new \RuntimeException('dingdandao_pms_tables_missing');
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return Db::getTableInfo($table, 'fields') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($validated) && $validated > 0 ? $validated : null;
    }

    private function textOrNull(mixed $value, int $limit): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $text = $this->safeText((string)$value, $limit);
        return $text === '' ? null : $text;
    }

    private function sameText(string $left, string $right): bool
    {
        $normalize = static fn(string $value): string =>
            mb_strtolower(preg_replace('/\s+/u', '', trim($value)) ?? '', 'UTF-8');
        $left = $normalize($left);
        $right = $normalize($right);
        return $left !== '' && $right !== '' && hash_equals($left, $right);
    }

    private function safeText(string $value, int $limit): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $value = str_replace(['<', '>'], ['＜', '＞'], $value);
        return mb_substr($value, 0, max(1, $limit), 'UTF-8');
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('dingdandao_pms_date_invalid');
        }
        return $value;
    }

    private function json(array $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
