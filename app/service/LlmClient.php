<?php
declare(strict_types=1);

namespace app\service;

use app\model\AiModelCallLog;
use app\model\AiModelConfig;
use RuntimeException;
use think\facade\Cache;
use Throwable;

class LlmClient
{
    private const DEFAULT_LOW_CONFIDENCE_THRESHOLD = 0.70;
    private const DEFAULT_MAX_RETRIES = 3;
    private const DEFAULT_RETRY_DELAYS_MS = [2000, 5000, 10000];
    private const DEFAULT_CIRCUIT_FAILURE_THRESHOLD = 3;
    private const DEFAULT_CIRCUIT_OPEN_SECONDS = 60;
    private const DEFAULT_IDEMPOTENCY_TTL_SECONDS = 86400;
    private const DEFAULT_RESPONSE_CACHE_TTL_SECONDS = 21600;
    private const MAX_TRANSPORT_TIMEOUT_SECONDS = 60;
    private const STATE_PREFIX = 'suxios_llm_client_v2:';

    /** @var array<string, array{value:mixed,expires_at:int}> */
    private static array $localState = [];

    public function chat(string $prompt, string $modelKey = 'deepseek_v4_default', array $meta = [], array $options = []): array
    {
        $startedAt = microtime(true);
        $modelKey = $this->normalizeModelKey($modelKey, $options);
        $governance = $this->buildGovernanceMeta($prompt, $modelKey, $meta, $options);
        $fingerprint = $this->idempotencyFingerprint($governance, $modelKey, $options);
        $idempotencyEnabled = $this->optionEnabled($options, 'idempotency_enabled', true);

        if ($idempotencyEnabled) {
            $replay = $this->idempotencyReplay((string)$governance['request_id'], $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
        }

        $execute = function () use ($prompt, $modelKey, $meta, $options, $governance, $fingerprint, $idempotencyEnabled, $startedAt): array {
            if ($idempotencyEnabled) {
                $replay = $this->idempotencyReplay((string)$governance['request_id'], $fingerprint);
                if ($replay !== null) {
                    return $replay;
                }
            }

            try {
                $result = $this->executeStableChat($prompt, $modelKey, $meta, $options, $governance, $startedAt);
            } catch (Throwable $e) {
                $message = 'LLM client internal failure: ' . $this->sanitize($e->getMessage(), 200);
                $result = $this->manualDegradationResult(
                    [],
                    $governance,
                    ['model_key' => $modelKey, 'source' => 'database'],
                    $prompt,
                    $message,
                    'client_internal_error',
                    [],
                    $startedAt
                );
            }

            $result = $this->markIdempotencyResult($result, false);
            if ($idempotencyEnabled) {
                $this->storeIdempotencyResult((string)$governance['request_id'], $fingerprint, $result, $options);
            }
            return $result;
        };

        if (!$idempotencyEnabled) {
            return $execute();
        }

        $result = $this->withIdempotencyLock((string)$governance['request_id'], $execute);
        if ($result !== null) {
            return $result;
        }

        return $this->markIdempotencyResult($this->manualDegradationResult(
            [],
            $governance,
            ['model_key' => $modelKey, 'source' => 'database'],
            $prompt,
            'An identical AI request is already in progress.',
            'idempotency_in_progress',
            [],
            $startedAt
        ), true);
    }

    private function executeStableChat(
        string $prompt,
        string $modelKey,
        array $meta,
        array $options,
        array $governance,
        float $startedAt
    ): array {
        $primaryConfig = $this->configByModelKey($modelKey);
        $configs = [];
        $attempts = [];
        $lastResult = [];

        if (($primaryConfig['ok'] ?? false) === true) {
            $configs[] = $primaryConfig;
        } else {
            $message = (string)($primaryConfig['message'] ?? 'Primary LLM configuration is unavailable.');
            $primaryConfig['data'] = $this->debug('config_error', $primaryConfig, 0, '', $prompt, '', $message, $meta);
            $lastResult = $this->finishWithGovernance(
                $primaryConfig,
                $governance,
                $primaryConfig,
                $prompt,
                '',
                'failed',
                'config_error',
                $message,
                0,
                0,
                $startedAt,
                false
            );
            $attempts[] = $this->providerAttemptSummary($primaryConfig, true, 'config_error', 0, 0, 'closed');
        }

        foreach ($this->providerFallbackConfigs($primaryConfig, $modelKey, $options) as $fallbackConfig) {
            if (($fallbackConfig['ok'] ?? false) !== true) {
                continue;
            }
            $identity = $this->configIdentity($fallbackConfig);
            $duplicate = false;
            foreach ($configs as $configured) {
                if (hash_equals($identity, $this->configIdentity($configured))) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $configs[] = $fallbackConfig;
            }
        }

        $primaryIdentity = ($primaryConfig['ok'] ?? false) === true ? $this->configIdentity($primaryConfig) : '';
        foreach ($configs as $config) {
            $isFallback = $primaryIdentity === '' || !hash_equals($primaryIdentity, $this->configIdentity($config));
            $circuit = $this->beforeCircuitRequest($config, $options);
            if (($circuit['allow'] ?? false) !== true) {
                $message = 'LLM circuit breaker is open; provider request skipped.';
                $result = [
                    'ok' => false,
                    'message' => $message,
                    'code' => 503,
                    'retryable' => true,
                    'terminal_failure' => true,
                    'failure_state' => 'circuit_open',
                    'data' => $this->debug('circuit_open', $config, 0, '', $prompt, '', $message, array_merge($meta, [
                        'failure_state' => 'circuit_open',
                        'terminal_failure' => true,
                    ])),
                ];
                $attempts[] = $this->providerAttemptSummary($config, $isFallback, 'circuit_open', 0, 0, (string)($circuit['state'] ?? 'open'));
                $attemptGovernance = array_merge($governance, [
                    'requested_model_key' => $modelKey,
                    'fallback_used' => $isFallback,
                    'circuit_state' => (string)($circuit['state'] ?? 'open'),
                    'provider_attempts' => $attempts,
                ]);
                $lastResult = $this->finishWithGovernance(
                    $result,
                    $attemptGovernance,
                    $config,
                    $prompt,
                    '',
                    'failed',
                    'circuit_open',
                    $message,
                    0,
                    0,
                    $startedAt,
                    false
                );
                continue;
            }

            $outcome = $this->executeProviderAttempt($config, $prompt, $meta, array_merge($options, [
                'request_id' => (string)$governance['request_id'],
            ]));
            if (($outcome['success'] ?? false) === true) {
                $circuitState = $this->recordCircuitSuccess($config, $options);
            } elseif (($outcome['circuit_failure'] ?? false) === true) {
                $circuitState = $this->recordCircuitFailure($config, $options, (string)($circuit['state'] ?? 'closed'));
            } else {
                $circuitState = (string)($circuit['state'] ?? 'closed');
            }

            $attempts[] = $this->providerAttemptSummary(
                $config,
                $isFallback,
                (string)($outcome['error_type'] ?? ''),
                (int)($outcome['http_status'] ?? 0),
                (int)($outcome['retry_attempts'] ?? 0),
                $circuitState,
                (string)($circuit['state'] ?? 'closed')
            );
            $attemptGovernance = array_merge($governance, [
                'requested_model_key' => $modelKey,
                'fallback_used' => $isFallback,
                'circuit_state' => $circuitState,
                'provider_attempts' => $attempts,
            ]);
            $loggedResult = $this->finishWithGovernance(
                (array)$outcome['result'],
                $attemptGovernance,
                $config,
                $prompt,
                (string)($outcome['response'] ?? ''),
                ($outcome['success'] ?? false) === true ? 'success' : 'failed',
                (string)($outcome['error_type'] ?? ''),
                (string)($outcome['error_message'] ?? ''),
                (int)($outcome['http_status'] ?? 0),
                (int)($outcome['payload_size'] ?? 0),
                $startedAt,
                ($outcome['success'] ?? false) === true
            );
            $loggedResult = $this->attachStabilityMetadata($loggedResult, $governance, $attempts, $isFallback, $circuitState);

            if (($outcome['success'] ?? false) === true && ($loggedResult['ok'] ?? false) === true) {
                $this->storeSuccessfulResponse($modelKey, $governance, $options, $config, $loggedResult);
                return $loggedResult;
            }

            $lastResult = $loggedResult;
            if (($outcome['success'] ?? false) === true) {
                break;
            }
        }

        return $this->degradeAfterProviderFailures(
            $lastResult,
            $prompt,
            $modelKey,
            $meta,
            $options,
            $governance,
            $attempts,
            $startedAt
        );
    }

    /** @return array<string, mixed> */
    private function executeProviderAttempt(array $config, string $prompt, array $meta, array $options): array
    {
        $payload = $this->chatPayload($config, $prompt, $options);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            $message = 'LLM payload encode failed: ' . json_last_error_msg();
            return [
                'success' => false,
                'circuit_failure' => false,
                'response' => '',
                'http_status' => 0,
                'retry_attempts' => 0,
                'payload_size' => 0,
                'error_type' => 'json_encode_error',
                'error_message' => $message,
                'result' => [
                    'ok' => false,
                    'message' => $message,
                    'code' => 500,
                    'data' => $this->debug('json_encode_error', $config, 0, '', $prompt, '', $message, $meta),
                ],
            ];
        }

        $transport = $this->sendWithRetry(
            $this->chatCompletionUrl($config),
            $config,
            $payloadJson,
            $options
        );
        $response = $transport['response'];
        $statusCode = (int)$transport['http_status'];
        $retryMeta = [
            'request_id' => (string)($options['request_id'] ?? ''),
            'retry_attempts' => (int)$transport['retry_attempts'],
            'max_retries' => (int)$transport['max_retries'],
            'retry_delays_ms' => $this->retrySchedule($options),
            'retryable' => (bool)$transport['retryable'],
            'retry_reason' => (string)$transport['retry_reason'],
            'retry_exhausted' => (bool)($transport['retry_exhausted'] ?? false),
            'terminal_failure' => (bool)($transport['terminal_failure'] ?? false),
            'failure_state' => (string)($transport['failure_state'] ?? ''),
        ];
        $debugMeta = array_merge($meta, $retryMeta);

        if ($response === false) {
            $message = $this->sanitize((string)($transport['error'] ?? 'Network request failed'));
            return [
                'success' => false,
                'circuit_failure' => true,
                'response' => '',
                'http_status' => $statusCode,
                'retry_attempts' => (int)$transport['retry_attempts'],
                'payload_size' => strlen($payloadJson),
                'error_type' => 'network_error',
                'error_message' => $message,
                'result' => [
                    'ok' => false,
                    'message' => $message,
                    'code' => 503,
                    'retryable' => (bool)$transport['retryable'],
                    'terminal_failure' => (bool)($transport['terminal_failure'] ?? true),
                    'failure_state' => (string)($transport['failure_state'] ?? 'retry_exhausted'),
                    'data' => $this->debug('network_error', $config, $statusCode, $message, $prompt, '', $message, $debugMeta, strlen($payloadJson)),
                ],
            ];
        }

        $data = json_decode((string)$response, true);
        if ($statusCode >= 400) {
            $message = $this->sanitize((string)(is_array($data)
                ? ($data['error']['message'] ?? ('LLM HTTP error: ' . $statusCode))
                : ('LLM HTTP error: ' . $statusCode)));
            return [
                'success' => false,
                'circuit_failure' => $this->isCircuitFailureStatus($statusCode),
                'response' => (string)$response,
                'http_status' => $statusCode,
                'retry_attempts' => (int)$transport['retry_attempts'],
                'payload_size' => strlen($payloadJson),
                'error_type' => 'http_error',
                'error_message' => $message,
                'result' => [
                    'ok' => false,
                    'message' => $message,
                    'code' => $statusCode,
                    'retryable' => (bool)$transport['retryable'],
                    'terminal_failure' => true,
                    'failure_state' => (string)($transport['failure_state'] ?? 'terminal_failure'),
                    'data' => $this->debug('http_error', $config, $statusCode, '', $prompt, (string)$response, $message, $debugMeta, strlen($payloadJson)),
                ],
            ];
        }

        if (!is_array($data)) {
            $message = 'LLM response is not JSON';
            return [
                'success' => false,
                'circuit_failure' => true,
                'response' => (string)$response,
                'http_status' => $statusCode,
                'retry_attempts' => (int)$transport['retry_attempts'],
                'payload_size' => strlen($payloadJson),
                'error_type' => 'invalid_response',
                'error_message' => $message,
                'result' => [
                    'ok' => false,
                    'message' => $message,
                    'code' => 502,
                    'data' => $this->debug('invalid_response', $config, $statusCode, '', $prompt, (string)$response, $message, $debugMeta, strlen($payloadJson)),
                ],
            ];
        }

        $content = $this->extractChatContent($data);
        if ($content === '') {
            $message = 'LLM returned empty content';
            return [
                'success' => false,
                'circuit_failure' => true,
                'response' => (string)$response,
                'http_status' => $statusCode,
                'retry_attempts' => (int)$transport['retry_attempts'],
                'payload_size' => strlen($payloadJson),
                'error_type' => 'empty_content',
                'error_message' => $message,
                'result' => [
                    'ok' => false,
                    'message' => $message,
                    'code' => 502,
                    'data' => $this->debug('empty_content', $config, $statusCode, '', $prompt, (string)$response, $message, $debugMeta, strlen($payloadJson)),
                ],
            ];
        }

        if (is_array($options['json_schema'] ?? null)) {
            $structured = json_decode($this->extractJsonText($content), true);
            if (!is_array($structured)) {
                $message = 'LLM did not return valid structured JSON';
                return [
                    'success' => false,
                    'circuit_failure' => true,
                    'response' => (string)$response,
                    'http_status' => $statusCode,
                    'retry_attempts' => (int)$transport['retry_attempts'],
                    'payload_size' => strlen($payloadJson),
                    'error_type' => 'invalid_structured_response',
                    'error_message' => $message,
                    'result' => [
                        'ok' => false,
                        'message' => $message,
                        'code' => 502,
                        'data' => $this->debug('invalid_structured_response', $config, $statusCode, '', $prompt, $content, $message, $debugMeta, strlen($payloadJson)),
                    ],
                ];
            }
        }

        return [
            'success' => true,
            'circuit_failure' => false,
            'response' => $content,
            'http_status' => $statusCode,
            'retry_attempts' => (int)$transport['retry_attempts'],
            'payload_size' => strlen($payloadJson),
            'error_type' => '',
            'error_message' => '',
            'result' => [
                'ok' => true,
                'code' => 200,
                'content' => $content,
                'model' => $config['model'],
                'model_key' => $config['model_key'],
                'provider' => $config['provider'],
                'data' => [
                    'debug' => [
                        'provider' => (string)$config['provider'],
                        'model_key' => (string)$config['model_key'],
                        'model' => (string)$config['model'],
                        'model_name' => (string)$config['model'],
                        'config_source' => 'database',
                        'http_status' => $statusCode,
                        'selected_hotel_count' => (int)($meta['selected_hotel_count'] ?? 0),
                        'request_payload_size' => strlen($payloadJson),
                        'prompt_length' => (int)($meta['prompt_length'] ?? mb_strlen($prompt)),
                        'retry_attempts' => (int)$transport['retry_attempts'],
                        'max_retries' => (int)$transport['max_retries'],
                        'retry_delays_ms' => $this->retrySchedule($options),
                    ],
                ],
            ],
        ];
    }

    private function degradeAfterProviderFailures(
        array $lastResult,
        string $prompt,
        string $modelKey,
        array $meta,
        array $options,
        array $governance,
        array $attempts,
        float $startedAt
    ): array {
        $failureMessage = $this->sanitize((string)($lastResult['message'] ?? 'All configured LLM providers are unavailable.'), 300);
        $lastCircuitState = $this->lastCircuitState($attempts);
        $cached = $this->cachedSuccessfulResponse($modelKey, $governance, $options);
        if ($cached !== null) {
            $config = [
                'provider' => (string)($cached['provider'] ?? ''),
                'model_key' => (string)($cached['model_key'] ?? $modelKey),
                'model' => (string)($cached['model'] ?? ''),
                'source' => 'response_cache',
            ];
            $content = (string)($cached['content'] ?? '');
            $cacheAge = max(0, time() - (int)($cached['stored_at'] ?? time()));
            $result = $this->finishWithGovernance([
                'ok' => true,
                'code' => 200,
                'content' => $content,
                'model' => $config['model'],
                'model_key' => $config['model_key'],
                'provider' => $config['provider'],
                'data' => [
                    'debug' => [
                        'provider' => $config['provider'],
                        'model_key' => $config['model_key'],
                        'model' => $config['model'],
                        'config_source' => 'response_cache',
                        'http_status' => 200,
                        'cache_age_seconds' => $cacheAge,
                    ],
                ],
            ], array_merge($governance, [
                'requested_model_key' => $modelKey,
                'fallback_used' => true,
                'provider_attempts' => $attempts,
            ]), $config, $prompt, $content, 'degraded', 'cache_fallback', $failureMessage, 200, 0, $startedAt, true);
            $result = $this->attachStabilityMetadata($result, $governance, $attempts, true, $lastCircuitState);
            return $this->applyDegradationMetadata($result, 'cache', $failureMessage, false, [
                'cache_hit' => true,
                'cache_age_seconds' => $cacheAge,
            ]);
        }

        $ruleFallback = $this->resolveRuleEngineFallback($options, [
            'request_id' => (string)$governance['request_id'],
            'model_key' => $modelKey,
            'prompt_hash' => (string)$governance['prompt_hash'],
            'failure_message' => $failureMessage,
            'provider_attempts' => $attempts,
        ]);
        if ($ruleFallback !== null) {
            $config = [
                'provider' => 'rule_engine',
                'model_key' => $modelKey,
                'model' => 'deterministic_rules',
                'source' => 'rule_engine',
            ];
            $content = (string)$ruleFallback['content'];
            $result = $this->finishWithGovernance([
                'ok' => true,
                'code' => 200,
                'content' => $content,
                'model' => 'deterministic_rules',
                'model_key' => $modelKey,
                'provider' => 'rule_engine',
                'data' => [
                    'debug' => [
                        'provider' => 'rule_engine',
                        'model_key' => $modelKey,
                        'model' => 'deterministic_rules',
                        'config_source' => 'rule_engine',
                        'http_status' => 200,
                    ],
                ],
            ], array_merge($governance, [
                'requested_model_key' => $modelKey,
                'fallback_used' => true,
                'provider_attempts' => $attempts,
            ]), $config, $prompt, $content, 'degraded', 'rule_engine_fallback', $failureMessage, 200, 0, $startedAt, false);
            $result = $this->attachStabilityMetadata($result, $governance, $attempts, true, $lastCircuitState);
            return $this->applyDegradationMetadata($result, 'rule_engine', $failureMessage, false, [
                'rule_engine_applied' => true,
            ]);
        }

        return $this->manualDegradationResult(
            $lastResult,
            $governance,
            ['model_key' => $modelKey, 'source' => 'database'],
            $prompt,
            $failureMessage,
            'providers_unavailable',
            $attempts,
            $startedAt
        );
    }

    private function manualDegradationResult(
        array $baseResult,
        array $governance,
        array $config,
        string $prompt,
        string $failureMessage,
        string $failureState,
        array $attempts,
        float $startedAt
    ): array {
        if ($baseResult === []) {
            $baseResult = $this->finishWithGovernance([
                'ok' => false,
                'message' => $failureMessage,
                'code' => 503,
                'data' => $this->debug($failureState, $config, 0, '', $prompt, '', $failureMessage, [
                    'terminal_failure' => true,
                    'failure_state' => $failureState,
                ]),
            ], array_merge($governance, ['provider_attempts' => $attempts]), $config, $prompt, '', 'degraded', $failureState, $failureMessage, 0, 0, $startedAt, false);
        }

        $baseResult['ok'] = false;
        $baseResult['code'] = 200;
        $baseResult['message'] = 'AI服务暂时不可用，业务已安全降级，需人工处理。';
        $baseResult['terminal_failure'] = true;
        $baseResult['failure_state'] = $failureState;
        $baseResult = $this->attachStabilityMetadata($baseResult, $governance, $attempts, false, $this->lastCircuitState($attempts));
        return $this->applyDegradationMetadata($baseResult, 'manual', $failureMessage, true, [
            'rule_engine_applied' => false,
            'cache_hit' => false,
        ]);
    }

    private function applyDegradationMetadata(array $result, string $mode, string $reason, bool $manualRequired, array $extra = []): array
    {
        $result['degraded'] = true;
        $result['degradation_mode'] = $mode;
        $result['manual_required'] = $manualRequired;
        $result['business_action_allowed'] = false;
        $result['data'] = is_array($result['data'] ?? null) ? $result['data'] : [];
        $result['data']['degradation'] = array_merge([
            'active' => true,
            'mode' => $mode,
            'reason' => $this->sanitize($reason, 300),
            'manual_required' => $manualRequired,
            'available_chain' => ['provider_fallback', 'cache', 'rule_engine', 'manual'],
        ], $extra);
        return $result;
    }

    private function lastCircuitState(array $attempts): string
    {
        if ($attempts === []) {
            return 'closed';
        }
        $last = $attempts[array_key_last($attempts)] ?? [];
        $state = is_array($last) ? (string)($last['circuit_state'] ?? 'closed') : 'closed';
        return in_array($state, ['closed', 'open', 'half-open'], true) ? $state : 'closed';
    }

    private function attachStabilityMetadata(
        array $result,
        array $governance,
        array $attempts,
        bool $fallbackUsed,
        string $circuitState
    ): array {
        $result['request_id'] = (string)$governance['request_id'];
        $result['fallback_used'] = $fallbackUsed;
        $result['business_action_allowed'] = ($result['ok'] ?? false) === true && ($result['degraded'] ?? false) !== true;
        $result['data'] = is_array($result['data'] ?? null) ? $result['data'] : [];
        $result['data']['stability'] = [
            'request_id' => (string)$governance['request_id'],
            'provider_fallback_used' => $fallbackUsed,
            'provider_attempts' => $attempts,
            'circuit_state' => $circuitState,
        ];
        if (isset($result['data']['debug']) && is_array($result['data']['debug'])) {
            $result['data']['debug']['request_id'] = (string)$governance['request_id'];
            $result['data']['debug']['provider_fallback_used'] = $fallbackUsed;
            $result['data']['debug']['provider_attempts'] = $attempts;
            $result['data']['debug']['circuit_state'] = $circuitState;
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function providerAttemptSummary(
        array $config,
        bool $fallback,
        string $errorType,
        int $httpStatus,
        int $retryAttempts,
        string $circuitState,
        string $circuitStateBefore = ''
    ): array {
        return [
            'provider' => mb_substr((string)($config['provider'] ?? ''), 0, 50),
            'model_key' => mb_substr((string)($config['model_key'] ?? ''), 0, 100),
            'model' => mb_substr((string)($config['model'] ?? ''), 0, 150),
            'fallback' => $fallback,
            'http_status' => $httpStatus,
            'retry_attempts' => $retryAttempts,
            'error_type' => mb_substr($errorType, 0, 80),
            'circuit_state' => $circuitState,
            'circuit_state_before' => $circuitStateBefore !== '' ? $circuitStateBefore : $circuitState,
        ];
    }

    public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
    {
        $governanceMeta = $this->schemaGovernanceMeta($schema);
        $schemaForPrompt = $this->schemaWithoutGovernance($schema);
        $prompt = $this->messagesToPrompt($messages, $schemaForPrompt);
        $result = $this->chat($prompt, $modelKey, array_merge($governanceMeta, ['prompt_length' => mb_strlen($prompt)]), [
            'temperature' => 0.1,
            'timeout' => 60,
            'json_schema' => $schemaForPrompt,
            'json_schema_name' => $this->schemaResponseName((string)($governanceMeta['scenario'] ?? $governanceMeta['prompt_version'] ?? 'structured_response')),
        ]);
        if (($result['ok'] ?? false) !== true) {
            throw new RuntimeException(
                (string)($result['message'] ?? 'LLM request failed'),
                (int)($result['code'] ?? 200)
            );
        }

        $jsonText = $this->extractJsonText((string)$result['content']);
        $data = json_decode($jsonText, true);
        if (!is_array($data)) {
            throw new RuntimeException('LLM did not return valid JSON.');
        }
        $this->updateGovernanceFromJson($result, $data);
        return $data;
    }

    public function isConfiguredModelKey(string $modelKey): bool
    {
        $modelKey = $this->normalizeModelKey($modelKey);
        try {
            return AiModelConfig::where('model_key', $modelKey)->find() !== null
                || in_array($modelKey, ['deepseek_chat', 'deepseek_reasoner', 'deepseek_v4_default', 'deepseek_v4_flash', 'deepseek_v4_fast', 'deepseek_v4_pro', 'openai_fast'], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function configByModelKey(string $modelKey): array
    {
        $modelKey = $this->normalizeModelKey($modelKey);
        foreach ($this->lookupCandidates($modelKey) as $candidateKey) {
            $config = $this->databaseConfig($candidateKey, $modelKey);
            if ($config !== null) {
                return $config;
            }
        }

        return [
            'ok' => false,
            'message' => '未找到启用的AI模型配置：' . $modelKey . '，请到系统设置 > AI模型配置中启用或新增模型。',
            'code' => 422,
            'model_key' => $modelKey,
            'source' => 'database',
            'config_entry' => '/ai-model-config',
            'next_action' => '检查模型Key、Base URL、模型名称、API Key和AI_CONFIG_SECRET。',
        ];
    }

    private function databaseConfig(string $modelKey, string $requestedModelKey): ?array
    {
        try {
            $config = AiModelConfig::where('model_key', $modelKey)->where('is_enabled', 1)->find();
            if (!$config) {
                $disabledConfig = AiModelConfig::where('model_key', $modelKey)->find();
                if ($disabledConfig) {
                    return $this->configIssue('模型配置已停用：' . $requestedModelKey . '，请到系统设置 > AI模型配置中启用。', [
                        'provider' => (string)$disabledConfig->provider,
                        'model_key' => $requestedModelKey,
                        'model' => (string)$disabledConfig->model_name,
                        'source' => 'database',
                    ]);
                }
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        $baseUrl = rtrim(trim((string)$config->base_url), '/');
        $modelName = trim((string)$config->model_name);
        $errorConfig = [
            'provider' => (string)$config->provider,
            'model_key' => $requestedModelKey,
            'model' => $modelName,
            'source' => 'database',
        ];
        if ($baseUrl === '') {
            return $this->configIssue('AI模型 Base URL 为空，请补充 OpenAI 兼容接口地址。', $errorConfig);
        }
        if ($modelName === '') {
            return $this->configIssue('AI模型名称为空，请填写实际模型名。', $errorConfig);
        }
        if (trim((string)$config->api_key_encrypted) === '') {
            return $this->configIssue('AI模型 API Key 为空，请重新保存密钥。', $errorConfig);
        }

        $secret = trim((string)env('AI_CONFIG_SECRET', ''));
        if ($secret === '') {
            return $this->configIssue('AI_CONFIG_SECRET 未配置，无法解密数据库中的模型密钥。', $errorConfig);
        }

        $apiKey = AiModelConfig::decryptApiKey((string)$config->api_key_encrypted, $secret);
        if ($apiKey === null) {
            return $this->configIssue('AI模型 API Key 解密失败，请确认 AI_CONFIG_SECRET 与保存密钥时一致。', $errorConfig);
        }

        return [
            'ok' => true,
            'provider' => (string)$config->provider,
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'model' => $modelName,
            'model_key' => $requestedModelKey,
            'source' => 'database',
        ];
    }

    private function configIssue(string $message, array $config, int $code = 400): array
    {
        return array_merge([
            'ok' => false,
            'message' => $message,
            'code' => $code,
            'config_entry' => '/ai-model-config',
            'next_action' => '检查模型Key、Base URL、模型名称、API Key和AI_CONFIG_SECRET。',
        ], $config);
    }

    private function normalizeModelKey(string $modelKey, array $options = []): string
    {
        $key = trim($modelKey);
        $mode = strtolower(trim((string)($options['model_mode'] ?? '')));
        if ($key === '') {
            $key = $mode === 'pro' ? 'deepseek_reasoner' : 'deepseek_chat';
        }

        $aliases = [
            'deepseek-v4-pro' => 'deepseek_reasoner',
            'deepseek-reasoner' => 'deepseek_reasoner',
            'deepseek-v4-flash' => 'deepseek_chat',
            'deepseek-chat' => 'deepseek_chat',
        ];
        $lowerKey = strtolower($key);
        if (isset($aliases[$lowerKey])) {
            return $aliases[$lowerKey];
        }

        if ($key === 'deepseek_v4_default') {
            return $mode === 'pro' ? 'deepseek_reasoner' : 'deepseek_chat';
        }

        return $key;
    }

    private function lookupCandidates(string $modelKey): array
    {
        $groups = [
            ['deepseek_chat', 'deepseek_v4_flash', 'deepseek_v4_fast'],
            ['deepseek_reasoner', 'deepseek_v4_pro'],
        ];

        $candidates = [$modelKey];
        foreach ($groups as $group) {
            if (in_array($modelKey, $group, true)) {
                $candidates = array_merge($candidates, $group);
                break;
            }
        }
        return array_values(array_unique($candidates));
    }

    /** @return array<int, array<string, mixed>> */
    protected function providerFallbackConfigs(array $primaryConfig, string $requestedModelKey, array $options): array
    {
        if (!$this->optionEnabled($options, 'provider_fallback_enabled', true)) {
            return [];
        }

        $maxFallbacks = max(0, min(3, (int)($options['max_provider_fallbacks'] ?? 1)));
        if ($maxFallbacks === 0) {
            return [];
        }

        $explicitKeys = $this->normalizeFallbackModelKeys(
            $options['fallback_model_keys'] ?? $options['fallback_model_key'] ?? []
        );
        $candidateKeys = $explicitKeys;
        $providerByKey = [];
        if ($candidateKeys === []) {
            try {
                $models = AiModelConfig::where('is_enabled', 1)
                    ->order('is_default', 'desc')
                    ->order('id', 'asc')
                    ->select();
                foreach ($models as $model) {
                    $key = $this->normalizeModelKey((string)$model->getAttr('model_key'));
                    if ($key === '') {
                        continue;
                    }
                    $candidateKeys[] = $key;
                    $providerByKey[$key] = strtolower(trim((string)$model->getAttr('provider')));
                }
            } catch (Throwable $e) {
                // Fallback discovery remains best-effort; explicit model keys still work.
            }
            $candidateKeys = array_merge($candidateKeys, ['openai_fast', 'deepseek_chat', 'deepseek_reasoner']);
        }

        $candidateKeys = array_values(array_unique(array_filter(array_map(
            fn(string $key): string => $this->normalizeModelKey($key),
            array_map('strval', $candidateKeys)
        ))));
        $primaryProvider = strtolower(trim((string)($primaryConfig['provider'] ?? '')));
        $primaryIdentity = ($primaryConfig['ok'] ?? false) === true ? $this->configIdentity($primaryConfig) : '';

        if ($explicitKeys === [] && $primaryProvider !== '') {
            usort($candidateKeys, static function (string $left, string $right) use ($providerByKey, $primaryProvider): int {
                $leftSame = ($providerByKey[$left] ?? '') === $primaryProvider;
                $rightSame = ($providerByKey[$right] ?? '') === $primaryProvider;
                return $leftSame <=> $rightSame;
            });
        }

        $fallbacks = [];
        $seen = [];
        foreach ($candidateKeys as $candidateKey) {
            if ($candidateKey === '' || hash_equals($requestedModelKey, $candidateKey)) {
                continue;
            }
            $config = $this->configByModelKey($candidateKey);
            if (($config['ok'] ?? false) !== true) {
                continue;
            }
            $identity = $this->configIdentity($config);
            if (($primaryIdentity !== '' && hash_equals($primaryIdentity, $identity)) || isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $fallbacks[] = $config;
            if (count($fallbacks) >= $maxFallbacks) {
                break;
            }
        }
        return $fallbacks;
    }

    /** @return array<int, string> */
    private function normalizeFallbackModelKeys(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,，;；]+/u', trim($value)) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $keys = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $key = $this->normalizeModelKey((string)$item);
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        return array_values(array_unique($keys));
    }

    private function configIdentity(array $config): string
    {
        return hash('sha256', implode('|', [
            strtolower(trim((string)($config['provider'] ?? ''))),
            strtolower(rtrim(trim((string)($config['base_url'] ?? '')), '/')),
            strtolower(trim((string)($config['model'] ?? ''))),
        ]));
    }

    /** @return array{allow:bool,state:string,failure_count:int} */
    private function beforeCircuitRequest(array $config, array $options): array
    {
        if (!$this->optionEnabled($options, 'circuit_breaker_enabled', true)) {
            return ['allow' => true, 'state' => 'closed', 'failure_count' => 0];
        }

        $key = $this->circuitCacheKey($config);
        $openSeconds = max(1, min(3600, (int)($options['circuit_open_seconds'] ?? self::DEFAULT_CIRCUIT_OPEN_SECONDS)));
        $probeTimeout = max(5, min(300, (int)($options['circuit_half_open_probe_timeout_seconds'] ?? self::MAX_TRANSPORT_TIMEOUT_SECONDS)));
        return $this->withStateTransitionLock($key, function () use ($key, $openSeconds, $probeTimeout): array {
            $now = time();
            $state = $this->stateGet($key);
            $state = is_array($state) ? $state : ['state' => 'closed', 'failure_count' => 0, 'opened_at' => 0];
            $name = (string)($state['state'] ?? 'closed');
            $failureCount = max(0, (int)($state['failure_count'] ?? 0));

            if ($name === 'open') {
                $openedAt = (int)($state['opened_at'] ?? 0);
                if ($openedAt > 0 && ($now - $openedAt) < $openSeconds) {
                    return ['allow' => false, 'state' => 'open', 'failure_count' => $failureCount];
                }
                $state['state'] = 'half-open';
                $state['probe_started_at'] = $now;
                $this->stateSet($key, $state, max(300, $openSeconds * 10));
                return ['allow' => true, 'state' => 'half-open', 'failure_count' => $failureCount];
            }

            if ($name === 'half-open') {
                $probeStartedAt = (int)($state['probe_started_at'] ?? 0);
                if ($probeStartedAt > 0 && ($now - $probeStartedAt) < $probeTimeout) {
                    return ['allow' => false, 'state' => 'half-open', 'failure_count' => $failureCount];
                }
                $state['probe_started_at'] = $now;
                $this->stateSet($key, $state, max(300, $openSeconds * 10));
                return ['allow' => true, 'state' => 'half-open', 'failure_count' => $failureCount];
            }

            return ['allow' => true, 'state' => 'closed', 'failure_count' => $failureCount];
        });
    }

    private function recordCircuitSuccess(array $config, array $options): string
    {
        if (!$this->optionEnabled($options, 'circuit_breaker_enabled', true)) {
            return 'closed';
        }
        $key = $this->circuitCacheKey($config);
        return $this->withStateTransitionLock($key, function () use ($key): string {
            $this->stateDelete($key);
            return 'closed';
        });
    }

    private function recordCircuitFailure(array $config, array $options, string $requestState): string
    {
        if (!$this->optionEnabled($options, 'circuit_breaker_enabled', true)) {
            return 'closed';
        }
        $key = $this->circuitCacheKey($config);
        $threshold = max(1, min(20, (int)($options['circuit_failure_threshold'] ?? self::DEFAULT_CIRCUIT_FAILURE_THRESHOLD)));
        $openSeconds = max(1, min(3600, (int)($options['circuit_open_seconds'] ?? self::DEFAULT_CIRCUIT_OPEN_SECONDS)));
        return $this->withStateTransitionLock($key, function () use ($key, $threshold, $openSeconds, $requestState): string {
            $state = $this->stateGet($key);
            $state = is_array($state) ? $state : ['state' => 'closed', 'failure_count' => 0, 'opened_at' => 0];
            $failureCount = max(0, (int)($state['failure_count'] ?? 0)) + 1;
            $shouldOpen = $requestState === 'half-open' || $failureCount >= $threshold;
            $state = [
                'state' => $shouldOpen ? 'open' : 'closed',
                'failure_count' => $failureCount,
                'opened_at' => $shouldOpen ? time() : 0,
            ];
            $this->stateSet($key, $state, max(300, $openSeconds * 10));
            return (string)$state['state'];
        });
    }

    private function circuitCacheKey(array $config): string
    {
        return self::STATE_PREFIX . 'circuit:' . hash('sha256', implode('|', [
            strtolower(trim((string)($config['provider'] ?? 'unknown'))),
            strtolower(rtrim(trim((string)($config['base_url'] ?? '')), '/')),
        ]));
    }

    private function isCircuitFailureStatus(int $statusCode): bool
    {
        return in_array($statusCode, [408, 425, 429], true) || ($statusCode >= 500 && $statusCode <= 599);
    }

    private function idempotencyFingerprint(array $governance, string $modelKey, array $options): string
    {
        $relevantOptions = [
            'temperature' => $options['temperature'] ?? null,
            'json_schema' => $options['json_schema'] ?? null,
            'model_mode' => $options['model_mode'] ?? null,
        ];
        $encoded = json_encode([
            'model_key' => $modelKey,
            'prompt_hash' => (string)($governance['prompt_hash'] ?? ''),
            'module' => (string)($governance['module'] ?? ''),
            'scenario' => (string)($governance['scenario'] ?? ''),
            'hotel_id' => (int)($governance['hotel_id'] ?? 0),
            'user_id' => (int)($governance['user_id'] ?? 0),
            'options' => $relevantOptions,
        ], JSON_UNESCAPED_UNICODE);
        return hash('sha256', $encoded !== false ? $encoded : serialize($relevantOptions));
    }

    private function idempotencyReplay(string $requestId, string $fingerprint): ?array
    {
        $entry = $this->stateGet($this->idempotencyCacheKey($requestId));
        if (!is_array($entry) || !is_array($entry['result'] ?? null)) {
            return null;
        }
        $storedFingerprint = (string)($entry['fingerprint'] ?? '');
        if ($storedFingerprint === '' || !hash_equals($storedFingerprint, $fingerprint)) {
            return [
                'ok' => false,
                'code' => 409,
                'message' => 'request_id 已用于不同的AI请求，已拒绝重复业务动作。',
                'request_id' => $requestId,
                'idempotent_replay' => true,
                'business_action_allowed' => false,
                'failure_state' => 'idempotency_conflict',
                'data' => [
                    'idempotency' => [
                        'request_id' => $requestId,
                        'replay' => true,
                        'conflict' => true,
                        'business_action_allowed' => false,
                    ],
                ],
            ];
        }
        return $this->markIdempotencyResult((array)$entry['result'], true);
    }

    private function storeIdempotencyResult(string $requestId, string $fingerprint, array $result, array $options): void
    {
        $ttl = max(60, min(604800, (int)($options['idempotency_ttl_seconds'] ?? self::DEFAULT_IDEMPOTENCY_TTL_SECONDS)));
        $this->stateSet($this->idempotencyCacheKey($requestId), [
            'fingerprint' => $fingerprint,
            'result' => $result,
            'stored_at' => time(),
        ], $ttl);
    }

    private function markIdempotencyResult(array $result, bool $replay): array
    {
        $requestId = (string)($result['request_id'] ?? $result['data']['governance']['request_id'] ?? '');
        $result['idempotent_replay'] = $replay;
        if ($replay) {
            $result['business_action_allowed'] = false;
        } elseif (!array_key_exists('business_action_allowed', $result)) {
            $result['business_action_allowed'] = ($result['ok'] ?? false) === true && ($result['degraded'] ?? false) !== true;
        }
        $result['data'] = is_array($result['data'] ?? null) ? $result['data'] : [];
        $result['data']['idempotency'] = [
            'request_id' => $requestId,
            'replay' => $replay,
            'conflict' => false,
            'business_action_allowed' => (bool)$result['business_action_allowed'],
        ];
        return $result;
    }

    private function idempotencyCacheKey(string $requestId): string
    {
        return self::STATE_PREFIX . 'idempotency:' . hash('sha256', $requestId);
    }

    private function responseCacheKey(string $modelKey, array $governance, array $options): string
    {
        return self::STATE_PREFIX . 'response:' . $this->idempotencyFingerprint($governance, $modelKey, $options);
    }

    private function storeSuccessfulResponse(string $modelKey, array $governance, array $options, array $config, array $result): void
    {
        if (!$this->optionEnabled($options, 'response_cache_enabled', true)) {
            return;
        }
        $content = trim((string)($result['content'] ?? ''));
        if ($content === '') {
            return;
        }
        $ttl = max(60, min(604800, (int)($options['response_cache_ttl_seconds'] ?? self::DEFAULT_RESPONSE_CACHE_TTL_SECONDS)));
        $this->stateSet($this->responseCacheKey($modelKey, $governance, $options), [
            'content' => $content,
            'provider' => (string)($config['provider'] ?? ''),
            'model_key' => (string)($config['model_key'] ?? $modelKey),
            'model' => (string)($config['model'] ?? ''),
            'stored_at' => time(),
        ], $ttl);
    }

    private function cachedSuccessfulResponse(string $modelKey, array $governance, array $options): ?array
    {
        if (!$this->optionEnabled($options, 'response_cache_enabled', true)) {
            return null;
        }
        $cached = $this->stateGet($this->responseCacheKey($modelKey, $governance, $options));
        return is_array($cached) && trim((string)($cached['content'] ?? '')) !== '' ? $cached : null;
    }

    /** @return array{content:string}|null */
    private function resolveRuleEngineFallback(array $options, array $context): ?array
    {
        $fallback = $options['rule_engine_fallback'] ?? $options['rule_fallback'] ?? null;
        if ($fallback === null) {
            return null;
        }
        try {
            if (is_callable($fallback)) {
                $fallback = $fallback($context);
            }
        } catch (Throwable $e) {
            return null;
        }
        if (is_string($fallback)) {
            $content = trim($fallback);
        } elseif (is_array($fallback)) {
            if (isset($fallback['content']) && is_scalar($fallback['content'])) {
                $content = trim((string)$fallback['content']);
            } else {
                $encoded = json_encode($fallback, JSON_UNESCAPED_UNICODE);
                $content = $encoded !== false ? $encoded : '';
            }
        } else {
            $content = '';
        }
        return $content !== '' ? ['content' => $content] : null;
    }

    private function optionEnabled(array $options, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }
        $value = $options[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return $default;
    }

    protected function withIdempotencyLock(string $requestId, callable $callback): ?array
    {
        $handle = $this->openLockHandle('request:' . $requestId);
        if (!is_resource($handle)) {
            return null;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        try {
            $result = $callback();
            return is_array($result) ? $result : null;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function withStateTransitionLock(string $key, callable $callback): mixed
    {
        $handle = $this->openLockHandle('state:' . $key);
        if (!is_resource($handle)) {
            return $callback();
        }
        if (!@flock($handle, LOCK_EX)) {
            fclose($handle);
            return $callback();
        }
        try {
            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return resource|false */
    private function openLockHandle(string $key): mixed
    {
        $bases = [];
        if (function_exists('runtime_path')) {
            try {
                $bases[] = (string)runtime_path();
            } catch (Throwable $e) {
                // Continue with the operating-system temporary directory.
            }
        }
        $bases[] = sys_get_temp_dir();
        foreach (array_values(array_unique(array_filter($bases))) as $base) {
            $directory = rtrim($base, '\\/') . DIRECTORY_SEPARATOR . 'suxios-llm-locks';
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                continue;
            }
            $handle = @fopen($directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.lock', 'c');
            if (is_resource($handle)) {
                return $handle;
            }
        }
        return false;
    }

    protected function stateGet(string $key): mixed
    {
        try {
            $value = Cache::get($key, null);
            if ($value !== null) {
                return $value;
            }
        } catch (Throwable $e) {
            // Process-local fallback keeps one worker safe when the shared cache is unavailable.
        }
        $entry = self::$localState[$key] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        if ((int)($entry['expires_at'] ?? 0) > 0 && (int)$entry['expires_at'] <= time()) {
            unset(self::$localState[$key]);
            return null;
        }
        return $entry['value'] ?? null;
    }

    protected function stateSet(string $key, mixed $value, int $ttlSeconds): void
    {
        $ttlSeconds = max(1, $ttlSeconds);
        self::$localState[$key] = [
            'value' => $value,
            'expires_at' => time() + $ttlSeconds,
        ];
        try {
            Cache::set($key, $value, $ttlSeconds);
        } catch (Throwable $e) {
            // The local fallback above preserves per-worker behavior and avoids a business 500.
        }
    }

    protected function stateDelete(string $key): void
    {
        unset(self::$localState[$key]);
        try {
            Cache::delete($key);
        } catch (Throwable $e) {
            // Best-effort shared state cleanup.
        }
    }

    private function messagesToPrompt(array $messages, array $schema): string
    {
        $parts = [
            'Return JSON only. The JSON must match this schema:',
            json_encode($schema, JSON_UNESCAPED_UNICODE),
            'Messages:',
        ];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string)($message['role'] ?? 'user');
            $content = (string)($message['content'] ?? '');
            $parts[] = strtoupper($role) . ': ' . $content;
        }
        return implode("\n\n", $parts);
    }

    private function chatPayload(array $config, string $prompt, array $options): array
    {
        $payload = [
            'model' => $config['model'],
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => isset($options['temperature']) ? (float)$options['temperature'] : 0.2,
        ];

        $responseFormat = $this->nativeJsonSchemaResponseFormat($config, $options);
        if ($responseFormat !== []) {
            $payload['response_format'] = $responseFormat;
        }

        return $payload;
    }

    private function nativeJsonSchemaResponseFormat(array $config, array $options): array
    {
        $provider = strtolower(trim((string)($config['provider'] ?? '')));
        if ($provider !== 'openai') {
            return [];
        }

        $schema = $options['json_schema'] ?? null;
        if (!is_array($schema) || $schema === []) {
            return [];
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $this->schemaResponseName((string)($options['json_schema_name'] ?? 'structured_response')),
                'strict' => true,
                'schema' => $this->schemaWithoutGovernance($schema),
            ],
        ];
    }

    private function schemaResponseName(string $value): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($value)) ?? '';
        $name = trim($name, '_-');
        if ($name === '') {
            $name = 'structured_response';
        }
        return substr($name, 0, 64);
    }

    private function extractJsonText(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
            $text = trim($text);
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            return substr($text, $start, $end - $start + 1);
        }
        return $text;
    }

    private function extractChatContent(array $data): string
    {
        $content = $data['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $content = implode('', array_map(static fn($item) => is_array($item) ? (string)($item['text'] ?? '') : (string)$item, $content));
        }
        return trim((string)$content);
    }

    protected function sendWithRetry(string $url, array $config, string $payloadJson, array $options): array
    {
        $maxRetries = $this->maxRetries($options);
        $attempt = 0;
        $last = [
            'response' => false,
            'http_status' => 0,
            'error' => 'Network request failed',
            'retry_attempts' => 0,
            'max_retries' => $maxRetries,
            'retryable' => false,
            'retry_reason' => '',
            'retry_exhausted' => false,
            'terminal_failure' => false,
            'failure_state' => 'none',
        ];

        do {
            $last = array_merge($last, $this->sendOnce($url, $config, $payloadJson, $options));
            $retryReason = $this->retryReason($last['response'], (int)$last['http_status']);
            $last['retryable'] = $retryReason !== '';
            $last['retry_reason'] = $retryReason;
            $last['retry_attempts'] = $attempt;
            $last['max_retries'] = $maxRetries;

            if ($retryReason === '' || $attempt >= $maxRetries) {
                $failed = $last['response'] === false || (int)$last['http_status'] >= 400;
                $last['retry_exhausted'] = $retryReason !== '' && $attempt >= $maxRetries;
                $last['terminal_failure'] = $failed;
                $last['failure_state'] = !$failed
                    ? 'none'
                    : ($last['retryable']
                        ? ($last['retry_exhausted'] ? 'retry_exhausted' : 'retryable_failure')
                        : 'terminal_non_retryable');
                if ($last['response'] === false) {
                    $last['error'] = $this->actionableTransportError(
                        (string)($last['error'] ?? ''),
                        (bool)$last['retry_exhausted']
                    );
                }
                return $last;
            }

            $this->sleepBeforeRetry($attempt, $options);
            $attempt++;
        } while (true);
    }

    protected function chatCompletionUrl(array $config): string
    {
        return LlmEndpoint::chatCompletionUrl((string)$config['base_url'], (string)$config['provider']);
    }

    protected function sendOnce(string $url, array $config, string $payloadJson, array $options): array
    {
        try {
            $target = (new OutboundUrlGuard())->validate($url);
        } catch (\Throwable $e) {
            return [
                'response' => false,
                'http_status' => 0,
                'error' => 'Outbound LLM URL is not allowed',
            ];
        }
        if (!function_exists('curl_init')) {
            return [
                'response' => false,
                'http_status' => 0,
                'error' => 'Network request component is unavailable',
            ];
        }

        $transport = $this->performCurlRequest(
            (string)$target['url'],
            $this->buildCurlOptions($target, $config, $payloadJson, $options)
        );
        $response = $transport['response'];
        $statusCode = $transport['http_status'];
        $curlErrorNumber = $transport['curl_errno'];

        $error = $response === false
            ? ($curlErrorNumber === CURLE_OPERATION_TIMEDOUT ? 'Network request timed out' : 'Network request failed')
            : '';

        return [
            'response' => $response,
            'http_status' => $statusCode,
            'error' => $error,
        ];
    }

    /**
     * Isolated cURL execution seam for deterministic transport tests.
     *
     * @param array<int, mixed> $curlOptions
     * @return array{response:string|false,http_status:int,curl_errno:int}
     */
    protected function performCurlRequest(string $url, array $curlOptions): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['response' => false, 'http_status' => 0, 'curl_errno' => 0];
        }

        try {
            curl_setopt_array($ch, $curlOptions);
            return [
                'response' => curl_exec($ch),
                'http_status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'curl_errno' => curl_errno($ch),
            ];
        } finally {
            curl_close($ch);
        }
    }

    /**
     * @param array{url:string,host:string,port:int,addresses:array<int,string>,curl_resolve:array<int,string>} $target
     * @return array<int, mixed>
     */
    private function buildCurlOptions(array $target, array $config, string $payloadJson, array $options): array
    {
        $timeout = $this->transportTimeoutSeconds($options);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . (string)($config['api_key'] ?? ''),
        ];
        $requestId = $this->normalizeRequestId((string)($options['request_id'] ?? ''), false);
        if ($requestId !== '') {
            $headers[] = 'X-Request-ID: ' . $requestId;
            $headers[] = 'Idempotency-Key: ' . $requestId;
        }
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_RESOLVE => $target['curl_resolve'],
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $curlOptions[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        return $curlOptions;
    }

    private function transportTimeoutSeconds(array $options): int
    {
        $timeout = array_key_exists('timeout', $options) ? (int)$options['timeout'] : 45;
        return max(1, min(self::MAX_TRANSPORT_TIMEOUT_SECONDS, $timeout));
    }

    private function actionableTransportError(string $error, bool $retryExhausted): string
    {
        $isTimeout = preg_match('/timed?\s*out|timeout/i', $error) === 1;
        $failure = $isTimeout ? 'LLM transport timeout' : 'LLM transport failed';
        if ($retryExhausted) {
            return $failure . '; retries exhausted for this request. Retry later.';
        }
        return $failure . '. Retry later.';
    }

    private function retryReason(mixed $response, int $statusCode): string
    {
        if ($response === false) {
            return 'network_error';
        }
        if (in_array($statusCode, [408, 425, 429], true)) {
            return 'retryable_http_' . $statusCode;
        }
        if ($statusCode >= 500 && $statusCode <= 599) {
            return 'retryable_http_' . $statusCode;
        }
        return '';
    }

    private function maxRetries(array $options): int
    {
        $maxRetries = (int)($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES);
        return max(0, min(5, $maxRetries));
    }

    private function retryDelayMs(int $attempt, array $options): int
    {
        $schedule = $this->retrySchedule($options);
        if ($schedule === []) {
            return 0;
        }
        $delay = (int)$schedule[min(max(0, $attempt), count($schedule) - 1)];
        if (array_key_exists('retry_jitter_ms', $options)) {
            $delay += max(0, min(1000, (int)$options['retry_jitter_ms']));
        }
        return min(30000, $delay);
    }

    /** @return array<int, int> */
    private function retrySchedule(array $options): array
    {
        $configured = $options['retry_delays_ms'] ?? null;
        if (is_string($configured)) {
            $configured = preg_split('/[\s,，;；]+/u', trim($configured)) ?: [];
        }
        if (is_array($configured) && $configured !== []) {
            $delays = [];
            foreach ($configured as $delay) {
                if (is_numeric($delay)) {
                    $delays[] = max(0, min(30000, (int)$delay));
                }
            }
            if ($delays !== []) {
                return array_values($delays);
            }
        }

        if (array_key_exists('retry_base_delay_ms', $options) || array_key_exists('retry_max_delay_ms', $options)) {
            $baseDelay = max(0, min(5000, (int)($options['retry_base_delay_ms'] ?? self::DEFAULT_RETRY_DELAYS_MS[0])));
            $maxDelay = max($baseDelay, min(30000, (int)($options['retry_max_delay_ms'] ?? self::DEFAULT_RETRY_DELAYS_MS[2])));
            $count = max(1, $this->maxRetries($options));
            $delays = [];
            for ($attempt = 0; $attempt < $count; $attempt++) {
                $delays[] = min($maxDelay, $baseDelay * (2 ** $attempt));
            }
            return $delays;
        }

        return self::DEFAULT_RETRY_DELAYS_MS;
    }

    protected function sleepBeforeRetry(int $attempt, array $options): void
    {
        $delayMs = $this->retryDelayMs($attempt, $options);
        if ($delayMs > 0) {
            $this->sleepMilliseconds($delayMs);
        }
    }

    protected function sleepMilliseconds(int $delayMs): void
    {
        usleep(max(0, $delayMs) * 1000);
    }

    private function schemaGovernanceMeta(array $schema): array
    {
        $meta = $schema['x-governance'] ?? [];
        return is_array($meta) ? $meta : [];
    }

    private function schemaWithoutGovernance(array $schema): array
    {
        unset($schema['x-governance']);
        return $schema;
    }

    private function buildGovernanceMeta(string $prompt, string $modelKey, array $meta = [], array $options = []): array
    {
        $promptHash = hash('sha256', $prompt);
        $promptVersion = trim((string)($meta['prompt_version'] ?? $options['prompt_version'] ?? ''));
        if ($promptVersion === '') {
            $promptVersion = 'adhoc-' . substr($promptHash, 0, 12);
        }

        $threshold = $this->confidenceThreshold($meta, $options);
        $confidence = $this->normalizeConfidence($meta['confidence_score'] ?? $meta['confidence'] ?? null);
        $decisionImpact = strtolower(trim((string)($meta['decision_impact'] ?? $options['decision_impact'] ?? 'none')));
        $knowledgeSources = $this->normalizeKnowledgeSources($meta['knowledge_sources'] ?? $meta['source_refs'] ?? []);
        $evaluationSet = mb_substr(trim((string)($meta['evaluation_set'] ?? $options['evaluation_set'] ?? '')), 0, 120);
        $lowConfidenceReasons = [];
        if ($confidence !== null && $confidence < $threshold) {
            $lowConfidenceReasons[] = 'confidence below threshold';
        }
        if ($confidence === null && in_array($decisionImpact, ['operational', 'investment'], true)) {
            $lowConfidenceReasons[] = 'confidence score missing';
        }
        if (empty($knowledgeSources) && in_array($decisionImpact, ['operational', 'investment'], true)) {
            $lowConfidenceReasons[] = 'source references missing';
        }
        if ($evaluationSet === '' && in_array($decisionImpact, ['operational', 'investment'], true)) {
            $lowConfidenceReasons[] = 'evaluation set missing';
        }
        $providedLowConfidenceReason = mb_substr(trim((string)($meta['low_confidence_reason'] ?? $options['low_confidence_reason'] ?? '')), 0, 200);
        if ($providedLowConfidenceReason !== '' && !in_array($providedLowConfidenceReason, $lowConfidenceReasons, true)) {
            $lowConfidenceReasons[] = $providedLowConfidenceReason;
        }
        $lowConfidence = !empty($lowConfidenceReasons);
        $explicitHumanRequired = $this->normalizeBool($meta['human_confirmation_required'] ?? $options['human_confirmation_required'] ?? null);
        $humanRequired = $explicitHumanRequired || $lowConfidence || in_array($decisionImpact, ['operational', 'investment'], true);
        $humanConfirmationReason = mb_substr(trim((string)($meta['human_confirmation_reason'] ?? $options['human_confirmation_reason'] ?? '')), 0, 200);
        $requestId = trim((string)($meta['request_id'] ?? $options['request_id'] ?? ''));
        if ($requestId === '') {
            $requestId = $this->currentRequestId();
        }

        return [
            'request_id' => $this->normalizeRequestId($requestId),
            'module' => mb_substr(trim((string)($meta['module'] ?? '')), 0, 80),
            'scenario' => mb_substr(trim((string)($meta['scenario'] ?? '')), 0, 120),
            'hotel_id' => max(0, (int)($meta['hotel_id'] ?? 0)),
            'user_id' => max(0, (int)($meta['user_id'] ?? 0)),
            'model_key' => $modelKey,
            'prompt_version' => $promptVersion,
            'prompt_hash' => $promptHash,
            'confidence_score' => $confidence,
            'confidence_threshold' => $threshold,
            'low_confidence' => $lowConfidence,
            'low_confidence_reason' => implode('; ', $lowConfidenceReasons),
            'decision_impact' => $decisionImpact,
            'human_confirmation_required' => $humanRequired,
            'human_confirmation_status' => $humanRequired ? 'pending' : 'not_required',
            'human_confirmation_reason' => $humanConfirmationReason,
            'knowledge_sources' => $knowledgeSources,
            'evaluation_set' => $evaluationSet,
            'eval_case_id' => mb_substr(trim((string)($meta['eval_case_id'] ?? $options['eval_case_id'] ?? '')), 0, 100),
        ];
    }

    private function currentRequestId(): string
    {
        if (!function_exists('request')) {
            return '';
        }
        try {
            $request = request();
            $requestId = trim((string)($request->request_id ?? ''));
            if ($requestId !== '') {
                return $requestId;
            }
            if (method_exists($request, 'header')) {
                return trim((string)$request->header('X-Request-ID', ''));
            }
        } catch (Throwable $e) {
            // CLI jobs and isolated tests may not have an active HTTP request.
        }
        return '';
    }

    private function normalizeRequestId(string $requestId, bool $generate = true): string
    {
        $requestId = trim($requestId);
        if ($requestId !== '') {
            $requestId = preg_replace('/[^A-Za-z0-9._:-]+/', '_', $requestId) ?? '';
            $requestId = trim($requestId, '._:-');
            if ($requestId !== '') {
                return substr($requestId, 0, 64);
            }
        }
        if (!$generate) {
            return '';
        }
        try {
            return 'ai_' . bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            return str_replace('.', '', uniqid('ai_', true));
        }
    }

    protected function finishWithGovernance(
        array $result,
        array $governance,
        array $config,
        string $prompt,
        string $response,
        string $status,
        string $errorType,
        string $errorMessage,
        int $httpStatus,
        int $payloadSize,
        float $startedAt,
        bool $hasConclusion
    ): array {
        try {
            $logId = $this->recordModelCall($governance, $config, $prompt, $response, $status, $errorType, $errorMessage, $httpStatus, $payloadSize, $startedAt);
            $result['data'] = $this->attachGovernanceToData(is_array($result['data'] ?? null) ? $result['data'] : [], $governance, $logId, $status);
            $result['request_id'] = (string)$governance['request_id'];
            return $result;
        } catch (Throwable $e) {
            $message = 'AI治理日志写入失败: ' . $this->sanitize($e->getMessage(), 200);
            if ($hasConclusion) {
                return [
                    'ok' => false,
                    'message' => 'AI治理日志写入失败，已阻断模型结论输出',
                    'code' => 200,
                    'request_id' => (string)$governance['request_id'],
                    'degraded' => true,
                    'degradation_mode' => 'manual',
                    'manual_required' => true,
                    'business_action_allowed' => false,
                    'data' => $this->attachGovernanceToData(
                        $this->debug('governance_log_error', $config, $httpStatus, '', $prompt, $response, $message, [], $payloadSize),
                        $governance,
                        null,
                        'blocked',
                        $message
                    ),
                ];
            }

            $result['data'] = $this->attachGovernanceToData(is_array($result['data'] ?? null) ? $result['data'] : [], $governance, null, $status, $message);
            $result['request_id'] = (string)$governance['request_id'];
            return $result;
        }
    }

    private function recordModelCall(
        array $governance,
        array $config,
        string $prompt,
        string $response,
        string $status,
        string $errorType,
        string $errorMessage,
        int $httpStatus,
        int $payloadSize,
        float $startedAt
    ): int {
        $log = AiModelCallLog::create([
            'request_id' => (string)$governance['request_id'],
            'module' => (string)$governance['module'],
            'scenario' => (string)$governance['scenario'],
            'hotel_id' => (int)$governance['hotel_id'],
            'user_id' => (int)$governance['user_id'],
            'provider' => mb_substr((string)($config['provider'] ?? ''), 0, 50),
            'model_key' => mb_substr((string)($config['model_key'] ?? $governance['model_key'] ?? ''), 0, 100),
            'model_name' => mb_substr((string)($config['model'] ?? ''), 0, 150),
            'prompt_version' => mb_substr((string)$governance['prompt_version'], 0, 120),
            'prompt_hash' => (string)$governance['prompt_hash'],
            'prompt_preview' => $this->sanitize($prompt, 1000),
            'prompt_length' => mb_strlen($prompt),
            'request_payload_size' => $payloadSize,
            'http_status' => $httpStatus,
            'latency_ms' => max(0, (int)round((microtime(true) - $startedAt) * 1000)),
            'status' => $status,
            'error_type' => mb_substr($errorType, 0, 80),
            'error_message' => $errorMessage !== '' ? $this->sanitize($errorMessage, 500) : null,
            'response_hash' => $response !== '' ? hash('sha256', $response) : '',
            'response_preview' => $response !== '' ? $this->sanitize($response, 1000) : null,
            'response_length' => mb_strlen($response),
            'confidence_score' => $governance['confidence_score'],
            'low_confidence' => !empty($governance['low_confidence']) ? 1 : 0,
            'human_confirmation_required' => !empty($governance['human_confirmation_required']) ? 1 : 0,
            'human_confirmation_status' => (string)$governance['human_confirmation_status'],
            'knowledge_sources_json' => $governance['knowledge_sources'],
            'evaluation_set' => (string)$governance['evaluation_set'],
            'eval_case_id' => (string)$governance['eval_case_id'],
            'governance_json' => [
                'decision_impact' => (string)$governance['decision_impact'],
                'confidence_threshold' => (float)$governance['confidence_threshold'],
                'low_confidence_reason' => (string)($governance['low_confidence_reason'] ?? ''),
                'human_confirmation_reason' => (string)($governance['human_confirmation_reason'] ?? ''),
                'knowledge_source_count' => count($governance['knowledge_sources']),
                'evaluation_set' => (string)$governance['evaluation_set'],
                'requested_model_key' => (string)($governance['requested_model_key'] ?? $governance['model_key'] ?? ''),
                'fallback_used' => (bool)($governance['fallback_used'] ?? false),
                'circuit_state' => (string)($governance['circuit_state'] ?? 'closed'),
                'provider_attempts' => is_array($governance['provider_attempts'] ?? null) ? $governance['provider_attempts'] : [],
            ],
        ]);

        return (int)$log->id;
    }

    private function attachGovernanceToData(array $data, array $governance, ?int $logId, string $status, string $logError = ''): array
    {
        $summary = [
            'call_log_id' => $logId,
            'request_id' => (string)$governance['request_id'],
            'status' => $status,
            'prompt_version' => (string)$governance['prompt_version'],
            'knowledge_source_count' => count($governance['knowledge_sources']),
            'confidence_score' => $governance['confidence_score'],
            'confidence_threshold' => (float)$governance['confidence_threshold'],
            'low_confidence' => !empty($governance['low_confidence']),
            'low_confidence_reason' => (string)($governance['low_confidence_reason'] ?? ''),
            'human_confirmation_required' => !empty($governance['human_confirmation_required']),
            'human_confirmation_status' => (string)$governance['human_confirmation_status'],
            'human_confirmation_reason' => (string)($governance['human_confirmation_reason'] ?? ''),
            'evaluation_set' => (string)$governance['evaluation_set'],
            'eval_case_id' => (string)$governance['eval_case_id'],
            'requested_model_key' => (string)($governance['requested_model_key'] ?? $governance['model_key'] ?? ''),
            'fallback_used' => (bool)($governance['fallback_used'] ?? false),
            'circuit_state' => (string)($governance['circuit_state'] ?? 'closed'),
        ];
        if ($logError !== '') {
            $summary['log_error'] = $logError;
        }

        $data['governance'] = $summary;
        if (isset($data['debug']) && is_array($data['debug'])) {
            $data['debug']['governance_call_log_id'] = $logId;
            $data['debug']['prompt_version'] = (string)$governance['prompt_version'];
            $data['debug']['human_confirmation_required'] = !empty($governance['human_confirmation_required']);
        }
        return $data;
    }

    private function updateGovernanceFromJson(array $result, array $data): void
    {
        $callLogId = (int)($result['data']['governance']['call_log_id'] ?? 0);
        if ($callLogId <= 0) {
            return;
        }

        $confidence = $this->extractConfidenceFromArray($data);
        if ($confidence === null) {
            return;
        }

        $threshold = (float)($result['data']['governance']['confidence_threshold'] ?? self::DEFAULT_LOW_CONFIDENCE_THRESHOLD);
        $reasonText = (string)($result['data']['governance']['low_confidence_reason'] ?? '');
        $lowConfidenceReasons = array_values(array_filter(
            array_map('trim', explode(';', $reasonText)),
            static fn(string $reason): bool => $reason !== '' && !in_array($reason, ['confidence score missing', 'confidence below threshold'], true)
        ));
        if ($confidence < $threshold) {
            $lowConfidenceReasons[] = 'confidence below threshold';
        }
        $lowConfidence = !empty($lowConfidenceReasons);
        try {
            $update = [
                'confidence_score' => $confidence,
                'low_confidence' => $lowConfidence ? 1 : 0,
                'human_confirmation_required' => $lowConfidence ? 1 : (int)($result['data']['governance']['human_confirmation_required'] ?? 0),
                'human_confirmation_status' => $lowConfidence ? 'pending' : (string)($result['data']['governance']['human_confirmation_status'] ?? 'not_required'),
            ];
            $log = AiModelCallLog::where('id', $callLogId)->find();
            if ($log) {
                $governanceJson = is_array($log->governance_json ?? null) ? $log->governance_json : [];
                $governanceJson['low_confidence_reason'] = implode('; ', $lowConfidenceReasons);
                $update['governance_json'] = $governanceJson;
                $log->save($update);
                return;
            }
            AiModelCallLog::where('id', $callLogId)->update($update);
        } catch (Throwable $e) {
            // 调用日志已存在，置信度回填失败不影响已记录的模型调用审计。
        }
    }

    private function extractConfidenceFromArray(array $data): ?float
    {
        foreach (['confidence_score', 'confidence', 'confidenceScore'] as $key) {
            if (array_key_exists($key, $data)) {
                return $this->normalizeConfidence($data[$key]);
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $nested = $this->extractConfidenceFromArray($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }
        return null;
    }

    private function normalizeKnowledgeSources(mixed $sources): array
    {
        if (is_string($sources) && trim($sources) !== '') {
            $sources = preg_split('/[\r\n,，;；]+/u', $sources) ?: [];
        }
        if (!is_array($sources)) {
            return [];
        }

        $normalized = [];
        foreach ($sources as $source) {
            if (is_string($source)) {
                $ref = mb_substr(trim($source), 0, 160);
                if ($ref !== '') {
                    $normalized[] = ['ref' => $ref];
                }
                continue;
            }
            if (!is_array($source)) {
                continue;
            }

            $item = [];
            foreach (['ref', 'table', 'record_id', 'date', 'label', 'title', 'source', 'url'] as $key) {
                if (!array_key_exists($key, $source)) {
                    continue;
                }
                $value = $source[$key];
                if (is_scalar($value) || $value === null) {
                    $item[$key] = is_string($value) ? mb_substr(trim($value), 0, 200) : $value;
                }
            }
            if (!empty($item)) {
                $normalized[] = $item;
            }
            if (count($normalized) >= 30) {
                break;
            }
        }

        return $normalized;
    }

    private function confidenceThreshold(array $meta, array $options): float
    {
        $default = self::DEFAULT_LOW_CONFIDENCE_THRESHOLD;
        if (function_exists('env')) {
            try {
                $default = (float)env('AI_LOW_CONFIDENCE_THRESHOLD', self::DEFAULT_LOW_CONFIDENCE_THRESHOLD);
            } catch (Throwable $e) {
                $default = self::DEFAULT_LOW_CONFIDENCE_THRESHOLD;
            }
        }
        $threshold = $this->normalizeConfidence($meta['confidence_threshold'] ?? $options['confidence_threshold'] ?? $default);
        return $threshold ?? self::DEFAULT_LOW_CONFIDENCE_THRESHOLD;
    }

    private function normalizeConfidence(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = trim(str_replace('%', '', $value));
        }
        if (!is_numeric($value)) {
            return null;
        }
        $score = (float)$value;
        if ($score > 1 && $score <= 100) {
            $score /= 100;
        }
        return max(0.0, min(1.0, round($score, 3)));
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    private function httpStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/HTTP\/\d+(?:\.\d+)?\s+(\d+)/', (string)$header, $matches)) {
                return (int)$matches[1];
            }
        }
        return 0;
    }

    private function debug(string $type, array $config, int $httpStatus, string $curlError, string $prompt, string $response, string $message, array $meta = [], int $payloadSize = 0): array
    {
        return [
            'error_type' => $type,
            'debug' => [
                'provider' => (string)($config['provider'] ?? ''),
                'model_key' => (string)($config['model_key'] ?? ''),
                'model' => (string)($config['model'] ?? ''),
                'model_name' => (string)($config['model'] ?? ''),
                'config_source' => (string)($config['source'] ?? 'database'),
                'http_status' => $httpStatus,
                'curl_errno' => 0,
                'curl_error' => $this->sanitize($curlError),
                'error_message' => $this->sanitize($message),
                'selected_hotel_count' => (int)($meta['selected_hotel_count'] ?? 0),
                'request_payload_size' => $payloadSize,
                'prompt_length' => (int)($meta['prompt_length'] ?? mb_strlen($prompt)),
                'response_preview' => $this->sanitize($response, 500),
                'retry_attempts' => (int)($meta['retry_attempts'] ?? 0),
                'max_retries' => (int)($meta['max_retries'] ?? 0),
                'retry_delays_ms' => is_array($meta['retry_delays_ms'] ?? null) ? $meta['retry_delays_ms'] : [],
                'retryable' => (bool)($meta['retryable'] ?? false),
                'retry_reason' => (string)($meta['retry_reason'] ?? ''),
                'retry_exhausted' => (bool)($meta['retry_exhausted'] ?? false),
                'terminal_failure' => (bool)($meta['terminal_failure'] ?? false),
                'failure_state' => (string)($meta['failure_state'] ?? ''),
                'request_id' => mb_substr(trim((string)($meta['request_id'] ?? '')), 0, 64),
                'circuit_state' => (string)($meta['circuit_state'] ?? ''),
            ],
        ];
    }

    private function sanitize(string $message, int $limit = 300): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }
        $message = preg_replace('/\bauthorization\s*[:=]\s*(?:Bearer|Basic)?\s*[^,\s;]+/i', 'authorization=****', $message) ?? $message;
        $message = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 ****', $message) ?? $message;
        $message = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', 'sk-****', $message) ?? $message;
        $message = preg_replace('/\b(api[_-]?key|cookie|set-cookie|spidertoken|access[_-]?token|refresh[_-]?token|session[_-]?id|session|password|secret|[a-z0-9_.-]*token[a-z0-9_.-]*)\s*[:=]\s*[^,\s;]+/i', '$1=****', $message) ?? $message;
        return mb_substr($message, 0, $limit);
    }
}
