<?php
declare(strict_types=1);

namespace app\service;

/**
 * Builds an evidence-only diagnosis directory from persisted OTA public-page facts.
 * It never invents values or a health score when a validated scoring rule is absent.
 */
final class OtaPublicPageDiagnosisService
{
    public const EXECUTION_SOURCE_MODULE = 'ota_diagnosis';
    public const EXECUTION_IDENTITY_VERSION = 'public_page_v3';
    public const VERSION_TWO_EXECUTION_IDENTITY_VERSION = 'public_page_v2';
    public const LEGACY_EXECUTION_IDENTITY_VERSION = 'legacy_v1';

    private const PUBLIC_PAGE_SOURCE_RECORD_OFFSET = 4294967296;

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

    /** @return array<int, string> */
    public static function expectedFieldKeys(): array
    {
        return array_values(array_unique(array_merge(...array_map(
            static fn(array $dimension): array => $dimension['fields'],
            self::DIMENSIONS
        ))));
    }

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
            $role = strtolower(trim((string)($profile['role'] ?? '')));
            if ($role !== '' && $role !== 'self') {
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
        usort($selected, static function (array $left, array $right): int {
            $leftAt = trim((string)($left['collected_at'] ?? $left['last_seen_at'] ?? ''));
            $rightAt = trim((string)($right['collected_at'] ?? $right['last_seen_at'] ?? ''));
            $timeCompare = strcmp($rightAt, $leftAt);
            return $timeCompare !== 0
                ? $timeCompare
                : (int)($right['snapshot_id'] ?? 0) <=> (int)($left['snapshot_id'] ?? 0);
        });
        $selected = array_slice($selected, 0, 1);

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
                'response_ref' => $this->evidenceRef($profile, $snapshotId, $platform),
                'screenshot_ref' => trim((string)($profile['screenshot_ref'] ?? '')) ?: null,
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
            'target_role' => 'self',
            'target_platform_hotel_id' => $selected !== []
                ? trim((string)($selected[0]['ota_hotel_id'] ?? '')) ?: null
                : null,
            'business_date' => $businessDate,
            'stay_date' => null,
            'platform_source_status' => $this->platformSourceStatus($platform, $profiles),
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

    /**
     * Converts a server-built diagnosis into the canonical operation task-draft contract.
     * Only evidence identity and gap metadata are carried forward; no score or OTA action is invented.
     *
     * @param array<string, mixed> $diagnosis
     * @param array<string, mixed> $schedule
     * @return array{input:array<string,mixed>,idempotency_base_key:string,idempotency_key:string,source_record_id:int,diagnosis_fingerprint:string,identity_version:string,legacy_idempotency_base_key:string,legacy_idempotency_key:string,legacy_source_record_id:int}
     */
    public function buildExecutionIntentDraft(array $diagnosis, array $schedule): array
    {
        $systemHotelId = (int)($diagnosis['system_hotel_id'] ?? 0);
        $platform = strtolower(trim((string)($diagnosis['platform'] ?? '')));
        $businessDate = trim((string)($diagnosis['business_date'] ?? ''));
        $status = trim((string)($diagnosis['status'] ?? ''));
        if ($systemHotelId <= 0) {
            throw new \InvalidArgumentException('diagnosis system_hotel_id must be positive');
        }
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            throw new \InvalidArgumentException('diagnosis platform must be ctrip or meituan');
        }
        if (!$this->isDate($businessDate)) {
            throw new \InvalidArgumentException('diagnosis business_date must be YYYY-MM-DD');
        }
        if (!in_array($status, ['evidence_complete', 'insufficient_evidence'], true)) {
            throw new \InvalidArgumentException('diagnosis status is not supported');
        }

        $coverage = is_array($diagnosis['evidence_coverage'] ?? null) ? $diagnosis['evidence_coverage'] : [];
        $observedFieldCount = max(0, (int)($coverage['observed_field_count'] ?? 0));
        $verifiedFieldCount = max(0, (int)($coverage['verified_field_count'] ?? 0));
        $expectedFieldCount = max(0, (int)($coverage['expected_field_count'] ?? 0));
        $coverageRate = is_numeric($coverage['coverage_rate'] ?? null)
            ? (float)$coverage['coverage_rate']
            : null;
        $dataGaps = $this->executionIntentDataGaps((array)($diagnosis['dimensions'] ?? []));
        $sourceEvidence = $this->executionIntentSourceEvidence((array)($diagnosis['sources'] ?? []));
        $workflowSchedule = $this->normalizeTaskSchedule($schedule);
        $evidenceComplete = $status === 'evidence_complete'
            && $expectedFieldCount > 0
            && $verifiedFieldCount === $expectedFieldCount;
        $actionType = $evidenceComplete ? 'review_public_page_evidence' : 'complete_public_page_evidence';
        $fullEvidenceFingerprint = hash('sha256', json_encode([
            'system_hotel_id' => $systemHotelId,
            'platform' => $platform,
            'business_date' => $businessDate,
            'status' => $status,
            'coverage' => $coverage,
            'data_gaps' => $dataGaps,
            'sources' => $sourceEvidence,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $identityGaps = $dataGaps;
        sort($identityGaps, SORT_STRING);
        $platformHotelIds = array_values(array_unique(array_filter(array_map(
            static fn(array $source): string => trim((string)($source['platform_hotel_id'] ?? '')),
            $sourceEvidence
        ))));
        sort($platformHotelIds, SORT_STRING);
        $taskIdentityFingerprint = hash('sha256', json_encode([
            'identity_version' => self::EXECUTION_IDENTITY_VERSION,
            'system_hotel_id' => $systemHotelId,
            'platform_hotel_ids' => $platformHotelIds,
            'platform' => $platform,
            'business_date' => $businessDate,
            'action_type' => $actionType,
            'metric_scope' => 'ota_channel_public_page',
            'data_gaps' => $identityGaps,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $diagnosisFingerprint = $fullEvidenceFingerprint;
        $sourceRefs = [
            'ota_public_page_diagnosis:' . $platform . ':' . $systemHotelId . ':' . $businessDate . ':' . substr($diagnosisFingerprint, 0, 12),
            '/api/online-data/public-page-diagnosis',
        ];
        foreach ($sourceEvidence as $source) {
            $responseRef = trim((string)($source['response_ref'] ?? ''));
            if ($responseRef !== '') {
                $sourceRefs[] = $responseRef;
            }
        }
        $sourceRefs = array_values(array_unique($sourceRefs));

        $evidenceComplete = $status === 'evidence_complete'
            && $expectedFieldCount > 0
            && $verifiedFieldCount === $expectedFieldCount;
        $platformLabel = $platform === 'ctrip' ? '携程' : '美团';
        $actionType = $evidenceComplete ? 'review_public_page_evidence' : 'complete_public_page_evidence';
        $title = $evidenceComplete
            ? '复核' . $platformLabel . '公开页证据并形成运营判断'
            : '补齐' . $platformLabel . '公开页证据';
        $actionText = $evidenceComplete
            ? '复核已验证的公开页字段并记录人工运营判断；不得据此外推全酒店经营结论，也不直接写入 OTA。'
            : trim((string)($diagnosis['next_action'] ?? ''));
        if ($actionText === '') {
            $actionText = '按证据缺口补齐公开页字段、来源定位和数据库回读，再重新生成诊断。';
        }

        // Daily-workbench OTA intents use unsigned CRC32 values. Keep public-page
        // identities above that range and inside JavaScript's safe-integer range.
        $legacySourceRecordId = (int)hexdec(substr($fullEvidenceFingerprint, 0, 7)) + 1;
        $versionTwoSourceRecordId = self::PUBLIC_PAGE_SOURCE_RECORD_OFFSET
            + (int)hexdec(substr($fullEvidenceFingerprint, 0, 13));
        $sourceRecordId = self::PUBLIC_PAGE_SOURCE_RECORD_OFFSET
            + (int)hexdec(substr($taskIdentityFingerprint, 0, 13));
        $idempotencyIdentity = hash('sha256', json_encode([
            'task_identity_fingerprint' => $taskIdentityFingerprint,
            'action_contract' => 'ota_public_page_evidence_v3',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $versionTwoIdempotencyIdentity = hash('sha256', json_encode([
            'diagnosis_fingerprint' => $fullEvidenceFingerprint,
            'action_contract' => 'ota_public_page_evidence_v2',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $legacyIdempotencyIdentity = hash('sha256', json_encode([
            'diagnosis_fingerprint' => $fullEvidenceFingerprint,
            'action_contract' => 'ota_public_page_evidence_v1',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return [
            'input' => [
                'source_module' => self::EXECUTION_SOURCE_MODULE,
                'source_record_id' => $sourceRecordId,
                'hotel_id' => $systemHotelId,
                'platform' => $platform,
                'object_type' => 'data_collection',
                'action_type' => $actionType,
                'date_start' => $businessDate,
                'date_end' => $businessDate,
                'current_value' => [
                    'diagnosis_type' => 'ota_public_page_evidence',
                    'diagnosis_status' => $status,
                    'platform_source_status' => (string)($diagnosis['platform_source_status'] ?? 'unknown'),
                    'observed_field_count' => $observedFieldCount,
                    'verified_field_count' => $verifiedFieldCount,
                    'expected_field_count' => $expectedFieldCount,
                    'coverage_rate' => $coverageRate,
                    'score_status' => (string)($diagnosis['score_status'] ?? 'not_calculated_no_validated_scoring_rule'),
                ],
                'target_value' => [
                    'title' => $title,
                    'collection_scope' => 'ota_public_page_evidence',
                    'target_date' => $businessDate,
                    'action_text' => $actionText,
                    'target_metric' => 'public_page_verified_field_count',
                    'target_verified_field_count' => $expectedFieldCount,
                    'target_public_page_verified_field_count' => $expectedFieldCount,
                    'assignee_id' => $workflowSchedule['assignee_id'],
                    'due_at' => $workflowSchedule['due_at'],
                    'review_at' => $workflowSchedule['review_at'],
                    'workflow_schedule' => $workflowSchedule,
                    'acceptance_criteria' => [
                        '证据归属同一系统门店、平台和业务日期',
                        '字段具备公开页来源网址、采集时间和来源定位',
                        '保存后数据库回读与 OTA 来源验证状态分别可见',
                        '重新生成诊断后，证据状态与实际回读一致',
                    ],
                ],
                'evidence' => [
                    'evidence_refs' => $sourceRefs,
                    'data_gaps' => $dataGaps,
                    'sources' => $sourceEvidence,
                    'source_policy' => (string)($diagnosis['source_policy'] ?? 'persisted_public_page_facts_only_no_default_score_no_ota_write'),
                    'protected_boundary' => '任务只处理公开页证据补齐或人工复核，不自动修改 OTA 价格、库存、活动或内容。',
                    'diagnosis_summary' => sprintf(
                        '%s公开页证据：已观察 %d/%d，来源已验证 %d/%d，状态 %s。',
                        $platformLabel,
                        $observedFieldCount,
                        $expectedFieldCount,
                        $verifiedFieldCount,
                        $expectedFieldCount,
                        $status
                    ),
                    'metric_scope' => 'ota_channel_public_page',
                    'scope_notice' => (string)($diagnosis['scope_notice'] ?? ''),
                    'workflow_schedule' => $workflowSchedule,
                    'diagnosis_fingerprint' => $diagnosisFingerprint,
                    'full_evidence_fingerprint' => $fullEvidenceFingerprint,
                    'task_identity_fingerprint' => $taskIdentityFingerprint,
                    'identity_version' => self::EXECUTION_IDENTITY_VERSION,
                ],
                'expected_metric' => 'public_page_verified_field_count',
                'expected_delta' => max(0, $expectedFieldCount - $verifiedFieldCount),
                'risk_level' => $evidenceComplete ? 'low' : 'medium',
                'status' => 'pending_approval',
            ],
            'idempotency_base_key' => 'ota_diagnosis_action_' . substr($idempotencyIdentity, 0, 32),
            'idempotency_key' => 'ota_diagnosis_action_' . substr($idempotencyIdentity, 0, 32) . ':attempt:1',
            'source_record_id' => $sourceRecordId,
            'diagnosis_fingerprint' => $diagnosisFingerprint,
            'full_evidence_fingerprint' => $fullEvidenceFingerprint,
            'task_identity_fingerprint' => $taskIdentityFingerprint,
            'identity_version' => self::EXECUTION_IDENTITY_VERSION,
            'version_two_idempotency_base_key' => 'ota_diagnosis_action_' . substr($versionTwoIdempotencyIdentity, 0, 32),
            'version_two_idempotency_key' => 'ota_diagnosis_action_' . substr($versionTwoIdempotencyIdentity, 0, 32) . ':attempt:1',
            'version_two_source_record_id' => $versionTwoSourceRecordId,
            'legacy_idempotency_base_key' => 'ota_diagnosis_action_' . substr($legacyIdempotencyIdentity, 0, 32),
            'legacy_idempotency_key' => 'ota_diagnosis_action_' . substr($legacyIdempotencyIdentity, 0, 32) . ':attempt:1',
            'legacy_source_record_id' => $legacySourceRecordId,
        ];
    }

    /** @param array<int, mixed> $dimensions */
    private function executionIntentDataGaps(array $dimensions): array
    {
        $gaps = [];
        foreach ($dimensions as $dimension) {
            if (!is_array($dimension) || ($dimension['status'] ?? '') === 'verified') {
                continue;
            }
            $dimensionKey = trim((string)($dimension['key'] ?? 'unknown_dimension'));
            foreach ((array)($dimension['unknown_fields'] ?? []) as $field) {
                $field = trim((string)$field);
                if ($field !== '') {
                    $gaps[] = $dimensionKey . ':' . $field . ':missing';
                }
            }
            foreach ((array)($dimension['facts'] ?? []) as $fact) {
                if (!is_array($fact) || ($fact['quality_status'] ?? '') === 'verified') {
                    continue;
                }
                $fieldKey = trim((string)($fact['field_key'] ?? 'unknown_field'));
                $qualityStatus = trim((string)($fact['quality_status'] ?? 'unverified'));
                $gaps[] = $dimensionKey . ':' . $fieldKey . ':' . $qualityStatus;
            }
        }
        return array_values(array_unique($gaps));
    }

    /** @param array<int, mixed> $sources */
    private function executionIntentSourceEvidence(array $sources): array
    {
        $rows = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $rows[] = [
                'platform_hotel_id' => trim((string)($source['platform_hotel_id'] ?? '')),
                'role' => trim((string)($source['role'] ?? 'unknown')),
                'source_url' => $this->safeSourceUrl((string)($source['source_url'] ?? '')),
                'collected_at' => trim((string)($source['collected_at'] ?? '')),
                'capture_status' => trim((string)($source['capture_status'] ?? 'unknown')),
                'response_ref' => trim((string)($source['response_ref'] ?? '')),
                'persistence_readback_status' => trim((string)($source['persistence_readback_status'] ?? 'unverified')),
                'source_validation_status' => trim((string)($source['source_validation_status'] ?? 'unverified')),
            ];
        }
        return $rows;
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
        $sourceVerified = in_array($captureStatus, ['available', 'partial'], true)
            && $sourceValidationStatus === 'source_verified';
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
            'evidence_ref' => $this->evidenceRef($profile, $snapshotId, $platform),
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

    /** @param array<string, mixed> $schedule */
    private function normalizeTaskSchedule(array $schedule): array
    {
        $assigneeId = (int)($schedule['assignee_id'] ?? 0);
        if ($assigneeId <= 0) {
            throw new \InvalidArgumentException('assignee_id is required before creating a public-page evidence task');
        }
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $normalizeDateTime = static function (mixed $value, string $field) use ($timezone): \DateTimeImmutable {
            $value = trim(str_replace('T', ' ', (string)$value));
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/D', $value) !== 1) {
                throw new \InvalidArgumentException($field . ' must use YYYY-MM-DD HH:MM[:SS]');
            }
            $canonical = strlen($value) === 16 ? $value . ':00' : $value;
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $canonical, $timezone);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($parsed === false
                || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
                || $parsed->format('Y-m-d H:i:s') !== $canonical
            ) {
                throw new \InvalidArgumentException($field . ' must be a valid date-time');
            }
            return $parsed;
        };
        $dueAt = $normalizeDateTime($schedule['due_at'] ?? '', 'due_at');
        $reviewAt = $normalizeDateTime($schedule['review_at'] ?? '', 'review_at');
        if ($dueAt <= new \DateTimeImmutable('now', $timezone)) {
            throw new \InvalidArgumentException('due_at must be later than the current time');
        }
        if ($reviewAt <= $dueAt) {
            throw new \InvalidArgumentException('review_at must be later than due_at');
        }

        return [
            'assignee_id' => $assigneeId,
            'due_at' => $dueAt->format('Y-m-d H:i:s'),
            'review_at' => $reviewAt->format('Y-m-d H:i:s'),
            'source_policy' => 'human_assigned_schedule_requires_manual_approval_and_readback_review',
        ];
    }

    private function fieldValue(array $fields, string $fieldKey): mixed
    {
        return match ($fieldKey) {
            'platform_grade' => $fields['platform_grade'] ?? $fields['grade_label'] ?? $fields['diamond_level'] ?? $fields['star_level'] ?? null,
            'location' => $fields['location'] ?? $fields['city_name'] ?? (($fields['latitude'] ?? null) !== null && ($fields['longitude'] ?? null) !== null
                ? ['latitude' => $fields['latitude'], 'longitude' => $fields['longitude']]
                : null),
            'images' => $fields['images'] ?? array_values(array_filter(array_merge(
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
        $platformLabel = $platform === 'ctrip' ? '携程' : '美团';
        if ($observedCount === 0 && $latestDate !== '' && $latestDate !== $businessDate) {
            return '所选日期没有' . $platformLabel . '公开页快照；最新可用日期为 ' . $latestDate . '，可切换日期查看或补录当前日期证据。';
        }
        if ($observedCount === 0) {
            return $platform === 'ctrip'
                ? '先绑定携程公开酒店 ID 并采集保存；没有来源网址、采集时间和响应引用时不评分。'
                : '录入刚核对的美团公开页字段、页面定位和采集时间；商家后台数据不能替代公开页证据。';
        }
        if ($observedCount === $expectedCount && $verifiedCount < $expectedCount) {
            return '字段覆盖已齐全，但仍含过期、观察或未验证来源；需重新采集或完成来源验证后才能标记证据完整。';
        }
        if ($verifiedCount === $expectedCount) {
            return '公开页字段及来源验证已齐全；该证据目录仍不自动计算经营评分。';
        }
        return '按未知字段清单补充可复核公开页证据；覆盖不足前保持 insufficient_evidence。';
    }

    /** @param array<int, array<string, mixed>> $profiles */
    private function platformSourceStatus(string $platform, array $profiles): string
    {
        if ($platform === 'ctrip') {
            return $profiles === []
                ? 'public_hotel_binding_or_snapshot_missing'
                : 'persisted_public_profile_snapshots';
        }
        return $profiles === []
            ? 'manual_public_profile_entry_available'
            : 'persisted_manual_public_page_observations';
    }

    private function evidenceRef(array $profile, int $snapshotId, string $platform): ?string
    {
        $explicit = trim((string)($profile['response_ref'] ?? ''));
        if (preg_match('/^(?:ota_ctrip_entity_snapshots|online_daily_data)#[1-9][0-9]*$/D', $explicit) === 1) {
            return $explicit;
        }
        if ($snapshotId <= 0) {
            return null;
        }
        return ($platform === 'ctrip' ? 'ota_ctrip_entity_snapshots#' : 'online_daily_data#') . $snapshotId;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
