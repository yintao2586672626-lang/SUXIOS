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
    /**
     * @param list<string> $requiredColumns
     */
    public static function assertTableColumns(string $table, array $requiredColumns): void
    {
        self::assertIdentifier($table);
        foreach ($requiredColumns as $column) {
            self::assertIdentifier($column);
        }

        try {
            $actualColumns = Db::name($table)->getTableFields();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf(
                    'Database schema upgrade required: table "%s" is unavailable; run php think db:migrate.',
                    $table
                ),
                0,
                $exception
            );
        }

        $missing = array_values(array_diff($requiredColumns, array_map('strval', $actualColumns)));
        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Database schema upgrade required: table "%s" is missing columns [%s]; run php think db:migrate.',
                $table,
                implode(', ', $missing)
            ));
        }
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new RuntimeException('Invalid database schema identifier.');
        }
    }
}
