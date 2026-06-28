<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{date:string,hotel_id:int|null,file:string}
 */
function ctrip_pricing_pipeline_parse_args(array $argv): array
{
    $options = [
        'file' => '',
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
    if ((string)$options['hotel-id'] === '' && (string)$options['hotel_id'] !== '') {
        $options['hotel-id'] = (string)$options['hotel_id'];
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$options['date'])) {
        throw new InvalidArgumentException('Invalid --date, expected YYYY-MM-DD.');
    }

    $hotelId = null;
    if ((string)$options['hotel-id'] !== '') {
        if (!ctype_digit((string)$options['hotel-id']) || (int)$options['hotel-id'] <= 0) {
            throw new InvalidArgumentException('Invalid --hotel-id, expected a positive integer.');
        }
        $hotelId = (int)$options['hotel-id'];
    }

    return [
        'date' => (string)$options['date'],
        'hotel_id' => $hotelId,
        'file' => (string)$options['file'],
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_pricing_pipeline_finish(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

/**
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed> $details
 */
function ctrip_pricing_pipeline_check(array &$checks, string $code, bool $ok, string $message, array $details = []): bool
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

    return $ok;
}

/**
 * @param mixed $value
 * @return array<string, mixed>
 */
function ctrip_pricing_pipeline_map(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @param mixed $value
 * @return array<int, mixed>
 */
function ctrip_pricing_pipeline_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @param array<int, string> $args
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function ctrip_pricing_pipeline_run_process(array $args, string $cwd): array
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
function ctrip_pricing_pipeline_decode_json_payload(string $stdout): array
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
 * @return array<string, mixed>
 */
function ctrip_pricing_pipeline_run_summary(array $run, array $payload): array
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
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function ctrip_pricing_pipeline_run_importer(string $root, array $args): array
{
    return ctrip_pricing_pipeline_run_process(
        array_merge([PHP_BINARY, 'scripts/import_revenue_ai_ctrip_pricing_inputs.php'], $args),
        $root
    );
}

/**
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function ctrip_pricing_pipeline_run_scope_verifier(string $root, string $date, ?int $hotelId): array
{
    $args = [
        PHP_BINARY,
        'scripts/verify_revenue_ai_ctrip_scope.php',
        '--date=' . $date,
    ];
    if ($hotelId !== null) {
        $args[] = '--hotel-id=' . $hotelId;
    }

    return ctrip_pricing_pipeline_run_process($args, $root);
}

/**
 * @return array<string, mixed>
 */
function ctrip_pricing_pipeline_fingerprint_scope(array $scopePayload): array
{
    $summary = ctrip_pricing_pipeline_map($scopePayload['summary'] ?? []);
    $preflight = ctrip_pricing_pipeline_map($summary['pricing_generation_preflight'] ?? []);
    $reviewQueue = ctrip_pricing_pipeline_map($summary['review_queue'] ?? []);
    $executionSummary = ctrip_pricing_pipeline_map($summary['execution_summary'] ?? []);

    return [
        'source_channels' => ctrip_pricing_pipeline_list($summary['source_channels'] ?? []),
        'preflight_status' => $preflight['status'] ?? null,
        'preflight_reason' => $preflight['reason'] ?? null,
        'room_type_count' => $preflight['room_type_count'] ?? null,
        'pending_suggestion_count' => $preflight['pending_suggestion_count'] ?? null,
        'create_candidate_count' => $preflight['create_candidate_count'] ?? null,
        'review_queue_status' => $reviewQueue['status'] ?? null,
        'review_queue_pending_count' => $reviewQueue['pending_count'] ?? null,
        'execution_status' => $executionSummary['status'] ?? null,
        'execution_total_count' => $executionSummary['total_count'] ?? null,
        'execution_roi_ready_count' => $executionSummary['roi_ready_count'] ?? null,
    ];
}

/**
 * @param array<int, mixed> $values
 * @return array<int, int>
 */
function ctrip_pricing_pipeline_positive_ints(array $values): array
{
    return array_values(array_filter(
        array_map('intval', $values),
        static fn(int $value): bool => $value > 0
    ));
}

function ctrip_pricing_pipeline_resolve_hotel_id(array $scopePayload, array $templatePayload, ?int $requestedHotelId): int
{
    if ($requestedHotelId !== null && $requestedHotelId > 0) {
        return $requestedHotelId;
    }

    $templateSummary = ctrip_pricing_pipeline_map($templatePayload['summary'] ?? []);
    $templateHotelId = (int)($templateSummary['hotel_id'] ?? 0);
    if ($templateHotelId > 0) {
        return $templateHotelId;
    }

    $summary = ctrip_pricing_pipeline_map($scopePayload['summary'] ?? []);
    $preflight = ctrip_pricing_pipeline_map($summary['pricing_generation_preflight'] ?? []);
    $targetHotelIds = ctrip_pricing_pipeline_positive_ints(ctrip_pricing_pipeline_list($preflight['target_hotel_ids'] ?? []));
    if ($targetHotelIds !== []) {
        return $targetHotelIds[0];
    }

    throw new RuntimeException('No Ctrip target hotel was found for the requested date.');
}

/**
 * @return array<string, mixed>
 */
function ctrip_pricing_pipeline_filled_payload(string $date, int $hotelId, string $marker): array
{
    $roomKey = 'room_' . $marker;
    $roomName = 'Ctrip Pipeline Room ' . substr($marker, -8);

    return [
        'business_date' => $date,
        'hotel_id' => $hotelId,
        'platform' => 'ctrip',
        'input_scope' => 'manual_pricing_configuration',
        'source_scope' => 'ctrip_ota_channel',
        'evidence_status' => 'verifier_transaction_only',
        'target_workflow' => 'ctrip_revenue_ai_pricing_generation',
        'auto_write_ota' => false,
        'room_types' => [
            [
                'key' => $roomKey,
                'name' => $roomName,
                'base_price' => 320,
                'min_price' => 260,
                'max_price' => 460,
                'room_count' => 8,
                'sort_order' => 9998,
                'is_enabled' => 1,
            ],
        ],
        'demand_forecasts' => [
            [
                'room_type_key' => $roomKey,
                'forecast_date' => $date,
                'predicted_occupancy' => 91,
                'predicted_demand' => 8,
                'confidence_score' => 0.84,
                'forecast_method' => 3,
                'remark' => 'transaction-only Ctrip pricing input pipeline verifier',
            ],
        ],
        'competitor_price_samples' => [
            [
                'room_type_key' => $roomKey,
                'analysis_date' => $date,
                'competitor_name' => 'Ctrip Pipeline Competitor',
                'our_price' => 320,
                'competitor_price' => 365,
                'ota_platform' => 'ctrip',
            ],
        ],
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_pricing_pipeline_write_json(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode verifier input JSON.');
    }
    if (file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write verifier input JSON: ' . $path);
    }
}

/**
 * @param mixed $value
 */
function ctrip_pricing_pipeline_contains_meituan(mixed $value): bool
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return true;
    }
    $lower = strtolower($json);

    return str_contains($lower, 'meituan') || str_contains($json, "\u{7F8E}\u{56E2}");
}

/**
 * @param array<int, string> $files
 */
function ctrip_pricing_pipeline_cleanup(array $files): void
{
    foreach ($files as $file) {
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }
}

/**
 * @return array<string, mixed>
 */
function ctrip_pricing_pipeline_load_input_payload(string $file): array
{
    if ($file === '' || !is_file($file)) {
        throw new InvalidArgumentException('Input file does not exist: ' . $file);
    }
    $json = file_get_contents($file);
    if (!is_string($json) || trim($json) === '') {
        throw new InvalidArgumentException('Input file is empty: ' . $file);
    }
    $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Input file is not valid JSON: ' . $file);
    }

    return $payload;
}

$root = dirname(__DIR__);
$checks = [];
$tempFiles = [];

try {
    $options = ctrip_pricing_pipeline_parse_args($argv);
    $date = $options['date'];
    $marker = 'ctrip_pipeline_' . date('YmdHis') . '_' . getmypid();
    $templatePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $marker . '_template.json';
    $filledPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $marker . '_filled.json';
    $tempFiles = [$templatePath, $filledPath];

    if ($options['file'] !== '') {
        $inputPayload = ctrip_pricing_pipeline_load_input_payload($options['file']);
        $inputPath = realpath($options['file']);
        if (!is_string($inputPath)) {
            throw new InvalidArgumentException('Unable to resolve input file path: ' . $options['file']);
        }
        $payloadHotelId = (int)($inputPayload['hotel_id'] ?? 0);
        $effectiveHotelId = $options['hotel_id'] ?? ($payloadHotelId > 0 ? $payloadHotelId : null);
        $inputBusinessDate = trim((string)($inputPayload['business_date'] ?? $inputPayload['date'] ?? ''));
        $fileHash = hash_file('sha256', $inputPath) ?: '';

        $scopeBeforeRun = ctrip_pricing_pipeline_run_scope_verifier($root, $date, $effectiveHotelId);
        $scopeBeforePayload = ctrip_pricing_pipeline_decode_json_payload($scopeBeforeRun['stdout']);
        $scopeBeforeFingerprint = ctrip_pricing_pipeline_fingerprint_scope($scopeBeforePayload);
        ctrip_pricing_pipeline_check(
            $checks,
            'current_scope_verifier_passes_before_real_input_gate',
            $scopeBeforeRun['exit_code'] === 0 && ($scopeBeforePayload['status'] ?? null) === 'passed',
            'Current Ctrip Revenue AI scope verifier passes before checking the operator input file.',
            ctrip_pricing_pipeline_run_summary($scopeBeforeRun, $scopeBeforePayload)
        );
        ctrip_pricing_pipeline_check(
            $checks,
            'real_input_file_loaded',
            $fileHash !== ''
                && strtolower((string)($inputPayload['platform'] ?? 'ctrip')) === 'ctrip'
                && (string)($inputPayload['source_scope'] ?? '') === 'ctrip_ota_channel'
                && ($inputPayload['auto_write_ota'] ?? false) !== true,
            'Operator-provided pricing input file is loaded and declares Ctrip OTA channel scope.',
            [
                'file_sha256' => $fileHash,
                'hotel_id' => $effectiveHotelId,
                'platform' => $inputPayload['platform'] ?? null,
                'source_scope' => $inputPayload['source_scope'] ?? null,
                'auto_write_ota' => $inputPayload['auto_write_ota'] ?? null,
            ]
        );
        ctrip_pricing_pipeline_check(
            $checks,
            'real_input_file_date_matches',
            $inputBusinessDate === '' || $inputBusinessDate === $date,
            'Operator-provided input date matches the verifier date.',
            ['input_business_date' => $inputBusinessDate, 'date' => $date]
        );

        $lintRun = ctrip_pricing_pipeline_run_importer($root, [
            '--lint-only=1',
            '--file=' . $inputPath,
            '--date=' . $date,
        ]);
        $lintPayload = ctrip_pricing_pipeline_decode_json_payload($lintRun['stdout']);
        $lintSummary = ctrip_pricing_pipeline_map($lintPayload['summary'] ?? []);
        ctrip_pricing_pipeline_check(
            $checks,
            'real_input_file_lint_passes_without_db',
            $lintRun['exit_code'] === 0
                && ($lintPayload['status'] ?? null) === 'passed'
                && ($lintPayload['mode'] ?? null) === 'lint_only'
                && ($lintSummary['database_touched'] ?? true) === false
                && (int)($lintSummary['issue_count'] ?? 1) === 0,
            'Operator-provided Ctrip pricing file passes lint-only validation without touching the database.',
            [
                'run' => ctrip_pricing_pipeline_run_summary($lintRun, $lintPayload),
                'summary' => $lintSummary,
            ]
        );

        $validateRun = ctrip_pricing_pipeline_run_importer($root, [
            '--validate-only=1',
            '--file=' . $inputPath,
            '--date=' . $date,
        ]);
        $validatePayload = ctrip_pricing_pipeline_decode_json_payload($validateRun['stdout']);
        $validateSummary = ctrip_pricing_pipeline_map($validatePayload['summary'] ?? []);
        $validatePreflight = ctrip_pricing_pipeline_map($validateSummary['pricing_generation_preflight'] ?? []);
        ctrip_pricing_pipeline_check(
            $checks,
            'real_input_file_validate_only_rolls_back_without_generation',
            $validateRun['exit_code'] === 0
                && ($validatePayload['status'] ?? null) === 'passed'
                && ($validatePayload['mode'] ?? null) === 'validate_only'
                && ($validateSummary['rolled_back'] ?? false) === true
                && ($validateSummary['committed'] ?? true) === false
                && ($validateSummary['generation'] ?? null) === null
                && ((int)($validatePreflight['create_candidate_count'] ?? 0) > 0 || (int)($validatePreflight['pending_suggestion_count'] ?? 0) > 0),
            'Validate-only mode proves the operator file can create or expose a Ctrip pricing candidate, disables generation, and rolls back.',
            [
                'run' => ctrip_pricing_pipeline_run_summary($validateRun, $validatePayload),
                'pricing_generation_preflight' => $validatePreflight,
            ]
        );

        $dryRun = ctrip_pricing_pipeline_run_importer($root, [
            '--file=' . $inputPath,
            '--date=' . $date,
        ]);
        $dryPayload = ctrip_pricing_pipeline_decode_json_payload($dryRun['stdout']);
        $drySummary = ctrip_pricing_pipeline_map($dryPayload['summary'] ?? []);
        $dryGeneration = ctrip_pricing_pipeline_map($drySummary['generation'] ?? []);
        ctrip_pricing_pipeline_check(
            $checks,
            'real_input_file_dry_run_generates_pending_review_and_rolls_back',
            $dryRun['exit_code'] === 0
                && ($dryPayload['status'] ?? null) === 'passed'
                && ($dryPayload['mode'] ?? null) === 'dry_run'
                && ($drySummary['rolled_back'] ?? false) === true
                && ($drySummary['committed'] ?? true) === false
                && ((int)($dryGeneration['created_count'] ?? 0) > 0 || (string)($dryGeneration['reason'] ?? '') === 'price_suggestions_pending_review'),
            'Dry-run creates or exposes the pending Ctrip AI review path and rolls back all writes.',
            [
                'run' => ctrip_pricing_pipeline_run_summary($dryRun, $dryPayload),
                'generation' => $dryGeneration,
            ]
        );
        ctrip_pricing_pipeline_check(
            $checks,
            'real_input_file_dry_run_keeps_manual_review_gate',
            ($dryGeneration['source_scope'] ?? null) === 'ctrip_ota_channel'
                && ctrip_pricing_pipeline_list($dryGeneration['source_channels'] ?? []) === ['ctrip']
                && ($dryGeneration['auto_write_ota'] ?? true) === false
                && (ctrip_pricing_pipeline_map($dryGeneration['ai_review_gate'] ?? [])['operation_intake_allowed'] ?? true) === false,
            'Dry-run stays Ctrip-only, manual-review gated, and never writes OTA.',
            $dryGeneration
        );

        $scopeAfterRun = ctrip_pricing_pipeline_run_scope_verifier($root, $date, $effectiveHotelId);
        $scopeAfterPayload = ctrip_pricing_pipeline_decode_json_payload($scopeAfterRun['stdout']);
        $scopeAfterFingerprint = ctrip_pricing_pipeline_fingerprint_scope($scopeAfterPayload);
        ctrip_pricing_pipeline_check(
            $checks,
            'real_input_file_scope_unchanged_after_rollback',
            $scopeAfterRun['exit_code'] === 0
                && ($scopeAfterPayload['status'] ?? null) === 'passed'
                && $scopeAfterFingerprint === $scopeBeforeFingerprint,
            'Current real DB Ctrip scope is unchanged after lint, validate-only, and dry-run rollback for the operator file.',
            [
                'before' => $scopeBeforeFingerprint,
                'after' => $scopeAfterFingerprint,
            ]
        );
        ctrip_pricing_pipeline_check(
            $checks,
            'real_input_file_meituan_not_present',
            !ctrip_pricing_pipeline_contains_meituan([
                'input_payload' => $inputPayload,
                'dry_run_scope' => $dryPayload['scope'] ?? null,
                'dry_run_summary' => $drySummary,
                'scope_after_summary' => $scopeAfterPayload['summary'] ?? null,
            ]),
            'Operator input pre-execute gate payloads do not include Meituan tokens.',
            ['source_channels' => $dryGeneration['source_channels'] ?? null]
        );

        $failures = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'passed'));
        ctrip_pricing_pipeline_cleanup($tempFiles);
        ctrip_pricing_pipeline_finish([
            'status' => $failures === [] ? 'passed' : 'failed',
            'scope' => [
                'business_date' => $date,
                'platform' => 'ctrip',
                'enabled_channels' => ['ctrip'],
                'hotel_id' => $effectiveHotelId,
                'source_scope' => 'ctrip_ota_channel',
                'source_policy' => 'operator_input_file_pre_execute_gate_rollback_only',
                'auto_write_ota' => false,
            ],
            'summary' => [
                'input_file_sha256' => $fileHash,
                'lint_passed' => ($lintPayload['status'] ?? null) === 'passed',
                'validate_only_rolled_back' => $validateSummary['rolled_back'] ?? null,
                'dry_run_rolled_back' => $drySummary['rolled_back'] ?? null,
                'dry_run_generation' => $dryGeneration,
                'current_scope_before' => $scopeBeforeFingerprint,
                'current_scope_after' => $scopeAfterFingerprint,
                'next_action' => 'If this is operator-verified real Ctrip evidence, the next allowed step is explicit execute; pending AI suggestions still require manual review before operation intake.',
            ],
            'checks' => $checks,
        ], $failures === [] ? 0 : 1);
    }

    $scopeBeforeRun = ctrip_pricing_pipeline_run_scope_verifier($root, $date, $options['hotel_id']);
    $scopeBeforePayload = ctrip_pricing_pipeline_decode_json_payload($scopeBeforeRun['stdout']);
    $scopeBeforeFingerprint = ctrip_pricing_pipeline_fingerprint_scope($scopeBeforePayload);
    ctrip_pricing_pipeline_check(
        $checks,
        'current_scope_verifier_passes_before_pipeline',
        $scopeBeforeRun['exit_code'] === 0 && ($scopeBeforePayload['status'] ?? null) === 'passed',
        'Current Ctrip Revenue AI scope verifier passes before pipeline dry-run.',
        ctrip_pricing_pipeline_run_summary($scopeBeforeRun, $scopeBeforePayload)
    );
    ctrip_pricing_pipeline_check(
        $checks,
        'current_real_db_gap_is_explicit',
        ($scopeBeforeFingerprint['preflight_status'] ?? null) !== null
            && (($scopeBeforeFingerprint['preflight_reason'] ?? null) !== null || $scopeBeforeFingerprint['preflight_status'] === 'ready'),
        'Current real DB preflight exposes a concrete reason, including room_types_empty when no Ctrip room types exist.',
        $scopeBeforeFingerprint
    );

    $exportArgs = ['--print-current-template', '--date=' . $date, '--output=' . $templatePath];
    if ($options['hotel_id'] !== null) {
        $exportArgs[] = '--hotel-id=' . $options['hotel_id'];
    }
    $exportRun = ctrip_pricing_pipeline_run_importer($root, $exportArgs);
    $exportPayload = ctrip_pricing_pipeline_decode_json_payload($exportRun['stdout']);
    $hotelId = ctrip_pricing_pipeline_resolve_hotel_id($scopeBeforePayload, $exportPayload, $options['hotel_id']);
    ctrip_pricing_pipeline_check(
        $checks,
        'current_template_export_writes_file',
        $exportRun['exit_code'] === 0
            && ($exportPayload['status'] ?? null) === 'template'
            && is_file($templatePath)
            && (string)(ctrip_pricing_pipeline_map($exportPayload['output_file'] ?? [])['path'] ?? '') !== '',
        'Current Ctrip pricing input template is exported to a file.',
        [
            'run' => ctrip_pricing_pipeline_run_summary($exportRun, $exportPayload),
            'output_file' => $exportPayload['output_file'] ?? null,
            'hotel_id' => $hotelId,
        ]
    );
    ctrip_pricing_pipeline_check(
        $checks,
        'current_template_scope_ctrip_only',
        ($exportPayload['source_scope'] ?? null) === 'ctrip_ota_channel'
            && ($exportPayload['auto_write_ota'] ?? true) === false
            && (string)(ctrip_pricing_pipeline_map($exportPayload['summary'] ?? [])['pricing_generation_preflight_reason'] ?? '') !== '',
        'Current template is Ctrip OTA channel scoped and carries the visible preflight reason.',
        [
            'source_scope' => $exportPayload['source_scope'] ?? null,
            'auto_write_ota' => $exportPayload['auto_write_ota'] ?? null,
            'summary' => $exportPayload['summary'] ?? null,
        ]
    );

    $duplicateRun = ctrip_pricing_pipeline_run_importer($root, $exportArgs);
    $duplicatePayload = ctrip_pricing_pipeline_decode_json_payload($duplicateRun['stdout']);
    ctrip_pricing_pipeline_check(
        $checks,
        'template_output_requires_force_to_overwrite',
        $duplicateRun['exit_code'] !== 0
            && str_contains((string)($duplicatePayload['error'] ?? ''), 'Output file already exists'),
        'Template exporter refuses to overwrite an existing file without --force=1.',
        ctrip_pricing_pipeline_run_summary($duplicateRun, $duplicatePayload)
    );

    $forceRun = ctrip_pricing_pipeline_run_importer($root, array_merge($exportArgs, ['--force=1']));
    $forcePayload = ctrip_pricing_pipeline_decode_json_payload($forceRun['stdout']);
    $forceOutput = ctrip_pricing_pipeline_map($forcePayload['output_file'] ?? []);
    ctrip_pricing_pipeline_check(
        $checks,
        'template_output_force_overwrites_explicitly',
        $forceRun['exit_code'] === 0
            && ($forcePayload['status'] ?? null) === 'template'
            && ($forceOutput['overwritten'] ?? false) === true,
        'Template exporter overwrites only when --force=1 is explicit.',
        [
            'run' => ctrip_pricing_pipeline_run_summary($forceRun, $forcePayload),
            'output_file' => $forceOutput,
        ]
    );

    $placeholderLintRun = ctrip_pricing_pipeline_run_importer($root, [
        '--lint-only=1',
        '--file=' . $templatePath,
        '--date=' . $date,
    ]);
    $placeholderLintPayload = ctrip_pricing_pipeline_decode_json_payload($placeholderLintRun['stdout']);
    $placeholderSummary = ctrip_pricing_pipeline_map($placeholderLintPayload['summary'] ?? []);
    ctrip_pricing_pipeline_check(
        $checks,
        'placeholder_template_lint_fails_without_db',
        $placeholderLintRun['exit_code'] !== 0
            && ($placeholderLintPayload['mode'] ?? null) === 'lint_only'
            && ($placeholderSummary['database_touched'] ?? true) === false
            && in_array('placeholder_value', ctrip_pricing_pipeline_list($placeholderSummary['issue_codes'] ?? []), true),
        'Lint-only mode rejects unfilled <...> placeholders and does not touch the database.',
        [
            'run' => ctrip_pricing_pipeline_run_summary($placeholderLintRun, $placeholderLintPayload),
            'summary' => $placeholderSummary,
        ]
    );

    ctrip_pricing_pipeline_write_json($filledPath, ctrip_pricing_pipeline_filled_payload($date, $hotelId, $marker));

    $filledLintRun = ctrip_pricing_pipeline_run_importer($root, [
        '--lint-only=1',
        '--file=' . $filledPath,
        '--date=' . $date,
    ]);
    $filledLintPayload = ctrip_pricing_pipeline_decode_json_payload($filledLintRun['stdout']);
    $filledLintSummary = ctrip_pricing_pipeline_map($filledLintPayload['summary'] ?? []);
    ctrip_pricing_pipeline_check(
        $checks,
        'filled_input_lint_passes_without_db',
        $filledLintRun['exit_code'] === 0
            && ($filledLintPayload['status'] ?? null) === 'passed'
            && ($filledLintPayload['mode'] ?? null) === 'lint_only'
            && ($filledLintSummary['database_touched'] ?? true) === false
            && (int)($filledLintSummary['issue_count'] ?? 1) === 0,
        'Filled Ctrip pricing input passes lint-only validation without touching the database.',
        [
            'run' => ctrip_pricing_pipeline_run_summary($filledLintRun, $filledLintPayload),
            'summary' => $filledLintSummary,
        ]
    );

    $validateRun = ctrip_pricing_pipeline_run_importer($root, [
        '--validate-only=1',
        '--file=' . $filledPath,
        '--date=' . $date,
    ]);
    $validatePayload = ctrip_pricing_pipeline_decode_json_payload($validateRun['stdout']);
    $validateSummary = ctrip_pricing_pipeline_map($validatePayload['summary'] ?? []);
    ctrip_pricing_pipeline_check(
        $checks,
        'validate_only_rolls_back_without_generation',
        $validateRun['exit_code'] === 0
            && ($validatePayload['status'] ?? null) === 'passed'
            && ($validatePayload['mode'] ?? null) === 'validate_only'
            && ($validateSummary['rolled_back'] ?? false) === true
            && ($validateSummary['committed'] ?? true) === false
            && ($validateSummary['generation'] ?? null) === null,
        'Validate-only mode proves Ctrip pricing inputs, keeps generation disabled, and rolls back.',
        [
            'run' => ctrip_pricing_pipeline_run_summary($validateRun, $validatePayload),
            'summary' => [
                'committed' => $validateSummary['committed'] ?? null,
                'rolled_back' => $validateSummary['rolled_back'] ?? null,
                'generation' => $validateSummary['generation'] ?? null,
                'pricing_generation_preflight' => $validateSummary['pricing_generation_preflight'] ?? null,
            ],
        ]
    );

    $dryRun = ctrip_pricing_pipeline_run_importer($root, [
        '--file=' . $filledPath,
        '--date=' . $date,
    ]);
    $dryPayload = ctrip_pricing_pipeline_decode_json_payload($dryRun['stdout']);
    $drySummary = ctrip_pricing_pipeline_map($dryPayload['summary'] ?? []);
    $dryGeneration = ctrip_pricing_pipeline_map($drySummary['generation'] ?? []);
    ctrip_pricing_pipeline_check(
        $checks,
        'dry_run_generates_pending_review_and_rolls_back',
        $dryRun['exit_code'] === 0
            && ($dryPayload['status'] ?? null) === 'passed'
            && ($dryPayload['mode'] ?? null) === 'dry_run'
            && ($drySummary['rolled_back'] ?? false) === true
            && ($drySummary['committed'] ?? true) === false
            && ((int)($dryGeneration['created_count'] ?? 0) > 0 || (string)($dryGeneration['reason'] ?? '') === 'price_suggestions_pending_review'),
        'Dry-run creates or exposes a pending Ctrip AI suggestion path and rolls back all writes.',
        [
            'run' => ctrip_pricing_pipeline_run_summary($dryRun, $dryPayload),
            'generation' => $dryGeneration,
        ]
    );
    ctrip_pricing_pipeline_check(
        $checks,
        'dry_run_generation_keeps_ctrip_manual_review_gate',
        ($dryGeneration['source_scope'] ?? null) === 'ctrip_ota_channel'
            && ctrip_pricing_pipeline_list($dryGeneration['source_channels'] ?? []) === ['ctrip']
            && ($dryGeneration['auto_write_ota'] ?? true) === false
            && (ctrip_pricing_pipeline_map($dryGeneration['ai_review_gate'] ?? [])['operation_intake_allowed'] ?? true) === false,
        'Dry-run generation stays Ctrip-only, manual-review gated, and never writes OTA.',
        $dryGeneration
    );

    $scopeAfterRun = ctrip_pricing_pipeline_run_scope_verifier($root, $date, $options['hotel_id']);
    $scopeAfterPayload = ctrip_pricing_pipeline_decode_json_payload($scopeAfterRun['stdout']);
    $scopeAfterFingerprint = ctrip_pricing_pipeline_fingerprint_scope($scopeAfterPayload);
    ctrip_pricing_pipeline_check(
        $checks,
        'current_scope_unchanged_after_dry_run_rollback',
        $scopeAfterRun['exit_code'] === 0
            && ($scopeAfterPayload['status'] ?? null) === 'passed'
            && $scopeAfterFingerprint === $scopeBeforeFingerprint,
        'Current real DB Ctrip scope is unchanged after lint, validate-only, and dry-run rollback.',
        [
            'before' => $scopeBeforeFingerprint,
            'after' => $scopeAfterFingerprint,
        ]
    );
    ctrip_pricing_pipeline_check(
        $checks,
        'meituan_not_present',
        !ctrip_pricing_pipeline_contains_meituan([
            'template_summary' => $exportPayload['summary'] ?? null,
            'dry_run_scope' => $dryPayload['scope'] ?? null,
            'dry_run_summary' => $drySummary,
            'scope_after_summary' => $scopeAfterPayload['summary'] ?? null,
        ]),
        'Ctrip pricing input pipeline payloads do not include Meituan tokens.',
        ['source_channels' => $dryGeneration['source_channels'] ?? null]
    );

    $failures = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'passed'));
    ctrip_pricing_pipeline_cleanup($tempFiles);
    ctrip_pricing_pipeline_finish([
        'status' => $failures === [] ? 'passed' : 'failed',
        'scope' => [
            'business_date' => $date,
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $hotelId,
            'source_scope' => 'ctrip_ota_channel',
            'source_policy' => 'runtime_operator_input_pipeline_rollback_only',
            'auto_write_ota' => false,
        ],
        'summary' => [
            'template_exported' => true,
            'placeholder_lint_rejected' => ($placeholderLintRun['exit_code'] !== 0),
            'filled_lint_passed' => ($filledLintPayload['status'] ?? null) === 'passed',
            'validate_only_rolled_back' => $validateSummary['rolled_back'] ?? null,
            'dry_run_rolled_back' => $drySummary['rolled_back'] ?? null,
            'dry_run_generation' => $dryGeneration,
            'current_scope_before' => $scopeBeforeFingerprint,
            'current_scope_after' => $scopeAfterFingerprint,
            'next_action' => 'Use the exported template for operator-verified Ctrip pricing inputs; execute only after lint, validate-only, and dry-run pass.',
        ],
        'checks' => $checks,
    ], $failures === [] ? 0 : 1);
} catch (Throwable $e) {
    ctrip_pricing_pipeline_cleanup($tempFiles);
    ctrip_pricing_pipeline_finish([
        'status' => 'failed',
        'error' => $e->getMessage(),
        'checks' => $checks,
    ], 1);
}
