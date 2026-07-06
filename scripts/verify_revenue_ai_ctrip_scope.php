<?php
declare(strict_types=1);

use app\service\RevenueAiOverviewService;
use think\App;

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function revenue_ai_ctrip_parse_args(array $argv): array
{
    $options = [
        'date' => date('Y-m-d'),
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (array_key_exists($key, $options)) {
            $options[$key] = trim($value);
        }
    }

    foreach (['business-date', 'business_date'] as $dateKey) {
        if ((string)$options[$dateKey] !== '') {
            $options['date'] = (string)$options[$dateKey];
        }
    }
    foreach (['hotel_id'] as $hotelKey) {
        if ((string)$options['hotel-id'] === '' && (string)$options[$hotelKey] !== '') {
            $options['hotel-id'] = (string)$options[$hotelKey];
        }
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$options['date'])) {
        throw new InvalidArgumentException('Invalid --date, expected YYYY-MM-DD.');
    }

    if ((string)$options['hotel-id'] !== '') {
        if (!ctype_digit((string)$options['hotel-id']) || (int)$options['hotel-id'] <= 0) {
            throw new InvalidArgumentException('Invalid --hotel-id, expected a positive integer.');
        }
        $options['hotel-id'] = (int)$options['hotel-id'];
    }

    return [
        'date' => (string)$options['date'],
        'hotel_id' => $options['hotel-id'] === '' ? null : (int)$options['hotel-id'],
    ];
}

/**
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed> $details
 */
function revenue_ai_ctrip_check(array &$checks, string $code, bool $ok, string $message, array $details = []): void
{
    $row = [
        'code' => $code,
        'status' => $ok ? 'passed' : 'failed',
        'message' => $message,
    ];
    if ($details !== []) {
        $row['details'] = $details;
    }
    $checks[] = $row;
}

/**
 * @param mixed $value
 * @return array<int, mixed>
 */
function revenue_ai_ctrip_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @param mixed $value
 * @return array<string, mixed>
 */
function revenue_ai_ctrip_map(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @param mixed $value
 * @param array<int, string> $terms
 * @return array<int, array{path:string, term:string, value:string}>
 */
function revenue_ai_ctrip_find_terms(mixed $value, array $terms, string $path = '$', int $limit = 30): array
{
    $hits = [];
    $pushHit = static function (string $hitPath, string $term, string $text) use (&$hits, $limit): void {
        if (count($hits) >= $limit) {
            return;
        }
        $hits[] = [
            'path' => $hitPath,
            'term' => $term,
            'value' => substr($text, 0, 180),
        ];
    };

    $scanText = static function (string $text, string $hitPath) use ($terms, $pushHit): void {
        $lower = strtolower($text);
        foreach ($terms as $term) {
            $termLower = strtolower($term);
            if (($termLower !== '' && str_contains($lower, $termLower)) || ($term !== '' && str_contains($text, $term))) {
                $pushHit($hitPath, $term, $text);
            }
        }
    };

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $keyText = (string)$key;
            $childPath = $path . '.' . $keyText;
            $scanText($keyText, $childPath . '#key');
            foreach (revenue_ai_ctrip_find_terms($item, $terms, $childPath, $limit - count($hits)) as $hit) {
                if (count($hits) >= $limit) {
                    break;
                }
                $hits[] = $hit;
            }
        }
        return $hits;
    }

    if (is_scalar($value)) {
        $scanText((string)$value, $path);
    }

    return $hits;
}

/**
 * @param array<string, mixed> $metrics
 * @return array<string, array<string, mixed>>
 */
function revenue_ai_ctrip_metric_statuses(array $metrics): array
{
    $summary = [];
    foreach ($metrics as $key => $metric) {
        if (!is_array($metric)) {
            continue;
        }
        $summary[(string)$key] = [
            'status' => (string)($metric['status'] ?? ''),
            'reason' => (string)($metric['reason'] ?? ''),
            'value' => $metric['value'] ?? null,
            'scope' => (string)($metric['scope'] ?? ''),
        ];
    }
    return $summary;
}

/**
 * @param array<int, array<string, mixed>> $actions
 * @return array<string, mixed>
 */
function revenue_ai_ctrip_pricing_action(array $actions): array
{
    foreach ($actions as $action) {
        if (is_array($action) && (string)($action['key'] ?? '') === 'pricing_review') {
            return $action;
        }
    }
    return is_array($actions[0] ?? null) ? $actions[0] : [];
}

/**
 * @param array<int, string> $args
 * @return array{exit_code:int, stdout:string, stderr:string}
 */
function revenue_ai_ctrip_run_process(array $args, string $cwd): array
{
    $command = implode(' ', array_map('escapeshellarg', $args));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        return [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'Unable to start process.',
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => is_int($exitCode) ? $exitCode : 1,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

/**
 * @return array<string, mixed>
 */
function revenue_ai_ctrip_decode_json_payload(string $stdout): array
{
    $text = trim($stdout);
    if ($text === '') {
        return [];
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end < $start) {
        return [];
    }
    $json = substr($text, $start, $end - $start + 1);
    $payload = json_decode($json, true);
    return is_array($payload) ? $payload : [];
}

function revenue_ai_ctrip_p0_verifier_command(string $date, ?int $hotelId): string
{
    $command = 'npm.cmd run verify:p0-ota-field-loop -- --date=' . $date . ' --platform=ctrip';
    if ($hotelId !== null) {
        $command .= ' --system-hotel-id=' . $hotelId;
    }
    return $command;
}

/**
 * @param array{exit_code:int, stdout:string, stderr:string} $run
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function revenue_ai_ctrip_p0_gate_from_authority(string $date, ?int $hotelId, array $run, array $payload): array
{
    $passed = $run['exit_code'] === 0 && (string)($payload['status'] ?? '') === 'passed';
    $gate = [
        'status' => $passed ? 'ready' : 'blocked_by_p0_ota_gate',
        'current_upstream_status' => $passed ? 'ready' : (string)($payload['status'] ?? 'not_verified'),
        'required_upstream_status' => 'ready',
        'required_gate_command' => revenue_ai_ctrip_p0_verifier_command($date, $hotelId),
        'scope_policy' => 'ota_channel_gate_before_downstream_claims',
    ];
    if (!$passed) {
        $gate['blocking_missing_inputs'] = ['p0_field_loop_verifier_ready'];
    }
    return $gate;
}

/**
 * @param array<string, mixed> $payload
 */
function revenue_ai_ctrip_finish(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    revenue_ai_ctrip_finish([
        'status' => 'failed',
        'error' => 'vendor/autoload.php is missing.',
    ], 1);
}

require $autoload;

try {
    $options = revenue_ai_ctrip_parse_args($argv);
    $app = new App($root);
    $app->initialize();

    $filters = [
        'business_date' => $options['date'],
        'platform' => 'ctrip',
        'enabled_channels' => ['ctrip'],
    ];
    if ($options['hotel_id'] !== null) {
        $filters['hotel_id'] = $options['hotel_id'];
    }

    $p0Args = [
        PHP_BINARY,
        'scripts/verify_p0_ota_field_loop_closure.php',
        '--date=' . $options['date'],
        '--platform=ctrip',
    ];
    if ($options['hotel_id'] !== null) {
        $p0Args[] = '--system-hotel-id=' . $options['hotel_id'];
    }
    $p0Run = revenue_ai_ctrip_run_process($p0Args, $root);
    $p0Payload = revenue_ai_ctrip_decode_json_payload($p0Run['stdout']);
    $p0AuthorityPassed = $p0Run['exit_code'] === 0 && (string)($p0Payload['status'] ?? '') === 'passed';
    $filters['p0_downstream_gate'] = revenue_ai_ctrip_p0_gate_from_authority(
        $options['date'],
        $options['hotel_id'],
        $p0Run,
        $p0Payload
    );

    $overview = (new RevenueAiOverviewService())->overview($filters);
    $checks = [];
    $sourceChannels = revenue_ai_ctrip_list($overview['source_channels'] ?? []);
    $channelStatuses = revenue_ai_ctrip_map($overview['channel_statuses'] ?? []);
    $p0Gate = revenue_ai_ctrip_map($overview['p0_downstream_gate'] ?? []);
    $reviewQueue = revenue_ai_ctrip_map($overview['review_queue'] ?? []);
    $pricingGenerationPreflight = revenue_ai_ctrip_map($overview['pricing_generation_preflight'] ?? []);
    $agentActivity = revenue_ai_ctrip_map($overview['agent_activity'] ?? []);
    $executionSummary = revenue_ai_ctrip_map($overview['execution_summary'] ?? []);
    $pricingReadiness = revenue_ai_ctrip_map($overview['pricing_readiness'] ?? []);
    $resolutionPlan = revenue_ai_ctrip_map($pricingReadiness['ai_decision_resolution_plan'] ?? []);
    $reviewContract = revenue_ai_ctrip_map($pricingReadiness['ai_decision_review_contract'] ?? []);
    $aiToOperation = revenue_ai_ctrip_map($overview['ai_to_operation_handoff'] ?? []);
    $operationPreflight = revenue_ai_ctrip_map(
        revenue_ai_ctrip_map($aiToOperation['operation_intake_packet'] ?? [])['operation_intake_preflight_contract'] ?? []
    );
    $operationToInvestment = revenue_ai_ctrip_map($overview['operation_to_investment_handoff'] ?? []);
    $investmentPacket = revenue_ai_ctrip_map($operationToInvestment['investment_precheck_packet'] ?? []);
    $pricingAction = revenue_ai_ctrip_pricing_action(revenue_ai_ctrip_list($overview['actions'] ?? []));
    $contaminationHits = revenue_ai_ctrip_find_terms($overview, ['meituan', 'Meituan', '美团']);
    $serviceGateCommand = (string)($p0Gate['required_gate_command'] ?? '');

    revenue_ai_ctrip_check(
        $checks,
        'business_date_matches',
        (string)($overview['business_date'] ?? '') === $options['date'],
        'Revenue AI overview uses the requested business date.',
        ['expected' => $options['date'], 'actual' => $overview['business_date'] ?? null]
    );
    revenue_ai_ctrip_check(
        $checks,
        'source_channels_exact_ctrip',
        $sourceChannels === ['ctrip'],
        'Revenue AI overview source channel is Ctrip only.',
        ['source_channels' => $sourceChannels]
    );
    revenue_ai_ctrip_check(
        $checks,
        'channel_statuses_scoped_ctrip',
        array_diff(array_keys($channelStatuses), ['ctrip']) === [] && isset($channelStatuses['ctrip']),
        'Channel status output is scoped to Ctrip only.',
        ['channel_status_keys' => array_keys($channelStatuses)]
    );
    revenue_ai_ctrip_check(
        $checks,
        'p0_authority_verifier_passed',
        $p0Run['exit_code'] === 0 && (string)($p0Payload['status'] ?? '') === 'passed',
        'Authoritative Ctrip P0 field-loop verifier passes for the requested date.',
        [
            'exit_code' => $p0Run['exit_code'],
            'status' => $p0Payload['status'] ?? null,
            'summary' => $p0Payload['summary'] ?? null,
            'stderr' => trim($p0Run['stderr']),
        ]
    );
    revenue_ai_ctrip_check(
        $checks,
        'service_p0_gate_scoped_ctrip',
        str_contains($serviceGateCommand, '--platform=ctrip') && !str_contains(strtolower($serviceGateCommand), 'meituan'),
        'Revenue AI service P0 gate command stays scoped to Ctrip.',
        ['status' => $p0Gate['status'] ?? null, 'required_gate_command' => $serviceGateCommand]
    );
    revenue_ai_ctrip_check(
        $checks,
        'service_p0_gate_reflects_authority',
        ($p0AuthorityPassed && (string)($p0Gate['status'] ?? '') === 'ready')
            || (!$p0AuthorityPassed && (string)($p0Gate['status'] ?? '') === 'blocked_by_p0_ota_gate'),
        'Revenue AI service P0 gate reflects the authoritative Ctrip P0 verifier snapshot supplied to this runtime check.',
        [
            'authority_status' => $p0Payload['status'] ?? null,
            'authority_exit_code' => $p0Run['exit_code'],
            'service_status' => $p0Gate['status'] ?? null,
            'required_gate_command' => $serviceGateCommand,
        ]
    );
    revenue_ai_ctrip_check(
        $checks,
        'ai_decision_resolution_scope_ctrip',
        (string)($resolutionPlan['source_scope'] ?? '') === 'ctrip_ota_channel'
            && revenue_ai_ctrip_list($resolutionPlan['source_channels'] ?? []) === ['ctrip'],
        'AI decision resolution plan stays in Ctrip OTA channel scope.',
        ['source_scope' => $resolutionPlan['source_scope'] ?? null, 'source_channels' => $resolutionPlan['source_channels'] ?? null]
    );
    revenue_ai_ctrip_check(
        $checks,
        'ai_decision_review_manual_only',
        ($reviewContract['auto_apply_ai_advice'] ?? true) === false
            && ($reviewContract['operation_intake_allowed'] ?? true) === false,
        'AI decision review remains manual-only and cannot open operation intake automatically.',
        [
            'status' => $reviewContract['status'] ?? null,
            'approval_allowed' => $reviewContract['approval_allowed'] ?? null,
            'operation_intake_allowed' => $reviewContract['operation_intake_allowed'] ?? null,
            'auto_apply_ai_advice' => $reviewContract['auto_apply_ai_advice'] ?? null,
        ]
    );
    revenue_ai_ctrip_check(
        $checks,
        'manual_review_queue_loaded',
        (string)($reviewQueue['source_table'] ?? '') === 'price_suggestions'
            && (string)($reviewQueue['reason'] ?? '') !== 'manual_review_workflow_not_connected'
            && in_array((string)($reviewQueue['status'] ?? ''), ['empty', 'pending_review', 'reviewed', 'missing', 'failed'], true),
        'Revenue AI overview reads the local price_suggestions review queue instead of the disconnected placeholder.',
        [
            'status' => $reviewQueue['status'] ?? null,
            'reason' => $reviewQueue['reason'] ?? null,
            'source_table' => $reviewQueue['source_table'] ?? null,
            'pending_count' => $reviewQueue['pending_count'] ?? null,
        ]
    );
    revenue_ai_ctrip_check(
        $checks,
        'manual_review_queue_navigation_target',
        (string)($reviewQueue['target_page'] ?? '') === 'agent-center'
            && (string)($reviewQueue['target_agent_tab'] ?? '') === 'revenue'
            && (string)($reviewQueue['target_revenue_tab'] ?? '') === 'suggestions',
        'Revenue AI review queue points to the existing Agent pricing suggestion workflow.',
        [
            'target_page' => $reviewQueue['target_page'] ?? null,
            'target_agent_tab' => $reviewQueue['target_agent_tab'] ?? null,
            'target_revenue_tab' => $reviewQueue['target_revenue_tab'] ?? null,
            'target_filter' => $reviewQueue['target_filter'] ?? null,
        ]
    );
    revenue_ai_ctrip_check(
        $checks,
        'pricing_generation_preflight_loaded',
        $pricingGenerationPreflight !== []
            && (string)($pricingGenerationPreflight['status'] ?? '') !== 'not_loaded'
            && (string)($pricingGenerationPreflight['reason'] ?? '') !== '',
        'Revenue AI loads a read-only price suggestion generation preflight instead of hiding an empty queue.',
        [
            'status' => $pricingGenerationPreflight['status'] ?? null,
            'reason' => $pricingGenerationPreflight['reason'] ?? null,
            'target_hotel_ids' => $pricingGenerationPreflight['target_hotel_ids'] ?? null,
            'room_type_count' => $pricingGenerationPreflight['room_type_count'] ?? null,
            'create_candidate_count' => $pricingGenerationPreflight['create_candidate_count'] ?? null,
        ]
    );
    revenue_ai_ctrip_check(
        $checks,
        'pricing_generation_preflight_ctrip_readonly',
        (string)($pricingGenerationPreflight['source_scope'] ?? '') === 'ctrip_ota_channel'
            && revenue_ai_ctrip_list($pricingGenerationPreflight['source_channels'] ?? []) === ['ctrip']
            && ($pricingGenerationPreflight['auto_write_ota'] ?? true) === false
            && ($pricingGenerationPreflight['advisory_only'] ?? false) === true
            && ($pricingGenerationPreflight['read_only'] ?? false) === true,
        'Price suggestion generation preflight stays Ctrip-scoped, advisory-only, and never writes OTA.',
        [
            'source_scope' => $pricingGenerationPreflight['source_scope'] ?? null,
            'source_channels' => $pricingGenerationPreflight['source_channels'] ?? null,
            'advisory_only' => $pricingGenerationPreflight['advisory_only'] ?? null,
            'read_only' => $pricingGenerationPreflight['read_only'] ?? null,
            'auto_write_ota' => $pricingGenerationPreflight['auto_write_ota'] ?? null,
        ]
    );
    revenue_ai_ctrip_check(
        $checks,
        'pricing_generation_preflight_navigation_target',
        (string)($pricingGenerationPreflight['target_page'] ?? '') === 'agent-center'
            && (string)($pricingGenerationPreflight['target_agent_tab'] ?? '') === 'revenue'
            && (string)($pricingGenerationPreflight['target_revenue_tab'] ?? '') === 'suggestions',
        'Price suggestion generation preflight points to the existing Agent pricing suggestion workflow.',
        [
            'target_page' => $pricingGenerationPreflight['target_page'] ?? null,
            'target_agent_tab' => $pricingGenerationPreflight['target_agent_tab'] ?? null,
            'target_revenue_tab' => $pricingGenerationPreflight['target_revenue_tab'] ?? null,
            'target_filter' => $pricingGenerationPreflight['target_filter'] ?? null,
        ]
    );
    revenue_ai_ctrip_check(
        $checks,
        'agent_and_execution_state_loaded',
        (string)($agentActivity['source_table'] ?? '') === 'agent_logs'
            && array_key_exists('total_count', $agentActivity)
            && array_key_exists('total_count', $executionSummary),
        'Revenue AI overview reads local Agent activity and operation execution state explicitly.',
        [
            'agent_status' => $agentActivity['status'] ?? null,
            'agent_reason' => $agentActivity['reason'] ?? null,
            'execution_status' => $executionSummary['status'] ?? null,
            'execution_reason' => $executionSummary['reason'] ?? null,
        ]
    );
    revenue_ai_ctrip_check(
        $checks,
        'ai_to_operation_scope_ctrip',
        (string)($aiToOperation['source_scope'] ?? '') === 'ctrip_ota_channel'
            && revenue_ai_ctrip_list($aiToOperation['source_channels'] ?? []) === ['ctrip']
            && ($aiToOperation['can_create_operation_execution'] ?? true) === false
            && ($aiToOperation['auto_create_operation_execution'] ?? true) === false,
        'AI-to-operation handoff stays Ctrip-only and read-only.',
        [
            'status' => $aiToOperation['status'] ?? null,
            'source_scope' => $aiToOperation['source_scope'] ?? null,
            'can_create_operation_execution' => $aiToOperation['can_create_operation_execution'] ?? null,
            'auto_create_operation_execution' => $aiToOperation['auto_create_operation_execution'] ?? null,
        ]
    );
    revenue_ai_ctrip_check(
        $checks,
        'operation_intake_preflight_blocked',
        ($operationPreflight['create_allowed'] ?? true) === false
            && ($operationPreflight['would_call_create_endpoint'] ?? true) === false
            && ($operationPreflight['dry_run_only'] ?? false) === true,
        'Operation intake preflight cannot create records before human AI review.',
        [
            'status' => $operationPreflight['status'] ?? null,
            'create_allowed' => $operationPreflight['create_allowed'] ?? null,
            'would_call_create_endpoint' => $operationPreflight['would_call_create_endpoint'] ?? null,
            'dry_run_only' => $operationPreflight['dry_run_only'] ?? null,
        ]
    );
    revenue_ai_ctrip_check(
        $checks,
        'operation_to_investment_scope_ctrip',
        (string)($operationToInvestment['source_scope'] ?? '') === 'ctrip_ota_channel_to_operation_roi'
            && revenue_ai_ctrip_list($operationToInvestment['source_channels'] ?? []) === ['ctrip'],
        'Operation-to-investment precheck keeps Ctrip OTA channel to operation ROI scope.',
        ['source_scope' => $operationToInvestment['source_scope'] ?? null, 'source_channels' => $operationToInvestment['source_channels'] ?? null]
    );
    revenue_ai_ctrip_check(
        $checks,
        'investment_decision_blocked_until_roi',
        ($operationToInvestment['decision_allowed'] ?? true) === false
            && ($operationToInvestment['can_create_investment_decision'] ?? true) === false
            && (string)($investmentPacket['required_gate'] ?? '') === 'operation_execution.roi_ready',
        'Investment decision stays blocked until closed operation ROI evidence is ready.',
        [
            'status' => $operationToInvestment['status'] ?? null,
            'decision_allowed' => $operationToInvestment['decision_allowed'] ?? null,
            'can_create_investment_decision' => $operationToInvestment['can_create_investment_decision'] ?? null,
            'required_gate' => $investmentPacket['required_gate'] ?? null,
        ]
    );
    revenue_ai_ctrip_check(
        $checks,
        'pricing_action_carries_handoffs',
        is_array($pricingAction['ai_decision_resolution_plan'] ?? null)
            && is_array($pricingAction['ai_to_operation_handoff'] ?? null)
            && is_array($pricingAction['operation_to_investment_handoff'] ?? null)
            && is_array($pricingAction['pricing_generation_preflight'] ?? null),
        'Pricing action carries the AI, operation, and investment handoff packets.',
        ['action_key' => $pricingAction['key'] ?? null]
    );
    revenue_ai_ctrip_check(
        $checks,
        'meituan_not_present',
        $contaminationHits === [],
        'Ctrip-only overview has no Meituan token or label in the returned payload.',
        ['hits' => $contaminationHits]
    );

    $failures = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'passed'));
    revenue_ai_ctrip_finish([
        'status' => $failures === [] ? 'passed' : 'failed',
        'scope' => [
            'business_date' => $options['date'],
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $options['hotel_id'],
            'metric_scope' => 'ota_channel',
            'source_policy' => 'read_current_database_revenue_ai_overview_only',
        ],
        'summary' => [
            'data_status' => $overview['data_status'] ?? null,
            'source_channels' => $sourceChannels,
            'p0_downstream_gate' => [
                'status' => $p0Gate['status'] ?? null,
                'required_gate_command' => $p0Gate['required_gate_command'] ?? null,
            ],
            'p0_authority_verifier' => [
                'exit_code' => $p0Run['exit_code'],
                'status' => $p0Payload['status'] ?? null,
                'summary' => $p0Payload['summary'] ?? null,
            ],
            'metric_statuses' => revenue_ai_ctrip_metric_statuses(revenue_ai_ctrip_map($overview['metrics'] ?? [])),
            'ai_decision_review_contract' => [
                'status' => $reviewContract['status'] ?? null,
                'approval_allowed' => $reviewContract['approval_allowed'] ?? null,
                'operation_intake_allowed' => $reviewContract['operation_intake_allowed'] ?? null,
                'auto_apply_ai_advice' => $reviewContract['auto_apply_ai_advice'] ?? null,
                'required_input_count' => $reviewContract['required_input_count'] ?? null,
            ],
            'review_queue' => [
                'status' => $reviewQueue['status'] ?? null,
                'reason' => $reviewQueue['reason'] ?? null,
                'source_table' => $reviewQueue['source_table'] ?? null,
                'pending_count' => $reviewQueue['pending_count'] ?? null,
                'approved_or_applied_count' => $reviewQueue['approved_or_applied_count'] ?? null,
            ],
            'pricing_generation_preflight' => [
                'status' => $pricingGenerationPreflight['status'] ?? null,
                'reason' => $pricingGenerationPreflight['reason'] ?? null,
                'source_scope' => $pricingGenerationPreflight['source_scope'] ?? null,
                'target_hotel_ids' => $pricingGenerationPreflight['target_hotel_ids'] ?? null,
                'target_date_rows' => $pricingGenerationPreflight['target_date_rows'] ?? null,
                'room_type_count' => $pricingGenerationPreflight['room_type_count'] ?? null,
                'pending_suggestion_count' => $pricingGenerationPreflight['pending_suggestion_count'] ?? null,
                'create_candidate_count' => $pricingGenerationPreflight['create_candidate_count'] ?? null,
                'required_inputs' => $pricingGenerationPreflight['required_inputs'] ?? null,
            ],
            'agent_activity' => [
                'status' => $agentActivity['status'] ?? null,
                'reason' => $agentActivity['reason'] ?? null,
                'source_table' => $agentActivity['source_table'] ?? null,
                'total_count' => $agentActivity['total_count'] ?? null,
            ],
            'execution_summary' => [
                'status' => $executionSummary['status'] ?? null,
                'reason' => $executionSummary['reason'] ?? null,
                'total_count' => $executionSummary['total_count'] ?? null,
                'roi_ready_count' => $executionSummary['roi_ready_count'] ?? null,
            ],
            'ai_to_operation_handoff' => [
                'status' => $aiToOperation['status'] ?? null,
                'source_scope' => $aiToOperation['source_scope'] ?? null,
                'can_create_operation_execution' => $aiToOperation['can_create_operation_execution'] ?? null,
                'auto_create_operation_execution' => $aiToOperation['auto_create_operation_execution'] ?? null,
            ],
            'operation_to_investment_handoff' => [
                'status' => $operationToInvestment['status'] ?? null,
                'source_scope' => $operationToInvestment['source_scope'] ?? null,
                'operation_roi_ready' => $operationToInvestment['operation_roi_ready'] ?? null,
                'decision_allowed' => $operationToInvestment['decision_allowed'] ?? null,
                'can_create_investment_decision' => $operationToInvestment['can_create_investment_decision'] ?? null,
            ],
        ],
        'checks' => $checks,
    ], $failures === [] ? 0 : 1);
} catch (Throwable $error) {
    revenue_ai_ctrip_finish([
        'status' => 'failed',
        'error' => [
            'type' => get_class($error),
            'message' => $error->getMessage(),
        ],
    ], 1);
}
