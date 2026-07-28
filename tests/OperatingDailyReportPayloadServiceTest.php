<?php
declare(strict_types=1);

namespace tests;

use app\service\ManualNotificationService;
use app\service\OperatingDailyReportPayloadService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class OperatingDailyReportPayloadServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testBuildsOnlySelectedSameDayPmsAndOtaFactsWithTruthLabels(): void
    {
        $service = $this->service();

        $result = $service->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'immediate_test'
        );

        self::assertSame('ready', $result['status']);
        self::assertTrue($result['formal_send_gate']['allowed']);
        self::assertSame([], $result['formal_send_gate']['blockers']);
        self::assertSame('text', $result['payload']['msgtype']);

        $content = $result['payload']['text']['content'];
        self::assertSame(
            implode("\n", [
                '今日经营数据汇总｜PMS＋OTA',
                '门店：敦煌漠蓝新',
                '业务日：2026-07-28',
                'PMS｜订单来了',
                '- 住宿客房房费：¥8,745.66',
                '- 已售间夜：15',
                '- 可售房夜：15',
                '- 入住率：100%',
                '- ADR：¥583.04',
                '- RevPAR：¥583.04',
                '携程｜OTA 渠道',
                '- APP 实时访客量：58（上周同期 195）',
                '- 实时预订订单：0',
                '- 实时在店间夜：4',
                '- 实时排名：615',
                '- 实时起价：¥0.00',
                '去哪儿｜OTA 渠道',
                '- APP 实时访客量：18（竞争圈平均 44）',
                '- 实时预订订单：0',
                '- APP 实时下单转化率：0%（竞争圈平均 7.44%）',
                '美团｜OTA 渠道',
                '- 曝光人数：471',
                '- 浏览人数：77',
                '- 曝光→浏览转化率：16.35%',
                '- 支付订单：1',
                '- 浏览→支付转化率：1.3%',
            ]),
            $content
        );
    }

    public function testCustomTemplateUsesTheSameVerifiedFactsButOnlyCustomWording(): void
    {
        $result = $this->service()->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'immediate_test',
            'combined',
            [],
            OperatingDailyReportPayloadService::TEMPLATE_MODE_CUSTOM,
            '敦煌漠蓝新经营快报｜{经营日期}',
            "今日房费 {住宿客房房费}，入住率 {入住率}。\n携程访客 {携程实时访客量}，美团支付 {美团支付订单}。"
        );

        self::assertSame('custom', $result['content_template_mode']);
        self::assertSame(
            "敦煌漠蓝新经营快报｜2026-07-28\n"
                . "今日房费 ¥8,745.66，入住率 100%。\n"
                . '携程访客 58，美团支付 1。',
            $result['payload']['text']['content']
        );
        self::assertStringNotContainsString('来源范围', $result['payload']['text']['content']);
    }

    public function testCustomTemplateRejectsUnknownVariables(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('operating_daily_custom_variable_invalid');

        $this->service()->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'immediate_test',
            'combined',
            [],
            OperatingDailyReportPayloadService::TEMPLATE_MODE_CUSTOM,
            '门店日报',
            '未知数据：{不存在的字段}'
        );
    }

    public function testMissingSelectedFieldBlocksInsteadOfUsingOldOrDefaultValue(): void
    {
        $service = $this->service(false);

        $result = $service->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'immediate_test'
        );

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['formal_send_gate']['allowed']);
        self::assertNull($result['payload']);
        self::assertContains(
            'operating_daily_field_missing:ctrip_starting_price',
            array_column($result['formal_send_gate']['blockers'], 'code')
        );
    }

    public function testCtripPlanOnlyRequiresAndRendersSelectedCtripSections(): void
    {
        $result = $this->service(false)->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'immediate_test',
            'ctrip',
            ['ctrip_traffic']
        );

        self::assertSame('ready', $result['status']);
        self::assertSame('ctrip', $result['source_scope']);
        self::assertSame(['ctrip_traffic'], $result['content_sections']);
        self::assertSame(
            [
                'ctrip_visitors',
                'ctrip_last_week_visitors',
                'ctrip_booking_orders',
                'ctrip_in_house_room_nights',
            ],
            array_keys($result['facts'])
        );
        $content = $result['payload']['text']['content'];
        self::assertStringContainsString('携程渠道实时播报', $content);
        self::assertStringContainsString('APP 实时访客量：58', $content);
        self::assertStringNotContainsString('PMS｜订单来了', $content);
        self::assertStringNotContainsString('美团｜OTA 渠道', $content);
        self::assertStringNotContainsString('实时排名：', $content);
        self::assertStringNotContainsString('实时起价：', $content);
    }

    public function testMeituanAndDingdandaoPlansKeepTheirOwnTruthBoundaries(): void
    {
        $meituan = $this->service()->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'scheduled_test',
            'meituan',
            ['meituan_conversion']
        );
        self::assertSame('ready', $meituan['status']);
        self::assertStringContainsString(
            '美团渠道实时播报',
            $meituan['payload']['text']['content']
        );
        self::assertStringNotContainsString(
            '携程｜OTA 渠道',
            $meituan['payload']['text']['content']
        );

        $pms = $this->service()->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'scheduled_test',
            'dingdandao_pms',
            ['pms_summary']
        );
        self::assertSame('ready', $pms['status']);
        self::assertStringContainsString(
            '订单来了 PMS 经营播报',
            $pms['payload']['text']['content']
        );
        self::assertStringContainsString(
            '住宿客房房费：¥8,745.66',
            $pms['payload']['text']['content']
        );
        self::assertStringNotContainsString(
            'ADR：',
            $pms['payload']['text']['content']
        );
        self::assertStringNotContainsString(
            'OTA 渠道',
            $pms['payload']['text']['content']
        );
    }

    public function testDifferentBusinessDateNeverFallsBackToTargetDateRows(): void
    {
        $service = $this->service();

        $result = $service->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-27',
            'immediate_test'
        );

        self::assertSame('blocked', $result['status']);
        self::assertNull($result['payload']);
        self::assertContains(
            'operating_daily_pms_not_verified',
            array_column($result['formal_send_gate']['blockers'], 'code')
        );
        self::assertContains(
            'operating_daily_ctrip_traffic_missing',
            array_column($result['formal_send_gate']['blockers'], 'code')
        );
    }

    public function testManualNotificationRoutesOperatingDailyTypeToDailyPayload(): void
    {
        $manual = new ManualNotificationService(
            null,
            null,
            null,
            null,
            null,
            $this->service()
        );

        $candidate = $this->invokeNonPublic($manual, 'deliveryCandidate', [
            80,
            80,
            '敦煌漠蓝新',
            [
                'template_type' => ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
                'business_date_rule' => 'today',
            ],
            '2026-07-28',
            'scheduled_test',
        ]);

        self::assertSame('ready', $candidate['status']);
        self::assertSame('text', $candidate['payload']['msgtype']);
        self::assertStringContainsString(
            '今日经营数据汇总｜PMS＋OTA',
            $candidate['payload']['text']['content']
        );

        $customCandidate = $this->invokeNonPublic($manual, 'deliveryCandidate', [
            80,
            80,
            '敦煌漠蓝新',
            [
                'template_type' => ManualNotificationService::OPERATING_DAILY_CUSTOM_REPORT_TYPE,
                'business_date_rule' => 'today',
                'title' => '门店快报',
                'body' => '房费：{住宿客房房费}',
            ],
            '2026-07-28',
            'scheduled_test',
        ]);
        self::assertSame(
            "门店快报\n房费：¥8,745.66",
            $customCandidate['payload']['text']['content']
        );
    }

    private function service(bool $includeStartingPrice = true): OperatingDailyReportPayloadService
    {
        $pmsResolver = static function (int $tenantId, int $hotelId, string $date): array {
            if ($tenantId !== 80 || $hotelId !== 80 || $date !== '2026-07-28') {
                return [];
            }
            return [
                'id' => 3,
                'tenant_id' => 80,
                'hotel_id' => 80,
                'business_date' => '2026-07-28',
                'capture_status' => 'verified',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
                'identity_status' => 'matched',
                'reconciliation_status' => 'matched',
                'captured_at' => '2026-07-28 18:33:41',
                'summary' => [
                    'total_room_fee' => 8745.66,
                    'sold_room_nights' => 15,
                    'derived_sellable_room_nights' => 15,
                    'occupancy_rate_percent' => 100,
                    'adr' => 583.04,
                    'revpar' => 583.04,
                ],
            ];
        };

        $rowResolver = static function (
            int $tenantId,
            int $hotelId,
            string $date,
            string $source,
            string $dataType,
            ?string $dimension
        ) use ($includeStartingPrice): ?array {
            if ($tenantId !== 80 || $hotelId !== 80 || $date !== '2026-07-28') {
                return null;
            }
            $lineage = [
                'data_source_id' => 7,
                'sync_task_id' => 9,
                'source_trace_id' => 'ctrip:trace',
                'readback_verified' => 1,
                'data_period' => 'realtime_snapshot',
                'is_final' => 0,
                'snapshot_time' => '2026-07-28 21:38:00',
            ];
            $surfaces = [
                [
                    'surface' => 'home_channel_card',
                    'channel' => 'ctrip',
                    'observed_at' => '2026-07-28 21:37:00',
                    'fields' => ['starting_price'],
                ],
                [
                    'surface' => 'business_report',
                    'channel' => 'ctrip',
                    'observed_at' => '2026-07-28 21:38:00',
                    'fields' => ['realtime_visitors'],
                ],
                [
                    'surface' => 'flow_data',
                    'channel' => 'qunar',
                    'observed_at' => '2026-07-28 21:38:00',
                    'fields' => ['realtime_visitors'],
                ],
            ];
            if ($source === 'ctrip' && $dataType === 'traffic' && $dimension === 'realtime:ctrip') {
                $metrics = [
                    'last_week_visitors' => 195,
                    'starting_price' => 0.0,
                ];
                if (!$includeStartingPrice) {
                    unset($metrics['starting_price']);
                }
                return $lineage + [
                    'id' => 101,
                    'detail_exposure' => 58,
                    'book_order_num' => 0,
                    'quantity' => 4,
                    'raw_data' => [
                        'source_surfaces' => $surfaces,
                        'metrics' => $metrics,
                    ],
                ];
            }
            if ($source === 'ctrip' && $dataType === 'peer_rank' && $dimension === 'realtime:ctrip:rank') {
                return $lineage + [
                    'id' => 102,
                    'rank' => 615,
                    'raw_data' => [
                        'source_surfaces' => $surfaces,
                        'rank_metrics' => [
                            'realtime_rank' => 615,
                            'competitor_rank' => 24,
                            'competitor_total' => 26,
                        ],
                    ],
                ];
            }
            if ($source === 'ctrip' && $dataType === 'traffic' && $dimension === 'realtime:qunar') {
                return $lineage + [
                    'id' => 103,
                    'detail_exposure' => 18,
                    'book_order_num' => 0,
                    'flow_rate' => 0,
                    'raw_data' => [
                        'source_surfaces' => $surfaces,
                        'metrics' => [
                            'visitor_peer_avg' => 44,
                            'visitor_lagging' => true,
                            'conversion_peer_avg' => 7.44,
                            'conversion_lagging' => true,
                        ],
                    ],
                ];
            }
            if ($source === 'meituan' && $dataType === 'traffic' && $dimension === null) {
                return [
                    'id' => 64381,
                    'snapshot_time' => '2026-07-28 18:16:16',
                    'list_exposure' => 471,
                    'detail_exposure' => 77,
                    'flow_rate' => 16.35,
                    'order_submit_num' => 1,
                    'readback_verified' => 1,
                    'data_period' => 'realtime_snapshot',
                    'is_final' => 0,
                    'ingestion_method' => 'legacy',
                ];
            }
            return null;
        };

        return new OperatingDailyReportPayloadService(
            null,
            $pmsResolver,
            $rowResolver
        );
    }
}
