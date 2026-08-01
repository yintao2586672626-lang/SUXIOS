<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenuePricingInputSpreadsheetService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class RevenuePricingInputSpreadsheetServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not installed.');
        }
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'suxios_pricing_xlsx_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->tempDir, 0777, true));
    }

    protected function tearDown(): void
    {
        if (!isset($this->tempDir) || !is_dir($this->tempDir)) {
            return;
        }
        foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }

    public function testReadsPreferredSheetAndNormalizesExcelDateWithoutLosingZero(): void
    {
        $headers = $this->requiredHeaders();
        $values = [
            'section' => 'room_type',
            'business_date' => ['numeric' => $this->excelSerial('2026-07-31')],
            'hotel_id' => ['numeric' => 80],
            'room_type_key' => 'deluxe_king',
            'room_type_name' => 'Deluxe King',
            'base_price' => ['numeric' => 0],
            'min_price' => ['numeric' => 260],
            'max_price' => ['numeric' => 460],
            'room_count' => ['numeric' => 8],
            'is_enabled' => ['numeric' => 1],
            'sort_order' => ['numeric' => 1],
        ];
        $row = array_map(static fn(string $header): mixed => $values[$header] ?? '', $headers);
        $path = $this->buildWorkbook('valid.xlsx', [
            ['name' => 'Instructions', 'rows' => [['do_not_import']]],
            ['name' => RevenuePricingInputSpreadsheetService::PREFERRED_SHEET_NAME, 'rows' => [$headers, $row]],
        ]);

        $result = (new RevenuePricingInputSpreadsheetService())->read($path, $headers);

        self::assertSame(RevenuePricingInputSpreadsheetService::PREFERRED_SHEET_NAME, $result['sheet_name']);
        self::assertSame('1900', $result['date_system']);
        self::assertSame(1, $result['row_count']);
        self::assertSame(2, $result['rows_with_lines'][0]['line']);
        self::assertSame('2026-07-31', $result['rows'][0]['business_date']);
        self::assertSame('80', $result['rows'][0]['hotel_id']);
        self::assertSame('0', $result['rows'][0]['base_price']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['sha256']);
    }

    public function testMissingRequiredHeaderIsRejectedBeforeBusinessImport(): void
    {
        $headers = array_values(array_filter(
            $this->requiredHeaders(),
            static fn(string $header): bool => $header !== 'source_note'
        ));
        $path = $this->buildWorkbook('missing-header.xlsx', [[
            'name' => RevenuePricingInputSpreadsheetService::PREFERRED_SHEET_NAME,
            'rows' => [$headers],
        ]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('XLSX header missing required columns: source_note');
        (new RevenuePricingInputSpreadsheetService())->read($path, $this->requiredHeaders());
    }

    public function testFormulaCellIsRejectedEvenWhenCachedValueExists(): void
    {
        $headers = $this->requiredHeaders();
        $values = [
            'section' => 'room_type',
            'business_date' => '2026-07-31',
            'hotel_id' => '80',
            'room_type_key' => 'deluxe_king',
            'room_type_name' => 'Deluxe King',
            'base_price' => ['formula' => '160+160', 'value' => 320],
        ];
        $row = array_map(static fn(string $header): mixed => $values[$header] ?? '', $headers);
        $path = $this->buildWorkbook('formula.xlsx', [[
            'name' => RevenuePricingInputSpreadsheetService::PREFERRED_SHEET_NAME,
            'rows' => [$headers, $row],
        ]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('XLSX formulas are not accepted as operator evidence');
        (new RevenuePricingInputSpreadsheetService())->read($path, $headers);
    }

    public function testMultipleSheetsRequireAnExplicitIntakeSheetName(): void
    {
        $path = $this->buildWorkbook('ambiguous.xlsx', [
            ['name' => 'July', 'rows' => [$this->requiredHeaders()]],
            ['name' => 'August', 'rows' => [$this->requiredHeaders()]],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name the intake sheet "pricing-input-intake"');
        (new RevenuePricingInputSpreadsheetService())->read($path, $this->requiredHeaders());
    }

    public function testCliBuildsValidatedJsonFromXlsxWithoutDatabaseWrite(): void
    {
        $path = $this->buildWorkbook('cli-valid.xlsx', [[
            'name' => RevenuePricingInputSpreadsheetService::PREFERRED_SHEET_NAME,
            'rows' => $this->validIntakeRows('2026-07-31', 80),
        ]]);
        $output = $this->tempDir . DIRECTORY_SEPARATOR . 'pricing-input.json';

        $run = $this->runImporter($path, $output, '2026-07-31', 80);

        self::assertSame(0, $run['exit_code'], $run['stderr'] . "\n" . $run['stdout']);
        self::assertSame('passed', $run['payload']['status'] ?? null);
        self::assertSame('build_json_from_xlsx', $run['payload']['mode'] ?? null);
        self::assertFalse((bool)($run['payload']['scope']['database_written'] ?? true));
        self::assertSame('pricing-input-intake', $run['payload']['summary']['sheet_name'] ?? null);
        self::assertSame(4, $run['payload']['summary']['spreadsheet_row_count'] ?? null);
        self::assertFileExists($output);
        $built = json_decode((string)file_get_contents($output), true);
        self::assertSame(80, $built['hotel_id'] ?? null);
        self::assertSame('2026-07-31', $built['business_date'] ?? null);
        self::assertSame('ctrip_ota_channel', $built['source_scope'] ?? null);
    }

    public function testCliRejectsCrossHotelXlsxRow(): void
    {
        $rows = $this->validIntakeRows('2026-07-31', 80);
        $hotelColumn = array_search('hotel_id', $rows[0], true);
        self::assertIsInt($hotelColumn);
        $rows[2][$hotelColumn] = 81;
        $path = $this->buildWorkbook('cross-hotel.xlsx', [[
            'name' => RevenuePricingInputSpreadsheetService::PREFERRED_SHEET_NAME,
            'rows' => $rows,
        ]]);

        $run = $this->runImporter($path, '', '2026-07-31', 80);

        self::assertSame(1, $run['exit_code']);
        self::assertSame('failed', $run['payload']['status'] ?? null);
        self::assertStringContainsString('XLSX row 3 hotel_id does not match --hotel-id', (string)($run['payload']['error'] ?? ''));
    }

    public function testCliRejectsCrossDateXlsxRow(): void
    {
        $rows = $this->validIntakeRows('2026-07-31', 80);
        $dateColumn = array_search('business_date', $rows[0], true);
        self::assertIsInt($dateColumn);
        $rows[3][$dateColumn] = ['numeric' => $this->excelSerial('2026-08-01')];
        $path = $this->buildWorkbook('cross-date.xlsx', [[
            'name' => RevenuePricingInputSpreadsheetService::PREFERRED_SHEET_NAME,
            'rows' => $rows,
        ]]);

        $run = $this->runImporter($path, '', '2026-07-31', 80);

        self::assertSame(1, $run['exit_code']);
        self::assertSame('failed', $run['payload']['status'] ?? null);
        self::assertStringContainsString('XLSX row 4 business_date does not match --date', (string)($run['payload']['error'] ?? ''));
    }

    public function testExistingCsvBuildModeRemainsCompatible(): void
    {
        $date = '2026-07-31';
        $rows = $this->validIntakeRows($date, 80);
        $headers = $rows[0];
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'pricing-input.csv';
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        foreach ($rows as $rowIndex => $row) {
            $csvRow = [];
            foreach ($row as $column => $value) {
                if ($rowIndex > 0 && is_array($value) && array_key_exists('numeric', $value)) {
                    $header = (string)($headers[$column] ?? '');
                    $value = in_array($header, ['business_date', 'forecast_date', 'analysis_date'], true)
                        ? $date
                        : $value['numeric'];
                }
                $csvRow[] = $value;
            }
            self::assertNotFalse(fputcsv($handle, $csvRow));
        }
        fclose($handle);

        $run = $this->runCsvImporter($path, $date, 80);

        self::assertSame(0, $run['exit_code'], $run['stderr'] . "\n" . $run['stdout']);
        self::assertSame('passed', $run['payload']['status'] ?? null);
        self::assertSame('build_json_from_csv', $run['payload']['mode'] ?? null);
        self::assertSame('csv_issue_map_no_values_no_import', $run['payload']['summary']['csv_issue_map_policy'] ?? null);
        self::assertSame([], $run['payload']['csv_issue_map'] ?? null);
    }

    /** @return array<int, string> */
    private function requiredHeaders(): array
    {
        return [
            'section',
            'business_date',
            'hotel_id',
            'room_type_key',
            'room_type_name',
            'base_price',
            'min_price',
            'max_price',
            'room_count',
            'is_enabled',
            'sort_order',
            'forecast_date',
            'predicted_occupancy',
            'predicted_demand',
            'confidence_score',
            'forecast_method',
            'analysis_date',
            'competitor_name',
            'our_price',
            'competitor_price',
            'ota_platform',
            'confirmed_by',
            'confirmed_at',
            'room_type_source',
            'price_guard_source',
            'demand_forecast_source',
            'competitor_price_source',
            'source_note',
        ];
    }

    private function excelSerial(string $date): int
    {
        $timezone = new DateTimeZone('UTC');
        $base = new DateTimeImmutable('1899-12-30', $timezone);
        $target = new DateTimeImmutable($date, $timezone);
        return (int)$base->diff($target)->days;
    }

    /** @return array<int, array<int, mixed>> */
    private function validIntakeRows(string $date, int $hotelId): array
    {
        $headers = $this->requiredHeaders();
        $serial = ['numeric' => $this->excelSerial($date)];
        $row = static function (array $values) use ($headers): array {
            return array_map(static fn(string $header): mixed => $values[$header] ?? '', $headers);
        };

        return [
            $headers,
            $row([
                'section' => 'evidence',
                'business_date' => $serial,
                'hotel_id' => ['numeric' => $hotelId],
                'confirmed_by' => 'automated acceptance actor',
                'confirmed_at' => $date . ' 09:00:00',
                'room_type_source' => 'controlled acceptance run room catalog',
                'price_guard_source' => 'controlled acceptance run price guard record',
                'demand_forecast_source' => 'controlled acceptance run demand record',
                'competitor_price_source' => 'controlled acceptance run Ctrip comparison record',
            ]),
            $row([
                'section' => 'room_type',
                'business_date' => $serial,
                'hotel_id' => ['numeric' => $hotelId],
                'room_type_key' => 'acceptance_deluxe_king',
                'room_type_name' => 'Acceptance Deluxe King',
                'base_price' => ['numeric' => 320],
                'min_price' => ['numeric' => 260],
                'max_price' => ['numeric' => 460],
                'room_count' => ['numeric' => 8],
                'is_enabled' => ['numeric' => 1],
                'sort_order' => ['numeric' => 1],
                'source_note' => 'controlled acceptance run room row',
            ]),
            $row([
                'section' => 'demand_forecast',
                'business_date' => $serial,
                'hotel_id' => ['numeric' => $hotelId],
                'room_type_key' => 'acceptance_deluxe_king',
                'forecast_date' => $serial,
                'predicted_occupancy' => ['numeric' => 91],
                'predicted_demand' => ['numeric' => 8],
                'confidence_score' => ['numeric' => 0.84],
                'forecast_method' => ['numeric' => 3],
                'source_note' => 'controlled acceptance run demand row',
            ]),
            $row([
                'section' => 'competitor_price_sample',
                'business_date' => $serial,
                'hotel_id' => ['numeric' => $hotelId],
                'room_type_key' => 'acceptance_deluxe_king',
                'analysis_date' => $serial,
                'competitor_name' => 'Acceptance Competitor Hotel',
                'our_price' => ['numeric' => 320],
                'competitor_price' => ['numeric' => 365],
                'ota_platform' => 'ctrip',
                'source_note' => 'controlled acceptance run competitor row',
            ]),
        ];
    }

    /** @return array{exit_code:int,stdout:string,stderr:string,payload:array<string,mixed>} */
    private function runImporter(string $xlsxPath, string $outputPath, string $date, int $hotelId): array
    {
        $root = dirname(__DIR__);
        $command = [
            PHP_BINARY,
            $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'import_revenue_ai_ctrip_pricing_inputs.php',
            '--build-json-from-xlsx=1',
            '--xlsx-file=' . $xlsxPath,
            '--date=' . $date,
            '--hotel-id=' . $hotelId,
        ];
        if ($outputPath !== '') {
            $command[] = '--output=' . $outputPath;
            $command[] = '--force=1';
        }
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start pricing XLSX importer.');
        }
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $payload = json_decode($stdout, true);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    /** @return array{exit_code:int,stdout:string,stderr:string,payload:array<string,mixed>} */
    private function runCsvImporter(string $csvPath, string $date, int $hotelId): array
    {
        $root = dirname(__DIR__);
        $command = [
            PHP_BINARY,
            $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'import_revenue_ai_ctrip_pricing_inputs.php',
            '--build-json-from-csv=1',
            '--csv-file=' . $csvPath,
            '--date=' . $date,
            '--hotel-id=' . $hotelId,
        ];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start pricing CSV importer.');
        }
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $payload = json_decode($stdout, true);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    /**
     * @param array<int, array{name:string,rows:array<int,array<int,mixed>>}> $sheets
     */
    private function buildWorkbook(string $fileName, array $sheets): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $fileName;
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $worksheetOverrides = '';
        $workbookSheets = '';
        $workbookRelationships = '';
        foreach ($sheets as $index => $sheet) {
            $number = $index + 1;
            $worksheetOverrides .= '<Override PartName="/xl/worksheets/sheet' . $number . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets .= '<sheet name="' . $this->xml($sheet['name']) . '" sheetId="' . $number . '" r:id="rId' . $number . '"/>';
            $workbookRelationships .= '<Relationship Id="rId' . $number . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $number . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $number . '.xml', $this->worksheetXml($sheet['rows']));
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $worksheetOverrides . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<workbookPr date1904="0"/><sheets>' . $workbookSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $workbookRelationships . '</Relationships>');
        self::assertTrue($zip->close());

        return $path;
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function worksheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $rowIndex => $row) {
            $line = $rowIndex + 1;
            $xml .= '<row r="' . $line . '">';
            foreach ($row as $columnIndex => $value) {
                $reference = $this->columnLetters($columnIndex) . $line;
                if (is_array($value) && array_key_exists('formula', $value)) {
                    $xml .= '<c r="' . $reference . '"><f>' . $this->xml((string)$value['formula']) . '</f><v>'
                        . $this->xml((string)($value['value'] ?? '')) . '</v></c>';
                    continue;
                }
                if (is_array($value) && array_key_exists('numeric', $value)) {
                    $xml .= '<c r="' . $reference . '"><v>' . $this->xml((string)$value['numeric']) . '</v></c>';
                    continue;
                }
                $xml .= '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">'
                    . $this->xml((string)$value) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
    }

    private function columnLetters(int $index): string
    {
        $letters = '';
        for ($value = $index + 1; $value > 0; $value = intdiv($value - 1, 26)) {
            $letters = chr(65 + (($value - 1) % 26)) . $letters;
        }
        return $letters;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
