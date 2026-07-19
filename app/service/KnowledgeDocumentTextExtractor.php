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
    private const MAX_DOCX_ARCHIVE_ENTRIES = 256;
    private const MAX_DOCX_HEADER_FOOTER_PARTS = 32;
    private const MAX_DOCX_XML_PART_BYTES = 8 * 1024 * 1024;
    private const MAX_DOCX_TOTAL_XML_BYTES = 16 * 1024 * 1024;
    private const MAX_DOCX_XML_COMPRESSION_RATIO = 100;
    private const DOCX_XML_PATHS = ['word/document.xml'];

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
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new InvalidArgumentException('docx 文档无法打开，请确认文件未损坏');
        }

        try {
            $parts = [];
            foreach ($this->validatedDocxXmlEntries($zip) as $entry) {
                $xml = $this->readDocxXmlEntry($zip, $entry);
                if (trim($xml) !== '') {
                    $parts[] = $this->extractDocxXmlText($xml);
                }
            }
        } finally {
            $zip->close();
        }

        return implode("\n\n", array_filter($parts, static fn(string $part): bool => trim($part) !== ''));
    }

    /**
     * @return array<int, array{name:string,size:int}>
     */
    private function validatedDocxXmlEntries(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_DOCX_ARCHIVE_ENTRIES) {
            throw new InvalidArgumentException('docx 文档压缩包条目过多，无法安全读取');
        }

        $entriesByName = [];
        $headerFooterNames = [];
        $totalXmlBytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !isset($stat['name']) || !is_string($stat['name']) || $stat['name'] === '') {
                throw new InvalidArgumentException('docx 文档压缩条目元数据异常，无法安全读取');
            }

            $name = $stat['name'];
            if (!array_key_exists('size', $stat)
                || !is_int($stat['size'])
                || $stat['size'] < 0
                || !array_key_exists('comp_size', $stat)
                || !is_int($stat['comp_size'])
                || $stat['comp_size'] < 0) {
                throw new InvalidArgumentException('docx 文档压缩条目元数据异常，无法安全读取');
            }

            if (array_key_exists('encryption_method', $stat)) {
                if (!is_int($stat['encryption_method'])) {
                    throw new InvalidArgumentException('docx 文档压缩条目元数据异常，无法安全读取');
                }
                if ($stat['encryption_method'] !== ZipArchive::EM_NONE) {
                    throw new InvalidArgumentException('docx 文档包含加密条目，无法读取');
                }
            }

            $isHeaderFooter = preg_match('/^word\/(?:header|footer)\d+\.xml$/', $name) === 1;
            if (!in_array($name, self::DOCX_XML_PATHS, true) && !$isHeaderFooter) {
                continue;
            }

            if (isset($entriesByName[$name])) {
                throw new InvalidArgumentException('docx 文档包含重复的 XML 条目，无法安全读取');
            }

            $size = $stat['size'];
            $compressedSize = $stat['comp_size'];
            if ($size > self::MAX_DOCX_XML_PART_BYTES) {
                throw new InvalidArgumentException('docx 文档单个 XML 解压后不能超过 8MB');
            }
            if ($size > 0 && $compressedSize === 0) {
                throw new InvalidArgumentException('docx 文档压缩条目元数据异常，无法安全读取');
            }
            if ($compressedSize > 0 && ($size / $compressedSize) > self::MAX_DOCX_XML_COMPRESSION_RATIO) {
                throw new InvalidArgumentException('docx 文档 XML 压缩比异常，无法安全读取');
            }

            $totalXmlBytes += $size;
            if ($totalXmlBytes > self::MAX_DOCX_TOTAL_XML_BYTES) {
                throw new InvalidArgumentException('docx 文档 XML 总解压大小不能超过 16MB');
            }

            $entriesByName[$name] = [
                'name' => $name,
                'size' => $size,
            ];
            if ($isHeaderFooter) {
                $headerFooterNames[] = $name;
                if (count($headerFooterNames) > self::MAX_DOCX_HEADER_FOOTER_PARTS) {
                    throw new InvalidArgumentException('docx 文档页眉页脚数量过多，无法安全读取');
                }
            }
        }

        if (!isset($entriesByName['word/document.xml'])) {
            throw new InvalidArgumentException('docx 文档缺少正文 XML，无法读取');
        }

        $entries = [];
        foreach (self::DOCX_XML_PATHS as $name) {
            if (isset($entriesByName[$name])) {
                $entries[] = $entriesByName[$name];
            }
        }
        sort($headerFooterNames, SORT_NATURAL);
        foreach ($headerFooterNames as $name) {
            $entries[] = $entriesByName[$name];
        }

        return $entries;
    }

    /**
     * @param array{name:string,size:int} $entry
     */
    private function readDocxXmlEntry(ZipArchive $zip, array $entry): string
    {
        $stream = $zip->getStream($entry['name']);
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('docx 文档 XML 内容读取失败');
        }

        try {
            $xml = stream_get_contents($stream, $entry['size'] + 1);
        } finally {
            fclose($stream);
        }

        if (!is_string($xml) || strlen($xml) !== $entry['size']) {
            throw new InvalidArgumentException('docx 文档 XML 内容与压缩元数据不一致');
        }

        return $xml;
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
