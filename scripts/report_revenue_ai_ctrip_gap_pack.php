<?php
declare(strict_types=1);

use app\service\RevenueAiOverviewService;
use think\App;

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{date:string,hotel_id:int|null,format:string,bundle_dir:string}
 */
function ctrip_gap_pack_parse_args(array $argv): array
{
    $options = [
        'date' => date('Y-m-d'),
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'format' => 'json',
        'bundle-dir' => '',
        'bundle_dir' => '',
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
    if ($options['bundle-dir'] === '' && $options['bundle_dir'] !== '') {
        $options['bundle-dir'] = $options['bundle_dir'];
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
        'bundle_dir' => $options['bundle-dir'],
    ];
}

/**
 * @return array<string, mixed>
 */
function ctrip_gap_pack_map(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @return array<int, mixed>
 */
function ctrip_gap_pack_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @return array<int, string>
 */
function ctrip_gap_pack_positive_strings(mixed $value): array
{
    $items = [];
    foreach (ctrip_gap_pack_list($value) as $item) {
        if (is_array($item)) {
            $code = trim((string)($item['code'] ?? $item['key'] ?? $item['name'] ?? ''));
        } else {
            $code = trim((string)$item);
        }
        if ($code !== '') {
            $items[] = $code;
        }
    }

    return array_values(array_unique($items));
}

function ctrip_gap_pack_default_bundle_dir(string $root, string $date, ?int $hotelId): string
{
    if ($hotelId === null || $hotelId <= 0) {
        return '';
    }

    return $root . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR
        . 'revenue_ai_ctrip_' . $hotelId . '_' . str_replace('-', '', $date) . '_operator_bundle';
}

/**
 * @return array<int, string>
 */
function ctrip_gap_pack_placeholder_paths(mixed $value, string $path = '$'): array
{
    $paths = [];
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

    foreach ($value as $key => $item) {
        $paths = array_merge($paths, ctrip_gap_pack_placeholder_paths($item, $path . '.' . (string)$key));
    }

    return $paths;
}

/**
 * @return array<string, string>
 */
function ctrip_gap_pack_field_guidance(string $path): array
{
    $field = (string)preg_replace('/^.*\./', '', $path);
    $group = str_contains($path, '$.room_types.')
        ? 'room_types'
        : (str_contains($path, '$.demand_forecasts.')
            ? 'demand_forecasts'
            : (str_contains($path, '$.competitor_price_samples.') ? 'competitor_price_samples' : 'unknown'));
    $guide = [
        'key' => 'Operator-confirmed stable room type key used to join room, forecast, and competitor rows.',
        'name' => 'Operator-verified Ctrip room type name.',
        'base_price' => 'Current operator-verified Ctrip sell price for the room type.',
        'min_price' => 'Operator-approved floor/protection price; must be <= base_price.',
        'max_price' => 'Operator-approved upper guard price; must be >= base_price.',
        'room_count' => 'Operator-confirmed sellable room count for the room type.',
        'predicted_occupancy' => 'Target-date demand forecast occupancy percentage, 0-100.',
        'predicted_demand' => 'Target-date demand forecast count or demand index, numeric and positive.',
        'confidence_score' => 'Forecast confidence from operator/model review, 0-1.',
        'competitor_name' => 'Real Ctrip competitor hotel/name used for this price sample.',
        'our_price' => 'Our Ctrip price observed in the recent-7-day competitor comparison window.',
        'competitor_price' => 'Competitor Ctrip price observed in the same recent-7-day window.',
    ];

    return [
        'path' => $path,
        'group' => $group,
        'field' => $field,
        'expected_real_input' => $guide[$field] ?? 'Operator-verified Ctrip channel value.',
        'forbidden_fill' => 'sample, guessed, fallback, verifier-only, Meituan, or whole-hotel value',
    ];
}

/**
 * @return array<string, mixed>
 */
function ctrip_gap_pack_bundle_status(string $bundleDir): array
{
    if ($bundleDir === '') {
        return [
            'status' => 'not_checked',
            'reason' => 'bundle_dir_not_provided_and_hotel_id_missing',
        ];
    }

    $fillable = $bundleDir . DIRECTORY_SEPARATOR . 'pricing-input-fillable.json';
    if (!is_file($fillable)) {
        return [
            'status' => 'missing',
            'bundle_dir' => $bundleDir,
            'fillable_file' => $fillable,
            'placeholder_count' => null,
            'can_generate_pending_review' => false,
        ];
    }

    $decoded = json_decode((string)file_get_contents($fillable), true);
    $payload = is_array($decoded) ? $decoded : [];
    $input = [];
    foreach (['room_types', 'demand_forecasts', 'competitor_price_samples'] as $key) {
        $input[$key] = $payload[$key] ?? [];
    }
    $placeholders = ctrip_gap_pack_placeholder_paths($input);

    return [
        'status' => $placeholders === [] ? 'filled_or_needs_lint' : 'pending_operator_real_values',
        'bundle_dir' => $bundleDir,
        'fillable_file' => $fillable,
        'placeholder_count' => count($placeholders),
        'placeholder_paths' => array_slice($placeholders, 0, 30),
        'placeholder_details' => array_map(
            static fn(string $path): array => ctrip_gap_pack_field_guidance($path),
            array_slice($placeholders, 0, 30)
        ),
        'can_generate_pending_review' => $placeholders === [],
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function ctrip_gap_pack_stages(array $overview, array $bundle, string $date): array
{
    $p0Gate = ctrip_gap_pack_map($overview['p0_downstream_gate'] ?? []);
    $metrics = ctrip_gap_pack_map($overview['metrics'] ?? []);
    $preflight = ctrip_gap_pack_map($overview['pricing_generation_preflight'] ?? []);
    $reviewQueue = ctrip_gap_pack_map($overview['review_queue'] ?? []);
    $aiToOperation = ctrip_gap_pack_map($overview['ai_to_operation_handoff'] ?? []);
    $executionSummary = ctrip_gap_pack_map($overview['execution_summary'] ?? []);
    $operationToInvestment = ctrip_gap_pack_map($overview['operation_to_investment_handoff'] ?? []);
    $investmentPacket = ctrip_gap_pack_map($operationToInvestment['investment_precheck_packet'] ?? []);

    $p0Ready = in_array((string)($p0Gate['status'] ?? ''), ['ready', 'passed'], true);
    $metricStatuses = [];
    foreach ($metrics as $key => $metric) {
        if (is_array($metric)) {
            $metricStatuses[(string)$key] = $metric['status'] ?? null;
        }
    }

    $preflightStatus = (string)($preflight['status'] ?? '');
    $requiredInputs = ctrip_gap_pack_positive_strings($preflight['required_inputs'] ?? []);
    $pendingCount = (int)($reviewQueue['pending_count'] ?? 0);
    $executionTotal = (int)($executionSummary['total_count'] ?? 0);
    $roiReady = (int)($executionSummary['roi_ready_count'] ?? ($operationToInvestment['operation_roi_ready_count'] ?? 0));
    $operationRoiReady = (int)($operationToInvestment['operation_roi_ready'] ?? 0) === 1 || $roiReady > 0;

    return [
        [
            'stage' => 'ctrip_p0_target_day',
            'status' => $p0Ready ? 'ready' : 'not_reverified_by_gap_pack',
            'proved_by' => 'RevenueAiOverviewService.p0_downstream_gate',
            'current_evidence' => [
                'p0_status' => $p0Gate['status'] ?? null,
                'required_gate_command' => $p0Gate['required_gate_command'] ?? null,
                'report_policy' => 'does_not_run_p0_verifier_or_read_raw_capture',
                'metric_statuses' => $metricStatuses,
            ],
            'missing_real_inputs' => $p0Ready ? [] : ['latest P0 authority verifier output not re-run inside this gap pack'],
        ],
        [
            'stage' => 'pricing_generation_inputs',
            'status' => in_array($preflightStatus, ['ready', 'pending_review_exists'], true) ? 'ready' : 'blocked',
            'proved_by' => 'pricing_generation_preflight + pricing-input-fillable.json',
            'current_evidence' => [
                'preflight_status' => $preflightStatus,
                'preflight_reason' => $preflight['reason'] ?? null,
                'room_type_count' => $preflight['room_type_count'] ?? null,
                'demand_forecast_count' => $preflight['demand_forecast_count'] ?? null,
                'competitor_analysis_recent_count' => $preflight['competitor_analysis_recent_count'] ?? null,
                'create_candidate_count' => $preflight['create_candidate_count'] ?? null,
                'bundle_status' => $bundle['status'] ?? null,
                'placeholder_count' => $bundle['placeholder_count'] ?? null,
            ],
            'missing_real_inputs' => $requiredInputs !== [] ? $requiredInputs : [
                'room_types_enabled',
                'floor_price_or_min_rate_guard',
                'demand_forecast',
                'competitor_price_samples_recent_7d',
            ],
        ],
        [
            'stage' => 'ai_pending_review_suggestion',
            'status' => $pendingCount > 0 ? 'pending_review' : 'blocked_until_pricing_inputs_execute',
            'proved_by' => 'price_suggestions review_queue',
            'current_evidence' => [
                'review_queue_status' => $reviewQueue['status'] ?? null,
                'pending_count' => $pendingCount,
                'source_table' => $reviewQueue['source_table'] ?? null,
            ],
            'missing_real_inputs' => $pendingCount > 0 ? [] : ['operator-filled pricing input file that passes preflight and explicit execute/generate'],
        ],
        [
            'stage' => 'human_review_to_operation_intent',
            'status' => $executionTotal > 0 ? 'intent_exists_or_downstream' : 'waiting_manual_review',
            'proved_by' => 'manual review queue + operation execution intents',
            'current_evidence' => [
                'ai_to_operation_status' => $aiToOperation['status'] ?? null,
                'execution_summary_status' => $executionSummary['status'] ?? null,
                'execution_total_count' => $executionTotal,
            ],
            'missing_real_inputs' => $executionTotal > 0 ? [] : ['manual approval/rejection decision', 'approved target price', 'room/rate mapping for execution intent'],
        ],
        [
            'stage' => 'execution_evidence_and_roi_window',
            'status' => $operationRoiReady ? 'roi_ready' : 'waiting_execution_evidence',
            'proved_by' => 'operation_execution_evidence + operation_execution.roi_ready',
            'current_evidence' => [
                'execution_summary_status' => $executionSummary['status'] ?? null,
                'roi_ready_count' => $roiReady,
                'operation_roi_ready' => $operationToInvestment['operation_roi_ready'] ?? null,
                'roi_window' => [
                    'previous_day' => date('Y-m-d', strtotime($date . ' -1 day')),
                    'business_date' => $date,
                    'next_day' => date('Y-m-d', strtotime($date . ' +1 day')),
                    'scope' => 'ctrip_ota_channel_only',
                ],
            ],
            'missing_real_inputs' => $operationRoiReady ? [] : [
                'manual execution proof',
                'previous-day Ctrip revenue/room_nights/orders/conversion/traffic',
                'next-day Ctrip revenue/room_nights/orders/conversion/traffic',
                'operator ROI review summary',
            ],
        ],
        [
            'stage' => 'investment_manual_review',
            'status' => $operationRoiReady ? 'manual_review_only' : 'blocked_until_roi_ready',
            'proved_by' => 'operation_to_investment_handoff',
            'current_evidence' => [
                'handoff_status' => $operationToInvestment['status'] ?? null,
                'decision_allowed' => $operationToInvestment['decision_allowed'] ?? null,
                'investment_precheck_status' => $investmentPacket['status'] ?? null,
                'protected_boundary' => $investmentPacket['protected_boundary'] ?? 'investment_decision_requires_closed_operation_roi_not_ota_channel_only',
            ],
            'missing_real_inputs' => $operationRoiReady ? ['manual investment decision record readiness'] : ['operation_execution.roi_ready'],
        ],
    ];
}

/**
 * @return array<string, string>
 */
function ctrip_gap_pack_commands(string $date, ?int $hotelId, string $bundleDir): array
{
    $hotelArg = $hotelId === null ? '' : ' --hotel-id=' . $hotelId;
    $bundleArg = $bundleDir !== '' ? $bundleDir : '<operator-bundle-dir>';

    return [
        'p0_authority_check' => 'npm.cmd run verify:p0-ota-field-loop -- --date=' . $date . ' --platform=ctrip' . ($hotelId === null ? ' --system-hotel-id=<hotel-id>' : ' --system-hotel-id=' . $hotelId),
        'operator_bundle_preflight' => 'npm.cmd run verify:revenue-ai-ctrip-operator-bundle-preflight -- --dir=' . $bundleArg . ' --date=' . $date . $hotelArg,
        'execute_inputs_and_generate_pending_review' => 'npm.cmd run run:revenue-ai-ctrip-pricing-file-to-pending-review -- --file=' . $bundleArg . '\\pricing-input-fillable.json --date=' . $date . $hotelArg . ' --execute=1 --generate=1',
        'pending_review_packet' => 'npm.cmd run report:revenue-ai-ctrip-pending-review-packet -- --date=' . $date . $hotelArg . ' --format=markdown',
        'review_decision_template' => 'npm.cmd run export:revenue-ai-ctrip-review-template -- --date=' . $date . $hotelArg . ' --suggestion-id=<pending-suggestion-id> --output=<review-decision-json-path>',
        'execute_review_and_create_intent' => 'npm.cmd run run:revenue-ai-ctrip-review-decision -- --file=<review-decision-json-path> --date=' . $date . $hotelArg . ' --execute=1 --create-intent=1',
        'verify_roi_boundary' => 'npm.cmd run verify:revenue-ai-ctrip-operation-roi -- --date=' . $date . $hotelArg,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_gap_pack_markdown(array $payload): string
{
    $scope = ctrip_gap_pack_map($payload['scope'] ?? []);
    $lines = [];
    $lines[] = '# Revenue AI Ctrip Real Input Gap Pack';
    $lines[] = '';
    $lines[] = '- status: `' . (string)($payload['status'] ?? 'unknown') . '`';
    $lines[] = '- business_date: `' . (string)($scope['business_date'] ?? '') . '`';
    $lines[] = '- hotel_id: `' . (string)($scope['hotel_id'] ?? 'unknown') . '`';
    $lines[] = '- source_scope: `ctrip_ota_channel`';
    $lines[] = '- raw_capture_read: `false`';
    $lines[] = '- database_written: `false`';
    $lines[] = '- auto_write_ota: `false`';
    $lines[] = '';
    $lines[] = '## Stage Gaps';
    $lines[] = '';
    $lines[] = '| Stage | Status | Missing real inputs |';
    $lines[] = '|---|---|---|';
    foreach (ctrip_gap_pack_list($payload['stages'] ?? []) as $stage) {
        $row = ctrip_gap_pack_map($stage);
        $missing = ctrip_gap_pack_list($row['missing_real_inputs'] ?? []);
        $lines[] = '| `' . (string)($row['stage'] ?? '') . '` | `' . (string)($row['status'] ?? '') . '` | ' . ($missing === [] ? '`none`' : '`' . implode('`, `', array_map('strval', $missing)) . '`') . ' |';
    }
    $lines[] = '';
    $lines[] = '## Operator Bundle';
    $bundle = ctrip_gap_pack_map($payload['operator_bundle'] ?? []);
    $lines[] = '';
    $lines[] = '- status: `' . (string)($bundle['status'] ?? 'unknown') . '`';
    $lines[] = '- fillable_file: `' . (string)($bundle['fillable_file'] ?? '') . '`';
    $lines[] = '- placeholder_count: `' . (string)($bundle['placeholder_count'] ?? 'unknown') . '`';
    $lines[] = '';
    $placeholderDetails = ctrip_gap_pack_list($bundle['placeholder_details'] ?? []);
    if ($placeholderDetails !== []) {
        $lines[] = '## Fillable Placeholder Checklist';
        $lines[] = '';
        $lines[] = '| Path | Expected real input | Forbidden fill |';
        $lines[] = '|---|---|---|';
        foreach ($placeholderDetails as $detail) {
            $row = ctrip_gap_pack_map($detail);
            $lines[] = '| `' . (string)($row['path'] ?? '') . '` | ' . (string)($row['expected_real_input'] ?? '') . ' | `' . (string)($row['forbidden_fill'] ?? '') . '` |';
        }
        $lines[] = '';
    }
    $lines[] = '## Next Commands';
    foreach (ctrip_gap_pack_map($payload['commands'] ?? []) as $key => $command) {
        $lines[] = '';
        $lines[] = '1. `' . (string)$key . '`';
        $lines[] = '   ```powershell';
        $lines[] = '   ' . (string)$command;
        $lines[] = '   ```';
    }
    $lines[] = '';
    $lines[] = '## Boundaries';
    foreach (ctrip_gap_pack_list($payload['boundaries'] ?? []) as $boundary) {
        $lines[] = '- ' . (string)$boundary;
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_gap_pack_finish(array $payload, int $exitCode, string $format): void
{
    if ($format === 'markdown') {
        echo ctrip_gap_pack_markdown($payload);
    } else {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    exit($exitCode);
}

try {
    $root = dirname(__DIR__);
    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        ctrip_gap_pack_finish([
            'status' => 'failed',
            'error' => 'vendor/autoload.php is missing.',
        ], 1, 'json');
    }

    require $autoload;

    $options = ctrip_gap_pack_parse_args($argv);
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
    $overview = (new RevenueAiOverviewService())->overview($filters);
    $preflight = ctrip_gap_pack_map($overview['pricing_generation_preflight'] ?? []);
    $targetHotelIds = ctrip_gap_pack_list($preflight['target_hotel_ids'] ?? []);
    $resolvedHotelId = $options['hotel_id'] ?? ((int)($targetHotelIds[0] ?? 0) ?: null);
    $bundleDir = $options['bundle_dir'] !== ''
        ? $options['bundle_dir']
        : ctrip_gap_pack_default_bundle_dir($root, $options['date'], $resolvedHotelId);
    if ($bundleDir !== '' && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $bundleDir) && !str_starts_with($bundleDir, DIRECTORY_SEPARATOR)) {
        $bundleDir = $root . DIRECTORY_SEPARATOR . $bundleDir;
    }
    $bundle = ctrip_gap_pack_bundle_status($bundleDir);
    $stages = ctrip_gap_pack_stages($overview, $bundle, $options['date']);
    $blocked = array_values(array_filter(
        $stages,
        static fn(array $stage): bool => !in_array((string)($stage['status'] ?? ''), ['ready', 'pending_review', 'intent_exists_or_downstream', 'roi_ready', 'manual_review_only'], true)
    ));

    $payload = [
        'status' => $blocked === [] ? 'ready_or_waiting_manual_steps' : 'blocked_by_real_inputs',
        'scope' => [
            'business_date' => $options['date'],
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $resolvedHotelId,
            'source_scope' => 'ctrip_ota_channel',
            'source_policy' => 'read_current_revenue_ai_overview_and_operator_bundle_only',
            'raw_capture_read' => false,
            'database_written' => false,
            'auto_write_ota' => false,
            'meituan_scope_included' => false,
        ],
        'operator_bundle' => $bundle,
        'stages' => $stages,
        'commands' => ctrip_gap_pack_commands($options['date'], $resolvedHotelId, $bundleDir),
        'boundaries' => [
            'Do not use Meituan rows or whole-hotel values for this Ctrip gap pack.',
            'Do not fill missing room, price, demand, competitor, execution, or ROI values with samples, guesses, fallbacks, or verifier-only fixtures.',
            'Do not write OTA prices from AI suggestions; every generated suggestion remains pending manual review.',
            'Investment decision support requires operation_execution.roi_ready plus manual decision-record readiness.',
            'Ctrip OTA channel evidence must not be promoted to whole-hotel operating truth.',
        ],
    ];

    ctrip_gap_pack_finish($payload, 0, $options['format']);
} catch (Throwable $error) {
    $format = 'json';
    try {
        $parsed = ctrip_gap_pack_parse_args($argv);
        $format = $parsed['format'];
    } catch (Throwable) {
    }
    ctrip_gap_pack_finish([
        'status' => 'failed',
        'error' => [
            'type' => get_class($error),
            'message' => $error->getMessage(),
        ],
    ], 1, $format);
}
