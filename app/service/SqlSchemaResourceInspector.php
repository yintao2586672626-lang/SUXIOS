<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class SqlSchemaResourceInspector
{
    /**
     * @param list<string> $files Project-relative SQL files.
     * @return array{schema: array<string,list<string>>, creates: array<string,string>, columns: array<string,array<string,string>>, indexes: array<string,array<string,string>>}
     */
    public static function parse(string $root, array $files): array
    {
        $schema = [];
        $creates = [];
        $columns = [];
        $indexes = [];

        foreach ($files as $file) {
            $sql = self::readText($root, $file);
            $lines = self::splitLines($sql);
            $currentTable = null;
            $createLines = [];

            foreach ($lines as $line) {
                if (preg_match('/^\s*CREATE TABLE(?: IF NOT EXISTS)?\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\s*\(/i', $line, $match)) {
                    $currentTable = $match[1];
                    $createLines = [$line];
                    continue;
                }

                if ($currentTable === null) {
                    continue;
                }

                $createLines[] = $line;
                if (preg_match('/^\s*\)\s*(?:ENGINE\s*=.*)?;?\s*$/i', $line)) {
                    $table = $currentTable;
                    if (!isset($creates[$table])) {
                        $createSql = implode(PHP_EOL, $createLines) . ';';
                        $creates[$table] = preg_replace(
                            '/CREATE TABLE\s+`/i',
                            'CREATE TABLE IF NOT EXISTS `',
                            $createSql,
                            1
                        ) ?? $createSql;
                    }

                    $currentTable = null;
                    continue;
                }
            }

            if (preg_match_all(
                '/CREATE TABLE(?: IF NOT EXISTS)?\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\s*\((.*?)\)\s*(?:ENGINE\s*=.*?)?;/is',
                $sql,
                $createMatches,
                PREG_SET_ORDER
            )) {
                foreach ($createMatches as $createMatch) {
                    $table = $createMatch[1];
                    self::recordCreateResources(
                        $table,
                        self::splitDefinitions($createMatch[2]),
                        $schema,
                        $columns,
                        $indexes
                    );
                }
            }

            foreach (SchemaVersionService::splitSqlStatements($sql) as $statement) {
                if (preg_match(
                    '/^ALTER TABLE\s+(?:`(?P<quoted>[^`]+)`|(?P<bare>[A-Za-z_][A-Za-z0-9_]*))(?P<body>.+)$/is',
                    $statement,
                    $alter
                )) {
                    $table = ($alter['quoted'] ?? '') !== '' ? $alter['quoted'] : $alter['bare'];
                    self::recordAlterResources($table, $alter['body'], $schema, $columns, $indexes);
                }
            }

            foreach (self::preparedAlterStatements($sql) as $statement) {
                if (preg_match(
                    '/^ALTER\s+TABLE\s+(?:`([^`]+)`|([A-Za-z_][A-Za-z0-9_]*))\s+(?P<body>.+)$/is',
                    $statement,
                    $alter
                )) {
                    $table = $alter[1] !== '' ? $alter[1] : $alter[2];
                    self::recordAlterResources($table, $alter['body'], $schema, $columns, $indexes);
                }
            }

            if (preg_match_all(
                '/CREATE\s+(UNIQUE\s+)?INDEX(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\s+ON\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\s*(\([^;]+\))/i',
                $sql,
                $indexMatches,
                PREG_SET_ORDER
            )) {
                foreach ($indexMatches as $indexMatch) {
                    $prefix = trim((string)$indexMatch[1]) !== '' ? 'ADD UNIQUE INDEX' : 'ADD INDEX';
                    $indexes[$indexMatch[3]][$indexMatch[2]] = $prefix
                        . ' IF NOT EXISTS `' . $indexMatch[2] . '` ' . trim($indexMatch[4]);
                }
            }
        }

        ksort($schema);
        return [
            'schema' => $schema,
            'creates' => $creates,
            'columns' => $columns,
            'indexes' => $indexes,
        ];
    }

    private static function readText(string $root, string $relative): string
    {
        $path = rtrim($root, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException("Cannot read SQL resource: {$relative}");
        }
        if (str_starts_with($raw, "\xFF\xFE") && function_exists('iconv')) {
            $converted = iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
            if (is_string($converted)) {
                $raw = $converted;
            }
        }
        return str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
    }

    /** @return list<string> */
    private static function preparedAlterStatements(string $sql): array
    {
        if (
            preg_match('/\bPREPARE\s+[A-Za-z_][A-Za-z0-9_]*\s+FROM\s+@[A-Za-z_][A-Za-z0-9_]*\s*;/i', $sql) !== 1
            || preg_match('/\bEXECUTE\s+[A-Za-z_][A-Za-z0-9_]*\s*;/i', $sql) !== 1
        ) {
            return [];
        }
        if (preg_match_all("/'((?:''|[^'])*\\bALTER\\s+TABLE\\b(?:''|[^'])*)'/is", $sql, $matches) < 1) {
            return [];
        }

        $statements = [];
        foreach ($matches[1] as $statement) {
            $decoded = trim(str_replace("''", "'", (string)$statement));
            if (preg_match('/^ALTER\s+TABLE\b/i', $decoded) === 1) {
                $statements[] = $decoded;
            }
        }
        return array_values(array_unique($statements));
    }

    private static function recordAlterResources(
        string $table,
        string $body,
        array &$schema,
        array &$columns,
        array &$indexes
    ): void {
        foreach (self::splitDefinitions($body) as $part) {
            $definition = self::trimSqlLine($part);
            if (preg_match(
                '/^ADD COLUMN(?: IF NOT EXISTS)?\s+(?:`([^`]+)`|([A-Za-z_][A-Za-z0-9_]*))\s+(.+)$/is',
                $definition,
                $column
            )) {
                $name = $column[1] !== '' ? $column[1] : $column[2];
                if (!isset($columns[$table][$name])) {
                    $columns[$table][$name] = '`' . $name . '` ' . rtrim($column[3], ", \t;");
                    $schema[$table][] = $name;
                }
            } elseif (preg_match(
                '/^MODIFY(?: COLUMN)?\s+(?:`([^`]+)`|([A-Za-z_][A-Za-z0-9_]*))\s+(.+)$/is',
                $definition,
                $column
            )) {
                $name = $column[1] !== '' ? $column[1] : $column[2];
                $columns[$table][$name] = '`' . $name . '` ' . rtrim($column[3], ", \t;");
                $schema[$table][] = $name;
            } elseif (preg_match('/^ADD INDEX(?: IF NOT EXISTS)?\s+`([^`]+)`\s*(\(.+\))$/is', $definition, $index)) {
                $indexes[$table][$index[1]] ??= 'ADD INDEX IF NOT EXISTS `' . $index[1] . '` ' . $index[2];
            } elseif (preg_match('/^ADD UNIQUE (?:KEY|INDEX)(?: IF NOT EXISTS)?\s+`([^`]+)`\s*(\(.+\))$/is', $definition, $index)) {
                $indexes[$table][$index[1]] ??= 'ADD UNIQUE INDEX IF NOT EXISTS `' . $index[1] . '` ' . $index[2];
            }
        }
        if (isset($schema[$table])) {
            $schema[$table] = array_values(array_unique($schema[$table]));
        }
    }

    /**
     * @param list<string> $definitions
     * @param array<string,list<string>> $schema
     * @param array<string,array<string,string>> $columns
     * @param array<string,array<string,string>> $indexes
     */
    private static function recordCreateResources(
        string $table,
        array $definitions,
        array &$schema,
        array &$columns,
        array &$indexes
    ): void {
        foreach ($definitions as $bodyLine) {
            $definition = self::trimSqlLine($bodyLine);
            if (preg_match('/^UNIQUE\s+(?:KEY|INDEX)\s+`?([^`\s]+)`?\s*(\(.+\))$/i', $definition, $index)) {
                $indexes[$table][$index[1]] ??= 'ADD UNIQUE INDEX IF NOT EXISTS `' . $index[1] . '` ' . $index[2];
            } elseif (preg_match('/^(?:(?:FULLTEXT|SPATIAL)\s+)?(?:KEY|INDEX)\s+`?([^`\s]+)`?\s*(\(.+\))$/i', $definition, $index)) {
                $indexes[$table][$index[1]] ??= 'ADD INDEX IF NOT EXISTS `' . $index[1] . '` ' . $index[2];
            } elseif (preg_match('/^(?:`([^`]+)`|([A-Za-z_][A-Za-z0-9_]*))\s+/', $definition, $column)) {
                $name = $column[1] !== '' ? $column[1] : $column[2];
                if (in_array(strtoupper($name), [
                    'PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FULLTEXT', 'SPATIAL',
                    'CONSTRAINT', 'FOREIGN', 'CHECK',
                ], true)) {
                    continue;
                }
                if (!isset($columns[$table][$name])) {
                    $columns[$table][$name] = $definition;
                    $schema[$table][] = $name;
                }
            }
        }
        $schema[$table] = array_values(array_unique($schema[$table] ?? []));
    }

    /** @return list<string> */
    private static function splitDefinitions(string $body): array
    {
        $definitions = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($body);
        for ($index = 0; $index < $length; $index++) {
            $char = $body[$index];
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote) {
                    $next = $index + 1 < $length ? $body[$index + 1] : '';
                    if ($next === $quote) {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $quote = null;
                    }
                } elseif ($char === '\\' && $index + 1 < $length) {
                    $buffer .= $body[++$index];
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')' && $depth > 0) {
                $depth--;
            }
            if ($char === ',' && $depth === 0) {
                if (trim($buffer) !== '') {
                    $definitions[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $definitions[] = trim($buffer);
        }
        return $definitions;
    }

    /** @return list<string> */
    private static function splitLines(string $text): array
    {
        return preg_split("/\r\n|\n|\r/", $text) ?: [];
    }

    private static function trimSqlLine(string $line): string
    {
        return rtrim(trim($line), ", \t");
    }
}
