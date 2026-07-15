<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class KnowledgeDocumentTextExtractor
{
    private const TEXT_EXTENSIONS = ['txt', 'md', 'markdown', 'csv', 'json', 'log'];
    private const HTML_EXTENSIONS = ['html', 'htm'];
    private const DOCX_XML_PATHS = [
        'word/document.xml',
        'word/footnotes.xml',
        'word/endnotes.xml',
        'word/comments.xml',
    ];

    /**
     * @return array{filename:string, extension:string, text:string, char_count:int}
     */
    public function extractFromPath(string $path, string $filename): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('文档文件不可读取');
        }

        $extension = $this->extensionFromFilename($filename);
        if ($extension === '') {
            throw new InvalidArgumentException('无法识别文档类型，请使用 txt、md、csv、json、html 或 docx 文件');
        }

        $text = match (true) {
            in_array($extension, self::TEXT_EXTENSIONS, true) => $this->readUtf8TextFile($path),
            in_array($extension, self::HTML_EXTENSIONS, true) => $this->extractHtmlText($this->readUtf8TextFile($path)),
            $extension === 'docx' => $this->extractDocxText($path),
            default => throw new InvalidArgumentException('暂不支持该文档类型：' . $extension),
        };

        $text = $this->normalizeText($text);
        if ($text === '') {
            throw new InvalidArgumentException('文档未解析到可导入的文字内容');
        }

        return [
            'filename' => $filename,
            'extension' => $extension,
            'text' => $text,
            'char_count' => mb_strlen($text),
        ];
    }

    private function extensionFromFilename(string $filename): string
    {
        return strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    }

    private function readUtf8TextFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('文档读取失败');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new InvalidArgumentException('文本文档必须使用 UTF-8 编码；非 UTF-8 文档请复制正文后直接粘贴');
        }

        return $content;
    }

    private function extractHtmlText(string $html): string
    {
        $html = preg_replace('/<(script|style|noscript|template)\b[^>]*>.*?<\/\1\s*>/isu', '', $html) ?? $html;
        $html = preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/iu', "\n", $html) ?? $html;
        $html = preg_replace('/<li\b[^>]*>/iu', "\n- ", $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function extractDocxText(string $path): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('服务器未启用 ZipArchive，无法读取 docx 文档');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('docx 文档无法打开，请确认文件未损坏');
        }

        try {
            $parts = [];
            foreach ($this->docxXmlPaths($zip) as $xmlPath) {
                $xml = $zip->getFromName($xmlPath);
                if (is_string($xml) && trim($xml) !== '') {
                    $parts[] = $this->extractDocxXmlText($xml);
                }
            }
        } finally {
            $zip->close();
        }

        return implode("\n\n", array_filter($parts, static fn(string $part): bool => trim($part) !== ''));
    }

    /**
     * @return array<int, string>
     */
    private function docxXmlPaths(ZipArchive $zip): array
    {
        $paths = self::DOCX_XML_PATHS;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string)$zip->getNameIndex($index);
            if (preg_match('/^word\/(?:header|footer)\d+\.xml$/', $name)) {
                $paths[] = $name;
            }
        }

        return array_values(array_unique($paths));
    }

    private function extractDocxXmlText(string $xml): string
    {
        $xml = preg_replace('/<w:tab\b[^>]*\/>/u', "\t", $xml) ?? $xml;
        $xml = preg_replace('/<w:br\b[^>]*\/>/u', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:tc>/u', "\t", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:(p|tr)>/u', "\n", $xml) ?? $xml;

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;

        return trim($text);
    }
}
