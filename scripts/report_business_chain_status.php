<?php
declare(strict_types=1);

use app\service\BusinessClosureOverviewService;
use app\service\OperationManagementService;
use app\service\OtaStandardEtlService;
use app\service\RevenueAiOverviewService;
use app\service\RevenueFactLayerService;
use think\App;
use think\facade\Db;

if (!class_exists(\Composer\Autoload\ClassLoader::class, false)) {
    require __DIR__ . '/../vendor/autoload.php';
}

date_default_timezone_set('Asia/Shanghai');

/**
 * @return array<string, mixed>
 */
function parse_business_chain_args(array $argv): array
{
    $options = [
        'date' => date('Y-m-d'),
        'system_hotel_id' => null,
        'expected_hotel_name' => '',
        'limit' => 5000,
        'platforms' => ['ctrip', 'meituan'],
        'skip_p0' => false,
        'skip_platforms' => [],
        'format' => 'json',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--skip-p0' || $arg === '--allow-skip-p0') {
            $options['skip_p0'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $value = trim($value);
        if ($key === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $options['date'] = $value;
        } elseif ($key === 'system-hotel-id' || $key === 'system_hotel_id') {
            $options['system_hotel_id'] = $value !== '' ? (int)$value : null;
        } elseif ($key === 'expected-hotel-name' || $key === 'expected_hotel_name') {
            $options['expected_hotel_name'] = $value;
        } elseif ($key === 'limit') {
            $options['limit'] = max(1, min(5000, (int)$value));
        } elseif ($key === 'platform' || $key === 'platforms') {
            $options['platforms'] = business_chain_parse_platforms($value);
        } elseif ($key === 'skip-platform' || $key === 'skip_platform') {
            $options['skip_platforms'] = array_values(array_filter(array_map(
                static fn (string $item): string => strtolower(trim($item)),
                explode(',', $value)
            )));
        } elseif ($key === 'format' && in_array($value, ['json', 'markdown'], true)) {
            $options['format'] = $value;
        }
    }

    return $options;
}

/**
 * @return array<int, mixed>
 */
function business_chain_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @return array<int, string>
 */
function business_chain_supported_platforms(): array
{
    return ['ctrip', 'meituan'];
}

/**
 * @return array<int, string>
 */
function business_chain_parse_platforms(string $value): array
{
    $platforms = array_values(array_unique(array_filter(array_map(
        static fn(string $platform): string => strtolower(trim($platform)),
        explode(',', $value)
    ))));
    if ($platforms === []) {
        return business_chain_supported_platforms();
    }
    foreach ($platforms as $platform) {
        if (!in_array($platform, business_chain_supported_platforms(), true)) {
            throw new InvalidArgumentException('Unsupported --platform, expected ctrip and/or meituan.');
        }
    }
    return $platforms;
}

/**
 * @return array<string, mixed>
 */
function business_chain_failure_payload(Throwable $error): array
{
    $message = $error->getMessage();
    $databaseUnavailable = (string)$error->getCode() === '2002'
        || str_contains($message, 'SQLSTATE[HY000] [2002]')
        || str_contains(strtolower($message), 'connection refused')
        || str_contains($message, '积极拒绝');

    return [
        'status' => $databaseUnavailable ? 'blocked' : 'failed',
        'error_code' => $databaseUnavailable ? 'database_unavailable' : 'report_generation_failed',
        'message' => $databaseUnavailable
            ? 'Business-chain report requires an available project database.'
            : $message,
        'claim_allowed' => false,
        'runtime_data_ready' => false,
        'business_loop_ready' => false,
        'database_ready' => $databaseUnavailable ? false : null,
        'error_file' => str_replace('\\', '/', $error->getFile()),
        'error_line' => $error->getLine(),
        'source_policy' => 'read_only_report_no_ota_collection',
    ];
}

/**
 * @param array<int, string> $platforms
 */
function business_chain_platform_scope_arg(array $platforms): string
{
    $normalized = array_values(array_unique(array_map('strval', $platforms)));
    sort($normalized);
    $default = business_chain_supported_platforms();
    sort($default);
    return $normalized === $default ? '' : implode(',', $platforms);
}

function business_chain_table_exists(string $table): bool
{
    try {
        return Db::query("SHOW TABLES LIKE '{$table}'") !== [];
    } catch (Throwable) {
        return false;
    }
}

/**
 * @return array<string, mixed>
 */
function business_chain_system_hotel_identity(?int $systemHotelId, string $expectedHotelName = ''): array
{
    $expectedHotelName = trim($expectedHotelName);
    if ($systemHotelId === null || $systemHotelId <= 0) {
        return [
            'status' => 'not_scoped',
            'system_hotel_id' => null,
            'system_hotel_name' => null,
            'expected_hotel_name' => $expectedHotelName !== '' ? $expectedHotelName : null,
            'expected_name_status' => $expectedHotelName !== '' ? 'system_hotel_id_missing' : 'not_requested',
            'same_name_system_hotel_ids' => [],
        ];
    }
    if (!business_chain_table_exists('hotels')) {
        return [
            'status' => 'table_missing',
            'system_hotel_id' => $systemHotelId,
            'system_hotel_name' => null,
            'expected_hotel_name' => $expectedHotelName !== '' ? $expectedHotelName : null,
            'expected_name_status' => 'not_verified',
            'same_name_system_hotel_ids' => [],
        ];
    }
    try {
        $hotel = Db::name('hotels')
            ->field('id,name')
            ->where('id', $systemHotelId)
            ->find();
    } catch (Throwable) {
        $hotel = null;
    }
    $sameNameSystemHotelIds = [];
    if ($expectedHotelName !== '') {
        try {
            $sameNameSystemHotelIds = array_values(array_map(
                'intval',
                Db::name('hotels')
                    ->where('name', $expectedHotelName)
                    ->order('id', 'asc')
                    ->limit(20)
                    ->column('id')
            ));
        } catch (Throwable) {
            $sameNameSystemHotelIds = [];
        }
    }
    if (is_array($hotel)) {
        $systemHotelName = trim((string)($hotel['name'] ?? ''));
        $expectedNameMatches = $expectedHotelName === '' || $systemHotelName === $expectedHotelName;
        return [
            'status' => $expectedNameMatches ? 'ready' : 'mismatch',
            'system_hotel_id' => (int)($hotel['id'] ?? $systemHotelId),
            'system_hotel_name' => $systemHotelName !== '' ? $systemHotelName : null,
            'expected_hotel_name' => $expectedHotelName !== '' ? $expectedHotelName : null,
            'expected_name_status' => $expectedHotelName === ''
                ? 'not_requested'
                : ($expectedNameMatches ? 'matched' : 'mismatch'),
            'same_name_system_hotel_ids' => $sameNameSystemHotelIds,
        ];
    }

    return [
        'status' => 'missing',
        'system_hotel_id' => $systemHotelId,
        'system_hotel_name' => null,
        'expected_hotel_name' => $expectedHotelName !== '' ? $expectedHotelName : null,
        'expected_name_status' => $sameNameSystemHotelIds !== []
            ? 'name_exists_on_other_system_hotel'
            : ($expectedHotelName !== '' ? 'name_not_found' : 'not_requested'),
        'same_name_system_hotel_ids' => $sameNameSystemHotelIds,
    ];
}

function business_chain_latest_date(string $source, ?int $systemHotelId): string
{
    if (!business_chain_table_exists('online_daily_data')) {
        return '';
    }
    $query = Db::name('online_daily_data')
        ->where('source', $source)
        ->whereNotNull('data_date')
        ->where('data_date', '<>', '');
    if ($systemHotelId !== null) {
        $query->where('system_hotel_id', $systemHotelId);
    }
    $rows = $query->field('data_date')->order('data_date', 'desc')->limit(20)->select()->toArray();
    foreach ($rows as $row) {
        $date = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }
    }
    return '';
}

/**
 * @param array<string, array<string, mixed>> $datasets
 * @return array<string, mixed>
 */
function business_chain_merge_datasets(array $datasets): array
{
    $merged = [
        'status' => 'empty',
        'dim_hotel' => [],
        'dim_platform' => [],
        'fact_ota_daily' => [],
        'fact_ota_traffic' => [],
        'fact_ota_advertising' => [],
        'fact_ota_quality' => [],
        'fact_ota_search_keyword' => [],
        'fact_ota_peer_rank' => [],
        'fact_ota_traffic_analysis' => [],
        'fact_ota_traffic_forecast' => [],
        'fact_ota_comment' => [],
        'data_quality' => [
            'input_rows' => 0,
            'accepted_rows' => 0,
            'rejected_rows' => [],
        ],
    ];
    $hotelKeys = [];
    $platformKeys = [];
    foreach ($datasets as $dataset) {
        foreach (business_chain_list($dataset['dim_hotel'] ?? []) as $hotel) {
            if (!is_array($hotel)) {
                continue;
            }
            $key = (string)($hotel['hotel_key'] ?? json_encode($hotel));
            if ($key !== '' && !isset($hotelKeys[$key])) {
                $hotelKeys[$key] = true;
                $merged['dim_hotel'][] = $hotel;
            }
        }
        foreach (business_chain_list($dataset['dim_platform'] ?? []) as $platform) {
            if (!is_array($platform)) {
                continue;
            }
            $key = (string)($platform['platform_key'] ?? '');
            if ($key !== '' && !isset($platformKeys[$key])) {
                $platformKeys[$key] = true;
                $merged['dim_platform'][] = $platform;
            }
        }
        foreach ([
            'fact_ota_daily',
            'fact_ota_traffic',
            'fact_ota_advertising',
            'fact_ota_quality',
            'fact_ota_search_keyword',
            'fact_ota_peer_rank',
            'fact_ota_traffic_analysis',
            'fact_ota_traffic_forecast',
            'fact_ota_comment',
        ] as $factKey) {
            $merged[$factKey] = array_merge($merged[$factKey], business_chain_list($dataset[$factKey] ?? []));
        }
        $quality = is_array($dataset['data_quality'] ?? null) ? $dataset['data_quality'] : [];
        $merged['data_quality']['input_rows'] += (int)($quality['input_rows'] ?? 0);
        $merged['data_quality']['accepted_rows'] += (int)($quality['accepted_rows'] ?? 0);
        $merged['data_quality']['rejected_rows'] = array_merge(
            $merged['data_quality']['rejected_rows'],
            business_chain_list($quality['rejected_rows'] ?? [])
        );
    }
    $accepted = 0;
    foreach ([
        'fact_ota_daily',
        'fact_ota_traffic',
        'fact_ota_advertising',
        'fact_ota_quality',
        'fact_ota_search_keyword',
        'fact_ota_peer_rank',
        'fact_ota_traffic_analysis',
        'fact_ota_traffic_forecast',
        'fact_ota_comment',
    ] as $factKey) {
        $accepted += count($merged[$factKey]);
    }
    $merged['status'] = $accepted > 0 ? 'ready' : 'empty';
    return $merged;
}

/**
 * @return array<string, mixed>
 */
function business_chain_build_dataset_for(string $source, string $date, ?int $systemHotelId, int $limit): array
{
    $filters = [
        'source' => $source,
        'start_date' => $date,
        'end_date' => $date,
        'limit' => $limit,
    ];
    if ($systemHotelId !== null) {
        $filters['system_hotel_id'] = $systemHotelId;
    }
    return (new OtaStandardEtlService())->buildDataset($filters);
}

/**
 * @param array<string, mixed> $dataset
 * @param array<int, string> $platforms
 * @return array<string, mixed>
 */
function business_chain_filter_dataset_platforms(array $dataset, array $platforms): array
{
    $platformLookup = array_fill_keys(array_map('strtolower', $platforms), true);
    $dataset['dim_platform'] = array_values(array_filter(
        business_chain_list($dataset['dim_platform'] ?? []),
        static function (mixed $platform) use ($platformLookup): bool {
            return is_array($platform) && isset($platformLookup[strtolower((string)($platform['platform_key'] ?? ''))]);
        }
    ));
    foreach ([
        'fact_ota_daily',
        'fact_ota_traffic',
        'fact_ota_advertising',
        'fact_ota_quality',
        'fact_ota_search_keyword',
        'fact_ota_peer_rank',
        'fact_ota_traffic_analysis',
        'fact_ota_traffic_forecast',
        'fact_ota_comment',
    ] as $factKey) {
        if (!is_array($dataset[$factKey] ?? null)) {
            continue;
        }
        $dataset[$factKey] = array_values(array_filter(
            business_chain_list($dataset[$factKey]),
            static function (mixed $row) use ($platformLookup): bool {
                if (!is_array($row)) {
                    return false;
                }
                $source = strtolower((string)($row['source'] ?? $row['platform'] ?? $row['channel'] ?? ''));
                return $source === '' || isset($platformLookup[$source]);
            }
        ));
    }
    return $dataset;
}

/**
 * @return array<string, mixed>
 */
function business_chain_gate(
    string $targetDate,
    ?int $systemHotelId,
    bool $skipP0,
    array $operatorSkippedPlatforms = [],
    array $platforms = ['ctrip', 'meituan'],
    bool $p0Ready = false
): array
{
    $blockingMissingInputs = $p0Ready
        ? []
        : ($skipP0 || $operatorSkippedPlatforms !== []
        ? ['p0_skipped_by_operator', 'p0_field_loop_verifier_ready', 'target_date_ota_rows', 'target_date_traffic_rows']
        : ['p0_field_loop_verifier_ready']);
    $platformArg = business_chain_platform_scope_arg($platforms);

    return [
        'status' => $p0Ready ? 'ready' : 'blocked_by_p0_ota_gate',
        'current_upstream_status' => $p0Ready ? 'ready' : ($skipP0 ? 'skip_p0_reference_only' : 'incomplete'),
        'required_upstream_status' => 'ready',
        'required_gate_command' => 'npm.cmd run verify:p0-ota-field-loop -- --date='
            . $targetDate
            . ($platformArg !== '' ? ' --platform=' . $platformArg : '')
            . ($systemHotelId !== null ? ' --system-hotel-id=' . $systemHotelId : ''),
        'scope_policy' => $platformArg !== ''
            ? 'platform_scoped_ota_channel_gate_before_downstream_claims'
            : 'ota_channel_gate_before_downstream_claims',
        'scope_platforms' => array_values($platforms),
        'blocking_missing_inputs' => $blockingMissingInputs,
        'operator_skip_platforms' => $operatorSkippedPlatforms,
    ];
}

/**
 * @param array<string, mixed> $dataset
 * @return array<string, int>
 */
function business_chain_fact_counts(array $dataset): array
{
    return [
        'daily' => count(business_chain_list($dataset['fact_ota_daily'] ?? [])),
        'traffic' => count(business_chain_list($dataset['fact_ota_traffic'] ?? [])),
        'advertising' => count(business_chain_list($dataset['fact_ota_advertising'] ?? [])),
        'quality' => count(business_chain_list($dataset['fact_ota_quality'] ?? [])),
        'accepted' => (int)($dataset['data_quality']['accepted_rows'] ?? 0),
    ];
}

function business_chain_source_evidence_status(array $dataset): string
{
    $counts = business_chain_fact_counts($dataset);
    if ($counts['accepted'] <= 0) {
        return 'empty';
    }
    return $counts['traffic'] > 0 ? 'ready' : 'reference_only_non_traffic';
}

/**
 * @param array<string, mixed> $revenue
 * @param array<string, mixed> $closure
 * @return array<int, array<string, mixed>>
 */
function business_chain_stage_rows(array $referenceDataset, array $revenue, array $closure, bool $skipP0): array
{
    $counts = business_chain_fact_counts($referenceDataset);
    $p0Blocked = (string)($closure['summary']['status'] ?? '') === 'blocked_by_p0_ota_gate';
    $otaClaimAllowed = !$skipP0 && !$p0Blocked && $counts['accepted'] > 0;
    $revenueStatus = (string)(
        $revenue['revenue_analysis_status']
        ?? $revenue['data_status']
        ?? 'unknown'
    );
    $revenueClaimAllowed = $otaClaimAllowed
        && in_array($revenueStatus, ['ok', 'ready'], true);
    $actionCount = count(business_chain_list($revenue['actions'] ?? []));
    $pricingReadiness = is_array($revenue['pricing_readiness'] ?? null)
        ? $revenue['pricing_readiness']
        : [];
    $aiReviewInputsReady = ($pricingReadiness['can_generate_recommendation'] ?? false) === true;
    $aiClaimAllowed = $revenueClaimAllowed
        && $actionCount > 0
        && $aiReviewInputsReady;

    return [
        [
            'key' => 'ota_data',
            'label' => 'OTA data',
            'status' => $skipP0
                ? 'reference_only'
                : ($p0Blocked ? 'blocked_by_p0_ota_gate' : ($counts['accepted'] > 0 ? 'ready' : 'data_gap')),
            'claim_allowed' => $otaClaimAllowed,
            'evidence' => $counts,
        ],
        [
            'key' => 'revenue_analysis',
            'label' => 'Revenue analysis',
            'status' => $skipP0 ? 'reference_only' : ($p0Blocked ? 'blocked_by_p0_ota_gate' : $revenueStatus),
            'claim_allowed' => $revenueClaimAllowed,
            'evidence' => [
                'data_status' => $revenue['data_status'] ?? '',
                'revenue_analysis_status' => $revenueStatus,
                'source_channels' => $revenue['source_channels'] ?? [],
                'pricing_status' => $revenue['pricing_readiness']['status'] ?? '',
                'three_source_fact_layer_status' =>
                    $revenue['three_source_fact_layer']['status']
                    ?? '',
            ],
        ],
        [
            'key' => 'ai_decision_advice',
            'label' => 'AI decision advice',
            'status' => $p0Blocked
                ? 'blocked_by_p0_ota_gate'
                : (
                    !$revenueClaimAllowed
                        ? 'blocked_by_revenue_data'
                        : (
                            !$aiReviewInputsReady
                                ? 'blocked_by_review_inputs'
                                : ($actionCount > 0 ? 'ready_for_review' : 'no_actionable_advice')
                        )
                ),
            'claim_allowed' => $aiClaimAllowed,
            'evidence' => [
                'action_count' => $actionCount,
                'agent_activity_status' => $revenue['agent_activity']['status'] ?? '',
            ],
        ],
        [
            'key' => 'operation_closure',
            'label' => 'Operation closure',
            'status' => (string)($closure['summary']['status'] ?? 'unknown'),
            'claim_allowed' => $aiClaimAllowed && (string)($closure['summary']['status'] ?? '') === 'closed',
            'evidence' => [
                'statistics_status' => (string)($closure['summary']['operation_statistics_status'] ?? 'unknown'),
                'statistics_loaded' => ($closure['summary']['operation_statistics_loaded'] ?? false) === true,
                'execution_total_loaded' => ($closure['summary']['operation_execution_total_loaded'] ?? false) === true,
                'roi_loaded' => ($closure['summary']['operation_roi_loaded'] ?? false) === true,
                'operation_execution_total' => ($closure['summary']['operation_execution_total_loaded'] ?? false) === true
                    ? (int)($closure['summary']['operation_execution_total'] ?? 0)
                    : null,
                'operation_roi_ready' => ($closure['summary']['operation_roi_loaded'] ?? false) === true
                    ? (int)($closure['summary']['operation_roi_ready'] ?? 0)
                    : null,
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function business_chain_p0_execution_plan(
    string $targetDate,
    ?int $systemHotelId,
    array $operatorSkippedPlatforms = [],
    array $platforms = ['ctrip', 'meituan']
): array
{
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php';
    if (!is_file($script)) {
        return [
            'status' => 'not_loaded',
            'source_policy' => 'p0_verifier_script_missing',
            'error' => 'scripts/verify_p0_ota_field_loop_closure.php not found',
        ];
    }

    $command = [PHP_BINARY, $script, '--date=' . $targetDate, '--format=json'];
    $platformArg = business_chain_platform_scope_arg($platforms);
    if ($platformArg !== '') {
        $command[] = '--platform=' . $platformArg;
    }
    if ($systemHotelId !== null) {
        $command[] = '--system-hotel-id=' . $systemHotelId;
    }
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        return [
            'status' => 'not_loaded',
            'source_policy' => 'p0_verifier_proc_open_failed',
            'error' => 'Unable to start P0 verifier process.',
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $payload = business_chain_extract_json($stdout !== '' ? $stdout : $stderr);
    if ($payload === []) {
        return [
            'status' => 'not_loaded',
            'source_policy' => 'p0_verifier_output_not_json',
            'verifier_exit_code' => $exitCode,
            'error' => 'Unable to parse P0 verifier output.',
        ];
    }

    return business_chain_compact_p0_execution_plan($payload, $targetDate, $systemHotelId, $exitCode, $operatorSkippedPlatforms, $platforms);
}

/**
 * @return array<string, mixed>
 */
function business_chain_extract_json(string $text): array
{
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return [];
    }
    $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $platformPayload
 * @param array<string, mixed> $gate
 */
function business_chain_p0_platform_ready(array $platformPayload, array $gate): bool
{
    $gateStatus = strtolower(trim((string)($gate['status'] ?? '')));
    if ($gateStatus !== '') {
        return $gateStatus === 'ready';
    }

    $legacyStatus = strtolower(trim((string)($gate['action_status'] ?? $platformPayload['status'] ?? '')));
    if ($legacyStatus === 'ready') {
        return true;
    }
    $missingInputs = business_chain_list($gate['action_missing_inputs'] ?? []);
    return (int)($platformPayload['target_date_rows'] ?? 0) > 0
        && (int)($gate['traffic_rows'] ?? 0) > 0
        && $missingInputs === [];
}

/**
 * @return array<int, true>
 */
function business_chain_p0_hotel_id_lookup(mixed $value): array
{
    $lookup = [];
    foreach (business_chain_list($value) as $hotelId) {
        $normalizedHotelId = (int)$hotelId;
        if ($normalizedHotelId > 0) {
            $lookup[$normalizedHotelId] = true;
        }
    }
    return $lookup;
}

/**
 * @param array<string, mixed> $gate
 */
function business_chain_p0_hotel_ready(array $gate, ?int $systemHotelId, bool $platformReady): bool
{
    if ($platformReady) {
        return true;
    }
    if ($systemHotelId === null || $systemHotelId <= 0 || !array_key_exists('profile_scope_system_hotel_ids', $gate)) {
        return false;
    }

    $activeHotelLookup = business_chain_p0_hotel_id_lookup($gate['profile_scope_system_hotel_ids']);
    if (!isset($activeHotelLookup[$systemHotelId])) {
        return false;
    }

    foreach ([
        'profile_scope_missing_profile_source_hotel_ids',
        'profile_scope_missing_traffic_source_hotel_ids',
        'profile_scope_missing_target_date_traffic_hotel_ids',
    ] as $missingHotelKey) {
        if (isset(business_chain_p0_hotel_id_lookup($gate[$missingHotelKey] ?? [])[$systemHotelId])) {
            return false;
        }
    }

    $rowCounts = is_array($gate['system_hotel_row_counts'] ?? null)
        ? $gate['system_hotel_row_counts']
        : [];
    if ((int)($rowCounts[(string)$systemHotelId] ?? $rowCounts[$systemHotelId] ?? 0) <= 0) {
        return false;
    }

    foreach ([
        'traffic_field_fact_status',
        'p0_standard_fact_status',
        'required_metric_value_status',
        'platform_hotel_identifier_status',
    ] as $statusKey) {
        if (strtolower(trim((string)($gate[$statusKey] ?? ''))) !== 'ready') {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $plan
 */
function business_chain_p0_execution_plan_ready(array $plan): bool
{
    $status = strtolower(trim((string)($plan['status'] ?? '')));
    if (!in_array($status, ['passed', 'incomplete'], true)) {
        return false;
    }

    $scope = is_array($plan['scope'] ?? null) ? $plan['scope'] : [];
    $selectedHotelId = isset($scope['system_hotel_id']) && (int)$scope['system_hotel_id'] > 0
        ? (int)$scope['system_hotel_id']
        : null;
    $summariesByPlatform = [];
    foreach (business_chain_list($plan['platform_summaries'] ?? []) as $summary) {
        if (!is_array($summary)) {
            continue;
        }
        $platform = strtolower(trim((string)($summary['platform'] ?? '')));
        if ($platform !== '') {
            $summariesByPlatform[$platform] = $summary;
        }
    }

    $requestedPlatforms = array_values(array_unique(array_filter(array_map(
        static fn(mixed $platform): string => strtolower(trim((string)$platform)),
        business_chain_list($scope['platforms'] ?? [])
    ))));
    if ($requestedPlatforms === []) {
        $requestedPlatforms = array_keys($summariesByPlatform);
    }
    if ($requestedPlatforms === []) {
        return false;
    }

    foreach ($requestedPlatforms as $platform) {
        $summary = $summariesByPlatform[$platform] ?? null;
        if (!is_array($summary)
            || ($summary['operator_skip_active'] ?? false) === true
            || (int)($summary['target_date_rows'] ?? 0) <= 0
            || (int)($summary['traffic_rows'] ?? 0) <= 0
            || ($summary['readback_check_supported'] ?? false) !== true
            || strtolower(trim((string)($summary['readback_status'] ?? ''))) !== 'ready'
            || (int)($summary['readback_verified_rows'] ?? 0) <= 0
            || (int)($summary['readback_unverified_rows'] ?? 0) > 0) {
            return false;
        }

        $scopeReady = ($summary['platform_ready'] ?? false) === true;
        if ($selectedHotelId !== null) {
            foreach (business_chain_list($summary['next_steps'] ?? []) as $step) {
                if (!is_array($step) || (int)($step['system_hotel_id'] ?? 0) !== $selectedHotelId) {
                    continue;
                }
                $scopeReady = $scopeReady
                    || (($step['hotel_ready'] ?? false) === true
                        && ($step['operator_skip_active'] ?? false) !== true);
                break;
            }
        }
        if (!$scopeReady) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $payload
 * @param array<int, string> $operatorSkippedPlatforms
 * @return array<string, mixed>
 */
function business_chain_compact_p0_execution_plan(
    array $payload,
    string $targetDate,
    ?int $systemHotelId,
    int $exitCode,
    array $operatorSkippedPlatforms = [],
    array $platforms = ['ctrip', 'meituan']
): array
{
    $platformSummaries = [];
    $operatorSequence = [];
    $operatorSkippedLookup = array_fill_keys(array_map('strtolower', $operatorSkippedPlatforms), true);
    foreach (business_chain_list($payload['platforms'] ?? []) as $platformPayload) {
        if (!is_array($platformPayload)) {
            continue;
        }
        $platform = (string)($platformPayload['platform'] ?? '');
        $gate = is_array($platformPayload['p0_traffic_gate'] ?? null) ? $platformPayload['p0_traffic_gate'] : [];
        $platformReady = business_chain_p0_platform_ready($platformPayload, $gate);
        $operatorSkipActive = isset($operatorSkippedLookup[strtolower($platform)]);
        $activeHotelScopeDeclared = array_key_exists('profile_scope_system_hotel_ids', $gate);
        $activeHotelLookup = business_chain_p0_hotel_id_lookup($gate['profile_scope_system_hotel_ids'] ?? []);
        $steps = [];
        foreach (business_chain_list($gate['hotel_scoped_next_steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            $stepHotelId = isset($step['system_hotel_id']) ? (int)$step['system_hotel_id'] : null;
            if ($activeHotelScopeDeclared && ($stepHotelId === null || !isset($activeHotelLookup[$stepHotelId]))) {
                continue;
            }
            $trigger = is_array($step['profile_login_trigger'] ?? null) ? $step['profile_login_trigger'] : [];
            $afterLoginSync = is_array($trigger['after_login_sync'] ?? null) ? $trigger['after_login_sync'] : [];
            $manualLoginVerified = ($step['manual_login_state_verified'] ?? false) === true;
            $skipWithVerifiedLogin = $operatorSkipActive && $manualLoginVerified;
            $hotelReady = business_chain_p0_hotel_ready($gate, $stepHotelId, $platformReady);
            $compact = [
                'platform' => $platform,
                'system_hotel_id' => $stepHotelId,
                'data_source_id' => isset($step['data_source_id']) ? (int)$step['data_source_id'] : null,
                'data_source_status' => (string)($step['data_source_status'] ?? ''),
                'last_sync_status' => (string)($step['last_sync_status'] ?? ''),
                'manual_login_state_verified' => $manualLoginVerified,
                'login_trigger_entry' => ($hotelReady || $skipWithVerifiedLogin) ? '' : (string)($trigger['entry'] ?? ''),
                'login_trigger_status' => $hotelReady
                    ? 'already_ready_no_login'
                    : ($skipWithVerifiedLogin ? 'login_verified_reference_only' : (string)($trigger['status'] ?? '')),
                'after_login_sync_entry' => ($hotelReady || $operatorSkipActive) ? '' : (string)($afterLoginSync['entry'] ?? ''),
                'after_login_sync_status' => $hotelReady
                    ? 'already_ready_no_sync'
                    : ($operatorSkipActive ? 'skipped_by_operator_no_sync' : ''),
                'verifier_command' => (string)($step['p0_verifier_command'] ?? ''),
                'platform_ready' => $platformReady,
                'hotel_ready' => $hotelReady,
                'operator_skip_active' => $operatorSkipActive,
            ];
            $steps[] = $compact;
            if ($hotelReady) {
                $operatorSequence[] = [
                    'type' => 'already_ready',
                    'platform' => $platform,
                    'system_hotel_id' => $compact['system_hotel_id'],
                    'data_source_id' => $compact['data_source_id'],
                    'status' => $platformReady ? 'p0_traffic_gate_ready' : 'p0_hotel_scope_ready',
                    'boundary' => 'Target-date OTA rows and traffic field evidence are already ready for this hotel scope; do not start login or after-login sync from this report.',
                ];
                $operatorSequence[] = [
                    'type' => 'single_scope_verifier',
                    'platform' => $platform,
                    'system_hotel_id' => $compact['system_hotel_id'],
                    'data_source_id' => $compact['data_source_id'],
                    'command' => $compact['verifier_command'],
                    'required_result' => 'ready',
                ];
                continue;
            }
            if ($operatorSkipActive) {
                $operatorSequence[] = [
                    'type' => 'operator_skip',
                    'platform' => $platform,
                    'system_hotel_id' => $compact['system_hotel_id'],
                    'data_source_id' => $compact['data_source_id'],
                    'status' => 'p0_skipped_by_operator',
                    'boundary' => 'No OTA collection or after-login sync should be started for this platform while the operator skip is active.',
                ];
                $operatorSequence[] = [
                    'type' => 'single_scope_verifier',
                    'platform' => $platform,
                    'system_hotel_id' => $compact['system_hotel_id'],
                    'data_source_id' => $compact['data_source_id'],
                    'command' => $compact['verifier_command'],
                    'required_result' => 'ready',
                ];
                continue;
            }
            $operatorSequence[] = [
                'type' => 'manual_login',
                'platform' => $platform,
                'system_hotel_id' => $compact['system_hotel_id'],
                'data_source_id' => $compact['data_source_id'],
                'entry' => $compact['login_trigger_entry'],
                'status' => $compact['login_trigger_status'],
                'required_human_action' => 'Complete authorized OTA login, captcha/SMS/human verification, and permission confirmation in the opened browser Profile.',
            ];
            $operatorSequence[] = [
                'type' => 'after_login_sync',
                'platform' => $platform,
                'system_hotel_id' => $compact['system_hotel_id'],
                'data_source_id' => $compact['data_source_id'],
                'entry' => $compact['after_login_sync_entry'],
                'requires' => 'manual_login_state_verified=true',
            ];
            $operatorSequence[] = [
                'type' => 'single_scope_verifier',
                'platform' => $platform,
                'system_hotel_id' => $compact['system_hotel_id'],
                'data_source_id' => $compact['data_source_id'],
                'command' => $compact['verifier_command'],
                'required_result' => 'ready',
            ];
        }
        $platformSummaries[] = [
            'platform' => $platform,
            'target_date_rows' => (int)($platformPayload['target_date_rows'] ?? 0),
            'latest_available_date' => (string)($platformPayload['latest_available']['date'] ?? ''),
            'latest_available_rows' => (int)($platformPayload['latest_available']['rows'] ?? 0),
            'field_fact_status' => (string)($platformPayload['field_fact_status'] ?? ''),
            'traffic_gate_status' => (string)($gate['status'] ?? ''),
            'traffic_rows' => (int)($gate['traffic_rows'] ?? 0),
            'readback_check_supported' => (bool)($gate['readback_check_supported'] ?? false),
            'readback_verified_rows' => (int)($gate['readback_verified_rows'] ?? 0),
            'readback_unverified_rows' => (int)($gate['readback_unverified_rows'] ?? 0),
            'readback_status' => (string)($gate['readback_status'] ?? 'not_loaded'),
            'action_entry' => $operatorSkipActive ? '' : (string)($gate['action_entry'] ?? ''),
            'action_status' => $operatorSkipActive ? 'skipped_by_operator_no_capture' : (string)($gate['action_status'] ?? ''),
            'platform_ready' => $platformReady,
            'operator_skip_active' => $operatorSkipActive,
            'operator_skip_policy' => $operatorSkipActive ? 'p0_skipped_by_operator_reference_only_no_collection' : '',
            'missing_inputs' => array_values(array_map('strval', (array)($gate['action_missing_inputs'] ?? $gate['required_next_inputs'] ?? []))),
            'next_steps' => $steps,
        ];
    }

    $platformArg = business_chain_platform_scope_arg($platforms);
    return [
        'status' => (string)($payload['status'] ?? 'unknown'),
        'verifier_exit_code' => $exitCode,
        'source_policy' => 'read_p0_verifier_metadata_only_no_ota_collection',
        'sensitive_values_policy' => 'metadata_only_no_cookie_token_profile_path_or_raw_payload',
        'scope' => [
            'target_date' => (string)($payload['scope']['date'] ?? $targetDate),
            'system_hotel_id' => $systemHotelId,
            'platforms' => array_values(array_unique(array_map(
                static fn(string $platform): string => strtolower(trim($platform)),
                $platforms
            ))),
            'metric_scope' => (string)($payload['scope']['metric_scope'] ?? 'ota_channel'),
        ],
        'summary' => is_array($payload['summary'] ?? null) ? $payload['summary'] : [],
        'platform_summaries' => $platformSummaries,
        'operator_sequence' => $operatorSequence,
        'authorization_options' => [
            [
                'mode' => 'browser_profile_tiancheng_account',
                'status' => 'allowed_with_human_login',
                'scope_policy' => 'authorized_ota_account_only',
                'required_inputs' => ['manual_login_state_verified', 'authorized_browser_profile', 'selected_system_hotel_match'],
                'completion_gate' => 'p0_field_loop_verifier_ready',
            ],
            [
                'mode' => 'authorized_cookie_api_temporary',
                'status' => 'temporary_only',
                'scope_policy' => 'authorized_cookie_or_headers_may_seed_collection_but_must_not_become_default_mainline',
                'required_inputs' => ['authorized_cookie_or_headers', 'traffic_request_url_or_cdp_endpoint_evidence', 'target_date_traffic_response_captured'],
                'forbidden_outputs' => ['raw_cookie_value_in_report', 'raw_token_value_in_report', 'cookie_api_as_default_mainline'],
                'completion_gate' => 'target_date_rows_ingested_and_p0_field_loop_verifier_ready',
            ],
        ],
        'completion_gate' => [
            'command' => 'npm.cmd run verify:p0-ota-field-loop -- --date='
                . $targetDate
                . ($platformArg !== '' ? ' --platform=' . $platformArg : '')
                . ($systemHotelId !== null ? ' --system-hotel-id=' . $systemHotelId : ''),
            'required_status' => 'ready',
            'current_status' => (string)($payload['status'] ?? 'unknown'),
        ],
    ];
}

/**
 * Add the stable source/date/quality contract required by the unified report.
 * Missing rows, bindings, and readback evidence remain explicit and never
 * become zero-valued business facts.
 *
 * @param array<int, array<string, mixed>> $sourceRows
 * @param array<string, mixed> $p0ExecutionPlan
 * @return array<int, array<string, mixed>>
 */
function business_chain_attach_source_date_quality(array $sourceRows, array $p0ExecutionPlan): array
{
    $scope = is_array($p0ExecutionPlan['scope'] ?? null) ? $p0ExecutionPlan['scope'] : [];
    $systemHotelId = isset($scope['system_hotel_id']) && (int)$scope['system_hotel_id'] > 0
        ? (int)$scope['system_hotel_id']
        : null;
    $hotelIdentity = is_array($scope['system_hotel_identity'] ?? null)
        ? $scope['system_hotel_identity']
        : business_chain_system_hotel_identity($systemHotelId);
    $hotelIdentityStatus = (string)($hotelIdentity['status'] ?? 'missing');
    $hotelIdentityReady = $hotelIdentityStatus === 'ready';
    $systemHotelName = trim((string)($hotelIdentity['system_hotel_name'] ?? ''));
    $expectedHotelName = trim((string)($hotelIdentity['expected_hotel_name'] ?? ''));
    $summaries = [];
    foreach (business_chain_list($p0ExecutionPlan['platform_summaries'] ?? []) as $summary) {
        if (!is_array($summary)) {
            continue;
        }
        $platform = strtolower(trim((string)($summary['platform'] ?? '')));
        if ($platform !== '') {
            $summaries[$platform] = $summary;
        }
    }

    foreach ($sourceRows as &$row) {
        $source = strtolower(trim((string)($row['source'] ?? '')));
        $summary = is_array($summaries[$source] ?? null) ? $summaries[$source] : [];
        $missingInputs = array_values(array_unique(array_map(
            'strval',
            (array)($summary['missing_inputs'] ?? [])
        )));
        $trafficGateStatus = (string)($summary['traffic_gate_status'] ?? 'not_loaded');
        $fieldFactStatus = (string)($summary['field_fact_status'] ?? 'not_loaded');
        $targetDateRows = (int)($summary['target_date_rows'] ?? $row['target_counts']['accepted'] ?? 0);
        $trafficRows = (int)($summary['traffic_rows'] ?? $row['target_counts']['traffic'] ?? 0);
        $readbackStatus = (string)($summary['readback_status'] ?? 'not_loaded');
        $platformReady = $hotelIdentityReady
            && ($summary['platform_ready'] ?? false) === true
            && (string)($row['target_status'] ?? '') === 'ready';
        $bindingMissing = array_values(array_filter(
            $missingInputs,
            static function (string $item): bool {
                $item = strtolower(trim($item));
                foreach ([
                    'data_source',
                    'profile_dir',
                    'platform_hotel',
                    'poi_id',
                    'profile_binding',
                    'same_source_profile',
                ] as $marker) {
                    if (str_contains($item, $marker)) {
                        return true;
                    }
                }
                return false;
            }
        )) !== [];
        $permissionDenied = str_contains(strtolower($trafficGateStatus), 'permission')
            || array_values(array_filter(
                $missingInputs,
                static fn(string $item): bool => str_contains(strtolower($item), 'permission')
            )) !== [];

        $qualityStatus = 'unverified';
        if (!$hotelIdentityReady) {
            $qualityStatus = 'binding_missing';
        } elseif ($platformReady) {
            $qualityStatus = 'available';
        } elseif ($bindingMissing) {
            $qualityStatus = 'binding_missing';
        } elseif ($permissionDenied) {
            $qualityStatus = 'permission_denied';
        } elseif ($targetDateRows > 0 || $trafficRows > 0) {
            $qualityStatus = $fieldFactStatus === 'partial' ? 'partial' : 'unverified';
        }

        $qualityFlags = $missingInputs;
        if (!$hotelIdentityReady) {
            array_unshift($qualityFlags, 'system_hotel_identity_' . $hotelIdentityStatus);
        }
        if ($trafficGateStatus !== '' && $trafficGateStatus !== 'ready') {
            $qualityFlags[] = $trafficGateStatus;
        }
        if ($source === 'meituan' && $readbackStatus !== 'ready') {
            $qualityFlags[] = $readbackStatus === 'not_loaded'
                ? 'target_date_readback_not_loaded'
                : 'target_date_' . $readbackStatus;
        }
        $qualityFlags = array_values(array_unique(array_filter(array_map(
            static fn(string $item): string => strtolower(trim($item)),
            $qualityFlags
        ), static fn(string $item): bool => $item !== '')));
        $status = $platformReady
            ? 'ready'
            : (!$hotelIdentityReady || ($targetDateRows <= 0 && $trafficRows <= 0) ? 'blocked' : 'partial');

        $contract = [
            'contract_version' => 'ota-source-date-quality-v1',
            'source' => $source,
            'target_date' => (string)($row['target_date'] ?? ''),
            'system_hotel_id' => $systemHotelId,
            'system_hotel_name' => $systemHotelName !== '' ? $systemHotelName : null,
            'expected_hotel_name' => $expectedHotelName !== '' ? $expectedHotelName : null,
            'metric_scope' => 'ota_channel',
            'status' => $status,
            'quality_status' => $qualityStatus,
            'quality_flags' => $qualityFlags,
            'claim_allowed' => $platformReady,
            'evidence' => [
                'target_date_rows' => $targetDateRows,
                'traffic_rows' => $trafficRows,
                'system_hotel_identity_status' => $hotelIdentityStatus,
                'expected_name_status' => (string)($hotelIdentity['expected_name_status'] ?? 'not_requested'),
                'same_name_system_hotel_ids' => array_values(array_map(
                    'intval',
                    (array)($hotelIdentity['same_name_system_hotel_ids'] ?? [])
                )),
                'field_fact_status' => $fieldFactStatus,
                'readback_check_supported' => (bool)($summary['readback_check_supported'] ?? false),
                'readback_verified_rows' => (int)($summary['readback_verified_rows'] ?? 0),
                'readback_unverified_rows' => (int)($summary['readback_unverified_rows'] ?? 0),
                'readback_status' => $readbackStatus,
                'p0_traffic_gate_status' => $trafficGateStatus,
            ],
            'next_action' => [
                'entry' => $platformReady || !$hotelIdentityReady
                    ? ''
                    : (string)($summary['action_entry'] ?? ''),
                'missing_inputs' => $platformReady
                    ? []
                    : array_values(array_unique(array_merge(
                        $hotelIdentityReady ? [] : ['system_hotel_identity'],
                        $missingInputs
                    ))),
            ],
            'forbidden_fallbacks' => [
                'zero_as_missing_data',
                'historical_date_as_target_date',
                'cross_hotel_profile_reuse',
                'cross_platform_data_substitution',
            ],
            'sensitive_values_exposed' => false,
        ];
        $row['quality_status'] = $qualityStatus;
        $row['quality_flags'] = $qualityFlags;
        $row['system_hotel_id'] = $systemHotelId;
        $row['system_hotel_name'] = $systemHotelName !== '' ? $systemHotelName : null;
        $row['expected_hotel_name'] = $expectedHotelName !== '' ? $expectedHotelName : null;
        $row['source_date_quality'] = $contract;
    }
    unset($row);

    return $sourceRows;
}

/**
 * @param array<string, mixed> $revenue
 * @param array<string, mixed> $closure
 * @return array<string, mixed>
 */
function business_chain_downstream_reference_scope(array $sourceRows, array $operatorSkippedPlatforms): array
{
    $operatorSkippedLookup = array_fill_keys(array_map('strtolower', $operatorSkippedPlatforms), true);
    $targetReadyPlatforms = [];
    $targetBlockedPlatforms = [];
    $targetReferenceOnlyPlatforms = [];
    $referenceReadyPlatforms = [];
    $operatorSkippedReadyPlatforms = [];
    $targetDate = '';
    foreach ($sourceRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($targetDate === '') {
            $targetDate = (string)($row['target_date'] ?? '');
        }
        $source = strtolower(trim((string)($row['source'] ?? '')));
        if ($source === '') {
            continue;
        }
        $operatorSkipped = isset($operatorSkippedLookup[$source]);
        if ($operatorSkipped) {
            $targetBlockedPlatforms[] = $source;
        } elseif (($row['target_status'] ?? '') === 'ready') {
            $targetReadyPlatforms[] = $source;
        } else {
            $targetBlockedPlatforms[] = $source;
            if (($row['target_status'] ?? '') === 'reference_only_non_traffic') {
                $targetReferenceOnlyPlatforms[] = $source;
            }
        }
        if (in_array(($row['reference_status'] ?? ''), ['ready', 'reference_only_non_traffic'], true)) {
            $referenceReadyPlatforms[] = $source;
        }
        if ($operatorSkipped) {
            $operatorSkippedReadyPlatforms[] = $source;
        }
    }

    $status = 'target_date_p0_required';
    if ($targetReadyPlatforms !== [] && $targetBlockedPlatforms !== []) {
        $status = 'partial_target_date_ready';
    } elseif ($targetReadyPlatforms !== []) {
        $status = 'target_date_ready';
    } elseif ($targetReferenceOnlyPlatforms !== []) {
        $status = 'target_date_reference_only';
    } elseif ($referenceReadyPlatforms !== []) {
        $status = 'latest_reference_ready';
    }

    return [
        'status' => $status,
        'metric_scope' => 'ota_channel',
        'target_date' => $targetDate,
        'target_ready_platforms' => array_values(array_unique($targetReadyPlatforms)),
        'target_blocked_platforms' => array_values(array_unique($targetBlockedPlatforms)),
        'target_reference_only_platforms' => array_values(array_unique($targetReferenceOnlyPlatforms)),
        'reference_ready_platforms' => array_values(array_unique($referenceReadyPlatforms)),
        'operator_skip_platforms' => array_values(array_unique($operatorSkippedReadyPlatforms)),
        'claim_policy' => 'ready_platform_rows_are_read_only_reference_until_all_required_p0_platforms_ready',
    ];
}

/**
 * @param array<string, mixed> $referenceScope
 * @param array<string, mixed> $revenueDiagnosis
 * @param array<string, mixed> $aiAdviceDraft
 * @return array<string, mixed>
 */
function business_chain_revenue_to_ai_handoff(array $referenceScope, array $revenueDiagnosis, array $aiAdviceDraft, bool $p0Ready = false): array
{
    $targetReadyPlatforms = array_values(array_unique(array_map('strval', business_chain_list($referenceScope['target_ready_platforms'] ?? []))));
    $targetBlockedPlatforms = array_values(array_unique(array_map('strval', business_chain_list($referenceScope['target_blocked_platforms'] ?? []))));
    $operatorSkipPlatforms = array_values(array_unique(array_map('strval', business_chain_list($referenceScope['operator_skip_platforms'] ?? []))));
    $sourceChannels = array_values(array_unique(array_map('strval', business_chain_list($revenueDiagnosis['source_channels'] ?? []))));
    $sourcePlatforms = $targetReadyPlatforms !== [] ? $targetReadyPlatforms : $sourceChannels;
    $metrics = is_array($revenueDiagnosis['metrics'] ?? null) ? $revenueDiagnosis['metrics'] : [];
    $metricRows = [];
    foreach ($metrics as $key => $metric) {
        if (!is_array($metric)) {
            continue;
        }
        $metricRows[] = [
            'key' => (string)($metric['key'] ?? $key),
            'status' => (string)($metric['status'] ?? ''),
            'reason' => (string)($metric['reason'] ?? ''),
            'unit' => (string)($metric['unit'] ?? ''),
        ];
    }

    $actionRows = [];
    foreach (business_chain_list($aiAdviceDraft['actions'] ?? []) as $action) {
        if (!is_array($action)) {
            continue;
        }
        $basis = is_array($action['decision_basis_summary'] ?? null) ? $action['decision_basis_summary'] : [];
        $actionRows[] = [
            'key' => (string)($action['key'] ?? ''),
            'status' => (string)($action['status'] ?? ''),
            'reason' => (string)($action['reason'] ?? ''),
            'manual_review_required' => ($action['manual_review_required'] ?? true) !== false,
            'auto_write_ota' => false,
            'decision_basis_status' => (string)($basis['status'] ?? 'unknown'),
            'decision_basis_ready_count' => (int)($basis['ready_count'] ?? 0),
            'decision_basis_blocked_count' => (int)($basis['blocked_count'] ?? 0),
        ];
    }

    $draftStatus = (string)($aiAdviceDraft['status'] ?? '');
    $handoffReadyForReview = $sourcePlatforms !== [] && $draftStatus === 'ready_for_manual_review';
    $handoffReferenceOnly = $sourcePlatforms !== [] && $draftStatus === 'draft_reference_only';
    $requiredBeforeExecution = $p0Ready
        ? ['manual_review_workflow_connected', 'approved_ai_advice', 'operation_execution_intent_created_by_human_review']
        : ['all_required_p0_platforms_ready', 'manual_review_workflow_connected', 'approved_ai_advice', 'operation_execution_intent_created_by_human_review'];

    $handoff = [
        'status' => $handoffReadyForReview
            ? 'handoff_ready_for_manual_review'
            : ($handoffReferenceOnly ? 'handoff_reference_only' : 'handoff_blocked'),
        'source_scope' => $targetReadyPlatforms !== []
            ? implode('_', $targetReadyPlatforms) . '_target_date_ota_channel' . ($handoffReadyForReview ? '' : '_reference')
            : 'ota_channel' . ($handoffReadyForReview ? '' : '_reference'),
        'metric_scope' => 'ota_channel',
        'source_platforms' => $sourcePlatforms,
        'target_ready_platforms' => $targetReadyPlatforms,
        'target_blocked_platforms' => $targetBlockedPlatforms,
        'operator_skip_platforms' => $operatorSkipPlatforms,
        'revenue_status' => (string)($revenueDiagnosis['status'] ?? ''),
        'revenue_metric_keys' => array_values(array_map(
            static fn(array $row): string => (string)$row['key'],
            $metricRows
        )),
        'revenue_metrics' => $metricRows,
        'ai_draft_status' => $draftStatus,
        'ai_action_count' => count($actionRows),
        'ai_actions' => $actionRows,
        'manual_review_required' => true,
        'can_auto_write_ota' => false,
        'can_create_operation_execution' => false,
        'decision_policy' => $handoffReadyForReview
            ? 'scoped_ai_review_packet_no_auto_write_or_execution_without_human_approval'
            : 'read_only_ai_draft_no_execution_until_p0_ready_and_manual_review',
        'required_before_execution' => $requiredBeforeExecution,
        'forbidden_promotions' => [
            'whole_hotel_truth_from_ota_only',
            'ai_decision_final',
            'operation_execution_completed',
        ],
    ];
    $handoff['manual_review_packet'] = business_chain_manual_review_packet($handoff, $revenueDiagnosis, $aiAdviceDraft);
    return $handoff;
}

function business_chain_manual_review_input_key_for_reason(string $reason, string $fallback): string
{
    $map = [
        'available_room_nights_missing' => 'revpar_denominator',
        'floor_price_missing' => 'floor_price',
        'manual_review_workflow_not_connected' => 'manual_review_workflow',
        'operation_execution_not_loaded' => 'operation_feedback_input',
        'competitor_price_fields_missing' => 'competitor_price',
        'demand_forecasts_not_loaded' => 'demand_signal_7d',
        'ota_room_nights_zero' => 'ota_metrics',
        'ota_revenue_metrics_missing' => 'ota_metrics',
        'online_daily_data_empty' => 'ota_metrics',
    ];
    return $map[$reason] ?? $fallback;
}

function business_chain_manual_review_blocker_rank(array $blocker): int
{
    $reason = (string)($blocker['reason'] ?? '');
    $key = (string)($blocker['key'] ?? '');
    $rank = [
        'available_room_nights_missing' => 10,
        'floor_price_missing' => 20,
        'manual_review_workflow_not_connected' => 30,
        'operation_execution_not_loaded' => 40,
        'competitor_price_fields_missing' => 50,
        'demand_forecasts_not_loaded' => 60,
        'ota_room_nights_zero' => 70,
        'ota_revenue_metrics_missing' => 70,
        'online_daily_data_empty' => 70,
    ];
    if (isset($rank[$reason])) {
        return $rank[$reason];
    }
    $keyRank = [
        'revpar_denominator' => 10,
        'floor_price' => 20,
        'manual_review_workflow' => 30,
        'operation_feedback_input' => 40,
        'competitor_price' => 50,
        'demand_signal_7d' => 60,
        'ota_metrics' => 70,
    ];
    return $keyRank[$key] ?? 100;
}

/**
 * @param array<string, mixed> $handoff
 * @param array<string, mixed> $revenueDiagnosis
 * @param array<string, mixed> $aiAdviceDraft
 * @return array<string, mixed>
 */
function business_chain_manual_review_packet(array $handoff, array $revenueDiagnosis, array $aiAdviceDraft): array
{
    $actions = business_chain_list($aiAdviceDraft['actions'] ?? []);
    $firstAction = [];
    foreach ($actions as $action) {
        if (is_array($action)) {
            $firstAction = $action;
            break;
        }
    }
    $basis = is_array($firstAction['decision_basis_summary'] ?? null) ? $firstAction['decision_basis_summary'] : [];
    $blockers = [];
    $seenBlockers = [];
    $addBlocker = static function (array $blocker) use (&$blockers, &$seenBlockers): void {
        $reason = trim((string)($blocker['reason'] ?? ''));
        $key = trim((string)($blocker['key'] ?? ''));
        if ($reason === '' && $key === '') {
            return;
        }
        if ($key === '') {
            $key = business_chain_manual_review_input_key_for_reason($reason, $reason);
            $blocker['key'] = $key;
        }
        $dedupeKey = $key . '|' . $reason;
        if (isset($seenBlockers[$dedupeKey])) {
            return;
        }
        $seenBlockers[$dedupeKey] = true;
        $blockers[] = $blocker;
    };
    foreach (business_chain_list($basis['items'] ?? []) as $index => $item) {
        if (!is_array($item) || (string)($item['status'] ?? '') === 'ok') {
            continue;
        }
        $addBlocker([
            'key' => (string)($item['key'] ?? ''),
            'label' => (string)($item['label'] ?? ''),
            'status' => (string)($item['status'] ?? ''),
            'reason' => (string)($item['reason'] ?? ''),
            'severity' => (string)($item['severity'] ?? ''),
            'category' => (string)($item['category'] ?? ''),
            'next_action' => (string)($item['next_action'] ?? ''),
            'target_page' => (string)($item['target_page'] ?? ''),
            'target_tab' => (string)($item['target_tab'] ?? ''),
            'target_platform' => (string)($item['target_platform'] ?? ''),
            '_order' => $index,
        ]);
    }
    foreach (business_chain_list($firstAction['blocking_reasons'] ?? []) as $index => $reasonValue) {
        $reason = trim((string)$reasonValue);
        if ($reason === '') {
            continue;
        }
        $addBlocker([
            'key' => business_chain_manual_review_input_key_for_reason($reason, (string)($firstAction['key'] ?? '')),
            'label' => '',
            'status' => 'blocked',
            'reason' => $reason,
            'severity' => '',
            'category' => 'ai_action_blocker',
            'next_action' => '',
            'target_page' => '',
            'target_tab' => '',
            'target_platform' => '',
            '_order' => 100 + $index,
        ]);
    }
    $actionResolutionPlan = is_array($firstAction['ai_decision_resolution_plan'] ?? null)
        ? $firstAction['ai_decision_resolution_plan']
        : [];
    foreach (['items', 'skipped_items'] as $listKey) {
        foreach (business_chain_list($actionResolutionPlan[$listKey] ?? []) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $reason = trim((string)($item['evidence_code'] ?? ''));
            if ($reason === '') {
                continue;
            }
            $addBlocker([
                'key' => business_chain_manual_review_input_key_for_reason($reason, (string)($item['code'] ?? '')),
                'label' => '',
                'status' => (string)($item['status'] ?? ($listKey === 'skipped_items' ? 'skipped_by_operator_policy' : 'blocked')),
                'reason' => $reason,
                'severity' => (string)($item['severity'] ?? ''),
                'category' => (string)($item['input_type'] ?? ''),
                'next_action' => '',
                'target_page' => (string)($item['target_page'] ?? ''),
                'target_tab' => (string)($item['target_tab'] ?? ''),
                'target_platform' => (string)($item['target_platform'] ?? ''),
                '_order' => 200 + ($listKey === 'skipped_items' ? 100 : 0) + $index,
            ]);
        }
    }
    $severityRank = ['high' => 0, 'medium' => 1, 'low' => 2];
    usort($blockers, static function (array $left, array $right) use ($severityRank): int {
        $leftBusinessRank = business_chain_manual_review_blocker_rank($left);
        $rightBusinessRank = business_chain_manual_review_blocker_rank($right);
        if ($leftBusinessRank !== $rightBusinessRank) {
            return $leftBusinessRank <=> $rightBusinessRank;
        }
        $leftSeverityRank = $severityRank[(string)($left['severity'] ?? '')] ?? 3;
        $rightSeverityRank = $severityRank[(string)($right['severity'] ?? '')] ?? 3;
        return $leftSeverityRank === $rightSeverityRank
            ? ((int)($left['_order'] ?? 0) <=> (int)($right['_order'] ?? 0))
            : ($leftSeverityRank <=> $rightSeverityRank);
    });
    $blockers = array_map(static function (array $blocker): array {
        unset($blocker['_order']);
        return $blocker;
    }, $blockers);

    $metrics = [];
    foreach (is_array($revenueDiagnosis['metrics'] ?? null) ? $revenueDiagnosis['metrics'] : [] as $key => $metric) {
        if (!is_array($metric)) {
            continue;
        }
        $metrics[] = [
            'key' => (string)($metric['key'] ?? $key),
            'label' => (string)($metric['label'] ?? ''),
            'value' => $metric['value'] ?? null,
            'unit' => (string)($metric['unit'] ?? ''),
            'status' => (string)($metric['status'] ?? ''),
            'reason' => (string)($metric['reason'] ?? ''),
        ];
    }

    $primaryBlocker = $blockers[0] ?? [];
    $actionReason = (string)($firstAction['reason'] ?? '');
    $status = $blockers === [] && $actionReason === ''
        ? 'ready_for_manual_review'
        : 'blocked_ready_for_manual_review';
    $reviewContract = business_chain_ai_decision_review_contract(
        $handoff,
        $revenueDiagnosis,
        $firstAction,
        $blockers,
        $metrics,
        $status
    );

    return [
        'status' => $status,
        'review_mode' => 'manual_review_only',
        'source_scope' => (string)($handoff['source_scope'] ?? ''),
        'metric_scope' => 'ota_channel',
        'source_platforms' => business_chain_list($handoff['source_platforms'] ?? []),
        'revenue_status' => (string)($revenueDiagnosis['status'] ?? ''),
        'primary_action' => [
            'key' => (string)($firstAction['key'] ?? ''),
            'status' => (string)($firstAction['status'] ?? ''),
            'reason' => $actionReason,
            'manual_review_required' => ($firstAction['manual_review_required'] ?? true) !== false,
            'auto_write_ota' => false,
        ],
        'primary_blocker' => $primaryBlocker === [] ? null : $primaryBlocker,
        'blockers' => $blockers,
        'revenue_metrics' => $metrics,
        'ai_decision_review_contract' => $reviewContract,
        'ready_count' => (int)($basis['ready_count'] ?? 0),
        'blocked_count' => (int)($basis['blocked_count'] ?? count($blockers)),
        'required_before_execution' => business_chain_list($handoff['required_before_execution'] ?? []),
        'forbidden_actions' => [
            'auto_write_ota',
            'create_operation_execution_without_human_approval',
            'claim_ai_decision_final',
            'promote_ota_scope_to_whole_hotel_truth',
        ],
    ];
}

/**
 * @param array<string, mixed> $handoff
 * @param array<string, mixed> $revenueDiagnosis
 * @param array<string, mixed> $firstAction
 * @param array<int, array<string, mixed>> $blockers
 * @param array<int, array<string, mixed>> $metrics
 * @return array<string, mixed>
 */
function business_chain_ai_decision_review_contract(
    array $handoff,
    array $revenueDiagnosis,
    array $firstAction,
    array $blockers,
    array $metrics,
    string $packetStatus
): array {
    $requiredInputs = [];
    foreach ($blockers as $index => $blocker) {
        $key = trim((string)($blocker['key'] ?? ''));
        $reason = trim((string)($blocker['reason'] ?? ''));
        $requiredInputs[] = [
            'order' => $index + 1,
            'code' => $key !== '' ? $key : ($reason !== '' ? $reason : 'review_input_' . ($index + 1)),
            'status' => 'missing_or_blocked',
            'input_type' => business_chain_ai_review_input_type($key, $reason),
            'evidence_code' => $reason,
            'severity' => (string)($blocker['severity'] ?? ''),
            'target_page' => (string)($blocker['target_page'] ?? ''),
            'target_tab' => (string)($blocker['target_tab'] ?? ''),
            'required_before' => 'approve_ai_advice_for_operation_intake',
        ];
    }

    $metricSnapshot = [];
    foreach ($metrics as $metric) {
        $metricSnapshot[] = [
            'key' => (string)($metric['key'] ?? ''),
            'value' => $metric['value'] ?? null,
            'unit' => (string)($metric['unit'] ?? ''),
            'status' => (string)($metric['status'] ?? ''),
            'reason' => (string)($metric['reason'] ?? ''),
        ];
    }

    $hasBlockingInputs = $requiredInputs !== [];
    $resolutionPlan = business_chain_ai_decision_resolution_plan($requiredInputs, (string)($handoff['source_scope'] ?? ''));

    return [
        'status' => $hasBlockingInputs ? 'blocked_by_review_inputs' : 'ready_for_human_ai_decision',
        'review_mode' => 'manual_review_only',
        'review_entry' => 'agent-center',
        'persisted' => false,
        'source_scope' => (string)($handoff['source_scope'] ?? ''),
        'metric_scope' => 'ota_channel',
        'source_platforms' => business_chain_list($handoff['source_platforms'] ?? []),
        'revenue_status' => (string)($revenueDiagnosis['status'] ?? ''),
        'manual_review_packet_status' => $packetStatus,
        'candidate_action_key' => (string)($firstAction['key'] ?? ''),
        'candidate_action_reason' => (string)($firstAction['reason'] ?? ''),
        'approval_allowed' => !$hasBlockingInputs,
        'operation_intake_allowed' => false,
        'auto_apply_ai_advice' => false,
        'required_input_count' => count($requiredInputs),
        'required_input_items' => $requiredInputs,
        'resolution_plan' => $resolutionPlan,
        'metric_snapshot' => $metricSnapshot,
        'required_output_fields' => [
            'reviewer_id',
            'decision_status',
            'decision_reason',
            'approved_action_key',
            'evidence_links',
        ],
        'allowed_decision_outputs' => [
            [
                'code' => 'request_revenue_metric_evidence',
                'allowed' => true,
                'next_gate' => 'resolve_revenue_metric_gap',
            ],
            [
                'code' => 'record_manual_review_note',
                'allowed' => true,
                'next_gate' => 'manual_review_workflow_connected',
            ],
            [
                'code' => 'reject_ai_advice',
                'allowed' => true,
                'next_gate' => 'new_revenue_ai_review',
            ],
            [
                'code' => 'approve_ai_advice_for_operation_intake',
                'allowed' => !$hasBlockingInputs,
                'next_gate' => 'operator_creates_execution_intent',
            ],
        ],
        'forbidden_actions' => [
            'auto_apply_ai_advice',
            'auto_write_ota',
            'auto_create_operation_execution_intent',
            'claim_ai_decision_final_without_review_record',
            'promote_ota_scope_to_whole_hotel_truth',
        ],
        'protected_boundary' => 'manual_review_requires_explicit_evidence_no_auto_apply',
    ];
}

/**
 * @param array<int, array<string, mixed>> $requiredInputs
 * @return array<string, mixed>
 */
function business_chain_ai_decision_resolution_plan(array $requiredInputs, string $sourceScope): array
{
    $items = [];
    foreach ($requiredInputs as $input) {
        if (!is_array($input)) {
            continue;
        }
        $code = (string)($input['code'] ?? '');
        $evidenceCode = (string)($input['evidence_code'] ?? '');
        $resolution = business_chain_ai_decision_resolution_spec($code, $evidenceCode);
        $items[] = [
            'order' => (int)($input['order'] ?? (count($items) + 1)),
            'code' => $code,
            'input_type' => (string)($input['input_type'] ?? ''),
            'evidence_code' => $evidenceCode,
            'status' => 'pending_evidence',
            'target_page' => (string)($input['target_page'] ?? ''),
            'target_tab' => (string)($input['target_tab'] ?? ''),
            'resolution_action' => $resolution['resolution_action'],
            'acceptance_check' => $resolution['acceptance_check'],
            'unblocks' => $resolution['unblocks'],
            'forbidden_shortcut' => $resolution['forbidden_shortcut'],
        ];
    }

    return [
        'status' => $items === [] ? 'ready_for_ai_review' : 'has_pending_evidence',
        'source_scope' => $sourceScope,
        'metric_scope' => 'ota_channel',
        'item_count' => count($items),
        'pending_count' => count($items),
        'approval_allowed_after_resolution' => $items === [],
        'post_resolution_gate' => 'ai_decision_review_contract.approval_allowed',
        'post_resolution_verifier' => 'npm.cmd run verify:business-chain-report',
        'forbidden_actions' => [
            'fill_missing_evidence_with_defaults',
            'approve_ai_advice_without_resolving_inputs',
            'auto_write_ota',
            'auto_create_operation_execution_intent',
            'promote_ota_scope_to_whole_hotel_truth',
        ],
        'items' => $items,
    ];
}

/**
 * @return array<string, string>
 */
function business_chain_ai_decision_resolution_spec(string $code, string $evidenceCode): array
{
    $map = [
        'available_room_nights_missing' => [
            'resolution_action' => 'provide_available_room_nights_or_mark_metric_unusable',
            'acceptance_check' => 'available_room_nights evidence exists or RevPAR remains explicitly not_calculable',
            'unblocks' => 'ota_contribution_revpar_review',
            'forbidden_shortcut' => 'default_available_room_nights',
        ],
        'floor_price_missing' => [
            'resolution_action' => 'provide_floor_price_or_min_rate_guard',
            'acceptance_check' => 'floor price guard is present before price recommendation approval',
            'unblocks' => 'pricing_guard_review',
            'forbidden_shortcut' => 'approve_price_without_floor_guard',
        ],
        'manual_review_workflow_not_connected' => [
            'resolution_action' => 'persist_or_attach_manual_review_record',
            'acceptance_check' => 'manual review record has reviewer, decision_status, decision_reason, and evidence_links',
            'unblocks' => 'approve_ai_advice_for_operation_intake',
            'forbidden_shortcut' => 'treat_chat_confirmation_as_persisted_review',
        ],
        'ota_room_nights_zero' => [
            'resolution_action' => 'verify_zero_room_nights_or_correct_ota_room_nights',
            'acceptance_check' => 'zero room nights is operator-verified or corrected from source evidence',
            'unblocks' => 'adr_and_pricing_review',
            'forbidden_shortcut' => 'calculate_adr_from_zero_denominator',
        ],
        'competitor_price_fields_missing' => [
            'resolution_action' => 'provide_competitor_price_field_evidence',
            'acceptance_check' => 'competitor price fields are loaded or marked unavailable with explicit reason',
            'unblocks' => 'competitor_price_context_review',
            'forbidden_shortcut' => 'invent_competitor_price',
        ],
        'demand_forecasts_not_loaded' => [
            'resolution_action' => 'load_or_mark_7d_demand_forecast_unavailable',
            'acceptance_check' => '7-day demand forecast is loaded or unavailable state is explicit',
            'unblocks' => 'demand_context_review',
            'forbidden_shortcut' => 'invent_demand_forecast',
        ],
        'operation_execution_not_loaded' => [
            'resolution_action' => 'attach_operation_feedback_or_keep_feedback_gate_closed',
            'acceptance_check' => 'operation feedback evidence exists or operation feedback gate remains blocked',
            'unblocks' => 'operation_feedback_review',
            'forbidden_shortcut' => 'claim_operation_feedback_from_ota_only',
        ],
    ];

    if (isset($map[$evidenceCode])) {
        return $map[$evidenceCode];
    }

    return [
        'resolution_action' => $code !== '' ? 'resolve_' . $code : 'resolve_review_input',
        'acceptance_check' => $evidenceCode !== '' ? 'evidence code ' . $evidenceCode . ' is resolved or remains explicit' : 'input evidence is resolved or remains explicit',
        'unblocks' => 'ai_decision_review',
        'forbidden_shortcut' => 'hide_missing_evidence',
    ];
}

function business_chain_ai_review_input_type(string $key, string $reason): string
{
    if (in_array($reason, ['available_room_nights_missing', 'ota_room_nights_zero', 'floor_price_missing'], true)) {
        return 'revenue_metric_evidence';
    }
    if ($reason === 'manual_review_workflow_not_connected') {
        return 'manual_review_process_gate';
    }
    if (in_array($reason, ['competitor_price_fields_missing', 'demand_forecasts_not_loaded'], true)) {
        return 'market_context_evidence';
    }
    if ($reason === 'operation_execution_not_loaded' || $key === 'operation_feedback_input') {
        return 'operation_feedback_evidence';
    }
    return 'supporting_evidence';
}

/**
 * @param array<string, mixed> $reviewContract
 * @param array<string, mixed> $packet
 * @param array<string, mixed> $referenceScope
 * @param array<int, string> $sourcePlatforms
 * @param array<int, string> $blockerReasons
 * @return array<string, mixed>
 */
function business_chain_operation_intake_preflight_contract(
    array $reviewContract,
    array $packet,
    array $referenceScope,
    array $sourcePlatforms,
    array $blockerReasons
): array {
    $approvalAllowed = ($reviewContract['approval_allowed'] ?? false) === true;
    $operationIntakeAllowed = ($reviewContract['operation_intake_allowed'] ?? false) === true;
    $targetDate = (string)($referenceScope['target_date'] ?? '');
    $sourcePlatform = (string)($sourcePlatforms[0] ?? '');
    $primaryAction = is_array($packet['primary_action'] ?? null) ? $packet['primary_action'] : [];
    $requiredReviewInputs = (int)($reviewContract['required_input_count'] ?? 0);

    $missingFields = [];
    if (!$approvalAllowed) {
        $missingFields[] = [
            'field' => 'approved_ai_advice',
            'reason' => $requiredReviewInputs > 0 ? 'ai_decision_review_inputs_pending' : 'ai_decision_review_not_approved',
            'source' => 'ai_decision_review_contract',
        ];
    }
    if (!$operationIntakeAllowed) {
        $missingFields[] = [
            'field' => 'operation_intake_allowed',
            'reason' => 'operation_intake_gate_closed',
            'source' => 'ai_decision_review_contract',
        ];
    }
    foreach ([
        ['field' => 'hotel_id', 'reason' => 'operator_selected_hotel_missing'],
        ['field' => 'source_record_id', 'reason' => 'persisted_manual_review_record_missing'],
        ['field' => 'operator_id', 'reason' => 'operator_identity_required'],
        ['field' => 'target_value.room_type_key', 'reason' => 'room_type_key_missing'],
        ['field' => 'target_value.rate_plan_key', 'reason' => 'rate_plan_key_missing'],
        ['field' => 'target_value.target_price', 'reason' => 'approved_target_price_missing'],
        ['field' => 'expected_metric', 'reason' => 'expected_metric_missing'],
    ] as $item) {
        $missingFields[] = [
            'field' => $item['field'],
            'reason' => $item['reason'],
            'source' => 'operation_execution_intent_contract',
        ];
    }

    if ($sourcePlatform === '') {
        $missingFields[] = [
            'field' => 'platform',
            'reason' => 'source_platform_missing',
            'source' => 'operation_execution_intent_contract',
        ];
    }
    if ($targetDate === '') {
        $missingFields[] = [
            'field' => 'date_start',
            'reason' => 'target_date_missing',
            'source' => 'operation_execution_intent_contract',
        ];
        $missingFields[] = [
            'field' => 'date_end',
            'reason' => 'target_date_missing',
            'source' => 'operation_execution_intent_contract',
        ];
    }

    $blockedReasons = array_values(array_unique(array_filter(array_merge(
        $blockerReasons,
        array_map(static fn(array $item): string => (string)($item['reason'] ?? ''), $missingFields)
    ))));

    return [
        'status' => $approvalAllowed && $operationIntakeAllowed && $missingFields === []
            ? 'ready_for_operator_create'
            : ($approvalAllowed ? 'blocked_by_operation_payload' : 'blocked_by_ai_review_contract'),
        'target_entry' => '/api/operation/execution-intents',
        'service_contract' => 'OperationManagementService::buildExecutionIntentPayload',
        'controller_action' => 'OperationManagement/createExecutionIntent',
        'persisted' => false,
        'dry_run_only' => true,
        'would_call_create_endpoint' => false,
        'create_allowed' => false,
        'source_scope' => (string)($reviewContract['source_scope'] ?? ''),
        'metric_scope' => 'ota_channel',
        'source_platforms' => $sourcePlatforms,
        'manual_review_packet_status' => (string)($packet['status'] ?? ''),
        'review_contract_status' => (string)($reviewContract['status'] ?? ''),
        'approval_allowed' => $approvalAllowed,
        'operation_intake_allowed' => $operationIntakeAllowed,
        'required_review_input_count' => $requiredReviewInputs,
        'missing_required_field_count' => count($missingFields),
        'missing_required_fields' => $missingFields,
        'blocked_reasons' => $blockedReasons,
        'projected_payload_template' => [
            'source_module' => 'ota_revenue_ai_manual_review',
            'source_record_id' => 0,
            'platform' => $sourcePlatform,
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => $targetDate,
            'date_end' => $targetDate,
            'current_value' => [
                'source_policy' => 'operator_confirmed_from_ctrip_ota_channel_after_review',
            ],
            'target_value_required_fields' => [
                'room_type_key',
                'rate_plan_key',
                'target_price',
            ],
            'evidence_required_fields' => [
                'approved_ai_advice',
                'manual_review_record',
                'source_scope',
                'metric_snapshot',
                'blocked_reasons_resolved',
            ],
            'expected_metric_policy' => 'operator_selected_after_review',
        ],
        'required_before_create' => [
            'ai_decision_review_contract.approval_allowed',
            'ai_decision_review_contract.operation_intake_allowed',
            'persisted_manual_review_record',
            'operator_selected_hotel',
            'operator_confirmed_price_target',
            'operation_execution_intent_payload_complete',
        ],
        'candidate_action_reason' => (string)($primaryAction['reason'] ?? ''),
        'forbidden_actions' => [
            'call_create_execution_intent_before_ai_review_approval',
            'auto_create_operation_execution_intent',
            'mark_operation_executed_without_evidence',
            'claim_operation_roi_ready',
            'promote_ota_scope_to_whole_hotel_truth',
        ],
        'protected_boundary' => 'operation_intake_requires_approved_ai_review_and_price_target_no_auto_create',
    ];
}

/**
 * @param array<string, mixed> $revenueToAiHandoff
 * @param array<string, mixed> $closure
 * @param array<string, mixed> $referenceScope
 * @return array<string, mixed>
 */
function business_chain_ai_to_operation_handoff(array $revenueToAiHandoff, array $closure, array $referenceScope): array
{
    $packet = is_array($revenueToAiHandoff['manual_review_packet'] ?? null)
        ? $revenueToAiHandoff['manual_review_packet']
        : [];
    $primaryAction = is_array($packet['primary_action'] ?? null) ? $packet['primary_action'] : [];
    $primaryBlocker = is_array($packet['primary_blocker'] ?? null) ? $packet['primary_blocker'] : [];
    $reviewContract = is_array($packet['ai_decision_review_contract'] ?? null) ? $packet['ai_decision_review_contract'] : [];
    $blockerReasons = [];
    foreach (business_chain_list($packet['blockers'] ?? []) as $blocker) {
        if (!is_array($blocker)) {
            continue;
        }
        $reason = trim((string)($blocker['reason'] ?? ''));
        if ($reason !== '') {
            $blockerReasons[] = $reason;
        }
    }
    $blockerReasons = array_values(array_unique($blockerReasons));

    $packetStatus = (string)($packet['status'] ?? '');
    $readyForApproval = $packetStatus === 'ready_for_manual_review';
    $primaryActionReason = (string)($primaryAction['reason'] ?? '');
    $primaryBlockerReason = (string)($primaryBlocker['reason'] ?? '');
    $blockedReason = $primaryBlockerReason !== ''
        ? $primaryBlockerReason
        : ($primaryActionReason !== '' ? $primaryActionReason : 'manual_review_required');
    $requiredBeforeCreate = [
        'operator_approves_ai_advice',
        'operator_confirms_operation_scope',
        'operator_selects_target_hotel',
        'operator_creates_execution_intent',
    ];
    if (!$readyForApproval) {
        array_unshift($requiredBeforeCreate, 'resolve_manual_review_blockers');
    }
    $forbiddenActions = array_values(array_unique(array_merge(
        business_chain_list($packet['forbidden_actions'] ?? []),
        [
            'auto_create_operation_execution_intent',
            'mark_operation_executed_without_evidence',
            'claim_roi_ready_without_review',
            'promote_ota_scope_to_whole_hotel_truth',
        ]
    )));
    $closureSummary = is_array($closure['summary'] ?? null) ? $closure['summary'] : [];
    $sourcePlatforms = business_chain_list($revenueToAiHandoff['source_platforms'] ?? []);
    $operationIntakePreflight = business_chain_operation_intake_preflight_contract(
        $reviewContract,
        $packet,
        $referenceScope,
        array_map('strval', $sourcePlatforms),
        $blockerReasons !== [] ? $blockerReasons : [$blockedReason]
    );

    return [
        'status' => $readyForApproval
            ? 'operation_intake_waiting_human_approval'
            : 'operation_intake_blocked_by_manual_review',
        'persisted' => false,
        'target_module' => 'operation_execution',
        'target_page' => 'ops-track',
        'target_entry' => '/api/operation/execution-intents',
        'source_scope' => (string)($revenueToAiHandoff['source_scope'] ?? ''),
        'metric_scope' => 'ota_channel',
        'target_date' => (string)($referenceScope['target_date'] ?? ''),
        'source_platforms' => $sourcePlatforms,
        'manual_review_required' => true,
        'can_create_operation_execution' => false,
        'blocked_reasons' => $blockerReasons !== [] ? $blockerReasons : [$blockedReason],
        'required_before_create' => $requiredBeforeCreate,
        'forbidden_actions' => $forbiddenActions,
        'operation_intake_packet' => [
            'status' => $readyForApproval
                ? 'waiting_human_approval'
                : 'blocked_by_manual_review_packet',
            'source_policy' => 'read_only_candidate_from_ctrip_ota_revenue_ai_manual_review',
            'candidate_source_module' => 'ota_revenue_ai_manual_review',
            'candidate_source_record_id' => 0,
            'candidate_source_record_policy' => 'requires_persisted_manual_review_or_operator_selected_action',
            'candidate_platforms' => $sourcePlatforms,
            'candidate_object_type' => 'ota_pricing',
            'candidate_action_type' => 'manual_review_revenue_pricing',
            'candidate_status' => $readyForApproval ? 'pending_human_approval' : 'blocked',
            'candidate_blocked_reason' => $blockedReason,
            'candidate_evidence' => [
                'source_scope' => (string)($revenueToAiHandoff['source_scope'] ?? ''),
                'metric_scope' => 'ota_channel',
                'manual_review_packet_status' => $packetStatus,
                'primary_action_reason' => $primaryActionReason,
                'primary_blocker_reason' => $primaryBlockerReason,
                'blocked_reasons' => $blockerReasons,
                'protected_boundary' => 'ctrip_ota_channel_only_no_whole_hotel_truth',
            ],
            'required_fields_for_real_create' => [
                'hotel_id',
                'source_record_id',
                'approved_ai_advice',
                'target_value',
                'expected_metric',
                'operator_id',
            ],
            'operation_intake_preflight_contract' => $operationIntakePreflight,
        ],
        'operation_intake_preflight_contract' => $operationIntakePreflight,
        'operation_closure_snapshot' => [
            'status' => (string)($closureSummary['status'] ?? ''),
            'statistics_status' => (string)($closureSummary['operation_statistics_status'] ?? 'unknown'),
            'statistics_loaded' => ($closureSummary['operation_statistics_loaded'] ?? false) === true,
            'execution_total_loaded' => ($closureSummary['operation_execution_total_loaded'] ?? false) === true,
            'roi_loaded' => ($closureSummary['operation_roi_loaded'] ?? false) === true,
            'operation_execution_total' => ($closureSummary['operation_execution_total_loaded'] ?? false) === true
                ? (int)($closureSummary['operation_execution_total'] ?? 0)
                : null,
            'operation_roi_ready' => ($closureSummary['operation_roi_loaded'] ?? false) === true
                ? (int)($closureSummary['operation_roi_ready'] ?? 0)
                : null,
        ],
    ];
}


/**
 * Route a revenue/AI-review evidence gap to the source that can actually
 * resolve it. Keep missing inputs explicit and never substitute another
 * metric merely because its collection path is already ready.
 *
 * @param array<string,mixed> $context
 * @return array{stage:string,target_entry:string,required_gate:string,next_action:string}
 */
function business_chain_revenue_gap_action_contract(
    string $evidenceCode,
    array $context = []
): array
{
    $evidenceCode = trim($evidenceCode);
    $businessDate = trim((string)($context['business_date'] ?? ''));
    $today = trim((string)($context['today'] ?? date('Y-m-d')));
    $captureSourceScope = trim((string)(
        $context['capture_source_scope'] ?? ''
    ));
    $historicalPmsRecollectionAvailable =
        $evidenceCode === 'dingdandao_pms_not_readback_verified'
        && (
            (string)($context['recovery_status'] ?? '')
                === 'historical_recollection_available'
            || (
                in_array(
                    $captureSourceScope,
                    ['today_only', 'historical_single_date'],
                    true
                )
                && $businessDate !== ''
                && $today !== ''
                && $businessDate < $today
            )
        );
    if ($historicalPmsRecollectionAvailable) {
        return [
            'stage' => 'pms_historical_collection',
            'target_entry' => 'pms-operating-data',
            'required_gate' => 'historical_pms_single_date_collection_and_readback_verified',
            'next_action' => 'recollect_authorized_historical_pms_single_date_and_exact_readback',
        ];
    }

    return match ($evidenceCode) {
        'dingdandao_pms_not_readback_verified' => [
            'stage' => 'pms_collection',
            'target_entry' => 'pms-operating-data',
            'required_gate' => 'pms_collection_claim_and_readback_verified',
            'next_action' => 'recollect_pms_with_current_contract_and_exact_readback',
        ],
        'ctrip_ota_not_readback_verified' => [
            'stage' => 'ota_collection',
            'target_entry' => 'ctrip-ebooking',
            'required_gate' => 'ctrip_target_date_revenue_facts_saved_and_readback_verified',
            'next_action' => 'recollect_ctrip_target_date_revenue_facts',
        ],
        'meituan_ota_not_readback_verified' => [
            'stage' => 'ota_collection',
            'target_entry' => 'meituan-ebooking',
            'required_gate' => 'meituan_target_date_revenue_facts_saved_and_readback_verified',
            'next_action' => 'recollect_meituan_target_date_revenue_facts',
        ],
        'floor_price_missing' => [
            'stage' => 'ai_decision_review_inputs',
            'target_entry' => 'agent-center',
            'required_gate' => 'room_type_floor_price_saved_and_readback_verified',
            'next_action' => 'configure_room_type_floor_price_before_ai_review',
        ],
        default => [
            'stage' => 'revenue_analysis',
            'target_entry' => 'revenue-ai-overview',
            'required_gate' => 'available_room_nights_or_verified_zero_room_nights',
            'next_action' => 'resolve_revenue_metric_gap_before_final_ai_advice',
        ],
    };
}

/**
 * @param array<string, mixed> $revenueToAiHandoff
 * @param array<string, mixed> $aiToOperationHandoff
 * @return array<string, mixed>
 */
function business_chain_ctrip_chain_action_queue(array $revenueToAiHandoff, array $aiToOperationHandoff): array
{
    $packet = is_array($revenueToAiHandoff['manual_review_packet'] ?? null)
        ? $revenueToAiHandoff['manual_review_packet']
        : [];
    $primaryAction = is_array($packet['primary_action'] ?? null) ? $packet['primary_action'] : [];
    $primaryBlocker = is_array($packet['primary_blocker'] ?? null) ? $packet['primary_blocker'] : [];
    $operationIntake = is_array($aiToOperationHandoff['operation_intake_packet'] ?? null)
        ? $aiToOperationHandoff['operation_intake_packet']
        : [];

    $revenueEvidenceCode = trim((string)($primaryBlocker['reason'] ?? ''));
    if ($revenueEvidenceCode === '') {
        $revenueEvidenceCode = trim((string)($primaryAction['reason'] ?? 'unknown_revenue_metric_gap'));
    }
    $revenueGapAction = business_chain_revenue_gap_action_contract($revenueEvidenceCode);
    $operationBlockedReason = trim((string)($operationIntake['candidate_blocked_reason'] ?? ''));

    $items = [
        [
            'priority' => 1,
            'code' => 'resolve_revenue_metric_gap',
            'stage' => $revenueGapAction['stage'],
            'status' => 'blocked',
            'blocking' => true,
            'source' => 'manual_review_packet',
            'evidence_code' => $revenueEvidenceCode,
            'target_entry' => $revenueGapAction['target_entry'],
            'required_gate' => $revenueGapAction['required_gate'],
            'next_action' => $revenueGapAction['next_action'],
        ],
        [
            'priority' => 2,
            'code' => 'approve_ai_manual_review',
            'stage' => 'ai_decision',
            'status' => 'blocked',
            'blocking' => true,
            'source' => 'manual_review_packet',
            'evidence_code' => (string)($packet['status'] ?? 'manual_review_status_unknown'),
            'target_entry' => 'agent-center',
            'required_gate' => 'operator_approves_ai_advice',
            'next_action' => 'connect_or_record_human_manual_review_before_execution',
        ],
        [
            'priority' => 3,
            'code' => 'create_operation_intent_after_review',
            'stage' => 'operation_management',
            'status' => 'blocked',
            'blocking' => true,
            'source' => 'operation_intake_packet',
            'evidence_code' => (string)($aiToOperationHandoff['status'] ?? 'operation_intake_not_approved'),
            'target_entry' => '/api/operation/execution-intents',
            'required_gate' => 'operator_creates_execution_intent',
            'next_action' => 'create_operation_execution_intent_only_after_human_review',
            'blocked_reason' => $operationBlockedReason !== '' ? $operationBlockedReason : 'manual_review_required',
        ],
        [
            'priority' => 4,
            'code' => 'attach_operation_execution_evidence',
            'stage' => 'operation_management',
            'status' => 'blocked',
            'blocking' => true,
            'source' => 'operation_execution',
            'evidence_code' => 'operation_execution.evidence_and_effect_review',
            'target_entry' => 'ops-track',
            'required_gate' => 'execution_evidence_attached_and_effect_review_completed',
            'next_action' => 'attach_real_execution_and_roi_evidence_after_operation_action',
        ],
    ];

    return [
        'status' => 'has_blocking_actions',
        'item_count' => count($items),
        'blocking_count' => count($items),
        'source_scope' => (string)($revenueToAiHandoff['source_scope'] ?? ''),
        'metric_scope' => 'ota_channel',
        'source_platforms' => business_chain_list($revenueToAiHandoff['source_platforms'] ?? []),
        'upstream_statuses' => [
            'revenue_to_ai_handoff' => (string)($revenueToAiHandoff['status'] ?? ''),
            'ai_to_operation_handoff' => (string)($aiToOperationHandoff['status'] ?? ''),
        ],
        'protected_boundary' => 'ota_channel_action_queue_no_auto_write_no_whole_hotel_truth',
        'forbidden_actions' => [
            'auto_write_ota',
            'auto_create_operation_execution_intent',
            'claim_ai_decision_final',
            'claim_operation_roi_ready',
            'promote_ota_scope_to_whole_hotel_truth',
        ],
        'items' => $items,
    ];
}

/**
 * Select the first unresolved gate in causal order. A completed P0 verifier
 * must not remain the advertised next action once downstream data or review
 * inputs are the real blocker.
 *
 * @param array<string, mixed> $p0Gate
 * @param array<string, mixed> $revenueFactLayer
 * @param array<string, mixed> $workflow
 * @return array<string, mixed>
 */
function business_chain_next_required_gate(
    array $p0Gate,
    array $revenueFactLayer,
    array $workflow
): array {
    $requiredP0Status = trim((string)($p0Gate['required_upstream_status'] ?? 'ready'));
    if ($requiredP0Status === '') {
        $requiredP0Status = 'ready';
    }
    $currentP0Status = trim((string)($p0Gate['current_upstream_status'] ?? $p0Gate['status'] ?? 'unknown'));
    $p0Ready = (string)($p0Gate['status'] ?? '') === 'ready'
        && $currentP0Status === $requiredP0Status;

    if (!$p0Ready) {
        return [
            'type' => 'p0_verifier',
            'code' => 'p0_downstream_gate',
            'command' => (string)($p0Gate['required_gate_command'] ?? ''),
            'evidence_code' => (string)($p0Gate['status'] ?? 'p0_not_ready'),
            'target_entry' => 'online-data:data-health',
            'required_gate' => 'p0_target_date_field_loop_ready',
            'required_status' => $requiredP0Status,
            'current_status' => $currentP0Status,
        ];
    }

    $uniqueGap = is_array($revenueFactLayer['unique_remaining_gap'] ?? null)
        ? $revenueFactLayer['unique_remaining_gap']
        : [];
    $uniqueGapCode = trim((string)($uniqueGap['code'] ?? ''));
    if ($uniqueGapCode !== '') {
        $pmsSource = is_array(
            $revenueFactLayer['sources']['dingdandao_pms']['source'] ?? null
        )
            ? $revenueFactLayer['sources']['dingdandao_pms']['source']
            : [];
        $action = business_chain_revenue_gap_action_contract(
            $uniqueGapCode,
            [
                'business_date' => $revenueFactLayer['business_date'] ?? '',
                'today' => date('Y-m-d'),
                'capture_source_scope' =>
                    $pmsSource['capture_source_scope'] ?? '',
                'recovery_status' => $uniqueGap['recovery_status'] ?? '',
            ]
        );
        return [
            'type' => 'data_gap',
            'code' => 'resolve_revenue_metric_gap',
            'command' => '',
            'stage' => $action['stage'],
            'evidence_code' => $uniqueGapCode,
            'target_entry' => $action['target_entry'],
            'required_gate' => $action['required_gate'],
            'required_status' => 'resolved',
            'current_status' => (string)($uniqueGap['status'] ?? 'missing'),
            'recovery_status' =>
                (string)($uniqueGap['recovery_status'] ?? ''),
            'live_recollection_allowed' =>
                ($uniqueGap['live_recollection_allowed'] ?? null),
            'historical_recollection_allowed' =>
                ($uniqueGap['historical_recollection_allowed'] ?? null),
            'next_action' => $action['next_action'],
        ];
    }

    $queue = is_array($workflow['ctrip_chain_action_queue'] ?? null)
        ? $workflow['ctrip_chain_action_queue']
        : [];
    foreach (business_chain_list($queue['items'] ?? []) as $item) {
        if (!is_array($item)
            || ($item['blocking'] ?? false) !== true
            || (string)($item['status'] ?? '') === 'ready') {
            continue;
        }
        $evidenceCode = trim((string)($item['evidence_code'] ?? ''));
        if ((string)($item['code'] ?? '') === 'resolve_revenue_metric_gap'
            && in_array($evidenceCode, ['', 'unknown_revenue_metric_gap'], true)) {
            continue;
        }

        return [
            'type' => 'workflow_action',
            'code' => (string)($item['code'] ?? 'resolve_business_loop_gap'),
            'command' => '',
            'stage' => (string)($item['stage'] ?? ''),
            'evidence_code' => $evidenceCode,
            'target_entry' => (string)($item['target_entry'] ?? ''),
            'required_gate' => (string)($item['required_gate'] ?? ''),
            'required_status' => 'ready',
            'current_status' => (string)($item['status'] ?? 'blocked'),
            'next_action' => (string)($item['next_action'] ?? ''),
        ];
    }

    return [
        'type' => 'complete',
        'code' => 'business_loop_ready',
        'command' => '',
        'evidence_code' => '',
        'target_entry' => '',
        'required_gate' => 'business_loop_closed',
        'required_status' => 'closed',
        'current_status' => 'closed',
    ];
}

/**
 * @param array<string, mixed> $revenue
 * @param array<string, mixed> $closure
 * @param array<string, mixed> $referenceScope
 * @return array<string, mixed>
 */
function business_chain_downstream_reference_workflow(array $revenue, array $closure, bool $skipP0, array $referenceScope = [], bool $p0Ready = false): array
{
    $actions = business_chain_list($revenue['actions'] ?? []);
    $metrics = is_array($revenue['metrics'] ?? null) ? $revenue['metrics'] : [];
    $targetReadyPlatforms = business_chain_list($referenceScope['target_ready_platforms'] ?? []);
    $targetBlockedPlatforms = business_chain_list($referenceScope['target_blocked_platforms'] ?? []);
    $diagnosisSourceChannels = $targetReadyPlatforms !== []
        ? $targetReadyPlatforms
        : business_chain_list($revenue['source_channels'] ?? []);
    $hasPartialTargetReadyScope = !$skipP0 && $targetReadyPlatforms !== [] && $targetBlockedPlatforms !== [];
    $hasScopedReadyScope = $p0Ready && $targetReadyPlatforms !== [] && $targetBlockedPlatforms === [];
    $referenceOnly = $skipP0 || $hasPartialTargetReadyScope;
    $revenueDiagnosis = [
        'status' => $skipP0
            ? 'reference_only'
            : ($hasPartialTargetReadyScope ? 'partial_reference_only' : (string)($revenue['data_status'] ?? 'unknown')),
        'data_status' => (string)($revenue['data_status'] ?? ''),
        'source_channels' => $diagnosisSourceChannels,
        'metric_scope' => 'ota_channel',
        'metrics' => [
            'ota_room_revenue' => business_chain_metric_digest($metrics['ota_room_revenue'] ?? []),
            'ota_room_nights' => business_chain_metric_digest($metrics['ota_room_nights'] ?? []),
            'ota_adr' => business_chain_metric_digest($metrics['ota_adr'] ?? []),
            'ota_contribution_revpar' => business_chain_metric_digest($metrics['ota_contribution_revpar'] ?? []),
            'data_completeness' => business_chain_metric_digest($metrics['data_completeness'] ?? []),
        ],
        'missing_datasets' => $revenue['missing_datasets'] ?? [],
        'quality_issues' => $revenue['quality_issues'] ?? [],
    ];
    $aiAdviceDraft = [
        'status' => $hasScopedReadyScope ? 'ready_for_manual_review' : ($referenceOnly ? 'draft_reference_only' : 'requires_p0'),
        'manual_review_required' => true,
        'auto_write_ota' => false,
        'action_count' => count($actions),
        'actions' => $actions,
        'next_gate' => $hasScopedReadyScope ? 'manual_review_before_execution' : 'manual_review_after_p0_ready_or_reference_review_only',
    ];
    $revenueToAiHandoff = business_chain_revenue_to_ai_handoff($referenceScope, $revenueDiagnosis, $aiAdviceDraft, $p0Ready);
    $aiToOperationHandoff = business_chain_ai_to_operation_handoff($revenueToAiHandoff, $closure, $referenceScope);
    $ctripChainActionQueue = business_chain_ctrip_chain_action_queue($revenueToAiHandoff, $aiToOperationHandoff);

    return [
        'status' => $skipP0
            ? 'reference_workflow_ready_not_claimable'
            : ($hasScopedReadyScope ? 'scoped_workflow_ready_for_manual_review' : ($hasPartialTargetReadyScope ? 'partial_reference_workflow_not_claimable' : 'p0_required')),
        'claim_allowed' => false,
        'source_policy' => $skipP0
            ? 'use_reference_ota_rows_for_diagnosis_only'
            : ($hasScopedReadyScope
                ? 'use_scoped_target_date_ota_rows_for_ai_review'
                : ($hasPartialTargetReadyScope
                ? 'use_target_date_ready_platform_rows_for_diagnosis_only'
                : 'requires_target_date_p0_ota_rows')),
        'evidence_scope' => $referenceScope,
        'forbidden_claims' => [
            'auto_apply_ai_advice',
            'operation_execution_completed',
            'roi_ready',
            'whole_hotel_truth_from_ota_only',
        ],
        'revenue_diagnosis' => $revenueDiagnosis,
        'ai_advice_draft' => $aiAdviceDraft,
        'revenue_to_ai_handoff' => $revenueToAiHandoff,
        'ai_to_operation_handoff' => $aiToOperationHandoff,
        'ctrip_chain_action_queue' => $ctripChainActionQueue,
        'operation_execution_draft' => [
            'status' => 'draft_not_written',
            'persisted' => false,
            'source_scope' => 'operation_execution_candidate_from_reference_ai_advice',
            'intake_status' => (string)($aiToOperationHandoff['status'] ?? ''),
            'intake_target_entry' => (string)($aiToOperationHandoff['target_entry'] ?? ''),
            'can_create_operation_execution' => false,
            'statistics_status' => (string)($closure['summary']['operation_statistics_status'] ?? 'unknown'),
            'statistics_loaded' => ($closure['summary']['operation_statistics_loaded'] ?? false) === true,
            'execution_total_loaded' => ($closure['summary']['operation_execution_total_loaded'] ?? false) === true,
            'roi_loaded' => ($closure['summary']['operation_roi_loaded'] ?? false) === true,
            'operation_execution_total' => ($closure['summary']['operation_execution_total_loaded'] ?? false) === true
                ? (int)($closure['summary']['operation_execution_total'] ?? 0)
                : null,
            'operation_roi_ready' => ($closure['summary']['operation_roi_loaded'] ?? false) === true
                ? (int)($closure['summary']['operation_roi_ready'] ?? 0)
                : null,
            'next_actions' => [
                'create_execution_intent_after_human_review',
                'attach_execution_evidence_after_real_action',
                'review_roi_only_after_target_date_p0_ready',
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function business_chain_metric_digest(mixed $metric): array
{
    if (!is_array($metric)) {
        return ['status' => 'not_loaded'];
    }
    return [
        'key' => (string)($metric['key'] ?? ''),
        'label' => (string)($metric['label'] ?? ''),
        'value' => $metric['value'] ?? null,
        'unit' => (string)($metric['unit'] ?? ''),
        'status' => (string)($metric['status'] ?? ''),
        'reason' => (string)($metric['reason'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $revenue
 * @return array<int, array<string, mixed>>
 */
function business_chain_downstream_signals(array $revenue, array $executionFlow = []): array
{
    $actionCount = count(business_chain_list($revenue['actions'] ?? []));
    $executionSummary = is_array($executionFlow['summary'] ?? null) ? $executionFlow['summary'] : [];
    $stageCounts = is_array($executionSummary['stage_counts'] ?? null) ? $executionSummary['stage_counts'] : [];
    $executionDataGaps = business_chain_list($executionFlow['data_gaps'] ?? []);
    return [
        [
            'key' => 'ai_daily_report',
            'label' => 'AI经营日报 / AI决策',
            'source_scope' => 'revenue_ai_overview_reference_only',
            'record_count' => $actionCount,
            'linked_execution_count' => 0,
            'reviewed_count' => 0,
            'roi_ready_count' => 0,
            'data_gaps' => [
                ['code' => 'p0_ota_gate_not_ready', 'message' => 'P0 OTA target-date field loop is not ready.'],
            ],
        ],
        [
            'key' => 'revenue_pricing',
            'label' => '收益调价建议',
            'source_scope' => 'revenue_ai_overview_reference_only',
            'record_count' => $actionCount,
            'linked_execution_count' => 0,
            'reviewed_count' => 0,
            'roi_ready_count' => 0,
            'data_gaps' => [
                ['code' => 'p0_ota_gate_not_ready', 'message' => 'Revenue advice remains reference-only until target-date OTA field evidence is ready.'],
            ],
        ],
        [
            'key' => 'operation_execution',
            'label' => '运营执行闭环',
            'source_scope' => 'read_existing_operation_execution_records',
            'table_status' => (string)($executionFlow['data_status'] ?? 'unknown'),
            'record_count' => (int)($executionSummary['total'] ?? 0),
            'linked_execution_count' => (int)($executionSummary['total'] ?? 0),
            'approved_count' => (int)($executionSummary['approved'] ?? 0),
            'executed_count' => (int)($executionSummary['executed'] ?? 0),
            'evidence_ready_count' => (int)($executionSummary['evidence_ready'] ?? 0),
            'reviewed_count' => (int)($stageCounts['reviewed'] ?? 0),
            'roi_ready_count' => (int)($executionSummary['roi_ready'] ?? 0),
            'blocked_count' => (int)($stageCounts['blocked'] ?? 0),
            'data_gaps' => $executionDataGaps,
        ],
    ];
}

/**
 * Keep operation evidence on the same target date and OTA platform scope as the report.
 *
 * @param array<string, mixed> $executionFlow
 * @param array<int, string> $platforms
 * @return array<string, mixed>
 */
function business_chain_scope_execution_flow(
    array $executionFlow,
    string $targetDate,
    array $platforms,
    ?int $systemHotelId
): array
{
    $platforms = array_values(array_unique(array_map(static fn(string $item): string => strtolower(trim($item)), $platforms)));
    $executionFlow['scope'] = [
        'target_date' => $targetDate,
        'platforms' => $platforms,
        'system_hotel_id' => $systemHotelId,
        'policy' => $systemHotelId !== null
            ? 'same_hotel_same_target_date_same_ota_platform'
            : 'single_system_hotel_scope_required',
        'query_applied_before_limit' => $systemHotelId !== null,
        'scope_source' => $systemHotelId !== null
            ? 'operation_execution_intents_query'
            : 'operation_query_not_run_without_hotel_scope',
    ];

    return $executionFlow;
}

/**
 * @param array<string, mixed> $p0Gate
 * @param array<string, mixed> $workflow
 * @param array<int, string> $platforms
 * @return array<string, mixed>
 */
function business_chain_focused_ota_revenue_ai_chain(array $p0Gate, array $workflow, array $platforms): array
{
    $revenueDiagnosis = is_array($workflow['revenue_diagnosis'] ?? null) ? $workflow['revenue_diagnosis'] : [];
    $handoff = is_array($workflow['revenue_to_ai_handoff'] ?? null) ? $workflow['revenue_to_ai_handoff'] : [];
    $otaReady = (string)($p0Gate['status'] ?? '') === 'ready';
    $revenueReady = business_chain_list($revenueDiagnosis['source_channels'] ?? []) !== []
        && !in_array((string)($revenueDiagnosis['status'] ?? ''), ['', 'unknown'], true);
    $aiReviewReady = (string)($handoff['status'] ?? '') === 'handoff_ready_for_manual_review';
    $ready = $otaReady && $revenueReady && $aiReviewReady;

    return [
        'key' => 'ota_revenue_ai',
        'status' => $ready ? 'scoped_ai_review_ready' : 'not_ready',
        'claim_allowed' => $ready,
        'claim_policy' => 'platform_scoped_ota_to_ai_review_only_not_operation_or_investment',
        'platforms' => array_values($platforms),
        'metric_scope' => 'ota_channel',
        'stages' => [
            [
                'key' => 'ota_data',
                'status' => $otaReady ? 'ready' : 'blocked',
                'evidence' => [
                    'p0_gate_status' => (string)($p0Gate['status'] ?? ''),
                    'required_gate_command' => (string)($p0Gate['required_gate_command'] ?? ''),
                ],
            ],
            [
                'key' => 'revenue_analysis',
                'status' => (string)($revenueDiagnosis['status'] ?? 'unknown'),
                'evidence' => [
                    'source_channels' => business_chain_list($revenueDiagnosis['source_channels'] ?? []),
                    'metric_keys' => array_keys(is_array($revenueDiagnosis['metrics'] ?? null) ? $revenueDiagnosis['metrics'] : []),
                ],
            ],
            [
                'key' => 'ai_decision_review',
                'status' => (string)($handoff['status'] ?? 'unknown'),
                'evidence' => [
                    'ai_draft_status' => (string)($handoff['ai_draft_status'] ?? ''),
                    'ai_action_count' => (int)($handoff['ai_action_count'] ?? 0),
                    'can_auto_write_ota' => (bool)($handoff['can_auto_write_ota'] ?? false),
                    'can_create_operation_execution' => (bool)($handoff['can_create_operation_execution'] ?? false),
                ],
            ],
        ],
        'forbidden_promotions' => [
            'whole_hotel_truth_from_ota_only',
            'ai_decision_final',
            'operation_execution_completed',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function business_chain_report(array $options): array
{
    $targetDate = (string)$options['date'];
    $systemHotelId = $options['system_hotel_id'];
    $expectedHotelName = trim((string)($options['expected_hotel_name'] ?? ''));
    $limit = (int)$options['limit'];
    $skipP0 = (bool)$options['skip_p0'];
    $sources = $options['platforms'];
    $targetDatasets = [];
    $referenceDatasets = [];
    $sourceRows = [];
    foreach ($sources as $source) {
        $target = business_chain_filter_dataset_platforms(
            business_chain_build_dataset_for($source, $targetDate, $systemHotelId, $limit),
            [$source]
        );
        $latestDate = business_chain_latest_date($source, $systemHotelId);
        $referenceDate = $target['status'] === 'ready'
            ? $targetDate
            : ($skipP0 ? $latestDate : '');
        $reference = $target;
        if ($target['status'] !== 'ready' && $skipP0 && $latestDate !== '') {
            $reference = business_chain_filter_dataset_platforms(
                business_chain_build_dataset_for($source, $latestDate, $systemHotelId, $limit),
                [$source]
            );
        }
        $targetDatasets[$source] = $target;
        $referenceDatasets[$source] = $reference;
        $targetCounts = business_chain_fact_counts($target);
        $referenceCounts = business_chain_fact_counts($reference);
        $sourceRows[] = [
            'source' => $source,
            'target_date' => $targetDate,
            'target_status' => business_chain_source_evidence_status($target),
            'target_dataset_status' => $target['status'] ?? 'empty',
            'target_counts' => $targetCounts,
            'reference_date' => $referenceDate,
            'reference_status' => business_chain_source_evidence_status($reference),
            'reference_dataset_status' => $reference['status'] ?? 'empty',
            'reference_counts' => $referenceCounts,
            'reference_only' => $referenceDate !== '' && $referenceDate !== $targetDate,
        ];
    }

    $targetDataset = business_chain_merge_datasets($targetDatasets);
    $referenceDataset = business_chain_merge_datasets($referenceDatasets);
    $targetDataset = business_chain_filter_dataset_platforms($targetDataset, $sources);
    $referenceDataset = business_chain_filter_dataset_platforms($referenceDataset, $sources);
    $skipActive = $skipP0 && $targetDataset['status'] !== 'ready' && $referenceDataset['status'] === 'ready';
    $p0ExecutionPlan = business_chain_p0_execution_plan($targetDate, $systemHotelId, $options['skip_platforms'], $sources);
    $systemHotelIdentity = business_chain_system_hotel_identity($systemHotelId, $expectedHotelName);
    $p0ExecutionPlan['scope']['system_hotel_identity'] = $systemHotelIdentity;
    $p0Ready = business_chain_p0_execution_plan_ready($p0ExecutionPlan)
        && (string)($systemHotelIdentity['status'] ?? '') === 'ready';
    $p0ExecutionPlan['completion_gate']['global_verifier_status'] = (string)($p0ExecutionPlan['status'] ?? 'unknown');
    $p0ExecutionPlan['completion_gate']['selected_scope_status'] = $p0Ready ? 'ready' : 'blocked';
    $p0ExecutionPlan['completion_gate']['selected_scope_policy'] = 'same_hotel_same_target_date_requested_platforms_traffic_readback';
    $p0ExecutionPlan['completion_gate']['system_hotel_identity_status'] = (string)($systemHotelIdentity['status'] ?? 'missing');
    $p0ExecutionPlan['completion_gate']['nonblocking_global_issues_retained'] = $p0Ready
        && (string)($p0ExecutionPlan['status'] ?? '') !== 'passed';
    $sourceRows = business_chain_attach_source_date_quality($sourceRows, $p0ExecutionPlan);
    $p0Gate = business_chain_gate($targetDate, $systemHotelId, $skipActive, $options['skip_platforms'], $sources, $p0Ready);
    $downstreamReferenceScope = business_chain_downstream_reference_scope($sourceRows, $options['skip_platforms']);
    $diagnosisPlatforms = business_chain_list($downstreamReferenceScope['target_ready_platforms'] ?? []);
    $diagnosisReferenceDataset = $referenceDataset;
    $diagnosisReferenceDatasets = $referenceDatasets;
    if ($diagnosisPlatforms !== [] && business_chain_list($downstreamReferenceScope['target_blocked_platforms'] ?? []) !== []) {
        $diagnosisReferenceDataset = business_chain_filter_dataset_platforms($referenceDataset, $diagnosisPlatforms);
        $diagnosisReferenceDatasets = array_intersect_key(
            $referenceDatasets,
            array_fill_keys(array_values(array_map('strval', $diagnosisPlatforms)), true)
        );
    }
    $diagnosisEnabledChannels = $diagnosisPlatforms !== [] ? $diagnosisPlatforms : $sources;

    $revenueFactLayer = $systemHotelId !== null
        ? (new RevenueFactLayerService())->build(
            $systemHotelId,
            $targetDate
        )
        : [];
    $revenue = (new RevenueAiOverviewService())->buildOverviewFromDataset(
        $diagnosisReferenceDataset,
        $diagnosisReferenceDatasets,
        [],
        [
            'business_date' => $targetDate,
            'hotel_id' => $systemHotelId,
            'p0_downstream_gate' => $p0Gate,
            'enabled_channels' => $diagnosisEnabledChannels,
            'revenue_fact_layer' => $revenueFactLayer,
        ]
    );
    $operationService = new OperationManagementService();
    $executionFlow = $systemHotelId !== null
        ? $operationService->executionFlow(
            [$systemHotelId],
            $systemHotelId,
            [
                'target_date' => $targetDate,
                'platforms' => $sources,
                'limit' => 500,
            ]
        )
        : [
            'summary' => $operationService->buildExecutionFlowSummary([]),
            'stages' => [],
            'list' => [],
            'data_status' => 'pending',
            'data_gaps' => [[
                'code' => 'operation_system_hotel_scope_missing',
                'message' => 'system_hotel_id is required before loading hotel-scoped operation statistics',
            ]],
            'matched_total' => null,
            'returned_count' => 0,
            'truncated' => false,
            'statistics' => [
                'execution_total_loaded' => false,
                'task_status_loaded' => false,
                'evidence_loaded' => false,
                'roi_loaded' => false,
            ],
        ];
    $executionFlow = business_chain_scope_execution_flow($executionFlow, $targetDate, $sources, $systemHotelId);
    $executionSummary = is_array($executionFlow['summary'] ?? null) ? $executionFlow['summary'] : [];
    $executionDataGaps = business_chain_list($executionFlow['data_gaps'] ?? []);
    $closure = (new BusinessClosureOverviewService())->buildOverviewFromSignals(
        business_chain_downstream_signals($revenue, $executionFlow),
        $executionSummary,
        $executionDataGaps,
        $p0Gate
    );
    $operationStatistics = is_array($executionFlow['statistics'] ?? null) ? $executionFlow['statistics'] : [];
    $operationExecutionTotalLoaded = ($operationStatistics['execution_total_loaded'] ?? false) === true;
    $operationRoiLoaded = ($operationStatistics['roi_loaded'] ?? false) === true;
    $operationStatisticsLoaded = $operationExecutionTotalLoaded && $operationRoiLoaded;
    $closure['summary']['operation_statistics_status'] = (string)($executionFlow['data_status'] ?? 'unknown');
    $closure['summary']['operation_statistics_loaded'] = $operationStatisticsLoaded;
    $closure['summary']['operation_execution_total_loaded'] = $operationExecutionTotalLoaded;
    $closure['summary']['operation_roi_loaded'] = $operationRoiLoaded;
    if ($operationExecutionTotalLoaded) {
        $closure['summary']['operation_execution_total'] = (int)($executionFlow['matched_total'] ?? 0);
    } else {
        $closure['summary']['operation_execution_total'] = null;
    }
    if (!$operationRoiLoaded) {
        $closure['summary']['operation_roi_ready'] = null;
    }
    $downstreamReferenceWorkflow = business_chain_downstream_reference_workflow($revenue, $closure, $skipActive, $downstreamReferenceScope, $p0Ready);
    $focusedChain = business_chain_focused_ota_revenue_ai_chain($p0Gate, $downstreamReferenceWorkflow, $sources);
    $stages = business_chain_stage_rows($referenceDataset, $revenue, $closure, $skipActive);
    $nextRequiredGate = business_chain_next_required_gate(
        $p0Gate,
        $revenueFactLayer,
        $downstreamReferenceWorkflow
    );
    $claimAllowed = count(array_filter($stages, static fn(array $row): bool => ($row['claim_allowed'] ?? false) !== true)) === 0;
    $stageMap = [];
    foreach ($stages as $stage) {
        $stageMap[(string)($stage['key'] ?? '')] = $stage;
    }
    $runtimeDataReady = ($stageMap['ota_data']['claim_allowed'] ?? false) === true
        && ($stageMap['revenue_analysis']['claim_allowed'] ?? false) === true;

    return [
        'generated_at' => date('c'),
        'status' => $claimAllowed ? 'closed' : ($skipActive ? 'skip_p0_reference_only' : 'incomplete'),
        'claim_allowed' => $claimAllowed,
        'readiness' => [
            'code_contract_ready' => null,
            'code_contract_status' => 'not_evaluated_by_runtime_report',
            'runtime_data_ready' => $runtimeDataReady,
            'business_loop_ready' => $claimAllowed,
            'release_ready' => null,
            'release_status' => 'not_evaluated_by_runtime_report',
        ],
        'mode' => $skipActive ? 'skip_p0_reference_only' : 'p0_required',
        'scope' => [
            'target_date' => $targetDate,
            'system_hotel_id' => $systemHotelId,
            'metric_scope' => 'ota_channel',
            'fact_layer_metric_scope' => 'three_source_layered',
            'platforms' => $sources,
            'source_policy' => $skipActive
                ? 'read_existing_latest_available_ota_rows_reference_only'
                : 'read_existing_target_date_ota_rows',
        ],
        'skip_p0_policy' => [
            'requested' => $skipP0,
            'active' => $skipActive,
            'reason' => $skipActive ? 'target_date_p0_rows_missing_but_latest_real_ota_rows_exist' : '',
            'forbidden_claims' => [
                'target_date_closure',
                'whole_hotel_operating_truth',
                'ai_decision_final',
                'operation_closure_complete',
            ],
        ],
        'source_rows' => $sourceRows,
        'p0_downstream_gate' => $p0Gate,
        'p0_execution_plan' => $p0ExecutionPlan,
        'operator_skip_platforms' => $options['skip_platforms'],
        'focused_chain' => $focusedChain,
        'three_source_fact_layer' => $revenueFactLayer,
        'downstream_reference_workflow' => $downstreamReferenceWorkflow,
        'stages' => $stages,
        'revenue_ai_summary' => [
            'data_status' => $revenue['data_status'] ?? '',
            'source_channels' => $revenue['source_channels'] ?? [],
            'missing_datasets' => $revenue['missing_datasets'] ?? [],
            'pricing_status' => $revenue['pricing_readiness']['status'] ?? '',
        ],
        'operation_summary' => [
            'status' => $closure['summary']['status'] ?? '',
            'statistics_status' => $closure['summary']['operation_statistics_status'] ?? 'unknown',
            'statistics_loaded' => ($closure['summary']['operation_statistics_loaded'] ?? false) === true,
            'execution_total_loaded' => ($closure['summary']['operation_execution_total_loaded'] ?? false) === true,
            'roi_loaded' => ($closure['summary']['operation_roi_loaded'] ?? false) === true,
            'scope' => $executionFlow['scope'] ?? [],
            'matched_total' => $executionFlow['matched_total'] ?? null,
            'returned_count' => (int)($executionFlow['returned_count'] ?? 0),
            'truncated' => ($executionFlow['truncated'] ?? false) === true,
            'operation_execution_total' => ($closure['summary']['operation_execution_total_loaded'] ?? false) === true
                ? (int)($closure['summary']['operation_execution_total'] ?? 0)
                : null,
            'operation_roi_ready' => ($closure['summary']['operation_roi_loaded'] ?? false) === true
                ? (int)($closure['summary']['operation_roi_ready'] ?? 0)
                : null,
        ],
        'next_required_gate' => $nextRequiredGate,
    ];
}

/**
 * @param array<string, mixed> $report
 */
function business_chain_markdown(array $report): string
{
    $lines = [];
    $lines[] = '# Business Chain Status';
    $lines[] = '';
    $lines[] = '- status: `' . ($report['status'] ?? '') . '`';
    $lines[] = '- claim_allowed: `' . (($report['claim_allowed'] ?? false) ? 'true' : 'false') . '`';
    $lines[] = '- mode: `' . ($report['mode'] ?? '') . '`';
    $lines[] = '- target_date: `' . ($report['scope']['target_date'] ?? '') . '`';
    $readiness = is_array($report['readiness'] ?? null) ? $report['readiness'] : [];
    $lines[] = '- code_contract_ready: `' . (($readiness['code_contract_ready'] ?? null) === null ? 'not_evaluated' : (($readiness['code_contract_ready'] ?? false) ? 'true' : 'false')) . '`';
    $lines[] = '- runtime_data_ready: `' . (($readiness['runtime_data_ready'] ?? false) ? 'true' : 'false') . '`';
    $lines[] = '- business_loop_ready: `' . (($readiness['business_loop_ready'] ?? false) ? 'true' : 'false') . '`';
    $lines[] = '- release_ready: `' . (($readiness['release_ready'] ?? null) === null ? 'not_evaluated' : (($readiness['release_ready'] ?? false) ? 'true' : 'false')) . '`';
    $focusedChain = is_array($report['focused_chain'] ?? null) ? $report['focused_chain'] : [];
    if ($focusedChain !== []) {
        $lines[] = '- focused_chain: `' . ($focusedChain['status'] ?? '') . '`, platforms=`' . implode(',', business_chain_list($focusedChain['platforms'] ?? [])) . '`';
    }
    $factLayer = is_array($report['three_source_fact_layer'] ?? null)
        ? $report['three_source_fact_layer']
        : [];
    if ($factLayer !== []) {
        $hotel = is_array($factLayer['hotel'] ?? null)
            ? $factLayer['hotel']
            : [];
        $lines[] = '- three_source_fact_layer: `' . ($factLayer['revenue_analysis_status'] ?? '') . '`, tenant=`' . ($hotel['tenant_id'] ?? '') . '`, hotel=`' . ($hotel['system_hotel_id'] ?? '') . '`, date=`' . ($factLayer['business_date'] ?? '') . '`';
        $sourceCompleteness = is_array($factLayer['source_completeness'] ?? null)
            ? $factLayer['source_completeness']
            : [];
        $sourceSummary = [];
        foreach ($sourceCompleteness as $source => $status) {
            $sourceSummary[] = (string)$source . ':' . (string)$status;
        }
        $lines[] = '- three_source_readback: `' . implode(',', $sourceSummary) . '`';
        $uniqueGap = is_array($factLayer['unique_remaining_gap'] ?? null)
            ? $factLayer['unique_remaining_gap']
            : [];
        $lines[] = '- fact_layer_unique_gap: `' . ($uniqueGap['code'] ?? 'none') . '`';
        $lines[] = '- fact_layer_scope_policy: `PMS=whole_hotel_accommodation, Ctrip/Meituan=ota_channel, PMS+OTA revenue addition=forbidden`';
    }
    $lines[] = '';
    $lines[] = '| Stage | Status | Claim allowed |';
    $lines[] = '|---|---:|---:|';
    foreach (business_chain_list($report['stages'] ?? []) as $stage) {
        if (!is_array($stage)) {
            continue;
        }
        $lines[] = '| ' . ($stage['label'] ?? $stage['key'] ?? '') . ' | `' . ($stage['status'] ?? '') . '` | `' . (($stage['claim_allowed'] ?? false) ? 'true' : 'false') . '` |';
    }
    $lines[] = '';
    $nextGate = is_array($report['next_required_gate'] ?? null)
        ? $report['next_required_gate']
        : [];
    if (trim((string)($nextGate['command'] ?? '')) !== '') {
        $lines[] = 'Next gate: `' . ($nextGate['command'] ?? '') . '`';
    } else {
        $lines[] = 'Next gate: action=`' . ($nextGate['code'] ?? '')
            . '`, stage=`' . ($nextGate['stage'] ?? '')
            . '`, evidence=`' . ($nextGate['evidence_code'] ?? '')
            . '`, target=`' . ($nextGate['target_entry'] ?? '')
            . '`, required_gate=`' . ($nextGate['required_gate'] ?? '') . '`';
    }
    $p0Plan = is_array($report['p0_execution_plan'] ?? null) ? $report['p0_execution_plan'] : [];
    $operatorSequence = business_chain_list($p0Plan['operator_sequence'] ?? []);
    if ($p0Plan !== []) {
        $lines[] = '';
        $lines[] = '## P0 Execution Plan';
        $completionGate = is_array($p0Plan['completion_gate'] ?? null)
            ? $p0Plan['completion_gate']
            : [];
        $lines[] = '- global_verifier_status: `' . ($completionGate['global_verifier_status'] ?? $p0Plan['status'] ?? 'unknown') . '`';
        $lines[] = '- selected_scope_status: `' . ($completionGate['selected_scope_status'] ?? 'unknown')
            . '`, hotel_identity=`' . ($completionGate['system_hotel_identity_status'] ?? 'unknown')
            . '`, retained_global_issues=`'
            . (($completionGate['nonblocking_global_issues_retained'] ?? false) ? 'true' : 'false') . '`';
        foreach ($operatorSequence as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string)($item['type'] ?? '');
            $platform = (string)($item['platform'] ?? '');
            $hotel = (string)($item['system_hotel_id'] ?? '');
            $source = (string)($item['data_source_id'] ?? '');
            if ($type === 'manual_login') {
                $lines[] = '- login `' . $platform . '` hotel `' . $hotel . '` source `' . $source . '`: `' . ($item['entry'] ?? '') . '`';
            } elseif ($type === 'after_login_sync') {
                $lines[] = '- sync `' . $platform . '` hotel `' . $hotel . '` source `' . $source . '`: `' . ($item['entry'] ?? '') . '`';
            } elseif ($type === 'already_ready') {
                $lines[] = '- already_ready `' . $platform . '` hotel `' . $hotel . '` source `' . $source . '`: `' . ($item['status'] ?? '') . '`';
            } elseif ($type === 'operator_skip') {
                $lines[] = '- operator_skip `' . $platform . '` hotel `' . $hotel . '` source `' . $source . '`: `' . ($item['status'] ?? '') . '`';
            } elseif ($type === 'single_scope_verifier') {
                $lines[] = '- verify `' . $platform . '` hotel `' . $hotel . '` source `' . $source . '`: `' . ($item['command'] ?? '') . '`';
            }
        }
    }
    $workflow = is_array($report['downstream_reference_workflow'] ?? null) ? $report['downstream_reference_workflow'] : [];
    if ($workflow !== []) {
        $lines[] = '';
        $lines[] = '## Downstream Reference Workflow';
        $scope = is_array($workflow['evidence_scope'] ?? null) ? $workflow['evidence_scope'] : [];
        $lines[] = '- status: `' . ($workflow['status'] ?? '') . '`';
        $lines[] = '- source_policy: `' . ($workflow['source_policy'] ?? '') . '`';
        $lines[] = '- target_ready_platforms: `' . implode(',', business_chain_list($scope['target_ready_platforms'] ?? [])) . '`';
        $lines[] = '- operator_skip_platforms: `' . implode(',', business_chain_list($scope['operator_skip_platforms'] ?? [])) . '`';
        $lines[] = '- revenue_diagnosis: `' . ($workflow['revenue_diagnosis']['status'] ?? '') . '`';
        $lines[] = '- ai_advice_draft: `' . ($workflow['ai_advice_draft']['status'] ?? '') . '`, action_count=`' . (int)($workflow['ai_advice_draft']['action_count'] ?? 0) . '`';
        $handoff = is_array($workflow['revenue_to_ai_handoff'] ?? null) ? $workflow['revenue_to_ai_handoff'] : [];
        $lines[] = '- revenue_to_ai_handoff: `' . ($handoff['status'] ?? '') . '`, source_scope=`' . ($handoff['source_scope'] ?? '') . '`';
        $packet = is_array($handoff['manual_review_packet'] ?? null) ? $handoff['manual_review_packet'] : [];
        if ($packet !== []) {
            $primaryAction = is_array($packet['primary_action'] ?? null) ? $packet['primary_action'] : [];
            $primaryBlocker = is_array($packet['primary_blocker'] ?? null) ? $packet['primary_blocker'] : [];
            $lines[] = '- manual_review_packet: `' . ($packet['status'] ?? '') . '`, mode=`' . ($packet['review_mode'] ?? '') . '`, primary_action=`' . ($primaryAction['reason'] ?? '') . '`, primary_blocker=`' . ($primaryBlocker['reason'] ?? '') . '`';
            $nextBlockers = [];
            foreach (business_chain_list($packet['blockers'] ?? []) as $blocker) {
                if (!is_array($blocker)) {
                    continue;
                }
                $reason = (string)($blocker['reason'] ?? '');
                if ($reason === '') {
                    continue;
                }
                $nextBlockers[] = $reason;
                if (count($nextBlockers) >= 3) {
                    break;
                }
            }
            if ($nextBlockers !== []) {
                $lines[] = '- manual_review_next_blockers: `' . implode(',', $nextBlockers) . '`';
            }
            $forbiddenActions = array_map(
                static fn(mixed $item): string => (string)$item,
                business_chain_list($packet['forbidden_actions'] ?? [])
            );
            if ($forbiddenActions !== []) {
                $lines[] = '- manual_review_forbidden_actions: `' . implode(',', $forbiddenActions) . '`';
            }
            $reviewContract = is_array($packet['ai_decision_review_contract'] ?? null) ? $packet['ai_decision_review_contract'] : [];
            if ($reviewContract !== []) {
                $lines[] = '- ai_decision_review_contract: `' . ($reviewContract['status'] ?? '') . '`, approval_allowed=`' . (($reviewContract['approval_allowed'] ?? false) ? 'true' : 'false') . '`, operation_intake_allowed=`' . (($reviewContract['operation_intake_allowed'] ?? false) ? 'true' : 'false') . '`, required_inputs=`' . (int)($reviewContract['required_input_count'] ?? 0) . '`';
                $requiredInputSummary = [];
                foreach (business_chain_list($reviewContract['required_input_items'] ?? []) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $requiredInputSummary[] = (string)($item['code'] ?? '') . ':' . (string)($item['evidence_code'] ?? '');
                    if (count($requiredInputSummary) >= 4) {
                        break;
                    }
                }
                if ($requiredInputSummary !== []) {
                    $lines[] = '- ai_decision_required_inputs: `' . implode(',', $requiredInputSummary) . '`';
                }
                $allowedOutputSummary = [];
                foreach (business_chain_list($reviewContract['allowed_decision_outputs'] ?? []) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $allowedOutputSummary[] = (string)($item['code'] ?? '') . ':' . (($item['allowed'] ?? false) ? 'allowed' : 'blocked');
                }
                if ($allowedOutputSummary !== []) {
                    $lines[] = '- ai_decision_allowed_outputs: `' . implode(',', $allowedOutputSummary) . '`';
                }
                $resolutionPlan = is_array($reviewContract['resolution_plan'] ?? null) ? $reviewContract['resolution_plan'] : [];
                if ($resolutionPlan !== []) {
                    $lines[] = '- ai_decision_resolution_plan: `' . ($resolutionPlan['status'] ?? '') . '`, items=`' . (int)($resolutionPlan['item_count'] ?? 0) . '`, pending=`' . (int)($resolutionPlan['pending_count'] ?? 0) . '`, gate=`' . ($resolutionPlan['post_resolution_gate'] ?? '') . '`';
                    $resolutionSummary = [];
                    foreach (business_chain_list($resolutionPlan['items'] ?? []) as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $resolutionSummary[] = (string)($item['code'] ?? '') . ':' . (string)($item['resolution_action'] ?? '');
                        if (count($resolutionSummary) >= 4) {
                            break;
                        }
                    }
                    if ($resolutionSummary !== []) {
                        $lines[] = '- ai_decision_resolution_items: `' . implode(',', $resolutionSummary) . '`';
                    }
                }
            }
        }
        $aiToOperation = is_array($workflow['ai_to_operation_handoff'] ?? null) ? $workflow['ai_to_operation_handoff'] : [];
        if ($aiToOperation !== []) {
            $lines[] = '- ai_to_operation_handoff: `' . ($aiToOperation['status'] ?? '') . '`, target=`' . ($aiToOperation['target_entry'] ?? '') . '`, persisted=`' . (($aiToOperation['persisted'] ?? false) ? 'true' : 'false') . '`, can_create=`' . (($aiToOperation['can_create_operation_execution'] ?? false) ? 'true' : 'false') . '`';
            $intake = is_array($aiToOperation['operation_intake_packet'] ?? null) ? $aiToOperation['operation_intake_packet'] : [];
            if ($intake !== []) {
                $lines[] = '- operation_intake_packet: `' . ($intake['status'] ?? '') . '`, source_module=`' . ($intake['candidate_source_module'] ?? '') . '`, object_type=`' . ($intake['candidate_object_type'] ?? '') . '`, blocked_reason=`' . ($intake['candidate_blocked_reason'] ?? '') . '`';
                $preflight = is_array($intake['operation_intake_preflight_contract'] ?? null) ? $intake['operation_intake_preflight_contract'] : [];
                if ($preflight !== []) {
                    $lines[] = '- operation_intake_preflight_contract: `' . ($preflight['status'] ?? '') . '`, create_allowed=`' . (($preflight['create_allowed'] ?? false) ? 'true' : 'false') . '`, would_call_create=`' . (($preflight['would_call_create_endpoint'] ?? false) ? 'true' : 'false') . '`, missing_fields=`' . (int)($preflight['missing_required_field_count'] ?? 0) . '`';
                    $missingFieldSummary = [];
                    foreach (business_chain_list($preflight['missing_required_fields'] ?? []) as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $missingFieldSummary[] = (string)($item['field'] ?? '') . ':' . (string)($item['reason'] ?? '');
                        if (count($missingFieldSummary) >= 5) {
                            break;
                        }
                    }
                    if ($missingFieldSummary !== []) {
                        $lines[] = '- operation_intake_missing_fields: `' . implode(',', $missingFieldSummary) . '`';
                    }
                }
            }
        }
        $actionQueue = is_array($workflow['ctrip_chain_action_queue'] ?? null) ? $workflow['ctrip_chain_action_queue'] : [];
        if ($actionQueue !== []) {
            $lines[] = '- ctrip_chain_action_queue: `' . ($actionQueue['status'] ?? '') . '`, items=`' . (int)($actionQueue['item_count'] ?? 0) . '`, blocking=`' . (int)($actionQueue['blocking_count'] ?? 0) . '`';
            foreach (business_chain_list($actionQueue['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $lines[] = '- ctrip_chain_next_action: action=`' . ($item['code'] ?? '') . '`, stage=`' . ($item['stage'] ?? '') . '`, evidence=`' . ($item['evidence_code'] ?? '') . '`, target=`' . ($item['target_entry'] ?? '') . '`';
            }
            $queueForbiddenActions = array_map(
                static fn(mixed $item): string => (string)$item,
                business_chain_list($actionQueue['forbidden_actions'] ?? [])
            );
            if ($queueForbiddenActions !== []) {
                $lines[] = '- ctrip_chain_forbidden_actions: `' . implode(',', $queueForbiddenActions) . '`';
            }
        }
        $lines[] = '- operation_execution_draft: `' . ($workflow['operation_execution_draft']['status'] ?? '') . '`';
    }
    return implode(PHP_EOL, $lines) . PHP_EOL;
}

/**
 * @param array<int, string> $argv
 */
function business_chain_main(array $argv): int
{
    $options = parse_business_chain_args($argv);

    try {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        $report = business_chain_report($options);
        if ($options['format'] === 'markdown') {
            echo business_chain_markdown($report);
        } else {
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }
        $focusedChainReady = (string)($report['focused_chain']['status'] ?? '') === 'scoped_ai_review_ready';
        return ($report['status'] ?? '') === 'incomplete' && !$options['skip_p0'] && !$focusedChainReady ? 2 : 0;
    } catch (Throwable $e) {
        $payload = business_chain_failure_payload($e);
        fwrite(STDERR, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        return 1;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(business_chain_main($argv));
}
