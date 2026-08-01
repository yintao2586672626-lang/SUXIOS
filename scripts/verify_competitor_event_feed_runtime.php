<?php
declare(strict_types=1);

use app\service\CompetitorEventFeedService;
use think\App;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing.\n");
    exit(1);
}
require $autoload;

$options = getopt('', ['hotel-id:', 'date:', 'platform::', 'strict']);
$hotelId = isset($options['hotel-id']) ? (int)$options['hotel-id'] : 0;
$stayDate = trim((string)($options['date'] ?? ''));
$platform = trim((string)($options['platform'] ?? 'all'));
$strict = array_key_exists('strict', $options);

if ($hotelId <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $stayDate) !== 1) {
    fwrite(STDERR, "Usage: php scripts/verify_competitor_event_feed_runtime.php --hotel-id=<id> --date=YYYY-MM-DD [--platform=all|ctrip|meituan] [--strict]\n");
    exit(2);
}

(new App(dirname(__DIR__)))->initialize();

try {
    $feed = (new CompetitorEventFeedService())->build($hotelId, $platform, $stayDate);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'error_type' => get_debug_type($error),
        'message' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

$events = array_map(static fn(array $event): array => [
    'id' => (int)($event['id'] ?? 0),
    'platform' => $event['platform'] ?? null,
    'competitor_hotel_name' => $event['competitor_hotel_name'] ?? null,
    'event_type' => $event['event_type'] ?? null,
    'secondary_event_type' => $event['secondary_event_type'] ?? null,
    'price' => $event['price'] ?? null,
    'currency' => $event['currency'] ?? null,
    'availability' => $event['availability'] ?? null,
    'stay_date' => $event['stay_date'] ?? null,
    'collected_at' => $event['collected_at'] ?? null,
    'quality_status' => $event['quality_status'] ?? null,
    'readback_verified' => ($event['readback_verified'] ?? false) === true,
    'event_eligible' => ($event['event_eligible'] ?? false) === true,
    'price_evidence_eligible' => ($event['price_evidence_eligible'] ?? false) === true,
    'source_method' => $event['source_method'] ?? null,
    'source_ref' => $event['source_ref'] ?? null,
    'evidence_gaps' => array_values((array)($event['evidence_gaps'] ?? [])),
    'event_evidence_gaps' => array_values((array)($event['event_evidence_gaps'] ?? [])),
], (array)($feed['events'] ?? []));

$sampleCount = is_numeric($feed['sample_count'] ?? null) ? (int)$feed['sample_count'] : 0;
$readbackCount = (int)($feed['readback_verified_count'] ?? 0);
$namedSourceCount = count(array_filter($events, static fn(array $event): bool =>
    trim((string)($event['competitor_hotel_name'] ?? '')) !== ''
    && trim((string)($event['source_method'] ?? '')) !== ''
    && trim((string)($event['source_ref'] ?? '')) !== ''
));
$observableCount = count(array_filter($events, static fn(array $event): bool =>
    in_array((string)($event['availability'] ?? ''), ['available', 'bookable', 'unavailable', 'sold_out'], true)
    && (
        is_numeric($event['price'] ?? null)
        || in_array((string)($event['availability'] ?? ''), ['unavailable', 'sold_out'], true)
    )
));
$eligibleEventCount = count(array_filter(
    $events,
    static fn(array $event): bool => ($event['event_eligible'] ?? false) === true
));
$truncated = ($feed['truncated'] ?? false) === true;
$feedStatus = (string)($feed['status'] ?? '');

$checks = [
    'has_observations' => $sampleCount > 0 && count($events) > 0,
    'feed_status_available' => $feedStatus === 'available',
    'not_truncated' => !$truncated,
    'all_matching_rows_returned' => $sampleCount > 0 && $sampleCount === count($events),
    'all_rows_readback_verified' => $sampleCount > 0 && $readbackCount === $sampleCount,
    'all_rows_have_named_source' => count($events) > 0 && $namedSourceCount === count($events),
    'all_rows_have_price_or_closed_availability' => count($events) > 0 && $observableCount === count($events),
    'all_events_evidence_eligible' => count($events) > 0 && $eligibleEventCount === count($events),
];
$passed = !in_array(false, $checks, true);

$payload = [
    'status' => $passed ? (string)($feed['status'] ?? 'available') : 'failed',
    'hotel_id' => $hotelId,
    'platform' => $platform,
    'stay_date' => $stayDate,
    'sample_count' => $sampleCount,
    'returned_event_count' => count($events),
    'truncated' => $truncated,
    'readback_verified_count' => $readbackCount,
    'availability_evidence_eligible_sample_count' => (int)($feed['availability_evidence_eligible_sample_count'] ?? 0),
    'price_evidence_eligible_sample_count' => (int)($feed['price_evidence_eligible_sample_count'] ?? 0),
    'decision_gate' => $feed['decision_gate'] ?? null,
    'checks' => $checks,
    'data_gaps' => array_values((array)($feed['data_gaps'] ?? [])),
    'events' => $events,
    'scope_notice' => $feed['scope_notice'] ?? null,
];

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
exit($strict && !$passed ? 1 : 0);
