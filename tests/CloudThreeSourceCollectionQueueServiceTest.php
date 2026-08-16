<?php
declare(strict_types=1);

namespace tests;

use app\service\CloudThreeSourceCollectionQueueService;
use PHPUnit\Framework\TestCase;

final class CloudThreeSourceCollectionQueueServiceTest extends TestCase
{
    public function testEligibleHotelsRunGloballySerialInPmsCtripMeituanOrderWithoutSending(): void
    {
        $plans = [
            $this->plan(2, 81, 31, 32),
            $this->plan(1, 80, 21, 22),
            [...$this->plan(3, 82, 41, 42), 'enabled' => 0, 'active_slot' => null],
        ];
        $calls = [];
        $service = $this->service($plans, $calls);

        $receipt = $service->run([
            'target_date' => '2026-08-14',
            'child_timeout_seconds' => 300,
            'deadline_seconds' => 1200,
        ]);

        self::assertSame('all_hotels_saved_and_readback_verified', $receipt['status']);
        self::assertSame(2, $receipt['eligible_plan_count']);
        self::assertSame(2, $receipt['verified_hotel_count']);
        self::assertSame(0, $receipt['blocked_hotel_count']);
        self::assertSame([
            [80, 'pms'], [80, 'ctrip'], [80, 'meituan'],
            [81, 'pms'], [81, 'ctrip'], [81, 'meituan'],
        ], array_map(
            static fn(array $call): array => [$call['hotel_id'], $call['source']],
            $calls
        ));
        self::assertContains('--no-push', $calls[0]['command']);
        self::assertNotContains('--no-push', $calls[1]['command']);
        foreach ($calls as $call) {
            self::assertContains(
                '--control-token-file=/run/credentials/suxios-cloud-three-source-queue.service/control-token',
                $call['command']
            );
            self::assertSame(300, $call['timeout_seconds']);
        }
        $dispatcherIds = [];
        foreach (array_filter($calls, static fn(array $call): bool => $call['source'] !== 'pms') as $call) {
            $argument = current(array_values(array_filter(
                $call['command'],
                static fn(string $part): bool => str_starts_with($part, '--dispatcher-run-id=')
            )));
            self::assertIsString($argument);
            $dispatcherIds[(int)$call['hotel_id']][] = substr((string)$argument, 20);
        }
        self::assertCount(1, array_unique($dispatcherIds[80]));
        self::assertCount(1, array_unique($dispatcherIds[81]));
        self::assertSame('collected', $receipt['hotels'][0]['run_receipt_status']);
        self::assertTrue($receipt['hotels'][0]['run_receipt_structure_verified']);
        self::assertFalse($receipt['hotels'][0]['run_receipt_readback_verified']);
        self::assertFalse($receipt['message_sent']);
        $encoded = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('cbp_', $encoded);
    }

    public function testOneSourceFailureDoesNotStopRemainingSourcesOrNextHotel(): void
    {
        $plans = [
            $this->plan(1, 80, 21, 22),
            $this->plan(2, 81, 31, 32),
        ];
        $calls = [];
        $child = function (
            string $source,
            array $command,
            int $timeoutSeconds,
            array $context
        ) use (&$calls): array {
            $calls[] = [
                'source' => $source,
                'hotel_id' => (int)$context['system_hotel_id'],
                'command' => $command,
                'timeout_seconds' => $timeoutSeconds,
            ];
            if ((int)$context['system_hotel_id'] === 80 && $source === 'ctrip') {
                return [
                    'exit_code' => 1,
                    'timed_out' => false,
                    'receipt' => [
                        'status' => 'blocked',
                        'reason' => 'capture_session_expired',
                        'message_sent' => false,
                    ],
                ];
            }
            return $this->successChild($source);
        };

        $receipt = $this->service($plans, $calls, $child)->run([
            'target_date' => '2026-08-14',
        ]);

        self::assertSame('partial_or_blocked', $receipt['status']);
        self::assertSame(1, $receipt['verified_hotel_count']);
        self::assertSame(1, $receipt['blocked_hotel_count']);
        self::assertCount(6, $calls);
        self::assertSame([80, 'meituan'], [$calls[2]['hotel_id'], $calls[2]['source']]);
        self::assertSame([81, 'pms'], [$calls[3]['hotel_id'], $calls[3]['source']]);
        $ctrip = $receipt['hotels'][0]['sources'][1];
        self::assertSame('blocked', $ctrip['status']);
        self::assertSame('capture_session_expired', $ctrip['reason']);
        self::assertSame('partial', $receipt['hotels'][0]['run_receipt_status']);
        self::assertTrue($receipt['hotels'][0]['run_receipt_structure_verified']);
        self::assertFalse($receipt['message_sent']);
    }

    public function testMeituanCloudPmsPlanUsesItsExactProfileAndNoPushRunner(): void
    {
        $plans = [$this->plan(1, 80, 21, 22, 'meituan_cloud_pms')];
        $calls = [];
        $profiles = fn(int $tenantId, int $hotelId, int $ownerUserId): array =>
            $this->profiles($hotelId, $ownerUserId, 'meituan_cloud_pms');

        $receipt = $this->service($plans, $calls, null, $profiles)->run([
            'target_date' => '2026-08-14',
        ]);

        self::assertSame('all_hotels_saved_and_readback_verified', $receipt['status']);
        self::assertStringEndsWith(
            '/scripts/run_meituan_cloud_pms_collection.php',
            str_replace('\\', '/', (string)$calls[0]['command'][3])
        );
        self::assertContains('--no-push', $calls[0]['command']);
        self::assertContains(
            '--profile-id=cbp_meituan_cloud_pms_hotel_80_abcdef',
            $calls[0]['command']
        );
        self::assertSame([
            [80, 'pms'], [80, 'ctrip'], [80, 'meituan'],
        ], array_map(
            static fn(array $call): array => [$call['hotel_id'], $call['source']],
            $calls
        ));
        self::assertFalse($receipt['message_sent']);
    }

    public function testMissingExactProfileBlocksOnlyThatHotelBeforeStartingChildren(): void
    {
        $plans = [
            $this->plan(1, 80, 21, 22),
            $this->plan(2, 81, 31, 32),
        ];
        $calls = [];
        $profiles = function (int $tenantId, int $hotelId, int $ownerUserId): array {
            $rows = $this->profiles($hotelId, $ownerUserId);
            return $hotelId === 80
                ? array_values(array_filter(
                    $rows,
                    static fn(array $row): bool => $row['platform'] !== 'dingdandao'
                ))
                : $rows;
        };

        $receipt = $this->service($plans, $calls, null, $profiles)->run([
            'target_date' => '2026-08-14',
        ]);

        self::assertSame('partial_or_blocked', $receipt['status']);
        self::assertSame('cloud_profile_binding_missing_dingdandao', $receipt['hotels'][0]['reason']);
        self::assertSame([
            [81, 'pms'], [81, 'ctrip'], [81, 'meituan'],
        ], array_map(
            static fn(array $call): array => [$call['hotel_id'], $call['source']],
            $calls
        ));
        self::assertFalse($receipt['message_sent']);
    }

    public function testBlockedAuthorizationPersistsATerminalRunReceiptBeforeStartingChildren(): void
    {
        $plan = $this->plan(1, 80, 21, 22);
        $calls = [];
        $authorization = static function (
            array $hotel,
            string $targetDate,
            array $sourceIds,
            array $platforms,
            string $runMode
        ) use ($plan): array {
            return [
                'status' => 'blocked',
                'collection_allowed' => false,
                'tenant_id' => (int)$hotel['tenant_id'],
                'system_hotel_id' => (int)$hotel['id'],
                'business_date' => $targetDate,
                'run_mode' => $runMode,
                'plan_id' => (int)$plan['id'],
                'plan_hash' => (string)$plan['plan_hash'],
                'plan_readback_verified' => true,
                'binding_digest_matches' => false,
                'execution_owner_user_id' => (int)$plan['execution_owner_user_id'],
                'actual_source_ids' => $sourceIds,
                'actual_platforms' => $platforms,
                'failure_reasons' => [[
                    'code' => 'hotel_collection_binding_readback_unverified',
                    'platform' => '',
                ]],
            ];
        };

        $receipt = $this->service(
            [$plan],
            $calls,
            null,
            null,
            null,
            null,
            null,
            $authorization
        )->run(['target_date' => '2026-08-14']);

        self::assertSame([], $calls);
        self::assertSame('partial_or_blocked', $receipt['status']);
        self::assertSame('collection_plan_authorization_blocked', $receipt['hotels'][0]['reason']);
        self::assertSame('blocked', $receipt['hotels'][0]['run_receipt_status']);
        self::assertTrue($receipt['hotels'][0]['run_receipt_structure_verified']);
        self::assertTrue($receipt['hotels'][0]['run_receipt_readback_verified']);
    }

    public function testUnverifiedTimeoutCleanupStopsTheSharedGatewayQueue(): void
    {
        $plans = [
            $this->plan(1, 80, 21, 22),
            $this->plan(2, 81, 31, 32),
        ];
        $calls = [];
        $aborts = [];
        $child = function (
            string $source,
            array $command,
            int $timeoutSeconds,
            array $context
        ) use (&$calls): array {
            $calls[] = [$source, (int)$context['system_hotel_id']];
            return [
                'exit_code' => 124,
                'timed_out' => true,
                'process_group_cleanup_verified' => false,
                'receipt' => [],
            ];
        };
        $aborter = static function (string $profilePublicId, string $tokenFile) use (&$aborts): bool {
            $aborts[] = [$profilePublicId, $tokenFile];
            return true;
        };

        $receipt = $this->service($plans, $calls, $child, null, $aborter)->run([
            'target_date' => '2026-08-14',
        ]);

        self::assertSame([['pms', 80]], $calls);
        self::assertCount(1, $aborts);
        self::assertFalse($receipt['gateway_cleanup_verified']);
        self::assertFalse($receipt['hotels'][0]['gateway_cleanup_verified']);
        self::assertFalse($receipt['hotels'][0]['sources'][0]['timeout_cleanup_verified']);
        self::assertSame('previous_timeout_cleanup_unverified', $receipt['hotels'][0]['sources'][1]['reason']);
        self::assertSame('previous_timeout_cleanup_unverified', $receipt['hotels'][1]['reason']);
        self::assertFalse($receipt['message_sent']);
    }

    public function testTransientGatewayFailureRetriesOnceThenRecoversWithoutRepeatingOtherSources(): void
    {
        $plans = [$this->plan(1, 80, 21, 22)];
        $calls = [];
        $delays = [];
        $pmsAttempts = 0;
        $child = function (
            string $source,
            array $command,
            int $timeoutSeconds,
            array $context
        ) use (&$calls, &$pmsAttempts): array {
            $calls[] = $source;
            if ($source === 'pms' && ++$pmsAttempts === 1) {
                return [
                    'exit_code' => 1,
                    'timed_out' => false,
                    'receipt' => [
                        'status' => 'blocked',
                        'reason' => 'gateway_collection_capacity_busy',
                        'business_data_persisted' => false,
                        'message_sent' => false,
                        'sensitive_values_exposed' => false,
                    ],
                ];
            }
            return $this->successChild($source);
        };
        $sleeper = static function (int $seconds) use (&$delays): void {
            $delays[] = $seconds;
        };

        $receipt = $this->service($plans, $calls, $child, null, null, $sleeper)->run([
            'target_date' => '2026-08-14',
        ]);

        self::assertSame('all_hotels_saved_and_readback_verified', $receipt['status']);
        self::assertSame(['pms', 'pms', 'ctrip', 'meituan'], $calls);
        self::assertSame([30], $delays);
        $pms = $receipt['hotels'][0]['sources'][0];
        self::assertSame(2, $pms['attempt_count']);
        self::assertSame(1, $pms['retry_count']);
        self::assertTrue($pms['recovered_after_retry']);
        self::assertFalse($pms['transient_retry_exhausted']);
        self::assertSame('gateway_collection_capacity_busy', $pms['last_transient_reason']);
        self::assertSame('verified', $pms['retry_stop_reason']);
    }

    public function testTransientFailureExhaustsExactlyTwoRetriesThenContinuesOtherSources(): void
    {
        $plans = [$this->plan(1, 80, 21, 22)];
        $calls = [];
        $delays = [];
        $child = function (string $source) use (&$calls): array {
            $calls[] = $source;
            if ($source === 'ctrip') {
                return [
                    'exit_code' => 1,
                    'timed_out' => false,
                    'receipt' => [
                        'status' => 'blocked',
                        'reason' => 'gateway_temporarily_unavailable',
                        'business_data_persisted' => false,
                        'message_sent' => false,
                        'sensitive_values_exposed' => false,
                    ],
                ];
            }
            return $this->successChild($source);
        };
        $sleeper = static function (int $seconds) use (&$delays): void {
            $delays[] = $seconds;
        };

        $receipt = $this->service($plans, $calls, $child, null, null, $sleeper)->run([
            'target_date' => '2026-08-14',
        ]);

        self::assertSame('partial_or_blocked', $receipt['status']);
        self::assertSame(['pms', 'ctrip', 'ctrip', 'ctrip', 'meituan'], $calls);
        self::assertSame([30, 90], $delays);
        $ctrip = $receipt['hotels'][0]['sources'][1];
        self::assertSame(3, $ctrip['attempt_count']);
        self::assertSame(2, $ctrip['retry_count']);
        self::assertTrue($ctrip['transient_retry_exhausted']);
        self::assertSame('transient_retry_exhausted', $ctrip['retry_stop_reason']);
    }

    public function testTransientCodeNeverRetriesWhenBusinessDataMayAlreadyBePersisted(): void
    {
        $plans = [$this->plan(1, 80, 21, 22)];
        $calls = [];
        $delays = [];
        $child = function (string $source) use (&$calls): array {
            $calls[] = $source;
            if ($source === 'ctrip') {
                return [
                    'exit_code' => 1,
                    'timed_out' => false,
                    'receipt' => [
                        'status' => 'blocked',
                        'reason' => 'gateway_connection_timeout',
                        'business_data_persisted' => true,
                        'message_sent' => false,
                        'sensitive_values_exposed' => false,
                    ],
                ];
            }
            return $this->successChild($source);
        };

        $receipt = $this->service(
            $plans,
            $calls,
            $child,
            null,
            null,
            static function (int $seconds) use (&$delays): void { $delays[] = $seconds; }
        )->run(['target_date' => '2026-08-14']);

        self::assertSame(['pms', 'ctrip', 'meituan'], $calls);
        self::assertSame([], $delays);
        $ctrip = $receipt['hotels'][0]['sources'][1];
        self::assertSame(1, $ctrip['attempt_count']);
        self::assertSame('business_data_persisted', $ctrip['retry_stop_reason']);
    }

    public function testTransientCodeNeverRetriesWhenPersistenceOutcomeIsMissing(): void
    {
        $plans = [$this->plan(1, 80, 21, 22)];
        $calls = [];
        $delays = [];
        $child = function (string $source) use (&$calls): array {
            $calls[] = $source;
            if ($source === 'meituan') {
                return [
                    'exit_code' => 1,
                    'timed_out' => false,
                    'receipt' => [
                        'status' => 'blocked',
                        'reason' => 'gateway_connection_refused',
                        'message_sent' => false,
                        'sensitive_values_exposed' => false,
                    ],
                ];
            }
            return $this->successChild($source);
        };

        $receipt = $this->service(
            $plans,
            $calls,
            $child,
            null,
            null,
            static function (int $seconds) use (&$delays): void { $delays[] = $seconds; }
        )->run(['target_date' => '2026-08-14']);

        self::assertSame(['pms', 'ctrip', 'meituan'], $calls);
        self::assertSame([], $delays);
        $meituan = $receipt['hotels'][0]['sources'][2];
        self::assertNull($meituan['business_data_persisted']);
        self::assertSame(1, $meituan['attempt_count']);
        self::assertSame('persistence_outcome_unknown', $meituan['retry_stop_reason']);
    }

    public function testTimeoutAbortExceptionFreezesSharedQueueAndDoesNotRetry(): void
    {
        $plans = [
            $this->plan(1, 80, 21, 22),
            $this->plan(2, 81, 31, 32),
        ];
        $calls = [];
        $delays = [];
        $child = function (string $source, array $command, int $timeout, array $context) use (&$calls): array {
            $calls[] = [$source, (int)$context['system_hotel_id']];
            return [
                'exit_code' => 124,
                'timed_out' => true,
                'process_group_cleanup_verified' => true,
                'receipt' => [
                    'status' => 'blocked',
                    'business_data_persisted' => false,
                    'message_sent' => false,
                    'sensitive_values_exposed' => false,
                ],
            ];
        };
        $aborter = static function (): bool {
            throw new \RuntimeException('abort unavailable');
        };

        $receipt = $this->service(
            $plans,
            $calls,
            $child,
            null,
            $aborter,
            static function (int $seconds) use (&$delays): void { $delays[] = $seconds; }
        )->run(['target_date' => '2026-08-14']);

        self::assertSame([['pms', 80]], $calls);
        self::assertSame([], $delays);
        self::assertFalse($receipt['gateway_cleanup_verified']);
        $pms = $receipt['hotels'][0]['sources'][0];
        self::assertTrue($pms['process_group_cleanup_verified']);
        self::assertFalse($pms['gateway_abort_verified']);
        self::assertFalse($pms['timeout_cleanup_verified']);
        self::assertSame('terminal_failure', $pms['retry_stop_reason']);
        self::assertSame('previous_timeout_cleanup_unverified', $receipt['hotels'][1]['reason']);
    }

    public function testTimeoutRetriesOnlyAfterProcessAndGatewayCleanupAreBothVerified(): void
    {
        $plans = [$this->plan(1, 80, 21, 22)];
        $calls = [];
        $delays = [];
        $attempt = 0;
        $child = function (string $source) use (&$calls, &$attempt): array {
            $calls[] = $source;
            if ($source === 'pms' && ++$attempt === 1) {
                return [
                    'exit_code' => 124,
                    'timed_out' => true,
                    'process_group_cleanup_verified' => true,
                    'receipt' => [
                        'status' => 'blocked',
                        'business_data_persisted' => false,
                        'message_sent' => false,
                        'sensitive_values_exposed' => false,
                    ],
                ];
            }
            return $this->successChild($source);
        };

        $receipt = $this->service(
            $plans,
            $calls,
            $child,
            null,
            static fn(): bool => true,
            static function (int $seconds) use (&$delays): void { $delays[] = $seconds; }
        )->run(['target_date' => '2026-08-14']);

        self::assertSame('all_hotels_saved_and_readback_verified', $receipt['status']);
        self::assertSame(['pms', 'pms', 'ctrip', 'meituan'], $calls);
        self::assertSame([30], $delays);
        self::assertTrue($receipt['hotels'][0]['gateway_cleanup_verified']);
        self::assertTrue($receipt['hotels'][0]['sources'][0]['recovered_after_retry']);
    }

    /**
     * @param array<int,array<string,mixed>> $plans
     * @param array<int,array<string,mixed>> $calls
     */
    private function service(
        array $plans,
        array &$calls,
        ?callable $childRunner = null,
        ?callable $profileLoader = null,
        ?callable $collectionAborter = null,
        ?callable $sleeper = null,
        ?callable $runReceiptWriter = null,
        ?callable $authorizationLoader = null
    ): CloudThreeSourceCollectionQueueService {
        $planByHotel = [];
        foreach ($plans as $plan) {
            $planByHotel[(int)$plan['system_hotel_id']] = $plan;
        }
        $profileLoader ??= fn(int $tenantId, int $hotelId, int $ownerUserId): array =>
            $this->profiles($hotelId, $ownerUserId);
        $childRunner ??= function (
            string $source,
            array $command,
            int $timeoutSeconds,
            array $context
        ) use (&$calls): array {
            $calls[] = [
                'source' => $source,
                'hotel_id' => (int)$context['system_hotel_id'],
                'command' => $command,
                'timeout_seconds' => $timeoutSeconds,
            ];
            return $this->successChild($source);
        };
        if ($runReceiptWriter === null) {
            $ledger = [];
            $runReceiptWriter = static function (string $action, array $context) use (&$ledger): array {
                if ($action === 'begin') {
                    $gate = (array)($context['gate'] ?? []);
                    $runId = (string)($gate['dispatcher_run_id'] ?? '');
                    $ledger[$runId] = [
                        'dispatcher_run_id' => $runId,
                        'tenant_id' => (int)($gate['tenant_id'] ?? 0),
                        'system_hotel_id' => (int)($gate['system_hotel_id'] ?? 0),
                        'business_date' => (string)($gate['business_date'] ?? ''),
                        'status' => ($gate['collection_allowed'] ?? false) === true
                            ? 'started'
                            : 'blocked',
                        'pms_provider' => (string)($gate['sources']['pms']['provider'] ?? ''),
                        'pms_receipt' => [
                            'provider' => (string)($gate['sources']['pms']['provider'] ?? ''),
                            'status' => 'not_run',
                            'capture_id' => null,
                            'readback_verified' => false,
                        ],
                        'sources' => [
                            'ctrip' => [
                                'platform' => 'ctrip',
                                'data_source_id' => (int)($gate['sources']['ctrip']['data_source_id'] ?? 0),
                                'status' => ($gate['collection_allowed'] ?? false) === true
                                    ? 'declared'
                                    : 'blocked',
                                'platform_sync_task_id' => null,
                                'readback_verified' => false,
                            ],
                            'meituan' => [
                                'platform' => 'meituan',
                                'data_source_id' => (int)($gate['sources']['meituan']['data_source_id'] ?? 0),
                                'status' => ($gate['collection_allowed'] ?? false) === true
                                    ? 'declared'
                                    : 'blocked',
                                'platform_sync_task_id' => null,
                                'readback_verified' => false,
                            ],
                        ],
                    ];
                } else {
                    $runId = (string)($context['dispatcher_run_id'] ?? '');
                    if (!isset($ledger[$runId])) {
                        throw new \RuntimeException('test_run_receipt_missing');
                    }
                    if ($action === 'pms_success') {
                        $ledger[$runId]['pms_receipt'] = [
                            'provider' => (string)($context['provider'] ?? ''),
                            'status' => 'verified',
                            'capture_id' => (string)((int)($context['capture_id'] ?? 0)),
                            'readback_verified' => true,
                        ];
                    } elseif ($action === 'pms_failure') {
                        $outcome = (array)($context['outcome'] ?? []);
                        $ledger[$runId]['pms_receipt'] = [
                            'provider' => (string)($context['provider'] ?? ''),
                            'status' => 'failed',
                            'capture_id' => null,
                            'readback_verified' => false,
                            'reason_code' => (string)($outcome['reason'] ?? ''),
                        ];
                    } elseif ($action === 'platform_results') {
                        foreach ((array)($context['results'] ?? []) as $result) {
                            $platform = (string)($result['platform'] ?? '');
                            $success = ($result['success'] ?? false) === true;
                            $ledger[$runId]['sources'][$platform] = [
                                'platform' => $platform,
                                'data_source_id' => (int)($result['data_source_id'] ?? 0),
                                'status' => $success ? 'success' : 'failed',
                                'platform_sync_task_id' => $success
                                    ? max(1, (int)($result['task_id'] ?? 0))
                                    : null,
                                'readback_verified' => $success,
                            ];
                        }
                        $statuses = array_column($ledger[$runId]['sources'], 'status');
                        if (in_array('declared', $statuses, true)) {
                            $ledger[$runId]['status'] = 'in_progress';
                        } elseif (count(array_filter($statuses, static fn(string $status): bool => $status === 'success')) === 2) {
                            $ledger[$runId]['status'] = 'collected';
                        } elseif (in_array('success', $statuses, true)) {
                            $ledger[$runId]['status'] = 'partial';
                        } else {
                            $ledger[$runId]['status'] = 'failed';
                        }
                    } elseif ($action !== 'read') {
                        throw new \RuntimeException('test_run_receipt_action_invalid');
                    }
                }

                $runId = $action === 'begin'
                    ? (string)($context['gate']['dispatcher_run_id'] ?? '')
                    : (string)($context['dispatcher_run_id'] ?? '');
                $state = $ledger[$runId];
                return [
                    'dispatcher_run_id' => $runId,
                    'tenant_id' => $state['tenant_id'],
                    'system_hotel_id' => $state['system_hotel_id'],
                    'business_date' => $state['business_date'],
                    'status' => $state['status'],
                    'pms_receipt' => $state['pms_receipt'],
                    'source_receipts' => array_values($state['sources']),
                    'ledger_structure_verified' => true,
                    'readback_verified' => in_array($state['status'], ['blocked', 'partial', 'failed'], true),
                ];
            };
        }
        $authorizationLoader ??= static function (
            array $hotel,
            string $targetDate,
            array $sourceIds,
            array $platforms,
            string $runMode
        ) use ($planByHotel): array {
            $plan = $planByHotel[(int)$hotel['id']];
            return [
                'status' => 'ready',
                'collection_allowed' => true,
                'tenant_id' => (int)$hotel['tenant_id'],
                'system_hotel_id' => (int)$hotel['id'],
                'business_date' => $targetDate,
                'run_mode' => $runMode,
                'plan_id' => (int)$plan['id'],
                'plan_hash' => (string)$plan['plan_hash'],
                'plan_readback_verified' => true,
                'binding_digest_matches' => true,
                'execution_owner_user_id' => (int)$plan['execution_owner_user_id'],
                'actual_source_ids' => $sourceIds,
                'actual_platforms' => $platforms,
            ];
        };

        return new CloudThreeSourceCollectionQueueService(
            static fn(): array => $plans,
            static fn(int $tenantId, int $hotelId): array => [
                'id' => $hotelId,
                'tenant_id' => $tenantId,
                'name' => 'Hotel ' . $hotelId,
                'status' => 1,
            ],
            $authorizationLoader,
            $profileLoader,
            $childRunner,
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-08-14 10:30:00'),
            static fn(): float => 1000.0,
            'D:/suxios',
            $collectionAborter,
            $sleeper,
            $runReceiptWriter
        );
    }

    /** @return array<string,mixed> */
    private function plan(
        int $id,
        int $hotelId,
        int $ctripSourceId,
        int $meituanSourceId,
        string $pmsProvider = 'dingdandao_pms'
    ): array
    {
        return [
            'id' => $id,
            'tenant_id' => 8,
            'system_hotel_id' => $hotelId,
            'plan_version' => 1,
            'plan_status' => 'active',
            'enabled' => 1,
            'active_slot' => 1,
            'business_date_policy' => 'same_day_realtime',
            'execution_owner_user_id' => 7,
            'binding_digest' => hash('sha256', 'binding-' . $hotelId),
            'plan_hash' => hash('sha256', 'plan-' . $hotelId),
            'source_plan_json' => json_encode([
                'ctrip' => ['data_source_id' => $ctripSourceId],
                'meituan' => ['data_source_id' => $meituanSourceId],
                'pms' => ['provider' => $pmsProvider],
            ], JSON_THROW_ON_ERROR),
            'validation_status' => 'ready',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function profiles(
        int $hotelId,
        int $ownerUserId,
        string $pmsProfilePlatform = 'dingdandao'
    ): array
    {
        $profiles = [];
        foreach ([$pmsProfilePlatform, 'ctrip', 'meituan'] as $index => $platform) {
            $profiles[] = [
                'id' => ($hotelId * 10) + $index,
                'tenant_id' => 8,
                'system_hotel_id' => $hotelId,
                'owner_user_id' => $ownerUserId,
                'platform' => $platform,
                'profile_public_id' => 'cbp_' . $platform . '_hotel_' . $hotelId . '_abcdef',
                'authorization_status' => 'ready_to_collect',
                'ready_at' => '2026-08-14 10:00:00',
                'session_expires_at' => '2026-08-15 10:00:00',
            ];
        }
        return $profiles;
    }

    /** @return array<string,mixed> */
    private function successChild(string $source): array
    {
        if ($source === 'pms') {
            return [
                'exit_code' => 0,
                'timed_out' => false,
                'receipt' => [
                    'status' => 'saved_and_readback_verified',
                    'capture_id' => 51,
                    'identity_status' => 'matched',
                    'readback_status' => 'readback_verified',
                    'message_sent' => false,
                    'push_orchestration' => [
                        'disabled_by_invocation' => true,
                        'delivery_attempted' => false,
                    ],
                ],
            ];
        }
        return [
            'exit_code' => 0,
            'timed_out' => false,
            'receipt' => [
                'status' => 'saved_and_readback_verified',
                'saved_count' => 3,
                'readback_count' => 3,
                'readback_verified' => true,
                'business_data_persisted' => true,
                'gateway_receipt_readback_verified' => true,
                'message_sent' => false,
            ],
        ];
    }
}
