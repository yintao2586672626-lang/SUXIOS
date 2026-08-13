<?php
declare(strict_types=1);

namespace tests;

use app\service\HotelOtaBindingOnboardingService;
use PHPUnit\Framework\TestCase;

final class HotelOtaBindingOnboardingServiceTest extends TestCase
{
    private const RAW_DEVICE = 'PRIVATE-H80-DEVICE';
    private const RAW_PLATFORM_HOTEL = 'PRIVATE-MEITUAN-HOTEL';

    public function testPreviewPinsHotelAndSourcesAndAllowsOnlyProofGatedIdentityAction(): void
    {
        $phase = 0;
        $service = $this->service($phase);

        $receipt = $service->preview(80, 80);

        self::assertSame('unverified', $receipt['status']);
        self::assertTrue($receipt['scope_verified']);
        self::assertSame(['ctrip' => 25, 'meituan' => 68], $receipt['source_ids']);
        self::assertSame('claim_meituan_identity', $receipt['action_required']);
        self::assertTrue($receipt['actions']['claim_meituan_identity']['allowed']);
        self::assertFalse($receipt['actions']['bind_local_profile_scheduler']['allowed']);
        self::assertFalse($receipt['database_write_performed']);
        self::assertFalse($receipt['ota_collection_performed']);
        self::assertFalse($receipt['collector_task_created']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $receipt['actions']['claim_meituan_identity']['intent_digest']
        );
        $this->assertNoRawValues($receipt);
    }

    public function testIdentityClaimThenSchedulerBindingEachRequireFreshIntentAndExactReadback(): void
    {
        $phase = 0;
        $identityExecutions = 0;
        $bindingExecutions = 0;
        $service = $this->service($phase, $identityExecutions, $bindingExecutions);

        $beforeClaim = $service->preview(80, 80);
        $afterClaim = $service->execute(
            80,
            80,
            'claim_meituan_identity',
            $beforeClaim['actions']['claim_meituan_identity']['intent_digest']
        );

        self::assertSame(1, $identityExecutions);
        self::assertSame(0, $bindingExecutions);
        self::assertSame('success', $afterClaim['operation']['outcome']);
        self::assertTrue($afterClaim['operation']['database_write_performed']);
        self::assertTrue($afterClaim['operation']['exact_readback_verified']);
        self::assertFalse($afterClaim['operation']['ota_collection_performed']);
        self::assertSame('partial', $afterClaim['status']);
        self::assertSame('bind_local_profile_scheduler', $afterClaim['action_required']);
        self::assertTrue($afterClaim['actions']['bind_local_profile_scheduler']['allowed']);
        $this->assertNoRawValues($afterClaim);

        $afterBind = $service->execute(
            80,
            80,
            'bind_local_profile_scheduler',
            $afterClaim['actions']['bind_local_profile_scheduler']['intent_digest']
        );

        self::assertSame(1, $identityExecutions);
        self::assertSame(1, $bindingExecutions);
        self::assertSame('success', $afterBind['operation']['outcome']);
        self::assertTrue($afterBind['operation']['database_write_performed']);
        self::assertTrue($afterBind['operation']['exact_readback_verified']);
        self::assertSame('verified', $afterBind['status']);
        self::assertTrue($afterBind['exact_readback_verified']);
        self::assertNull($afterBind['action_required']);
        $this->assertNoRawValues($afterBind);
    }

    public function testStaleIntentAndUnconfirmedScopeNeverInvokeWriters(): void
    {
        $phase = 0;
        $identityExecutions = 0;
        $bindingExecutions = 0;
        $service = $this->service($phase, $identityExecutions, $bindingExecutions);

        $result = $service->execute(80, 80, 'claim_meituan_identity', str_repeat('0', 64));

        self::assertSame('blocked', $result['operation']['outcome']);
        self::assertSame('hotel_ota_binding_onboarding_preview_stale', $result['operation']['failure_code']);
        self::assertFalse($result['operation']['database_write_performed']);
        self::assertFalse($result['operation']['ota_collection_performed']);
        self::assertSame(0, $identityExecutions);
        self::assertSame(0, $bindingExecutions);

        $wrongHotel = $service->preview(80, 81);
        self::assertSame('blocked', $wrongHotel['status']);
        self::assertSame(
            'hotel_ota_binding_onboarding_scope_invalid',
            $wrongHotel['reason_codes'][0]['code']
        );
    }

    public function testSourceOwnerOrFixedScopeMismatchFailsClosed(): void
    {
        $service = new HotelOtaBindingOnboardingService(
            static fn(): array => [
                ['id' => 25, 'tenant_id' => 80, 'user_id' => 7, 'system_hotel_id' => 80, 'platform' => 'ctrip'],
                ['id' => 68, 'tenant_id' => 80, 'user_id' => 8, 'system_hotel_id' => 80, 'platform' => 'meituan'],
            ]
        );

        $receipt = $service->preview(80, 80);

        self::assertSame('blocked', $receipt['status']);
        self::assertSame(
            'hotel_ota_binding_onboarding_execution_owner_conflict',
            $receipt['reason_codes'][0]['code']
        );
        self::assertFalse($receipt['database_write_performed']);
    }

    public function testExistingExactTwoSourceDeviceIdentityIsReusedInternallyButNeverExposed(): void
    {
        $capturedDevice = '';
        $source = static function (int $id, string $platform): array {
            $config = [
                'source_method' => 'single_user_local',
                'collector_binding_mode' => 'single_user_local',
                'collector_device_id' => self::RAW_DEVICE,
                'collector_device_id_hash' => hash('sha256', self::RAW_DEVICE),
                'collector_user_id' => 7,
                'collector_tenant_id' => 80,
                'collector_hotel_id' => 80,
                'collector_platform' => $platform,
                'collector_bound_at' => '2026-08-11 09:00:00',
            ];
            return [
                'id' => $id,
                'tenant_id' => 80,
                'user_id' => 7,
                'system_hotel_id' => 80,
                'platform' => $platform,
                'config_json' => json_encode($config, JSON_THROW_ON_ERROR),
            ];
        };
        $service = new HotelOtaBindingOnboardingService(
            static fn(): array => [$source(25, 'ctrip'), $source(68, 'meituan')],
            static fn(): array => [
                'status' => 'ready',
                'claim_ready' => true,
                'already_canonical' => true,
                'blockers' => [],
                'receipt_digest' => hash('sha256', 'identity-existing'),
            ],
            static fn(): array => [],
            static function (
                int $tenantId,
                int $hotelId,
                int $userId,
                int $ctripSourceId,
                int $meituanSourceId,
                string $deviceId
            ) use (&$capturedDevice): array {
                $capturedDevice = $deviceId;
                return [
                    'status' => 'blocked',
                    'binding_ready' => false,
                    'bound' => false,
                    'blockers' => [['code' => 'local_profile_scheduler_current_session_scope_drift']],
                    'receipt_digest' => hash('sha256', 'binding-existing'),
                ];
            },
            static fn(): array => [],
            null
        );

        $receipt = $service->preview(80, 80);

        self::assertSame(self::RAW_DEVICE, $capturedDevice);
        self::assertSame('blocked', $receipt['status']);
        $this->assertNoRawValues($receipt);
    }

    private function service(
        int &$phase,
        ?int &$identityExecutions = null,
        ?int &$bindingExecutions = null
    ): HotelOtaBindingOnboardingService {
        $identityExecutions ??= 0;
        $bindingExecutions ??= 0;
        return new HotelOtaBindingOnboardingService(
            static fn(): array => [
                ['id' => 25, 'tenant_id' => 80, 'user_id' => 7, 'system_hotel_id' => 80, 'platform' => 'ctrip'],
                ['id' => 68, 'tenant_id' => 80, 'user_id' => 7, 'system_hotel_id' => 80, 'platform' => 'meituan'],
            ],
            static function () use (&$phase): array {
                return [
                    'contract_version' => 'identity.v1',
                    'status' => 'ready',
                    'claim_ready' => true,
                    'already_canonical' => $phase >= 1,
                    'identity_candidate_count' => 1,
                    'profile_binding' => ['status' => 'verified'],
                    'current_session_proof' => ['status' => 'verified'],
                    'ownership' => ['status' => 'verified'],
                    'write' => ['needed' => $phase < 1, 'readback_verified' => $phase >= 1],
                    'blockers' => [],
                    'receipt_digest' => hash('sha256', 'identity-' . $phase),
                    'platform_hotel_id' => self::RAW_PLATFORM_HOTEL,
                ];
            },
            static function () use (&$phase, &$identityExecutions): array {
                $identityExecutions++;
                $phase = 1;
                return [
                    'contract_version' => 'identity.v1',
                    'status' => 'ready',
                    'claim_ready' => true,
                    'claimed' => true,
                    'already_canonical' => false,
                    'identity_candidate_count' => 1,
                    'profile_binding' => ['status' => 'verified'],
                    'current_session_proof' => ['status' => 'verified'],
                    'ownership' => ['status' => 'verified'],
                    'write' => ['affected_rows' => 1, 'readback_verified' => true],
                    'blockers' => [],
                    'receipt_digest' => hash('sha256', 'identity-executed'),
                    'platform_hotel_id' => self::RAW_PLATFORM_HOTEL,
                ];
            },
            static function () use (&$phase): array {
                if ($phase === 0) {
                    return [
                        'contract_version' => 'binding.v1',
                        'status' => 'blocked',
                        'binding_ready' => false,
                        'bound' => false,
                        'sources' => [],
                        'write' => ['readback_verified' => false],
                        'blockers' => [[
                            'code' => 'local_profile_scheduler_canonical_identity_unverified',
                            'platform' => 'meituan',
                        ]],
                        'receipt_digest' => hash('sha256', 'binding-0'),
                    ];
                }
                return [
                    'contract_version' => 'binding.v1',
                    'status' => 'ready',
                    'binding_ready' => true,
                    'bound' => $phase >= 2,
                    'already_bound' => $phase >= 2,
                    'sources' => [],
                    'write' => ['readback_verified' => $phase >= 2],
                    'blockers' => [],
                    'receipt_digest' => hash('sha256', 'binding-' . $phase),
                    'collector_device_id' => self::RAW_DEVICE,
                ];
            },
            static function () use (&$phase, &$bindingExecutions): array {
                $bindingExecutions++;
                $phase = 2;
                return [
                    'contract_version' => 'binding.v1',
                    'status' => 'ready',
                    'binding_ready' => true,
                    'bound' => true,
                    'already_bound' => false,
                    'sources' => [
                        ['platform' => 'ctrip', 'data_source_id' => 25, 'readback_verified' => true],
                        ['platform' => 'meituan', 'data_source_id' => 68, 'readback_verified' => true],
                    ],
                    'write' => ['performed' => true, 'affected_rows' => 2, 'readback_verified' => true],
                    'database_write_performed' => true,
                    'blockers' => [],
                    'receipt_digest' => hash('sha256', 'binding-executed'),
                    'collector_device_id' => self::RAW_DEVICE,
                ];
            },
            static fn(): string => self::RAW_DEVICE
        );
    }

    /** @param array<string,mixed> $receipt */
    private function assertNoRawValues(array $receipt): void
    {
        $json = json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::RAW_DEVICE, $json);
        self::assertStringNotContainsString(self::RAW_PLATFORM_HOTEL, $json);
        self::assertStringNotContainsString('collector_device_id', $json);
        self::assertStringNotContainsString('cookie', strtolower($json));
        self::assertStringNotContainsString('token', strtolower($json));
    }
}
