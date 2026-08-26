<?php
declare(strict_types=1);

namespace app\service\concern;

use RuntimeException;

/**
 * Bounded JSON, CSV, and XLSX manual-import parsing.
 */
trait PlatformDataImportParsingConcern
{
    private function parseJsonImportFile(string $path): array
    {
        $content = file_get_contents($path);
        $decoded = json_decode((string)$content, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON import file is invalid.', 422);
        }
        if ($decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1)) {
            return array_values(array_filter($decoded, 'is_array'));
        }
        return $this->extractBusinessRows($decoded);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsvImportFile(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('CSV import file cannot be read.', 422);
        }

        $headers = [];
        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            $cells = array_map(static fn($value): string => trim((string)$value), $cells);
            if ($this->isBlankRow($cells)) {
                continue;
            }
            if ($headers === []) {
                $headers = $this->normalizeHeaderRow($cells);
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $cells[$index] ?? '';
            }
            if (!$this->isBlankRow(array_values($row))) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseXlsxImportFile(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('XLSX import requires PHP ZipArchive extension.', 422);
        }

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('XLSX import file cannot be opened.', 422);
        }

        try {
            $this->validateXlsxArchive($zip);
            $sharedStrings = $this->readXlsxSharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                throw new RuntimeException('XLSX sheet1.xml was not found.', 422);
            }
            $sheet = simplexml_load_string((string)$sheetXml, 'SimpleXMLElement', LIBXML_NONET);
            if (!$sheet) {
                throw new RuntimeException('XLSX sheet1.xml is invalid.', 422);
            }

            $matrix = [];
            $rowCount = 0;
            foreach ($sheet->sheetData->row as $rowNode) {
                $rowCount++;
                if ($rowCount > self::IMPORT_XLSX_MAX_ROWS) {
                    throw new RuntimeException('XLSX import exceeds the maximum row count.', 422);
                }
                if (count($rowNode->c) > self::IMPORT_XLSX_MAX_COLUMNS) {
                    throw new RuntimeException('XLSX import exceeds the maximum column count.', 422);
                }
                $row = [];
                foreach ($rowNode->c as $cellNode) {
                    $ref = (string)($cellNode['r'] ?? '');
                    $columnIndex = $this->xlsxColumnIndex($ref);
                    if ($columnIndex < 0) {
                        continue;
                    }
                    $type = (string)($cellNode['t'] ?? '');
                    $value = (string)($cellNode->v ?? '');
                    if ($type === 's') {
                        $value = $sharedStrings[(int)$value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = (string)($cellNode->is->t ?? '');
                    }
                    $row[$columnIndex] = trim($value);
                }
                if (!$this->isBlankRow($row)) {
                    ksort($row);
                    $matrix[] = $row;
                }
            }
        } finally {
            $zip->close();
        }

        if (empty($matrix)) {
            return [];
        }

        $headers = $this->normalizeHeaderRow(array_values(array_shift($matrix)));
        $rows = [];
        foreach ($matrix as $cells) {
            $cells = array_values($cells);
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $cells[$index] ?? '';
            }
            if (!$this->isBlankRow(array_values($row))) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function validateXlsxArchive(\ZipArchive $zip): void
    {
        if ($zip->numFiles <= 0) {
            throw new RuntimeException('XLSX import archive is empty.', 422);
        }
        if ($zip->numFiles > self::IMPORT_XLSX_MAX_ARCHIVE_ENTRIES) {
            throw new RuntimeException('XLSX import archive contains too many entries.', 422);
        }

        $totalUncompressedBytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) {
                throw new RuntimeException('XLSX import archive entry is invalid.', 422);
            }

            $entryName = str_replace('\\', '/', trim((string)($stat['name'] ?? '')));
            if ($entryName === ''
                || str_starts_with($entryName, '/')
                || preg_match('/^[A-Za-z]:\//', $entryName) === 1
                || in_array('..', explode('/', $entryName), true)
            ) {
                throw new RuntimeException('XLSX import archive contains an unsafe path.', 422);
            }

            if (isset($stat['encryption_method'])
                && (int)$stat['encryption_method'] !== \ZipArchive::EM_NONE
            ) {
                throw new RuntimeException('Encrypted XLSX import entries are not supported.', 422);
            }

            $entryBytes = max(0, (int)($stat['size'] ?? 0));
            if ($entryBytes > self::IMPORT_XLSX_MAX_ENTRY_BYTES) {
                throw new RuntimeException('XLSX import archive entry is too large.', 422);
            }
            $totalUncompressedBytes += $entryBytes;
            if ($totalUncompressedBytes > self::IMPORT_XLSX_MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('XLSX import archive expands beyond the allowed size.', 422);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function readXlsxSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $shared = simplexml_load_string((string)$xml, 'SimpleXMLElement', LIBXML_NONET);
        if (!$shared) {
            return [];
        }

        $strings = [];
        foreach ($shared->si as $item) {
            if (count($strings) >= self::IMPORT_XLSX_MAX_SHARED_STRINGS) {
                throw new RuntimeException('XLSX import contains too many shared strings.', 422);
            }
            if (isset($item->t)) {
                $strings[] = (string)$item->t;
                continue;
            }
            $text = '';
            foreach ($item->r as $run) {
                $text .= (string)($run->t ?? '');
            }
            $strings[] = $text;
        }
        return $strings;
    }

    private function xlsxColumnIndex(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            return -1;
        }
        $letters = strtoupper($matches[1]);
        $index = 0;
        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
            if ($index > self::IMPORT_XLSX_MAX_COLUMNS) {
                return -1;
            }
        }
        return $index - 1;
    }

    /**
     * @param array<int, mixed> $cells
     * @return array<int, string>
     */
    private function normalizeHeaderRow(array $cells): array
    {
        return array_map(static function ($value): string {
            $header = trim((string)$value);
            return preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        }, $cells);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }
        return true;
    }
}
