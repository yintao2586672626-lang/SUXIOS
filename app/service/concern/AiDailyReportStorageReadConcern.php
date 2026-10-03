<?php
declare(strict_types=1);

namespace app\service\concern;

use app\service\RevenueOverviewDateContract;
use think\facade\Db;
use Throwable;

trait AiDailyReportStorageReadConcern
{
    private function applyHotelScope($query, array $hotelIds, ?int $hotelId): void
    {
        if ($hotelId !== null && $hotelId > 0) {
            $query->where('hotel_id', $hotelId);
            return;
        }
        if (!empty($hotelIds)) {
            $query->whereIn('hotel_id', array_values(array_map('intval', $hotelIds)));
        }
    }

    private function applyReportTenantScope($query): void
    {
        if ($this->tableHasColumn(self::TABLE, 'tenant_id') && $this->tableHasColumn('hotels', 'tenant_id')) {
            $reportTable = '`' . str_replace('`', '', $query->getTable()) . '`'; $hotelTable = '`' . str_replace('`', '', Db::name('hotels')->getTable()) . '`';
            $query->whereRaw($reportTable . '.tenant_id = (SELECT tenant_id FROM ' . $hotelTable . ' WHERE ' . $hotelTable . '.id = ' . $reportTable . '.hotel_id)');
        }
    }

    private function assertReportDateTenantOwnership(int $hotelId, string $reportDate): void
    {
        if (!$this->tableHasColumn(self::TABLE, 'tenant_id') || !$this->tableHasColumn('hotels', 'tenant_id')) {
            return;
        }
        $existing = Db::name(self::TABLE)
            ->where('hotel_id', $hotelId)
            ->where('report_date', $reportDate)
            ->whereNull('deleted_at')
            ->find();
        $this->assertReportTenantOwnership($existing, $hotelId);
    }

    private function assertReportTenantOwnership(?array $row, int $hotelId): void
    {
        if (!is_array($row) || !$this->tableHasColumn(self::TABLE, 'tenant_id')
            || !$this->tableHasColumn('hotels', 'tenant_id')) {
            return;
        }
        $currentTenantId = $this->resolveHotelTenantId($hotelId);
        if ($currentTenantId === null || (int)($row['tenant_id'] ?? 0) !== $currentTenantId) {
            throw new \RuntimeException('AI daily report belongs to another tenant');
        }
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            throw new \InvalidArgumentException('date is invalid');
        }

        try {
            return RevenueOverviewDateContract::businessDate($date);
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException('date is invalid', 0, $e);
        }
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (float)$value : null;
    }

    private function metricValue(array $metrics, string $key): ?float
    {
        foreach ($metrics as $metric) {
            if (is_array($metric) && ($metric['key'] ?? '') === $key) {
                return $this->numericOrNull($metric['value'] ?? null);
            }
        }

        return null;
    }

    private function uniqueByCodeAndMessage(array $items): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            $key = (string)($item['code'] ?? '') . '|' . (string)($item['message'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $item;
        }

        return $result;
    }

    private function dedupeActions(array $actions): array
    {
        $seen = [];
        $result = [];
        foreach ($actions as $action) {
            $key = (string)($action['title'] ?? '') . '|' . (string)($action['action_type'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $action;
        }

        return $result;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private function blockedReportRead(string $stage, array $extra = []): array
    {
        $shape = match ($stage) {
            'list' => [
                'list' => [],
                'pagination' => [
                    'total' => null,
                    'page' => null,
                    'page_size' => null,
                    'total_page' => null,
                ],
            ],
            'latest' => ['report' => null],
            default => [],
        };

        return array_merge($shape, [
            'status' => 'blocked',
            'data_status' => 'read_failed',
            'reason_code' => 'ai_daily_reports_read_failed',
            'stage' => $stage,
            'data_gaps' => [[
                'code' => 'ai_daily_reports_read_failed',
                'data_status' => 'read_failed',
                'stage' => $stage,
                'message' => 'AI daily report storage could not be read; the result was not treated as missing or empty.',
            ]],
        ], $extra);
    }

    private function tableExists(string $table): bool
    {
        $physicalTable = Db::name($table)->getTable(); try {
            Db::query('SELECT 1 FROM `' . str_replace('`', '', $physicalTable) . '` LIMIT 1'); return true;
        } catch (Throwable $e) {
            if ($this->isMissingTableException($e, $physicalTable)) {
                return false;
            }
            throw new \RuntimeException(
                'database_table_probe_failed:' . $table,
                503,
                $e
            );
        }
    }

    private function isMissingTableException(Throwable $exception, string $table): bool
    {
        $table = strtolower(str_replace('`', '', $table));
        $current = $exception;
        do {
            $code = strtoupper(trim((string)$current->getCode()));
            $message = strtolower($current->getMessage());
            if ($code === '42S02'
                || str_contains($message, "table '{$table}' doesn't exist")
                || str_contains($message, 'table `' . $table . '` does not exist')
                || str_contains($message, 'relation "' . $table . '" does not exist')
                || preg_match(
                    '/table\s+[' . "'`\"" . '](?:[a-z0-9_]+\.)?'
                        . preg_quote($table, '/')
                        . '[' . "'`\"" . ']\s+(?:doesn.t|does not)\s+exist/i',
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

    private function withTenantId(array $data, string $table, int $hotelId): array
    {
        if ($this->tableHasColumn($table, 'tenant_id')) {
            $data['tenant_id'] = $this->resolveHotelTenantId($hotelId);
        }

        return $data;
    }

    private function resolveHotelTenantId(int $hotelId): ?int
    {
        if ($hotelId <= 0) {
            return null;
        }
        $tenantId = (int)(Db::name('hotels')->where('id', $hotelId)->value('tenant_id') ?? 0);
        return $tenantId > 0 ? $tenantId : null;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        $physicalTable = str_replace('`', '', Db::name($table)->getTable());
        $inspection = \app\service\DatabaseSchemaRequirement::inspectTableColumns($physicalTable);
        if ($inspection['status'] === \app\service\DatabaseSchemaRequirement::STATUS_UNREADABLE) {
            throw new \RuntimeException('database_table_columns_probe_failed:' . $table, 503);
        }
        return $inspection['status'] === \app\service\DatabaseSchemaRequirement::STATUS_PRESENT
            && in_array($column, $inspection['columns'], true);
    }
}
