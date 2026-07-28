#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\DingdandaoCloudCollectionService;
use think\App;
use think\facade\Db;

const MAX_BINDING_PROBE_OUTPUT_BYTES = 65_536;
const CLOUD_BROWSER_GATEWAY_PROTOCOL_VERSION =
    'suxios_cloud_browser_gateway.v2';

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new App($root))->initialize();

$options = getopt('', [
    'hotel-id:',
    'owner-user-id:',
    'profile-id:',
    'gateway-url::',
    'cdp-url::',
    'control-token-file::',
    'node-binary::',
    'execute',
    'confirmation::',
]);
$hotelId = positiveInt($options['hotel-id'] ?? null, 'hotel_id_invalid');
$ownerUserId = positiveInt(
    $options['owner-user-id'] ?? null,
    'owner_user_id_invalid'
);
$profileId = opaqueId(
    (string)($options['profile-id'] ?? ''),
    'cbp_',
    'profile_id_invalid'
);
$gatewayUrl = rtrim(
    trim((string)($options['gateway-url'] ?? 'http://127.0.0.1:8787')),
    '/'
);
$cdpUrl = rtrim(
    trim((string)($options['cdp-url'] ?? 'http://127.0.0.1:9223')),
    '/'
);
$tokenFile = trim((string)($options['control-token-file']
    ?? '/etc/suxios-cloud-browser/control-token'));
$nodeBinary = trim((string)($options['node-binary'] ?? '/usr/bin/node'));
$execute = array_key_exists('execute', $options);
$confirmation = trim((string)($options['confirmation'] ?? ''));
if ($gatewayUrl !== 'http://127.0.0.1:8787'
    || $cdpUrl !== 'http://127.0.0.1:9223'
    || $tokenFile !== '/etc/suxios-cloud-browser/control-token'
    || $nodeBinary !== '/usr/bin/node'
    || (!$execute && $confirmation !== '')
) {
    fail('dingdandao_binding_arguments_invalid', 2);
}
if ($execute
    && !hash_equals('BIND DINGDANDAO HOTEL ' . $hotelId, $confirmation)
) {
    fail('dingdandao_binding_confirmation_required', 2);
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
    fail('dingdandao_binding_hotel_scope_invalid');
}
$tenantId = (int)$hotel['tenant_id'];

$lockPath = '/run/suxios-dingdandao-collection/hotel-' . $hotelId . '.lock';
if (!is_dir(dirname($lockPath))) {
    fail('dingdandao_binding_lock_directory_missing');
}
$lock = fopen($lockPath, 'c+');
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    fail('dingdandao_binding_collection_already_running');
}

$probeOutput = '';
$privateIdentityOutput = '';
$identity = [];
$result = null;
$mainError = null;
$bindingPersistenceStatus = false;
$profileLeaseId = null;
$controlToken = @file_get_contents($tokenFile);
$controlToken = is_string($controlToken) ? trim($controlToken) : '';
if (strlen($controlToken) < 32) {
    fail('dingdandao_binding_control_token_unavailable');
}
try {
    assertBindingGatewayProtocol($gatewayUrl);
    $opened = bindingLeaseGatewayRequest(
        $gatewayUrl,
        $controlToken,
        '/v1/profile-lease/open',
        [
            'profile_id' => $profileId,
            'platform' => 'dingdandao',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'owner_user_id' => $ownerUserId,
            'target_date' => (new DateTimeImmutable(
                'now',
                new DateTimeZone('Asia/Shanghai')
            ))->format('Y-m-d'),
            'lease_kind' => 'binding_identity',
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
        || (string)($opened['lease_kind'] ?? '') !== 'binding_identity'
    ) {
        throw new RuntimeException('dingdandao_binding_profile_lease_unverified');
    }
    $profileLeaseId = trim((string)($opened['profile_lease_id'] ?? ''));
    if (preg_match('/^cbpl_[A-Za-z0-9_-]{16,64}$/D', $profileLeaseId) !== 1) {
        throw new RuntimeException('dingdandao_binding_profile_lease_id_invalid');
    }

    $service = new DingdandaoCloudCollectionService();
    $scope = $service->bindingBootstrapScope(
        $profileId,
        $tenantId,
        $hotelId,
        $ownerUserId
    );
    $expectedProviderHotelName = trim(
        (string)($scope['expected_provider_hotel_name'] ?? '')
    );
    if (($scope['status'] ?? '') !== 'ready_for_identity_probe'
        || ($scope['binding_persisted'] ?? null) !== false
        || (int)($scope['tenant_id'] ?? 0) !== $tenantId
        || (int)($scope['hotel_id'] ?? 0) !== $hotelId
        || (int)($scope['owner_user_id'] ?? 0) !== $ownerUserId
        || $expectedProviderHotelName === ''
    ) {
        throw new RuntimeException('dingdandao_binding_scope_unverified');
    }

    $probe = runIdentityProbe(
        $nodeBinary,
        $root . '/scripts/dingdandao_binding_probe.mjs',
        $cdpUrl,
        $expectedProviderHotelName,
        $probeOutput,
        $privateIdentityOutput
    );
    if (($probe['status'] ?? '') !== 'identity_verified_unpersisted'
        || ($probe['raw_response_exposed'] ?? null) !== false
        || ($probe['session_material_exposed'] ?? null) !== false
        || ($probe['browser_process_started'] ?? null) !== false
        || ($probe['user_tabs_closed'] ?? null) !== false
        || ($probe['identity_transferred_via_private_pipe'] ?? null) !== true
        || !is_array($probe['identity'] ?? null)
    ) {
        throw new RuntimeException('dingdandao_binding_probe_output_invalid');
    }
    $identity = $probe['identity'];
    $providerHotelId = trim((string)($identity['provider_hotel_id'] ?? ''));
    $providerHotelName = trim((string)($identity['provider_hotel_name'] ?? ''));
    if ($providerHotelId === ''
        || $providerHotelName === ''
        || !hash_equals($expectedProviderHotelName, $providerHotelName)
    ) {
        throw new RuntimeException('dingdandao_binding_identity_unverified');
    }
    $providerHotelIdFingerprint = hash(
        'sha256',
        'dingdandao:' . $tenantId . ':' . $hotelId . ':' . $providerHotelId
    );

    if (!$execute) {
        $result = [
            'status' => 'identity_verified_binding_not_persisted',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'provider' => 'dingdandao',
            'provider_hotel_name' => $providerHotelName,
            'provider_hotel_id_fingerprint' => $providerHotelIdFingerprint,
            'identity_status' => 'matched',
            'source_api_path' => '/v2/ntw/web/ntw/get',
            'request_count' => 1,
            'binding_persisted' => false,
            'business_data_persisted' => false,
            'message_sent' => false,
            'user_tabs_closed' => false,
            'sensitive_values_exposed' => false,
        ];
    } else {
        $bindingPersistenceStatus = 'unknown';
        $result = $service->registerVerifiedBinding(
            $profileId,
            $tenantId,
            $hotelId,
            $ownerUserId,
            $identity,
            $confirmation
        );
        if (($result['binding_persisted'] ?? null) !== true
            || ($result['readback_status'] ?? '') !== 'readback_verified'
            || ($result['post_commit_readback_status'] ?? '') !== 'readback_verified'
            || !hash_equals(
                (string)($result['provider_hotel_id_fingerprint'] ?? ''),
                $providerHotelIdFingerprint
            )
        ) {
            throw new RuntimeException('dingdandao_binding_readback_unverified');
        }
        $bindingPersistenceStatus = true;
        $result['user_tabs_closed'] = false;
        $result['sensitive_values_exposed'] = false;
    }
} catch (Throwable $error) {
    $mainError = $error->getMessage();
} finally {
    if (isset($providerHotelId) && is_string($providerHotelId)) {
        $providerHotelId = str_repeat("\0", strlen($providerHotelId));
    }
    if (isset($identity['provider_hotel_id'])
        && is_string($identity['provider_hotel_id'])
    ) {
        $identity['provider_hotel_id'] = str_repeat(
            "\0",
            strlen($identity['provider_hotel_id'])
        );
    }
    if (isset($probe['identity']['provider_hotel_id'])
        && is_string($probe['identity']['provider_hotel_id'])
    ) {
        $probe['identity']['provider_hotel_id'] = str_repeat(
            "\0",
            strlen($probe['identity']['provider_hotel_id'])
        );
    }
    if ($probeOutput !== '') {
        $probeOutput = str_repeat("\0", strlen($probeOutput));
    }
    if ($privateIdentityOutput !== '') {
        $privateIdentityOutput = str_repeat("\0", strlen($privateIdentityOutput));
    }
    if ($profileLeaseId !== null) {
        try {
            $activateBinding = $execute
                && $mainError === null
                && $bindingPersistenceStatus === true
                && isset($providerHotelIdFingerprint)
                && is_string($providerHotelIdFingerprint);
            $closePayload = [
                'profile_lease_id' => $profileLeaseId,
                'profile_id' => $profileId,
                'platform' => 'dingdandao',
                'outcome' => $mainError === null ? 'completed' : 'failed',
                'activate_binding' => $activateBinding,
            ];
            if ($activateBinding) {
                $closePayload['provider_hotel_id_fingerprint'] =
                    $providerHotelIdFingerprint;
            }
            $closed = bindingLeaseGatewayRequest(
                $gatewayUrl,
                $controlToken,
                '/v1/profile-lease/close',
                $closePayload
            );
            if (($closed['status'] ?? '') !== 'profile_lease_closed'
                || ($closed['owned_browser_closed'] ?? null) !== true
                || ($closed['profile_encrypted_at_rest'] ?? null) !== true
                || ($closed['user_browser_closed'] ?? null) !== false
                || ($closed['sensitive_values_exposed'] ?? null) !== false
                || ($activateBinding
                    && (($closed['binding_activated'] ?? null) !== true
                        || ($closed['receipt_verified'] ?? null) !== true
                        || ($closed['profile_authorization_status'] ?? '')
                            !== 'ready_to_collect'))
            ) {
                throw new RuntimeException(
                    'dingdandao_binding_profile_lease_close_unverified'
                );
            }
            if ($activateBinding && is_array($result)) {
                $result['profile_authorization_status'] =
                    'ready_to_collect';
                $result['profile_ready_after_binding'] = true;
                $result['receipt_verified'] = true;
                $result['activation_receipt_id'] =
                    (string)($closed['receipt_id'] ?? '');
                $result['activation_receipt_hash'] =
                    (string)($closed['receipt_hash'] ?? '');
            }
        } catch (Throwable $closeError) {
            $mainError = safeReason(
                ($mainError ?? 'dingdandao_binding_bootstrap_failed')
                . '_'
                . $closeError->getMessage()
            );
            $result = null;
        }
    }
    $controlToken = str_repeat("\0", strlen($controlToken));
    flock($lock, LOCK_UN);
    fclose($lock);
}
if ($mainError !== null || !is_array($result)) {
    fail(
        $mainError ?? 'dingdandao_binding_bootstrap_failed',
        1,
        $bindingPersistenceStatus
    );
}
if ($execute
    && (($result['profile_authorization_status'] ?? '')
            !== 'ready_to_collect'
        || ($result['profile_ready_after_binding'] ?? null) !== true)
) {
    fail('dingdandao_binding_profile_promotion_failed', 1, true);
}
$result['profile_lease_status'] = 'closed';
$result['owned_browser_closed'] = true;
$result['profile_encrypted_at_rest'] = true;
$result['external_browser_required'] = false;
$result['user_browser_closed'] = false;
$result['sensitive_values_exposed'] = false;
echo json_encode(
    $result,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_THROW_ON_ERROR
) . PHP_EOL;

/** @return array<string,mixed> */
function runIdentityProbe(
    string $nodeBinary,
    string $script,
    string $cdpUrl,
    string $expectedHotelName,
    string &$rawOutput,
    string &$privateIdentityOutput
): array {
    $command = [
        $nodeBinary,
        '--experimental-websocket',
        $script,
        '--cdp-url=' . $cdpUrl,
        '--expected-hotel-name=' . $expectedHotelName,
        '--timeout-ms=12000',
        '--identity-fd=3',
    ];
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
            3 => ['pipe', 'w'],
        ],
        $pipes,
        dirname($script),
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('dingdandao_binding_probe_start_failed');
    }
    fclose($pipes[0]);
    $rawOutput = stream_get_contents(
        $pipes[1],
        MAX_BINDING_PROBE_OUTPUT_BYTES + 1
    );
    $stderr = stream_get_contents($pipes[2], 4096);
    $privateIdentityOutput = stream_get_contents(
        $pipes[3],
        MAX_BINDING_PROBE_OUTPUT_BYTES + 1
    );
    fclose($pipes[1]);
    fclose($pipes[2]);
    fclose($pipes[3]);
    $exitCode = proc_close($process);
    if (!is_string($rawOutput)
        || strlen($rawOutput) > MAX_BINDING_PROBE_OUTPUT_BYTES
        || !is_string($privateIdentityOutput)
        || strlen($privateIdentityOutput) > MAX_BINDING_PROBE_OUTPUT_BYTES
        || $exitCode !== 0
    ) {
        $error = is_string($stderr) ? json_decode(trim($stderr), true) : null;
        $reason = is_array($error) ? (string)($error['reason'] ?? '') : '';
        throw new RuntimeException(
            $reason !== '' ? $reason : 'dingdandao_binding_probe_failed'
        );
    }
    $decoded = json_decode(trim($rawOutput), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('dingdandao_binding_probe_output_invalid');
    }
    $identity = json_decode(trim($privateIdentityOutput), true);
    if (!is_array($identity)) {
        throw new RuntimeException('dingdandao_binding_private_output_invalid');
    }
    $decoded['identity'] = $identity;
    return $decoded;
}

/** @return array<string,mixed> */
function bindingLeaseGatewayRequest(
    string $baseUrl,
    string $token,
    string $path,
    array $body
): array {
    $expectedBuild = expectedBindingGatewayBuild();
    $body['expected_gateway_build_sha256'] = $expectedBuild;
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
    if (!is_array($decoded)
        || ($decoded['status'] ?? '') === 'failed'
        || !hash_equals(
            $expectedBuild,
            (string)($decoded['build_sha256'] ?? '')
        )
    ) {
        $reason = is_array($decoded) ? (string)($decoded['reason'] ?? '') : '';
        throw new RuntimeException(
            $reason !== '' ? $reason : 'dingdandao_binding_gateway_failed'
        );
    }
    return $decoded;
}

function assertBindingGatewayProtocol(string $gatewayUrl): void
{
    $expectedBuild = expectedBindingGatewayBuild();
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($gatewayUrl . '/health', false, $context);
    $health = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($health)
        || ($health['status'] ?? '') !== 'ok'
        || ($health['bind'] ?? '') !== '127.0.0.1'
        || ($health['protocol_version'] ?? '')
            !== CLOUD_BROWSER_GATEWAY_PROTOCOL_VERSION
        || !hash_equals(
            $expectedBuild,
            (string)($health['build_sha256'] ?? '')
        )
        || !hash_equals(
            $expectedBuild,
            (string)(
                $health['active_release_gateway_sha256'] ?? ''
            )
        )
        || ($health['active_release_build_match'] ?? null) !== true
    ) {
        throw new RuntimeException(
            'dingdandao_binding_gateway_build_mismatch'
        );
    }
}

function expectedBindingGatewayBuild(): string
{
    $path = dirname(__DIR__)
        . '/deploy/remote-browser/cloud_browser_gateway.mjs';
    $hash = is_file($path) ? hash_file('sha256', $path) : false;
    if (!is_string($hash)
        || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
    ) {
        throw new RuntimeException(
            'dingdandao_binding_gateway_build_unavailable'
        );
    }
    return $hash;
}

function positiveInt(mixed $value, string $reason): int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);
    if (!is_int($validated) || $validated <= 0) {
        fail($reason, 2);
    }
    return $validated;
}

function opaqueId(string $value, string $prefix, string $reason): string
{
    $value = trim($value);
    if (preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9_-]{16,64}$/D', $value) !== 1) {
        fail($reason, 2);
    }
    return $value;
}

function safeReason(string $reason): string
{
    return preg_replace('/[^a-zA-Z0-9_-]+/', '_', $reason)
        ?: 'dingdandao_binding_bootstrap_failed';
}

function fail(
    string $reason,
    int $exitCode = 1,
    bool|string $bindingPersisted = false
): never
{
    fwrite(STDERR, json_encode([
        'status' => 'blocked',
        'reason' => safeReason($reason),
        'binding_persisted' => $bindingPersisted,
        'business_data_persisted' => false,
        'message_sent' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($exitCode);
}
