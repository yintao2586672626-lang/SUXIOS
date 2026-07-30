<?php
declare(strict_types=1);

use app\service\CompetitorEventFeedService;
use app\service\CompetitorManualObservationService;
use think\App;
use think\facade\Db;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing.\n");
    exit(1);
}
require $autoload;

$options = getopt('', [
    'hotel-id:',
    'target-id:',
    'date:',
    'source-ref:',
    'collected-at::',
    'availability::',
    'price::',
]);
$hotelId = (int)($options['hotel-id'] ?? 0);
$targetId = (int)($options['target-id'] ?? 0);
$stayDate = trim((string)($options['date'] ?? ''));
$sourceRef = trim((string)($options['source-ref'] ?? ''));
$collectedAt = trim((string)($options['collected-at'] ?? date('Y-m-d H:i:s')));
$availability = strtolower(trim((string)($options['availability'] ?? 'bookable')));
$price = trim((string)($options['price'] ?? '391'));

if ($hotelId <= 0 || $targetId <= 0
    || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $stayDate) !== 1
    || $sourceRef === ''
) {
    fwrite(STDERR, "Usage: php scripts/verify_competitor_manual_observation_runtime.php --hotel-id=<id> --target-id=<id> --date=YYYY-MM-DD --source-ref=<public-ota-url> [--collected-at=<datetime>] [--availability=bookable|sold_out|unavailable] [--price=<amount>]\n");
    exit(2);
}

(new App(dirname(__DIR__)))->initialize();

$beforeCount = (int)Db::name('competitor_price_log')
    ->where('store_id', $hotelId)
    ->where('hotel_id', $targetId)
    ->count();
$targetHotelCode = trim((string)Db::name('competitor_hotel')
    ->where('id', $targetId)
    ->where('store_id', $hotelId)
    ->where('status', 1)
    ->value('hotel_code'));
$targetIdentityBound = preg_match('/^[1-9][0-9]{0,19}$/D', $targetHotelCode) === 1;
$checks = [];
$resultSummary = [];
$error = null;

Db::startTrans();
try {
    $checkOutDate = (new DateTimeImmutable($stayDate))->modify('+1 day')->format('Y-m-d');
    $input = [
        'collected_at' => $collectedAt,
        'check_in_date' => $stayDate,
        'check_out_date' => $checkOutDate,
        'adults' => 2,
        'children' => 0,
        'availability' => $availability,
        'price' => $price,
        'currency' => 'CNY',
        'source_ref' => $sourceRef,
        'source_surface' => 'public_nearby_card',
        'ota_hotel_id' => '',
    ];

    $service = new CompetitorManualObservationService();
    $first = $service->persist($hotelId, $targetId, 1, $input);
    $replay = $service->persist($hotelId, $targetId, 1, $input);
    $feed = (new CompetitorEventFeedService())->build(
        $hotelId,
        (string)$first['canonical_platform'],
        $stayDate,
        (string)$first['record']['collected_at'],
        (string)$first['record']['collected_at'],
        500
    );
    $event = null;
    foreach ((array)($feed['events'] ?? []) as $candidate) {
        if ((int)($candidate['id'] ?? 0) === (int)$first['id']) {
            $event = $candidate;
            break;
        }
    }
    $duringCount = (int)Db::name('competitor_price_log')
        ->where('store_id', $hotelId)
        ->where('hotel_id', $targetId)
        ->count();

    $checks = [
        'first_inserted_once' => ($first['idempotent_replay'] ?? true) === false
            && $duringCount === $beforeCount + 1,
        'identical_replay_is_idempotent' => ($replay['idempotent_replay'] ?? false) === true
            && (int)$replay['id'] === (int)$first['id']
            && $duringCount === $beforeCount + 1,
        'database_readback_verified' => ($first['readback_verified'] ?? false) === true
            && ($replay['readback_verified'] ?? false) === true,
        'event_returned_from_feed' => is_array($event),
        'availability_evidence_matches_target_binding' => is_array($event)
            && (($event['availability_evidence_eligible'] ?? false) === $targetIdentityBound),
        'unbound_target_stays_binding_missing' => $targetIdentityBound || (is_array($event)
            && in_array('ota_hotel_id_missing_or_unverified', (array)($event['availability_evidence_gaps'] ?? []), true)),
        'public_price_not_decision_eligible' => is_array($event)
            && ($event['price_evidence_eligible'] ?? true) === false
            && ($event['decision_eligible'] ?? true) === false,
        'source_and_target_preserved' => is_array($event)
            && (int)($event['competitor_hotel_id'] ?? 0) === $targetId
            && trim((string)($event['source_method'] ?? '')) !== ''
            && trim((string)($event['source_ref'] ?? '')) !== '',
    ];
    $resultSummary = [
        'temporary_event_id' => (int)$first['id'],
        'canonical_platform' => (string)$first['canonical_platform'],
        'event_type' => is_array($event) ? ($event['event_type'] ?? null) : null,
        'availability' => is_array($event) ? ($event['availability'] ?? null) : null,
        'readback_verified' => is_array($event) && ($event['readback_verified'] ?? false) === true,
        'availability_evidence_eligible' => is_array($event) && ($event['availability_evidence_eligible'] ?? false) === true,
        'price_evidence_eligible' => is_array($event) && ($event['price_evidence_eligible'] ?? false) === true,
        'target_identity_status' => $targetIdentityBound ? 'ota_hotel_id_configured' : 'binding_missing',
    ];
} catch (Throwable $exception) {
    $error = [
        'type' => get_debug_type($exception),
        'message' => $exception->getMessage(),
    ];
} finally {
    Db::rollback();
}

$afterCount = (int)Db::name('competitor_price_log')
    ->where('store_id', $hotelId)
    ->where('hotel_id', $targetId)
    ->count();
$checks['transaction_rollback_clean'] = $afterCount === $beforeCount;
$passed = $error === null && !in_array(false, $checks, true);

echo json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'hotel_id' => $hotelId,
    'competitor_hotel_id' => $targetId,
    'stay_date' => $stayDate,
    'collected_at' => $collectedAt,
    'before_count' => $beforeCount,
    'after_rollback_count' => $afterCount,
    'checks' => $checks,
    'temporary_result' => $resultSummary,
    'error' => $error,
    'scope_notice' => 'Verifier writes one temporary manual public observation inside a transaction and always rolls it back.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;

exit($passed ? 0 : 1);
