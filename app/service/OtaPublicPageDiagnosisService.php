<?php
declare(strict_types=1);

namespace app\service;

/**
 * Builds an evidence-only diagnosis directory from persisted OTA public-page facts.
 * It never invents values or a health score when a validated scoring rule is absent.
 */
final class OtaPublicPageDiagnosisService
{
    /** @var array<int, array{key:string,label:string,fields:array<int,string>}> */
    private const DIMENSIONS = [
        ['key' => 'platform_basics', 'label' => '平台与基础展示', 'fields' => ['name', 'address', 'location', 'brand_name', 'hotel_type', 'platform_grade', 'opening_year', 'renovation_year', 'room_count']],
        ['key' => 'pricing', 'label' => '价格与价盘', 'fields' => ['public_price', 'rate_plan']],
        ['key' => 'review_structure', 'label' => '点评结构', 'fields' => ['rating', 'review_count', 'review_tags']],
        ['key' => 'review_replies', 'label' => '点评回复', 'fields' => ['reply_rate', 'reply_timeliness']],
        ['key' => 'questions_answers', 'label' => '问答与咨询', 'fields' => ['qa_count', 'qa_topics']],
        ['key' => 'media', 'label' => '图片与视频', 'fields' => ['images', 'video_count']],
        ['key' => 'room_types', 'label' => '房型及命名', 'fields' => ['room_type_names', 'room_type_count']],
        ['key' => 'distribution', 'label' => '代理与分销展示', 'fields' => ['distribution_display', 'agency_display']],
        ['key' => 'future_pricing', 'label' => '未来日期价格', 'fields' => ['future_price_dates', 'future_price_range']],
        ['key' => 'marketing', 'label' => '营销活动', 'fields' => ['marketing_offers', 'discount_labels']],
        ['key' => 'packages_content', 'label' => '套餐与内容展示', 'fields' => ['description', 'highlights', 'facilities', 'policies', 'nearby_places', 'package_content']],
        ['key' => 'member_rights', 'label' => '会员或权益表达', 'fields' => ['member_rate', 'member_rights']],
    ];

    /** @param array<int, array<string, mixed>> $profiles */
    public function build(int $systemHotelId, string $platform, string $businessDate, array $profiles): array
    {
        if ($systemHotelId <= 0) {
            throw new \InvalidArgumentException('system_hotel_id must be positive');
        }
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            throw new \InvalidArgumentException('platform must be ctrip or meituan');
        }
        if (!$this->isDate($businessDate)) {
            throw new \InvalidArgumentException('business_date must be YYYY-MM-DD');
        }

        $latestAvailableDate = '';
        $selected = [];
        foreach ($profiles as $profile) {
            if (!is_array($profile) || strtolower(trim((string)($profile['platform'] ?? $platform))) !== $platform) {
                continue;
            }
            $profileDate = trim((string)($profile['data_date'] ?? substr((string)($profile['collected_at'] ?? ''), 0, 10)));
            if ($this->isDate($profileDate) && ($latestAvailableDate === '' || $profileDate > $latestAvailableDate)) {
                $latestAvailableDate = $profileDate;
            }
            if ($profileDate === $businessDate) {
                $selected[] = $profile;
            }
        }

        $dimensions = [];
        $sources = [];
        $observedFieldKeys = [];
        $verifiedFieldKeys = [];
        $expectedFieldCount = 0;
        foreach (self::DIMENSIONS as $dimension) {
            $facts = [];
            $unknown = [];
            foreach ($dimension['fields'] as $fieldKey) {
                $expectedFieldCount++;
                $fieldFacts = [];
                foreach ($selected as $profile) {
                    $fact = $this->fieldFact($profile, $platform, $systemHotelId, $businessDate, $dimension['key'], $fieldKey);
                    if ($fact !== null) {
                        $fieldFacts[] = $fact;
                    }
                }
                if ($fieldFacts === []) {
                    $unknown[] = $fieldKey;
                    continue;
                }
                $observedFieldKeys[$dimension['key'] . ':' . $fieldKey] = true;
                if (count(array_filter(
                    $fieldFacts,
                    static fn(array $fact): bool => ($fact['quality_status'] ?? '') === 'verified'
                )) > 0) {
                    $verifiedFieldKeys[$dimension['key'] . ':' . $fieldKey] = true;
                }
                array_push($facts, ...$fieldFacts);
            }
            $observedCount = count(array_unique(array_column($facts, 'field_key')));
            $verifiedCount = count(array_unique(array_column(array_filter(
                $facts,
                static fn(array $fact): bool => ($fact['quality_status'] ?? '') === 'verified'
            ), 'field_key')));
            $dimensionExpected = count($dimension['fields']);
            $dimensions[] = [
                'key' => $dimension['key'],
                'label' => $dimension['label'],
                'status' => $observedCount > 0 && $verifiedCount === $dimensionExpected ? 'verified' : ($observedCount > 0 ? 'partial' : 'unknown'),
                'observed_field_count' => $observedCount,
                'verified_field_count' => $verifiedCount,
                'expected_field_count' => $dimensionExpected,
                'coverage_rate' => $dimensionExpected > 0 ? round($observedCount / $dimensionExpected * 100, 2) : null,
                'facts' => $facts,
                'unknown_fields' => $unknown,
            ];
        }

        foreach ($selected as $profile) {
            $snapshotId = (int)($profile['snapshot_id'] ?? 0);
            $sources[] = [
                'platform' => $platform,
                'platform_hotel_id' => trim((string)($profile['ota_hotel_id'] ?? '')),
                'role' => trim((string)($profile['role'] ?? 'unknown')),
                'source_url' => $this->safeSourceUrl((string)($profile['source_url'] ?? '')),
                'collected_at' => trim((string)($profile['collected_at'] ?? $profile['last_seen_at'] ?? '')),
                'capture_status' => trim((string)($profile['capture_status'] ?? 'unknown')),
                'response_ref' => $snapshotId > 0 ? 'ota_ctrip_entity_snapshots#' . $snapshotId : null,
                'screenshot_ref' => null,
                'persistence_readback_status' => ($profile['persistence_readback_verified'] ?? false) === true
                    ? 'readback_verified'
                    : 'unverified',
                'source_validation_status' => $this->sourceValidationStatus($profile),
            ];
        }

        $observedFieldCount = count($observedFieldKeys);
        $verifiedFieldCount = count($verifiedFieldKeys);
        $coverageRate = $expectedFieldCount > 0 ? round($observedFieldCount / $expectedFieldCount * 100, 2) : null;
        $observedDimensionCount = count(array_filter($dimensions, static fn(array $row): bool => $row['observed_field_count'] > 0));
        $status = $verifiedFieldCount > 0 && $verifiedFieldCount === $expectedFieldCount
            ? 'evidence_complete'
            : 'insufficient_evidence';

        return [
            'status' => $status,
            'platform' => $platform,
            'system_hotel_id' => $systemHotelId,
            'business_date' => $businessDate,
            'stay_date' => null,
            'platform_source_status' => $platform === 'ctrip' ? 'persisted_public_profile_snapshots' : 'public_profile_source_not_connected',
            'latest_available_date' => $latestAvailableDate !== '' ? $latestAvailableDate : null,
            'evidence_coverage' => [
                'observed_field_count' => $observedFieldCount,
                'verified_field_count' => $verifiedFieldCount,
                'expected_field_count' => $expectedFieldCount,
                'coverage_rate' => $coverageRate,
                'observed_dimension_count' => $observedDimensionCount,
                'dimension_count' => count(self::DIMENSIONS),
            ],
            'diagnosis_score' => null,
            'score_status' => 'not_calculated_no_validated_scoring_rule',
            'dimensions' => $dimensions,
            'sources' => $sources,
            'unknown_items' => array_values(array_unique(array_merge(...array_map(
                static fn(array $row): array => $row['unknown_fields'],
                $dimensions
            )))),
            'next_action' => $this->nextAction(
                $platform,
                $businessDate,
                $latestAvailableDate,
                $observedFieldCount,
                $verifiedFieldCount,
                $expectedFieldCount
            ),
            'source_policy' => 'persisted_public_page_facts_only_no_default_score_no_ota_write',
            'scope_notice' => '仅为 OTA 公开页证据目录，不代表酒店总房态、真实库存、出租率、ADR、RevPAR 或利润。未知、受阻和过期字段不按零处理。',
        ];
    }

    private function fieldFact(
        array $profile,
        string $platform,
        int $systemHotelId,
        string $businessDate,
        string $dimension,
        string $fieldKey
    ): ?array {
        $statuses = is_array($profile['field_statuses'] ?? null) ? $profile['field_statuses'] : [];
        if (($statuses[$fieldKey] ?? 'missing') !== 'available') {
            return null;
        }
        $fields = is_array($profile['fields'] ?? null) ? $profile['fields'] : [];
        $value = $this->fieldValue($fields, $fieldKey);
        $paths = is_array($profile['evidence_paths'] ?? null) ? $profile['evidence_paths'] : [];
        $locator = trim((string)($paths[$fieldKey] ?? ''));
        $sourceUrl = $this->safeSourceUrl((string)($profile['source_url'] ?? ''));
        if ($value === null || $value === '' || $locator === '' || $sourceUrl === '') {
            return null;
        }
        $snapshotId = (int)($profile['snapshot_id'] ?? 0);
        $persistenceReadbackVerified = ($profile['persistence_readback_verified'] ?? false) === true;
        if (!$persistenceReadbackVerified || $snapshotId <= 0) {
            return null;
        }
        $sourceValidationStatus = $this->sourceValidationStatus($profile);
        $captureStatus = strtolower(trim((string)($profile['capture_status'] ?? 'unknown')));
        $sourceVerified = $captureStatus === 'available' && $sourceValidationStatus === 'source_verified';
        $qualityStatus = match (true) {
            $sourceVerified => 'verified',
            $captureStatus === 'stale' || $sourceValidationStatus === 'stale' => 'stale',
            in_array($captureStatus, ['collection_failed', 'failed', 'error'], true)
                || $sourceValidationStatus === 'collection_failed' => 'failed',
            $captureStatus === 'partial' || $sourceValidationStatus === 'partial' => 'partial',
            $sourceValidationStatus === 'source_observed' => 'observed',
            default => 'unverified',
        };

        return [
            'platform' => $platform,
            'system_hotel_id' => $systemHotelId,
            'platform_hotel_id' => trim((string)($profile['ota_hotel_id'] ?? '')),
            'business_date' => $businessDate,
            'stay_date' => null,
            'captured_at' => trim((string)($profile['collected_at'] ?? $profile['last_seen_at'] ?? '')),
            'dimension' => $dimension,
            'field_key' => $fieldKey,
            'observed_value' => $value,
            'source_url' => $sourceUrl,
            'source_method' => trim((string)($profile['source_method'] ?? 'public_page')),
            'source_locator' => $locator,
            'evidence_ref' => $snapshotId > 0 ? 'ota_ctrip_entity_snapshots#' . $snapshotId : null,
            'quality_status' => $qualityStatus,
            'confidence' => $sourceVerified ? 'high' : ($qualityStatus === 'observed' ? 'medium' : 'low'),
            'persistence_readback_status' => 'readback_verified',
            'source_validation_status' => $sourceValidationStatus,
        ];
    }

    private function sourceValidationStatus(array $profile): string
    {
        $status = strtolower(trim((string)($profile['source_validation_status'] ?? '')));
        return in_array($status, ['source_verified', 'partial', 'source_observed', 'collection_failed', 'stale', 'unverified'], true)
            ? $status
            : 'unverified';
    }

    private function fieldValue(array $fields, string $fieldKey): mixed
    {
        return match ($fieldKey) {
            'platform_grade' => $fields['grade_label'] ?? $fields['diamond_level'] ?? $fields['star_level'] ?? null,
            'location' => $fields['city_name'] ?? (($fields['latitude'] ?? null) !== null && ($fields['longitude'] ?? null) !== null
                ? ['latitude' => $fields['latitude'], 'longitude' => $fields['longitude']]
                : null),
            'images' => array_values(array_filter(array_merge(
                isset($fields['cover_image_url']) ? [(string)$fields['cover_image_url']] : [],
                is_array($fields['gallery_image_urls'] ?? null) ? $fields['gallery_image_urls'] : []
            ))),
            default => $fields[$fieldKey] ?? null,
        };
    }

    private function safeSourceUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return '';
        }
        return (string)($parts['scheme'] ?? 'https') . '://' . (string)($parts['host'] ?? '') . (string)($parts['path'] ?? '');
    }

    private function nextAction(
        string $platform,
        string $businessDate,
        string $latestDate,
        int $observedCount,
        int $verifiedCount,
        int $expectedCount
    ): string {
        if ($platform === 'meituan') {
            return '美团公开页诊断源尚未接入；保持十二维未知，不使用携程或内部经营数据替代。';
        }
        if ($observedCount === 0 && $latestDate !== '' && $latestDate !== $businessDate) {
            return '所选日期没有携程公开页快照；最新可用日期为 ' . $latestDate . '，可切换日期查看或人工触发公开页更新。';
        }
        if ($observedCount === 0) {
            return '先绑定携程公开酒店 ID 并采集保存；没有来源网址、采集时间和响应引用时不评分。';
        }
        if ($observedCount === $expectedCount && $verifiedCount < $expectedCount) {
            return '字段覆盖已齐全，但仍含过期、观察或未验证来源；需重新采集或完成来源验证后才能标记证据完整。';
        }
        if ($verifiedCount === $expectedCount) {
            return '公开页字段及来源验证已齐全；该证据目录仍不自动计算经营评分。';
        }
        return '按未知字段清单补充可复核公开页证据；覆盖不足前保持 insufficient_evidence。';
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
