<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
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
    private const XLSX_MAX_ARCHIVE_ENTRIES = 512;
    private const XLSX_MAX_WORKSHEETS = 32;
    private const XLSX_MAX_ROWS_PER_SHEET = 5000;
    private const XLSX_MAX_CELLS_PER_SHEET = 200000;
    private const XLSX_MAX_SHARED_STRINGS = 100000;
    private const XLSX_MAX_MERGED_RANGES_PER_SHEET = 512;
    private const XLSX_MAX_XML_PART_BYTES = 8 * 1024 * 1024;
    private const XLSX_MAX_TOTAL_XML_BYTES = 32 * 1024 * 1024;
    private const XLSX_MAX_XML_COMPRESSION_RATIO = 100;
    private const XLSX_MAX_CELL_TEXT_CHARS = 10000;
    private const XLSX_MAX_EXTRACTED_TEXT_CHARS = 200000;
    private const XLSX_MAX_METADATA_CELL_REFERENCES = 5000;
    private const SPREADSHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const OFFICE_RELATIONSHIP_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_RELATIONSHIP_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * @return array{
     *     filename:string,
     *     extension:string,
     *     text:string,
     *     char_count:int,
     *     sha256:string,
     *     source_document:array<string,mixed>
     * }
     */
    public function extractFromPath(string $path, string $filename): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('文档文件不可读取');
        }

        $extension = $this->extensionFromFilename($filename);
        if ($extension === '') {
            throw new InvalidArgumentException('无法识别文档类型，请使用 txt、md、csv、json、html、docx 或 xlsx 文件');
        }

        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256) || $sha256 === '') {
            throw new RuntimeException('文档来源指纹计算失败');
        }

        $xlsxMetadata = [];
        $text = match (true) {
            in_array($extension, self::TEXT_EXTENSIONS, true) => $this->readUtf8TextFile($path),
            in_array($extension, self::HTML_EXTENSIONS, true) => $this->extractHtmlText($this->readUtf8TextFile($path)),
            $extension === 'docx' => $this->extractDocxText($path),
            $extension === 'xlsx' => $this->extractXlsxText($path, $filename, $sha256, $xlsxMetadata),
            default => throw new InvalidArgumentException('暂不支持该文档类型：' . $extension),
        };

        $text = $this->normalizeText($text);
        if ($text === '') {
            throw new InvalidArgumentException('文档未解析到可导入的文字内容');
        }

        $safeFilename = trim(str_replace(["\r", "\n"], ' ', basename($filename)));
        if ($safeFilename === '') {
            $safeFilename = 'document.' . $extension;
        }
        $sourceDocument = [
            'filename' => $safeFilename,
            'extension' => $extension,
            'sha256' => $sha256,
            'text_sha256' => hash('sha256', $text),
            'char_count' => mb_strlen($text),
        ];
        if ($extension === 'xlsx') {
            $sourceDocument['sheets'] = array_values((array)($xlsxMetadata['sheets'] ?? []));
        }

        return [
            'filename' => $safeFilename,
            'extension' => $extension,
            'text' => $text,
            'char_count' => mb_strlen($text),
            'sha256' => $sha256,
            'source_document' => $sourceDocument,
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
     * @param array<string, mixed> $metadata
     */
    private function extractXlsxText(
        string $path,
        string $filename,
        string $fingerprint,
        array &$metadata
    ): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('服务器未启用 ZipArchive，无法读取 xlsx 文档');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new InvalidArgumentException('xlsx 文档无法打开，请确认文件未损坏');
        }

        try {
            $this->validateXlsxArchive($zip);
            $workbook = $this->readXlsxXmlEntry($zip, 'xl/workbook.xml', 'workbook');
            $relationships = $this->readXlsxXmlEntry($zip, 'xl/_rels/workbook.xml.rels', 'workbook relationships');
            $sharedStrings = $this->readXlsxSharedStrings($zip);
            $sheets = $this->resolveXlsxWorksheets($workbook, $relationships);

            $sections = [];
            $sheetMetadata = [];
            foreach ($sheets as $sheet) {
                $worksheet = $this->readXlsxXmlEntry($zip, $sheet['path'], 'worksheet ' . $sheet['name']);
                $rendered = $this->renderXlsxWorksheet($worksheet, $sharedStrings, $sheet['name']);
                if ($rendered['text'] !== '') {
                    $sections[] = $rendered['text'];
                }
                $sheetMetadata[] = $rendered['metadata'];
            }
        } finally {
            $zip->close();
        }

        if ($sections === []) {
            throw new InvalidArgumentException('xlsx 文档未解析到可导入的文字内容');
        }

        $safeFilename = trim(str_replace(["\r", "\n"], ' ', basename($filename)));
        $parts = ['Excel 工作簿：' . ($safeFilename !== '' ? $safeFilename : 'workbook.xlsx')];
        $parts[] = '来源指纹：sha256:' . $fingerprint;
        $parts = array_merge($parts, $sections);
        $text = implode("\n\n", $parts);
        if (mb_strlen($text) > self::XLSX_MAX_EXTRACTED_TEXT_CHARS) {
            throw new InvalidArgumentException('xlsx 文档可导入文字内容超过 200000 字限制');
        }

        $metadata = ['sheets' => $sheetMetadata];

        return $text;
    }

    private function validateXlsxArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::XLSX_MAX_ARCHIVE_ENTRIES) {
            throw new InvalidArgumentException('xlsx 文档压缩包条目数量异常，无法安全读取');
        }

        $totalXmlBytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)
                || !isset($stat['name'])
                || !is_string($stat['name'])
                || $stat['name'] === ''
                || str_contains($stat['name'], '\\')
                || str_starts_with($stat['name'], '/')
                || preg_match('#(?:^|/)\.\.?/#', $stat['name']) === 1) {
                throw new InvalidArgumentException('xlsx 文档压缩条目路径异常，无法安全读取');
            }
            if (!array_key_exists('size', $stat)
                || !is_int($stat['size'])
                || $stat['size'] < 0
                || !array_key_exists('comp_size', $stat)
                || !is_int($stat['comp_size'])
                || $stat['comp_size'] < 0) {
                throw new InvalidArgumentException('xlsx 文档压缩条目元数据异常，无法安全读取');
            }
            if (array_key_exists('encryption_method', $stat)
                && (!is_int($stat['encryption_method']) || $stat['encryption_method'] !== ZipArchive::EM_NONE)) {
                throw new InvalidArgumentException('xlsx 文档包含加密条目，无法读取');
            }
            if (str_starts_with($stat['name'], 'xl/') && str_ends_with(strtolower($stat['name']), '.xml')) {
                $totalXmlBytes += $stat['size'];
                if ($totalXmlBytes > self::XLSX_MAX_TOTAL_XML_BYTES) {
                    throw new InvalidArgumentException('xlsx 文档 XML 总解压大小不能超过 32MB');
                }
            }
        }
    }

    private function readXlsxXmlEntry(ZipArchive $zip, string $name, string $label): SimpleXMLElement
    {
        $index = $zip->locateName($name);
        if ($index === false) {
            throw new InvalidArgumentException('xlsx 文档缺少 ' . $label . '，无法读取');
        }

        $stat = $zip->statIndex($index);
        if (!is_array($stat)
            || !isset($stat['size'], $stat['comp_size'])
            || !is_int($stat['size'])
            || !is_int($stat['comp_size'])
            || $stat['size'] < 0
            || $stat['comp_size'] < 0) {
            throw new InvalidArgumentException('xlsx 文档 ' . $label . ' 元数据异常，无法读取');
        }
        if ($stat['size'] > self::XLSX_MAX_XML_PART_BYTES) {
            throw new InvalidArgumentException('xlsx 文档 ' . $label . ' 解压后不能超过 8MB');
        }
        if ($stat['size'] > 0 && $stat['comp_size'] === 0) {
            throw new InvalidArgumentException('xlsx 文档 ' . $label . ' 压缩元数据异常，无法读取');
        }
        if ($stat['comp_size'] > 0 && ($stat['size'] / $stat['comp_size']) > self::XLSX_MAX_XML_COMPRESSION_RATIO) {
            throw new InvalidArgumentException('xlsx 文档 ' . $label . ' XML 压缩比异常，无法读取');
        }

        $stream = $zip->getStream($name);
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('xlsx 文档 ' . $label . ' 内容读取失败');
        }

        try {
            $xml = stream_get_contents($stream, $stat['size'] + 1);
        } finally {
            fclose($stream);
        }
        if (!is_string($xml) || strlen($xml) !== $stat['size']) {
            throw new InvalidArgumentException('xlsx 文档 ' . $label . ' 内容与压缩元数据不一致');
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
        if (!$document instanceof SimpleXMLElement) {
            throw new InvalidArgumentException('xlsx 文档 ' . $label . ' XML 格式错误，无法读取');
        }

        return $document;
    }

    /**
     * @return array<int, array{name:string,path:string}>
     */
    private function resolveXlsxWorksheets(SimpleXMLElement $workbook, SimpleXMLElement $relationships): array
    {
        $relationships->registerXPathNamespace('p', self::PACKAGE_RELATIONSHIP_NAMESPACE);
        $relationshipTargets = [];
        foreach ($relationships->xpath('/p:Relationships/p:Relationship') ?: [] as $relationship) {
            $attributes = $relationship->attributes();
            $id = trim((string)($attributes['Id'] ?? ''));
            $target = trim((string)($attributes['Target'] ?? ''));
            $type = trim((string)($attributes['Type'] ?? ''));
            $targetMode = strtolower(trim((string)($attributes['TargetMode'] ?? '')));
            if ($id === '' || $type === '' || !str_ends_with($type, '/worksheet')) {
                continue;
            }
            if (isset($relationshipTargets[$id])) {
                throw new InvalidArgumentException('xlsx 文档包含重复的工作表关系');
            }
            if ($targetMode === 'external') {
                throw new InvalidArgumentException('xlsx 文档包含外部工作表关系，无法读取');
            }
            $relationshipTargets[$id] = $this->normalizeXlsxWorksheetTarget($target);
        }

        $workbook->registerXPathNamespace('m', self::SPREADSHEET_NAMESPACE);
        $sheetNodes = $workbook->xpath('/m:workbook/m:sheets/m:sheet') ?: [];
        if ($sheetNodes === [] || count($sheetNodes) > self::XLSX_MAX_WORKSHEETS) {
            throw new InvalidArgumentException('xlsx 文档工作表数量异常，无法读取');
        }

        $sheets = [];
        $seenNames = [];
        foreach ($sheetNodes as $sheetNode) {
            $attributes = $sheetNode->attributes();
            $relationshipAttributes = $sheetNode->attributes(self::OFFICE_RELATIONSHIP_NAMESPACE);
            $name = $this->normalizeXlsxSheetName((string)($attributes['name'] ?? ''));
            $relationshipId = trim((string)($relationshipAttributes['id'] ?? ''));
            if ($relationshipId === '' || !isset($relationshipTargets[$relationshipId])) {
                throw new InvalidArgumentException('xlsx 文档工作表关系缺失或无效');
            }
            if (isset($seenNames[$name])) {
                throw new InvalidArgumentException('xlsx 文档包含重复的工作表名称：' . $name);
            }
            $seenNames[$name] = true;
            $sheets[] = ['name' => $name, 'path' => $relationshipTargets[$relationshipId]];
        }

        return $sheets;
    }

    private function normalizeXlsxWorksheetTarget(string $target): string
    {
        $target = str_replace('\\', '/', trim($target));
        if (preg_match('#^worksheets/[A-Za-z0-9._-]+\.xml$#', $target) !== 1) {
            throw new InvalidArgumentException('xlsx 文档工作表关系路径不受支持，无法读取');
        }

        return 'xl/' . $target;
    }

    private function normalizeXlsxSheetName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('xlsx 文档包含无效的工作表名称');
        }

        return $name;
    }

    /** @return array<int, string> */
    private function readXlsxSharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $sharedStrings = $this->readXlsxXmlEntry($zip, 'xl/sharedStrings.xml', 'shared strings');
        $sharedStrings->registerXPathNamespace('m', self::SPREADSHEET_NAMESPACE);
        $nodes = $sharedStrings->xpath('/m:sst/m:si') ?: [];
        if (count($nodes) > self::XLSX_MAX_SHARED_STRINGS) {
            throw new InvalidArgumentException('xlsx 文档共享字符串数量超过 100000 条限制');
        }

        $values = [];
        foreach ($nodes as $node) {
            $values[] = $this->normalizeXlsxCellText($this->xlsxTextContent($node));
        }

        return $values;
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array{text:string,metadata:array<string,mixed>}
     */
    private function renderXlsxWorksheet(SimpleXMLElement $worksheet, array $sharedStrings, string $sheetName): array
    {
        $worksheet->registerXPathNamespace('m', self::SPREADSHEET_NAMESPACE);
        $rowNodes = $worksheet->xpath('/m:worksheet/m:sheetData/m:row') ?: [];
        if (count($rowNodes) > self::XLSX_MAX_ROWS_PER_SHEET) {
            throw new InvalidArgumentException('xlsx 工作表 ' . $sheetName . ' 超过 5000 行限制');
        }

        $lines = [];
        $seenRows = [];
        $cellCount = 0;
        $cellReferences = [];
        $cellReferencesTruncated = false;
        foreach ($rowNodes as $rowNode) {
            $rowNode->registerXPathNamespace('m', self::SPREADSHEET_NAMESPACE);
            $rowAttributes = $rowNode->attributes();
            $rowNumber = trim((string)($rowAttributes['r'] ?? ''));
            if (!ctype_digit($rowNumber) || (int)$rowNumber <= 0 || isset($seenRows[(int)$rowNumber])) {
                throw new InvalidArgumentException('xlsx 工作表 ' . $sheetName . ' 包含无效或重复的行号');
            }
            $seenRows[(int)$rowNumber] = true;

            $cells = [];
            $seenReferences = [];
            foreach ($rowNode->xpath('./m:c') ?: [] as $cellNode) {
                $cellAttributes = $cellNode->attributes();
                $reference = strtoupper(trim((string)($cellAttributes['r'] ?? '')));
                if (preg_match('/^[A-Z]{1,3}[1-9][0-9]{0,6}$/', $reference) !== 1
                    || (int)preg_replace('/^[A-Z]+/', '', $reference) !== (int)$rowNumber
                    || isset($seenReferences[$reference])) {
                    throw new InvalidArgumentException('xlsx 工作表 ' . $sheetName . ' 包含无效或重复的单元格引用');
                }
                $seenReferences[$reference] = true;
                $cellCount++;
                if ($cellCount > self::XLSX_MAX_CELLS_PER_SHEET) {
                    throw new InvalidArgumentException('xlsx 工作表 ' . $sheetName . ' 超过 200000 个单元格限制');
                }
                if (count($cellReferences) < self::XLSX_MAX_METADATA_CELL_REFERENCES) {
                    $cellReferences[] = $reference;
                } else {
                    $cellReferencesTruncated = true;
                }

                $value = $this->readXlsxCellValue($cellNode, $sharedStrings);
                if ($value !== '') {
                    $cells[] = $reference . '：' . $value;
                }
            }
            if ($cells !== []) {
                $lines[] = '第 ' . (int)$rowNumber . ' 行：' . implode(' | ', $cells);
            }
        }

        $mergedRanges = [];
        foreach ($worksheet->xpath('/m:worksheet/m:mergeCells/m:mergeCell') ?: [] as $mergeNode) {
            $reference = strtoupper(trim((string)($mergeNode->attributes()['ref'] ?? '')));
            if (preg_match('/^[A-Z]{1,3}[1-9][0-9]{0,6}:[A-Z]{1,3}[1-9][0-9]{0,6}$/', $reference) !== 1) {
                throw new InvalidArgumentException('xlsx 工作表 ' . $sheetName . ' 包含无效的合并单元格范围');
            }
            $mergedRanges[] = $reference;
            if (count($mergedRanges) > self::XLSX_MAX_MERGED_RANGES_PER_SHEET) {
                throw new InvalidArgumentException('xlsx 工作表 ' . $sheetName . ' 合并单元格范围过多');
            }
        }

        $header = '工作表：' . $sheetName;
        if ($mergedRanges !== []) {
            $header .= "\n合并单元格：" . implode('、', $mergedRanges);
        }

        return [
            'text' => $lines !== [] ? $header . "\n" . implode("\n", $lines) : '',
            'metadata' => [
                'name' => $sheetName,
                'row_count' => count($rowNodes),
                'cell_count' => $cellCount,
                'cell_refs' => $cellReferences,
                'cell_refs_truncated' => $cellReferencesTruncated,
                'merged_ranges' => $mergedRanges,
            ],
        ];
    }

    /** @param array<int, string> $sharedStrings */
    private function readXlsxCellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $cell->registerXPathNamespace('m', self::SPREADSHEET_NAMESPACE);
        $attributes = $cell->attributes();
        $type = strtolower(trim((string)($attributes['t'] ?? '')));
        $formulaNodes = $cell->xpath('./m:f') ?: [];
        if ($formulaNodes !== []) {
            $formula = $this->normalizeXlsxCellText($this->xlsxTextContent($formulaNodes[0]));
            return $formula !== '' ? '=' . $formula : '（公式）';
        }

        if ($type === 'inlinestr') {
            $inlineNodes = $cell->xpath('./m:is') ?: [];
            return $inlineNodes !== [] ? $this->normalizeXlsxCellText($this->xlsxTextContent($inlineNodes[0])) : '';
        }

        $valueNodes = $cell->xpath('./m:v') ?: [];
        $value = $valueNodes !== [] ? trim((string)$valueNodes[0]) : '';
        if ($type === 's') {
            if (!ctype_digit($value) || !array_key_exists((int)$value, $sharedStrings)) {
                throw new InvalidArgumentException('xlsx 文档包含无效的共享字符串索引');
            }
            return $sharedStrings[(int)$value];
        }
        if ($type === 'b') {
            return $value === '1' ? 'TRUE' : ($value === '0' ? 'FALSE' : $this->normalizeXlsxCellText($value));
        }

        return $this->normalizeXlsxCellText($value);
    }

    private function xlsxTextContent(SimpleXMLElement $node): string
    {
        $node->registerXPathNamespace('m', self::SPREADSHEET_NAMESPACE);
        $textNodes = $node->xpath('.//m:t') ?: [];
        if ($textNodes === []) {
            return trim((string)$node);
        }

        return implode('', array_map(static fn(SimpleXMLElement $textNode): string => (string)$textNode, $textNodes));
    }

    private function normalizeXlsxCellText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[ \t]*\n[ \t]*/u', '；', $value) ?? $value;
        $value = trim($value);
        if (mb_strlen($value) > self::XLSX_MAX_CELL_TEXT_CHARS) {
            throw new InvalidArgumentException('xlsx 文档单元格文字内容超过 10000 字限制');
        }

        return $value;
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
