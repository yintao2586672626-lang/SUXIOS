<?php
declare(strict_types=1);

namespace Tests;

use app\service\SingleHotelOperatingBriefService;
use PHPUnit\Framework\TestCase;

final class SingleHotelOperatingBriefServiceTest extends TestCase
{
    public function testRendersTargetIndependentThreeSourcePreview(): void
    {
        $preview = (new SingleHotelOperatingBriefService())->preview($this->digest());

        self::assertSame('preview_ready', $preview['status']);
        self::assertTrue($preview['preview_only']);
        self::assertFalse($preview['message_sent']);
        self::assertFalse($preview['external_delivery_authorized']);
        self::assertTrue($preview['source_gate_passed']);
        self::assertSame('not_set', $preview['operating_target_status']);
        self::assertStringContainsString('经营目标：未设置', $preview['content']);
        self::assertStringContainsString('订单来了PMS', $preview['content']);
        self::assertStringContainsString('携程｜流量、转化与成交事实', $preview['content']);
        self::assertStringContainsString('美团｜流量、转化与支付订单事实', $preview['content']);
        self::assertStringContainsString('¥8,275.67', $preview['content']);
        self::assertStringContainsString('¥1,318.00', $preview['content']);
        self::assertStringContainsString('曝光→详情：20%', $preview['content']);
        self::assertStringContainsString('平台详情→支付：14.29%', $preview['content']);
        self::assertStringContainsString('自算支付订单/详情访问：2.87%', $preview['content']);
        self::assertStringContainsString('独立填单人数：未获取', $preview['content']);
        self::assertStringContainsString('目标日期订单：0', $preview['content']);
        self::assertStringContainsString('渠道收入：未获取', $preview['content']);
        self::assertLessThanOrEqual(3800, strlen($preview['content']));
    }

    public function testUnknownValuesAreNeverRenderedAsZero(): void
    {
        $digest = $this->digest();
        $digest['sources']['meituan']['facts']['target_date_order_count'] = null;

        $preview = (new SingleHotelOperatingBriefService())->preview($digest);

        self::assertStringContainsString('目标日期订单：未获取', $preview['content']);
        self::assertStringNotContainsString('目标日期订单：0', $preview['content']);
    }

    public function testZeroDenominatorIsRenderedAsNotCalculableInsteadOfZeroPercent(): void
    {
        $digest = $this->digest();
        $digest['sources']['meituan']['conversion_rates']['list_to_detail'] = [
            'status' => 'not_calculable_zero_denominator',
            'value_percent' => null,
            'numerator' => 0,
            'denominator' => 0,
        ];

        $preview = (new SingleHotelOperatingBriefService())->preview($digest);

        self::assertStringContainsString('曝光→详情：不可计算（分母为0）', $preview['content']);
        self::assertStringNotContainsString('曝光→详情：0%', $preview['content']);
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
                        'list_exposure' => 1000,
                        'detail_exposure' => 200,
                        'order_filling_visitors' => 40,
                        'order_submit_users' => 20,
                    ],
                    'conversion_rates' => [
                        'list_to_detail' => [
                            'status' => 'available',
                            'value_percent' => 20,
                        ],
                        'detail_to_order_filling' => [
                            'status' => 'available',
                            'value_percent' => 20,
                        ],
                        'order_filling_to_submit' => [
                            'status' => 'available',
                            'value_percent' => 50,
                        ],
                        'detail_to_submit' => [
                            'status' => 'available',
                            'value_percent' => 10,
                        ],
                    ],
                ],
                'meituan' => [
                    'delivery_evidence_ready' => true,
                    'facts' => [
                        'list_exposure' => 1071,
                        'detail_exposure' => 174,
                        'order_filling_visitors' => null,
                        'flow_rate_percent' => 16.25,
                        'platform_reported_rate_percent' => 16.25,
                        'platform_detail_to_paid_rate_percent' => 14.29,
                        'paid_orders' => 5,
                        'target_date_order_count' => 0,
                        'channel_revenue' => null,
                        'room_nights' => null,
                    ],
                    'conversion_rates' => [
                        'list_to_detail' => [
                            'status' => 'available',
                            'value_percent' => 16.25,
                        ],
                        'detail_to_paid_order' => [
                            'status' => 'available',
                            'value_percent' => 2.87,
                        ],
                        'platform_detail_to_paid_order' => [
                            'status' => 'available',
                            'value_percent' => 14.29,
                        ],
                    ],
                ],
            ],
            'gaps' => [
                ['code' => 'operating_target_not_set'],
                ['code' => 'meituan_order_filling_missing'],
                ['code' => 'meituan_room_revenue_missing'],
                ['code' => 'meituan_room_nights_missing'],
            ],
            'blockers' => [],
        ];
    }
}
