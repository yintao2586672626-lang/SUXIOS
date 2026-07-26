<?php
declare(strict_types=1);

namespace tests;

use app\service\DingdandaoOperatingTargetCaptureService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DingdandaoOperatingTargetCaptureServiceTest extends TestCase
{
    public function testUnverifiedIdentityIsNotReportedAsMismatch(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-26 17:45:00')
        );

        $identity = $this->invoke($service, 'identityStatus', [
            '敦煌漠蓝',
            '敦煌漠蓝新',
            'unverified',
        ]);
        self::assertSame('unverified', $identity);

        $summary = [
            'total_room_fee' => 10135.29,
            'adr' => 633.46,
            'occupancy_rate_percent' => 100.0,
            'revpar' => 633.46,
            'sold_room_nights' => 16,
            'average_daily_room_nights' => 16.0,
        ];
        $details = [];
        for ($index = 0; $index < 16; $index++) {
            $details[] = [
                'row_kind' => 'room',
                'room_type' => '测试房型',
                'room_number' => 'R' . ($index + 1),
                'room_fee' => $index === 15 ? 633.44 : 633.46,
            ];
        }
        $trace = array_fill_keys(array_keys($summary), 'DOM:经营指标');

        $assessment = $this->invoke($service, 'assess', [
            $summary,
            $details,
            $identity,
            true,
            $trace,
        ]);

        self::assertSame('identity_unverified', $assessment['capture_status']);
        self::assertSame('unverified', $assessment['quality_status']);
        self::assertContains(
            'dingdandao_hotel_identity_unverified',
            array_column($assessment['gaps'], 'code')
        );
    }

    public function testAuthoritativeDifferentIdentityRemainsMismatch(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService();

        $identity = $this->invoke($service, 'identityStatus', [
            '其他酒店',
            '敦煌漠蓝新',
            'platform_store_selector',
        ]);

        self::assertSame('identity_mismatch', $identity);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invoke(object $target, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($target, $arguments);
    }
}
