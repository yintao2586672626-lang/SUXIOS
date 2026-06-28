<?php
declare(strict_types=1);

use app\model\PriceSuggestion;
use app\service\OperationManagementService;
use think\App;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{
 *   file:string,
 *   date:string,
 *   hotel_id:int|null,
 *   execute:bool,
 *   create_intent:bool,
 *   user_id:int,
 *   print_template:bool,
 *   output:string,
 *   force:bool,
 *   suggestion_id:int,
 *   manage_transaction:bool
 * }
 */
function ctrip_review_decision_parse_args(array $argv): array
{
    $options = [
        'file' => '',
        'date' => date('Y-m-d'),
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'execute' => false,
        'create-intent' => false,
        'create_intent' => false,
        'user-id' => '0',
        'user_id' => '',
        'print-template' => false,
        'print_template' => false,
        'output' => '',
        'force' => false,
        'suggestion-id' => '0',
        'suggestion_id' => '',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--execute') {
            $options['execute'] = true;
            continue;
        }
        if ($arg === '--create-intent') {
            $options['create-intent'] = true;
            continue;
        }
        if ($arg === '--print-template') {
            $options['print-template'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (!array_key_exists($key, $options)) {
            continue;
        }
        if (in_array($key, ['execute', 'create-intent', 'create_intent', 'print-template', 'print_template', 'force'], true)) {
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
    if ((string)$options['user-id'] === '' && (string)$options['user_id'] !== '') {
        $options['user-id'] = (string)$options['user_id'];
    }
    if ((bool)$options['create_intent']) {
        $options['create-intent'] = true;
    }
    if ((bool)$options['print_template']) {
        $options['print-template'] = true;
    }
    if ((string)$options['suggestion-id'] === '' && (string)$options['suggestion_id'] !== '') {
        $options['suggestion-id'] = (string)$options['suggestion_id'];
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

    $userId = 0;
    if ((string)$options['user-id'] !== '') {
        if (!ctype_digit((string)$options['user-id']) || (int)$options['user-id'] < 0) {
            throw new InvalidArgumentException('Invalid --user-id, expected a non-negative integer.');
        }
        $userId = (int)$options['user-id'];
    }

    $printTemplate = (bool)$options['print-template'];
    $suggestionId = 0;
    if ((string)$options['suggestion-id'] !== '') {
        if (!ctype_digit((string)$options['suggestion-id']) || (int)$options['suggestion-id'] < 0) {
            throw new InvalidArgumentException('Invalid --suggestion-id, expected a non-negative integer.');
        }
        $suggestionId = (int)$options['suggestion-id'];
    }

    if (!$printTemplate) {
        if ((string)$options['file'] === '') {
            throw new InvalidArgumentException('Missing --file=<review-decision-json-path>.');
        }
        if (!is_file((string)$options['file'])) {
            throw new InvalidArgumentException('Input file does not exist: ' . (string)$options['file']);
        }
    }

    return [
        'file' => (string)$options['file'],
        'date' => (string)$options['date'],
        'hotel_id' => $hotelId,
        'execute' => (bool)$options['execute'],
        'create_intent' => (bool)$options['create-intent'],
        'user_id' => $userId,
        'print_template' => $printTemplate,
        'output' => (string)$options['output'],
        'force' => (bool)$options['force'],
        'suggestion_id' => $suggestionId,
        'manage_transaction' => true,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_review_decision_finish(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function ctrip_review_decision_write_json_output(string $output, array $payload, bool $force): array
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
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed> $details
 */
function ctrip_review_decision_check(array &$checks, string $code, bool $ok, string $message, array $details = []): void
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
 * @return array<string, mixed>
 */
function ctrip_review_decision_map(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @param mixed $value
 * @return array<int, mixed>
 */
function ctrip_review_decision_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @param mixed $value
 * @return array<string, mixed>
 */
function ctrip_review_decision_decode_map(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}

function ctrip_review_decision_money(mixed $value): ?float
{
    if (is_string($value)) {
        $value = preg_replace('/[^\d.\-]/', '', $value) ?? '';
    }
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    $number = round((float)$value, 2);

    return $number > 0 ? $number : null;
}

function ctrip_review_decision_text(mixed $value, int $limit = 500): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    return mb_substr($text, 0, $limit);
}

/**
 * @return array<string, mixed>
 */
function ctrip_review_decision_template(string $date, ?int $hotelId = null): array
{
    return [
        'business_date' => $date,
        'hotel_id' => $hotelId ?? '<ctrip_target_hotel_id>',
        'platform' => 'ctrip',
        'source_scope' => 'ctrip_ota_channel',
        'auto_write_ota' => false,
        'review_decision' => [
            'suggestion_id' => '<pending_price_suggestion_id>',
            'action' => 'approve',
            'approved_price' => null,
            'remark' => '<operator_review_remark>',
            'create_execution_intent_after_approval' => false,
            'execution_intent' => [
                'platform' => 'ctrip',
                'room_type_key' => '',
                'rate_plan_key' => '<rate_plan_key_required_for_operation_intent>',
                'expected_metric' => 'orders',
                'expected_delta' => 0,
                'risk_level' => 'medium',
            ],
        ],
        'operator_rules' => [
            'Use approve_with_changes when approved_price differs from suggested_price.',
            'Use reject when the Ctrip OTA evidence or price guard is not acceptable.',
            'Do not set auto_write_ota=true; this file only records local manual review and optional operation intent.',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function ctrip_review_decision_pending_template_context(int $suggestionId, string $date, ?int $hotelId): array
{
    if ($suggestionId <= 0) {
        return [];
    }

    $suggestion = PriceSuggestion::find($suggestionId);
    if (!$suggestion) {
        throw new InvalidArgumentException('Pending price suggestion not found for template: ' . $suggestionId);
    }
    $row = $suggestion->toArray();
    $rowHotelId = (int)($row['hotel_id'] ?? 0);
    $rowDate = ctrip_review_decision_text($row['suggestion_date'] ?? '', 20);
    if ((int)($row['status'] ?? 0) !== PriceSuggestion::STATUS_PENDING) {
        throw new InvalidArgumentException('Review decision template requires a pending price suggestion.');
    }
    if ($rowDate !== $date) {
        throw new InvalidArgumentException('Pending price suggestion date does not match requested date.');
    }
    if ($hotelId !== null && $rowHotelId !== $hotelId) {
        throw new InvalidArgumentException('Pending price suggestion hotel_id does not match requested hotel scope.');
    }
    $sourceScope = ctrip_review_decision_suggestion_source_scope($row);
    $channels = ctrip_review_decision_suggestion_channels($row);
    if ($sourceScope !== 'ctrip_ota_channel' || $channels !== ['ctrip']) {
        throw new InvalidArgumentException('Review decision template requires a Ctrip-only pending suggestion.');
    }

    $currentPrice = ctrip_review_decision_money($row['current_price'] ?? null);
    $suggestedPrice = ctrip_review_decision_money($row['suggested_price'] ?? null);
    $minPrice = ctrip_review_decision_money($row['min_price'] ?? null);
    $maxPrice = ctrip_review_decision_money($row['max_price'] ?? null);
    $priceGuardStatus = 'not_checked';
    if ($suggestedPrice === null || $minPrice === null) {
        $priceGuardStatus = 'missing_required_price_guard';
    } elseif ($suggestedPrice < $minPrice) {
        $priceGuardStatus = 'below_min_price';
    } elseif ($maxPrice !== null && $suggestedPrice > $maxPrice) {
        $priceGuardStatus = 'above_max_price';
    } else {
        $priceGuardStatus = 'within_guard';
    }

    return [
        'suggestion_id' => $suggestionId,
        'hotel_id' => $rowHotelId,
        'suggestion_date' => $rowDate,
        'status' => 'pending_review',
        'source_scope' => $sourceScope,
        'source_channels' => $channels,
        'room_type_id' => (int)($row['room_type_id'] ?? 0),
        'current_price' => $currentPrice,
        'suggested_price' => $suggestedPrice,
        'min_price' => $minPrice,
        'max_price' => $maxPrice,
        'price_guard_status' => $priceGuardStatus,
        'review_endpoint' => '/api/revenue-ai/price-suggestions/' . $suggestionId . '/review',
        'execution_intent_endpoint_after_approval' => '/api/revenue-ai/price-suggestions/' . $suggestionId . '/execution-intent',
        'auto_write_ota' => false,
    ];
}

/**
 * @return array<string, mixed>
 */
function ctrip_review_decision_template_for_pending(string $date, ?int $hotelId, int $suggestionId): array
{
    $template = ctrip_review_decision_template($date, $hotelId);
    $context = ctrip_review_decision_pending_template_context($suggestionId, $date, $hotelId);
    if ($context === []) {
        return $template;
    }

    $template['hotel_id'] = (int)$context['hotel_id'];
    $template['review_decision']['suggestion_id'] = (int)$context['suggestion_id'];
    $template['pending_suggestion'] = $context;

    return $template;
}

/**
 * @return array<string, mixed>
 */
function ctrip_review_decision_read_json_file(string $file): array
{
    $text = file_get_contents($file);
    if ($text === false) {
        throw new RuntimeException('Unable to read input file: ' . $file);
    }
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    $payload = json_decode($text, true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Input file is not valid JSON object.');
    }

    return $payload;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function ctrip_review_decision_extract_decision(array $payload): array
{
    $decision = ctrip_review_decision_map($payload['review_decision'] ?? []);
    if ($decision === []) {
        $decision = $payload;
    }

    return $decision;
}

/**
 * @param mixed $value
 * @return array<int, string>
 */
function ctrip_review_decision_string_values(mixed $value): array
{
    if (is_string($value)) {
        return [$value];
    }
    if (!is_array($value)) {
        return [];
    }

    $strings = [];
    foreach ($value as $item) {
        $strings = array_merge($strings, ctrip_review_decision_string_values($item));
    }

    return $strings;
}

/**
 * @param array<string, mixed> $payload
 * @return array<int, string>
 */
function ctrip_review_decision_placeholder_paths(array $payload, string $prefix = ''): array
{
    $paths = [];
    foreach ($payload as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
        if (is_array($value)) {
            $paths = array_merge($paths, ctrip_review_decision_placeholder_paths($value, $path));
            continue;
        }
        if (is_string($value) && preg_match('/<[^>]+>/', $value) === 1) {
            $paths[] = $path;
        }
    }

    return $paths;
}

/**
 * @param array<string, mixed> $payload
 * @return array<int, string>
 */
function ctrip_review_decision_forbidden_value_hits(array $payload): array
{
    $hits = [];
    foreach (ctrip_review_decision_string_values($payload) as $value) {
        $lower = strtolower($value);
        if (str_contains($lower, 'meituan') || str_contains($value, '美团')) {
            $hits[] = mb_substr($value, 0, 80);
        }
    }

    return array_values(array_unique($hits));
}

/**
 * @param array<string, mixed> $suggestion
 * @return array<int, string>
 */
function ctrip_review_decision_suggestion_channels(array $suggestion): array
{
    $factors = ctrip_review_decision_decode_map($suggestion['factors'] ?? []);
    $competitorData = ctrip_review_decision_decode_map($suggestion['competitor_data'] ?? []);
    $channels = ctrip_review_decision_list($factors['source_channels'] ?? ($competitorData['source_channels'] ?? []));

    return array_values(array_unique(array_map(
        static fn(mixed $value): string => strtolower(trim((string)$value)),
        $channels
    )));
}

/**
 * @param array<string, mixed> $suggestion
 */
function ctrip_review_decision_suggestion_source_scope(array $suggestion): string
{
    $factors = ctrip_review_decision_decode_map($suggestion['factors'] ?? []);
    $competitorData = ctrip_review_decision_decode_map($suggestion['competitor_data'] ?? []);

    return strtolower(trim((string)($factors['source_scope'] ?? ($competitorData['source_scope'] ?? ''))));
}

/**
 * @param array<string, mixed> $suggestion
 * @return array{factors:array<string, mixed>,review:array<string, mixed>}
 */
function ctrip_review_decision_build_manual_review(
    array $suggestion,
    string $action,
    int $userId,
    string $remark,
    ?float $approvedPrice
): array {
    $factors = ctrip_review_decision_decode_map($suggestion['factors'] ?? []);
    $versions = is_array($factors['manual_review_versions'] ?? null)
        ? array_values(array_filter($factors['manual_review_versions'], 'is_array'))
        : [];
    $originalPrice = ctrip_review_decision_money($suggestion['suggested_price'] ?? null);
    $finalApprovedPrice = match ($action) {
        'reject' => null,
        'approve_with_changes' => $approvedPrice,
        default => $originalPrice,
    };
    $priceDelta = $originalPrice !== null && $finalApprovedPrice !== null
        ? round($finalApprovedPrice - $originalPrice, 2)
        : null;
    $statusAfter = match ($action) {
        'approve', 'approve_with_changes' => 'approved',
        'reject' => 'rejected',
        default => 'unknown',
    };
    $review = [
        'version' => count($versions) + 1,
        'action' => $action,
        'status_after' => $statusAfter,
        'original_suggested_price' => $originalPrice,
        'approved_price' => $finalApprovedPrice,
        'price_delta' => $priceDelta,
        'reviewed_by' => $userId,
        'reviewed_at' => date('Y-m-d H:i:s'),
        'remark' => $remark,
        'auto_write_ota' => false,
        'local_price_updated' => false,
        'ota_write' => false,
        'version_storage' => 'price_suggestions.factors.manual_review_versions',
    ];
    $versions[] = $review;
    $factors['manual_review_versions'] = $versions;
    $factors['manual_review'] = $review;

    return ['factors' => $factors, 'review' => $review];
}

function ctrip_review_decision_status_after(string $action): int
{
    return match ($action) {
        'approve', 'approve_with_changes' => PriceSuggestion::STATUS_APPROVED,
        'reject' => PriceSuggestion::STATUS_REJECTED,
        default => throw new InvalidArgumentException('price_suggestion_review_action_invalid'),
    };
}

/**
 * @param array<string, mixed> $suggestion
 * @return array<string, mixed>|null
 */
function ctrip_review_decision_existing_intent(array $suggestion, OperationManagementService $service): ?array
{
    $suggestionId = (int)($suggestion['id'] ?? 0);
    $hotelId = (int)($suggestion['hotel_id'] ?? 0);
    if ($suggestionId <= 0 || $hotelId <= 0 || !$service->tableExists('operation_execution_intents')) {
        return null;
    }

    $row = Db::name('operation_execution_intents')
        ->where('source_module', 'price_suggestion')
        ->where('source_record_id', $suggestionId)
        ->where('hotel_id', $hotelId)
        ->whereNull('deleted_at')
        ->order('id', 'desc')
        ->find();

    return is_array($row) ? $row : null;
}

/**
 * @param array<string, mixed> $suggestion
 * @param array<string, mixed> $decision
 * @return array<string, mixed>|null
 */
function ctrip_review_decision_create_intent(array $suggestion, array $decision, int $userId): ?array
{
    $service = new OperationManagementService();
    $existing = ctrip_review_decision_existing_intent($suggestion, $service);
    if ($existing !== null) {
        $existing['existing'] = true;
        return $existing;
    }

    $intentOverrides = ctrip_review_decision_map($decision['execution_intent'] ?? []);
    $intentOverrides['platform'] = strtolower(trim((string)($intentOverrides['platform'] ?? 'ctrip')));
    $input = $service->buildPriceSuggestionExecutionIntentInput($suggestion, $intentOverrides);
    $intent = $service->createExecutionIntent([(int)$suggestion['hotel_id']], (int)$suggestion['hotel_id'], $input, $userId);
    $intent['existing'] = false;

    return $intent;
}

/**
 * @param array<string, mixed> $options
 * @return array{payload:array<string, mixed>,exit_code:int}
 */
function ctrip_review_decision_run(array $options): array
{
    if (($options['print_template'] ?? false) === true) {
        $date = (string)($options['date'] ?? date('Y-m-d'));
        $hotelId = isset($options['hotel_id']) && $options['hotel_id'] !== null ? (int)$options['hotel_id'] : null;
        $suggestionId = (int)($options['suggestion_id'] ?? 0);
        $template = ctrip_review_decision_template_for_pending($date, $hotelId, $suggestionId);
        $outputFile = ctrip_review_decision_write_json_output(
            (string)($options['output'] ?? ''),
            $template,
            (bool)($options['force'] ?? false)
        );

        return [
            'payload' => [
                'status' => 'template',
                'source_scope' => 'ctrip_ota_channel',
                'source_policy' => $suggestionId > 0
                    ? 'review_decision_template_from_pending_suggestion'
                    : 'review_decision_template_without_pending_suggestion',
                'auto_write_ota' => false,
                'suggestion_id' => $suggestionId > 0 ? $suggestionId : null,
                'output_file' => $outputFile === [] ? null : $outputFile,
                'template' => $template,
            ],
            'exit_code' => 0,
        ];
    }

    $checks = [];
    $file = (string)($options['file'] ?? '');
    $date = (string)($options['date'] ?? date('Y-m-d'));
    $hotelId = isset($options['hotel_id']) && $options['hotel_id'] !== null ? (int)$options['hotel_id'] : null;
    $execute = (bool)($options['execute'] ?? false);
    $createIntentRequested = (bool)($options['create_intent'] ?? false);
    $userId = (int)($options['user_id'] ?? 0);
    $manageTransaction = (bool)($options['manage_transaction'] ?? true);
    $fileHash = is_file($file) ? (hash_file('sha256', $file) ?: '') : '';
    $payload = ctrip_review_decision_read_json_file($file);
    $decision = ctrip_review_decision_extract_decision($payload);

    $businessDate = ctrip_review_decision_text($payload['business_date'] ?? ($payload['date'] ?? $date), 20);
    $platform = strtolower(trim((string)($payload['platform'] ?? ($decision['platform'] ?? ''))));
    $sourceScope = strtolower(trim((string)($payload['source_scope'] ?? ($decision['source_scope'] ?? ''))));
    $autoWriteOta = filter_var($payload['auto_write_ota'] ?? ($decision['auto_write_ota'] ?? false), FILTER_VALIDATE_BOOL);
    $action = strtolower(trim((string)($decision['action'] ?? '')));
    $suggestionId = (int)($decision['suggestion_id'] ?? $payload['suggestion_id'] ?? 0);
    $remark = ctrip_review_decision_text($decision['remark'] ?? $payload['remark'] ?? '', 500);
    $approvedPrice = ctrip_review_decision_money($decision['approved_price'] ?? $decision['target_price'] ?? null);
    $createIntent = $createIntentRequested
        || filter_var($decision['create_execution_intent_after_approval'] ?? false, FILTER_VALIDATE_BOOL);
    $executionIntent = ctrip_review_decision_map($decision['execution_intent'] ?? []);
    $operationRoomTypeKey = trim((string)($executionIntent['room_type_key'] ?? ''));
    $operationRatePlanKey = trim((string)($executionIntent['rate_plan_key'] ?? ''));
    $placeholderPaths = ctrip_review_decision_placeholder_paths($payload);
    $forbiddenHits = ctrip_review_decision_forbidden_value_hits($payload);

    ctrip_review_decision_check(
        $checks,
        'file_scope_ctrip',
        $businessDate === $date
            && $platform === 'ctrip'
            && $sourceScope === 'ctrip_ota_channel'
            && $autoWriteOta === false,
        'Review decision file must be scoped to the requested Ctrip OTA channel and forbid OTA writes.',
        [
            'business_date' => $businessDate,
            'expected_date' => $date,
            'platform' => $platform,
            'source_scope' => $sourceScope,
            'auto_write_ota' => $autoWriteOta,
        ]
    );
    ctrip_review_decision_check(
        $checks,
        'file_has_no_placeholders',
        $placeholderPaths === [],
        'Review decision file must not contain template placeholders.',
        ['placeholder_paths' => $placeholderPaths]
    );
    ctrip_review_decision_check(
        $checks,
        'file_contains_no_meituan_values',
        $forbiddenHits === [],
        'Review decision file must not include non-Ctrip channel values.',
        ['hits' => $forbiddenHits]
    );
    ctrip_review_decision_check(
        $checks,
        'review_action_supported',
        in_array($action, ['approve', 'approve_with_changes', 'reject'], true),
        'Review action must be approve, approve_with_changes, or reject.',
        ['action' => $action]
    );
    ctrip_review_decision_check(
        $checks,
        'suggestion_id_present',
        $suggestionId > 0,
        'Review decision must target a pending price_suggestions row.',
        ['suggestion_id' => $suggestionId]
    );
    ctrip_review_decision_check(
        $checks,
        'approve_action_price_unambiguous',
        $action !== 'approve' || $approvedPrice === null,
        'Use approve_with_changes when the operator supplies an approved_price.',
        ['action' => $action, 'approved_price' => $approvedPrice]
    );
    ctrip_review_decision_check(
        $checks,
        'approve_with_changes_price_present',
        $action !== 'approve_with_changes' || $approvedPrice !== null,
        'approve_with_changes requires an approved_price.',
        ['action' => $action, 'approved_price' => $approvedPrice]
    );
    ctrip_review_decision_check(
        $checks,
        'material_change_has_remark',
        !in_array($action, ['approve_with_changes', 'reject'], true) || $remark !== '',
        'approve_with_changes and reject require an operator remark.',
        ['action' => $action, 'remark_present' => $remark !== '']
    );
    ctrip_review_decision_check(
        $checks,
        'reject_does_not_create_intent',
        $action !== 'reject' || !$createIntent,
        'Rejected suggestions cannot create operation execution intent.',
        ['action' => $action, 'create_intent' => $createIntent]
    );
    ctrip_review_decision_check(
        $checks,
        'operation_intent_keys_present',
        !$createIntent || ($operationRoomTypeKey !== '' && $operationRatePlanKey !== ''),
        'Creating an operation execution intent from a Ctrip price decision requires room_type_key and rate_plan_key.',
        [
            'create_intent' => $createIntent,
            'room_type_key_present' => $operationRoomTypeKey !== '',
            'rate_plan_key_present' => $operationRatePlanKey !== '',
        ]
    );

    $preDbFailures = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'passed'));
    if ($preDbFailures !== []) {
        return [
            'payload' => [
                'status' => 'failed',
                'mode' => 'review_decision_lint_failed',
                'scope' => [
                    'business_date' => $date,
                    'platform' => 'ctrip',
                    'source_scope' => 'ctrip_ota_channel',
                    'source_policy' => 'review_decision_lint_no_write',
                    'database_written' => false,
                    'auto_write_ota' => false,
                ],
                'summary' => [
                    'input_file_sha256' => $fileHash,
                    'suggestion_id' => $suggestionId,
                    'action' => $action,
                ],
                'checks' => $checks,
            ],
            'exit_code' => 1,
        ];
    }

    $suggestion = PriceSuggestion::find($suggestionId);
    $suggestionArray = $suggestion ? $suggestion->toArray() : [];
    $suggestionHotelId = (int)($suggestionArray['hotel_id'] ?? 0);
    $suggestionDate = ctrip_review_decision_text($suggestionArray['suggestion_date'] ?? '', 20);
    $suggestionStatus = (int)($suggestionArray['status'] ?? 0);
    $suggestionChannels = $suggestionArray === [] ? [] : ctrip_review_decision_suggestion_channels($suggestionArray);
    $suggestionSourceScope = $suggestionArray === [] ? '' : ctrip_review_decision_suggestion_source_scope($suggestionArray);
    $minPrice = ctrip_review_decision_money($suggestionArray['min_price'] ?? null);
    $maxPrice = ctrip_review_decision_money($suggestionArray['max_price'] ?? null);

    ctrip_review_decision_check(
        $checks,
        'suggestion_exists',
        $suggestion !== null,
        'Target price suggestion must exist.',
        ['suggestion_id' => $suggestionId]
    );
    ctrip_review_decision_check(
        $checks,
        'suggestion_matches_requested_scope',
        $suggestion !== null
            && $suggestionDate === $date
            && ($hotelId === null || $suggestionHotelId === $hotelId),
        'Target suggestion must match requested business date and hotel scope.',
        [
            'suggestion_date' => $suggestionDate,
            'expected_date' => $date,
            'suggestion_hotel_id' => $suggestionHotelId,
            'expected_hotel_id' => $hotelId,
        ]
    );
    ctrip_review_decision_check(
        $checks,
        'suggestion_pending_review',
        $suggestion !== null && $suggestionStatus === PriceSuggestion::STATUS_PENDING,
        'Target suggestion must still be pending manual review.',
        ['status_code' => $suggestionStatus]
    );
    ctrip_review_decision_check(
        $checks,
        'suggestion_source_ctrip_only',
        $suggestion !== null
            && $suggestionSourceScope === 'ctrip_ota_channel'
            && $suggestionChannels === ['ctrip'],
        'Target suggestion must carry Ctrip-only source evidence.',
        [
            'source_scope' => $suggestionSourceScope,
            'source_channels' => $suggestionChannels,
        ]
    );
    ctrip_review_decision_check(
        $checks,
        'approved_price_within_guard',
        $action !== 'approve_with_changes'
            || ($approvedPrice !== null
                && ($minPrice === null || $approvedPrice >= $minPrice)
                && ($maxPrice === null || $approvedPrice <= $maxPrice)),
        'Approved changed price must stay within min_price and max_price.',
        [
            'approved_price' => $approvedPrice,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
        ]
    );

    $dbFailures = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'passed'));
    if ($dbFailures !== []) {
        return [
            'payload' => [
                'status' => 'failed',
                'mode' => 'review_decision_gate_failed',
                'scope' => [
                    'business_date' => $date,
                    'platform' => 'ctrip',
                    'source_scope' => 'ctrip_ota_channel',
                    'source_policy' => 'review_decision_gate_no_write',
                    'database_written' => false,
                    'auto_write_ota' => false,
                ],
                'summary' => [
                    'input_file_sha256' => $fileHash,
                    'suggestion_id' => $suggestionId,
                    'action' => $action,
                    'suggestion_scope' => [
                        'hotel_id' => $suggestionHotelId,
                        'suggestion_date' => $suggestionDate,
                        'status_code' => $suggestionStatus,
                        'source_scope' => $suggestionSourceScope,
                        'source_channels' => $suggestionChannels,
                    ],
                ],
                'checks' => $checks,
            ],
            'exit_code' => 1,
        ];
    }

    $transactionStarted = false;
    $review = [];
    $intent = null;
    $rolledBack = false;
    $committed = false;

    try {
        if ($manageTransaction) {
            Db::startTrans();
            $transactionStarted = true;
        }

        $state = ctrip_review_decision_build_manual_review($suggestionArray, $action, $userId, $remark, $approvedPrice);
        $suggestion->status = ctrip_review_decision_status_after($action);
        $suggestion->applied_by = $userId;
        $suggestion->remark = $remark;
        $suggestion->factors = $state['factors'];
        $suggestion->save();

        $fresh = PriceSuggestion::find($suggestionId);
        $freshArray = $fresh ? $fresh->toArray() : $suggestion->toArray();
        $freshFactors = ctrip_review_decision_decode_map($freshArray['factors'] ?? []);
        $review = $state['review'];
        if ($createIntent && in_array($action, ['approve', 'approve_with_changes'], true)) {
            $intent = ctrip_review_decision_create_intent($freshArray, $decision, $userId);
        }

        ctrip_review_decision_check(
            $checks,
            'manual_review_written_locally',
            (int)($freshArray['status'] ?? 0) === ctrip_review_decision_status_after($action)
                && is_array($freshFactors['manual_review'] ?? null),
            'Review updates the local price_suggestions manual-review state.',
            [
                'status_after' => (int)($freshArray['status'] ?? 0),
                'manual_review_storage' => 'price_suggestions.factors.manual_review_versions',
            ]
        );
        ctrip_review_decision_check(
            $checks,
            'review_keeps_no_ota_write',
            ($review['auto_write_ota'] ?? true) === false
                && ($review['local_price_updated'] ?? true) === false
                && ($review['ota_write'] ?? true) === false,
            'Manual review must not write OTA prices or local room-type prices.',
            $review
        );
        ctrip_review_decision_check(
            $checks,
            'execution_intent_policy_respected',
            !$createIntent
                || ($intent !== null
                    && (string)($intent['source_module'] ?? '') === 'price_suggestion'
                    && (int)($intent['source_record_id'] ?? 0) === $suggestionId
                    && strtolower((string)($intent['platform'] ?? '')) === 'ctrip'),
            'Execution intent is optional, post-approval only, and Ctrip-scoped.',
            [
                'requested' => $createIntent,
                'intent_id' => is_array($intent) ? (int)($intent['id'] ?? 0) : null,
                'source_module' => is_array($intent) ? ($intent['source_module'] ?? null) : null,
                'platform' => is_array($intent) ? ($intent['platform'] ?? null) : null,
            ]
        );

        if ($manageTransaction) {
            if ($execute) {
                Db::commit();
                $committed = true;
            } else {
                Db::rollback();
                $rolledBack = true;
            }
        }
    } catch (Throwable $error) {
        if ($transactionStarted && $manageTransaction) {
            try {
                Db::rollback();
            } catch (Throwable) {
            }
        }
        throw $error;
    }

    $failures = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'passed'));
    $status = $failures === [] ? 'passed' : 'failed';

    return [
        'payload' => [
            'status' => $status,
            'mode' => $execute ? 'execute_review_decision' : 'validate_review_decision_rollback',
            'scope' => [
                'business_date' => $date,
                'platform' => 'ctrip',
                'enabled_channels' => ['ctrip'],
                'hotel_id' => $suggestionHotelId,
                'source_scope' => 'ctrip_ota_channel',
                'source_policy' => $execute
                    ? 'operator_review_decision_explicit_execute'
                    : 'operator_review_decision_validate_only_rollback',
                'database_written' => $execute,
                'committed' => $committed,
                'rolled_back' => $rolledBack,
                'auto_write_ota' => false,
            ],
            'summary' => [
                'input_file_sha256' => $fileHash,
                'suggestion_id' => $suggestionId,
                'action' => $action,
                'approved_price' => $review['approved_price'] ?? null,
                'manual_review_storage' => 'price_suggestions.factors.manual_review_versions',
                'create_execution_intent_after_approval' => $createIntent,
                'execution_intent' => is_array($intent) ? [
                    'id' => (int)($intent['id'] ?? 0),
                    'existing' => (bool)($intent['existing'] ?? false),
                    'status' => $intent['status'] ?? null,
                    'source_module' => $intent['source_module'] ?? null,
                    'source_record_id' => $intent['source_record_id'] ?? null,
                    'platform' => $intent['platform'] ?? null,
                    'target_page' => 'ops-track',
                    'target_action' => 'approve_intent',
                    'auto_write_ota' => false,
                ] : null,
                'next_action' => $action === 'reject'
                    ? 'No operation execution is allowed for a rejected Ctrip AI pricing suggestion.'
                    : ($createIntent
                        ? 'Review or approve the operation execution intent, then record manual OTA execution and ROI evidence.'
                        : 'Create operation execution intent only after this approved AI decision is accepted for operations.'),
            ],
            'checks' => $checks,
        ],
        'exit_code' => $status === 'passed' ? 0 : 1,
    ];
}

/**
 * @param array<int, string> $argv
 */
function ctrip_review_decision_cli(array $argv): void
{
    $root = dirname(__DIR__);
    try {
        $options = ctrip_review_decision_parse_args($argv);
        $autoload = $root . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            ctrip_review_decision_finish([
                'status' => 'failed',
                'error' => 'vendor/autoload.php is missing.',
            ], 1);
        }

        require $autoload;
        $app = new App($root);
        $app->initialize();
        $result = ctrip_review_decision_run($options);

        ctrip_review_decision_finish($result['payload'], $result['exit_code']);
    } catch (Throwable $error) {
        ctrip_review_decision_finish([
            'status' => 'failed',
            'error' => [
                'type' => get_class($error),
                'message' => $error->getMessage(),
            ],
        ], 1);
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ctrip_review_decision_cli($argv);
}
