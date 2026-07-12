<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class CtripCompetitionCirclePersistenceService
{
    public const DATA_TYPE = 'competitor';
    public const DIMENSION = 'competition_circle_hotel';
    public const INGESTION_METHOD = 'manual_cookie_api';
    public const BACKFILL_INGESTION_METHOD = 'historical_backfill';

    /**
     * Competition-circle rows are hotel entities with the three daily business
     * facts plus at least one rank fact. This deliberately excludes generic
     * business, traffic, advertising and catalog rows.
     */
    public static function hasCompetitionCircleSignature(array $row): bool
    {
        $hotelId = self::platformHotelId($row);
        $hotelName = self::firstScalar($row, ['hotelName', 'hotel_name', 'HotelName', 'name']);
        if ($hotelId === '' || $hotelName === '') {
            return false;
        }

        foreach ([
            ['amount', 'Amount'],
            ['quantity', 'Quantity'],
            ['bookOrderNum', 'book_order_num'],
        ] as $requiredGroup) {
            if (!self::hasAnyKey($row, $requiredGroup)) {
                return false;
            }
        }

        return self::hasAnyKey($row, [
            'amountRank',
            'amount_rank',
            'quantityRank',
            'quantity_rank',
            'bookOrderNumRank',
            'book_order_num_rank',
            'commentScoreRank',
            'comment_score_rank',
            'totalDetailNumRank',
            'convertionRateRank',
            'qunarCommentScoreRank',
            'qunarDetailVisitorsRank',
            'qunarDetailCRRank',
        ]);
    }

    /**
     * @return array{
     *   data_type:string,
     *   dimension:string,
     *   compare_type:string,
     *   comment_score:?float,
     *   qunar_comment_score:?float,
     *   validation_status:string,
     *   validation_flag_codes:array<int,string>
     * }
     */
    public static function normalizeRowSemantics(array $row, array $context = []): array
    {
        $selfHotelIds = self::normalizeHotelIds($context['self_hotel_ids'] ?? []);
        $hotelId = self::platformHotelId($row);
        $isSelf = $hotelId !== '' && isset($selfHotelIds[$hotelId]);

        $commentScore = self::nullableScore($row, [
            'commentScore',
            'comment_score',
            'score',
            'avgScore',
        ]);
        $qunarScore = self::nullableScore($row, [
            'qunarCommentScore',
            'qunar_comment_score',
            'qunarScore',
        ]);
        $flagCodes = [];
        if ($commentScore === null) {
            $flagCodes[] = 'field_missing:comment_score';
        }
        if ($qunarScore === null) {
            $flagCodes[] = 'field_missing:qunar_comment_score';
        }

        return [
            'data_type' => self::DATA_TYPE,
            'dimension' => self::DIMENSION,
            'compare_type' => $isSelf ? 'self' : 'competitor',
            'comment_score' => $commentScore,
            'qunar_comment_score' => $qunarScore,
            'validation_status' => $flagCodes === [] ? 'normal' : 'partial',
            'validation_flag_codes' => $flagCodes,
        ];
    }

    /**
     * Historical migration traces prove only which local row was transformed;
     * they are not evidence that the original platform response was retained.
     */
    public static function buildLegacyBackfillFields(array $row, array $selfHotelIds = []): array
    {
        $raw = self::decodeRawRow($row);
        $semantics = self::normalizeRowSemantics($raw, ['self_hotel_ids' => $selfHotelIds]);
        $snapshotTime = self::normalizeDateTime($row['update_time'] ?? $row['create_time'] ?? null);
        $flagCodes = array_values(array_unique(array_merge(
            $semantics['validation_flag_codes'],
            ['historical_source_trace_unavailable']
        )));
        if ($snapshotTime === null) {
            $dataDate = self::normalizeDate($row['data_date'] ?? null);
            if ($dataDate !== '') {
                $snapshotTime = $dataDate . ' 23:59:59';
                $flagCodes[] = 'snapshot_time_inferred_from_data_date';
            }
        }

        return array_merge($semantics, [
            'source_trace_id' => self::buildLegacyTraceId($row),
            'snapshot_time' => $snapshotTime,
            'ingestion_method' => self::BACKFILL_INGESTION_METHOD,
            'validation_status' => 'unverified',
            'validation_flag_codes' => $flagCodes,
        ]);
    }

    public static function buildCaptureTraceId(array $context): string
    {
        $parts = [
            (string)($context['data_source_id'] ?? ''),
            (string)($context['sync_task_id'] ?? ''),
            (string)($context['system_hotel_id'] ?? ''),
            (string)($context['data_date'] ?? ''),
            (string)($context['fingerprint'] ?? ''),
        ];
        return 'ctrip-cc:' . hash('sha256', implode('|', $parts));
    }

    public static function buildLegacyTraceId(array $row): string
    {
        $rawHash = hash('sha256', (string)($row['raw_data'] ?? ''));
        $identity = implode('|', [
            (string)($row['id'] ?? ''),
            (string)($row['system_hotel_id'] ?? ''),
            (string)($row['hotel_id'] ?? ''),
            (string)($row['data_date'] ?? ''),
            $rawHash,
        ]);
        return 'legacy_backfill:' . hash('sha256', $identity);
    }

    /**
     * A parser without a structured source and sync task may discover the same
     * row again, but it must never downgrade a row that already has complete
     * acquisition evidence.
     */
    public static function shouldPreserveExistingEvidence(array $existing, array $context): bool
    {
        $existingComplete = (int)($existing['data_source_id'] ?? 0) > 0
            && (int)($existing['sync_task_id'] ?? 0) > 0
            && trim((string)($existing['source_trace_id'] ?? '')) !== '';
        $incomingComplete = (int)($context['data_source_id'] ?? 0) > 0
            && (int)($context['sync_task_id'] ?? 0) > 0
            && trim((string)($context['source_trace_id'] ?? '')) !== '';

        return $existingComplete && !$incomingComplete;
    }

    public function resolveOrCreateDataSource(
        int $systemHotelId,
        int $userId = 0,
        array $safeConfig = []
    ): int {
        $tenantId = (int)(Db::name('hotels')->where('id', $systemHotelId)->value('tenant_id') ?? 0);
        $query = Db::name('platform_data_sources')
            ->where('system_hotel_id', $systemHotelId)
            ->where('platform', 'ctrip')
            ->where('data_type', self::DATA_TYPE)
            ->where('ingestion_method', self::INGESTION_METHOD);
        $existing = $query->order('id', 'asc')->find();
        $now = date('Y-m-d H:i:s');
        $config = [
            'scope' => 'competition_circle',
            'system_hotel_id' => $systemHotelId,
            'platform_hotel_id' => trim((string)($safeConfig['platform_hotel_id'] ?? '')),
            'config_id' => trim((string)($safeConfig['config_id'] ?? '')),
            'credential_storage' => 'ota_credential_vault_reference_only',
        ];
        $payload = [
            'tenant_id' => $tenantId > 0 ? $tenantId : null,
            'system_hotel_id' => $systemHotelId,
            'user_id' => $userId > 0 ? $userId : null,
            'name' => '携程手动竞争圈采集',
            'platform' => 'ctrip',
            'data_type' => self::DATA_TYPE,
            'ingestion_method' => self::INGESTION_METHOD,
            'status' => 'ready',
            'enabled' => 1,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'secret_json' => null,
            'updated_by' => $userId > 0 ? $userId : null,
            'update_time' => $now,
        ];

        if ($existing) {
            Db::name('platform_data_sources')->where('id', (int)$existing['id'])->update($payload);
            return (int)$existing['id'];
        }

        $payload['created_by'] = $userId > 0 ? $userId : null;
        $payload['create_time'] = $now;
        return (int)Db::name('platform_data_sources')->insertGetId($payload);
    }

    public function startSyncTask(
        int $dataSourceId,
        int $systemHotelId,
        int $userId = 0,
        string $triggerType = 'manual'
    ): int {
        $now = date('Y-m-d H:i:s');
        $tenantId = (int)(Db::name('hotels')->where('id', $systemHotelId)->value('tenant_id') ?? 0);
        return (int)Db::name('platform_data_sync_tasks')->insertGetId([
            'tenant_id' => $tenantId > 0 ? $tenantId : null,
            'data_source_id' => $dataSourceId,
            'system_hotel_id' => $systemHotelId,
            'platform' => 'ctrip',
            'data_type' => self::DATA_TYPE,
            'ingestion_method' => self::INGESTION_METHOD,
            'trigger_type' => $triggerType,
            'status' => 'running',
            'attempt_count' => 1,
            'max_attempts' => 1,
            'started_at' => $now,
            'requested_by' => $userId > 0 ? $userId : null,
            'message' => '携程竞争圈数据持久化开始',
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    public function finishSyncTask(int $taskId, int $dataSourceId, array $stats): void
    {
        $now = date('Y-m-d H:i:s');
        Db::name('platform_data_sync_tasks')->where('id', $taskId)->update([
            'status' => 'success',
            'finished_at' => $now,
            'message' => '携程竞争圈数据持久化完成',
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'update_time' => $now,
        ]);
        Db::name('platform_data_sources')->where('id', $dataSourceId)->update([
            'last_sync_time' => $now,
            'last_sync_status' => 'success',
            'last_error' => null,
            'update_time' => $now,
        ]);
    }

    public function failSyncTask(int $taskId, int $dataSourceId, string $message): void
    {
        $now = date('Y-m-d H:i:s');
        $safeMessage = mb_substr(trim($message), 0, 500);
        Db::name('platform_data_sync_tasks')->where('id', $taskId)->update([
            'status' => 'failed',
            'finished_at' => $now,
            'message' => $safeMessage !== '' ? $safeMessage : 'competition_circle_persistence_failed',
            'update_time' => $now,
        ]);
        Db::name('platform_data_sources')->where('id', $dataSourceId)->update([
            'last_sync_time' => $now,
            'last_sync_status' => 'failed',
            'last_error' => $safeMessage,
            'update_time' => $now,
        ]);
    }

    /**
     * @return array{saved_count:int,inserted_count:int,updated_count:int,skipped_count:int,row_ids:array<int,int>}
     */
    public function persistRows(
        array $rows,
        string $dataDate,
        int $systemHotelId,
        array $context
    ): array {
        $columns = OnlineDailyDataPersistenceService::getColumns();
        $now = self::normalizeDateTime($context['fetched_at'] ?? null) ?? date('Y-m-d H:i:s');
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $rowIds = [];
        $contextDataSourceId = (int)($context['data_source_id'] ?? 0);
        $contextSyncTaskId = (int)($context['sync_task_id'] ?? 0);
        $evidenceFlagCodes = [];
        if ($contextDataSourceId <= 0) {
            $evidenceFlagCodes[] = 'evidence_missing:data_source_id';
        }
        if ($contextSyncTaskId <= 0) {
            $evidenceFlagCodes[] = 'evidence_missing:sync_task_id';
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !self::hasCompetitionCircleSignature($row)) {
                $skipped++;
                continue;
            }
            $hotelId = self::platformHotelId($row);
            $hotelName = self::firstScalar($row, ['hotelName', 'hotel_name', 'HotelName', 'name']);
            $rowDate = self::normalizeDate(
                $row['dataDate']
                ?? $row['date']
                ?? $row['data_date']
                ?? $row['statDate']
                ?? $dataDate
            ) ?: $dataDate;
            $semantics = self::normalizeRowSemantics($row, $context);
            $data = [
                'tenant_id' => $systemHotelId,
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'system_hotel_id' => $systemHotelId,
                'data_date' => $rowDate,
                'amount' => self::numericValue($row, ['amount', 'Amount', 'totalAmount', 'total_amount']),
                'quantity' => (int)self::numericValue($row, ['quantity', 'Quantity', 'roomNights', 'room_nights']),
                'book_order_num' => (int)self::numericValue($row, ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count']),
                'comment_score' => $semantics['comment_score'],
                'qunar_comment_score' => $semantics['qunar_comment_score'],
                'raw_data' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'source' => 'ctrip',
                'platform' => 'Ctrip',
                'data_type' => self::DATA_TYPE,
                'dimension' => self::DIMENSION,
                'compare_type' => $semantics['compare_type'],
                'ingestion_method' => (string)($context['ingestion_method'] ?? self::INGESTION_METHOD),
                'data_source_id' => $contextDataSourceId ?: null,
                'sync_task_id' => $contextSyncTaskId ?: null,
                'source_trace_id' => (string)($context['source_trace_id'] ?? ''),
                'data_period' => 'historical_daily',
                'is_final' => 1,
                'update_time' => $now,
            ];
            $data = OnlineDailyDataPersistenceService::applyValidationFields($data, $columns);
            if (isset($columns['snapshot_time'])) {
                $data['snapshot_time'] = $now;
            }
            $validationFlagCodes = array_values(array_unique(array_merge(
                $semantics['validation_flag_codes'],
                $evidenceFlagCodes
            )));
            $semanticStatus = $evidenceFlagCodes === [] ? $semantics['validation_status'] : 'partial';
            self::appendQualityFlags($data, $validationFlagCodes, $columns, $semanticStatus);
            $data = array_intersect_key($data, $columns);

            $existing = $this->findExistingCompetitionRow($hotelId, $rowDate, $systemHotelId, $columns);
            if ($existing) {
                $id = (int)$existing['id'];
                if (self::shouldPreserveExistingEvidence($existing, array_merge($context, [
                    'source_trace_id' => (string)($data['source_trace_id'] ?? ''),
                ]))) {
                    $skipped++;
                    $rowIds[] = $id;
                    continue;
                }
                Db::name('online_daily_data')->where('id', $id)->update($data);
                $updated++;
                $rowIds[] = $id;
                continue;
            }

            if (isset($columns['create_time'])) {
                $data['create_time'] = $now;
            }
            $id = (int)Db::name('online_daily_data')->insertGetId($data);
            $inserted++;
            $rowIds[] = $id;
        }

        return [
            'saved_count' => $inserted + $updated,
            'inserted_count' => $inserted,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'row_ids' => $rowIds,
        ];
    }

    private function findExistingCompetitionRow(
        string $hotelId,
        string $dataDate,
        int $systemHotelId,
        array $columns
    ): ?array {
        $query = Db::name('online_daily_data')
            ->where('source', 'ctrip')
            ->where('hotel_id', $hotelId)
            ->where('data_date', $dataDate)
            ->where('system_hotel_id', $systemHotelId);

        if (isset($columns['data_type'], $columns['dimension'])) {
            $query->where('data_type', self::DATA_TYPE)
                ->where('dimension', self::DIMENSION);
        }
        if (isset($columns['data_period'])) {
            $query->where('data_period', 'historical_daily');
        }

        $row = $query->order('id', 'desc')->find();
        return is_array($row) ? $row : null;
    }

    private static function appendQualityFlags(
        array &$data,
        array $codes,
        array $columns,
        string $semanticStatus
    ): void {
        if (!isset($columns['validation_flags'])) {
            return;
        }
        $flags = json_decode((string)($data['validation_flags'] ?? '[]'), true);
        if (!is_array($flags)) {
            $flags = [];
        }
        foreach (array_values(array_unique($codes)) as $code) {
            $flags[] = [
                'level' => 'warning',
                'field' => str_starts_with($code, 'field_missing:') ? substr($code, 14) : 'source_trace_id',
                'code' => $code,
                'message' => $code,
            ];
        }
        $data['validation_flags'] = json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (
            isset($columns['validation_status'])
            && $semanticStatus !== 'normal'
            && (string)($data['validation_status'] ?? '') !== 'abnormal'
        ) {
            $data['validation_status'] = $semanticStatus;
        }
    }

    private static function decodeRawRow(array $row): array
    {
        if (is_array($row['raw_data'] ?? null)) {
            return $row['raw_data'];
        }
        $decoded = json_decode((string)($row['raw_data'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function normalizeHotelIds(mixed $ids): array
    {
        $set = [];
        foreach (is_array($ids) ? $ids : [$ids] as $id) {
            if (is_array($id) || is_object($id)) {
                continue;
            }
            $value = trim((string)$id);
            if ($value !== '') {
                $set[$value] = true;
            }
        }
        return $set;
    }

    private static function nullableScore(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
                continue;
            }
            if (!is_numeric($row[$key])) {
                return null;
            }
            $value = (float)$row[$key];
            return $value > 0.0 && $value <= 5.0 ? $value : null;
        }
        return null;
    }

    private static function platformHotelId(array $row): string
    {
        return self::firstScalar($row, [
            'masterHotelId',
            'masterhotelid',
            'master_hotel_id',
            'hotelId',
            'hotel_id',
            'HotelId',
            'hotelID',
            'ota_hotel_id',
            'ctrip_hotel_id',
        ]);
    }

    private static function firstScalar(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || is_array($row[$key]) || is_object($row[$key])) {
                continue;
            }
            $value = trim((string)$row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private static function hasAnyKey(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return true;
            }
        }
        return false;
    }

    private static function numericValue(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_numeric($row[$key])) {
                return (float)$row[$key];
            }
        }
        return 0.0;
    }

    private static function normalizeDate(mixed $value): string
    {
        $text = trim((string)($value ?? ''));
        $timestamp = $text !== '' ? strtotime($text) : false;
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private static function normalizeDateTime(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        $timestamp = $text !== '' ? strtotime($text) : false;
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
