#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\SingleHotelCollectionPreviewRunService;
use app\service\SingleHotelOperatingBriefService;
use app\service\SingleHotelOperatingDigestService;
use think\App;
use think\facade\Db;

const MOLANXIN_RUNNER_MAX_OUTPUT_BYTES = 2_000_000;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new App($root))->initialize();

$options = getopt('', [
    'hotel-id:',
    'owner-user-id:',
    'profile-id:',
    'target-date::',
    'control-token-file::',
    'php-binary::',
    'node-binary::',
]);
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
    ->format('Y-m-d');
$hotelId = molanxinPositiveInt($options['hotel-id'] ?? null, 'hotel_id_invalid');
$ownerUserId = molanxinPositiveInt(
    $options['owner-user-id'] ?? null,
    'owner_user_id_invalid'
);
$profileId = trim((string)($options['profile-id'] ?? ''));
$targetDate = trim((string)($options['target-date'] ?? $today));
$tokenFile = trim((string)($options['control-token-file']
    ?? '/run/credentials/suxios-dingdandao-collection.service/control-token'));
$phpBinary = trim((string)($options['php-binary'] ?? '/usr/bin/php'));
$nodeBinary = trim((string)($options['node-binary'] ?? '/usr/bin/node'));
if (preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $profileId) !== 1
    || !molanxinValidDate($targetDate)
    || $targetDate !== $today
    || $tokenFile
        !== '/run/credentials/suxios-dingdandao-collection.service/control-token'
    || $phpBinary !== '/usr/bin/php'
    || $nodeBinary !== '/usr/bin/node'
) {
    molanxinFail('molanxin_collection_arguments_invalid', 2);
}

$hotel = Db::name('hotels')
    ->where('id', $hotelId)
    ->field('id,tenant_id,name,status')
    ->find();
$digestService = new SingleHotelOperatingDigestService();
if (!is_array($hotel)
    || (int)($hotel['tenant_id'] ?? 0) <= 0
    || (int)($hotel['status'] ?? 0) !== 1
    || !$digestService->appliesTo((int)$hotel['tenant_id'], $hotelId)
) {
    molanxinFail('molanxin_collection_hotel_scope_invalid', 2);
}
$tenantId = (int)$hotel['tenant_id'];
$observedAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
$runs = new SingleHotelCollectionPreviewRunService();
$runId = 0;

try {
    $runId = $runs->start($hotelId, $observedAt);
    $collection = molanxinRunJsonProcess([
        $phpBinary,
        $root . '/scripts/run_dingdandao_profile_lease_collection.php',
        '--hotel-id=' . $hotelId,
        '--owner-user-id=' . $ownerUserId,
        '--profile-id=' . $profileId,
        '--target-date=' . $targetDate,
        '--control-token-file=' . $tokenFile,
        '--node-binary=' . $nodeBinary,
        '--collector-script=' . $root . '/scripts/run_dingdandao_cloud_collection.php',
    ], $root);
    if (!in_array((string)($collection['status'] ?? ''), [
        'saved_synced_and_report_ready',
        'saved_synced_but_report_blocked',
    ], true)
        || (int)($collection['capture_id'] ?? 0) <= 0
        || (string)($collection['target_date'] ?? '') !== $targetDate
        || (int)($collection['hotel_id'] ?? 0) !== $hotelId
        || ($collection['message_sent'] ?? null) !== false
        || ($collection['sensitive_values_exposed'] ?? null) !== false
    ) {
        throw new RuntimeException('molanxin_pms_collection_unverified');
    }

    $digest = $digestService->build($tenantId, $hotelId, $targetDate, []);
    $brief = (new SingleHotelOperatingBriefService())->preview($digest);
    $sources = is_array($digest['sources'] ?? null) ? $digest['sources'] : [];
    $previewStatus = (string)($brief['status'] ?? '') === 'preview_ready'
        ? ((string)($digest['status'] ?? '') === 'ready' ? 'ready' : 'partial')
        : 'blocked';
    $fingerprint = hash('sha256', json_encode([
        'tenant_id' => $tenantId,
        'hotel_id' => $hotelId,
        'business_date' => $targetDate,
        'sources' => $sources,
        'gaps' => $digest['gaps'] ?? [],
        'blockers' => $digest['blockers'] ?? [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $summary = [
        'stage' => 'three_source_preview',
        'business_date' => $targetDate,
        'capture_id' => (int)$collection['capture_id'],
        'operating_target_record_id' => (int)(
            $collection['operating_target_record_id'] ?? 0
        ),
        'operating_target_revision_no' => (int)(
            $collection['operating_target_revision_no'] ?? 0
        ),
        'operating_target_status' => (string)(
            $collection['operating_target_status'] ?? 'missing'
        ),
        'collection_status' => (string)$collection['status'],
        'digest_status' => (string)($digest['status'] ?? 'blocked'),
        'preview_status' => $previewStatus,
        'source_gate_passed' => ($brief['source_gate_passed'] ?? false) === true,
        'pms_status' => (string)($sources['pms']['status'] ?? 'missing'),
        'ctrip_status' => (string)($sources['ctrip']['status'] ?? 'missing'),
        'meituan_status' => (string)($sources['meituan']['status'] ?? 'missing'),
        'digest_contract_version' => (string)($digest['contract_version'] ?? ''),
        'brief_contract_version' => (string)($brief['contract_version'] ?? ''),
        'preview_fingerprint' => $fingerprint,
        'gap_codes' => array_column((array)($digest['gaps'] ?? []), 'code'),
        'blocker_codes' => array_column((array)($digest['blockers'] ?? []), 'code'),
    ];
    $run = $runs->finish(
        $runId,
        'completed',
        $previewStatus,
        $summary,
        new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'))
    );
    $output = [
        'status' => $previewStatus === 'blocked'
            ? 'three_source_preview_blocked'
            : 'three_source_preview_ready',
        'run_id' => $runId,
        'runner_mode' => 'collection_only',
        'hotel_id' => $hotelId,
        'business_date' => $targetDate,
        'capture_id' => (int)$collection['capture_id'],
        'operating_target_record_id' => (int)(
            $collection['operating_target_record_id'] ?? 0
        ),
        'digest_status' => (string)($digest['status'] ?? 'blocked'),
        'preview_status' => $previewStatus,
        'source_gate_passed' => ($brief['source_gate_passed'] ?? false) === true,
        'source_statuses' => [
            'pms' => (string)($sources['pms']['status'] ?? 'missing'),
            'ctrip' => (string)($sources['ctrip']['status'] ?? 'missing'),
            'meituan' => (string)($sources['meituan']['status'] ?? 'missing'),
        ],
        'preview_fingerprint' => $fingerprint,
        'message_preview' => (string)($brief['content'] ?? ''),
        'run_readback_status' => (string)$run['status'],
        'dispatch_requested' => false,
        'preview_only' => true,
        'message_sent' => false,
        'webhook_read' => false,
        'sensitive_values_exposed' => false,
    ];
    echo json_encode(
        $output,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    exit($previewStatus === 'blocked' ? 2 : 0);
} catch (Throwable $exception) {
    $reason = molanxinSafeReason($exception->getMessage());
    if ($runId > 0) {
        try {
            $runs->finish(
                $runId,
                'failed',
                'blocked',
                [
                    'stage' => 'collection_exception',
                    'reason_code' => $reason,
                    'business_date' => $targetDate,
                    'preview_status' => 'blocked',
                ],
                new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'))
            );
        } catch (Throwable) {
            $reason .= '_run_history_failed';
        }
    }
    molanxinFail($reason, 1, $runId);
}

/** @param array<int,string> $command @return array<string,mixed> */
function molanxinRunJsonProcess(array $command, string $workingDirectory): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('molanxin_collection_process_start_failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1], MOLANXIN_RUNNER_MAX_OUTPUT_BYTES + 1);
    $stderr = stream_get_contents($pipes[2], 4096);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $decoded = is_string($stdout) ? json_decode(trim($stdout), true) : null;
    if (!is_array($decoded)
        || !is_string($stdout)
        || strlen($stdout) > MOLANXIN_RUNNER_MAX_OUTPUT_BYTES
        || $exitCode !== 0
    ) {
        $error = is_string($stderr) ? json_decode(trim($stderr), true) : null;
        $reason = is_array($error) ? (string)($error['reason'] ?? '') : '';
        throw new RuntimeException(
            $reason !== '' ? $reason : 'molanxin_collection_process_failed'
        );
    }

    return $decoded;
}

function molanxinPositiveInt(mixed $value, string $reason): int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);
    if (!is_int($validated) || $validated <= 0) {
        molanxinFail($reason, 2);
    }

    return $validated;
}

function molanxinValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function molanxinSafeReason(string $reason): string
{
    $reason = preg_replace(
        '/(key|token|secret|cookie|password|authorization|webhook)\s*[=:]\s*[^\s,;]+/iu',
        '$1=<redacted>',
        strtolower(trim($reason))
    ) ?? '';

    return mb_strcut(
        $reason !== '' ? $reason : 'molanxin_collection_failed',
        0,
        240,
        'UTF-8'
    );
}

function molanxinFail(string $reason, int $exitCode, int $runId = 0): never
{
    fwrite(STDERR, json_encode([
        'status' => 'blocked',
        'reason' => molanxinSafeReason($reason),
        'run_id' => max(0, $runId),
        'runner_mode' => 'collection_only',
        'dispatch_requested' => false,
        'message_sent' => false,
        'webhook_read' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($exitCode);
}
