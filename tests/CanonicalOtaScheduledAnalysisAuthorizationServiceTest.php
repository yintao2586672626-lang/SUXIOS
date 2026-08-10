<?php
declare(strict_types=1);

namespace Tests;

use app\service\CanonicalOtaInvestigationActionService;
use app\service\CanonicalOtaScheduledAnalysisAuthorizationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CanonicalOtaScheduledAnalysisAuthorizationServiceTest extends TestCase
{
    public function testExactCandidateMustMatchTheCurrentRevocableServerGrant(): void
    {
        $grant = $this->grant(1, 80);
        $service = new CanonicalOtaScheduledAnalysisAuthorizationService(
            static fn(int $hotelId): array => [
                'enabled' => true,
                'canonical_daily_analysis_authorization' => $grant,
            ]
        );

        $resolved = $service->assertMatches($grant, 1, 80, 'ctrip');

        self::assertSame($grant, $resolved);
        self::assertSame(1, $resolved['tenant_id']);
        self::assertSame(80, $resolved['hotel_id']);
    }

    public function testSelfRehashedFabricatedCandidateCannotReplaceTheServerGrant(): void
    {
        $grant = $this->grant(1, 80);
        $fabricated = $grant;
        $fabricated['plan_id'] = 'forged_but_rehashed_plan';
        $fabricated['content_digest'] = $this->digest($fabricated);
        $service = new CanonicalOtaScheduledAnalysisAuthorizationService(
            static fn(int $hotelId): array => [
                'enabled' => true,
                'canonical_daily_analysis_authorization' => $grant,
            ]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_scheduled_analysis_grant_mismatch');
        $service->assertMatches($fabricated, 1, 80, 'ctrip');
    }

    public function testDisabledAutoFetchRevokesTheGrant(): void
    {
        $grant = $this->grant(1, 80);
        $service = new CanonicalOtaScheduledAnalysisAuthorizationService(
            static fn(int $hotelId): array => [
                'enabled' => false,
                'canonical_daily_analysis_authorization' => $grant,
            ]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_scheduled_analysis_grant_unavailable');
        $service->assertMatches($grant, 1, 80, 'ctrip');
    }

    public function testPlatformGrantMapResolvesMeituanWithoutUsingLegacyCtripGrant(): void
    {
        $ctrip = $this->grant(1, 80, 'ctrip');
        $meituan = $this->grant(1, 80, 'meituan');
        $service = new CanonicalOtaScheduledAnalysisAuthorizationService(
            static fn(int $hotelId): array => [
                'enabled' => true,
                'canonical_daily_analysis_authorization' => $ctrip,
                'canonical_daily_analysis_authorizations' => [
                    'ctrip' => $ctrip,
                    'meituan' => $meituan,
                ],
            ]
        );

        self::assertSame($meituan, $service->assertMatches($meituan, 1, 80, 'meituan'));
        self::assertSame($ctrip, $service->assertMatches($ctrip, 1, 80, 'ctrip'));
    }

    public function testCtripGrantCannotAuthorizeMeituan(): void
    {
        $ctrip = $this->grant(1, 80, 'ctrip');
        $service = new CanonicalOtaScheduledAnalysisAuthorizationService(
            static fn(int $hotelId): array => [
                'enabled' => true,
                'canonical_daily_analysis_authorization' => $ctrip,
                'canonical_daily_analysis_authorizations' => ['ctrip' => $ctrip],
            ]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_scheduled_analysis_grant_invalid');
        $service->assertMatches($ctrip, 1, 80, 'meituan');
    }

    /** @return array<string,mixed> */
    private function grant(int $tenantId, int $hotelId, string $platform = 'ctrip'): array
    {
        $grant = [
            'schema_version' => CanonicalOtaInvestigationActionService::SCHEDULED_AUTHORIZATION_VERSION,
            'enabled' => true,
            'plan_id' => 'hotel80_' . $platform . '_daily_goal_019fe32a_v1',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'trigger' => 'historical_daily_canonical_promotion',
            'authorized_at' => '2026-08-09T10:00:00+08:00',
            'authorized_by' => 'user_goal',
            'analysis_only' => true,
            'operation_count' => 4,
            'external_action_allowed' => false,
        ];
        $grant['content_digest'] = $this->digest($grant);
        return $grant;
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        unset($value['content_digest']);
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
