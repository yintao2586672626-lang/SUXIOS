<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{date:string,hotel_id:int|null,format:string}
 */
function ctrip_pricing_packet_parse_args(array $argv): array
{
    $options = [
        'date' => date('Y-m-d'),
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'format' => 'json',
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
        if ($options[$dateKey] !== '') {
            $options['date'] = $options[$dateKey];
        }
    }
    if ($options['hotel-id'] === '' && $options['hotel_id'] !== '') {
        $options['hotel-id'] = $options['hotel_id'];
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $options['date'])) {
        throw new InvalidArgumentException('Invalid --date, expected YYYY-MM-DD.');
    }

    $hotelId = null;
    if ($options['hotel-id'] !== '') {
        if (!ctype_digit($options['hotel-id']) || (int)$options['hotel-id'] <= 0) {
            throw new InvalidArgumentException('Invalid --hotel-id, expected a positive integer.');
        }
        $hotelId = (int)$options['hotel-id'];
    }

    $format = strtolower($options['format']);
    if (!in_array($format, ['json', 'markdown'], true)) {
        throw new InvalidArgumentException('Invalid --format, expected json or markdown.');
    }

    return [
        'date' => $options['date'],
        'hotel_id' => $hotelId,
        'format' => $format,
    ];
}

/**
 * @param mixed $value
 * @return array<string, mixed>
 */
function ctrip_pricing_packet_map(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @param mixed $value
 * @return array<int, mixed>
 */
function ctrip_pricing_packet_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @param array<int, string> $args
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function ctrip_pricing_packet_run_process(array $args, string $cwd): array
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
function ctrip_pricing_packet_decode_json_payload(string $stdout): array
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
    $payload = json_decode(substr($text, $start, $end - $start + 1), true);

    return is_array($payload) ? $payload : [];
}

/**
 * @param array<int, string> $baseArgs
 * @return array<int, string>
 */
function ctrip_pricing_packet_scoped_args(array $baseArgs, string $date, ?int $hotelId): array
{
    $args = array_merge($baseArgs, ['--date=' . $date]);
    if ($hotelId !== null) {
        $args[] = '--hotel-id=' . $hotelId;
    }

    return $args;
}

/**
 * @param array<int, string> $args
 */
function ctrip_pricing_packet_command_text(array $args): string
{
    return implode(' ', array_map(static function (string $arg): string {
        return str_contains($arg, ' ') ? '"' . str_replace('"', '\"', $arg) . '"' : $arg;
    }, $args));
}

/**
 * @param array<int, string> $args
 * @return array{run:array{exit_code:int,stdout:string,stderr:string},payload:array<string,mixed>,command:array<int,string>}
 */
function ctrip_pricing_packet_run_json(string $root, array $args): array
{
    $run = ctrip_pricing_packet_run_process($args, $root);

    return [
        'run' => $run,
        'payload' => ctrip_pricing_packet_decode_json_payload($run['stdout']),
        'command' => $args,
    ];
}

/**
 * @param array{exit_code:int,stdout:string,stderr:string} $run
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function ctrip_pricing_packet_process_summary(array $run, array $payload): array
{
    return [
        'exit_code' => $run['exit_code'],
        'status' => $payload['status'] ?? null,
        'mode' => $payload['mode'] ?? null,
        'error' => $payload['error'] ?? null,
        'stderr' => substr(trim($run['stderr']), 0, 300),
    ];
}

/**
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed> $details
 */
function ctrip_pricing_packet_check(array &$checks, string $code, bool $ok, string $message, array $details = []): void
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
 * @return array<string, array<int, string>>
 */
function ctrip_pricing_packet_required_fields(): array
{
    return [
        'room_types' => [
            'key',
            'name',
            'base_price',
            'min_price',
            'max_price',
            'room_count',
            'is_enabled',
        ],
        'demand_forecasts' => [
            'room_type_key',
            'forecast_date',
            'predicted_occupancy',
            'predicted_demand',
            'confidence_score',
            'forecast_method',
        ],
        'competitor_price_samples' => [
            'room_type_key',
            'analysis_date',
            'competitor_name',
            'our_price',
            'competitor_price',
            'ota_platform=ctrip',
        ],
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function ctrip_pricing_packet_collection_priorities(array $observedHints, array $requiredBeforeExecute, array $candidateSourceAudit): array
{
    $tableCounts = ctrip_pricing_packet_map($candidateSourceAudit['required_input_table_counts'] ?? []);
    $pricingCounts = ctrip_pricing_packet_map($candidateSourceAudit['ctrip_room_pricing_evidence_counts'] ?? []);
    $required = array_flip(array_map('strval', $requiredBeforeExecute));

    return [
        [
            'input_code' => 'room_types_enabled',
            'required_now' => isset($required['room_types_enabled']),
            'current_table_count' => (int)($tableCounts['room_types_enabled'] ?? 0),
            'ctrip_source_hint_present' => (bool)($observedHints['room_type_key_or_name'] ?? false),
            'hint_counts' => [
                'online_daily_data_room_like_target_date' => (int)($pricingCounts['online_daily_data_room_like_target_date'] ?? 0),
                'online_daily_data_room_type_key_target_date' => (int)($pricingCounts['online_daily_data_room_type_key_target_date'] ?? 0),
            ],
            'operator_action' => 'Confirm the Ctrip room type key/name and sellable room count in pricing-input-fillable.json.',
            'acceptable_source' => 'Ctrip backend room/rate display, PMS room mapping, or operator-approved room inventory note.',
            'must_not_use' => 'Do not infer enabled room types from traffic, peer-rank, sample, or whole-hotel rows.',
        ],
        [
            'input_code' => 'floor_price_or_min_rate_guard',
            'required_now' => isset($required['floor_price_or_min_rate_guard']),
            'current_table_count' => (int)($tableCounts['room_types_with_price_guards'] ?? 0),
            'ctrip_source_hint_present' => (bool)($observedHints['price_or_rate_key'] ?? false),
            'hint_counts' => [
                'online_daily_data_price_guard_key_target_date' => (int)($pricingCounts['online_daily_data_price_guard_key_target_date'] ?? 0),
                'online_daily_data_price_guard_key_all_dates' => (int)($pricingCounts['online_daily_data_price_guard_key_all_dates'] ?? 0),
            ],
            'operator_action' => 'Fill base_price, min_price, and max_price with operator-approved Ctrip pricing guards.',
            'acceptable_source' => 'Current Ctrip rate view plus operator-approved floor/protection and upper guard policy.',
            'must_not_use' => 'Do not derive floor or guard prices from OTA average price fields alone.',
        ],
        [
            'input_code' => 'demand_forecast',
            'required_now' => isset($required['demand_forecast']),
            'current_table_count' => (int)($tableCounts['demand_forecasts_target_date'] ?? 0),
            'ctrip_source_hint_present' => (bool)($observedHints['demand_or_forecast_key'] ?? false),
            'hint_counts' => [],
            'operator_action' => 'Provide target-date predicted_occupancy, predicted_demand, confidence_score, and forecast source.',
            'acceptable_source' => 'Operator forecast, approved forecast model output, booking pace review, or revenue-manager note.',
            'must_not_use' => 'Do not treat Ctrip traffic, exposure, or rank metrics as a demand forecast by default.',
        ],
        [
            'input_code' => 'competitor_price_samples',
            'required_now' => isset($required['competitor_price_samples']),
            'current_table_count' => (int)($tableCounts['competitor_analysis_ctrip_recent_7d'] ?? 0),
            'ctrip_source_hint_present' => (bool)($observedHints['competitor_key'] ?? false),
            'hint_counts' => [],
            'operator_action' => 'Fill recent-7-day Ctrip competitor_name, our_price, and competitor_price for the same room mapping.',
            'acceptable_source' => 'Ctrip competitor comparison view or manually reviewed Ctrip competitor price screenshot/source note.',
            'must_not_use' => 'Do not use Meituan competitor prices or stale whole-hotel competitor averages.',
        ],
    ];
}

/**
 * @return array<string, string>
 */
function ctrip_pricing_packet_allowed_commands(string $date, ?int $hotelId): array
{
    $hotelArg = $hotelId === null ? '' : ' --hotel-id=' . $hotelId;

    return [
        'operator_packet' => 'npm.cmd run report:revenue-ai-ctrip-pricing-operator-packet -- --date=' . $date . $hotelArg . ' --format=markdown',
        'export_operator_bundle' => 'npm.cmd run export:revenue-ai-ctrip-operator-bundle -- --date=' . $date . $hotelArg . ' --output-dir=<operator-bundle-dir>',
        'inspect_current_ota_evidence' => 'npm.cmd run inspect:revenue-ai-ctrip-pricing-sources -- --date=' . $date . $hotelArg,
        'export_template' => 'npm.cmd run export:revenue-ai-ctrip-pricing-template -- --date=' . $date . $hotelArg . ' --output=<draft-json-path>',
        'lint_only' => 'npm.cmd run lint:revenue-ai-ctrip-pricing-inputs -- --file=<filled-json-path> --date=' . $date . $hotelArg,
        'dry_run' => 'npm.cmd run import:revenue-ai-ctrip-pricing-inputs -- --file=<filled-json-path> --date=' . $date . $hotelArg,
        'validate_only' => 'npm.cmd run validate:revenue-ai-ctrip-pricing-inputs -- --file=<filled-json-path> --date=' . $date . $hotelArg,
        'pre_execute_gate' => 'npm.cmd run verify:revenue-ai-ctrip-pricing-file -- --file=<filled-json-path> --date=' . $date . $hotelArg,
        'gate_then_execute_and_generate_pending_review' => 'npm.cmd run run:revenue-ai-ctrip-pricing-file-to-pending-review -- --file=<filled-json-path> --date=' . $date . $hotelArg . ' --execute=1 --generate=1',
        'execute_inputs_only' => 'npm.cmd run import:revenue-ai-ctrip-pricing-inputs:execute -- --file=<filled-json-path> --date=' . $date . $hotelArg,
        'execute_and_generate_pending_review' => 'npm.cmd run import:revenue-ai-ctrip-pricing-inputs:execute -- --file=<filled-json-path> --date=' . $date . $hotelArg . ' --generate=1',
        'pending_review_packet' => 'npm.cmd run report:revenue-ai-ctrip-pending-review-packet -- --date=' . $date . $hotelArg . ' --format=markdown',
        'verify_pending_review_packet' => 'npm.cmd run verify:revenue-ai-ctrip-pending-review-packet -- --date=' . $date . $hotelArg,
        'export_review_decision_template' => 'npm.cmd run export:revenue-ai-ctrip-review-template -- --date=' . $date . $hotelArg . ' --suggestion-id=<pending-suggestion-id> --output=<review-decision-json-path>',
        'validate_review_decision' => 'npm.cmd run run:revenue-ai-ctrip-review-decision -- --file=<review-decision-json-path> --date=' . $date . $hotelArg,
        'execute_review_decision' => 'npm.cmd run run:revenue-ai-ctrip-review-decision -- --file=<review-decision-json-path> --date=' . $date . $hotelArg . ' --execute=1',
        'execute_review_decision_and_create_operation_intent' => 'npm.cmd run run:revenue-ai-ctrip-review-decision -- --file=<review-decision-json-path> --date=' . $date . $hotelArg . ' --execute=1 --create-intent=1',
        'verify_review_decision' => 'npm.cmd run verify:revenue-ai-ctrip-review-decision -- --date=' . $date . $hotelArg,
        'verify_operation_roi_boundary' => 'npm.cmd run verify:revenue-ai-ctrip-operation-roi -- --date=' . $date . $hotelArg,
        'verify_current_scope' => 'npm.cmd run verify:revenue-ai-ctrip-scope -- --date=' . $date . $hotelArg,
    ];
}

/**
 * @return array<int, string>
 */
function ctrip_pricing_packet_forbidden_shortcuts(): array
{
    return [
        'Do not use Meituan rows or whole-hotel operating values in this Ctrip OTA channel packet.',
        'Do not convert traffic, business, quality, or peer-rank rows into room types, demand forecasts, or competitor prices.',
        'Do not fill missing prices with sample, guessed, fallback, or verifier-only values.',
        'Do not set auto_write_ota=true or create OTA price writes from this packet.',
        'Do not create operation execution or investment decisions before pending AI suggestions pass manual review and ROI evidence.',
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_pricing_packet_render_markdown(array $payload): string
{
    $scope = ctrip_pricing_packet_map($payload['scope'] ?? []);
    $blocker = ctrip_pricing_packet_map($payload['current_blocker'] ?? []);
    $sourceAudit = ctrip_pricing_packet_map($payload['source_audit'] ?? []);
    $candidateSourceAudit = ctrip_pricing_packet_map($payload['candidate_source_audit'] ?? []);
    $commands = ctrip_pricing_packet_map($payload['allowed_commands'] ?? []);
    $requiredFields = ctrip_pricing_packet_map($payload['operator_required_fields'] ?? []);
    $collectionPriorities = ctrip_pricing_packet_list($payload['operator_collection_priorities'] ?? []);
    $locatorItems = ctrip_pricing_packet_map(ctrip_pricing_packet_map($payload['operator_input_locators'] ?? [])['items'] ?? []);

    $lines = [];
    $lines[] = '# Ctrip Revenue AI Pricing Operator Packet';
    $lines[] = '';
    $lines[] = '- status: `' . (string)($payload['status'] ?? 'unknown') . '`';
    $lines[] = '- business_date: `' . (string)($scope['business_date'] ?? '') . '`';
    $lines[] = '- platform: `ctrip`';
    $lines[] = '- source_scope: `ctrip_ota_channel`';
    $lines[] = '- hotel_id: `' . (string)($scope['hotel_id'] ?? 'unknown') . '`';
    $lines[] = '- database_written: `false`';
    $lines[] = '- raw_values_exposed: `false`';
    $lines[] = '- auto_write_ota: `false`';
    $lines[] = '';
    $lines[] = '## Current Blocker';
    $lines[] = '';
    $lines[] = '- pricing_generation_preflight.status: `' . (string)($blocker['status'] ?? 'unknown') . '`';
    $lines[] = '- reason: `' . (string)($blocker['reason'] ?? 'unknown') . '`';
    $lines[] = '- room_type_count: `' . (string)($blocker['room_type_count'] ?? 'unknown') . '`';
    $lines[] = '- create_candidate_count: `' . (string)($blocker['create_candidate_count'] ?? 'unknown') . '`';
    $lines[] = '- pending_suggestion_count: `' . (string)($blocker['pending_suggestion_count'] ?? 'unknown') . '`';
    $lines[] = '';
    $lines[] = '## Existing Ctrip Evidence';
    $lines[] = '';
    $lines[] = '- online_daily_data row_count: `' . (string)($sourceAudit['row_count'] ?? 'unknown') . '`';
    $lines[] = '- rows_with_field_facts: `' . (string)($sourceAudit['rows_with_field_facts'] ?? 'unknown') . '`';
    $lines[] = '- data_type_counts: `' . json_encode($sourceAudit['data_type_counts'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '`';
    $lines[] = '- can_prefill_import_file: `' . (($sourceAudit['can_prefill_import_file'] ?? null) === false ? 'false' : 'unknown') . '`';
    $lines[] = '- required_before_execute: `' . implode(', ', array_map('strval', ctrip_pricing_packet_list($sourceAudit['required_before_execute'] ?? []))) . '`';
    $lines[] = '';
    $lines[] = '## Candidate Source Audit';
    $lines[] = '';
    $lines[] = '- required_input_table_counts: `' . json_encode($candidateSourceAudit['required_input_table_counts'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '`';
    $lines[] = '- ctrip_room_pricing_evidence_counts: `' . json_encode($candidateSourceAudit['ctrip_room_pricing_evidence_counts'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '`';
    $eligibility = ctrip_pricing_packet_map($candidateSourceAudit['prefill_eligibility'] ?? []);
    $lines[] = '- prefill_eligibility.status: `' . (string)($eligibility['status'] ?? 'unknown') . '`';
    $lines[] = '- can_generate_operator_file_from_existing_db: `' . (($eligibility['can_generate_operator_file_from_existing_db'] ?? null) === false ? 'false' : 'unknown') . '`';
    $lines[] = '';
    if ($collectionPriorities !== []) {
        $lines[] = '## Operator Collection Priorities';
        $lines[] = '';
        $lines[] = '| Input | Required now | Current table count | Ctrip source hint | Operator action |';
        $lines[] = '|---|---:|---:|---:|---|';
        foreach ($collectionPriorities as $priority) {
            $row = ctrip_pricing_packet_map($priority);
            $lines[] = '| `' . (string)($row['input_code'] ?? '') . '` | `'
                . ((bool)($row['required_now'] ?? false) ? 'true' : 'false') . '` | `'
                . (string)($row['current_table_count'] ?? 0) . '` | `'
                . ((bool)($row['ctrip_source_hint_present'] ?? false) ? 'true' : 'false') . '` | '
                . (string)($row['operator_action'] ?? '') . ' |';
        }
        $lines[] = '';
        $lines[] = '> Ctrip source hints are for locating evidence only; they are not importable values until an operator fills and validates the input file.';
        $lines[] = '';
    }
    if ($locatorItems !== []) {
        $lines[] = '## Operator Evidence Locators';
        $lines[] = '';
        $lines[] = '| Input | Locator status | Count | Row ids | Operator use |';
        $lines[] = '|---|---|---:|---|---|';
        foreach ($locatorItems as $inputCode => $locator) {
            $row = ctrip_pricing_packet_map($locator);
            $rowIds = [];
            foreach (ctrip_pricing_packet_list($row['locators'] ?? []) as $item) {
                $locatorRow = ctrip_pricing_packet_map($item);
                if (isset($locatorRow['id'])) {
                    $rowIds[] = (string)$locatorRow['id'];
                }
            }
            $lines[] = '| `' . (string)$inputCode . '` | `'
                . (string)($row['status'] ?? 'unknown') . '` | `'
                . (string)($row['locator_count'] ?? 0) . '` | `'
                . ($rowIds === [] ? 'none' : implode(', ', array_slice($rowIds, 0, 5))) . '` | '
                . (string)($row['operator_use'] ?? '') . ' |';
        }
        $lines[] = '';
        $lines[] = '> Locator row ids and source traces are metadata only. They are not pricing, demand, or competitor values.';
        $lines[] = '';
    }
    $lines[] = '## Operator Required Fields';
    foreach ($requiredFields as $group => $fields) {
        $lines[] = '';
        $lines[] = '- ' . (string)$group . ': `' . implode(', ', array_map('strval', ctrip_pricing_packet_list($fields))) . '`';
    }
    $lines[] = '';
    $lines[] = '## Allowed Sequence';
    foreach ($commands as $key => $command) {
        $lines[] = '';
        $lines[] = '1. `' . (string)$key . '`:';
        $lines[] = '   ```powershell';
        $lines[] = '   ' . (string)$command;
        $lines[] = '   ```';
    }
    $lines[] = '';
    $lines[] = '## Forbidden Shortcuts';
    foreach (ctrip_pricing_packet_list($payload['forbidden_shortcuts'] ?? []) as $shortcut) {
        $lines[] = '';
        $lines[] = '- ' . (string)$shortcut;
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_pricing_packet_finish(array $payload, int $exitCode, string $format): void
{
    if ($format === 'markdown') {
        echo ctrip_pricing_packet_render_markdown($payload);
    } else {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    exit($exitCode);
}

try {
    $options = ctrip_pricing_packet_parse_args($argv);
    $root = dirname(__DIR__);

    $inspect = ctrip_pricing_packet_run_json($root, ctrip_pricing_packet_scoped_args(
        [PHP_BINARY, 'scripts/inspect_revenue_ai_ctrip_pricing_source_fields.php'],
        $options['date'],
        $options['hotel_id']
    ));
    $template = ctrip_pricing_packet_run_json($root, ctrip_pricing_packet_scoped_args(
        [PHP_BINARY, 'scripts/import_revenue_ai_ctrip_pricing_inputs.php', '--print-current-template'],
        $options['date'],
        $options['hotel_id']
    ));
    $scope = ctrip_pricing_packet_run_json($root, ctrip_pricing_packet_scoped_args(
        [PHP_BINARY, 'scripts/verify_revenue_ai_ctrip_scope.php'],
        $options['date'],
        $options['hotel_id']
    ));

    $inspectPayload = $inspect['payload'];
    $templatePayload = $template['payload'];
    $scopePayload = $scope['payload'];

    $inspectScope = ctrip_pricing_packet_map($inspectPayload['scope'] ?? []);
    $inspectSummary = ctrip_pricing_packet_map($inspectPayload['summary'] ?? []);
    $candidateSourceAudit = ctrip_pricing_packet_map($inspectPayload['candidate_source_audit'] ?? []);
    $operatorInputLocators = ctrip_pricing_packet_map($inspectPayload['operator_input_locators'] ?? []);
    $operatorGap = ctrip_pricing_packet_map($inspectPayload['operator_gap_summary'] ?? []);
    $operatorGapPreflight = ctrip_pricing_packet_map($operatorGap['current_preflight'] ?? []);
    $templateBody = ctrip_pricing_packet_map($templatePayload['template'] ?? []);
    $templatePreflight = ctrip_pricing_packet_map($templateBody['current_preflight'] ?? ($templatePayload['current_preflight'] ?? []));
    $scopePreflight = ctrip_pricing_packet_map($scopePayload['pricing_generation_preflight'] ?? []);
    $preflight = $templatePreflight !== [] ? $templatePreflight : ($operatorGapPreflight !== [] ? $operatorGapPreflight : $scopePreflight);

    $hotelId = $options['hotel_id']
        ?? (int)($templateBody['hotel_id'] ?? 0)
        ?: (int)($templatePayload['hotel_id'] ?? 0)
        ?: (int)($inspectScope['hotel_id'] ?? 0)
        ?: null;
    $canPrefill = $operatorGap['can_prefill_import_file'] ?? null;
    $requiredBeforeExecute = ctrip_pricing_packet_list($operatorGap['required_before_execute'] ?? ($templatePreflight['required_input_codes'] ?? []));

    $commands = ctrip_pricing_packet_allowed_commands($options['date'], $hotelId);
    $collectionPriorities = ctrip_pricing_packet_collection_priorities(
        ctrip_pricing_packet_map($operatorGap['observed_hints'] ?? []),
        $requiredBeforeExecute,
        $candidateSourceAudit
    );
    $checks = [];
    ctrip_pricing_packet_check(
        $checks,
        'source_audit_passed',
        $inspect['run']['exit_code'] === 0 && ($inspectPayload['status'] ?? null) === 'passed',
        'Read-only Ctrip source audit must pass before operator fill.'
    );
    ctrip_pricing_packet_check(
        $checks,
        'template_export_passed',
        $template['run']['exit_code'] === 0 && (($templatePayload['source_scope'] ?? null) === 'ctrip_ota_channel' || ($templateBody['source_scope'] ?? null) === 'ctrip_ota_channel'),
        'Current Ctrip pricing input template must be exportable.'
    );
    ctrip_pricing_packet_check(
        $checks,
        'runtime_scope_passed',
        $scope['run']['exit_code'] === 0 && ($scopePayload['status'] ?? null) === 'passed',
        'Current Revenue AI runtime scope must pass in Ctrip-only mode.'
    );
    ctrip_pricing_packet_check(
        $checks,
        'no_meituan_source_summary',
        !str_contains(strtolower(json_encode($inspectSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), 'meituan'),
        'Operator packet source summary must not include Meituan evidence.'
    );
    ctrip_pricing_packet_check(
        $checks,
        'no_auto_write_ota',
        ($templatePayload['auto_write_ota'] ?? ($templateBody['auto_write_ota'] ?? false)) === false,
        'Template must keep OTA price writes disabled.'
    );
    ctrip_pricing_packet_check(
        $checks,
        'operator_input_still_required',
        $canPrefill === false,
        'Existing OTA metadata cannot be treated as importable pricing inputs.'
    );
    ctrip_pricing_packet_check(
        $checks,
        'candidate_source_audit_counts_only',
        ($candidateSourceAudit['raw_values_exposed'] ?? true) === false
            && ($candidateSourceAudit['database_written'] ?? true) === false,
        'Candidate source audit must expose counts only and keep the database unchanged.'
    );
    ctrip_pricing_packet_check(
        $checks,
        'operator_input_locators_metadata_only',
        ($operatorInputLocators['raw_values_exposed'] ?? true) === false
            && ($operatorInputLocators['database_written'] ?? true) === false,
        'Operator input locators must expose metadata only and keep raw values hidden.'
    );
    ctrip_pricing_packet_check(
        $checks,
        'pre_execute_gate_present',
        isset($commands['pre_execute_gate']),
        'Real operator-filled files must pass the pre-execute gate before execute.'
    );
    ctrip_pricing_packet_check(
        $checks,
        'post_review_roi_boundary_gate_present',
        isset($commands['verify_operation_roi_boundary'])
            && str_contains($commands['verify_operation_roi_boundary'], 'verify:revenue-ai-ctrip-operation-roi'),
        'Approved Ctrip AI suggestions must pass the operation ROI boundary verifier before investment decisions.'
    );
    $demandPriority = [];
    foreach ($collectionPriorities as $priority) {
        if (($priority['input_code'] ?? '') === 'demand_forecast') {
            $demandPriority = $priority;
            break;
        }
    }
    ctrip_pricing_packet_check(
        $checks,
        'operator_collection_priorities_present',
        $collectionPriorities !== []
            && ($demandPriority['required_now'] ?? false) === true
            && ($demandPriority['ctrip_source_hint_present'] ?? true) === false,
        'Operator packet must expose collection priorities and keep missing demand forecast explicit.'
    );

    $failed = array_values(array_filter(
        $checks,
        static fn(array $check): bool => ($check['status'] ?? '') !== 'passed'
    ));

    $payload = [
        'status' => $failed === [] ? 'passed' : 'failed',
        'scope' => [
            'business_date' => $options['date'],
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $hotelId,
            'source_scope' => 'ctrip_ota_channel',
            'source_policy' => 'read_only_operator_decision_packet_no_values_no_import',
            'raw_values_exposed' => false,
            'database_written' => false,
            'auto_write_ota' => false,
            'meituan_scope_included' => false,
        ],
        'current_blocker' => [
            'status' => $preflight['status'] ?? null,
            'reason' => $preflight['reason'] ?? null,
            'target_hotel_ids' => $preflight['target_hotel_ids'] ?? [],
            'target_date_rows' => $preflight['target_date_rows'] ?? null,
            'room_type_count' => $preflight['room_type_count'] ?? null,
            'pending_suggestion_count' => $preflight['pending_suggestion_count'] ?? null,
            'demand_forecast_count' => $preflight['demand_forecast_count'] ?? null,
            'competitor_analysis_recent_count' => $preflight['competitor_analysis_recent_count'] ?? null,
            'create_candidate_count' => $preflight['create_candidate_count'] ?? null,
            'required_before_execute' => $requiredBeforeExecute,
        ],
        'source_audit' => [
            'row_count' => $inspectSummary['row_count'] ?? null,
            'source_counts' => $inspectSummary['source_counts'] ?? [],
            'system_hotel_id_counts' => $inspectSummary['system_hotel_id_counts'] ?? [],
            'data_type_counts' => $inspectSummary['data_type_counts'] ?? [],
            'raw_object_rows' => $inspectSummary['raw_object_rows'] ?? null,
            'rows_with_field_facts' => $inspectSummary['rows_with_field_facts'] ?? null,
            'fact_metric_keys' => $inspectSummary['fact_metric_keys'] ?? [],
            'raw_key_samples_matching_pricing_terms' => $inspectSummary['raw_key_samples_matching_pricing_terms'] ?? [],
            'observed_hints' => $operatorGap['observed_hints'] ?? [],
            'can_prefill_import_file' => $canPrefill,
            'prefill_policy' => $operatorGap['prefill_policy'] ?? null,
            'reason' => $operatorGap['reason'] ?? null,
            'required_before_execute' => $requiredBeforeExecute,
        ],
        'candidate_source_audit' => $candidateSourceAudit,
        'operator_input_locators' => $operatorInputLocators,
        'operator_collection_priorities' => $collectionPriorities,
        'operator_required_fields' => ctrip_pricing_packet_required_fields(),
        'fillable_template' => $templateBody !== [] ? $templateBody : $templatePayload,
        'template_export_summary' => [
            'status' => $templatePayload['status'] ?? null,
            'source_scope' => $templatePayload['source_scope'] ?? null,
            'source_policy' => $templatePayload['source_policy'] ?? null,
            'auto_write_ota' => $templatePayload['auto_write_ota'] ?? null,
            'summary' => $templatePayload['summary'] ?? [],
        ],
        'allowed_commands' => $commands,
        'stop_conditions' => [
            'Stop if source_scope is not ctrip_ota_channel.',
            'Stop if any filled value is a placeholder, sample, guessed, fallback, or whole-hotel value.',
            'Stop if pre_execute_gate fails.',
            'Stop if dry-run does not produce at least one Ctrip create candidate or pending manual-review suggestion.',
            'Stop before operation execution until an AI pricing suggestion is manually reviewed.',
        ],
        'forbidden_shortcuts' => ctrip_pricing_packet_forbidden_shortcuts(),
        'processes' => [
            'source_audit' => ctrip_pricing_packet_process_summary($inspect['run'], $inspectPayload),
            'template_export' => ctrip_pricing_packet_process_summary($template['run'], $templatePayload),
            'runtime_scope' => ctrip_pricing_packet_process_summary($scope['run'], $scopePayload),
        ],
        'checks' => $checks,
    ];

    ctrip_pricing_packet_finish($payload, $failed === [] ? 0 : 1, $options['format']);
} catch (Throwable $e) {
    $format = 'json';
    try {
        $parsed = ctrip_pricing_packet_parse_args($argv);
        $format = $parsed['format'];
    } catch (Throwable) {
        $format = 'json';
    }
    ctrip_pricing_packet_finish([
        'status' => 'failed',
        'error' => $e->getMessage(),
    ], 1, $format);
}
