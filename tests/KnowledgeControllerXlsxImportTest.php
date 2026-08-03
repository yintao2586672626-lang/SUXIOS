<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Knowledge;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use ZipArchive;

final class KnowledgeControllerXlsxImportTest extends TestCase
{
    public function testUploadedXlsxIsReExtractedWithServerFingerprintAndStructuredMetadata(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available');
        }

        $path = $this->xlsxFixture();
        $file = new class($path) {
            public function __construct(private string $path)
            {
            }

            public function getSize(): int
            {
                return (int)filesize($this->path);
            }

            public function getOriginalName(): string
            {
                return '房型梳理.xlsx';
            }

            public function getPathname(): string
            {
                return $this->path;
            }
        };

        try {
            $result = $this->invokeKnowledgeMethod('extractUploadedXlsxImport', [$file]);

            self::assertSame(hash_file('sha256', $path), $result['source_document']['sha256']);
            self::assertSame('房型梳理.xlsx', $result['source_document']['filename']);
            self::assertSame('xlsx', $result['source_document']['extension']);
            self::assertSame(['A1', 'B1'], $result['source_document']['sheets'][0]['cell_refs']);
            self::assertSame(['A1:B1'], $result['source_document']['sheets'][0]['merged_ranges']);
            self::assertStringContainsString('A1：房型', $result['text']);
        } finally {
            @unlink($path);
        }
    }

    public function testExactReadbackAcceptsCanonicalJsonAndReturnsIdsAndHashes(): void
    {
        $expectedUnit = [
            'unit_id' => 31,
            'hotel_id' => 80,
            'name' => '通用房型梳理模板',
            'source' => 'manual_template',
            'status' => 'done',
            'description' => 'AI 摘要',
            'tags' => ['人工模板', '行业通用', '未核验'],
            'created_by' => 7,
        ];
        $expectedChunk = [
            'chunk_id' => 91,
            'unit_id' => 31,
            'type' => 'AI资料蒸馏',
            'content' => [
                'knowledge_scope' => 'industry_general',
                'source_document' => ['sha256' => str_repeat('a', 64), 'extension' => 'xlsx'],
                'confidence_score' => 1.0,
            ],
            'created_by' => 7,
        ];
        $actualChunk = $expectedChunk;
        $actualChunk['content'] = [
            'source_document' => ['extension' => 'xlsx', 'sha256' => str_repeat('a', 64)],
            'knowledge_scope' => 'industry_general',
            'confidence_score' => 1,
        ];

        $result = $this->invokeKnowledgeMethod(
            'verifyImportedKnowledgeReadbackRows',
            [$expectedUnit, $expectedChunk, $expectedUnit, $actualChunk]
        );

        self::assertSame(31, $result['unit_id']);
        self::assertSame(91, $result['chunk_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['unit_snapshot_sha256']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['chunk_content_sha256']);
    }

    public function testExactReadbackRejectsChangedPersistedContent(): void
    {
        $unit = [
            'unit_id' => 31,
            'hotel_id' => 80,
            'name' => '模板',
            'source' => 'manual_template',
            'status' => 'done',
            'description' => '摘要',
            'tags' => ['未核验'],
            'created_by' => 7,
        ];
        $chunk = [
            'chunk_id' => 91,
            'unit_id' => 31,
            'type' => 'AI资料蒸馏',
            'content' => ['verification_status' => 'unverified'],
            'created_by' => 7,
        ];
        $changed = $chunk;
        $changed['content']['verification_status'] = 'verified';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Imported knowledge chunk readback mismatch: content');

        $this->invokeKnowledgeMethod(
            'verifyImportedKnowledgeReadbackRows',
            [$unit, $chunk, $unit, $changed]
        );
    }

    /** @return mixed */
    private function invokeKnowledgeMethod(string $name, array $arguments)
    {
        $controller = (new ReflectionClass(Knowledge::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Knowledge::class, $name);
        $method->setAccessible(true);

        return $method->invokeArgs($controller, $arguments);
    }

    private function xlsxFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'knowledge_import_');
        self::assertIsString($path);
        $target = $path . '.xlsx';
        rename($path, $target);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'));
        self::assertTrue($zip->addFromString('xl/workbook.xml', implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ',
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">',
            '<sheets><sheet name="房型模板" sheetId="1" r:id="rId1"/></sheets></workbook>',
        ])));
        self::assertTrue($zip->addFromString('xl/_rels/workbook.xml.rels', implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">',
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" ',
            'Target="worksheets/sheet1.xml"/>',
            '</Relationships>',
        ])));
        self::assertTrue($zip->addFromString('xl/worksheets/sheet1.xml', implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">',
            '<sheetData><row r="1">',
            '<c r="A1" t="inlineStr"><is><t>房型</t></is></c>',
            '<c r="B1" t="inlineStr"><is><t>大床房</t></is></c>',
            '</row></sheetData><mergeCells count="1"><mergeCell ref="A1:B1"/></mergeCells>',
            '</worksheet>',
        ])));
        $zip->close();

        return $target;
    }
}
