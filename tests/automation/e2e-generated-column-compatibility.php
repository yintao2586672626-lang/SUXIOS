<?php

declare(strict_types=1);

use think\facade\Db;

/** @param array<string, mixed> $databaseSafety @return array<string, mixed> */
function e2eVerifyGeneratedColumnCompatibility(array $databaseSafety): array
{
    if (($databaseSafety['dedicated_database'] ?? false) !== true) {
        throw new RuntimeException('Generated-column verification requires a dedicated test database');
    }
    $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20260717_add_online_data_history_projection.sql');
    if (!is_string($migration) || !preg_match(
        '/ADD COLUMN IF NOT EXISTS `history_status`.*?GENERATED ALWAYS AS \((.*?)\)\s*STORED/s',
        $migration,
        $matches
    )) {
        throw new RuntimeException('Cannot resolve the repository history_status generation expression');
    }
    $expression = trim($matches[1]);
    $suffix = bin2hex(random_bytes(6));
    $created = [];
    $cases = [];
    $check = static function (bool $allowed, string $message): void {
        if (!$allowed) {
            throw new RuntimeException('Generated-column verification failed: ' . $message);
        }
    };
    try {
        foreach (['writable', 'generated'] as $schema) {
            $table = 'codex_e2e_columns_' . $schema . '_' . $suffix;
            $statusColumn = $schema === 'generated'
                ? "VARCHAR(20) GENERATED ALWAYS AS ({$expression}) STORED"
                : "VARCHAR(20) NOT NULL DEFAULT 'unverified'";
            Db::execute("CREATE TABLE `{$table}` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `tenant_id` BIGINT NOT NULL,
                `system_hotel_id` BIGINT NOT NULL,
                `data_source_id` BIGINT NOT NULL,
                `sync_task_id` BIGINT NOT NULL,
                `validation_status` VARCHAR(30) NOT NULL,
                `readback_verified` TINYINT NOT NULL DEFAULT 0,
                `history_status` {$statusColumn},
                `verified_projection` TINYINT GENERATED ALWAYS AS (`readback_verified`) STORED,
                `create_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $created[] = $table;
            $metadata = e2eTableColumnMetadata($table);
            $check(isset($metadata['history_status']), 'status column must remain queryable');
            $generated = trim((string)$metadata['history_status']['GenerationExpression']) !== '';
            $check($generated === ($schema === 'generated'), 'actual expression identifies the correct schema');
            $owners = ['tenant_id' => 71, 'system_hotel_id' => 81, 'data_source_id' => 91, 'sync_task_id' => 101];
            $payload = e2eFilterPayload($table, $owners + [
                'validation_status' => 'pending_verification', 'readback_verified' => 0,
                'history_status' => 'pending', 'verified_projection' => 99,
                'create_time' => '2026-09-30 00:00:00', 'unknown_column' => 'reject',
            ]);
            $check(!array_key_exists('verified_projection', $payload) && !array_key_exists('unknown_column', $payload), 'derived/unknown columns cannot be written');
            $check(array_key_exists('history_status', $payload) === !$generated, 'status writes must follow actual metadata');
            $check(isset($payload['create_time']), 'ordinary generated defaults remain writable');
            $id = (int)Db::name($table)->insertGetId($payload);
            $row = Db::name($table)->where('id', $id)->find();
            $check($id > 0 && is_array($row), 'pending row must be persisted and read back');
            foreach ($owners as $key => $value) {
                $check((int)$row[$key] === $value, 'pending row ownership must read back exactly');
            }
            $check((int)$row['readback_verified'] === 0 && (string)$row['history_status'] !== 'success', 'pending rows cannot be successful');
            $negativeStates = 0;
            foreach ([['failed', 1, 'failed'], ['warning', 1, 'partial'], ['unverified', 1, 'unverified'], ['verified', 0, 'unverified']] as [$validation, $readback, $status]) {
                $write = e2eFilterPayload($table, [
                    'validation_status' => $validation, 'readback_verified' => $readback,
                    'history_status' => $status, 'verified_projection' => 99,
                ]);
                $check(array_key_exists('history_status', $write) === !$generated, 'updates must also omit generated status');
                Db::name($table)->where('id', $id)->update($write);
                $row = Db::name($table)->where('id', $id)->find();
                $check((string)$row['history_status'] === $status, 'failed/partial/unverified states must not become success');
                $negativeStates++;
            }
            Db::name($table)->where('id', $id)->update(e2eFilterPayload($table, [
                'validation_status' => 'verified', 'readback_verified' => 1, 'history_status' => 'success',
            ]));
            $row = Db::name($table)->where('id', $id)->find();
            $check((string)$row['validation_status'] === 'verified' && (int)$row['readback_verified'] === 1
                && (string)$row['history_status'] === 'success' && (int)$row['verified_projection'] === 1,
                'success must follow verified validation and exact readback');
            foreach ($owners as $key => $value) {
                $check((int)$row[$key] === $value, 'confirmed row ownership must remain exact');
            }
            $cases[] = ['schema' => $schema, 'extra' => $metadata['history_status']['Extra'],
                'has_generation_expression' => $generated, 'negative_states_verified' => $negativeStates, 'status' => 'passed'];
        }
    } finally {
        // Only successfully created, unpredictable, fixed-prefix test tables are removed.
        foreach ($created as $table) {
            Db::execute("DROP TABLE `{$table}`");
        }
    }
    foreach ($created as $table) {
        $check(e2eTableColumnMetadata($table) === [], 'test tables must be removed');
    }
    return ['schema_cases' => $cases, 'generation_expression_sha256' => hash('sha256', $expression), 'remaining_test_tables' => 0];
}
