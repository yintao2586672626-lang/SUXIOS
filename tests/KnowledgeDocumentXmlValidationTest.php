<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeDocumentTextExtractor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class KnowledgeDocumentXmlValidationTest extends TestCase
{
    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public static function invalidParts(): array
    {
        return [
            'truncated body' => ['word/document.xml', '<w:document xmlns:w="' . self::WORD_NS . '"><w:body><w:p><w:r><w:t>Partial text', false],
            'truncated header' => ['word/header1.xml', '<w:hdr xmlns:w="' . self::WORD_NS . '"><w:p>Partial header', true],
            'mismatched footer' => ['word/footer1.xml', '<w:ftr xmlns:w="' . self::WORD_NS . '"><w:p>Partial footer</w:ftr>', false],
            'empty selected header XML' => ['word/header1.xml', '', true],
        ];
    }

    #[DataProvider('invalidParts')]
    public function testRejectsInvalidSelectedXmlWithoutLeakingLibxmlState(string $part, string $xml, bool $previousUseErrors): void
    {
        $path = $this->documentFixture([$part => $xml]);
        $originalUseErrors = libxml_use_internal_errors($previousUseErrors);
        try {
            (new KnowledgeDocumentTextExtractor())->extractFromPath($path, 'synthetic-invalid.docx');
            self::fail('A truncated or malformed selected XML part must not return a successful partial preview');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('docx 文档 XML 格式错误，无法读取', $exception->getMessage());
            self::assertStringNotContainsString($path, $exception->getMessage());
        } finally {
            self::assertSame($previousUseErrors, libxml_use_internal_errors(), 'parser restores caller error mode');
            libxml_clear_errors();
            libxml_use_internal_errors($originalUseErrors);
            @unlink($path);
        }
    }

    public function testPreservesValidBodyTablesLineBreaksHeadersAndSourceMetadata(): void
    {
        $body = '<w:document xmlns:w="' . self::WORD_NS . '"><w:body>'
            . '<w:p><w:r><w:t>第一段</w:t><w:tab/><w:t>制表</w:t><w:br/><w:t>换行</w:t></w:r></w:p>'
            . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>列一</w:t></w:r></w:p></w:tc>'
            . '<w:tc><w:p><w:r><w:t>0</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
            . '</w:body></w:document>';
        $path = $this->documentFixture([
            'word/document.xml' => $body,
            'word/header1.xml' => '<w:hdr xmlns:w="' . self::WORD_NS . '"><w:p><w:r><w:t>页眉</w:t></w:r></w:p></w:hdr>',
            'word/footer1.xml' => '<w:ftr xmlns:w="' . self::WORD_NS . '"><w:p><w:r><w:t>页脚</w:t></w:r></w:p></w:ftr>',
        ]);
        $originalUseErrors = libxml_use_internal_errors(false);
        try {
            $result = (new KnowledgeDocumentTextExtractor())->extractFromPath($path, 'synthetic-valid.docx');
            self::assertStringContainsString("第一段\t制表\n换行", $result['text']);
            foreach (['列一', '0', '页眉', '页脚'] as $value) self::assertStringContainsString($value, $result['text']);
            self::assertSame(hash_file('sha256', $path), $result['sha256']);
            self::assertSame(hash('sha256', $result['text']), $result['source_document']['text_sha256']);
            self::assertSame(mb_strlen($result['text']), $result['char_count']);
            self::assertFalse(libxml_use_internal_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($originalUseErrors);
            @unlink($path);
        }
    }

    private function documentFixture(array $overrides): string
    {
        $path = tempnam(sys_get_temp_dir(), 'knowledge_xml_');
        self::assertIsString($path);
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $entries = array_replace([
            'word/document.xml' => '<w:document xmlns:w="' . self::WORD_NS . '"><w:body><w:p><w:r><w:t>Complete synthetic body</w:t></w:r></w:p></w:body></w:document>',
        ], $overrides);
        foreach ($entries as $name => $xml) self::assertTrue($archive->addFromString($name, $xml));
        self::assertTrue($archive->close());
        return $path;
    }
}
