<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeDocumentTextExtractor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class KnowledgeDocumentTextExtractorTest extends TestCase
{
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
}
