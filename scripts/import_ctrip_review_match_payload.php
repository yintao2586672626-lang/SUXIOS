<?php
declare(strict_types=1);

use app\controller\concern\CtripCapturedPayloadConcern;
use app\controller\concern\CtripReviewOrderMatchConcern;
use app\service\CtripReviewOrderMatchService;
use think\App;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');

$root = dirname(__DIR__);
require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function ctrip_review_match_import_parse_args(array $argv): array
{
    $options = [
        'file' => '',
        'payload' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'system-hotel-id' => '',
        'system_hotel_id' => '',
        'review-limit' => 500,
        'review_limit' => 500,
        'execute' => false,
        'preflight' => false,
        'print-template' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--execute') {
            $options['execute'] = true;
            continue;
        }
        if ($arg === '--preflight') {
            $options['preflight'] = true;
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
        $options[$key] = in_array($key, ['execute', 'preflight', 'print-template'], true)
            ? in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true)
            : trim($value);
    }

    if ((string)$options['file'] === '' && (string)$options['payload'] !== '') {
        $options['file'] = (string)$options['payload'];
    }
    if ((string)$options['system-hotel-id'] === '' && (string)$options['system_hotel_id'] !== '') {
        $options['system-hotel-id'] = (string)$options['system_hotel_id'];
    }
    if ((string)$options['system-hotel-id'] === '' && (string)$options['hotel-id'] !== '') {
        $options['system-hotel-id'] = (string)$options['hotel-id'];
    }
    if ((string)$options['system-hotel-id'] === '' && (string)$options['hotel_id'] !== '') {
        $options['system-hotel-id'] = (string)$options['hotel_id'];
    }
    if ((string)$options['review-limit'] === '' && (string)$options['review_limit'] !== '') {
        $options['review-limit'] = (string)$options['review_limit'];
    }

    $reviewLimit = max(1, min(2000, (int)$options['review-limit']));
    $systemHotelId = (string)$options['system-hotel-id'];
    if ($systemHotelId !== '' && (!ctype_digit($systemHotelId) || (int)$systemHotelId <= 0)) {
        throw new InvalidArgumentException('Invalid --system-hotel-id, expected a positive integer.');
    }

    return [
        'file' => (string)$options['file'],
        'system_hotel_id' => $systemHotelId === '' ? null : (int)$systemHotelId,
        'review_limit' => $reviewLimit,
        'execute' => (bool)$options['execute'],
        'preflight' => (bool)$options['preflight'],
        'print_template' => (bool)$options['print-template'],
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_review_match_import_finish(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

/**
 * @return array<string, mixed>
 */
function ctrip_review_match_import_template(): array
{
    return [
        'system_hotel_id' => 58,
        'scope' => 'ctrip_ota_channel',
        'template_notice' => 'Replace every replace-with-* value before dry-run or execute; template payload is rejected by the importer.',
        'reviews' => [
            [
                'commentId' => 'replace-with-ctrip-comment-id',
                'check_in_date' => 'replace-with-check-in-date',
                'room_type' => 'replace-with-room-type',
                'content' => 'replace-with-authorized-review-text-or-summary',
            ],
        ],
        'im_sessions' => [[
            'groupId' => 'replace-with-ctrip-im-group-id',
            'orderId' => 'replace-with-ctrip-order-no',
            'arrivalDate' => 'replace-with-check-in-date',
            'roomName' => 'replace-with-order-room-type',
            'members' => [],
        ]],
        'orders' => [
            [
                'orderNo' => 'replace-with-ctrip-order-no',
                'checkIn' => 'replace-with-check-in-date',
                'room_type_name' => 'replace-with-order-room-type',
                'orderStatus' => 'replace-with-order-status',
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function ctrip_review_match_import_read_payload(string $file): array
{
    if ($file === '') {
        throw new InvalidArgumentException('Missing --file=<authorized-payload.json>.');
    }
    if ($file === '-') {
        $raw = file_get_contents('php://stdin');
        if (!is_string($raw) || trim($raw) === '') {
            throw new InvalidArgumentException('STDIN payload is empty.');
        }
        $decoded = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Payload JSON is invalid: ' . json_last_error_msg());
        }

        return $decoded;
    }
    if (!is_file($file) || !is_readable($file)) {
        throw new InvalidArgumentException('Payload file is not readable.');
    }

    $raw = file_get_contents($file);
    if (!is_string($raw)) {
        throw new RuntimeException('Payload file read failed.');
    }
    $decoded = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Payload JSON is invalid: ' . json_last_error_msg());
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_review_match_import_resolve_hotel_id(array $payload, ?int $overrideHotelId): int
{
    $value = $overrideHotelId
        ?? $payload['system_hotel_id']
        ?? $payload['systemHotelId']
        ?? $payload['hotel_id']
        ?? $payload['hotelId']
        ?? null;

    if (!is_numeric($value) || (int)$value <= 0) {
        throw new InvalidArgumentException('Missing --system-hotel-id, or system_hotel_id in payload.');
    }

    return (int)$value;
}

/**
 * @param array<string, mixed> $payload
 * @return array<int, array<string, mixed>>
 */
function ctrip_review_match_import_payloads(array $payload): array
{
    if (isset($payload['payloads']) && is_array($payload['payloads'])) {
        return array_values(array_filter($payload['payloads'], static fn($item): bool => is_array($item)));
    }
    if (isset($payload['payload']) && is_array($payload['payload'])) {
        return [$payload['payload']];
    }

    return [$payload];
}

/**
 * @param mixed $value
 * @param array<int, string> $paths
 */
function ctrip_review_match_import_collect_placeholders($value, string $path, array &$paths): void
{
    if (is_string($value)) {
        if (str_starts_with(trim($value), 'replace-with-')) {
            $paths[] = $path;
        }
        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $key => $child) {
        $childPath = $path === '' ? (string)$key : $path . '.' . (string)$key;
        if (is_string($key) && str_starts_with(trim($key), 'replace-with-')) {
            $paths[] = $childPath . '.__key';
        }
        ctrip_review_match_import_collect_placeholders($child, $childPath, $paths);
    }
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_review_match_import_assert_no_placeholders(array $payload): void
{
    $paths = [];
    ctrip_review_match_import_collect_placeholders($payload, '', $paths);
    $paths = array_values(array_unique($paths));
    if ($paths === []) {
        return;
    }

    throw new InvalidArgumentException(
        'Payload still contains template placeholders. Replace every replace-with-* value before import: '
        . implode(', ', array_slice($paths, 0, 12))
    );
}

/**
 * @param array<int, string> $commentIds
 * @param array<int, array<string, mixed>> $reviews
 * @return array<int, array<string, mixed>>
 */
function ctrip_review_match_import_filter_reviews(array $reviews, array $commentIds): array
{
    if ($commentIds === []) {
        return [];
    }

    $wanted = array_fill_keys($commentIds, true);
    return array_values(array_filter($reviews, static function (array $review) use ($wanted): bool {
        $commentId = trim((string)($review['commentId'] ?? $review['comment_id'] ?? $review['id'] ?? $review['reviewId'] ?? $review['review_id'] ?? ''));
        return $commentId !== '' && isset($wanted[$commentId]);
    }));
}

final class CtripReviewMatchImportBridge
{
    use CtripCapturedPayloadConcern;
    use CtripReviewOrderMatchConcern;

    /**
     * @param array<int, string> $path
     */
    private function readNestedMeituanValue(array $data, array $path)
    {
        $current = $data;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    private function isSequentialArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function extractCtripCommentId(array $comment): string
    {
        foreach (['commentId', 'comment_id', 'id', 'reviewId', 'review_id'] as $field) {
            if (isset($comment[$field]) && trim((string)$comment[$field]) !== '') {
                return trim((string)$comment[$field]);
            }
        }

        return '';
    }

    /**
     * @param array<int, mixed> $args
     */
    public function callPrivate(string $name, array $args = []): mixed
    {
        $method = new ReflectionMethod($this, $name);
        $method->setAccessible(true);

        return $method->invokeArgs($this, $args);
    }
}

try {
    $options = ctrip_review_match_import_parse_args($argv);
    if ($options['print_template']) {
        ctrip_review_match_import_finish(ctrip_review_match_import_template(), 0);
    }

    $payload = ctrip_review_match_import_read_payload($options['file']);
    ctrip_review_match_import_assert_no_placeholders($payload);
    $systemHotelId = null;
    try {
        $systemHotelId = ctrip_review_match_import_resolve_hotel_id($payload, $options['system_hotel_id']);
    } catch (InvalidArgumentException $e) {
        if (!$options['preflight']) {
            throw $e;
        }
    }

    $app = new App($root);
    $app->initialize();

    $bridge = new CtripReviewMatchImportBridge();
    $payloads = ctrip_review_match_import_payloads($payload);
    $payloadPreflight = $bridge->callPrivate('buildCtripReviewMatchPayloadPreflight', [$payloads]);
    if ($options['preflight']) {
        $preflight = is_array($payloadPreflight) ? $payloadPreflight : [];
        ctrip_review_match_import_finish([
            'mode' => 'preflight',
            'scope' => 'ctrip_ota_channel',
            'system_hotel_id' => $systemHotelId,
            'payload_preflight' => $preflight,
            'source_status' => [
                'policy' => 'authorized_payload_preflight_only',
                'storage_write' => false,
                'transaction' => 'not_started',
            ],
        ], (bool)($preflight['ready_for_match_attempt'] ?? false) ? 0 : 2);
    }

    $service = new CtripReviewOrderMatchService();

    $importSummary = [
        'payloads_scanned' => 0,
        'reviews_upserted' => 0,
        'im_sessions_upserted' => 0,
        'orders_upserted' => 0,
    ];
    $importedCommentIds = [];

    Db::startTrans();
    try {
        foreach ($payloads as $item) {
            $importSummary['payloads_scanned']++;
            $reviewsInPayload = $bridge->callPrivate('extractCtripCapturedComments', [$item]);
            foreach ($reviewsInPayload as $review) {
                $commentId = $bridge->callPrivate('extractCtripReviewCommentId', [$review]);
                if (is_string($commentId) && $commentId !== '') {
                    $importedCommentIds[] = $commentId;
                }
            }

            $imported = $bridge->callPrivate('importCtripReviewMatchPayload', [$systemHotelId, $item]);
            $importSummary['reviews_upserted'] += (int)($imported['reviews_upserted'] ?? 0);
            $importSummary['im_sessions_upserted'] += (int)($imported['im_sessions_upserted'] ?? 0);
            $importSummary['orders_upserted'] += (int)($imported['orders_upserted'] ?? 0);
        }

        $importedCommentIds = array_values(array_unique($importedCommentIds));
        $reviews = $bridge->callPrivate('loadCtripReviewsForMatch', [$systemHotelId, $options['review_limit']]);
        $reviews = is_array($reviews) ? ctrip_review_match_import_filter_reviews($reviews, $importedCommentIds) : [];
        $imSessions = $bridge->callPrivate('loadCtripReviewImSessions', [$systemHotelId]);
        $orders = $bridge->callPrivate('loadCtripOrderPool', [$systemHotelId]);
        $coverageStartDate = $bridge->callPrivate('firstCtripOrderCoverageDate', [$systemHotelId]);

        $statusCounts = [];
        $samples = [];
        $multiReviewResolution = ['resolved_count' => 0, 'assignments' => []];
        foreach ($reviews as $review) {
            $result = $service->matchReviewToOrder(
                $review,
                is_array($imSessions) ? $imSessions : [],
                is_array($orders) ? $orders : [],
                ['coverage_start_date' => is_string($coverageStartDate) ? $coverageStartDate : '']
            );
            $status = (string)($result['status'] ?? 'unknown');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $bridge->callPrivate('saveCtripReviewMatchAttempt', [$systemHotelId, $review, $result, 'payload_import']);
            if (count($samples) < 20) {
                $samples[] = [
                    'comment_id' => (string)$bridge->callPrivate('extractCtripReviewCommentId', [$review]),
                    'status' => $status,
                    'order_id' => (string)($result['order']['order_id'] ?? ''),
                    'reason' => (string)($result['reason'] ?? ''),
                    'missing_evidence' => is_array($result['missing_evidence'] ?? null) ? $result['missing_evidence'] : [],
                ];
            }
        }

        $sourceTables = [
            'ctrip_reviews' => count($reviews),
            'ctrip_im_sessions' => is_array($imSessions) ? count($imSessions) : 0,
            'ctrip_orders' => is_array($orders) ? count($orders) : 0,
        ];
        $missingSources = [];
        foreach ($sourceTables as $key => $count) {
            if ($count === 0) {
                $missingSources[] = $key;
            }
        }

        $result = [
            'mode' => $options['execute'] ? 'execute' : 'dry_run',
            'scope' => 'ctrip_ota_channel',
            'system_hotel_id' => $systemHotelId,
            'import' => $importSummary,
            'payload_preflight' => is_array($payloadPreflight) ? $payloadPreflight : [],
            'summary' => [
                'review_count' => $sourceTables['ctrip_reviews'],
                'im_session_count' => $sourceTables['ctrip_im_sessions'],
                'order_count' => $sourceTables['ctrip_orders'],
                'matched_count' => (int)($statusCounts['found'] ?? 0),
                'person_locked_count' => (int)($statusCounts['person_locked'] ?? 0),
                'needs_ops_count' => (int)($statusCounts['needs_ops'] ?? 0),
                'out_of_coverage_count' => (int)($statusCounts['out_of_coverage'] ?? 0),
                'multi_review_resolved_count' => (int)($multiReviewResolution['resolved_count'] ?? 0),
            ],
            'status_counts' => $statusCounts,
            'missing_sources' => $missingSources,
            'source_status' => [
                'detail_sources_ready' => $missingSources === [],
                'source_tables' => $sourceTables,
                'policy' => 'authorized_import_only',
            ],
            'samples' => $samples,
            'transaction' => $options['execute'] ? 'committed' : 'rolled_back',
        ];

        if ($options['execute']) {
            Db::commit();
        } else {
            Db::rollback();
        }

        ctrip_review_match_import_finish($result, $missingSources === [] ? 0 : 2);
    } catch (Throwable $e) {
        Db::rollback();
        throw $e;
    }
} catch (Throwable $e) {
    ctrip_review_match_import_finish([
        'status' => 'failed',
        'error' => $e->getMessage(),
        'scope' => 'ctrip_ota_channel',
    ], 1);
}
