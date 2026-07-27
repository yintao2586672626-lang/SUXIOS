#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\SingleHotelOperatingBriefService;
use app\service\SingleHotelOperatingDigestService;
use think\App;
use think\facade\Db;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new App($root))->initialize();

$options = getopt('', ['hotel-id:', 'target-date::']);
$hotelId = filter_var($options['hotel-id'] ?? null, FILTER_VALIDATE_INT);
$targetDate = trim((string)($options['target-date'] ?? (
    new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'))
)->format('Y-m-d')));

if (!is_int($hotelId)
    || $hotelId <= 0
    || !digestValidDate($targetDate)
) {
    digestFail('single_hotel_digest_arguments_invalid', 2);
}

try {
    $hotel = Db::name('hotels')
        ->where('id', $hotelId)
        ->field('id,tenant_id,name,status')
        ->find();
    if (!is_array($hotel)
        || (int)($hotel['tenant_id'] ?? 0) <= 0
        || (int)($hotel['status'] ?? 0) !== 1
        || trim((string)($hotel['name'] ?? '')) === ''
    ) {
        throw new RuntimeException('single_hotel_digest_hotel_scope_invalid');
    }
    $tenantId = (int)$hotel['tenant_id'];
    $digest = (new SingleHotelOperatingDigestService())->build(
        $tenantId,
        $hotelId,
        $targetDate,
        []
    );
    $brief = (new SingleHotelOperatingBriefService())->preview($digest);
    $sources = is_array($digest['sources'] ?? null) ? $digest['sources'] : [];
    $output = [
        'status' => (string)($brief['status'] ?? 'blocked'),
        'contract_version' => (string)($digest['contract_version'] ?? ''),
        'brief_contract_version' => (string)($brief['contract_version'] ?? ''),
        'tenant_id' => $tenantId,
        'hotel_id' => $hotelId,
        'hotel_name' => (string)($hotel['name'] ?? ''),
        'business_date' => $targetDate,
        'digest_status' => (string)($digest['status'] ?? 'blocked'),
        'source_gate_passed' => ($brief['source_gate_passed'] ?? false) === true,
        'operating_target_status' => (string)(
            $digest['operating_target_status'] ?? 'not_set'
        ),
        'source_statuses' => [
            'pms' => (string)($sources['pms']['status'] ?? 'missing'),
            'ctrip' => (string)($sources['ctrip']['status'] ?? 'missing'),
            'meituan' => (string)($sources['meituan']['status'] ?? 'missing'),
        ],
        'source_facts' => [
            'pms' => is_array($sources['pms']['facts'] ?? null)
                ? $sources['pms']['facts']
                : [],
            'ctrip' => is_array($sources['ctrip']['facts'] ?? null)
                ? $sources['ctrip']['facts']
                : [],
            'meituan' => is_array($sources['meituan']['facts'] ?? null)
                ? $sources['meituan']['facts']
                : [],
        ],
        'gaps' => array_values(array_filter(
            (array)($digest['gaps'] ?? []),
            'is_array'
        )),
        'blockers' => array_values(array_filter(
            (array)($digest['blockers'] ?? []),
            'is_array'
        )),
        'message_preview' => (string)($brief['content'] ?? ''),
        'preview_only' => true,
        'message_sent' => false,
        'webhook_read' => false,
        'sensitive_values_exposed' => false,
    ];
    echo json_encode(
        $output,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    exit(($output['source_gate_passed'] ?? false) === true ? 0 : 2);
} catch (Throwable $exception) {
    digestFail($exception->getMessage(), 2);
}

function digestValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function digestFail(string $reason, int $exitCode): never
{
    $reason = strtolower(trim($reason));
    $reason = preg_replace(
        '/(key|token|secret|cookie|password|authorization|webhook)\s*[=:]\s*[^\s,;]+/iu',
        '$1=<redacted>',
        $reason
    ) ?? 'single_hotel_digest_failed';
    if ($reason === '') {
        $reason = 'single_hotel_digest_failed';
    }
    fwrite(STDERR, json_encode([
        'status' => 'blocked',
        'reason' => mb_strcut($reason, 0, 240, 'UTF-8'),
        'preview_only' => true,
        'message_sent' => false,
        'webhook_read' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($exitCode);
}
