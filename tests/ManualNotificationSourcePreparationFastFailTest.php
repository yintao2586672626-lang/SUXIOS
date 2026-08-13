<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManualNotificationScheduleService;
use app\service\ManualNotificationService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ManualNotificationSourcePreparationFastFailTest extends TestCase
{
    public function testStopsBeforeLaterSourcesWhenPmsIsBlocked(): void
    {
        $calls = [];
        $service = new ManualNotificationScheduleService(
            meituanTemporalRefresher: static function () use (&$calls): array {
                $calls[] = 'meituan';
                return ['status' => 'ready'];
            },
            ctripTemporalRefresher: static function () use (&$calls): array {
                $calls[] = 'ctrip';
                return ['status' => 'ready'];
            },
            pmsSourceRefresher: static function () use (&$calls): array {
                $calls[] = 'pms';
                return [
                    'status' => 'blocked',
                    'reason_code' => 'capture_session_expired',
                ];
            }
        );

        $result = $service->prepareSourcesForDelivery(
            [
                'template_type' => ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
                'source_scope' => 'combined',
                'content_sections' => 'pms_summary,meituan_traffic,ctrip_traffic',
                'business_date_rule' => 'today',
                'hotel_id' => 80,
            ],
            '2026-08-13',
            new DateTimeImmutable('2026-08-13 18:00:00+08:00')
        );

        self::assertSame(['pms'], $calls);
        self::assertSame('blocked', $result['status']);
        self::assertSame('capture_session_expired', $result['reason_code']);
        self::assertSame('not_attempted', $result['meituan']['status']);
        self::assertSame('pms', $result['meituan']['blocked_by']);
        self::assertSame('not_attempted', $result['ctrip']['status']);
        self::assertSame('pms', $result['ctrip']['blocked_by']);
    }
}
