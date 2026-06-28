<?php
declare(strict_types=1);

use app\model\PriceSuggestion;
use app\service\RevenueAiOverviewService;
use think\App;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{date:string,hotel_id:int|null,format:string,limit:int}
 */
function ctrip_pending_review_parse_args(array $argv): array
{
    $options = [
        'date' => date('Y-m-d'),
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'format' => 'json',
        'limit' => '20',
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

    if (!ctype_digit($options['limit']) || (int)$options['limit'] <= 0 || (int)$options['limit'] > 100) {
        throw new InvalidArgumentException('Invalid --limit, expected an integer between 1 and 100.');
    }

    return [
        'date' => $options['date'],
        'hotel_id' => $hotelId,
        'format' => $format,
        'limit' => (int)$options['limit'],
    ];
}

/**
 * @param mixed $value
 * @return array<string, mixed>
 */
function ctrip_pending_review_map(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @param mixed $value
 * @return array<int, mixed>
 */
function ctrip_pending_review_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @param array<int, mixed> $values
 * @return array<int, int>
 */
function ctrip_pending_review_positive_ints(array $values): array
{
    return array_values(array_filter(
        array_map('intval', $values),
        static fn(int $value): bool => $value > 0
    ));
}

/**
 * @return array<string, bool>
 */
function ctrip_pending_review_table_columns(string $table): array
{
    try {
        $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    } catch (Throwable) {
        return [];
    }

    $columns = [];
    foreach ($rows as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }
    return $columns;
}

/**
 * @return array<int, string>
 */
function ctrip_pending_review_required_columns(): array
{
    return [
        'id',
        'hotel_id',
        'room_type_id',
        'status',
        'suggestion_date',
        'current_price',
        'suggested_price',
        'min_price',
        'max_price',
        'factors',
    ];
}

/**
 * @param mixed $value
 * @return array<string, mixed>
 */
function ctrip_pending_review_decode_json_map(mixed $value): array
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

function ctrip_pending_review_float(mixed $value): ?float
{
    if (is_string($value)) {
        $value = preg_replace('/[^\d.\-]/', '', $value) ?? '';
    }
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    return round((float)$value, 2);
}

function ctrip_pending_review_money(?float $value): string
{
    if ($value === null) {
        return '--';
    }
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' CNY';
}

function ctrip_pending_review_signed_money(?float $value): string
{
    if ($value === null) {
        return '--';
    }
    return ($value > 0 ? '+' : '') . ctrip_pending_review_money($value);
}

function ctrip_pending_review_signed_percent(?float $value): string
{
    if ($value === null) {
        return '--';
    }
    return ($value > 0 ? '+' : '') . rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
}

function ctrip_pending_review_text(mixed $value, int $limit = 160): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    return mb_substr($text, 0, $limit);
}

/**
 * @param array<string, mixed> $overview
 */
function ctrip_pending_review_resolve_hotel_id(?int $requestedHotelId, array $overview): ?int
{
    if ($requestedHotelId !== null && $requestedHotelId > 0) {
        return $requestedHotelId;
    }

    $reviewQueue = ctrip_pending_review_map($overview['review_queue'] ?? []);
    $reviewHotelId = (int)($reviewQueue['hotel_id'] ?? 0);
    if ($reviewHotelId > 0) {
        return $reviewHotelId;
    }

    $preflight = ctrip_pending_review_map($overview['pricing_generation_preflight'] ?? []);
    $targetHotelIds = ctrip_pending_review_positive_ints(ctrip_pending_review_list($preflight['target_hotel_ids'] ?? []));
    if ($targetHotelIds !== []) {
        return $targetHotelIds[0];
    }

    $overviewHotelId = (int)($overview['hotel_id'] ?? 0);
    return $overviewHotelId > 0 ? $overviewHotelId : null;
}

/**
 * @param array<string, bool> $columns
 * @return array<int, array<string, mixed>>
 */
function ctrip_pending_review_load_rows(string $date, ?int $hotelId, int $limit, array $columns): array
{
    if ($columns === []) {
        return [];
    }

    $fields = array_values(array_intersect([
        'id',
        'hotel_id',
        'room_type_id',
        'demand_forecast_id',
        'suggestion_type',
        'status',
        'suggestion_date',
        'current_price',
        'suggested_price',
        'min_price',
        'max_price',
        'confidence_score',
        'competitor_data',
        'factors',
        'reason',
        'remark',
        'risk_level',
        'create_time',
        'update_time',
        'applied_time',
    ], array_keys($columns)));

    $query = Db::name('price_suggestions')
        ->field(implode(',', $fields))
        ->where('suggestion_date', $date)
        ->where('status', PriceSuggestion::STATUS_PENDING);
    if ($hotelId !== null) {
        $query->where('hotel_id', $hotelId);
    }
    if (isset($columns['update_time'])) {
        $query->order('update_time', 'desc');
    }
    if (isset($columns['id'])) {
        $query->order('id', 'desc');
    }

    return $query->limit($limit)->select()->toArray();
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, string>
 */
function ctrip_pending_review_room_names(array $rows): array
{
    $roomTypeColumns = ctrip_pending_review_table_columns('room_types');
    if (!isset($roomTypeColumns['id'], $roomTypeColumns['name'])) {
        return [];
    }

    $ids = ctrip_pending_review_positive_ints(array_map(
        static fn(array $row): int => (int)($row['room_type_id'] ?? 0),
        $rows
    ));
    if ($ids === []) {
        return [];
    }

    try {
        $names = Db::name('room_types')->whereIn('id', $ids)->column('name', 'id');
    } catch (Throwable) {
        return [];
    }

    $result = [];
    foreach ($names as $id => $name) {
        $result[(int)$id] = (string)$name;
    }
    return $result;
}

function ctrip_pending_review_type_label(int $type): string
{
    return match ($type) {
        1 => 'dynamic_pricing',
        2 => 'competitor_follow',
        3 => 'event_driven',
        4 => 'forecast_driven',
        default => 'unknown',
    };
}

/**
 * @param array<string, mixed> $row
 * @param array<int, string> $roomNames
 * @return array<string, mixed>
 */
function ctrip_pending_review_normalize_item(array $row, array $roomNames): array
{
    $id = (int)($row['id'] ?? 0);
    $roomTypeId = (int)($row['room_type_id'] ?? 0);
    $currentPrice = ctrip_pending_review_float($row['current_price'] ?? null);
    $suggestedPrice = ctrip_pending_review_float($row['suggested_price'] ?? null);
    $minPrice = ctrip_pending_review_float($row['min_price'] ?? null);
    $maxPrice = ctrip_pending_review_float($row['max_price'] ?? null);
    $confidence = ctrip_pending_review_float($row['confidence_score'] ?? null);
    $priceDelta = $currentPrice !== null && $suggestedPrice !== null
        ? round($suggestedPrice - $currentPrice, 2)
        : null;
    $priceDeltaPercent = $priceDelta !== null && $currentPrice !== null && $currentPrice > 0
        ? round(($priceDelta / $currentPrice) * 100, 2)
        : null;
    $factors = ctrip_pending_review_decode_json_map($row['factors'] ?? []);
    $signals = ctrip_pending_review_map($factors['signals'] ?? []);
    $dataGaps = ctrip_pending_review_list($signals['data_gaps'] ?? ($factors['data_gaps'] ?? []));
    $missingFields = [];
    foreach ([
        'current_price' => $currentPrice,
        'suggested_price' => $suggestedPrice,
        'min_price' => $minPrice,
        'max_price' => $maxPrice,
    ] as $field => $value) {
        if ($value === null) {
            $missingFields[] = $field;
        }
    }

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
        'id' => $id,
        'hotel_id' => (int)($row['hotel_id'] ?? 0),
        'room_type_id' => $roomTypeId,
        'room_type_name' => $roomNames[$roomTypeId] ?? '',
        'demand_forecast_id' => (int)($row['demand_forecast_id'] ?? 0),
        'suggestion_date' => ctrip_pending_review_text($row['suggestion_date'] ?? ''),
        'suggestion_type' => (int)($row['suggestion_type'] ?? 0),
        'suggestion_type_label' => ctrip_pending_review_type_label((int)($row['suggestion_type'] ?? 0)),
        'status' => 'pending_review',
        'current_price' => $currentPrice,
        'current_price_display' => ctrip_pending_review_money($currentPrice),
        'suggested_price' => $suggestedPrice,
        'suggested_price_display' => ctrip_pending_review_money($suggestedPrice),
        'min_price' => $minPrice,
        'min_price_display' => ctrip_pending_review_money($minPrice),
        'max_price' => $maxPrice,
        'max_price_display' => ctrip_pending_review_money($maxPrice),
        'price_delta' => $priceDelta,
        'price_delta_display' => ctrip_pending_review_signed_money($priceDelta),
        'price_delta_percent' => $priceDeltaPercent,
        'price_delta_percent_display' => ctrip_pending_review_signed_percent($priceDeltaPercent),
        'confidence_score' => $confidence,
        'risk_level' => ctrip_pending_review_text($factors['risk_level'] ?? ($row['risk_level'] ?? ''), 40),
        'primary_signal_count' => (int)($factors['primary_signal_count'] ?? 0),
        'data_gaps' => $dataGaps,
        'missing_fields' => $missingFields,
        'price_guard_status' => $priceGuardStatus,
        'reason' => ctrip_pending_review_text($row['reason'] ?? ($row['remark'] ?? ''), 180),
        'review_endpoint' => '/api/revenue-ai/price-suggestions/' . $id . '/review',
        'execution_intent_endpoint_after_approval' => '/api/revenue-ai/price-suggestions/' . $id . '/execution-intent',
        'allowed_manual_actions' => ['approve', 'approve_with_changes', 'reject'],
        'review_checklist' => [
            'confirm_source_scope_is_ctrip_ota_channel',
            'confirm_suggested_or_approved_price_within_min_max',
            'add_manual_remark_for_approve_with_changes_or_reject',
            'do_not_write_ota_rate_from_review',
            'create_operation_execution_intent_only_after_approved_status',
        ],
        'forbidden_actions' => [
            'apply_price',
            'ota_write',
            'update_room_type_base_price',
            'operation_execution_before_review',
            'investment_decision_before_operation_roi',
        ],
        'manual_review_required' => true,
        'auto_write_ota' => false,
        'read_only' => true,
        'last_success_at' => ctrip_pending_review_text($row['update_time'] ?? ($row['create_time'] ?? ''), 40),
    ];
}

/**
 * @return array<string, string>
 */
function ctrip_pending_review_upstream_commands(string $date, ?int $hotelId): array
{
    $hotelArg = $hotelId === null ? '' : ' --hotel-id=' . $hotelId;
    return [
        'operator_packet' => 'npm.cmd run report:revenue-ai-ctrip-pricing-operator-packet -- --date=' . $date . $hotelArg . ' --format=markdown',
        'export_operator_bundle' => 'npm.cmd run export:revenue-ai-ctrip-operator-bundle -- --date=' . $date . $hotelArg . ' --output-dir=<operator-bundle-dir>',
        'export_template' => 'npm.cmd run export:revenue-ai-ctrip-pricing-template -- --date=' . $date . $hotelArg . ' --output=<draft-json-path>',
        'pre_execute_gate' => 'npm.cmd run verify:revenue-ai-ctrip-pricing-file -- --file=<filled-json-path> --date=' . $date . $hotelArg,
        'gate_then_execute_and_generate_pending_review' => 'npm.cmd run run:revenue-ai-ctrip-pricing-file-to-pending-review -- --file=<filled-json-path> --date=' . $date . $hotelArg . ' --execute=1 --generate=1',
        'verify_pending_review_packet' => 'npm.cmd run verify:revenue-ai-ctrip-pending-review-packet -- --date=' . $date . $hotelArg,
        'export_review_decision_template' => 'npm.cmd run export:revenue-ai-ctrip-review-template -- --date=' . $date . $hotelArg . ' --suggestion-id=<pending-suggestion-id> --output=<review-decision-json-path>',
        'validate_review_decision' => 'npm.cmd run run:revenue-ai-ctrip-review-decision -- --file=<review-decision-json-path> --date=' . $date . $hotelArg,
        'execute_review_decision' => 'npm.cmd run run:revenue-ai-ctrip-review-decision -- --file=<review-decision-json-path> --date=' . $date . $hotelArg . ' --execute=1',
        'execute_review_decision_and_create_operation_intent' => 'npm.cmd run run:revenue-ai-ctrip-review-decision -- --file=<review-decision-json-path> --date=' . $date . $hotelArg . ' --execute=1 --create-intent=1',
        'verify_review_decision' => 'npm.cmd run verify:revenue-ai-ctrip-review-decision -- --date=' . $date . $hotelArg,
        'verify_current_scope' => 'npm.cmd run verify:revenue-ai-ctrip-scope -- --date=' . $date . $hotelArg,
    ];
}

/**
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed> $details
 */
function ctrip_pending_review_check(array &$checks, string $code, bool $ok, string $message, array $details = []): void
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
 * @param array<string, mixed> $payload
 */
function ctrip_pending_review_render_markdown(array $payload): string
{
    $scope = ctrip_pending_review_map($payload['scope'] ?? []);
    $readiness = ctrip_pending_review_map($payload['review_readiness'] ?? []);
    $queue = ctrip_pending_review_map($payload['review_queue'] ?? []);
    $items = ctrip_pending_review_list($payload['pending_items'] ?? []);
    $commands = ctrip_pending_review_map($payload['upstream_next_commands'] ?? []);
    $contract = ctrip_pending_review_map($payload['review_contract'] ?? []);

    $lines = [];
    $lines[] = '# Ctrip Revenue AI Pending Review Packet';
    $lines[] = '';
    $lines[] = '- status: `' . (string)($payload['status'] ?? 'unknown') . '`';
    $lines[] = '- business_date: `' . (string)($scope['business_date'] ?? '') . '`';
    $lines[] = '- platform: `ctrip`';
    $lines[] = '- source_scope: `ctrip_ota_channel`';
    $lines[] = '- hotel_id: `' . (string)($scope['hotel_id'] ?? 'unknown') . '`';
    $lines[] = '- review_readiness: `' . (string)($readiness['status'] ?? 'unknown') . '`';
    $lines[] = '- pending_count: `' . (string)($queue['pending_count'] ?? 0) . '`';
    $lines[] = '- database_written: `false`';
    $lines[] = '- auto_write_ota: `false`';
    $lines[] = '';
    $lines[] = '## Review Queue';
    $lines[] = '';
    $lines[] = '- source_table: `' . (string)($queue['source_table'] ?? 'price_suggestions') . '`';
    $lines[] = '- status: `' . (string)($queue['status'] ?? 'unknown') . '`';
    $lines[] = '- reason: `' . (string)($queue['reason'] ?? 'unknown') . '`';
    $lines[] = '- total_count: `' . (string)($queue['total_count'] ?? 0) . '`';
    $lines[] = '- approved_or_applied_count: `' . (string)($queue['approved_or_applied_count'] ?? 0) . '`';
    $lines[] = '';
    $lines[] = '## Review Contract';
    $lines[] = '';
    $lines[] = '- allowed_manual_actions: `' . implode(', ', array_map('strval', ctrip_pending_review_list($contract['allowed_manual_actions'] ?? []))) . '`';
    $lines[] = '- review_endpoint_base: `' . (string)($contract['review_endpoint_base'] ?? '') . '`';
    $lines[] = '- execution_intent_endpoint_base: `' . (string)($contract['execution_intent_endpoint_base'] ?? '') . '`';
    $lines[] = '- forbidden_actions: `' . implode(', ', array_map('strval', ctrip_pending_review_list($contract['forbidden_actions'] ?? []))) . '`';
    $lines[] = '';
    $lines[] = '## Pending Items';
    if ($items === []) {
        $lines[] = '';
        $lines[] = '- No pending Ctrip AI price suggestion is present in the current real database.';
    } else {
        $lines[] = '';
        $lines[] = '| id | room_type | current | suggested | delta | guard | confidence | review_endpoint |';
        $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
        foreach ($items as $item) {
            $item = ctrip_pending_review_map($item);
            $roomLabel = (string)($item['room_type_name'] ?? '') !== ''
                ? (string)$item['room_type_name']
                : ('#' . (string)($item['room_type_id'] ?? 0));
            $lines[] = '| `' . (string)($item['id'] ?? 0) . '` | `' . $roomLabel . '` | `'
                . (string)($item['current_price_display'] ?? '--') . '` | `'
                . (string)($item['suggested_price_display'] ?? '--') . '` | `'
                . (string)($item['price_delta_display'] ?? '--') . '` | `'
                . (string)($item['price_guard_status'] ?? 'unknown') . '` | `'
                . (string)($item['confidence_score'] ?? '--') . '` | `'
                . (string)($item['review_endpoint'] ?? '') . '` |';
        }
    }
    $lines[] = '';
    $lines[] = '## Upstream Next Commands';
    foreach ($commands as $key => $command) {
        $lines[] = '';
        $lines[] = '1. `' . (string)$key . '`';
        $lines[] = '   ```powershell';
        $lines[] = '   ' . (string)$command;
        $lines[] = '   ```';
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_pending_review_finish(array $payload, int $exitCode, string $format): void
{
    if ($format === 'markdown') {
        echo ctrip_pending_review_render_markdown($payload);
    } else {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    exit($exitCode);
}

/**
 * @param array{date:string,hotel_id:int|null,format:string,limit:int} $options
 * @return array{payload:array<string, mixed>,exit_code:int}
 */
function ctrip_pending_review_build_packet(array $options): array
{
    $overviewFilters = [
        'business_date' => $options['date'],
        'platform' => 'ctrip',
        'enabled_channels' => ['ctrip'],
    ];
    if ($options['hotel_id'] !== null) {
        $overviewFilters['hotel_id'] = $options['hotel_id'];
    }
    $overview = (new RevenueAiOverviewService())->overview($overviewFilters);
    $hotelId = ctrip_pending_review_resolve_hotel_id($options['hotel_id'], $overview);
    if ($options['hotel_id'] === null && $hotelId !== null) {
        $overviewFilters['hotel_id'] = $hotelId;
        $overview = (new RevenueAiOverviewService())->overview($overviewFilters);
    }

    $reviewQueue = ctrip_pending_review_map($overview['review_queue'] ?? []);
    $preflight = ctrip_pending_review_map($overview['pricing_generation_preflight'] ?? []);
    $pricingReadiness = ctrip_pending_review_map($overview['pricing_readiness'] ?? []);
    $reviewContractFromOverview = ctrip_pending_review_map($pricingReadiness['ai_decision_review_contract'] ?? []);
    $sourceChannels = ctrip_pending_review_list($overview['source_channels'] ?? []);
    $columns = ctrip_pending_review_table_columns('price_suggestions');
    $missingColumns = array_values(array_filter(
        ctrip_pending_review_required_columns(),
        static fn(string $field): bool => !isset($columns[$field])
    ));

    $rows = [];
    $rowReadError = '';
    if ($columns !== [] && $missingColumns === []) {
        try {
            $rows = ctrip_pending_review_load_rows($options['date'], $hotelId, $options['limit'], $columns);
        } catch (Throwable $error) {
            $rowReadError = $error->getMessage();
        }
    }
    $roomNames = ctrip_pending_review_room_names($rows);
    $pendingItems = array_map(
        static fn(array $row): array => ctrip_pending_review_normalize_item($row, $roomNames),
        $rows
    );

    $queueStatus = (string)($reviewQueue['status'] ?? 'unknown');
    $pendingCount = (int)($reviewQueue['pending_count'] ?? count($pendingItems));
    $readinessStatus = match (true) {
        $columns === [] => 'unavailable_price_suggestions_missing',
        $missingColumns !== [] => 'unavailable_price_suggestions_required_fields_missing',
        $rowReadError !== '' => 'unavailable_price_suggestions_read_failed',
        $pendingCount > 0 && $pendingItems !== [] => 'pending_review',
        $pendingCount > 0 => 'pending_review_ids_only',
        $queueStatus === 'reviewed' => 'reviewed_no_pending',
        default => 'blocked_empty_queue',
    };

    $checks = [];
    ctrip_pending_review_check(
        $checks,
        'overview_source_channels_exact_ctrip',
        $sourceChannels === ['ctrip'],
        'Revenue AI overview must stay scoped to Ctrip only.',
        ['source_channels' => $sourceChannels]
    );
    ctrip_pending_review_check(
        $checks,
        'review_queue_loaded_from_price_suggestions',
        (string)($reviewQueue['source_table'] ?? '') === 'price_suggestions'
            && in_array($queueStatus, ['empty', 'pending_review', 'reviewed', 'missing', 'failed'], true),
        'Review queue must be loaded from the local price_suggestions table.',
        ['status' => $queueStatus, 'reason' => $reviewQueue['reason'] ?? null]
    );
    ctrip_pending_review_check(
        $checks,
        'price_suggestions_columns_available',
        $columns !== [] && $missingColumns === [],
        'Pending review packet requires price_suggestions review columns.',
        ['missing_columns' => $missingColumns]
    );
    ctrip_pending_review_check(
        $checks,
        'pending_rows_match_review_queue',
        $pendingCount === 0 || $pendingItems !== [],
        'If overview reports pending suggestions, the packet must include at least one review item.',
        ['pending_count' => $pendingCount, 'item_count' => count($pendingItems), 'row_read_error' => $rowReadError]
    );
    ctrip_pending_review_check(
        $checks,
        'pending_items_have_no_meituan',
        !str_contains(strtolower(json_encode($pendingItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), 'meituan'),
        'Pending review items must not contain non-Ctrip channel evidence.',
        ['item_count' => count($pendingItems)]
    );
    ctrip_pending_review_check(
        $checks,
        'manual_review_gate_no_auto_apply',
        ($reviewContractFromOverview['auto_apply_ai_advice'] ?? false) === false
            && ($reviewContractFromOverview['operation_intake_allowed'] ?? false) === false,
        'AI decisions must remain manual-review gated before operation intake.',
        [
            'auto_apply_ai_advice' => $reviewContractFromOverview['auto_apply_ai_advice'] ?? null,
            'operation_intake_allowed' => $reviewContractFromOverview['operation_intake_allowed'] ?? null,
        ]
    );
    ctrip_pending_review_check(
        $checks,
        'report_is_read_only',
        true,
        'This command only reads overview, price_suggestions, and room_types metadata; it writes no database rows and no OTA prices.',
        ['database_written' => false, 'auto_write_ota' => false]
    );

    $failed = array_values(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? '') !== 'passed'));
    $payload = [
        'status' => $failed === [] ? 'passed' : 'failed',
        'scope' => [
            'business_date' => $options['date'],
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $hotelId,
            'source_scope' => 'ctrip_ota_channel',
            'source_policy' => 'read_only_pending_review_packet_no_write',
            'database_written' => false,
            'auto_write_ota' => false,
            'meituan_scope_included' => false,
            'manual_review_required' => true,
        ],
        'review_readiness' => [
            'status' => $readinessStatus,
            'reason' => $pendingCount > 0 ? 'price_suggestions_pending_review' : (string)($reviewQueue['reason'] ?? 'price_suggestions_empty'),
            'can_review_pending_items' => $pendingItems !== [],
            'pending_count_from_overview' => $pendingCount,
            'pending_item_count' => count($pendingItems),
            'limit' => $options['limit'],
            'current_preflight_status' => $preflight['status'] ?? null,
            'current_preflight_reason' => $preflight['reason'] ?? null,
        ],
        'review_queue' => [
            'status' => $queueStatus,
            'reason' => $reviewQueue['reason'] ?? null,
            'source_table' => $reviewQueue['source_table'] ?? 'price_suggestions',
            'date_basis' => $reviewQueue['date_basis'] ?? 'suggestion_date',
            'business_date' => $reviewQueue['business_date'] ?? $options['date'],
            'hotel_id' => $reviewQueue['hotel_id'] ?? $hotelId,
            'total_count' => $reviewQueue['total_count'] ?? 0,
            'pending_count' => $pendingCount,
            'approved_count' => $reviewQueue['approved_count'] ?? 0,
            'rejected_count' => $reviewQueue['rejected_count'] ?? 0,
            'applied_count' => $reviewQueue['applied_count'] ?? 0,
            'approved_or_applied_count' => $reviewQueue['approved_or_applied_count'] ?? 0,
            'pending_ids' => $reviewQueue['pending_ids'] ?? [],
            'manual_review_required' => true,
            'auto_write_ota' => false,
        ],
        'review_contract' => [
            'status' => $pendingItems !== [] ? 'manual_review_available' : 'manual_review_waiting_for_pending_suggestions',
            'source_table' => 'price_suggestions',
            'review_endpoint_base' => '/api/revenue-ai/price-suggestions/{id}/review',
            'execution_intent_endpoint_base' => '/api/revenue-ai/price-suggestions/{id}/execution-intent',
            'allowed_manual_actions' => ['approve', 'approve_with_changes', 'reject'],
            'required_checks' => [
                'price_suggestion.status must be pending before review.',
                'approved_price must stay within min_price and max_price when using approve_with_changes.',
                'review writes only manual review state under price_suggestions; it must not write OTA prices.',
                'operation execution intent can be created only after approved status.',
            ],
            'forbidden_actions' => [
                'apply_price',
                'ota_write',
                'update_room_type_base_price',
                'operation_execution_before_review',
                'investment_decision_before_operation_roi',
            ],
            'manual_review_required' => true,
            'auto_apply_ai_advice' => false,
            'operation_intake_allowed_before_review' => false,
            'auto_write_ota' => false,
        ],
        'pending_items' => $pendingItems,
        'upstream_next_commands' => ctrip_pending_review_upstream_commands($options['date'], $hotelId),
        'stop_conditions' => [
            'Stop if source_scope is not ctrip_ota_channel.',
            'Stop if pending_items contain non-Ctrip source evidence.',
            'Stop before any OTA price write; this packet is advisory and manual-review only.',
            'Stop before operation execution until a pending suggestion is manually approved.',
            'Stop before investment decision until operation ROI evidence is closed.',
        ],
        'checks' => $checks,
    ];

    return [
        'payload' => $payload,
        'exit_code' => $failed === [] ? 0 : 1,
    ];
}

/**
 * @param array<int, string> $argv
 */
function ctrip_pending_review_cli(array $argv): void
{
    $root = dirname(__DIR__);
    $format = 'json';

    try {
        $options = ctrip_pending_review_parse_args($argv);
        $format = $options['format'];

        $autoload = $root . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            ctrip_pending_review_finish([
                'status' => 'failed',
                'error' => 'vendor/autoload.php is missing.',
            ], 1, $format);
        }

        require $autoload;

        $app = new App($root);
        $app->initialize();
        $result = ctrip_pending_review_build_packet($options);

        ctrip_pending_review_finish($result['payload'], $result['exit_code'], $format);
    } catch (Throwable $error) {
        ctrip_pending_review_finish([
            'status' => 'failed',
            'error' => [
                'type' => get_class($error),
                'message' => $error->getMessage(),
            ],
        ], 1, $format);
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ctrip_pending_review_cli($argv);
}
