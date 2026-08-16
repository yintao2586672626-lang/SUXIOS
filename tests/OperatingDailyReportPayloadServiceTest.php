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
                '数据说明：OTA 渠道数据为平台采集快照，不代表发送时点状态；以采集时间为准。',
                '携程｜OTA 渠道（采集快照）',
                '- APP 访客量：58（上周同期 195）',
                '- 预订订单：0',
                '- 在店间夜：4',
                '- 排名：615',
                '- 起价：¥688.00',
                '去哪儿｜OTA 渠道（采集快照）',
                '- APP 访客量：18（竞争圈平均 44）',
                '- 预订订单：0',
                '- APP 下单转化率：0%（竞争圈平均 7.44%）',
                '美团｜OTA 渠道（采集快照）',
                '- 曝光人数：471',
                '- 浏览人数：77',
                '- 曝光→浏览转化率：16.35%',
                '- 支付订单：1',
                '- 浏览→支付转化率：1.3%',
            ]),
            $content
        );
        self::assertStringNotContainsString('实时访客量', $content);
        self::assertStringNotContainsString('实时预订订单', $content);
        self::assertStringNotContainsString('实时排名', $content);
        self::assertSame(
            'provider-hotel-80',
            $result['source_snapshot_refs']['pms']['provider_hotel_id']
        );
        self::assertSame(
            'provider-hotel-80',
            $result['source_snapshot_refs']['pms']['bound_provider_hotel_id']
        );
        self::assertSame(
            '敦煌漠蓝新',
            $result['source_snapshot_refs']['pms']['bound_provider_hotel_name']
        );
    }

    public function testDefaultCustomTemplateCallsOtaValuesCollectionSnapshots(): void
    {
        $template = OperatingDailyReportPayloadService::defaultCustomTemplate();

        self::assertStringContainsString('平台采集快照，不代表发送时点状态', $template['body']);
        self::assertStringContainsString('携程｜OTA 渠道（采集快照）', $template['body']);
        self::assertStringContainsString('美团｜OTA 渠道（采集快照）', $template['body']);
        self::assertStringContainsString('{携程访客量}', $template['body']);
        self::assertStringContainsString('{去哪儿下单转化率}', $template['body']);
        self::assertStringNotContainsString('实时', $template['body']);
        self::assertContains(
            '{携程访客量}',
            OperatingDailyReportPayloadService::customTemplateVariables()
        );
        self::assertNotContains(
            '{携程实时访客量}',
            OperatingDailyReportPayloadService::customTemplateVariables()
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

    public function testLegacyRealtimeNamedVariablesRemainCompatibleWithoutBeingOffered(): void
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
            '兼容模板',
            '携程访客 {携程实时访客量}，去哪儿订单 {去哪儿实时预订订单}。'
        );

        self::assertSame(
            "兼容模板\n携程访客 58，去哪儿订单 0。",
            $result['payload']['text']['content']
        );
        self::assertNotContains(
            '{携程实时访客量}',
            OperatingDailyReportPayloadService::customTemplateVariables()
        );
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
            'operating_daily_ctrip_traffic_untrusted',
            array_column($result['formal_send_gate']['blockers'], 'code')
        );
    }

    public function testZeroStartingPriceIsBlockedInsteadOfBeingSentAsARealPrice(): void
    {
        $result = $this->service(true, true, true, true, true, 0.0)->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'immediate_test',
            'ctrip',
            ['ctrip_market']
        );

        self::assertSame('blocked', $result['status']);
        self::assertNull($result['payload']);
        self::assertContains(
            'operating_daily_field_invalid:ctrip_starting_price',
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
        self::assertStringContainsString('携程渠道采集快照', $content);
        self::assertStringContainsString('APP 访客量：58', $content);
        self::assertStringContainsString('平台采集快照，不代表发送时点状态', $content);
        self::assertStringNotContainsString('PMS｜订单来了', $content);
        self::assertStringNotContainsString('美团｜OTA 渠道（采集快照）', $content);
        self::assertStringNotContainsString('- 排名：', $content);
        self::assertStringNotContainsString('- 起价：', $content);
    }

    public function testCombinedCommonTemplateRendersOnlySelectedSections(): void
    {
        $result = $this->service()->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'scheduled_test',
            'combined',
            ['pms_summary', 'ctrip_traffic', 'meituan_traffic']
        );

        self::assertSame('ready', $result['status']);
        $content = $result['payload']['text']['content'];
        foreach ([
            '住宿客房房费：¥8,745.66',
            '已售间夜：15',
            'APP 访客量：58（上周同期 195）',
            '曝光人数：471',
            '曝光→浏览转化率：16.35%',
        ] as $expected) {
            self::assertStringContainsString($expected, $content);
        }
        foreach ([
            '可售房夜：',
            '入住率：',
            'ADR：',
            'RevPAR：',
            '排名：',
            '起价：',
            '去哪儿｜',
            '支付订单：',
            '浏览→支付转化率：',
        ] as $unselected) {
            self::assertStringNotContainsString($unselected, $content);
        }
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
            ['meituan_traffic', 'meituan_conversion']
        );
        self::assertSame('ready', $meituan['status']);
        self::assertSame(
            implode("\n", [
                '美团今日实时数据',
                '门店：敦煌漠蓝新',
                '业务日：2026-07-28',
                '采集完成：2026-07-28 18:16:16',
                '',
                '美团｜OTA 渠道',
                '- 引流价：¥868.00',
                '- 销售间夜：2 间夜',
                '- 销售额：¥2,026.78',
                '- 销售均价：¥1,013.39',
                '- 曝光人数：471',
                '- 浏览人数：77',
                '- 曝光→浏览转化率：16.35%',
                '- 支付订单数：1',
                '- 浏览→支付转化率：1.3%（支付订单数÷浏览人数）',
            ]),
            $meituan['payload']['text']['content']
        );
        self::assertStringNotContainsString(
            '数据范围：',
            $meituan['payload']['text']['content']
        );
        self::assertStringNotContainsString(
            '携程｜OTA 渠道',
            $meituan['payload']['text']['content']
        );
        foreach (['当前模式', '酒店：', '通知类型', '计划发送', '发送触发', '状态：', '来源范围', '来源证据'] as $internalLabel) {
            self::assertStringNotContainsString(
                $internalLabel,
                $meituan['payload']['text']['content']
            );
        }

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

    public function testMeituanExposureToBrowseRateCanUseSameSnapshotCounts(): void
    {
        $result = $this->service(true, true, false)->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'immediate_test',
            'meituan',
            ['meituan_traffic', 'meituan_conversion']
        );

        self::assertSame('ready', $result['status']);
        self::assertNotNull($result['payload']);
        self::assertStringContainsString(
            '曝光→浏览转化率：16.35%',
            $result['payload']['text']['content']
        );
        self::assertContains(
            'meituan_exposure_view_conversion_derived',
            array_column($result['formal_send_gate']['warnings'], 'code')
        );
        self::assertSame(
            'meituan_viewers_div_exposure',
            $result['fact_derivations']['meituan_exposure_view_conversion']['method']
        );
    }

    public function testPagePreviewUsesPreviewSpecificStatus(): void
    {
        $ready = $this->service()->pagePreview(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'meituan',
            ['meituan_traffic', 'meituan_conversion']
        );
        self::assertSame('preview_ready', $ready['status']);
        self::assertSame('preview_only', $ready['delivery_status']);

        $blocked = $this->service(true, true, true, true, false)->pagePreview(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'meituan',
            ['meituan_traffic', 'meituan_conversion']
        );
        self::assertSame('preview_unavailable', $blocked['status']);
        self::assertSame('preview_unavailable', $blocked['delivery_status']);
    }

    public function testMeituanExposureToBrowseRateDoesNotDivideByZero(): void
    {
        $result = $this->service(
            true,
            true,
            false,
            true,
            true,
            688.0,
            null,
            0.0,
            0.0
        )->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'immediate_test',
            'meituan',
            ['meituan_traffic', 'meituan_conversion']
        );

        self::assertSame('blocked', $result['status']);
        self::assertNull($result['payload']);
        self::assertContains(
            'operating_daily_field_missing:meituan_exposure_view_conversion',
            array_column($result['formal_send_gate']['blockers'], 'code')
        );
    }

    public function testMeituanReadbackWithoutBoundLineageCannotEnterAReport(): void
    {
        $result = $this->service(true, true, true, true, false)->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'immediate_test',
            'meituan',
            ['meituan_traffic', 'meituan_conversion']
        );

        self::assertSame('blocked', $result['status']);
        self::assertNull($result['payload']);
        self::assertContains(
            'operating_daily_meituan_traffic_untrusted',
            array_column($result['formal_send_gate']['blockers'], 'code')
        );
    }

    public function testReadbackBrowserAssistRowCannotEnterAReport(): void
    {
        $rowResolver = static function (
            int $tenantId,
            int $hotelId,
            string $date,
            string $source,
            string $dataType,
            ?string $dimension
        ): ?array {
            if ($source !== 'meituan' || $dataType !== 'traffic') {
                return null;
            }
            $row = self::trustedOtaFixture([
                'id' => 9001,
                'data_source_id' => 8,
                'sync_task_id' => 12,
                'source_trace_id' => 'meituan:unverified-assist',
                'snapshot_time' => $date . ' 18:16:16',
                'readback_verified' => 1,
                'data_period' => 'realtime_snapshot',
                'is_final' => 0,
                'list_exposure' => 471,
                'detail_exposure' => 77,
            ], $date, $source, $dataType, $dimension, [
                'list_exposure',
                'detail_exposure',
            ]);
            $row['ingestion_method'] = 'browser_assist_dom';
            $row['validation_status'] = 'unverified';
            $row['validation_flags'] = json_encode([
                'source_ingestion_method_unverified',
                'hotel_binding_unverified',
            ]);
            return $row;
        };
        $service = new OperatingDailyReportPayloadService(
            null,
            null,
            $rowResolver
        );

        $result = $service->build(
            80,
            80,
            '敦煌漠蓝新',
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'immediate_test',
            'meituan',
            ['meituan_traffic']
        );

        self::assertSame('blocked', $result['status']);
        self::assertNull($result['payload']);
        self::assertContains(
            'operating_daily_meituan_traffic_untrusted',
            array_column($result['formal_send_gate']['blockers'], 'code')
        );
    }

    public function testHistoricalBusinessDateAcceptsOnlyAValidHistoricalRole(): void
    {
        $rowResolver = static function (
            int $tenantId,
            int $hotelId,
            string $date,
            string $source,
            string $dataType,
            ?string $dimension
        ): ?array {
            if ($source !== 'meituan' || $dataType !== 'traffic') {
                return null;
            }
            return self::trustedOtaFixture([
                'id' => 9002,
                'data_source_id' => 8,
                'sync_task_id' => 13,
                'source_trace_id' => 'meituan:historical-final',
                'readback_verified' => 1,
                'data_period' => 'historical_daily',
                'is_final' => 1,
                'list_exposure' => 991,
                'detail_exposure' => 125,
            ], $date, $source, $dataType, $dimension, [
                'list_exposure',
                'detail_exposure',
            ]);
        };
        $service = new OperatingDailyReportPayloadService(
            null,
            null,
            $rowResolver
        );

        $historical = $service->build(
            80,
            80,
            '敦煌漠蓝新',
            (new \DateTimeImmutable('yesterday'))->format('Y-m-d'),
            'immediate_test',
            'meituan',
            ['meituan_traffic']
        );
        self::assertSame('ready', $historical['status']);

        $today = $service->build(
            80,
            80,
            '敦煌漠蓝新',
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'immediate_test',
            'meituan',
            ['meituan_traffic']
        );
        self::assertSame('blocked', $today['status']);
        self::assertContains(
            'operating_daily_meituan_traffic_untrusted',
            array_column($today['formal_send_gate']['blockers'], 'code')
        );
    }

    public function testMeituanCompactReportDoesNotTurnMissingBusinessFactsIntoZero(): void
    {
        $meituan = $this->service(true, false)->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'immediate_test',
            'meituan',
            ['meituan_traffic', 'meituan_conversion']
        );

        self::assertSame('ready', $meituan['status']);
        self::assertArrayNotHasKey(
            'meituan_business_row_id',
            $meituan['source_snapshot_ids']
        );
        $content = $meituan['payload']['text']['content'];
        self::assertStringContainsString('- 引流价：未返回', $content);
        self::assertStringContainsString('- 销售间夜：未返回', $content);
        self::assertStringContainsString('- 销售额：未返回', $content);
        self::assertStringContainsString('- 销售均价：未返回', $content);
        self::assertStringNotContainsString('- 销售间夜：0 间夜', $content);
        self::assertStringContainsString('- 曝光人数：471', $content);
    }

    public function testStoredNormalizedMeituanBusinessEnvelopeIsReadable(): void
    {
        $raw = $this->invokeNonPublic(
            $this->service(),
            'raw',
            [[
                'raw_data' => [
                    'row' => [
                        'lead_price' => 1158,
                        'sales_avg_price' => 1032.39,
                    ],
                    'field_facts' => [[
                        'metric_key' => 'lead_price',
                        'status' => 'captured',
                        'stored_value_present' => true,
                    ]],
                ],
            ]]
        );

        self::assertSame(1158, $raw['lead_price']);
        self::assertSame(1032.39, $raw['sales_avg_price']);
        self::assertSame('captured', $raw['field_facts'][0]['status']);
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
        self::assertArrayNotHasKey('pms', $result['source_snapshot_refs']);
    }

    public function testLegacyPmsFlagsWithoutUnifiedSourceEvidenceAreBlocked(): void
    {
        $result = $this->service(true, true, true, false)->build(
            80,
            80,
            'hotel-80',
            '2026-07-28',
            'immediate_test',
            'dingdandao_pms',
            ['pms_summary']
        );

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['formal_send_gate']['allowed']);
        self::assertNull($result['payload']);
        self::assertContains(
            'operating_daily_pms_not_verified',
            array_column($result['formal_send_gate']['blockers'], 'code')
        );
    }

    public function testPersistedLegacyPmsApiReadbackPassesTheReportGate(): void
    {
        $service = $this->service();
        $capture = $this->invokeNonPublic(
            $service,
            'resolvePms',
            [80, 80, '2026-07-28']
        );
        $sourceApiPath =
            '/v2/um-b/web/pro/data/businessIndicatorsTotal';
        $capture['capture_contract_version'] =
            'dingdandao_operating_target_capture.v2';
        $capture['source_api_path'] = $sourceApiPath;
        $capture['identity_evidence_type'] =
            'verified_api_store_identity';
        $capture['field_trace'] = [
            'total_room_fee' =>
                'API:' . $sourceApiPath . '#data.totalRoomFee',
            'adr' => 'API:' . $sourceApiPath . '#data.adr',
            'occupancy_rate_percent' =>
                'API:' . $sourceApiPath . '#data.occ',
            'revpar' => 'API:' . $sourceApiPath . '#data.revPar',
            'sold_room_nights' =>
                'API:' . $sourceApiPath . '#data.totalSalesNight',
            'average_daily_room_nights' =>
                'API:' . $sourceApiPath . '#data.adn',
            'provider_hotel_identity' =>
                'API:/v2/ntw/web/ntw/get#data.id+data.name',
            'room_type_names' =>
                'API:/v2/um-b/web/pro/data/businessIndicatorsSumDetail?type=0#data.list[]',
            'room_fee_details' =>
                'API:/v2/um-b/web/pro/data/businessIndicatorsDailyDetail?type=0#data.list[].dailyRoomRate[]',
        ];
        unset(
            $capture['collection_mode'],
            $capture['capture_strategy'],
            $capture['capture_evidence'],
            $capture['source_trace_id']
        );

        $gate = $this->invokeNonPublic($service, 'pmsGate', [
            $capture,
            80,
            80,
            'hotel-80',
            '2026-07-28',
        ]);

        self::assertTrue($gate['allowed']);
        self::assertSame(
            'operating_daily_pms_verified',
            $gate['reason_code']
        );
    }

    public function testPmsCaptureMustMatchTheCurrentProviderBinding(): void
    {
        $result = $this->service(
            pmsBindingResolver: static fn(): array => [
                'configured' => true,
                'expected_provider_hotel_id' => 'different-provider-hotel',
                'expected_provider_hotel_name' => '敦煌漠蓝新',
            ]
        )->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'immediate_test',
            'dingdandao_pms',
            ['pms_summary']
        );

        self::assertSame('blocked', $result['status']);
        self::assertNull($result['payload']);
        self::assertContains(
            'operating_daily_pms_provider_identity_mismatch',
            array_column($result['formal_send_gate']['blockers'], 'code')
        );
    }

    public function testPmsReportFailsClosedWhenCurrentBindingIsMissing(): void
    {
        $result = $this->service(
            pmsBindingResolver: static fn(): array => [
                'configured' => false,
                'expected_provider_hotel_id' => null,
                'expected_provider_hotel_name' => '',
            ]
        )->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'immediate_test',
            'dingdandao_pms',
            ['pms_summary']
        );

        self::assertSame('blocked', $result['status']);
        self::assertNull($result['payload']);
        self::assertContains(
            'operating_daily_pms_binding_missing',
            array_column($result['formal_send_gate']['blockers'], 'code')
        );
    }

    public function testOtaOnlyReportDoesNotReadThePmsBinding(): void
    {
        $bindingCalls = 0;
        $result = $this->service(
            pmsBindingResolver: static function () use (&$bindingCalls): array {
                $bindingCalls++;
                throw new \RuntimeException('must_not_read_pms_binding');
            }
        )->build(
            80,
            80,
            '敦煌漠蓝新',
            '2026-07-28',
            'immediate_test',
            'ctrip',
            ['ctrip_traffic']
        );

        self::assertSame('ready', $result['status']);
        self::assertSame(0, $bindingCalls);
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

    private function service(
        bool $includeStartingPrice = true,
        bool $includeMeituanBusiness = true,
        bool $includeMeituanExposureToBrowseRate = true,
        bool $includePmsEvidence = true,
        bool $includeMeituanLineage = true,
        float $startingPrice = 688.0,
        ?callable $pmsBindingResolver = null,
        float $meituanExposure = 471.0,
        float $meituanViewers = 77.0
    ): OperatingDailyReportPayloadService
    {
        $pmsResolver = static function (
            int $tenantId,
            int $hotelId,
            string $date
        ) use ($includePmsEvidence): array {
            if ($tenantId !== 80 || $hotelId !== 80 || $date !== '2026-07-28') {
                return [];
            }
            $sourceUrl = \app\service\DingdandaoOperatingTargetCaptureService::SOURCE_URL;
            $sourceApiPath = '/api/verified';
            $providerHotelId = 'provider-hotel-80';
            $captureEvidence =
                \app\service\DingdandaoOperatingTargetCaptureService::
                expectedCaptureEvidence(
                    $sourceApiPath,
                    '2026-07-28',
                    $providerHotelId,
                    'full_diagnostic'
                );
            if (!is_array($captureEvidence)) {
                throw new \RuntimeException(
                    'dingdandao_test_capture_evidence_invalid'
                );
            }
            $sourceTraceId = (string)$captureEvidence['source_trace_id'];
            $capture = [
                'id' => 3,
                'tenant_id' => 80,
                'hotel_id' => 80,
                'provider' => 'dingdandao_pms',
                'provider_hotel_id' => $providerHotelId,
                'provider_hotel_name' => '敦煌漠蓝新',
                'business_date' => '2026-07-28',
                'source_url' => $sourceUrl,
                'source_api_path' => $sourceApiPath,
                'source_scope' => 'today_only',
                'collection_mode' => 'full_diagnostic',
                'capture_method' => 'network_response',
                'capture_strategy' => 'verified_endpoint_recipe',
                'capture_status' => 'verified',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
                'identity_status' => 'matched',
                'reconciliation_status' => 'matched',
                'source_trace_id' => $sourceTraceId,
                'source_fingerprint' => str_repeat('b', 64),
                'detail_row_count' => 25,
                'captured_at' => '2026-07-28 18:33:41',
                'summary' => [
                    'total_room_fee' => 8745.66,
                    'sold_room_nights' => 15,
                    'average_daily_room_nights' => 15.0,
                    'derived_sellable_room_nights' => 15,
                    'occupancy_rate_percent' => 100,
                    'adr' => 583.04,
                    'revpar' => 583.04,
                ],
                'capture_evidence' => $captureEvidence,
                'gaps' => [],
            ];
            if (!$includePmsEvidence) {
                $capture['capture_method'] = 'browser_assist_dom';
                $capture['capture_evidence'] = [];
                unset(
                    $capture['source_api_path'],
                    $capture['collection_mode'],
                    $capture['capture_strategy'],
                    $capture['source_trace_id'],
                    $capture['source_fingerprint']
                );
            }
            return $capture;
        };

        $rowResolver = static function (
            int $tenantId,
            int $hotelId,
            string $date,
            string $source,
            string $dataType,
            ?string $dimension
        ) use (
            $includeStartingPrice,
            $includeMeituanBusiness,
            $includeMeituanExposureToBrowseRate,
            $includeMeituanLineage,
            $startingPrice,
            $meituanExposure,
            $meituanViewers
        ): ?array {
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
                    'starting_price' => $startingPrice,
                ];
                if (!$includeStartingPrice) {
                    unset($metrics['starting_price']);
                }
                return self::trustedOtaFixture($lineage + [
                    'id' => 101,
                    'detail_exposure' => 58,
                    'book_order_num' => 0,
                    'quantity' => 4,
                    'raw_data' => [
                        'source_surfaces' => $surfaces,
                        'metrics' => $metrics,
                    ],
                ], $date, 'ctrip', 'traffic', $dimension, [
                    'realtime_visitors',
                    'last_week_visitors',
                    'booking_order_count',
                    'in_house_room_nights',
                    ...($includeStartingPrice ? ['starting_price'] : []),
                ]);
            }
            if ($source === 'ctrip' && $dataType === 'peer_rank' && $dimension === 'realtime:ctrip:rank') {
                return self::trustedOtaFixture($lineage + [
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
                ], $date, 'ctrip', 'peer_rank', $dimension, [
                    'realtime_rank',
                    'competitor_rank',
                    'competitor_total',
                ]);
            }
            if ($source === 'ctrip' && $dataType === 'traffic' && $dimension === 'realtime:qunar') {
                return self::trustedOtaFixture($lineage + [
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
                ], $date, 'ctrip', 'traffic', $dimension, [
                    'realtime_visitors',
                    'visitor_peer_avg',
                    'booking_order_count',
                    'order_conversion_rate',
                    'conversion_peer_avg',
                    'visitor_lagging',
                    'conversion_lagging',
                ]);
            }
            if ($includeMeituanBusiness
                && $source === 'meituan'
                && $dataType === 'business'
                && $dimension === null
            ) {
                return self::trustedOtaFixture(($includeMeituanLineage ? [
                    'platform' => 'meituan',
                    'data_source_id' => 8,
                    'sync_task_id' => 10,
                    'source_trace_id' => 'meituan:trace',
                    'validation_status' => 'verified',
                ] : []) + [
                    'id' => 64380,
                    'snapshot_time' => '2026-07-28 18:16:16',
                    'quantity' => 2,
                    'amount' => 2026.78,
                    'data_value' => 1013.39,
                    'readback_verified' => 1,
                    'data_period' => 'realtime_snapshot',
                    'is_final' => 0,
                    'raw_data' => [
                        'lead_price' => 868,
                    ],
                ], $date, 'meituan', 'business', $dimension, [
                    'lead_price',
                    'sales_room_nights',
                    'sales_amount',
                    'sales_avg_price',
                ], $includeMeituanLineage);
            }
            if ($source === 'meituan' && $dataType === 'traffic' && $dimension === null) {
                return self::trustedOtaFixture(($includeMeituanLineage ? [
                    'platform' => 'meituan',
                    'data_source_id' => 8,
                    'sync_task_id' => 10,
                    'source_trace_id' => 'meituan:trace',
                    'validation_status' => 'verified',
                ] : []) + [
                    'id' => 64381,
                    'snapshot_time' => '2026-07-28 18:16:16',
                    'list_exposure' => $meituanExposure,
                    'detail_exposure' => $meituanViewers,
                    'flow_rate' => 16.35,
                    'order_submit_num' => 1,
                    'readback_verified' => 1,
                    'data_period' => 'realtime_snapshot',
                    'is_final' => 0,
                    'raw_data' => $includeMeituanExposureToBrowseRate
                        ? [
                            'exposure_to_browse_rate' => 16.35,
                            'intentionPerExposure' => '16.35%',
                        ]
                        : [],
                ], $date, 'meituan', 'traffic', $dimension, [
                    'list_exposure',
                    'detail_exposure',
                    'order_submit_num',
                ], $includeMeituanLineage);
            }
            return null;
        };

        $pmsBindingResolver ??= static fn(): array => [
            'configured' => true,
            'expected_provider_hotel_id' => 'provider-hotel-80',
            'expected_provider_hotel_name' => '敦煌漠蓝新',
        ];

        return new OperatingDailyReportPayloadService(
            null,
            $pmsResolver,
            $rowResolver,
            null,
            $pmsBindingResolver
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $metricKeys
     * @return array<string, mixed>
     */
    private static function trustedOtaFixture(
        array $row,
        string $date,
        string $source,
        string $dataType,
        ?string $dimension,
        array $metricKeys,
        bool $withLineage = true
    ): array {
        $trace = $withLineage
            ? trim((string)($row['source_trace_id'] ?? $source . ':fixture'))
            : '';
        $urlHash = hash(
            'sha256',
            'https://fixture.suxios.test/' . $source . '/' . $dataType
        );
        $captureEvidence = [
            'source_trace_id' => $trace,
            'source_url_hash' => $urlHash,
        ];
        $raw = is_array($row['raw_data'] ?? null)
            ? $row['raw_data']
            : [];
        if ($withLineage) {
            $raw['source_trace_id'] = $trace;
            $raw['hotel_id'] = 'provider-hotel-80';
            $raw['capture_evidence'] = $captureEvidence;
        }
        $raw['field_facts'] = array_map(
            static fn(string $metricKey): array => [
                'metric_key' => $metricKey,
                'status' => 'captured',
                'source_path' => 'fixture.metrics.' . $metricKey,
                'storage_field' =>
                    'online_daily_data.raw_data.facts.metric_key='
                    . $metricKey,
                'stored_value_present' => true,
                'value' => 1,
                'capture_evidence' => $captureEvidence,
            ],
            $metricKeys
        );
        return array_replace($row, [
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'hotel_id' => 'provider-hotel-80',
            'source' => $source,
            'platform' => $source,
            'data_date' => $date,
            'data_type' => $dataType,
            'dimension' => $dimension ?? '',
            'validation_status' => 'verified',
            'validation_flags' => '[]',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => $trace,
            'raw_data' => $raw,
        ]);
    }
}
