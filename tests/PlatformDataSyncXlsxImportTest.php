<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;
use think\App;

final class PlatformDataSyncXlsxImportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
    }

    public function testXlsxImportRejectsArchiveWithTooManyEntriesBeforeXmlParsing(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not installed.');
        }

        $path = tempnam(sys_get_temp_dir(), 'platform_xlsx_many_');
        self::assertIsString($path);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        for ($index = 0; $index < 257; $index++) {
            self::assertTrue($zip->addFromString('xl/custom/entry-' . $index . '.xml', ''));
        }
        self::assertTrue($zip->close());

        try {
            $method = new \ReflectionMethod(new PlatformDataSyncService(), 'parseXlsxImportFile');
            $method->setAccessible(true);
            $method->invoke(new PlatformDataSyncService(), $path);
            self::fail('Oversized XLSX archive entry count must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame('XLSX import archive contains too many entries.', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function testXlsxImportStillParsesAValidBoundedWorksheet(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not installed.');
        }

        $path = tempnam(sys_get_temp_dir(), 'platform_xlsx_valid_');
        self::assertIsString($path);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString(
            'xl/worksheets/sheet1.xml',
            '<worksheet><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>hotel_name</t></is></c></row>'
            . '<row r="2"><c r="A2" t="inlineStr"><is><t>Bounded Hotel</t></is></c></row>'
            . '</sheetData></worksheet>'
        ));
        self::assertTrue($zip->close());

        try {
            $service = new PlatformDataSyncService();
            $method = new \ReflectionMethod($service, 'parseXlsxImportFile');
            $method->setAccessible(true);
            $rows = $method->invoke($service, $path);
            self::assertSame([['hotel_name' => 'Bounded Hotel']], $rows);
        } finally {
            @unlink($path);
        }
    }
}
