<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManualNotificationBusinessPayloadService;
use PHPUnit\Framework\TestCase;

final class ManualNotificationBusinessPayloadServiceTest extends TestCase
{
    public function testTodayPayloadKeepsPmsCtripAndMeituanIndependent(): void
    {
        $service = new ManualNotificationBusinessPayloadService(
            fn(string $type, int $hotelId, string $date): array =>
                $this->sectionFixture($type, $hotelId, $date)
        );

        $result = $service->pagePreview(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-26',
            'today_revenue_management'
        );

        self::assertSame('ready', $result['status']);
        self::assertTrue($result['formal_send_gate']['allowed']);
        self::assertSame(
            ManualNotificationBusinessPayloadService::FACT_ENVELOPE_VERSION,
            $result['fact_envelope']['contract_version']
        );
        self::assertFalse(
            $result['fact_envelope']['aggregation_policy']['pms_plus_ota_revenue_addition_allowed']
        );
        self::assertSame(
            8745.6,
            $result['fact_envelope']['facts']['dingdandao_pms']['room_fee']
        );
        self::assertSame(
            8745.4,
            $result['fact_envelope']['facts']['dingdandao_pms']
                ['revenue_overview']['total_accommodation_turnover']
        );
        self::assertSame(
            800,
            $result['fact_envelope']['sources']['ctrip_ota']['facts']['revenue']
        );
        self::assertSame(
            'collection_failed',
            $result['fact_envelope']['sources']['meituan_ota']['data_status']
        );
        self::assertSame('ready', $result['fact_envelope']['message_delivery_status']);
        self::assertSame(
            'partial',
            $result['fact_envelope']['fact_completeness_status']
        );
        self::assertFalse(
            $result['fact_envelope']['all_three_sources_readback_verified']
        );
        self::assertContains(
            'meituan_ota',
            $result['fact_envelope']['incomplete_sources']
        );
        self::assertContains(
            'revenue_agent_input_partial_with_explicit_gaps',
            $result['fact_envelope']['allowed_uses']
        );
        self::assertNotContains(
            'revenue_agent_input_three_source_complete',
            $result['fact_envelope']['allowed_uses']
        );
        $content = $result['payload']['markdown']['content'];
        self::assertStringContainsString('房费｜¥8745.60', $content);
        self::assertStringContainsString('住宿总营业额｜¥8745.40', $content);
        self::assertStringContainsString('早餐/客房消费｜-¥0.20', $content);
        self::assertStringContainsString('过去 / 当前 / 未来口径', $content);
        self::assertStringContainsString('房费｜+¥245.60', $content);
        self::assertStringContainsString('今日可售已归零', $content);
        self::assertStringContainsString('携程｜已保存并回读', $content);
        self::assertStringContainsString('美团｜采集失败', $content);
        self::assertStringContainsString('三源不相加', $content);
        self::assertStringNotContainsString('¥10045.60', $content);
    }

    public function testFuturePayloadRendersFourHorizonsAndKeepsDetailsInEnvelope(): void
    {
        $service = new ManualNotificationBusinessPayloadService(
            fn(string $type, int $hotelId, string $date): array =>
                $this->sectionFixture($type, $hotelId, $date)
        );

        $result = $service->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-26',
            'future_room_status',
            'scheduled_test'
        );

        self::assertSame('ready', $result['status']);
        self::assertCount(
            21,
            $result['fact_envelope']['facts']['dingdandao_pms']['daily_rows']
        );
        self::assertSame(
            [3, 7, 14, 21],
            $result['fact_envelope']['facts']['dingdandao_pms']['display_horizons']
        );
        $content = $result['payload']['markdown']['content'];
        foreach (['3天｜', '7天｜', '14天｜', '21天｜'] as $needle) {
            self::assertStringContainsString($needle, $content);
        }
        self::assertSame(0, substr_count($content, '｜订'));
        self::assertStringContainsString(
            '逐日/房型明细｜已保存21天',
            $content
        );
        self::assertStringContainsString(
            '07-27｜景观大床房｜超售1间',
            $content
        );
        self::assertCount(
            2,
            $result['fact_envelope']['facts']['dingdandao_pms']['alerts']
        );
        self::assertStringContainsString('企业微信测试群定时真实投递', $content);
        self::assertStringContainsString('彼此包含、不可相加', $content);
    }

    public function testEnvelopeDeclaresCompleteOnlyWhenAllThreeSourcesAreVerified(): void
    {
        $service = new ManualNotificationBusinessPayloadService(
            function (string $type, int $hotelId, string $date): array {
                $preview = $this->sectionFixture($type, $hotelId, $date);
                $preview['section']['message_data']['sources']['meituan_ota'] = [
                    'data_status' => 'readback_verified',
                    'business_scope' => 'ota_channel',
                    'facts' => ['revenue' => 500, 'orders' => 4, 'room_nights' => 5],
                ];
                $preview['section']['gaps'] = [];
                return $preview;
            }
        );

        $result = $service->pagePreview(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-26',
            'today_revenue_management'
        );

        self::assertSame(
            'complete',
            $result['fact_envelope']['fact_completeness_status']
        );
        self::assertTrue(
            $result['fact_envelope']['all_three_sources_readback_verified']
        );
        self::assertSame([], $result['fact_envelope']['incomplete_sources']);
        self::assertContains(
            'revenue_agent_input_three_source_complete',
            $result['fact_envelope']['allowed_uses']
        );
    }

    public function testDailyReviewUsesLatestSnapshotWithoutClaimingEndOfDayFinal(): void
    {
        $service = new ManualNotificationBusinessPayloadService(
            fn(string $type, int $hotelId, string $date): array =>
                $this->sectionFixture($type, $hotelId, $date)
        );

        $result = $service->pagePreview(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-26',
            'daily_review'
        );

        self::assertSame('ready', $result['status']);
        self::assertStringContainsString(
            '并非日终最终定稿标记',
            $result['payload']['markdown']['content']
        );
    }

    public function testMissingCurrentTemporalDateIsNotBackfilledFromRequest(): void
    {
        $service = new ManualNotificationBusinessPayloadService(
            function (string $type, int $hotelId, string $date): array {
                $preview = $this->sectionFixture($type, $hotelId, $date);
                $pms =& $preview['section']['message_data']['sources']
                    ['dingdandao_pms'];
                $pms['temporal_context']['data_status'] = 'partial';
                $pms['temporal_context']['current']['business_date'] = '';
                $pms['facts']['temporal_context'] = $pms['temporal_context'];
                return $preview;
            }
        );

        $result = $service->pagePreview(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-26',
            'today_revenue_management'
        );

        self::assertSame('ready', $result['status']);
        self::assertStringContainsString(
            '当前｜日期未取得',
            $result['payload']['markdown']['content']
        );
    }

    public function testMissingOrCrossHotelPmsEvidenceBlocksPayload(): void
    {
        $missing = new ManualNotificationBusinessPayloadService(
            fn(string $type, int $hotelId, string $date): array => [
                'contract_version' => 'manual_notification_business_preview.v1',
                'hotel' => ['id' => $hotelId, 'tenant_id' => 80, 'name' => '敦煌漠蓝新'],
                'business_date' => $date,
                'section' => [
                    'key' => $type,
                    'status' => 'partial',
                    'facts' => [],
                    'gaps' => [[
                        'code' => 'dingdandao_today_capture_readback_not_verified',
                        'status' => 'missing',
                        'message' => '订单来了当天事实未取得。',
                    ]],
                    'message_data' => [
                        'contract_version' => 'three_source_today_message_facts.v1',
                        'data_status' => 'blocked',
                        'sources' => [
                            'dingdandao_pms' => [
                                'data_status' => 'missing',
                                'facts' => [
                                    'room_fee' => null,
                                    'sold_room_nights' => null,
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
        $blocked = $missing->pagePreview(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-26',
            'today_revenue_management'
        );
        self::assertSame('blocked', $blocked['status']);
        self::assertSame(
            'business_message_dingdandao_pms_not_verified',
            $blocked['reason_code']
        );
        self::assertNull($blocked['payload']);
        self::assertSame(
            'blocked',
            $blocked['fact_envelope']['status']
        );

        $crossHotel = new ManualNotificationBusinessPayloadService(
            fn(string $type, int $hotelId, string $date): array => array_replace(
                $this->sectionFixture($type, $hotelId, $date),
                ['hotel' => ['id' => 81, 'tenant_id' => 80, 'name' => '其他酒店']]
            )
        );
        $mismatch = $crossHotel->pagePreview(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-26',
            'today_revenue_management'
        );
        self::assertSame('blocked', $mismatch['status']);
        self::assertSame('business_message_identity_mismatch', $mismatch['reason_code']);
        self::assertNull($mismatch['payload']);
    }

    /** @return array<string,mixed> */
    private function sectionFixture(
        string $type,
        int $hotelId,
        string $date
    ): array {
        self::assertSame(80, $hotelId);
        self::assertSame('2026-07-26', $date);
        $revenueOverview = [
            'contract_version' =>
                'dingdandao_accommodation_revenue_overview_message.v1',
            'data_status' => 'readback_verified',
            'fact_scope' => 'whole_hotel_accommodation_turnover',
            'total_accommodation_turnover' => 8745.4,
            'subjects' => [
                [
                    'provider_subject_type' => -1,
                    'subject_name' => '住宿总营业额',
                    'single_day_total' => 8745.4,
                    'period_total' => 8745.4,
                    'percent' => 100,
                ],
                [
                    'provider_subject_type' => 1,
                    'subject_name' => '房费',
                    'single_day_total' => 8745.6,
                    'period_total' => 8745.6,
                    'percent' => 100,
                ],
                [
                    'provider_subject_type' => 7,
                    'subject_name' => '早餐/客房消费',
                    'single_day_total' => -0.2,
                    'period_total' => -0.2,
                    'percent' => 0,
                ],
            ],
            'total_trend' => [
                ['observation_date' => '2026-07-25', 'amount' => 8500],
                ['observation_date' => '2026-07-26', 'amount' => 8745.4],
            ],
            'gap_codes' => [],
        ];
        $temporalContext = [
            'contract_version' => 'dingdandao_temporal_context_message.v1',
            'data_status' => 'readback_verified',
            'past' => [
                'status' => 'verified',
                'date_from' => '2026-07-20',
                'date_to' => '2026-07-26',
            ],
            'current' => [
                'status' => 'verified',
                'business_date' => $date,
                'settlement_status' => 'provisional',
            ],
            'future' => [
                'status' => 'verified',
                'stay_date_from' => '2026-07-27',
                'stay_date_to' => '2026-08-16',
                'display_horizons' => [3, 7, 14, 21],
            ],
        ];
        $snapshotDelta = [
            'contract_version' => 'dingdandao_snapshot_delta_message.v1',
            'data_status' => 'comparable',
            'captured_from' => '2026-07-26 17:35:00',
            'captured_to' => '2026-07-26 18:35:00',
            'deltas' => [
                'room_fee' => 245.6,
                'sold_room_nights' => 1,
                'occupancy_rate_percent' => 6.67,
                'adr' => -24.1,
                'revpar' => 16.37,
            ],
        ];
        $todayAlerts = [[
            'code' => 'pms_today_sold_out',
            'severity' => 'warning',
            'sold_room_nights' => 15,
            'sellable_room_nights' => 15,
            'remaining_sellable_room_nights' => 0,
            'occupancy_rate_percent' => 100,
        ]];
        $pmsToday = [
            'contract_version' => 'dingdandao_today_message_facts.v1',
            'data_status' => 'readback_verified',
            'business_scope' => 'accommodation_room_fee',
            'business_date' => $date,
            'facts' => [
                'room_fee' => 8745.6,
                'sold_room_nights' => 15,
                'sellable_room_nights' => 15,
                'remaining_sellable_room_nights' => 0,
                'occupancy_rate_percent' => 100,
                'adr' => 583.04,
                'revpar' => 583.04,
                'revenue_overview' => $revenueOverview,
                'temporal_context' => $temporalContext,
                'snapshot_delta' => $snapshotDelta,
                'alerts' => $todayAlerts,
            ],
            'revenue_overview' => $revenueOverview,
            'temporal_context' => $temporalContext,
            'snapshot_delta' => $snapshotDelta,
            'alerts' => $todayAlerts,
            'source' => [
                'table' => 'dingdandao_operating_target_captures',
                'record_id' => 980,
                'tenant_id' => 80,
                'hotel_id' => 80,
                'data_date' => $date,
                'captured_at' => '2026-07-26 18:35:00',
                'readback_status' => 'readback_verified',
            ],
        ];
        $sources = [
            'dingdandao_pms' => $pmsToday,
            'ctrip_ota' => [
                'data_status' => 'readback_verified',
                'business_scope' => 'ota_channel',
                'facts' => ['revenue' => 800, 'orders' => 6, 'room_nights' => 7],
            ],
            'meituan_ota' => [
                'data_status' => 'collection_failed',
                'business_scope' => 'ota_channel',
                'facts' => ['revenue' => null, 'orders' => null, 'room_nights' => null],
            ],
        ];
        $aggregation = [
            'pms_plus_ota_revenue_addition_allowed' => false,
            'missing_source_value' => null,
            'cross_source_comparison_requires_same_hotel_and_date' => true,
        ];
        $messageData = [
            'contract_version' => $type === 'daily_review'
                ? 'three_source_daily_review_message_facts.v1'
                : 'three_source_today_message_facts.v1',
            'data_status' => 'partial',
            'business_date' => $date,
            'sources' => $sources,
            'aggregation_policy' => $aggregation,
        ];
        if ($type === 'daily_review') {
            $messageData['snapshot_role'] =
                'latest_verified_snapshot_not_end_of_day_final';
        }
        if ($type === 'future_room_status') {
            $dailyRows = [];
            for ($offset = 1; $offset <= 21; $offset++) {
                $dailyRows[] = [
                    'stay_date' => (new \DateTimeImmutable($date))
                        ->modify('+' . $offset . ' days')
                        ->format('Y-m-d'),
                    'booked_rooms' => 9,
                    'remaining_sellable_rooms' => 6,
                    'occupancy_rate_percent' => 60,
                    'adr' => 500,
                    'revpar' => 300,
                ];
            }
            $horizons = [];
            foreach ([3, 7, 14, 21] as $days) {
                $horizons[] = [
                    'horizon_days' => $days,
                    'booked_room_nights' => 9 * $days,
                    'remaining_sellable_room_nights' => 6 * $days,
                    'occupancy_rate_percent' => 60,
                    'adr' => 500,
                ];
            }
            $messageData = [
                'contract_version' => 'dingdandao_forward_message_facts.v1',
                'data_status' => 'readback_verified',
                'fact_scope' => 'whole_hotel_forward_room_status',
                'as_of_date' => $date,
                'display_horizons' => [3, 7, 14, 21],
                'source_day_count' => 31,
                'display_day_count' => 21,
                'source_coverage_status' => 'complete',
                'source_gap_codes' => [],
                'horizons' => $horizons,
                'daily_rows' => $dailyRows,
                'room_types' => [],
                'alerts' => [
                    [
                        'code' => 'pms_forward_oversold',
                        'severity' => 'critical',
                        'stay_date' => '2026-07-27',
                        'room_type_name' => '景观大床房',
                        'oversold_rooms' => 1,
                    ],
                    [
                        'code' => 'pms_forward_sold_out',
                        'severity' => 'warning',
                        'stay_date' => '2026-07-28',
                        'booked_rooms' => 15,
                        'remaining_sellable_rooms' => 0,
                    ],
                ],
                'source' => [
                    'table' => 'dingdandao_operating_target_captures',
                    'record_id' => 980,
                    'tenant_id' => 80,
                    'hotel_id' => 80,
                    'data_date' => $date,
                    'readback_status' => 'readback_verified',
                ],
                'sources' => [
                    'dingdandao_pms' => [
                        'data_status' => 'readback_verified',
                        'business_scope' => 'whole_hotel_forward_room_status',
                    ],
                    'ctrip_ota' => $sources['ctrip_ota'],
                    'meituan_ota' => $sources['meituan_ota'],
                ],
                'aggregation_policy' => $aggregation,
            ];
        }
        return [
            'contract_version' => 'manual_notification_business_preview.v1',
            'preview_only' => true,
            'hotel' => ['id' => 80, 'tenant_id' => 80, 'name' => '敦煌漠蓝新'],
            'business_date' => $date,
            'scope_boundary' => [
                'missing_data' => '缺失保持 null。',
            ],
            'section' => [
                'key' => $type,
                'title' => match ($type) {
                    'future_room_status' => '远期房态',
                    'daily_review' => '今日复盘',
                    default => '今日收益管理',
                },
                'status' => 'partial',
                'facts' => [],
                'forecasts' => [],
                'reviews' => [],
                'gaps' => [[
                    'code' => 'meituan_collection_failed',
                    'status' => 'collection_failed',
                    'message' => '美团今日采集明确失败；当前不输出该平台数值。',
                ]],
                'message_data' => $messageData,
            ],
        ];
    }
}
