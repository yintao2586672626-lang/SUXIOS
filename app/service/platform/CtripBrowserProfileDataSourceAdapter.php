<?php
declare(strict_types=1);

namespace app\service\platform;

use app\contract\DataSourceAdapter;
use app\service\CtripCollectorWorkflowService;
use think\facade\Db;

final class CtripBrowserProfileDataSourceAdapter implements DataSourceAdapter
{
    private const PROFILE_FIELDS_CONFIG_KEY = 'ctrip_profile_capture_fields';
    private const PROFILE_MODULES_CONFIG_KEY = 'ctrip_profile_capture_modules';
    private const FACT_GUARDED_NUMERIC_FIELDS = [
        'amount',
        'quantity',
        'book_order_num',
        'comment_score',
        'qunar_comment_score',
        'data_value',
        'list_exposure',
        'detail_exposure',
        'flow_rate',
        'order_filling_num',
        'order_submit_num',
    ];

    private string $projectRoot;
    private string $nodeBinary;

    /** @var callable|null */
    private $processRunner;

    public function __construct(?string $projectRoot = null, ?string $nodeBinary = null, ?callable $processRunner = null)
    {
        $this->projectRoot = $projectRoot ?: dirname(__DIR__, 3);
        $this->nodeBinary = $nodeBinary ?: $this->resolveNodeBinary();
        $this->processRunner = $processRunner;
    }

    public function supports(array $source): bool
    {
        return strtolower((string)($source['platform'] ?? '')) === 'ctrip'
            && in_array((string)($source['ingestion_method'] ?? ''), ['browser_profile', 'profile_browser'], true);
    }

    public function fetch(array $source, array $options = []): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $workflow = new CtripCollectorWorkflowService();
        $gate = $workflow->collectionGate($source, $options);
        if (empty($gate['allowed'])) {
            return [
                'status' => (string)$gate['status'],
                'status_code' => (string)$gate['reason'],
                'error_code' => (string)$gate['reason'],
                'message' => (string)$gate['message'],
                'payload' => [
                    'ctrip_collector_contract' => $workflow->buildContract($source, $options),
                ],
            ];
        }
        $options = $workflow->applyFlowOptions($options, $config);
        $systemHotelId = (int)($source['system_hotel_id'] ?? 0);
        $profileId = $this->firstString($options, $config, ['profile_id', 'profileId', 'browser_profile_id', 'browserProfileId']);
        if ($profileId === '') {
            return [
                'status' => 'waiting_config',
                'message' => 'Ctrip browser Profile ID is not configured.',
                'payload' => [],
            ];
        }

        $interactive = $this->truthy($options['interactive_browser'] ?? $options['interactiveBrowser'] ?? false);
        $profileDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . $this->safeName($profileId);
        $profilePrepared = is_dir($profileDir);
        if (!$profilePrepared && !$interactive) {
            return [
                'status' => 'waiting_config',
                'message' => 'Ctrip browser Profile is not prepared: storage/ctrip_profile_' . $this->safeName($profileId),
                'payload' => [],
            ];
        }

        $scriptPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return [
                'status' => 'failed',
                'message' => 'Ctrip browser capture script was not found.',
                'payload' => [],
            ];
        }
        if ($this->nodeBinary === '') {
            return [
                'status' => 'failed',
                'message' => 'Node.js is not configured for Ctrip browser capture.',
                'payload' => [],
            ];
        }

        $safeProfileId = $this->safeName($profileId);
        $lock = $this->acquireLock('ctrip', $safeProfileId);
        if ($lock === null) {
            return [
                'status' => 'failed',
                'status_code' => 'resource_busy_login',
                'error_code' => 'resource_busy_login',
                'message' => 'Ctrip browser Profile capture is already running for profile_id=' . $profileId,
                'payload' => ['lock_key' => 'ctrip:' . $safeProfileId],
            ];
        }

        $outputDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'platform_data_sources';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            $this->releaseLock($lock);
            return [
                'status' => 'failed',
                'message' => 'Cannot create Ctrip browser capture output directory.',
                'payload' => [],
            ];
        }

        $dataDate = $this->normalizeDate((string)($options['data_date'] ?? $options['dataDate'] ?? $config['data_date'] ?? $config['dataDate'] ?? ''));
        if ($dataDate === '') {
            $dataDate = date('Y-m-d', strtotime('-1 day'));
        }
        $fieldConfigPayload = $this->buildProfileFieldConfigPayload($options);
        if (!empty($fieldConfigPayload['configured']) && empty($fieldConfigPayload['allowed_field_keys'])) {
            $this->releaseLock($lock);
            return [
                'status' => 'waiting_config',
                'message' => 'Ctrip Profile field config has no enabled capture fields.',
                'payload' => [],
            ];
        }
        $sections = $this->resolveCaptureSections($options, $config, $fieldConfigPayload);
        $sectionList = $this->captureSectionList($sections);
        $notApplicableSectionList = $this->resolveNotApplicableSections($options, $config);
        if ($notApplicableSectionList !== []) {
            $notApplicableMap = array_fill_keys($notApplicableSectionList, true);
            $sectionList = array_values(array_filter(
                $sectionList,
                static fn(string $section): bool => !isset($notApplicableMap[$section])
            ));
            if ($sectionList === []) {
                $this->releaseLock($lock);
                return [
                    'status' => 'waiting_config',
                    'message' => 'All selected Ctrip Profile sections are marked not applicable for this source.',
                    'payload' => ['not_applicable_sections' => $notApplicableSectionList],
                ];
            }
            $sections = implode(',', $sectionList);
        }
        $hotelId = $this->firstString($options, $config, ['hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId', 'node_id', 'nodeId']);
        $hotelName = $this->firstString($options, $config, ['hotel_name', 'hotelName', 'name']);
        $timeoutSeconds = max(60, min(900, (int)($options['timeout_seconds'] ?? $options['timeoutSeconds'] ?? ($interactive ? 600 : 120))));
        $sectionConcurrency = $this->resolveCtripSectionConcurrency($options, $config);

        try {
            if ($this->shouldCaptureSectionsSequentially($options, $sectionList)) {
                return $this->runSequentialCaptureSections(
                    $source,
                    $scriptPath,
                    $profileId,
                    $systemHotelId,
                    $dataDate,
                    $sectionList,
                    $outputDir,
                    $hotelId,
                    $hotelName,
                    $interactive,
                    $timeoutSeconds,
                    $fieldConfigPayload,
                    $notApplicableSectionList
                );
            }

            $outputPath = $this->captureOutputPath($outputDir, $profileId);
            $result = $this->runCaptureProcess(
                $source,
                $scriptPath,
                $profileId,
                $systemHotelId,
                $dataDate,
                implode(',', $sectionList),
                $outputPath,
                $hotelId,
                $hotelName,
                $interactive,
                $timeoutSeconds,
                $fieldConfigPayload,
                [
                    'section_concurrency' => $sectionConcurrency,
                    'parallel_fallback' => true,
                    'not_applicable_sections' => $notApplicableSectionList,
                ]
            );
            if ($this->shouldFallbackToSequentialAfterParallel($result, $sectionList, $sectionConcurrency, $options)) {
                $fallback = $this->runSequentialCaptureSections(
                    $source,
                    $scriptPath,
                    $profileId,
                    $systemHotelId,
                    $dataDate,
                    $sectionList,
                    $outputDir,
                    $hotelId,
                    $hotelName,
                    $interactive,
                    $timeoutSeconds,
                    $fieldConfigPayload,
                    $notApplicableSectionList
                );
                if (is_array($fallback['payload'] ?? null)) {
                    $fallback['payload']['parallel_capture_fallback'] = [
                        'reason' => (string)($result['message'] ?? 'parallel capture failed'),
                        'section_concurrency' => $sectionConcurrency,
                        'original_status' => (string)($result['status'] ?? ''),
                    ];
                }
                if (($fallback['status'] ?? '') === 'success') {
                    $fallback['message'] = 'Ctrip browser Profile parallel capture failed; sequential fallback completed. ' . (string)($fallback['message'] ?? '');
                }
                return $fallback;
            }

            return $result;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @return array<string, mixed> */
    private function evaluatePlatformIdentity(
        array $payload,
        string $expectedHotelId,
        string $expectedPlatformHotelName = '',
        bool $trustedNameReference = false
    ): array
    {
        $expectedHotelId = trim($expectedHotelId);
        $expectedPlatformHotelName = preg_replace('/\s+/u', ' ', trim($expectedPlatformHotelName)) ?? '';
        if ($expectedHotelId === '') {
            return [
                'schema_version' => 1,
                'status' => 'not_configured',
                'expected_identifier_present' => false,
                'observed_identifier_count' => 0,
                'validated_identifier' => '',
                'sensitive_values_exposed' => false,
            ];
        }

        $scriptValidation = is_array($payload['platform_identity_validation'] ?? null)
            ? $payload['platform_identity_validation']
            : [];
        $scriptStatus = strtolower(trim((string)($scriptValidation['status'] ?? '')));
        $scriptIdentifier = trim((string)($scriptValidation['validated_identifier'] ?? ''));
        $scriptObservedCount = max(0, (int)($scriptValidation['observed_identifier_count'] ?? 0));
        $scriptMatchedCount = max(0, (int)($scriptValidation['matched_identifier_count'] ?? 0));
        $scriptMismatchedCount = max(0, (int)($scriptValidation['mismatched_identifier_count'] ?? 0));
        $scriptEvidenceSource = trim((string)($scriptValidation['evidence_source'] ?? ''));
        $scriptSchemaValid = (int)($scriptValidation['schema_version'] ?? 0) === 1
            && (int)($scriptValidation['expected_identifier_count'] ?? 0) === 1
            && ($scriptValidation['sensitive_values_exposed'] ?? true) === false;
        $requestContractValid = $scriptSchemaValid && $scriptEvidenceSource === 'ota_request';
        if ($requestContractValid
            && $scriptStatus === 'matched'
            && ($scriptValidation['source_validation'] ?? null) === true
            && $scriptIdentifier !== ''
            && $scriptObservedCount === 1
            && $scriptMatchedCount === 1
            && $scriptMismatchedCount === 0
        ) {
            $matched = hash_equals($expectedHotelId, $scriptIdentifier);
            return [
                'schema_version' => 1,
                'status' => $matched ? 'matched' : 'mismatch',
                'expected_identifier_present' => true,
                'observed_identifier_count' => 1,
                'validated_identifier' => $matched ? $expectedHotelId : '',
                'evidence_source' => 'ota_request',
                'sensitive_values_exposed' => false,
            ];
        }
        if ($requestContractValid
            && in_array($scriptStatus, ['mismatch', 'ambiguous'], true)
            && $scriptObservedCount > 0
        ) {
            return [
                'schema_version' => 1,
                'status' => 'mismatch',
                'expected_identifier_present' => true,
                'observed_identifier_count' => $scriptObservedCount,
                'validated_identifier' => '',
                'evidence_source' => 'ota_request',
                'sensitive_values_exposed' => false,
            ];
        }

        $scriptName = preg_replace('/\s+/u', ' ', trim((string)($scriptValidation['validated_name'] ?? ''))) ?? '';
        $scriptObservedNameCount = max(0, (int)($scriptValidation['observed_name_count'] ?? 0));
        $scriptMatchedNameCount = max(0, (int)($scriptValidation['matched_name_count'] ?? 0));
        $scriptMismatchedNameCount = max(0, (int)($scriptValidation['mismatched_name_count'] ?? 0));
        $scriptObservedPageStateCount = max(0, (int)($scriptValidation['observed_page_state_identifier_count'] ?? 0));
        $scriptMatchedPageStateCount = max(0, (int)($scriptValidation['matched_page_state_identifier_count'] ?? 0));
        $scriptMismatchedPageStateCount = max(0, (int)($scriptValidation['mismatched_page_state_identifier_count'] ?? 0));
        $pageStateContractValid = $scriptSchemaValid
            && $scriptEvidenceSource === 'trusted_ota_page_state'
            && $trustedNameReference
            && $expectedPlatformHotelName !== ''
            && (int)($scriptValidation['expected_name_count'] ?? 0) === 1;
        if ($pageStateContractValid
            && $scriptStatus === 'matched'
            && ($scriptValidation['source_validation'] ?? null) === true
            && $scriptObservedCount === 0
            && $scriptObservedPageStateCount === 1
            && $scriptMatchedPageStateCount === 1
            && $scriptMismatchedPageStateCount === 0
            && $scriptObservedNameCount === 1
            && $scriptMatchedNameCount === 1
            && $scriptMismatchedNameCount === 0
            && hash_equals($expectedHotelId, $scriptIdentifier)
            && hash_equals($expectedPlatformHotelName, $scriptName)
        ) {
            return [
                'schema_version' => 1,
                'status' => 'matched',
                'expected_identifier_present' => true,
                'observed_identifier_count' => 0,
                'observed_page_state_identifier_count' => 1,
                'observed_name_count' => 1,
                'validated_identifier' => $expectedHotelId,
                'validated_name' => $expectedPlatformHotelName,
                'evidence_source' => 'trusted_ota_page_state',
                'identity_reference_source' => 'trip_public_profile',
                'sensitive_values_exposed' => false,
            ];
        }
        if ($pageStateContractValid
            && in_array($scriptStatus, ['mismatch', 'ambiguous'], true)
            && $scriptObservedPageStateCount > 0
        ) {
            return [
                'schema_version' => 1,
                'status' => 'mismatch',
                'expected_identifier_present' => true,
                'observed_identifier_count' => 0,
                'observed_page_state_identifier_count' => $scriptObservedPageStateCount,
                'validated_identifier' => '',
                'validated_name' => '',
                'evidence_source' => 'trusted_ota_page_state',
                'identity_reference_source' => 'trip_public_profile',
                'sensitive_values_exposed' => false,
            ];
        }
        $nameContractValid = $scriptSchemaValid
            && $scriptEvidenceSource === 'trusted_ota_page_header'
            && $trustedNameReference
            && $expectedPlatformHotelName !== ''
            && (int)($scriptValidation['expected_name_count'] ?? 0) === 1;
        if ($nameContractValid && $scriptObservedCount === 0 && $scriptObservedNameCount > 0) {
            $nameMatched = $scriptObservedNameCount === 1
                && $scriptMatchedNameCount === 1
                && $scriptMismatchedNameCount === 0
                && $scriptName !== ''
                && hash_equals($expectedPlatformHotelName, $scriptName);
            return [
                'schema_version' => 1,
                // A visible exact name is supporting evidence only. It cannot
                // synthesize the platform hotel identifier required for writes.
                'status' => $nameMatched ? 'unverified' : 'mismatch',
                'expected_identifier_present' => true,
                'observed_identifier_count' => 0,
                'observed_name_count' => $scriptObservedNameCount,
                'validated_identifier' => '',
                'validated_name' => $nameMatched ? $expectedPlatformHotelName : '',
                'evidence_source' => 'trusted_ota_page_header',
                'identity_reference_source' => 'trip_public_profile',
                'source_validation' => false,
                'sensitive_values_exposed' => false,
            ];
        }

        $observed = [];
        foreach (is_array($payload['catalog_facts'] ?? null) ? $payload['catalog_facts'] : [] as $fact) {
            if (!is_array($fact) || strtolower(trim((string)($fact['metric_key'] ?? ''))) !== 'hotel_id') {
                continue;
            }
            $sourceKey = strtolower(trim((string)($fact['source_key'] ?? '')));
            if (!in_array($sourceKey, ['masterhotelid', 'master_hotel_id', 'hotelid', 'hotel_id'], true)) {
                continue;
            }
            $value = trim((string)($fact['value'] ?? ''));
            if ($value !== '' && $value !== '-1') {
                $observed[$value] = true;
            }
        }
        foreach (is_array($payload['standard_rows'] ?? null) ? $payload['standard_rows'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawData = is_array($row['raw_data'] ?? null) ? $row['raw_data'] : [];
            if (trim((string)($rawData['hotel_id_source_key'] ?? '')) === '') {
                continue;
            }
            $value = trim((string)($row['hotel_id'] ?? $row['hotelId'] ?? ''));
            if ($value !== '' && $value !== '-1') {
                $observed[$value] = true;
            }
        }

        $observedIds = array_keys($observed);
        $status = count($observedIds) === 1 && (string)$observedIds[0] === $expectedHotelId
            ? 'matched'
            : ($observedIds !== [] ? 'mismatch' : 'unverified');
        return [
            'schema_version' => 1,
            'status' => $status,
            'expected_identifier_present' => true,
            'observed_identifier_count' => count($observedIds),
            'validated_identifier' => $status === 'matched' ? $expectedHotelId : '',
            'sensitive_values_exposed' => false,
        ];
    }

    /** @return array{valid: bool, name: string, source: string} */
    private function platformNameIdentityReference(array $source, string $expectedHotelId): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $name = $this->firstString([], $config, ['platform_hotel_name', 'platformHotelName']);
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        $referenceSource = strtolower($this->firstString([], $config, [
            'platform_hotel_identity_source',
            'platformHotelIdentitySource',
        ]));
        $publicUrl = $this->firstString([], $config, ['platform_hotel_public_url', 'platformHotelPublicUrl']);
        $checkedAt = $this->firstString([], $config, ['platform_hotel_identity_checked_at', 'platformHotelIdentityCheckedAt']);
        $parts = $publicUrl !== '' ? parse_url($publicUrl) : false;
        $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
        $path = is_array($parts) ? (string)($parts['path'] ?? '') : '';
        $trustedHost = $host === 'trip.com' || str_ends_with($host, '.trip.com');
        $pathHasExpectedId = $expectedHotelId !== ''
            && preg_match(
                '~(?:^|[^0-9])hotel-detail-' . preg_quote($expectedHotelId, '~') . '(?:[^0-9]|$)~i',
                $path
            ) === 1;
        $valid = $name !== ''
            && $expectedHotelId !== ''
            && $referenceSource === 'trip_public_profile'
            && is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && $trustedHost
            && $pathHasExpectedId
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment'])
            && $checkedAt !== ''
            && strtotime($checkedAt) !== false;

        return [
            'valid' => $valid,
            'name' => $name,
            'source' => $referenceSource,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(array $payload, array $source, int $systemHotelId, string $dataDate, string $platformHotelId): array
    {
        $rows = [];
        foreach (['standard_rows', 'business', 'traffic'] as $section) {
            $sectionRows = is_array($payload[$section] ?? null) ? $payload[$section] : [];
            foreach ($sectionRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row = $this->alignStandardMetricPlaceholdersWithFieldFacts($row);
                $row['source'] = 'ctrip';
                $row['platform'] = $row['platform'] ?? 'ctrip';
                $row['system_hotel_id'] = $row['system_hotel_id'] ?? $systemHotelId;
                $row['hotel_id'] = $row['hotel_id'] ?? $row['hotelId'] ?? $platformHotelId;
                $row['hotel_name'] = $row['hotel_name'] ?? $row['hotelName'] ?? $source['name'] ?? '';
                $row['data_date'] = $this->normalizeDate((string)($row['data_date'] ?? $row['dataDate'] ?? $row['date'] ?? '')) ?: $dataDate;
                if (!isset($row['data_type'])) {
                    $row['data_type'] = $section === 'traffic' ? 'traffic' : 'business';
                }
                $row['acquisition_method'] = 'browser_profile';
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function alignStandardMetricPlaceholdersWithFieldFacts(array $row): array
    {
        $rawData = $row['raw_data'] ?? null;
        if (is_string($rawData) && trim($rawData) !== '') {
            $decoded = json_decode($rawData, true);
            $rawData = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($rawData) || !is_array($rawData['field_facts'] ?? null)) {
            return $row;
        }

        $fieldStates = [];
        foreach ($rawData['field_facts'] as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $storageField = trim((string)($fact['storage_field'] ?? ''));
            if (!str_starts_with($storageField, 'online_daily_data.')) {
                continue;
            }
            $field = substr($storageField, strlen('online_daily_data.'));
            if (!in_array($field, self::FACT_GUARDED_NUMERIC_FIELDS, true) || !array_key_exists($field, $row)) {
                continue;
            }

            $fieldStates[$field] ??= ['captured' => false];
            if (strtolower(trim((string)($fact['status'] ?? ''))) === 'captured'
                && $this->truthy($fact['stored_value_present'] ?? false)
            ) {
                $fieldStates[$field]['captured'] = true;
            }
        }

        foreach ($fieldStates as $field => $state) {
            if (($state['captured'] ?? false) === true || !$this->isMissingMetricPlaceholder($row[$field])) {
                continue;
            }
            $row[$field] = null;
        }

        return $row;
    }

    private function isMissingMetricPlaceholder(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '') {
            return true;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return true;
            }
        }

        return is_numeric($value) && (float)$value === 0.0;
    }

    /**
     * @param array<int, string> $sectionList
     */
    private function runSequentialCaptureSections(
        array $source,
        string $scriptPath,
        string $profileId,
        int $systemHotelId,
        string $dataDate,
        array $sectionList,
        string $outputDir,
        string $hotelId,
        string $hotelName,
        bool $interactive,
        int $timeoutSeconds,
        array $fieldConfigPayload,
        array $notApplicableSectionList = []
    ): array {
        $payloads = [];
        $moduleResults = [];
        $firstFailure = null;

        foreach ($sectionList as $section) {
            $sectionFieldConfig = $this->filterProfileFieldConfigPayloadForSections($fieldConfigPayload, [$section]);
            $outputPath = $this->captureOutputPath($outputDir, $profileId, $section);
            $result = $this->runCaptureProcess(
                $source,
                $scriptPath,
                $profileId,
                $systemHotelId,
                $dataDate,
                $section,
                $outputPath,
                $hotelId,
                $hotelName,
                $interactive,
                $timeoutSeconds,
                $sectionFieldConfig,
                [
                    'not_applicable_sections' => $notApplicableSectionList,
                ]
            );
            $moduleResults[] = $this->captureModuleResultSummary($section, $result);

            if (($result['status'] ?? '') === 'success') {
                $payloads[] = $result['payload'];
                continue;
            }

            if ($firstFailure === null) {
                $firstFailure = $result;
            }
            if (($result['status'] ?? '') === 'waiting_config') {
                break;
            }
        }

        if ($payloads === []) {
            $failurePayload = is_array($firstFailure['payload'] ?? null) ? $firstFailure['payload'] : [];
            $failurePayload['capture_module_results'] = $moduleResults;
            $failureStatus = (string)($firstFailure['status'] ?? 'failed');
            $failureMessage = (string)($firstFailure['message'] ?? 'unknown error');
            $failureResult = [
                'status' => $failureStatus,
                'message' => $failureStatus === 'waiting_config'
                    ? $failureMessage
                    : 'Ctrip browser Profile section capture failed: ' . $failureMessage,
                'payload' => $failurePayload,
            ];
            foreach (['status_code', 'error_code'] as $key) {
                $value = trim((string)($firstFailure[$key] ?? ''));
                if ($value !== '') {
                    $failureResult[$key] = $value;
                }
            }
            return $failureResult;
        }

        $payload = $this->mergeSequentialCapturePayloads(
            $payloads,
            $moduleResults,
            $sectionList,
            $profileId,
            $dataDate
        );
        $payload['not_applicable_sections'] = $notApplicableSectionList;
        $failures = array_values(array_filter(
            $moduleResults,
            static fn(array $item): bool => ($item['status'] ?? '') !== 'success'
        ));
        $message = 'Ctrip browser Profile capture completed by section.';
        if ($failures !== []) {
            $payload['capture_module_warning'] = [
                'level' => 'warning',
                'message' => 'Some Ctrip Profile sections failed. Saved successful section rows and retained failed section diagnostics.',
                'failed_sections' => array_values(array_map(static fn(array $item): string => (string)$item['section'], $failures)),
            ];
            $message .= ' Some sections failed; diagnostics retained.';
        }

        return [
            'status' => 'success',
            'message' => $message,
            'payload' => $payload,
        ];
    }

    private function runCaptureProcess(
        array $source,
        string $scriptPath,
        string $profileId,
        int $systemHotelId,
        string $dataDate,
        string $sections,
        string $outputPath,
        string $hotelId,
        string $hotelName,
        bool $interactive,
        int $timeoutSeconds,
        array $fieldConfigPayload,
        array $captureOptions = []
    ): array {
        $identityReference = $this->platformNameIdentityReference($source, $hotelId);
        $args = [
            $this->nodeBinary,
            $scriptPath,
            '--profile-id=' . $profileId,
            '--system-hotel-id=' . (string)$systemHotelId,
            '--data-date=' . $dataDate,
            '--output=' . $outputPath,
            '--login-timeout-ms=' . ($interactive ? '300000' : '30000'),
            '--sections=' . $sections,
            $interactive ? '--headless=false' : '--headless=true',
        ];
        $sectionConcurrency = (int)($captureOptions['section_concurrency'] ?? 0);
        if ($sectionConcurrency > 0) {
            $args[] = '--section-concurrency=' . max(1, min(4, $sectionConcurrency));
        }
        if (array_key_exists('parallel_fallback', $captureOptions) && !$this->truthy($captureOptions['parallel_fallback'])) {
            $args[] = '--disable-parallel-fallback=true';
        }
        $notApplicableSections = $this->normalizeOptionalSectionList($captureOptions['not_applicable_sections'] ?? []);
        if ($notApplicableSections !== []) {
            $args[] = '--not-applicable-sections=' . implode(',', $notApplicableSections);
        }
        if ($hotelId !== '') {
            $args[] = '--hotel-id=' . $hotelId;
        }
        if ($hotelName !== '') {
            $args[] = '--hotel-name=' . $hotelName;
        }
        if ($identityReference['valid'] && $identityReference['name'] !== '') {
            $args[] = '--platform-hotel-name=' . $identityReference['name'];
        }

        $fieldConfigPath = '';
        if (!empty($fieldConfigPayload['configured'])) {
            $fieldConfigPath = $this->createProfileFieldConfigFile($fieldConfigPayload);
            if ($fieldConfigPath === '') {
                return [
                    'status' => 'failed',
                    'message' => 'Cannot create Ctrip Profile field config snapshot.',
                    'payload' => ['capture_sections' => $sections],
                ];
            }
            $args[] = '--field-config=' . $fieldConfigPath;
        }
        try {
            $runResult = $this->runProcess($args, $this->projectRoot, $timeoutSeconds);
        } finally {
            if ($fieldConfigPath !== '' && is_file($fieldConfigPath)) {
                @unlink($fieldConfigPath);
            }
        }

        return $this->buildCaptureResultFromOutput(
            $source,
            $profileId,
            $systemHotelId,
            $dataDate,
            $sections,
            $outputPath,
            $hotelId,
            $runResult
        );
    }

    private function buildCaptureResultFromOutput(
        array $source,
        string $profileId,
        int $systemHotelId,
        string $dataDate,
        string $sections,
        string $outputPath,
        string $hotelId,
        array $runResult
    ): array {
        if (!is_file($outputPath)) {
            $message = $this->buildProcessFailureMessage(
                'Ctrip browser capture did not produce an output file',
                $runResult
            );
            return [
                'status' => 'failed',
                'message' => $message,
                'payload' => [
                    'error_summary' => $message,
                    'capture_sections' => $sections,
                    'stdout' => $this->trimLog((string)($runResult['stdout'] ?? '')),
                    'stderr' => $this->trimLog((string)($runResult['stderr'] ?? '')),
                ],
            ];
        }

        $payload = json_decode((string)file_get_contents($outputPath), true);
        if (!is_array($payload)) {
            return [
                'status' => 'failed',
                'message' => 'Ctrip browser capture output is not valid JSON.',
                'payload' => ['output' => $outputPath, 'capture_sections' => $sections],
            ];
        }
        $payload['output'] = $outputPath;
        $payload['data_source_capture'] = [
            'platform' => 'ctrip',
            'acquisition_method' => 'browser_profile',
            'profile_id' => $profileId,
            'capture_sections' => $sections,
            'not_applicable_sections' => $this->normalizeOptionalSectionList($payload['not_applicable_sections'] ?? []),
            'data_date' => $dataDate,
            'captured_by' => 'platform_data_source_sync',
        ];

        $authOk = (bool)($payload['auth_status']['ok'] ?? false);
        if (!$authOk) {
            return [
                'status' => 'waiting_config',
                'message' => (string)($payload['auth_status']['message'] ?? 'Ctrip login session is not ready; open the Profile and complete login.'),
                'payload' => $this->compactFailurePayload($payload, $runResult),
            ];
        }

        $identityReference = $this->platformNameIdentityReference($source, $hotelId);
        $payload['platform_identity_validation'] = $this->evaluatePlatformIdentity(
            $payload,
            $hotelId,
            $identityReference['name'],
            $identityReference['valid']
        );
        $identityStatus = strtolower(trim((string)($payload['platform_identity_validation']['status'] ?? 'unverified')));

        $gate = is_array($payload['capture_gate'] ?? null) ? $payload['capture_gate'] : [];
        $gateWarning = null;
        if (($gate['status'] ?? 'fail') !== 'pass') {
            $failedCheckIds = $this->captureGateFailedCheckIds($gate);
            if (!$this->canContinueWithSoftCaptureGateWarning($payload, $failedCheckIds)) {
                $failedIds = implode(',', $failedCheckIds);
                return [
                    'status' => 'failed',
                    'message' => 'Ctrip browser capture gate failed' . ($failedIds !== '' ? ': ' . $failedIds : '.'),
                    'payload' => $this->compactFailurePayload($payload, $runResult),
                ];
            }
            $gateWarning = $this->buildCaptureGateWarning($gate, $failedCheckIds);
        }

        if ($identityStatus !== 'matched') {
            $identityStatusCode = $identityStatus === 'mismatch'
                ? 'ctrip_platform_identity_mismatch'
                : 'ctrip_platform_identity_unverified';
            return [
                'status' => 'failed',
                'status_code' => $identityStatusCode,
                'error_code' => $identityStatusCode,
                'message' => $identityStatusCode,
                'payload' => $this->compactFailurePayload($payload, $runResult),
            ];
        }

        $rows = $this->buildRows($payload, $source, $systemHotelId, $dataDate, $hotelId);
        if (empty($rows)) {
            return [
                'status' => 'failed',
                'message' => 'Ctrip browser capture completed but no business rows were parsed.',
                'payload' => $this->compactFailurePayload($payload, $runResult),
            ];
        }
        if ($gateWarning !== null) {
            $payload['capture_gate_warning'] = $gateWarning;
        }
        $payload['rows'] = $rows;
        $payload['sync_summary'] = [
            'row_count' => count($rows),
            'standard_row_count' => count(is_array($payload['standard_rows'] ?? null) ? $payload['standard_rows'] : []),
            'business_count' => count(is_array($payload['business'] ?? null) ? $payload['business'] : []),
            'traffic_count' => count(is_array($payload['traffic'] ?? null) ? $payload['traffic'] : []),
        ];

        return [
            'status' => 'success',
            'message' => 'Ctrip browser Profile capture completed.' . ($gateWarning !== null ? ' Capture gate warning retained.' : ''),
            'payload' => $payload,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $payloads
     * @param array<int, array<string, mixed>> $moduleResults
     * @param array<int, string> $sectionList
     */
    private function mergeSequentialCapturePayloads(array $payloads, array $moduleResults, array $sectionList, string $profileId, string $dataDate): array
    {
        $base = $payloads[0];
        foreach (['pages', 'responses', 'xhr_urls', 'unmatched_xhr_urls', 'endpoint_candidates', 'p3_evidence_drafts', 'rows', 'standard_rows', 'catalog_facts', 'business', 'traffic', 'reviews', 'screenshots'] as $key) {
            $base[$key] = $this->mergePayloadLists($payloads, $key);
        }

        $bySection = array_fill_keys($sectionList, []);
        foreach ($payloads as $payload) {
            foreach (is_array($payload['by_section'] ?? null) ? $payload['by_section'] : [] as $section => $rows) {
                $section = (string)$section;
                $bySection[$section] = array_merge($bySection[$section] ?? [], is_array($rows) ? $rows : []);
            }
        }

        $failures = array_values(array_filter(
            $moduleResults,
            static fn(array $item): bool => ($item['status'] ?? '') !== 'success'
        ));
        $base['requested_sections'] = $sectionList;
        $base['by_section'] = $bySection;
        $base['outputs'] = array_values(array_filter(array_map(
            static fn(array $item): string => (string)($item['output'] ?? ''),
            $moduleResults
        )));
        $base['capture_module_results'] = $moduleResults;
        $base['capture_gate'] = [
            'status' => $failures === [] ? 'pass' : 'partial',
            'failed_check_ids' => $failures === [] ? [] : ['module_capture'],
            'module_failure_count' => count($failures),
        ];
        $base['data_source_capture'] = [
            'platform' => 'ctrip',
            'acquisition_method' => 'browser_profile',
            'profile_id' => $profileId,
            'capture_sections' => implode(',', $sectionList),
            'not_applicable_sections' => $this->normalizeOptionalSectionList($base['not_applicable_sections'] ?? []),
            'capture_mode' => 'sequential_sections',
            'data_date' => $dataDate,
            'captured_by' => 'platform_data_source_sync',
        ];
        if (is_array($base['profile_field_config'] ?? null)) {
            $base['profile_field_config']['allowed_sections'] = $sectionList;
            $base['profile_field_config']['allowed_section_count'] = count($sectionList);
        }
        $base['sync_summary'] = [
            'row_count' => count(is_array($base['rows'] ?? null) ? $base['rows'] : []),
            'standard_row_count' => count(is_array($base['standard_rows'] ?? null) ? $base['standard_rows'] : []),
            'business_count' => count(is_array($base['business'] ?? null) ? $base['business'] : []),
            'traffic_count' => count(is_array($base['traffic'] ?? null) ? $base['traffic'] : []),
            'section_count' => count($sectionList),
            'module_success_count' => count($moduleResults) - count($failures),
            'module_failure_count' => count($failures),
        ];

        return $base;
    }

    /**
     * @param array<int, array<string, mixed>> $payloads
     * @return array<int, mixed>
     */
    private function mergePayloadLists(array $payloads, string $key): array
    {
        $merged = [];
        foreach ($payloads as $payload) {
            $rows = is_array($payload[$key] ?? null) ? $payload[$key] : [];
            foreach ($rows as $row) {
                $merged[] = $row;
            }
        }
        return $merged;
    }

    private function captureModuleResultSummary(string $section, array $result): array
    {
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        return [
            'section' => $section,
            'status' => (string)($result['status'] ?? 'unknown'),
            'message' => (string)($result['message'] ?? ''),
            'output' => (string)($payload['output'] ?? ''),
            'row_count' => count(is_array($payload['rows'] ?? null) ? $payload['rows'] : []),
            'standard_row_count' => count(is_array($payload['standard_rows'] ?? null) ? $payload['standard_rows'] : []),
            'business_count' => count(is_array($payload['business'] ?? null) ? $payload['business'] : []),
            'traffic_count' => count(is_array($payload['traffic'] ?? null) ? $payload['traffic'] : []),
            'capture_gate_status' => (string)($payload['capture_gate']['status'] ?? ''),
        ];
    }

    private function captureOutputPath(string $outputDir, string $profileId, string $section = ''): string
    {
        $suffix = date('YmdHis') . ($section !== '' ? '_' . $this->safeName($section) : '');
        return $outputDir . DIRECTORY_SEPARATOR . 'ctrip_browser_source_' . $this->safeName($profileId) . '_' . $suffix . '.json';
    }

    /**
     * @return array<int, string>
     */
    private function captureSectionList(string $sections): array
    {
        $items = array_values(array_unique(array_filter(array_map(
            fn($item): string => $this->normalizeSectionKey((string)$item),
            preg_split('/[,\s]+/', $sections) ?: []
        ))));
        return $items !== [] ? $items : ['business_overview'];
    }

    /**
     * @return array<int, string>
     */
    private function resolveNotApplicableSections(array $options, array $config): array
    {
        foreach (['not_applicable_sections', 'notApplicableSections', 'excluded_sections', 'excludedSections'] as $key) {
            if (array_key_exists($key, $options)) {
                return $this->normalizeOptionalSectionList($options[$key]);
            }
            if (array_key_exists($key, $config)) {
                return $this->normalizeOptionalSectionList($config[$key]);
            }
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeOptionalSectionList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $tokens = is_array($value)
            ? $value
            : (preg_split('/[,\s]+/', strtolower(trim((string)$value))) ?: []);
        $sections = [];
        foreach ($tokens as $token) {
            $section = $this->normalizeCaptureSectionToken((string)$token);
            if ($section !== '' && !in_array($section, $sections, true)) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    private function normalizeCaptureSectionToken(string $token): string
    {
        $token = $this->normalizeSectionKey($token);
        $aliases = [
            'business' => 'business_overview',
            'overview' => 'business_overview',
            'outline' => 'business_overview',
            'weekly' => 'business_weekly_overview',
            'week' => 'business_weekly_overview',
            'sales' => 'sales_report',
            'sale' => 'sales_report',
            'traffic' => 'traffic_report',
            'flow' => 'traffic_report',
            'rank' => 'competitor_rank',
            'ranking' => 'competitor_rank',
            'ads' => 'ads_pyramid',
            'ad' => 'ads_pyramid',
            'psi' => 'quality_psi',
            'quality' => 'quality_psi',
            'comment' => 'comment_review',
            'comments' => 'comment_review',
            'review' => 'comment_review',
            'reviews' => 'comment_review',
            'market' => 'market_calendar',
            'user' => 'user_profile',
            'profile' => 'user_profile',
        ];

        return $aliases[$token] ?? $token;
    }

    /**
     * @param array<int, string> $sectionList
     */
    private function shouldCaptureSectionsSequentially(array $options, array $sectionList): bool
    {
        if (count($sectionList) <= 1) {
            return false;
        }
        foreach (['sequential_sections', 'sequentialSections', 'section_sequential', 'sectionSequential'] as $key) {
            if (array_key_exists($key, $options)) {
                return $this->truthy($options[$key]);
            }
        }
        return false;
    }

    private function resolveCtripSectionConcurrency(array $options, array $config): int
    {
        foreach (['ctrip_section_concurrency', 'ctripSectionConcurrency', 'section_concurrency', 'sectionConcurrency'] as $key) {
            $value = $options[$key] ?? $config[$key] ?? null;
            if ($value !== null && trim((string)$value) !== '') {
                return max(1, min(4, (int)$value));
            }
        }

        return 3;
    }

    /**
     * @param array<int, string> $sectionList
     */
    private function shouldFallbackToSequentialAfterParallel(array $result, array $sectionList, int $sectionConcurrency, array $options): bool
    {
        if (count($sectionList) <= 1 || $sectionConcurrency <= 1) {
            return false;
        }
        foreach (['disable_parallel_fallback', 'disableParallelFallback', 'disable_adapter_parallel_fallback', 'disableAdapterParallelFallback'] as $key) {
            if (array_key_exists($key, $options) && $this->truthy($options[$key])) {
                return false;
            }
        }
        $statusCode = strtolower(trim((string)($result['status_code'] ?? $result['error_code'] ?? '')));
        if (in_array($statusCode, [
            'ctrip_platform_identity_mismatch',
            'ctrip_platform_identity_unverified',
            'permission_denied',
            'platform_contract_drift',
            'anti_bot',
        ], true)) {
            return false;
        }
        return !in_array((string)($result['status'] ?? ''), ['success', 'partial_success'], true);
    }

    /**
     * @param array<int, string> $sections
     */
    private function filterProfileFieldConfigPayloadForSections(array $payload, array $sections): array
    {
        if (empty($payload['configured'])) {
            return $payload;
        }
        $sectionMap = array_fill_keys(array_map(fn($section): string => $this->normalizeSectionKey((string)$section), $sections), true);
        $fields = [];
        $allowedKeys = [];
        foreach (is_array($payload['fields'] ?? null) ? $payload['fields'] : [] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $section = $this->normalizeSectionKey((string)($field['section'] ?? ''));
            if ($section === '' || !isset($sectionMap[$section])) {
                continue;
            }
            $fields[] = $field;
            $fieldKey = $this->normalizeFieldKey((string)($field['field_key'] ?? $field['fieldKey'] ?? $field['id'] ?? ''));
            if ($fieldKey !== '') {
                $allowedKeys[$fieldKey] = true;
            }
        }

        $next = $payload;
        $next['allowed_sections'] = array_keys($sectionMap);
        $next['fields'] = $fields;
        $next['allowed_field_keys'] = $allowedKeys !== []
            ? array_keys($allowedKeys)
            : array_values(array_filter(array_map(
                fn($key): string => $this->normalizeFieldKey((string)$key),
                is_array($payload['allowed_field_keys'] ?? null) ? $payload['allowed_field_keys'] : []
            )));

        return $next;
    }

    private function compactFailurePayload(array $payload, array $runResult): array
    {
        return [
            'auth_status' => $payload['auth_status'] ?? null,
            'capture_gate' => $payload['capture_gate'] ?? null,
            'capture_gate_warning' => $payload['capture_gate_warning'] ?? null,
            'capture_audit' => $payload['capture_audit'] ?? null,
            'platform_identity_validation' => $payload['platform_identity_validation'] ?? null,
            'pages' => $payload['pages'] ?? [],
            'xhr_urls' => array_slice(is_array($payload['xhr_urls'] ?? null) ? $payload['xhr_urls'] : [], 0, 20),
            'output' => $payload['output'] ?? '',
            'stdout' => $this->trimLog((string)($runResult['stdout'] ?? '')),
            'stderr' => $this->trimLog((string)($runResult['stderr'] ?? '')),
        ];
    }

    private function captureGateFailedCheckIds(array $gate): array
    {
        return array_values(array_filter(array_map(
            static fn($item): string => trim((string)$item),
            is_array($gate['failed_check_ids'] ?? null) ? $gate['failed_check_ids'] : []
        )));
    }

    private function captureGateBlockingFailedCheckIds(array $failedCheckIds): array
    {
        $softCheckIds = ['field_coverage', 'endpoint_coverage'];
        return array_values(array_filter(
            $failedCheckIds,
            static fn($checkId): bool => !in_array((string)$checkId, $softCheckIds, true)
        ));
    }

    private function canContinueWithSoftCaptureGateWarning(array $payload, array $failedCheckIds): bool
    {
        if ($failedCheckIds === [] || $this->captureGateBlockingFailedCheckIds($failedCheckIds) !== []) {
            return false;
        }
        if (!(bool)($payload['auth_status']['ok'] ?? false)) {
            return false;
        }

        return $this->countPayloadRows($payload, 'standard_rows') > 0
            && (
                $this->countPayloadRows($payload, 'business') > 0
                || $this->countPayloadRows($payload, 'traffic') > 0
                || $this->countPayloadRows($payload, 'responses') > 0
            );
    }

    private function buildCaptureGateWarning(array $gate, array $failedCheckIds): array
    {
        return [
            'level' => 'warning',
            'message' => 'Ctrip browser Profile captured usable rows, but capture gate coverage has gaps. Saved captured rows and kept diagnostics for missing coverage.',
            'status' => (string)($gate['status'] ?? 'unknown'),
            'failed_check_ids' => $failedCheckIds,
            'blocking_failed_check_ids' => $this->captureGateBlockingFailedCheckIds($failedCheckIds),
        ];
    }

    private function countPayloadRows(array $payload, string $section): int
    {
        return is_array($payload[$section] ?? null) ? count($payload[$section]) : 0;
    }

    private function runProcess(array $args, string $cwd, int $timeoutSeconds): array
    {
        if ($this->processRunner !== null) {
            return (array)call_user_func($this->processRunner, $args, $cwd, $timeoutSeconds);
        }

        $command = implode(' ', array_map('escapeshellarg', $args));
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['success' => false, 'message' => 'Cannot start Ctrip browser capture process.', 'stdout' => '', 'stderr' => ''];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = time();
        $timedOut = false;
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() - $startedAt > $timeoutSeconds) {
                $timedOut = true;
                $this->terminateProcessTree((int)($status['pid'] ?? 0), $process);
                break;
            }
            usleep(250000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            return ['success' => false, 'message' => 'Ctrip browser capture timed out.', 'stdout' => $stdout, 'stderr' => $stderr];
        }
        if ($exitCode !== 0 && $exitCode !== -1) {
            return ['success' => false, 'message' => 'Ctrip browser capture exited with code ' . $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        return ['success' => true, 'message' => 'ok', 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function terminateProcessTree(int $pid, $process): void
    {
        if ($pid > 0 && PHP_OS_FAMILY === 'Windows') {
            @exec('taskkill /PID ' . (int)$pid . ' /T /F 2>NUL');
            return;
        }
        if (is_resource($process)) {
            @proc_terminate($process);
        }
    }

    private function acquireLock(string $platform, string $profileId)
    {
        $dir = $this->projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'profile_capture_' . $platform . '_' . $this->safeName($profileId) . '.lock';
        $handle = fopen($path, 'c+');
        if (!$handle) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        ftruncate($handle, 0);
        fwrite($handle, json_encode(['platform' => $platform, 'profile_id' => $profileId, 'pid' => getmypid(), 'locked_at' => date('c')], JSON_UNESCAPED_SLASHES));
        return $handle;
    }

    private function releaseLock($lock): void
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function resolveNodeBinary(): string
    {
        $candidates = array_filter([
            trim((string)(getenv('NODE_BINARY') ?: '')),
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
            getenv('USERPROFILE') ? getenv('USERPROFILE') . '\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\node\\bin\\node.exe' : '',
            'node',
        ]);
        foreach ($candidates as $candidate) {
            if ($candidate === 'node' || is_file($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    private function firstString(array $options, array $config, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = $options[$key] ?? $config[$key] ?? null;
            if ($value !== null && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }
        return $default;
    }

    private function sanitizeSections(string $sections): string
    {
        $sections = strtolower(preg_replace('/[^a-z,_\-\s]+/i', '', $sections) ?: '');
        $parts = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\s]+/', $sections) ?: []))));
        return implode(',', $parts) ?: 'business_overview';
    }

    private function resolveCaptureSections(array $options, array $config, array $fieldConfigPayload): string
    {
        $allowedSections = array_values(array_unique(array_filter(array_map(
            fn($item): string => $this->normalizeSectionKey((string)$item),
            is_array($fieldConfigPayload['allowed_sections'] ?? null) ? $fieldConfigPayload['allowed_sections'] : []
        ))));
        if (!empty($fieldConfigPayload['configured']) && $allowedSections !== []) {
            $hasOptionFieldConfig = array_key_exists('profile_field_config', $options)
                || array_key_exists('profileFieldConfig', $options);
            $hasOptionSections = false;
            foreach (['capture_sections', 'captureSections', 'sections', 'profile_sections'] as $key) {
                if (array_key_exists($key, $options) && trim((string)$options[$key]) !== '') {
                    $hasOptionSections = true;
                    break;
                }
            }
            if ($hasOptionFieldConfig && !$hasOptionSections) {
                foreach (['sequential_sections', 'sequentialSections', 'section_sequential', 'sectionSequential'] as $key) {
                    if (array_key_exists($key, $options) && $this->truthy($options[$key])) {
                        return implode(',', $allowedSections);
                    }
                }
            }
            $optionSections = $this->firstString($options, $config, ['capture_sections', 'captureSections', 'sections', 'profile_sections'], '');
            $tokens = array_values(array_unique(array_filter(array_map(
                'trim',
                preg_split('/[,\s]+/', strtolower($optionSections)) ?: []
            ))));
            $presetTokens = ['default' => true, 'core' => true, 'wide' => true, 'all' => true];
            if ($tokens === []) {
                return implode(',', $allowedSections);
            }
            if (count($tokens) === 1 && isset($presetTokens[$tokens[0]])) {
                return implode(',', $this->filterAllowedPresetSections($tokens[0], $allowedSections));
            }

            $aliases = [
                'business' => 'business_overview',
                'overview' => 'business_overview',
                'outline' => 'business_overview',
                'weekly' => 'business_weekly_overview',
                'week' => 'business_weekly_overview',
                'sales' => 'sales_report',
                'sale' => 'sales_report',
                'traffic' => 'traffic_report',
                'flow' => 'traffic_report',
                'rank' => 'competitor_rank',
                'ranking' => 'competitor_rank',
                'ads' => 'ads_pyramid',
                'ad' => 'ads_pyramid',
                'psi' => 'quality_psi',
                'quality' => 'quality_psi',
                'comment' => 'comment_review',
                'comments' => 'comment_review',
                'review' => 'comment_review',
                'reviews' => 'comment_review',
                'market' => 'market_calendar',
                'user' => 'user_profile',
                'profile' => 'user_profile',
            ];
            $allowedMap = array_fill_keys($allowedSections, true);
            $selected = [];
            foreach ($tokens as $token) {
                $section = $aliases[$token] ?? $this->normalizeSectionKey($token);
                if (isset($allowedMap[$section]) && !in_array($section, $selected, true)) {
                    $selected[] = $section;
                }
            }

            return implode(',', $selected ?: $allowedSections);
        }

        return $this->sanitizeSections($this->firstString($options, $config, ['capture_sections', 'captureSections', 'sections', 'profile_sections'], 'business_overview'));
    }

    /**
     * @param array<int, string> $allowedSections
     * @return array<int, string>
     */
    private function filterAllowedPresetSections(string $preset, array $allowedSections): array
    {
        $presetSections = match ($preset) {
            'all', 'wide' => $allowedSections,
            'core' => ['homepage', 'business_overview', 'business_weekly_overview', 'sales_report', 'traffic_report'],
            default => ['business_overview', 'business_weekly_overview', 'traffic_report'],
        };
        $allowedMap = array_fill_keys($allowedSections, true);
        $selected = array_values(array_filter(
            $presetSections,
            static fn(string $section): bool => isset($allowedMap[$section])
        ));
        return $selected !== [] ? $selected : $allowedSections;
    }

    private function buildProfileFieldConfigPayload(array $options = []): array
    {
        $payload = $this->profileFieldConfigFromOptions($options);
        $configured = $payload !== null;
        $fromOptions = $payload !== null;
        if ($payload === null) {
            $payload = $this->readSystemConfigPayload(self::PROFILE_FIELDS_CONFIG_KEY);
            $configured = $payload !== null;
        }
        if ($payload === null) {
            return ['configured' => false, 'allowed_sections' => [], 'allowed_field_keys' => [], 'fields' => []];
        }

        $moduleMap = $fromOptions ? [] : $this->activeProfileModuleMap();
        $rawFields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        if ($rawFields === [] && !$this->hasListPayload($payload)) {
            $rawFields = $payload;
        }

        $fields = [];
        $allowedKeys = [];
        $allowedSections = [];
        foreach ($rawFields as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (empty($item['id']) && is_string($key)) {
                $item['id'] = $key;
            }
            if ($this->fieldDeleted($item) || !$this->fieldEnabled($item['enabled'] ?? true)) {
                continue;
            }
            $section = $this->normalizeSectionKey((string)($item['section'] ?? $item['module'] ?? ''));
            if ($section === '' || ($moduleMap !== [] && !isset($moduleMap[$section]))) {
                continue;
            }
            $fieldKey = $this->normalizeFieldKey((string)($item['field_key'] ?? $item['fieldKey'] ?? $item['id'] ?? ''));
            if ($fieldKey === '') {
                continue;
            }

            $allowedKeys[$fieldKey] = true;
            $allowedSections[$section] = true;
            $field = [
                'id' => (string)($item['id'] ?? ''),
                'field_key' => $fieldKey,
                'field_name' => (string)($item['field_name'] ?? $item['fieldName'] ?? ''),
                'section' => $section,
                'data_type' => (string)($item['data_type'] ?? $item['dataType'] ?? ''),
                'source_interface' => (string)($item['source_interface'] ?? $item['sourceInterface'] ?? ''),
                'source_keys' => (string)($item['source_keys'] ?? $item['sourceKeys'] ?? ''),
                'status' => (string)($item['status'] ?? ''),
            ];
            $this->assertProfileFieldRuntimeMetadataSafe($field);
            $fields[] = $field;
        }

        foreach (is_array($payload['allowed_field_keys'] ?? null) ? $payload['allowed_field_keys'] : [] as $key) {
            $fieldKey = $this->normalizeFieldKey((string)$key);
            if ($fieldKey !== '') {
                $allowedKeys[$fieldKey] = true;
            }
        }
        foreach (is_array($payload['allowed_sections'] ?? null) ? $payload['allowed_sections'] : [] as $section) {
            $sectionKey = $this->normalizeSectionKey((string)$section);
            if ($sectionKey !== '' && ($moduleMap === [] || isset($moduleMap[$sectionKey]))) {
                $allowedSections[$sectionKey] = true;
            }
        }

        return [
            'configured' => $configured,
            'source' => self::PROFILE_FIELDS_CONFIG_KEY,
            'generated_at' => date('Y-m-d H:i:s'),
            'allowed_sections' => array_keys($allowedSections),
            'allowed_field_keys' => array_keys($allowedKeys),
            'fields' => $fields,
        ];
    }

    private function profileFieldConfigFromOptions(array $options): ?array
    {
        $value = $options['profile_field_config'] ?? $options['profileFieldConfig'] ?? null;
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    private function readSystemConfigPayload(string $key): ?array
    {
        try {
            $raw = Db::name('system_configs')->where('config_key', $key)->value('config_value');
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function activeProfileModuleMap(): array
    {
        $payload = $this->readSystemConfigPayload(self::PROFILE_MODULES_CONFIG_KEY);
        if ($payload === null) {
            return [];
        }
        $rawModules = is_array($payload['modules'] ?? null) ? $payload['modules'] : $payload;
        $modules = [];
        foreach ($rawModules as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $this->normalizeSectionKey((string)($item['id'] ?? (is_string($key) ? $key : '')));
            if ($id === '' || $this->fieldDeleted($item) || !$this->fieldEnabled($item['enabled'] ?? true)) {
                continue;
            }
            $modules[$id] = true;
        }
        return $modules;
    }

    private function createProfileFieldConfigFile(array $payload): string
    {
        $dir = $this->projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'platform_data_sources';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = $dir . DIRECTORY_SEPARATOR . 'ctrip_profile_field_config_' . bin2hex(random_bytes(6)) . '.json';
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            return '';
        }
        if (!@chmod($path, 0600)) {
            @unlink($path);
            return '';
        }
        return $path;
    }

    private function assertProfileFieldRuntimeMetadataSafe(array $field): void
    {
        foreach (['field_name', 'source_interface', 'source_keys'] as $key) {
            $value = trim((string)($field[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if (
                preg_match('/["\']?(?:cookie|set-cookie|authorization|proxy-authorization|x-api-key|api-key|auth_data|token|access_token|refresh_token|spidertoken|spiderkey|mtgsig|usertoken|usersign|password)["\']?\s*[:=]/i', $value) === 1
                || preg_match('/\bbearer\s+[A-Za-z0-9._~+\/=:-]{8,}/i', $value) === 1
            ) {
                throw new \RuntimeException('Ctrip Profile field metadata contains credential material.');
            }
        }
    }

    private function hasListPayload(array $payload): bool
    {
        return array_key_exists('version', $payload)
            || array_key_exists('fields', $payload)
            || array_key_exists('allowed_field_keys', $payload)
            || array_key_exists('allowed_sections', $payload);
    }

    private function fieldDeleted(array $item): bool
    {
        return trim((string)($item['deleted_at'] ?? $item['deletedAt'] ?? '')) !== '';
    }

    private function fieldEnabled(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }
        return !in_array(strtolower(trim((string)$value)), ['0', 'false', 'no', 'off', 'disabled'], true);
    }

    private function normalizeFieldKey(string $value): string
    {
        return trim((string)preg_replace('/[^a-z0-9_-]+/', '_', strtolower(trim($value))), '_-');
    }

    private function normalizeSectionKey(string $value): string
    {
        return trim((string)preg_replace('/[^a-z0-9_-]+/', '_', strtolower(trim($value))), '_-');
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    private function safeName(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($value)) ?: 'default';
    }

    private function trimLog(string $value): string
    {
        $value = trim($value);
        return mb_strlen($value) > 4000 ? mb_substr($value, -4000) : $value;
    }

    private function buildProcessFailureMessage(string $prefix, array $runResult): string
    {
        $message = trim((string)($runResult['message'] ?? 'unknown error'));
        $summary = $this->extractProcessErrorSummary(
            (string)($runResult['stderr'] ?? ''),
            (string)($runResult['stdout'] ?? '')
        );
        $result = $prefix . ($message !== '' ? ': ' . $message : '');
        return $summary !== '' ? $result . ' | ' . $summary : $result;
    }

    private function extractProcessErrorSummary(string $stderr, string $stdout): string
    {
        $text = trim($stderr) !== '' ? $stderr : $stdout;
        $text = trim((string)preg_replace('/\e\[[\d;]*m/', '', $text));
        if ($text === '') {
            return '';
        }
        if (stripos($text, 'spawn EPERM') !== false) {
            return 'browser_runtime_error=spawn EPERM; check browser executable permission and scheduled-task runtime account.';
        }
        if (stripos($text, 'spawn EACCES') !== false) {
            return 'browser_runtime_error=spawn EACCES; check browser executable permission and scheduled-task runtime account.';
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R+/', $text) ?: [])));
        foreach ($lines as $line) {
            if (stripos($line, 'Error') !== false || stripos($line, 'Exception') !== false || stripos($line, 'failed') !== false) {
                return mb_substr($line, 0, 240);
            }
        }
        return mb_substr((string)end($lines), 0, 240);
    }
}
