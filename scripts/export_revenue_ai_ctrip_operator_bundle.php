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

/**
 * @param mixed $value
 * @return array<int, mixed>
 */
function ctrip_operator_bundle_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
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
        'operator_intake_form_markdown' => $dir . DIRECTORY_SEPARATOR . 'OPERATOR_INTAKE_FORM.md',
        'closure_runbook_markdown' => $dir . DIRECTORY_SEPARATOR . 'CTRIP_CLOSURE_RUNBOOK.md',
        'real_input_todo_markdown' => $dir . DIRECTORY_SEPARATOR . 'REAL_INPUT_TODO.md',
        'real_input_checklist_json' => $dir . DIRECTORY_SEPARATOR . 'real-input-checklist.json',
        'pricing_input_schema_json' => $dir . DIRECTORY_SEPARATOR . 'pricing-input.schema.json',
        'pricing_input_intake_csv' => $dir . DIRECTORY_SEPARATOR . 'pricing-input-intake.csv',
        'operator_input_locators_csv' => $dir . DIRECTORY_SEPARATOR . 'operator-input-locators.csv',
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
        'operator_input_evidence' => ctrip_operator_bundle_map($template['operator_input_evidence'] ?? []),
        'room_types' => array_values(array_filter((array)($template['room_types'] ?? []), 'is_array')),
        'demand_forecasts' => array_values(array_filter((array)($template['demand_forecasts'] ?? []), 'is_array')),
        'competitor_price_samples' => array_values(array_filter((array)($template['competitor_price_samples'] ?? []), 'is_array')),
    ];
}

function ctrip_operator_bundle_command(string $name, string $date, ?int $hotelId, string $filePath = '', string $outputPath = ''): string
{
    $hotelArg = $hotelId === null ? '' : ' --hotel-id=' . $hotelId;
    return match ($name) {
        'preflight_no_execute' => 'npm.cmd run verify:revenue-ai-ctrip-operator-bundle-preflight -- --dir=' . $filePath . ' --date=' . $date . $hotelArg,
        'build_fillable_from_csv' => 'npm.cmd run build:revenue-ai-ctrip-pricing-input-from-csv -- --csv-file=' . $filePath . ' --output=' . $outputPath . ' --date=' . $date . $hotelArg . ' --force=1',
        'lint_only' => 'npm.cmd run lint:revenue-ai-ctrip-pricing-inputs -- --file=' . $filePath . ' --date=' . $date . $hotelArg,
        'validate_only' => 'npm.cmd run validate:revenue-ai-ctrip-pricing-inputs -- --file=' . $filePath . ' --date=' . $date . $hotelArg,
        'dry_run' => 'npm.cmd run import:revenue-ai-ctrip-pricing-inputs -- --file=' . $filePath . ' --date=' . $date . $hotelArg,
        'pre_execute_gate' => 'npm.cmd run verify:revenue-ai-ctrip-pricing-file -- --file=' . $filePath . ' --date=' . $date . $hotelArg,
        'execute_to_pending_review' => 'npm.cmd run run:revenue-ai-ctrip-pricing-file-to-pending-review -- --file=' . $filePath . ' --date=' . $date . $hotelArg . ' --execute=1 --generate=1',
        'pending_review_packet' => 'npm.cmd run report:revenue-ai-ctrip-pending-review-packet -- --date=' . $date . $hotelArg . ' --format=markdown',
        'review_decision_template' => 'npm.cmd run export:revenue-ai-ctrip-review-template -- --date=' . $date . $hotelArg . ' --suggestion-id=<pending-suggestion-id> --output=<review-decision-json-path>',
        'execute_review_and_create_intent' => 'npm.cmd run run:revenue-ai-ctrip-review-decision -- --file=<review-decision-json-path> --date=' . $date . $hotelArg . ' --execute=1 --create-intent=1',
        'verify_roi_boundary' => 'npm.cmd run verify:revenue-ai-ctrip-operation-roi -- --date=' . $date . $hotelArg,
        'verify_current_scope' => 'npm.cmd run verify:revenue-ai-ctrip-scope -- --date=' . $date . $hotelArg,
        default => '',
    };
}

/**
 * @return array<int, string>
 */
function ctrip_operator_bundle_placeholder_paths(mixed $value, string $path = '$'): array
{
    if (is_string($value)) {
        $text = trim($value);
        if ($text !== '' && preg_match('/^<[^>]+>$|placeholder|operator_verified|sample|guess|fallback/i', $text) === 1) {
            return [$path];
        }
        return [];
    }
    if (!is_array($value)) {
        return [];
    }

    $paths = [];
    foreach ($value as $key => $item) {
        $paths = array_merge($paths, ctrip_operator_bundle_placeholder_paths($item, $path . '.' . (string)$key));
    }
    return $paths;
}

/**
 * @return array<string, string>
 */
function ctrip_operator_bundle_field_guidance(string $path): array
{
    $field = (string)preg_replace('/^.*\./', '', $path);
    $group = str_contains($path, '$.room_types.')
        ? 'room_types'
        : (str_contains($path, '$.demand_forecasts.')
            ? 'demand_forecasts'
            : (str_contains($path, '$.competitor_price_samples.')
                ? 'competitor_price_samples'
                : (str_contains($path, '$.operator_input_evidence.') ? 'operator_input_evidence' : 'unknown')));
    $guide = [
        'confirmed_by' => ['Operator name or role accountable for the submitted Ctrip pricing inputs.', 'non-empty text'],
        'confirmed_at' => ['Confirmation timestamp for the Ctrip input review.', 'must start with YYYY-MM-DD'],
        'room_type_source' => ['Human-verifiable source for Ctrip room type, room count, and room mapping.', 'source note or evidence path'],
        'price_guard_source' => ['Human-verifiable source for base price, floor/protection price, and max price guard.', 'source note or evidence path'],
        'demand_forecast_source' => ['Human-verifiable source for target-date demand forecast and confidence.', 'source note or model/operator note'],
        'competitor_price_source' => ['Human-verifiable source for recent 7-day Ctrip competitor price samples.', 'source note or evidence path'],
        'name' => ['Operator-verified Ctrip room type name.', 'non-empty text'],
        'base_price' => ['Current operator-verified Ctrip sell price for the room type.', 'numeric > 0'],
        'min_price' => ['Operator-approved floor/protection price.', 'numeric > 0 and <= base_price'],
        'max_price' => ['Operator-approved upper guard price.', 'numeric >= base_price'],
        'room_count' => ['Operator-confirmed sellable room count for the room type.', 'positive integer'],
        'predicted_occupancy' => ['Target-date demand forecast occupancy percentage.', 'numeric 0-100'],
        'predicted_demand' => ['Target-date demand forecast count or demand index.', 'numeric > 0'],
        'confidence_score' => ['Forecast confidence from operator/model review.', 'numeric 0-1'],
        'competitor_name' => ['Real Ctrip competitor hotel/name used for this price sample.', 'non-empty text'],
        'our_price' => ['Our Ctrip price observed in the recent-7-day competitor comparison window.', 'numeric > 0'],
        'competitor_price' => ['Competitor Ctrip price observed in the same recent-7-day window.', 'numeric > 0'],
    ];

    return [
        'path' => $path,
        'group' => $group,
        'field' => $field,
        'expected_real_input' => $guide[$field][0] ?? 'Operator-verified Ctrip channel value.',
        'format_guard' => $guide[$field][1] ?? 'real value with human-verifiable source',
        'forbidden_fill' => 'sample, guessed, fallback, verifier-only, Meituan, or whole-hotel value',
    ];
}

function ctrip_operator_bundle_markdown_cell(string $value): string
{
    return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
}

function ctrip_operator_bundle_csv_cell(mixed $value): string
{
    $text = (string)$value;
    if (str_contains($text, '"') || str_contains($text, ',') || str_contains($text, "\n") || str_contains($text, "\r")) {
        return '"' . str_replace('"', '""', $text) . '"';
    }
    return $text;
}

/**
 * @return string
 */
function ctrip_operator_bundle_pricing_input_intake_csv(string $date, ?int $hotelId): string
{
    $headers = [
        'section',
        'business_date',
        'hotel_id',
        'room_type_key',
        'room_type_name',
        'base_price',
        'min_price',
        'max_price',
        'room_count',
        'is_enabled',
        'sort_order',
        'forecast_date',
        'predicted_occupancy',
        'predicted_demand',
        'confidence_score',
        'forecast_method',
        'analysis_date',
        'competitor_name',
        'our_price',
        'competitor_price',
        'ota_platform',
        'confirmed_by',
        'confirmed_at',
        'room_type_source',
        'price_guard_source',
        'demand_forecast_source',
        'competitor_price_source',
        'source_note',
        'required_fields',
        'expected_real_input',
        'format_guard',
        'forbidden_fill',
    ];
    $base = array_fill_keys($headers, '');
    $base['business_date'] = $date;
    $base['hotel_id'] = $hotelId === null ? '' : (string)$hotelId;
    $forbiddenFill = 'sample, guessed, fallback, verifier-only, non-Ctrip OTA, or whole-hotel value';

    $rows = [];
    $evidence = $base;
    $evidence['section'] = 'evidence';
    $evidence['required_fields'] = 'confirmed_by; confirmed_at; room_type_source; price_guard_source; demand_forecast_source; competitor_price_source';
    $evidence['expected_real_input'] = 'Operator accountability and human-verifiable source notes for the submitted Ctrip pricing inputs.';
    $evidence['format_guard'] = 'confirmed_at starts with YYYY-MM-DD; all source fields are non-empty evidence notes';
    $evidence['forbidden_fill'] = $forbiddenFill;
    $rows[] = $evidence;
    $room = $base;
    $room['section'] = 'room_type';
    $room['required_fields'] = 'room_type_key; room_type_name; base_price; min_price; max_price; room_count; is_enabled; source_note';
    $room['expected_real_input'] = 'Operator-verified Ctrip room type, current sell price, floor/protection price, upper guard, and sellable room count.';
    $room['format_guard'] = 'base/min/max numeric > 0; min_price <= base_price; max_price >= base_price; room_count positive integer';
    $room['forbidden_fill'] = $forbiddenFill;
    $rows[] = $room;
    $forecast = $base;
    $forecast['section'] = 'demand_forecast';
    $forecast['forecast_date'] = $date;
    $forecast['required_fields'] = 'room_type_key; forecast_date; predicted_occupancy; predicted_demand; confidence_score; source_note';
    $forecast['expected_real_input'] = 'Target-date demand forecast for the same Ctrip room type and hotel scope.';
    $forecast['format_guard'] = 'predicted_occupancy 0-100; predicted_demand numeric > 0; confidence_score 0-1';
    $forecast['forbidden_fill'] = $forbiddenFill;
    $rows[] = $forecast;
    $competitor = $base;
    $competitor['section'] = 'competitor_price_sample';
    $competitor['analysis_date'] = $date;
    $competitor['ota_platform'] = 'ctrip';
    $competitor['required_fields'] = 'room_type_key; analysis_date; competitor_name; our_price; competitor_price; ota_platform; source_note';
    $competitor['expected_real_input'] = 'Recent 7-day Ctrip competitor price sample for the same comparable room context.';
    $competitor['format_guard'] = 'analysis_date within 7 days ending at business_date; our_price and competitor_price numeric > 0; ota_platform=ctrip';
    $competitor['forbidden_fill'] = $forbiddenFill;
    $rows[] = $competitor;

    $lines = [
        implode(',', array_map('ctrip_operator_bundle_csv_cell', $headers)),
    ];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(
            static fn(string $header): string => ctrip_operator_bundle_csv_cell($row[$header] ?? ''),
            $headers
        ));
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

/**
 * @param array<string, mixed> $operatorPacket
 * @return string
 */
function ctrip_operator_bundle_operator_input_locators_csv(array $operatorPacket, string $date, ?int $hotelId): string
{
    $headers = [
        'input_code',
        'locator_status',
        'locator_count',
        'operator_use',
        'row_id',
        'data_date',
        'source',
        'data_type',
        'dimension',
        'system_hotel_id',
        'data_source_id',
        'sync_task_id',
        'ingestion_method',
        'validation_status',
        'source_trace_id',
        'matched_path_count',
        'matched_paths',
        'locator_policy',
        'raw_values_exposed',
        'database_written',
        'auto_write_ota',
        'importable_value',
    ];
    $locators = ctrip_operator_bundle_map(ctrip_operator_bundle_map($operatorPacket['operator_input_locators'] ?? [])['items'] ?? []);
    $rows = [];
    foreach ($locators as $inputCode => $item) {
        $group = ctrip_operator_bundle_map($item);
        $locatorRows = ctrip_operator_bundle_list($group['locators'] ?? []);
        if ($locatorRows === []) {
            $rows[] = [
                'input_code' => (string)$inputCode,
                'locator_status' => (string)($group['status'] ?? 'no_metadata_locator'),
                'locator_count' => '0',
                'operator_use' => (string)($group['operator_use'] ?? ''),
                'row_id' => '',
                'data_date' => $date,
                'source' => 'ctrip',
                'data_type' => '',
                'dimension' => '',
                'system_hotel_id' => $hotelId === null ? '' : (string)$hotelId,
                'data_source_id' => '',
                'sync_task_id' => '',
                'ingestion_method' => '',
                'validation_status' => '',
                'source_trace_id' => '',
                'matched_path_count' => '0',
                'matched_paths' => '',
                'locator_policy' => 'metadata_locator_only_no_values_no_import',
                'raw_values_exposed' => 'false',
                'database_written' => 'false',
                'auto_write_ota' => 'false',
                'importable_value' => 'false',
            ];
            continue;
        }
        foreach ($locatorRows as $locator) {
            $locatorRow = ctrip_operator_bundle_map($locator);
            $matchedPaths = ctrip_operator_bundle_list($locatorRow['matched_paths'] ?? []);
            $rows[] = [
                'input_code' => (string)$inputCode,
                'locator_status' => (string)($group['status'] ?? 'metadata_locator_available'),
                'locator_count' => (string)($group['locator_count'] ?? count($locatorRows)),
                'operator_use' => (string)($group['operator_use'] ?? ''),
                'row_id' => (string)($locatorRow['id'] ?? ''),
                'data_date' => (string)($locatorRow['data_date'] ?? $date),
                'source' => (string)($locatorRow['source'] ?? 'ctrip'),
                'data_type' => (string)($locatorRow['data_type'] ?? ''),
                'dimension' => (string)($locatorRow['dimension'] ?? ''),
                'system_hotel_id' => (string)($locatorRow['system_hotel_id'] ?? ($hotelId ?? '')),
                'data_source_id' => (string)($locatorRow['data_source_id'] ?? ''),
                'sync_task_id' => (string)($locatorRow['sync_task_id'] ?? ''),
                'ingestion_method' => (string)($locatorRow['ingestion_method'] ?? ''),
                'validation_status' => (string)($locatorRow['validation_status'] ?? ''),
                'source_trace_id' => (string)($locatorRow['source_trace_id'] ?? ''),
                'matched_path_count' => (string)($locatorRow['matched_path_count'] ?? count($matchedPaths)),
                'matched_paths' => implode('; ', array_map(static fn(mixed $path): string => (string)$path, $matchedPaths)),
                'locator_policy' => 'metadata_locator_only_no_values_no_import',
                'raw_values_exposed' => 'false',
                'database_written' => 'false',
                'auto_write_ota' => 'false',
                'importable_value' => 'false',
            ];
        }
    }

    $lines = [
        implode(',', array_map('ctrip_operator_bundle_csv_cell', $headers)),
    ];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(
            static fn(string $header): string => ctrip_operator_bundle_csv_cell($row[$header] ?? ''),
            $headers
        ));
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

/**
 * @return array<string, mixed>
 */
function ctrip_operator_bundle_real_input_checklist(array $fillable, array $manifest): array
{
    $scope = ctrip_operator_bundle_map($manifest['scope'] ?? []);
    $blocker = ctrip_operator_bundle_map($manifest['current_blocker'] ?? []);
    $fillableInputs = [];
    foreach (['operator_input_evidence', 'room_types', 'demand_forecasts', 'competitor_price_samples'] as $key) {
        $fillableInputs[$key] = $fillable[$key] ?? [];
    }
    $items = array_map(
        static fn(string $path): array => ctrip_operator_bundle_field_guidance($path),
        ctrip_operator_bundle_placeholder_paths($fillableInputs)
    );

    return [
        'status' => $items === [] ? 'filled_or_needs_lint' : 'pending_operator_real_values',
        'source_policy' => 'operator_real_input_checklist_no_values_no_import',
        'scope' => [
            'business_date' => (string)($scope['business_date'] ?? ''),
            'platform' => 'ctrip',
            'hotel_id' => $scope['hotel_id'] ?? null,
            'source_scope' => 'ctrip_ota_channel',
            'raw_values_exposed' => false,
            'database_written' => false,
            'auto_write_ota' => false,
            'meituan_scope_included' => false,
        ],
        'current_blocker' => [
            'status' => $blocker['status'] ?? null,
            'reason' => $blocker['reason'] ?? null,
            'required_before_execute' => ctrip_operator_bundle_list($blocker['required_before_execute'] ?? []),
        ],
        'placeholder_count' => count($items),
        'can_generate_pending_review' => false,
        'next_required_gate' => 'Fill pricing-input-fillable.json, then run lint_only, validate_only, dry_run, and pre_execute_gate before execute_to_pending_review.',
        'items' => $items,
        'stop_conditions' => [
            'Stop if any value is a sample, guess, fallback, verifier fixture, Meituan row, or whole-hotel value.',
            'Stop if source_scope is not ctrip_ota_channel.',
            'Stop if auto_write_ota becomes true.',
            'Stop before operation intent until a generated AI suggestion is manually reviewed.',
            'Stop before investment decision until operation ROI evidence is ready.',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function ctrip_operator_bundle_pricing_input_schema(string $date, ?int $hotelId): array
{
    $nonPlaceholderString = [
        'type' => 'string',
        'minLength' => 1,
        'not' => ['pattern' => '^<[^>]+>$|placeholder|operator_verified|sample|guess|fallback'],
    ];
    $optionalNonPlaceholderString = [
        'anyOf' => [
            ['type' => 'string', 'const' => ''],
            $nonPlaceholderString,
        ],
        'description' => 'Optional row-level evidence reference. When filled, PHP importer preserves it as operator_row_source_note in AI pricing source metadata.',
    ];

    return [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        '$id' => 'https://suxios.local/schemas/revenue-ai/ctrip-pricing-input.schema.json',
        'title' => 'Ctrip Revenue AI Operator Pricing Input',
        'description' => 'Editor guidance for pricing-input-fillable.json. PHP lint/validate/pre-execute gates remain authoritative before any import.',
        'type' => 'object',
        'additionalProperties' => false,
        'required' => [
            'business_date',
            'hotel_id',
            'platform',
            'input_scope',
            'source_scope',
            'evidence_status',
            'target_workflow',
            'auto_write_ota',
            'operator_input_evidence',
            'room_types',
            'demand_forecasts',
            'competitor_price_samples',
        ],
        'properties' => [
            'business_date' => ['type' => 'string', 'const' => $date, 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'hotel_id' => $hotelId === null
                ? ['type' => 'integer', 'minimum' => 1]
                : ['type' => 'integer', 'const' => $hotelId],
            'platform' => ['type' => 'string', 'const' => 'ctrip'],
            'input_scope' => ['type' => 'string', 'const' => 'manual_pricing_configuration'],
            'source_scope' => ['type' => 'string', 'const' => 'ctrip_ota_channel'],
            'evidence_status' => ['type' => 'string', 'const' => 'operator_provided'],
            'target_workflow' => ['type' => 'string', 'const' => 'ctrip_revenue_ai_pricing_generation'],
            'auto_write_ota' => ['type' => 'boolean', 'const' => false],
            'operator_input_evidence' => [
                'type' => 'object',
                'additionalProperties' => true,
                'required' => [
                    'confirmed_by',
                    'confirmed_at',
                    'room_type_source',
                    'price_guard_source',
                    'demand_forecast_source',
                    'competitor_price_source',
                ],
                'properties' => [
                    'confirmed_by' => $nonPlaceholderString,
                    'confirmed_at' => array_merge($nonPlaceholderString, ['pattern' => '^\\d{4}-\\d{2}-\\d{2}']),
                    'room_type_source' => $nonPlaceholderString,
                    'price_guard_source' => $nonPlaceholderString,
                    'demand_forecast_source' => $nonPlaceholderString,
                    'competitor_price_source' => $nonPlaceholderString,
                ],
            ],
            'room_types' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'required' => ['key', 'name', 'base_price', 'min_price', 'max_price', 'room_count', 'is_enabled'],
                    'properties' => [
                        'key' => $nonPlaceholderString,
                        'name' => $nonPlaceholderString,
                        'base_price' => ['type' => 'number', 'exclusiveMinimum' => 0],
                        'min_price' => ['type' => 'number', 'exclusiveMinimum' => 0],
                        'max_price' => ['type' => 'number', 'exclusiveMinimum' => 0],
                        'room_count' => ['type' => 'integer', 'minimum' => 1],
                        'is_enabled' => ['type' => 'boolean', 'const' => true],
                        'source_note' => $optionalNonPlaceholderString,
                    ],
                ],
            ],
            'demand_forecasts' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'required' => ['room_type_key', 'forecast_date', 'predicted_occupancy', 'predicted_demand', 'confidence_score', 'forecast_method'],
                    'properties' => [
                        'room_type_key' => $nonPlaceholderString,
                        'forecast_date' => ['type' => 'string', 'const' => $date, 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
                        'predicted_occupancy' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                        'predicted_demand' => ['type' => 'number', 'exclusiveMinimum' => 0],
                        'confidence_score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'forecast_method' => $nonPlaceholderString,
                        'source_note' => $optionalNonPlaceholderString,
                    ],
                ],
            ],
            'competitor_price_samples' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'required' => ['room_type_key', 'analysis_date', 'competitor_name', 'our_price', 'competitor_price', 'ota_platform'],
                    'properties' => [
                        'room_type_key' => $nonPlaceholderString,
                        'analysis_date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
                        'competitor_name' => $nonPlaceholderString,
                        'our_price' => ['type' => 'number', 'exclusiveMinimum' => 0],
                        'competitor_price' => ['type' => 'number', 'exclusiveMinimum' => 0],
                        'ota_platform' => ['type' => 'string', 'const' => 'ctrip'],
                        'source_note' => $optionalNonPlaceholderString,
                    ],
                ],
            ],
        ],
        'x-suxios-policy' => [
            'source_scope' => 'ctrip_ota_channel',
            'raw_values_exposed' => false,
            'database_written' => false,
            'auto_write_ota' => false,
            'schema_is_authoritative' => false,
            'authoritative_gate' => 'npm.cmd run verify:revenue-ai-ctrip-operator-bundle-preflight',
        ],
    ];
}

/**
 * @return string
 */
function ctrip_operator_bundle_real_input_todo(array $operatorPacket, array $fillable, array $manifest): string
{
    $scope = ctrip_operator_bundle_map($manifest['scope'] ?? []);
    $blocker = ctrip_operator_bundle_map($manifest['current_blocker'] ?? []);
    $commands = ctrip_operator_bundle_map($manifest['next_commands_after_filling_template'] ?? []);
    $priorities = ctrip_operator_bundle_list($operatorPacket['operator_collection_priorities'] ?? []);
    $locatorItems = ctrip_operator_bundle_map(ctrip_operator_bundle_map($operatorPacket['operator_input_locators'] ?? [])['items'] ?? []);
    $sourceAudit = ctrip_operator_bundle_map($operatorPacket['source_audit'] ?? []);
    $observedHints = ctrip_operator_bundle_map($sourceAudit['observed_hints'] ?? []);
    $fillableInputs = [];
    foreach (['operator_input_evidence', 'room_types', 'demand_forecasts', 'competitor_price_samples'] as $key) {
        $fillableInputs[$key] = $fillable[$key] ?? [];
    }
    $placeholderDetails = array_map(
        static fn(string $path): array => ctrip_operator_bundle_field_guidance($path),
        ctrip_operator_bundle_placeholder_paths($fillableInputs)
    );

    $lines = [];
    $lines[] = '# Revenue AI Ctrip Real Input TODO';
    $lines[] = '';
    $lines[] = '- business_date: `' . (string)($scope['business_date'] ?? '') . '`';
    $lines[] = '- platform: `ctrip`';
    $lines[] = '- hotel_id: `' . (string)($scope['hotel_id'] ?? 'unknown') . '`';
    $lines[] = '- source_scope: `ctrip_ota_channel`';
    $lines[] = '- source_policy: `read_only_operator_handoff_no_values_no_import`';
    $lines[] = '- raw_values_exposed: `false`';
    $lines[] = '- database_written: `false`';
    $lines[] = '- auto_write_ota: `false`';
    $lines[] = '- p0_authority_status: `not_reverified_by_bundle`';
    $lines[] = '';
    $lines[] = '## Current Evidence';
    $lines[] = '';
    $lines[] = '- pricing_generation_preflight.status: `' . (string)($blocker['status'] ?? 'unknown') . '`';
    $lines[] = '- pricing_generation_preflight.reason: `' . (string)($blocker['reason'] ?? 'unknown') . '`';
    $lines[] = '- target_date_rows: `' . (string)($blocker['target_date_rows'] ?? 'unknown') . '`';
    $lines[] = '- room_type_count: `' . (string)($blocker['room_type_count'] ?? 'unknown') . '`';
    $lines[] = '- demand_forecast_count: `' . (string)($blocker['demand_forecast_count'] ?? 'unknown') . '`';
    $lines[] = '- competitor_analysis_recent_count: `' . (string)($blocker['competitor_analysis_recent_count'] ?? 'unknown') . '`';
    $lines[] = '- create_candidate_count: `' . (string)($blocker['create_candidate_count'] ?? 'unknown') . '`';
    $lines[] = '- pending_suggestion_count: `' . (string)($blocker['pending_suggestion_count'] ?? 'unknown') . '`';
    $lines[] = '- observed_hints: `' . json_encode($observedHints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '`';
    $lines[] = '';
    $lines[] = '## Operator Collection Priorities';
    $lines[] = '';
    $lines[] = '| Input | Required now | Current table count | Ctrip source hint | Operator action |';
    $lines[] = '|---|---:|---:|---:|---|';
    foreach ($priorities as $priority) {
        $row = ctrip_operator_bundle_map($priority);
        $lines[] = '| `' . (string)($row['input_code'] ?? '') . '` | `'
            . ((bool)($row['required_now'] ?? false) ? 'true' : 'false') . '` | `'
            . (string)($row['current_table_count'] ?? 0) . '` | `'
            . ((bool)($row['ctrip_source_hint_present'] ?? false) ? 'true' : 'false') . '` | '
            . (string)($row['operator_action'] ?? '') . ' |';
    }
    $lines[] = '';
    $lines[] = '> Ctrip source hints are not importable values. They only tell the operator where to look before filling pricing-input-fillable.json.';
    $lines[] = '> Spreadsheet users can use `operator-input-locators.csv` as the same metadata-only locator index; it is not an input file and carries no importable values.';
    $lines[] = '';
    if ($locatorItems !== []) {
        $lines[] = '## Operator Evidence Locators';
        $lines[] = '';
        $lines[] = '| Input | Locator status | Count | Row ids | Operator use |';
        $lines[] = '|---|---|---:|---|---|';
        foreach ($locatorItems as $inputCode => $locator) {
            $row = ctrip_operator_bundle_map($locator);
            $rowIds = [];
            foreach (ctrip_operator_bundle_list($row['locators'] ?? []) as $item) {
                $locatorRow = ctrip_operator_bundle_map($item);
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
    $lines[] = '## Fill Only These Sections';
    $lines[] = '';
    $lines[] = '- `operator_input_evidence`: confirmed_by, confirmed_at, room_type_source, price_guard_source, demand_forecast_source, competitor_price_source';
    $lines[] = '- `room_types`: key, name, base_price, min_price, max_price, room_count, is_enabled';
    $lines[] = '- `demand_forecasts`: room_type_key, forecast_date, predicted_occupancy, predicted_demand, confidence_score, forecast_method';
    $lines[] = '- `competitor_price_samples`: room_type_key, analysis_date, competitor_name, our_price, competitor_price, ota_platform=ctrip';
    $lines[] = '- `source_note`: optional row-level evidence note; when present it is preserved as `operator_row_source_note` in AI pricing source metadata and cannot replace `operator_input_evidence`.';
    $lines[] = '';
    $lines[] = '## Fillable File';
    $lines[] = '';
    $lines[] = '- `pricing-input-fillable.json` currently contains placeholders and is not executable until lint passes.';
    $lines[] = '- `pricing-input-intake.csv` is a spreadsheet-friendly intake file; convert it into `pricing-input-fillable.json` before linting if operators prefer CSV.';
    $lines[] = '- If CSV conversion fails, use `csv_issue_map.csv_row_number` and `csv_issue_map.csv_column` to fill the exact missing cells.';
    $lines[] = '- `operator-input-locators.csv` is metadata-only evidence navigation; it is not importable and must not be copied as pricing, demand, or competitor values.';
    $lines[] = '- In CSV or JSON rows, fill `source_note` with the exact operator evidence reference for that row when available; it remains Ctrip-only metadata, not a price write.';
    $lines[] = '- `pricing-input-template.json` is read-only context and must not be used as the business input file.';
    $lines[] = '- fillable_section_count: `' . count(array_intersect(array_keys($fillable), ['operator_input_evidence', 'room_types', 'demand_forecasts', 'competitor_price_samples'])) . '`';
    $lines[] = '';
    if ($placeholderDetails !== []) {
        $lines[] = '## Field-Level Real Input Checklist';
        $lines[] = '';
        $lines[] = '| Path | Group | Expected real input | Format guard | Forbidden fill |';
        $lines[] = '|---|---|---|---|---|';
        foreach ($placeholderDetails as $detail) {
            $row = ctrip_operator_bundle_map($detail);
            $lines[] = '| `' . ctrip_operator_bundle_markdown_cell((string)($row['path'] ?? '')) . '` | `'
                . ctrip_operator_bundle_markdown_cell((string)($row['group'] ?? 'unknown')) . '` | '
                . ctrip_operator_bundle_markdown_cell((string)($row['expected_real_input'] ?? '')) . ' | '
                . ctrip_operator_bundle_markdown_cell((string)($row['format_guard'] ?? '')) . ' | '
                . ctrip_operator_bundle_markdown_cell((string)($row['forbidden_fill'] ?? '')) . ' |';
        }
        $lines[] = '';
        $lines[] = '> Fill only real operator-verified Ctrip OTA channel values. This checklist is not an evidence substitute and does not make the file executable.';
        $lines[] = '';
    }
    $lines[] = '## Validation Sequence';
    foreach (['build_fillable_from_csv', 'preflight_no_execute', 'lint_only', 'validate_only', 'dry_run', 'pre_execute_gate', 'execute_to_pending_review'] as $key) {
        $command = trim((string)($commands[$key] ?? ''));
        if ($command === '') {
            continue;
        }
        $lines[] = '';
        $lines[] = '1. `' . $key . '`';
        $lines[] = '   ```powershell';
        $lines[] = '   ' . $command;
        $lines[] = '   ```';
    }
    $lines[] = '';
    $lines[] = '## Stop Conditions';
    $lines[] = '';
    $lines[] = '- Stop if any value is a sample, guess, fallback, verifier fixture, Meituan row, or whole-hotel value.';
    $lines[] = '- Stop if `source_scope` is not `ctrip_ota_channel`.';
    $lines[] = '- Stop if `auto_write_ota` becomes true.';
    $lines[] = '- Stop before operation intent until a generated AI suggestion is manually reviewed.';
    $lines[] = '- Stop before investment decision until operation ROI evidence is ready.';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

/**
 * @return string
 */
function ctrip_operator_bundle_operator_intake_form(array $fillable, array $manifest): string
{
    $scope = ctrip_operator_bundle_map($manifest['scope'] ?? []);
    $date = (string)($scope['business_date'] ?? ($fillable['business_date'] ?? ''));
    $hotelId = (string)($scope['hotel_id'] ?? ($fillable['hotel_id'] ?? 'unknown'));

    $lines = [];
    $lines[] = '# Ctrip Revenue AI Operator Intake Form';
    $lines[] = '';
    $lines[] = '- business_date: `' . $date . '`';
    $lines[] = '- platform: `ctrip`';
    $lines[] = '- hotel_id: `' . $hotelId . '`';
    $lines[] = '- source_scope: `ctrip_ota_channel`';
    $lines[] = '- raw_values_exposed: `false`';
    $lines[] = '- database_written: `false`';
    $lines[] = '- auto_write_ota: `false`';
    $lines[] = '- form_policy: `human_fillable_collection_not_importable`';
    $lines[] = '';
    $lines[] = '## Operator Confirmation';
    $lines[] = '';
    $lines[] = '| Field | Value | Expected evidence |';
    $lines[] = '|---|---|---|';
    $lines[] = '| `confirmed_by` |  | Operator name or role confirming the values. |';
    $lines[] = '| `confirmed_at` |  | Confirmation time in ISO-like local time. |';
    $lines[] = '| `room_type_source` |  | Source used for Ctrip room type mapping. |';
    $lines[] = '| `price_guard_source` |  | Source used for base price, min price, max price, and room count. |';
    $lines[] = '| `demand_forecast_source` |  | Source used for occupancy, demand, confidence, and method. |';
    $lines[] = '| `competitor_price_source` |  | Source used for Ctrip competitor price samples. |';
    $lines[] = '';
    $lines[] = '## Room Types And Price Guards';
    $lines[] = '';
    $lines[] = '| room_type_key | room_type_name | base_price | min_price | max_price | room_count | is_enabled | source_note |';
    $lines[] = '|---|---|---:|---:|---:|---:|---|---|';
    $lines[] = '|  |  |  |  |  |  | true |  |';
    $lines[] = '';
    $lines[] = '## Demand Forecasts';
    $lines[] = '';
    $lines[] = '| room_type_key | forecast_date | predicted_occupancy | predicted_demand | confidence_score | forecast_method | source_note |';
    $lines[] = '|---|---|---:|---:|---:|---|---|';
    $lines[] = '|  | ' . ctrip_operator_bundle_markdown_cell($date) . ' |  |  |  |  |  |';
    $lines[] = '';
    $lines[] = '## Ctrip Competitor Price Samples';
    $lines[] = '';
    $lines[] = '| room_type_key | analysis_date | competitor_name | our_price | competitor_price | ota_platform | source_note |';
    $lines[] = '|---|---|---|---:|---:|---|---|';
    $lines[] = '|  | ' . ctrip_operator_bundle_markdown_cell($date) . ' |  |  |  | ctrip |  |';
    $lines[] = '';
    $lines[] = '## Copy To JSON';
    $lines[] = '';
    $lines[] = '- Copy only verified values from this form into `pricing-input-fillable.json`.';
    $lines[] = '- Use `operator-input-locators.csv` only to find Ctrip metadata rows and source traces; do not copy locator rows as business values.';
    $lines[] = '- Keep each row `source_note` as the row-level evidence reference; it will be preserved as `operator_row_source_note` in AI pricing source metadata.';
    $lines[] = '- `source_note` does not replace `operator_input_evidence`; both are required for a defensible Ctrip-only handoff when row evidence exists.';
    $lines[] = '- This Markdown form is not executable and not importable evidence.';
    $lines[] = '- Run `verify:revenue-ai-ctrip-operator-bundle-preflight` after filling `pricing-input-fillable.json`.';
    $lines[] = '';
    $lines[] = '## Stop Conditions';
    $lines[] = '';
    $lines[] = '- Stop if any value is a sample, guess, fallback, verifier fixture, Meituan row, or whole-hotel value.';
    $lines[] = '- Stop if `source_scope` is not `ctrip_ota_channel`.';
    $lines[] = '- Stop if `auto_write_ota` becomes true.';
    $lines[] = '- Stop before operation intent until a generated AI suggestion is manually reviewed.';
    $lines[] = '- Stop before investment decision until operation ROI evidence is ready.';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

/**
 * @return string
 */
function ctrip_operator_bundle_closure_runbook(array $manifest): string
{
    $scope = ctrip_operator_bundle_map($manifest['scope'] ?? []);
    $commands = ctrip_operator_bundle_map($manifest['next_commands_after_filling_template'] ?? []);
    $date = (string)($scope['business_date'] ?? '');
    $hotelId = (string)($scope['hotel_id'] ?? 'unknown');

    $lines = [];
    $lines[] = '# Ctrip Revenue AI Closure Runbook';
    $lines[] = '';
    $lines[] = '- business_date: `' . $date . '`';
    $lines[] = '- platform: `ctrip`';
    $lines[] = '- hotel_id: `' . $hotelId . '`';
    $lines[] = '- source_scope: `ctrip_ota_channel`';
    $lines[] = '- auto_write_ota: `false`';
    $lines[] = '- runbook_policy: `manual_closure_sequence_no_ota_price_write`';
    $lines[] = '- chain: `Ctrip OTA evidence -> revenue analysis -> AI pricing suggestion -> manual review -> operation intent -> ROI evidence`';
    $lines[] = '';
    $lines[] = '## Before Start';
    $lines[] = '';
    $lines[] = '- Fill `pricing-input-fillable.json` with real operator-verified Ctrip values only.';
    $lines[] = '- Optional: fill `pricing-input-intake.csv`, then run `build_fillable_from_csv` to generate `pricing-input-fillable.json`.';
    $lines[] = '- If `build_fillable_from_csv` fails, use `csv_issue_map.csv_row_number` and `csv_issue_map.csv_column` to correct the intake CSV.';
    $lines[] = '- Use `operator-input-locators.csv` only to locate Ctrip metadata rows and source traces; it is not an input file and carries no business values.';
    $lines[] = '- Preserve row-level `source_note` values; dry-run and execute carry them as `operator_row_source_note` in AI pricing source metadata.';
    $lines[] = '- Keep `pricing-input-template.json`, `real-input-checklist.json`, and `pricing-input.schema.json` as guidance only.';
    $lines[] = '- Stop if any value is a sample, guess, fallback, verifier fixture, Meituan row, or whole-hotel value.';
    $lines[] = '';
    $lines[] = '## Sequence';
    $lines[] = '';
    $steps = [
        ['build_fillable_from_csv', 'Convert the spreadsheet intake into the fillable JSON file; no database or OTA write.'],
        ['preflight_no_execute', 'Confirm bundle structure and lint the filled input without executing.'],
        ['lint_only', 'Check the filled pricing input file before any local write.'],
        ['validate_only', 'Validate import behavior with rollback.'],
        ['dry_run', 'Run import dry-run with rollback.'],
        ['pre_execute_gate', 'Run the final pre-execute gate.'],
        ['execute_to_pending_review', 'Create the local AI pricing suggestion in pending manual review; no OTA price write.'],
        ['pending_review_packet', 'Inspect the pending review packet and capture `<pending-suggestion-id>`.'],
        ['review_decision_template', 'Export the manual review decision file for the pending suggestion.'],
        ['execute_review_and_create_intent', 'Execute the manual review and create the operation intent when approved.'],
        ['verify_roi_boundary', 'Verify the Ctrip operation/ROI boundary with rollback-only verifier evidence.'],
        ['verify_current_scope', 'Re-check the current Ctrip-only Revenue AI state.'],
    ];
    foreach ($steps as [$key, $description]) {
        $command = trim((string)($commands[$key] ?? ''));
        $lines[] = '### ' . $key;
        $lines[] = '';
        $lines[] = '- purpose: ' . $description;
        if ($command !== '') {
            $lines[] = '```powershell';
            $lines[] = $command;
            $lines[] = '```';
        }
        $lines[] = '';
    }
    $lines[] = '## Manual Evidence Gates';
    $lines[] = '';
    $lines[] = '- AI suggestion must stay `pending_review` until a human fills `operator_review_evidence`.';
    $lines[] = '- Operation intent requires room/rate mapping and must not imply OTA price write automation.';
    $lines[] = '- ROI evidence requires previous-day and next-day Ctrip metrics plus operator ROI source notes.';
    $lines[] = '- Investment review remains blocked until `operation_execution.roi_ready` is true.';
    $lines[] = '';
    $lines[] = '## Stop Conditions';
    $lines[] = '';
    $lines[] = '- Stop if `auto_write_ota` becomes true or any command introduces an OTA write path.';
    $lines[] = '- Stop if the pending suggestion id is missing after `execute_to_pending_review`.';
    $lines[] = '- Stop before operation intent if manual review evidence is incomplete.';
    $lines[] = '- Stop before investment decision if ROI evidence is incomplete or outside Ctrip OTA channel scope.';

    return implode(PHP_EOL, $lines) . PHP_EOL;
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
            'Fill operator_input_evidence with the operator, confirmation time, and source notes for room, price guard, demand forecast, and competitor price inputs.',
            'Use pricing-input-template.json as read-only context; do not edit verification commands as business inputs.',
            'Do not use Meituan rows, whole-hotel values, sample values, guessed values, fallback values, or verifier-only values.',
            'Do not create OTA price writes from this bundle; AI suggestions remain manual-review gated.',
        ],
        'next_commands_after_filling_template' => [
            'preflight_no_execute' => ctrip_operator_bundle_command('preflight_no_execute', $date, $resolvedHotelId, $outputDir),
            'build_fillable_from_csv' => ctrip_operator_bundle_command('build_fillable_from_csv', $date, $resolvedHotelId, $files['pricing_input_intake_csv'], $fillableFile),
            'lint_only' => ctrip_operator_bundle_command('lint_only', $date, $resolvedHotelId, $fillableFile),
            'validate_only' => ctrip_operator_bundle_command('validate_only', $date, $resolvedHotelId, $fillableFile),
            'dry_run' => ctrip_operator_bundle_command('dry_run', $date, $resolvedHotelId, $fillableFile),
            'pre_execute_gate' => ctrip_operator_bundle_command('pre_execute_gate', $date, $resolvedHotelId, $fillableFile),
            'execute_to_pending_review' => ctrip_operator_bundle_command('execute_to_pending_review', $date, $resolvedHotelId, $fillableFile),
            'pending_review_packet' => ctrip_operator_bundle_command('pending_review_packet', $date, $resolvedHotelId),
            'review_decision_template' => ctrip_operator_bundle_command('review_decision_template', $date, $resolvedHotelId),
            'execute_review_and_create_intent' => ctrip_operator_bundle_command('execute_review_and_create_intent', $date, $resolvedHotelId),
            'verify_roi_boundary' => ctrip_operator_bundle_command('verify_roi_boundary', $date, $resolvedHotelId),
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
    $realInputChecklist = ctrip_operator_bundle_real_input_checklist($fillableBody, $manifest);
    $pricingInputSchema = ctrip_operator_bundle_pricing_input_schema($date, $resolvedHotelId);
    $manifest['real_input_checklist'] = [
        'status' => $realInputChecklist['status'],
        'source_policy' => $realInputChecklist['source_policy'],
        'file' => 'real-input-checklist.json',
        'placeholder_count' => $realInputChecklist['placeholder_count'],
        'can_generate_pending_review' => $realInputChecklist['can_generate_pending_review'],
    ];
    $manifest['pricing_input_schema'] = [
        'file' => 'pricing-input.schema.json',
        'schema' => 'https://json-schema.org/draft/2020-12/schema',
        'source_scope' => 'ctrip_ota_channel',
        'schema_is_authoritative' => false,
        'authoritative_gate' => 'verify:revenue-ai-ctrip-operator-bundle-preflight',
    ];
    $manifest['pricing_input_intake_csv'] = [
        'file' => 'pricing-input-intake.csv',
        'status' => 'human_fillable_csv_not_importable_until_converted_and_linted',
        'source_scope' => 'ctrip_ota_channel',
        'auto_write_ota' => false,
        'converter_command' => 'build_fillable_from_csv',
    ];
    $manifest['operator_input_locators_csv'] = [
        'file' => 'operator-input-locators.csv',
        'status' => 'metadata_locator_csv_not_importable',
        'source_scope' => 'ctrip_ota_channel',
        'source_policy' => 'metadata_locator_only_no_values_no_import',
        'raw_values_exposed' => false,
        'database_written' => false,
        'auto_write_ota' => false,
        'importable_value' => false,
    ];
    $manifest['operator_intake_form'] = [
        'file' => 'OPERATOR_INTAKE_FORM.md',
        'status' => 'human_fillable_collection_not_importable',
        'source_scope' => 'ctrip_ota_channel',
        'auto_write_ota' => false,
        'can_generate_pending_review' => false,
    ];
    $manifest['closure_runbook'] = [
        'file' => 'CTRIP_CLOSURE_RUNBOOK.md',
        'status' => 'manual_closure_sequence_no_ota_price_write',
        'source_scope' => 'ctrip_ota_channel',
        'auto_write_ota' => false,
        'requires_operator_real_inputs' => true,
        'completion_gate' => 'operation_execution.roi_ready',
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
    $written['operator_intake_form_markdown'] = ctrip_operator_bundle_write_file(
        $files['operator_intake_form_markdown'],
        ctrip_operator_bundle_operator_intake_form($fillableBody, $manifest),
        $overwritten
    );
    $written['closure_runbook_markdown'] = ctrip_operator_bundle_write_file(
        $files['closure_runbook_markdown'],
        ctrip_operator_bundle_closure_runbook($manifest),
        $overwritten
    );
    $written['real_input_todo_markdown'] = ctrip_operator_bundle_write_file(
        $files['real_input_todo_markdown'],
        ctrip_operator_bundle_real_input_todo($operatorPacket, $fillableBody, $manifest),
        $overwritten
    );
    $written['real_input_checklist_json'] = ctrip_operator_bundle_write_file(
        $files['real_input_checklist_json'],
        ctrip_operator_bundle_json($realInputChecklist),
        $overwritten
    );
    $written['pricing_input_schema_json'] = ctrip_operator_bundle_write_file(
        $files['pricing_input_schema_json'],
        ctrip_operator_bundle_json($pricingInputSchema),
        $overwritten
    );
    $written['pricing_input_intake_csv'] = ctrip_operator_bundle_write_file(
        $files['pricing_input_intake_csv'],
        ctrip_operator_bundle_pricing_input_intake_csv($date, $resolvedHotelId),
        $overwritten
    );
    $written['operator_input_locators_csv'] = ctrip_operator_bundle_write_file(
        $files['operator_input_locators_csv'],
        ctrip_operator_bundle_operator_input_locators_csv($operatorPacket, $date, $resolvedHotelId),
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
