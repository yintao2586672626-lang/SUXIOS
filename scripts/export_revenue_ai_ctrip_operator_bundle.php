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
        'operator_action_sheet_csv' => $dir . DIRECTORY_SEPARATOR . 'operator-action-sheet.csv',
        'operator_confirmation_brief_markdown' => $dir . DIRECTORY_SEPARATOR . 'OPERATOR_CONFIRMATION_BRIEF.md',
        'operator_confirmation_brief_csv' => $dir . DIRECTORY_SEPARATOR . 'operator-confirmation-brief.csv',
        'operator_input_locators_csv' => $dir . DIRECTORY_SEPARATOR . 'operator-input-locators.csv',
        'operator_review_draft_markdown' => $dir . DIRECTORY_SEPARATOR . 'OPERATOR_REVIEW_DRAFT.md',
        'demand_trend_draft_json' => $dir . DIRECTORY_SEPARATOR . 'demand-trend-draft.json',
        'demand_trend_draft_markdown' => $dir . DIRECTORY_SEPARATOR . 'demand-trend-draft.md',
        'pricing_evidence_candidates_json' => $dir . DIRECTORY_SEPARATOR . 'pricing-evidence-candidates.json',
        'pricing_evidence_candidates_markdown' => $dir . DIRECTORY_SEPARATOR . 'pricing-evidence-candidates.md',
        'pricing_evidence_candidates_csv' => $dir . DIRECTORY_SEPARATOR . 'pricing-evidence-candidates.csv',
        'external_input_candidates_json' => $dir . DIRECTORY_SEPARATOR . 'external-input-candidates.json',
        'external_input_candidates_markdown' => $dir . DIRECTORY_SEPARATOR . 'external-input-candidates.md',
        'external_input_candidates_csv' => $dir . DIRECTORY_SEPARATOR . 'external-input-candidates.csv',
        'input_readiness_json' => $dir . DIRECTORY_SEPARATOR . 'input-readiness.json',
        'input_readiness_markdown' => $dir . DIRECTORY_SEPARATOR . 'input-readiness.md',
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
        'csv_to_json_preflight' => 'npm.cmd run verify:revenue-ai-ctrip-operator-csv-preflight -- --dir=' . $filePath . ' --date=' . $date . $hotelArg,
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
        'external_input_candidates' => 'npm.cmd run report:revenue-ai-ctrip-external-input-candidates -- --date=' . $date . $hotelArg . ' --format=markdown',
        'pricing_evidence_candidates' => 'npm.cmd run report:revenue-ai-ctrip-pricing-evidence-candidates -- --date=' . $date . $hotelArg . ' --format=markdown',
        'input_readiness_scan' => 'npm.cmd run report:revenue-ai-ctrip-input-readiness -- --date=' . $date . $hotelArg . ' --format=markdown',
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
function ctrip_operator_bundle_field_guidance(string $path, bool $trafficTrendDemandReady = false): array
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
        'demand_forecast_source' => ['Human-verifiable source for target-date demand forecast and confidence; Ctrip historical traffic trend is allowed via report:revenue-ai-ctrip-traffic-demand-trend.', 'source note or model/operator note'],
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
    ] + ctrip_operator_bundle_csv_cell_hint($path, $trafficTrendDemandReady);
}

/**
 * @return array<string, mixed>
 */
function ctrip_operator_bundle_csv_cell_hint(string $path, bool $trafficTrendDemandReady): array
{
    $field = (string)preg_replace('/^.*\./', '', $path);
    $column = $field;
    $section = 'unknown';
    $rowNumber = null;

    if (str_contains($path, '$.operator_input_evidence.')) {
        $section = 'evidence';
        $rowNumber = 2;
    } elseif (str_contains($path, '$.room_types.')) {
        $section = 'room_type';
        $rowNumber = 3;
        if ($field === 'key' || $field === 'name') {
            $column = 'room_type_' . $field;
        }
    } elseif (str_contains($path, '$.demand_forecasts.')) {
        $section = 'demand_forecast';
        $rowNumber = 4;
    } elseif (str_contains($path, '$.competitor_price_samples.')) {
        $section = 'competitor_price_sample';
        $rowNumber = $trafficTrendDemandReady ? 4 : 5;
    }

    return [
        'csv_file' => 'pricing-input-intake.csv',
        'csv_section' => $section,
        'csv_row_number' => $rowNumber,
        'csv_column' => $column,
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
function ctrip_operator_bundle_demand_source_is_traffic_trend(string $source): bool
{
    return trim($source) !== ''
        && str_contains($source, 'ctrip_historical_traffic_trend')
        && str_contains($source, 'report:revenue-ai-ctrip-traffic-demand-trend');
}

function ctrip_operator_bundle_pricing_input_intake_csv(string $date, ?int $hotelId, string $demandForecastSource = ''): string
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
    $evidence['demand_forecast_source'] = ctrip_operator_bundle_demand_source_is_traffic_trend($demandForecastSource)
        ? $demandForecastSource
        : '';
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
    if (!ctrip_operator_bundle_demand_source_is_traffic_trend($demandForecastSource)) {
        $forecast = $base;
        $forecast['section'] = 'demand_forecast';
        $forecast['forecast_date'] = $date;
        $forecast['required_fields'] = 'room_type_key; forecast_date; predicted_occupancy; predicted_demand; confidence_score; source_note';
        $forecast['expected_real_input'] = 'Target-date demand trend forecast for the same Ctrip room type and hotel scope; Ctrip historical traffic trend draft is allowed via report:revenue-ai-ctrip-traffic-demand-trend.';
        $forecast['format_guard'] = 'predicted_occupancy 0-100; predicted_demand numeric > 0; confidence_score 0-1';
        $forecast['forbidden_fill'] = $forbiddenFill;
        $rows[] = $forecast;
    }
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
 * @param array<int, array<string, mixed>> $items
 * @return array<string, mixed>
 */
function ctrip_operator_bundle_first_candidate(array $items): array
{
    foreach ($items as $item) {
        $row = ctrip_operator_bundle_map($item);
        if ($row !== []) {
            return $row;
        }
    }

    return [];
}

/**
 * @param array<int, array<string, mixed>> $items
 */
function ctrip_operator_bundle_price_hint(array $items): string
{
    $hints = [];
    foreach (array_slice($items, 0, 3) as $item) {
        $row = ctrip_operator_bundle_map($item);
        $metric = trim((string)($row['metric_key'] ?? ''));
        $value = trim((string)($row['observed_value'] ?? ''));
        if ($metric !== '' && $value !== '') {
            $hints[] = $metric . '=' . $value;
        }
    }

    return implode('; ', $hints);
}

/**
 * @param array<int, array<string, mixed>> $items
 * @param array<int, string> $keys
 */
function ctrip_operator_bundle_candidate_hint(array $items, array $keys, int $limit = 3): string
{
    $hints = [];
    foreach (array_slice($items, 0, $limit) as $item) {
        $row = ctrip_operator_bundle_map($item);
        $parts = [];
        foreach ($keys as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        if ($parts !== []) {
            $hints[] = implode(' ', $parts);
        }
    }

    return implode('; ', array_values(array_unique($hints)));
}

/**
 * @param array<int, array<string, mixed>> $items
 */
function ctrip_operator_bundle_candidate_sources(array $items, int $limit = 3): string
{
    $sources = [];
    foreach (array_slice($items, 0, $limit) as $item) {
        $row = ctrip_operator_bundle_map($item);
        $source = trim((string)($row['source_note'] ?? ''));
        if ($source !== '') {
            $sources[] = $source;
        }
    }

    return implode('; ', array_values(array_unique($sources)));
}

function ctrip_operator_bundle_join_nonempty(string ...$parts): string
{
    return implode(' | ', array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== '')));
}

/**
 * @return array<string, string>
 */
function ctrip_operator_bundle_locator_capture_contract(string $inputCode, bool $trafficTrendDemandReady): array
{
    $contracts = [
        'operator_input_evidence' => [
            'capture_source_entry' => 'Operator review record; not platform auto capture',
            'capture_module' => 'operator_accountability',
            'field_contract' => 'confirmed_by, confirmed_at, room_type_source, price_guard_source, demand_forecast_source, competitor_price_source',
            'accepted_evidence' => 'named operator confirmation with source notes',
            'missing_state' => 'missing_operator_confirmation',
            'operator_next_step' => 'Fill accountable role, timestamp, and source notes before any pending-review generation.',
        ],
        'room_types_enabled' => [
            'capture_source_entry' => 'Ctrip ebooking room/ARI visible pages; endpoint hints: queryRoomTypeInfo, queryVendibilityRoom',
            'capture_module' => 'room_type_mapping',
            'field_contract' => 'room_type_name, room_type_key, enabled_or_sale_status, sellable_room_count; Ctrip OTA channel only',
            'accepted_evidence' => 'operator-confirmed Ctrip room setup or authorized export/sanitized response trace',
            'missing_state' => 'missing_ctrip_room_type_mapping_or_sellable_room_count',
            'operator_next_step' => 'Confirm actual enabled Ctrip room type and sellable count before filling room_types.',
        ],
        'floor_price_or_min_rate_guard' => [
            'capture_source_entry' => 'Ctrip room price/ARI/rate-plan visible pages or operator price guard policy; do not infer from avg_price',
            'capture_module' => 'price_guard_confirmation',
            'field_contract' => 'base_price, min_price_or_floor_price, max_price for the same Ctrip room_type_key',
            'accepted_evidence' => 'operator-approved price guard or authorized Ctrip rate/ARI evidence',
            'missing_state' => 'missing_floor_price_or_min_rate_guard',
            'operator_next_step' => 'Fill price guards from operator policy; observed averages and external assumptions are context only.',
        ],
        'demand_forecast' => $trafficTrendDemandReady
            ? [
                'capture_source_entry' => 'report:revenue-ai-ctrip-traffic-demand-trend; source=ctrip_historical_traffic_trend',
                'capture_module' => 'ctrip_traffic_demand_trend',
                'field_contract' => 'order_submit_num, demand_index, trend_score_0_100, confidence, forecast_method; not whole-hotel occupancy',
                'accepted_evidence' => 'existing Ctrip traffic aggregate trend for the target path',
                'missing_state' => 'not_blocking_when_ctrip_historical_traffic_trend_ready',
                'operator_next_step' => 'Keep demand_forecast_source; leave demand_forecasts empty unless recording a manual override.',
            ]
            : [
                'capture_source_entry' => 'Ctrip traffic report or operator manual demand forecast evidence',
                'capture_module' => 'manual_or_ctrip_traffic_demand_forecast',
                'field_contract' => 'forecast_date, room_type_key, predicted_occupancy_or_trend_score, predicted_demand, confidence_score, forecast_method',
                'accepted_evidence' => 'operator-approved forecast source or accepted Ctrip historical traffic trend',
                'missing_state' => 'missing_target_date_demand_forecast_or_traffic_trend',
                'operator_next_step' => 'Provide an accepted demand_forecast_source or a manual forecast row before execution.',
            ],
        'competitor_price_samples' => [
            'capture_source_entry' => 'Ctrip competitor/loss analysis visible pages or operator-reviewed Ctrip comparable price sample; endpoint hints: queryCompetingHotelsV2, fetchCompetitiveMarket, getLossOrderCompeteHotel',
            'capture_module' => 'competitor_sample_confirmation',
            'field_contract' => 'analysis_date within recent 7 days, room_type_key, competitor_name, our_price, competitor_price, ota_platform=ctrip',
            'accepted_evidence' => 'named Ctrip competitor sample in the same sample window and comparable room context',
            'missing_state' => 'missing_current_named_ctrip_competitor_price_sample',
            'operator_next_step' => 'Capture at least one named Ctrip competitor price sample; aggregate ranks are context only.',
        ],
    ];

    return $contracts[$inputCode] ?? [
        'capture_source_entry' => 'Operator-provided Ctrip OTA evidence',
        'capture_module' => 'manual_pricing_configuration',
        'field_contract' => 'operator-verified Ctrip OTA channel value with source note',
        'accepted_evidence' => 'operator-confirmed value with human-verifiable source',
        'missing_state' => 'missing_operator_verified_ctrip_input',
        'operator_next_step' => 'Fill only real Ctrip OTA channel values after operator confirmation.',
    ];
}

/**
 * @return string
 */
function ctrip_operator_bundle_operator_action_sheet_csv(array $pricingEvidence, array $demandTrend, array $externalInputCandidates, string $date, ?int $hotelId): string
{
    $headers = [
        'action_group',
        'target_file',
        'target_json_path',
        'target_csv_section',
        'target_csv_column',
        'candidate_hint',
        'candidate_source_note',
        'operator_required_action',
        'importable_value',
        'auto_write_ota',
        'forbidden_fill',
    ];
    $base = array_fill_keys($headers, '');
    $base['target_file'] = 'pricing-input-intake.csv';
    $base['importable_value'] = 'false';
    $base['auto_write_ota'] = 'false';
    $base['forbidden_fill'] = 'sample, guessed, fallback, verifier-only, Meituan, whole-hotel, or direct candidate value';
    $rows = [];

    $candidates = ctrip_operator_bundle_map($pricingEvidence['candidates'] ?? []);
    $roomCandidate = ctrip_operator_bundle_first_candidate(ctrip_operator_bundle_list($candidates['room_type_candidates'] ?? []));
    $priceCandidates = ctrip_operator_bundle_list($candidates['price_observation_candidates'] ?? []);
    $priceCandidate = ctrip_operator_bundle_first_candidate($priceCandidates);
    $competitorCandidate = ctrip_operator_bundle_first_candidate(ctrip_operator_bundle_list($candidates['competitor_aggregate_candidates'] ?? []));
    $externalCandidates = ctrip_operator_bundle_map($externalInputCandidates['candidates'] ?? []);
    $externalRoomCounts = ctrip_operator_bundle_list($externalCandidates['room_count_candidates'] ?? []);
    $externalPriceAssumptions = ctrip_operator_bundle_list($externalCandidates['price_assumption_candidates'] ?? []);
    $externalCompetitorRefs = ctrip_operator_bundle_list($externalCandidates['competitor_reference_candidates'] ?? []);
    $externalRoomConcepts = ctrip_operator_bundle_list($externalCandidates['room_concept_candidates'] ?? []);
    $externalMarketDistributions = ctrip_operator_bundle_list($externalCandidates['market_distribution_candidates'] ?? []);
    $forecast = ctrip_operator_bundle_map($demandTrend['forecast_draft'] ?? []);
    $forecastRow = ctrip_operator_bundle_map($forecast['import_row_draft'] ?? []);
    $roomConceptHint = ctrip_operator_bundle_candidate_hint($externalRoomConcepts, ['observed_value']);
    $roomCountHint = ctrip_operator_bundle_candidate_hint($externalRoomCounts, ['observed_value']);
    $externalPriceHint = ctrip_operator_bundle_candidate_hint($externalPriceAssumptions, ['observed_value']);
    $externalCompetitorHint = ctrip_operator_bundle_join_nonempty(
        ctrip_operator_bundle_candidate_hint($externalCompetitorRefs, ['competitor_name_candidate', 'historical_ctrip_price_reference']),
        ctrip_operator_bundle_candidate_hint($externalMarketDistributions, ['observed_value'])
    );
    $externalRoomSources = ctrip_operator_bundle_candidate_sources(array_merge($externalRoomConcepts, $externalRoomCounts));
    $externalPriceSources = ctrip_operator_bundle_candidate_sources($externalPriceAssumptions);
    $externalCompetitorSources = ctrip_operator_bundle_candidate_sources(array_merge($externalCompetitorRefs, $externalMarketDistributions));

    $add = static function (array $row) use (&$rows, $base): void {
        $rows[] = array_merge($base, $row);
    };

    $add([
        'action_group' => 'operator_accountability',
        'target_json_path' => '$.operator_input_evidence.confirmed_by',
        'target_csv_section' => 'evidence',
        'target_csv_column' => 'confirmed_by',
        'operator_required_action' => 'Fill the operator name or role accountable for the submitted Ctrip inputs.',
    ]);
    $add([
        'action_group' => 'operator_accountability',
        'target_json_path' => '$.operator_input_evidence.confirmed_at',
        'target_csv_section' => 'evidence',
        'target_csv_column' => 'confirmed_at',
        'operator_required_action' => 'Fill the local confirmation timestamp starting with YYYY-MM-DD.',
    ]);
    $add([
        'action_group' => 'demand_source',
        'target_json_path' => '$.operator_input_evidence.demand_forecast_source',
        'target_csv_section' => 'evidence',
        'target_csv_column' => 'demand_forecast_source',
        'candidate_hint' => (string)($forecastRow['source_note'] ?? ''),
        'candidate_source_note' => (string)($forecastRow['source_note'] ?? ''),
        'operator_required_action' => 'Keep the Ctrip historical traffic trend source unless replacing it with a manual forecast override.',
    ]);
    $add([
        'action_group' => 'room_type_mapping',
        'target_json_path' => '$.room_types.0.key',
        'target_csv_section' => 'room_type',
        'target_csv_column' => 'room_type_key',
        'candidate_hint' => ctrip_operator_bundle_join_nonempty((string)($roomCandidate['room_type_name_candidate'] ?? ''), 'operator must assign stable room_type_key'),
        'candidate_source_note' => ctrip_operator_bundle_join_nonempty((string)($roomCandidate['source_note'] ?? ''), $externalRoomSources),
        'operator_required_action' => 'Confirm the stable Ctrip room_type_key and reuse it across room type and competitor sample rows.',
    ]);
    $add([
        'action_group' => 'room_type_mapping',
        'target_json_path' => '$.room_types.0.name',
        'target_csv_section' => 'room_type',
        'target_csv_column' => 'room_type_name',
        'candidate_hint' => ctrip_operator_bundle_join_nonempty((string)($roomCandidate['room_type_name_candidate'] ?? ''), 'external historical room concept: ' . $roomConceptHint),
        'candidate_source_note' => ctrip_operator_bundle_join_nonempty((string)($roomCandidate['source_note'] ?? ''), $externalRoomSources),
        'operator_required_action' => 'Confirm the actual Ctrip room type name and room_type_key before copying.',
    ]);
    $add([
        'action_group' => 'room_type_mapping',
        'target_json_path' => '$.room_types.0.room_count',
        'target_csv_section' => 'room_type',
        'target_csv_column' => 'room_count',
        'candidate_hint' => ctrip_operator_bundle_join_nonempty('observed Ctrip room nights=' . (string)($roomCandidate['room_nights_observed'] ?? ''), 'external room count clues=' . $roomCountHint),
        'candidate_source_note' => ctrip_operator_bundle_join_nonempty((string)($roomCandidate['source_note'] ?? ''), $externalRoomSources),
        'operator_required_action' => 'Fill sellable room count from operator inventory; observed nights and external room count clues are context only.',
    ]);
    foreach ([
        ['$.room_types.0.base_price', 'base_price', 'Fill current operator-verified Ctrip sell price; observed averages are context only.'],
        ['$.room_types.0.min_price', 'min_price', 'Fill operator-approved floor/protection price; never infer it from observed average price.'],
        ['$.room_types.0.max_price', 'max_price', 'Fill operator-approved upper guard price; never infer it from observed average price.'],
    ] as [$path, $column, $action]) {
        $add([
            'action_group' => 'price_guard_confirmation',
            'target_json_path' => $path,
            'target_csv_section' => 'room_type',
            'target_csv_column' => $column,
            'candidate_hint' => ctrip_operator_bundle_join_nonempty(ctrip_operator_bundle_price_hint($priceCandidates), 'external 350/450 assumptions=' . $externalPriceHint),
            'candidate_source_note' => ctrip_operator_bundle_join_nonempty((string)($priceCandidate['source_note'] ?? ''), $externalPriceSources),
            'operator_required_action' => $action,
        ]);
    }
    foreach ([
        ['$.competitor_price_samples.0.room_type_key', 'room_type_key', 'Reuse the operator-confirmed Ctrip room_type_key from the room type row.'],
        ['$.competitor_price_samples.0.competitor_name', 'competitor_name', 'Fill a named comparable Ctrip competitor from an operator-reviewed sample.'],
        ['$.competitor_price_samples.0.our_price', 'our_price', 'Fill our Ctrip price from the same sample window and comparable room context.'],
        ['$.competitor_price_samples.0.competitor_price', 'competitor_price', 'Fill the named competitor Ctrip price from the same sample window.'],
    ] as [$path, $column, $action]) {
        $add([
            'action_group' => 'competitor_sample_confirmation',
            'target_json_path' => $path,
            'target_csv_section' => 'competitor_price_sample',
            'target_csv_column' => $column,
            'candidate_hint' => ctrip_operator_bundle_join_nonempty('competitor aggregate context only; named sample still required', 'external historical competitor or market clues=' . $externalCompetitorHint),
            'candidate_source_note' => ctrip_operator_bundle_join_nonempty((string)($competitorCandidate['source_note'] ?? ''), $externalCompetitorSources),
            'operator_required_action' => $action,
        ]);
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
 * @return array<int, array<string, string>>
 */
function ctrip_operator_bundle_operator_confirmation_brief_rows(array $pricingEvidence, array $demandTrend, array $externalInputCandidates, string $date, ?int $hotelId): array
{
    $candidates = ctrip_operator_bundle_map($pricingEvidence['candidates'] ?? []);
    $roomCandidate = ctrip_operator_bundle_first_candidate(ctrip_operator_bundle_list($candidates['room_type_candidates'] ?? []));
    $priceCandidates = ctrip_operator_bundle_list($candidates['price_observation_candidates'] ?? []);
    $priceCandidate = ctrip_operator_bundle_first_candidate($priceCandidates);
    $competitorCandidate = ctrip_operator_bundle_first_candidate(ctrip_operator_bundle_list($candidates['competitor_aggregate_candidates'] ?? []));
    $externalCandidates = ctrip_operator_bundle_map($externalInputCandidates['candidates'] ?? []);
    $externalRoomCounts = ctrip_operator_bundle_list($externalCandidates['room_count_candidates'] ?? []);
    $externalPriceAssumptions = ctrip_operator_bundle_list($externalCandidates['price_assumption_candidates'] ?? []);
    $externalCompetitorRefs = ctrip_operator_bundle_list($externalCandidates['competitor_reference_candidates'] ?? []);
    $externalRoomConcepts = ctrip_operator_bundle_list($externalCandidates['room_concept_candidates'] ?? []);
    $externalMarketDistributions = ctrip_operator_bundle_list($externalCandidates['market_distribution_candidates'] ?? []);
    $forecast = ctrip_operator_bundle_map($demandTrend['forecast_draft'] ?? []);
    $forecastRow = ctrip_operator_bundle_map($forecast['import_row_draft'] ?? []);

    $roomConceptHint = ctrip_operator_bundle_candidate_hint($externalRoomConcepts, ['observed_value']);
    $roomCountHint = ctrip_operator_bundle_candidate_hint($externalRoomCounts, ['observed_value']);
    $externalPriceHint = ctrip_operator_bundle_candidate_hint($externalPriceAssumptions, ['observed_value']);
    $externalCompetitorHint = ctrip_operator_bundle_join_nonempty(
        ctrip_operator_bundle_candidate_hint($externalCompetitorRefs, ['competitor_name_candidate', 'historical_ctrip_price_reference']),
        ctrip_operator_bundle_candidate_hint($externalMarketDistributions, ['observed_value'])
    );
    $externalRoomSources = ctrip_operator_bundle_candidate_sources(array_merge($externalRoomConcepts, $externalRoomCounts));
    $externalPriceSources = ctrip_operator_bundle_candidate_sources($externalPriceAssumptions);
    $externalCompetitorSources = ctrip_operator_bundle_candidate_sources(array_merge($externalCompetitorRefs, $externalMarketDistributions));
    $roomSource = ctrip_operator_bundle_join_nonempty((string)($roomCandidate['source_note'] ?? ''), $externalRoomSources);
    $priceSource = ctrip_operator_bundle_join_nonempty((string)($priceCandidate['source_note'] ?? ''), $externalPriceSources);
    $competitorSource = ctrip_operator_bundle_join_nonempty((string)($competitorCandidate['source_note'] ?? ''), $externalCompetitorSources);
    $roomHint = ctrip_operator_bundle_join_nonempty((string)($roomCandidate['room_type_name_candidate'] ?? ''), 'external room concept=' . $roomConceptHint);
    $roomCountContext = ctrip_operator_bundle_join_nonempty('observed Ctrip room nights=' . (string)($roomCandidate['room_nights_observed'] ?? ''), 'external room count clues=' . $roomCountHint);
    $priceContext = ctrip_operator_bundle_join_nonempty(ctrip_operator_bundle_price_hint($priceCandidates), 'external 350/450 assumptions=' . $externalPriceHint);
    $competitorContext = ctrip_operator_bundle_join_nonempty('competitor aggregate context only; named sample still required', 'external competitor or market clues=' . $externalCompetitorHint);

    $headers = [
        'priority',
        'input_status',
        'input_group',
        'target_file',
        'target_json_path',
        'target_csv_row',
        'target_csv_section',
        'target_csv_column',
        'candidate_hint',
        'candidate_source_note',
        'required_confirmation',
        'format_guard',
        'operator_confirmed_value',
        'importable_value',
        'auto_write_ota',
        'forbidden_fill',
    ];
    $base = array_fill_keys($headers, '');
    $base['target_file'] = 'pricing-input-intake.csv';
    $base['operator_confirmed_value'] = '';
    $base['importable_value'] = 'false';
    $base['auto_write_ota'] = 'false';
    $base['forbidden_fill'] = 'sample, guessed, fallback, verifier-only, Meituan, whole-hotel, or direct candidate value';

    $rows = [];
    $add = static function (array $row) use (&$rows, $base): void {
        $rows[] = array_merge($base, $row);
    };

    $add([
        'priority' => '1',
        'input_status' => 'required_manual_input',
        'input_group' => 'operator_input_evidence',
        'target_json_path' => '$.operator_input_evidence.confirmed_by',
        'target_csv_row' => '2',
        'target_csv_section' => 'evidence',
        'target_csv_column' => 'confirmed_by',
        'required_confirmation' => 'Operator name or role accountable for the submitted Ctrip inputs.',
        'format_guard' => 'non-empty text',
    ]);
    $add([
        'priority' => '1',
        'input_status' => 'required_manual_input',
        'input_group' => 'operator_input_evidence',
        'target_json_path' => '$.operator_input_evidence.confirmed_at',
        'target_csv_row' => '2',
        'target_csv_section' => 'evidence',
        'target_csv_column' => 'confirmed_at',
        'required_confirmation' => 'Local confirmation timestamp for the Ctrip input review.',
        'format_guard' => 'must start with YYYY-MM-DD',
    ]);
    $add([
        'priority' => '1',
        'input_status' => 'required_manual_input',
        'input_group' => 'operator_input_evidence',
        'target_json_path' => '$.operator_input_evidence.room_type_source',
        'target_csv_row' => '2',
        'target_csv_section' => 'evidence',
        'target_csv_column' => 'room_type_source',
        'candidate_hint' => $roomHint,
        'candidate_source_note' => $roomSource,
        'required_confirmation' => 'Human-verifiable source for current Ctrip room type, key, and sellable room count.',
        'format_guard' => 'source note or evidence path',
    ]);
    $add([
        'priority' => '1',
        'input_status' => 'required_manual_input',
        'input_group' => 'operator_input_evidence',
        'target_json_path' => '$.operator_input_evidence.price_guard_source',
        'target_csv_row' => '2',
        'target_csv_section' => 'evidence',
        'target_csv_column' => 'price_guard_source',
        'candidate_hint' => $priceContext,
        'candidate_source_note' => $priceSource,
        'required_confirmation' => 'Human-verifiable source for operator-approved base/min/max Ctrip price guard.',
        'format_guard' => 'source note or evidence path',
    ]);
    $add([
        'priority' => '1',
        'input_status' => 'ready_from_ctrip_traffic_trend',
        'input_group' => 'operator_input_evidence',
        'target_json_path' => '$.operator_input_evidence.demand_forecast_source',
        'target_csv_row' => '2',
        'target_csv_section' => 'evidence',
        'target_csv_column' => 'demand_forecast_source',
        'candidate_hint' => (string)($forecastRow['source_note'] ?? ''),
        'candidate_source_note' => (string)($forecastRow['source_note'] ?? ''),
        'required_confirmation' => 'Keep the Ctrip historical traffic trend source unless replacing it with a manual forecast override.',
        'format_guard' => 'ctrip_historical_traffic_trend source note',
    ]);
    $add([
        'priority' => '1',
        'input_status' => 'required_manual_input',
        'input_group' => 'operator_input_evidence',
        'target_json_path' => '$.operator_input_evidence.competitor_price_source',
        'target_csv_row' => '2',
        'target_csv_section' => 'evidence',
        'target_csv_column' => 'competitor_price_source',
        'candidate_hint' => $competitorContext,
        'candidate_source_note' => $competitorSource,
        'required_confirmation' => 'Human-verifiable source for recent-7-day named Ctrip competitor price samples.',
        'format_guard' => 'source note or evidence path',
    ]);
    $add([
        'priority' => '2',
        'input_status' => 'required_manual_input',
        'input_group' => 'room_types',
        'target_json_path' => '$.room_types.0.key',
        'target_csv_row' => '3',
        'target_csv_section' => 'room_type',
        'target_csv_column' => 'room_type_key',
        'candidate_hint' => $roomHint,
        'candidate_source_note' => $roomSource,
        'required_confirmation' => 'Confirm a stable Ctrip room_type_key and reuse it in competitor sample rows.',
        'format_guard' => 'stable non-empty key',
    ]);
    $add([
        'priority' => '2',
        'input_status' => 'required_manual_input',
        'input_group' => 'room_types',
        'target_json_path' => '$.room_types.0.name',
        'target_csv_row' => '3',
        'target_csv_section' => 'room_type',
        'target_csv_column' => 'room_type_name',
        'candidate_hint' => $roomHint,
        'candidate_source_note' => $roomSource,
        'required_confirmation' => 'Confirm the current Ctrip room type name.',
        'format_guard' => 'non-empty text',
    ]);
    foreach ([
        ['base_price', '$.room_types.0.base_price', 'Current operator-verified Ctrip sell price for the room type.', 'numeric > 0'],
        ['min_price', '$.room_types.0.min_price', 'Operator-approved floor/protection price; never infer it from observed averages.', 'numeric > 0 and <= base_price'],
        ['max_price', '$.room_types.0.max_price', 'Operator-approved upper guard price; never infer it from observed averages.', 'numeric >= base_price'],
    ] as [$column, $path, $confirmation, $format]) {
        $add([
            'priority' => '2',
            'input_status' => 'required_manual_input',
            'input_group' => 'room_types',
            'target_json_path' => $path,
            'target_csv_row' => '3',
            'target_csv_section' => 'room_type',
            'target_csv_column' => $column,
            'candidate_hint' => $priceContext,
            'candidate_source_note' => $priceSource,
            'required_confirmation' => $confirmation,
            'format_guard' => $format,
        ]);
    }
    $add([
        'priority' => '2',
        'input_status' => 'required_manual_input',
        'input_group' => 'room_types',
        'target_json_path' => '$.room_types.0.room_count',
        'target_csv_row' => '3',
        'target_csv_section' => 'room_type',
        'target_csv_column' => 'room_count',
        'candidate_hint' => $roomCountContext,
        'candidate_source_note' => $roomSource,
        'required_confirmation' => 'Operator-confirmed sellable room count for the Ctrip room type.',
        'format_guard' => 'positive integer',
    ]);
    foreach ([
        ['room_type_key', '$.competitor_price_samples.0.room_type_key', 'Reuse the confirmed room_type_key from the room type row.', 'same non-empty key as room_types.0.key'],
        ['competitor_name', '$.competitor_price_samples.0.competitor_name', 'Real Ctrip competitor hotel/name used for this price sample.', 'non-empty text'],
        ['our_price', '$.competitor_price_samples.0.our_price', 'Our Ctrip price observed in the same recent-7-day sample window.', 'numeric > 0'],
        ['competitor_price', '$.competitor_price_samples.0.competitor_price', 'Named competitor Ctrip price observed in the same sample window.', 'numeric > 0'],
    ] as [$column, $path, $confirmation, $format]) {
        $add([
            'priority' => '3',
            'input_status' => $column === 'room_type_key' ? 'required_same_as_room_type' : 'required_manual_input',
            'input_group' => 'competitor_price_samples',
            'target_json_path' => $path,
            'target_csv_row' => '4',
            'target_csv_section' => 'competitor_price_sample',
            'target_csv_column' => $column,
            'candidate_hint' => $competitorContext,
            'candidate_source_note' => $competitorSource,
            'required_confirmation' => $confirmation,
            'format_guard' => $format,
        ]);
    }

    return $rows;
}

function ctrip_operator_bundle_operator_confirmation_brief_markdown(array $pricingEvidence, array $demandTrend, array $externalInputCandidates, array $manifest): string
{
    $scope = ctrip_operator_bundle_map($manifest['scope'] ?? []);
    $date = (string)($scope['business_date'] ?? '');
    $hotelId = isset($scope['hotel_id']) ? (int)$scope['hotel_id'] : null;
    $rows = ctrip_operator_bundle_operator_confirmation_brief_rows($pricingEvidence, $demandTrend, $externalInputCandidates, $date, $hotelId);

    $lines = [];
    $lines[] = '# Ctrip Operator Confirmation Brief';
    $lines[] = '';
    $lines[] = '- business_date: `' . ctrip_operator_bundle_markdown_cell($date) . '`';
    $lines[] = '- platform: `ctrip`';
    $lines[] = '- hotel_id: `' . ctrip_operator_bundle_markdown_cell($hotelId === null ? 'unknown' : (string)$hotelId) . '`';
    $lines[] = '- source_scope: `ctrip_ota_channel`';
    $lines[] = '- source_policy: `operator_confirmation_brief_no_values_no_import`';
    $lines[] = '- candidate_values_exposed: `true`';
    $lines[] = '- database_written: `false`';
    $lines[] = '- auto_write_ota: `false`';
    $lines[] = '- importable_value: `false`';
    $lines[] = '- target_file: `pricing-input-intake.csv`';
    $lines[] = '';
    $lines[] = '## Minimum Confirmation Rows';
    $lines[] = '';
    $lines[] = '| priority | status | csv row | csv column | candidate hint | required confirmation |';
    $lines[] = '|---:|---|---:|---|---|---|';
    foreach ($rows as $row) {
        $lines[] = '| ' . ctrip_operator_bundle_markdown_cell($row['priority'] ?? '')
            . ' | `' . ctrip_operator_bundle_markdown_cell($row['input_status'] ?? '') . '`'
            . ' | ' . ctrip_operator_bundle_markdown_cell($row['target_csv_row'] ?? '')
            . ' | `' . ctrip_operator_bundle_markdown_cell($row['target_csv_column'] ?? '') . '`'
            . ' | ' . ctrip_operator_bundle_markdown_cell($row['candidate_hint'] ?? '')
            . ' | ' . ctrip_operator_bundle_markdown_cell($row['required_confirmation'] ?? '') . ' |';
    }
    $lines[] = '';
    $lines[] = '## Rules';
    $lines[] = '';
    $lines[] = '- `operator_confirmed_value` is intentionally blank in the CSV brief; fill only verified Ctrip OTA channel values in `pricing-input-intake.csv` or `pricing-input-fillable.json`.';
    $lines[] = '- Candidate hints are review aids only: room names are not room keys, observed averages are not floor/protection guards, and competitor aggregates are not named price samples.';
    $lines[] = '- Demand is already satisfiable through `ctrip_historical_traffic_trend`; keep `demand_forecast_source` unless recording an explicit manual forecast override.';
    $lines[] = '- Run `verify:revenue-ai-ctrip-operator-csv-preflight` or `verify:revenue-ai-ctrip-operator-bundle-preflight` after filling values.';
    $lines[] = '- Stop if any value is sample, guessed, fallback, verifier-only, Meituan, whole-hotel, or if `auto_write_ota` becomes true.';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function ctrip_operator_bundle_operator_confirmation_brief_csv(array $pricingEvidence, array $demandTrend, array $externalInputCandidates, string $date, ?int $hotelId): string
{
    $rows = ctrip_operator_bundle_operator_confirmation_brief_rows($pricingEvidence, $demandTrend, $externalInputCandidates, $date, $hotelId);
    $headers = [
        'priority',
        'input_status',
        'input_group',
        'target_file',
        'target_json_path',
        'target_csv_row',
        'target_csv_section',
        'target_csv_column',
        'candidate_hint',
        'candidate_source_note',
        'required_confirmation',
        'format_guard',
        'operator_confirmed_value',
        'importable_value',
        'auto_write_ota',
        'forbidden_fill',
    ];
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
function ctrip_operator_bundle_operator_input_locators_csv(array $operatorPacket, string $date, ?int $hotelId, bool $trafficTrendDemandReady): string
{
    $headers = [
        'input_code',
        'locator_status',
        'locator_count',
        'operator_use',
        'capture_source_entry',
        'capture_module',
        'field_contract',
        'accepted_evidence',
        'missing_state',
        'operator_next_step',
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
        $captureContract = ctrip_operator_bundle_locator_capture_contract((string)$inputCode, $trafficTrendDemandReady);
        if ($locatorRows === []) {
            $rows[] = [
                'input_code' => (string)$inputCode,
                'locator_status' => (string)($group['status'] ?? 'no_metadata_locator'),
                'locator_count' => '0',
                'operator_use' => (string)($group['operator_use'] ?? ''),
                'capture_source_entry' => $captureContract['capture_source_entry'],
                'capture_module' => $captureContract['capture_module'],
                'field_contract' => $captureContract['field_contract'],
                'accepted_evidence' => $captureContract['accepted_evidence'],
                'missing_state' => $captureContract['missing_state'],
                'operator_next_step' => $captureContract['operator_next_step'],
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
                'capture_source_entry' => $captureContract['capture_source_entry'],
                'capture_module' => $captureContract['capture_module'],
                'field_contract' => $captureContract['field_contract'],
                'accepted_evidence' => $captureContract['accepted_evidence'],
                'missing_state' => $captureContract['missing_state'],
                'operator_next_step' => $captureContract['operator_next_step'],
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
    $operatorEvidence = ctrip_operator_bundle_map($fillable['operator_input_evidence'] ?? []);
    $demandForecastSource = trim((string)($operatorEvidence['demand_forecast_source'] ?? ''));
    $trafficTrendDemandReady = ctrip_operator_bundle_demand_source_is_traffic_trend($demandForecastSource);
    $fillableInputs = [];
    foreach (['operator_input_evidence', 'room_types', 'demand_forecasts', 'competitor_price_samples'] as $key) {
        $fillableInputs[$key] = $fillable[$key] ?? [];
    }
    $items = array_map(
        static fn(string $path): array => ctrip_operator_bundle_field_guidance($path, $trafficTrendDemandReady),
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
function ctrip_operator_bundle_pricing_input_schema(string $date, ?int $hotelId, bool $trafficTrendDemandReady = false): array
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
                'minItems' => $trafficTrendDemandReady ? 0 : 1,
                'description' => $trafficTrendDemandReady
                    ? 'Can remain empty when operator_input_evidence.demand_forecast_source names the Ctrip historical traffic trend report.'
                    : 'Required unless operator_input_evidence.demand_forecast_source names the Ctrip historical traffic trend report.',
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
    $p0Authority = ctrip_operator_bundle_map($manifest['p0_authority'] ?? []);
    $p0AuthorityStatus = (string)($p0Authority['status'] ?? 'not_reverified_by_bundle');
    $p0AuthorityCommand = trim((string)($p0Authority['required_gate_command'] ?? ''));
    $operatorEvidence = ctrip_operator_bundle_map($fillable['operator_input_evidence'] ?? []);
    $demandForecastSource = trim((string)($operatorEvidence['demand_forecast_source'] ?? ''));
    $demandForecastSourceIsTrafficTrend = ctrip_operator_bundle_demand_source_is_traffic_trend($demandForecastSource);
    $fillableInputs = [];
    foreach (['operator_input_evidence', 'room_types', 'demand_forecasts', 'competitor_price_samples'] as $key) {
        $fillableInputs[$key] = $fillable[$key] ?? [];
    }
    $placeholderDetails = array_map(
        static fn(string $path): array => ctrip_operator_bundle_field_guidance($path, $demandForecastSourceIsTrafficTrend),
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
    $lines[] = '- p0_authority_status: `' . $p0AuthorityStatus . '`';
    if ($p0AuthorityCommand !== '') {
        $lines[] = '- p0_authority_command: `' . ctrip_operator_bundle_markdown_cell($p0AuthorityCommand) . '`';
    }
    $lines[] = '';
    $lines[] = '## Current Evidence';
    $lines[] = '';
    $lines[] = '- pricing_generation_preflight.status: `' . (string)($blocker['status'] ?? 'unknown') . '`';
    $lines[] = '- pricing_generation_preflight.reason: `' . (string)($blocker['reason'] ?? 'unknown') . '`';
    $lines[] = '- target_date_rows: `' . (string)($blocker['target_date_rows'] ?? 'unknown') . '`';
    $lines[] = '- room_type_count: `' . (string)($blocker['room_type_count'] ?? 'unknown') . '`';
    $lines[] = '- demand_forecast_count: `' . (string)($blocker['demand_forecast_count'] ?? 'unknown') . '`';
    if ($demandForecastSourceIsTrafficTrend) {
        $lines[] = '- demand_forecast_source: `ctrip_historical_traffic_trend`';
        $lines[] = '- ctrip_traffic_demand_forecast_count: `' . (string)($blocker['ctrip_traffic_demand_forecast_count'] ?? 'unknown') . '`';
    }
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
    if ($demandForecastSourceIsTrafficTrend) {
        $lines[] = '- `demand_forecasts`: leave empty for the current traffic-trend demand source; fill only if the operator intentionally replaces it with a manual forecast row.';
    } else {
        $lines[] = '- `demand_forecasts`: room_type_key, forecast_date, predicted_occupancy, predicted_demand, confidence_score, forecast_method';
    }
    $lines[] = '- `competitor_price_samples`: room_type_key, analysis_date, competitor_name, our_price, competitor_price, ota_platform=ctrip';
    $lines[] = '- `source_note`: optional row-level evidence note; when present it is preserved as `operator_row_source_note` in AI pricing source metadata and cannot replace `operator_input_evidence`.';
    $lines[] = '';
    $lines[] = '## Fillable File';
    $lines[] = '';
    $lines[] = '- `pricing-input-fillable.json` currently contains placeholders and is not executable until lint passes.';
    $lines[] = '- Start with `OPERATOR_REVIEW_DRAFT.md` to review Ctrip candidate evidence and the traffic demand trend draft in one place; it is not an import file.';
    $lines[] = '- `external-input-candidates.*` lists external project-material clues only; 120/137 room counts, 350/450 price assumptions, historical room concepts, and historical competitor or market references are not importable until an operator confirms current Ctrip room mapping, price guards, and competitor samples.';
    $lines[] = '- Use `operator-action-sheet.csv` to map candidate hints to exact intake CSV fields; leave business values blank until operator confirmation.';
    $lines[] = '- `pricing-input-intake.csv` is a spreadsheet-friendly intake file; convert it into `pricing-input-fillable.json` before linting if operators prefer CSV.';
    if ($demandForecastSourceIsTrafficTrend) {
        $lines[] = '- `operator_input_evidence.demand_forecast_source`: `' . ctrip_operator_bundle_markdown_cell($demandForecastSource) . '`';
        $lines[] = '- `demand_forecasts` can stay empty for this hotel/date because Revenue AI uses the Ctrip historical traffic trend source above.';
    } else {
        $lines[] = '- `demand_trend_draft` can produce a Ctrip historical traffic trend source_note for the demand forecast row; it still requires operator room_type_key mapping.';
    }
    $lines[] = '- `pricing-evidence-candidates.*` can help locate Ctrip room/price/competitor context, but its values are not importable until an operator confirms room mapping, price guards, and named competitor samples.';
    $lines[] = '- Do not use Ctrip price observations as floor/protection prices, and do not use competitor aggregates as named competitor price samples.';
    $lines[] = '- If CSV conversion fails, use `csv_issue_map.csv_row_number` and `csv_issue_map.csv_column` to fill the exact missing cells.';
    $lines[] = '- `operator-input-locators.csv` is metadata-only evidence navigation; it is not importable and must not be copied as pricing, demand, or competitor values.';
    $lines[] = '- In CSV or JSON rows, fill `source_note` with the exact operator evidence reference for that row when available; it remains Ctrip-only metadata, not a price write.';
    $lines[] = '- `pricing-input-template.json` is read-only context and must not be used as the business input file.';
    $lines[] = '- fillable_section_count: `' . count(array_intersect(array_keys($fillable), ['operator_input_evidence', 'room_types', 'demand_forecasts', 'competitor_price_samples'])) . '`';
    $lines[] = '';
    if ($placeholderDetails !== []) {
        $lines[] = '## Minimum CSV Fill Cells';
        $lines[] = '';
        $lines[] = '| JSON path | CSV file | CSV row | CSV section | CSV column | Expected real input |';
        $lines[] = '|---|---|---:|---|---|---|';
        foreach ($placeholderDetails as $detail) {
            $row = ctrip_operator_bundle_map($detail);
            $rowNumber = $row['csv_row_number'] ?? null;
            $lines[] = '| `' . ctrip_operator_bundle_markdown_cell((string)($row['path'] ?? '')) . '` | `'
                . ctrip_operator_bundle_markdown_cell((string)($row['csv_file'] ?? 'pricing-input-intake.csv')) . '` | `'
                . ctrip_operator_bundle_markdown_cell($rowNumber === null ? 'unknown' : (string)$rowNumber) . '` | `'
                . ctrip_operator_bundle_markdown_cell((string)($row['csv_section'] ?? 'unknown')) . '` | `'
                . ctrip_operator_bundle_markdown_cell((string)($row['csv_column'] ?? 'unknown')) . '` | '
                . ctrip_operator_bundle_markdown_cell((string)($row['expected_real_input'] ?? '')) . ' |';
        }
        $lines[] = '';
        $lines[] = '> These cells are the current minimum spreadsheet inputs. Fill only operator-verified Ctrip OTA channel values; do not copy locator rows or candidate values as business values.';
        $lines[] = '';
        $lines[] = '## Field-Level Real Input Checklist';
        $lines[] = '';
        $lines[] = '| Path | Group | CSV column | Expected real input | Format guard | Forbidden fill |';
        $lines[] = '|---|---|---|---|---|---|';
        foreach ($placeholderDetails as $detail) {
            $row = ctrip_operator_bundle_map($detail);
            $lines[] = '| `' . ctrip_operator_bundle_markdown_cell((string)($row['path'] ?? '')) . '` | `'
                . ctrip_operator_bundle_markdown_cell((string)($row['group'] ?? 'unknown')) . '` | '
                . '`' . ctrip_operator_bundle_markdown_cell((string)($row['csv_column'] ?? 'unknown')) . '` | '
                . ctrip_operator_bundle_markdown_cell((string)($row['expected_real_input'] ?? '')) . ' | '
                . ctrip_operator_bundle_markdown_cell((string)($row['format_guard'] ?? '')) . ' | '
                . ctrip_operator_bundle_markdown_cell((string)($row['forbidden_fill'] ?? '')) . ' |';
        }
        $lines[] = '';
        $lines[] = '> Fill only real operator-verified Ctrip OTA channel values. This checklist is not an evidence substitute and does not make the file executable.';
        $lines[] = '';
    }
    $lines[] = '## Validation Sequence';
    foreach (['external_input_candidates', 'pricing_evidence_candidates', 'demand_trend_draft', 'build_fillable_from_csv', 'csv_to_json_preflight', 'preflight_no_execute', 'lint_only', 'validate_only', 'dry_run', 'pre_execute_gate', 'execute_to_pending_review'] as $key) {
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
function ctrip_operator_bundle_operator_intake_form(array $fillable, array $manifest, bool $trafficTrendDemandReady = false): string
{
    $scope = ctrip_operator_bundle_map($manifest['scope'] ?? []);
    $operatorEvidence = ctrip_operator_bundle_map($fillable['operator_input_evidence'] ?? []);
    $date = (string)($scope['business_date'] ?? ($fillable['business_date'] ?? ''));
    $hotelId = (string)($scope['hotel_id'] ?? ($fillable['hotel_id'] ?? 'unknown'));
    $demandForecastSource = trim((string)($operatorEvidence['demand_forecast_source'] ?? ''));

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
    $lines[] = '| `demand_forecast_source` | ' . ctrip_operator_bundle_markdown_cell($trafficTrendDemandReady ? $demandForecastSource : '') . ' | Source used for occupancy/trend score, demand index, confidence, and method; `report:revenue-ai-ctrip-traffic-demand-trend` is allowed. |';
    $lines[] = '| `competitor_price_source` |  | Source used for Ctrip competitor price samples. |';
    $lines[] = '| `external_input_candidates` | `external-input-candidates.*` | Optional project-material clues only; room counts, 350/450 assumptions, historical room concepts, and historical competitor or market references are not importable values. |';
    $lines[] = '| `pricing_evidence_candidates` | `pricing-evidence-candidates.*` | Optional locator aid only; candidate room names, observed prices, and competitor aggregates are not importable values. |';
    $lines[] = '| `operator_action_sheet` | `operator-action-sheet.csv` | Maps candidate hints to exact intake fields; still requires operator confirmation and is not importable. |';
    $lines[] = '';
    $lines[] = '## Room Types And Price Guards';
    $lines[] = '';
    $lines[] = '| room_type_key | room_type_name | base_price | min_price | max_price | room_count | is_enabled | source_note |';
    $lines[] = '|---|---|---:|---:|---:|---:|---|---|';
    $lines[] = '|  |  |  |  |  |  | true |  |';
    $lines[] = '';
    if ($trafficTrendDemandReady) {
        $lines[] = '## Demand Forecast Source';
        $lines[] = '';
        $lines[] = '- No demand_forecasts row is required on the Ctrip traffic-trend path.';
        $lines[] = '- Keep `operator_input_evidence.demand_forecast_source` on the Ctrip historical traffic trend report unless the operator intentionally replaces it with a manual forecast override.';
        $lines[] = '- Manual override only: add `demand_forecasts` rows only when replacing the traffic-trend demand source with operator-verified room-type-level forecast evidence.';
        $lines[] = '- Do not copy `demand_trend_draft` values into `demand_forecasts` for the current traffic-trend path.';
        $lines[] = '';
    } else {
        $lines[] = '## Demand Forecasts';
        $lines[] = '';
        $lines[] = '| room_type_key | forecast_date | predicted_occupancy | predicted_demand | confidence_score | forecast_method | source_note |';
        $lines[] = '|---|---|---:|---:|---:|---|---|';
        $lines[] = '|  | ' . ctrip_operator_bundle_markdown_cell($date) . ' |  |  |  |  |  |';
        $lines[] = '';
    }
    $lines[] = '## Ctrip Competitor Price Samples';
    $lines[] = '';
    $lines[] = '| room_type_key | analysis_date | competitor_name | our_price | competitor_price | ota_platform | source_note |';
    $lines[] = '|---|---|---|---:|---:|---|---|';
    $lines[] = '|  | ' . ctrip_operator_bundle_markdown_cell($date) . ' |  |  |  | ctrip |  |';
    $lines[] = '';
    $lines[] = '## Copy To JSON';
    $lines[] = '';
    $lines[] = '- Copy only verified values from this form into `pricing-input-fillable.json`.';
    $lines[] = '- Review `OPERATOR_REVIEW_DRAFT.md` first when using Ctrip candidates or AI traffic trend; it is a review aid, not an import source.';
    $lines[] = '- Use `operator-action-sheet.csv` as a field-by-field action list; do not import it or copy candidate hints without operator confirmation.';
    $lines[] = '- Use `pricing-evidence-candidates.*` only as Ctrip locator/context evidence; do not copy candidate values without operator confirmation.';
    $lines[] = '- Use `operator-input-locators.csv` only to find Ctrip metadata rows and source traces; do not copy locator rows as business values.';
    if ($trafficTrendDemandReady) {
        $lines[] = '- For demand forecast, use `demand_trend_draft` only as the Ctrip historical traffic trend source reference; leave `demand_forecasts` empty unless recording an explicit manual override.';
    } else {
        $lines[] = '- For demand forecast, run `demand_trend_draft` when using AI trend from past Ctrip traffic; copy its `source_note` and keep the trend-score boundary visible.';
    }
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
function ctrip_operator_bundle_operator_review_draft(array $pricingEvidence, array $demandTrend, array $externalInputCandidates, array $manifest): string
{
    $scope = ctrip_operator_bundle_map($manifest['scope'] ?? []);
    $date = (string)($scope['business_date'] ?? '');
    $hotelId = (string)($scope['hotel_id'] ?? 'unknown');
    $forecast = ctrip_operator_bundle_map($demandTrend['forecast_draft'] ?? []);
    $forecastRow = ctrip_operator_bundle_map($forecast['import_row_draft'] ?? []);
    $candidates = ctrip_operator_bundle_map($pricingEvidence['candidates'] ?? []);
    $roomCandidates = ctrip_operator_bundle_list($candidates['room_type_candidates'] ?? []);
    $priceCandidates = ctrip_operator_bundle_list($candidates['price_observation_candidates'] ?? []);
    $competitorCandidates = ctrip_operator_bundle_list($candidates['competitor_aggregate_candidates'] ?? []);
    $externalCandidates = ctrip_operator_bundle_map($externalInputCandidates['candidates'] ?? []);
    $externalRoomCandidates = ctrip_operator_bundle_list($externalCandidates['room_count_candidates'] ?? []);
    $externalPriceCandidates = ctrip_operator_bundle_list($externalCandidates['price_assumption_candidates'] ?? []);
    $externalCompetitorCandidates = ctrip_operator_bundle_list($externalCandidates['competitor_reference_candidates'] ?? []);
    $externalRoomConceptCandidates = ctrip_operator_bundle_list($externalCandidates['room_concept_candidates'] ?? []);
    $externalMarketDistributionCandidates = ctrip_operator_bundle_list($externalCandidates['market_distribution_candidates'] ?? []);

    $lines = [];
    $lines[] = '# Ctrip Operator Review Draft';
    $lines[] = '';
    $lines[] = '- business_date: `' . $date . '`';
    $lines[] = '- platform: `ctrip`';
    $lines[] = '- hotel_id: `' . $hotelId . '`';
    $lines[] = '- source_scope: `ctrip_ota_channel`';
    $lines[] = '- draft_policy: `human_review_draft_not_importable`';
    $lines[] = '- database_written: `false`';
    $lines[] = '- auto_write_ota: `false`';
    $lines[] = '- importable_value: `false`';
    $lines[] = '';
    $lines[] = '## Demand Trend Draft';
    $lines[] = '';
    $lines[] = '| forecast_date | primary_metric | trend_direction | predicted_occupancy | predicted_demand | confidence_score | source_note |';
    $lines[] = '|---|---|---|---:|---:|---:|---|';
    if ($forecastRow !== []) {
        $lines[] = '| ' . ctrip_operator_bundle_markdown_cell((string)($forecastRow['forecast_date'] ?? ''))
            . ' | `' . ctrip_operator_bundle_markdown_cell((string)($forecast['primary_metric'] ?? '')) . '`'
            . ' | `' . ctrip_operator_bundle_markdown_cell((string)($forecast['trend_direction'] ?? '')) . '`'
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($forecastRow['predicted_occupancy'] ?? ''))
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($forecastRow['predicted_demand'] ?? ''))
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($forecastRow['confidence_score'] ?? ''))
            . ' | `' . ctrip_operator_bundle_markdown_cell((string)($forecastRow['source_note'] ?? '')) . '` |';
    } else {
        $lines[] = '|  |  |  |  |  |  | no demand trend draft available |';
    }
    $lines[] = '';
    $lines[] = '> `predicted_occupancy` here is a Ctrip traffic trend score, not whole-hotel occupancy. This trend can satisfy `operator_input_evidence.demand_forecast_source`; do not add a `demand_forecasts` row unless the operator intentionally records a manual forecast override.';
    $lines[] = '';
    $lines[] = '## External Project-Material Clues Not Inputs';
    $lines[] = '';
    $lines[] = '| clue_type | value | source_note | copy_rule |';
    $lines[] = '|---|---|---|---|';
    foreach (array_slice($externalRoomCandidates, 0, 8) as $candidate) {
        $row = ctrip_operator_bundle_map($candidate);
        $lines[] = '| room_count_candidate'
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($row['observed_value'] ?? ''))
            . ' | `' . ctrip_operator_bundle_markdown_cell((string)($row['source_note'] ?? '')) . '`'
            . ' | do not copy as room type mapping; operator must confirm Ctrip room_type_key, room_count, and source |';
    }
    foreach (array_slice($externalPriceCandidates, 0, 8) as $candidate) {
        $row = ctrip_operator_bundle_map($candidate);
        $lines[] = '| price_assumption_candidate'
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($row['observed_value'] ?? ''))
            . ' | `' . ctrip_operator_bundle_markdown_cell((string)($row['source_note'] ?? '')) . '`'
            . ' | do not copy as base/min/max; operator must approve current Ctrip price guards |';
    }
    foreach (array_slice($externalCompetitorCandidates, 0, 8) as $candidate) {
        $row = ctrip_operator_bundle_map($candidate);
        $lines[] = '| historical_competitor_reference'
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($row['competitor_name_candidate'] ?? '') . ' ' . (string)($row['historical_ctrip_price_reference'] ?? ''))
            . ' | `' . ctrip_operator_bundle_markdown_cell((string)($row['source_note'] ?? '')) . '`'
            . ' | use only to choose competitors; collect current recent-7-day named Ctrip price samples |';
    }
    foreach (array_slice($externalRoomConceptCandidates, 0, 8) as $candidate) {
        $row = ctrip_operator_bundle_map($candidate);
        $lines[] = '| historical_room_concept_candidate'
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($row['observed_value'] ?? ''))
            . ' | `' . ctrip_operator_bundle_markdown_cell((string)($row['source_note'] ?? '')) . '`'
            . ' | historical concept only; operator must confirm current Ctrip room_type_key, room_count, and price guards |';
    }
    foreach (array_slice($externalMarketDistributionCandidates, 0, 8) as $candidate) {
        $row = ctrip_operator_bundle_map($candidate);
        $lines[] = '| historical_market_distribution'
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($row['observed_value'] ?? ''))
            . ' | `' . ctrip_operator_bundle_markdown_cell((string)($row['source_note'] ?? '')) . '`'
            . ' | historical market context only; collect current recent-7-day named Ctrip competitor samples |';
    }
    if ($externalRoomCandidates === [] && $externalPriceCandidates === [] && $externalCompetitorCandidates === [] && $externalRoomConceptCandidates === [] && $externalMarketDistributionCandidates === []) {
        $lines[] = '|  |  |  | no external project-material candidates available |';
    }
    $lines[] = '';
    $lines[] = '## Room Type Candidates To Confirm';
    $lines[] = '';
    $lines[] = '| room_type_name_candidate | observed nights | sale percent | source_note | copy_rule |';
    $lines[] = '|---|---:|---:|---|---|';
    foreach (array_slice($roomCandidates, 0, 10) as $candidate) {
        $row = ctrip_operator_bundle_map($candidate);
        $lines[] = '| ' . ctrip_operator_bundle_markdown_cell((string)($row['room_type_name_candidate'] ?? ''))
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($row['room_nights_observed'] ?? ''))
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($row['sale_percent_observed'] ?? ''))
            . ' | `' . ctrip_operator_bundle_markdown_cell((string)($row['source_note'] ?? '')) . '`'
            . ' | operator must confirm room_type_key, room_count, base_price, min_price, and max_price |';
    }
    $lines[] = '';
    $lines[] = '## Price Observations Not Guards';
    $lines[] = '';
    $lines[] = '| metric_key | observed_value | source_note | copy_rule |';
    $lines[] = '|---|---:|---|---|';
    foreach (array_slice($priceCandidates, 0, 10) as $candidate) {
        $row = ctrip_operator_bundle_map($candidate);
        $lines[] = '| `' . ctrip_operator_bundle_markdown_cell((string)($row['metric_key'] ?? '')) . '`'
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($row['observed_value'] ?? ''))
            . ' | `' . ctrip_operator_bundle_markdown_cell((string)($row['source_note'] ?? '')) . '`'
            . ' | do not copy as base/min/max unless operator confirms it as the actual Ctrip pricing guard |';
    }
    $lines[] = '';
    $lines[] = '## Competitor Aggregates Not Price Samples';
    $lines[] = '';
    $lines[] = '| source_note | revenue | orders | revenue_per_order | exposure | detail_visitor | still_required |';
    $lines[] = '|---|---:|---:|---:|---:|---:|---|';
    foreach (array_slice($competitorCandidates, 0, 10) as $candidate) {
        $row = ctrip_operator_bundle_map($candidate);
        $lines[] = '| `' . ctrip_operator_bundle_markdown_cell((string)($row['source_note'] ?? '')) . '`'
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($row['competitor_revenue_aggregate'] ?? ''))
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($row['competitor_orders_aggregate'] ?? ''))
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($row['competitor_revenue_per_order_aggregate'] ?? ''))
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($row['competitor_list_exposure'] ?? ''))
            . ' | ' . ctrip_operator_bundle_markdown_cell((string)($row['competitor_detail_visitor'] ?? ''))
            . ' | competitor_name, our_price, competitor_price, room_type_key |';
    }
    $lines[] = '';
    $lines[] = '## Fillable Copy Rules';
    $lines[] = '';
    $lines[] = '- Copy values only into `pricing-input-fillable.json` after operator confirmation.';
    $lines[] = '- Demand trend values should stay as `operator_input_evidence.demand_forecast_source` for this traffic-trend path; copy them into `demand_forecasts` only for an explicit manual forecast override with room type mapping.';
    $lines[] = '- External project-material clues are not importable: 120/137 are not room type mappings, 350/450 are not price guards, historical room concepts are not current Ctrip room mappings, and historical competitor or market references are not current Ctrip samples.';
    $lines[] = '- Candidate room names are not enough; `room_type_key`, `room_count`, `base_price`, `min_price`, and `max_price` remain required real inputs.';
    $lines[] = '- Price observations are not floor/protection prices.';
    $lines[] = '- Competitor aggregates are not named competitor price samples.';
    $lines[] = '- Stop if any value is a sample, guess, fallback, verifier fixture, Meituan row, or whole-hotel value.';

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
    $lines[] = '- Open `OPERATOR_REVIEW_DRAFT.md` first to review Ctrip candidate evidence and demand trend draft together.';
    $lines[] = '- Use `operator-action-sheet.csv` to map candidate hints to exact `pricing-input-intake.csv` fields; it is not importable and does not fill values.';
    $lines[] = '- Fill `pricing-input-fillable.json` with real operator-verified Ctrip values only.';
    $lines[] = '- Optional: run `external_input_candidates` to review external project-material room count, 350/450 price assumption, historical room concept, and historical Ctrip market reference clues; these are not importable.';
    $lines[] = '- Optional: run `pricing_evidence_candidates` to review Ctrip room name candidates, observed price metrics, and competitor aggregates before filling real values.';
    $lines[] = '- External project-material clues do not replace Ctrip room type mapping, operator price guards, or current recent-7-day named Ctrip competitor price samples.';
    $lines[] = '- Candidate price observations are not floor/protection prices; competitor aggregates are not named competitor price samples.';
    $lines[] = '- If demand forecast is based on AI trend, run `demand_trend_draft`; it reads past Ctrip traffic aggregates and writes no database or OTA data.';
    $lines[] = '- Optional: fill `pricing-input-intake.csv`, then run `csv_to_json_preflight` to generate `pricing-input-fillable.json` and run the no-execute gate in one command.';
    $lines[] = '- If `csv_to_json_preflight` or `build_fillable_from_csv` fails, use `csv_issue_map.csv_row_number` and `csv_issue_map.csv_column` to correct the intake CSV.';
    $lines[] = '- Use `operator-input-locators.csv` only to locate Ctrip metadata rows and source traces; it is not an input file and carries no business values.';
    $lines[] = '- Preserve row-level `source_note` values; dry-run and execute carry them as `operator_row_source_note` in AI pricing source metadata.';
    $lines[] = '- Keep `pricing-input-template.json`, `real-input-checklist.json`, and `pricing-input.schema.json` as guidance only.';
    $lines[] = '- Stop if any value is a sample, guess, fallback, verifier fixture, Meituan row, or whole-hotel value.';
    $lines[] = '';
    $lines[] = '## Sequence';
    $lines[] = '';
    $steps = [
        ['input_readiness_scan', 'Check current local room type, price guard, demand-source, and competitor-sample readiness; no raw rows, database write, or OTA write.'],
        ['external_input_candidates', 'Review external project-material clues for operator confirmation only; no database or OTA write and not importable.'],
        ['pricing_evidence_candidates', 'Review Ctrip candidate evidence for operator locating only; no database or OTA write and not importable.'],
        ['demand_trend_draft', 'Generate the Ctrip historical traffic demand trend draft for demand_forecast source_note; no database or OTA write.'],
        ['build_fillable_from_csv', 'Convert the spreadsheet intake into the fillable JSON file; no database or OTA write.'],
        ['csv_to_json_preflight', 'Convert the spreadsheet intake and run no-execute preflight; local JSON only, no database or OTA write.'],
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
    $scopeSummary = ctrip_operator_bundle_map($scopePayload['summary'] ?? []);
    $scopeP0Authority = ctrip_operator_bundle_map($scopeSummary['p0_authority_verifier'] ?? []);
    $scopeP0Gate = ctrip_operator_bundle_map($scopeSummary['p0_downstream_gate'] ?? []);
    $p0AuthorityStatus = trim((string)($scopeP0Authority['status'] ?? ''));
    $p0AuthoritySummary = [
        'status' => $p0AuthorityStatus !== '' ? $p0AuthorityStatus : 'not_reverified_by_bundle',
        'source' => 'current-scope.json',
        'required_gate_command' => $scopeP0Gate['required_gate_command'] ?? null,
        'exit_code' => $scopeP0Authority['exit_code'] ?? null,
        'summary' => $scopeP0Authority['summary'] ?? null,
        'raw_capture_read_by_bundle' => false,
        'database_written' => false,
        'auto_write_ota' => false,
    ];

    $inputReadinessJsonRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        [PHP_BINARY, 'scripts/report_revenue_ai_ctrip_input_readiness.php', '--format=json'],
        $date,
        $hotelId
    ), $root);
    $inputReadinessPayload = ctrip_operator_bundle_decode_json($inputReadinessJsonRun['stdout']);

    $inputReadinessMarkdownRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        [PHP_BINARY, 'scripts/report_revenue_ai_ctrip_input_readiness.php', '--format=markdown'],
        $date,
        $hotelId
    ), $root);

    $externalInputJsonRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        ['node', 'scripts/report_revenue_ai_ctrip_external_input_candidates.mjs', '--format=json'],
        $date,
        $hotelId
    ), $root);
    $externalInputPayload = ctrip_operator_bundle_decode_json($externalInputJsonRun['stdout']);

    $externalInputMarkdownRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        ['node', 'scripts/report_revenue_ai_ctrip_external_input_candidates.mjs', '--format=markdown'],
        $date,
        $hotelId
    ), $root);

    $externalInputCsvRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        ['node', 'scripts/report_revenue_ai_ctrip_external_input_candidates.mjs', '--format=csv'],
        $date,
        $hotelId
    ), $root);

    $pricingEvidenceJsonRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        [PHP_BINARY, 'scripts/report_revenue_ai_ctrip_pricing_evidence_candidates.php', '--format=json'],
        $date,
        $hotelId
    ), $root);
    $pricingEvidencePayload = ctrip_operator_bundle_decode_json($pricingEvidenceJsonRun['stdout']);

    $pricingEvidenceMarkdownRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        [PHP_BINARY, 'scripts/report_revenue_ai_ctrip_pricing_evidence_candidates.php', '--format=markdown'],
        $date,
        $hotelId
    ), $root);

    $pricingEvidenceCsvRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        [PHP_BINARY, 'scripts/report_revenue_ai_ctrip_pricing_evidence_candidates.php', '--format=csv'],
        $date,
        $hotelId
    ), $root);

    $demandTrendJsonRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        [PHP_BINARY, 'scripts/report_revenue_ai_ctrip_traffic_demand_trend.php', '--format=json'],
        $date,
        $hotelId
    ), $root);
    $demandTrendPayload = ctrip_operator_bundle_decode_json($demandTrendJsonRun['stdout']);

    $demandTrendMarkdownRun = ctrip_operator_bundle_run_process(ctrip_operator_bundle_scoped_args(
        [PHP_BINARY, 'scripts/report_revenue_ai_ctrip_traffic_demand_trend.php', '--format=markdown'],
        $date,
        $hotelId
    ), $root);

    $scope = ctrip_operator_bundle_map($operatorPacket['scope'] ?? []);
    $resolvedHotelId = (int)($scope['hotel_id'] ?? 0) > 0
        ? (int)$scope['hotel_id']
        : ($hotelId ?? ((int)($templateBody['hotel_id'] ?? 0) ?: null));
    $templateFile = $files['pricing_input_template_json'];
    $fillableFile = $files['pricing_input_fillable_json'];
    $fillableBody = ctrip_operator_bundle_fillable_payload($templateBody);
    $currentBlocker = ctrip_operator_bundle_map($operatorPacket['current_blocker'] ?? []);
    $demandTrendHotelArg = $resolvedHotelId === null ? ' --hotel-id=<hotel-id>' : ' --hotel-id=' . $resolvedHotelId;

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
        'p0_authority' => $p0AuthoritySummary,
        'operator_fill_required' => [
            'Edit pricing-input-fillable.json with operator-verified Ctrip OTA channel values before running lint or execute.',
            'Fill operator_input_evidence with the operator, confirmation time, and source notes for room, price guard, demand forecast, and competitor price inputs.',
            'Use external-input-candidates.* only as project-material clues for operator confirmation; room counts, 350/450 assumptions, historical room concepts, and historical competitor or market references remain non-importable.',
            'Use pricing-evidence-candidates.* only to locate candidate Ctrip evidence; candidate values remain non-importable until operator-confirmed.',
            'For demand_forecast_source, Ctrip historical traffic trend is allowed: keep it in operator_input_evidence.demand_forecast_source; demand_forecasts may remain empty when preflight no longer requires demand_forecast.',
            'Do not use observed Ctrip average prices as floor/protection prices, or competitor aggregates as named competitor price samples.',
            'Use pricing-input-template.json as read-only context; do not edit verification commands as business inputs.',
            'Do not use Meituan rows, whole-hotel values, sample values, guessed values, fallback values, or verifier-only values.',
            'Do not create OTA price writes from this bundle; AI suggestions remain manual-review gated.',
        ],
        'next_commands_after_filling_template' => [
            'preflight_no_execute' => ctrip_operator_bundle_command('preflight_no_execute', $date, $resolvedHotelId, $outputDir),
            'build_fillable_from_csv' => ctrip_operator_bundle_command('build_fillable_from_csv', $date, $resolvedHotelId, $files['pricing_input_intake_csv'], $fillableFile),
            'csv_to_json_preflight' => ctrip_operator_bundle_command('csv_to_json_preflight', $date, $resolvedHotelId, $outputDir),
            'input_readiness_scan' => 'npm.cmd run report:revenue-ai-ctrip-input-readiness -- --date=' . $date . $demandTrendHotelArg . ' --format=markdown',
            'external_input_candidates' => 'npm.cmd run report:revenue-ai-ctrip-external-input-candidates -- --date=' . $date . $demandTrendHotelArg . ' --format=markdown',
            'pricing_evidence_candidates' => 'npm.cmd run report:revenue-ai-ctrip-pricing-evidence-candidates -- --date=' . $date . $demandTrendHotelArg . ' --format=markdown',
            'demand_trend_draft' => 'npm.cmd run report:revenue-ai-ctrip-traffic-demand-trend -- --date=' . $date . $demandTrendHotelArg . ' --format=markdown',
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
            'input_readiness_json' => ['exit_code' => $inputReadinessJsonRun['exit_code'], 'status' => $inputReadinessPayload['status'] ?? null],
            'input_readiness_markdown' => ['exit_code' => $inputReadinessMarkdownRun['exit_code']],
            'external_input_candidates_json' => ['exit_code' => $externalInputJsonRun['exit_code'], 'status' => $externalInputPayload['status'] ?? null],
            'external_input_candidates_markdown' => ['exit_code' => $externalInputMarkdownRun['exit_code']],
            'external_input_candidates_csv' => ['exit_code' => $externalInputCsvRun['exit_code']],
            'pricing_evidence_candidates_json' => ['exit_code' => $pricingEvidenceJsonRun['exit_code'], 'status' => $pricingEvidencePayload['status'] ?? null],
            'pricing_evidence_candidates_markdown' => ['exit_code' => $pricingEvidenceMarkdownRun['exit_code']],
            'pricing_evidence_candidates_csv' => ['exit_code' => $pricingEvidenceCsvRun['exit_code']],
            'demand_trend_draft_json' => ['exit_code' => $demandTrendJsonRun['exit_code'], 'status' => $demandTrendPayload['status'] ?? null],
            'demand_trend_draft_markdown' => ['exit_code' => $demandTrendMarkdownRun['exit_code']],
        ],
    ];
    $operatorEvidence = ctrip_operator_bundle_map($fillableBody['operator_input_evidence'] ?? []);
    $demandForecastSource = trim((string)($operatorEvidence['demand_forecast_source'] ?? ''));
    $trafficTrendDemandReady = ctrip_operator_bundle_demand_source_is_traffic_trend($demandForecastSource);
    $realInputChecklist = ctrip_operator_bundle_real_input_checklist($fillableBody, $manifest);
    $pricingInputSchema = ctrip_operator_bundle_pricing_input_schema($date, $resolvedHotelId, $trafficTrendDemandReady);
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
        'demand_forecast_source' => $trafficTrendDemandReady ? 'ctrip_historical_traffic_trend' : 'manual_or_missing',
        'demand_forecast_row_required' => !$trafficTrendDemandReady,
    ];
    $manifest['operator_action_sheet_csv'] = [
        'file' => 'operator-action-sheet.csv',
        'status' => 'candidate_to_operator_action_sheet_not_importable',
        'source_scope' => 'ctrip_ota_channel',
        'source_policy' => 'candidate_hints_mapped_to_fill_fields_no_values_no_import',
        'candidate_values_exposed' => true,
        'database_written' => false,
        'auto_write_ota' => false,
        'importable_value' => false,
        'operator_confirmation_required' => true,
        'target_file' => 'pricing-input-intake.csv',
    ];
    $manifest['operator_confirmation_brief'] = [
        'files' => [
            'markdown' => 'OPERATOR_CONFIRMATION_BRIEF.md',
            'csv' => 'operator-confirmation-brief.csv',
        ],
        'status' => 'minimum_human_confirmation_brief_not_importable',
        'source_scope' => 'ctrip_ota_channel',
        'source_policy' => 'operator_confirmation_brief_no_values_no_import',
        'candidate_values_exposed' => true,
        'database_written' => false,
        'auto_write_ota' => false,
        'importable_value' => false,
        'operator_confirmation_required' => true,
        'target_file' => 'pricing-input-intake.csv',
        'required_before_execute' => ctrip_operator_bundle_list($currentBlocker['required_before_execute'] ?? []),
    ];
    $manifest['operator_input_locators_csv'] = [
        'file' => 'operator-input-locators.csv',
        'status' => 'metadata_locator_csv_not_importable',
        'source_scope' => 'ctrip_ota_channel',
        'source_policy' => 'metadata_locator_only_no_values_no_import',
        'capture_contract_status' => 'metadata_only_no_live_capture_no_import',
        'raw_values_exposed' => false,
        'database_written' => false,
        'auto_write_ota' => false,
        'importable_value' => false,
    ];
    $inputReadinessSummary = ctrip_operator_bundle_map($inputReadinessPayload['summary'] ?? []);
    $inputReadinessTarget = ctrip_operator_bundle_map($inputReadinessSummary['target_date_candidate'] ?? []);
    $manifest['input_readiness_scan'] = [
        'files' => [
            'json' => 'input-readiness.json',
            'markdown' => 'input-readiness.md',
        ],
        'status' => (string)($inputReadinessPayload['status'] ?? 'unknown'),
        'source_scope' => 'ctrip_ota_channel',
        'source_policy' => 'read_current_database_counts_and_ctrip_traffic_trend_only_no_raw_rows_no_import',
        'raw_rows_exposed' => false,
        'database_written' => false,
        'auto_write_ota' => false,
        'importable_value' => false,
        'scan_status' => (string)($inputReadinessSummary['scan_status'] ?? 'unknown'),
        'target_date_demand_source' => (string)($inputReadinessTarget['demand_forecast_source'] ?? 'missing'),
        'target_date_missing_inputs' => ctrip_operator_bundle_list($inputReadinessSummary['current_required_real_inputs_before_execute'] ?? []),
        'table_counts' => $inputReadinessSummary['table_counts'] ?? [],
    ];
    $manifest['external_input_candidates'] = [
        'files' => [
            'json' => 'external-input-candidates.json',
            'markdown' => 'external-input-candidates.md',
            'csv' => 'external-input-candidates.csv',
        ],
        'status' => (string)($externalInputPayload['status'] ?? 'unknown'),
        'source_scope' => 'external_project_materials_for_ctrip_operator_review',
        'source_policy' => 'read_allowlisted_external_project_materials_candidates_only_no_db_no_ota_write',
        'candidate_values_exposed' => true,
        'raw_rows_exposed' => false,
        'database_written' => false,
        'auto_write_ota' => false,
        'importable_value' => false,
        'operator_review_required' => true,
        'not_floor_price_guard' => true,
        'not_current_ctrip_price_sample' => true,
        'candidate_count' => (int)(ctrip_operator_bundle_map($externalInputPayload['summary'] ?? [])['candidate_count'] ?? 0),
    ];
    $manifest['pricing_evidence_candidates'] = [
        'files' => [
            'json' => 'pricing-evidence-candidates.json',
            'markdown' => 'pricing-evidence-candidates.md',
            'csv' => 'pricing-evidence-candidates.csv',
        ],
        'status' => (string)($pricingEvidencePayload['status'] ?? 'unknown'),
        'source_scope' => 'ctrip_ota_channel',
        'source_policy' => 'read_existing_online_daily_data_ctrip_candidate_values_only',
        'candidate_values_exposed' => true,
        'raw_rows_exposed' => false,
        'database_written' => false,
        'auto_write_ota' => false,
        'importable_value' => false,
        'operator_review_required' => true,
        'candidate_count' => (int)(ctrip_operator_bundle_map($pricingEvidencePayload['summary'] ?? [])['candidate_count'] ?? 0),
    ];
    $manifest['demand_trend_draft'] = [
        'files' => [
            'json' => 'demand-trend-draft.json',
            'markdown' => 'demand-trend-draft.md',
        ],
        'status' => (string)($demandTrendPayload['status'] ?? 'unknown'),
        'source_scope' => 'ctrip_ota_channel',
        'source_policy' => 'read_existing_online_daily_data_ctrip_traffic_aggregates_only',
        'raw_rows_exposed' => false,
        'aggregate_values_exposed' => true,
        'database_written' => false,
        'auto_write_ota' => false,
        'importable_value' => false,
        'operator_review_required' => true,
        'requires_room_type_key_mapping' => true,
    ];
    $manifest['operator_review_draft'] = [
        'file' => 'OPERATOR_REVIEW_DRAFT.md',
        'status' => 'human_review_draft_not_importable',
        'source_scope' => 'ctrip_ota_channel',
        'database_written' => false,
        'auto_write_ota' => false,
        'importable_value' => false,
        'combines' => [
            'external-input-candidates.json',
            'pricing-evidence-candidates.json',
            'demand-trend-draft.json',
        ],
    ];
    $manifest['operator_intake_form'] = [
        'file' => 'OPERATOR_INTAKE_FORM.md',
        'status' => 'human_fillable_collection_not_importable',
        'source_scope' => 'ctrip_ota_channel',
        'auto_write_ota' => false,
        'demand_forecast_source' => $trafficTrendDemandReady ? 'ctrip_historical_traffic_trend' : 'manual_or_missing',
        'demand_forecast_row_required' => !$trafficTrendDemandReady,
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
        ctrip_operator_bundle_operator_intake_form($fillableBody, $manifest, $trafficTrendDemandReady),
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
        ctrip_operator_bundle_pricing_input_intake_csv($date, $resolvedHotelId, $demandForecastSource),
        $overwritten
    );
    $written['operator_action_sheet_csv'] = ctrip_operator_bundle_write_file(
        $files['operator_action_sheet_csv'],
        ctrip_operator_bundle_operator_action_sheet_csv($pricingEvidencePayload, $demandTrendPayload, $externalInputPayload, $date, $resolvedHotelId),
        $overwritten
    );
    $written['operator_confirmation_brief_markdown'] = ctrip_operator_bundle_write_file(
        $files['operator_confirmation_brief_markdown'],
        ctrip_operator_bundle_operator_confirmation_brief_markdown($pricingEvidencePayload, $demandTrendPayload, $externalInputPayload, $manifest),
        $overwritten
    );
    $written['operator_confirmation_brief_csv'] = ctrip_operator_bundle_write_file(
        $files['operator_confirmation_brief_csv'],
        ctrip_operator_bundle_operator_confirmation_brief_csv($pricingEvidencePayload, $demandTrendPayload, $externalInputPayload, $date, $resolvedHotelId),
        $overwritten
    );
    $written['operator_input_locators_csv'] = ctrip_operator_bundle_write_file(
        $files['operator_input_locators_csv'],
        ctrip_operator_bundle_operator_input_locators_csv($operatorPacket, $date, $resolvedHotelId, $trafficTrendDemandReady),
        $overwritten
    );
    $written['operator_review_draft_markdown'] = ctrip_operator_bundle_write_file(
        $files['operator_review_draft_markdown'],
        ctrip_operator_bundle_operator_review_draft($pricingEvidencePayload, $demandTrendPayload, $externalInputPayload, $manifest),
        $overwritten
    );
    $written['input_readiness_json'] = ctrip_operator_bundle_write_file(
        $files['input_readiness_json'],
        ctrip_operator_bundle_json($inputReadinessPayload),
        $overwritten
    );
    $written['input_readiness_markdown'] = ctrip_operator_bundle_write_file(
        $files['input_readiness_markdown'],
        rtrim($inputReadinessMarkdownRun['stdout']) . PHP_EOL,
        $overwritten
    );
    $written['demand_trend_draft_json'] = ctrip_operator_bundle_write_file(
        $files['demand_trend_draft_json'],
        ctrip_operator_bundle_json($demandTrendPayload),
        $overwritten
    );
    $written['demand_trend_draft_markdown'] = ctrip_operator_bundle_write_file(
        $files['demand_trend_draft_markdown'],
        rtrim($demandTrendMarkdownRun['stdout']) . PHP_EOL,
        $overwritten
    );
    $written['pricing_evidence_candidates_json'] = ctrip_operator_bundle_write_file(
        $files['pricing_evidence_candidates_json'],
        ctrip_operator_bundle_json($pricingEvidencePayload),
        $overwritten
    );
    $written['pricing_evidence_candidates_markdown'] = ctrip_operator_bundle_write_file(
        $files['pricing_evidence_candidates_markdown'],
        rtrim($pricingEvidenceMarkdownRun['stdout']) . PHP_EOL,
        $overwritten
    );
    $written['pricing_evidence_candidates_csv'] = ctrip_operator_bundle_write_file(
        $files['pricing_evidence_candidates_csv'],
        $pricingEvidenceCsvRun['stdout'],
        $overwritten
    );
    $written['external_input_candidates_json'] = ctrip_operator_bundle_write_file(
        $files['external_input_candidates_json'],
        ctrip_operator_bundle_json($externalInputPayload),
        $overwritten
    );
    $written['external_input_candidates_markdown'] = ctrip_operator_bundle_write_file(
        $files['external_input_candidates_markdown'],
        rtrim($externalInputMarkdownRun['stdout']) . PHP_EOL,
        $overwritten
    );
    $written['external_input_candidates_csv'] = ctrip_operator_bundle_write_file(
        $files['external_input_candidates_csv'],
        $externalInputCsvRun['stdout'],
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
