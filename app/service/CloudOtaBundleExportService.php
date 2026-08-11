<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

final class CloudOtaBundleExportService
{
    private const TRUSTED_VALIDATION_STATUSES = [
        'normal', 'available', 'verified', 'ok', 'success', 'complete', 'completed', 'readback_verified',
    ];
    private const P0_METRIC_KEYS = [
        'ctrip' => [
            'list_exposure', 'detail_exposure', 'flow_rate',
            'order_filling_num', 'order_submit_num',
        ],
        'meituan' => ['list_exposure', 'detail_exposure', 'flow_rate'],
    ];
    /**
     * @param array<string, mixed> $binding
     * @param array<int, string> $requiredPlatforms
     * @param array<int, int> $syncTaskIdsBySource
     * @return array<string, mixed>
     */
    public function export(
        array $binding,
        string $targetDate,
        array $requiredPlatforms,
        string $outputPath,
        array $syncTaskIdsBySource
    ): array
    {
        $binding = CloudOtaBundleCodec::verifyBinding($binding);
        $sourceHotelId = (int)$binding['source_system_hotel_id'];
        $destinationHotelId = (int)$binding['destination_system_hotel_id'];
        $requiredPlatforms = $this->normalizePlatforms($requiredPlatforms);
        $sourceHotel = $this->assertSourceHotel($sourceHotelId);
        $this->assertReadbackSchema();

        $boundPlatforms = array_values(array_unique(array_map(
            static fn(array $item): string => (string)$item['platform'],
            $binding['bindings']
        )));
        foreach ($requiredPlatforms as $platform) {
            if (!in_array($platform, $boundPlatforms, true)) {
                throw new RuntimeException('cloud_binding_required_platform_missing:' . $platform);
            }
        }
        $selectedBindings = array_values(array_filter(
            $binding['bindings'],
            static fn(array $item): bool => in_array((string)$item['platform'], $requiredPlatforms, true)
        ));

        $packages = [];
        $rowCount = 0;
        $missingPlatforms = [];
        foreach ($selectedBindings as $item) {
            $source = $this->loadSource(
                (int)$item['source_data_source_id'],
                $sourceHotelId,
                (int)$sourceHotel['tenant_id'],
                (string)$item['platform']
            );
            $sourceId = (int)$item['source_data_source_id'];
            $syncTaskId = (int)($syncTaskIdsBySource[$sourceId] ?? 0);
            if ($syncTaskId <= 0) {
                throw new RuntimeException('cloud_bundle_sync_task_binding_missing:' . (string)$item['platform']);
            }
            $syncTask = $this->loadVerifiedSyncTask(
                $syncTaskId,
                $source,
                $sourceHotelId,
                (int)$sourceHotel['tenant_id'],
                (string)$item['platform'],
                $targetDate
            );
            [$rows, $targetRowCount] = $this->trustedTargetRows(
                $source,
                $syncTask,
                $sourceHotelId,
                (int)$sourceHotel['tenant_id'],
                $targetDate
            );
            $collection = $this->collectionState($syncTask, $rows, $targetRowCount);
            if ($rows === []) {
                $missingPlatforms[] = (string)$item['platform'];
            }
            $rowCount += count($rows);
            if ($rowCount > CloudOtaBundleCodec::MAX_ROWS) {
                throw new RuntimeException('cloud_bundle_row_limit_exceeded');
            }
            $packages[] = [
                'platform' => (string)$item['platform'],
                'source_data_source_id' => (int)$item['source_data_source_id'],
                'source_sync_task_id' => $syncTaskId,
                'destination_data_source_id' => (int)$item['destination_data_source_id'],
                'collection' => $collection,
                'snapshot_complete' => count($rows) === $targetRowCount,
                'source_row_count' => $targetRowCount,
                'rows' => $rows,
            ];
        }

        $bundle = CloudOtaBundleCodec::build([
            'source_system_hotel_id' => $sourceHotelId,
            'destination_system_hotel_id' => $destinationHotelId,
            'target_date' => $targetDate,
            'required_platforms' => $requiredPlatforms,
        ], $packages);
        $this->writeAtomic($outputPath, $bundle);

        return [
            'status' => $missingPlatforms === [] ? 'ready' : 'partial',
            'bundle_id' => (string)$bundle['bundle_id'],
            'payload_sha256' => (string)$bundle['payload_sha256'],
            'target_date' => (string)$bundle['target_date'],
            'source_system_hotel_id' => $sourceHotelId,
            'destination_system_hotel_id' => $destinationHotelId,
            'package_count' => count($packages),
            'row_count' => $rowCount,
            'missing_platforms' => array_values(array_unique($missingPlatforms)),
            'output_file' => realpath($outputPath) ?: $outputPath,
            'upload_allowed' => $rowCount > 0 || $missingPlatforms !== [],
            'boundary' => 'Only locally read-back-verified rows are exported; missing packages remain explicit health evidence.',
        ];
    }

    /** @return array<string, mixed> */
    public function readBindingFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('cloud_binding_file_not_readable');
        }
        if ((int)filesize($path) > 256 * 1024) {
            throw new RuntimeException('cloud_binding_file_too_large');
        }
        $decoded = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('cloud_binding_file_invalid');
        }
        return CloudOtaBundleCodec::verifyBinding($decoded);
    }

    /** @return array<string, mixed> */
    private function assertSourceHotel(int $hotelId): array
    {
        $hotel = Db::name('hotels')->where('id', $hotelId)->field('id,tenant_id,name,status')->find();
        if (!is_array($hotel) || (int)($hotel['status'] ?? 0) !== 1 || (int)($hotel['tenant_id'] ?? 0) <= 0) {
            throw new RuntimeException('cloud_bundle_source_hotel_missing_or_disabled');
        }
        return $hotel;
    }

    private function assertReadbackSchema(): void
    {
        $columns = $this->tableColumns('online_daily_data');
        foreach ([
            'tenant_id', 'system_hotel_id', 'data_source_id', 'data_date', 'source_trace_id',
            'sync_task_id', 'validation_status', 'readback_verified', 'readback_verified_at',
        ] as $column) {
            if (!isset($columns[$column])) {
                throw new RuntimeException('cloud_bundle_export_schema_missing:' . $column);
            }
        }
    }

    /** @return array<string, mixed> */
    private function loadSource(int $sourceId, int $hotelId, int $tenantId, string $platform): array
    {
        $source = Db::name('platform_data_sources')
            ->withoutField('secret_json')
            ->where('id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->find();
        if (!is_array($source)) {
            throw new RuntimeException('cloud_bundle_source_binding_missing:' . $platform);
        }
        if ((int)($source['tenant_id'] ?? 0) <= 0 || (int)($source['enabled'] ?? 0) !== 1) {
            throw new RuntimeException('cloud_bundle_source_binding_disabled:' . $platform);
        }
        return $source;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function loadVerifiedSyncTask(
        int $taskId,
        array $source,
        int $hotelId,
        int $tenantId,
        string $platform,
        string $targetDate
    ): array {
        $taskColumns = $this->tableColumns('platform_data_sync_tasks');
        $query = Db::name('platform_data_sync_tasks')
            ->where('id', $taskId)
            ->where('data_source_id', (int)$source['id'])
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform);
        if (isset($taskColumns['tenant_id'])) {
            $query->where('tenant_id', $tenantId);
        }
        $task = $query->find();
        if (!is_array($task)
            || !in_array(strtolower(trim((string)($task['status'] ?? ''))), ['success', 'partial_success'], true)
        ) {
            throw new RuntimeException('cloud_bundle_sync_task_missing_or_incomplete:' . $platform);
        }
        try {
            $stats = json_decode((string)($task['stats_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new RuntimeException('cloud_bundle_sync_task_receipt_invalid:' . $platform, 0, $exception);
        }
        $receipt = is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [];
        $rowIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($receipt['row_ids'] ?? null) ? $receipt['row_ids'] : []
        ), static fn(int $id): bool => $id > 0)));
        sort($rowIds, SORT_NUMERIC);
        if (($receipt['readback_verified'] ?? false) !== true
            || (int)($receipt['sync_task_id'] ?? 0) !== $taskId
            || (int)($receipt['data_source_id'] ?? 0) !== (int)$source['id']
            || (int)($receipt['system_hotel_id'] ?? 0) !== $hotelId
            || strtolower(trim((string)($receipt['platform'] ?? ''))) !== $platform
            || substr(trim((string)($receipt['target_date'] ?? '')), 0, 10) !== $targetDate
            || (int)($receipt['readback_count'] ?? 0) !== count($rowIds)
            || $rowIds === []
        ) {
            throw new RuntimeException('cloud_bundle_sync_task_receipt_invalid:' . $platform);
        }
        $receipt['row_ids'] = $rowIds;
        $task['run_readback'] = $receipt;
        return $task;
    }

    /**
     * @param array<string, mixed> $source
     * @return array{0:array<int, array<string, mixed>>,1:int}
     */
    private function trustedTargetRows(array $source, array $syncTask, int $hotelId, int $tenantId, string $targetDate): array
    {
        $columns = $this->tableColumns('online_daily_data');
        $fields = array_values(array_intersect(CloudOtaBundleCodec::rowFields(), array_keys($columns)));
        $selectFields = $fields;
        if (isset($columns['raw_data'])) {
            $selectFields[] = 'raw_data';
        }
        $base = Db::name('online_daily_data')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('data_source_id', (int)$source['id'])
            ->where('sync_task_id', (int)$syncTask['id'])
            ->where('data_date', $targetDate);
        $targetRowIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array)(clone $base)->order('id', 'asc')->column('id')
        ), static fn(int $id): bool => $id > 0)));
        sort($targetRowIds, SORT_NUMERIC);
        $targetRowCount = count($targetRowIds);
        $receiptRowIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array)($syncTask['run_readback']['row_ids'] ?? [])
        ), static fn(int $id): bool => $id > 0)));
        sort($receiptRowIds, SORT_NUMERIC);
        $rows = $base
            ->whereIn('id', $receiptRowIds)
            ->where('readback_verified', 1)
            ->whereIn('validation_status', self::TRUSTED_VALIDATION_STATUSES)
            ->order('id', 'asc')
            ->limit(CloudOtaBundleCodec::MAX_ROWS + 1)
            ->field('id,' . implode(',', array_values(array_unique($selectFields))))
            ->select()
            ->toArray();
        if (count($rows) > CloudOtaBundleCodec::MAX_ROWS) {
            throw new RuntimeException(
                'cloud_bundle_source_row_limit_exceeded:' . strtolower((string)($source['platform'] ?? 'unknown'))
            );
        }

        $normalized = [];
        $trustedRowIds = [];
        foreach ($rows as $row) {
            $trustedRowIds[] = (int)($row['id'] ?? 0);
            $transportRow = CloudOtaBundleCodec::allowlistedRow($row);
            $originIngestionMethod = $this->safeOriginIngestionMethod($row, $syncTask, $source);
            if ($originIngestionMethod !== null) {
                $transportRow['ingestion_method'] = $originIngestionMethod;
            }
            $dataType = strtolower(trim((string)($transportRow['data_type'] ?? '')));
            if (in_array($dataType, ['traffic', 'flow', 'conversion'], true)) {
                $transportRow = array_merge(
                    $transportRow,
                    $this->safeP0Evidence($row, strtolower((string)($source['platform'] ?? '')))
                );
            } else {
                $captureSource = $this->safeCaptureSource($row['raw_data'] ?? null);
                if ($captureSource !== null) {
                    $transportRow['capture_source'] = $captureSource;
                }
            }
            $normalized[] = $transportRow;
        }
        sort($trustedRowIds, SORT_NUMERIC);
        if (array_diff($trustedRowIds, $targetRowIds) !== []
            || array_diff($trustedRowIds, $receiptRowIds) !== []
        ) {
            throw new RuntimeException(
                'cloud_bundle_sync_task_row_identity_mismatch:' . strtolower((string)($source['platform'] ?? 'unknown'))
            );
        }
        return [$normalized, $targetRowCount];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $syncTask @param array<string, mixed> $source */
    private function safeOriginIngestionMethod(array $row, array $syncTask, array $source): ?string
    {
        foreach ([
            $row['ingestion_method'] ?? null,
            $syncTask['ingestion_method'] ?? null,
            $source['ingestion_method'] ?? null,
        ] as $candidate) {
            $method = strtolower(trim((string)$candidate));
            if ($method === '') {
                continue;
            }
            return in_array($method, ['browser_profile', 'profile_browser', 'local_collector'], true)
                ? $method
                : null;
        }
        return null;
    }

    private function safeCaptureSource(mixed $rawData): ?string
    {
        if (is_string($rawData) && trim($rawData) !== '') {
            try {
                $rawData = json_decode($rawData, true, 64, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return null;
            }
        }
        if (!is_array($rawData)) {
            return null;
        }

        $containers = [$rawData];
        foreach (['row', 'source_row', 'capture_evidence'] as $key) {
            if (is_array($rawData[$key] ?? null)) {
                $containers[] = $rawData[$key];
            }
        }
        foreach (['row', 'source_row'] as $key) {
            $sourceRow = is_array($rawData[$key] ?? null) ? $rawData[$key] : [];
            if (is_array($sourceRow['capture_evidence'] ?? null)) {
                $containers[] = $sourceRow['capture_evidence'];
            }
        }

        $candidates = [];
        foreach ($containers as $container) {
            foreach (['_capture_source', 'capture_source'] as $key) {
                $value = strtolower(trim((string)($container[$key] ?? '')));
                if ($value !== '') {
                    $candidates[$value] = true;
                }
            }
        }
        if (count($candidates) !== 1) {
            return null;
        }

        $captureSource = (string)array_key_first($candidates);
        return mb_strlen($captureSource) <= 160
            && preg_match(
                '/^(?:xhr|fetch|same_origin_api|browser_response|network_response)(?::[a-z0-9._-]+)*$/D',
                $captureSource
            ) === 1
                ? $captureSource
                : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function safeP0Evidence(array $row, string $platform): array
    {
        if (!isset(self::P0_METRIC_KEYS[$platform])) {
            throw new RuntimeException('cloud_bundle_export_p0_platform_invalid');
        }
        $raw = $this->decodeRawData($row['raw_data'] ?? null);
        if ($raw === []) {
            throw new RuntimeException('cloud_bundle_export_p0_raw_data_missing:' . $platform);
        }

        $rowTraceId = trim((string)($row['source_trace_id'] ?? ''));
        if ($rowTraceId === '' || mb_strlen($rowTraceId) > 200 || $this->containsCredentialMaterial($rowTraceId)) {
            throw new RuntimeException('cloud_bundle_export_p0_source_trace_invalid:' . $platform);
        }
        $rawTraceId = $this->singleEvidenceValue(
            $this->evidenceContainers($raw),
            ['source_trace_id', '_source_trace_id', 'trace_id', '_trace_id'],
            200
        );
        if ($rawTraceId === '' || !hash_equals($rowTraceId, $rawTraceId)) {
            throw new RuntimeException('cloud_bundle_export_p0_source_trace_mismatch:' . $platform);
        }

        $sourceUrlHash = strtolower($this->singleEvidenceValue(
            $this->evidenceContainers($raw),
            ['source_url_hash', '_source_url_hash', 'url_hash', '_url_hash'],
            64
        ));
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceUrlHash) !== 1) {
            throw new RuntimeException('cloud_bundle_export_p0_source_url_hash_invalid:' . $platform);
        }
        $captureSource = $this->safeCaptureSource($raw);
        if ($captureSource === null) {
            throw new RuntimeException('cloud_bundle_export_p0_capture_source_invalid:' . $platform);
        }

        $facts = $raw['field_facts'] ?? null;
        if (!is_array($facts)
            || $facts === []
            || array_keys($facts) !== range(0, count($facts) - 1)
            || count($facts) > 64
        ) {
            throw new RuntimeException('cloud_bundle_export_p0_field_facts_invalid:' . $platform);
        }

        $allowedMetrics = self::P0_METRIC_KEYS[$platform];
        $normalizedFacts = [];
        $seen = [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                throw new RuntimeException('cloud_bundle_export_p0_field_fact_invalid:' . $platform);
            }
            if ($this->containsCredentialMaterial($fact)) {
                throw new RuntimeException('cloud_bundle_export_p0_credential_evidence_rejected:' . $platform);
            }
            $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
            if (!in_array($metricKey, $allowedMetrics, true)) {
                continue;
            }
            if (isset($seen[$metricKey])) {
                throw new RuntimeException('cloud_bundle_export_p0_metric_duplicate:' . $metricKey);
            }
            $seen[$metricKey] = true;
            if (!array_key_exists($metricKey, $row)
                || !is_numeric($row[$metricKey])
                || !is_finite((float)$row[$metricKey])
            ) {
                throw new RuntimeException('cloud_bundle_export_p0_metric_value_invalid:' . $metricKey);
            }

            $sourceKey = $this->strictEvidenceText($fact['source_key'] ?? null, 160);
            $sourcePath = $this->strictEvidenceText($fact['source_path'] ?? null, 500);
            if ($sourceKey === ''
                || $sourcePath === ''
                || (!str_contains($sourcePath, '.') && !str_contains($sourcePath, '[') && !str_contains($sourcePath, '/'))
                || str_contains($sourcePath, '://')
                || str_starts_with($sourcePath, '//')
                || $this->credentialFieldReference($sourceKey)
                || $this->credentialFieldReference($sourcePath)
            ) {
                throw new RuntimeException('cloud_bundle_export_p0_fact_source_invalid:' . $metricKey);
            }
            if (trim((string)($fact['storage_field'] ?? '')) !== 'online_daily_data.' . $metricKey
                || ($fact['stored_value_present'] ?? null) !== true
                || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured'
            ) {
                throw new RuntimeException('cloud_bundle_export_p0_fact_contract_invalid:' . $metricKey);
            }

            $factEvidence = is_array($fact['capture_evidence'] ?? null) ? $fact['capture_evidence'] : [];
            $factContainers = [$fact, $factEvidence];
            $factTraceId = $this->singleEvidenceValue(
                $factContainers,
                ['source_trace_id', '_source_trace_id', 'trace_id', '_trace_id'],
                200
            );
            $factUrlHash = strtolower($this->singleEvidenceValue(
                $factContainers,
                ['source_url_hash', '_source_url_hash', 'url_hash', '_url_hash'],
                64
            ));
            $factCaptureSource = strtolower($this->singleEvidenceValue(
                $factContainers,
                ['capture_source', '_capture_source'],
                160
            ));
            if ($factTraceId === ''
                || !hash_equals($rowTraceId, $factTraceId)
                || preg_match('/^[a-f0-9]{64}$/D', $factUrlHash) !== 1
                || !hash_equals($sourceUrlHash, $factUrlHash)
            ) {
                throw new RuntimeException('cloud_bundle_export_p0_fact_evidence_mismatch:' . $metricKey);
            }
            if ($factCaptureSource !== '' && preg_match(
                '/^(?:xhr|fetch|same_origin_api|browser_response|network_response)(?::[a-z0-9._-]+)*$/D',
                $factCaptureSource
            ) !== 1) {
                throw new RuntimeException('cloud_bundle_export_p0_fact_capture_source_invalid:' . $metricKey);
            }
            if ($platform === 'meituan'
                && ($factCaptureSource === '' || !hash_equals($captureSource, $factCaptureSource))
            ) {
                throw new RuntimeException('cloud_bundle_export_meituan_fact_capture_source_mismatch:' . $metricKey);
            }

            $transportEvidence = [
                'source_trace_id' => $factTraceId,
                'source_url_hash' => $factUrlHash,
            ];
            if ($factCaptureSource !== '') {
                $transportEvidence['capture_source'] = $factCaptureSource;
            }
            $normalizedFacts[] = [
                'metric_key' => $metricKey,
                'source_key' => $sourceKey,
                'source_path' => $sourcePath,
                'storage_field' => 'online_daily_data.' . $metricKey,
                'stored_value_present' => true,
                'status' => 'captured',
                'capture_evidence' => $transportEvidence,
            ];
        }
        if ($normalizedFacts === []) {
            throw new RuntimeException('cloud_bundle_export_p0_field_facts_missing:' . $platform);
        }
        if ($platform === 'meituan' && array_diff($allowedMetrics, array_keys($seen)) !== []) {
            throw new RuntimeException('cloud_bundle_export_meituan_field_facts_incomplete');
        }
        usort($normalizedFacts, static fn(array $left, array $right): int => strcmp(
            (string)$left['metric_key'],
            (string)$right['metric_key']
        ));
        return [
            'source_url_hash' => $sourceUrlHash,
            'capture_source' => $captureSource,
            'field_facts' => $normalizedFacts,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeRawData(mixed $rawData): array
    {
        if (is_array($rawData)) {
            return $rawData;
        }
        if (!is_string($rawData) || trim($rawData) === '') {
            return [];
        }
        try {
            $decoded = json_decode($rawData, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $raw @return array<int, array<string, mixed>> */
    private function evidenceContainers(array $raw): array
    {
        $containers = [$raw];
        foreach (['row', 'source_row', 'capture_evidence'] as $key) {
            if (is_array($raw[$key] ?? null)) {
                $containers[] = $raw[$key];
            }
        }
        foreach (['row', 'source_row'] as $key) {
            $sourceRow = is_array($raw[$key] ?? null) ? $raw[$key] : [];
            if (is_array($sourceRow['capture_evidence'] ?? null)) {
                $containers[] = $sourceRow['capture_evidence'];
            }
        }
        return $containers;
    }

    /**
     * @param array<int, array<string, mixed>> $containers
     * @param array<int, string> $keys
     */
    private function singleEvidenceValue(array $containers, array $keys, int $limit): string
    {
        $values = [];
        foreach ($containers as $container) {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $container)) {
                    continue;
                }
                $value = $this->strictEvidenceText($container[$key], $limit);
                if ($value !== '') {
                    $values[$value] = true;
                }
            }
        }
        if (count($values) > 1) {
            throw new RuntimeException('cloud_bundle_export_p0_evidence_conflict');
        }
        return count($values) === 1 ? (string)array_key_first($values) : '';
    }

    private function strictEvidenceText(mixed $value, int $limit): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new RuntimeException('cloud_bundle_export_p0_evidence_shape_invalid');
        }
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) > $limit || $this->containsCredentialMaterial($text)) {
            throw new RuntimeException('cloud_bundle_export_p0_evidence_sensitive_or_oversized');
        }
        return $text;
    }

    private function containsCredentialMaterial(mixed $value, int $depth = 0): bool
    {
        if ($depth > 6) {
            return true;
        }
        if (is_array($value)) {
            if (count($value) > 64) {
                return true;
            }
            foreach ($value as $key => $item) {
                if (preg_match('/^(?:authorization|cookie|token|password|secret|session|headers?|profile)$/i', (string)$key) === 1
                    || $this->containsCredentialMaterial($item, $depth + 1)
                ) {
                    return true;
                }
            }
            return false;
        }
        if (!is_scalar($value) || $value === null) {
            return false;
        }
        $text = trim((string)$value);
        return preg_match('/(?:authorization["\x27]?\s*[:=]|bearer\s+[a-z0-9._~+\/-]{8,}|(?:cookie|token|password|secret|session)["\x27]?\s*[:=]\s*["\x27]?\S{4,})/i', $text) === 1
            || preg_match('#https://qyapi\.weixin\.qq\.com/cgi-bin/webhook/send\?key=#i', $text) === 1;
    }

    private function credentialFieldReference(string $value): bool
    {
        return preg_match(
            '/(?:^|[.\[\]\/\\:_-])(?:authorization|cookie|token|password|secret|session|headers?)(?:$|[.\[\]\/\\:_-])/i',
            $value
        ) === 1;
    }

    /** @param array<string, mixed> $syncTask @param array<int, array<string, mixed>> $rows @return array<string, string> */
    private function collectionState(array $syncTask, array $rows, int $targetRowCount): array
    {
        $taskStatus = strtolower(trim((string)($syncTask['status'] ?? '')));
        $snapshotComplete = count($rows) === $targetRowCount;

        if ($rows !== []) {
            $status = $taskStatus === 'success' && $snapshotComplete ? 'success' : 'partial';
            $message = $status === 'success' ? 'target_date_rows_readback_verified' : 'target_date_rows_verified_with_source_warning';
        } elseif ($targetRowCount > 0) {
            $status = 'failed';
            $message = 'sync_task_rows_exist_but_are_untrusted';
        } else {
            $status = 'target_date_missing';
            $message = 'sync_task_target_date_rows_missing';
        }

        return [
            'status' => $status,
            'message' => $message,
            'last_sync_time' => trim((string)($syncTask['finished_at'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $bundle */
    private function writeAtomic(string $outputPath, array $bundle): void
    {
        $outputPath = trim($outputPath);
        if ($outputPath === '' || strtolower(pathinfo($outputPath, PATHINFO_EXTENSION)) !== 'json') {
            throw new RuntimeException('cloud_bundle_output_file_must_be_json');
        }
        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('cloud_bundle_output_directory_create_failed');
        }
        $json = json_encode(
            $bundle,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (strlen($json) > CloudOtaBundleCodec::MAX_FILE_BYTES) {
            throw new RuntimeException('cloud_bundle_file_limit_exceeded');
        }
        $tempPath = $outputPath . '.part-' . bin2hex(random_bytes(4));
        if (file_put_contents($tempPath, $json, LOCK_EX) !== strlen($json)) {
            @unlink($tempPath);
            throw new RuntimeException('cloud_bundle_write_failed');
        }
        @chmod($tempPath, 0640);
        if (!rename($tempPath, $outputPath)) {
            @unlink($tempPath);
            throw new RuntimeException('cloud_bundle_atomic_rename_failed');
        }
    }

    /** @return array<string, bool> */
    private function tableColumns(string $table): array
    {
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            return array_fill_keys(array_column($rows, 'Field'), true);
        } catch (\Throwable $exception) {
            throw new RuntimeException('cloud_bundle_table_unavailable:' . $table, 0, $exception);
        }
    }

    /** @param array<int, string> $platforms @return array<int, string> */
    private function normalizePlatforms(array $platforms): array
    {
        $normalized = [];
        foreach ($platforms as $platform) {
            $platform = strtolower(trim((string)$platform));
            if (!in_array($platform, ['ctrip', 'meituan'], true)) {
                throw new RuntimeException('cloud_bundle_platform_invalid:' . $platform);
            }
            $normalized[] = $platform;
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);
        if ($normalized === []) {
            throw new RuntimeException('cloud_bundle_required_platforms_missing');
        }
        return $normalized;
    }
}
