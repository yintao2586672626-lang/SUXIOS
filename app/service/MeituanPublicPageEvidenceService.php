<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

/**
 * Persists manually observed Meituan consumer-page facts without promoting
 * merchant-console data or a database readback into source verification.
 */
final class MeituanPublicPageEvidenceService
{
    public const SOURCE = 'meituan_public_page';
    public const PLATFORM = 'meituan';
    public const DATA_TYPE = 'public_profile';
    public const DIMENSION = 'public_hotel_profile';
    public const PROFILE_SCHEMA_VERSION = 1;

    private const MAX_TEXT_LENGTH = 2000;
    private const MAX_LIST_ITEMS = 200;
    private const BLOCKED_HOSTS = [
        'eb.meituan.com',
        'ebooking.meituan.com',
        'hms.meituan.com',
        'pms.meituan.com',
        'me.meituan.com',
    ];

    /** @return array<string, mixed> */
    public function saveObservation(int $systemHotelId, array $input, int $actorId): array
    {
        if ($systemHotelId <= 0) {
            throw new \InvalidArgumentException('system_hotel_id must be positive');
        }
        $hotel = Db::name('hotels')->where('id', $systemHotelId)->find();
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if (!is_array($hotel) || $tenantId <= 0) {
            throw new RuntimeException('Meituan public-page evidence requires a valid hotel tenant binding.');
        }

        $otaHotelId = trim((string)($input['ota_hotel_id'] ?? $input['poi_id'] ?? ''));
        if (preg_match('/^[1-9][0-9]{0,39}$/D', $otaHotelId) !== 1) {
            throw new \InvalidArgumentException('美团公开酒店/POI ID 必须是正整数');
        }
        $role = strtolower(trim((string)($input['role'] ?? 'self')));
        if (!in_array($role, ['self', 'competitor'], true)) {
            throw new \InvalidArgumentException('role must be self or competitor');
        }
        $businessDate = $this->normalizeDate((string)($input['business_date'] ?? ''));
        $collectedAt = $this->normalizeDateTime((string)($input['collected_at'] ?? ''));
        if (substr($collectedAt, 0, 10) !== $businessDate) {
            throw new \InvalidArgumentException('采集时间必须属于所选业务日期');
        }
        $timezone = new \DateTimeZone('Asia/Shanghai');
        if (new \DateTimeImmutable($collectedAt, $timezone) > new \DateTimeImmutable('+5 minutes', $timezone)) {
            throw new \InvalidArgumentException('采集时间不能晚于当前时间');
        }
        $sourceUrl = $this->normalizePublicSourceUrl((string)($input['source_url'] ?? ''));
        $sourceHotelId = $this->publicSourceHotelId($sourceUrl);
        if ($sourceHotelId !== null && !hash_equals($otaHotelId, $sourceHotelId)) {
            throw new \InvalidArgumentException('source_url POI ID does not match ota_hotel_id');
        }
        $fields = $this->normalizeFields($input['fields'] ?? null);
        $evidencePaths = $this->normalizeEvidencePaths($input['evidence_paths'] ?? null, array_keys($fields));
        $this->assertNoCredentialMaterial([$fields, $evidencePaths, $sourceUrl]);

        $screenshotRef = trim((string)($input['screenshot_ref'] ?? ''));
        if (mb_strlen($screenshotRef) > 500 || preg_match('/(?:cookie|authorization|bearer|token|password|secret)\s*[:=]/i', $screenshotRef) === 1) {
            throw new \InvalidArgumentException('screenshot_ref is invalid');
        }
        $fieldStatuses = array_fill_keys(array_keys($fields), 'available');
        $now = date('Y-m-d H:i:s');
        $profile = [
            'profile_schema_version' => self::PROFILE_SCHEMA_VERSION,
            'platform' => self::PLATFORM,
            'system_hotel_id' => $systemHotelId,
            'ota_hotel_id' => $otaHotelId,
            'role' => $role,
            'data_date' => $businessDate,
            'collected_at' => $collectedAt,
            'capture_status' => 'available',
            'source_method' => 'manual_public_page_observation',
            'source_url' => $sourceUrl,
            'source_identity_status' => $sourceHotelId === null ? 'url_hotel_id_unresolved' : 'url_hotel_id_matched',
            'source_validation_status' => 'source_observed',
            'fields' => $fields,
            'field_statuses' => $fieldStatuses,
            'evidence_paths' => $evidencePaths,
            'screenshot_ref' => $screenshotRef,
            'captured_by' => $actorId > 0 ? $actorId : null,
        ];

        $columns = OnlineDailyDataPersistenceService::getColumns();
        foreach (['tenant_id', 'system_hotel_id', 'source', 'data_type', 'dimension', 'hotel_id', 'data_date', 'raw_data', 'readback_verified'] as $required) {
            if (!isset($columns[$required])) {
                throw new RuntimeException('online_daily_data.' . $required . ' is required for public-page evidence readback');
            }
        }

        $persistence = Db::transaction(function () use (
            $columns,
            $profile,
            $hotel,
            $tenantId,
            $systemHotelId,
            $otaHotelId,
            $businessDate,
            $role,
            $now
        ): array {
            $query = Db::name('online_daily_data')
                ->where('system_hotel_id', $systemHotelId)
                ->where('source', self::SOURCE)
                ->where('data_type', self::DATA_TYPE)
                ->where('dimension', self::DIMENSION)
                ->where('hotel_id', $otaHotelId)
                ->where('data_date', $businessDate);
            if (isset($columns['platform'])) {
                $query->where('platform', self::PLATFORM);
            }
            if (isset($columns['compare_type'])) {
                $query->where('compare_type', $role);
            }
            $existing = $query->lock(true)->order('id', 'desc')->find();

            $mergedProfile = $profile;
            if (is_array($existing)) {
                $previous = $this->profileFromRawData((string)($existing['raw_data'] ?? ''));
                if ($previous !== null) {
                    $mergedProfile['fields'] = array_merge((array)($previous['fields'] ?? []), $profile['fields']);
                    $mergedProfile['evidence_paths'] = array_merge((array)($previous['evidence_paths'] ?? []), $profile['evidence_paths']);
                    $mergedProfile['field_statuses'] = array_fill_keys(array_keys($mergedProfile['fields']), 'available');
                    if ((string)($previous['collected_at'] ?? '') > (string)$profile['collected_at']) {
                        foreach (['collected_at', 'source_url', 'screenshot_ref'] as $field) {
                            $mergedProfile[$field] = $previous[$field] ?? $mergedProfile[$field];
                        }
                    }
                }
            }

            $rawData = json_encode([
                'schema' => 'suxi_meituan_public_profile_observation_v1',
                'profile' => $mergedProfile,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $data = [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $systemHotelId,
                'source' => self::SOURCE,
                'platform' => self::PLATFORM,
                'data_type' => self::DATA_TYPE,
                'dimension' => self::DIMENSION,
                'hotel_id' => $otaHotelId,
                'hotel_name' => trim((string)($mergedProfile['fields']['name'] ?? $hotel['name'] ?? '')),
                'data_date' => $businessDate,
                'compare_type' => $role,
                'data_period' => 'historical_daily',
                'snapshot_bucket' => '',
                'is_final' => 1,
                'validation_status' => 'unverified',
                'validation_flags' => json_encode([[
                    'level' => 'warning',
                    'field' => 'source_validation_status',
                    'message' => 'manual public-page observation requires independent source review',
                ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'source_method' => 'manual_public_page_observation',
                'source_url' => (string)$mergedProfile['source_url'],
                'source_trace_id' => 'mt-public-' . substr(hash('sha256', $systemHotelId . '|' . $otaHotelId . '|' . $businessDate . '|' . $rawData), 0, 24),
                'raw_data' => $rawData,
                'amount' => null,
                'quantity' => null,
                'book_order_num' => null,
                'comment_score' => null,
                'qunar_comment_score' => null,
                'data_value' => null,
                'update_time' => $now,
            ];
            if (!is_array($existing)) {
                $data['create_time'] = $now;
            }
            $data = OnlineDailyDataPersistenceService::resetReadbackVerification(
                array_intersect_key($data, $columns),
                $columns
            );

            if (is_array($existing)) {
                Db::name('online_daily_data')->where('id', (int)$existing['id'])->update($data);
                return ['row_id' => (int)$existing['id'], 'raw_data' => $rawData];
            }
            return [
                'row_id' => (int)Db::name('online_daily_data')->insertGetId($data),
                'raw_data' => $rawData,
            ];
        });

        $rowId = (int)($persistence['row_id'] ?? 0);
        $readback = Db::name('online_daily_data')->where('id', $rowId)->find();
        $expected = array_intersect_key([
            'tenant_id' => $tenantId,
            'system_hotel_id' => $systemHotelId,
            'source' => self::SOURCE,
            'platform' => self::PLATFORM,
            'data_type' => self::DATA_TYPE,
            'dimension' => self::DIMENSION,
            'hotel_id' => $otaHotelId,
            'data_date' => $businessDate,
            'compare_type' => $role,
            'data_period' => 'historical_daily',
            'snapshot_bucket' => '',
            'raw_data' => (string)($persistence['raw_data'] ?? ''),
        ], $columns);
        if (!is_array($readback)
            || !OnlineDailyDataPersistenceService::matchesBusinessReadback($readback, $expected)
            || !OnlineDailyDataPersistenceService::markRowsReadbackVerified([$readback], $columns)
        ) {
            throw new RuntimeException('Meituan public-page evidence persistence readback failed.');
        }
        $verifiedRow = Db::name('online_daily_data')->where('id', $rowId)->find();
        if (!is_array($verifiedRow) || (int)($verifiedRow['readback_verified'] ?? 0) !== 1) {
            throw new RuntimeException('Meituan public-page evidence readback proof was not persisted.');
        }

        return [
            'status' => 'saved_readback_verified',
            'system_hotel_id' => $systemHotelId,
            'platform' => self::PLATFORM,
            'profile' => $this->profileFromRow($verifiedRow),
            'profiles' => $this->listProfiles($systemHotelId),
            'scope_notice' => '仅保存人工核对的美团消费者公开页事实；来源状态为 source_observed，不使用商家后台数据替代，也不写入 OTA。',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function listProfiles(int $systemHotelId, bool $includeHistory = false): array
    {
        if ($systemHotelId <= 0) {
            return [];
        }
        $columns = OnlineDailyDataPersistenceService::getColumns();
        $query = Db::name('online_daily_data')
            ->where('system_hotel_id', $systemHotelId)
            ->where('source', self::SOURCE)
            ->where('data_type', self::DATA_TYPE)
            ->where('dimension', self::DIMENSION);
        if (isset($columns['platform'])) {
            $query->where('platform', self::PLATFORM);
        }
        $rows = $query
            ->order('data_date', 'desc')
            ->order('update_time', 'desc')
            ->order('id', 'desc')
            ->limit(1000)
            ->select()
            ->toArray();

        $profiles = [];
        foreach ($rows as $row) {
            $profile = $this->profileFromRow($row);
            if ($profile === null) {
                continue;
            }
            $key = $includeHistory ? (string)$profile['snapshot_id'] : (string)$profile['ota_hotel_id'];
            if (!$includeHistory && isset($profiles[$key])) {
                continue;
            }
            $profiles[$key] = $profile;
        }
        return array_values($profiles);
    }

    /** @return array<int, array<string, mixed>> */
    public function listDiagnosisProfiles(int $systemHotelId, string $businessDate): array
    {
        if ($systemHotelId <= 0
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate) !== 1
        ) {
            return [];
        }
        $columns = OnlineDailyDataPersistenceService::getColumns();
        $query = Db::name('online_daily_data')
            ->where('system_hotel_id', $systemHotelId)
            ->where('source', self::SOURCE)
            ->where('data_type', self::DATA_TYPE)
            ->where('dimension', self::DIMENSION)
            ->where('data_date', $businessDate);
        if (isset($columns['platform'])) {
            $query->where('platform', self::PLATFORM);
        }
        if (isset($columns['compare_type'])) {
            $query->where('compare_type', 'self');
        }
        $row = $query->order('update_time', 'desc')->order('id', 'desc')->find();
        if (!is_array($row)) {
            $fallback = Db::name('online_daily_data')
                ->where('system_hotel_id', $systemHotelId)
                ->where('source', self::SOURCE)
                ->where('data_type', self::DATA_TYPE)
                ->where('dimension', self::DIMENSION);
            if (isset($columns['platform'])) {
                $fallback->where('platform', self::PLATFORM);
            }
            if (isset($columns['compare_type'])) {
                $fallback->where('compare_type', 'self');
            }
            $row = $fallback->order('data_date', 'desc')->order('update_time', 'desc')->order('id', 'desc')->find();
            if (!is_array($row)) {
                return [];
            }
        }
        $profile = $this->profileFromRow($row);
        if ($profile === null || !in_array((string)($profile['role'] ?? ''), ['', 'self'], true)) {
            return [];
        }
        $profile['role'] = 'self';

        return [$profile];
    }

    /** @return array<string, mixed>|null */
    private function profileFromRow(array $row): ?array
    {
        $profile = $this->profileFromRawData((string)($row['raw_data'] ?? ''));
        if ($profile === null) {
            return null;
        }
        $snapshotId = (int)($row['id'] ?? 0);
        $profile['platform'] = self::PLATFORM;
        $profile['system_hotel_id'] = (int)($row['system_hotel_id'] ?? 0);
        $profile['ota_hotel_id'] = trim((string)($row['hotel_id'] ?? $profile['ota_hotel_id'] ?? ''));
        $profile['data_date'] = (string)($row['data_date'] ?? $profile['data_date'] ?? '');
        $profile['snapshot_id'] = $snapshotId;
        $profile['response_ref'] = $snapshotId > 0 ? 'online_daily_data#' . $snapshotId : null;
        $profile['persistence_readback_verified'] = (int)($row['readback_verified'] ?? 0) === 1;
        $profile['persistence_readback_status'] = $profile['persistence_readback_verified'] ? 'readback_verified' : 'unverified';
        $profile['source_validation_status'] = 'source_observed';
        $profile['capture_status'] = 'available';
        return $profile;
    }

    /** @return array<string, mixed>|null */
    private function profileFromRawData(string $rawData): ?array
    {
        $decoded = json_decode($rawData, true);
        if (!is_array($decoded)
            || ($decoded['schema'] ?? '') !== 'suxi_meituan_public_profile_observation_v1'
            || !is_array($decoded['profile'] ?? null)
        ) {
            return null;
        }
        return $decoded['profile'];
    }

    /** @return array<string, mixed> */
    private function normalizeFields(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('fields must be a JSON object');
        }
        $allowed = array_fill_keys(OtaPublicPageDiagnosisService::expectedFieldKeys(), true);
        $result = [];
        foreach ($value as $key => $item) {
            $key = trim((string)$key);
            if (!isset($allowed[$key])) {
                throw new \InvalidArgumentException('unsupported public-page field: ' . $key);
            }
            $normalized = $this->normalizeValue($item, 0);
            if ($normalized === null || $normalized === '' || $normalized === []) {
                continue;
            }
            $result[$key] = $normalized;
        }
        if ($result === []) {
            throw new \InvalidArgumentException('at least one public-page field is required');
        }
        return $result;
    }

    /** @param array<int, string> $fieldKeys @return array<string, string> */
    private function normalizeEvidencePaths(mixed $value, array $fieldKeys): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('evidence_paths must be a JSON object');
        }
        $result = [];
        foreach ($fieldKeys as $fieldKey) {
            $locator = trim((string)($value[$fieldKey] ?? ''));
            if ($locator === '' || mb_strlen($locator) > 500) {
                throw new \InvalidArgumentException('evidence_paths.' . $fieldKey . ' is required and must be no longer than 500 characters');
            }
            $result[$fieldKey] = $locator;
        }
        return $result;
    }

    private function normalizeValue(mixed $value, int $depth): mixed
    {
        if ($depth > 3) {
            throw new \InvalidArgumentException('public-page field nesting is too deep');
        }
        if (is_string($value)) {
            $value = trim($value);
            if (mb_strlen($value) > self::MAX_TEXT_LENGTH) {
                throw new \InvalidArgumentException('public-page field text is too long');
            }
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (!is_array($value) || count($value) > self::MAX_LIST_ITEMS) {
            throw new \InvalidArgumentException('public-page field value must be scalar or a bounded JSON array/object');
        }
        $result = [];
        foreach ($value as $key => $item) {
            $normalized = $this->normalizeValue($item, $depth + 1);
            if ($normalized === null || $normalized === '' || $normalized === []) {
                continue;
            }
            $result[$key] = $normalized;
        }
        return array_is_list($value) ? array_values($result) : $result;
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('business_date must be YYYY-MM-DD');
        }
        return $value;
    }

    private function normalizeDateTime(string $value): string
    {
        $value = trim(str_replace('T', ' ', $value));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('collected_at must use YYYY-MM-DD HH:MM[:SS]');
        }
        $value = strlen($value) === 16 ? $value . ':00' : $value;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('Asia/Shanghai'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $value
        ) {
            throw new \InvalidArgumentException('collected_at must be a valid date-time');
        }
        return $value;
    }

    private function normalizePublicSourceUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > self::MAX_TEXT_LENGTH) {
            throw new \InvalidArgumentException('source_url must be a bounded Meituan consumer public-page HTTPS URL');
        }
        $parts = parse_url($value);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('source_url must be a Meituan consumer public-page HTTPS URL, not a merchant console URL');
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $port = (int)($parts['port'] ?? 443);
        $blockedHost = false;
        foreach (self::BLOCKED_HOSTS as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                $blockedHost = true;
                break;
            }
        }
        $query = (string)($parts['query'] ?? '');
        if ($scheme !== 'https'
            || $host === ''
            || ($host !== 'meituan.com' && !str_ends_with($host, '.meituan.com'))
            || $blockedHost
            || isset($parts['user'])
            || isset($parts['pass'])
            || $port !== 443
            || ($query !== '' && preg_match('/(?:^|&)[^=&]*(?:auth|cookie|password|secret|token)[^=&]*=/i', $query) === 1)
        ) {
            throw new \InvalidArgumentException('source_url must be a Meituan consumer public-page HTTPS URL, not a merchant console URL');
        }
        $path = (string)($parts['path'] ?? '/');
        return 'https://' . $host . ($path !== '' ? $path : '/') . ($query !== '' ? '?' . $query : '');
    }

    private function publicSourceHotelId(string $url): ?string
    {
        $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');
        $parameters = [];
        parse_str($query, $parameters);
        foreach (['poiId', 'poi_id', 'hotelId', 'hotel_id'] as $key) {
            $value = is_scalar($parameters[$key] ?? null) ? trim((string)$parameters[$key]) : '';
            if (preg_match('/^[1-9][0-9]{0,39}$/D', $value) === 1) {
                return $value;
            }
        }
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('#/(?:hotel|poi)/([1-9][0-9]{0,39})(?:[/.]|$)#i', $path, $matches) === 1) {
            return (string)$matches[1];
        }

        return null;
    }

    private function assertNoCredentialMaterial(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertNoCredentialMaterial($item);
            }
            return;
        }
        if (is_string($value)
            && preg_match('/(?:cookie|authorization|bearer|access[_-]?token|refresh[_-]?token|password|secret)\s*[:=]/i', $value) === 1
        ) {
            throw new \InvalidArgumentException('public-page evidence must not contain credentials');
        }
    }
}
