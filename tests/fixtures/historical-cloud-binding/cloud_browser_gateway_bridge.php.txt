#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\CloudBrowserProfileService;
use app\service\DingdandaoCloudCollectionService;
use think\App;

const MAX_GATEWAY_INPUT_BYTES = 8192;

$appDir = dirname(__DIR__);
$autoload = $appDir . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fail('app_autoload_missing');
}

$raw = stream_get_contents(STDIN, MAX_GATEWAY_INPUT_BYTES + 1);
if (!is_string($raw) || $raw === '' || strlen($raw) > MAX_GATEWAY_INPUT_BYTES) {
    fail('gateway_input_invalid');
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    fail('gateway_input_invalid');
}

require $autoload;
(new App($appDir))->initialize();

$action = strtolower(trim((string)($input['action'] ?? '')));
$service = new CloudBrowserProfileService();
$dingdandaoCollection = new DingdandaoCloudCollectionService();

try {
    $result = match ($action) {
        'validate_login' => $service->validateLoginEntry(
            requiredId($input, 'profile_id', 'cbp_'),
            requiredId($input, 'session_id', 'cbls_'),
            requiredTicket($input)
        ),
        'verify_dingdandao_login_identity' =>
            verifyDingdandaoLoginIdentity(
                $service,
                $dingdandaoCollection,
                $input,
                $appDir
            ),
        'complete_login' => $service->completeGatewayLogin(
            requiredId($input, 'profile_id', 'cbp_'),
            requiredId($input, 'session_id', 'cbls_'),
            requiredTicket($input),
            requiredText($input, 'session_expires_at', 32)
        ),
        'login_status' => $service->loginSessionStatus(
            requiredId($input, 'profile_id', 'cbp_'),
            requiredId($input, 'session_id', 'cbls_'),
            requiredText($input, 'platform', 24)
        ),
        'expire_login' => $service->expireGatewayLogin(
            requiredId($input, 'profile_id', 'cbp_'),
            requiredId($input, 'session_id', 'cbls_'),
            requiredTicket($input),
            requiredText($input, 'reason', 80)
        ),
        'expire_profile' => $service->markSessionExpired(
            requiredId($input, 'profile_id', 'cbp_'),
            requiredText($input, 'reason', 80)
        ),
        'validate_dingdandao_collection' => $service->validateDingdandaoCollectionProfile(
            requiredId($input, 'profile_id', 'cbp_'),
            requiredPositiveInt($input, 'tenant_id'),
            requiredPositiveInt($input, 'hotel_id'),
            requiredPositiveInt($input, 'owner_user_id'),
            requiredDate($input, 'target_date')
        ),
        'validate_dingdandao_binding_lease' => $dingdandaoCollection->bindingBootstrapScope(
            requiredId($input, 'profile_id', 'cbp_'),
            requiredPositiveInt($input, 'tenant_id'),
            requiredPositiveInt($input, 'hotel_id'),
            requiredPositiveInt($input, 'owner_user_id')
        ),
        'activate_dingdandao_binding' =>
            $dingdandaoCollection->activateVerifiedBinding(
                requiredId($input, 'profile_id', 'cbp_'),
                requiredId($input, 'profile_lease_id', 'cbpl_'),
                requiredId($input, 'receipt_id', 'cbr_'),
                requiredSha256($input, 'receipt_hash'),
                requiredPositiveInt($input, 'tenant_id'),
                requiredPositiveInt($input, 'hotel_id'),
                requiredPositiveInt($input, 'owner_user_id'),
                requiredDate($input, 'target_date'),
                requiredSha256(
                    $input,
                    'provider_hotel_id_fingerprint'
                )
            ),
        'claim_dingdandao_collection' => $dingdandaoCollection->claim(
            requiredId($input, 'profile_id', 'cbp_'),
            requiredId($input, 'collection_session_id', 'cbcs_'),
            requiredPositiveInt($input, 'tenant_id'),
            requiredPositiveInt($input, 'hotel_id'),
            requiredPositiveInt($input, 'owner_user_id'),
            requiredDate($input, 'target_date'),
            requiredText($input, 'collection_kind', 40),
            requiredText($input, 'access_mode', 20),
            requiredText($input, 'window_expires_at', 40)
        ),
        'complete_dingdandao_collection' => $dingdandaoCollection->completeLifecycle(
            requiredId($input, 'claim_id', 'cct_'),
            requiredId($input, 'collection_session_id', 'cbcs_'),
            requiredId($input, 'profile_id', 'cbp_'),
            requiredText($input, 'outcome', 32)
        ),
        default => throw new RuntimeException('gateway_action_unsupported'),
    };
} catch (Throwable $e) {
    fail($e->getMessage() !== '' ? $e->getMessage() : 'gateway_action_failed');
}

echo json_encode(
    ['status' => 'ok', 'action' => $action, 'result' => $result],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . PHP_EOL;

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function verifyDingdandaoLoginIdentity(
    CloudBrowserProfileService $profiles,
    DingdandaoCloudCollectionService $collections,
    array $input,
    string $appDir
): array {
    $profileId = requiredId($input, 'profile_id', 'cbp_');
    $sessionId = requiredId($input, 'session_id', 'cbls_');
    $ticket = requiredTicket($input);
    $login = $profiles->validateLoginEntry(
        $profileId,
        $sessionId,
        $ticket
    );
    $profile = is_array($login['profile'] ?? null)
        ? $login['profile']
        : [];
    if (($login['login_entry']['validated'] ?? null) !== true
        || ($login['login_entry']['consumed'] ?? null) !== false
        || (string)($profile['profile_id'] ?? '') !== $profileId
        || (string)($profile['platform'] ?? '') !== 'dingdandao'
    ) {
        throw new RuntimeException(
            'dingdandao_login_identity_entry_unverified'
        );
    }

    $scope = $collections->loginIdentityScope($profileId);
    $expectedHotelName = trim((string)(
        $scope['expected_provider_hotel_name'] ?? ''
    ));
    if (($scope['status'] ?? '') !== 'ready_for_login_identity_probe'
        || ($scope['provider'] ?? '') !== 'dingdandao'
        || (string)($scope['profile_id'] ?? '') !== $profileId
        || (int)($scope['hotel_id'] ?? 0)
            !== (int)($profile['hotel_id'] ?? 0)
        || $expectedHotelName === ''
    ) {
        throw new RuntimeException(
            'dingdandao_login_identity_scope_unverified'
        );
    }

    $nodeBinary = trim((string)(
        getenv('SUXIOS_CLOUD_BROWSER_NODE_BINARY') ?: '/usr/bin/node'
    ));
    $appDirReal = realpath($appDir);
    $probeScript = realpath(
        $appDir . '/scripts/dingdandao_binding_probe.mjs'
    );
    if ($nodeBinary !== '/usr/bin/node'
        || !is_string($appDirReal)
        || !is_string($probeScript)
        || $probeScript !== $appDirReal
            . '/scripts/dingdandao_binding_probe.mjs'
    ) {
        throw new RuntimeException(
            'dingdandao_login_identity_runtime_unavailable'
        );
    }

    $pipes = [];
    $process = proc_open(
        [
            $nodeBinary,
            '--experimental-websocket',
            $probeScript,
            '--cdp-url=http://127.0.0.1:9223',
            '--expected-hotel-name=' . $expectedHotelName,
            '--timeout-ms=12000',
            '--identity-fd=3',
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
            3 => ['pipe', 'w'],
        ],
        $pipes,
        dirname($probeScript),
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException(
            'dingdandao_login_identity_probe_start_failed'
        );
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1], 65_537);
    $stderr = stream_get_contents($pipes[2], 4097);
    $privateIdentity = stream_get_contents($pipes[3], 65_537);
    fclose($pipes[1]);
    fclose($pipes[2]);
    fclose($pipes[3]);
    $exitCode = proc_close($process);
    try {
        if ($exitCode !== 0
            || !is_string($stdout)
            || strlen($stdout) > 65_536
            || !is_string($privateIdentity)
            || strlen($privateIdentity) > 65_536
        ) {
            $error = is_string($stderr)
                ? json_decode(trim($stderr), true)
                : null;
            $reason = is_array($error)
                ? (string)($error['reason'] ?? '')
                : '';
            throw new RuntimeException(
                $reason !== ''
                    ? $reason
                    : 'dingdandao_login_identity_probe_failed'
            );
        }

        $summary = json_decode(trim($stdout), true);
        $identity = json_decode(trim($privateIdentity), true);
        if (!is_array($summary)
            || !is_array($identity)
            || ($summary['status'] ?? '')
                !== 'identity_verified_unpersisted'
            || ($summary['session_material_exposed'] ?? null) !== false
            || ($summary['raw_response_exposed'] ?? null) !== false
            || ($summary['browser_process_started'] ?? null) !== false
            || ($summary['user_tabs_closed'] ?? null) !== false
            || trim((string)($identity['provider_hotel_id'] ?? '')) === ''
            || !hash_equals(
                $expectedHotelName,
                trim((string)($identity['provider_hotel_name'] ?? ''))
            )
            || ($identity['identity_status'] ?? '') !== 'matched'
            || ($identity['source_api_path'] ?? '')
                !== '/v2/ntw/web/ntw/get'
            || (int)($identity['request_count'] ?? 0) !== 1
        ) {
            throw new RuntimeException(
                'dingdandao_login_identity_unverified'
            );
        }

        return [
            'validated' => true,
            'profile_id' => $profileId,
            'session_id' => $sessionId,
            'platform' => 'dingdandao',
            'hotel_id' => (int)$scope['hotel_id'],
            'provider_hotel_name' => $expectedHotelName,
            'identity_status' => 'matched',
            'source_api_path' => '/v2/ntw/web/ntw/get',
            'capture_method' => (string)(
                $identity['capture_method'] ?? 'network_response'
            ),
            'request_count' => 1,
            'captured_at' => (string)($identity['captured_at'] ?? ''),
            'binding_persisted' => false,
            'session_material_exposed' => false,
            'raw_response_exposed' => false,
            'user_tabs_closed' => false,
        ];
    } finally {
        if (isset($identity['provider_hotel_id'])
            && is_string($identity['provider_hotel_id'])
        ) {
            $identity['provider_hotel_id'] = str_repeat(
                "\0",
                strlen($identity['provider_hotel_id'])
            );
        }
        if (is_string($privateIdentity)) {
            $privateIdentity = str_repeat("\0", strlen($privateIdentity));
        }
        if (is_string($stdout)) {
            $stdout = str_repeat("\0", strlen($stdout));
        }
        if (is_string($stderr)) {
            $stderr = str_repeat("\0", strlen($stderr));
        }
    }
}

/** @param array<string,mixed> $input */
function requiredId(array $input, string $key, string $prefix): string
{
    $value = trim((string)($input[$key] ?? ''));
    if (preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9_-]{16,64}$/D', $value) !== 1) {
        throw new RuntimeException('gateway_' . $key . '_invalid');
    }
    return $value;
}

/** @param array<string,mixed> $input */
function requiredTicket(array $input): string
{
    $ticket = trim((string)($input['ticket'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_-]{32,96}$/D', $ticket) !== 1) {
        throw new RuntimeException('gateway_ticket_invalid');
    }
    return $ticket;
}

/** @param array<string,mixed> $input */
function requiredSha256(array $input, string $key): string
{
    $value = trim((string)($input[$key] ?? ''));
    if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
        throw new RuntimeException('gateway_' . $key . '_invalid');
    }
    return $value;
}

/** @param array<string,mixed> $input */
function requiredText(array $input, string $key, int $maxLength): string
{
    $value = trim((string)($input[$key] ?? ''));
    if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        throw new RuntimeException('gateway_' . $key . '_invalid');
    }
    return $value;
}

/** @param array<string,mixed> $input */
function requiredPositiveInt(array $input, string $key): int
{
    $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($value) || $value <= 0) {
        throw new RuntimeException('gateway_' . $key . '_invalid');
    }
    return $value;
}

/** @param array<string,mixed> $input */
function requiredDate(array $input, string $key): string
{
    $value = trim((string)($input[$key] ?? ''));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException('gateway_' . $key . '_invalid');
    }
    return $value;
}

function fail(string $reason): never
{
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'reason' => preg_replace('/[^a-zA-Z0-9_-]+/', '_', $reason) ?: 'gateway_action_failed',
    ]) . PHP_EOL);
    exit(1);
}
