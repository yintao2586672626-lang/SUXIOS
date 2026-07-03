<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{date:string,hotel_id:int|null,output_dir:string,force:bool}
 */
function ctrip_operator_bundle_parse_args(array $argv): array
{
    $options = [
        'date' => date('Y-m-d'),
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'output-dir' => '',
        'output_dir' => '',
        'force' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--force') {
            $options['force'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (!array_key_exists($key, $options)) {
            continue;
        }
        if ($key === 'force') {
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
    if ((string)$options['output-dir'] === '' && (string)$options['output_dir'] !== '') {
        $options['output-dir'] = (string)$options['output_dir'];
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

    $outputDir = (string)$options['output-dir'];
    if ($outputDir === '') {
        throw new InvalidArgumentException('Missing --output-dir=<bundle-directory>.');
    }

    return [
        'date' => (string)$options['date'],
        'hotel_id' => $hotelId,
        'output_dir' => $outputDir,
        'force' => (bool)$options['force'],
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_operator_bundle_finish(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

/**
 * @return array<int, string>
 */
function ctrip_operator_bundle_scoped_args(array $baseArgs, string $date, ?int $hotelId): array
{
    $args = array_merge($baseArgs, ['--date=' . $date]);
    if ($hotelId !== null) {
        $args[] = '--hotel-id=' . $hotelId;
    }
    return $args;
}

/**
 * @param array<int, string> $args
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function ctrip_operator_bundle_run_process(array $args, string $cwd): array
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
function ctrip_operator_bundle_decode_json(string $stdout): array
{
    $text = trim($stdout);
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($text === '' || $start === false || $end === false || $end < $start) {
        return [];
    }
    $payload = json_decode(substr($text, $start, $end - $start + 1), true);

    return is_array($payload) ? $payload : [];
}

/**
 * @param mixed $value
 * @return array<string, mixed>
 */
function ctrip_operator_bundle_map(mixed $value): array
{
    return is_array($value) ? $value : [];
}

function ctrip_operator_bundle_resolve_output_dir(string $outputDir): string
{
    $parent = dirname($outputDir);
    $name = basename($outputDir);
    if ($name === '' || $name === '.' || $name === '..') {
        throw new InvalidArgumentException('Output directory must be a concrete directory path.');
    }
    $parentPath = realpath($parent === '' ? '.' : $parent);
    if (!is_string($parentPath) || !is_dir($parentPath)) {
        throw new InvalidArgumentException('Output parent directory does not exist: ' . $parent);
    }

    return $parentPath . DIRECTORY_SEPARATOR . $name;
}

/**
 * @return array<string, string>
 */
function ctrip_operator_bundle_target_files(string $dir): array
{
    return [
        'operator_packet_markdown' => $dir . DIRECTORY_SEPARATOR . 'operator-packet.md',
        'pricing_input_template_json' => $dir . DIRECTORY_SEPARATOR . 'pricing-input-template.json',
        'pricing_input_fillable_json' => $dir . DIRECTORY_SEPARATOR . 'pricing-input-fillable.json',
        'pending_review_packet_json' => $dir . DIRECTORY_SEPARATOR . 'pending-review-packet.json',
        'current_scope_json' => $dir . DIRECTORY_SEPARATOR . 'current-scope.json',
        'manifest_json' => $dir . DIRECTORY_SEPARATOR . 'manifest.json',
    ];
}

/**
 * @param array<string, string> $files
 */
function ctrip_operator_bundle_assert_writeable(string $dir, array $files, bool $force): bool
{
    if (is_file($dir)) {
        throw new InvalidArgumentException('Output directory path points to a file: ' . $dir);
    }
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create output directory: ' . $dir);
    }

    $overwriting = false;
    foreach ($files as $path) {
        if (is_dir($path)) {
            throw new InvalidArgumentException('Bundle file path points to a directory: ' . $path);
        }
        if (is_file($path)) {
            $overwriting = true;
            if (!$force) {
                throw new InvalidArgumentException('Bundle file already exists. Pass --force=1 to overwrite: ' . $path);
            }
        }
    }

    return $overwriting;
}

/**
 * @return array{path:string,bytes:int,sha256:string,overwritten:bool}
 */
function ctrip_operator_bundle_write_file(string $path, string $content, bool $overwritten): array
{
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write bundle file: ' . $path);
    }

    return [
        'path' => $path,
        'bytes' => strlen($content),
        'sha256' => hash('sha256', $content),
        'overwritten' => $overwritten,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_operator_bundle_json(array $payload): string
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode bundle JSON.');
    }
    return $json . PHP_EOL;
}

/**
 * @param array<string, mixed> $template
 * @return array<string, mixed>
 */
function ctrip_operator_bundle_fillable_payload(array $template): array
{
    return [
        'business_date' => $template['business_date'] ?? '',
        'hotel_id' => $template['hotel_id'] ?? null,
        'platform' => 'ctrip',
        'input_scope' => 'manual_pricing_configuration',
        'source_scope' => 'ctrip_ota_channel',
        'evidence_status' => 'operator_provided',
        'target_workflow' => 'ctrip_revenue_ai_pricing_generation',
        'auto_write_ota' => false,
        'room_types' => array_values(array_filter((array)($template['room_types'] ?? []), 'is_array')),
        'demand_forecasts' => array_values(array_filter((array)($template['demand_forecasts'] ?? []), 'is_array')),
        'competitor_price_samples' => array_values(array_filter((array)($template['competitor_price_samples'] ?? []), 'is_array')),
    ];
}

function ctrip_operator_bundle_command(string $name, string $date, ?int $hotelId, string $filePath = ''): string
{
    $hotelArg = $hotelId === null ? '' : ' --hotel-id=' . $hotelId;
    return match ($name) {
        'preflight_no_execute' => 'npm.cmd run verify:revenue-ai-ctrip-operator-bundle-preflight -- --dir=' . $filePath . ' --date=' . $date . $hotelArg,
        'lint_only' => 'npm.cmd run lint:revenue-ai-ctrip-pricing-inputs -- --file=' . $filePath . ' --date=' . $date . $hotelArg,
        'validate_only' => 'npm.cmd run validate:revenue-ai-ctrip-pricing-inputs -- --file=' . $filePath . ' --date=' . $date . $hotelArg,
        'dry_run' => 'npm.cmd run import:revenue-ai-ctrip-pricing-inputs -- --file=' . $filePath . ' --date=' . $date . $hotelArg,
        'pre_execute_gate' => 'npm.cmd run verify:revenue-ai-ctrip-pricing-file -- --file=' . $filePath . ' --date=' . $date . $hotelArg,
        'execute_to_pending_review' => 'npm.cmd run run:revenue-ai-ctrip-pricing-file-to-pending-review -- --file=' . $filePath . ' --date=' . $date . $hotelArg . ' --execute=1 --generate=1',
        'pending_review_packet' => 'npm.cmd run report:revenue-ai-ctrip-pending-review-packet -- --date=' . $date . $hotelArg . ' --format=markdown',
        'verify_current_scope' => 'npm.cmd run verify:revenue-ai-ctrip-scope -- --date=' . $date . $hotelArg,
        default => '',
    };
}

try {
    $options = ctrip_operator_bundle_parse_args($argv);
    $root = dirname(__DIR__);
    $date = $options['date'];
    $hotelId = $options['hotel_id'];
    $outputDir = ctrip_operator_bundle_resolve_output_dir($options['output_dir']);
    $files = ctrip_operator_bundle_target_files($outputDir);
    $overwritten = ctrip_operator_bundle_assert_writeable($outputDir, $files, $options['force']);

    $operatorPacketJsonRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        [PHP_BINARY, 'scripts/report_revenue_ai_ctrip_pricing_operator_packet.php', '--format=json'],
        $date,
        $hotelId
    ), $root);
    $operatorPacket = ctrip_operator_bundle_decode_json($operatorPacketJsonRun['stdout']);

    $operatorPacketMarkdownRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        [PHP_BINARY, 'scripts/report_revenue_ai_ctrip_pricing_operator_packet.php', '--format=markdown'],
        $date,
        $hotelId
    ), $root);

    $templateRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        [PHP_BINARY, 'scripts/import_revenue_ai_ctrip_pricing_inputs.php', '--print-current-template'],
        $date,
        $hotelId
    ), $root);
    $templatePayload = ctrip_operator_bundle_decode_json($templateRun['stdout']);
    $templateBody = ctrip_operator_bundle_map($templatePayload['template'] ?? []);

    $pendingReviewRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        [PHP_BINARY, 'scripts/report_revenue_ai_ctrip_pending_review_packet.php', '--format=json'],
        $date,
        $hotelId
    ), $root);
    $pendingReviewPayload = ctrip_operator_bundle_decode_json($pendingReviewRun['stdout']);

    $scopeRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        [PHP_BINARY, 'scripts/verify_revenue_ai_ctrip_scope.php'],
        $date,
        $hotelId
    ), $root);
    $scopePayload = ctrip_operator_bundle_decode_json($scopeRun['stdout']);

    $scope = ctrip_operator_bundle_map($operatorPacket['scope'] ?? []);
    $resolvedHotelId = (int)($scope['hotel_id'] ?? 0) > 0
        ? (int)$scope['hotel_id']
        : ($hotelId ?? ((int)($templateBody['hotel_id'] ?? 0) ?: null));
    $templateFile = $files['pricing_input_template_json'];
    $fillableFile = $files['pricing_input_fillable_json'];
    $fillableBody = ctrip_operator_bundle_fillable_payload($templateBody);
    $currentBlocker = ctrip_operator_bundle_map($operatorPacket['current_blocker'] ?? []);

    $manifest = [
        'status' => 'passed',
        'scope' => [
            'business_date' => $date,
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $resolvedHotelId,
            'source_scope' => 'ctrip_ota_channel',
            'source_policy' => 'operator_handoff_bundle_no_values_no_import',
            'raw_values_exposed' => false,
            'database_written' => false,
            'auto_write_ota' => false,
            'meituan_scope_included' => false,
        ],
        'current_blocker' => $currentBlocker,
        'operator_fill_required' => [
            'Edit pricing-input-fillable.json with operator-verified Ctrip OTA channel values before running lint or execute.',
            'Use pricing-input-template.json as read-only context; do not edit verification commands as business inputs.',
            'Do not use Meituan rows, whole-hotel values, sample values, guessed values, fallback values, or verifier-only values.',
            'Do not create OTA price writes from this bundle; AI suggestions remain manual-review gated.',
        ],
        'next_commands_after_filling_template' => [
            'preflight_no_execute' => ctrip_operator_bundle_command('preflight_no_execute', $date, $resolvedHotelId, $outputDir),
            'lint_only' => ctrip_operator_bundle_command('lint_only', $date, $resolvedHotelId, $fillableFile),
            'validate_only' => ctrip_operator_bundle_command('validate_only', $date, $resolvedHotelId, $fillableFile),
            'dry_run' => ctrip_operator_bundle_command('dry_run', $date, $resolvedHotelId, $fillableFile),
            'pre_execute_gate' => ctrip_operator_bundle_command('pre_execute_gate', $date, $resolvedHotelId, $fillableFile),
            'execute_to_pending_review' => ctrip_operator_bundle_command('execute_to_pending_review', $date, $resolvedHotelId, $fillableFile),
            'pending_review_packet' => ctrip_operator_bundle_command('pending_review_packet', $date, $resolvedHotelId),
            'verify_current_scope' => ctrip_operator_bundle_command('verify_current_scope', $date, $resolvedHotelId),
        ],
        'processes' => [
            'operator_packet_json' => ['exit_code' => $operatorPacketJsonRun['exit_code'], 'status' => $operatorPacket['status'] ?? null],
            'operator_packet_markdown' => ['exit_code' => $operatorPacketMarkdownRun['exit_code']],
            'pricing_template' => ['exit_code' => $templateRun['exit_code'], 'status' => $templatePayload['status'] ?? null],
            'pending_review_packet' => ['exit_code' => $pendingReviewRun['exit_code'], 'status' => $pendingReviewPayload['status'] ?? null],
            'current_scope' => ['exit_code' => $scopeRun['exit_code'], 'status' => $scopePayload['status'] ?? null],
        ],
    ];

    $failedProcesses = array_filter(
        $manifest['processes'],
        static fn(array $process): bool => (int)($process['exit_code'] ?? 1) !== 0
    );
    if ($failedProcesses !== []) {
        $manifest['status'] = 'failed';
    }
    if (($operatorPacket['status'] ?? null) !== 'passed' || ($templatePayload['status'] ?? null) !== 'template') {
        $manifest['status'] = 'failed';
    }
    if (($scope['source_scope'] ?? null) !== 'ctrip_ota_channel'
        || ($scope['auto_write_ota'] ?? true) !== false
        || ($scope['meituan_scope_included'] ?? true) !== false
    ) {
        $manifest['status'] = 'failed';
    }

    $written = [];
    $written['operator_packet_markdown'] = ctrip_operator_bundle_write_file(
        $files['operator_packet_markdown'],
        rtrim($operatorPacketMarkdownRun['stdout']) . PHP_EOL,
        $overwritten
    );
    $written['pricing_input_template_json'] = ctrip_operator_bundle_write_file(
        $files['pricing_input_template_json'],
        ctrip_operator_bundle_json($templateBody),
        $overwritten
    );
    $written['pricing_input_fillable_json'] = ctrip_operator_bundle_write_file(
        $files['pricing_input_fillable_json'],
        ctrip_operator_bundle_json($fillableBody),
        $overwritten
    );
    $written['pending_review_packet_json'] = ctrip_operator_bundle_write_file(
        $files['pending_review_packet_json'],
        ctrip_operator_bundle_json($pendingReviewPayload),
        $overwritten
    );
    $written['current_scope_json'] = ctrip_operator_bundle_write_file(
        $files['current_scope_json'],
        ctrip_operator_bundle_json($scopePayload),
        $overwritten
    );

    $manifest['files'] = $written;
    $written['manifest_json'] = ctrip_operator_bundle_write_file(
        $files['manifest_json'],
        ctrip_operator_bundle_json($manifest),
        $overwritten
    );
    $manifest['files'] = $written;

    ctrip_operator_bundle_finish([
        'status' => $manifest['status'],
        'scope' => $manifest['scope'],
        'bundle_dir' => $outputDir,
        'files' => $written,
        'current_blocker' => $currentBlocker,
        'next_commands_after_filling_template' => $manifest['next_commands_after_filling_template'],
    ], $manifest['status'] === 'passed' ? 0 : 1);
} catch (Throwable $e) {
    ctrip_operator_bundle_finish([
        'status' => 'failed',
        'error' => $e->getMessage(),
    ], 1);
}
