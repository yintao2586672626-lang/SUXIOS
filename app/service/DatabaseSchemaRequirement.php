<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;
use Throwable;

/**
 * Read-only schema assertions for business services.
 *
 * Database changes belong to the versioned migration runner. Runtime request
 * paths may verify their prerequisites, but must never repair schema drift.
 */
final class DatabaseSchemaRequirement
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_MISSING = 'missing';
    public const STATUS_UNREADABLE = 'unreadable';

    /**
     * Classify a table probe without converting connection, permission or
     * corrupt-view failures into a false migration claim.
     *
     * @return array{table:string,status:string,error_code:?string}
     */
    public static function inspectTable(string $table): array
    {
        self::assertIdentifier($table);

        try {
            Db::query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            return ['table' => $table, 'status' => self::STATUS_PRESENT, 'error_code' => null];
        } catch (Throwable $exception) {
            $status = self::classifyTableProbeFailure($exception, $table);
            return [
                'table' => $table,
                'status' => $status,
                'error_code' => $status === self::STATUS_MISSING
                    ? 'database_table_missing'
                    : 'database_table_unreadable',
            ];
        }
    }

    /**
     * @return array{table:string,status:string,columns:list<string>,error_code:?string}
     */
    public static function inspectTableColumns(string $table): array
    {
        self::assertIdentifier($table);

        $showFailure = null;
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . $table . '`');
            $columns = array_values(array_filter(array_map(
                static fn(array $row): string => (string)($row['Field'] ?? ''),
                $rows
            ), static fn(string $column): bool => $column !== ''));
            if ($columns !== []) {
                return self::columnInspection($table, self::STATUS_PRESENT, $columns);
            }
        } catch (Throwable $exception) {
            $showFailure = $exception;
        }

        $xinfoFailure = null;
        try {
            $rows = Db::query('PRAGMA table_xinfo(`' . $table . '`)');
            $columns = array_values(array_filter(array_map(
                static fn(array $row): string => (string)($row['name'] ?? ''),
                $rows
            ), static fn(string $column): bool => $column !== ''));
            if ($columns !== []) {
                return self::columnInspection($table, self::STATUS_PRESENT, $columns);
            }
        } catch (Throwable $exception) {
            $xinfoFailure = $exception;
        }

        try {
            $rows = Db::query('PRAGMA table_info(`' . $table . '`)');
            $columns = array_values(array_filter(array_map(
                static fn(array $row): string => (string)($row['name'] ?? ''),
                $rows
            ), static fn(string $column): bool => $column !== ''));
            if ($columns !== []) {
                return self::columnInspection($table, self::STATUS_PRESENT, $columns);
            }
            $tableInspection = self::inspectTable($table);
            return self::columnInspection($table, $tableInspection['status'], []);
        } catch (Throwable $pragmaFailure) {
            $pragmaStatus = self::classifyTableProbeFailure($pragmaFailure, $table);
            $showStatus = $showFailure instanceof Throwable
                ? self::classifyTableProbeFailure($showFailure, $table)
                : self::STATUS_UNREADABLE;
            $xinfoStatus = $xinfoFailure instanceof Throwable
                ? self::classifyTableProbeFailure($xinfoFailure, $table)
                : self::STATUS_UNREADABLE;
            $status = $showStatus === self::STATUS_MISSING
                || $xinfoStatus === self::STATUS_MISSING
                || $pragmaStatus === self::STATUS_MISSING
                ? self::STATUS_MISSING
                : self::STATUS_UNREADABLE;
            return self::columnInspection($table, $status, []);
        }
    }

    /** @param list<string> $columns @return array{table:string,status:string,columns:list<string>,error_code:?string} */
    private static function columnInspection(string $table, string $status, array $columns): array
    {
        return [
            'table' => $table,
            'status' => $status,
            'columns' => array_values(array_unique($columns)),
            'error_code' => $status === self::STATUS_PRESENT
                ? null
                : ($status === self::STATUS_MISSING
                    ? 'database_table_missing'
                    : 'database_table_unreadable'),
        ];
    }

    public static function classifyTableProbeFailure(Throwable $exception, string $table): string
    {
        self::assertIdentifier($table);
        return self::isMissingTableException($exception, $table)
            ? self::STATUS_MISSING
            : self::STATUS_UNREADABLE;
    }

    /**
     * @param list<string> $requiredColumns
     */
    public static function assertTableColumns(string $table, array $requiredColumns): void
    {
        self::assertIdentifier($table);
        foreach ($requiredColumns as $column) {
            self::assertIdentifier($column);
        }

        $inspection = self::inspectTableColumns($table);
        if ($inspection['status'] === self::STATUS_MISSING) {
            throw new RuntimeException(
                sprintf(
                    'Database schema upgrade required: table "%s" is unavailable; run php think db:migrate.',
                    $table
                ),
                503
            );
        }
        if ($inspection['status'] !== self::STATUS_PRESENT) {
            throw new RuntimeException(
                sprintf('Database schema inspection failed for table "%s".', $table),
                503
            );
        }

        $missing = array_values(array_diff($requiredColumns, $inspection['columns']));
        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Database schema upgrade required: table "%s" is missing columns [%s]; run php think db:migrate.',
                $table,
                implode(', ', $missing)
            ));
        }
    }

    private static function isMissingTableException(Throwable $exception, string $table): bool
    {
        $table = strtolower($table);
        $current = $exception;
        do {
            $code = strtoupper(trim((string)$current->getCode()));
            $message = strtolower($current->getMessage());
            if (in_array($code, ['42S02', '42P01', '1146'], true)
                || preg_match(
                    '/table\s+[' . "'`\"" . '](?:[a-z0-9_]+\.)?'
                        . preg_quote($table, '/')
                        . '[' . "'`\"" . ']\s+(?:doesn.t|does not)\s+exist/i',
                    $message
                ) === 1
                || preg_match(
                    '/relation\s+[' . "'`\"" . '](?:[a-z0-9_]+\.)?'
                        . preg_quote($table, '/')
                        . '[' . "'`\"" . ']\s+does not exist/i',
                    $message
                ) === 1
                || preg_match(
                    '/no such table:\s*(?:[a-z0-9_]+\.)?[`"\[]?'
                        . preg_quote($table, '/')
                        . '[`"\]]?(?:\s|$)/i',
                    $message
                ) === 1
            ) {
                return true;
            }
            $current = $current->getPrevious();
        } while ($current instanceof Throwable);

        return false;
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new RuntimeException('Invalid database schema identifier.');
        }
    }
}
