#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\ManualNotificationPipelineRunService;
use app\service\ManualNotificationDispatchLedgerService;
use app\service\ManualNotificationTestTargetService;
use think\App;
use think\facade\Db;

const PIPELINE_MAX_OUTPUT_BYTES = 2_000_000;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new App($root))->initialize();

$options = getopt('', [
    'hotel-id:',
    'robot-id:',
    'owner-user-id:',
    'profile-id:',
    'control-token-file::',
    'php-binary::',
    'node-binary::',
    'limit::',
]);
$hotelId = pipelinePositiveInt($options['hotel-id'] ?? null, 'pipeline_hotel_id_invalid');
$robotId = pipelinePositiveInt($options['robot-id'] ?? null, 'pipeline_robot_id_invalid');
$ownerUserId = pipelinePositiveInt(
    $options['owner-user-id'] ?? null,
    'pipeline_owner_user_id_invalid'
);
$profileId = pipelineOpaqueId(
    (string)($options['profile-id'] ?? ''),
    'cbp_',
    'pipeline_profile_id_invalid'
);
$tokenFile = trim((string)($options['control-token-file']
    ?? '/run/credentials/suxios-dingdandao-notification-pipeline.service/control-token'));
$phpBinary = trim((string)($options['php-binary'] ?? '/usr/bin/php'));
$nodeBinary = trim((string)($options['node-binary'] ?? '/usr/bin/node'));
$limit = min(20, pipelinePositiveInt($options['limit'] ?? 5, 'pipeline_limit_invalid'));
if ($tokenFile !== '/run/credentials/suxios-dingdandao-notification-pipeline.service/control-token'
    || $phpBinary !== '/usr/bin/php'
    || $nodeBinary !== '/usr/bin/node'
) {
    pipelineFail('pipeline_runtime_arguments_invalid', 2);
}

$hotel = Db::name('hotels')
    ->where('id', $hotelId)
    ->field('id,tenant_id,name,status')
    ->find();
$target = (new ManualNotificationTestTargetService())->resolve($hotelId, $robotId);
if (!is_array($hotel)
    || (int)($hotel['tenant_id'] ?? 0) <= 0
    || (int)($hotel['status'] ?? 0) !== 1
    || $target === null
    || (int)($target['hotel_id'] ?? 0) !== $hotelId
    || (int)($target['robot_id'] ?? 0) !== $robotId
    || (string)($target['notification_scope'] ?? '')
        !== ManualNotificationTestTargetService::TEST_SCOPE
) {
    pipelineFail('pipeline_verified_test_scope_missing');
}

$lockPath = '/run/suxios-dingdandao-pipeline/hotel-' . $hotelId . '.lock';
if (!is_dir(dirname($lockPath))) {
    pipelineFail('pipeline_lock_directory_missing');
}
$lock = fopen($lockPath, 'c+');
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    pipelineFail('pipeline_already_running');
}

$timezone = new DateTimeZone('Asia/Shanghai');
$observedAt = new DateTimeImmutable('now', $timezone);
$observedAtArgument = '--at=' . $observedAt->format('Y-m-d H:i:s');
$businessDate = $observedAt->format('Y-m-d');
$runs = new ManualNotificationPipelineRunService();
$pipelineRunId = 0;
$dispatchRequested = false;
$dispatchStarted = false;
try {
    $staleSendingCount = (new ManualNotificationDispatchLedgerService())
        ->markStaleSendingAsOutcomeUnknown($hotelId, $robotId, $observedAt);
    if ($staleSendingCount > 0) {
        $pipelineRunId = $runs->start($hotelId, $robotId, $observedAt);
        $runs->finish($pipelineRunId, 'blocked', false, [
            'stage' => 'stale_reconciliation',
            'reason_code' => 'pipeline_stale_sending_outcome_unknown',
            'business_date' => $businessDate,
            'blocked_count' => $staleSendingCount,
            'stale_sending_outcome_unknown_count' => $staleSendingCount,
        ], $observedAt);
        $blockedRunId = $pipelineRunId;
        $pipelineRunId = 0;
        pipelineFail(
            'pipeline_stale_sending_outcome_unknown',
            2,
            $blockedRunId
        );
    }

    $preview = pipelineRunJsonProcess([
        $phpBinary,
        $root . '/think',
        'manual-notification:schedule',
        '--preview',
        '--mode=test',
        '--hotel-id=' . $hotelId,
        '--robot-id=' . $robotId,
        $observedAtArgument,
        '--limit=' . $limit,
    ], $root);
    $candidateCount = (int)($preview['candidate_count'] ?? 0);
    $dueCount = (int)($preview['due_count'] ?? 0);
    if (($preview['status'] ?? '') !== 'preview'
        || (string)($preview['observed_at'] ?? '')
            !== $observedAt->format('Y-m-d H:i:s')
        || ($dueCount > 0
            && pipelineDueResultKeys($preview, $observedAt) === null)
    ) {
        throw new RuntimeException('pipeline_due_preview_invalid');
    }
    if ($dueCount <= 0) {
        echo pipelineJson([
            'status' => 'not_due',
            'pipeline_run_id' => 0,
            'schedule_run_id' => (int)($preview['schedule_run_id'] ?? 0),
            'business_date' => $businessDate,
            'candidate_count' => $candidateCount,
            'due_count' => 0,
            'message_sent' => false,
            'sensitive_values_exposed' => false,
        ]) . PHP_EOL;
        exit(0);
    }
    $pipelineRunId = $runs->start($hotelId, $robotId, $observedAt);
    $dispatchRequested = true;

    $collection = pipelineRunJsonProcess([
        $phpBinary,
        $root . '/scripts/run_dingdandao_profile_lease_collection.php',
        '--hotel-id=' . $hotelId,
        '--owner-user-id=' . $ownerUserId,
        '--profile-id=' . $profileId,
        '--target-date=' . $businessDate,
        '--control-token-file=' . $tokenFile,
        '--node-binary=' . $nodeBinary,
        '--collector-script=' . $root . '/scripts/run_dingdandao_cloud_collection.php',
    ], $root);
    if (($collection['report_send_eligible'] ?? false) !== true
        || ($collection['status'] ?? '') !== 'saved_synced_and_report_ready'
    ) {
        $reason = (string)($collection['status'] ?? 'pipeline_report_gate_blocked');
        $runs->finish($pipelineRunId, 'blocked', true, [
            'stage' => 'report_gate',
            'reason_code' => $reason,
            'business_date' => $businessDate,
            'candidate_count' => $candidateCount,
            'due_count' => $dueCount,
            'blocked_count' => $dueCount,
            'capture_id' => (int)($collection['capture_id'] ?? 0),
            'operating_target_record_id' => (int)(
                $collection['operating_target_record_id'] ?? 0
            ),
            'operating_target_revision_no' => (int)(
                $collection['operating_target_revision_no'] ?? 0
            ),
            'operating_target_status' => (string)(
                $collection['operating_target_status'] ?? 'partial'
            ),
            'collection_status' => (string)($collection['status'] ?? 'blocked'),
        ], new DateTimeImmutable('now', $timezone));
        pipelineFail($reason, 2, $pipelineRunId);
    }

    $dispatchStarted = true;
    $dispatch = pipelineRunJsonProcess([
        $phpBinary,
        $root . '/think',
        'manual-notification:schedule',
        '--dispatch',
        '--mode=test',
        '--hotel-id=' . $hotelId,
        '--robot-id=' . $robotId,
        $observedAtArgument,
        '--limit=' . $limit,
    ], $root, true);
    $dispatchStatus = (string)($dispatch['status'] ?? '');
    $sentCount = (int)($dispatch['sent_count'] ?? 0);
    $failedCount = (int)($dispatch['failed_count'] ?? 0);
    $blockedCount = (int)($dispatch['blocked_count'] ?? 0);
    $dispatchClosureReason = pipelineDispatchClosureReason(
        $preview,
        $dispatch,
        $observedAt
    );
    if ($dispatchStatus !== 'dispatch_checked'
        || $failedCount > 0
        || $blockedCount > 0
        || $dispatchClosureReason !== null
    ) {
        $status = $failedCount > 0 || $dispatchClosureReason !== null
            ? 'failed'
            : 'blocked';
        $runs->finish($pipelineRunId, $status, true, [
            'stage' => 'wecom_dispatch',
            'reason_code' => $dispatchClosureReason
                ?? ($dispatchStatus !== ''
                    ? $dispatchStatus
                    : 'pipeline_dispatch_failed'),
            'business_date' => $businessDate,
            'candidate_count' => $candidateCount,
            'due_count' => $dueCount,
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'blocked_count' => $blockedCount,
            'capture_id' => (int)$collection['capture_id'],
            'operating_target_record_id' => (int)$collection['operating_target_record_id'],
            'operating_target_revision_no' => (int)$collection['operating_target_revision_no'],
            'operating_target_status' => (string)$collection['operating_target_status'],
            'collection_status' => (string)$collection['status'],
            'schedule_run_id' => (int)($dispatch['schedule_run_id'] ?? 0),
        ], new DateTimeImmutable('now', $timezone));
        pipelineFail(
            $dispatchClosureReason
                ?? ($dispatchStatus !== ''
                    ? $dispatchStatus
                    : 'pipeline_dispatch_failed'),
            2,
            $pipelineRunId,
            $sentCount > 0,
            $sentCount
        );
    }

    $finished = $runs->finish($pipelineRunId, 'completed', true, [
        'stage' => 'completed',
        'reason_code' => $sentCount > 0
            ? 'wecom_test_sent'
            : 'dispatch_already_sent_verified',
        'business_date' => $businessDate,
        'candidate_count' => $candidateCount,
        'due_count' => $dueCount,
        'sent_count' => $sentCount,
        'failed_count' => 0,
        'blocked_count' => 0,
        'capture_id' => (int)$collection['capture_id'],
        'operating_target_record_id' => (int)$collection['operating_target_record_id'],
        'operating_target_revision_no' => (int)$collection['operating_target_revision_no'],
        'operating_target_status' => (string)$collection['operating_target_status'],
        'collection_status' => (string)$collection['status'],
        'schedule_run_id' => (int)($dispatch['schedule_run_id'] ?? 0),
    ], new DateTimeImmutable('now', $timezone));
    echo pipelineJson([
        'status' => $sentCount > 0 ? 'sent' : 'dispatch_checked_no_new_send',
        'pipeline_run_id' => $pipelineRunId,
        'business_date' => $businessDate,
        'capture_id' => (int)$collection['capture_id'],
        'operating_target_record_id' => (int)$collection['operating_target_record_id'],
        'operating_target_revision_no' => (int)$collection['operating_target_revision_no'],
        'schedule_run_id' => (int)($dispatch['schedule_run_id'] ?? 0),
        'sent_count' => $sentCount,
        'failed_count' => 0,
        'blocked_count' => 0,
        'message_sent' => $sentCount > 0,
        'sensitive_values_exposed' => false,
        'run_readback_status' => $finished['status'],
    ]) . PHP_EOL;
} catch (Throwable $error) {
    $reason = pipelineSafeReason($error->getMessage());
    if ($pipelineRunId > 0) {
        try {
            $runs->finish($pipelineRunId, 'failed', $dispatchRequested, [
                'stage' => 'pipeline_exception',
                'reason_code' => $reason,
                'business_date' => $businessDate,
                'failed_count' => 1,
            ], new DateTimeImmutable('now', $timezone));
        } catch (Throwable) {
            $reason .= '_run_history_failed';
        }
    }
    pipelineFail(
        $reason,
        1,
        $pipelineRunId,
        $dispatchStarted ? null : false,
        $dispatchStarted ? null : 0
    );
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

/**
 * @param array<int,string> $command
 * @return array<string,mixed>
 */
function pipelineRunJsonProcess(
    array $command,
    string $workingDirectory,
    bool $acceptNonZeroJson = false
): array {
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
        throw new RuntimeException('pipeline_process_start_failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1], PIPELINE_MAX_OUTPUT_BYTES + 1);
    $stderr = stream_get_contents($pipes[2], 8192);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $decoded = is_string($stdout) ? json_decode(trim($stdout), true) : null;
    if (!is_array($decoded)
        || !is_string($stdout)
        || strlen($stdout) > PIPELINE_MAX_OUTPUT_BYTES
        || ($exitCode !== 0 && !$acceptNonZeroJson)
    ) {
        $error = is_string($stderr) ? json_decode(trim($stderr), true) : null;
        $reason = is_array($error) ? (string)($error['reason'] ?? '') : '';
        throw new RuntimeException(
            $reason !== '' ? $reason : 'pipeline_process_failed'
        );
    }
    return $decoded;
}

function pipelinePositiveInt(mixed $value, string $reason): int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);
    if (!is_int($validated) || $validated <= 0) {
        pipelineFail($reason, 2);
    }
    return $validated;
}

function pipelineOpaqueId(string $value, string $prefix, string $reason): string
{
    $value = trim($value);
    if (preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9_-]{16,64}$/D', $value) !== 1) {
        pipelineFail($reason, 2);
    }
    return $value;
}

/**
 * The preview and dispatch must describe the same exact due set at the same
 * observed time. A zero-send result is successful only when every due item has
 * durable evidence that the same dispatch window was already sent.
 *
 * @param array<string,mixed> $preview
 * @param array<string,mixed> $dispatch
 */
function pipelineDispatchClosureReason(
    array $preview,
    array $dispatch,
    DateTimeImmutable $observedAt
): ?string {
    $expectedObservedAt = $observedAt
        ->setTimezone(new DateTimeZone('Asia/Shanghai'))
        ->format('Y-m-d H:i:s');
    if ((string)($dispatch['observed_at'] ?? '') !== $expectedObservedAt) {
        return 'pipeline_dispatch_observed_at_mismatch';
    }
    $expectedDueCount = (int)($preview['due_count'] ?? 0);
    if ($expectedDueCount <= 0
        || (int)($dispatch['due_count'] ?? -1) !== $expectedDueCount
    ) {
        return 'pipeline_dispatch_due_count_mismatch';
    }

    $expectedKeys = pipelineDueResultKeys($preview, $observedAt);
    $actualKeys = pipelineDueResultKeys($dispatch, $observedAt);
    if ($expectedKeys === null
        || $actualKeys === null
        || $expectedKeys !== $actualKeys
    ) {
        return 'pipeline_dispatch_due_set_mismatch';
    }

    $sentResultCount = 0;
    $alreadySentCount = 0;
    foreach ((array)($dispatch['results'] ?? []) as $result) {
        if (!is_array($result)) {
            return 'pipeline_dispatch_result_invalid';
        }
        $status = (string)($result['status'] ?? '');
        if ($status === 'sent') {
            $sentResultCount++;
            continue;
        }
        if ($status === 'skipped'
            && (string)($result['existing_status'] ?? '') === 'sent'
        ) {
            $alreadySentCount++;
            continue;
        }
        return (int)($dispatch['sent_count'] ?? 0) === 0
            ? 'pipeline_dispatch_zero_send_unverified'
            : 'pipeline_dispatch_result_unclosed';
    }
    $sentCount = (int)($dispatch['sent_count'] ?? -1);
    if ($sentCount === 0 && $alreadySentCount !== $expectedDueCount) {
        return 'pipeline_dispatch_zero_send_unverified';
    }
    if ($sentResultCount !== $sentCount
        || $sentResultCount + $alreadySentCount !== $expectedDueCount
    ) {
        return 'pipeline_dispatch_result_count_mismatch';
    }
    return null;
}

/**
 * @param array<string,mixed> $summary
 * @return array<int,string>|null
 */
function pipelineDueResultKeys(
    array $summary,
    DateTimeImmutable $observedAt
): ?array {
    $businessDate = $observedAt
        ->setTimezone(new DateTimeZone('Asia/Shanghai'))
        ->format('Y-m-d');
    $results = $summary['results'] ?? null;
    if (!is_array($results)
        || count($results) !== (int)($summary['due_count'] ?? -1)
    ) {
        return null;
    }
    $keys = [];
    foreach ($results as $result) {
        if (!is_array($result)) {
            return null;
        }
        $notificationId = (int)($result['notification_id'] ?? 0);
        $dispatchWindow = trim((string)($result['dispatch_window'] ?? ''));
        if ($notificationId <= 0
            || (string)($result['business_date'] ?? '') !== $businessDate
            || preg_match(
                '/^' . preg_quote($businessDate, '/') . ' \d{2}:\d{2}$/D',
                $dispatchWindow
            ) !== 1
        ) {
            return null;
        }
        $keys[] = $notificationId . '|' . $dispatchWindow;
    }
    sort($keys, SORT_STRING);
    return $keys;
}

function pipelineSafeReason(string $reason): string
{
    return preg_replace('/[^a-zA-Z0-9_-]+/', '_', $reason)
        ?: 'dingdandao_notification_pipeline_failed';
}

/** @param array<string,mixed> $value */
function pipelineJson(array $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
}

function pipelineFail(
    string $reason,
    int $exitCode = 1,
    int $pipelineRunId = 0,
    ?bool $messageSent = false,
    ?int $sentCount = 0
): never {
    fwrite(STDERR, pipelineJson([
        'status' => 'blocked',
        'reason' => pipelineSafeReason($reason),
        'pipeline_run_id' => $pipelineRunId,
        'message_sent' => $messageSent,
        'sent_count' => $sentCount,
        'sensitive_values_exposed' => false,
    ]) . PHP_EOL);
    exit($exitCode);
}
