<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\SystemConfig;
use app\service\BrowserProfileCaptureRequestService;
use app\service\OtaCredentialVault;
use app\service\OtaExecutionStageException;
use app\service\OtaConfigVerificationService;
use RuntimeException;
use think\facade\Db;
use think\facade\Log;

trait OtaConfigConcern
{
    private ?OtaCredentialVault $otaCredentialVaultInstance = null;
    private ?OtaConfigVerificationService $otaConfigVerificationServiceInstance = null;

    protected function otaCredentialVault(): object
    {
        return $this->otaCredentialVaultInstance ??= new OtaCredentialVault();
    }

    private function otaConfigVerificationService(): OtaConfigVerificationService
    {
        return $this->otaConfigVerificationServiceInstance ??= new OtaConfigVerificationService();
    }

    /**
     * @param array<string, mixed> $vaultMetadata
     * @param array<string, mixed> $secretPayload
     * @return array{credential_ref: mixed, credential_status: string, has_cookies: bool, secret_mask: string}
     */
    private function buildSafeOtaCredentialMetadata(array $vaultMetadata, array $secretPayload): array
    {
        $credentialRefValue = $vaultMetadata['credential_ref'] ?? null;
        $credentialRef = null;
        if (is_int($credentialRefValue) && $credentialRefValue > 0) {
            $credentialRef = $credentialRefValue;
        } elseif (is_string($credentialRefValue)) {
            $trimmedRef = trim($credentialRefValue);
            if (preg_match('/^[1-9]\d*$/D', $trimmedRef) === 1) {
                $filteredRef = filter_var($trimmedRef, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (is_int($filteredRef)) {
                    $credentialRef = $filteredRef;
                }
            }
        }
        $credentialStatus = trim((string)($vaultMetadata['credential_status'] ?? ''));
        if ($credentialRef === null || !in_array($credentialStatus, ['ready', 'revoked'], true)) {
            throw new RuntimeException('OTA credential metadata is incomplete.');
        }

        return [
            'credential_ref' => $credentialRef,
            'credential_status' => $credentialStatus,
            'has_cookies' => $this->otaSecretPayloadHasNonEmptyCookie($secretPayload),
            'secret_mask' => trim((string)($vaultMetadata['secret_mask'] ?? '')),
        ];
    }

    private function otaCredentialTenantIdForHotel(int $hotelId): int
    {
        if ($hotelId <= 0) {
            throw new RuntimeException('Hotel tenant scope not found.');
        }

        $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
        if ($tenantId <= 0) {
            throw new RuntimeException('Hotel tenant scope not found.');
        }

        return $tenantId;
    }

    private function strictPositiveOtaConfigHotelId(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if (preg_match('/^[1-9]\d*$/D', $trimmed) === 1) {
                $filtered = filter_var($trimmed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (is_int($filtered)) {
                    return $filtered;
                }
            }
        }

        throw new \InvalidArgumentException('Hotel ID must be a positive integer.');
    }

    private function validateOtaCredentialLocator(string $platform, string $configId): void
    {
        if (!in_array($platform, ['ctrip', 'meituan'], true)
            || preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $configId) !== 1) {
            throw new RuntimeException('Invalid credential locator.');
        }
    }

    /**
     * Execute one operation with a vault payload without hydrating it into controller state.
     */
    private function withOtaCredentialForExecution(
        string $platform,
        string $configId,
        int $hotelId,
        callable $consumer,
        bool $internalCollector = false,
        bool $classifyManualStages = false
    ): mixed {
        try {
            $this->validateOtaCredentialLocator($platform, $configId);
            if (!$internalCollector && !$this->currentUserCanMaintainOtaConfig($hotelId)) {
                throw new RuntimeException('Forbidden OTA credential execution.', 403);
            }

            $tenantId = $this->otaCredentialTenantIdForHotel($hotelId);
            return $this->otaCredentialVault()->withPayloadForExecution(
                $tenantId,
                $hotelId,
                $platform,
                $configId,
                function (array $payload) use ($consumer, $classifyManualStages): mixed {
                    try {
                        $protectedValues = $this->collectReusableOtaCredentialScalars($payload);
                        try {
                            $result = $consumer($payload);
                        } catch (OtaExecutionStageException $e) {
                            throw $e;
                        } catch (RuntimeException $e) {
                            if (!$classifyManualStages) {
                                throw $e;
                            }
                            throw new OtaExecutionStageException(
                                'platform_execution',
                                'OTA 平台请求或数据处理失败',
                                502,
                                $e
                            );
                        }

                        try {
                            $this->assertOtaExecutionResultDoesNotLeak($result, $protectedValues);
                        } catch (RuntimeException $e) {
                            if (!$classifyManualStages) {
                                throw $e;
                            }
                            $safeMessage = $e->getMessage()
                                === 'OTA credential execution result contains protected credential material.'
                                ? '返回结果包含疑似 Cookie/令牌内容，已在结果返回阶段拦截'
                                : '获取结果安全检查未通过（结果返回阶段）';
                            throw new OtaExecutionStageException(
                                'result_inspection',
                                $safeMessage,
                                500,
                                $e
                            );
                        }
                        return $result;
                    } finally {
                        $payload = [];
                        unset($payload);
                    }
                }
            );
        } catch (OtaExecutionStageException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            if (!$classifyManualStages) {
                throw $e;
            }
            $forbidden = $e->getCode() === 403;
            throw new OtaExecutionStageException(
                $forbidden ? 'authorization' : 'credential',
                $forbidden ? '无权使用该门店 OTA 凭据' : 'OTA 凭据不可用',
                $forbidden ? 403 : 409,
                $e
            );
        }
    }

    private function otaExecutionStageFailureResponse(
        string $operation,
        OtaExecutionStageException $exception
    ): \think\Response {
        Log::error('Manual OTA execution failed.', [
            'operation' => $operation,
            'stage' => $exception->stage(),
            'exception_type' => get_debug_type($exception->getPrevious() ?? $exception),
        ]);

        return $this->error($exception->safeMessage(), $exception->httpStatus(), [
            'reason' => 'ota_manual_execution_failed',
            'stage' => $exception->stage(),
        ]);
    }

    private function otaUnknownExecutionFailureResponse(string $operation, \Throwable $exception): \think\Response
    {
        Log::error('Manual OTA execution failed without a classified stage.', [
            'operation' => $operation,
            'stage' => 'unknown',
            'exception_type' => get_debug_type($exception),
        ]);

        return $this->error('OTA 执行失败', 500, [
            'reason' => 'ota_manual_execution_failed',
            'stage' => 'unknown',
        ]);
    }

    /**
     * @param array<mixed> $payload
     * @return array{substring: array<int, string>, exact: array<int, string>}
     */
    private function collectReusableOtaCredentialScalars(array $payload): array
    {
        $protected = [
            'substring' => [],
            'exact' => [],
        ];
        $totalItems = 0;
        $totalBytes = 0;
        $this->collectReusableOtaCredentialScalarsFromValue(
            $payload,
            'normal',
            0,
            $totalItems,
            $totalBytes,
            $protected
        );
        return [
            'substring' => array_values($protected['substring']),
            'exact' => array_values($protected['exact']),
        ];
    }

    /**
     * @param array<mixed> $value
     * @param array{substring: array<string, string>, exact: array<string, string>} $protected
     */
    private function collectReusableOtaCredentialScalarsFromValue(
        array $value,
        string $context,
        int $depth,
        int &$totalItems,
        int &$totalBytes,
        array &$protected,
        bool $decodeAuthDataStrings = true,
        bool $setCookieHeaderList = false
    ): void {
        if ($depth > 8) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }
        $totalItems += count($value);
        if ($totalItems > 256) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }
        if ($context === 'cookie_header') {
            $this->collectReusableOtaCookieHeaderList($value, $setCookieHeaderList, $protected);
            return;
        }
        if ($context === 'headers' && $this->otaCredentialArrayHasIntegerKey($value)) {
            $this->collectReusableOtaRawHeaderLineList($value, $protected);
            return;
        }

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $totalBytes += strlen($key);
            }

            $normalizedKey = is_string($key) ? $this->normalizeOtaCredentialContextKey($key) : '';
            $compactKey = str_replace('_', '', $normalizedKey);
            $nextContext = 'normal';
            $collectScalar = false;
            $nextSetCookieHeaderList = false;

            if ($context === 'cookie_values') {
                $nextContext = 'cookie_values';
                $collectScalar = !$this->isKnownNonCredentialOtaCookieName($normalizedKey);
            } elseif ($context === 'headers') {
                $nextContext = 'headers';
                $sensitiveHeader = $this->isReusableOtaSensitiveHeaderKey($normalizedKey);
                if ($sensitiveHeader && is_array($item)) {
                    if (in_array($compactKey, ['cookie', 'setcookie'], true)) {
                        $nextContext = 'cookie_header';
                        $nextSetCookieHeaderList = $compactKey === 'setcookie';
                    } else {
                        throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
                    }
                } else {
                    $collectScalar = $sensitiveHeader;
                }
            } elseif ($compactKey === 'headers') {
                $nextContext = 'headers';
                $collectScalar = !is_array($item);
            } elseif ($compactKey === 'authdata') {
                $nextContext = 'normal';
                $collectScalar = !is_array($item);
            } elseif ($compactKey === 'headersjson' || $compactKey === 'secretjson') {
                if (!is_string($item) && $item !== null) {
                    throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
                }
                $collectScalar = is_string($item);
            } elseif ($compactKey === 'cookieobj') {
                $nextContext = 'cookie_values';
                $collectScalar = true;
            } elseif ($this->isOtaSecretConfigKey($normalizedKey)) {
                if ($normalizedKey === 'set_cookie' && is_array($item)) {
                    $nextContext = 'cookie_header';
                    $nextSetCookieHeaderList = true;
                } else {
                    $nextContext = 'cookie_values';
                    $collectScalar = true;
                }
            }

            $isHeadersString = $context === 'normal' && $compactKey === 'headers' && is_string($item);
            $isAuthDataString = $context === 'normal' && $compactKey === 'authdata' && is_string($item);
            $isHeadersJsonString = $context === 'normal' && $compactKey === 'headersjson' && is_string($item);
            $isSecretJsonString = $context === 'normal' && $compactKey === 'secretjson' && is_string($item);
            $isAuthorizationString = is_string($item) && (
                ($context === 'headers' && in_array($compactKey, ['authorization', 'proxyauthorization'], true))
                || ($context === 'normal' && in_array($normalizedKey, ['authorization', 'authorization_header'], true))
            );
            $isCookieHeaderString = is_string($item) && (
                in_array($normalizedKey, ['cookies', 'cookie', 'set_cookie'], true)
                || ($context === 'headers' && in_array($compactKey, ['cookie', 'setcookie'], true))
            );
            if (is_string($item) && $collectScalar && strlen($item) > 65536) {
                throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
            }
            if (is_string($item) && !$collectScalar && !$isAuthDataString) {
                $totalBytes += strlen($item);
            }

            if (is_array($item)) {
                $this->collectReusableOtaCredentialScalarsFromValue(
                    $item,
                    $nextContext,
                    $depth + 1,
                    $totalItems,
                    $totalBytes,
                    $protected,
                    $decodeAuthDataStrings,
                    $nextSetCookieHeaderList
                );
            } elseif ($collectScalar) {
                $this->addReusableOtaCredentialScalar($item, $protected);
            }
            if ($isAuthDataString && $decodeAuthDataStrings) {
                $this->collectReusableOtaCredentialScalarsFromAuthDataString($item, $protected);
            }
            if ($isHeadersString) {
                $this->collectReusableOtaCredentialScalarsFromHeadersString($item, $protected);
            }
            if ($isHeadersJsonString) {
                $this->collectReusableOtaCredentialScalarsFromHeadersJson($item, $protected);
            }
            if ($isSecretJsonString) {
                $this->collectReusableOtaCredentialScalarsFromSecretJson($item, $protected);
            }
            if ($isCookieHeaderString) {
                $this->collectReusableOtaCookieHeaderValues(
                    $item,
                    $normalizedKey === 'set_cookie'
                        || ($context === 'headers' && $compactKey === 'setcookie'),
                    $protected
                );
            }
            if ($isAuthorizationString) {
                $this->collectReusableOtaAuthorizationValue($item, $protected);
            }

            if (
                $totalBytes > 65536
                || count($protected['substring']) + count($protected['exact']) > 512
            ) {
                throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
            }
        }
    }

    /**
     * @param array{substring: array<string, string>, exact: array<string, string>} $protected
     */
    private function collectReusableOtaCredentialScalarsFromAuthDataString(
        string $authData,
        array &$protected
    ): void {
        $decoded = $this->decodeReusableOtaJsonCredentialContainer($authData);
        if ($decoded === null) {
            return;
        }

        $nestedItems = 0;
        $nestedBytes = 0;
        try {
            $this->collectReusableOtaCredentialScalarsFromValue(
                $decoded,
                'normal',
                0,
                $nestedItems,
                $nestedBytes,
                $protected,
                false
            );
        } catch (RuntimeException) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }
    }

    /**
     * @param array{substring: array<string, string>, exact: array<string, string>} $protected
     */
    private function collectReusableOtaCredentialScalarsFromHeadersJson(
        string $headersJson,
        array &$protected
    ): void {
        $decoded = $this->decodeReusableOtaJsonCredentialContainer($headersJson, true);

        $nestedItems = 0;
        $nestedBytes = 0;
        try {
            $this->collectReusableOtaCredentialScalarsFromValue(
                $decoded,
                'headers',
                0,
                $nestedItems,
                $nestedBytes,
                $protected,
                false
            );
        } catch (RuntimeException) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }
    }

    /**
     * @param array{substring: array<string, string>, exact: array<string, string>} $protected
     */
    private function collectReusableOtaCredentialScalarsFromHeadersString(
        string $headers,
        array &$protected
    ): void {
        $length = strlen($headers);
        if ($length > 65536) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }
        if ($length === 0) {
            return;
        }

        $trimmed = ltrim($headers);
        $firstCharacter = $trimmed !== '' ? $trimmed[0] : '';
        $looksLikeJson = in_array($firstCharacter, ['{', '[', '"'], true)
            || preg_match('/^(?:true|false|null|-?\d)/i', $trimmed) === 1;
        try {
            $decoded = json_decode($headers, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            if ($looksLikeJson) {
                throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
            }
            $this->collectReusableOtaRawHeaderBlock($headers, $protected);
            return;
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }

        $nestedItems = 0;
        $nestedBytes = 0;
        $this->collectReusableOtaCredentialScalarsFromValue(
            $decoded,
            'headers',
            0,
            $nestedItems,
            $nestedBytes,
            $protected,
            false
        );
    }

    /**
     * @param array{substring: array<string, string>, exact: array<string, string>} $protected
     */
    private function collectReusableOtaCredentialScalarsFromSecretJson(
        string $secretJson,
        array &$protected
    ): void {
        $decoded = $this->decodeReusableOtaJsonCredentialContainer($secretJson, true);
        if ($decoded === null) {
            return;
        }

        $nestedItems = 0;
        $nestedBytes = 0;
        $this->collectAllReusableOtaSecretScalars(
            $decoded,
            0,
            $nestedItems,
            $nestedBytes,
            $protected
        );
    }

    /**
     * @return array<mixed>|null
     */
    private function decodeReusableOtaJsonCredentialContainer(
        string $json,
        bool $requireValidJson = false
    ): ?array
    {
        $length = strlen($json);
        if ($length === 0) {
            if ($requireValidJson) {
                throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
            }
            return null;
        }
        if ($length > 65536) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }

        $trimmed = ltrim($json);
        $firstCharacter = $trimmed !== '' ? $trimmed[0] : '';
        $looksLikeJson = in_array($firstCharacter, ['{', '[', '"'], true)
            || preg_match('/^(?:true|false|null|-?\d)/i', $trimmed) === 1;

        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            if ($requireValidJson || $looksLikeJson) {
                throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
            }
            return null;
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }
        return $decoded;
    }

    /**
     * @param array<mixed> $value
     * @param array{substring: array<string, string>, exact: array<string, string>} $protected
     */
    private function collectAllReusableOtaSecretScalars(
        array $value,
        int $depth,
        int &$totalItems,
        int &$totalBytes,
        array &$protected
    ): void {
        if ($depth > 8) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }
        $totalItems += count($value);
        if ($totalItems > 256) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $totalBytes += strlen($key);
            }
            if (is_array($item)) {
                $this->collectAllReusableOtaSecretScalars(
                    $item,
                    $depth + 1,
                    $totalItems,
                    $totalBytes,
                    $protected
                );
            } elseif ($item !== null) {
                $scalar = $this->reusableOtaCredentialScalarString($item);
                if ($scalar === null) {
                    throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
                }
                $totalBytes += strlen($scalar);
                if ($scalar !== '') {
                    $this->addReusableOtaCredentialScalar($scalar, $protected);
                }
            }
            if ($totalBytes > 65536) {
                throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
            }
        }
    }

    /**
     * @param array{substring: array<string, string>, exact: array<string, string>} $protected
     */
    private function collectReusableOtaCookieHeaderValues(
        string $header,
        bool $setCookie,
        array &$protected
    ): void {
        $length = strlen($header);
        if ($length === 0) {
            return;
        }
        if ($length > 65536) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }

        $parts = explode(';', $header);
        if (count($parts) > 128) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }
        $setCookieAttributeSet = array_fill_keys([
            'domain',
            'expires',
            'max_age',
            'path',
            'priority',
            'samesite',
        ], true);

        foreach ($parts as $index => $part) {
            $separator = strpos($part, '=');
            if ($separator === false) {
                continue;
            }
            $name = trim(substr($part, 0, $separator));
            if ($name === '') {
                continue;
            }
            $normalizedName = $this->normalizeOtaCredentialContextKey($name);
            if ($setCookie && $index > 0 && isset($setCookieAttributeSet[$normalizedName])) {
                continue;
            }
            if ($this->isKnownNonCredentialOtaCookieName($normalizedName)) {
                continue;
            }

            $cookieValue = trim(substr($part, $separator + 1));
            $valueLength = strlen($cookieValue);
            if ($valueLength >= 2) {
                $first = $cookieValue[0];
                $last = $cookieValue[$valueLength - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $cookieValue = substr($cookieValue, 1, -1);
                }
            }
            if ($cookieValue !== '' && !$this->isNonCredentialOtaCookiePreferenceValue($cookieValue)) {
                $this->addReusableOtaCredentialScalar($cookieValue, $protected);
            }
        }
    }

    /**
     * @param array<mixed> $headers
     * @param array{substring: array<string, string>, exact: array<string, string>} $protected
     */
    private function collectReusableOtaCookieHeaderList(
        array $headers,
        bool $setCookie,
        array &$protected
    ): void {
        if (count($headers) > 128) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }

        $expectedIndex = 0;
        $totalBytes = 0;
        foreach ($headers as $index => $header) {
            if ($index !== $expectedIndex || !is_string($header)) {
                throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
            }
            $expectedIndex++;
            $totalBytes += strlen($header);
            if ($totalBytes > 65536) {
                throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
            }

            $this->addReusableOtaCredentialScalar($header, $protected);
            $this->collectReusableOtaCookieHeaderValues($header, $setCookie, $protected);
        }
    }

    /**
     * @param array<mixed> $headers
     * @param array{substring: array<string, string>, exact: array<string, string>} $protected
     */
    private function collectReusableOtaRawHeaderLineList(array $headers, array &$protected): void
    {
        if (count($headers) > 128) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }

        $expectedIndex = 0;
        $totalBytes = 0;
        foreach ($headers as $index => $headerLine) {
            if ($index !== $expectedIndex || !is_string($headerLine)) {
                throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
            }
            $expectedIndex++;
            $totalBytes += strlen($headerLine);
            if ($totalBytes > 65536) {
                throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
            }

            $separator = strpos($headerLine, ':');
            if ($separator === false) {
                throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
            }
            $headerName = trim(substr($headerLine, 0, $separator));
            if ($headerName === '') {
                throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
            }
            $headerValue = trim(substr($headerLine, $separator + 1));
            $normalizedName = $this->normalizeOtaCredentialContextKey($headerName);
            $compactName = str_replace('_', '', $normalizedName);

            if (in_array($compactName, ['cookie', 'setcookie'], true)) {
                $this->addReusableOtaCredentialScalar($headerLine, $protected);
                $this->addReusableOtaCredentialScalar($headerValue, $protected);
                $this->collectReusableOtaCookieHeaderValues(
                    $headerValue,
                    $compactName === 'setcookie',
                    $protected
                );
            } elseif (in_array($compactName, ['authorization', 'proxyauthorization'], true)) {
                $this->addReusableOtaCredentialScalar($headerLine, $protected);
                $this->collectReusableOtaAuthorizationValue($headerValue, $protected);
            } elseif ($this->isReusableOtaSensitiveHeaderKey($normalizedName)) {
                $this->addReusableOtaCredentialScalar($headerLine, $protected);
                $this->addReusableOtaCredentialScalar($headerValue, $protected);
            }
        }
    }

    /**
     * @param array{substring: array<string, string>, exact: array<string, string>} $protected
     */
    private function collectReusableOtaRawHeaderBlock(string $headers, array &$protected): void
    {
        if (strlen($headers) > 65536) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }

        $rawLines = preg_split('/\r\n|\n|\r/', $headers);
        if (!is_array($rawLines)) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }
        $lines = [];
        foreach ($rawLines as $line) {
            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }
        $this->collectReusableOtaRawHeaderLineList($lines, $protected);
    }

    /**
     * @param array{substring: array<string, string>, exact: array<string, string>} $protected
     */
    private function collectReusableOtaAuthorizationValue(string $value, array &$protected): void
    {
        $this->addReusableOtaCredentialScalar($value, $protected);
        $trimmed = trim($value);
        if (preg_match('/^\S+\s+(.+)$/s', $trimmed, $matches) !== 1) {
            return;
        }
        $credential = trim((string)($matches[1] ?? ''));
        if ($credential !== '') {
            $this->addReusableOtaCredentialScalar($credential, $protected);
        }
    }

    /**
     * @param array<mixed> $value
     */
    private function otaCredentialArrayHasIntegerKey(array $value): bool
    {
        foreach ($value as $key => $_) {
            if (is_int($key)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeOtaCredentialContextKey(string $key): string
    {
        $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', trim($key)));
        return trim($normalized, '_');
    }

    private function isReusableOtaSensitiveHeaderKey(string $normalizedKey): bool
    {
        $compact = str_replace('_', '', $normalizedKey);
        if (in_array($compact, [
            'cookie',
            'setcookie',
            'authorization',
            'proxyauthorization',
            'token',
            'spidertoken',
            'mtgsig',
            'usertoken',
            'usersign',
            'apikey',
            'authtoken',
            'authorizationheader',
            'secret',
        ], true)) {
            return true;
        }

        return str_ends_with($compact, 'apikey')
            || str_ends_with($compact, 'token')
            || str_ends_with($compact, 'secret')
            || str_contains($compact, 'authorization')
            || str_contains($compact, 'cookie');
    }

    private function isKnownNonCredentialOtaCookieName(string $normalizedName): bool
    {
        return in_array(str_replace('_', '', $normalizedName), [
            'bfastatus',
            'cookiepricesdisplayed',
            'currency',
            'locale',
            'language',
            'lang',
        ], true);
    }

    private function isNonCredentialOtaCookiePreferenceValue(string $value): bool
    {
        return preg_match('/^(?:true|false|null|-?\d+(?:\.\d+)?)$/i', trim($value)) === 1;
    }

    /**
     * @param array{substring: array<string, string>, exact: array<string, string>} $protected
     */
    private function addReusableOtaCredentialScalar(mixed $value, array &$protected): void
    {
        $scalar = $this->reusableOtaCredentialScalarString($value);
        if ($scalar === null || $scalar === '' || in_array(strtolower($scalar), ['true', 'false'], true)) {
            return;
        }
        $mode = strlen($scalar) >= 8 ? 'substring' : 'exact';
        $hash = hash('sha256', $scalar);
        if (isset($protected[$mode][$hash])) {
            return;
        }
        if (count($protected['substring']) + count($protected['exact']) >= 512) {
            throw new RuntimeException('OTA credential payload exceeds execution inspection limits.');
        }
        $protected[$mode][$hash] = $scalar;
    }

    private function reusableOtaCredentialScalarString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return null;
    }

    /**
     * @param array{substring: array<int, string>, exact: array<int, string>} $protectedValues
     */
    private function assertOtaExecutionResultDoesNotLeak(mixed $result, array $protectedValues): void
    {
        if ($protectedValues['substring'] === [] && $protectedValues['exact'] === []) {
            return;
        }

        if ($result instanceof \think\Response) {
            $inspected = false;
            if (is_callable([$result, 'getData'])) {
                try {
                    $value = $result->getData();
                } catch (\Throwable) {
                    throw new RuntimeException('OTA credential execution result could not be inspected.');
                }
                $this->assertOtaExecutionValueDoesNotLeak($value, $protectedValues);
                $inspected = true;
            }
            if (is_callable([$result, 'getContent'])) {
                try {
                    $content = $result->getContent();
                } catch (\Throwable) {
                    throw new RuntimeException('OTA credential execution result could not be inspected.');
                }
                $this->assertOtaExecutionResponseContentDoesNotLeak($content, $protectedValues);
                $inspected = true;
            }
            if (!$inspected) {
                throw new RuntimeException('OTA credential execution result could not be inspected.');
            }
            return;
        }

        $this->assertOtaExecutionValueDoesNotLeak($result, $protectedValues);
    }

    /**
     * @param array{substring: array<int, string>, exact: array<int, string>} $protectedValues
     */
    private function assertOtaExecutionResponseContentDoesNotLeak(
        string $content,
        array $protectedValues
    ): void {
        if (strlen($content) > 16777216) {
            throw new RuntimeException('OTA credential execution result exceeds inspection limits.');
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->assertOtaExecutionValueDoesNotLeak($content, $protectedValues);
            return;
        }

        $this->assertOtaExecutionValueDoesNotLeak($decoded, $protectedValues);
        $this->assertOtaExecutionStringDoesNotLeak($content, $protectedValues, false);
    }

    /**
     * @param array{substring: array<int, string>, exact: array<int, string>} $protectedValues
     */
    private function assertOtaExecutionValueDoesNotLeak(mixed $value, array $protectedValues): void
    {
        $totalItems = 0;
        $totalBytes = 0;
        $this->scanOtaExecutionResultValue(
            $value,
            $protectedValues,
            0,
            $totalItems,
            $totalBytes
        );
    }

    /**
     * @param array{substring: array<int, string>, exact: array<int, string>} $protectedValues
     */
    private function scanOtaExecutionResultValue(
        mixed $value,
        array $protectedValues,
        int $depth,
        int &$totalItems,
        int &$totalBytes
    ): void {
        if ($depth > 12) {
            throw new RuntimeException('OTA credential execution result exceeds inspection limits.');
        }
        if (is_array($value)) {
            $totalItems += count($value);
            if ($totalItems > 100000) {
                throw new RuntimeException('OTA credential execution result exceeds inspection limits.');
            }
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $this->assertOtaExecutionStringDoesNotLeak($key, $protectedValues);
                    $totalBytes += strlen($key);
                }
                $this->scanOtaExecutionResultValue(
                    $item,
                    $protectedValues,
                    $depth + 1,
                    $totalItems,
                    $totalBytes
                );
                if ($totalBytes > 16777216) {
                    throw new RuntimeException('OTA credential execution result exceeds inspection limits.');
                }
            }
            return;
        }
        if (is_string($value)) {
            $totalBytes += strlen($value);
            if ($totalBytes > 16777216) {
                throw new RuntimeException('OTA credential execution result exceeds inspection limits.');
            }
            $this->assertOtaExecutionStringDoesNotLeak($value, $protectedValues);
            return;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            $scalar = $this->reusableOtaCredentialScalarString($value);
            if ($scalar !== null) {
                $totalBytes += strlen($scalar);
                if ($totalBytes > 16777216) {
                    throw new RuntimeException('OTA credential execution result exceeds inspection limits.');
                }
                $this->assertOtaExecutionStringDoesNotLeak($scalar, $protectedValues);
            }
            return;
        }
        if (is_object($value)) {
            throw new RuntimeException('OTA credential execution result could not be inspected.');
        }
    }

    /**
     * @param array{substring: array<int, string>, exact: array<int, string>} $protectedValues
     */
    private function assertOtaExecutionStringDoesNotLeak(
        string $value,
        array $protectedValues,
        bool $checkExact = true
    ): void {
        foreach ($protectedValues['substring'] as $protectedValue) {
            if (str_contains($value, $protectedValue)) {
                throw new RuntimeException(
                    'OTA credential execution result contains protected credential material.'
                );
            }
        }
        if (!$checkExact) {
            return;
        }
        foreach ($protectedValues['exact'] as $protectedValue) {
            if (hash_equals($protectedValue, $value)) {
                throw new RuntimeException(
                    'OTA credential execution result contains protected credential material.'
                );
            }
        }
    }

    /**
     * Execution endpoints accept only a credential locator, never reusable credentials.
     *
     * @param array<string, mixed> $requestData
     */
    private function assertNoInlineOtaExecutionCredentials(array $requestData): void
    {
        $totalItems = 0;
        $totalBytes = 0;
        $this->scanInlineOtaExecutionCredentials($requestData, 0, $totalItems, $totalBytes);
    }

    /**
     * @param array<mixed> $value
     */
    private function scanInlineOtaExecutionCredentials(
        array $value,
        int $depth,
        int &$totalItems,
        int &$totalBytes
    ): void {
        if ($depth > 4) {
            throw new \InvalidArgumentException('OTA execution request exceeds credential scan limits.', 400);
        }
        $totalItems += count($value);
        if ($totalItems > 64) {
            throw new \InvalidArgumentException('OTA execution request exceeds credential scan limits.', 400);
        }

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                if (is_array($item)) {
                    $this->scanInlineOtaExecutionCredentials($item, $depth + 1, $totalItems, $totalBytes);
                } elseif (is_string($item)) {
                    $totalBytes += strlen($item);
                }
                if ($totalBytes > 4096) {
                    throw new \InvalidArgumentException('OTA execution request exceeds credential scan limits.', 400);
                }
                continue;
            }
            $totalBytes += strlen($key);
            if ($totalBytes > 4096) {
                throw new \InvalidArgumentException('OTA execution request exceeds credential scan limits.', 400);
            }
            if ($this->isOtaSecretConfigKey($key)) {
                throw new \InvalidArgumentException(
                    'Inline OTA credentials are not allowed on execution endpoints.',
                    400
                );
            }
            if (is_array($item)) {
                $this->scanInlineOtaExecutionCredentials($item, $depth + 1, $totalItems, $totalBytes);
            } elseif (is_string($item)) {
                $totalBytes += strlen($item);
            }
            if ($totalBytes > 4096) {
                throw new \InvalidArgumentException('OTA execution request exceeds credential scan limits.', 400);
            }
        }
    }

    /**
     * @param array<string, mixed> $secretPayload
     * @param array<string, mixed> $existingMetadata
     * @return array{credential_ref: mixed, credential_status: string, has_cookies: bool, secret_mask: string}
     */
    private function storeOtaConfigCredential(
        int $hotelId,
        string $platform,
        string $configId,
        array $secretPayload,
        int $actorId,
        array $existingMetadata = []
    ): array {
        $this->validateOtaCredentialLocator($platform, $configId);
        $tenantId = $this->otaCredentialTenantIdForHotel($hotelId);

        if (!$this->otaSecretPayloadHasNonEmptyScalar($secretPayload)) {
            return $this->buildSafeOtaCredentialMetadata([
                'credential_ref' => $existingMetadata['credential_ref'] ?? null,
                'credential_status' => $existingMetadata['credential_status'] ?? '',
                'secret_mask' => $existingMetadata['secret_mask'] ?? '',
            ], !empty($existingMetadata['has_cookies']) ? ['cookies' => true] : []);
        }

        $stored = $this->otaCredentialVault()->store(
            $tenantId,
            $hotelId,
            $platform,
            $configId,
            $secretPayload,
            $actorId
        );
        if (!is_array($stored)) {
            throw new RuntimeException('OTA credential vault returned invalid metadata.');
        }

        return $this->buildSafeOtaCredentialMetadata($stored, $secretPayload);
    }

    private function deleteOtaConfigCredential(int $hotelId, string $platform, string $configId): bool
    {
        $this->validateOtaCredentialLocator($platform, $configId);
        $tenantId = $this->otaCredentialTenantIdForHotel($hotelId);
        return (bool)$this->otaCredentialVault()->delete($tenantId, $hotelId, $platform, $configId);
    }

    private function revokeOtaConfigCredential(int $hotelId, string $platform, string $configId): bool
    {
        $this->validateOtaCredentialLocator($platform, $configId);
        $tenantId = $this->otaCredentialTenantIdForHotel($hotelId);
        $metadata = $this->otaCredentialVault()->revoke($tenantId, $hotelId, $platform, $configId);
        return is_array($metadata) && strtolower(trim((string)($metadata['credential_status'] ?? ''))) === 'revoked';
    }

    /**
     * Persist one Ctrip configuration and its credential in the same database transaction.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function persistCtripConfigMetadata(array $config, int $actorId, bool $isUpdate): array
    {
        $id = trim((string)($config['id'] ?? ''));
        $configId = trim((string)($config['config_id'] ?? $id));
        if ($id === '' || $configId === '' || !hash_equals($id, $configId)) {
            throw new RuntimeException('Ctrip config id is invalid.');
        }
        $this->validateOtaCredentialLocator('ctrip', $configId);

        $systemHotelId = $this->strictPositiveOtaConfigHotelId($config['system_hotel_id'] ?? null);
        $hotelId = null;
        if (array_key_exists('hotel_id', $config) && $config['hotel_id'] !== null && $config['hotel_id'] !== '') {
            $hotelId = $this->strictPositiveOtaConfigHotelId($config['hotel_id']);
        }
        if ($hotelId !== null && $hotelId !== $systemHotelId) {
            throw new RuntimeException('Ctrip config hotel binding is invalid.');
        }
        $this->otaCredentialTenantIdForHotel($systemHotelId);

        $saved = Db::transaction(function () use ($config, $actorId, $isUpdate, $id, $configId, $systemHotelId): array {
            $key = 'ctrip_config_list';
            $row = Db::name('system_configs')->where('config_key', $key)->lock(true)->find();
            $list = [];
            if ($row) {
                $decoded = json_decode((string)($row['config_value'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Stored Ctrip config list is invalid.');
                }
                $list = $decoded;
            }

            $existing = isset($list[$id]) && is_array($list[$id]) ? $list[$id] : [];
            if ($isUpdate && $existing === []) {
                throw new RuntimeException('Ctrip config does not exist.');
            }
            if (!$isUpdate && $existing !== []) {
                throw new RuntimeException('Ctrip config already exists.');
            }
            if ($isUpdate) {
                $existingConfigId = trim((string)($existing['config_id'] ?? $existing['id'] ?? ''));
                if ($this->otaConfigHasHotelBindingConflict($existing)
                    || $this->otaConfigBoundSystemHotelId($existing) !== $systemHotelId
                    || $existingConfigId === ''
                    || !hash_equals($id, $existingConfigId)) {
                    throw new RuntimeException('Ctrip config scope changed during save.');
                }
            }

            $platformHotelId = $this->otaPlatformHotelIdFromConfig('ctrip', $config);
            $this->assertUniqueOtaPlatformHotelBinding(
                $list,
                'ctrip',
                $platformHotelId,
                $systemHotelId,
                $id
            );

            foreach ($list as $siblingId => $sibling) {
                if ((string)$siblingId === $id) {
                    continue;
                }
                if (!is_array($sibling)) {
                    throw new RuntimeException('Stored Ctrip sibling config is invalid.');
                }
                [, $siblingSecrets] = $this->splitOtaConfigSecrets($sibling);
                if ($this->otaSecretPayloadHasNonEmptyScalar($siblingSecrets)) {
                    throw new RuntimeException('Legacy Ctrip sibling credential must migrate before save.');
                }
                $list[$siblingId] = $this->sanitizeSecretConfig($sibling);
            }

            if ($isUpdate) {
                $this->appendOtaConfigHistoryVersion($list, $id, $existing);
            }
            $this->retireOtherCurrentOtaConfigs($list, $systemHotelId, $id);

            [$metadata, $secretPayload] = $this->splitOtaConfigSecrets($config);
            foreach (['credential_ref', 'credential_status', 'has_cookies', 'secret_mask'] as $field) {
                unset($metadata[$field]);
            }
            $metadata['id'] = $id;
            $metadata['config_id'] = $configId;
            $metadata['hotel_id'] = (string)$systemHotelId;
            $metadata['system_hotel_id'] = $systemHotelId;
            $metadata['capture_sections'] = 'all';
            $metadata['profile_sections'] = 'all';
            $metadata['config_status'] = 'active';
            $metadata['deleted_at'] = '';

            $credentialMetadata = $this->storeOtaConfigCredential(
                $systemHotelId,
                'ctrip',
                $configId,
                $secretPayload,
                $actorId,
                $existing
            );
            $metadata = array_merge($metadata, $credentialMetadata);
            $metadata['verification_status'] = 'saved_pending_verification';
            $metadata['verification_status_label'] = '已保存，待授权验证';
            $metadata['configuration_saved'] = true;
            $metadata['configuration_verified'] = false;
            $metadata['verified_at'] = '';
            $list[$id] = $metadata;
            $jsonValue = json_encode(
                $list,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $now = date('Y-m-d H:i:s');

            if ($row) {
                Db::name('system_configs')->where('config_key', $key)->update([
                    'config_value' => $jsonValue,
                    'update_time' => $now,
                ]);
            } else {
                Db::name('system_configs')->insert([
                    'config_key' => $key,
                    'config_value' => $jsonValue,
                    'description' => '携程配置列表',
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }

            return $metadata;
        });

        SystemConfig::clearProtectedOtaCaches();
        return $saved;
    }

    /**
     * Persist one Meituan configuration and its credential in the same database transaction.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function persistMeituanConfigMetadata(
        array $config,
        int $actorId,
        bool $isUpdate,
        ?string $expectedScope = null
    ): array
    {
        $id = trim((string)($config['id'] ?? ''));
        $configId = trim((string)($config['config_id'] ?? $id));
        if ($id === '' || $configId === '' || !hash_equals($id, $configId)) {
            throw new RuntimeException('Meituan config id is invalid.');
        }
        $this->validateOtaCredentialLocator('meituan', $configId);

        $systemHotelId = $this->strictOtaConfigBoundHotelId($config, 'Meituan');
        $this->otaCredentialTenantIdForHotel($systemHotelId);
        if ($this->isMeituanCommentConfigMetadata($config)) {
            throw new \InvalidArgumentException('Meituan protected review metadata is invalid for normal save.');
        }
        $configScope = trim((string)($config['scope'] ?? ''));
        if ($expectedScope !== null && !hash_equals($expectedScope, $configScope)) {
            throw new RuntimeException('Meituan config scope is invalid.');
        }

        $saved = Db::transaction(function () use (
            $config,
            $actorId,
            $isUpdate,
            $id,
            $configId,
            $systemHotelId,
            $configScope,
            $expectedScope
        ): array {
            $key = 'meituan_config_list';
            $row = Db::name('system_configs')->where('config_key', $key)->lock(true)->find();
            $list = [];
            if ($row) {
                $decoded = json_decode((string)($row['config_value'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Stored Meituan config list is invalid.');
                }
                $list = $decoded;
            }

            foreach ($list as $siblingKey => $sibling) {
                $siblingId = (string)$siblingKey;
                if ($siblingId === '' || !is_array($sibling)) {
                    throw new RuntimeException('Stored Meituan sibling config is invalid.');
                }
                if ($this->isMeituanCommentConfigMetadata($sibling)) {
                    throw new RuntimeException('Stored Meituan protected review scope metadata blocks normal save.');
                }
                $storedId = trim((string)($sibling['id'] ?? $siblingId));
                $storedConfigId = trim((string)($sibling['config_id'] ?? $storedId));
                if ($storedId === ''
                    || $storedConfigId === ''
                    || !hash_equals($siblingId, $storedId)
                    || !hash_equals($siblingId, $storedConfigId)) {
                    throw new RuntimeException('Stored Meituan sibling config id is invalid.');
                }
                $this->validateOtaCredentialLocator('meituan', $storedConfigId);
                $siblingHotelId = $this->strictOtaConfigBoundHotelId($sibling, 'Meituan');
                // Historical metadata may outlive its hotel row. Only the target
                // hotel is required to have a current tenant binding for this save.

                [, $siblingSecrets] = $this->splitOtaConfigSecrets($sibling);
                $siblingSecrets = $this->sanitizeOtaVaultSecretPayload($siblingSecrets);
                if ($this->otaSecretPayloadHasNonEmptyScalar($siblingSecrets)) {
                    // The target is intentionally included: normal CRUD must never read or migrate legacy plaintext.
                    throw new RuntimeException(
                        'Legacy Meituan plaintext credential requires Task6 migration; normal save cannot read or migrate it.'
                    );
                }

                $safeSibling = $this->sanitizeSecretConfig($sibling);
                $safeSibling['id'] = $siblingId;
                $safeSibling['config_id'] = $storedConfigId;
                $safeSibling['hotel_id'] = (string)$siblingHotelId;
                $safeSibling['system_hotel_id'] = $siblingHotelId;
                $list[$siblingKey] = $safeSibling;
            }

            $existing = isset($list[$id]) && is_array($list[$id]) ? $list[$id] : [];
            if ($isUpdate && $existing === []) {
                throw new RuntimeException('Meituan config does not exist.');
            }
            if (!$isUpdate && $existing !== []) {
                throw new RuntimeException('Meituan config already exists.');
            }
            if ($isUpdate) {
                $existingConfigId = trim((string)($existing['config_id'] ?? $existing['id'] ?? ''));
                $existingScope = trim((string)($existing['scope'] ?? ''));
                if ($this->strictOtaConfigBoundHotelId($existing, 'Meituan') !== $systemHotelId
                    || $existingConfigId === ''
                    || !hash_equals($id, $existingConfigId)
                    || !hash_equals($existingScope, $configScope)
                    || ($expectedScope !== null && !hash_equals($expectedScope, $existingScope))) {
                    throw new RuntimeException('Meituan config scope changed during save.');
                }
            }

            $this->assertUniqueOtaPlatformHotelBinding(
                $list,
                'meituan',
                $this->otaPlatformHotelIdFromConfig('meituan', $config),
                $systemHotelId,
                $id
            );

            if ($isUpdate) {
                $this->appendOtaConfigHistoryVersion($list, $id, $existing);
            }
            $this->retireOtherCurrentOtaConfigs($list, $systemHotelId, $id);

            [$metadata, $secretPayload] = $this->splitOtaConfigSecrets($config);
            $secretPayload = $this->sanitizeOtaVaultSecretPayload($secretPayload);
            foreach ([
                'credential_ref',
                'credential_status',
                'has_cookies',
                'secret_mask',
                'credential_requirement',
                'credential_status_label',
                'credential_level',
                'credential_level_label',
                'missing_fields',
                'missing_text',
                'api_configured',
            ] as $field) {
                unset($metadata[$field]);
            }
            $metadata['id'] = $id;
            $metadata['config_id'] = $configId;
            $metadata['hotel_id'] = (string)$systemHotelId;
            $metadata['system_hotel_id'] = $systemHotelId;
            $metadata['config_status'] = 'active';
            $metadata['deleted_at'] = '';

            if (!$isUpdate && !$this->otaSecretPayloadHasNonEmptyScalar($secretPayload)) {
                throw new \InvalidArgumentException('Meituan credential is required.');
            }

            $credentialMetadata = $this->storeOtaConfigCredential(
                $systemHotelId,
                'meituan',
                $configId,
                $secretPayload,
                $actorId,
                $existing
            );
            $metadata = array_merge($metadata, $credentialMetadata);
            $metadata['verification_status'] = 'saved_pending_verification';
            $metadata['verification_status_label'] = '已保存，待授权验证';
            $metadata['configuration_saved'] = true;
            $metadata['configuration_verified'] = false;
            $metadata['verified_at'] = '';
            $list[$id] = $metadata;
            $jsonValue = json_encode(
                $list,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $now = date('Y-m-d H:i:s');

            if ($row) {
                Db::name('system_configs')->where('config_key', $key)->update([
                    'config_value' => $jsonValue,
                    'update_time' => $now,
                ]);
            } else {
                Db::name('system_configs')->insert([
                    'config_key' => $key,
                    'config_value' => $jsonValue,
                    'description' => 'Meituan configuration list',
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }

            return $metadata;
        });

        SystemConfig::clearProtectedOtaCaches();
        return $saved;
    }

    /**
     * Soft-delete one Ctrip configuration while retaining non-secret history.
     *
     * @return array<string, mixed>
     */
    private function deleteCtripConfigMetadata(string $configId, int $systemHotelId): array
    {
        $configId = trim($configId);
        $this->validateOtaCredentialLocator('ctrip', $configId);
        $systemHotelId = $this->strictPositiveOtaConfigHotelId($systemHotelId);
        $this->otaCredentialTenantIdForHotel($systemHotelId);

        $deleted = Db::transaction(function () use ($configId, $systemHotelId): array {
            $key = 'ctrip_config_list';
            $row = Db::name('system_configs')->where('config_key', $key)->lock(true)->find();
            if (!$row) {
                throw new RuntimeException('Ctrip config list disappeared during delete.');
            }
            $list = json_decode((string)($row['config_value'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($list) || !isset($list[$configId]) || !is_array($list[$configId])) {
                throw new RuntimeException('Ctrip config disappeared during delete.');
            }
            $config = $list[$configId];
            $storedConfigId = trim((string)($config['config_id'] ?? $config['id'] ?? $configId));
            if ($this->otaConfigHasHotelBindingConflict($config)
                || $this->otaConfigBoundSystemHotelId($config) !== $systemHotelId
                || $storedConfigId === ''
                || !hash_equals($configId, $storedConfigId)) {
                throw new RuntimeException('Ctrip config hotel binding changed during delete.');
            }

            $this->revokeOtaConfigCredential($systemHotelId, 'ctrip', $configId);
            $now = date('Y-m-d H:i:s');
            $config['config_status'] = 'deleted';
            $config['credential_status'] = 'revoked';
            $config['has_cookies'] = false;
            $config['deleted_at'] = $now;
            $config['update_time'] = $now;
            $list[$configId] = $this->sanitizeSecretConfig($config);
            Db::name('system_configs')->where('config_key', $key)->update([
                'config_value' => json_encode(
                    $list,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'update_time' => $now,
            ]);
            return $list[$configId];
        });

        SystemConfig::clearProtectedOtaCaches();
        return $deleted;
    }

    /**
     * Delete one Meituan configuration and its credential in the same database transaction.
     *
     * @return array<string, mixed>
     */
    private function deleteMeituanConfigMetadata(
        string $configId,
        int $systemHotelId,
        ?string $expectedScope = null
    ): array
    {
        $configId = trim($configId);
        $this->validateOtaCredentialLocator('meituan', $configId);
        $systemHotelId = $this->strictPositiveOtaConfigHotelId($systemHotelId);
        $this->otaCredentialTenantIdForHotel($systemHotelId);
        $expectedScope = $expectedScope !== null ? trim($expectedScope) : null;

        $deleted = Db::transaction(function () use ($configId, $systemHotelId, $expectedScope): array {
            $key = 'meituan_config_list';
            $row = Db::name('system_configs')->where('config_key', $key)->lock(true)->find();
            if (!$row) {
                throw new RuntimeException('Meituan config list disappeared during delete.');
            }
            $list = json_decode((string)($row['config_value'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($list) || !isset($list[$configId]) || !is_array($list[$configId])) {
                throw new RuntimeException('Meituan config disappeared during delete.');
            }

            $config = $list[$configId];
            if ($this->isMeituanCommentConfigMetadata($config)) {
                throw new RuntimeException('Meituan review configuration cannot be deleted by normal CRUD.');
            }
            $lockedScopeValue = $config['scope'] ?? '';
            if (!is_scalar($lockedScopeValue) && $lockedScopeValue !== null) {
                throw new RuntimeException('Meituan config scope changed during delete.');
            }
            $lockedScope = trim((string)$lockedScopeValue);
            if ($expectedScope !== null && !hash_equals($expectedScope, $lockedScope)) {
                throw new RuntimeException('Meituan config scope changed during delete.');
            }
            $storedId = trim((string)($config['id'] ?? $configId));
            $storedConfigId = trim((string)($config['config_id'] ?? $storedId));
            if ($storedId === ''
                || $storedConfigId === ''
                || !hash_equals($configId, $storedId)
                || !hash_equals($configId, $storedConfigId)
                || $this->strictOtaConfigBoundHotelId($config, 'Meituan') !== $systemHotelId) {
                throw new RuntimeException('Meituan config scope changed during delete.');
            }

            $this->revokeOtaConfigCredential($systemHotelId, 'meituan', $configId);
            $now = date('Y-m-d H:i:s');
            $config['config_status'] = 'deleted';
            $config['credential_status'] = 'revoked';
            $config['has_cookies'] = false;
            $config['deleted_at'] = $now;
            $config['update_time'] = $now;
            $list[$configId] = $this->sanitizeSecretConfig($config);
            $jsonValue = json_encode(
                $list,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            Db::name('system_configs')->where('config_key', $key)->update([
                'config_value' => $jsonValue,
                'update_time' => $now,
            ]);

            return $list[$configId];
        });

        SystemConfig::clearProtectedOtaCaches();
        return $deleted;
    }

    private function strictOtaConfigBoundHotelId(array $config, string $platform): int
    {
        $hotelIds = [];
        foreach (['system_hotel_id', 'hotel_id'] as $field) {
            if (!array_key_exists($field, $config) || $config[$field] === null || $config[$field] === '') {
                continue;
            }
            $hotelIds[] = $this->strictPositiveOtaConfigHotelId($config[$field]);
        }
        $hotelIds = array_values(array_unique($hotelIds));
        if (count($hotelIds) !== 1) {
            throw new RuntimeException("{$platform} config hotel binding is invalid.");
        }

        return $hotelIds[0];
    }

    /**
     * @param array<string|int, mixed> $payload
     * @return array<string|int, mixed>
     */
    private function sanitizeOtaVaultSecretPayload(array $payload): array
    {
        $sanitized = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', trim($key)));
                if (in_array($normalized, ['keyid', 'payloadversion', 'encryptedpayload', 'ciphertext'], true)) {
                    continue;
                }
            }
            $sanitized[$key] = is_array($value) ? $this->sanitizeOtaVaultSecretPayload($value) : $value;
        }

        return $sanitized;
    }

    private function sanitizeSecretConfig(array $item): array
    {
        $isMeituanConfig = array_key_exists('partner_id', $item)
            || array_key_exists('partnerId', $item)
            || array_key_exists('poi_id', $item)
            || array_key_exists('poiId', $item)
            || array_key_exists('hotel_room_count', $item)
            || array_key_exists('competitor_room_count', $item);
        $hasOpaqueCredentialMetadata = isset($item['credential_ref'])
            && in_array((string)($item['credential_status'] ?? ''), ['ready', 'revoked'], true);
        if ($isMeituanConfig && !$hasOpaqueCredentialMetadata) {
            $credentialStatus = $this->meituanAutoFetchConfigStatus($item);
            $item['credential_requirement'] = $credentialStatus;
            $item['credential_status'] = $credentialStatus['credential_status'];
            $item['credential_status_label'] = $credentialStatus['credential_status_label'];
            $item['credential_level'] = $credentialStatus['credential_level'];
            $item['credential_level_label'] = $credentialStatus['credential_level_label'];
            $item['missing_fields'] = $credentialStatus['missing_fields'];
            $item['missing_text'] = $credentialStatus['missing_text'];
        }

        [$metadata, $secretPayload] = $this->splitOtaConfigSecrets($item);
        if ($this->otaSecretPayloadContainsCookie($secretPayload)
            && !array_key_exists('has_cookies', $metadata)) {
            $metadata['has_cookies'] = $this->otaSecretPayloadHasNonEmptyCookie($secretPayload);
        }
        if ($this->otaSecretPayloadHasNonEmptyScalar($secretPayload)) {
            $metadata['secret_mask'] = '********';
        }

        return $metadata;
    }

    /**
     * Keep normal runtime and shared caches metadata-only. A legacy row that
     * still contains any secret-bearing field is visible as migration debt but
     * can never be treated as an executable credential.
     *
     * @param array<mixed> $list
     * @return array<mixed>
     */
    private function sanitizeStoredOtaConfigListForRuntime(array $list): array
    {
        $safeList = [];
        foreach ($list as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            [, $legacySecretPayload] = $this->splitOtaConfigSecrets($item);
            $metadata = $this->sanitizeSecretConfig($item);
            if ($legacySecretPayload !== []) {
                $metadata['migration_required'] = true;
                $metadata['migration_reason'] = 'legacy_secret_fields_present';
                $metadata['credential_status'] = 'migration_required';
                $metadata['credential_status_label'] = '待迁移';
                $metadata['credential_level'] = 'blocked';
                $metadata['credential_level_label'] = '阻塞';
                $metadata['has_cookies'] = false;
            }

            $platform = array_key_exists('partner_id', $metadata)
                || array_key_exists('partnerId', $metadata)
                || array_key_exists('poi_id', $metadata)
                || array_key_exists('poiId', $metadata)
                ? 'meituan'
                : 'ctrip';
            $metadata = array_merge(
                $metadata,
                $this->otaConfigVerificationService()->statusForConfig($metadata, $platform)
            );

            $safeList[$index] = $metadata;
        }

        return $safeList;
    }

    /**
     * @return array{0: array<mixed>, 1: array<mixed>}
     */
    private function splitOtaConfigSecrets(array $config): array
    {
        $metadata = [];
        $secretPayload = [];

        foreach ($config as $key => $value) {
            if (is_string($key) && in_array(strtolower(trim($key)), ['key_id', 'payload_version'], true)) {
                continue;
            }
            if (is_string($key) && $this->isOtaSecretConfigKey($key)) {
                $secretPayload[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                if ($value === []) {
                    $metadata[$key] = [];
                    continue;
                }
                [$nestedMetadata, $nestedSecrets] = $this->splitOtaConfigSecrets($value);
                if ($nestedMetadata !== []) {
                    $metadata[$key] = $nestedMetadata;
                }
                if ($nestedSecrets !== []) {
                    $secretPayload[$key] = $nestedSecrets;
                }
                continue;
            }

            if (is_string($value) && $this->otaConfigStringContainsCredentialMaterial($value)) {
                $secretPayload[$key] = $value;
                continue;
            }

            $metadata[$key] = $value;
        }

        return [$metadata, $secretPayload];
    }

    private function otaConfigStringContainsCredentialMaterial(string $value): bool
    {
        return preg_match('/["\']?(?:cookie|set-cookie|authorization|proxy-authorization|x-api-key|api-key|auth_data|token|access_token|refresh_token|spidertoken|spiderkey|mtgsig|usertoken|usersign|password)["\']?\s*[:=]/i', $value) === 1
            || preg_match('/\bbearer\s+[A-Za-z0-9._~+\/=:-]{8,}/i', $value) === 1;
    }

    private function isOtaSecretConfigKey(string $key): bool
    {
        $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', trim($key)));
        $normalized = trim($normalized, '_');
        $compact = str_replace('_', '', $normalized);

        return in_array($normalized, [
            'cookies',
            'cookie',
            'auth_data',
            'authorization',
            'authorization_header',
            'token',
            'spiderkey',
            'spider_key',
            'spidertoken',
            'mtgsig',
            'mtsi_eb_u',
            'usertoken',
            'usersign',
            'password',
            'secret',
            'api_key',
            'secret_json',
            'auth_token',
            'headers',
            'headers_json',
            'set_cookie',
            'access_token',
            'refresh_token',
            'encrypted_payload',
            'ciphertext',
        ], true) || in_array($compact, [
            'authdata',
            'apikey',
            'spiderkey',
            'spidertoken',
            'mtgsig',
            'mtsiebu',
            'secretjson',
            'authtoken',
            'authorizationheader',
            'headersjson',
            'setcookie',
            'accesstoken',
            'refreshtoken',
            'encryptedpayload',
        ], true);
    }

    private function otaSecretPayloadContainsCookie(array $secretPayload): bool
    {
        foreach ($secretPayload as $key => $value) {
            if (is_string($key) && in_array(strtolower(str_replace(['-', '_'], '', $key)), ['cookie', 'cookies', 'setcookie'], true)) {
                return true;
            }
            if (is_array($value) && $this->otaSecretPayloadContainsCookie($value)) {
                return true;
            }
        }

        return false;
    }

    private function otaSecretPayloadHasNonEmptyCookie(array $secretPayload): bool
    {
        foreach ($secretPayload as $key => $value) {
            if (is_string($key) && in_array(strtolower(str_replace(['-', '_'], '', $key)), ['cookie', 'cookies', 'setcookie'], true)) {
                if ($this->otaSecretValueHasNonEmptyScalar($value)) {
                    return true;
                }
                continue;
            }
            if (is_array($value) && $this->otaSecretPayloadHasNonEmptyCookie($value)) {
                return true;
            }
        }

        return false;
    }

    private function otaSecretPayloadHasNonEmptyScalar(array $secretPayload): bool
    {
        return $this->otaSecretValueHasNonEmptyScalar($secretPayload);
    }

    private function otaSecretValueHasNonEmptyScalar($value): bool
    {
        if (is_array($value)) {
            foreach ($value as $nestedValue) {
                if ($this->otaSecretValueHasNonEmptyScalar($nestedValue)) {
                    return true;
                }
            }

            return false;
        }

        return is_scalar($value) && trim((string)$value) !== '';
    }

    private function getStoredCtripConfigList(): array
    {
        try {
            $raw = Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value');
        } catch (\Throwable) {
            throw new RuntimeException('Stored ctrip config metadata is unavailable.');
        }
        $list = $this->decodeStoredOtaConfigMetadata($raw, 'ctrip');
        $list = $this->normalizeStoredOtaConfigList('system_configs', 'ctrip_config_list', $list, 'ctrip');
        return array_values($this->sanitizeStoredOtaConfigListForRuntime($list));
    }

    private function getStoredMeituanConfigList(): array
    {
        try {
            $raw = Db::name('system_configs')->where('config_key', 'meituan_config_list')->value('config_value');
        } catch (\Throwable) {
            throw new RuntimeException('Stored meituan config metadata is unavailable.');
        }
        $list = $this->decodeStoredOtaConfigMetadata($raw, 'meituan');
        $list = $this->normalizeStoredOtaConfigList('system_configs', 'meituan_config_list', $list, 'meituan');
        $list = array_filter($list, fn($item): bool => is_array($item)
            && !$this->isMeituanCommentConfigMetadata($item));
        return array_values($this->sanitizeStoredOtaConfigListForRuntime($list));
    }

    private function decodeStoredOtaConfigMetadata(mixed $raw, string $platform): array
    {
        if ($raw === null) {
            return [];
        }
        try {
            $list = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException("Stored {$platform} config metadata is invalid.");
        }
        if (!is_array($list)) {
            throw new RuntimeException("Stored {$platform} config metadata is invalid.");
        }
        return $list;
    }

    private function filterOtaConfigListForCurrentUser(array $list): array
    {
        return $this->filterOtaConfigListForUser($list, $this->currentUser);
    }

    private function filterOtaConfigListForUser(array $list, $user): array
    {
        if (!$user || !isset($user->id) || !$user->id) {
            return [];
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return array_values($list);
        }

        $permittedHotelIdSet = $this->getPermittedHotelIdSetForUser($user);
        $visibleList = [];

        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($this->isOtaConfigVisibleToUser($item, $user, $permittedHotelIdSet)) {
                $visibleList[] = $item;
            }
        }

        return $visibleList;
    }

    private function isOtaConfigVisibleToCurrentUser(array $item, ?array $permittedHotelIdSet = null): bool
    {
        return $this->isOtaConfigVisibleToUser($item, $this->currentUser, $permittedHotelIdSet);
    }

    private function currentUserHasOtaConfigMaintenanceCapability(): bool
    {
        $user = $this->currentUser ?? null;
        if (!$user || !isset($user->id) || !$user->id) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        $canManageOwnHotels = method_exists($user, 'canManageOwnHotels') && $user->canManageOwnHotels();
        $canFetchOnlineData = method_exists($user, 'hasPermission') && $user->hasPermission('can_fetch_online_data');

        return $canManageOwnHotels || $canFetchOnlineData;
    }

    private function currentUserCanMaintainOtaConfig(?int $hotelId = null): bool
    {
        $user = $this->currentUser ?? null;
        if (!$user || !isset($user->id) || !$user->id) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        $canManageOwnHotels = method_exists($user, 'canManageOwnHotels') && $user->canManageOwnHotels();
        $canFetchOnlineData = method_exists($user, 'hasPermission') && $user->hasPermission('can_fetch_online_data');
        if (!$canManageOwnHotels && !$canFetchOnlineData) {
            return false;
        }

        if ($hotelId === null || $hotelId <= 0) {
            return false;
        }

        $permittedHotelIdSet = $this->getPermittedHotelIdSetForUser($user);
        return isset($permittedHotelIdSet[(string)$hotelId]);
    }

    private function isOtaConfigOwnedByCurrentUser(array $item): bool
    {
        $user = $this->currentUser ?? null;
        if (!$user || !isset($user->id) || !$user->id) {
            return false;
        }

        $itemUserId = $item['user_id'] ?? null;
        return $itemUserId !== null && $itemUserId !== '' && (string)$itemUserId === (string)$user->id;
    }

    private function positiveOtaConfigHotelId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $hotelId = (int)$value;
        return $hotelId > 0 ? $hotelId : null;
    }

    private function otaConfigBoundSystemHotelId(array $item): ?int
    {
        foreach (['system_hotel_id', 'hotel_id'] as $field) {
            $hotelId = $this->positiveOtaConfigHotelId($item[$field] ?? null);
            if ($hotelId !== null) {
                return $hotelId;
            }
        }

        return null;
    }

    /**
     * Attach hotel-scoped persisted OTA evidence to already-filtered config rows.
     * This deliberately runs after the current-user visibility filter so the
     * evidence query can never widen the caller's hotel scope.
     *
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    private function appendOtaConfigCollectionEvidence(array $list, string $platform): array
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return $list;
        }

        $hotelIds = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hotelId = $this->otaConfigBoundSystemHotelId($item);
            if ($hotelId !== null) {
                $hotelIds[$hotelId] = $hotelId;
            }
        }

        $evidenceByHotelId = [];
        $queryFailed = false;
        if ($hotelIds !== []) {
            try {
                $rows = Db::name('online_daily_data')
                    ->field('system_hotel_id, MAX(COALESCE(update_time, create_time)) AS latest_platform_success_at, MAX(data_date) AS latest_platform_data_date, COUNT(*) AS stored_platform_row_count')
                    ->where('source', $platform)
                    ->whereIn('system_hotel_id', array_values($hotelIds))
                    ->group('system_hotel_id')
                    ->select()
                    ->toArray();
                foreach ($rows as $row) {
                    $hotelId = $this->positiveOtaConfigHotelId($row['system_hotel_id'] ?? null);
                    if ($hotelId !== null) {
                        $evidenceByHotelId[$hotelId] = $row;
                    }
                }
            } catch (\Throwable $e) {
                $queryFailed = true;
                Log::warning('读取 OTA 配置入库证据失败', [
                    'platform' => $platform,
                    'hotel_ids' => array_values($hotelIds),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($list as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $item['collection_evidence_policy'] = 'hotel_platform_persisted_rows_only';
            $item['latest_platform_success_at'] = '';
            $item['latest_platform_data_date'] = '';
            $item['stored_platform_row_count'] = 0;

            $hotelId = $this->otaConfigBoundSystemHotelId($item);
            if ($hotelId === null) {
                $item['collection_evidence_status'] = 'unbound';
                continue;
            }
            if ($queryFailed) {
                $item['collection_evidence_status'] = 'unverified';
                continue;
            }

            $evidence = $evidenceByHotelId[$hotelId] ?? null;
            if (!is_array($evidence) || (int)($evidence['stored_platform_row_count'] ?? 0) <= 0) {
                $item['collection_evidence_status'] = 'no_successful_storage';
                continue;
            }

            $latestSuccessAt = trim((string)($evidence['latest_platform_success_at'] ?? ''));
            $item['latest_platform_success_at'] = $latestSuccessAt;
            $item['latest_platform_data_date'] = trim((string)($evidence['latest_platform_data_date'] ?? ''));
            $item['stored_platform_row_count'] = max(0, (int)($evidence['stored_platform_row_count'] ?? 0));

            $configUpdatedAt = trim((string)($item['update_time'] ?? $item['updated_at'] ?? $item['created_at'] ?? ''));
            $latestSuccessTimestamp = $latestSuccessAt !== '' ? strtotime($latestSuccessAt) : false;
            $configUpdatedTimestamp = $configUpdatedAt !== '' ? strtotime($configUpdatedAt) : false;
            $item['collection_evidence_status'] = $latestSuccessTimestamp !== false
                && ($configUpdatedTimestamp === false || $latestSuccessTimestamp >= $configUpdatedTimestamp)
                ? 'success_after_current_config'
                : 'historical_success_before_config_update';
        }
        unset($item);

        return $list;
    }

    private function otaConfigHasHotelBindingConflict(array $item): bool
    {
        $systemHotelId = $this->positiveOtaConfigHotelId($item['system_hotel_id'] ?? null);
        $hotelId = $this->positiveOtaConfigHotelId($item['hotel_id'] ?? null);
        return $systemHotelId !== null && $hotelId !== null && $systemHotelId !== $hotelId;
    }

    private function currentUserCanMaintainOtaConfigItem(array $item, ?int $targetHotelId = null): bool
    {
        if ($this->otaConfigHasHotelBindingConflict($item)) {
            return false;
        }
        if (!$this->currentUserHasOtaConfigMaintenanceCapability()) {
            return false;
        }

        if ($this->currentUser && method_exists($this->currentUser, 'isSuperAdmin') && $this->currentUser->isSuperAdmin()) {
            return true;
        }

        $existingHotelId = $this->otaConfigBoundSystemHotelId($item);
        if ($existingHotelId !== null) {
            if ($targetHotelId !== null && $targetHotelId !== $existingHotelId) {
                return false;
            }

            return $this->currentUserCanMaintainOtaConfig($existingHotelId);
        }

        if (!$this->isOtaConfigOwnedByCurrentUser($item)) {
            return false;
        }

        if ($targetHotelId !== null) {
            return $this->currentUserCanMaintainOtaConfig($targetHotelId);
        }

        return false;
    }

    private function checkOtaConfigMaintenancePermission(?int $hotelId = null): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }

        if (!$this->currentUserCanMaintainOtaConfig($hotelId)) {
            abort(403, '无权限维护该门店 OTA 配置');
        }
    }

    private function isOtaConfigVisibleToUser(array $item, $user, ?array $permittedHotelIdSet = null): bool
    {
        if ($this->otaConfigHasHotelBindingConflict($item)) {
            return false;
        }
        if (!$user || !isset($user->id) || !$user->id) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        $permittedHotelIdSet = $permittedHotelIdSet ?? $this->getPermittedHotelIdSetForUser($user);
        $systemHotelId = $this->otaConfigBoundSystemHotelId($item);
        if ($systemHotelId !== null && isset($permittedHotelIdSet[(string)$systemHotelId])) {
            return true;
        }

        return false;
    }

    private function getPermittedHotelIdSetForUser($user): array
    {
        if (!$user || !method_exists($user, 'getPermittedHotelIds')) {
            return [];
        }

        $hotelIds = array_map('strval', $user->getPermittedHotelIds());
        return array_fill_keys($hotelIds, true);
    }

    private function resolveCtripFetchConfigForHotel(int $hotelId): array
    {
        $matches = [];
        foreach ($this->getStoredCtripConfigList() as $config) {
            if ($this->otaConfigHasHotelBindingConflict($config)) {
                if ((int)($config['hotel_id'] ?? 0) === $hotelId || (int)($config['system_hotel_id'] ?? 0) === $hotelId) {
                    return [];
                }
                continue;
            }
            $configHotelId = (string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? '');
            if ($configHotelId !== '' && (string)$hotelId === $configHotelId) {
                $matches[] = $config;
            }
        }

        return $this->selectLatestSuccessfulCtripConfig($matches);
    }

    private function resolveMeituanFetchConfigForHotel(int $hotelId): array
    {
        $matches = [];
        foreach ($this->getStoredMeituanConfigList() as $config) {
            if ($this->otaConfigHasHotelBindingConflict($config)) {
                if ((int)($config['hotel_id'] ?? 0) === $hotelId || (int)($config['system_hotel_id'] ?? 0) === $hotelId) {
                    return [];
                }
                continue;
            }
            $configHotelId = (string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? '');
            if ($configHotelId !== '' && (string)$hotelId === $configHotelId) {
                $matches[] = $config;
            }
        }

        return $this->selectLatestSuccessfulMeituanConfig($matches);
    }

    private function resolveCtripFetchConfigForHotelLight(int $hotelId): array
    {
        $matches = [];
        foreach ($this->getStoredCtripConfigListForLightCache() as $config) {
            if ($this->otaConfigHasHotelBindingConflict($config)) {
                if ((int)($config['hotel_id'] ?? 0) === $hotelId || (int)($config['system_hotel_id'] ?? 0) === $hotelId) {
                    return [];
                }
                continue;
            }
            $configHotelId = (string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? '');
            if ($configHotelId !== '' && (string)$hotelId === $configHotelId) {
                $matches[] = $this->sanitizeSecretConfig($config);
            }
        }

        return $this->selectLatestSuccessfulCtripConfig($matches);
    }

    private function resolveMeituanFetchConfigForHotelLight(int $hotelId): array
    {
        $matches = [];
        foreach ($this->getStoredMeituanConfigListForLightCache() as $config) {
            if ($this->otaConfigHasHotelBindingConflict($config)) {
                if ((int)($config['hotel_id'] ?? 0) === $hotelId || (int)($config['system_hotel_id'] ?? 0) === $hotelId) {
                    return [];
                }
                continue;
            }
            $configHotelId = (string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? '');
            if ($configHotelId !== '' && (string)$hotelId === $configHotelId) {
                $matches[] = $this->sanitizeSecretConfig($config);
            }
        }

        return $this->selectLatestSuccessfulMeituanConfig($matches);
    }

    private function selectLatestSuccessfulMeituanConfig(array $matches): array
    {
        $successful = array_values(array_filter($matches, function ($config): bool {
            if (!is_array($config)) {
                return false;
            }
            $configId = trim((string)($config['config_id'] ?? $config['id'] ?? ''));
            return $this->isCurrentOtaConfig($config)
                && preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $configId) === 1
                && (string)($config['credential_status'] ?? '') === 'ready'
                && ($config['has_cookies'] ?? false) === true;
        }));
        if ($successful === []) {
            return [];
        }

        usort($successful, static function (array $left, array $right): int {
            $leftTime = trim((string)($left['update_time'] ?? $left['updated_at'] ?? $left['created_at'] ?? $left['create_time'] ?? ''));
            $rightTime = trim((string)($right['update_time'] ?? $right['updated_at'] ?? $right['created_at'] ?? $right['create_time'] ?? ''));
            $timeComparison = strcmp($rightTime, $leftTime);
            if ($timeComparison !== 0) {
                return $timeComparison;
            }
            $leftId = (string)($left['config_id'] ?? $left['id'] ?? '');
            $rightId = (string)($right['config_id'] ?? $right['id'] ?? '');
            return strcmp($rightId, $leftId);
        });

        return $successful[0];
    }

    private function selectLatestSuccessfulCtripConfig(array $matches): array
    {
        $successful = array_values(array_filter($matches, function ($config): bool {
            if (!is_array($config)) {
                return false;
            }
            $configId = trim((string)($config['config_id'] ?? $config['id'] ?? ''));
            return $this->isCurrentOtaConfig($config)
                && preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $configId) === 1
                && (string)($config['credential_status'] ?? '') === 'ready'
                && ($config['has_cookies'] ?? false) === true;
        }));
        if ($successful === []) {
            return [];
        }

        $this->sortOtaConfigsNewestFirst($successful);
        return $successful[0];
    }

    private function selectLatestSuccessfulCtripConfigForHotel(array $list, int $hotelId): array
    {
        $matches = [];
        foreach ($list as $config) {
            if (!is_array($config) || $this->otaConfigHasHotelBindingConflict($config)) {
                continue;
            }
            if ($this->otaConfigBoundSystemHotelId($config) === $hotelId) {
                $matches[] = $config;
            }
        }

        return $this->selectLatestSuccessfulCtripConfig($matches);
    }

    private function collapseCtripConfigListByHotel(array $list): array
    {
        $groups = [];
        foreach ($list as $index => $config) {
            if (!is_array($config)) {
                continue;
            }
            $hotelId = $this->otaConfigHasHotelBindingConflict($config)
                ? null
                : $this->otaConfigBoundSystemHotelId($config);
            $configId = trim((string)($config['config_id'] ?? $config['id'] ?? $index));
            $groupKey = $hotelId === null ? 'unbound:' . $configId . ':' . $index : 'hotel:' . $hotelId;
            $groups[$groupKey][] = $config;
        }

        $collapsed = [];
        foreach ($groups as $configs) {
            $currentConfigs = array_values(array_filter(
                $configs,
                fn(array $config): bool => $this->isCurrentOtaConfig($config)
            ));
            if ($currentConfigs === []) {
                continue;
            }
            $primary = $this->selectLatestSuccessfulCtripConfig($currentConfigs);
            if ($primary === []) {
                $this->sortOtaConfigsNewestFirst($currentConfigs);
                $primary = $currentConfigs[0];
            }
            $primary['history_count'] = max(0, count($configs) - 1);
            $primary['active_config_count'] = count($currentConfigs);
            $primary['duplicate_current_count'] = max(0, count($currentConfigs) - 1);
            $primary['duplicate_status'] = $primary['duplicate_current_count'] > 0 ? 'warning' : 'ok';
            $collapsed[] = $primary;
        }

        $this->sortOtaConfigsNewestFirst($collapsed);
        return $collapsed;
    }

    private function collapseMeituanConfigListByHotel(array $list): array
    {
        $groups = [];
        foreach ($list as $index => $config) {
            if (!is_array($config)) {
                continue;
            }
            $hotelId = $this->otaConfigHasHotelBindingConflict($config)
                ? null
                : $this->otaConfigBoundSystemHotelId($config);
            $configId = trim((string)($config['config_id'] ?? $config['id'] ?? $index));
            $groupKey = $hotelId === null ? 'unbound:' . $configId . ':' . $index : 'hotel:' . $hotelId;
            $groups[$groupKey][] = $config;
        }

        $collapsed = [];
        foreach ($groups as $configs) {
            $currentConfigs = array_values(array_filter(
                $configs,
                fn(array $config): bool => $this->isCurrentOtaConfig($config)
            ));
            if ($currentConfigs === []) {
                continue;
            }
            $primary = $this->selectLatestSuccessfulMeituanConfig($currentConfigs);
            if ($primary === []) {
                $this->sortOtaConfigsNewestFirst($currentConfigs);
                $primary = $currentConfigs[0];
            }
            $primary['history_count'] = max(0, count($configs) - 1);
            $primary['active_config_count'] = count($currentConfigs);
            $primary['duplicate_current_count'] = max(0, count($currentConfigs) - 1);
            $primary['duplicate_status'] = $primary['duplicate_current_count'] > 0 ? 'warning' : 'ok';
            $collapsed[] = $primary;
        }

        $this->sortOtaConfigsNewestFirst($collapsed);
        return $collapsed;
    }

    private function isCurrentOtaConfig(array $config): bool
    {
        if (trim((string)($config['deleted_at'] ?? '')) !== '') {
            return false;
        }
        return !in_array(
            strtolower(trim((string)($config['config_status'] ?? 'active'))),
            ['deleted', 'history', 'superseded', 'archived'],
            true
        );
    }

    private function otaPlatformHotelIdFromConfig(string $platform, array $config): string
    {
        $keys = $platform === 'meituan'
            ? ['poi_id', 'poiId', 'store_id', 'storeId']
            : ['ctrip_hotel_id', 'ctripHotelId', 'ota_hotel_id', 'otaHotelId', 'platform_hotel_id', 'platformHotelId'];
        foreach ($keys as $key) {
            if (is_scalar($config[$key] ?? null) && trim((string)$config[$key]) !== '') {
                return trim((string)$config[$key]);
            }
        }
        return '';
    }

    private function assertUniqueOtaPlatformHotelBinding(
        array $list,
        string $platform,
        string $platformHotelId,
        int $systemHotelId,
        string $excludeConfigId
    ): void {
        if ($platformHotelId === '') {
            return;
        }
        foreach ($list as $storedKey => $candidate) {
            if (!is_array($candidate) || !$this->isCurrentOtaConfig($candidate)) {
                continue;
            }
            $candidateConfigId = trim((string)($candidate['config_id'] ?? $candidate['id'] ?? $storedKey));
            if ($candidateConfigId !== '' && hash_equals($excludeConfigId, $candidateConfigId)) {
                continue;
            }
            if (!hash_equals($platformHotelId, $this->otaPlatformHotelIdFromConfig($platform, $candidate))) {
                continue;
            }
            $candidateHotelId = $this->otaConfigHasHotelBindingConflict($candidate)
                ? null
                : $this->otaConfigBoundSystemHotelId($candidate);
            if ($candidateHotelId !== null && $candidateHotelId !== $systemHotelId) {
                throw new RuntimeException('OTA platform hotel ID is already bound to another hotel.');
            }
        }
    }

    private function appendOtaConfigHistoryVersion(array &$list, string $configId, array $existing): void
    {
        if ($existing === [] || !$this->isCurrentOtaConfig($existing)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $fingerprint = substr(hash('sha256', json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 8);
        $base = substr($configId, 0, 58) . '__history_' . date('YmdHis') . '_' . $fingerprint;
        $historyId = substr($base, 0, 100);
        for ($suffix = 2; isset($list[$historyId]); $suffix++) {
            $historyId = substr($base, 0, 96) . '_' . $suffix;
        }
        $history = $this->sanitizeSecretConfig($existing);
        $history['id'] = $historyId;
        $history['config_id'] = $historyId;
        $history['config_status'] = 'history';
        $history['credential_status'] = 'revoked';
        $history['has_cookies'] = false;
        $history['history_of_config_id'] = $configId;
        $history['superseded_at'] = $now;
        $history['update_time'] = $now;
        unset($history['credential_ref'], $history['secret_mask'], $history['history_count']);
        $list[$historyId] = $history;
    }

    private function retireOtherCurrentOtaConfigs(array &$list, int $systemHotelId, string $excludeConfigId): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($list as $storedKey => $candidate) {
            if (!is_array($candidate) || !$this->isCurrentOtaConfig($candidate)) {
                continue;
            }
            $candidateConfigId = trim((string)($candidate['config_id'] ?? $candidate['id'] ?? $storedKey));
            if ($candidateConfigId !== '' && hash_equals($excludeConfigId, $candidateConfigId)) {
                continue;
            }
            if ($this->otaConfigHasHotelBindingConflict($candidate)
                || $this->otaConfigBoundSystemHotelId($candidate) !== $systemHotelId) {
                continue;
            }
            $candidate['config_status'] = 'history';
            $candidate['credential_status'] = 'revoked';
            $candidate['has_cookies'] = false;
            $candidate['superseded_at'] = $now;
            $candidate['update_time'] = $now;
            $list[$storedKey] = $candidate;
        }
    }

    private function sortOtaConfigsNewestFirst(array &$configs): void
    {
        usort($configs, static function (array $left, array $right): int {
            $leftTime = trim((string)($left['update_time'] ?? $left['updated_at'] ?? $left['created_at'] ?? $left['create_time'] ?? ''));
            $rightTime = trim((string)($right['update_time'] ?? $right['updated_at'] ?? $right['created_at'] ?? $right['create_time'] ?? ''));
            $timeComparison = strcmp($rightTime, $leftTime);
            if ($timeComparison !== 0) {
                return $timeComparison;
            }
            $leftId = (string)($left['config_id'] ?? $left['id'] ?? '');
            $rightId = (string)($right['config_id'] ?? $right['id'] ?? '');
            return strcmp($rightId, $leftId);
        });
    }

    private function getStoredCtripConfigListForLightCache(): array
    {
        $cacheKey = $this->autoFetchLightConfigListCacheKey('ctrip');
        $cached = $this->readAutoFetchLightReadCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $raw = Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value');
        } catch (\Throwable) {
            throw new RuntimeException('Stored ctrip config metadata is unavailable.');
        }
        $list = $this->applyCtripAllCaptureCapabilityToList(
            $this->decodeStoredOtaConfigMetadata($raw, 'ctrip')
        );
        $list = array_values(array_filter($list, 'is_array'));
        $safeList = array_values($this->sanitizeStoredOtaConfigListForRuntime($list));
        return $this->writeAutoFetchLightReadCache($cacheKey, $safeList);
    }

    private function getStoredMeituanConfigListForLightCache(): array
    {
        $cacheKey = $this->autoFetchLightConfigListCacheKey('meituan');
        $cached = $this->readAutoFetchLightReadCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $raw = Db::name('system_configs')->where('config_key', 'meituan_config_list')->value('config_value');
        } catch (\Throwable) {
            throw new RuntimeException('Stored meituan config metadata is unavailable.');
        }
        $list = array_values(array_filter(
            $this->decodeStoredOtaConfigMetadata($raw, 'meituan'),
            fn($item): bool => is_array($item) && !$this->isMeituanCommentConfigMetadata($item)
        ));
        $safeList = array_values($this->sanitizeStoredOtaConfigListForRuntime($list));
        return $this->writeAutoFetchLightReadCache($cacheKey, $safeList);
    }

    private function isMeituanCommentConfigMetadata(array $config): bool
    {
        $totalBytes = 0;
        $totalItems = 0;
        foreach ([
            'scope',
            'privacy_boundary',
            'privacyBoundary',
            'capture_sections',
            'profile_sections',
            'captureSections',
            'profileSections',
        ] as $field) {
            if (array_key_exists($field, $config)
                && $this->meituanConfigValueContainsReviewScope(
                    $config[$field],
                    0,
                    $totalBytes,
                    $totalItems
                )) {
                return true;
            }
        }

        return false;
    }

    private function meituanConfigValueContainsReviewScope(
        mixed $value,
        int $depth,
        int &$totalBytes,
        int &$totalItems
    ): bool
    {
        if (is_array($value)) {
            if ($depth >= 4) {
                return true;
            }
            $expectedIndex = 0;
            foreach ($value as $index => $_) {
                if ($index !== $expectedIndex) {
                    return true;
                }
                $expectedIndex++;
            }
            $totalItems += count($value);
            if ($totalItems > 64) {
                return true;
            }
            foreach ($value as $nestedValue) {
                if ($this->meituanConfigValueContainsReviewScope(
                    $nestedValue,
                    $depth + 1,
                    $totalBytes,
                    $totalItems
                )) {
                    return true;
                }
            }

            return false;
        }
        if (!is_string($value)) {
            return true;
        }

        $length = strlen($value);
        $totalBytes += $length;
        if ($length > 1024 || $totalBytes > 4096) {
            return true;
        }

        $tokens = preg_split('/[\s,]+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens)) {
            return true;
        }

        return in_array('reviews', $tokens, true)
            || in_array('ota_channel_review_summary', $tokens, true);
    }

    private function ctripProfileStoreIdFromConfig(array $config, int $hotelId = 0): string
    {
        foreach (['profile_id', 'profileId'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        foreach ([(string)$hotelId, (string)($config['system_hotel_id'] ?? ''), (string)($config['hotel_id'] ?? '')] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && $this->ctripProfileDirExists($candidate)) {
                return $candidate;
            }
        }

        foreach (['ota_hotel_id', 'ctrip_hotel_id', 'ctripHotelId', 'hotel_code', 'hotelCode', 'hotel_id', 'system_hotel_id'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $hotelId > 0 ? (string)$hotelId : '';
    }

    private function ctripProfileExistsForConfig(array $config, int $hotelId = 0): bool
    {
        $profileId = $this->ctripProfileStoreIdFromConfig($config, $hotelId);
        if ($profileId === '') {
            return false;
        }

        return $this->ctripProfileDirExists($profileId);
    }

    private function ctripProfileDirExists(string $profileId): bool
    {
        $profileId = trim($profileId);
        if ($profileId === '') {
            return false;
        }

        $projectRoot = dirname(__DIR__, 3);
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . BrowserProfileCaptureRequestService::safeFilePart($profileId);
        return is_dir($profileDir);
    }

    private function meituanProfileStoreIdFromConfig(array $config): string
    {
        return trim((string)($config['store_id'] ?? $config['storeId'] ?? $config['poi_id'] ?? $config['poiId'] ?? ''));
    }

    private function meituanProfileExistsForConfig(array $config): bool
    {
        $storeId = $this->meituanProfileStoreIdFromConfig($config);
        if ($storeId === '') {
            return false;
        }

        $projectRoot = dirname(__DIR__, 3);
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meituan_profile_' . BrowserProfileCaptureRequestService::safeFilePart($storeId);
        return is_dir($profileDir);
    }

    private function ctripLatestFetchStatusKey(?int $hotelId): string
    {
        return $hotelId ? "online_data_ctrip_latest_fetch_{$hotelId}" : 'online_data_ctrip_latest_fetch';
    }

    private function updateCtripLatestFetchStatus(?int $hotelId, string $fetchedAt, string $dataDate, int $savedCount): void
    {
        cache($this->ctripLatestFetchStatusKey($hotelId), [
            'fetched_at' => $fetchedAt,
            'data_date' => $dataDate,
            'saved_count' => $savedCount,
        ], 86400 * 30);
    }

    private function getCtripLatestFetchStatus(string $hotelId): array
    {
        $statusKeyHotelId = is_numeric($hotelId) && (int)$hotelId > 0 ? (int)$hotelId : null;
        $status = cache($this->ctripLatestFetchStatusKey($statusKeyHotelId)) ?: [];
        return is_array($status) ? $status : [];
    }


    private function getHotelsForOtaConfigMatching(): array
    {
        try {
            $rows = Db::name('hotels')
                ->field('id,name,code,status')
                ->order('status', 'desc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $rows = array_values(array_filter($rows, static function ($row): bool {
            return is_array($row) && trim((string)($row['name'] ?? '')) !== '';
        }));

        usort($rows, static function (array $a, array $b): int {
            $statusCompare = (int)($b['status'] ?? 0) <=> (int)($a['status'] ?? 0);
            if ($statusCompare !== 0) {
                return $statusCompare;
            }
            return mb_strlen((string)($b['name'] ?? '')) <=> mb_strlen((string)($a['name'] ?? ''));
        });

        return $rows;
    }

    private function normalizeOtaConfigMatchText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/(携程|美团|ebooking|e-booking|ebk|数据源|配置|主账号|账号|cookie|cookies)/iu', '', $value) ?? $value;
        $value = preg_replace('/[^\p{Han}a-z0-9]+/iu', '', $value) ?? $value;

        return mb_strtolower($value, 'UTF-8');
    }

    private function findOtaConfigHotelMatch(array $config, array $hotels): ?array
    {
        $currentHotelId = trim((string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? ''));
        if ($currentHotelId !== '') {
            foreach ($hotels as $hotel) {
                if ((string)($hotel['id'] ?? '') === $currentHotelId) {
                    return $hotel;
                }
            }
        }

        $sourceParts = [
            $config['hotel_name'] ?? '',
            $config['name'] ?? '',
            $config['config_name'] ?? '',
            $config['remark'] ?? '',
        ];
        $source = trim(implode(' ', array_filter(array_map(static fn($part): string => trim((string)$part), $sourceParts))));
        if ($source === '') {
            return null;
        }

        foreach ($hotels as $hotel) {
            $hotelName = trim((string)($hotel['name'] ?? ''));
            if ($hotelName !== '' && mb_strpos($source, $hotelName, 0, 'UTF-8') !== false) {
                return $hotel;
            }
        }

        $normalizedSource = $this->normalizeOtaConfigMatchText($source);
        if ($normalizedSource === '') {
            return null;
        }

        foreach ($hotels as $hotel) {
            $hotelName = $this->normalizeOtaConfigMatchText((string)($hotel['name'] ?? ''));
            $hotelCode = $this->normalizeOtaConfigMatchText((string)($hotel['code'] ?? ''));
            if ($hotelName !== '' && mb_strpos($normalizedSource, $hotelName, 0, 'UTF-8') !== false) {
                return $hotel;
            }
            if ($hotelCode !== '' && mb_strpos($normalizedSource, $hotelCode, 0, 'UTF-8') !== false) {
                return $hotel;
            }
        }

        return null;
    }

    private function normalizeOtaConfigHotelBinding(array $config, string $platform, ?array $hotels = null): array
    {
        if ($this->otaConfigHasHotelBindingConflict($config)) {
            $config['migration_required'] = true;
            return $config;
        }

        $explicitHotelId = $this->positiveOtaConfigHotelId($config['system_hotel_id'] ?? null);
        if ($explicitHotelId === null) {
            $explicitHotelId = $this->positiveOtaConfigHotelId($config['hotel_id'] ?? null);
        }
        if ($explicitHotelId !== null) {
            foreach ($hotels ?? [] as $hotel) {
                if ((int)($hotel['id'] ?? 0) === $explicitHotelId) {
                    $config['system_hotel_id'] = (string)$explicitHotelId;
                    $config['hotel_id'] = (string)$explicitHotelId;
                    $config['hotel_name'] = (string)($hotel['name'] ?? $config['hotel_name'] ?? '');
                    $config['platform'] = $config['platform'] ?? $platform;
                    return $config;
                }
            }
            $config['migration_required'] = true;
            return $config;
        }

        $config['migration_required'] = true;
        return $config;
    }

    private function normalizeStoredOtaConfigList(string $table, string $key, array $list, string $platform): array
    {
        if ($platform === 'ctrip') {
            $list = $this->applyCtripAllCaptureCapabilityToList($list);
        }
        if (empty($list)) {
            return $list;
        }

        $hotels = $this->getHotelsForOtaConfigMatching();
        if (empty($hotels)) {
            return $list;
        }

        $normalizedList = [];

        foreach ($list as $index => $item) {
            if (!is_array($item)) {
                $normalizedList[$index] = $item;
                continue;
            }

            $normalized = $this->normalizeOtaConfigHotelBinding($item, $platform, $hotels);
            $normalizedList[$index] = $normalized;
        }

        return $normalizedList;
    }

    private function applyCtripAllCaptureCapability(array $config): array
    {
        $config['capture_sections'] = 'all';
        $config['profile_sections'] = 'all';
        return $config;
    }

    private function applyCtripAllCaptureCapabilityToList(array $list): array
    {
        foreach ($list as $key => $config) {
            if (is_array($config)) {
                $list[$key] = $this->applyCtripAllCaptureCapability($config);
            }
        }
        return $list;
    }

    /**
     * Public Ctrip hotel pages are a human-verification aid only. They never
     * overwrite the selected system hotel or the stored platform binding.
     *
     * @param array<int, mixed> $hotelIds
     * @return array<int, array{hotel_id:string,url:string}>
     */
    private function buildCtripPublicHotelVerificationLinks(array $hotelIds): array
    {
        $links = [];
        foreach ($hotelIds as $hotelId) {
            if (is_array($hotelId) || is_object($hotelId)) {
                continue;
            }
            $value = trim((string)$hotelId);
            if (preg_match('/^\d+$/D', $value) !== 1) {
                continue;
            }
            $links[$value] = [
                'hotel_id' => $value,
                'url' => 'https://hotels.ctrip.com/hotels/' . $value . '.html',
            ];
        }
        return array_values($links);
    }

}
