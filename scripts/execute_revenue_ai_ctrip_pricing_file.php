<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{file:string,date:string,hotel_id:int|null,execute:bool,generate:bool}
 */
function ctrip_pricing_execute_parse_args(array $argv): array
{
    $options = [
        'file' => '',
        'date' => date('Y-m-d'),
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'execute' => false,
        'generate' => true,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--execute') {
            $options['execute'] = true;
            continue;
        }
        if ($arg === '--generate') {
            $options['generate'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (!array_key_exists($key, $options)) {
            continue;
        }
        if (in_array($key, ['execute', 'generate'], true)) {
            $options[$key] = in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        } else {
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

    if ((string)$options['file'] === '') {
        throw new InvalidArgumentException('Missing --file=<filled-json-path>.');
    }
    if (!is_file((string)$options['file'])) {
        throw new InvalidArgumentException('Input file does not exist: ' . (string)$options['file']);
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
        'file' => (string)$options['file'],
        'date' => (string)$options['date'],
        'hotel_id' => $hotelId,
        'execute' => (bool)$options['execute'],
        'generate' => (bool)$options['generate'],
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_pricing_execute_finish(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

/**
 * @param mixed $value
 * @return array<string, mixed>
 */
function ctrip_pricing_execute_map(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @param mixed $value
 * @return array<int, mixed>
 */
function ctrip_pricing_execute_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @param array<int, string> $args
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function ctrip_pricing_execute_run_process(array $args, string $cwd): array
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
function ctrip_pricing_execute_decode_json_payload(string $stdout): array
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
function ctrip_pricing_execute_scoped_args(array $baseArgs, array $options): array
{
    $args = array_merge($baseArgs, [
        '--file=' . $options['file'],
        '--date=' . $options['date'],
    ]);
    if ($options['hotel_id'] !== null) {
        $args[] = '--hotel-id=' . (int)$options['hotel_id'];
    }

    return $args;
}

/**
 * @param array{exit_code:int,stdout:string,stderr:string} $run
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function ctrip_pricing_execute_run_summary(array $run, array $payload): array
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
function ctrip_pricing_execute_check(array &$checks, string $code, bool $ok, string $message, array $details = []): void
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

try {
    $options = ctrip_pricing_execute_parse_args($argv);
    $root = dirname(__DIR__);
    $checks = [];
    $fileHash = hash_file('sha256', $options['file']) ?: '';

    $gateArgs = ctrip_pricing_execute_scoped_args(
        [PHP_BINARY, 'scripts/verify_revenue_ai_ctrip_pricing_input_pipeline.php'],
        $options
    );
    $gateRun = ctrip_pricing_execute_run_process($gateArgs, $root);
    $gatePayload = ctrip_pricing_execute_decode_json_payload($gateRun['stdout']);
    $gateSummary = ctrip_pricing_execute_map($gatePayload['summary'] ?? []);
    ctrip_pricing_execute_check(
        $checks,
        'pre_execute_gate_passed',
        $gateRun['exit_code'] === 0
            && ($gatePayload['status'] ?? null) === 'passed'
            && (string)(ctrip_pricing_execute_map($gatePayload['scope'] ?? [])['source_policy'] ?? '') === 'operator_input_file_pre_execute_gate_rollback_only',
        'Filled Ctrip pricing input file must pass rollback-only pre-execute gate before any persistence.',
        [
            'run' => ctrip_pricing_execute_run_summary($gateRun, $gatePayload),
            'input_file_sha256' => $fileHash,
        ]
    );
    ctrip_pricing_execute_check(
        $checks,
        'gate_rolled_back_without_ota_write',
        ($gateSummary['validate_only_rolled_back'] ?? false) === true
            && ($gateSummary['dry_run_rolled_back'] ?? false) === true
            && (ctrip_pricing_execute_map($gateSummary['dry_run_generation'] ?? [])['auto_write_ota'] ?? null) === false,
        'Pre-execute gate validates and dry-runs with rollback and no OTA write.',
        [
            'validate_only_rolled_back' => $gateSummary['validate_only_rolled_back'] ?? null,
            'dry_run_rolled_back' => $gateSummary['dry_run_rolled_back'] ?? null,
            'dry_run_generation' => $gateSummary['dry_run_generation'] ?? null,
        ]
    );

    $gateFailures = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'passed'));
    if ($gateFailures !== []) {
        ctrip_pricing_execute_finish([
            'status' => 'failed',
            'mode' => 'pre_execute_gate_failed',
            'scope' => [
                'business_date' => $options['date'],
                'platform' => 'ctrip',
                'source_scope' => 'ctrip_ota_channel',
                'source_policy' => 'gate_failed_no_execute',
                'auto_write_ota' => false,
                'database_written' => false,
            ],
            'summary' => [
                'input_file_sha256' => $fileHash,
                'gate' => ctrip_pricing_execute_run_summary($gateRun, $gatePayload),
                'next_action' => 'Fix the operator-filled Ctrip pricing input file, then run the pre-execute gate again.',
            ],
            'checks' => $checks,
        ], 1);
    }

    if (!$options['execute']) {
        ctrip_pricing_execute_finish([
            'status' => 'passed',
            'mode' => 'pre_execute_gate_only',
            'scope' => [
                'business_date' => $options['date'],
                'platform' => 'ctrip',
                'source_scope' => 'ctrip_ota_channel',
                'source_policy' => 'pre_execute_gate_passed_no_persistence',
                'auto_write_ota' => false,
                'database_written' => false,
            ],
            'summary' => [
                'input_file_sha256' => $fileHash,
                'gate' => ctrip_pricing_execute_run_summary($gateRun, $gatePayload),
                'next_action' => 'If these are operator-verified real Ctrip values, re-run this command with --execute=1 --generate=1 to persist inputs and create pending AI review items.',
            ],
            'checks' => $checks,
        ], 0);
    }

    $executeArgs = ctrip_pricing_execute_scoped_args(
        [PHP_BINARY, 'scripts/import_revenue_ai_ctrip_pricing_inputs.php', '--execute=1'],
        $options
    );
    if ($options['generate']) {
        $executeArgs[] = '--generate=1';
    }
    $executeRun = ctrip_pricing_execute_run_process($executeArgs, $root);
    $executePayload = ctrip_pricing_execute_decode_json_payload($executeRun['stdout']);
    $executeSummary = ctrip_pricing_execute_map($executePayload['summary'] ?? []);
    $generation = ctrip_pricing_execute_map($executeSummary['generation'] ?? []);
    ctrip_pricing_execute_check(
        $checks,
        'execute_committed_after_gate',
        $executeRun['exit_code'] === 0
            && ($executePayload['status'] ?? null) === 'passed'
            && ($executeSummary['committed'] ?? false) === true
            && ($executeSummary['rolled_back'] ?? true) === false,
        'Execution commits only after the pre-execute gate has passed.',
        ctrip_pricing_execute_run_summary($executeRun, $executePayload)
    );
    ctrip_pricing_execute_check(
        $checks,
        'execute_keeps_ctrip_manual_review_gate',
        !$options['generate'] || (
            (string)($generation['source_scope'] ?? '') === 'ctrip_ota_channel'
            && ctrip_pricing_execute_list($generation['source_channels'] ?? []) === ['ctrip']
            && ($generation['auto_write_ota'] ?? true) === false
            && (ctrip_pricing_execute_map($generation['ai_review_gate'] ?? [])['operation_intake_allowed'] ?? true) === false
        ),
        'Generated AI suggestions remain Ctrip-scoped, pending manual review, and do not write OTA.',
        $generation
    );

    $scopeArgs = [PHP_BINARY, 'scripts/verify_revenue_ai_ctrip_scope.php', '--date=' . $options['date']];
    if ($options['hotel_id'] !== null) {
        $scopeArgs[] = '--hotel-id=' . (int)$options['hotel_id'];
    }
    $scopeRun = ctrip_pricing_execute_run_process($scopeArgs, $root);
    $scopePayload = ctrip_pricing_execute_decode_json_payload($scopeRun['stdout']);
    $scopeSummary = ctrip_pricing_execute_map($scopePayload['summary'] ?? []);
    $reviewQueue = ctrip_pricing_execute_map($scopeSummary['review_queue'] ?? []);
    ctrip_pricing_execute_check(
        $checks,
        'post_execute_scope_verifier_passes',
        $scopeRun['exit_code'] === 0
            && ($scopePayload['status'] ?? null) === 'passed'
            && !str_contains(strtolower(json_encode($scopePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), 'meituan'),
        'Post-execute Revenue AI scope remains Ctrip-only.',
        ctrip_pricing_execute_run_summary($scopeRun, $scopePayload)
    );
    ctrip_pricing_execute_check(
        $checks,
        'post_execute_pending_review_visible',
        !$options['generate'] || (
            (string)($reviewQueue['status'] ?? '') === 'pending_review'
            && (int)($reviewQueue['pending_count'] ?? 0) > 0
        ),
        'Generated Ctrip AI price suggestions must be visible as pending manual review after execute.',
        $reviewQueue
    );

    $failures = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'passed'));
    ctrip_pricing_execute_finish([
        'status' => $failures === [] ? 'passed' : 'failed',
        'mode' => $options['generate'] ? 'execute_and_generate_pending_review' : 'execute_inputs_only',
        'scope' => [
            'business_date' => $options['date'],
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $options['hotel_id'],
            'source_scope' => 'ctrip_ota_channel',
            'source_policy' => 'pre_execute_gate_then_explicit_execute',
            'auto_write_ota' => false,
        ],
        'summary' => [
            'input_file_sha256' => $fileHash,
            'gate' => ctrip_pricing_execute_run_summary($gateRun, $gatePayload),
            'execute' => ctrip_pricing_execute_run_summary($executeRun, $executePayload),
            'execute_summary' => $executeSummary,
            'post_execute_review_queue' => $reviewQueue,
            'next_action' => $options['generate']
                ? 'Review pending Ctrip AI price suggestions before creating any operation execution.'
                : 'Generate pending suggestions from Agent Center, then review them before operation intake.',
        ],
        'checks' => $checks,
    ], $failures === [] ? 0 : 1);
} catch (Throwable $e) {
    ctrip_pricing_execute_finish([
        'status' => 'failed',
        'error' => $e->getMessage(),
    ], 1);
}
