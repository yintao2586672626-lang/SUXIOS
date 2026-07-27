<?php
declare(strict_types=1);

namespace Tests;

use app\service\SingleHotelOperatingBriefService;
use PHPUnit\Framework\TestCase;

final class SingleHotelOperatingBriefServiceTest extends TestCase
{
    public function testRendersTargetIndependentOperatingFactsPreview(): void
    {
        $preview = (new SingleHotelOperatingBriefService())->preview($this->digest());

        self::assertSame('preview_ready', $preview['status']);
        self::assertTrue($preview['preview_only']);
        self::assertFalse($preview['message_sent']);
        self::assertFalse($preview['external_delivery_authorized']);
        self::assertTrue($preview['source_gate_passed']);
        self::assertSame('not_set', $preview['operating_target_status']);
        self::assertStringContainsString('经营目标模块：未启用', $preview['content']);
        self::assertStringContainsString('订单来了PMS', $preview['content']);
        self::assertStringContainsString('携程｜可选渠道事实', $preview['content']);
        self::assertStringContainsString('美团｜可选流量与订单事实', $preview['content']);
        self::assertStringContainsString('¥8,275.67', $preview['content']);
        self::assertStringContainsString('¥1,318.00', $preview['content']);
        self::assertStringContainsString('目标日期订单：0', $preview['content']);
        self::assertStringContainsString('渠道收入：未获取', $preview['content']);
        self::assertLessThanOrEqual(3800, strlen($preview['content']));
    }

    public function testMissingOptionalOtaBlocksRemainVisibleWithoutBlockingPmsPreview(): void
    {
        $digest = $this->digest();
        $digest['sources']['ctrip'] = [
            'delivery_evidence_ready' => false,
            'facts' => [],
        ];
        $digest['sources']['meituan'] = [
            'delivery_evidence_ready' => false,
            'facts' => [],
        ];
        $digest['gaps'] = [
            [
                'code' => 'operating_target_not_set',
                'message' => '经营目标模块未启用，不影响PMS基础经营事实推送。',
            ],
            [
                'code' => 'ctrip_optional_source_unavailable',
                'message' => '携程同店同日渠道事实未通过。',
            ],
            [
                'code' => 'meituan_optional_source_unavailable',
                'message' => '美团同店同日渠道事实未通过。',
            ],
        ];

        $preview = (new SingleHotelOperatingBriefService())->preview($digest);

        self::assertSame('preview_ready', $preview['status']);
        self::assertTrue($preview['source_gate_passed']);
        self::assertSame([], $preview['blockers']);
        self::assertStringContainsString(
            '未获取或未验证（不阻断PMS基础事实）',
            $preview['content']
        );
        self::assertStringContainsString('可选模块与提示', $preview['content']);
        self::assertStringContainsString('携程同店同日渠道事实未通过', $preview['content']);
        self::assertStringContainsString('美团同店同日渠道事实未通过', $preview['content']);
        self::assertStringContainsString('渠道收入：未获取', $preview['content']);
    }

    public function testUnknownValuesAreNeverRenderedAsZero(): void
    {
        $digest = $this->digest();
        $digest['sources']['meituan']['facts']['target_date_order_count'] = null;

        $preview = (new SingleHotelOperatingBriefService())->preview($digest);

        self::assertStringContainsString('目标日期订单：未获取', $preview['content']);
        self::assertStringNotContainsString('目标日期订单：0', $preview['content']);
    }

    public function testBlockedEvidenceProducesPreviewWithoutSendClaim(): void
    {
        $digest = $this->digest();
        $digest['delivery_allowed'] = false;
        $digest['sources']['pms']['delivery_evidence_ready'] = false;
        $digest['blockers'] = [[
            'code' => 'pms_delivery_evidence_missing',
            'message' => '订单来了PMS证据未通过完整门禁。',
        ]];

        $preview = (new SingleHotelOperatingBriefService())->preview($digest);

        self::assertSame('blocked', $preview['status']);
        self::assertFalse($preview['source_gate_passed']);
        self::assertFalse($preview['message_sent']);
        self::assertStringContainsString('当前阻断', $preview['content']);
        self::assertStringContainsString('订单来了PMS证据未通过完整门禁', $preview['content']);
    }

    /** @return array<string,mixed> */
    private function digest(): array
    {
        return [
            'contract_version' => 'suxios.single_hotel_digest.v1',
            'applies' => true,
            'tenant_id' => 1,
            'hotel_id' => 5,
            'hotel_name' => '敦煌漠蓝新',
            'business_date' => '2026-07-27',
            'status' => 'partial',
            'delivery_allowed' => true,
            'operating_target_status' => 'not_set',
            'sources' => [
                'pms' => [
                    'delivery_evidence_ready' => true,
                    'facts' => [
                        'room_fee_revenue' => 8275.67,
                        'adr' => 636.59,
                        'occupancy_rate_percent' => 86.67,
                        'revpar' => 551.71,
                        'sold_room_nights' => 13,
                        'average_daily_room_nights' => 13,
                        'sellable_room_nights' => 15,
                        'detail_room_fee_total' => 8275.67,
                    ],
                ],
                'ctrip' => [
                    'delivery_evidence_ready' => true,
                    'facts' => [
                        'channel_revenue' => 1318,
                        'orders' => 2,
                        'room_nights' => 2,
                    ],
                ],
                'meituan' => [
                    'delivery_evidence_ready' => true,
                    'facts' => [
                        'list_exposure' => 1071,
                        'detail_exposure' => 174,
                        'flow_rate_percent' => 16.25,
                        'paid_orders' => 5,
                        'target_date_order_count' => 0,
                        'channel_revenue' => null,
                        'room_nights' => null,
                    ],
                ],
            ],
            'gaps' => [
                ['code' => 'operating_target_not_set'],
                ['code' => 'meituan_room_revenue_missing'],
                ['code' => 'meituan_room_nights_missing'],
            ],
            'blockers' => [],
        ];
    }
}
