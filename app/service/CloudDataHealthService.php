<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class CloudDataHealthService
{
    private const TRUSTED_VALIDATION_STATUSES = [
        'normal', 'available', 'verified', 'ok', 'success', 'complete', 'completed', 'readback_verified',
    ];

    /** @return array<int, array<string, mixed>> */
    public function enabledHotels(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        if (!$this->tableExists('hotels')) {
            return [];
        }
        $columns = $this->tableColumns('hotels');
        $fields = array_values(array_intersect(['id', 'tenant_id', 'name', 'status'], $columns));
        if (!in_array('id', $fields, true)) {
            return [];
        }
        $query = Db::name('hotels')->order('id', 'asc')->limit($limit);
        if (in_array('status', $columns, true)) {
            $query->where('status', 1);
        }
        $rows = $query->field(implode(',', $fields))->select()->toArray();
        return array_values(array_filter(array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'tenant_id' => (int)($row['tenant_id'] ?? 0),
                'name' => trim((string)($row['name'] ?? '')),
            ];
        }, $rows), static fn(array $row): bool => (int)$row['id'] > 0));
    }

    /**
     * @param array<string, mixed> $hotel
     * @param array<int, string> $requiredPlatforms
     * @return array<string, mixed>
     */
    public function inspectHotel(array $hotel, string $targetDate, array $requiredPlatforms): array
    {
        $hotelId = (int)($hotel['id'] ?? 0);
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('Data health hotel scope is invalid.');
        }
        if (!$this->validDate($targetDate)) {
            throw new \InvalidArgumentException('Data health target date must use YYYY-MM-DD.');
        }
        if (!$this->tableExists('online_daily_data')) {
            return [
                'hotel_id' => $hotelId,
                'hotel_name' => (string)($hotel['name'] ?? ''),
                'target_date' => $targetDate,
                'status' => 'blocked',
                'can_generate_report' => false,
                'issues' => [[
                    'code' => 'online_daily_data_table_missing',
                    'platform' => '',
                    'message' => '线上数据表不存在，无法执行 MySQL 回读。',
                    'blocking' => true,
                    'next_action' => '先完成数据库迁移，再运行巡检。',
                ]],
                'platforms' => [],
                'readback' => ['verified' => false, 'mode' => 'table_missing'],
            ];
        }

        $sources = $this->dataSourcesForHotel($hotelId);
        $tasks = $this->syncTasksForHotel($hotelId);
        $rows = $this->dailyRowsForHotel($hotelId, $sources);
        $columns = $this->tableColumns('online_daily_data');

        return self::evaluate(
            $hotel,
            $targetDate,
            $requiredPlatforms,
            $rows,
            $sources,
            $tasks,
            in_array('readback_verified', $columns, true)
        );
    }

    /**
     * Pure evaluation contract used by tests and by the live database adapter.
     *
     * @param array<string, mixed> $hotel
     * @param array<int, string> $requiredPlatforms
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    public static function evaluate(
        array $hotel,
        string $targetDate,
        array $requiredPlatforms,
        array $rows,
        array $sources,
        array $tasks,
        bool $hasReadbackColumn
    ): array {
        $hotelId = (int)($hotel['id'] ?? 0);
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        $requiredPlatforms = self::normalizePlatforms($requiredPlatforms);
        if ($requiredPlatforms === []) {
            $requiredPlatforms = ['ctrip', 'meituan'];
        }

        $issues = [];
        if ($tenantId <= 0) {
            $issues[] = self::issue(
                'hotel_tenant_scope_missing',
                '',
                'The hotel has no authoritative tenant scope, so OTA evidence cannot be used for report generation.',
                true,
                'Bind the hotel to an authoritative tenant before running cloud data health or report generation.'
            );
        }
        $platformResults = [];
        $targetRowCount = 0;
        $retiredTargetRowCount = 0;
        foreach ($requiredPlatforms as $platform) {
            $allPlatformSources = array_values(array_filter($sources, static function (array $source) use ($platform): bool {
                return self::rowPlatform($source) === $platform;
            }));
            $platformSources = array_values(array_filter($allPlatformSources, static function (array $source): bool {
                return (int)($source['enabled'] ?? 1) === 1;
            }));
            $sourceIds = array_values(array_filter(array_map(
                static fn(array $source): int => (int)($source['id'] ?? 0),
                $platformSources
            ), static fn(int $id): bool => $id > 0));
            $disabledSourceIds = array_values(array_filter(array_map(
                static fn(array $source): int => (int)($source['id'] ?? 0),
                array_filter($allPlatformSources, static fn(array $source): bool => (int)($source['enabled'] ?? 1) !== 1)
            ), static fn(int $id): bool => $id > 0));

            if ($platformSources === []) {
                $issues[] = self::issue(
                    'binding_missing',
                    $platform,
                    '未找到启用中的门店数据源绑定。',
                    true,
                    '在数据配置中绑定该平台与当前门店。'
                );
            }

            $platformRows = array_values(array_filter($rows, static function (array $row) use ($platform, $sourceIds, $disabledSourceIds): bool {
                $rowPlatform = self::rowPlatform($row);
                $dataSourceId = (int)($row['data_source_id'] ?? 0);
                if ($dataSourceId > 0 && in_array($dataSourceId, $disabledSourceIds, true)) {
                    return false;
                }
                return $rowPlatform === $platform || ($dataSourceId > 0 && in_array($dataSourceId, $sourceIds, true));
            }));
            $retiredPlatformRows = array_values(array_filter(
                $platformRows,
                static fn(array $row): bool => self::isRetiredSnapshotRow($row)
            ));
            $platformRows = array_values(array_filter(
                $platformRows,
                static fn(array $row): bool => !self::isRetiredSnapshotRow($row)
            ));
            $retiredTargetRows = array_values(array_filter($retiredPlatformRows, static fn(array $row): bool =>
                substr((string)($row['data_date'] ?? ''), 0, 10) === $targetDate
            ));
            $targetRows = array_values(array_filter($platformRows, static fn(array $row): bool =>
                substr((string)($row['data_date'] ?? ''), 0, 10) === $targetDate
            ));
            $targetRowCount += count($targetRows);
            $retiredTargetRowCount += count($retiredTargetRows);

            $latestDate = '';
            foreach ($platformRows as $row) {
                $date = substr((string)($row['data_date'] ?? ''), 0, 10);
                if ($date !== '' && strcmp($date, $latestDate) > 0) {
                    $latestDate = $date;
                }
            }

            $latestTask = self::latestTask($tasks, $platform);
            $loginExpired = self::loginExpired($latestTask, $platformSources);
            if ($loginExpired) {
                $issues[] = self::issue(
                    'login_expired',
                    $platform,
                    '平台登录状态已过期或需要重新登录。',
                    $targetRows === [],
                    '在本地专用浏览器重新登录并完成一次受控采集。'
                );
            }

            if (self::collectionPartial($latestTask, $platformSources)) {
                $issues[] = self::issue(
                    'latest_collection_partial',
                    $platform,
                    'The latest collection completed only partially, so its snapshot is not complete enough for report generation.',
                    true,
                    'Recollect the target date until the platform task and bound data source both report success.'
                );
            }

            if ($targetRows === []) {
                $issues[] = self::issue(
                    'target_date_missing',
                    $platform,
                    '目标日期没有可回读的数据。' . ($latestDate !== '' ? ' 最新保存日期为 ' . $latestDate . '。' : ''),
                    true,
                    '核对目标日期后，在本地采集并上传该日期数据。'
                );
                if ($latestDate !== '' && $latestDate !== $targetDate) {
                    $issues[] = self::issue(
                        strcmp($latestDate, $targetDate) > 0 ? 'future_dated_for_target' : 'stale_before_target',
                        $platform,
                        '最新保存日期与目标日期不一致。',
                        true,
                        '核对采集日期和上传日期，禁止用其他日期替代。'
                    );
                }
            }

            $trustedRows = 0;
            $readbackRows = 0;
            $fieldEvidenceRows = 0;
            $coreBusinessRows = 0;
            foreach ($targetRows as $row) {
                $rowHotelId = (int)($row['system_hotel_id'] ?? 0);
                if ($rowHotelId !== $hotelId) {
                    $issues[] = self::issue(
                        'hotel_scope_mismatch',
                        $platform,
                        '回读记录不属于当前门店。',
                        true,
                        '停止使用该记录并重新核对门店映射。'
                    );
                    continue;
                }
                $rowTenantId = (int)($row['tenant_id'] ?? 0);
                if ($tenantId > 0 && $rowTenantId <= 0) {
                    $issues[] = self::issue(
                        'tenant_scope_missing',
                        $platform,
                        'The persisted OTA row has no tenant scope and cannot prove its ownership.',
                        true,
                        'Recollect or repair the row through the authoritative tenant and hotel binding.'
                    );
                    continue;
                }
                if ($tenantId > 0 && $rowTenantId !== $tenantId) {
                    $issues[] = self::issue(
                        'tenant_scope_mismatch',
                        $platform,
                        '回读记录与门店租户范围不一致。',
                        true,
                        '停止使用该记录并修复租户/门店绑定。'
                    );
                    continue;
                }

                $dataSourceId = (int)($row['data_source_id'] ?? 0);
                if ($dataSourceId <= 0) {
                    $issues[] = self::issue(
                        'data_source_binding_missing',
                        $platform,
                        'The persisted OTA row has no data-source binding and cannot prove its profile or store identity.',
                        true,
                        'Recollect the target date through an enabled platform data source with a verified hotel binding.'
                    );
                    continue;
                }
                if (!in_array($dataSourceId, $sourceIds, true)) {
                    $issues[] = self::issue(
                        'data_source_hotel_mismatch',
                        $platform,
                        '数据源编号未绑定到当前门店或平台。',
                        true,
                        '核对 Profile、平台门店和宿析门店映射后重新采集。'
                    );
                    continue;
                }

                $validationStatus = strtolower(trim((string)($row['validation_status'] ?? 'normal')));
                if (!in_array($validationStatus, self::TRUSTED_VALIDATION_STATUSES, true)) {
                    $issues[] = self::issue(
                        'validation_failed',
                        $platform,
                        '回读记录的数据质量状态为 ' . ($validationStatus !== '' ? $validationStatus : 'unknown') . '。',
                        true,
                        '查看采集失败原因并重新保存通过校验的数据。'
                    );
                    continue;
                }
                $trustedRows++;

                if (!$hasReadbackColumn || (int)($row['readback_verified'] ?? 0) === 1) {
                    $readbackRows++;
                    if (self::isCoreBusinessRow($row)) {
                        $coreBusinessRows++;
                    }
                } else {
                    $issues[] = self::issue(
                        'readback_unverified',
                        $platform,
                        '记录已保存但未通过数据库回读标记。',
                        true,
                        '重新执行保存与数据库回读校验。'
                    );
                }

                if (self::hasFieldEvidence($row)) {
                    $fieldEvidenceRows++;
                }
                foreach (self::validationFlagIssues($row, $platform) as $flagIssue) {
                    $issues[] = $flagIssue;
                }
            }

            if ($targetRows !== [] && $fieldEvidenceRows === 0) {
                $issues[] = self::issue(
                    'field_evidence_missing',
                    $platform,
                    '目标日记录缺少可识别的字段/来源证据。',
                    true,
                    '补齐字段映射、source path 和质量状态后重新保存。'
                );
            }
            if ($targetRows !== [] && $trustedRows > 0 && $coreBusinessRows === 0) {
                $issues[] = self::issue(
                    'core_business_metrics_missing',
                    $platform,
                    '目标日只有辅助类数据，缺少可回读的 OTA 经营核心指标。',
                    false,
                    '补采或导入该平台目标日的房费收入、间夜或订单证据；现有报告仅按已验证渠道数据生成。'
                );
            }

            $platformResults[] = [
                'platform' => $platform,
                'binding_status' => $platformSources !== [] ? 'bound' : 'binding_missing',
                'source_ids' => $sourceIds,
                'latest_task_status' => (string)($latestTask['status'] ?? ''),
                'login_status' => $loginExpired ? 'login_expired' : 'not_expired_by_saved_state',
                'latest_data_date' => $latestDate,
                'target_row_count' => count($targetRows),
                'retired_target_row_count' => count($retiredTargetRows),
                'trusted_row_count' => $trustedRows,
                'readback_row_count' => $readbackRows,
                'field_evidence_row_count' => $fieldEvidenceRows,
                'core_business_row_count' => $coreBusinessRows,
            ];
        }

        $issues = self::dedupeIssues($issues);
        $blockingCount = count(array_filter($issues, static fn(array $issue): bool => !empty($issue['blocking'])));
        $status = $blockingCount > 0 ? 'blocked' : ($issues !== [] ? 'partial' : 'verified');
        return [
            'hotel_id' => $hotelId,
            'hotel_name' => (string)($hotel['name'] ?? ''),
            'target_date' => $targetDate,
            'metric_scope' => 'ota_channel',
            'status' => $status,
            'can_generate_report' => $blockingCount === 0 && $targetRowCount > 0,
            'issues' => $issues,
            'blocking_issue_count' => $blockingCount,
            'platforms' => $platformResults,
            'readback' => [
                'verified' => $blockingCount === 0 && $targetRowCount > 0,
                'mode' => $hasReadbackColumn ? 'persisted_readback_flag' : 'legacy_mysql_identity_query',
                'target_row_count' => $targetRowCount,
                'retired_target_row_count' => $retiredTargetRowCount,
            ],
            'boundary' => 'Missing, stale, cross-bound, or unverified rows do not enter AI report generation and are never replaced by zero.',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function dataSourcesForHotel(int $hotelId): array
    {
        if (!$this->tableExists('platform_data_sources')) {
            return [];
        }
        $columns = $this->tableColumns('platform_data_sources');
        $fields = array_values(array_intersect([
            'id', 'tenant_id', 'system_hotel_id', 'platform', 'data_type', 'status', 'enabled',
            'last_sync_status', 'last_error', 'last_sync_time', 'update_time',
        ], $columns));
        return Db::name('platform_data_sources')
            ->where('system_hotel_id', $hotelId)
            ->order('id', 'asc')
            ->field(implode(',', $fields))
            ->select()
            ->toArray();
    }

    /** @return array<int, array<string, mixed>> */
    private function syncTasksForHotel(int $hotelId): array
    {
        if (!$this->tableExists('platform_data_sync_tasks')) {
            return [];
        }
        $columns = $this->tableColumns('platform_data_sync_tasks');
        $fields = array_values(array_intersect([
            'id', 'tenant_id', 'data_source_id', 'system_hotel_id', 'platform', 'status', 'message',
            'started_at', 'finished_at', 'create_time', 'update_time',
        ], $columns));
        return Db::name('platform_data_sync_tasks')
            ->where('system_hotel_id', $hotelId)
            ->order('id', 'desc')
            ->limit(100)
            ->field(implode(',', $fields))
            ->select()
            ->toArray();
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    private function dailyRowsForHotel(int $hotelId, array $sources): array
    {
        $columns = $this->tableColumns('online_daily_data');
        $fields = array_values(array_intersect([
            'id', 'tenant_id', 'system_hotel_id', 'hotel_id', 'hotel_name', 'data_date', 'source', 'platform',
            'data_type', 'dimension', 'validation_status', 'validation_flags', 'data_source_id', 'sync_task_id',
            'ingestion_method', 'source_trace_id', 'raw_data', 'readback_verified', 'readback_verified_at',
        ], $columns));
        $rows = Db::name('online_daily_data')
            ->where('system_hotel_id', $hotelId)
            ->order('data_date', 'desc')
            ->order('id', 'desc')
            ->limit(500)
            ->field(implode(',', $fields))
            ->select()
            ->toArray();

        if (!in_array('data_source_id', $columns, true)) {
            return $rows;
        }
        $sourceIds = array_values(array_filter(array_map(
            static fn(array $source): int => (int)($source['id'] ?? 0),
            $sources
        ), static fn(int $id): bool => $id > 0));
        if ($sourceIds === []) {
            return $rows;
        }
        $boundRows = Db::name('online_daily_data')
            ->whereIn('data_source_id', $sourceIds)
            ->order('data_date', 'desc')
            ->order('id', 'desc')
            ->limit(500)
            ->field(implode(',', $fields))
            ->select()
            ->toArray();
        $byId = [];
        foreach (array_merge($rows, $boundRows) as $row) {
            $key = (string)($row['id'] ?? md5(json_encode($row)));
            $byId[$key] = $row;
        }
        return array_values($byId);
    }

    /** @param array<int, array<string, mixed>> $tasks */
    private static function latestTask(array $tasks, string $platform): array
    {
        $matches = array_values(array_filter($tasks, static fn(array $task): bool => self::rowPlatform($task) === $platform));
        usort($matches, static function (array $left, array $right): int {
            $leftTime = (string)($left['finished_at'] ?? $left['update_time'] ?? $left['create_time'] ?? '');
            $rightTime = (string)($right['finished_at'] ?? $right['update_time'] ?? $right['create_time'] ?? '');
            return strcmp($rightTime, $leftTime);
        });
        return $matches[0] ?? [];
    }

    /** @param array<int, array<string, mixed>> $sources */
    private static function loginExpired(array $latestTask, array $sources): bool
    {
        $parts = [
            (string)($latestTask['status'] ?? ''),
            (string)($latestTask['message'] ?? ''),
        ];
        foreach ($sources as $source) {
            $parts[] = (string)($source['status'] ?? '');
            $parts[] = (string)($source['last_sync_status'] ?? '');
            $parts[] = (string)($source['last_error'] ?? '');
        }
        return preg_match(
            '/login[_\s-]?(expired|required)|session[_\s-]?expired|unauthorized|forbidden|cookie[_\s-]?expired|重新登录|登录(失效|过期)|未登录|401|403/i',
            implode(' ', $parts)
        ) === 1;
    }

    /** @param array<int, array<string, mixed>> $sources */
    private static function collectionPartial(array $latestTask, array $sources): bool
    {
        if (in_array(strtolower(trim((string)($latestTask['status'] ?? ''))), ['partial', 'partial_success'], true)) {
            return true;
        }
        foreach ($sources as $source) {
            if (in_array(strtolower(trim((string)($source['last_sync_status'] ?? ''))), ['partial', 'partial_success'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, array<string, mixed>> */
    private static function validationFlagIssues(array $row, string $platform): array
    {
        $flags = $row['validation_flags'] ?? [];
        if (is_string($flags) && trim($flags) !== '') {
            $decoded = json_decode($flags, true);
            $flags = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($flags)) {
            return [];
        }
        $issues = [];
        foreach ($flags as $flag) {
            $code = is_array($flag) ? (string)($flag['code'] ?? '') : (string)$flag;
            if ($code === '' || preg_match('/missing|mismatch|unverified|wrong|invalid|failed|stale/i', $code) !== 1) {
                continue;
            }
            $issues[] = self::issue(
                'field_validation_' . preg_replace('/[^a-z0-9_\-]/', '_', strtolower($code)),
                $platform,
                '字段校验未通过：' . $code . '。',
                true,
                '按字段映射和来源路径修复后重新保存。'
            );
        }
        return $issues;
    }

    private static function isRetiredSnapshotRow(array $row): bool
    {
        if (
            strtolower(trim((string)($row['validation_status'] ?? ''))) !== 'unverified'
            || !array_key_exists('readback_verified', $row)
            || (int)$row['readback_verified'] !== 0
        ) {
            return false;
        }

        $flags = $row['validation_flags'] ?? [];
        if (is_string($flags) && trim($flags) !== '') {
            $decoded = json_decode($flags, true);
            $flags = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($flags)) {
            return false;
        }

        foreach ($flags as $flag) {
            $code = is_array($flag) ? (string)($flag['code'] ?? '') : (string)$flag;
            if ($code === 'cloud_bundle_row_absent_from_newer_verified_snapshot') {
                return true;
            }
        }
        return false;
    }

    private static function isCoreBusinessRow(array $row): bool
    {
        $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
        return in_array($dataType, ['business', 'business_overview', 'revenue', 'order', 'orders'], true);
    }

    private static function hasFieldEvidence(array $row): bool
    {
        $raw = $row['raw_data'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw) || $raw === []) {
            return false;
        }

        $status = OnlineDataFieldFactService::buildStatus($row, $raw);
        $capturedCount = (int)($status['captured_count'] ?? 0);
        return $capturedCount > 0
            && (int)($status['matching_desensitized_capture_evidence_count'] ?? 0) >= $capturedCount
            && (int)($status['stored_value_missing_count'] ?? 0) === 0;
    }

    private static function rowPlatform(array $row): string
    {
        $platform = strtolower(trim((string)($row['platform'] ?? $row['source'] ?? '')));
        return match (true) {
            str_contains($platform, 'ctrip'), str_contains($platform, 'xiecheng') => 'ctrip',
            str_contains($platform, 'meituan') => 'meituan',
            default => $platform,
        };
    }

    /** @param array<int, string> $platforms @return array<int, string> */
    private static function normalizePlatforms(array $platforms): array
    {
        $normalized = [];
        foreach ($platforms as $platform) {
            $platform = self::rowPlatform(['platform' => $platform]);
            if (in_array($platform, ['ctrip', 'meituan'], true)) {
                $normalized[] = $platform;
            }
        }
        return array_values(array_unique($normalized));
    }

    /** @return array<string, mixed> */
    private static function issue(
        string $code,
        string $platform,
        string $message,
        bool $blocking,
        string $nextAction
    ): array {
        return [
            'code' => substr($code, 0, 100),
            'platform' => $platform,
            'message' => $message,
            'blocking' => $blocking,
            'next_action' => $nextAction,
        ];
    }

    /** @param array<int, array<string, mixed>> $issues @return array<int, array<string, mixed>> */
    private static function dedupeIssues(array $issues): array
    {
        $deduped = [];
        foreach ($issues as $issue) {
            $key = (string)($issue['platform'] ?? '') . '|' . (string)($issue['code'] ?? '');
            $deduped[$key] = $issue;
        }
        return array_values($deduped);
    }

    /** @return array<int, string> */
    private function tableColumns(string $table): array
    {
        try {
            return array_keys(Db::getFields($table));
        } catch (\Throwable) {
            try {
                return array_values(array_filter(array_map(
                    static fn(array $row): string => (string)($row['Field'] ?? ''),
                    Db::query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')
                )));
            } catch (\Throwable) {
                return [];
            }
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
        } catch (\Throwable) {
            return false;
        }
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === trim($value);
    }
}
