<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeDocumentTextExtractor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class KnowledgeDocumentTextExtractorTest extends TestCase
{
    private const MAX_DOCX_ENTRIES = 256;
    private const MAX_DOCX_XML_PART_BYTES = 8 * 1024 * 1024;
    private const MAX_DOCX_TOTAL_XML_BYTES = 16 * 1024 * 1024;

    public function testExtractsUtf8TextDocument(): void
    {
        $path = $this->tempFile('txt');
        file_put_contents($path, "门店SOP\n\n差评30分钟内首响");

        try {
            $result = (new KnowledgeDocumentTextExtractor())->extractFromPath($path, 'sop.txt');

            self::assertSame('txt', $result['extension']);
            self::assertSame("门店SOP\n\n差评30分钟内首响", $result['text']);
            self::assertGreaterThan(0, $result['char_count']);
        } finally {
            @unlink($path);
        }
    }

    public function testExtractsDocxDocumentXml(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available');
        }

        $path = $this->tempFile('docx');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>');
        $zip->addFromString('word/document.xml', implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">',
            '<w:body>',
            '<w:p><w:r><w:t>巢湖测试门店卫生差评处理</w:t></w:r></w:p>',
            '<w:p><w:r><w:t>当天完成回访</w:t></w:r></w:p>',
            '</w:body></w:document>',
        ]));
        $zip->close();

        try {
            $result = (new KnowledgeDocumentTextExtractor())->extractFromPath($path, 'sop.docx');

            self::assertSame('docx', $result['extension']);
            self::assertStringContainsString('巢湖测试门店卫生差评处理', $result['text']);
            self::assertStringContainsString('当天完成回访', $result['text']);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsDocxWithTooManyArchiveEntries(): void
    {
        $this->requireZipArchive();

        $path = $this->tempFile('docx');
        $zip = $this->openDocxFixture($path);
        $zip->addFromString('word/document.xml', '<w:document><w:p>正文</w:p></w:document>');
        for ($index = 0; $index < self::MAX_DOCX_ENTRIES; $index++) {
            $zip->addFromString('custom/entry-' . $index . '.bin', 'x');
        }
        $zip->close();

        $this->assertDocxRejected(
            $path,
            'docx 文档压缩包条目过多，无法安全读取'
        );
    }

    public function testRejectsDocxWithTooManyHeaderAndFooterParts(): void
    {
        $this->requireZipArchive();

        $path = $this->tempFile('docx');
        $zip = $this->openDocxFixture($path);
        $zip->addFromString('word/document.xml', '<w:document><w:p>正文</w:p></w:document>');
        for ($index = 1; $index <= 33; $index++) {
            $zip->addFromString('word/header' . $index . '.xml', '<w:hdr><w:p>页眉</w:p></w:hdr>');
        }
        $zip->close();

        $this->assertDocxRejected(
            $path,
            'docx 文档页眉页脚数量过多，无法安全读取'
        );
    }

    public function testRejectsOversizedDocxXmlPartBeforeReadingIt(): void
    {
        $this->requireZipArchive();

        $path = $this->tempFile('docx');
        $zip = $this->openDocxFixture($path);
        $xml = '<w:document>'
            . str_repeat('A', self::MAX_DOCX_XML_PART_BYTES)
            . '</w:document>';
        $zip->addFromString('word/document.xml', $xml);
        self::assertTrue($zip->setCompressionName('word/document.xml', ZipArchive::CM_STORE));
        $zip->close();

        $this->assertDocxRejected(
            $path,
            'docx 文档单个 XML 解压后不能超过 8MB'
        );
    }

    public function testRejectsExcessiveTotalDocxXmlSizeBeforeReadingAnyPart(): void
    {
        $this->requireZipArchive();

        $path = $this->tempFile('docx');
        $zip = $this->openDocxFixture($path);
        $partPayloadBytes = intdiv(self::MAX_DOCX_TOTAL_XML_BYTES, 4) + 1;
        foreach ([
            'word/document.xml' => 'document',
            'word/header1.xml' => 'hdr',
            'word/header2.xml' => 'hdr',
            'word/footer1.xml' => 'ftr',
        ] as $name => $element) {
            $xml = '<w:' . $element . '>'
                . str_repeat('B', $partPayloadBytes)
                . '</w:' . $element . '>';
            $zip->addFromString($name, $xml);
            self::assertTrue($zip->setCompressionName($name, ZipArchive::CM_STORE));
        }
        $zip->close();

        $this->assertDocxRejected(
            $path,
            'docx 文档 XML 总解压大小不能超过 16MB'
        );
    }

    public function testIgnoresNonAllowlistedWordXmlParts(): void
    {
        $this->requireZipArchive();

        $path = $this->tempFile('docx');
        $zip = $this->openDocxFixture($path);
        $zip->addFromString('word/document.xml', '<w:document><w:p>正文内容</w:p></w:document>');
        $zip->addFromString('word/comments.xml', '<w:comments><w:p>不应读取的批注</w:p></w:comments>');
        $zip->addFromString('word/footnotes.xml', '<w:footnotes><w:p>不应读取的脚注</w:p></w:footnotes>');
        $zip->close();

        try {
            $result = (new KnowledgeDocumentTextExtractor())->extractFromPath($path, 'allowlist.docx');

            self::assertStringContainsString('正文内容', $result['text']);
            self::assertStringNotContainsString('不应读取的批注', $result['text']);
            self::assertStringNotContainsString('不应读取的脚注', $result['text']);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsDocxXmlWithExtremeCompressionRatio(): void
    {
        $this->requireZipArchive();

        $path = $this->tempFile('docx');
        $zip = $this->openDocxFixture($path);
        $zip->addFromString(
            'word/document.xml',
            '<w:document>' . str_repeat('C', 1024 * 1024) . '</w:document>'
        );
        $zip->close();

        $this->assertDocxRejected(
            $path,
            'docx 文档 XML 压缩比异常，无法安全读取'
        );
    }

    public function testRejectsEncryptedDocxXmlEntryWhenEncryptionMetadataIsAvailable(): void
    {
        $this->requireZipArchive();
        if (!method_exists(ZipArchive::class, 'setEncryptionName')
            || !defined(ZipArchive::class . '::EM_AES_256')
            || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            self::markTestSkipped('ZipArchive AES encryption is not available');
        }

        $path = $this->tempFile('docx');
        $zip = $this->openDocxFixture($path);
        $zip->addFromString('word/document.xml', '<w:document><w:p>加密正文</w:p></w:document>');
        self::assertTrue($zip->setEncryptionName('word/document.xml', ZipArchive::EM_AES_256, 'fixture-password'));
        $zip->close();

        $this->assertDocxRejected(
            $path,
            'docx 文档包含加密条目，无法读取'
        );
    }

    public function testExtractsHtmlWithoutExecutableOrStyleContent(): void
    {
        $path = $this->tempFile('html');
        file_put_contents($path, implode('', [
            '<!doctype html><html><head><title>房型经营分析</title>',
            '<style>:root{--navy:#123}.panel{display:grid}</style>',
            '<script>window.secretMetric = 97.7;</script></head>',
            '<body><h1>房型经营分析</h1><p>先校验口径，再解释经营。</p>',
            '<noscript>浏览器脚本提示</noscript>',
            '<template>页面模板占位</template></body></html>',
        ]));

        try {
            $result = (new KnowledgeDocumentTextExtractor())->extractFromPath($path, 'room-analysis.html');

            self::assertSame('html', $result['extension']);
            self::assertStringContainsString('房型经营分析', $result['text']);
            self::assertStringContainsString('先校验口径，再解释经营。', $result['text']);
            self::assertStringNotContainsString('--navy', $result['text']);
            self::assertStringNotContainsString('secretMetric', $result['text']);
            self::assertStringNotContainsString('浏览器脚本提示', $result['text']);
            self::assertStringNotContainsString('页面模板占位', $result['text']);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsUnsupportedDocumentType(): void
    {
        $path = $this->tempFile('pdf');
        file_put_contents($path, '%PDF-unsupported');

        try {
            $this->expectException(InvalidArgumentException::class);
            (new KnowledgeDocumentTextExtractor())->extractFromPath($path, 'demo.pdf');
        } finally {
            @unlink($path);
        }
    }

    private function tempFile(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'knowledge_doc_');
        self::assertIsString($path);
        $target = $path . '.' . $extension;
        rename($path, $target);

        return $target;
    }

    private function requireZipArchive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available');
        }
    }

    private function openDocxFixture(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
        ));

        return $zip;
    }

    private function assertDocxRejected(string $path, string $expectedMessage): void
    {
        try {
            (new KnowledgeDocumentTextExtractor())->extractFromPath($path, 'security-fixture.docx');
            self::fail('Expected the unsafe docx fixture to be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
            self::assertStringNotContainsString($path, $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }
}
