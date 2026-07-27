#!/usr/bin/env php
<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

const PROFILE_LEASE_RUNNER_MAX_OUTPUT_BYTES = 2_000_000;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new App($root))->initialize();

$options = getopt('', [
    'hotel-id:',
    'owner-user-id:',
    'profile-id:',
    'target-date::',
    'gateway-url::',
    'cdp-url::',
    'control-token-file::',
    'runtime-directory::',
    'php-binary::',
    'node-binary::',
    'collector-script::',
    'collection-only',
]);
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
    ->format('Y-m-d');
$hotelId = leasePositiveInt($options['hotel-id'] ?? null, 'hotel_id_invalid');
$ownerUserId = leasePositiveInt(
    $options['owner-user-id'] ?? null,
    'owner_user_id_invalid'
);
$profileId = leaseOpaqueId(
    (string)($options['profile-id'] ?? ''),
    'cbp_',
    'profile_id_invalid'
);
$targetDate = trim((string)($options['target-date'] ?? $today));
$gatewayUrl = rtrim(
    trim((string)($options['gateway-url'] ?? 'http://127.0.0.1:8787')),
    '/'
);
$cdpUrl = rtrim(
    trim((string)($options['cdp-url'] ?? 'http://127.0.0.1:9223')),
    '/'
);
$tokenFile = trim((string)($options['control-token-file']
    ?? '/run/credentials/suxios-dingdandao-collection.service/control-token'));
$runtimeDirectory = rtrim(trim((string)($options['runtime-directory']
    ?? '/run/suxios-dingdandao-collection')), '/');
$phpBinary = trim((string)($options['php-binary'] ?? '/usr/bin/php'));
$nodeBinary = trim((string)($options['node-binary'] ?? '/usr/bin/node'));
$collectorScript = trim((string)($options['collector-script']
    ?? $root . '/scripts/run_dingdandao_cloud_collection.php'));
$collectionOnly = array_key_exists('collection-only', $options);
$expectedCollectorScript = realpath(
    $root . '/scripts/run_dingdandao_cloud_collection.php'
);
$resolvedCollectorScript = realpath($collectorScript);
$allowedTokenFiles = [
    '/run/credentials/suxios-dingdandao-collection.service/control-token',
    '/run/credentials/suxios-dingdandao-notification-pipeline.service/control-token',
    '/run/credentials/suxios-molanxin-three-source-collection.service/control-token',
    '/etc/suxios-cloud-browser/control-token',
];
$allowedRuntimeDirectories = [
    '/run/suxios-dingdandao-collection',
    '/run/suxios-molanxin-three-source-collection',
];
if (!leaseValidDate($targetDate)
    || $targetDate !== $today
    || $gatewayUrl !== 'http://127.0.0.1:8787'
    || $cdpUrl !== 'http://127.0.0.1:9223'
    || !in_array($tokenFile, $allowedTokenFiles, true)
    || !in_array($runtimeDirectory, $allowedRuntimeDirectories, true)
    || $phpBinary !== '/usr/bin/php'
    || $nodeBinary !== '/usr/bin/node'
    || !is_string($expectedCollectorScript)
    || !is_string($resolvedCollectorScript)
    || !hash_equals($expectedCollectorScript, $resolvedCollectorScript)
) {
    leaseFail('dingdandao_profile_lease_arguments_invalid', 2);
}

$hotel = Db::name('hotels')
    ->field('id,tenant_id,name,status')
    ->where('id', $hotelId)
    ->find();
if (!is_array($hotel)
    || (int)($hotel['tenant_id'] ?? 0) <= 0
    || (int)($hotel['status'] ?? 0) !== 1
    || trim((string)($hotel['name'] ?? '')) === ''
) {
    leaseFail('dingdandao_profile_lease_hotel_scope_invalid');
}
$tenantId = (int)$hotel['tenant_id'];

$controlToken = @file_get_contents($tokenFile);
$controlToken = is_string($controlToken) ? trim($controlToken) : '';
if (strlen($controlToken) < 32) {
    leaseFail('dingdandao_profile_lease_control_token_unavailable');
}

$profileLeaseId = null;
$profileLeaseStatus = 'not_opened_or_unverified';
$mainError = null;
$result = null;
$businessDataPersisted = false;
try {
    $opened = leaseGatewayRequest(
        $gatewayUrl,
        $controlToken,
        '/v1/profile-lease/open',
        [
            'profile_id' => $profileId,
            'platform' => 'dingdandao',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'owner_user_id' => $ownerUserId,
            'target_date' => $targetDate,
            'lease_kind' => 'daily_collection',
            'access_mode' => 'read_only',
        ]
    );
    if (($opened['status'] ?? '') !== 'profile_lease_open'
        || ($opened['browser_started'] ?? null) !== true
        || ($opened['profile_restored'] ?? null) !== true
        || ($opened['read_only_enforced'] ?? null) !== true
        || ($opened['session_owner'] ?? '') !== 'gateway_profile_lease'
        || ($opened['external_browser_required'] ?? null) !== false
        || ($opened['user_browser_closed'] ?? null) !== false
        || (int)($opened['tenant_id'] ?? 0) !== $tenantId
        || (int)($opened['hotel_id'] ?? 0) !== $hotelId
        || (int)($opened['owner_user_id'] ?? 0) !== $ownerUserId
        || (string)($opened['target_date'] ?? '') !== $targetDate
    ) {
        throw new RuntimeException('dingdandao_profile_lease_open_unverified');
    }
    $profileLeaseId = leaseOpaqueId(
        (string)($opened['profile_lease_id'] ?? ''),
        'cbpl_',
        'profile_lease_id_invalid'
    );
    $profileLeaseStatus = 'open';

    $collection = runCollectorProcess(
        $phpBinary,
        $collectorScript,
        $hotelId,
        $ownerUserId,
        $profileId,
        $targetDate,
        $gatewayUrl,
        $cdpUrl,
        $tokenFile,
        $runtimeDirectory,
        $nodeBinary,
        $collectionOnly
    );
    if (($collection['ok'] ?? false) !== true) {
        $businessDataPersisted = ($collection['business_data_persisted'] ?? false) === true;
        throw new RuntimeException(
            (string)($collection['reason'] ?? 'dingdandao_profile_lease_collector_failed')
        );
    }
    $result = is_array($collection['result'] ?? null)
        ? $collection['result']
        : null;
    if (!is_array($result)) {
        throw new RuntimeException('dingdandao_profile_lease_collector_output_invalid');
    }
    assertLeaseSafeOutput($result);
    $businessDataPersisted = (int)($result['capture_id'] ?? 0) > 0;
} catch (Throwable $error) {
    $mainError = leaseSafeReason($error->getMessage());
} finally {
    if ($profileLeaseId !== null) {
        try {
            $closed = leaseGatewayRequest(
                $gatewayUrl,
                $controlToken,
                '/v1/profile-lease/close',
                [
                    'profile_lease_id' => $profileLeaseId,
                    'profile_id' => $profileId,
                    'platform' => 'dingdandao',
                    'outcome' => $mainError === null ? 'completed' : 'failed',
                ]
            );
            if (($closed['status'] ?? '') !== 'profile_lease_closed'
                || ($closed['owned_browser_closed'] ?? null) !== true
                || ($closed['profile_encrypted_at_rest'] ?? null) !== true
                || ($closed['user_browser_closed'] ?? null) !== false
                || ($closed['sensitive_values_exposed'] ?? null) !== false
            ) {
                throw new RuntimeException('dingdandao_profile_lease_close_unverified');
            }
            $profileLeaseStatus = 'closed';
        } catch (Throwable $closeError) {
            $profileLeaseStatus = 'closure_unverified';
            $mainError = ($mainError ?? 'dingdandao_profile_lease_failed')
                . '_'
                . leaseSafeReason($closeError->getMessage());
            $result = null;
        }
    }
    $controlToken = str_repeat("\0", strlen($controlToken));
}

if ($mainError !== null || !is_array($result)) {
    leaseFail(
        $mainError ?? 'dingdandao_profile_lease_failed',
        1,
        $businessDataPersisted,
        $profileLeaseStatus
    );
}
$result['profile_lease_status'] = 'closed';
$result['profile_encrypted_at_rest'] = true;
$result['external_browser_required'] = false;
$result['user_browser_closed'] = false;
$result['sensitive_values_exposed'] = false;
echo json_encode(
    $result,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL;

/** @return array<string,mixed> */
function leaseGatewayRequest(
    string $baseUrl,
    string $token,
    string $path,
    array $body
): array {
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n"
                . "Authorization: Bearer {$token}\r\n",
            'content' => $json,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($baseUrl . $path, false, $context);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || ($decoded['status'] ?? '') === 'failed') {
        $reason = is_array($decoded) ? (string)($decoded['reason'] ?? '') : '';
        throw new RuntimeException(
            $reason !== '' ? $reason : 'dingdandao_profile_lease_gateway_failed'
        );
    }
    return $decoded;
}

/** @return array<string,mixed> */
function runCollectorProcess(
    string $phpBinary,
    string $collectorScript,
    int $hotelId,
    int $ownerUserId,
    string $profileId,
    string $targetDate,
    string $gatewayUrl,
    string $cdpUrl,
    string $tokenFile,
    string $runtimeDirectory,
    string $nodeBinary,
    bool $collectionOnly
): array {
    $command = [
        $phpBinary,
        $collectorScript,
        '--hotel-id=' . $hotelId,
        '--owner-user-id=' . $ownerUserId,
        '--profile-id=' . $profileId,
        '--target-date=' . $targetDate,
        '--gateway-url=' . $gatewayUrl,
        '--cdp-url=' . $cdpUrl,
        '--control-token-file=' . $tokenFile,
        '--runtime-directory=' . $runtimeDirectory,
        '--node-binary=' . $nodeBinary,
    ];
    if ($collectionOnly) {
        $command[] = '--collection-only';
    }
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname($collectorScript),
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return [
            'ok' => false,
            'reason' => 'dingdandao_profile_lease_collector_start_failed',
            'business_data_persisted' => false,
        ];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents(
        $pipes[1],
        PROFILE_LEASE_RUNNER_MAX_OUTPUT_BYTES + 1
    );
    $stderr = stream_get_contents($pipes[2], 4096);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if (!is_string($stdout)
        || strlen($stdout) > PROFILE_LEASE_RUNNER_MAX_OUTPUT_BYTES
        || $exitCode !== 0
    ) {
        $failure = is_string($stderr)
            ? json_decode(trim($stderr), true)
            : null;
        return [
            'ok' => false,
            'reason' => is_array($failure)
                ? leaseSafeReason((string)($failure['reason'] ?? ''))
                : 'dingdandao_profile_lease_collector_failed',
            'business_data_persisted' => is_array($failure)
                && ($failure['business_data_persisted'] ?? false) === true,
        ];
    }
    $decoded = json_decode(trim($stdout), true);
    return [
        'ok' => is_array($decoded),
        'reason' => is_array($decoded)
            ? ''
            : 'dingdandao_profile_lease_collector_output_invalid',
        'result' => is_array($decoded) ? $decoded : null,
        'business_data_persisted' => is_array($decoded)
            && (int)($decoded['capture_id'] ?? 0) > 0,
    ];
}

/** @param array<string,mixed> $value */
function assertLeaseSafeOutput(array $value): void
{
    $sensitive = '/cookie|password|authorization|token|secret|headers?|raw|html|har|'
        . 'profile[_-]?path|localstorage|sessionstorage/i';
    $walk = function (mixed $node) use (&$walk, $sensitive): void {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $entry) {
            if (is_string($key) && preg_match($sensitive, $key) === 1) {
                throw new RuntimeException('dingdandao_profile_lease_sensitive_output_rejected');
            }
            $walk($entry);
        }
    };
    $walk($value);
}

function leasePositiveInt(mixed $value, string $reason): int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);
    if (!is_int($validated) || $validated <= 0) {
        leaseFail($reason, 2);
    }
    return $validated;
}

function leaseOpaqueId(string $value, string $prefix, string $reason): string
{
    $value = trim($value);
    if (preg_match(
        '/^' . preg_quote($prefix, '/') . '[A-Za-z0-9_-]{16,64}$/D',
        $value
    ) !== 1) {
        leaseFail($reason, 2);
    }
    return $value;
}

function leaseValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable
        && $date->format('Y-m-d') === $value;
}

function leaseSafeReason(string $reason): string
{
    return preg_replace('/[^a-zA-Z0-9_-]+/', '_', $reason)
        ?: 'dingdandao_profile_lease_failed';
}

function leaseFail(
    string $reason,
    int $exitCode = 1,
    bool $businessDataPersisted = false,
    string $profileLeaseStatus = 'not_opened'
): never {
    fwrite(STDERR, json_encode([
        'status' => 'blocked',
        'reason' => leaseSafeReason($reason),
        'profile_lease_status' => $profileLeaseStatus,
        'business_data_persisted' => $businessDataPersisted,
        'message_sent' => false,
        'user_browser_closed' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($exitCode);
}
