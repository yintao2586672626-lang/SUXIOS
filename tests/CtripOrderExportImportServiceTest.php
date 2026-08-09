<?php
declare(strict_types=1);

use app\service\CtripOrderExportImportService;
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
        $ctrip = $byChannel['ctrip'];
        self::assertSame('system:80', $ctrip['hotel_id']);
        self::assertSame('2026-08-08', $ctrip['data_date']);
        self::assertSame(2, $ctrip['book_order_num']);
        self::assertSame(2, $ctrip['gross_order_num']);
        self::assertSame(0, $ctrip['cancel_order_num']);
        self::assertSame(4.0, $ctrip['quantity']);
        self::assertSame(900.0, $ctrip['amount']);
        self::assertSame(225.0, $ctrip['bottom_price_adr']);
        self::assertSame(5.0, $ctrip['avg_lead_days']);
        self::assertLessThanOrEqual(64, strlen($ctrip['source_trace_id']));
        self::assertSame('reference_bottom_price_not_confirmed_revenue', $ctrip['raw_data']['amount_semantics']);
        self::assertSame('explicit_test_fixture', $ctrip['raw_data']['fixture_status']);
        self::assertSame(1, $ctrip['raw_data']['source_file_count']);
        self::assertSame('biff_xls', $ctrip['raw_data']['source_format']);
        self::assertSame(['biff_xls'], $ctrip['raw_data']['source_formats']);

        $qunar = $byChannel['qunar'];
        self::assertSame(0, $qunar['book_order_num']);
        self::assertSame(1, $qunar['gross_order_num']);
        self::assertSame(1, $qunar['cancel_order_num']);
        self::assertEqualsWithDelta(1.0, (float)$qunar['cancel_rate'], 0.000001);
        self::assertSame(0.0, $qunar['quantity']);
        self::assertNull($qunar['bottom_price_adr']);

        $storedJson = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['O-100', 'O-101', 'O-102', '张三', '李四', '王五', '13800138000', '13900139000', '13700137000', 'TEST-FIXTURE-携程订单-含张三'] as $privateText) {
            self::assertStringNotContainsString($privateText, $storedJson);
        }
        self::assertStringContainsString('guest_name_and_raw_order_id_excluded', $storedJson);
    }

    public function testHtmlTableWithXlsExtensionUsesAllowedReaderAndPreservesSafeFormatMetadata(): void
    {
        $path = $this->htmlFixturePath();
        $service = new CtripOrderExportImportService();

        $parsed = $service->parseLegacyXls($path, 'PRIVATE-携程订单-张三-13800138000.xls');
        self::assertCount(3, $parsed);
        self::assertSame('html_table_xls', $parsed[0]['_source_format']);
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
        self::assertSame(900.0, $byChannel['ctrip']['amount']);
        self::assertSame('reference_bottom_price_not_confirmed_revenue', $byChannel['ctrip']['raw_data']['amount_semantics']);
        self::assertSame('html_table_xls', $byChannel['ctrip']['raw_data']['source_format']);
        self::assertSame(['html_table_xls'], $byChannel['ctrip']['raw_data']['source_formats']);
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

    private function legacyFixturePath(): string
    {
        $xlsPath = $this->temporaryXlsPath('ctrip-order-fixture-');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('携程订单-测试fixture');
        $sheet->fromArray([['携程旧版订单测试 fixture']], null, 'A1');
        $sheet->fromArray([[
            '订单编号', '订单状态名称', '入住时间', '离店时间', '预订日期', '最后更新时间',
            '间夜数', '房间数量', '底价总额', '售卖价', '房型', '预订渠道', '门店名称',
            '住客姓名', '联系电话', '订单类型',
        ]], null, 'A3');
        $sheet->fromArray([
            ['O-100', '已确认', '2026-08-08', '2026-08-10', '2026-08-01 09:00:00', '2026-08-01 10:00:00', 2, 1, 400, 520, '江景大床房', '携程', '测试酒店（fixture）', '张三', '13800138000', '正常'],
            ['O-101', '已入住', '2026-08-08', '2026-08-09', '2026-08-05 09:00:00', '2026-08-05 10:00:00', 1, 2, 500, 620, '双床房', '携程', '测试酒店（fixture）', '李四', '13900139000', '正常'],
            ['O-102', '已取消', '2026-08-08', '2026-08-09', '2026-08-06 09:00:00', '2026-08-07 10:00:00', 1, 1, 200, 260, '大床房', '去哪儿', '测试酒店（fixture）', '王五', '13700137000', '正常'],
        ], null, 'A4');

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
