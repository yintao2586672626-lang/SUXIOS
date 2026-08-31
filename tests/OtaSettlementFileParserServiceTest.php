<?php
declare(strict_types=1);

use app\service\OtaSettlementFileParserService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class OtaSettlementFileParserServiceTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->files = [];
    }

    public function testChineseCanonicalCsvMapsDirectFactsWithoutInventingMissingAdjustments(): void
    {
        $file = $this->temp('csv');
        file_put_contents($file, implode("\n", [
            '行号,业务日期,平台订单号,PMS住宿号,成交总额,佣金金额,结算金额,匹配状态',
            '1,2026-08-10,OTA-1,PMS-1,1000,150,850,matched',
        ]));

        $result = (new OtaSettlementFileParserService())->parse($file, 'settlement.csv');

        self::assertSame('ota_settlement_file_parser.v1', $result['contract_version']);
        self::assertSame('canonical_settlement_csv.v1', $result['parser_version']);
        self::assertSame(hash_file('sha256', $file), $result['file_sha256']);
        self::assertSame(1, $result['row_count']);
        self::assertSame('settlement', $result['lines'][0]['amount_scope']);
        self::assertSame('OTA-1', $result['lines'][0]['ota_order_ref']);
        self::assertSame(1000.0, $result['lines'][0]['gross_amount']);
        self::assertSame('source_direct', $result['lines'][0]['gross_amount_basis']);
        self::assertSame('source_direct', $result['lines'][0]['commission_amount_basis']);
        self::assertArrayNotHasKey('refund_amount', $result['lines'][0]);
        self::assertArrayNotHasKey('refund_amount_basis', $result['lines'][0]);
    }

    public function testUnknownPiiColumnsAreNotReturnedAsSettlementFacts(): void
    {
        $file = $this->temp('csv');
        file_put_contents($file, implode("\n", [
            '业务日期,平台订单号,客人姓名,手机号,身份证号,结算金额',
            '2026-08-10,OTA-PII-1,测试客人,13800000000,110101199001010000,850',
        ]));

        $result = (new OtaSettlementFileParserService())->parse($file, 'settlement.csv');
        $serialized = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('测试客人', $serialized);
        self::assertStringNotContainsString('13800000000', $serialized);
        self::assertStringNotContainsString('110101199001010000', $serialized);
        self::assertArrayNotHasKey('客人姓名', $result['lines'][0]);
        self::assertArrayNotHasKey('手机号', $result['lines'][0]);
        self::assertArrayNotHasKey('身份证号', $result['lines'][0]);
        self::assertSame('OTA-PII-1', $result['lines'][0]['ota_order_ref']);
    }

    public function testCanonicalXlsxUsesExistingBoundedSpreadsheetParser(): void
    {
        $file = $this->temp('xlsx');
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([
            ['source_line_no', 'business_date', 'amount_scope', 'ota_order_ref', 'gross_amount', 'commission_amount', 'net_revenue', 'match_status'],
            [1, '2026-08-11', 'settlement', 'OTA-XLSX-1', 500, 75, 425, 'not_evaluated'],
        ]);
        (new Xlsx($book))->save($file);
        $book->disconnectWorksheets();

        $result = (new OtaSettlementFileParserService())->parse($file, 'settlement.xlsx');

        self::assertSame('canonical_settlement_xlsx.v1', $result['parser_version']);
        self::assertSame(1, $result['row_count']);
        self::assertSame(425.0, $result['lines'][0]['net_revenue']);
        self::assertSame('source_direct', $result['lines'][0]['net_revenue_basis']);
        self::assertSame('not_evaluated', $result['lines'][0]['match_status']);
    }

    public function testCanonicalXlsxPreservesSparseColumnPositions(): void
    {
        $file = $this->temp('xlsx');
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', '业务日期');
        $sheet->setCellValue('B1', '佣金金额');
        $sheet->setCellValue('C1', '结算金额');
        $sheet->setCellValue('A2', '2026-08-12');
        $sheet->setCellValue('C2', 800);
        (new Xlsx($book))->save($file);
        $book->disconnectWorksheets();

        $result = (new OtaSettlementFileParserService())->parse($file, 'sparse-settlement.xlsx');

        self::assertSame(1, $result['row_count']);
        self::assertArrayNotHasKey('commission_amount', $result['lines'][0]);
        self::assertSame(800.0, $result['lines'][0]['settlement_amount']);
        self::assertSame('source_direct', $result['lines'][0]['settlement_amount_basis']);
    }

    public function testCanonicalXlsxConvertsExcelDateSerialOnlyForBusinessDate(): void
    {
        $file = $this->temp('xlsx');
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', '业务日期');
        $sheet->setCellValue('B1', '结算金额');
        $sheet->setCellValue('A2', SpreadsheetDate::dateTimeToExcel(
            new \DateTimeImmutable('2026-08-01 00:00:00')
        ));
        $sheet->getStyle('A2')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->setCellValue('B2', 800);
        (new Xlsx($book))->save($file);
        $book->disconnectWorksheets();

        $result = (new OtaSettlementFileParserService())->parse($file, 'dated-settlement.xlsx');

        self::assertSame('2026-08-01', $result['lines'][0]['business_date']);
        self::assertSame(800.0, $result['lines'][0]['settlement_amount']);
    }

    public function testCanonicalHeaderAliasesCannotSilentlyOverwriteOneAnother(): void
    {
        $file = $this->temp('csv');
        file_put_contents($file, "业务日期,结算金额,实际结算\n2026-08-01,800,900\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('settlement_file_canonical_header_collision:settlement_amount');
        (new OtaSettlementFileParserService())->parse($file, 'collision.csv');
    }

    public function testDirectDiscrepancyBasisIsNeverInventedByFileParser(): void
    {
        $file = $this->temp('csv');
        file_put_contents($file, "业务日期,金额口径,结算金额,差异金额\n2026-08-01,settlement,800,100\n");

        $result = (new OtaSettlementFileParserService())->parse($file, 'discrepancy.csv');

        self::assertSame(100.0, $result['lines'][0]['discrepancy_amount']);
        self::assertArrayNotHasKey('discrepancy_basis', $result['lines'][0]);
    }

    public function testSettlementJsonRequiresOneUnambiguousRootArray(): void
    {
        $file = $this->temp('json');
        file_put_contents($file, json_encode([
            'rows' => [['business_date' => '2026-08-01', 'settlement_amount' => 100]],
            'data' => ['rows' => [['business_date' => '2026-08-01', 'settlement_amount' => 900]]],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('settlement_json_root_array_required');
        (new OtaSettlementFileParserService())->parse($file, 'ambiguous.json');
    }

    public function testUnsupportedFileIsRejectedBeforeParsing(): void
    {
        $file = $this->temp('txt');
        file_put_contents($file, 'not a settlement file');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('settlement_file_type_invalid');
        (new OtaSettlementFileParserService())->parse($file, 'settlement.txt');
    }

    private function temp(string $extension): string
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'ota_settlement_parser_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $this->files[] = $file;
        return $file;
    }
}
