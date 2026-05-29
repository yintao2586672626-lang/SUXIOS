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
        self::assertSame(0.0, $this->invokeNonPublic($controller, 'resolveReportSalableRooms', [[]]));
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
    }

    public function testLegacyJsonReportDataFeedsMonthSumAndExportTotals(): void
    {
        $controller = $this->controller();
        $reports = [
            (object)['report_data' => json_encode([
                'xb_revenue' => '1,200',
                'xb_rooms' => 3,
                'salable_rooms' => 10,
            ], JSON_UNESCAPED_UNICODE)],
            (object)['report_data' => (object)[
                'mt_revenue' => 800,
                'mt_rooms' => '2',
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
            'xb_rooms' => 5,
            'mt_rooms' => 2,
            'walkin_rooms' => 1,
            'hourly_rooms' => 1,
            'salable_rooms' => 20,
            'overnight_rooms' => 7,
            'member_card_sold' => 2,
            'wechat_add' => 3,
            'private_revenue' => 100,
            'private_rooms' => 1,
            'stored_value' => 500,
            'xb_good_review' => 2,
            'mt_bad_review' => 1,
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

        $this->invokeNonPublic($controller, 'calculateDerivedFields', [&$data]);

        self::assertSame(28, $data['salable_rooms']);
        self::assertSame(12, $data['total_rooms']);
        self::assertSame(1800, $data['online_revenue']);
        self::assertSame(7, $data['online_rooms']);
        self::assertSame(600, $data['offline_revenue']);
        self::assertSame(3, $data['offline_rooms']);
        self::assertSame(200, $data['adr']);
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

        self::assertSame(
            'ADR 200 / score 4.8 / missing 0',
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
                'xb_revenue' => 100,
                'xb_rooms' => 2,
                'walkin_revenue' => 80,
                'walkin_rooms' => 1,
                'hourly_revenue' => 30,
                'hourly_rooms' => 1,
                'parking_revenue' => 20,
                'xb_reviewable' => 2,
                'xb_good_review' => 1,
                'wechat_add' => 3,
            ]],
            ['data' => [
                'total_rooms_count' => 20,
                'mt_revenue' => 200,
                'mt_rooms' => 3,
                'protocol_revenue' => 100,
                'protocol_rooms' => 2,
                'dining_revenue' => 40,
                'mt_reviewable' => 4,
                'mt_good_review' => 2,
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

        self::assertSame('1,234.50', $this->invokeNonPublic($controller, 'fmtNum', [1234.5, 2]));
        self::assertSame('12.34%', $this->invokeNonPublic($controller, 'fmtPct', [0.1234]));
        self::assertSame('0', $this->invokeNonPublic($controller, 'formatNumber', ['bad']));
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

    /**
     * 覆盖 parseNumber/parsePercent：
     * 验证百分号、普通数字、小数比例和大于 100 的边界值。
     */
    public function testParseNumberAndPercentHelpers(): void
    {
        $controller = $this->controller();

        self::assertSame(0.125, $this->invokeNonPublic($controller, 'parseNumber', ['12.5%']));
        self::assertSame(42.0, $this->invokeNonPublic($controller, 'parseNumber', ['42']));
        self::assertSame(12.5, $this->invokeNonPublic($controller, 'parsePercent', ['12.5%']));
        self::assertSame(25.0, $this->invokeNonPublic($controller, 'parsePercent', ['0.25']));
        self::assertSame(125.0, $this->invokeNonPublic($controller, 'parsePercent', ['125']));
    }
}
