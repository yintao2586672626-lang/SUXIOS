<?php
declare(strict_types=1);

use app\model\PriceSuggestion;
use app\model\RoomType;
use app\service\RevenueAiOverviewService;
use think\App;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{date:string,hotel_id:int|null}
 */
function ctrip_pending_review_verify_parse_args(array $argv): array
{
    $options = [
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

    return [
        'date' => $options['date'],
        'hotel_id' => $hotelId,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_pending_review_verify_finish(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

/**
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed> $details
 */
function ctrip_pending_review_verify_check(array &$checks, string $code, bool $ok, string $message, array $details = []): void
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
function ctrip_pending_review_verify_map(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @param mixed $value
 * @return array<int, mixed>
 */
function ctrip_pending_review_verify_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @param array<int, mixed> $values
 * @return array<int, int>
 */
function ctrip_pending_review_verify_positive_ints(array $values): array
{
    return array_values(array_filter(
        array_map('intval', $values),
        static fn(int $value): bool => $value > 0
    ));
}

function ctrip_pending_review_verify_resolve_hotel_id(?int $requestedHotelId, string $businessDate): int
{
    if ($requestedHotelId !== null && $requestedHotelId > 0) {
        return $requestedHotelId;
    }

    $overview = (new RevenueAiOverviewService())->overview([
        'business_date' => $businessDate,
        'platform' => 'ctrip',
        'enabled_channels' => ['ctrip'],
    ]);
    $preflight = ctrip_pending_review_verify_map($overview['pricing_generation_preflight'] ?? []);
    $targetHotelIds = ctrip_pending_review_verify_positive_ints(ctrip_pending_review_verify_list($preflight['target_hotel_ids'] ?? []));
    if ($targetHotelIds !== []) {
        return $targetHotelIds[0];
    }

    $channelStatus = ctrip_pending_review_verify_map(ctrip_pending_review_verify_map($overview['channel_statuses'] ?? [])['ctrip'] ?? []);
    $channelHotelIds = ctrip_pending_review_verify_positive_ints(ctrip_pending_review_verify_list($channelStatus['system_hotel_ids'] ?? []));
    if ($channelHotelIds !== []) {
        return $channelHotelIds[0];
    }

    throw new RuntimeException('No Ctrip target hotel was found for the requested date.');
}

/**
 * @return array<string, int|string>
 */
function ctrip_pending_review_verify_insert_fixture(int $hotelId, string $businessDate): array
{
    $marker = 'codex_ctrip_pending_review_packet_' . date('YmdHis');
    $roomType = RoomType::create([
        'hotel_id' => $hotelId,
        'name' => 'Ctrip Pending Review Packet ' . substr($marker, -6),
        'base_price' => 300,
        'min_price' => 240,
        'max_price' => 430,
        'room_count' => 10,
        'sort_order' => 9998,
        'is_enabled' => RoomType::STATUS_ENABLED,
        'facilities' => [
            'source_scope' => 'ctrip_ota_channel',
            'evidence_status' => 'verifier_transaction_only',
            'auto_write_ota' => false,
            'verifier_marker' => $marker,
        ],
    ]);

    $suggestion = PriceSuggestion::create([
        'hotel_id' => $hotelId,
        'room_type_id' => (int)$roomType->id,
        'demand_forecast_id' => 0,
        'suggestion_type' => PriceSuggestion::TYPE_COMPETITOR,
        'status' => PriceSuggestion::STATUS_PENDING,
        'suggestion_date' => $businessDate,
        'current_price' => 300,
        'suggested_price' => 330,
        'min_price' => 240,
        'max_price' => 430,
        'confidence_score' => 0.82,
        'competitor_data' => [
            'source_scope' => 'ctrip_ota_channel',
            'source_channels' => ['ctrip'],
            'input_scope' => 'manual_pricing_configuration',
            'competitor_name' => 'Ctrip Pending Review Competitor',
            'our_price' => 300,
            'competitor_price' => 360,
            'auto_write_ota' => false,
            'verifier_marker' => $marker,
        ],
        'factors' => [
            'source_scope' => 'ctrip_ota_channel',
            'source_channels' => ['ctrip'],
            'decision_boundary' => 'manual_review_required_no_auto_rate_write',
            'risk_level' => 'medium',
            'primary_signal_count' => 3,
            'confidence_score' => 0.82,
            'signals' => [
                'demand_forecast' => ['data_status' => 'ok'],
                'competitor' => ['data_status' => 'ok'],
                'data_gaps' => [],
            ],
            'auto_write_ota' => false,
            'verifier_marker' => $marker,
        ],
        'reason' => 'transaction-only Ctrip pending review packet verifier',
    ]);

    return [
        'marker' => $marker,
        'room_type_id' => (int)$roomType->id,
        'price_suggestion_id' => (int)$suggestion->id,
    ];
}

/**
 * @param array<string, int|string> $fixture
 * @return array<string, int>
 */
function ctrip_pending_review_verify_inserted_id_counts(array $fixture): array
{
    $suggestionId = (int)($fixture['price_suggestion_id'] ?? 0);
    $roomTypeId = (int)($fixture['room_type_id'] ?? 0);

    return [
        'price_suggestions' => $suggestionId > 0 ? (int)PriceSuggestion::where('id', $suggestionId)->count() : -1,
        'room_types' => $roomTypeId > 0 ? (int)RoomType::where('id', $roomTypeId)->count() : -1,
    ];
}

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    ctrip_pending_review_verify_finish([
        'status' => 'failed',
        'error' => 'vendor/autoload.php is missing.',
    ], 1);
}

require $autoload;
require_once $root . '/scripts/report_revenue_ai_ctrip_pending_review_packet.php';

try {
    $options = ctrip_pending_review_verify_parse_args($argv);
    $app = new App($root);
    $app->initialize();

    $hotelId = ctrip_pending_review_verify_resolve_hotel_id($options['hotel_id'], $options['date']);
    $checks = [];
    $fixture = [];
    $packet = [];
    $packetExitCode = 1;
    $rolledBack = false;
    $rollbackCounts = [];

    Db::startTrans();
    try {
        $fixture = ctrip_pending_review_verify_insert_fixture($hotelId, $options['date']);
        $packetResult = ctrip_pending_review_build_packet([
            'date' => $options['date'],
            'hotel_id' => $hotelId,
            'format' => 'json',
            'limit' => 20,
        ]);
        $packet = $packetResult['payload'];
        $packetExitCode = (int)$packetResult['exit_code'];
    } finally {
        Db::rollback();
        $rolledBack = true;
    }

    if ($fixture !== []) {
        $rollbackCounts = ctrip_pending_review_verify_inserted_id_counts($fixture);
    }

    $scope = ctrip_pending_review_verify_map($packet['scope'] ?? []);
    $readiness = ctrip_pending_review_verify_map($packet['review_readiness'] ?? []);
    $reviewQueue = ctrip_pending_review_verify_map($packet['review_queue'] ?? []);
    $contract = ctrip_pending_review_verify_map($packet['review_contract'] ?? []);
    $pendingItems = ctrip_pending_review_verify_list($packet['pending_items'] ?? []);
    $createdSuggestionId = (int)($fixture['price_suggestion_id'] ?? 0);
    $createdItem = [];
    foreach ($pendingItems as $item) {
        $item = ctrip_pending_review_verify_map($item);
        if ((int)($item['id'] ?? 0) === $createdSuggestionId) {
            $createdItem = $item;
            break;
        }
    }
    $contamination = json_encode([
        'platform' => $scope['platform'] ?? null,
        'enabled_channels' => $scope['enabled_channels'] ?? [],
        'source_scope' => $scope['source_scope'] ?? null,
        'readiness_status' => $readiness['status'] ?? null,
        'readiness_reason' => $readiness['reason'] ?? null,
        'review_queue_status' => $reviewQueue['status'] ?? null,
        'review_queue_reason' => $reviewQueue['reason'] ?? null,
        'created_item_values' => [
            'room_type_name' => $createdItem['room_type_name'] ?? null,
            'suggestion_type_label' => $createdItem['suggestion_type_label'] ?? null,
            'reason' => $createdItem['reason'] ?? null,
            'risk_level' => $createdItem['risk_level'] ?? null,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

    ctrip_pending_review_verify_check(
        $checks,
        'transaction_fixture_inserted',
        (int)($fixture['room_type_id'] ?? 0) > 0 && $createdSuggestionId > 0,
        'Verifier inserted a transaction-only Ctrip pending AI suggestion.',
        $fixture
    );
    ctrip_pending_review_verify_check(
        $checks,
        'packet_build_passed',
        $packetExitCode === 0 && (string)($packet['status'] ?? '') === 'passed',
        'Pending review packet builds successfully inside the transaction.',
        ['exit_code' => $packetExitCode, 'status' => $packet['status'] ?? null]
    );
    ctrip_pending_review_verify_check(
        $checks,
        'packet_scope_ctrip_read_only',
        (string)($scope['source_scope'] ?? '') === 'ctrip_ota_channel'
            && ctrip_pending_review_verify_list($scope['enabled_channels'] ?? []) === ['ctrip']
            && ($scope['database_written'] ?? true) === false
            && ($scope['auto_write_ota'] ?? true) === false,
        'Pending review packet stays Ctrip-scoped and read-only.',
        $scope
    );
    ctrip_pending_review_verify_check(
        $checks,
        'packet_reads_pending_review_item',
        (string)($readiness['status'] ?? '') === 'pending_review'
            && (int)($readiness['pending_item_count'] ?? 0) >= 1
            && (string)($reviewQueue['status'] ?? '') === 'pending_review'
            && (int)($reviewQueue['pending_count'] ?? 0) >= 1
            && $createdItem !== [],
        'Pending review packet includes the transaction-only pending AI suggestion.',
        [
            'review_readiness' => $readiness,
            'review_queue' => [
                'status' => $reviewQueue['status'] ?? null,
                'pending_count' => $reviewQueue['pending_count'] ?? null,
            ],
            'created_item_id' => $createdItem['id'] ?? null,
        ]
    );
    ctrip_pending_review_verify_check(
        $checks,
        'created_item_review_contract_complete',
        (string)($createdItem['review_endpoint'] ?? '') === '/api/revenue-ai/price-suggestions/' . $createdSuggestionId . '/review'
            && (string)($createdItem['execution_intent_endpoint_after_approval'] ?? '') === '/api/revenue-ai/price-suggestions/' . $createdSuggestionId . '/execution-intent'
            && in_array('approve_with_changes', ctrip_pending_review_verify_list($createdItem['allowed_manual_actions'] ?? []), true)
            && in_array('ota_write', ctrip_pending_review_verify_list($createdItem['forbidden_actions'] ?? []), true)
            && (string)($createdItem['price_guard_status'] ?? '') === 'within_guard',
        'Created pending item exposes manual review actions, post-approval execution endpoint, and OTA-write prohibition.',
        $createdItem
    );
    ctrip_pending_review_verify_check(
        $checks,
        'packet_contract_blocks_downstream_shortcuts',
        in_array('operation_execution_before_review', ctrip_pending_review_verify_list($contract['forbidden_actions'] ?? []), true)
            && in_array('investment_decision_before_operation_roi', ctrip_pending_review_verify_list($contract['forbidden_actions'] ?? []), true)
            && ($contract['auto_apply_ai_advice'] ?? true) === false
            && ($contract['operation_intake_allowed_before_review'] ?? true) === false,
        'Packet contract blocks operation and investment shortcuts before manual AI review and ROI evidence.',
        $contract
    );
    ctrip_pending_review_verify_check(
        $checks,
        'meituan_not_present',
        !str_contains(strtolower($contamination), 'meituan') && !str_contains($contamination, '美团'),
        'Transaction pending-review packet contains no Meituan token or label.',
        []
    );
    ctrip_pending_review_verify_check(
        $checks,
        'transaction_rolled_back',
        $rolledBack && $rollbackCounts !== [] && array_sum($rollbackCounts) === 0,
        'Verifier transaction was rolled back and did not leave fixture rows behind.',
        ['rolled_back' => $rolledBack, 'marker_counts_after_rollback' => $rollbackCounts]
    );

    $failures = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'passed'));
    ctrip_pending_review_verify_finish([
        'status' => $failures === [] ? 'passed' : 'failed',
        'scope' => [
            'business_date' => $options['date'],
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $hotelId,
            'source_policy' => 'transactional_pending_review_packet_fixture_rollback_no_ota_write',
        ],
        'summary' => [
            'fixture' => $fixture,
            'packet' => [
                'status' => $packet['status'] ?? null,
                'source_scope' => $scope['source_scope'] ?? null,
                'database_written' => $scope['database_written'] ?? null,
                'auto_write_ota' => $scope['auto_write_ota'] ?? null,
                'review_readiness_status' => $readiness['status'] ?? null,
                'pending_item_count' => $readiness['pending_item_count'] ?? null,
                'review_queue_status' => $reviewQueue['status'] ?? null,
                'review_queue_pending_count' => $reviewQueue['pending_count'] ?? null,
            ],
            'created_item' => [
                'id' => $createdItem['id'] ?? null,
                'room_type_id' => $createdItem['room_type_id'] ?? null,
                'suggested_price' => $createdItem['suggested_price'] ?? null,
                'price_guard_status' => $createdItem['price_guard_status'] ?? null,
                'review_endpoint' => $createdItem['review_endpoint'] ?? null,
                'auto_write_ota' => $createdItem['auto_write_ota'] ?? null,
            ],
            'rollback_counts' => $rollbackCounts,
        ],
        'checks' => $checks,
    ], $failures === [] ? 0 : 1);
} catch (Throwable $error) {
    try {
        Db::rollback();
    } catch (Throwable) {
    }
    ctrip_pending_review_verify_finish([
        'status' => 'failed',
        'error' => [
            'type' => get_class($error),
            'message' => $error->getMessage(),
        ],
    ], 1);
}
