<?php
declare(strict_types=1);

use app\service\BusinessClosureOverviewService;
use app\service\InvestmentDecisionSupportService;
use app\service\OtaStandardEtlService;
use app\service\RevenueAiOverviewService;
use think\App;
use think\facade\Db;

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Shanghai');

/**
 * @return array<string, mixed>
 */
function parse_business_chain_args(array $argv): array
{
    $options = [
        'date' => date('Y-m-d'),
        'system_hotel_id' => null,
        'limit' => 5000,
        'skip_p0' => false,
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
        } elseif ($key === 'limit') {
            $options['limit'] = max(1, min(5000, (int)$value));
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

function business_chain_table_exists(string $table): bool
{
    try {
        return Db::query("SHOW TABLES LIKE '{$table}'") !== [];
    } catch (Throwable) {
        return false;
    }
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
 * @return array<string, mixed>
 */
function business_chain_gate(string $targetDate, ?int $systemHotelId, bool $skipP0): array
{
    return [
        'status' => 'blocked_by_p0_ota_gate',
        'current_upstream_status' => $skipP0 ? 'skip_p0_reference_only' : 'incomplete',
        'required_upstream_status' => 'ready',
        'required_gate_command' => 'npm.cmd run verify:p0-ota-field-loop -- --date='
            . $targetDate
            . ($systemHotelId !== null ? ' --system-hotel-id=' . $systemHotelId : ''),
        'scope_policy' => 'ota_channel_gate_before_downstream_claims',
        'blocking_missing_inputs' => $skipP0
            ? ['p0_skipped_by_operator', 'p0_field_loop_verifier_ready', 'target_date_ota_rows', 'target_date_traffic_rows']
            : ['p0_field_loop_verifier_ready'],
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

/**
 * @param array<string, mixed> $revenue
 * @param array<string, mixed> $closure
 * @param array<string, mixed> $investment
 * @return array<int, array<string, mixed>>
 */
function business_chain_stage_rows(array $referenceDataset, array $revenue, array $closure, array $investment, bool $skipP0): array
{
    $counts = business_chain_fact_counts($referenceDataset);
    $p0Blocked = (string)($closure['summary']['status'] ?? '') === 'blocked_by_p0_ota_gate'
        || (string)($investment['operating_data_gate']['status'] ?? '') === 'blocked_by_p0_ota_gate';

    return [
        [
            'key' => 'ota_data',
            'label' => 'OTA data',
            'status' => $counts['accepted'] > 0 ? ($skipP0 ? 'reference_only' : 'ready') : 'data_gap',
            'claim_allowed' => !$skipP0 && $counts['accepted'] > 0,
            'evidence' => $counts,
        ],
        [
            'key' => 'revenue_analysis',
            'label' => 'Revenue analysis',
            'status' => $skipP0 ? 'reference_only' : (string)($revenue['data_status'] ?? 'unknown'),
            'claim_allowed' => !$skipP0 && !$p0Blocked,
            'evidence' => [
                'data_status' => $revenue['data_status'] ?? '',
                'source_channels' => $revenue['source_channels'] ?? [],
                'pricing_status' => $revenue['pricing_readiness']['status'] ?? '',
            ],
        ],
        [
            'key' => 'ai_decision_advice',
            'label' => 'AI decision advice',
            'status' => $p0Blocked ? 'blocked_by_p0_ota_gate' : 'ready_for_review',
            'claim_allowed' => !$p0Blocked,
            'evidence' => [
                'action_count' => count(business_chain_list($revenue['actions'] ?? [])),
                'agent_activity_status' => $revenue['agent_activity']['status'] ?? '',
            ],
        ],
        [
            'key' => 'operation_closure',
            'label' => 'Operation closure',
            'status' => (string)($closure['summary']['status'] ?? 'unknown'),
            'claim_allowed' => !$p0Blocked && (string)($closure['summary']['status'] ?? '') === 'closed',
            'evidence' => [
                'operation_execution_total' => (int)($closure['summary']['operation_execution_total'] ?? 0),
                'operation_roi_ready' => (int)($closure['summary']['operation_roi_ready'] ?? 0),
            ],
        ],
        [
            'key' => 'investment_judgment',
            'label' => 'Investment judgment',
            'status' => (string)($investment['summary']['status'] ?? 'unknown'),
            'claim_allowed' => (bool)($investment['summary']['decision_allowed'] ?? false),
            'evidence' => [
                'operating_gate_status' => $investment['operating_data_gate']['status'] ?? '',
                'decision_record_count' => (int)($investment['sections']['decision_records']['record_count'] ?? 0),
                'eligible_count' => (int)($investment['sections']['decision_records']['eligible_count'] ?? 0),
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function business_chain_p0_execution_plan(string $targetDate, ?int $systemHotelId): array
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

    return business_chain_compact_p0_execution_plan($payload, $targetDate, $systemHotelId, $exitCode);
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
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function business_chain_compact_p0_execution_plan(array $payload, string $targetDate, ?int $systemHotelId, int $exitCode): array
{
    $platformSummaries = [];
    $operatorSequence = [];
    foreach (business_chain_list($payload['platforms'] ?? []) as $platformPayload) {
        if (!is_array($platformPayload)) {
            continue;
        }
        $platform = (string)($platformPayload['platform'] ?? '');
        $gate = is_array($platformPayload['p0_traffic_gate'] ?? null) ? $platformPayload['p0_traffic_gate'] : [];
        $steps = [];
        foreach (business_chain_list($gate['hotel_scoped_next_steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            $trigger = is_array($step['profile_login_trigger'] ?? null) ? $step['profile_login_trigger'] : [];
            $afterLoginSync = is_array($trigger['after_login_sync'] ?? null) ? $trigger['after_login_sync'] : [];
            $compact = [
                'platform' => $platform,
                'system_hotel_id' => isset($step['system_hotel_id']) ? (int)$step['system_hotel_id'] : null,
                'data_source_id' => isset($step['data_source_id']) ? (int)$step['data_source_id'] : null,
                'data_source_status' => (string)($step['data_source_status'] ?? ''),
                'last_sync_status' => (string)($step['last_sync_status'] ?? ''),
                'manual_login_state_verified' => ($step['manual_login_state_verified'] ?? false) === true,
                'login_trigger_entry' => (string)($trigger['entry'] ?? ''),
                'login_trigger_status' => (string)($trigger['status'] ?? ''),
                'after_login_sync_entry' => (string)($afterLoginSync['entry'] ?? ''),
                'verifier_command' => (string)($step['p0_verifier_command'] ?? ''),
            ];
            $steps[] = $compact;
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
            'action_entry' => (string)($gate['action_entry'] ?? ''),
            'action_status' => (string)($gate['action_status'] ?? ''),
            'missing_inputs' => array_values(array_map('strval', (array)($gate['action_missing_inputs'] ?? $gate['required_next_inputs'] ?? []))),
            'next_steps' => $steps,
        ];
    }

    return [
        'status' => (string)($payload['status'] ?? 'unknown'),
        'verifier_exit_code' => $exitCode,
        'source_policy' => 'read_p0_verifier_metadata_only_no_ota_collection',
        'sensitive_values_policy' => 'metadata_only_no_cookie_token_profile_path_or_raw_payload',
        'scope' => [
            'target_date' => (string)($payload['scope']['date'] ?? $targetDate),
            'system_hotel_id' => $systemHotelId,
            'metric_scope' => (string)($payload['scope']['metric_scope'] ?? 'ota_channel'),
        ],
        'summary' => is_array($payload['summary'] ?? null) ? $payload['summary'] : [],
        'platform_summaries' => $platformSummaries,
        'operator_sequence' => $operatorSequence,
        'completion_gate' => [
            'command' => 'npm.cmd run verify:p0-ota-field-loop -- --date='
                . $targetDate
                . ($systemHotelId !== null ? ' --system-hotel-id=' . $systemHotelId : ''),
            'required_status' => 'ready',
            'current_status' => (string)($payload['status'] ?? 'unknown'),
        ],
    ];
}

/**
 * @param array<string, mixed> $revenue
 * @return array<int, array<string, mixed>>
 */
function business_chain_downstream_signals(array $revenue): array
{
    $actionCount = count(business_chain_list($revenue['actions'] ?? []));
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
            'source_scope' => 'operation_execution_not_loaded_by_read_only_chain_report',
            'record_count' => 0,
            'linked_execution_count' => 0,
            'reviewed_count' => 0,
            'roi_ready_count' => 0,
            'data_gaps' => [
                ['code' => 'operation_execution_not_loaded', 'message' => 'Read-only business-chain report did not load or write operation execution records.'],
            ],
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
    $limit = (int)$options['limit'];
    $skipP0 = (bool)$options['skip_p0'];
    $sources = ['ctrip', 'meituan'];
    $targetDatasets = [];
    $referenceDatasets = [];
    $sourceRows = [];
    foreach ($sources as $source) {
        $target = business_chain_build_dataset_for($source, $targetDate, $systemHotelId, $limit);
        $latestDate = business_chain_latest_date($source, $systemHotelId);
        $referenceDate = $target['status'] === 'ready'
            ? $targetDate
            : ($skipP0 ? $latestDate : '');
        $reference = $target;
        if ($target['status'] !== 'ready' && $skipP0 && $latestDate !== '') {
            $reference = business_chain_build_dataset_for($source, $latestDate, $systemHotelId, $limit);
        }
        $targetDatasets[$source] = $target;
        $referenceDatasets[$source] = $reference;
        $sourceRows[] = [
            'source' => $source,
            'target_date' => $targetDate,
            'target_status' => $target['status'] ?? 'empty',
            'target_counts' => business_chain_fact_counts($target),
            'reference_date' => $referenceDate,
            'reference_status' => $reference['status'] ?? 'empty',
            'reference_counts' => business_chain_fact_counts($reference),
            'reference_only' => $referenceDate !== '' && $referenceDate !== $targetDate,
        ];
    }

    $targetDataset = business_chain_merge_datasets($targetDatasets);
    $referenceDataset = business_chain_merge_datasets($referenceDatasets);
    $skipActive = $skipP0 && $targetDataset['status'] !== 'ready' && $referenceDataset['status'] === 'ready';
    $p0Gate = business_chain_gate($targetDate, $systemHotelId, $skipActive);
    $p0ExecutionPlan = business_chain_p0_execution_plan($targetDate, $systemHotelId);

    $revenue = (new RevenueAiOverviewService())->buildOverviewFromDataset(
        $referenceDataset,
        $referenceDatasets,
        [],
        [
            'business_date' => $targetDate,
            'hotel_id' => $systemHotelId,
            'p0_downstream_gate' => $p0Gate,
        ]
    );
    $closure = (new BusinessClosureOverviewService())->buildOverviewFromSignals(
        business_chain_downstream_signals($revenue),
        ['total' => 0, 'roi_ready' => 0],
        [
            ['code' => 'read_only_report_operation_execution_not_loaded', 'message' => 'Operation execution records are not loaded by this read-only P0 skip report.'],
        ],
        $p0Gate
    );
    $investment = (new InvestmentDecisionSupportService())->buildOverviewFromEvidence($closure);
    $stages = business_chain_stage_rows($referenceDataset, $revenue, $closure, $investment, $skipActive);
    $claimAllowed = count(array_filter($stages, static fn(array $row): bool => ($row['claim_allowed'] ?? false) !== true)) === 0;

    return [
        'generated_at' => date('c'),
        'status' => $claimAllowed ? 'closed' : ($skipActive ? 'skip_p0_reference_only' : 'incomplete'),
        'claim_allowed' => $claimAllowed,
        'mode' => $skipActive ? 'skip_p0_reference_only' : 'p0_required',
        'scope' => [
            'target_date' => $targetDate,
            'system_hotel_id' => $systemHotelId,
            'metric_scope' => 'ota_channel',
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
                'investment_judgment_allowed',
            ],
        ],
        'source_rows' => $sourceRows,
        'p0_downstream_gate' => $p0Gate,
        'p0_execution_plan' => $p0ExecutionPlan,
        'stages' => $stages,
        'revenue_ai_summary' => [
            'data_status' => $revenue['data_status'] ?? '',
            'source_channels' => $revenue['source_channels'] ?? [],
            'missing_datasets' => $revenue['missing_datasets'] ?? [],
            'pricing_status' => $revenue['pricing_readiness']['status'] ?? '',
        ],
        'operation_summary' => [
            'status' => $closure['summary']['status'] ?? '',
            'operation_execution_total' => (int)($closure['summary']['operation_execution_total'] ?? 0),
            'operation_roi_ready' => (int)($closure['summary']['operation_roi_ready'] ?? 0),
        ],
        'investment_summary' => [
            'status' => $investment['summary']['status'] ?? '',
            'decision_allowed' => (bool)($investment['summary']['decision_allowed'] ?? false),
            'operating_gate_status' => $investment['operating_data_gate']['status'] ?? '',
        ],
        'next_required_gate' => [
            'command' => $p0Gate['required_gate_command'],
            'required_status' => 'ready',
            'current_status' => $p0Gate['current_upstream_status'],
        ],
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
    $lines[] = 'Next gate: `' . ($report['next_required_gate']['command'] ?? '') . '`';
    $p0Plan = is_array($report['p0_execution_plan'] ?? null) ? $report['p0_execution_plan'] : [];
    $operatorSequence = business_chain_list($p0Plan['operator_sequence'] ?? []);
    if ($operatorSequence !== []) {
        $lines[] = '';
        $lines[] = '## P0 Execution Plan';
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
            } elseif ($type === 'single_scope_verifier') {
                $lines[] = '- verify `' . $platform . '` hotel `' . $hotel . '` source `' . $source . '`: `' . ($item['command'] ?? '') . '`';
            }
        }
    }
    return implode(PHP_EOL, $lines) . PHP_EOL;
}

$options = parse_business_chain_args($argv);

try {
    $app = new App();
    $app->initialize();
    $report = business_chain_report($options);
    if ($options['format'] === 'markdown') {
        echo business_chain_markdown($report);
    } else {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    exit(($report['status'] ?? '') === 'incomplete' && !$options['skip_p0'] ? 2 : 0);
} catch (Throwable $e) {
    $payload = [
        'status' => 'failed',
        'message' => $e->getMessage(),
        'error_file' => str_replace('\\', '/', $e->getFile()),
        'error_line' => $e->getLine(),
        'source_policy' => 'read_only_report_no_ota_collection',
    ];
    fwrite(STDERR, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
