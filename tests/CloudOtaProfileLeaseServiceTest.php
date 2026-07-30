<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudOtaProfileLeaseService;
use app\service\CloudBrowserProfileService;
use PHPUnit\Framework\TestCase;

final class CloudOtaProfileLeaseServiceTest extends TestCase
{
    public function testRunsCollectorInsideExactScopedLeaseAndAlwaysClosesIt(): void
    {
        $calls = [];
        $service = new CloudOtaProfileLeaseService(
            static function (string $path, string $token, array $body) use (&$calls): array {
                $calls[] = [$path, $token, $body];
                if ($path === '/v1/profile-lease/open') {
                    return [
                        'status' => 'profile_lease_open',
                        'profile_lease_id' => 'cbpl_abcdefghijklmnop',
                        'profile_id' => 'cbp_abcdefghijklmnop',
                        'platform' => 'ctrip',
                        'tenant_id' => 1,
                        'hotel_id' => 5,
                        'owner_user_id' => 1,
                        'target_date' => date('Y-m-d'),
                        'browser_started' => true,
                        'profile_restored' => true,
                        'read_only_enforced' => true,
                        'session_owner' => 'gateway_profile_lease',
                        'external_browser_required' => false,
                        'user_browser_closed' => false,
                    ];
                }
                return [
                    'status' => 'profile_lease_closed',
                    'owned_browser_closed' => true,
                    'profile_encrypted_at_rest' => true,
                    'user_browser_closed' => false,
                    'sensitive_values_exposed' => false,
                ];
            },
            static fn(): string => str_repeat('t', 48),
            static fn(): array => [
                'profile_public_id' => 'cbp_abcdefghijklmnop',
                'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT,
            ]
        );

        $result = $service->withReadOnlyLease([
            'tenant_id' => 1,
            'system_hotel_id' => 5,
            'user_id' => 1,
            'platform' => 'ctrip',
        ], date('Y-m-d'), static fn(string $cdpUrl): array => [
            'status' => 'ok',
            'cdp_url' => $cdpUrl,
        ]);

        self::assertSame('ok', $result['status']);
        self::assertSame('http://127.0.0.1:9223', $result['cdp_url']);
        self::assertSame(
            ['/v1/profile-lease/open', '/v1/profile-lease/close'],
            array_column($calls, 0)
        );
        self::assertSame('daily_collection', $calls[0][2]['lease_kind']);
        self::assertSame('read_only', $calls[0][2]['access_mode']);
        self::assertSame('completed', $calls[1][2]['outcome']);
    }

    public function testCollectorFailureStillClosesLeaseAndStaysFailClosed(): void
    {
        $paths = [];
        $service = new CloudOtaProfileLeaseService(
            static function (string $path, string $token, array $body) use (&$paths): array {
                $paths[] = [$path, $body['outcome'] ?? ''];
                return $path === '/v1/profile-lease/open'
                    ? [
                        'status' => 'profile_lease_open',
                        'profile_lease_id' => 'cbpl_abcdefghijklmnop',
                        'profile_id' => 'cbp_abcdefghijklmnop',
                        'platform' => 'meituan',
                        'tenant_id' => 1,
                        'hotel_id' => 5,
                        'owner_user_id' => 1,
                        'target_date' => date('Y-m-d'),
                        'browser_started' => true,
                        'profile_restored' => true,
                        'read_only_enforced' => true,
                        'session_owner' => 'gateway_profile_lease',
                        'external_browser_required' => false,
                        'user_browser_closed' => false,
                    ]
                    : [
                        'status' => 'profile_lease_closed',
                        'owned_browser_closed' => true,
                        'profile_encrypted_at_rest' => true,
                        'user_browser_closed' => false,
                        'sensitive_values_exposed' => false,
                    ];
            },
            static fn(): string => str_repeat('t', 48),
            static fn(): array => [
                'profile_public_id' => 'cbp_abcdefghijklmnop',
                'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT,
            ]
        );

        try {
            $service->withReadOnlyLease([
                'tenant_id' => 1,
                'system_hotel_id' => 5,
                'user_id' => 1,
                'platform' => 'meituan',
            ], date('Y-m-d'), static function (): never {
                throw new \RuntimeException('capture failed');
            });
            self::fail('collector failure must fail the lease');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud_ota_profile_collection_failed', $error->getMessage());
        }
        self::assertSame([
            ['/v1/profile-lease/open', ''],
            ['/v1/profile-lease/close', 'failed'],
        ], $paths);
    }
}
