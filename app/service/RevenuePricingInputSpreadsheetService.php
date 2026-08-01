<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class RevenuePricingInputSpreadsheetService
{
    public const PREFERRED_SHEET_NAME = 'pricing-input-intake';

    private const SPREADSHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const OFFICE_RELATIONSHIP_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_RELATIONSHIP_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const MAX_FILE_BYTES = 10 * 1024 * 1024;
    private const MAX_ARCHIVE_ENTRIES = 256;
    private const MAX_ENTRY_BYTES = 8 * 1024 * 1024;
    private const MAX_UNCOMPRESSED_BYTES = 20 * 1024 * 1024;
    private const MAX_COMPRESSION_RATIO = 200;
    private const MAX_ROWS = 5000;
    private const MAX_COLUMNS = 64;
    private const MAX_SHARED_STRINGS = 100000;
    private const MAX_CELL_CHARS = 16384;
    private const DATE_HEADERS = ['business_date', 'forecast_date', 'analysis_date'];

    /**
     * @param array<int, string> $requiredHeaders
     * @return array{
     *   input_type:string,
     *   sheet_name:string,
     *   date_system:string,
     *   headers:array<int,string>,
     *   rows:array<int,array<string,string>>,
     *   rows_with_lines:array<int,array{line:int,row:array<string,string>}>,
     *   row_count:int,
     *   sha256:string
     * }
     */
    public function read(string $path, array $requiredHeaders = []): array
    {
        $this->assertInputFile($path);
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('XLSX input requires the PHP ZipArchive extension.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new InvalidArgumentException('XLSX input cannot be opened or is not a valid archive: ' . $path);
        }

        try {
            $this->validateArchive($zip);
            $workbook = $this->readXmlEntry($zip, 'xl/workbook.xml', 'workbook');
            $relationships = $this->readXmlEntry($zip, 'xl/_rels/workbook.xml.rels', 'workbook relationships');
            $sheet = $this->selectWorksheet($workbook, $relationships);
            $sharedStrings = $this->readSharedStrings($zip);
            $worksheet = $this->readXmlEntry($zip, $sheet['path'], 'worksheet ' . $sheet['name']);
            $date1904 = $this->uses1904DateSystem($workbook);
            $parsed = $this->readRows($worksheet, $sharedStrings, $sheet['name'], $date1904, $requiredHeaders);
        } finally {
            $zip->close();
        }

        return [
            'input_type' => 'xlsx',
            'sheet_name' => $sheet['name'],
            'date_system' => $date1904 ? '1904' : '1900',
            'headers' => $parsed['headers'],
            'rows' => array_column($parsed['rows_with_lines'], 'row'),
            'rows_with_lines' => $parsed['rows_with_lines'],
            'row_count' => count($parsed['rows_with_lines']),
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    private function assertInputFile(string $path): void
    {
        if ($path === '') {
            throw new InvalidArgumentException('Missing --xlsx-file=<operator-intake-xlsx-path>.');
        }
        if (!is_file($path)) {
            throw new InvalidArgumentException('XLSX input file does not exist: ' . $path);
        }
        if (strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new InvalidArgumentException('XLSX input must use the .xlsx extension: ' . $path);
        }
        $size = filesize($path);
        if (!is_int($size) || $size <= 0) {
            throw new InvalidArgumentException('XLSX input file is empty: ' . $path);
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('XLSX input exceeds the 10 MB file limit.');
        }
    }

    private function validateArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles <= 0) {
            throw new InvalidArgumentException('XLSX archive is empty.');
        }
        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new InvalidArgumentException('XLSX archive contains too many entries.');
        }

        $totalBytes = 0;
        $seen = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) {
                throw new InvalidArgumentException('XLSX archive entry metadata is invalid.');
            }
            $name = str_replace('\\', '/', trim((string)($stat['name'] ?? '')));
            if ($name === ''
                || str_starts_with($name, '/')
                || preg_match('/^[A-Za-z]:\//', $name) === 1
                || in_array('..', explode('/', $name), true)
            ) {
                throw new InvalidArgumentException('XLSX archive contains an unsafe path.');
            }
            $identity = strtolower($name);
            if (isset($seen[$identity])) {
                throw new InvalidArgumentException('XLSX archive contains duplicate entries: ' . $name);
            }
            $seen[$identity] = true;

            if (isset($stat['encryption_method']) && (int)$stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw new InvalidArgumentException('Encrypted XLSX entries are not supported.');
            }

            $size = max(0, (int)($stat['size'] ?? 0));
            $compressedSize = max(0, (int)($stat['comp_size'] ?? 0));
            if ($size > self::MAX_ENTRY_BYTES) {
                throw new InvalidArgumentException('XLSX archive entry exceeds the 8 MB limit: ' . $name);
            }
            if ($size > 0 && $compressedSize === 0) {
                throw new InvalidArgumentException('XLSX archive entry compression metadata is invalid: ' . $name);
            }
            if ($compressedSize > 0 && ($size / $compressedSize) > self::MAX_COMPRESSION_RATIO) {
                throw new InvalidArgumentException('XLSX archive entry compression ratio is unsafe: ' . $name);
            }
            $totalBytes += $size;
            if ($totalBytes > self::MAX_UNCOMPRESSED_BYTES) {
                throw new InvalidArgumentException('XLSX archive expands beyond the 20 MB limit.');
            }
        }

        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $requiredEntry) {
            if (!isset($seen[strtolower($requiredEntry)])) {
                throw new InvalidArgumentException('XLSX archive is missing required entry: ' . $requiredEntry);
            }
        }
    }

    private function readXmlEntry(ZipArchive $zip, string $name, string $label): SimpleXMLElement
    {
        $xml = $zip->getFromName($name);
        if (!is_string($xml) || $xml === '') {
            throw new InvalidArgumentException('XLSX ' . $label . ' XML is missing: ' . $name);
        }
        if (strlen($xml) > self::MAX_ENTRY_BYTES) {
            throw new InvalidArgumentException('XLSX ' . $label . ' XML exceeds the allowed size.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA);
            if (!$document instanceof SimpleXMLElement) {
                throw new InvalidArgumentException('XLSX ' . $label . ' XML is invalid.');
            }
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return array{name:string,path:string}
     */
    private function selectWorksheet(SimpleXMLElement $workbook, SimpleXMLElement $relationships): array
    {
        $relationships->registerXPathNamespace('p', self::PACKAGE_RELATIONSHIP_NAMESPACE);
        $targets = [];
        foreach ($relationships->xpath('/p:Relationships/p:Relationship') ?: [] as $relationship) {
            $attributes = $relationship->attributes();
            $id = trim((string)($attributes['Id'] ?? ''));
            $target = trim((string)($attributes['Target'] ?? ''));
            $type = strtolower(trim((string)($attributes['Type'] ?? '')));
            $targetMode = strtolower(trim((string)($attributes['TargetMode'] ?? '')));
            if ($id === '' || $target === '' || $targetMode === 'external' || !str_ends_with($type, '/worksheet')) {
                continue;
            }
            $targets[$id] = $this->normalizeWorksheetTarget($target);
        }

        $workbook->registerXPathNamespace('m', self::SPREADSHEET_NAMESPACE);
        $sheets = [];
        $names = [];
        foreach ($workbook->xpath('/m:workbook/m:sheets/m:sheet') ?: [] as $sheetNode) {
            $attributes = $sheetNode->attributes();
            $relationshipAttributes = $sheetNode->attributes(self::OFFICE_RELATIONSHIP_NAMESPACE);
            $name = trim((string)($attributes['name'] ?? ''));
            $relationshipId = trim((string)($relationshipAttributes['id'] ?? ''));
            if ($name === '' || $relationshipId === '' || !isset($targets[$relationshipId])) {
                throw new InvalidArgumentException('XLSX workbook contains an invalid worksheet relationship.');
            }
            $nameKey = strtolower($name);
            if (isset($names[$nameKey])) {
                throw new InvalidArgumentException('XLSX workbook contains duplicate worksheet names: ' . $name);
            }
            $names[$nameKey] = true;
            $sheets[] = ['name' => $name, 'path' => $targets[$relationshipId]];
        }

        if ($sheets === []) {
            throw new InvalidArgumentException('XLSX workbook does not contain a worksheet.');
        }
        foreach ($sheets as $sheet) {
            if (strcasecmp($sheet['name'], self::PREFERRED_SHEET_NAME) === 0) {
                return $sheet;
            }
        }
        if (count($sheets) === 1) {
            return $sheets[0];
        }

        throw new InvalidArgumentException(
            'XLSX workbook has multiple worksheets; name the intake sheet "' . self::PREFERRED_SHEET_NAME . '". Found: '
            . implode(', ', array_column($sheets, 'name'))
        );
    }

    private function normalizeWorksheetTarget(string $target): string
    {
        $target = str_replace('\\', '/', trim($target));
        $target = ltrim($target, '/');
        if ($target === '' || in_array('..', explode('/', $target), true)) {
            throw new InvalidArgumentException('XLSX worksheet relationship contains an unsafe path.');
        }
        if (!str_starts_with(strtolower($target), 'xl/')) {
            $target = 'xl/' . $target;
        }
        if (preg_match('#^xl/worksheets/[^/]+\.xml$#i', $target) !== 1) {
            throw new InvalidArgumentException('XLSX worksheet relationship target is unsupported: ' . $target);
        }
        return $target;
    }

    /** @return array<int, string> */
    private function readSharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $document = $this->readXmlEntry($zip, 'xl/sharedStrings.xml', 'shared strings');
        $document->registerXPathNamespace('m', self::SPREADSHEET_NAMESPACE);
        $strings = [];
        foreach ($document->xpath('/m:sst/m:si') ?: [] as $item) {
            if (count($strings) >= self::MAX_SHARED_STRINGS) {
                throw new InvalidArgumentException('XLSX contains too many shared strings.');
            }
            $strings[] = $this->textContent($item);
        }
        return $strings;
    }

    private function uses1904DateSystem(SimpleXMLElement $workbook): bool
    {
        $workbook->registerXPathNamespace('m', self::SPREADSHEET_NAMESPACE);
        $properties = $workbook->xpath('/m:workbook/m:workbookPr') ?: [];
        if ($properties === []) {
            return false;
        }
        $value = strtolower(trim((string)($properties[0]->attributes()['date1904'] ?? '')));
        return in_array($value, ['1', 'true'], true);
    }

    /**
     * @param array<int, string> $sharedStrings
     * @param array<int, string> $requiredHeaders
     * @return array{headers:array<int,string>,rows_with_lines:array<int,array{line:int,row:array<string,string>}>}
     */
    private function readRows(
        SimpleXMLElement $worksheet,
        array $sharedStrings,
        string $sheetName,
        bool $date1904,
        array $requiredHeaders
    ): array {
        $worksheet->registerXPathNamespace('m', self::SPREADSHEET_NAMESPACE);
        $rowNodes = $worksheet->xpath('/m:worksheet/m:sheetData/m:row') ?: [];
        if (count($rowNodes) > self::MAX_ROWS) {
            throw new InvalidArgumentException('XLSX worksheet exceeds the 5,000 row limit: ' . $sheetName);
        }

        $matrix = [];
        $fallbackLine = 0;
        $seenLines = [];
        foreach ($rowNodes as $rowNode) {
            $rowNode->registerXPathNamespace('m', self::SPREADSHEET_NAMESPACE);
            $fallbackLine++;
            $line = (int)($rowNode->attributes()['r'] ?? $fallbackLine);
            if ($line <= 0 || $line > 1048576) {
                throw new InvalidArgumentException('XLSX worksheet contains an invalid row number.');
            }
            if (isset($seenLines[$line])) {
                throw new InvalidArgumentException('XLSX worksheet contains a duplicate row number: ' . $line . '.');
            }
            $seenLines[$line] = true;
            $cells = [];
            foreach ($rowNode->xpath('./m:c') ?: [] as $cellNode) {
                $reference = strtoupper(trim((string)($cellNode->attributes()['r'] ?? '')));
                if (preg_match('/^[A-Z]+([1-9]\d*)$/', $reference, $matches) !== 1 || (int)$matches[1] !== $line) {
                    throw new InvalidArgumentException('XLSX cell reference does not match its worksheet row: ' . $reference . '.');
                }
                $column = $this->columnIndex($reference);
                if ($column < 0 || $column >= self::MAX_COLUMNS) {
                    throw new InvalidArgumentException('XLSX worksheet exceeds the 64 column limit at ' . $reference . '.');
                }
                if (array_key_exists($column, $cells)) {
                    throw new InvalidArgumentException('XLSX worksheet contains duplicate cell references at ' . $reference . '.');
                }
                $cells[$column] = $this->cellValue($cellNode, $sharedStrings, $sheetName, $reference);
            }
            if ($this->hasValue($cells)) {
                $matrix[] = ['line' => $line, 'cells' => $cells];
            }
        }

        if ($matrix === []) {
            throw new InvalidArgumentException('XLSX worksheet is empty: ' . $sheetName);
        }
        $headerItem = array_shift($matrix);
        $headers = [];
        for ($column = 0; $column < self::MAX_COLUMNS; $column++) {
            $headers[$column] = strtolower(trim((string)preg_replace('/^\xEF\xBB\xBF/', '', (string)($headerItem['cells'][$column] ?? ''))));
        }
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }
        $this->assertHeaders($headers, $requiredHeaders, $sheetName);

        $rows = [];
        foreach ($matrix as $item) {
            $row = [];
            foreach ($headers as $column => $header) {
                if ($header === '') {
                    continue;
                }
                $value = trim((string)($item['cells'][$column] ?? ''));
                if (in_array($header, self::DATE_HEADERS, true)) {
                    $value = $this->normalizeDateValue($value, $date1904);
                }
                $row[$header] = $value;
            }
            if ($this->hasValue($row)) {
                $rows[] = ['line' => $item['line'], 'row' => $row];
            }
        }

        return ['headers' => array_values($headers), 'rows_with_lines' => $rows];
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, string> $requiredHeaders
     */
    private function assertHeaders(array $headers, array $requiredHeaders, string $sheetName): void
    {
        $seen = [];
        $duplicates = [];
        foreach ($headers as $header) {
            if ($header === '') {
                continue;
            }
            if (isset($seen[$header]) && !in_array($header, $duplicates, true)) {
                $duplicates[] = $header;
            }
            $seen[$header] = true;
        }
        if ($duplicates !== []) {
            throw new InvalidArgumentException('XLSX header has duplicate columns: ' . implode(', ', $duplicates));
        }

        $required = array_values(array_unique(array_filter(array_map(
            static fn(mixed $header): string => strtolower(trim((string)$header)),
            $requiredHeaders
        ), static fn(string $header): bool => $header !== '')));
        $missing = array_values(array_diff($required, array_keys($seen)));
        if ($missing !== []) {
            throw new InvalidArgumentException(
                'XLSX header missing required columns: ' . implode(', ', $missing)
                . '. Use the "' . self::PREFERRED_SHEET_NAME . '" template. Sheet: ' . $sheetName
            );
        }
    }

    /** @param array<int, string> $sharedStrings */
    private function cellValue(SimpleXMLElement $cell, array $sharedStrings, string $sheetName, string $reference): string
    {
        $cell->registerXPathNamespace('m', self::SPREADSHEET_NAMESPACE);
        if (($cell->xpath('./m:f') ?: []) !== []) {
            throw new InvalidArgumentException(
                'XLSX formulas are not accepted as operator evidence. Replace the formula with its verified value at '
                . $sheetName . '!' . $reference . '.'
            );
        }

        $type = strtolower(trim((string)($cell->attributes()['t'] ?? '')));
        if ($type === 'e') {
            throw new InvalidArgumentException('XLSX contains an Excel error cell at ' . $sheetName . '!' . $reference . '.');
        }
        if ($type === 'inlinestr') {
            $inlineNodes = $cell->xpath('./m:is') ?: [];
            $value = $inlineNodes === [] ? '' : $this->textContent($inlineNodes[0]);
        } else {
            $valueNodes = $cell->xpath('./m:v') ?: [];
            $value = $valueNodes === [] ? '' : (string)$valueNodes[0];
            if ($type === 's') {
                if (!ctype_digit($value) || !array_key_exists((int)$value, $sharedStrings)) {
                    throw new InvalidArgumentException('XLSX shared-string reference is invalid at ' . $sheetName . '!' . $reference . '.');
                }
                $value = $sharedStrings[(int)$value];
            } elseif ($type === 'b') {
                if (!in_array($value, ['0', '1'], true)) {
                    throw new InvalidArgumentException('XLSX boolean cell is invalid at ' . $sheetName . '!' . $reference . '.');
                }
            }
        }

        if (strlen($value) > self::MAX_CELL_CHARS) {
            throw new InvalidArgumentException('XLSX cell exceeds the 16,384 character limit at ' . $sheetName . '!' . $reference . '.');
        }
        return trim($value);
    }

    private function textContent(SimpleXMLElement $node): string
    {
        $node->registerXPathNamespace('m', self::SPREADSHEET_NAMESPACE);
        $text = '';
        foreach ($node->xpath('.//m:t') ?: [] as $textNode) {
            $text .= (string)$textNode;
        }
        return $text;
    }

    private function columnIndex(string $reference): int
    {
        if (preg_match('/^([A-Z]+)[1-9]\d*$/', $reference, $matches) !== 1) {
            return -1;
        }
        $index = 0;
        foreach (str_split($matches[1]) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }
        return $index - 1;
    }

    private function normalizeDateValue(string $value, bool $date1904): string
    {
        if ($value === '' || $value === '0' || preg_match('/^\d+(?:\.\d+)?$/', $value) !== 1) {
            return $value;
        }
        $serial = (float)$value;
        if ($serial < 1 || $serial > 100000) {
            return $value;
        }
        $days = (int)floor($serial);
        $timezone = new DateTimeZone('UTC');
        $base = new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30', $timezone);
        return $base->modify('+' . $days . ' days')->format('Y-m-d');
    }

    /** @param array<mixed> $values */
    private function hasValue(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }
        return false;
    }
}
