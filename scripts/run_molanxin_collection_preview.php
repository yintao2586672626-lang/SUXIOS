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
    'runtime-directory::',
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
$runtimeDirectory = rtrim(trim((string)($options['runtime-directory']
    ?? '/run/suxios-dingdandao-collection')), '/');
$phpBinary = trim((string)($options['php-binary'] ?? '/usr/bin/php'));
$nodeBinary = trim((string)($options['node-binary'] ?? '/usr/bin/node'));
if (preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $profileId) !== 1
    || !molanxinValidDate($targetDate)
    || $targetDate !== $today
    || !in_array($tokenFile, [
        '/run/credentials/suxios-dingdandao-collection.service/control-token',
        '/run/credentials/suxios-molanxin-three-source-collection.service/control-token',
    ], true)
    || !in_array($runtimeDirectory, [
        '/run/suxios-dingdandao-collection',
        '/run/suxios-molanxin-three-source-collection',
    ], true)
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
        '--runtime-directory=' . $runtimeDirectory,
        '--node-binary=' . $nodeBinary,
        '--collector-script=' . $root . '/scripts/run_dingdandao_cloud_collection.php',
        '--collection-only',
    ], $root);
    if (!in_array((string)($collection['status'] ?? ''), [
        'saved_capture_and_base_facts_ready',
    ], true)
        || (int)($collection['capture_id'] ?? 0) <= 0
        || (string)($collection['target_date'] ?? '') !== $targetDate
        || (int)($collection['hotel_id'] ?? 0) !== $hotelId
        || (string)($collection['runner_mode'] ?? '') !== 'collection_only'
        || ($collection['base_fact_ready'] ?? null) !== true
        || (int)($collection['operating_target_record_id'] ?? -1) !== 0
        || (string)($collection['operating_target_sync_status'] ?? '')
            !== 'skipped_collection_only'
        || ($collection['message_sent'] ?? null) !== false
        || ($collection['sensitive_values_exposed'] ?? null) !== false
    ) {
        throw new RuntimeException('molanxin_pms_collection_unverified');
    }

    $digest = $digestService->build($tenantId, $hotelId, $targetDate, []);
    $brief = (new SingleHotelOperatingBriefService())->preview($digest);
    $sources = is_array($digest['sources'] ?? null) ? $digest['sources'] : [];
    $sourceReadiness = [
        'pms' => ($sources['pms']['delivery_evidence_ready'] ?? false) === true,
        'ctrip' => ($sources['ctrip']['delivery_evidence_ready'] ?? false) === true,
        'meituan' => ($sources['meituan']['delivery_evidence_ready'] ?? false) === true,
    ];
    $pmsLineage = is_array($sources['pms']['lineage'] ?? null)
        ? $sources['pms']['lineage']
        : [];
    $ctripLineage = is_array($sources['ctrip']['lineage'] ?? null)
        ? $sources['ctrip']['lineage']
        : [];
    $meituanLineage = is_array($sources['meituan']['lineage'] ?? null)
        ? $sources['meituan']['lineage']
        : [];
    $sourceLineage = [
        'pms' => [
            'capture_ids' => molanxinPositiveIds([
                $pmsLineage['capture_id'] ?? $collection['capture_id'] ?? null,
            ]),
            'captured_at' => molanxinSafeTimestamp($pmsLineage['captured_at'] ?? null),
        ],
        'ctrip' => [
            'row_ids' => molanxinPositiveIds($ctripLineage['row_ids'] ?? []),
            'data_source_ids' => molanxinPositiveIds(
                $ctripLineage['data_source_ids'] ?? []
            ),
            'source_trace_ids' => molanxinSafeTraceIds(
                $ctripLineage['source_trace_ids'] ?? []
            ),
            'collected_at' => molanxinSafeTimestamp(
                $ctripLineage['collected_at'] ?? null
            ),
        ],
        'meituan' => [
            'row_ids' => molanxinPositiveIds([
                $meituanLineage['traffic_row_id'] ?? null,
                $meituanLineage['order_row_id'] ?? null,
            ]),
            'data_source_ids' => molanxinPositiveIds([
                $meituanLineage['data_source_id'] ?? null,
            ]),
            'source_trace_ids' => molanxinSafeTraceIds(
                $meituanLineage['source_trace_ids'] ?? []
            ),
            'traffic_collected_at' => molanxinSafeTimestamp(
                $meituanLineage['collected_at'] ?? null
            ),
            'order_collected_at' => molanxinSafeTimestamp(
                $meituanLineage['order_collected_at'] ?? null
            ),
        ],
    ];
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
        'pms_evidence_ready' => $sourceReadiness['pms'],
        'ctrip_evidence_ready' => $sourceReadiness['ctrip'],
        'meituan_evidence_ready' => $sourceReadiness['meituan'],
        'pms_capture_ids' => $sourceLineage['pms']['capture_ids'],
        'pms_captured_at' => $sourceLineage['pms']['captured_at'],
        'ctrip_row_ids' => $sourceLineage['ctrip']['row_ids'],
        'ctrip_data_source_ids' => $sourceLineage['ctrip']['data_source_ids'],
        'ctrip_source_trace_ids' => $sourceLineage['ctrip']['source_trace_ids'],
        'ctrip_collected_at' => $sourceLineage['ctrip']['collected_at'],
        'meituan_row_ids' => $sourceLineage['meituan']['row_ids'],
        'meituan_data_source_ids' => $sourceLineage['meituan']['data_source_ids'],
        'meituan_source_trace_ids' => $sourceLineage['meituan']['source_trace_ids'],
        'meituan_traffic_collected_at' =>
            $sourceLineage['meituan']['traffic_collected_at'],
        'meituan_order_collected_at' =>
            $sourceLineage['meituan']['order_collected_at'],
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
        'source_readiness' => $sourceReadiness,
        'source_lineage' => $sourceLineage,
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

/** @return array<int,int> */
function molanxinPositiveIds(mixed $value): array
{
    $values = is_array($value) ? $value : [$value];
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $values),
        static fn(int $id): bool => $id > 0
    )));
    sort($ids, SORT_NUMERIC);

    return $ids;
}

/** @return array<int,string> */
function molanxinSafeTraceIds(mixed $value): array
{
    $values = is_array($value) ? $value : [$value];
    $result = [];
    foreach ($values as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === ''
            || preg_match('/^[A-Za-z0-9._:-]{1,160}$/D', $candidate) !== 1
        ) {
            continue;
        }
        $result[] = $candidate;
    }

    return array_values(array_unique($result));
}

function molanxinSafeTimestamp(mixed $value): ?string
{
    $value = trim((string)$value);
    if ($value === ''
        || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:[.+-].*)?$/D', $value) !== 1
    ) {
        return null;
    }

    return mb_strcut($value, 0, 64, 'UTF-8');
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
