<?php
declare(strict_types=1);

namespace Tests;

use app\service\SingleHotelOperatingBriefPayloadService;
use app\service\SingleHotelOperatingDigestService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SingleHotelOperatingBriefPayloadServiceTest extends TestCase
{
    public function testVerifiedPmsBuildsTargetIndependentPreviewWithOptionalOtaGaps(): void
    {
        $today = $this->today();
        $service = new SingleHotelOperatingBriefPayloadService(
            $this->digest($today)
        );

        $page = $service->pagePreview(1, 5, '敦煌漠蓝新', $today);
        $content = (string)($page['payload']['markdown']['content'] ?? '');

        self::assertSame('ready', $page['status']);
        self::assertTrue($page['base_fact_gate']['allowed']);
        self::assertFalse($page['base_fact_gate']['target_module_required']);
        self::assertFalse($page['base_fact_gate']['ota_modules_required']);
        self::assertSame('not_enabled', $page['operating_target_status']);
        self::assertSame(0, $page['operating_target_record_id']);
        self::assertSame(
            ['missing', 'blocked'],
            array_values($page['optional_channel_status'])
        );
        self::assertStringContainsString('总房费：¥8,745.66', $content);
        self::assertStringContainsString('ADR：¥583.04', $content);
        self::assertStringContainsString('入住率：100%', $content);
        self::assertStringContainsString('RevPAR：¥583.04', $content);
        self::assertStringContainsString('累计售出间夜：15', $content);
        self::assertStringContainsString('经营目标模块：未启用', $content);
        self::assertStringContainsString(
            '缺失（不阻断PMS基础事实）',
            $content
        );
        self::assertStringContainsString(
            '证据门禁阻断（不阻断PMS基础事实）',
            $content
        );
        self::assertStringNotContainsString('20000', $content);
        self::assertFalse($page['message_sent']);
        self::assertFalse($page['webhook_read']);
    }

    public function testImmediateCandidateIsClearlyTestOnlyAndNeedsNoTargetOrOta(): void
    {
        $today = $this->today();
        $candidate = (new SingleHotelOperatingBriefPayloadService(
            $this->digest($today)
        ))->build(1, 5, '敦煌漠蓝新', $today, 'immediate_test');
        $content = (string)($candidate['payload']['markdown']['content'] ?? '');

        self::assertSame('ready', $candidate['status']);
        self::assertSame('base_operating_facts_ready', $candidate['reason_code']);
        self::assertTrue($candidate['formal_send_gate']['allowed']);
        self::assertSame(0, $candidate['operating_target_record_id']);
        self::assertStringContainsString('【测试】企业微信测试群单次推送', $content);
        self::assertStringContainsString('正式群未授权', $content);
        self::assertStringNotContainsString('每日经营目标报告', $content);
    }

    public function testMissingPmsBlocksCandidateEvenWhenOptionalOtaDataExists(): void
    {
        $today = $this->today();
        $digest = new SingleHotelOperatingDigestService(
            static fn(): array => [
                'id' => 5,
                'tenant_id' => 1,
                'name' => '敦煌漠蓝新',
                'status' => 1,
            ],
            static fn(): array => [],
            static fn(): array => ['rows' => []],
            static fn(): array => [],
            $this->scope()
        );

        $candidate = (new SingleHotelOperatingBriefPayloadService($digest))
            ->build(1, 5, '敦煌漠蓝新', $today, 'immediate_test');

        self::assertSame('blocked', $candidate['status']);
        self::assertNull($candidate['payload']);
        self::assertFalse($candidate['base_fact_gate']['allowed']);
        self::assertContains(
            'pms_delivery_evidence_missing',
            array_column($candidate['base_fact_gate']['blockers'], 'code')
        );
        self::assertFalse($candidate['message_sent']);
        self::assertFalse($candidate['webhook_read']);
    }

    private function digest(string $businessDate): SingleHotelOperatingDigestService
    {
        $clock = new DateTimeImmutable(
            $businessDate . ' 02:00:00',
            new DateTimeZone('Asia/Shanghai')
        );

        return new SingleHotelOperatingDigestService(
            static fn(): array => [
                'id' => 5,
                'tenant_id' => 1,
                'name' => '敦煌漠蓝新',
                'status' => 1,
            ],
            static fn(): array => [
                'id' => 1,
                'tenant_id' => 1,
                'hotel_id' => 5,
                'business_date' => $businessDate,
                'identity_status' => 'matched',
                'capture_status' => 'verified',
                'quality_status' => 'verified',
                'reconciliation_status' => 'matched',
                'readback_status' => 'readback_verified',
                'captured_at' => $businessDate . ' 01:00:00',
                'detail_room_fee_total' => 8745.66,
                'detail_row_count' => 25,
                'summary' => [
                    'total_room_fee' => 8745.66,
                    'adr' => 583.04,
                    'occupancy_rate_percent' => 100,
                    'revpar' => 583.04,
                    'sold_room_nights' => 15,
                    'average_daily_room_nights' => 15,
                    'derived_sellable_room_nights' => 15,
                ],
            ],
            static fn(): array => ['rows' => []],
            static fn(): array => [],
            $this->scope(),
            static fn(): DateTimeImmutable => $clock
        );
    }

    /** @return array<string,mixed> */
    private function scope(): array
    {
        return [
            'tenant_id' => 1,
            'hotel_id' => 5,
            'hotel_name' => '敦煌漠蓝新',
            'pms' => [
                'provider' => 'dingdandao',
                'provider_hotel_name' => '敦煌漠蓝',
            ],
            'platforms' => [
                'ctrip' => ['platform_hotel_id' => '130079194'],
                'meituan' => ['platform_hotel_id' => '1029642156589279'],
            ],
        ];
    }

    private function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
            ->format('Y-m-d');
    }
}
