<?php
declare(strict_types=1);

namespace app\service\platform;

use app\contract\DataSourceAdapter;
use app\service\BrowserProfileCaptureRequestService;

final class MeituanBrowserProfileDataSourceAdapter implements DataSourceAdapter
{
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
        return strtolower((string)($source['platform'] ?? '')) === 'meituan'
            && in_array((string)($source['ingestion_method'] ?? ''), ['browser_profile', 'profile_browser'], true);
    }

    public function fetch(array $source, array $options = []): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $systemHotelId = (int)($source['system_hotel_id'] ?? 0);
        $storeId = $this->firstString($options, $config, ['store_id', 'storeId', 'poi_id', 'poiId']);
        if ($storeId === '') {
            return [
                'status' => 'waiting_config',
                'message' => 'Meituan browser Profile store_id/poi_id is not configured.',
                'payload' => [],
            ];
        }

        $safeStoreId = $this->safeName($storeId);
        $interactive = $this->truthy($options['interactive_browser'] ?? $options['interactiveBrowser'] ?? false);
        $profileDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meituan_profile_' . $safeStoreId;
        if (!is_dir($profileDir) && !$interactive) {
            return [
                'status' => 'waiting_config',
                'message' => 'Meituan browser Profile is not prepared: storage/meituan_profile_' . $safeStoreId,
                'payload' => [],
            ];
        }

        $scriptPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return [
                'status' => 'failed',
                'message' => 'Meituan browser capture script was not found.',
                'payload' => [],
            ];
        }
        if ($this->nodeBinary === '') {
            return [
                'status' => 'failed',
                'message' => 'Node.js is not configured for Meituan browser capture.',
                'payload' => [],
            ];
        }

        $lock = $this->acquireLock('meituan', $safeStoreId);
        if ($lock === null) {
            return [
                'status' => 'failed',
                'status_code' => 'resource_busy_login',
                'error_code' => 'resource_busy_login',
                'message' => 'Meituan browser Profile capture is already running for store_id=' . $storeId,
                'payload' => ['lock_key' => 'meituan:' . $safeStoreId],
            ];
        }

        $outputDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'platform_data_sources';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            $this->releaseLock($lock);
            return [
                'status' => 'failed',
                'message' => 'Cannot create Meituan browser capture output directory.',
                'payload' => [],
            ];
        }

        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'meituan_browser_source_' . $safeStoreId . '_' . date('YmdHis') . '.json';
        $dataDate = $this->normalizeDate((string)($options['data_date'] ?? $options['dataDate'] ?? $config['data_date'] ?? $config['dataDate'] ?? ''));
        if ($dataDate === '') {
            $dataDate = date('Y-m-d', strtotime('-1 day'));
        }
        $requestedSections = $this->firstString($options, $config, ['capture_sections', 'captureSections', 'sections', 'profile_sections'], BrowserProfileCaptureRequestService::MEITUAN_DEFAULT_SECTIONS);
        $sections = $this->sanitizeSections($requestedSections);
        $poiId = $this->firstString($options, $config, ['poi_id', 'poiId']);
        $poiName = $this->firstString($options, $config, ['poi_name', 'poiName', 'hotel_name', 'hotelName', 'name']);
        $adsUrl = $this->firstString($options, $config, ['ads_url', 'adsUrl']);
        $dataPeriod = $this->firstString($options, $config, ['data_period', 'dataPeriod']);
        $snapshotTime = $this->firstString($options, $config, ['snapshot_time', 'snapshotTime']);
        $timeoutSeconds = max(60, min(900, (int)($options['timeout_seconds'] ?? $options['timeoutSeconds'] ?? ($interactive ? 600 : 120))));

        $args = [
            $this->nodeBinary,
            $scriptPath,
            '--store-id=' . $storeId,
            '--system-hotel-id=' . (string)$systemHotelId,
            '--output=' . $outputPath,
            '--login-timeout-ms=' . ($interactive ? '300000' : '30000'),
            '--data-date=' . $dataDate,
            '--sections=' . $sections,
            $interactive ? '--headless=false' : '--headless=true',
        ];
        if ($poiId !== '') {
            $args[] = '--poi-id=' . $poiId;
        }
        if ($poiName !== '') {
            $args[] = '--poi-name=' . $poiName;
        }
        if ($adsUrl !== '') {
            $args[] = '--ads-url=' . $adsUrl;
        }
        if ($dataPeriod !== '') {
            $args[] = '--data-period=' . $dataPeriod;
        }
        if ($snapshotTime !== '') {
            $args[] = '--snapshot-time=' . $snapshotTime;
        }

        try {
            $runResult = $this->runProcess($args, $this->projectRoot, $timeoutSeconds);
        } finally {
            $this->releaseLock($lock);
        }

        if (!is_file($outputPath)) {
            $message = $this->buildProcessFailureMessage(
                'Meituan browser capture did not produce an output file',
                $runResult
            );
            return [
                'status' => 'failed',
                'message' => $message,
                'payload' => [
                    'error_summary' => $message,
                    'stdout' => $this->trimLog((string)($runResult['stdout'] ?? '')),
                    'stderr' => $this->trimLog((string)($runResult['stderr'] ?? '')),
                ],
            ];
        }

        $payload = json_decode((string)file_get_contents($outputPath), true);
        if (!is_array($payload)) {
            return [
                'status' => 'failed',
                'message' => 'Meituan browser capture output is not valid JSON.',
                'payload' => ['output' => $outputPath],
            ];
        }

        $payload['output'] = $outputPath;
        $payload['data_source_capture'] = [
            'platform' => 'meituan',
            'acquisition_method' => 'browser_profile',
            'requested_store_id_present' => $storeId !== '',
            'requested_poi_id_present' => $poiId !== '',
            'capture_sections' => $sections,
            'requested_capture_sections' => $requestedSections,
            'data_date' => $dataDate,
            'data_period' => $dataPeriod,
            'snapshot_time' => $snapshotTime,
            'captured_by' => 'platform_data_source_sync',
        ];
        if ($dataPeriod !== '' && empty($payload['data_period'])) {
            $payload['data_period'] = $dataPeriod;
        }
        if ($snapshotTime !== '' && empty($payload['snapshot_time'])) {
            $payload['snapshot_time'] = $snapshotTime;
        }

        $authStatus = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
        if ($authStatus !== [] && empty($authStatus['ok'])) {
            if ($sections === 'ads') {
                return [
                    'status' => 'waiting_config',
                    'status_code' => 'profile_session_unverified',
                    'error_code' => 'profile_session_unverified',
                    'message' => 'profile_session_unverified',
                    'payload' => array_merge($this->compactFailurePayload($payload, $runResult), [
                        'module_status' => [
                            'module' => 'ads',
                            'status' => 'blocked',
                            'reason' => 'profile_session_unverified',
                            'external_action_required' => true,
                            'entry_url' => $adsUrl,
                        ],
                    ]),
                ];
            }
            return [
                'status' => 'waiting_config',
                'message' => (string)($authStatus['message'] ?? 'Meituan login session is not ready; open the Profile and complete login.'),
                'payload' => $this->compactFailurePayload($payload, $runResult),
            ];
        }

        $identityCheck = BrowserProfileCaptureRequestService::assessMeituanPlatformIdentity(
            $payload,
            [$storeId, $poiId]
        );
        $gate = is_array($payload['capture_gate'] ?? null) ? $payload['capture_gate'] : [];
        if ($gate !== [] && ($gate['status'] ?? 'fail') !== 'pass') {
            if ($sections === 'ads') {
                $reason = $this->adsServiceNotOpened($payload)
                    ? 'ads_service_not_opened'
                    : 'ads_collection_failed';
                $moduleStatus = $reason === 'ads_service_not_opened' ? 'not_applicable' : 'blocked';
                return [
                    'status' => $moduleStatus === 'not_applicable' ? 'not_applicable' : 'failed',
                    'status_code' => $reason,
                    'error_code' => $reason,
                    'message' => $reason,
                    'payload' => array_merge($this->compactFailurePayload($payload, $runResult), [
                        'module_status' => [
                            'module' => 'ads',
                            'status' => $moduleStatus,
                            'reason' => $reason,
                            'external_action_required' => $reason === 'ads_service_not_opened',
                            'entry_url' => $adsUrl,
                        ],
                    ]),
                ];
            }
            if (($identityCheck['ok'] ?? false) !== true) {
                return $this->platformIdentityFailureResult($payload, $runResult, $identityCheck);
            }
            $failedIds = implode(',', array_map('strval', $gate['failed_check_ids'] ?? []));
            return [
                'status' => 'failed',
                'message' => 'Meituan browser capture gate failed' . ($failedIds !== '' ? ': ' . $failedIds : '.'),
                'payload' => $this->compactFailurePayload($payload, $runResult),
            ];
        }
        if (($identityCheck['ok'] ?? false) !== true) {
            return $this->platformIdentityFailureResult($payload, $runResult, $identityCheck);
        }

        $validatedPlatformIdentifier = trim((string)(
            $payload['platform_identity_validation']['validated_identifier']
            ?? ''
        ));
        $rows = $this->buildRows($payload, $source, $systemHotelId, $dataDate, $validatedPlatformIdentifier);
        $supplementalFilter = BrowserProfileCaptureRequestService::filterUnverifiedMeituanSupplementalRows($rows, $dataDate);
        $rows = $supplementalFilter['rows'];
        if ($supplementalFilter['dropped_count'] > 0) {
            $payload['collection_warnings'][] = [
                'status_code' => 'unverified_supplemental_rows_dropped',
                'data_types' => $supplementalFilter['dropped_types'],
                'dropped_count' => $supplementalFilter['dropped_count'],
            ];
        }
        if (empty($rows)) {
            if (BrowserProfileCaptureRequestService::isConfirmedEmptyMeituanCaptureGate($gate)) {
                $payload['rows'] = [];
                $payload['sync_summary'] = [
                    'row_count' => 0,
                    'confirmed_empty' => true,
                ];
                return [
                    'status' => 'success',
                    'message' => 'Meituan returned an authoritative empty result; no rows were written.',
                    'payload' => $payload,
                ];
            }
            if ($sections === 'ads') {
                $reason = $this->adsServiceNotOpened($payload)
                    ? 'ads_service_not_opened'
                    : 'ads_collection_failed';
                $moduleStatus = $reason === 'ads_service_not_opened' ? 'not_applicable' : 'blocked';
                return [
                    'status' => $moduleStatus === 'not_applicable' ? 'not_applicable' : 'failed',
                    'status_code' => $reason,
                    'error_code' => $reason,
                    'message' => $reason,
                    'payload' => array_merge($this->compactFailurePayload($payload, $runResult), [
                        'module_status' => [
                            'module' => 'ads',
                            'status' => $moduleStatus,
                            'reason' => $reason,
                            'external_action_required' => $reason === 'ads_service_not_opened',
                            'entry_url' => $adsUrl,
                        ],
                    ]),
                ];
            }
            return [
                'status' => 'failed',
                'message' => 'Meituan browser capture completed but no business rows were parsed.',
                'payload' => $this->compactFailurePayload($payload, $runResult),
            ];
        }
        $mismatchedDates = BrowserProfileCaptureRequestService::mismatchedMeituanTargetDates($rows, $dataDate);
        if ($mismatchedDates !== []) {
            return [
                'status' => 'failed',
                'status_code' => 'meituan_target_date_mismatch',
                'error_code' => 'meituan_target_date_mismatch',
                'message' => 'Meituan browser capture returned rows outside requested target date ' . $dataDate . ': ' . implode(',', $mismatchedDates),
                'payload' => $this->compactFailurePayload($payload, $runResult),
            ];
        }
        $unverifiedDates = BrowserProfileCaptureRequestService::unverifiedMeituanTargetDateRows($rows, $dataDate);
        if ($unverifiedDates !== []) {
            return [
                'status' => 'failed',
                'status_code' => 'meituan_target_date_unverified',
                'error_code' => 'meituan_target_date_unverified',
                'message' => 'Meituan browser capture rows do not contain authoritative target-date evidence.',
                'payload' => $this->compactFailurePayload($payload, $runResult),
            ];
        }

        $payload['rows'] = $rows;
        $payload['sync_summary'] = [
            'row_count' => count($rows),
            'dropped_unverified_supplemental_count' => (int)$supplementalFilter['dropped_count'],
            'business_count' => $this->countPayloadSections($payload, ['businessData', 'business_data', 'business', 'overview']),
            'peer_rank_count' => $this->countPayloadSections($payload, ['peerRank', 'peer_rank', 'rankings', 'competitorRank']),
            'flow_analysis_count' => $this->countPayloadSections($payload, ['flowAnalysis', 'flow_analysis', 'trafficAnalysis', 'traffic_analysis']),
            'order_flow_count' => $this->countPayloadSections($payload, ['order_flow', 'orderFlow', 'orderFlowRows', 'order_flow_rows']),
            'search_keyword_count' => $this->countPayloadSections($payload, ['searchKeywords', 'search_keywords', 'keywords']),
            'traffic_forecast_count' => $this->countPayloadSections($payload, ['trafficForecast', 'traffic_forecast', 'flowForecast', 'flow_forecast']),
            'room_type_count' => $this->countPayloadSections($payload, ['roomTypes', 'room_types', 'products']),
            'traffic_count' => count(is_array($payload['traffic'] ?? null) ? $payload['traffic'] : []),
            'order_count' => count(is_array($payload['orders'] ?? null) ? $payload['orders'] : []),
            'ads_count' => count(is_array($payload['ads'] ?? null) ? $payload['ads'] : []),
            'review_count' => count(is_array($payload['reviews'] ?? null) ? $payload['reviews'] : []),
        ];

        return [
            'status' => 'success',
            'message' => 'Meituan browser Profile capture completed.',
            'payload' => $payload,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(array $payload, array $source, int $systemHotelId, string $dataDate, string $platformHotelId): array
    {
        $rows = [];
        $forcedDataType = $this->forcedResourceDataType($source, $payload);
        $sectionGroups = [
            ['data_type' => 'business', 'keys' => ['businessData', 'business_data', 'business', 'overview']],
            ['data_type' => 'peer_rank', 'keys' => ['peerRank', 'peer_rank', 'competitorRank', 'rankings', 'ranking']],
            ['data_type' => 'traffic_analysis', 'keys' => ['flowAnalysis', 'flow_analysis', 'trafficAnalysis', 'traffic_analysis']],
            ['data_type' => 'traffic', 'keys' => ['flowData', 'flow_data', 'traffic', 'flow']],
            ['data_type' => 'order_flow', 'keys' => ['order_flow', 'orderFlow', 'orderFlowRows', 'order_flow_rows']],
            ['data_type' => 'search_keyword', 'keys' => ['searchKeywords', 'search_keywords', 'searchKeyWords', 'keywords']],
            ['data_type' => 'traffic_forecast', 'keys' => ['trafficForecast', 'traffic_forecast', 'flowForecast', 'flow_forecast']],
            ['data_type' => 'room_type', 'keys' => ['roomTypes', 'room_types', 'products', 'roomType']],
            ['data_type' => 'order', 'keys' => ['orders']],
            ['data_type' => 'advertising', 'keys' => ['ads']],
            ['data_type' => 'review', 'keys' => ['reviews', 'review', 'comments', 'commentList', 'commentsInfo']],
        ];

        foreach ($sectionGroups as $sectionGroup) {
            $dataType = (string)$sectionGroup['data_type'];
            $sectionRows = $this->payloadRowsForKeys($payload, $sectionGroup['keys']);
            if ($forcedDataType !== '' && in_array($dataType, ['business', 'peer_rank', 'traffic', 'order_flow', 'search_keyword', 'room_type'], true)) {
                $dataType = $forcedDataType;
            }
            foreach ($sectionRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $explicitDataDate = $this->normalizeDate((string)($row['data_date'] ?? $row['dataDate'] ?? $row['date'] ?? ''));
                $row['source'] = 'meituan';
                $row['platform'] = $row['platform'] ?? 'meituan';
                $row['system_hotel_id'] = $row['system_hotel_id'] ?? $systemHotelId;
                $row['poi_id'] = $this->firstRowString(
                    $row,
                    ['poi_id', 'poiId', 'store_id', 'storeId', 'shop_id', 'shopId'],
                    $platformHotelId
                );
                $row['hotel_id'] = $this->firstRowString($row, ['hotel_id', 'hotelId'], $row['poi_id']);
                $row['hotel_name'] = $row['hotel_name'] ?? $row['hotelName'] ?? $row['poi_name'] ?? $row['poiName'] ?? $source['name'] ?? '';
                $row['data_date'] = $explicitDataDate !== '' ? $explicitDataDate : $dataDate;
                if (trim((string)($row['date_source'] ?? $row['dateSource'] ?? '')) === '') {
                    $row['date_source'] = $explicitDataDate !== '' ? 'row' : 'capture_context.default_data_date';
                }
                $row['data_type'] = $row['data_type'] ?? $dataType;
                $row['acquisition_method'] = 'browser_profile';
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function firstRowString(array $row, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = trim((string)$row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return $default;
    }

    /**
     * @param array<int, string> $keys
     * @return array<int, mixed>
     */
    private function payloadRowsForKeys(array $payload, array $keys): array
    {
        $rows = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload) || !is_array($payload[$key])) {
                continue;
            }
            $sectionRows = $payload[$key];
            if ($sectionRows !== [] && array_keys($sectionRows) !== range(0, count($sectionRows) - 1)) {
                $sectionRows = [$sectionRows];
            }
            $rows = array_merge($rows, $sectionRows);
        }
        return $rows;
    }

    /**
     * @param array<int, string> $keys
     */
    private function countPayloadSections(array $payload, array $keys): int
    {
        return count($this->payloadRowsForKeys($payload, $keys));
    }

    private function forcedResourceDataType(array $source, array $payload): string
    {
        $sourceType = $this->normalizeResourceDataType((string)($source['data_type'] ?? ''));
        if (in_array($sourceType, ['peer_rank', 'order_flow', 'search_keyword', 'room_type'], true)) {
            return $sourceType;
        }

        $capture = is_array($payload['data_source_capture'] ?? null) ? $payload['data_source_capture'] : [];
        $sectionText = strtolower((string)($capture['requested_capture_sections'] ?? $capture['capture_sections'] ?? ''));
        if (preg_match('/[,\s]+/', $sectionText)) {
            return '';
        }
        $sectionType = $this->normalizeResourceDataType($sectionText);
        if (in_array($sectionType, ['business', 'peer_rank', 'traffic', 'order_flow', 'search_keyword', 'room_type'], true)) {
            return $sectionType;
        }

        return '';
    }

    private function normalizeResourceDataType(string $value): string
    {
        $value = trim($value);
        $value = (string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value);
        $value = strtolower((string)preg_replace('/[\s\-.]+/', '_', $value));
        $value = (string)preg_replace('/_+/', '_', $value);
        $value = trim($value, '_');

        if (in_array($value, ['business_data', 'businessdata', 'trade_data', 'tradedata', 'overview', 'summary'], true)) {
            return 'business';
        }
        if (in_array($value, ['peer_rank', 'peerrank', 'competitor_rank', 'competitorrank', 'competition', 'rank', 'ranking', 'rankings'], true)) {
            return 'peer_rank';
        }
        if (in_array($value, ['flow_data', 'flowdata', 'flow', 'traffic', 'traffic_data', 'trafficdata'], true)) {
            return 'traffic';
        }
        if (in_array($value, ['traffic_analysis', 'trafficanalysis', 'flow_analysis', 'flowanalysis'], true)) {
            return 'traffic_analysis';
        }
        if (in_array($value, ['order_flow', 'orderflow', 'order_loss', 'orderloss', 'loss_order', 'lossorder'], true)) {
            return 'order_flow';
        }
        if (in_array($value, ['traffic_forecast', 'trafficforecast', 'flow_forecast', 'flowforecast', 'forecast'], true)) {
            return 'traffic_forecast';
        }
        if (in_array($value, ['search_keyword', 'search_keywords', 'searchkeyword', 'searchkeywords', 'search_key_word', 'search_key_words', 'keyword', 'keywords'], true)) {
            return 'search_keyword';
        }
        if (in_array($value, ['room_type', 'room_types', 'roomtype', 'roomtypes', 'product', 'products'], true)) {
            return 'room_type';
        }
        if (in_array($value, ['review', 'reviews', 'comment', 'comments', 'review_data', 'reviewdata'], true)) {
            return 'review';
        }
        if (in_array($value, ['ad', 'ads', 'advertising', 'advertisement', 'campaign', 'campaigns'], true)) {
            return 'advertising';
        }

        return $value;
    }

    private function compactFailurePayload(array $payload, array $runResult): array
    {
        return [
            'auth_status' => $payload['auth_status'] ?? null,
            'capture_gate' => $payload['capture_gate'] ?? null,
            'platform_identity_validation' => $payload['platform_identity_validation'] ?? null,
            'pages' => $payload['pages'] ?? [],
            'responses' => array_slice(is_array($payload['responses'] ?? null) ? $payload['responses'] : [], 0, 20),
            'output' => $payload['output'] ?? '',
            'stdout' => $this->trimLog((string)($runResult['stdout'] ?? '')),
            'stderr' => $this->trimLog((string)($runResult['stderr'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $identityCheck */
    private function platformIdentityFailureResult(array $payload, array $runResult, array $identityCheck): array
    {
        $statusCode = (string)($identityCheck['status_code'] ?? 'meituan_platform_identity_unverified');
        return [
            'status' => 'failed',
            'status_code' => $statusCode,
            'error_code' => $statusCode,
            'message' => $statusCode,
            'payload' => $this->compactFailurePayload($payload, $runResult),
        ];
    }

    private function adsServiceNotOpened(array $payload): bool
    {
        foreach (is_array($payload['pages'] ?? null) ? $payload['pages'] : [] as $page) {
            if (!is_array($page)) {
                continue;
            }
            $url = strtolower(trim((string)($page['url'] ?? '')));
            if ($url !== '' && (str_contains($url, '/online-sign') || str_contains($url, 'promopoiid/-1'))) {
                return true;
            }
        }
        return false;
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
            return ['success' => false, 'message' => 'Cannot start Meituan browser capture process.', 'stdout' => '', 'stderr' => ''];
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
                proc_terminate($process);
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
            return ['success' => false, 'message' => 'Meituan browser capture timed out.', 'stdout' => $stdout, 'stderr' => $stderr];
        }
        if ($exitCode !== 0 && $exitCode !== -1) {
            return ['success' => false, 'message' => 'Meituan browser capture exited with code ' . $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        return ['success' => true, 'message' => 'ok', 'stdout' => $stdout, 'stderr' => $stderr];
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
        return BrowserProfileCaptureRequestService::normalizeMeituanProfileSections($sections);
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
