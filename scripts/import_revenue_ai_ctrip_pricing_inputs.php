<?php
declare(strict_types=1);

use app\controller\Agent;
use app\model\CompetitorAnalysis;
use app\model\DemandForecast;
use app\model\RoomType;
use app\service\RevenueAiOverviewService;
use think\App;
use think\facade\Db;
use think\Response;

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function ctrip_pricing_import_parse_args(array $argv): array
{
    $options = [
        'file' => '',
        'date' => '',
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'output' => '',
        'force' => false,
        'execute' => false,
        'generate' => false,
        'lint-only' => false,
        'validate-only' => false,
        'print-template' => false,
        'print-current-template' => false,
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
        if ($arg === '--force') {
            $options['force'] = true;
            continue;
        }
        if ($arg === '--lint-only') {
            $options['lint-only'] = true;
            continue;
        }
        if ($arg === '--validate-only') {
            $options['validate-only'] = true;
            continue;
        }
        if ($arg === '--print-template') {
            $options['print-template'] = true;
            continue;
        }
        if ($arg === '--print-current-template') {
            $options['print-current-template'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (!array_key_exists($key, $options)) {
            continue;
        }
        if (in_array($key, ['force', 'execute', 'generate', 'lint-only', 'validate-only', 'print-template', 'print-current-template'], true)) {
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

    $hotelId = null;
    if ((string)$options['hotel-id'] !== '') {
        if (!ctype_digit((string)$options['hotel-id']) || (int)$options['hotel-id'] <= 0) {
            throw new InvalidArgumentException('Invalid --hotel-id, expected a positive integer.');
        }
        $hotelId = (int)$options['hotel-id'];
    }
    if ((string)$options['date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$options['date'])) {
        throw new InvalidArgumentException('Invalid --date, expected YYYY-MM-DD.');
    }

    return [
        'file' => (string)$options['file'],
        'date' => (string)$options['date'],
        'hotel_id' => $hotelId,
        'output' => (string)$options['output'],
        'force' => (bool)$options['force'],
        'execute' => (bool)$options['execute'],
        'generate' => (bool)$options['generate'],
        'lint_only' => (bool)$options['lint-only'],
        'validate_only' => (bool)$options['validate-only'],
        'print_template' => (bool)$options['print-template'],
        'print_current_template' => (bool)$options['print-current-template'],
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_pricing_import_finish(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function ctrip_pricing_import_write_json_output(string $output, array $payload, bool $force): array
{
    if ($output === '') {
        return [];
    }

    $parent = dirname($output);
    $parentPath = realpath($parent === '' ? '.' : $parent);
    if (!is_string($parentPath) || !is_dir($parentPath)) {
        throw new InvalidArgumentException('Output parent directory does not exist: ' . $parent);
    }
    $fileName = basename($output);
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        throw new InvalidArgumentException('Output path must be a JSON file path.');
    }

    $target = $parentPath . DIRECTORY_SEPARATOR . $fileName;
    if (is_dir($target)) {
        throw new InvalidArgumentException('Output path points to a directory: ' . $target);
    }
    $existedBeforeWrite = is_file($target);
    if ($existedBeforeWrite && !$force) {
        throw new InvalidArgumentException('Output file already exists. Pass --force=1 to overwrite: ' . $target);
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode output JSON.');
    }
    $json .= PHP_EOL;
    if (file_put_contents($target, $json, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write output file: ' . $target);
    }

    return [
        'path' => $target,
        'bytes' => strlen($json),
        'sha256' => hash('sha256', $json),
        'overwritten' => $existedBeforeWrite && $force,
    ];
}

/**
 * @return array<string, mixed>
 */
function ctrip_pricing_import_template(): array
{
    return [
        'business_date' => date('Y-m-d'),
        'hotel_id' => 64,
        'input_scope' => 'manual_pricing_configuration',
        'source_scope' => 'ctrip_ota_channel',
        'evidence_status' => 'operator_provided',
        'room_types' => [
            [
                'key' => 'room_type_key_from_operator',
                'name' => 'Verified Ctrip room type name',
                'base_price' => 300,
                'min_price' => 240,
                'max_price' => 430,
                'room_count' => 10,
                'sort_order' => 1,
                'is_enabled' => 1,
            ],
        ],
        'demand_forecasts' => [
            [
                'room_type_key' => 'room_type_key_from_operator',
                'predicted_occupancy' => 92,
                'predicted_demand' => 9,
                'confidence_score' => 0.86,
                'forecast_method' => 3,
                'remark' => 'operator-provided demand forecast for Ctrip pricing generation',
            ],
        ],
        'competitor_price_samples' => [
            [
                'room_type_key' => 'room_type_key_from_operator',
                'competitor_name' => 'Verified Ctrip competitor hotel name',
                'our_price' => 300,
                'competitor_price' => 360,
                'ota_platform' => 'ctrip',
            ],
        ],
    ];
}

/**
 * @return array<int, string>
 */
function ctrip_pricing_import_required_input_codes(array $preflight): array
{
    $codes = [];
    foreach (ctrip_pricing_import_list($preflight['required_inputs'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = trim((string)($row['code'] ?? ''));
        if ($code !== '') {
            $codes[] = $code;
        }
    }
    return array_values(array_unique($codes));
}

/**
 * @return array<int, array<string, mixed>>
 */
function ctrip_pricing_import_hotel_checks_for_template(array $preflight, int $hotelId): array
{
    $checks = [];
    foreach (ctrip_pricing_import_list($preflight['hotel_checks'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowHotelId = (int)($row['hotel_id'] ?? 0);
        if ($rowHotelId > 0 && $rowHotelId !== $hotelId) {
            continue;
        }
        $checks[] = [
            'hotel_id' => $rowHotelId,
            'target_date_rows' => (int)($row['target_date_rows'] ?? 0),
            'room_type_count' => (int)($row['room_type_count'] ?? 0),
            'pending_suggestions' => (int)($row['pending_suggestions'] ?? 0),
            'demand_forecasts' => (int)($row['demand_forecasts'] ?? 0),
            'competitor_analysis_recent' => (int)($row['competitor_analysis_recent'] ?? 0),
            'create_candidate_count' => (int)($row['create_candidate_count'] ?? 0),
            'skipped_candidate_count' => (int)($row['skipped_candidate_count'] ?? 0),
            'skip_reasons' => ctrip_pricing_import_list($row['skip_reasons'] ?? []),
        ];
    }
    return $checks;
}

/**
 * @return array<string, mixed>
 */
function ctrip_pricing_import_current_template(string $businessDate, int $hotelId, array $overview): array
{
    $preflight = ctrip_pricing_import_map($overview['pricing_generation_preflight'] ?? []);
    $requiredInputCodes = ctrip_pricing_import_required_input_codes($preflight);
    $hotelArg = ' --hotel-id=' . $hotelId;

    return [
        'business_date' => $businessDate,
        'hotel_id' => $hotelId,
        'platform' => 'ctrip',
        'input_scope' => 'manual_pricing_configuration',
        'source_scope' => 'ctrip_ota_channel',
        'evidence_status' => 'operator_provided',
        'target_workflow' => 'ctrip_revenue_ai_pricing_generation',
        'auto_write_ota' => false,
        'current_preflight' => [
            'status' => $preflight['status'] ?? null,
            'reason' => $preflight['reason'] ?? null,
            'target_hotel_ids' => $preflight['target_hotel_ids'] ?? [],
            'target_date_rows' => $preflight['target_date_rows'] ?? null,
            'room_type_count' => $preflight['room_type_count'] ?? null,
            'pending_suggestion_count' => $preflight['pending_suggestion_count'] ?? null,
            'demand_forecast_count' => $preflight['demand_forecast_count'] ?? null,
            'competitor_analysis_recent_count' => $preflight['competitor_analysis_recent_count'] ?? null,
            'create_candidate_count' => $preflight['create_candidate_count'] ?? null,
            'required_input_codes' => $requiredInputCodes,
            'hotel_checks' => ctrip_pricing_import_hotel_checks_for_template($preflight, $hotelId),
        ],
        'operator_fill_required' => [
            'Replace every <...> placeholder with operator-verified Ctrip OTA channel values before importing.',
            'Do not paste sample, guessed, fallback, or whole-hotel values into this Ctrip OTA channel input file.',
            'Run the dry-run import first; only use the execute script after the dry-run reports pricing_generation_preflight.create_candidate_count > 0.',
        ],
        'room_types' => [
            [
                'key' => 'room_type_1',
                'name' => '<operator_verified_ctrip_room_type_name>',
                'base_price' => '<base_price_number>',
                'min_price' => '<minimum_protection_price_number>',
                'max_price' => '<maximum_price_number>',
                'room_count' => '<available_room_count_number>',
                'sort_order' => 1,
                'is_enabled' => 1,
            ],
        ],
        'demand_forecasts' => [
            [
                'room_type_key' => 'room_type_1',
                'forecast_date' => $businessDate,
                'predicted_occupancy' => '<target_date_predicted_occupancy_percent>',
                'predicted_demand' => '<target_date_predicted_room_nights>',
                'confidence_score' => '<forecast_confidence_0_to_1>',
                'forecast_method' => 3,
                'remark' => 'operator-provided demand forecast for Ctrip pricing generation',
            ],
        ],
        'competitor_price_samples' => [
            [
                'room_type_key' => 'room_type_1',
                'analysis_date' => $businessDate,
                'competitor_name' => '<operator_verified_ctrip_competitor_hotel_name>',
                'our_price' => '<our_ctrip_price_number>',
                'competitor_price' => '<competitor_ctrip_price_number>',
                'ota_platform' => 'ctrip',
            ],
        ],
        'verification_commands' => [
            'operator_packet' => 'npm.cmd run report:revenue-ai-ctrip-pricing-operator-packet -- --date=' . $businessDate . $hotelArg . ' --format=markdown',
            'export_operator_bundle' => 'npm.cmd run export:revenue-ai-ctrip-operator-bundle -- --date=' . $businessDate . $hotelArg . ' --output-dir=<operator-bundle-dir>',
            'inspect_current_ota_evidence' => 'npm.cmd run inspect:revenue-ai-ctrip-pricing-sources -- --date=' . $businessDate . $hotelArg,
            'export_to_file' => 'npm.cmd run export:revenue-ai-ctrip-pricing-template -- --date=' . $businessDate . $hotelArg . ' --output=<draft-json-path>',
            'lint_only' => 'npm.cmd run lint:revenue-ai-ctrip-pricing-inputs -- --file=<filled-json-path> --date=' . $businessDate . $hotelArg,
            'dry_run' => 'npm.cmd run import:revenue-ai-ctrip-pricing-inputs -- --file=<filled-json-path> --date=' . $businessDate . $hotelArg,
            'validate_only' => 'npm.cmd run validate:revenue-ai-ctrip-pricing-inputs -- --file=<filled-json-path> --date=' . $businessDate . $hotelArg,
            'pre_execute_gate' => 'npm.cmd run verify:revenue-ai-ctrip-pricing-file -- --file=<filled-json-path> --date=' . $businessDate . $hotelArg,
            'gate_then_execute_and_generate_pending_review' => 'npm.cmd run run:revenue-ai-ctrip-pricing-file-to-pending-review -- --file=<filled-json-path> --date=' . $businessDate . $hotelArg . ' --execute=1 --generate=1',
            'execute_inputs_only' => 'npm.cmd run import:revenue-ai-ctrip-pricing-inputs:execute -- --file=<filled-json-path> --date=' . $businessDate . $hotelArg,
            'execute_and_generate_pending_review' => 'npm.cmd run import:revenue-ai-ctrip-pricing-inputs:execute -- --file=<filled-json-path> --date=' . $businessDate . $hotelArg . ' --generate=1',
            'pending_review_packet' => 'npm.cmd run report:revenue-ai-ctrip-pending-review-packet -- --date=' . $businessDate . $hotelArg . ' --format=markdown',
            'verify_pending_review_packet' => 'npm.cmd run verify:revenue-ai-ctrip-pending-review-packet -- --date=' . $businessDate . $hotelArg,
            'export_review_decision_template' => 'npm.cmd run export:revenue-ai-ctrip-review-template -- --date=' . $businessDate . $hotelArg . ' --suggestion-id=<pending-suggestion-id> --output=<review-decision-json-path>',
            'validate_review_decision' => 'npm.cmd run run:revenue-ai-ctrip-review-decision -- --file=<review-decision-json-path> --date=' . $businessDate . $hotelArg,
            'execute_review_decision' => 'npm.cmd run run:revenue-ai-ctrip-review-decision -- --file=<review-decision-json-path> --date=' . $businessDate . $hotelArg . ' --execute=1',
            'execute_review_decision_and_create_operation_intent' => 'npm.cmd run run:revenue-ai-ctrip-review-decision -- --file=<review-decision-json-path> --date=' . $businessDate . $hotelArg . ' --execute=1 --create-intent=1',
            'verify_review_decision' => 'npm.cmd run verify:revenue-ai-ctrip-review-decision -- --date=' . $businessDate . $hotelArg,
            'verify_current_scope' => 'npm.cmd run verify:revenue-ai-ctrip-scope -- --date=' . $businessDate . $hotelArg,
        ],
    ];
}

/**
 * @return object
 */
function ctrip_pricing_import_fake_super_admin(int $hotelId): object
{
    return new class($hotelId) {
        public int $id = 0;
        private int $hotelId;

        public function __construct(int $hotelId)
        {
            $this->hotelId = $hotelId;
        }

        public function isSuperAdmin(): bool
        {
            return true;
        }

        /**
         * @return array<int, int>
         */
        public function getPermittedHotelIds(): array
        {
            return [$this->hotelId];
        }
    };
}

/**
 * @param mixed $value
 * @return array<string, mixed>
 */
function ctrip_pricing_import_map(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @param mixed $value
 * @return array<int, mixed>
 */
function ctrip_pricing_import_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @param array<int, mixed> $values
 * @return array<int, int>
 */
function ctrip_pricing_import_positive_ints(array $values): array
{
    return array_values(array_filter(
        array_map('intval', $values),
        static fn(int $value): bool => $value > 0
    ));
}

function ctrip_pricing_import_resolve_hotel_id(?int $requestedHotelId, string $businessDate): int
{
    if ($requestedHotelId !== null && $requestedHotelId > 0) {
        return $requestedHotelId;
    }

    $overview = (new RevenueAiOverviewService())->overview([
        'business_date' => $businessDate,
        'platform' => 'ctrip',
        'enabled_channels' => ['ctrip'],
    ]);
    $preflight = ctrip_pricing_import_map($overview['pricing_generation_preflight'] ?? []);
    $targetHotelIds = ctrip_pricing_import_positive_ints(ctrip_pricing_import_list($preflight['target_hotel_ids'] ?? []));
    if ($targetHotelIds !== []) {
        return $targetHotelIds[0];
    }

    throw new RuntimeException('No Ctrip target hotel was found for the requested date. Pass --hotel-id explicitly.');
}

/**
 * @return array<string, mixed>
 */
function ctrip_pricing_import_decode_response(Response $response): array
{
    $decoded = json_decode((string)$response->getContent(), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Agent response is not JSON.');
    }
    return $decoded;
}

/**
 * @return array<string, mixed>
 */
function ctrip_pricing_import_expect_success(Response $response, string $operation): array
{
    $payload = ctrip_pricing_import_decode_response($response);
    if ((int)($payload['code'] ?? 0) !== 200) {
        throw new RuntimeException($operation . ' failed: ' . (string)($payload['message'] ?? 'unknown_error'));
    }
    return ctrip_pricing_import_map($payload['data'] ?? []);
}

/**
 * @return array<string, mixed>
 */
function ctrip_pricing_import_load_payload(string $file): array
{
    if ($file === '') {
        throw new InvalidArgumentException('Missing --file. Use --print-template to inspect the expected JSON shape.');
    }
    if (!is_file($file)) {
        throw new InvalidArgumentException('Input file does not exist: ' . $file);
    }
    $raw = file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        throw new InvalidArgumentException('Input file is empty: ' . $file);
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Input file must be a JSON object.');
    }
    return $decoded;
}

function ctrip_pricing_import_string(array $row, string $key): string
{
    return trim((string)($row[$key] ?? ''));
}

function ctrip_pricing_import_room_key(array $row): string
{
    $key = ctrip_pricing_import_string($row, 'room_type_key');
    if ($key === '') {
        $key = ctrip_pricing_import_string($row, 'key');
    }
    if ($key === '') {
        $key = ctrip_pricing_import_string($row, 'name');
    }
    if ($key === '') {
        $key = ctrip_pricing_import_string($row, 'room_type_name');
    }
    return $key;
}

/**
 * @param mixed $rows
 * @return array<int, array<string, mixed>>
 */
function ctrip_pricing_import_rows(mixed $rows, string $key): array
{
    if (!is_array($rows) || array_values($rows) !== $rows || $rows === []) {
        throw new InvalidArgumentException($key . ' must be a non-empty array.');
    }
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException($key . '[' . $index . '] must be an object.');
        }
    }
    return $rows;
}

function ctrip_pricing_import_assert_no_placeholder(mixed $value, string $path = '$'): void
{
    if ($path === '$' && is_array($value)) {
        foreach (['room_types', 'demand_forecasts', 'competitor_price_samples'] as $inputKey) {
            if (array_key_exists($inputKey, $value)) {
                ctrip_pricing_import_assert_no_placeholder($value[$inputKey], '$.' . $inputKey);
            }
        }
        return;
    }

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            ctrip_pricing_import_assert_no_placeholder($item, $path . '.' . (string)$key);
        }
        return;
    }
    if (!is_string($value)) {
        return;
    }
    $text = trim($value);
    if ($text !== '' && str_contains($text, '<') && str_contains($text, '>')) {
        throw new InvalidArgumentException('Placeholder value is not allowed at ' . $path);
    }
}

/**
 * @return array<int, string>
 */
function ctrip_pricing_import_placeholder_paths(mixed $value, string $path = '$'): array
{
    if ($path === '$' && is_array($value)) {
        $paths = [];
        foreach (['room_types', 'demand_forecasts', 'competitor_price_samples'] as $inputKey) {
            if (array_key_exists($inputKey, $value)) {
                array_push($paths, ...ctrip_pricing_import_placeholder_paths($value[$inputKey], '$.' . $inputKey));
            }
        }
        return $paths;
    }

    if (is_array($value)) {
        $paths = [];
        foreach ($value as $key => $item) {
            array_push($paths, ...ctrip_pricing_import_placeholder_paths($item, $path . '.' . (string)$key));
        }
        return $paths;
    }
    if (!is_string($value)) {
        return [];
    }
    $text = trim($value);
    return $text !== '' && str_contains($text, '<') && str_contains($text, '>') ? [$path] : [];
}

/**
 * @param array<int, array<string, mixed>> $issues
 * @param array<string, mixed> $details
 */
function ctrip_pricing_import_lint_issue(array &$issues, string $code, string $path, string $message, array $details = []): void
{
    $issue = [
        'code' => $code,
        'path' => $path,
        'message' => $message,
    ];
    if ($details !== []) {
        $issue['details'] = $details;
    }
    $issues[] = $issue;
}

function ctrip_pricing_import_lint_number(array &$issues, array $row, string $path, string $key, float $min, ?float $max = null): void
{
    if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
        ctrip_pricing_import_lint_issue($issues, 'numeric_field_missing', $path . '.' . $key, $key . ' must be numeric.');
        return;
    }
    $value = (float)$row[$key];
    if ($value < $min || ($max !== null && $value > $max)) {
        ctrip_pricing_import_lint_issue($issues, 'numeric_field_out_of_range', $path . '.' . $key, $key . ' is outside the allowed range.', [
            'value' => $value,
            'min' => $min,
            'max' => $max,
        ]);
    }
}

function ctrip_pricing_import_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date instanceof DateTimeImmutable) {
        return null;
    }
    return $date->format('Y-m-d') === $value ? $date : null;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function ctrip_pricing_import_lint_payload(array $payload, string $optionDate): array
{
    $issues = [];
    $placeholderPaths = ctrip_pricing_import_placeholder_paths($payload);
    foreach ($placeholderPaths as $path) {
        ctrip_pricing_import_lint_issue($issues, 'placeholder_value', $path, 'Replace placeholder values before importing.');
    }

    $businessDate = trim((string)($payload['business_date'] ?? $payload['date'] ?? ''));
    if ($optionDate !== '' && $businessDate !== '' && $businessDate !== $optionDate) {
        ctrip_pricing_import_lint_issue($issues, 'business_date_mismatch', '$.business_date', 'business_date does not match --date.', [
            'business_date' => $businessDate,
            'date_option' => $optionDate,
        ]);
    }
    if ($businessDate === '') {
        $businessDate = $optionDate;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
        ctrip_pricing_import_lint_issue($issues, 'business_date_invalid', '$.business_date', 'business_date must be YYYY-MM-DD.');
    }
    if ((string)($payload['source_scope'] ?? '') !== 'ctrip_ota_channel') {
        ctrip_pricing_import_lint_issue($issues, 'source_scope_invalid', '$.source_scope', 'source_scope must be ctrip_ota_channel.');
    }
    if (strtolower((string)($payload['platform'] ?? 'ctrip')) !== 'ctrip') {
        ctrip_pricing_import_lint_issue($issues, 'platform_invalid', '$.platform', 'platform must be ctrip.');
    }
    if (($payload['auto_write_ota'] ?? false) === true) {
        ctrip_pricing_import_lint_issue($issues, 'auto_write_ota_forbidden', '$.auto_write_ota', 'auto_write_ota must not be true for this importer.');
    }

    $roomRows = is_array($payload['room_types'] ?? null) && array_values($payload['room_types']) === $payload['room_types']
        ? $payload['room_types']
        : [];
    $forecastRows = is_array($payload['demand_forecasts'] ?? null) && array_values($payload['demand_forecasts']) === $payload['demand_forecasts']
        ? $payload['demand_forecasts']
        : [];
    $competitorRows = is_array($payload['competitor_price_samples'] ?? null) && array_values($payload['competitor_price_samples']) === $payload['competitor_price_samples']
        ? $payload['competitor_price_samples']
        : [];

    if ($roomRows === []) {
        ctrip_pricing_import_lint_issue($issues, 'room_types_missing', '$.room_types', 'room_types must be a non-empty array.');
    }
    if ($forecastRows === []) {
        ctrip_pricing_import_lint_issue($issues, 'demand_forecasts_missing', '$.demand_forecasts', 'demand_forecasts must be a non-empty array.');
    }
    if ($competitorRows === []) {
        ctrip_pricing_import_lint_issue($issues, 'competitor_price_samples_missing', '$.competitor_price_samples', 'competitor_price_samples must be a non-empty array.');
    }

    $roomKeys = [];
    foreach ($roomRows as $index => $row) {
        $path = '$.room_types.' . $index;
        if (!is_array($row)) {
            ctrip_pricing_import_lint_issue($issues, 'row_not_object', $path, 'room_types row must be an object.');
            continue;
        }
        $key = ctrip_pricing_import_room_key($row);
        if ($key === '') {
            ctrip_pricing_import_lint_issue($issues, 'room_type_key_missing', $path, 'room type key or name is required.');
        } else {
            $roomKeys[$key] = true;
        }
        if (ctrip_pricing_import_string($row, 'name') === '') {
            ctrip_pricing_import_lint_issue($issues, 'room_type_name_missing', $path . '.name', 'room type name is required.');
        }
        ctrip_pricing_import_lint_number($issues, $row, $path, 'base_price', 0.01);
        ctrip_pricing_import_lint_number($issues, $row, $path, 'min_price', 0.01);
        ctrip_pricing_import_lint_number($issues, $row, $path, 'max_price', 0.01);
        ctrip_pricing_import_lint_number($issues, $row, $path, 'room_count', 1);
        if (isset($row['base_price'], $row['min_price'], $row['max_price']) && is_numeric($row['base_price']) && is_numeric($row['min_price']) && is_numeric($row['max_price'])) {
            $basePrice = (float)$row['base_price'];
            if ((float)$row['min_price'] > $basePrice || (float)$row['max_price'] < $basePrice) {
                ctrip_pricing_import_lint_issue($issues, 'room_type_price_guard_inconsistent', $path, 'min_price <= base_price <= max_price is required.');
            }
        }
        if ((int)($row['is_enabled'] ?? 0) !== 1) {
            ctrip_pricing_import_lint_issue($issues, 'room_type_not_enabled', $path . '.is_enabled', 'room type must be enabled for Ctrip pricing generation.');
        }
    }

    foreach ($forecastRows as $index => $row) {
        $path = '$.demand_forecasts.' . $index;
        if (!is_array($row)) {
            ctrip_pricing_import_lint_issue($issues, 'row_not_object', $path, 'demand_forecasts row must be an object.');
            continue;
        }
        $key = ctrip_pricing_import_room_key($row);
        if ($key === '' || !isset($roomKeys[$key])) {
            ctrip_pricing_import_lint_issue($issues, 'room_type_key_unmatched', $path . '.room_type_key', 'room_type_key must match a room_types key.');
        }
        $forecastDate = trim((string)($row['forecast_date'] ?? $businessDate));
        if ($forecastDate !== '' && $businessDate !== '' && $forecastDate !== $businessDate) {
            ctrip_pricing_import_lint_issue($issues, 'forecast_date_mismatch', $path . '.forecast_date', 'forecast_date must match business_date.');
        }
        ctrip_pricing_import_lint_number($issues, $row, $path, 'predicted_occupancy', 0, 100);
        ctrip_pricing_import_lint_number($issues, $row, $path, 'predicted_demand', 0.01);
        ctrip_pricing_import_lint_number($issues, $row, $path, 'confidence_score', 0, 1);
    }

    foreach ($competitorRows as $index => $row) {
        $path = '$.competitor_price_samples.' . $index;
        if (!is_array($row)) {
            ctrip_pricing_import_lint_issue($issues, 'row_not_object', $path, 'competitor_price_samples row must be an object.');
            continue;
        }
        $key = ctrip_pricing_import_room_key($row);
        if ($key === '' || !isset($roomKeys[$key])) {
            ctrip_pricing_import_lint_issue($issues, 'room_type_key_unmatched', $path . '.room_type_key', 'room_type_key must match a room_types key.');
        }
        $analysisDate = trim((string)($row['analysis_date'] ?? $businessDate));
        $analysisDateValue = $analysisDate !== '' ? ctrip_pricing_import_date($analysisDate) : null;
        $businessDateValue = $businessDate !== '' ? ctrip_pricing_import_date($businessDate) : null;
        if ($analysisDate === '' || !$analysisDateValue instanceof DateTimeImmutable) {
            ctrip_pricing_import_lint_issue($issues, 'analysis_date_invalid', $path . '.analysis_date', 'analysis_date must be YYYY-MM-DD.');
        } elseif ($businessDateValue instanceof DateTimeImmutable) {
            if ($analysisDateValue > $businessDateValue) {
                ctrip_pricing_import_lint_issue($issues, 'analysis_date_in_future', $path . '.analysis_date', 'analysis_date must not be after business_date.');
            }
            $earliestRecentDate = $businessDateValue->modify('-6 days');
            if ($analysisDateValue < $earliestRecentDate) {
                ctrip_pricing_import_lint_issue($issues, 'analysis_date_outside_recent_7d', $path . '.analysis_date', 'analysis_date must be within the 7-day window ending at business_date.', [
                    'earliest_allowed_date' => $earliestRecentDate->format('Y-m-d'),
                    'latest_allowed_date' => $businessDateValue->format('Y-m-d'),
                ]);
            }
        }
        if (ctrip_pricing_import_string($row, 'competitor_name') === '' && (int)($row['competitor_hotel_id'] ?? 0) <= 0) {
            ctrip_pricing_import_lint_issue($issues, 'competitor_identity_missing', $path, 'competitor_name or competitor_hotel_id is required.');
        }
        $platform = strtolower(trim((string)($row['ota_platform'] ?? 'ctrip')));
        if (!in_array($platform, ['ctrip', '1'], true)) {
            ctrip_pricing_import_lint_issue($issues, 'competitor_platform_invalid', $path . '.ota_platform', 'competitor sample must use Ctrip platform.');
        }
        ctrip_pricing_import_lint_number($issues, $row, $path, 'our_price', 0.01);
        ctrip_pricing_import_lint_number($issues, $row, $path, 'competitor_price', 0.01);
    }

    $codes = array_values(array_unique(array_column($issues, 'code')));
    return [
        'status' => $issues === [] ? 'passed' : 'failed',
        'business_date' => $businessDate,
        'source_scope' => (string)($payload['source_scope'] ?? ''),
        'room_type_rows' => count($roomRows),
        'demand_forecast_rows' => count($forecastRows),
        'competitor_sample_rows' => count($competitorRows),
        'issue_count' => count($issues),
        'issue_codes' => $codes,
        'issues' => $issues,
    ];
}

/**
 * @param array<int, array<string, mixed>> $checks
 */
function ctrip_pricing_import_check(array &$checks, string $code, bool $ok, string $message, array $details = []): void
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
 * @param array<string, int> $roomTypeIdsByKey
 */
function ctrip_pricing_import_room_type_id(array $row, array $roomTypeIdsByKey): int
{
    $id = (int)($row['room_type_id'] ?? 0);
    if ($id > 0) {
        return $id;
    }
    $key = ctrip_pricing_import_room_key($row);
    if ($key !== '' && isset($roomTypeIdsByKey[$key])) {
        return $roomTypeIdsByKey[$key];
    }
    throw new InvalidArgumentException('room_type_key is missing or not matched for row: ' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    ctrip_pricing_import_finish([
        'status' => 'failed',
        'error' => 'vendor/autoload.php is missing.',
    ], 1);
}

require $autoload;

try {
    $options = ctrip_pricing_import_parse_args($argv);
    if ($options['print_template']) {
        ctrip_pricing_import_finish([
            'status' => 'template',
            'source_scope' => 'ctrip_ota_channel',
            'auto_write_ota' => false,
            'template' => ctrip_pricing_import_template(),
        ], 0);
    }
    if ($options['print_current_template']) {
        $businessDate = $options['date'] !== '' ? $options['date'] : date('Y-m-d');
        $app = new App($root);
        $app->initialize();
        $hotelId = ctrip_pricing_import_resolve_hotel_id($options['hotel_id'], $businessDate);
        $overview = (new RevenueAiOverviewService())->overview([
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
        ]);
        $preflight = ctrip_pricing_import_map($overview['pricing_generation_preflight'] ?? []);
        $template = ctrip_pricing_import_current_template($businessDate, $hotelId, $overview);
        $outputFile = ctrip_pricing_import_write_json_output($options['output'], $template, $options['force']);
        ctrip_pricing_import_finish([
            'status' => 'template',
            'source_scope' => 'ctrip_ota_channel',
            'source_policy' => 'operator_provided_ctrip_pricing_input_template_from_current_preflight',
            'auto_write_ota' => false,
            'output_file' => $outputFile === [] ? null : $outputFile,
            'summary' => [
                'business_date' => $businessDate,
                'hotel_id' => $hotelId,
                'pricing_generation_preflight_status' => $preflight['status'] ?? null,
                'pricing_generation_preflight_reason' => $preflight['reason'] ?? null,
                'required_input_codes' => ctrip_pricing_import_required_input_codes($preflight),
            ],
            'template' => $options['output'] === '' ? $template : null,
        ], 0);
    }

    $payload = ctrip_pricing_import_load_payload($options['file']);
    if ($options['lint_only']) {
        if ($options['execute'] || $options['generate'] || $options['validate_only']) {
            throw new InvalidArgumentException('--lint-only cannot be combined with --execute, --generate, or --validate-only.');
        }
        $lint = ctrip_pricing_import_lint_payload($payload, $options['date']);
        ctrip_pricing_import_finish([
            'status' => $lint['status'],
            'mode' => 'lint_only',
            'scope' => [
                'business_date' => $lint['business_date'],
                'platform' => 'ctrip',
                'source_scope' => $lint['source_scope'],
                'source_policy' => 'operator_provided_ctrip_pricing_inputs_lint_only_no_db',
            ],
            'summary' => [
                'auto_write_ota' => false,
                'database_touched' => false,
                'room_type_rows' => $lint['room_type_rows'],
                'demand_forecast_rows' => $lint['demand_forecast_rows'],
                'competitor_sample_rows' => $lint['competitor_sample_rows'],
                'issue_count' => $lint['issue_count'],
                'issue_codes' => $lint['issue_codes'],
            ],
            'issues' => $lint['issues'],
        ], $lint['status'] === 'passed' ? 0 : 1);
    }

    ctrip_pricing_import_assert_no_placeholder($payload);
    if ($options['validate_only'] && ($options['execute'] || $options['generate'])) {
        throw new InvalidArgumentException('--validate-only cannot be combined with --execute or --generate.');
    }

    $businessDate = (string)($payload['business_date'] ?? $payload['date'] ?? '');
    if ($options['date'] !== '') {
        if ($businessDate !== '' && $businessDate !== $options['date']) {
            throw new InvalidArgumentException('Input business_date does not match --date.');
        }
        $businessDate = $options['date'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
        throw new InvalidArgumentException('business_date must be YYYY-MM-DD.');
    }

    $sourceScope = (string)($payload['source_scope'] ?? 'ctrip_ota_channel');
    if ($sourceScope !== 'ctrip_ota_channel') {
        throw new InvalidArgumentException('source_scope must be ctrip_ota_channel.');
    }
    if (strtolower((string)($payload['platform'] ?? 'ctrip')) !== 'ctrip') {
        throw new InvalidArgumentException('platform must be ctrip.');
    }

    $roomRows = ctrip_pricing_import_rows($payload['room_types'] ?? null, 'room_types');
    $forecastRows = ctrip_pricing_import_rows($payload['demand_forecasts'] ?? null, 'demand_forecasts');
    $competitorRows = ctrip_pricing_import_rows($payload['competitor_price_samples'] ?? null, 'competitor_price_samples');

    $app = new App($root);
    $app->initialize();
    $hotelId = ctrip_pricing_import_resolve_hotel_id(
        $options['hotel_id'] ?? ((int)($payload['hotel_id'] ?? 0) > 0 ? (int)$payload['hotel_id'] : null),
        $businessDate
    );
    if ((int)($payload['hotel_id'] ?? 0) > 0 && (int)$payload['hotel_id'] !== $hotelId) {
        throw new InvalidArgumentException('Input hotel_id does not match resolved hotel_id.');
    }

    $app->request->user = ctrip_pricing_import_fake_super_admin($hotelId);
    $fileHash = hash_file('sha256', $options['file']) ?: '';
    $mode = $options['validate_only'] ? 'validate_only' : ($options['execute'] ? 'execute' : 'dry_run');
    $checks = [];
    $roomTypeIdsByKey = [];
    $imported = [
        'room_types' => [],
        'demand_forecasts' => [],
        'competitor_price_samples' => [],
    ];
    $generationData = [];
    $overview = [];
    $committed = false;
    $rolledBack = false;

    Db::startTrans();
    try {
        foreach ($roomRows as $row) {
            $key = ctrip_pricing_import_room_key($row);
            if ($key === '') {
                throw new InvalidArgumentException('room_types row is missing key/name.');
            }
            $roomPayload = $row;
            $roomPayload['hotel_id'] = $hotelId;
            $roomName = ctrip_pricing_import_string($roomPayload, 'name');
            if ((int)($roomPayload['id'] ?? 0) <= 0 && $roomName !== '') {
                $existing = RoomType::where('hotel_id', $hotelId)->where('name', $roomName)->find();
                if ($existing) {
                    $roomPayload['id'] = (int)$existing->id;
                }
            }
            $app->request->withPost($roomPayload);
            $data = ctrip_pricing_import_expect_success((new Agent($app))->saveRoomType(), 'save_room_type');
            $roomType = ctrip_pricing_import_map($data['room_type'] ?? []);
            $roomTypeId = (int)($roomType['id'] ?? 0);
            if ($roomTypeId <= 0) {
                throw new RuntimeException('save_room_type did not return room_type.id.');
            }
            $roomTypeIdsByKey[$key] = $roomTypeId;
            if ($roomName !== '') {
                $roomTypeIdsByKey[$roomName] = $roomTypeId;
            }
            $imported['room_types'][] = [
                'key' => $key,
                'id' => $roomTypeId,
                'name' => $roomType['name'] ?? $roomName,
                'is_enabled' => (int)($roomType['is_enabled'] ?? 0),
            ];
        }

        foreach ($forecastRows as $row) {
            $roomTypeId = ctrip_pricing_import_room_type_id($row, $roomTypeIdsByKey);
            $existingForecastCount = (int)DemandForecast::where('hotel_id', $hotelId)
                ->where('forecast_date', (string)($row['forecast_date'] ?? $businessDate))
                ->where('room_type_id', $roomTypeId)
                ->count();
            if ($existingForecastCount > 0) {
                throw new RuntimeException('existing_demand_forecast_found for room_type_id=' . $roomTypeId);
            }
            $forecastPayload = $row;
            $forecastPayload['hotel_id'] = $hotelId;
            $forecastPayload['forecast_date'] = (string)($row['forecast_date'] ?? $businessDate);
            $forecastPayload['room_type_id'] = $roomTypeId;
            $forecastPayload['historical_data'] = array_merge(
                ctrip_pricing_import_map($row['historical_data'] ?? []),
                [
                    'source_file_sha256' => $fileHash,
                    'import_mode' => $mode,
                    'importer' => 'scripts/import_revenue_ai_ctrip_pricing_inputs.php',
                ]
            );
            $app->request->withPost($forecastPayload);
            $data = ctrip_pricing_import_expect_success((new Agent($app))->createForecast(), 'create_forecast');
            $imported['demand_forecasts'][] = [
                'id' => (int)($data['id'] ?? 0),
                'room_type_id' => $roomTypeId,
                'forecast_date' => $forecastPayload['forecast_date'],
            ];
        }

        foreach ($competitorRows as $row) {
            $platform = strtolower(trim((string)($row['ota_platform'] ?? 'ctrip')));
            if (!in_array($platform, ['ctrip', '1'], true)) {
                throw new InvalidArgumentException('competitor_price_samples.ota_platform must be ctrip.');
            }
            $roomTypeId = ctrip_pricing_import_room_type_id($row, $roomTypeIdsByKey);
            $competitorPayload = $row;
            $competitorPayload['hotel_id'] = $hotelId;
            $competitorPayload['analysis_date'] = (string)($row['analysis_date'] ?? $businessDate);
            $competitorPayload['room_type_id'] = $roomTypeId;
            $competitorPayload['ota_platform'] = CompetitorAnalysis::PLATFORM_CTRIP;
            $competitorPayload['competitor_data'] = array_merge(
                ctrip_pricing_import_map($row['competitor_data'] ?? []),
                [
                    'source_file_sha256' => $fileHash,
                    'import_mode' => $mode,
                    'importer' => 'scripts/import_revenue_ai_ctrip_pricing_inputs.php',
                ]
            );
            if (isset($row['competitor_name'])) {
                $competitorPayload['competitor_data']['competitor_name'] = (string)$row['competitor_name'];
            }
            $app->request->withPost($competitorPayload);
            $data = ctrip_pricing_import_expect_success((new Agent($app))->recordCompetitorPrice(), 'record_competitor_price');
            $imported['competitor_price_samples'][] = [
                'id' => (int)($data['id'] ?? 0),
                'room_type_id' => $roomTypeId,
                'analysis_date' => $competitorPayload['analysis_date'],
                'ota_platform' => 'ctrip',
            ];
        }

        $overview = (new RevenueAiOverviewService())->overview([
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
        ]);

        $preflight = ctrip_pricing_import_map($overview['pricing_generation_preflight'] ?? []);
        ctrip_pricing_import_check(
            $checks,
            'operator_input_file_loaded',
            $fileHash !== '' && $roomRows !== [] && $forecastRows !== [] && $competitorRows !== [],
            'Operator-provided Ctrip pricing input file is loaded and non-empty.',
            [
                'file_sha256' => $fileHash,
                'room_type_rows' => count($roomRows),
                'demand_forecast_rows' => count($forecastRows),
                'competitor_sample_rows' => count($competitorRows),
            ]
        );
        ctrip_pricing_import_check(
            $checks,
            'source_scope_ctrip_only',
            $sourceScope === 'ctrip_ota_channel',
            'Input scope is explicitly Ctrip OTA channel only.',
            ['source_scope' => $sourceScope]
        );
        ctrip_pricing_import_check(
            $checks,
            'room_types_saved',
            count($imported['room_types']) === count($roomRows),
            'Room type pricing guards are saved through the Agent endpoint.',
            ['count' => count($imported['room_types'])]
        );
        ctrip_pricing_import_check(
            $checks,
            'demand_forecasts_saved',
            count($imported['demand_forecasts']) === count($forecastRows),
            'Demand forecasts are saved through the Agent endpoint.',
            ['count' => count($imported['demand_forecasts'])]
        );
        ctrip_pricing_import_check(
            $checks,
            'ctrip_competitor_samples_saved',
            count($imported['competitor_price_samples']) === count($competitorRows),
            'Ctrip competitor price samples are saved through the Agent endpoint.',
            ['count' => count($imported['competitor_price_samples'])]
        );
        ctrip_pricing_import_check(
            $checks,
            'pricing_preflight_has_candidate',
            (int)($preflight['create_candidate_count'] ?? 0) > 0 || (int)($preflight['pending_suggestion_count'] ?? 0) > 0,
            'Revenue AI pricing preflight can see at least one Ctrip create candidate or existing pending suggestion after import.',
            [
                'status' => $preflight['status'] ?? null,
                'reason' => $preflight['reason'] ?? null,
                'create_candidate_count' => $preflight['create_candidate_count'] ?? null,
                'pending_suggestion_count' => $preflight['pending_suggestion_count'] ?? null,
            ]
        );
        if ($options['validate_only']) {
            ctrip_pricing_import_check(
                $checks,
                'validate_only_keeps_generation_disabled',
                $generationData === [],
                'Validate-only mode proves the Ctrip pricing input file without creating pending suggestions.',
                ['auto_write_ota' => false, 'transaction_policy' => 'rollback']
            );
        }

        if (!$options['validate_only'] && (!$options['execute'] || $options['generate'])) {
            $app->request->withGet([
                'hotel_id' => $hotelId,
                'date' => $businessDate,
            ]);
            $generationPayload = ctrip_pricing_import_decode_response((new Agent($app))->generatePriceSuggestions());
            $generationData = ctrip_pricing_import_map($generationPayload['data'] ?? []);
            ctrip_pricing_import_check(
                $checks,
                'generation_creates_or_exposes_pending_review',
                (int)($generationData['created_count'] ?? 0) > 0
                    || (string)($generationData['reason'] ?? '') === 'price_suggestions_pending_review',
                'Generation creates or exposes local pending Ctrip AI price suggestions without OTA write.',
                [
                    'status' => $generationData['status'] ?? null,
                    'reason' => $generationData['reason'] ?? null,
                    'created_count' => $generationData['created_count'] ?? null,
                    'skipped_count' => $generationData['skipped_count'] ?? null,
                    'auto_write_ota' => $generationData['auto_write_ota'] ?? null,
                ]
            );
            ctrip_pricing_import_check(
                $checks,
                'generation_keeps_manual_review_gate',
                (string)($generationData['source_scope'] ?? '') === 'ctrip_ota_channel'
                    && ctrip_pricing_import_list($generationData['source_channels'] ?? []) === ['ctrip']
                    && ($generationData['auto_write_ota'] ?? true) === false
                    && (ctrip_pricing_import_map($generationData['ai_review_gate'] ?? [])['operation_intake_allowed'] ?? true) === false,
                'Generation remains Ctrip-scoped, manual-review gated, and never writes OTA.',
                [
                    'source_scope' => $generationData['source_scope'] ?? null,
                    'source_channels' => $generationData['source_channels'] ?? null,
                    'ai_review_gate' => $generationData['ai_review_gate'] ?? null,
                    'auto_write_ota' => $generationData['auto_write_ota'] ?? null,
                ]
            );
        }

        $failures = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'passed'));
        if ($failures !== []) {
            Db::rollback();
            $rolledBack = true;
        } elseif ($options['execute']) {
            Db::commit();
            $committed = true;
        } else {
            Db::rollback();
            $rolledBack = true;
        }
    } catch (Throwable $e) {
        Db::rollback();
        $rolledBack = true;
        throw $e;
    }

    $failures = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'passed'));
    $preflight = ctrip_pricing_import_map($overview['pricing_generation_preflight'] ?? []);
    ctrip_pricing_import_finish([
        'status' => $failures === [] ? 'passed' : 'failed',
        'mode' => $mode,
        'scope' => [
            'business_date' => $businessDate,
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $hotelId,
            'input_scope' => 'manual_pricing_configuration',
            'source_policy' => $options['validate_only']
                ? 'operator_provided_ctrip_pricing_inputs_validate_only_rollback'
                : ($options['execute']
                    ? 'operator_provided_ctrip_pricing_inputs_execute'
                    : 'operator_provided_ctrip_pricing_inputs_dry_run_rollback'),
        ],
        'summary' => [
            'committed' => $committed,
            'rolled_back' => $rolledBack,
            'auto_write_ota' => false,
            'imported' => $imported,
            'pricing_generation_preflight' => [
                'status' => $preflight['status'] ?? null,
                'reason' => $preflight['reason'] ?? null,
                'room_type_count' => $preflight['room_type_count'] ?? null,
                'create_candidate_count' => $preflight['create_candidate_count'] ?? null,
                'pending_suggestion_count' => $preflight['pending_suggestion_count'] ?? null,
                'required_inputs' => $preflight['required_inputs'] ?? [],
            ],
            'generation' => $generationData === [] ? null : [
                'status' => $generationData['status'] ?? null,
                'reason' => $generationData['reason'] ?? null,
                'created_count' => $generationData['created_count'] ?? null,
                'skipped_count' => $generationData['skipped_count'] ?? null,
                'source_scope' => $generationData['source_scope'] ?? null,
                'source_channels' => $generationData['source_channels'] ?? null,
                'auto_write_ota' => $generationData['auto_write_ota'] ?? null,
                'ai_review_gate' => $generationData['ai_review_gate'] ?? null,
            ],
            'next_action' => $options['execute']
                ? ($options['generate']
                    ? 'Review pending Ctrip AI price suggestions before creating operation execution evidence.'
                    : 'Run npm.cmd run verify:revenue-ai-ctrip-scope -- --date=' . $businessDate . ' and then generate pending suggestions from Agent Center.')
                : ($options['validate_only']
                    ? 'Validate-only rollback completed. Run dry-run generation next, then execute only after confirming the operator-provided values.'
                    : 'Dry-run only. Re-run with --execute=1 after confirming the operator-provided values.'),
        ],
        'checks' => $checks,
    ], $failures === [] ? 0 : 1);
} catch (Throwable $e) {
    ctrip_pricing_import_finish([
        'status' => 'failed',
        'error' => $e->getMessage(),
    ], 1);
}
