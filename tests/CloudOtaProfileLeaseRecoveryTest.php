<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudBrowserProfileService;
use app\service\CloudOtaProfileLeaseService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CloudOtaProfileLeaseRecoveryTest extends TestCase
{
    public static function invalidOpenProofs(): array
    {
        return [
            'read-only proof absent' => [['read_only_enforced' => false], true],
            'profile restoration failed' => [['profile_restored' => false], true],
            'browser start failed' => [['browser_started' => false], true],
            'unexpected status' => [['status' => 'incomplete'], true],
            'different hotel' => [['hotel_id' => 6], false],
            'different profile' => [['profile_id' => 'cbp_otherabcdefghijkl'], false],
            'invalid lease id' => [['collection_session_id' => 'invalid'], false],
        ];
    }

    #[DataProvider('invalidOpenProofs')]
    public function testInvalidOpenProofClosesOnlyAnExactlyOwnedLease(array $override, bool $mustClose): void
    {
        $calls = [];
        $collectorCalls = 0;
        $service = new CloudOtaProfileLeaseService(
            static function (string $path, string $_token, array $body) use (&$calls, $override): array {
                $calls[] = ['path' => $path, 'body' => $body];
                if ($path === '/v1/collection/open') {
                    return array_replace([
                        'status' => 'collection_open',
                        'collection_session_id' => 'cbcs_abcdefghijklmnop',
                        'profile_id' => 'cbp_abcdefghijklmnop',
                        'platform' => 'ctrip', 'tenant_id' => 1, 'hotel_id' => 5, 'owner_user_id' => 1,
                        'target_date' => date('Y-m-d'), 'collection_kind' => 'ota_target_date',
                        'data_period' => 'realtime_snapshot', 'access_mode' => 'read_only',
                        'browser_started' => true, 'profile_restored' => true,
                        'read_only_enforced' => true, 'session_owner' => 'gateway_collection',
                        'external_browser_required' => false, 'user_browser_closed' => false,
                    ], $override);
                }
                return [
                    'status' => 'collection_closed', 'browser_started' => false,
                    'profile_sealed' => true, 'user_browser_closed' => false,
                    'sensitive_values_exposed' => false,
                ];
            },
            static fn(): string => str_repeat('synthetic', 6),
            static fn(): array => [
                'profile_public_id' => 'cbp_abcdefghijklmnop',
                'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT,
            ]
        );
        try {
            $service->withReadOnlyLease([
                'tenant_id' => 1, 'system_hotel_id' => 5, 'user_id' => 1, 'platform' => 'ctrip',
            ], date('Y-m-d'), static function () use (&$collectorCalls): void {
                $collectorCalls++;
            });
            self::fail('Invalid proof must reject collection');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud_ota_profile_collection_failed', $error->getMessage());
        }
        self::assertSame(0, $collectorCalls);
        self::assertCount($mustClose ? 2 : 1, $calls);
        if ($mustClose) {
            self::assertSame('/v1/collection/close', $calls[1]['path']);
            self::assertSame('cancelled', $calls[1]['body']['outcome']);
            self::assertSame('cbcs_abcdefghijklmnop', $calls[1]['body']['collection_session_id']);
        }
    }
}
