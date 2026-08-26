<?php
declare(strict_types=1);

namespace app\service\operation;

use app\service\DatabaseSchemaRequirement;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use think\facade\Db;
use Throwable;

trait OperationBaselineConcern
{
    /** @param array<string, mixed> $row */
    private function dailyReportValidationStatusIsTrusted(array $row): bool
    {
        return $this->dailyReportTrustDecision($row)['trusted'];
    }

    /** @param array<string, mixed> $row */
    private function dailyReportValidationRejectionReason(array $row): string
    {
        return $this->dailyReportTrustDecision($row)['reason'];
    }

    /**
     * The production daily_reports writer persists a domain workflow status:
     * 1=draft and 2=submitted.  That contract is authoritative.  A narrowly
     * compatible legacy row without that column may use exact "verified"
     * evidence; generic workflow-success words never establish fact quality.
     *
     * @param array<string, mixed> $row
     * @return array{trusted:bool,reason:string}
     */
    private function dailyReportTrustDecision(array $row): array
    {
        if (array_key_exists('status', $row)
            && $row['status'] !== null
            && trim((string)$row['status']) !== ''
        ) {
            $rawStatus = $row['status'];
            $canonicalInteger = is_int($rawStatus)
                || (is_string($rawStatus) && preg_match('/^(?:0|[1-9]\d*)$/D', $rawStatus) === 1);
            if ($canonicalInteger && (int)$rawStatus === 2) {
                return ['trusted' => true, 'reason' => 'submitted'];
            }
            if ($canonicalInteger && (int)$rawStatus === 1) {
                return ['trusted' => false, 'reason' => 'report_status_draft'];
            }

            return ['trusted' => false, 'reason' => 'report_status_not_submitted'];
        }

        $validationStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
        if ($validationStatus === 'verified') {
            return ['trusted' => true, 'reason' => 'legacy_verified'];
        }
        if ($validationStatus === '') {
            return ['trusted' => false, 'reason' => 'validation_status_missing'];
        }
        if (in_array($validationStatus, [
            'failed', 'fail', 'error', 'abnormal', 'invalid', 'collection_failed',
            'capture_failed', 'permission_denied', 'binding_missing', 'mismatched',
            'mismatch', 'login_required', 'rejected',
        ], true)) {
            return ['trusted' => false, 'reason' => 'validation_status_failed'];
        }

        return ['trusted' => false, 'reason' => 'validation_status_untrusted'];
    }

    private function buildSummaryFromRows(
        array $daily,
        array $online,
        array $hotelIds,
        ?int $hotelId,
        string $date
    ): array {
        $dailyScope = $this->scopeDailyReportRowsToCurrentTenant($daily, $hotelIds);
        $onlineScope = $this->scopeOnlineRowsToCurrentTenant($online, $hotelIds);
        $summary = $this->buildSummaryFromTenantScopedRows(
            $dailyScope['rows'],
            $onlineScope['rows'],
            $hotelIds,
            $hotelId,
            $date
        );

        $summary = $this->applyDailyReportReadGap($summary, $dailyScope['gap']);
        return $this->applyDailyReportReadGap($summary, $onlineScope['gap']);
    }

    /**
     * @param array<int,mixed> $rows
     * @param array<int,int> $hotelIds
     * @return array{rows:array<int,array<string,mixed>>,gap:?array<string,mixed>}
     */
    private function scopeOnlineRowsToCurrentTenant(array $rows, array $hotelIds): array
    {
        foreach ($rows as $row) {
            if (is_array($row) && is_array($row['__operation_online_gap'] ?? null)) {
                return ['rows' => [], 'gap' => $row['__operation_online_gap']];
            }
        }
        if ($rows === []) {
            return ['rows' => [], 'gap' => null];
        }
        if (($gap = $this->operationOnlineTenantSchemaGap()) !== null) {
            return ['rows' => [], 'gap' => $gap];
        }

        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds))));
        try {
            $tenantByHotel = Db::name('hotels')
                ->whereIn('id', $hotelIds)
                ->where('tenant_id', '>', 0)
                ->column('tenant_id', 'id');
        } catch (Throwable) {
            return ['rows' => [], 'gap' => $this->dailyReportGap(
                'operation_online_hotel_tenant_read_failed',
                'Current hotel tenant scope could not be read for OTA operation facts.'
            )];
        }

        $tenantByHotel = array_map('intval', $tenantByHotel);
        $scoped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hotelId = (int)($row['system_hotel_id'] ?? 0);
            $tenantId = (int)($row['tenant_id'] ?? 0);
            if ($hotelId > 0
                && $tenantId > 0
                && isset($tenantByHotel[$hotelId])
                && $tenantByHotel[$hotelId] === $tenantId
            ) {
                $scoped[] = $row;
            }
        }
        return ['rows' => $scoped, 'gap' => null];
    }

    /** @return array<string,mixed>|null */
    private function operationOnlineTenantSchemaGap(): ?array
    {
        try {
            if (!$this->tableExists('online_daily_data')) {
                return null;
            }
            $onlineColumns = $this->operationTableColumnNames('online_daily_data');
            if ($onlineColumns === null
                || array_diff(['tenant_id', 'system_hotel_id', 'data_date'], $onlineColumns) !== []
            ) {
                return $this->dailyReportGap(
                    'operation_online_daily_data_tenant_schema_missing',
                    'online_daily_data must expose tenant_id, system_hotel_id and data_date before operation facts can be read.'
                );
            }
            $hotelColumns = $this->operationTableColumnNames('hotels');
            if ($hotelColumns === null || array_diff(['id', 'tenant_id'], $hotelColumns) !== []) {
                return $this->dailyReportGap(
                    'operation_online_hotels_tenant_schema_missing',
                    'hotels must expose id and tenant_id before OTA operation facts can be read.'
                );
            }
        } catch (Throwable) {
            return $this->dailyReportReadFailureGap(
                'operation_online_schema_unreadable',
                'OTA operation fact schema could not be inspected; operation analysis failed closed.'
            );
        }
        return null;
    }

    /**
     * @param array<int, mixed> $rows
     * @param array<int, int> $hotelIds
     * @return array{rows:array<int, array<string,mixed>>,gap:?array<string,mixed>}
     */
    private function scopeDailyReportRowsToCurrentTenant(array $rows, array $hotelIds): array
    {
        foreach ($rows as $row) {
            if (is_array($row) && is_array($row['__operation_daily_report_gap'] ?? null)) {
                return ['rows' => [], 'gap' => $row['__operation_daily_report_gap']];
            }
        }
        if ($rows === []) {
            return ['rows' => [], 'gap' => null];
        }

        if (($schemaGap = $this->operationDailyReportTenantSchemaGap()) !== null) {
            return ['rows' => [], 'gap' => $schemaGap];
        }

        $hotelIds = array_values(array_unique(array_filter(
            array_map('intval', $hotelIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($hotelIds === []) {
            return ['rows' => [], 'gap' => null];
        }

        try {
            $tenantByHotel = Db::name('hotels')
                ->whereIn('id', $hotelIds)
                ->where('tenant_id', '>', 0)
                ->column('tenant_id', 'id');
        } catch (Throwable) {
            return ['rows' => [], 'gap' => $this->dailyReportGap(
                'operation_hotels_tenant_read_failed',
                'Current hotel tenant scope could not be read; operation daily reports are unavailable.'
            )];
        }
        $tenantByHotel = array_map('intval', $tenantByHotel);
        if (count($tenantByHotel) !== count($hotelIds)) {
            return ['rows' => [], 'gap' => $this->dailyReportGap(
                'operation_hotel_tenant_scope_missing',
                'A selected hotel has no current positive tenant_id; operation daily reports are unavailable.'
            )];
        }

        $scopedRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!array_key_exists('tenant_id', $row)) {
                return ['rows' => [], 'gap' => $this->dailyReportGap(
                    'operation_daily_reports_tenant_context_missing',
                    'A daily report row lacks tenant_id and cannot be used as verified operation evidence.'
                )];
            }
            $rowHotelId = (int)($row['hotel_id'] ?? 0);
            $rowTenantId = (int)($row['tenant_id'] ?? 0);
            if ($rowHotelId <= 0
                || $rowTenantId <= 0
                || !isset($tenantByHotel[$rowHotelId])
                || $tenantByHotel[$rowHotelId] !== $rowTenantId
            ) {
                continue;
            }
            $scopedRows[] = $row;
        }

        return ['rows' => $scopedRows, 'gap' => null];
    }

    /** @return array<string,mixed>|null */
    private function operationDailyReportTenantSchemaGap(): ?array
    {
        try {
            $dailyColumns = $this->operationTableColumnNames('daily_reports');
            if ($dailyColumns === null
                || array_diff(['tenant_id', 'hotel_id', 'report_date'], $dailyColumns) !== []
            ) {
                return $this->dailyReportGap(
                    'operation_daily_reports_tenant_schema_missing',
                    'daily_reports must expose tenant_id, hotel_id and report_date before operation evidence can be read.'
                );
            }

            $hotelColumns = $this->operationTableColumnNames('hotels');
            if ($hotelColumns === null || array_diff(['id', 'tenant_id'], $hotelColumns) !== []) {
                return $this->dailyReportGap(
                    'operation_hotels_tenant_schema_missing',
                    'hotels must expose id and tenant_id before operation evidence can be read.'
                );
            }
        } catch (Throwable) {
            return $this->dailyReportReadFailureGap(
                'operation_daily_report_schema_unreadable',
                'Daily report schema could not be inspected; operation analysis failed closed.'
            );
        }

        return null;
    }

    /** @return array<int,string>|null */
    private function operationTableColumnNames(string $table): ?array
    {
        $inspection = DatabaseSchemaRequirement::inspectTableColumns(str_replace('`', '', $table));
        if ($inspection['status'] === DatabaseSchemaRequirement::STATUS_MISSING) {
            return null;
        }
        if ($inspection['status'] !== DatabaseSchemaRequirement::STATUS_PRESENT) {
            throw new \RuntimeException('database_table_columns_probe_failed:' . $table, 503);
        }

        return $inspection['columns'];
    }

    /** @return array<string,mixed> */
    private function dailyReportGap(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message, 'migration_required' => true];
    }

    /** @return array<string,mixed> */
    private function dailyReportReadFailureGap(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message, 'migration_required' => false];
    }

    /** @param array<string,mixed>|null $gap */
    private function applyDailyReportReadGap(array $summary, ?array $gap): array
    {
        if ($gap === null) {
            return $summary;
        }
        $summary['data_gaps'] = array_values(array_merge((array)($summary['data_gaps'] ?? []), [$gap]));
        $dataStatus = ($gap['migration_required'] ?? false) === true ? 'migration_required' : 'read_failed';
        $summary['data_status'] = $dataStatus;
        if (array_key_exists('source_status', $summary)) {
            $summary['source_status'] = $dataStatus;
        }

        return $summary;
    }

    private function operationShanghaiToday(): string
    {
        return $this->operationShanghaiBusinessDate();
    }

    private function operationShanghaiBusinessDate(?\DateTimeInterface $now = null): string
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        return ($now === null
            ? new DateTimeImmutable('now', $timezone)
            : DateTimeImmutable::createFromInterface($now)->setTimezone($timezone)
        )->format('Y-m-d');
    }

    private function operationShanghaiTimestampOrNull(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Asia/Shanghai'));
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))
            && $parsed->format('Y-m-d H:i:s') === $value
            ? $parsed
            : null;
    }

    private function operationStrictShanghaiDateOrNull(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Shanghai'));
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $value
            ? $parsed
            : null;
    }

    private function baseline(array $hotelIds, int $days, ?string $endDate = null): array
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        if ($endDate === null) {
            $endDay = (new DateTimeImmutable('today', $timezone))->modify('-1 day');
        } else {
            $normalizedEndDate = trim($endDate);
            $exclusiveEndDay = DateTimeImmutable::createFromFormat('!Y-m-d', $normalizedEndDate, $timezone);
            $parseErrors = DateTimeImmutable::getLastErrors();
            if ($exclusiveEndDay === false
                || ($parseErrors !== false
                    && ((int)$parseErrors['warning_count'] > 0 || (int)$parseErrors['error_count'] > 0))
                || $exclusiveEndDay->format('Y-m-d') !== $normalizedEndDate
            ) {
                throw new InvalidArgumentException('baseline end date must use the YYYY-MM-DD business-date format');
            }
            $endDay = $exclusiveEndDay->modify('-1 day');
        }
        $end = $endDay->format('Y-m-d');
        $start = $endDay->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $dailyScope = $this->scopeDailyReportRowsToCurrentTenant(
            $this->dailyReportRows($hotelIds, $start, $end),
            $hotelIds
        );
        $daily = $dailyScope['rows'];
        $dailyReadGap = $dailyScope['gap'];
        $onlineScope = $this->scopeOnlineRowsToCurrentTenant(
            $this->onlineRows($hotelIds, $start, $end),
            $hotelIds
        );
        $onlineRows = $onlineScope['rows'];
        $onlineReadGap = $onlineScope['gap'];
        $dailyByDate = [];
        $onlineByDate = [];
        $dates = [];
        foreach ($daily as $row) {
            $date = substr(trim((string)($row['report_date'] ?? '')), 0, 10);
            if ($date !== '') {
                $dailyByDate[$date][] = $row;
                $dates[$date] = true;
            }
        }
        foreach ($onlineRows as $row) {
            $date = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            if ($date !== '') {
                $onlineByDate[$date][] = $row;
                $dates[$date] = true;
            }
        }

        $metricValues = ['orders' => [], 'revenue' => [], 'room_nights' => []];
        $metricScopesByDate = ['orders' => [], 'revenue' => [], 'room_nights' => [], 'conversion' => []];
        $metricIdentitiesByDate = ['orders' => [], 'revenue' => [], 'room_nights' => [], 'conversion' => []];
        $sourceScopes = [];
        $incompleteDates = [];
        $actualDates = [];
        $rejectedDailyReportCount = 0;
        $rejectedDailyReportDates = [];
        $rejectedDailyReportReasons = [];
        foreach (array_keys($dates) as $date) {
            $summary = $this->buildSummaryFromTenantScopedRows(
                $dailyByDate[$date] ?? [],
                $onlineByDate[$date] ?? [],
                $hotelIds,
                count($hotelIds) === 1 ? (int)$hotelIds[0] : null,
                $date
            );
            $rejectedOnDate = max(0, (int)($summary['rejected_daily_report_count'] ?? 0));
            if ($rejectedOnDate > 0) {
                $rejectedDailyReportCount += $rejectedOnDate;
                $rejectedDailyReportDates[$date] = true;
                foreach ((array)($summary['rejected_daily_report_reasons'] ?? []) as $reason => $reasonCount) {
                    $reason = trim((string)$reason);
                    if ($reason === '') {
                        continue;
                    }
                    $rejectedDailyReportReasons[$reason] = ($rejectedDailyReportReasons[$reason] ?? 0)
                        + max(0, (int)$reasonCount);
                }
            }
            if (($summary['evidence_refs'] ?? []) === []) {
                continue;
            }
            $actualDates[$date] = true;
            $sourceScopes[(string)($summary['source_scope'] ?? 'unknown')] = true;
            $operatingPlatforms = [];
            $evidenceIdentities = ['orders' => [], 'revenue' => [], 'room_nights' => []];
            foreach ((array)($summary['evidence_refs'] ?? []) as $evidenceRef) {
                if (!is_array($evidenceRef)) {
                    continue;
                }
                $platform = $this->normalizeOtaChannel((string)($evidenceRef['platform'] ?? ''));
                if ($platform === '') {
                    $platform = $this->normalizeOtaChannel((string)($evidenceRef['source'] ?? ''));
                }
                if (in_array($platform, ['ctrip', 'meituan', 'qunar'], true)) {
                    $operatingPlatforms[$platform] = true;
                }
                $source = strtolower(trim((string)($evidenceRef['source'] ?? '')));
                $scope = str_starts_with((string)($evidenceRef['source_ref'] ?? ''), 'daily_reports#')
                    ? 'whole_hotel_daily_report'
                    : 'ota_channel';
                foreach ((array)($evidenceRef['metric_keys'] ?? []) as $rawMetric) {
                    $metricKey = match (strtolower(trim((string)$rawMetric))) {
                        'revenue', 'amount', 'order_amount' => 'revenue',
                        'orders', 'book_order_num', 'order_count' => 'orders',
                        'room_nights', 'quantity' => 'room_nights',
                        default => '',
                    };
                    if ($metricKey === '') {
                        continue;
                    }
                    $identity = [
                        'metric' => $metricKey,
                        'scope' => $scope,
                        'platform' => $scope === 'ota_channel' ? $platform : '',
                        'source' => $source,
                        'measurement_grain' => 'daily_average',
                    ];
                    $evidenceIdentities[$metricKey][json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)] = $identity;
                }
            }
            $operatingPlatforms = array_keys($operatingPlatforms);
            sort($operatingPlatforms);
            if (($summary['data_status'] ?? '') !== self::DATA_OK) {
                $incompleteDates[] = $date;
            }
            foreach (array_keys($metricValues) as $metric) {
                if ($summary[$metric] !== null && is_numeric($summary[$metric])) {
                    $metricValues[$metric][$date] = (float)$summary[$metric];
                    $scopes = is_array($summary['metric_scopes'][$metric] ?? null)
                        ? array_values(array_unique($summary['metric_scopes'][$metric]))
                        : [];
                    sort($scopes);
                    $metricScopesByDate[$metric][$date] = [
                        'scopes' => $scopes,
                        'platforms' => in_array('ota_channel', $scopes, true) ? $operatingPlatforms : [],
                    ];
                    $metricIdentitiesByDate[$metric][$date] = array_values($evidenceIdentities[$metric]);
                }
            }
        }

        $conversionValues = [];
        $flowByDate = [];
        foreach ($this->latestOnlineFlowRows($onlineRows) as $row) {
            $day = (string)($row['data_date'] ?? '');
            if ($day === '') {
                continue;
            }
            $metrics = $this->onlineFlowMetrics($row);
            $flowByDate[$day]['visitors'] = ($flowByDate[$day]['visitors'] ?? 0) + $metrics['visitors'];
            $flowByDate[$day]['orders'] = ($flowByDate[$day]['orders'] ?? 0) + $metrics['orders'];
            $platform = $this->normalizeOtaChannel((string)($row['platform'] ?? ''));
            if ($platform === '') {
                $platform = $this->normalizeOtaChannel((string)($row['source'] ?? ''));
            }
            if (in_array($platform, ['ctrip', 'meituan', 'qunar'], true)) {
                $flowByDate[$day]['platforms'][$platform] = true;
                $source = $this->normalizeOtaChannel((string)($row['source'] ?? ''));
                $flowByDate[$day]['sources'][$source !== '' ? $source : $platform] = true;
            }
        }
        foreach ($flowByDate as $day => $metric) {
            $visitors = (float)($metric['visitors'] ?? 0);
            if ($visitors > 0) {
                $conversionValues[$day] = (float)($metric['orders'] ?? 0) / $visitors * 100;
                $platforms = array_keys((array)($metric['platforms'] ?? []));
                sort($platforms);
                $metricScopesByDate['conversion'][$day] = [
                    'scopes' => ['ota_channel'],
                    'platforms' => $platforms,
                ];
                $conversionIdentities = [];
                foreach ((array)($metric['sources'] ?? []) as $source => $_present) {
                    $platform = $this->normalizeOtaChannel((string)$source);
                    $identity = [
                        'metric' => 'conversion',
                        'scope' => 'ota_channel',
                        'platform' => $platform,
                        'source' => strtolower(trim((string)$source)),
                        'measurement_grain' => 'daily_average',
                    ];
                    $conversionIdentities[json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)] = $identity;
                }
                $metricIdentitiesByDate['conversion'][$day] = array_values($conversionIdentities);
                $sourceScopes['ota_channel'] = true;
            }
        }

        $count = count($actualDates);
        $dataGaps = array_values(array_filter([$dailyReadGap, $onlineReadGap]));
        if ($count < $days) {
            $dataGaps[] = [
                'code' => 'insufficient_baseline_days',
                'message' => 'Baseline evidence covers ' . $count . '/' . $days . ' requested days',
            ];
        }
        if ($rejectedDailyReportCount > 0) {
            $dataGaps[] = [
                'code' => 'baseline_daily_report_validation_untrusted',
                'message' => $rejectedDailyReportCount . ' daily report record(s) across '
                    . count($rejectedDailyReportDates)
                    . ' day(s) were excluded because they were not formally submitted (status=2) '
                    . 'or exact verified legacy evidence',
            ];
        }
        foreach ([
            'orders' => ['baseline_orders_incomplete', '订单'],
            'revenue' => ['baseline_revenue_incomplete', '收入'],
            'room_nights' => ['baseline_room_nights_incomplete', '间夜'],
        ] as $metric => [$code, $label]) {
            if (count($metricValues[$metric]) < $days) {
                $dataGaps[] = [
                    'code' => $code,
                    'message' => $label . '仅覆盖 ' . count($metricValues[$metric]) . '/' . $days . ' 个请求日期',
                ];
            }
        }
        if ($incompleteDates !== []) {
            $dataGaps[] = [
                'code' => 'baseline_daily_summary_partial',
                'message' => count($incompleteDates) . ' 个日期存在必需字段或来源缺口',
            ];
        }
        $scopeDrifted = false;
        $actualDateKeys = array_keys($actualDates);
        sort($actualDateKeys);
        foreach (['orders', 'revenue', 'room_nights'] as $metric) {
            $metricDates = array_keys($metricScopesByDate[$metric]);
            sort($metricDates);
            if ($metricDates !== $actualDateKeys) {
                $scopeDrifted = true;
            }
        }
        if ($metricScopesByDate['conversion'] !== []) {
            $conversionDates = array_keys($metricScopesByDate['conversion']);
            sort($conversionDates);
            if ($conversionDates !== $actualDateKeys) {
                $scopeDrifted = true;
            }
        }

        $scopeIdentities = [];
        foreach ($metricScopesByDate as $scopesByDate) {
            foreach ($scopesByDate as $scope) {
                $scopes = is_array($scope['scopes'] ?? null) ? $scope['scopes'] : [];
                $platforms = is_array($scope['platforms'] ?? null) ? $scope['platforms'] : [];
                if (count($scopes) !== 1 || ($scopes[0] === 'ota_channel' && count($platforms) !== 1)) {
                    $scopeDrifted = true;
                }
                $scopeIdentities[json_encode([
                    'scope' => $scopes,
                    'platforms' => $platforms,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)] = true;
            }
        }
        if (count($scopeIdentities) > 1) {
            $scopeDrifted = true;
        }
        $metricIdentities = [];
        foreach ($metricIdentitiesByDate as $metric => $identitiesByDate) {
            $unique = [];
            foreach ($identitiesByDate as $identities) {
                if (count($identities) !== 1) {
                    $scopeDrifted = true;
                }
                foreach ($identities as $identity) {
                    $unique[json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)] = $identity;
                }
            }
            if ($identitiesByDate !== [] && count($unique) !== 1) {
                $scopeDrifted = true;
            }
            $metricIdentities[$metric] = array_values($unique);
        }
        if ($scopeDrifted) {
            $dataGaps[] = [
                'code' => 'baseline_scope_drift',
                'message' => 'Baseline dates use different metric or platform scopes and cannot be averaged together',
            ];
        }

        return [
            'days' => $days,
            'actual_days' => $count,
            'window_start_date' => $start,
            'window_end_date' => $end,
            'avg_orders' => !$scopeDrifted && $metricValues['orders'] !== [] ? round(array_sum($metricValues['orders']) / count($metricValues['orders']), 2) : null,
            'avg_revenue' => !$scopeDrifted && $metricValues['revenue'] !== [] ? round(array_sum($metricValues['revenue']) / count($metricValues['revenue']), 2) : null,
            'avg_room_nights' => !$scopeDrifted && $metricValues['room_nights'] !== [] ? round(array_sum($metricValues['room_nights']) / count($metricValues['room_nights']), 2) : null,
            'avg_conversion' => !$scopeDrifted && $conversionValues !== [] ? round(array_sum($conversionValues) / count($conversionValues), 2) : null,
            'metric_sample_days' => [
                'orders' => count($metricValues['orders']),
                'revenue' => count($metricValues['revenue']),
                'room_nights' => count($metricValues['room_nights']),
                'conversion' => count($conversionValues),
            ],
            'metric_identities' => $metricIdentities,
            'rejected_daily_report_count' => $rejectedDailyReportCount,
            'rejected_daily_report_days' => count($rejectedDailyReportDates),
            'rejected_daily_report_reasons' => $rejectedDailyReportReasons,
            'source_scopes' => array_keys($sourceScopes),
            'data_gaps' => $dataGaps,
            'data_status' => $dailyReadGap !== null || $onlineReadGap !== null
                ? ((($dailyReadGap['migration_required'] ?? false) === true
                    || ($onlineReadGap['migration_required'] ?? false) === true)
                    ? 'migration_required'
                    : 'read_failed')
                : ($count === 0
                ? ($rejectedDailyReportCount > 0 ? 'partial' : 'missing')
                : ($dataGaps === [] ? self::DATA_OK : 'partial')),
        ];
    }

    private function dailyReportRows(array $hotelIds, string $startDate, string $endDate): array
    {
        if (empty($hotelIds)) {
            return [];
        }
        if (($schemaGap = $this->operationDailyReportTenantSchemaGap()) !== null) {
            return [['__operation_daily_report_gap' => $schemaGap]];
        }
        try {
            return Db::name('daily_reports')
                ->alias('operation_daily')
                ->join('hotels operation_hotel', 'operation_hotel.id = operation_daily.hotel_id')
                ->whereIn('operation_daily.hotel_id', array_map('intval', $hotelIds))
                ->whereColumn('operation_daily.tenant_id', 'operation_hotel.tenant_id')
                ->where('operation_hotel.tenant_id', '>', 0)
                ->whereBetween('operation_daily.report_date', [$startDate, $endDate])
                ->field('operation_daily.*')
                ->select()
                ->toArray();
        } catch (Throwable) {
            return [['__operation_daily_report_gap' => $this->dailyReportGap(
                'operation_daily_reports_read_failed',
                'Current-tenant daily reports could not be read; operation analysis failed closed.'
            )]];
        }
    }

    private function onlineRows(array $hotelIds, string $startDate, string $endDate): array
    {
        if (!$this->tableExists('online_daily_data')) {
            return [];
        }
        if (($schemaGap = $this->operationOnlineTenantSchemaGap()) !== null) {
            return [['__operation_online_gap' => $schemaGap]];
        }
        try {
            $query = Db::name('online_daily_data')
                ->alias('operation_online')
                ->join('hotels operation_online_hotel', 'operation_online_hotel.id = operation_online.system_hotel_id')
                ->whereColumn('operation_online.tenant_id', 'operation_online_hotel.tenant_id')
                ->where('operation_online_hotel.tenant_id', '>', 0)
                ->whereBetween('operation_online.data_date', [$startDate, $endDate]);
            if (!empty($hotelIds)) {
                $query->whereIn('operation_online.system_hotel_id', array_map('intval', $hotelIds));
            }
            return $query->field('operation_online.*')->select()->toArray();
        } catch (Throwable) {
            return [['__operation_online_gap' => $this->dailyReportGap(
                'operation_online_daily_data_read_failed',
                'Current-tenant OTA operation facts could not be read; operation analysis failed closed.'
            )]];
        }
    }
}
