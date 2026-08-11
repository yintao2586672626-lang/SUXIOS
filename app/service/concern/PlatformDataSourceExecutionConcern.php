<?php
declare(strict_types=1);

namespace app\service\concern;

use app\contract\DataSourceAdapter;
use app\service\BrowserProfileCaptureRequestService;
use app\service\OtaProfileBindingService;
use app\service\platform\ApiDataSourceAdapter;
use app\service\platform\CtripBrowserProfileDataSourceAdapter;
use app\service\platform\LocalCollectorDataSourceAdapter;
use app\service\platform\ManualImportDataSourceAdapter;
use app\service\platform\MeituanBrowserProfileDataSourceAdapter;
use RuntimeException;
use think\facade\Cache;
use think\facade\Db;

trait PlatformDataSourceExecutionConcern
{
    private function normalizeSourcePayload(array $payload): array
    {
        $config = $this->decodeConfig($payload['config_json'] ?? $payload['config'] ?? []);
        $secret = $this->decodeConfig($payload['secret_json'] ?? $payload['secret'] ?? []);
        foreach (['cookies', 'cookie', 'token', 'api_key', 'authorization', 'authorization_header', 'password', 'spidertoken', 'spider_token', 'spiderkey', 'spider_key', 'mtgsig', 'auth_data', 'usertoken', 'usersign', '_mtsi_eb_u', 'access_token', 'refresh_token', 'set_cookie'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== '') {
                $secret[$key === 'cookie' ? 'cookies' : $key] = is_array($payload[$key])
                    ? $payload[$key]
                    : (string)$payload[$key];
            }
        }
        foreach (['config_id', 'url', 'request_url', 'method', 'allowed_hosts', 'payload', 'payload_json', 'headers', 'headers_json', 'external_hotel_id', 'hotel_name', 'profile_id', 'profileId', 'browser_profile_id', 'hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId', 'store_id', 'storeId', 'poi_id', 'poiId', 'poi_name', 'poiName', 'partner_id', 'partnerId', 'ads_url', 'adsUrl', 'capture_sections', 'captureSections', 'profile_sections', 'capture_plan', 'capturePlan', 'ctrip_capture_plan', 'ctripCapturePlan', 'section_concurrency', 'sectionConcurrency', 'ctrip_section_concurrency', 'ctripSectionConcurrency', 'not_applicable_sections', 'notApplicableSections', 'excluded_sections', 'excludedSections', 'allow_review', 'authorized_review_collection', 'review_collection_enabled', 'local_collector_account_id', 'collector_device_id_hash', 'profile_key_hash', 'source_method', 'current_session_verified'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== '') {
                $config[$key] = $payload[$key];
            }
        }

        $method = (string)($payload['ingestion_method'] ?? 'manual');
        $platform = strtolower(trim((string)($payload['platform'] ?? 'custom'))) ?: 'custom';
        if ($this->isOtaPlatform($platform)) {
            $this->moveOtaConfigCredentialsToSecret($config, $secret);
        }
        $this->assertNoOtaPasswordCustody($platform, $secret);
        $status = in_array($method, ['manual', 'import_json', 'import_csv', 'import_excel'], true) || !empty($config) || !empty($secret)
            ? 'ready'
            : 'waiting_config';
        $dataType = $this->normalizeDataType(trim((string)($payload['data_type'] ?? 'business')) ?: 'business');
        $sourceForPolicy = [
            'data_type' => $dataType,
            'ingestion_method' => $method,
            'config' => $config,
            'secret' => $secret,
        ];
        if ($this->isCommentDataType($dataType) && !$this->isReviewCollectionAllowed($sourceForPolicy, $payload, $dataType)) {
            throw new RuntimeException('Comment/review detail storage requires explicit authorization; aggregate metrics are allowed.', 422);
        }

        return [
            'name' => trim((string)($payload['name'] ?? '')) ?: 'Platform data source',
            'system_hotel_id' => is_numeric($payload['system_hotel_id'] ?? $payload['hotel_id'] ?? null) ? (int)($payload['system_hotel_id'] ?? $payload['hotel_id']) : 0,
            'platform' => $platform,
            'data_type' => $dataType,
            'ingestion_method' => $method,
            'status' => $status,
            'enabled' => (int)($payload['enabled'] ?? 1),
            'config' => $config,
            'secret' => $secret,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $secret
     */
    private function moveOtaConfigCredentialsToSecret(array &$config, array &$secret): void
    {
        foreach (array_keys($config) as $key) {
            $stringKey = (string)$key;
            $lowerKey = strtolower($stringKey);
            if (in_array($lowerKey, ['headers', 'headers_json'], true)) {
                [$safeHeaders, $secretHeaders] = $this->splitOtaHeaders($config[$key]);
                unset($config[$key]);
                if ($safeHeaders !== []) {
                    $config['headers'] = array_merge(is_array($config['headers'] ?? null) ? $config['headers'] : [], $safeHeaders);
                }
                foreach ($secretHeaders as $headerName => $headerValue) {
                    $normalizedName = strtolower($headerName);
                    if ($normalizedName === 'cookie') {
                        $secret['cookies'] = $headerValue;
                    } elseif ($normalizedName === 'authorization') {
                        $secret['authorization'] = $headerValue;
                    } elseif (in_array($normalizedName, ['x-api-key', 'api-key'], true)) {
                        $secret['api_key'] = $headerValue;
                    } else {
                        $secret['headers'][$headerName] = $headerValue;
                    }
                }
                continue;
            }
            if (!$this->isSensitiveConfigKey($stringKey)) {
                continue;
            }
            $targetKey = $lowerKey === 'cookie' ? 'cookies' : $stringKey;
            $secret[$targetKey] = $config[$key];
            unset($config[$key]);
        }
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function splitOtaHeaders(mixed $headers): array
    {
        if (is_string($headers)) {
            $decoded = json_decode($headers, true);
            if (is_array($decoded)) {
                $headers = $decoded;
            } else {
                $lines = preg_split('/\r?\n/', $headers) ?: [];
                $headers = [];
                foreach ($lines as $line) {
                    if (trim($line) === '') {
                        continue;
                    }
                    if (!str_contains($line, ':')) {
                        throw new RuntimeException('OTA header metadata must use Name: Value syntax.', 422);
                    }
                    [$name, $value] = explode(':', $line, 2);
                    $headers[trim($name)] = trim($value);
                }
            }
        }
        if (!is_array($headers)) {
            throw new RuntimeException('OTA header metadata must be an object or header string.', 422);
        }

        $safe = [];
        $secret = [];
        foreach ($headers as $name => $value) {
            if (is_int($name) && is_string($value) && str_contains($value, ':')) {
                [$name, $value] = explode(':', $value, 2);
            }
            $name = trim((string)$name);
            if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,100}$/D', $name) !== 1 || !is_scalar($value)) {
                throw new RuntimeException('OTA header metadata contains an unsupported entry.', 422);
            }
            $value = trim((string)$value);
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw new RuntimeException('OTA header metadata contains an invalid value.', 422);
            }
            if ($this->isSensitiveConfigKey($name)) {
                $secret[$name] = $value;
            } else {
                $safe[$name] = $value;
            }
        }
        return [$safe, $secret];
    }

    /**
     * @param array<string, mixed> $secret
     */
    private function assertNoOtaPasswordCustody(string $platform, array $secret): void
    {
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return;
        }
        if (!$this->credentialPayloadContainsPassword($secret)) {
            return;
        }

        throw new RuntimeException('OTA account password custody is not supported. Use the browser Profile login task and its current-session proof instead.', 422);
    }

    /**
     * @param array<string, mixed> $secret
     */
    private function credentialPayloadContainsPassword(array $secret): bool
    {
        foreach ($secret as $key => $value) {
            if (strtolower((string)$key) === 'password' && $this->credentialPayloadHasValue($value)) {
                return true;
            }
            if (is_array($value) && $this->credentialPayloadContainsPassword($value)) {
                return true;
            }
        }
        return false;
    }

    private function loadSource(int $id, $user): array
    {
        $query = Db::name('platform_data_sources')->withoutField('secret_json')->where('id', $id);
        $this->applySourceTenantScope($query, $user);
        $row = $query->find();
        if (!$row) {
            throw new RuntimeException('Data source not found.', 404);
        }
        $this->assertStoredSourceTenantForActor($row, $user);
        $this->assertCanUseHotel($user, (int)($row['system_hotel_id'] ?? 0), 'can_fetch_online_data');
        $row['config'] = $this->decodeConfig($row['config_json'] ?? []);
        if (!$this->isOtaPlatform((string)($row['platform'] ?? ''))) {
            $secretQuery = Db::name('platform_data_sources');
            $this->applyStoredSourceIdentity($secretQuery, $row);
            $row['secret'] = $this->decodeConfig($secretQuery->value('secret_json'));
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function fetchOtaSourceInsideVault(DataSourceAdapter $adapter, array $source, array $options): array
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $this->assertNoInlineOtaCredentialOptions($options, $platform);
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $tenantId = (int)($source['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            $tenantId = $this->resolveHotelTenantId($hotelId);
        }
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $this->assertOtaExecutionConfigSafe($config, $platform);
        $configId = trim((string)($config['config_id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $configId) !== 1) {
            throw new RuntimeException('OTA data source credential locator is missing.', 422);
        }
        if (trim((string)($config['credential_status'] ?? '')) !== 'ready') {
            throw new RuntimeException('OTA data source credential is not ready.', 422);
        }

        return $this->otaCredentialVault()->withPayloadForExecution(
            $tenantId,
            $hotelId,
            $platform,
            $configId,
            function (array $credentialPayload) use ($adapter, $source, $options): array {
                $executionSource = $source;
                unset($executionSource['secret_json']);
                $executionSource['secret'] = $credentialPayload;
                try {
                    $result = $adapter->fetch($executionSource, $options);
                    return $this->sanitizeAdapterResultForCredentialBoundary($result, $credentialPayload);
                } finally {
                    unset($executionSource['secret']);
                    $credentialPayload = [];
                }
            }
        );
    }

    /**
     * Browser-assist imports contain already-observed, bounded page facts.
     * They may use the manual-import adapter but never decrypt, inject or store
     * an OTA credential.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function fetchOtaBrowserAssistSource(
        DataSourceAdapter $adapter,
        array $source,
        array $options
    ): array {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $this->assertNoInlineOtaCredentialOptions($options, $platform);
        $executionSource = $source;
        unset($executionSource['secret'], $executionSource['secret_json']);
        return $this->sanitizeAdapterResultForCredentialBoundary(
            $adapter->fetch($executionSource, $options),
            []
        );
    }

    /**
     * Browser Profile collection reuses the authorized local browser session.
     * It must never decrypt or inject a reusable Cookie/API credential.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function fetchOtaBrowserProfileSource(DataSourceAdapter $adapter, array $source, array $options): array
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $this->assertNoInlineOtaCredentialOptions($options, $platform);
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $this->assertOtaExecutionConfigSafe($config, $platform);
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $profileKey = $this->otaBrowserProfileKey($platform, $config);
        if ($profileKey === '') {
            throw new RuntimeException('Browser Profile binding key is missing.', 422);
        }
        (new OtaProfileBindingService())->assertBound($hotelId, $platform, $profileKey);

        $this->assertNoUntrustedInAppBrowserCapture($options);

        $executionSource = $source;
        unset($executionSource['secret'], $executionSource['secret_json']);

        return $this->sanitizeAdapterResultForCredentialBoundary(
            $adapter->fetch($executionSource, $options),
            []
        );
    }

    /**
     * Offline IAB JSON is user-provided input. Its timestamps, response status,
     * origin and identity hashes can all be forged together, so it cannot prove
     * an authorized live platform session. Keep the Profile collector as the
     * verified path until a server-issued, single-use live capture handle is
     * implemented and checked here.
     *
     * @param array<string, mixed> $options
     */
    private function assertNoUntrustedInAppBrowserCapture(array $options): void
    {
        if (!array_key_exists('in_app_browser_capture', $options)) {
            return;
        }

        throw new RuntimeException(
            'user_provided_unverified: offline in-app browser JSON import is blocked; controlled_live_capture_handle_required.',
            422
        );
    }

    /**
     * Local collector uploads are already bound to a leased device task. They
     * bypass the central credential vault and may contain business facts only.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function fetchOtaLocalCollectorSource(
        DataSourceAdapter $adapter,
        array $source,
        array $options
    ): array {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $this->assertNoInlineOtaCredentialOptions($options, $platform);
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $this->assertOtaExecutionConfigSafe($config, $platform);
        if (($options['local_collector_verified'] ?? false) !== true
            || (int)($options['local_collector_task_id'] ?? 0) <= 0
        ) {
            throw new RuntimeException('Local collector task proof is missing.', 403);
        }

        return $adapter->fetch($source, $options);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $result
     */
    private function recordBrowserProfileCollectionPreflight(array $source, array $result): bool
    {
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $authStatus = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
        $authCode = strtolower(trim((string)($authStatus['status'] ?? '')));
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $profileKey = $this->otaBrowserProfileKey($platform, $config);
        if ($profileKey === '') {
            return false;
        }
        $sessionProbe = is_array($payload['session_probe'] ?? null) ? $payload['session_probe'] : [];
        $probeStatus = strtolower(trim((string)($sessionProbe['status'] ?? '')));
        $identityValidation = is_array($payload['platform_identity_validation'] ?? null)
            ? $payload['platform_identity_validation']
            : [];
        $identityStatus = strtolower(trim((string)($identityValidation['status'] ?? '')));

        $probeBlockStatuses = [
            'anti_bot' => 'anti_bot',
            'cookies_incomplete' => 'cookies_incomplete',
            'platform_contract_drift' => 'platform_contract_drift',
            'permission_denied' => 'permission_denied',
            'weak_evidence' => 'capture_failed',
            'probe_failed' => 'capture_failed',
        ];
        $authBlockStatuses = [
            'anti_bot' => 'anti_bot',
            'cookies_incomplete' => 'cookies_incomplete',
            'platform_contract_drift' => 'platform_contract_drift',
            'permission_denied' => 'permission_denied',
            'capture_failed' => 'capture_failed',
        ];
        $sessionBlockStatus = $probeBlockStatuses[$probeStatus]
            ?? $authBlockStatuses[$authCode]
            ?? '';
        if ($sessionBlockStatus !== '') {
            $this->profileSessionProofService->recordProfileSessionBlocked(
                (int)($source['id'] ?? 0),
                (int)($source['system_hotel_id'] ?? 0),
                $platform,
                $profileKey,
                $sessionBlockStatus,
                trim((string)($sessionProbe['next_retry_at'] ?? ''))
            );
            return true;
        }
        if (($authStatus['ok'] ?? null) === false
            && in_array($authCode, ['login_required', 'session_expired', 'login_expired', 'not_logged_in', 'unauthorized'], true)
        ) {
            $this->profileSessionProofService->recordCollectionPreflightFailed(
                (int)($source['id'] ?? 0),
                (int)($source['system_hotel_id'] ?? 0),
                $platform,
                $profileKey,
                $authStatus
            );
            return true;
        }
        $authVerified = ($authStatus['ok'] ?? null) === true
            && in_array($authCode, ['logged_in', 'authorized'], true);
        if ($identityStatus === 'mismatch') {
            $this->profileSessionProofService->recordProfileSessionBlocked(
                (int)($source['id'] ?? 0),
                (int)($source['system_hotel_id'] ?? 0),
                $platform,
                $profileKey,
                'identity_mismatch'
            );
            return true;
        }
        if (in_array($identityStatus, ['unverified', 'not_configured'], true)) {
            $currentSourceQuery = Db::name('platform_data_sources');
            $this->applyStoredSourceIdentity($currentSourceQuery, $source);
            $currentConfig = $this->decodeConfig(
                $currentSourceQuery->value('config_json')
            );
            $priorIdentityStatus = strtolower(trim((string)($currentConfig['current_session_probe_identity_status'] ?? '')));
            if ($authVerified
                && $this->truthy($currentConfig['current_session_verified'] ?? null)
                && $priorIdentityStatus === 'matched'
            ) {
                return false;
            }
            $this->profileSessionProofService->recordProfileSessionBlocked(
                (int)($source['id'] ?? 0),
                (int)($source['system_hotel_id'] ?? 0),
                $platform,
                $profileKey,
                'identity_unverified'
            );
            return true;
        }
        $identityMatched = $identityStatus === 'matched';
        if ($authVerified && $identityMatched) {
            $this->profileSessionProofService->recordCollectionPreflightVerified(
                (int)($source['id'] ?? 0),
                (int)($source['system_hotel_id'] ?? 0),
                $platform,
                $profileKey,
                true,
                $authStatus,
                $identityValidation
            );
            return true;
        }
        if (($result['status'] ?? '') !== 'success') {
            $this->profileSessionProofService->recordProfileSessionBlocked(
                (int)($source['id'] ?? 0),
                (int)($source['system_hotel_id'] ?? 0),
                $platform,
                $profileKey,
                'capture_failed'
            );
            return true;
        }
        if (!$authVerified) {
            return false;
        }
        if (!$identityMatched) {
            return false;
        }
        return false;
    }

    /** @param array<string, mixed> $config */
    private function otaBrowserProfileKey(string $platform, array $config): string
    {
        $keys = $platform === 'meituan'
            ? ['store_id', 'storeId', 'poi_id', 'poiId', 'profile_id', 'profileId']
            : ['profile_id', 'profileId', 'browser_profile_id', 'browserProfileId'];
        foreach ($keys as $key) {
            if (is_scalar($config[$key] ?? null) && trim((string)$config[$key]) !== '') {
                return trim((string)$config[$key]);
            }
        }
        return '';
    }

    /** @return array<string, mixed>|null */
    private function findReusableBrowserProfileSource(
        int $tenantId,
        int $systemHotelId,
        string $platform,
        string $profileKey
    ): ?array {
        $canonicalProfileKey = BrowserProfileCaptureRequestService::safeFilePart($profileKey);
        if ($canonicalProfileKey === '' || $canonicalProfileKey === 'default') {
            return null;
        }

        $query = Db::name('platform_data_sources')
            ->withoutField('secret_json')
            ->where('system_hotel_id', $systemHotelId)
            ->where('platform', strtolower(trim($platform)))
            ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
            ->order('id', 'desc')
            ->lock(true);
        if (isset($this->tableColumns('platform_data_sources')['tenant_id'])) {
            $query->where('tenant_id', $tenantId);
        }

        foreach ($query->select()->toArray() as $row) {
            $candidateConfig = $this->decodeConfig($row['config_json'] ?? []);
            $candidateKey = BrowserProfileCaptureRequestService::safeFilePart(
                $this->otaBrowserProfileKey($platform, $candidateConfig)
            );
            if ($candidateKey !== '' && hash_equals($canonicalProfileKey, $candidateKey)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assertNoInlineOtaCredentialOptions(array $options, string $platform): void
    {
        foreach ($options as $key => $value) {
            $key = (string)$key;
            $normalizedKey = strtolower($key);
            if ($this->isSensitiveConfigKey($key) && $this->credentialPayloadHasValue($value)) {
                throw new RuntimeException('Inline OTA credentials are not allowed for data source sync.', 422);
            }
            if (in_array(
                $normalizedKey,
                ['source_url_hash', '_source_url_hash', 'url_hash', '_url_hash'],
                true
            )) {
                if ($value !== null
                    && $value !== ''
                    && (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', strtolower($value)) !== 1)
                ) {
                    throw new RuntimeException('OTA source URL hash is invalid.', 422);
                }
            } elseif (str_contains($normalizedKey, 'url')) {
                $this->assertOtaMetadataUrlsAreSafe($value, $platform);
            }
            if ($normalizedKey === 'headers' && is_string($value)
                && preg_match('/(?:^|\r?\n)\s*(?:cookie|authorization|x-api-key|token)\s*:/i', $value) === 1
            ) {
                throw new RuntimeException('Inline OTA credentials are not allowed for data source sync.', 422);
            }
            if (is_string($value) && $this->stringContainsCredentialMaterial($value)) {
                throw new RuntimeException('Inline OTA credentials are not allowed for data source sync.', 422);
            }
            if (is_array($value)) {
                $this->assertNoInlineOtaCredentialOptions($value, $platform);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function assertOtaExecutionConfigSafe(array $config, string $platform): void
    {
        foreach (['url', 'request_url', 'ads_url', 'adsUrl'] as $urlKey) {
            if (array_key_exists($urlKey, $config)) {
                $this->assertOtaMetadataUrlsAreSafe($config[$urlKey], $platform);
            }
        }
        if (array_key_exists('allowed_hosts', $config)) {
            $this->normalizeOtaAllowedHosts($config['allowed_hosts'], $platform);
        }

        $safeCredentialMetadata = [
            'config_id', 'credential_ref', 'credential_status', 'status',
            'has_secret', 'has_cookies', 'secret_mask', 'key_id', 'payload_version', 'rotated_at',
        ];
        foreach ($config as $key => $value) {
            $key = (string)$key;
            if (in_array($key, $safeCredentialMetadata, true)) {
                continue;
            }
            if (in_array(strtolower($key), ['headers', 'headers_json'], true)) {
                [, $secretHeaders] = $this->splitOtaHeaders($value);
                if ($secretHeaders !== []) {
                    throw new RuntimeException('Legacy OTA source headers contain inline credentials and require migration.', 422);
                }
                continue;
            }
            if ($this->isSensitiveConfigKey($key) && $this->credentialPayloadHasValue($value)) {
                throw new RuntimeException('Legacy OTA source config contains inline credentials and requires migration.', 422);
            }
            $this->sanitizeOtaMetadataNode($value);
        }
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $credentialPayload
     * @return array<string, mixed>
     */
    private function sanitizeAdapterResultForCredentialBoundary(array $result, array $credentialPayload): array
    {
        $status = strtolower(trim((string)($result['status'] ?? 'failed')));
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $status) !== 1) {
            $status = 'failed';
        }
        $secretValues = $this->credentialScalarValues($credentialPayload);
        $safe = [
            'status' => $status,
            'message' => $this->safeSyncTaskMessage($status, (string)($result['message'] ?? '')),
            'payload' => is_array($result['payload'] ?? null)
                ? $this->redactCredentialBoundValue($result['payload'], $secretValues)
                : [],
        ];
        if (isset($result['http_status']) && is_numeric($result['http_status'])) {
            $safe['http_status'] = max(0, min(599, (int)$result['http_status']));
        }
        foreach (['status_code', 'error_code'] as $key) {
            if (!isset($result[$key]) || !is_scalar($result[$key])) {
                continue;
            }
            $value = (string)$this->redactCredentialBoundValue((string)$result[$key], $secretValues);
            if (preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $value) === 1) {
                $safe[$key] = $value;
            }
        }
        return $safe;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function credentialScalarValues(array $payload): array
    {
        $values = [];
        foreach ($payload as $value) {
            if (is_array($value)) {
                $values = array_merge($values, $this->credentialScalarValues($value));
                continue;
            }
            if (is_scalar($value)) {
                $value = (string)$value;
                if (strlen($value) >= 4) {
                    $values[] = $value;
                }
            }
        }
        return array_values(array_unique($values));
    }

    /**
     * @param array<int, string> $secretValues
     */
    private function redactCredentialBoundValue(mixed $value, array $secretValues): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && ($this->isSensitiveConfigKey($key) || $this->containsCredentialScalar($key, $secretValues))) {
                    continue;
                }
                $safe[$key] = $this->redactCredentialBoundValue($item, $secretValues);
            }
            return $safe;
        }
        if (is_string($value)) {
            foreach ($secretValues as $secret) {
                $value = str_replace($secret, '[redacted]', $value);
            }
            return $value;
        }
        return is_scalar($value) || $value === null ? $value : null;
    }

    /**
     * @param array<int, string> $secretValues
     */
    private function containsCredentialScalar(string $value, array $secretValues): bool
    {
        foreach ($secretValues as $secret) {
            if ($secret !== '' && str_contains($value, $secret)) {
                return true;
            }
        }
        return false;
    }

    private function resolveAdapter(array $source): DataSourceAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($source)) {
                return $adapter;
            }
        }
        throw new RuntimeException('No adapter is available for this data source.', 422);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     */
    private function assertBrowserProfileBackgroundSyncLoginVerified(array $source, array $options): void
    {
        $missing = $this->browserProfileBackgroundSyncLoginMissingRequirements($source, $options);
        if ($missing === []) {
            return;
        }

        throw new RuntimeException(
            'browser_profile synchronization requires ' . $missing[0] . ' before capture.',
            422
        );
    }

    /**
     * Revalidate the collector binding from the source row loaded by this sync
     * process. This closes the gap between the scheduler's preflight and the
     * actual adapter/persistence transaction.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     */
    private function assertRequiredCollectorBinding(array $source, array $options): void
    {
        $required = array_key_exists('require_collector_binding', $options)
            ? $this->truthy($options['require_collector_binding'])
            : $this->truthy($options['require_current_session_probe'] ?? false);
        if (!$required) {
            return;
        }
        $required = is_array($options['required_collector_binding'] ?? null)
            ? $options['required_collector_binding']
            : [];
        $evidence = $this->collectorBindingEvidence($source);
        if ($evidence !== []
            && (string)($required['mode'] ?? '') === 'single_user_local'
            && (string)$evidence['mode'] === (string)$required['mode']
            && (int)$evidence['tenant_id'] === (int)($required['tenant_id'] ?? 0)
            && (int)$evidence['user_id'] === (int)($required['user_id'] ?? 0)
            && hash_equals((string)$evidence['device_id'], (string)($required['device_id'] ?? ''))
            && hash_equals(
                (string)$evidence['device_id_hash'],
                strtolower(trim((string)($required['device_id_hash'] ?? '')))
            )
            && (int)$evidence['hotel_id'] === (int)($required['hotel_id'] ?? 0)
            && (string)$evidence['platform']
                === strtolower(trim((string)($required['platform'] ?? '')))
            && (string)$evidence['platform_hotel_id'] !== ''
            && hash_equals(
                (string)$evidence['platform_hotel_id'],
                trim((string)($required['platform_hotel_id'] ?? ''))
            )
        ) {
            return;
        }

        throw new RuntimeException(
            'Current sync process collector binding is missing or outside its explicit user/device/hotel/platform scope.',
            422
        );
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function collectorBindingEvidence(array $source): array
    {
        $config = is_array($source['config'] ?? null)
            ? $source['config']
            : $this->decodeConfig($source['config_json'] ?? []);
        $mode = strtolower(trim((string)($config['source_method'] ?? '')));
        $bindingMode = strtolower(trim((string)($config['collector_binding_mode'] ?? '')));
        $deviceId = trim((string)($config['collector_device_id'] ?? ''));
        $deviceIdHash = strtolower(trim((string)($config['collector_device_id_hash'] ?? '')));
        $tenantId = (int)($config['collector_tenant_id'] ?? 0);
        $userId = (int)($config['collector_user_id'] ?? 0);
        $hotelId = (int)($config['collector_hotel_id'] ?? 0);
        $platform = strtolower(trim((string)($config['collector_platform'] ?? '')));
        $platformHotelId = trim((string)($config['platform_hotel_id'] ?? ''));
        $boundAt = trim((string)($config['collector_bound_at'] ?? ''));
        if ($mode !== 'single_user_local'
            || $bindingMode !== 'single_user_local'
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $deviceId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $deviceIdHash) !== 1
            || !hash_equals(hash('sha256', $deviceId), $deviceIdHash)
            || $tenantId <= 0
            || $tenantId !== (int)($source['tenant_id'] ?? 0)
            || $userId <= 0
            || $userId !== (int)($source['user_id'] ?? 0)
            || $hotelId <= 0
            || $hotelId !== (int)($source['system_hotel_id'] ?? 0)
            || !in_array($platform, ['ctrip', 'meituan'], true)
            || $platform !== strtolower(trim((string)($source['platform'] ?? '')))
            || $platformHotelId === ''
            || $boundAt === ''
        ) {
            return [];
        }

        return [
            'mode' => $mode,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'device_id_hash' => $deviceIdHash,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'platform_hotel_id' => $platformHotelId,
            'bound_at' => $boundAt,
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * Cloud single-user Profile collection must prove the current process saw
     * an authorised session and the expected platform hotel before anything is
     * persisted. Historical config flags are deliberately insufficient.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @param array<string, mixed> $result
     */
    private function assertRequiredCurrentRunProfileSessionProbe(
        array $source,
        array $options,
        array $result
    ): void {
        $required = array_key_exists('require_current_run_session_probe', $options)
            ? $this->truthy($options['require_current_run_session_probe'])
            : $this->truthy($options['require_current_session_probe'] ?? false);
        if (!$this->isOtaBrowserProfileSource($source)
            || !$required
        ) {
            return;
        }

        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $networkFreshness = is_array($payload['network_freshness'] ?? null)
            ? $payload['network_freshness']
            : [];
        $networkFreshnessReady = strtolower(trim((string)($networkFreshness['status'] ?? ''))) === 'ready'
            && ($networkFreshness['http_cache_disabled'] ?? null) === true
            && ($networkFreshness['service_worker_bypassed'] ?? null) === true
            && ($networkFreshness['sensitive_values_exposed'] ?? null) === false;
        $authStatus = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
        $authCode = strtolower(trim((string)($authStatus['status'] ?? '')));
        $identity = is_array($payload['platform_identity_validation'] ?? null)
            ? $payload['platform_identity_validation']
            : [];
        $identityStatus = strtolower(trim((string)($identity['status'] ?? '')));
        $validatedPlatformHotelId = trim((string)($identity['validated_identifier'] ?? ''));
        $identityEvidenceSource = strtolower(trim((string)($identity['evidence_source'] ?? '')));
        $identitySourceValidated = ($identity['source_validation'] ?? null) === true
            || in_array($identityEvidenceSource, ['ota_request', 'trusted_ota_page_state'], true);
        $requiredPlatformHotelIds = $this->requiredCurrentRunPlatformHotelIds($source, $options);
        $platformHotelMatched = false;
        foreach ($requiredPlatformHotelIds as $requiredPlatformHotelId) {
            if ($validatedPlatformHotelId !== '' && hash_equals($requiredPlatformHotelId, $validatedPlatformHotelId)) {
                $platformHotelMatched = true;
                break;
            }
        }
        if (($result['status'] ?? '') === 'success'
            && $networkFreshnessReady
            && ($authStatus['ok'] ?? null) === true
            && in_array($authCode, ['logged_in', 'authorized'], true)
            && (int)($identity['schema_version'] ?? 0) === 1
            && $identityStatus === 'matched'
            && $identitySourceValidated
            && ($identity['sensitive_values_exposed'] ?? false) !== true
            && $validatedPlatformHotelId !== ''
            && $requiredPlatformHotelIds !== []
            && $platformHotelMatched
        ) {
            return;
        }

        throw new RuntimeException(
            'Current session proof from this execution is missing or outside the bound platform hotel.',
            422
        );
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    private function requiredCurrentRunPlatformHotelIds(array $source, array $options): array
    {
        $identifiers = [];
        $configured = is_array($options['required_platform_hotel_ids'] ?? null)
            ? $options['required_platform_hotel_ids']
            : [];
        $configured[] = $options['required_platform_hotel_id'] ?? '';
        $binding = is_array($options['required_collector_binding'] ?? null)
            ? $options['required_collector_binding']
            : [];
        $configured[] = $binding['platform_hotel_id'] ?? '';
        foreach ($configured as $identifier) {
            if (!is_scalar($identifier)) {
                continue;
            }
            $identifier = trim((string)$identifier);
            if ($identifier !== '') {
                $identifiers[$identifier] = true;
            }
        }
        if ($identifiers !== []) {
            return array_keys($identifiers);
        }

        $config = is_array($source['config'] ?? null)
            ? $source['config']
            : $this->decodeConfig($source['config_json'] ?? []);
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $keys = $platform === 'meituan'
            ? ['platform_hotel_id', 'store_id', 'storeId', 'poi_id', 'poiId']
            : ['platform_hotel_id', 'hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId', 'node_id', 'nodeId'];
        foreach ($keys as $key) {
            $identifier = trim((string)($config[$key] ?? ''));
            if ($identifier !== '') {
                $identifiers[$identifier] = true;
            }
        }
        return array_keys($identifiers);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    private function browserProfileBackgroundSyncLoginMissingRequirements(array $source, array $options): array
    {
        if (!$this->isOtaBrowserProfileSource($source)
            || !empty($options['interactive_browser'])
        ) {
            return [];
        }
        if ($this->browserProfileRiskControlReviewRequired($source)) {
            return ['profile_risk_control_manual_review_required'];
        }
        $triggerType = strtolower(trim((string)($options['trigger_type'] ?? '')));
        $blockingStatus = $this->profileSessionProofService->currentSessionBlockingStatus($source);
        // Old/missing local anchors, including a contradictory old mismatch
        // accompanied by today's strong same-source matched page probe, may
        // reach the real OTA response. Both Profile adapters still reject a
        // real mismatch before raw or normalized persistence.
        if ($this->profileSessionProofService->canAttemptResponseIdentityValidation($source)) {
            return [];
        }
        if ($blockingStatus !== '') {
            return [match ($blockingStatus) {
                'platform_contract_drift' => 'profile_platform_contract_drift',
                'permission_denied' => 'profile_permission_denied',
                'cookies_incomplete' => 'profile_session_cookies_incomplete',
                'identity_mismatch' => 'profile_hotel_identity_mismatch',
                'identity_unverified' => 'profile_hotel_identity_unverified',
                'capture_failed' => 'profile_session_probe_failed',
                'session_expired', 'login_expired' => 'profile_session_expired',
                default => 'profile_session_unverified',
            }];
        }
        if ($triggerType === 'profile_login_after_login') {
            return [];
        }
        $reuseState = $this->profileSessionProofService->profileReuseState($source);
        if (!empty($reuseState['is_reusable'])) {
            return [];
        }
        return [($reuseState['status'] ?? '') === 'expired'
            ? 'profile_session_expired'
            : 'profile_session_unverified'];
    }

    /** @param array<string, mixed> $source */
    private function browserProfileRiskControlReviewRequired(array $source): bool
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $config = is_array($source['config'] ?? null) ? $source['config'] : $this->decodeConfig($source['config_json'] ?? []);
        if (strtolower(trim((string)($config['current_session_status'] ?? ''))) === 'anti_bot') {
            return true;
        }
        if ($this->profileSessionProofService->currentSessionBlockingStatus($source) !== '') {
            return false;
        }
        $profileKey = $this->otaBrowserProfileKey($platform, $config);
        if ($hotelId <= 0 || $profileKey === '') {
            return false;
        }
        $cacheKey = 'platform_profile_status_' . $platform . '_' . $hotelId . '_'
            . BrowserProfileCaptureRequestService::safeFilePart($profileKey);
        try {
            $cached = Cache::get($cacheKey, []);
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($cached)
            || strtolower(trim((string)($cached['status_code'] ?? ''))) !== 'anti_bot'
        ) {
            return false;
        }
        $cacheCheckedAt = trim((string)($cached['checked_at'] ?? ''));
        $currentProbeAt = trim((string)($config['current_session_probe_at'] ?? ''));
        if ($cacheCheckedAt !== '' && $currentProbeAt !== '') {
            $cacheTimestamp = strtotime($cacheCheckedAt);
            $probeTimestamp = strtotime($currentProbeAt);
            if ($cacheTimestamp !== false && $probeTimestamp !== false && $cacheTimestamp < $probeTimestamp) {
                return false;
            }
        }
        return true;
    }

    /**
     * P0 and target-date diagnostics intentionally keep the stricter same-day proof.
     *
     * @param array<string, mixed> $source
     * @return array<int, string>
     */
    private function browserProfileCurrentSessionProofMissingRequirements(array $source): array
    {
        if ($this->isOtaBrowserProfileSource($source)) {
            return $this->profileSessionProofService->isCurrentVerified($source)
                ? []
                : ['current_session_verified'];
        }
        if ($this->isOtaLocalCollectorSource($source)) {
            $config = is_array($source['config'] ?? null)
                ? $source['config']
                : $this->decodeConfig($source['config_json'] ?? []);
            return $this->truthy($config['current_session_verified'] ?? false)
                ? []
                : ['current_session_verified'];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function isOtaBrowserProfileSource(array $source): bool
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $method = strtolower(trim((string)($source['ingestion_method'] ?? '')));
        return in_array($platform, ['ctrip', 'meituan'], true)
            && in_array($method, ['browser_profile', 'profile_browser'], true);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function isOtaLocalCollectorSource(array $source): bool
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $method = strtolower(trim((string)($source['ingestion_method'] ?? '')));

        return in_array($platform, ['ctrip', 'meituan'], true)
            && $method === 'local_collector';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function isOtaBrowserAssistSource(array $source): bool
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $method = strtolower(trim((string)($source['ingestion_method'] ?? '')));

        return in_array($platform, ['ctrip', 'meituan'], true)
            && $method === 'browser_assist_dom';
    }

}
