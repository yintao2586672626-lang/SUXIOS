<?php
declare(strict_types=1);

namespace Tests;

use app\controller\DailyReport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class DailyReportTest extends TestCase
{
    use ReflectionHelper;

    private function controller(): DailyReport
    {
        $reflection = new ReflectionClass(DailyReport::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    /**
     * 覆盖 normalizeNumber/readNumericValue/resolveSalableRoomsTotal/resolveReportSalableRooms：
     * 验证数字清洗、数组与对象读取、可售房间优先级和空值兜底。
     */
    public function testNumberNormalizationAndRoomResolution(): void
    {
        $controller = $this->controller();

        self::assertSame(1234.5, $this->invokeNonPublic($controller, 'normalizeNumber', ['1,234.5']));
        self::assertSame(12.5, $this->invokeNonPublic($controller, 'normalizeNumber', ['12.5%']));
        self::assertNull($this->invokeNonPublic($controller, 'normalizeNumber', ['bad']));
        self::assertNull($this->invokeNonPublic($controller, 'normalizeNumber', [[]]));

        $hotel = (object)['salable_rooms_total' => '80', 'room_count' => 100];
        self::assertSame(90.0, $this->invokeNonPublic($controller, 'resolveSalableRoomsTotal', [
            $hotel,
            ['salable_rooms_total' => '90'],
            ['salable_rooms' => 70],
        ]));
        self::assertSame(70.0, $this->invokeNonPublic($controller, 'resolveSalableRoomsTotal', [
            $hotel,
            [],
            ['salable_rooms' => 70],
        ]));
        self::assertSame(80.0, $this->invokeNonPublic($controller, 'readNumericValue', [$hotel, ['salable_rooms_total']]));
        self::assertNull($this->invokeNonPublic($controller, 'resolveReportSalableRooms', [[]]));
    }

    /**
     * 覆盖 calculateMonthSum：
     * 验证月累计只累加有效数值，忽略非法输入。
     */
    public function testCalculateMonthSumIgnoresInvalidValues(): void
    {
        $controller = $this->controller();
        $reports = [
            (object)['report_data' => ['xb_revenue' => '1,200', 'mt_revenue' => 'bad', 'xb_rooms' => 3]],
            (object)['report_data' => ['xb_revenue' => 800, 'mt_revenue' => '100.5', 'xb_rooms' => '2']],
        ];

        $sum = $this->invokeNonPublic($controller, 'calculateMonthSum', [$reports]);

        self::assertSame(2000.0, $sum['xb_revenue']);
        self::assertSame(100.5, $sum['mt_revenue']);
        self::assertSame(5.0, $sum['xb_rooms']);
        self::assertSame(2, $sum['__evidence']['report_count']);
        self::assertSame(2, $sum['__evidence']['field_counts']['xb_revenue']);
        self::assertSame(1, $sum['__evidence']['field_counts']['mt_revenue']);

        $invalidOnly = $this->invokeNonPublic($controller, 'calculateMonthSum', [[
            (object)['report_data' => ['xb_revenue' => '']],
        ]]);
        self::assertArrayNotHasKey('xb_revenue', $invalidOnly);
    }

    public function testLegacyJsonReportDataFeedsMonthSumAndExportTotals(): void
    {
        $controller = $this->controller();
        $reports = [
            (object)['report_data' => json_encode([
                'xb_revenue' => '1,200',
                'xb_rooms' => 3,
                'online_revenue' => 1200,
                'online_rooms' => 3,
                'offline_revenue' => 0,
                'offline_rooms' => 0,
                'other_revenue_total' => 0,
                'room_revenue' => 1200,
                'revenue' => 1200,
                'total_rooms' => 3,
                'overnight_rooms' => 3,
                'hourly_revenue' => 0,
                'hourly_rooms' => 0,
                'salable_rooms' => 10,
            ], JSON_UNESCAPED_UNICODE)],
            (object)['report_data' => (object)[
                'mt_revenue' => 800,
                'mt_rooms' => '2',
                'online_revenue' => 800,
                'online_rooms' => 2,
                'offline_revenue' => 0,
                'offline_rooms' => 0,
                'other_revenue_total' => 0,
                'room_revenue' => 800,
                'revenue' => 800,
                'total_rooms' => 2,
                'overnight_rooms' => 2,
                'hourly_revenue' => 0,
                'hourly_rooms' => 0,
                'salable_rooms' => 10,
            ]],
        ];

        $sum = $this->invokeNonPublic($controller, 'calculateMonthSum', [$reports]);

        self::assertSame(1200.0, $sum['xb_revenue']);
        self::assertSame(800.0, $sum['mt_revenue']);
        self::assertSame(5.0, $sum['xb_rooms'] + $sum['mt_rooms']);

        $html = $this->invokeNonPublic($controller, 'generateExcelHtml', [[
            'reports' => [
                [
                    'hotel_name' => 'Legacy Hotel',
                    'report_date' => '2026-05-01',
                    'data' => $reports[0]->report_data,
                ],
                [
                    'hotel_name' => 'Legacy Hotel',
                    'report_date' => '2026-05-02',
                    'data' => $reports[1]->report_data,
                ],
            ],
            'month_task' => ['revenue_budget' => 4000],
        ]]);

        self::assertStringContainsString('2026-05-01', $html);
        self::assertStringContainsString('2026-05-02', $html);
        self::assertStringContainsString('2,000.00', $html);
        self::assertStringContainsString('400.00', $html);
    }

    /**
     * 覆盖 calculateReportDetail：
     * 验证日报核心收入、房晚、出租率、ADR/RevPAR、点评和私域字段计算。
     */
    public function testCalculateReportDetailBuildsCoreBusinessMetrics(): void
    {
        $controller = $this->controller();
        $hotel = (object)['name' => 'Test Hotel', 'salable_rooms_total' => 20];
        $reportData = [
            'xb_revenue' => 1000,
            'mt_revenue' => 500,
            'walkin_revenue' => 300,
            'hourly_revenue' => 200,
            'parking_revenue' => 50,
            'online_revenue' => 1500,
            'offline_revenue' => 500,
            'other_revenue_total' => 50,
            'room_revenue' => 2000,
            'revenue' => 2050,
            'xb_rooms' => 5,
            'mt_rooms' => 2,
            'walkin_rooms' => 1,
            'hourly_rooms' => 1,
            'online_rooms' => 7,
            'offline_rooms' => 2,
            'total_rooms' => 9,
            'salable_rooms' => 20,
            'overnight_rooms' => 7,
            'member_card_sold' => 2,
            'wechat_add' => 3,
            'private_revenue' => 100,
            'private_rooms' => 1,
            'stored_value' => 500,
            'xb_good_review' => 2,
            'xb_bad_review' => 0,
            'mt_good_review' => 0,
            'mt_bad_review' => 1,
            'fliggy_good_review' => 0,
            'fliggy_bad_review' => 0,
            'tomorrow_booking' => 4,
            'cash_income' => 30,
        ];
        $taskData = [
            'revenue_budget' => 31000,
            'new_members' => 20,
            'wechat_new_friends' => 30,
        ];
        $monthSum = $reportData + [
            'member_card_sold' => 10,
            'wechat_add' => 12,
        ];

        $detail = $this->invokeNonPublic($controller, 'calculateReportDetail', [
            $hotel,
            $reportData,
            $taskData,
            $monthSum,
            '2026-05-10',
            10,
            31,
        ]);

        self::assertSame('Test Hotel', $detail['hotel_name']);
        self::assertSame(2050.0, $detail['day_revenue']);
        self::assertSame(2000.0, $detail['day_room_revenue']);
        self::assertSame(50.0, $detail['day_other_revenue']);
        self::assertSame(9, $detail['day_total_rooms']);
        self::assertSame(7, $detail['day_overnight_rooms']);
        self::assertSame(1, $detail['day_non_overnight_rooms']);
        self::assertSame(45.0, $detail['day_occ_rate']);
        self::assertSame(222.22, $detail['day_adr']);
        self::assertSame(100.0, $detail['day_revpar']);
        self::assertSame(2, $detail['day_good_review']);
        self::assertSame(1, $detail['day_bad_review']);
        self::assertSame(4, $detail['tomorrow_booking']);
        self::assertSame(30.0, $detail['day_cash_income']);
        self::assertSame(500.0, $detail['day_stored_value']);
        self::assertSame($reportData, $detail['raw_data']);
    }

    public function testDailyDetailBuildsOtaChannelSupplementWithoutReviews(): void
    {
        $controller = $this->controller();
        $hotel = (object)['name' => 'OTA Hotel', 'salable_rooms_total' => 20];
        $reportData = [
            'xb_revenue' => 1000,
            'xb_rooms' => 5,
            'online_revenue' => 1000,
            'online_rooms' => 5,
            'offline_revenue' => 0,
            'offline_rooms' => 0,
            'other_revenue_total' => 0,
            'salable_rooms' => 20,
        ];

        $detail = $this->invokeNonPublic($controller, 'calculateReportDetail', [
            $hotel,
            $reportData,
            [],
            $reportData,
            '2026-05-10',
            10,
            31,
            [
                [
                    'data_type' => 'advertising',
                    'amount' => '120.50',
                    'data_value' => 4.8,
                    'list_exposure' => 1000,
                    'detail_exposure' => 80,
                    'book_order_num' => 4,
                    'quantity' => 5,
                    'raw_data' => json_encode(['orderAmount' => 600, 'campaignId' => 'safe-campaign'], JSON_UNESCAPED_UNICODE),
                ],
                [
                    'data_type' => 'ads',
                    'raw_data' => json_encode(['cost' => 30, 'revenue' => 90, 'impressions' => 200, 'clicks' => 20, 'bookings' => 1], JSON_UNESCAPED_UNICODE),
                ],
                [
                    'data_type' => 'quality',
                    'data_value' => 88.6,
                    'raw_data' => json_encode(['serviceScore' => 92.5, 'psiScore' => 88.6], JSON_UNESCAPED_UNICODE),
                ],
                [
                    'data_type' => 'review',
                    'comment_score' => 1.0,
                    'raw_data' => json_encode(['content' => 'disabled review data'], JSON_UNESCAPED_UNICODE),
                ],
            ],
        ]);

        self::assertSame('ota_channel', $detail['ota_channel_supplement']['scope']);
        self::assertSame('ok', $detail['ota_channel_supplement']['data_status']);
        self::assertSame(150.5, $detail['ota_channel_supplement']['advertising']['spend']);
        self::assertSame(690.0, $detail['ota_channel_supplement']['advertising']['order_amount']);
        self::assertSame(5, $detail['ota_channel_supplement']['advertising']['bookings']);
        self::assertSame(1200, $detail['ota_channel_supplement']['advertising']['impressions']);
        self::assertSame(100, $detail['ota_channel_supplement']['advertising']['clicks']);
        self::assertSame(4.58, $detail['ota_channel_supplement']['advertising']['roas']);
        self::assertSame(1, $detail['ota_channel_supplement']['service_quality']['sample_count']);
        self::assertSame(88.6, $detail['ota_channel_supplement']['service_quality']['avg_psi_score']);
        self::assertSame(92.5, $detail['ota_channel_supplement']['service_quality']['avg_service_score']);
        self::assertArrayNotHasKey('reviews', $detail['ota_channel_supplement']);
        self::assertSame(200.0, $detail['day_adr']);
    }

    public function testOtaSupplementKeepsMissingMetricsNullAndPreservesExplicitZero(): void
    {
        $controller = $this->controller();

        $empty = $this->invokeNonPublic($controller, 'buildDailyOtaSupplementSummary', [[]]);
        self::assertSame('pending', $empty['data_status']);
        self::assertNull($empty['advertising']['spend']);
        self::assertNull($empty['advertising']['order_amount']);
        self::assertNull($empty['service_quality']['avg_psi_score']);
        self::assertNull($empty['service_quality']['avg_service_score']);

        $explicitZero = $this->invokeNonPublic($controller, 'buildDailyOtaSupplementSummary', [[
            [
                'data_type' => 'advertising',
                'amount' => 0,
                'order_amount' => 0,
                'list_exposure' => 0,
                'detail_exposure' => 0,
                'book_order_num' => 0,
                'quantity' => 0,
            ],
            [
                'data_type' => 'quality',
                'data_value' => 0,
                'raw_data' => ['serviceScore' => 0],
            ],
        ]]);

        self::assertSame('ok', $explicitZero['data_status']);
        self::assertSame(0.0, $explicitZero['advertising']['spend']);
        self::assertSame(0.0, $explicitZero['advertising']['order_amount']);
        self::assertSame(0, $explicitZero['advertising']['impressions']);
        self::assertSame(0, $explicitZero['advertising']['clicks']);
        self::assertSame(0, $explicitZero['advertising']['bookings']);
        self::assertSame(0.0, $explicitZero['advertising']['room_nights']);
        self::assertNull($explicitZero['advertising']['ctr']);
        self::assertNull($explicitZero['advertising']['cvr']);
        self::assertNull($explicitZero['advertising']['roas']);
        self::assertSame(0.0, $explicitZero['service_quality']['avg_psi_score']);
        self::assertSame(0.0, $explicitZero['service_quality']['avg_service_score']);

        $partial = $this->invokeNonPublic($controller, 'buildDailyOtaSupplementSummary', [[
            ['data_type' => 'advertising', 'amount' => 10],
        ]]);
        self::assertSame('partial', $partial['data_status']);
        self::assertSame('partial', $partial['advertising']['data_status']);
        self::assertSame(10.0, $partial['advertising']['spend']);
        self::assertNull($partial['advertising']['order_amount']);
        self::assertContains('advertising_order_amount_missing', $partial['advertising']['data_gaps']);
    }

    public function testMissingCoreEvidenceRemainsNullAndOtaRowsDoNotBecomeWholeHotelMetrics(): void
    {
        $controller = $this->controller();
        $hotel = (object)['name' => 'Sparse Hotel'];

        $missing = $this->invokeNonPublic($controller, 'calculateReportDetail', [
            $hotel,
            [],
            [],
            [],
            '2026-05-10',
            10,
            31,
        ]);

        foreach ([
            'total_rooms',
            'salable_rooms',
            'day_revenue',
            'day_room_revenue',
            'day_total_rooms',
            'day_occ_rate',
            'day_adr',
            'day_revpar',
            'month_revenue',
            'month_occ_rate',
            'month_adr',
            'month_revpar',
            'month_revenue_target',
            'month_complete_rate',
            'month_revenue_diff',
            'day_revenue_target',
            'day_revenue_diff',
        ] as $key) {
            self::assertNull($missing[$key], $key . ' must remain missing');
        }
        self::assertSame('missing', $missing['data_status']);
        self::assertFalse($missing['core_metrics_ready']);
        self::assertSame('whole_hotel_daily_report', $missing['metric_scope']);
        self::assertContains('daily_total_revenue_missing', array_column($missing['data_gaps'], 'code'));
        self::assertContains('monthly_revenue_target_missing', array_column($missing['data_gaps'], 'code'));

        $otaOnly = $this->invokeNonPublic($controller, 'calculateReportDetail', [
            (object)['name' => 'OTA Only Hotel', 'salable_rooms_total' => 20],
            ['xb_revenue' => 1000, 'xb_rooms' => 5, 'salable_rooms' => 20],
            [],
            ['xb_revenue' => 1000, 'xb_rooms' => 5, 'salable_rooms' => 20],
            '2026-05-10',
            10,
            31,
        ]);

        self::assertNull($otaOnly['ota_total_rooms']);
        self::assertSame(5, $otaOnly['xb_rooms']);
        self::assertNull($otaOnly['day_revenue']);
        self::assertNull($otaOnly['day_room_revenue']);
        self::assertNull($otaOnly['day_total_rooms']);
        self::assertNull($otaOnly['day_occ_rate']);
        self::assertNull($otaOnly['day_adr']);
        self::assertNull($otaOnly['day_revpar']);
        self::assertStringContainsString('不参与全酒店营收', $otaOnly['data_notice']);
    }

    public function testExplicitZeroIsPreservedWithoutInventingUndefinedRatios(): void
    {
        $controller = $this->controller();
        $reportData = [
            'revenue' => 0,
            'room_revenue' => 0,
            'total_rooms' => 0,
            'salable_rooms' => 20,
            'overnight_rooms' => 0,
            'hourly_rooms' => 0,
        ];

        $detail = $this->invokeNonPublic($controller, 'calculateReportDetail', [
            (object)['name' => 'Zero Hotel', 'salable_rooms_total' => 20],
            $reportData,
            ['revenue_budget' => 0],
            $reportData,
            '2026-05-10',
            10,
            31,
        ]);

        self::assertSame(0.0, $detail['day_revenue']);
        self::assertSame(0.0, $detail['day_room_revenue']);
        self::assertSame(0, $detail['day_total_rooms']);
        self::assertSame(0.0, $detail['day_occ_rate']);
        self::assertSame(0.0, $detail['day_revpar']);
        self::assertNull($detail['day_adr']);
        self::assertSame(0.0, $detail['month_revenue']);
        self::assertSame(0.0, $detail['month_occ_rate']);
        self::assertSame(0.0, $detail['month_revpar']);
        self::assertNull($detail['month_adr']);
        self::assertSame(0.0, $detail['month_revenue_target']);
        self::assertNull($detail['month_complete_rate']);
        self::assertContains('monthly_revenue_target_not_positive', array_column($detail['data_gaps'], 'code'));
    }

    public function testMonthlyTotalsRequireEvidenceFromEveryIncludedDailyReport(): void
    {
        $controller = $this->controller();
        $monthSum = $this->invokeNonPublic($controller, 'calculateMonthSum', [[
            (object)['report_data' => [
                'revenue' => 100,
                'room_revenue' => 100,
                'total_rooms' => 1,
                'salable_rooms' => 10,
            ]],
            (object)['report_data' => [
                'room_revenue' => 100,
                'total_rooms' => 1,
                'salable_rooms' => 10,
            ]],
        ]]);

        $detail = $this->invokeNonPublic($controller, 'calculateReportDetail', [
            (object)['name' => 'Partial Month Hotel', 'salable_rooms_total' => 10],
            [
                'revenue' => 100,
                'room_revenue' => 100,
                'total_rooms' => 1,
                'salable_rooms' => 10,
                'overnight_rooms' => 1,
                'hourly_revenue' => 0,
                'hourly_rooms' => 0,
            ],
            ['revenue_budget' => 1000],
            $monthSum,
            '2026-05-02',
            2,
            31,
        ]);

        self::assertNull($detail['month_revenue']);
        self::assertNull($detail['month_complete_rate']);
        self::assertNull($detail['month_revenue_diff']);
        self::assertSame(100.0, $detail['month_adr']);
        self::assertSame(10.0, $detail['month_occ_rate']);
        self::assertSame(10.0, $detail['month_revpar']);
        self::assertSame('data_gap', $detail['metric_status']['month_revenue']['status']);
    }

    public function testDerivedFieldsProtectImportMappingTotals(): void
    {
        $controller = $this->controller();
        $data = [
            'total_rooms_count' => 30,
            'maintenance_rooms' => 2,
            'overnight_rooms' => 10,
            'hourly_rooms' => 2,
            'room_revenue' => 2400,
            'xb_revenue' => 1000,
            'xb_rooms' => 4,
            'mt_revenue' => 800,
            'mt_rooms' => 3,
            'walkin_revenue' => 500,
            'walkin_rooms' => 2,
            'protocol_revenue' => 100,
            'protocol_rooms' => 1,
        ];
        foreach (['xb', 'mt', 'fliggy', 'tc', 'dy', 'qn', 'zx', 'booking', 'agoda', 'expedia'] as $channel) {
            $data[$channel . '_revenue'] ??= 0;
            $data[$channel . '_rooms'] ??= 0;
        }
        foreach (['walkin', 'member_exp', 'web_exp', 'group', 'protocol', 'wechat', 'free', 'gold_card', 'black_gold', 'hourly'] as $channel) {
            $data[$channel . '_revenue'] ??= 0;
            $data[$channel . '_rooms'] ??= 0;
        }

        $this->invokeNonPublic($controller, 'calculateDerivedFields', [&$data]);

        self::assertSame(28.0, $data['salable_rooms']);
        self::assertSame(12.0, $data['total_rooms']);
        self::assertSame(1800.0, $data['online_revenue']);
        self::assertSame(7.0, $data['online_rooms']);
        self::assertSame(600.0, $data['offline_revenue']);
        self::assertSame(5.0, $data['offline_rooms']);
        self::assertSame(200.0, $data['adr']);
        self::assertSame(7 / 12, $data['ota_room_rate']);
    }

    public function testReportDataNormalizationKeepsSavedEchoShapeReadable(): void
    {
        $controller = $this->controller();
        $data = [
            'xb_revenue' => 1200.5,
            'raw_data' => ['source' => 'import-preview'],
        ];

        $array = $this->invokeNonPublic($controller, 'normalizeReportData', [$data]);
        $json = $this->invokeNonPublic($controller, 'normalizeReportData', [json_encode($data, JSON_UNESCAPED_UNICODE)]);
        $object = $this->invokeNonPublic($controller, 'normalizeReportData', [(object)$data]);

        self::assertSame($data, $array);
        self::assertSame($data, $json);
        self::assertSame($data, $object);
    }

    /**
     * 覆盖 resolveContextValue/evaluateFormula/renderTemplate/calculateViewMappingValues：
     * 验证点路径取值、公式计算、非法公式错误、模板渲染。
     */
    public function testViewMappingFormulaAndTemplateHelpers(): void
    {
        $controller = $this->controller();
        $reportData = ['revenue' => 1000, 'rooms' => 5, 'nested' => ['score' => 4.8]];
        $taskData = ['target' => 2000];
        $monthSum = ['revenue' => 3000];
        $calc = ['day_adr' => 200];
        $context = ['report' => $reportData, 'task' => $taskData, 'month' => $monthSum, 'calc' => $calc];

        self::assertSame(4.8, $this->invokeNonPublic($controller, 'resolveContextValue', [$context, 'report', 'nested.score']));
        self::assertNull($this->invokeNonPublic($controller, 'resolveContextValue', [$context, 'report', 'missing']));

        $error = null;
        self::assertSame(25.0, $this->invokeNonPublic($controller, 'evaluateFormula', ['report.revenue / task.target * 50', $context, &$error]));
        self::assertNull($error);

        $invalidError = null;
        self::assertNull($this->invokeNonPublic($controller, 'evaluateFormula', ['report.revenue + bad_function()', $context, &$invalidError]));
        self::assertIsString($invalidError);

        $missingFormulaError = null;
        self::assertNull($this->invokeNonPublic($controller, 'evaluateFormula', ['report.revenue + report.missing', $context, &$missingFormulaError]));
        self::assertSame('公式缺少字段: report.missing', $missingFormulaError);

        self::assertSame(
            'ADR 200 / score 4.8 / missing —',
            $this->invokeNonPublic($controller, 'renderTemplate', ['ADR {calc.day_adr} / score {report.nested.score} / missing {report.none}', $context])
        );

        $values = $this->invokeNonPublic($controller, 'calculateViewMappingValues', [[
            ['template' => 'Revenue {report.revenue}'],
            ['formula' => 'report.revenue / report.rooms'],
            ['source' => 'calc', 'field' => 'day_adr'],
            ['source' => 'report', 'field' => 'missing'],
        ], $reportData, $taskData, $monthSum, $calc]);

        self::assertSame('Revenue 1000', $values[0]['value']);
        self::assertSame(200.0, $values[1]['value']);
        self::assertSame(200, $values[2]['value']);
        self::assertNull($values[3]['value']);
        self::assertIsString($values[3]['error']);
    }

    /**
     * 覆盖 calculateTotals/fmtNum/fmtPct/formatNumber/escapeHtml：
     * 验证导出合计、线上线下收入拆分、比例和 HTML 转义。
     */
    public function testCalculateTotalsAndFormatters(): void
    {
        $controller = $this->controller();
        $reports = [
            ['data' => [
                'salable_rooms' => 10,
                'online_revenue' => 100,
                'online_rooms' => 2,
                'offline_revenue' => 110,
                'offline_rooms' => 2,
                'other_revenue_total' => 20,
                'room_revenue' => 210,
                'revenue' => 230,
                'total_rooms' => 4,
                'overnight_rooms' => 3,
                'xb_revenue' => 100,
                'xb_rooms' => 2,
                'walkin_revenue' => 80,
                'walkin_rooms' => 1,
                'hourly_revenue' => 30,
                'hourly_rooms' => 1,
                'parking_revenue' => 20,
                'xb_reviewable' => 2,
                'xb_good_review' => 1,
                'mt_reviewable' => 0,
                'mt_good_review' => 0,
                'fliggy_reviewable' => 0,
                'fliggy_good_review' => 0,
                'wechat_add' => 3,
            ]],
            ['data' => [
                'total_rooms_count' => 20,
                'online_revenue' => 200,
                'online_rooms' => 3,
                'offline_revenue' => 100,
                'offline_rooms' => 2,
                'other_revenue_total' => 40,
                'room_revenue' => 300,
                'revenue' => 340,
                'total_rooms' => 5,
                'overnight_rooms' => 5,
                'hourly_revenue' => 0,
                'hourly_rooms' => 0,
                'mt_revenue' => 200,
                'mt_rooms' => 3,
                'protocol_revenue' => 100,
                'protocol_rooms' => 2,
                'dining_revenue' => 40,
                'mt_reviewable' => 4,
                'mt_good_review' => 2,
                'xb_reviewable' => 0,
                'xb_good_review' => 0,
                'fliggy_reviewable' => 0,
                'fliggy_good_review' => 0,
                'wechat_add' => 5,
            ]],
        ];

        $totals = $this->invokeNonPublic($controller, 'calculateTotals', [$reports, 1000.0, 600.0, 400.0]);

        self::assertSame(300.0, $totals['online_revenue']);
        self::assertSame(210.0, $totals['offline_revenue']);
        self::assertSame(60.0, $totals['other_revenue_total']);
        self::assertSame(510.0, $totals['room_revenue']);
        self::assertSame(570.0, $totals['total_revenue']);
        self::assertSame(9.0, $totals['total_rooms']);
        self::assertSame(0.3, $totals['occ_rate']);
        self::assertSame(0.5, $totals['good_review_rate']);
        self::assertSame('ready', $totals['data_status']);

        $missingTotals = $this->invokeNonPublic($controller, 'calculateTotals', [
            [['data' => []]],
            null,
            null,
            null,
        ]);
        self::assertNull($missingTotals['total_revenue']);
        self::assertNull($missingTotals['room_revenue']);
        self::assertNull($missingTotals['total_rooms']);
        self::assertNull($missingTotals['occ_rate']);
        self::assertNull($missingTotals['adr']);
        self::assertNull($missingTotals['revpar']);
        self::assertSame('partial', $missingTotals['data_status']);
        self::assertContains('export_total_revenue_incomplete', $missingTotals['data_gaps']);

        self::assertSame('1,234.50', $this->invokeNonPublic($controller, 'fmtNum', [1234.5, 2]));
        self::assertSame('12.34%', $this->invokeNonPublic($controller, 'fmtPct', [0.1234]));
        self::assertSame('—', $this->invokeNonPublic($controller, 'formatNumber', ['bad']));
        self::assertSame('&lt;tag&gt;&quot;', $this->invokeNonPublic($controller, 'escapeHtml', ['<tag>"']));
    }

    public function testExportLimitAndWatermarkHelpers(): void
    {
        $controller = $this->controller();

        self::assertTrue($this->invokeNonPublic($controller, 'isExportBatchAllowed', [31]));
        self::assertFalse($this->invokeNonPublic($controller, 'isExportBatchAllowed', [32]));

        $watermark = $this->invokeNonPublic($controller, 'formatExportWatermark', [[
            'text' => 'SUXIOS Export Watermark | user=<tester>#7',
        ]]);

        self::assertStringContainsString('SUXIOS Export Watermark', $watermark);
        self::assertStringContainsString('&lt;tester&gt;#7', $watermark);
    }

    public function testDailyImportUploadRejectsUnsafeExcelFiles(): void
    {
        $controller = $this->controller();
        $validPath = $this->createXlsxFixture([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"></workbook>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"></worksheet>',
        ]);
        $oversizedEntryPath = $this->createXlsxFixture([
            '[Content_Types].xml' => '<Types></Types>',
            'xl/workbook.xml' => '<workbook></workbook>',
            'xl/worksheets/sheet1.xml' => str_repeat('A', 8 * 1024 * 1024 + 1),
        ]);
        $invalidPath = tempnam(sys_get_temp_dir(), 'daily_invalid_xlsx_');
        file_put_contents($invalidPath, 'not a zip');

        try {
            self::assertNull($this->invokeNonPublic($controller, 'validateDailyImportUpload', [$validPath, 'daily.xlsx', filesize($validPath)]));
            self::assertSame('仅支持.xlsx格式的日报Excel文件', $this->invokeNonPublic($controller, 'validateDailyImportUpload', [$validPath, 'daily.txt', filesize($validPath)]));
            self::assertSame('Excel文件单个内容项超过8MB', $this->invokeNonPublic($controller, 'validateDailyImportUpload', [$oversizedEntryPath, 'daily.xlsx', filesize($oversizedEntryPath)]));
            self::assertSame('Excel文件结构异常，请上传有效的.xlsx文件', $this->invokeNonPublic($controller, 'validateDailyImportUpload', [$invalidPath, 'daily.xlsx', filesize($invalidPath)]));
        } finally {
            @unlink($validPath);
            @unlink($oversizedEntryPath);
            @unlink($invalidPath);
        }
    }

    /**
     * 覆盖 parseNumber/parsePercent：
     * 验证百分号、普通数字、小数比例和大于 100 的边界值。
     */
    public function testParseNumberAndPercentHelpers(): void
    {
        $controller = $this->controller();

        self::assertSame(0.125, $this->invokeNonPublic($controller, 'parseNumber', ['12.5%']));
        self::assertSame(42.0, $this->invokeNonPublic($controller, 'parseNumber', ['42']));
        self::assertNull($this->invokeNonPublic($controller, 'parseNumber', ['']));
        self::assertNull($this->invokeNonPublic($controller, 'parseNumber', ['not-a-number']));
        self::assertSame(12.5, $this->invokeNonPublic($controller, 'parsePercent', ['12.5%']));
        self::assertSame(25.0, $this->invokeNonPublic($controller, 'parsePercent', ['0.25']));
        self::assertSame(42.0, $this->invokeNonPublic($controller, 'parsePercent', ['42']));
        self::assertSame(125.0, $this->invokeNonPublic($controller, 'parsePercent', ['125']));
        self::assertNull($this->invokeNonPublic($controller, 'parsePercent', ['']));
    }

    /**
     * @param array<string, string> $entries
     */
    private function createXlsxFixture(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'daily_xlsx_');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            self::assertTrue($zip->addFromString($name, $content));
        }
        self::assertTrue($zip->close());

        return $path;
    }
}
