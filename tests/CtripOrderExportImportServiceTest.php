<?php
declare(strict_types=1);

use app\service\CtripOrderExportImportService;
use app\service\OtaStandardEtlService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PHPUnit\Framework\TestCase;

final class CtripOrderExportImportServiceTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporaryFiles = [];
    }

    public function testLegacyXlsFixtureParsesAliasesAggregatesAndExcludesPii(): void
    {
        $path = $this->legacyFixturePath();
        $service = new CtripOrderExportImportService();

        $parsed = $service->parseLegacyXls($path, 'TEST-FIXTURE-携程订单-含张三-13800138000.xls');
        self::assertCount(3, $parsed);
        self::assertSame('O-100', $parsed[0]['订单号']);
        self::assertSame('携程', $parsed[0]['预订网站']);
        self::assertSame('biff_xls', $parsed[0]['_source_format']);
        self::assertSame('ctrip_order_export_25_columns', $parsed[0]['_source_layout']);
        self::assertArrayNotHasKey('_source_file', $parsed[0]);
        foreach (['客人姓名', '酒店确认人', '预订号', '备注', '确认备注', '携程提示', '_source_sheet', '_source_row'] as $excludedKey) {
            self::assertArrayNotHasKey($excludedKey, $parsed[0]);
        }

        $rows = $service->normalizeRows($parsed, [
            'system_hotel_id' => 80,
            'hotel_name' => '测试酒店（fixture）',
            'test_fixture' => true,
        ]);
        self::assertCount(2, $rows);

        $byChannel = [];
        foreach ($rows as $row) {
            $byChannel[$row['source']] = $row;
        }
        $ctrip = $byChannel['ctrip'];
        self::assertSame('system:80', $ctrip['hotel_id']);
        self::assertSame('2026-08-08', $ctrip['data_date']);
        self::assertSame(2, $ctrip['book_order_num']);
        self::assertSame(2, $ctrip['gross_order_num']);
        self::assertSame(0, $ctrip['cancel_order_num']);
        self::assertSame(4.0, $ctrip['quantity']);
        self::assertNull($ctrip['amount']);
        self::assertSame(900.0, $ctrip['raw_data']['bottom_price_sum']);
        self::assertSame(225.0, $ctrip['bottom_price_adr']);
        self::assertSame(5.0, $ctrip['avg_lead_days']);
        self::assertLessThanOrEqual(64, strlen($ctrip['source_trace_id']));
        self::assertSame('reference_bottom_price_not_confirmed_revenue', $ctrip['raw_data']['amount_semantics']);
        self::assertSame('explicit_test_fixture', $ctrip['raw_data']['fixture_status']);
        self::assertSame(1, $ctrip['raw_data']['source_file_count']);
        self::assertSame('biff_xls', $ctrip['raw_data']['source_format']);
        self::assertSame(['biff_xls'], $ctrip['raw_data']['source_formats']);
        self::assertSame('ctrip_order_export_25_columns', $ctrip['raw_data']['source_layout']);
        self::assertSame('verified_25_column_layout', $ctrip['raw_data']['file_layout_acceptance']);
        self::assertEqualsWithDelta(1.0, (float)$ctrip['raw_data']['bottom_price_coverage_rate'], 0.000001);
        self::assertSame('complete', $ctrip['raw_data']['bottom_price_completeness']);

        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([array_replace($ctrip, [
            'id' => 9001,
            'readback_verified' => 1,
            'update_time' => '2026-08-08 12:00:00',
        ])]);
        self::assertCount(1, $dataset['fact_ota_daily']);
        $fact = $dataset['fact_ota_daily'][0];
        self::assertNull($fact['revenue']);
        self::assertNull($fact['gross_revenue']);
        self::assertNull($fact['room_revenue']);

        $qunar = $byChannel['qunar'];
        self::assertSame(0, $qunar['book_order_num']);
        self::assertSame(1, $qunar['gross_order_num']);
        self::assertSame(1, $qunar['cancel_order_num']);
        self::assertEqualsWithDelta(1.0, (float)$qunar['cancel_rate'], 0.000001);
        self::assertSame(0.0, $qunar['quantity']);
        self::assertNull($qunar['bottom_price_adr']);

        $storedJson = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach ([
            'O-100', 'O-101', 'O-102',
            'PII_GUEST_SENTINEL_A', 'PII_GUEST_SENTINEL_B', 'PII_GUEST_SENTINEL_C',
            'PII_STAFF_SENTINEL_A', 'PII_RESERVATION_SENTINEL_A',
            'PII_REMARK_SENTINEL_A', 'PII_CONFIRM_REMARK_SENTINEL_A', 'PII_CTRIP_TIP_SENTINEL_A',
            'TEST-FIXTURE-携程订单-含张三',
        ] as $privateText) {
            self::assertStringNotContainsString($privateText, $storedJson);
        }
        self::assertStringContainsString('aggregate_only_no_guest_staff_reservation_notes', $storedJson);
    }

    public function testHtmlTableWithXlsExtensionUsesAllowedReaderAndPreservesSafeFormatMetadata(): void
    {
        $path = $this->htmlFixturePath();
        $service = new CtripOrderExportImportService();

        $parsed = $service->parseLegacyXls($path, 'PRIVATE-携程订单-张三-13800138000.xls');
        self::assertCount(3, $parsed);
        self::assertSame('html_table_xls', $parsed[0]['_source_format']);
        self::assertSame('recognized_legacy_order_layout', $parsed[0]['_source_layout']);
        self::assertArrayNotHasKey('_source_file', $parsed[0]);

        $rows = $service->normalizeRows($parsed, [
            'system_hotel_id' => 80,
            'hotel_name' => '测试酒店（fixture）',
            'test_fixture' => true,
        ]);
        self::assertCount(2, $rows);

        $byChannel = [];
        foreach ($rows as $row) {
            $byChannel[$row['source']] = $row;
        }
        self::assertSame(2, $byChannel['ctrip']['book_order_num']);
        self::assertSame(4.0, $byChannel['ctrip']['quantity']);
        self::assertNull($byChannel['ctrip']['amount']);
        self::assertSame(900.0, $byChannel['ctrip']['raw_data']['bottom_price_sum']);
        self::assertSame('reference_bottom_price_not_confirmed_revenue', $byChannel['ctrip']['raw_data']['amount_semantics']);
        self::assertSame('html_table_xls', $byChannel['ctrip']['raw_data']['source_format']);
        self::assertSame(['html_table_xls'], $byChannel['ctrip']['raw_data']['source_formats']);
        self::assertSame('recognized_legacy_order_layout', $byChannel['ctrip']['raw_data']['source_layout']);
        self::assertSame(1, $byChannel['qunar']['cancel_order_num']);
        self::assertSame(0.0, $byChannel['qunar']['quantity']);

        $returnedJson = json_encode([$parsed, $rows], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PRIVATE-携程订单-张三-13800138000.xls', $returnedJson);
    }

    public function testUnsupportedAndMalformedXlsContentFailsClosedWithoutPathOrParserDetails(): void
    {
        $service = new CtripOrderExportImportService();
        $fixtures = [
            'unsupported' => "not a spreadsheet\x00\x01",
            'malformed-html' => '<html><body><table><tr><td>订单号</table></body></html>',
        ];

        foreach ($fixtures as $label => $contents) {
            $path = $this->temporaryXlsPath('ctrip-secret-' . $label . '-');
            file_put_contents($path, $contents);

            try {
                $service->parseLegacyXls($path, 'PRIVATE-张三-13800138000.xls');
                self::fail('Invalid fixture must fail closed: ' . $label);
            } catch (RuntimeException $exception) {
                self::assertSame(422, $exception->getCode());
                self::assertStringNotContainsString($path, $exception->getMessage());
                self::assertStringNotContainsString('PRIVATE-张三-13800138000.xls', $exception->getMessage());
                self::assertStringNotContainsString('Unable to identify', $exception->getMessage());
                self::assertStringNotContainsString('DOM Document', $exception->getMessage());
            }
        }
    }

    public function testCtripLikeRowsFailClosedWhenRequiredHeadersAreMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('入住日期');

        (new CtripOrderExportImportService())->normalizeRows([
            ['订单号' => 'O-1', '订单状态' => '已确认'],
        ], ['system_hotel_id' => 80]);
    }

    public function testCanonicalNonCtripRowsPassThrough(): void
    {
        $rows = [[
            'data_date' => '2026-08-08',
            'source' => 'ctrip',
            'data_type' => 'order',
            'book_order_num' => 2,
        ]];

        self::assertSame($rows, (new CtripOrderExportImportService())->normalizeRows($rows, [
            'system_hotel_id' => 80,
        ]));
    }

    public function testRealHotelAliasMatchesSelectedSystemHotelAndWrongHotelFailsClosed(): void
    {
        $row = [
            '城市' => '桂林',
            '酒店名称' => '漓江望月•Quiet Holiday 湖畔酒店(桂林两江四湖象鼻山景区店)',
            '订单号' => 'ANON-HOTEL-SCOPE-1',
            '订单类型' => '新订',
            '订单状态' => '已接单',
            '入住日期' => '2026-08-08',
            '离店日期' => '2026-08-09',
            '预订时间' => '2026-08-01 10:00:00',
            '通知时间' => '2026-08-01 10:05:00',
            '晚数' => '1',
            '房间数' => '1',
            '底价' => '300',
            '预订网站' => '携程',
            '_source_format' => 'biff_xls',
            '_source_layout' => 'ctrip_order_export_25_columns',
        ];
        $service = new CtripOrderExportImportService();

        $rows = $service->normalizeRows([$row], [
            'system_hotel_id' => 64,
            'hotel_name' => '桂林漓江望月',
        ]);
        self::assertCount(1, $rows);
        self::assertSame('桂林漓江望月', $rows[0]['hotel_name']);
        self::assertSame('matched_to_selected_system_hotel', $rows[0]['raw_data']['hotel_identity_status']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage('酒店与所选酒店不一致');
        $service->normalizeRows([$row], [
            'system_hotel_id' => 80,
            'hotel_name' => '敦煌漠蓝新',
        ]);
    }

    public function testGenericHotelFragmentCannotAuthorizeAnotherHotel(): void
    {
        $row = [
            '城市' => '桂林',
            '酒店名称' => '漓江望月•Quiet Holiday 湖畔酒店(桂林两江四湖象鼻山景区店)',
            '订单号' => 'ANON-HOTEL-SCOPE-GENERIC',
            '订单类型' => '新订',
            '订单状态' => '已接单',
            '入住日期' => '2026-08-08',
            '离店日期' => '2026-08-09',
            '预订时间' => '2026-08-01 10:00:00',
            '通知时间' => '2026-08-01 10:05:00',
            '晚数' => '1',
            '房间数' => '1',
            '底价' => '300',
            '预订网站' => '携程',
            '_source_format' => 'biff_xls',
            '_source_layout' => 'ctrip_order_export_25_columns',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage('酒店与所选酒店不一致');
        (new CtripOrderExportImportService())->normalizeRows([$row], [
            'system_hotel_id' => 65,
            'hotel_name' => '桂林湖畔酒店',
        ]);
    }

    public function testPartialBottomPriceCoverageAndUnknownStatusStayExplicitAcrossBatch(): void
    {
        $base = [
            '城市' => '桂林',
            '酒店名称' => '匿名酒店（测试fixture）',
            '订单类型' => '新订',
            '入住日期' => '2026-08-08',
            '离店日期' => '2026-08-09',
            '预订时间' => '2026-08-01 10:00:00',
            '通知时间' => '2026-08-01 10:05:00',
            '晚数' => '1',
            '房间数' => '1',
            '预订网站' => '携程',
            '_source_format' => 'biff_xls',
            '_source_layout' => 'ctrip_order_export_25_columns',
        ];
        $rows = [
            array_replace($base, ['订单号' => 'ANON-COVERAGE-1', '订单状态' => '已入住', '底价' => '1,234.50', '_source_file_index' => 1]),
            array_replace($base, ['订单号' => 'ANON-COVERAGE-2', '订单状态' => '已接单', '底价' => '', '_source_file_index' => 2]),
            array_replace($base, ['订单号' => 'ANON-COVERAGE-3', '订单状态' => '待人工确认的新状态', '底价' => '500', '_source_file_index' => 2]),
        ];

        $normalized = (new CtripOrderExportImportService())->normalizeRows($rows, [
            'system_hotel_id' => 64,
            'hotel_name' => '匿名酒店（测试fixture）',
            'test_fixture' => true,
        ]);
        self::assertCount(1, $normalized);
        $item = $normalized[0];
        self::assertSame(2, $item['book_order_num']);
        self::assertSame(3, $item['gross_order_num']);
        self::assertSame(1, $item['unknown_status_order_num']);
        self::assertNull($item['cancel_rate']);
        self::assertNull($item['amount']);
        self::assertSame(1234.5, $item['raw_data']['bottom_price_sum']);
        self::assertSame(1234.5, $item['bottom_price_adr']);
        self::assertSame(0.5, $item['raw_data']['bottom_price_coverage_rate']);
        self::assertSame('partial', $item['raw_data']['bottom_price_completeness']);
        self::assertSame(1, $item['raw_data']['bottom_price_missing_order_count']);
        self::assertSame(2, $item['raw_data']['source_file_count']);
        self::assertSame('unavailable_unknown_status_orders_present', $item['raw_data']['cancel_rate_basis']);
    }

    public function testV2PersistsDatasetClassificationDistributionsAndDoesNotGuessExclusions(): void
    {
        $base = [
            '城市' => '桂林',
            '酒店名称' => '匿名酒店（测试fixture）',
            '订单类型' => '新订',
            '入住日期' => '2026-08-08',
            '离店日期' => '2026-08-09',
            '预订时间' => '2026-08-08 09:00:00',
            '通知时间' => '2026-08-08 10:00:00',
            '晚数' => '1',
            '房间数' => '1',
            '底价' => '100',
            '房型名称' => '江景房',
            '预订网站' => '携程',
            '_source_format' => 'biff_xls',
            '_source_layout' => 'ctrip_order_export_25_columns',
            '_source_file_index' => 1,
        ];
        $rows = [
            array_replace($base, ['订单号' => 'ANON-V2-1', '订单状态' => '已接单', '通知时间' => '2026-08-08 09:30:00']),
            array_replace($base, ['订单号' => 'ANON-V2-1', '订单状态' => '已入住']),
            array_replace($base, [
                '订单号' => 'ANON-V2-2', '订单状态' => '部分入住', '晚数' => '2',
                '离店日期' => '2026-08-10', '预订时间' => '2026-08-06 09:00:00',
                '底价' => '200', '_source_file_index' => 2,
            ]),
            array_replace($base, [
                '订单号' => 'ANON-V2-3', '订单状态' => '已确认', '晚数' => '3',
                '离店日期' => '2026-08-11', '预订时间' => '2026-08-09 09:00:00',
                '房型名称' => '双床房', '底价' => '300', '_source_file_index' => 2,
            ]),
            array_replace($base, ['订单号' => 'ANON-V2-4', '订单状态' => '已取消']),
            array_replace($base, ['订单号' => 'ANON-V2-5', '订单状态' => '待人工确认的新状态']),
            array_replace($base, [
                '订单号' => 'ANON-V2-6', '订单状态' => '已接单',
                '入住日期' => '', '离店日期' => '', '预订时间' => '',
            ]),
            array_replace($base, ['订单号' => '', '订单状态' => '已入住']),
        ];

        $normalized = (new CtripOrderExportImportService())->normalizeRows($rows, [
            'system_hotel_id' => 64,
            'hotel_name' => '匿名酒店（测试fixture）',
            'test_fixture' => true,
        ]);

        self::assertCount(1, $normalized);
        $item = $normalized[0];
        $detail = $item['raw_data'];
        self::assertSame('ctrip_order_aggregate_v2', $detail['import_contract']);
        self::assertSame('channel_daily_aggregate', $detail['record_kind']);
        self::assertSame(5, $item['gross_order_num']);
        self::assertSame(3, $item['book_order_num']);
        self::assertSame(1, $item['cancel_order_num']);
        self::assertSame(1, $item['unknown_status_order_num']);
        self::assertSame(1, $detail['classification_receipt']['stayed_order_num']);
        self::assertSame(2, $detail['classification_receipt']['active_not_stayed_order_num']);
        self::assertSame(1, $detail['classification_receipt']['status_family_counts']['active_stayed']);
        self::assertSame(1, $detail['classification_receipt']['status_family_counts']['active_partial_stay']);
        self::assertSame(1, $detail['classification_receipt']['status_family_counts']['active_confirmed']);
        self::assertSame(1, $detail['classification_receipt']['status_family_counts']['cancelled']);
        self::assertSame(1, $detail['classification_receipt']['status_family_counts']['unknown']);

        $los = array_column($detail['los_distribution']['buckets'], 'orders', 'key');
        self::assertSame(1, $los['one_night']);
        self::assertSame(1, $los['two_nights']);
        self::assertSame(1, $los['three_to_four_nights']);
        self::assertSame(3, $detail['los_distribution']['valid_order_count']);
        self::assertSame(2.0, $detail['average_los']);

        $lead = array_column($detail['lead_time_distribution']['buckets'], 'orders', 'key');
        self::assertSame(1, $lead['same_day']);
        self::assertSame(1, $lead['one_to_three_days']);
        self::assertSame(2, $detail['lead_time_distribution']['valid_order_count']);
        self::assertSame(1, $detail['lead_time_distribution']['invalid_negative_order_count']);
        self::assertSame(1.0, $detail['average_booking_lead_days']);

        self::assertSame('unverified_not_applied', $detail['exclusion_receipt']['status']);
        self::assertSame(0, $detail['exclusion_receipt']['excluded_order_count']);
        self::assertSame([], $detail['exclusion_receipt']['reason_counts']);
        self::assertSame(8, $detail['dataset_receipt']['raw_row_count']);
        self::assertSame(6, $detail['dataset_receipt']['distinct_order_count']);
        self::assertSame(1, $detail['dataset_receipt']['duplicate_version_count']);
        self::assertSame(1, $detail['dataset_receipt']['missing_order_id_count']);
        self::assertSame(1, $detail['dataset_receipt']['missing_business_date_count']);
        self::assertSame(2, $detail['dataset_receipt']['source_file_count']);
        self::assertSame(64, strlen($detail['dataset_receipt']['dataset_hash']));
        self::assertFalse($detail['room_type_metrics_truncated']);
        self::assertSame('江景房', $detail['room_type_metrics'][0]['name']);
        self::assertSame(2, $detail['room_type_metrics'][0]['active_orders']);
        self::assertNull($item['amount']);
        self::assertSame('reference_bottom_price_not_confirmed_revenue', $detail['amount_semantics']);

        $storedJson = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['ANON-V2-1', 'ANON-V2-2', 'ANON-V2-3', 'ANON-V2-4', 'ANON-V2-5', 'ANON-V2-6'] as $orderId) {
            self::assertStringNotContainsString($orderId, $storedJson);
        }
    }

    private function legacyFixturePath(): string
    {
        $xlsPath = $this->temporaryXlsPath('ctrip-order-fixture-');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('1339783226820260808211005');
        $matrix = [[
            '城市', '酒店名称', '订单号', '订单类型', '订单状态', '房型ID', '房型名称', '客人姓名',
            '入住日期', '离店日期', '晚数', '预订时间', '通知时间', '房间数', '币种', '底价',
            '卖价', '促销', '确认类型', '酒店确认人', '预订号', '备注', '确认备注', '携程提示', '预订网站',
        ], [
            '测试市', '测试酒店（fixture）', 'O-100', '新订', '已确认', 'R-1', '江景大床房', 'PII_GUEST_SENTINEL_A',
            '2026-08-08', '2026-08-10', '2', '2026-08-01 09:00:00', '2026-08-01 10:00:00', '1', 'CNY', '400',
            '520', '', '自动确认', 'PII_STAFF_SENTINEL_A', 'PII_RESERVATION_SENTINEL_A', 'PII_REMARK_SENTINEL_A', 'PII_CONFIRM_REMARK_SENTINEL_A', 'PII_CTRIP_TIP_SENTINEL_A', '携程',
        ], [
            '测试市', '测试酒店（fixture）', 'O-101', '新订', '已入住', 'R-2', '双床房', 'PII_GUEST_SENTINEL_B',
            '2026-08-08', '2026-08-09', '1', '2026-08-05 09:00:00', '2026-08-05 10:00:00', '2', 'CNY', '500',
            '620', '', '自动确认', 'PII_STAFF_SENTINEL_B', 'PII_RESERVATION_SENTINEL_B', 'PII_REMARK_SENTINEL_B', 'PII_CONFIRM_REMARK_SENTINEL_B', 'PII_CTRIP_TIP_SENTINEL_B', '携程',
        ], [
            '测试市', '测试酒店（fixture）', 'O-102', '无效', '已取消', 'R-3', '大床房', 'PII_GUEST_SENTINEL_C',
            '2026-08-08', '2026-08-09', '1', '2026-08-06 09:00:00', '2026-08-07 10:00:00', '1', 'CNY', '200',
            '260', '', '自动确认', 'PII_STAFF_SENTINEL_C', 'PII_RESERVATION_SENTINEL_C', 'PII_REMARK_SENTINEL_C', 'PII_CONFIRM_REMARK_SENTINEL_C', 'PII_CTRIP_TIP_SENTINEL_C', '去哪儿',
        ]];
        foreach ($matrix as $rowOffset => $row) {
            foreach ($row as $columnOffset => $value) {
                $sheet->setCellValueExplicit(
                    [$columnOffset + 1, $rowOffset + 1],
                    (string)$value,
                    DataType::TYPE_STRING
                );
            }
        }

        (new Xls($spreadsheet))->save($xlsPath);
        $spreadsheet->disconnectWorksheets();
        return $xlsPath;
    }

    private function htmlFixturePath(): string
    {
        $xlsPath = $this->temporaryXlsPath('ctrip-html-fixture-');
        $html = <<<'HTML'
<!doctype html>
<html lang="zh-CN">
<head><meta charset="UTF-8"><title>携程订单测试 fixture</title></head>
<body>
<table>
<tr><td colspan="16">携程 HTML 订单测试 fixture</td></tr>
<tr>
<th>订单编号</th><th>订单状态名称</th><th>入住时间</th><th>离店时间</th><th>预订日期</th><th>最后更新时间</th>
<th>间夜数</th><th>房间数量</th><th>底价总额</th><th>售卖价</th><th>房型</th><th>预订渠道</th><th>门店名称</th>
<th>住客姓名</th><th>联系电话</th><th>订单类型</th>
</tr>
<tr><td>O-100</td><td>已确认</td><td>2026-08-08</td><td>2026-08-10</td><td>2026-08-01 09:00:00</td><td>2026-08-01 10:00:00</td><td>2</td><td>1</td><td>400</td><td>520</td><td>江景大床房</td><td>携程</td><td>测试酒店（fixture）</td><td>张三</td><td>13800138000</td><td>正常</td></tr>
<tr><td>O-101</td><td>已入住</td><td>2026-08-08</td><td>2026-08-09</td><td>2026-08-05 09:00:00</td><td>2026-08-05 10:00:00</td><td>1</td><td>2</td><td>500</td><td>620</td><td>双床房</td><td>携程</td><td>测试酒店（fixture）</td><td>李四</td><td>13900139000</td><td>正常</td></tr>
<tr><td>O-102</td><td>已取消</td><td>2026-08-08</td><td>2026-08-09</td><td>2026-08-06 09:00:00</td><td>2026-08-07 10:00:00</td><td>1</td><td>1</td><td>200</td><td>260</td><td>大床房</td><td>去哪儿</td><td>测试酒店（fixture）</td><td>王五</td><td>13700137000</td><td>正常</td></tr>
</table>
</body>
</html>
HTML;
        file_put_contents($xlsPath, $html);
        return $xlsPath;
    }

    private function temporaryXlsPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            self::fail('Unable to create temporary fixture path.');
        }
        $xlsPath = $path . '.xls';
        rename($path, $xlsPath);
        $this->temporaryFiles[] = $xlsPath;
        return $xlsPath;
    }
}
