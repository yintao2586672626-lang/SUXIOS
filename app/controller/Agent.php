<?php
declare(strict_types=1);

namespace app\controller;

use app\model\AgentConfig;
use app\model\AgentLog;
use app\model\AgentTask;
use app\model\KnowledgeBase;
use app\model\KnowledgeCategory;
use app\model\PriceSuggestion;
use app\model\RoomType;
use app\model\EnergyConsumption;
use app\model\Device;
use app\model\DeviceCategory;
use app\model\DeviceMaintenance;
use app\model\DemandForecast;
use app\model\CompetitorAnalysis;
use app\model\AgentWorkOrder;
use app\model\AgentConversation;
use app\model\EnergyBenchmark;
use app\model\EnergySavingSuggestion;
use app\model\MaintenancePlan;
use app\model\OperationLog;
use app\model\AiModelConfig;
use app\service\FeasibilityReportService;
use think\Response;
use think\facade\Db;
use think\facade\Log;

/**
 * Agent控制器
 * 管理三个AI Agent的功能：智能员工、收益管理、资产运维
 */
class Agent extends Base
{
    private function feasibilityService(): FeasibilityReportService
    {
        return new FeasibilityReportService();
    }

    private function callLlm(string $prompt, string $modelKey = 'deepseek_v4_default', array $meta = [], array $options = []): array
    {
        $modelKey = $this->normalizeRequestedModelKey($modelKey, $options);

        $config = $this->getLlmConfigByModelKey($modelKey);
        if (($config['ok'] ?? false) !== true) {
            $config['data'] = $this->buildLlmDebug('config_error', $config, 0, '', $prompt, '', (string) ($config['message'] ?? ''), $meta);
            return $config;
        }

        $apiKey = (string) $config['api_key'];
        $baseUrl = rtrim((string) $config['base_url'], '/');

        $payload = [
            'model' => $config['model'],
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ]),
                'content' => $payloadJson,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($baseUrl . '/chat/completions', false, $context);
        if ($response === false) {
            $error = error_get_last();
            $curlError = $this->sanitizeLlmErrorMessage((string) ($error['message'] ?? '网络请求失败'));
            return [
                'ok' => false,
                'message' => '网络请求失败',
                'code' => 502,
                'data' => $this->buildLlmDebug('curl_error', $config, 0, $curlError, $prompt, '', $curlError, $meta, strlen((string) $payloadJson)),
            ];
        }

        $headers = $http_response_header ?? [];
        $statusCode = 0;
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
            $statusCode = (int) $matches[1];
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? $errorData['message'] ?? '';
            $errorMessage = $this->sanitizeLlmErrorMessage((string) $errorMessage);
            return [
                'ok' => false,
                'message' => $errorMessage !== '' ? '模型返回异常: ' . $errorMessage : '模型返回异常',
                'code' => 502,
                'data' => $this->buildLlmDebug('http_error', $config, $statusCode, '', $prompt, $response, $errorMessage, $meta, strlen((string) $payloadJson)),
            ];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return [
                'ok' => false,
                'message' => '模型返回异常',
                'code' => 502,
                'data' => $this->buildLlmDebug('invalid_response', $config, $statusCode, '', $prompt, $response, '模型响应不是 JSON', $meta, strlen((string) $payloadJson)),
            ];
        }
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            $errorMessage = $data['error']['message'] ?? $data['message'] ?? '';
            $errorMessage = $this->sanitizeLlmErrorMessage((string) $errorMessage);
            return [
                'ok' => false,
                'message' => $errorMessage !== '' ? '模型返回异常: ' . $errorMessage : '模型返回异常',
                'code' => 502,
                'data' => $this->buildLlmDebug('empty_content', $config, $statusCode, '', $prompt, $response, $errorMessage, $meta, strlen((string) $payloadJson)),
            ];
        }

        return ['ok' => true, 'content' => trim($content)];
    }

    private function callDeepSeek(array $messages, array $options = []): array
    {
        $startedAt = microtime(true);
        $modelKey = (string) ($options['model_key'] ?? 'deepseek_v4_default');
        $modelMode = $this->normalizeDeepSeekModelMode(
            isset($options['model_mode']) ? (string) $options['model_mode'] : null,
            $modelKey
        );
        $model = $this->resolveDeepSeekModel($modelMode);
        $baseUrl = rtrim(trim((string) (config('llm.deepseek.base_url') ?: env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'))), '/');
        $apiKey = trim((string) (config('llm.deepseek.api_key') ?: env('DEEPSEEK_API_KEY', '')));
        $timeout = max(1, (int) (config('llm.deepseek.timeout') ?: env('DEEPSEEK_TIMEOUT', 60)));
        $temperature = (float) ($options['temperature'] ?? 0.3);
        $maxTokens = max(1, (int) ($options['max_tokens'] ?? 2000));
        $meta = is_array($options['meta'] ?? null) ? $options['meta'] : [];
        $messages = $this->normalizeDeepSeekMessages($messages);
        $promptLength = (int) ($options['prompt_length'] ?? $this->messagesContentLength($messages));
        $meta['prompt_length'] = $promptLength;

        $dbConfig = $this->getDeepSeekDatabaseConfig($modelKey);
        if ($dbConfig !== null) {
            if (($dbConfig['ok'] ?? false) !== true) {
                $dbConfig['data'] = $this->buildLlmDebug('config_error', $dbConfig, 0, '', '', '', (string) ($dbConfig['message'] ?? ''), $meta);
                return $dbConfig;
            }

            $baseUrl = rtrim((string) $dbConfig['base_url'], '/');
            $apiKey = (string) $dbConfig['api_key'];
            $dbModel = trim((string) ($dbConfig['model'] ?? ''));
            if ($modelMode === null && $dbModel !== '' && !in_array($dbModel, ['deepseek-chat', 'deepseek-reasoner'], true)) {
                $model = $dbModel;
            }
        }

        $config = [
            'provider' => 'deepseek',
            'model_key' => $modelKey,
            'model' => $model,
            'base_url' => $baseUrl,
        ];

        if ($apiKey === '') {
            $message = '未配置 DEEPSEEK_API_KEY';
            $this->logDeepSeekCall($model, (string) $modelMode, $startedAt, false, $message, $promptLength, 0);
            return [
                'ok' => false,
                'message' => $message,
                'code' => 400,
                'data' => $this->buildLlmDebug('config_error', $config, 0, '', '', '', $message, $meta),
            ];
        }
        if ($baseUrl === '') {
            $message = '未配置 DEEPSEEK_BASE_URL';
            $this->logDeepSeekCall($model, (string) $modelMode, $startedAt, false, $message, $promptLength, 0);
            return [
                'ok' => false,
                'message' => $message,
                'code' => 400,
                'data' => $this->buildLlmDebug('config_error', $config, 0, '', '', '', $message, $meta),
            ];
        }
        if (empty($messages)) {
            $message = 'DeepSeek messages 不能为空';
            $this->logDeepSeekCall($model, (string) $modelMode, $startedAt, false, $message, 0, 0);
            return [
                'ok' => false,
                'message' => $message,
                'code' => 422,
                'data' => $this->buildLlmDebug('invalid_messages', $config, 0, '', '', '', $message, $meta),
            ];
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson)) {
            $message = 'DeepSeek 请求参数 JSON 编码失败';
            $this->logDeepSeekCall($model, (string) $modelMode, $startedAt, false, $message, $promptLength, 0);
            return [
                'ok' => false,
                'message' => $message,
                'code' => 500,
                'data' => $this->buildLlmDebug('json_encode_error', $config, 0, '', '', '', $message, $meta),
            ];
        }

        if (!function_exists('curl_init')) {
            $message = 'PHP curl 扩展不可用';
            $this->logDeepSeekCall($model, (string) $modelMode, $startedAt, false, $message, $promptLength, strlen($payloadJson));
            return [
                'ok' => false,
                'message' => $message,
                'code' => 500,
                'data' => $this->buildLlmDebug('curl_unavailable', $config, 0, '', '', '', $message, $meta, strlen($payloadJson)),
            ];
        }

        $ch = curl_init($baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        ]);

        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = $this->sanitizeLlmErrorMessage((string) curl_error($ch));
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false || $curlErrno !== 0) {
            $message = $curlError !== '' ? 'DeepSeek 请求失败: ' . $curlError : 'DeepSeek 请求失败';
            $this->logDeepSeekCall($model, (string) $modelMode, $startedAt, false, $message, $promptLength, strlen($payloadJson));
            return [
                'ok' => false,
                'message' => $message,
                'code' => 502,
                'data' => $this->buildLlmDebug('curl_error', $config, $statusCode, $curlError, '', '', $message, $meta, strlen($payloadJson)),
            ];
        }

        $responseText = (string) $response;
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorData = json_decode($responseText, true);
            $errorMessage = is_array($errorData) ? (string) ($errorData['error']['message'] ?? $errorData['message'] ?? '') : '';
            $errorMessage = $this->sanitizeLlmErrorMessage($errorMessage);
            $message = $errorMessage !== '' ? 'DeepSeek 返回异常: ' . $errorMessage : 'DeepSeek 返回异常';
            $this->logDeepSeekCall($model, (string) $modelMode, $startedAt, false, $message, $promptLength, strlen($payloadJson));
            return [
                'ok' => false,
                'message' => $message,
                'code' => 502,
                'data' => $this->buildLlmDebug('http_error', $config, $statusCode, '', '', $responseText, $errorMessage, $meta, strlen($payloadJson)),
            ];
        }

        $data = json_decode($responseText, true);
        if (!is_array($data)) {
            $message = 'DeepSeek 返回异常';
            $this->logDeepSeekCall($model, (string) $modelMode, $startedAt, false, 'invalid json response', $promptLength, strlen($payloadJson));
            return [
                'ok' => false,
                'message' => $message,
                'code' => 502,
                'data' => $this->buildLlmDebug('invalid_response', $config, $statusCode, '', '', $responseText, 'DeepSeek 响应不是 JSON', $meta, strlen($payloadJson)),
            ];
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            $errorMessage = $this->sanitizeLlmErrorMessage((string) ($data['error']['message'] ?? $data['message'] ?? ''));
            $message = $errorMessage !== '' ? 'DeepSeek 返回异常: ' . $errorMessage : 'DeepSeek 返回异常';
            $this->logDeepSeekCall($model, (string) $modelMode, $startedAt, false, $message, $promptLength, strlen($payloadJson));
            return [
                'ok' => false,
                'message' => $message,
                'code' => 502,
                'data' => $this->buildLlmDebug('empty_content', $config, $statusCode, '', '', $responseText, $errorMessage, $meta, strlen($payloadJson)),
            ];
        }

        $this->logDeepSeekCall($model, (string) $modelMode, $startedAt, true, '', $promptLength, strlen($payloadJson));
        return ['ok' => true, 'content' => trim($content)];
    }

    private function shouldUseDeepSeek(string $modelKey, array $options): bool
    {
        if (array_key_exists('model_mode', $options) && trim((string) $options['model_mode']) !== '') {
            return true;
        }

        return in_array($modelKey, [
            'deepseek_chat',
            'deepseek_reasoner',
            'deepseek_v4_default',
            'deepseek_v4_flash',
            'deepseek_v4_fast',
            'deepseek_v4_pro',
        ], true);
    }

    private function normalizeRequestedModelKey(string $modelKey, array $options = []): string
    {
        $key = trim($modelKey);
        $mode = strtolower(trim((string) ($options['model_mode'] ?? '')));
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

    private function resolveDeepSeekModel(?string $modelMode): string
    {
        $mode = strtolower(trim((string) $modelMode));
        if ($mode === 'pro') {
            $model = trim((string) env('DEEPSEEK_PRO_MODEL', 'deepseek-v4-pro'));
            return $model !== '' ? $model : 'deepseek-v4-pro';
        }
        if ($mode === 'fast') {
            $model = trim((string) env('DEEPSEEK_FAST_MODEL', 'deepseek-v4-flash'));
            return $model !== '' ? $model : 'deepseek-v4-flash';
        }

        $model = trim((string) env('DEEPSEEK_DEFAULT_MODEL', 'deepseek-v4-flash'));
        return $model !== '' ? $model : 'deepseek-v4-flash';
    }

    private function normalizeDeepSeekModelMode(?string $modelMode, string $modelKey): ?string
    {
        $mode = strtolower(trim((string) $modelMode));
        if (in_array($mode, ['fast', 'pro', 'auto', 'default'], true)) {
            return $mode;
        }

        if (in_array($modelKey, ['deepseek_reasoner', 'deepseek_v4_pro'], true)) {
            return 'pro';
        }
        if (in_array($modelKey, ['deepseek_chat', 'deepseek_v4_flash', 'deepseek_v4_fast'], true)) {
            return 'fast';
        }

        return null;
    }

    private function normalizeDeepSeekMessages(array $messages): array
    {
        $safe = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = strtolower(trim((string) ($message['role'] ?? 'user')));
            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $safe[] = ['role' => $role, 'content' => $content];
        }

        return $safe;
    }

    private function messagesContentLength(array $messages): int
    {
        $length = 0;
        foreach ($messages as $message) {
            if (is_array($message)) {
                $length += mb_strlen((string) ($message['content'] ?? ''));
            }
        }
        return $length;
    }

    private function logDeepSeekCall(string $model, string $modelMode, float $startedAt, bool $success, string $errorSummary, int $promptLength, int $payloadSize): void
    {
        try {
            $context = [
                'model' => $model,
                'model_mode' => $modelMode,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'success' => $success,
                'error_summary' => $this->sanitizeLlmErrorMessage($errorSummary, 180),
                'prompt_length' => $promptLength,
                'request_payload_size' => $payloadSize,
            ];

            if ($success) {
                Log::info('deepseek_llm_call', $context);
            } else {
                Log::warning('deepseek_llm_call', $context);
            }
        } catch (\Throwable $e) {
            // Logging must not affect Agent responses.
        }
    }

    private function buildLlmDebug(string $errorType, array $config, int $httpStatus, string $curlError, string $prompt, string $response, string $errorMessage, array $meta = [], int $payloadSize = 0): array
    {
        return [
            'error_type' => $errorType,
            'debug' => [
                'provider' => (string) ($config['provider'] ?? ''),
                'model_key' => (string) ($config['model_key'] ?? ''),
                'model' => (string) ($config['model'] ?? ''),
                'config_source' => (string) ($config['source'] ?? ''),
                'http_status' => $httpStatus,
                'curl_errno' => 0,
                'curl_error' => $this->sanitizeLlmErrorMessage($curlError),
                'error_message' => $this->sanitizeLlmErrorMessage($errorMessage),
                'selected_hotel_count' => (int) ($meta['selected_hotel_count'] ?? 0),
                'request_payload_size' => $payloadSize,
                'prompt_length' => (int) ($meta['prompt_length'] ?? mb_strlen($prompt)),
                'response_preview' => $this->safeResponsePreview($response),
            ],
        ];
    }

    private function safeResponsePreview(string $response): string
    {
        if ($response === '') {
            return '';
        }
        return $this->sanitizeLlmErrorMessage($response, 500);
    }

    private function sanitizeLlmErrorMessage(string $message, int $limit = 300): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }
        $message = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', 'sk-****', $message);
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ****', $message);
        $message = preg_replace('/(api[_-]?key|authorization|cookie|spidertoken)\s*[:=]\s*[^,\s;]+/i', '$1=****', $message);
        return mb_substr((string) $message, 0, $limit);
    }

    private function getLlmConfigByModelKey(string $modelKey): array
    {
        $modelKey = $this->normalizeRequestedModelKey($modelKey);

        $dbConfig = $this->getDatabaseLlmConfigByModelKey($modelKey);
        if ($dbConfig !== null) {
            return $dbConfig;
        }

        $aliasKey = $this->legacyDeepSeekModelKeyAlias($modelKey);
        if ($aliasKey !== $modelKey) {
            $aliasDbConfig = $this->getDatabaseLlmConfigByModelKey($aliasKey);
            if ($aliasDbConfig !== null) {
                return $aliasDbConfig;
            }
            $modelKey = $aliasKey;
        }

        return $this->getEnvLlmConfigByModelKey($modelKey);
    }

    private function legacyDeepSeekModelKeyAlias(string $modelKey): string
    {
        return [
            'deepseek_v4_flash' => 'deepseek_chat',
            'deepseek_v4_fast' => 'deepseek_chat',
            'deepseek_v4_pro' => 'deepseek_reasoner',
        ][$modelKey] ?? $modelKey;
    }

    private function getDatabaseLlmConfigByModelKey(string $modelKey): ?array
    {
        try {
            $config = AiModelConfig::where('model_key', $modelKey)->find();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$config) {
            return null;
        }

        if ((int) $config->is_enabled !== 1) {
            return [
                'ok' => false,
                'message' => '未找到启用的模型配置：' . $modelKey . '，请先到系统设置 > AI模型配置中配置',
                'code' => 400,
                'provider' => (string) $config->provider,
                'model_key' => $modelKey,
                'model' => (string) $config->model_name,
                'source' => 'database',
            ];
        }

        $baseUrl = rtrim(trim((string) $config->base_url), '/');
        $modelName = trim((string) $config->model_name);
        $errorConfig = [
            'provider' => (string) $config->provider,
            'model_key' => $modelKey,
            'model' => $modelName,
            'source' => 'database',
        ];
        if ($baseUrl === '') {
            return array_merge(['ok' => false, 'message' => '未配置模型 base_url', 'code' => 400], $errorConfig);
        }
        if ($modelName === '') {
            return array_merge(['ok' => false, 'message' => '未配置模型名称', 'code' => 400], $errorConfig);
        }

        if (trim((string) $config->api_key_encrypted) === '') {
            return array_merge(['ok' => false, 'message' => '未配置模型 API Key', 'code' => 400], $errorConfig);
        }

        $secret = trim((string) env('AI_CONFIG_SECRET', ''));
        if ($secret === '') {
            return array_merge(['ok' => false, 'message' => '未配置 AI_CONFIG_SECRET', 'code' => 400], $errorConfig);
        }

        $apiKey = AiModelConfig::decryptApiKey((string) $config->api_key_encrypted, $secret);
        if ($apiKey === null) {
            return array_merge(['ok' => false, 'message' => '模型 API Key 解密失败', 'code' => 400], $errorConfig);
        }

        return [
            'ok' => true,
            'provider' => (string) $config->provider,
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'model' => $modelName,
            'model_key' => $modelKey,
            'source' => 'database',
        ];
    }

    private function getEnvLlmConfigByModelKey(string $modelKey): array
    {
        $configs = [
            'deepseek_v4_default' => [
                'provider' => 'deepseek',
                'base_url' => rtrim(trim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com')), '/'),
                'api_key' => trim((string) env('DEEPSEEK_API_KEY', '')),
                'model' => $this->resolveDeepSeekModel(null),
            ],
            'deepseek_v4_flash' => [
                'provider' => 'deepseek',
                'base_url' => rtrim(trim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com')), '/'),
                'api_key' => trim((string) env('DEEPSEEK_API_KEY', '')),
                'model' => $this->resolveDeepSeekModel('fast'),
            ],
            'deepseek_v4_fast' => [
                'provider' => 'deepseek',
                'base_url' => rtrim(trim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com')), '/'),
                'api_key' => trim((string) env('DEEPSEEK_API_KEY', '')),
                'model' => $this->resolveDeepSeekModel('fast'),
            ],
            'deepseek_v4_pro' => [
                'provider' => 'deepseek',
                'base_url' => rtrim(trim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com')), '/'),
                'api_key' => trim((string) env('DEEPSEEK_API_KEY', '')),
                'model' => $this->resolveDeepSeekModel('pro'),
            ],
            'deepseek_chat' => [
                'provider' => 'deepseek',
                'base_url' => rtrim(trim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com')), '/'),
                'api_key' => trim((string) env('DEEPSEEK_API_KEY', '')),
                'model' => $this->resolveDeepSeekModel('fast'),
            ],
            'deepseek_reasoner' => [
                'provider' => 'deepseek',
                'base_url' => rtrim(trim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com')), '/'),
                'api_key' => trim((string) env('DEEPSEEK_API_KEY', '')),
                'model' => $this->resolveDeepSeekModel('pro'),
            ],
            'openai_fast' => [
                'provider' => 'openai',
                'base_url' => rtrim(trim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1')), '/'),
                'api_key' => trim((string) env('OPENAI_API_KEY', '')),
                'model' => trim((string) env('OPENAI_MODEL', '')),
            ],
        ];

        if (!isset($configs[$modelKey])) {
            return [
                'ok' => false,
                'message' => '未找到启用的模型配置：' . $modelKey . '，请先到系统设置 > AI模型配置中配置',
                'code' => 422,
                'model_key' => $modelKey,
                'source' => 'env',
            ];
        }

        $config = $configs[$modelKey];
        if ($config['api_key'] === '') {
            $envName = $config['provider'] === 'deepseek' ? 'DEEPSEEK_API_KEY' : 'OPENAI_API_KEY';
            return [
                'ok' => false,
                'message' => '未配置 ' . $envName,
                'code' => 400,
                'provider' => $config['provider'],
                'model_key' => $modelKey,
                'model' => $config['model'],
                'source' => 'env',
            ];
        }
        if ($config['base_url'] === '') {
            $envName = $config['provider'] === 'deepseek' ? 'DEEPSEEK_BASE_URL' : 'OPENAI_BASE_URL';
            return [
                'ok' => false,
                'message' => '未配置 ' . $envName,
                'code' => 400,
                'provider' => $config['provider'],
                'model_key' => $modelKey,
                'model' => $config['model'],
                'source' => 'env',
            ];
        }
        if ($config['model'] === '') {
            return [
                'ok' => false,
                'message' => '未配置 OPENAI_MODEL',
                'code' => 400,
                'provider' => $config['provider'],
                'model_key' => $modelKey,
                'model' => '',
                'source' => 'env',
            ];
        }

        $config['ok'] = true;
        $config['model_key'] = $modelKey;
        $config['source'] = 'env';
        return $config;
    }

    private function isAllowedLlmModelKey(string $modelKey): bool
    {
        $modelKey = $this->normalizeRequestedModelKey($modelKey);
        if (in_array($modelKey, ['deepseek_chat', 'deepseek_reasoner', 'deepseek_v4_default', 'deepseek_v4_flash', 'deepseek_v4_fast', 'deepseek_v4_pro', 'openai_fast'], true)) {
            return true;
        }

        try {
            return AiModelConfig::where('model_key', $modelKey)->find() !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 检查管理员权限
     */
    protected function checkAdmin(): void
    {
        if (!$this->currentUser || !$this->currentUser->isSuperAdmin()) {
            abort(403, '只有超级管理员可以访问Agent功能');
        }
    }

    // ==================== Agent概览 ====================

    /**
     * 获取Agent概览数据
     */
    public function overview(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 获取三个Agent的状态
        $agentConfigs = AgentConfig::where('hotel_id', $hotelId)
            ->column('agent_type, is_enabled', 'agent_type');
        
        // 获取今日任务统计
        $todayTasks = AgentTask::where('hotel_id', $hotelId)
            ->whereDay('create_time', date('Y-m-d'))
            ->field('agent_type, status, COUNT(*) as count')
            ->group('agent_type, status')
            ->select();
        
        $taskStats = [];
        foreach ($todayTasks as $task) {
            $type = $task['agent_type'];
            $status = $task['status'];
            if (!isset($taskStats[$type])) {
                $taskStats[$type] = [
                    'total' => 0,
                    'pending' => 0,
                    'running' => 0,
                    'completed' => 0,
                    'failed' => 0,
                ];
            }
            $taskStats[$type]['total'] += $task['count'];
            if ($status == AgentTask::STATUS_PENDING) {
                $taskStats[$type]['pending'] = $task['count'];
            } elseif ($status == AgentTask::STATUS_RUNNING) {
                $taskStats[$type]['running'] = $task['count'];
            } elseif ($status == AgentTask::STATUS_COMPLETED) {
                $taskStats[$type]['completed'] = $task['count'];
            } elseif ($status == AgentTask::STATUS_FAILED) {
                $taskStats[$type]['failed'] = $task['count'];
            }
        }
        
        // 获取最近日志
        $recentLogs = AgentLog::where('hotel_id', $hotelId)
            ->order('id', 'desc')
            ->limit(10)
            ->select();
        
        return $this->success([
            'agents' => [
                'staff' => [
                    'name' => '智能员工Agent',
                    'type' => AgentConfig::AGENT_TYPE_STAFF,
                    'enabled' => ($agentConfigs[AgentConfig::AGENT_TYPE_STAFF]['is_enabled'] ?? 0) == 1,
                    'tasks' => $taskStats[AgentConfig::AGENT_TYPE_STAFF] ?? ['total' => 0, 'pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0],
                    'icon' => '👥',
                    'description' => '前台客服、工单处理、知识库问答',
                ],
                'revenue' => [
                    'name' => '收益管理Agent',
                    'type' => AgentConfig::AGENT_TYPE_REVENUE,
                    'enabled' => ($agentConfigs[AgentConfig::AGENT_TYPE_REVENUE]['is_enabled'] ?? 0) == 1,
                    'tasks' => $taskStats[AgentConfig::AGENT_TYPE_REVENUE] ?? ['total' => 0, 'pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0],
                    'icon' => '💰',
                    'description' => '竞对价格监控、定价建议、需求预测',
                ],
                'asset' => [
                    'name' => '资产运维Agent',
                    'type' => AgentConfig::AGENT_TYPE_ASSET,
                    'enabled' => ($agentConfigs[AgentConfig::AGENT_TYPE_ASSET]['is_enabled'] ?? 0) == 1,
                    'tasks' => $taskStats[AgentConfig::AGENT_TYPE_ASSET] ?? ['total' => 0, 'pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0],
                    'icon' => '🔧',
                    'description' => '能耗监控、设备维护预警',
                ],
            ],
            'recent_logs' => $recentLogs,
        ]);
    }

    public function testLlm(): Response
    {
        $this->checkAdmin();

        $prompt = trim((string) $this->request->param('prompt', ''));
        if ($prompt === '') {
            $prompt = '请用一句话说明你已接入宿析OS';
        }

        $modelKey = trim((string) $this->request->param('model_key', 'deepseek_v4_default'));
        $modelMode = $this->request->param('model_mode', null);
        $modelOptions = $modelMode !== null && trim((string) $modelMode) !== '' ? ['model_mode' => $modelMode] : [];
        $result = $this->callLlm($prompt, $modelKey, [], $modelOptions);
        if (($result['ok'] ?? false) !== true) {
            return $this->error((string) $result['message'], (int) $result['code']);
        }

        return $this->success(['content' => $result['content']], 'success');
    }

    public function otaDiagnosis(): Response
    {
        $this->checkAdmin();

        $hotelIdRaw = trim((string) $this->request->param('hotel_id', ''));
        $hotelId = (int) $hotelIdRaw;
        $configId = trim((string) $this->request->param('config_id', ''));
        $hotelName = trim((string) $this->request->param('hotel_name', ''));
        $platform = strtolower(trim((string) $this->request->param('platform', 'ctrip')));
        $startDate = trim((string) $this->request->param('start_date', ''));
        $endDate = trim((string) $this->request->param('end_date', ''));
        $analysisType = strtolower(trim((string) $this->request->param('analysis_type', 'traffic')));
        $modelKey = trim((string) $this->request->param('model_key', 'deepseek_v4_default'));
        $modelMode = $this->request->param('model_mode', null);
        $modelOptions = $modelMode !== null && trim((string) $modelMode) !== '' ? ['model_mode' => $modelMode] : [];
        if ($modelKey === '') {
            $modelKey = 'deepseek_v4_default';
        }

        if (!$this->isAllowedLlmModelKey($modelKey)) {
            return $this->error('未找到启用的模型配置：' . $modelKey . '，请先到系统设置 > AI模型配置中配置', 422);
        }
        if (!in_array($platform, ['ctrip', 'meituan', 'qunar'], true)) {
            return $this->error('platform 仅支持 ctrip、meituan、qunar', 422);
        }
        if (!$this->isDateString($startDate) || !$this->isDateString($endDate)) {
            return $this->error('start_date 和 end_date 必须为 YYYY-MM-DD', 422);
        }
        if (strtotime($startDate) > strtotime($endDate)) {
            return $this->error('start_date 不能晚于 end_date', 422);
        }
        if (!in_array($analysisType, ['traffic', 'business', 'all'], true)) {
            return $this->error('analysis_type 仅支持 traffic、business、all', 422);
        }

        try {
            if ($hotelIdRaw === '' && $configId !== '') {
                $config = $this->resolveOtaDiagnosisConfig($platform, $configId);
                if (!empty($config)) {
                    $hotelId = (int) ($config['hotel_id'] ?? $hotelId);
                    $hotelIdRaw = (string) ($config['hotel_id'] ?? $hotelIdRaw);
                    $hotelName = trim((string) ($config['hotel_name'] ?? $hotelName));
                }
            }
            if ($hotelIdRaw === '') {
                return $this->error('请选择有效的酒店配置，诊断必须包含 hotel_id', 422);
            }

            $dataSet = $this->queryOtaDiagnosisData($hotelId, $hotelIdRaw, $platform, $startDate, $endDate, $analysisType);
            if (!$this->hasOtaDiagnosisData($dataSet)) {
                return $this->success([
                    'hotel' => $dataSet['hotel'] ?? ['id' => $hotelIdRaw, 'name' => $hotelName],
                    'platform' => $platform,
                    'date_range' => ['start_date' => $startDate, 'end_date' => $endDate],
                    'data_summary' => [
                        'has_ota_data' => false,
                        'has_traffic_data' => false,
                        'has_competitor_data' => false,
                        'has_comment_data' => false,
                        'last_sync_time' => $dataSet['last_sync_time'] ?? '',
                    ],
                    'metrics' => [],
                    'diagnosis' => [
                        'summary' => '暂无该酒店在该日期范围内的OTA数据，请先同步/抓取数据。',
                        'exposure_analysis' => '',
                        'visit_conversion_analysis' => '',
                        'order_conversion_analysis' => '',
                        'price_analysis' => '',
                        'competitor_analysis' => '',
                        'comment_analysis' => '',
                        'actions' => [],
                    ],
                    'missing_sections' => ['OTA历史数据'],
                    'core_conclusion' => '暂无该酒店在该日期范围内的OTA数据，请先同步/抓取数据。',
                    'priority' => 'none',
                ], '暂无 OTA 数据');
            }

            $result = $this->buildOtaDiagnosisResult($dataSet, $hotelId, $hotelIdRaw, $hotelName, $platform, $startDate, $endDate, $analysisType);
            $llmResult = $this->callLlm($this->buildOtaDiagnosisPrompt($result), $modelKey, [], $modelOptions);
            if (($llmResult['ok'] ?? false) === true) {
                $result['diagnosis'] = array_merge($result['diagnosis'], $this->parseOtaDiagnosisResult((string) $llmResult['content']));
            } else {
                $result['missing_sections'][] = 'AI模型诊断';
                $result['diagnosis']['actions'][] = '模型诊断暂不可用，已基于系统历史数据生成基础诊断。';
            }

            $result['core_conclusion'] = $result['diagnosis']['summary'] ?? '';
            $result['main_problems'] = $result['diagnosis']['abnormal_metrics'] ?? [];
            $result['possible_reasons'] = array_values(array_filter([
                $result['diagnosis']['exposure_analysis'] ?? '',
                $result['diagnosis']['visit_conversion_analysis'] ?? '',
                $result['diagnosis']['order_conversion_analysis'] ?? '',
                $result['diagnosis']['price_analysis'] ?? '',
                $result['diagnosis']['competitor_analysis'] ?? '',
                $result['diagnosis']['comment_analysis'] ?? '',
            ]));
            $result['recommended_actions'] = $result['diagnosis']['actions'] ?? [];
            $result['data_anomalies_needing_confirmation'] = $result['missing_sections'];
            $result['priority'] = $result['diagnosis']['priority'] ?? $result['priority'];

            return $this->success($result, 'success');
        } catch (\Throwable $e) {
            return $this->error('OTA 诊断失败: ' . $this->sanitizeLlmErrorMessage($e->getMessage()), 500);
        }
    }

    public function analyzeCapturedOtaData(): Response
    {
        $this->checkAdmin();

        $payload = $this->request->post();
        $platform = strtolower(trim((string) ($payload['platform'] ?? 'ctrip')));
        $dataSource = strtolower(trim((string) ($payload['data_source'] ?? 'rank')));
        $modelKey = trim((string) ($payload['model_key'] ?? 'deepseek_v4_default'));
        $modelMode = $payload['model_mode'] ?? null;
        $modelOptions = $modelMode !== null && trim((string) $modelMode) !== '' ? ['model_mode' => $modelMode] : [];
        $startDate = trim((string) ($payload['start_date'] ?? ''));
        $endDate = trim((string) ($payload['end_date'] ?? ''));
        $hotels = $payload['hotels'] ?? [];

        if ($modelKey === '') {
            $modelKey = 'deepseek_v4_default';
        }
        if (!$this->isAllowedLlmModelKey($modelKey)) {
            return $this->error('未找到启用的模型配置：' . $modelKey . '，请先到系统设置 > AI模型配置中配置', 422);
        }
        if (!in_array($platform, ['ctrip', 'meituan', 'qunar'], true)) {
            return $this->error('platform 仅支持 ctrip、meituan、qunar', 422);
        }
        if (!in_array($dataSource, ['rank', 'traffic', 'business', 'captured'], true)) {
            return $this->error('data_source 仅支持 rank、traffic、business、captured', 422);
        }
        if (!$this->isDateString($startDate) || !$this->isDateString($endDate)) {
            return $this->error('start_date 和 end_date 必须为 YYYY-MM-DD', 422);
        }
        if (strtotime($startDate) > strtotime($endDate)) {
            return $this->error('start_date 不能晚于 end_date', 422);
        }
        if (!is_array($hotels) || empty($hotels)) {
            return $this->error('暂无抓取数据', 422);
        }

        try {
            $summary = $this->buildCapturedOtaSummary($hotels, $platform, $dataSource, $startDate, $endDate);
            if (empty($summary['hotels'])) {
                return $this->error('暂无可分析的抓取数据', 422);
            }

            $llmResult = $this->callLlm($this->buildCapturedOtaPrompt($summary), $modelKey, [
                'selected_hotel_count' => $summary['hotel_count'],
            ], $modelOptions);
            if (($llmResult['ok'] ?? false) !== true) {
                return $this->error((string) $llmResult['message'], (int) $llmResult['code'], $llmResult['data'] ?? null);
            }

            $report = $this->parseCapturedOtaAnalysisResult((string) $llmResult['content']);
            $report['summary'] = [
                'hotel_count' => $summary['hotel_count'],
                'input_hotel_count' => $summary['input_hotel_count'],
                'truncated' => $summary['truncated'],
                'platform' => $platform,
                'data_source' => $dataSource,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ];

            OperationLog::record('agent', 'analyze_captured_ota_data', '分析当前抓取OTA数据', (int) ($this->currentUser->id ?? 0), null, null, [
                'platform' => $platform,
                'data_source' => $dataSource,
                'model_key' => $modelKey,
                'hotel_count' => $summary['hotel_count'],
                'truncated' => $summary['truncated'],
            ]);

            return $this->success($report, 'success');
        } catch (\Throwable $e) {
            OperationLog::error('agent', 'analyze_captured_ota_data', '分析当前抓取OTA数据失败', $this->sanitizeLlmErrorMessage($e->getMessage()), (int) ($this->currentUser->id ?? 0));
            return $this->error('抓取数据 AI 分析失败: ' . $this->sanitizeLlmErrorMessage($e->getMessage()), 500);
        }
    }

    public function summarizeCapturedOtaAnalysis(): Response
    {
        $this->checkAdmin();

        $payload = $this->request->post();
        $platform = strtolower(trim((string) ($payload['platform'] ?? 'ctrip')));
        $modelKey = trim((string) ($payload['model_key'] ?? 'deepseek_v4_default'));
        $modelMode = $payload['model_mode'] ?? null;
        $modelOptions = $modelMode !== null && trim((string) $modelMode) !== '' ? ['model_mode' => $modelMode] : [];
        $dateRange = is_array($payload['date_range'] ?? null) ? $payload['date_range'] : [];
        $startDate = trim((string) ($dateRange['start_date'] ?? $payload['start_date'] ?? ''));
        $endDate = trim((string) ($dateRange['end_date'] ?? $payload['end_date'] ?? ''));
        $selectedHotelCount = max(0, (int) ($payload['selected_hotel_count'] ?? 0));
        $successHotelCount = max(0, (int) ($payload['success_hotel_count'] ?? 0));
        $failedHotelCount = max(0, (int) ($payload['failed_hotel_count'] ?? 0));
        $groupReports = $payload['group_summaries'] ?? $payload['group_reports'] ?? [];
        $failedGroups = $payload['failed_groups'] ?? [];

        if ($modelKey === '') {
            $modelKey = 'deepseek_v4_default';
        }
        if (!$this->isAllowedLlmModelKey($modelKey)) {
            return $this->error('未找到启用的模型配置：' . $modelKey . '，请先到系统设置 > AI模型配置中配置', 422);
        }
        if (!in_array($platform, ['ctrip', 'meituan', 'qunar'], true)) {
            return $this->error('platform 仅支持 ctrip、meituan、qunar', 422);
        }
        if (!$this->isDateString($startDate) || !$this->isDateString($endDate)) {
            return $this->error('start_date 和 end_date 必须为 YYYY-MM-DD', 422);
        }
        if (!is_array($groupReports) || empty($groupReports)) {
            return $this->error('暂无可汇总的分组报告', 422);
        }

        $summary = null;
        try {
            $summary = $this->buildCapturedOtaFinalSummary(
                $groupReports,
                is_array($failedGroups) ? $failedGroups : [],
                $platform,
                $startDate,
                $endDate,
                $selectedHotelCount,
                $successHotelCount,
                $failedHotelCount,
                $modelKey
            );
            $process = $this->buildCapturedOtaProcess($summary);
            $summaryMeta = [
                'group_count' => count($summary['groups']),
                'failed_group_count' => count($summary['failed_groups']),
                'selected_hotel_count' => $summary['selected_hotel_count'],
                'success_hotel_count' => $summary['success_hotel_count'],
                'failed_hotel_count' => $summary['failed_hotel_count'],
                'hotel_count' => $summary['success_hotel_count'],
                'platform' => $platform,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ];

            $llmResult = $this->callLlm($this->buildCapturedOtaFinalPrompt($summary), $modelKey, [
                'selected_hotel_count' => $summary['selected_hotel_count'],
            ], $modelOptions);
            if (($llmResult['ok'] ?? false) === true) {
                $report = $this->parseCapturedOtaAnalysisResult((string) $llmResult['content']);
                $report['fallback'] = false;
            } else {
                $report = $this->buildCapturedOtaFallbackReport($summary, (string) ($llmResult['message'] ?? '汇总失败'));
            }
            $report['summary'] = $summaryMeta;

            OperationLog::record('agent', 'summarize_captured_ota_analysis', '汇总当前抓取OTA分组报告', (int) ($this->currentUser->id ?? 0), null, null, [
                'platform' => $platform,
                'model_key' => $modelKey,
                'group_count' => count($summary['groups']),
                'failed_group_count' => count($summary['failed_groups']),
                'selected_hotel_count' => $summary['selected_hotel_count'],
                'success_hotel_count' => $summary['success_hotel_count'],
                'failed_hotel_count' => $summary['failed_hotel_count'],
            ]);

            return $this->success([
                'report' => $report,
                'process' => $process,
            ], 'success');
        } catch (\Throwable $e) {
            OperationLog::error('agent', 'summarize_captured_ota_analysis', '汇总当前抓取OTA分组报告失败', $this->sanitizeLlmErrorMessage($e->getMessage()), (int) ($this->currentUser->id ?? 0));
            if (is_array($summary) && !empty($summary['groups'])) {
                $report = $this->buildCapturedOtaFallbackReport($summary, $e->getMessage());
                $report['summary'] = [
                    'group_count' => count($summary['groups']),
                    'failed_group_count' => count($summary['failed_groups']),
                    'selected_hotel_count' => $summary['selected_hotel_count'],
                    'success_hotel_count' => $summary['success_hotel_count'],
                    'failed_hotel_count' => $summary['failed_hotel_count'],
                    'hotel_count' => $summary['success_hotel_count'],
                    'platform' => $platform,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ];
                return $this->success([
                    'report' => $report,
                    'process' => $this->buildCapturedOtaProcess($summary),
                ], 'success');
            }
            return $this->error('批量总报告生成失败: ' . $this->sanitizeLlmErrorMessage($e->getMessage()), 500);
        }
    }

    private function isDateString(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $time = strtotime($date);
        return $time !== false && date('Y-m-d', $time) === $date;
    }

    private function buildCapturedOtaSummary(array $hotels, string $platform, string $dataSource, string $startDate, string $endDate): array
    {
        $maxHotels = 50;
        $inputCount = count($hotels);
        $rows = [];
        $totals = [
            'room_nights' => 0.0,
            'room_revenue' => 0.0,
            'sales' => 0.0,
            'exposure' => 0.0,
            'views' => 0.0,
            'orders' => 0.0,
        ];
        $scoreValues = [];
        $conversionValues = [];

        foreach (array_slice($hotels, 0, $maxHotels) as $hotel) {
            if (!is_array($hotel)) {
                continue;
            }

            $hotelId = substr(trim((string) ($hotel['hotel_id'] ?? $hotel['hotelId'] ?? $hotel['poiId'] ?? '')), 0, 64);
            $hotelName = substr(trim((string) ($hotel['hotel_name'] ?? $hotel['hotelName'] ?? $hotel['name'] ?? '')), 0, 120);
            if ($hotelId === '' && $hotelName === '') {
                continue;
            }

            $metrics = [];
            foreach (['rank', 'price', 'score', 'comments_count', 'exposure', 'visitors', 'orders', 'revenue', 'room_nights'] as $field) {
                if (isset($hotel[$field])) {
                    $metrics[$field] = $hotel[$field];
                }
            }
            $extraMetrics = $hotel['raw_metrics'] ?? $hotel['metrics'] ?? [];
            if (!is_array($extraMetrics)) {
                $extraMetrics = [];
            }
            foreach ($extraMetrics as $field => $value) {
                if (!isset($metrics[$field])) {
                    $metrics[$field] = $value;
                }
            }
            if (!is_array($metrics)) {
                $metrics = [];
            }
            $safeMetrics = $this->sanitizeCapturedOtaMetrics($metrics);
            $roomNights = (float) ($safeMetrics['room_nights'] ?? 0);
            $roomRevenue = (float) ($safeMetrics['revenue'] ?? $safeMetrics['room_revenue'] ?? 0);
            $exposure = (float) ($safeMetrics['exposure'] ?? 0);
            $views = (float) ($safeMetrics['visitors'] ?? $safeMetrics['views'] ?? 0);
            $orders = (float) ($safeMetrics['orders'] ?? $safeMetrics['total_order_num'] ?? $safeMetrics['book_order_num'] ?? 0);
            $sales = (float) ($safeMetrics['sales'] ?? $safeMetrics['revenue'] ?? $roomRevenue);
            $commentScore = (float) ($safeMetrics['score'] ?? $safeMetrics['comment_score'] ?? 0);
            $viewConversion = (float) ($safeMetrics['view_conversion'] ?? $safeMetrics['conversion_rate'] ?? 0);
            $payConversion = (float) ($safeMetrics['pay_conversion'] ?? 0);
            $tags = $this->sanitizeCapturedTags($hotel['tags'] ?? []);
            $shortSummary = mb_substr(trim((string) ($hotel['short_summary'] ?? '')), 0, 160);

            $safeMetrics['adr'] = $roomNights > 0 ? round($roomRevenue / $roomNights, 2) : 0.0;
            $safeMetrics['view_rate'] = $exposure > 0 ? round($views / $exposure * 100, 2) : 0.0;
            $safeMetrics['order_rate'] = $views > 0 ? round($orders / $views * 100, 2) : 0.0;

            $totals['room_nights'] += $roomNights;
            $totals['room_revenue'] += $roomRevenue;
            $totals['sales'] += $sales;
            $totals['exposure'] += $exposure;
            $totals['views'] += $views;
            $totals['orders'] += $orders;
            if ($commentScore > 0) {
                $scoreValues[] = $commentScore;
            }
            if ($viewConversion > 0) {
                $conversionValues[] = $viewConversion;
            }
            if ($payConversion > 0) {
                $conversionValues[] = $payConversion;
            }

            $rows[] = [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName !== '' ? $hotelName : $hotelId,
                'metrics' => $safeMetrics,
                'tags' => $tags,
                'short_summary' => $shortSummary,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            return ((float) ($b['metrics']['revenue'] ?? $b['metrics']['room_revenue'] ?? 0)) <=> ((float) ($a['metrics']['revenue'] ?? $a['metrics']['room_revenue'] ?? 0));
        });

        return [
            'scope' => [
                'platform' => $platform,
                'data_source' => $dataSource,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'input_hotel_count' => $inputCount,
            'hotel_count' => count($rows),
            'truncated' => $inputCount > $maxHotels,
            'totals' => $totals,
            'averages' => [
                'adr' => $this->percentSafeAverage($totals['room_revenue'], $totals['room_nights']),
                'view_rate' => $this->percentRate($totals['views'], $totals['exposure']),
                'order_rate' => $this->percentRate($totals['orders'], $totals['views']),
                'comment_score' => $this->average($scoreValues),
                'conversion_rate' => $this->average($conversionValues),
            ],
            'hotels' => $rows,
            'top_hotels_by_revenue' => array_slice($rows, 0, 10),
            'data_anomalies' => $inputCount > $maxHotels ? ['单次最多分析 50 家酒店，已截断超出部分。'] : [],
        ];
    }

    private function sanitizeCapturedOtaMetrics(array $metrics): array
    {
        $allowed = [
            'rank',
            'price',
            'score',
            'comments_count',
            'visitors',
            'orders',
            'revenue',
            'room_nights',
            'room_revenue',
            'sales_room_nights',
            'sales',
            'view_conversion',
            'pay_conversion',
            'exposure',
            'views',
            'comment_score',
            'qunar_comment_score',
            'conversion_rate',
            'qunar_detail_cr',
            'amount_rank',
            'quantity_rank',
            'comment_score_rank',
            'qunar_detail_cr_rank',
            'total_order_num',
            'book_order_num',
        ];

        $safe = [];
        foreach ($allowed as $key) {
            if (isset($metrics[$key]) && is_numeric($metrics[$key])) {
                $safe[$key] = round((float) $metrics[$key], 4);
            }
        }
        return $safe;
    }

    private function sanitizeCapturedTags($tags): array
    {
        if (!is_array($tags)) {
            return [];
        }
        $safe = [];
        foreach (array_slice($tags, 0, 8) as $tag) {
            $tag = mb_substr(trim((string) $tag), 0, 24);
            if ($tag !== '') {
                $safe[] = $tag;
            }
        }
        return $safe;
    }

    private function buildCapturedOtaFinalSummary(
        array $groupReports,
        array $failedGroups,
        string $platform,
        string $startDate,
        string $endDate,
        int $selectedHotelCount,
        int $successHotelCount,
        int $failedHotelCount,
        string $modelKey
    ): array
    {
        $groups = [];
        $hotelCount = 0;
        foreach (array_slice($groupReports, 0, 20) as $index => $group) {
            if (!is_array($group)) {
                continue;
            }
            $report = $group['report'] ?? $group;
            if (!is_array($report)) {
                continue;
            }
            $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
            $hotelCount += (int) ($summary['hotel_count'] ?? $group['hotel_count'] ?? 0);
            $groups[] = [
                'group_index' => (int) ($group['group_index'] ?? ($index + 1)),
                'hotel_count' => (int) ($summary['hotel_count'] ?? $group['hotel_count'] ?? 0),
                'overall_conclusion' => mb_substr((string) ($report['overall_conclusion'] ?? ''), 0, 300),
                'key_findings' => $this->sanitizeReportList($report['key_findings'] ?? [], 5),
                'competitor_insights' => $this->sanitizeReportList($report['competitor_insights'] ?? [], 5),
                'problem_hotels' => $this->sanitizeReportList($report['problem_hotels'] ?? [], 8),
                'recommended_actions' => $this->sanitizeReportList($report['recommended_actions'] ?? [], 6),
                'priority' => in_array(($report['priority'] ?? ''), ['high', 'medium', 'low'], true) ? (string) $report['priority'] : 'medium',
                'data_anomalies' => $this->sanitizeReportList($report['data_anomalies'] ?? [], 5),
            ];
        }

        $safeFailedGroups = [];
        foreach (array_slice($failedGroups, 0, 20) as $group) {
            if (!is_array($group)) {
                continue;
            }
            $safeFailedGroups[] = [
                'group_index' => (int) ($group['group_index'] ?? 0),
                'hotel_count' => (int) ($group['hotel_count'] ?? 0),
                'error' => $this->sanitizeLlmErrorMessage((string) ($group['error'] ?? '分析失败')),
            ];
        }

        return [
            'scope' => [
                'platform' => $platform,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'selected_hotel_count' => $selectedHotelCount > 0 ? $selectedHotelCount : ($hotelCount + $failedHotelCount),
            'success_hotel_count' => $successHotelCount > 0 ? $successHotelCount : $hotelCount,
            'failed_hotel_count' => $failedHotelCount,
            'model_key' => $modelKey,
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'hotel_count' => $hotelCount,
            'groups' => $groups,
            'failed_groups' => $safeFailedGroups,
        ];
    }

    private function buildCapturedOtaProcess(array $summary): array
    {
        return [
            'selected_hotel_count' => (int) ($summary['selected_hotel_count'] ?? 0),
            'success_hotel_count' => (int) ($summary['success_hotel_count'] ?? 0),
            'failed_hotel_count' => (int) ($summary['failed_hotel_count'] ?? 0),
            'group_count' => count($summary['groups'] ?? []),
            'failed_group_count' => count($summary['failed_groups'] ?? []),
            'groups' => array_values($summary['groups'] ?? []),
            'failed_groups' => array_values($summary['failed_groups'] ?? []),
        ];
    }

    private function buildCapturedOtaFallbackReport(array $summary, string $reason = ''): array
    {
        $groups = is_array($summary['groups'] ?? null) ? $summary['groups'] : [];
        $failedGroups = is_array($summary['failed_groups'] ?? null) ? $summary['failed_groups'] : [];
        $selectedCount = (int) ($summary['selected_hotel_count'] ?? 0);
        $successCount = (int) ($summary['success_hotel_count'] ?? 0);
        $failedCount = (int) ($summary['failed_hotel_count'] ?? 0);

        $keyFindings = [];
        $competitorInsights = [];
        $problemHotels = [];
        $recommendedActions = [];
        $dataAnomalies = [];
        $priority = 'medium';
        $priorityRank = ['low' => 1, 'medium' => 2, 'high' => 3];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            if (!empty($group['overall_conclusion'])) {
                $keyFindings[] = (string) $group['overall_conclusion'];
            }
            $keyFindings = array_merge($keyFindings, $this->sanitizeReportList($group['key_findings'] ?? [], 3));
            $competitorInsights = array_merge($competitorInsights, $this->sanitizeReportList($group['competitor_insights'] ?? [], 3));
            $problemHotels = array_merge($problemHotels, $this->sanitizeReportList($group['problem_hotels'] ?? [], 4));
            $recommendedActions = array_merge($recommendedActions, $this->sanitizeReportList($group['recommended_actions'] ?? [], 4));
            $dataAnomalies = array_merge($dataAnomalies, $this->sanitizeReportList($group['data_anomalies'] ?? [], 3));
            $groupPriority = (string) ($group['priority'] ?? 'medium');
            if (($priorityRank[$groupPriority] ?? 2) > ($priorityRank[$priority] ?? 2)) {
                $priority = $groupPriority;
            }
        }

        if (!empty($failedGroups)) {
            $dataAnomalies[] = '部分分组汇总失败，报告覆盖可能不完整。';
        }
        if ($reason !== '') {
            $dataAnomalies[] = 'AI综合汇总失败，已自动生成基础综合报告。';
        }

        return [
            'overall_conclusion' => sprintf(
                '已完成 %d/%d 家酒店的OTA抓取数据分析，系统基于成功分组自动归纳基础综合报告。',
                $successCount,
                max($selectedCount, $successCount + $failedCount)
            ),
            'key_findings' => array_values(array_slice(array_unique(array_filter($keyFindings)), 0, 8)),
            'competitor_insights' => array_values(array_slice(array_unique(array_filter($competitorInsights)), 0, 8)),
            'problem_hotels' => array_values(array_slice(array_unique(array_filter($problemHotels)), 0, 10)),
            'recommended_actions' => array_values(array_slice(array_unique(array_filter($recommendedActions)), 0, 10)),
            'priority' => $priority,
            'data_anomalies' => array_values(array_slice(array_unique(array_filter($dataAnomalies)), 0, 8)),
            'fallback' => true,
            'fallback_reason' => $this->sanitizeLlmErrorMessage($reason),
        ];
    }

    private function sanitizeReportList($value, int $limit): array
    {
        $items = is_array($value) ? $value : [$value];
        $safe = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            if (is_array($item)) {
                $parts = [];
                foreach ($item as $key => $val) {
                    if (is_scalar($val) && trim((string) $val) !== '') {
                        $parts[] = mb_substr((string) $key, 0, 40) . ': ' . mb_substr((string) $val, 0, 160);
                    }
                }
                $text = implode('；', $parts);
            } else {
                $text = (string) $item;
            }
            $text = mb_substr(trim($text), 0, 240);
            if ($text !== '') {
                $safe[] = $text;
            }
        }
        return $safe;
    }

    private function resolveOtaDiagnosisConfig(string $platform, string $configId): array
    {
        $configKey = $platform === 'meituan' ? 'meituan_config_list' : 'ctrip_config_list';
        $table = $platform === 'meituan' ? 'system_config' : 'system_configs';
        $raw = (string) Db::name($table)->where('config_key', $configKey)->value('config_value');
        $list = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($list)) {
            return [];
        }

        foreach ($list as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemId = (string) ($item['id'] ?? $key);
            if ($itemId !== $configId && (string) $key !== $configId) {
                continue;
            }

            return [
                'hotel_id' => $item['system_hotel_id'] ?? $item['hotel_id'] ?? ($platform === 'meituan' ? ($item['poi_id'] ?? 0) : 0),
                'hotel_name' => $item['hotel_name'] ?? $item['name'] ?? '',
            ];
        }

        return [];
    }

    private function queryOtaDiagnosisData(int $hotelId, string $hotelIdRaw, string $platform, string $startDate, string $endDate, string $analysisType): array
    {
        $columns = $this->onlineDailyDataColumns();
        $fields = array_values(array_intersect([
            'id',
            'hotel_id',
            'hotel_name',
            'system_hotel_id',
            'data_date',
            'amount',
            'quantity',
            'book_order_num',
            'comment_score',
            'qunar_comment_score',
            'data_value',
            'source',
            'dimension',
            'data_type',
            'platform',
            'compare_type',
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
            'raw_data',
            'create_time',
            'update_time',
        ], array_keys($columns)));

        $query = Db::name('online_daily_data')
            ->field(implode(',', $fields))
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);

        if (isset($columns['source'])) {
            $query->where('source', $platform);
        }
        $query->where(function ($q) use ($hotelId, $hotelIdRaw, $columns) {
            $hasWhere = false;
            if ($hotelId > 0 && isset($columns['system_hotel_id'])) {
                $q->where('system_hotel_id', $hotelId);
                $hasWhere = true;
            }
            if ($hotelIdRaw !== '' && isset($columns['hotel_id'])) {
                $hasWhere ? $q->whereOr('hotel_id', $hotelIdRaw) : $q->where('hotel_id', $hotelIdRaw);
            }
        });

        if (isset($columns['data_type']) && $analysisType === 'traffic') {
            $query->where('data_type', 'traffic');
        } elseif (isset($columns['data_type']) && $analysisType === 'business') {
            $query->whereIn('data_type', ['business', '']);
        }

        $onlineRows = $query->order('data_date', 'asc')->order('id', 'asc')->select()->toArray();
        $dailyReports = $hotelId > 0 && $this->tableExists('daily_reports')
            ? Db::name('daily_reports')
                ->field('id,hotel_id,report_date,report_data,occupancy_rate,room_count,guest_count,revenue,expenses,notes,create_time,update_time')
                ->where('hotel_id', $hotelId)
                ->where('report_date', '>=', $startDate)
                ->where('report_date', '<=', $endDate)
                ->order('report_date', 'asc')
                ->select()
                ->toArray()
            : [];
        $competitorPrices = $hotelId > 0 && $this->tableExists('competitor_price_log')
            ? Db::name('competitor_price_log')
                ->field('id,store_id,hotel_id,platform,city,price,fetch_time,create_time')
                ->where('hotel_id', $hotelId)
                ->where('platform', $platform)
                ->where('create_time', '>=', $startDate . ' 00:00:00')
                ->where('create_time', '<=', $endDate . ' 23:59:59')
                ->order('fetch_time', 'asc')
                ->select()
                ->toArray()
            : [];
        $syncLogs = $hotelId > 0 && $this->tableExists('operation_logs')
            ? Db::name('operation_logs')
                ->field('id,module,action,description,create_time,error_info')
                ->where('hotel_id', $hotelId)
                ->where('module', 'online_data')
                ->where('create_time', '>=', $startDate . ' 00:00:00')
                ->where('create_time', '<=', $endDate . ' 23:59:59')
                ->order('create_time', 'desc')
                ->limit(10)
                ->select()
                ->toArray()
            : [];
        $hotel = $hotelId > 0 && $this->tableExists('hotels')
            ? (Db::name('hotels')->field('id,name,code,address,status')->where('id', $hotelId)->find() ?: [])
            : [];
        $lastSyncTime = $this->maxDateTime(array_merge(
            array_column($onlineRows, 'update_time'),
            array_column($onlineRows, 'create_time'),
            array_column($dailyReports, 'update_time'),
            array_column($competitorPrices, 'fetch_time'),
            array_column($syncLogs, 'create_time')
        ));

        return [
            'hotel' => $hotel ?: ['id' => $hotelIdRaw, 'name' => ''],
            'online_rows' => $onlineRows,
            'daily_reports' => $dailyReports,
            'competitor_prices' => $competitorPrices,
            'sync_logs' => $syncLogs,
            'last_sync_time' => $lastSyncTime,
        ];
    }

    private function hasOtaDiagnosisData(array $dataSet): bool
    {
        return !empty($dataSet['online_rows']) || !empty($dataSet['daily_reports']) || !empty($dataSet['competitor_prices']);
    }

    private function buildOtaDiagnosisResult(array $dataSet, int $hotelId, string $hotelIdRaw, string $hotelName, string $platform, string $startDate, string $endDate, string $analysisType): array
    {
        $rows = $dataSet['online_rows'] ?? [];
        $dailyReports = $dataSet['daily_reports'] ?? [];
        $competitorPrices = $dataSet['competitor_prices'] ?? [];
        $syncLogs = $dataSet['sync_logs'] ?? [];
        $summary = $this->buildOtaDiagnosisSummary($rows, $hotelId, $hotelName, $platform, $startDate, $endDate, $analysisType);
        $totals = $summary['totals'];
        $rates = $summary['derived_rates'];
        $avgCompetitorPrice = $this->average(array_map('floatval', array_column($competitorPrices, 'price')));
        $dailyRevenue = array_sum(array_map('floatval', array_column($dailyReports, 'revenue')));
        $hasTraffic = ($totals['list_exposure'] + $totals['detail_visitors'] + $totals['order_visitors'] + $totals['submit_users']) > 0;
        $hasComment = ($summary['averages']['comment_score'] > 0 || $summary['averages']['qunar_comment_score'] > 0);
        $hasCompetitor = !empty($competitorPrices) || $this->hasCompareRows($rows);
        $hasDaily = !empty($dailyReports);
        $missingSections = [];
        if (!$hasTraffic) {
            $missingSections[] = 'OTA流量数据';
        }
        if (!$hasCompetitor) {
            $missingSections[] = '竞对数据';
        }
        if (!$hasComment) {
            $missingSections[] = '点评数据';
        }
        if (!$hasDaily) {
            $missingSections[] = '日报经营数据';
        }
        if (empty($syncLogs) && ($dataSet['last_sync_time'] ?? '') === '') {
            $missingSections[] = '抓取日志/最近同步时间';
        }

        $metrics = [
            'record_count' => count($rows),
            'date_count' => $summary['date_count'],
            'amount' => round((float) $totals['amount'], 2),
            'quantity' => (int) $totals['quantity'],
            'book_order_num' => (int) $totals['book_order_num'],
            'adr' => $summary['averages']['adr'],
            'list_exposure' => (int) $totals['list_exposure'],
            'detail_visitors' => (int) $totals['detail_visitors'],
            'order_visitors' => (int) $totals['order_visitors'],
            'submit_users' => (int) $totals['submit_users'],
            'detail_rate' => $rates['detail_rate'],
            'order_rate' => $rates['order_rate'],
            'submit_rate' => $rates['submit_rate'],
            'comment_score' => $summary['averages']['comment_score'],
            'qunar_comment_score' => $summary['averages']['qunar_comment_score'],
            'daily_report_revenue' => round($dailyRevenue, 2),
            'competitor_avg_price' => $avgCompetitorPrice,
        ];
        $abnormal = $summary['data_anomalies'];
        if ($hasTraffic && $rates['detail_rate'] > 0 && $rates['detail_rate'] < 5) {
            $abnormal[] = '曝光到访问转化偏低';
        }
        if ($hasTraffic && $rates['order_rate'] > 0 && $rates['order_rate'] < 3) {
            $abnormal[] = '访问到订单转化偏低';
        }
        if ($hasComment && max($summary['averages']['comment_score'], $summary['averages']['qunar_comment_score']) < 4.5) {
            $abnormal[] = '点评评分低于 4.5';
        }

        $displayHotelName = trim((string) ($dataSet['hotel']['name'] ?? ''));
        if ($displayHotelName === '') {
            $displayHotelName = $hotelName !== '' ? $hotelName : $hotelIdRaw;
        }
        $diagnosis = [
            'summary' => sprintf('已读取%s在%s至%s的历史OTA数据，覆盖%d条OTA记录、%d条日报、%d条竞对价格记录。', $displayHotelName, $startDate, $endDate, count($rows), count($dailyReports), count($competitorPrices)),
            'data_overview' => [
                'OTA记录数: ' . count($rows),
                '日期覆盖: ' . $summary['date_count'] . ' 天',
                '收入: ' . $metrics['amount'],
                '间夜: ' . $metrics['quantity'],
                '订单: ' . $metrics['book_order_num'],
            ],
            'abnormal_metrics' => $abnormal,
            'traffic_analysis' => $hasTraffic ? sprintf('曝光%d，访问%d，曝光到访问率%s%%。', $metrics['list_exposure'], $metrics['detail_visitors'], $metrics['detail_rate']) : '缺少OTA流量数据，无法判断曝光和访问漏斗。',
            'exposure_analysis' => $hasTraffic ? sprintf('曝光%d，访问%d，曝光到访问率%s%%。', $metrics['list_exposure'], $metrics['detail_visitors'], $metrics['detail_rate']) : '缺少OTA流量数据，无法判断曝光表现。',
            'visit_conversion_analysis' => $hasTraffic ? sprintf('访问%d，订单意向%d，访问到订单率%s%%。', $metrics['detail_visitors'], $metrics['order_visitors'], $metrics['order_rate']) : '缺少访问转化数据。',
            'order_conversion_analysis' => $hasTraffic ? sprintf('订单意向%d，提交用户%d，提交率%s%%。', $metrics['order_visitors'], $metrics['submit_users'], $metrics['submit_rate']) : '缺少订单转化数据。',
            'price_analysis' => $avgCompetitorPrice > 0 ? sprintf('竞对均价%s，本店ADR%s，需结合房型和日期校准价差。', $avgCompetitorPrice, $metrics['adr']) : '缺少竞对价格数据，暂不能判断价格竞争力。',
            'competitor_analysis' => $hasCompetitor ? '已有竞对或对比数据，可继续关注价格、曝光和转化差距。' : '缺少竞对数据，无法判断同商圈机会。',
            'comment_analysis' => $hasComment ? sprintf('携程评分%s，去哪儿评分%s。', $metrics['comment_score'], $metrics['qunar_comment_score']) : '缺少点评数据，无法判断评分和口碑影响。',
            'actions' => $this->buildOtaDiagnosisActions($hasTraffic, $hasCompetitor, $hasComment, $metrics),
        ];

        return [
            'hotel' => $dataSet['hotel'] ?: ['id' => $hotelIdRaw, 'name' => $hotelName],
            'platform' => $platform,
            'date_range' => ['start_date' => $startDate, 'end_date' => $endDate],
            'data_summary' => [
                'has_ota_data' => !empty($rows),
                'has_traffic_data' => $hasTraffic,
                'has_competitor_data' => $hasCompetitor,
                'has_comment_data' => $hasComment,
                'has_daily_report_data' => $hasDaily,
                'last_sync_time' => $dataSet['last_sync_time'] ?? '',
            ],
            'metrics' => $metrics,
            'diagnosis' => $diagnosis,
            'missing_sections' => array_values(array_unique($missingSections)),
            'priority' => in_array('访问到订单转化偏低', $abnormal, true) || in_array('曝光到访问转化偏低', $abnormal, true) ? 'high' : 'medium',
            'source_summary' => $summary,
        ];
    }

    private function buildOtaDiagnosisActions(bool $hasTraffic, bool $hasCompetitor, bool $hasComment, array $metrics): array
    {
        $actions = [];
        if ($hasTraffic && (float) ($metrics['detail_rate'] ?? 0) < 5) {
            $actions[] = '优先优化列表页主图、标题卖点和价格展示，提升曝光到访问转化。';
        }
        if ($hasTraffic && (float) ($metrics['order_rate'] ?? 0) < 3) {
            $actions[] = '检查详情页房型、取消政策、促销和价格阶梯，降低访问后的下单阻力。';
        }
        if ($hasCompetitor) {
            $actions[] = '对比竞对价格和曝光差距，优先处理同价位竞品压制的日期。';
        }
        if ($hasComment && max((float) ($metrics['comment_score'] ?? 0), (float) ($metrics['qunar_comment_score'] ?? 0)) < 4.5) {
            $actions[] = '跟进低分点评，补充近期好评和服务补救动作。';
        }
        if (empty($actions)) {
            $actions[] = '先补齐缺失的数据源，再按曝光、访问、订单、点评顺序复盘。';
        }
        return $actions;
    }

    private function hasCompareRows(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['compare_type'] ?? '') === 'competitor') {
                return true;
            }
        }
        return false;
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
        }
        return $cache[$table];
    }

    private function maxDateTime(array $values): string
    {
        $max = '';
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && ($max === '' || strtotime($value) > strtotime($max))) {
                $max = $value;
            }
        }
        return $max;
    }

    private function queryOtaDiagnosisRowsLegacy(int $hotelId, string $hotelName, string $platform, string $startDate, string $endDate, string $analysisType): array
    {
        $columns = $this->onlineDailyDataColumns();
        $fields = array_values(array_intersect([
            'id',
            'hotel_id',
            'hotel_name',
            'system_hotel_id',
            'data_date',
            'amount',
            'quantity',
            'book_order_num',
            'comment_score',
            'qunar_comment_score',
            'data_value',
            'source',
            'dimension',
            'data_type',
            'platform',
            'compare_type',
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
            'raw_data',
        ], array_keys($columns)));

        $buildQuery = function (string $hotelField, $hotelValue) use ($platform, $startDate, $endDate, $analysisType, $fields, $columns) {
            $query = Db::name('online_daily_data')
                ->field(implode(',', $fields))
                ->where($hotelField, $hotelValue)
                ->where('source', $platform)
                ->where('data_date', '>=', $startDate)
                ->where('data_date', '<=', $endDate);

            if (isset($columns['data_type']) && $analysisType === 'traffic') {
                $query->where('data_type', 'traffic');
            } elseif (isset($columns['data_type']) && $analysisType === 'business') {
                $query->whereIn('data_type', ['business', '']);
            }

            return $query->order('data_date', 'asc')->order('id', 'asc');
        };

        if ($hotelId > 0) {
            $rows = $buildQuery('system_hotel_id', $hotelId)->select()->toArray();
            if (!empty($rows)) {
                return $rows;
            }

            $rows = $buildQuery('hotel_id', (string) $hotelId)->select()->toArray();
            if (!empty($rows)) {
                return $rows;
            }
        }

        if ($hotelName !== '' && isset($columns['hotel_name'])) {
            $query = Db::name('online_daily_data')
                ->field(implode(',', $fields))
                ->whereLike('hotel_name', '%' . $hotelName . '%')
                ->where('source', $platform)
                ->where('data_date', '>=', $startDate)
                ->where('data_date', '<=', $endDate);

            if (isset($columns['data_type']) && $analysisType === 'traffic') {
                $query->where('data_type', 'traffic');
            } elseif (isset($columns['data_type']) && $analysisType === 'business') {
                $query->whereIn('data_type', ['business', '']);
            }

            return $query->order('data_date', 'asc')->order('id', 'asc')->select()->toArray();
        }

        return [];
    }

    private function onlineDailyDataColumns(): array
    {
        static $columns = null;
        if ($columns !== null) {
            return $columns;
        }

        $columns = [];
        foreach (Db::query('SHOW COLUMNS FROM online_daily_data') as $row) {
            if (!empty($row['Field'])) {
                $columns[(string) $row['Field']] = true;
            }
        }

        return $columns;
    }

    private function buildOtaDiagnosisSummary(array $rows, int $hotelId, string $hotelName, string $platform, string $startDate, string $endDate, string $analysisType): array
    {
        $summary = [
            'scope' => [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'platform' => $platform,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'analysis_type' => $analysisType,
            ],
            'record_count' => count($rows),
            'date_count' => 0,
            'hotel_names' => [],
            'totals' => [
                'amount' => 0.0,
                'quantity' => 0,
                'book_order_num' => 0,
                'data_value' => 0.0,
                'list_exposure' => 0.0,
                'detail_visitors' => 0.0,
                'order_visitors' => 0.0,
                'submit_users' => 0.0,
            ],
            'averages' => [
                'comment_score' => 0.0,
                'qunar_comment_score' => 0.0,
                'adr' => 0.0,
            ],
            'daily' => [],
            'dimensions' => [],
            'data_anomalies' => [],
        ];

        $commentScores = [];
        $qunarCommentScores = [];
        $invalidRawCount = 0;
        $zeroValueCount = 0;

        foreach ($rows as $row) {
            $date = (string) ($row['data_date'] ?? '');
            if ($date === '') {
                continue;
            }

            if (!isset($summary['daily'][$date])) {
                $summary['daily'][$date] = [
                    'date' => $date,
                    'amount' => 0.0,
                    'quantity' => 0,
                    'book_order_num' => 0,
                    'data_value' => 0.0,
                    'list_exposure' => 0.0,
                    'detail_visitors' => 0.0,
                    'order_visitors' => 0.0,
                    'submit_users' => 0.0,
                ];
            }

            $hotelName = trim((string) ($row['hotel_name'] ?? ''));
            if ($hotelName !== '') {
                $summary['hotel_names'][$hotelName] = true;
            }

            $dimension = trim((string) ($row['dimension'] ?? ''));
            $dimensionKey = $dimension !== '' ? $dimension : '未标注维度';
            if (!isset($summary['dimensions'][$dimensionKey])) {
                $summary['dimensions'][$dimensionKey] = ['record_count' => 0, 'data_value' => 0.0];
            }

            $amount = (float) ($row['amount'] ?? 0);
            $quantity = (int) ($row['quantity'] ?? 0);
            $bookOrderNum = (int) ($row['book_order_num'] ?? 0);
            $dataValue = (float) ($row['data_value'] ?? 0);

            $summary['totals']['amount'] += $amount;
            $summary['totals']['quantity'] += $quantity;
            $summary['totals']['book_order_num'] += $bookOrderNum;
            $summary['totals']['data_value'] += $dataValue;
            $summary['daily'][$date]['amount'] += $amount;
            $summary['daily'][$date]['quantity'] += $quantity;
            $summary['daily'][$date]['book_order_num'] += $bookOrderNum;
            $summary['daily'][$date]['data_value'] += $dataValue;
            $summary['dimensions'][$dimensionKey]['record_count']++;
            $summary['dimensions'][$dimensionKey]['data_value'] += $dataValue;

            if ((float) ($row['comment_score'] ?? 0) > 0) {
                $commentScores[] = (float) $row['comment_score'];
            }
            if ((float) ($row['qunar_comment_score'] ?? 0) > 0) {
                $qunarCommentScores[] = (float) $row['qunar_comment_score'];
            }

            $raw = [];
            if (!empty($row['raw_data'])) {
                $decoded = json_decode((string) $row['raw_data'], true);
                if (is_array($decoded)) {
                    $raw = $decoded;
                } else {
                    $invalidRawCount++;
                }
            }

            $traffic = $this->extractOtaTrafficMetrics($row, $raw);
            foreach ($traffic as $key => $value) {
                $summary['totals'][$key] += $value;
                $summary['daily'][$date][$key] += $value;
            }

            if ($amount <= 0 && $quantity <= 0 && $bookOrderNum <= 0 && $dataValue <= 0) {
                $zeroValueCount++;
            }
        }

        $summary['date_count'] = count($summary['daily']);
        $summary['hotel_names'] = array_values(array_keys($summary['hotel_names']));
        $summary['daily'] = array_values($summary['daily']);
        $summary['dimensions'] = $this->topDimensionStats($summary['dimensions']);
        $summary['averages']['comment_score'] = $this->average($commentScores);
        $summary['averages']['qunar_comment_score'] = $this->average($qunarCommentScores);
        $summary['averages']['adr'] = $this->percentSafeAverage($summary['totals']['amount'], $summary['totals']['quantity']);
        $summary['derived_rates'] = [
            'detail_rate' => $this->percentRate($summary['totals']['detail_visitors'], $summary['totals']['list_exposure']),
            'order_rate' => $this->percentRate($summary['totals']['order_visitors'], $summary['totals']['detail_visitors']),
            'submit_rate' => $this->percentRate($summary['totals']['submit_users'], $summary['totals']['order_visitors']),
        ];

        $missingDates = $this->missingDates($startDate, $endDate, array_column($summary['daily'], 'date'));
        if (!empty($missingDates)) {
            $summary['data_anomalies'][] = '日期缺失: ' . implode(',', $missingDates);
        }
        if ($invalidRawCount > 0) {
            $summary['data_anomalies'][] = '原始 JSON 解析失败记录数: ' . $invalidRawCount;
        }
        if ($zeroValueCount > 0) {
            $summary['data_anomalies'][] = '全指标为 0 的记录数: ' . $zeroValueCount;
        }

        return $summary;
    }

    private function extractOtaTrafficMetrics(array $row, array $raw): array
    {
        $listExposure = $this->readRowNumber($row, 'list_exposure');
        if ($listExposure === null) {
            $listExposure = $this->readSummaryNumber($raw, ['listExposure', 'list_exposure', 'exposure'], null);
        }
        if ($listExposure === null && ($row['data_type'] ?? '') === 'traffic') {
            $listExposure = (float) ($row['data_value'] ?? 0);
        }

        $detailVisitors = $this->readRowNumber($row, 'detail_exposure');
        if ($detailVisitors === null) {
            $detailVisitors = $this->readSummaryNumber($raw, ['detailExposure', 'detail_exposure', 'totalDetailNum', 'detailVisitors', 'qunarDetailVisitors'], 0);
        }

        $orderVisitors = $this->readRowNumber($row, 'order_filling_num');
        if ($orderVisitors === null) {
            $orderVisitors = $this->readSummaryNumber($raw, ['orderFillingNum', 'order_filling_num', 'orderVisitors'], 0);
        }

        $submitUsers = $this->readRowNumber($row, 'order_submit_num');
        if ($submitUsers === null) {
            $submitUsers = $this->readSummaryNumber($raw, ['orderSubmitNum', 'order_submit_num', 'submitUsers'], 0);
        }

        return [
            'list_exposure' => (float) ($listExposure ?? 0),
            'detail_visitors' => (float) ($detailVisitors ?? 0),
            'order_visitors' => (float) ($orderVisitors ?? 0),
            'submit_users' => (float) ($submitUsers ?? 0),
        ];
    }

    private function readRowNumber(array $row, string $key): ?float
    {
        if (isset($row[$key]) && is_numeric($row[$key])) {
            return (float) $row[$key];
        }
        return null;
    }

    private function readSummaryNumber(array $data, array $keys, ?float $default): ?float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }
        return $default;
    }

    private function topDimensionStats(array $dimensions): array
    {
        uasort($dimensions, function (array $a, array $b): int {
            return $b['data_value'] <=> $a['data_value'];
        });
        return array_slice($dimensions, 0, 10, true);
    }

    private function average(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        return round(array_sum($values) / count($values), 2);
    }

    private function percentRate(float $numerator, float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }
        return round($numerator / $denominator * 100, 2);
    }

    private function percentSafeAverage(float $numerator, float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }
        return round($numerator / $denominator, 2);
    }

    private function missingDates(string $startDate, string $endDate, array $existingDates): array
    {
        $existing = array_flip($existingDates);
        $missing = [];
        for ($time = strtotime($startDate); $time <= strtotime($endDate); $time += 86400) {
            $date = date('Y-m-d', $time);
            if (!isset($existing[$date])) {
                $missing[] = $date;
            }
        }
        return $missing;
    }

    private function buildOtaDiagnosisPrompt(array $summary): string
    {
        return "你是宿析OS酒店OTA经营分析顾问。只基于以下系统已入库数据摘要输出诊断，不要实时抓取OTA后台，不要把Cookie状态作为历史诊断失败原因，不要编造未提供的数据。\n"
            . "必须返回 JSON，字段为 summary、data_overview、abnormal_metrics、traffic_analysis、exposure_analysis、visit_conversion_analysis、order_conversion_analysis、price_analysis、competitor_analysis、comment_analysis、actions、priority。\n"
            . "data_overview、abnormal_metrics、actions 必须是数组；priority 只能是 high、medium、low。\n"
            . "结构化摘要：\n"
            . json_encode($summary, JSON_UNESCAPED_UNICODE);
    }

    private function buildCapturedOtaPrompt(array $summary): string
    {
        return "你是宿析OS酒店OTA经营分析顾问。只基于以下前端当前抓取的携程ebooking结构化摘要输出一份批量分析报告，不要查询或假设数据库数据。\n"
            . "必须返回 JSON，字段为 overall_conclusion、key_findings、competitor_insights、problem_hotels、recommended_actions、priority、data_anomalies。\n"
            . "key_findings、competitor_insights、problem_hotels、recommended_actions、data_anomalies 必须是数组；priority 只能是 high、medium、low。\n"
            . "只输出一个 JSON 对象，不要输出 Markdown、解释文字或代码块。problem_hotels 可以包含酒店名、问题、关键指标和处理建议。不要输出 API Key、Cookie 或认证信息。\n"
            . "结构化摘要：\n"
            . json_encode($summary, JSON_UNESCAPED_UNICODE);
    }

    private function buildCapturedOtaFinalPrompt(array $summary): string
    {
        return "你是酒店OTA经营分析顾问。请基于多个分组分析结果，输出一份面向酒店经营者的综合诊断报告。\n"
            . "不要逐组复述，要综合归纳。只基于分组报告摘要，不要使用完整原始抓取数据或假设数据。\n"
            . "重点回答：1. 整体经营现状；2. 最大问题；3. 最值得关注的酒店；4. 竞对机会；5. 价格、曝光、转化、订单的异常；6. 下一步最优先的运营动作。\n"
            . "返回 JSON：{\"overall_conclusion\":\"总体结论\",\"key_findings\":[],\"competitor_insights\":[],\"problem_hotels\":[],\"recommended_actions\":[],\"priority\":\"high/medium/low\",\"data_anomalies\":[]}\n"
            . "key_findings、competitor_insights、problem_hotels、recommended_actions、data_anomalies 必须是数组；priority 只能是 high、medium、low。\n"
            . "只输出一个 JSON 对象，不要输出 Markdown、解释文字或代码块。若存在失败组，请在 data_anomalies 中提示分析覆盖不足。不要输出 API Key、Cookie 或认证信息。\n"
            . "分组报告摘要：\n"
            . json_encode($summary, JSON_UNESCAPED_UNICODE);
    }

    private function parseOtaDiagnosisResult(string $content): array
    {
        $json = $this->extractJsonObjectFromText($content);

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [
                'core_conclusion' => '模型未返回可解析 JSON，已返回原始文本供人工判断。',
                'main_problems' => [],
                'possible_reasons' => [],
                'recommended_actions' => [],
                'priority' => 'medium',
                'data_anomalies_needing_confirmation' => ['模型返回格式不是 JSON。'],
                'raw_text' => $content,
                'parse_warning' => '模型未返回标准JSON',
            ];
        }

        return [
            'summary' => (string) ($data['summary'] ?? $data['core_conclusion'] ?? ''),
            'data_overview' => array_values((array) ($data['data_overview'] ?? [])),
            'abnormal_metrics' => array_values((array) ($data['abnormal_metrics'] ?? $data['main_problems'] ?? [])),
            'traffic_analysis' => (string) ($data['traffic_analysis'] ?? ''),
            'exposure_analysis' => (string) ($data['exposure_analysis'] ?? ''),
            'visit_conversion_analysis' => (string) ($data['visit_conversion_analysis'] ?? ''),
            'order_conversion_analysis' => (string) ($data['order_conversion_analysis'] ?? ''),
            'price_analysis' => (string) ($data['price_analysis'] ?? ''),
            'competitor_analysis' => (string) ($data['competitor_analysis'] ?? ''),
            'comment_analysis' => (string) ($data['comment_analysis'] ?? ''),
            'actions' => array_values((array) ($data['actions'] ?? $data['recommended_actions'] ?? [])),
            'priority' => (string) ($data['priority'] ?? 'medium'),
        ];
    }

    private function parseCapturedOtaAnalysisResult(string $content): array
    {
        $json = $this->extractJsonObjectFromText($content);

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [
                'overall_conclusion' => '模型未返回可解析 JSON，已返回原始文本供人工判断。',
                'key_findings' => [],
                'competitor_insights' => [],
                'problem_hotels' => [],
                'recommended_actions' => [],
                'priority' => 'medium',
                'data_anomalies' => ['模型返回格式不是 JSON。'],
                'raw_text' => $content,
                'parse_warning' => '模型未返回标准JSON',
            ];
        }

        return [
            'overall_conclusion' => (string) ($data['overall_conclusion'] ?? ''),
            'key_findings' => array_values((array) ($data['key_findings'] ?? [])),
            'competitor_insights' => array_values((array) ($data['competitor_insights'] ?? [])),
            'problem_hotels' => array_values((array) ($data['problem_hotels'] ?? [])),
            'recommended_actions' => array_values((array) ($data['recommended_actions'] ?? [])),
            'priority' => (string) ($data['priority'] ?? 'medium'),
            'data_anomalies' => array_values((array) ($data['data_anomalies'] ?? [])),
        ];
    }

    private function extractJsonObjectFromText(string $content): string
    {
        $json = trim($content);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $matches)) {
            $json = trim($matches[1]);
        }
        if (json_decode($json, true) !== null) {
            return $json;
        }
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($json, $start, $end - $start + 1);
        }
        return $json;
    }

    public function feasibilityReportGenerate(): Response
    {
        $this->checkAdmin();

        try {
            $data = $this->request->post();
            $report = $this->feasibilityService()->generate($data, (int) ($this->currentUser->id ?? 0));
            OperationLog::record('agent', 'feasibility_generate', '生成智策可行性报告', (int) ($this->currentUser->id ?? 0), null, null, [
                'report_id' => $report['id'] ?? 0,
                'project_name' => $report['project_name'] ?? '',
            ]);

            return $this->success($report, '报告生成成功');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            OperationLog::error('agent', 'feasibility_generate', '生成智策可行性报告失败', $e->getMessage(), (int) ($this->currentUser->id ?? 0));
            return $this->error('报告生成失败：' . $e->getMessage(), 500);
        }
    }

    public function feasibilityReportDetail(): Response
    {
        $this->checkAdmin();

        $id = (int) $this->request->param('id', 0);
        $report = $this->feasibilityService()->detail($id);
        if (!$report) {
            return $this->error('报告不存在', 404);
        }

        return $this->success($report);
    }

    public function feasibilityReportRegenerate(): Response
    {
        $this->checkAdmin();

        try {
            $id = (int) $this->request->param('id', 0);
            $report = $this->feasibilityService()->regenerate($id, (int) ($this->currentUser->id ?? 0));
            if (!$report) {
                return $this->error('报告不存在', 404);
            }

            OperationLog::record('agent', 'feasibility_regenerate', '重新生成智策可行性报告', (int) ($this->currentUser->id ?? 0), null, null, [
                'source_report_id' => $id,
                'report_id' => $report['id'] ?? 0,
            ]);

            return $this->success($report, '报告重新生成成功');
        } catch (\Throwable $e) {
            OperationLog::error('agent', 'feasibility_regenerate', '重新生成智策可行性报告失败', $e->getMessage(), (int) ($this->currentUser->id ?? 0));
            return $this->error('报告重新生成失败：' . $e->getMessage(), 500);
        }
    }

    public function feasibilityReportList(): Response
    {
        $this->checkAdmin();

        $pagination = $this->getPagination();
        return $this->success($this->feasibilityService()->list($pagination['page'], $pagination['page_size']));
    }

    // ==================== Agent配置 ====================

    /**
     * 获取Agent配置
     */
    public function getConfig(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $agentType = (int) $this->request->param('agent_type', 0);
        
        $config = AgentConfig::where('hotel_id', $hotelId)
            ->where('agent_type', $agentType)
            ->find();
        
        if (!$config) {
            // 返回默认配置
            $defaultConfigs = [
                AgentConfig::AGENT_TYPE_STAFF => [
                    'auto_reply' => true,
                    'work_order_auto_create' => true,
                    'knowledge_base_enabled' => true,
                    'max_response_time' => 30,
                    'notification_channels' => ['wechat', 'sms'],
                ],
                AgentConfig::AGENT_TYPE_REVENUE => [
                    'price_monitor_interval' => 60,
                    'auto_pricing_enabled' => false,
                    'pricing_strategy' => 'balanced',
                    'min_profit_margin' => 15,
                    'max_price_adjustment' => 20,
                    'notification_channels' => ['wechat'],
                ],
                AgentConfig::AGENT_TYPE_ASSET => [
                    'energy_monitor_enabled' => true,
                    'anomaly_detection_enabled' => true,
                    'maintenance_reminder_days' => 7,
                    'energy_alert_threshold' => 20,
                    'notification_channels' => ['wechat'],
                ],
            ];
            
            return $this->success([
                'agent_type' => $agentType,
                'is_enabled' => false,
                'config_data' => $defaultConfigs[$agentType] ?? [],
            ]);
        }
        
        return $this->success($config);
    }

    /**
     * 保存Agent配置
     */
    public function saveConfig(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'agent_type' => 'require|integer|in:1,2,3',
            'is_enabled' => 'require|integer|in:0,1',
        ]);
        
        $config = AgentConfig::where('hotel_id', $data['hotel_id'])
            ->where('agent_type', $data['agent_type'])
            ->find();
        
        if (!$config) {
            $config = new AgentConfig();
            $config->hotel_id = $data['hotel_id'];
            $config->agent_type = $data['agent_type'];
        }
        
        $config->is_enabled = $data['is_enabled'];
        $config->config_data = $data['config_data'] ?? [];
        $config->save();
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            $data['agent_type'],
            'config_update',
            'Agent配置已更新',
            AgentLog::LEVEL_INFO,
            ['is_enabled' => $data['is_enabled']],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '配置保存成功');
    }

    // ==================== 智能员工Agent ====================

    /**
     * 获取知识库列表
     */
    public function knowledgeList(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $categoryId = (int) $this->request->param('category_id', 0);
        $keyword = (string) $this->request->param('keyword', '');
        
        $query = KnowledgeBase::where('hotel_id', $hotelId);
        
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }
        
        if ($keyword) {
            $query->searchKeyword($keyword);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('category')
            ->order('sort_order', 'asc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 保存知识库条目
     */
    public function saveKnowledge(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'title' => 'require|max:200',
            'content' => 'require',
        ]);
        
        if (!empty($data['id'])) {
            $knowledge = KnowledgeBase::find($data['id']);
            if (!$knowledge) {
                return $this->error('知识库条目不存在');
            }
        } else {
            $knowledge = new KnowledgeBase();
            $knowledge->hotel_id = $data['hotel_id'];
        }
        
        $knowledge->category_id = $data['category_id'] ?? 0;
        $knowledge->title = $data['title'];
        $knowledge->content = $data['content'];
        $knowledge->keywords = $data['keywords'] ?? '';
        $knowledge->tags = $data['tags'] ?? [];
        $knowledge->sort_order = $data['sort_order'] ?? 0;
        $knowledge->is_enabled = $data['is_enabled'] ?? 1;
        $knowledge->save();
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_STAFF,
            'knowledge_update',
            '知识库条目已保存: ' . $data['title'],
            AgentLog::LEVEL_INFO,
            ['knowledge_id' => $knowledge->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $knowledge->id], '保存成功');
    }

    /**
     * 删除知识库条目
     */
    public function deleteKnowledge(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $knowledge = KnowledgeBase::find($id);
        
        if (!$knowledge) {
            return $this->error('知识库条目不存在');
        }
        
        $hotelId = $knowledge->hotel_id;
        $title = $knowledge->title;
        $knowledge->delete();
        
        // 记录日志
        AgentLog::record(
            $hotelId,
            AgentLog::AGENT_TYPE_STAFF,
            'knowledge_delete',
            '知识库条目已删除: ' . $title,
            AgentLog::LEVEL_WARNING,
            [],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '删除成功');
    }

    /**
     * 获取知识库分类
     */
    public function knowledgeCategories(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $tree = KnowledgeCategory::getTree($hotelId);
        
        return $this->success($tree);
    }

    // ==================== 智能员工Agent - 增强功能 ====================

    /**
     * 获取工单列表
     */
    public function workOrders(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        $priority = (int) $this->request->param('priority', 0);
        $type = (int) $this->request->param('type', 0);
        
        $query = AgentWorkOrder::where('hotel_id', $hotelId);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        if ($priority > 0) {
            $query->where('priority', $priority);
        }
        if ($type > 0) {
            $query->where('order_type', $type);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with(['assignee', 'room'])
            ->order('priority', 'desc')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 创建工单
     */
    public function createWorkOrder(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'title' => 'require|max:200',
            'content' => 'require',
        ]);
        
        $order = AgentWorkOrder::createOrder($data['hotel_id'], [
            'source_type' => $data['source_type'] ?? AgentWorkOrder::SOURCE_MANUAL,
            'order_type' => $data['order_type'] ?? AgentWorkOrder::TYPE_OTHER,
            'priority' => $data['priority'] ?? AgentWorkOrder::PRIORITY_NORMAL,
            'title' => $data['title'],
            'content' => $data['content'],
            'guest_name' => $data['guest_name'] ?? '',
            'guest_phone' => $data['guest_phone'] ?? '',
            'room_id' => $data['room_id'] ?? 0,
            'room_number' => $data['room_number'] ?? '',
            'emotion_score' => $data['emotion_score'] ?? 0,
            'tags' => $data['tags'] ?? [],
            'created_by' => $this->currentUser->id ?? 0,
            'assigned_to' => $data['assigned_to'] ?? 0,
        ]);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_STAFF,
            'work_order_create',
            '工单已创建: ' . $data['title'],
            AgentLog::LEVEL_INFO,
            ['order_id' => $order->id, 'priority' => $order->priority],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $order->id], '工单创建成功');
    }

    /**
     * 分配工单
     */
    public function assignWorkOrder(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $userId = (int) $this->request->param('user_id', 0);
        
        $order = AgentWorkOrder::find($id);
        if (!$order) {
            return $this->error('工单不存在');
        }
        
        $order->assign($userId);
        
        // 记录日志
        AgentLog::record(
            $order->hotel_id,
            AgentLog::AGENT_TYPE_STAFF,
            'work_order_assign',
            '工单已分配给: ' . ($order->assignee->realname ?? '未知'),
            AgentLog::LEVEL_INFO,
            ['order_id' => $id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '工单分配成功');
    }

    /**
     * 解决工单
     */
    public function resolveWorkOrder(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $solution = (string) $this->request->param('solution', '');
        
        $order = AgentWorkOrder::find($id);
        if (!$order) {
            return $this->error('工单不存在');
        }
        
        $order->resolve($solution);
        
        // 记录日志
        AgentLog::record(
            $order->hotel_id,
            AgentLog::AGENT_TYPE_STAFF,
            'work_order_resolve',
            '工单已解决: ' . $order->title,
            AgentLog::LEVEL_INFO,
            ['order_id' => $id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '工单已解决');
    }

    /**
     * 获取工单统计
     */
    public function workOrderStats(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        $pending = AgentWorkOrder::getPendingStats($hotelId);
        $today = AgentWorkOrder::getTodayStats($hotelId);
        
        return $this->success([
            'pending' => $pending,
            'today' => $today,
        ]);
    }

    /**
     * 获取对话记录
     */
    public function conversations(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $channel = (int) $this->request->param('channel', 0);
        $keyword = (string) $this->request->param('keyword', '');
        
        $pagination = $this->getPagination();
        $result = AgentConversation::search($hotelId, $keyword, $channel, $pagination['page'], $pagination['page_size']);
        
        return $this->paginate($result['list'], $result['total'], $pagination['page'], $pagination['page_size']);
    }

    /**
     * 获取对话统计
     */
    public function conversationStats(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $days = (int) $this->request->param('days', 7);
        
        $today = AgentConversation::getTodayStats($hotelId);
        $intents = AgentConversation::getIntentStats($hotelId, $days);
        $emotions = AgentConversation::getEmotionStats($hotelId, $days);
        
        return $this->success([
            'today' => $today,
            'intent_distribution' => $intents,
            'emotion_analysis' => $emotions,
        ]);
    }

    /**
     * 获取智能员工Agent综合仪表板
     */
    public function staffDashboard(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 工单统计
        $workOrderStats = AgentWorkOrder::getPendingStats($hotelId);
        
        // 对话统计
        $todayConversations = AgentConversation::getTodayStats($hotelId);
        
        // 知识库统计
        $knowledgeStats = [
            'total' => KnowledgeBase::where('hotel_id', $hotelId)->count(),
            'enabled' => KnowledgeBase::where('hotel_id', $hotelId)->where('is_enabled', 1)->count(),
            'hot' => KnowledgeBase::getHotKnowledge($hotelId, 5),
        ];
        
        // 高优先级工单
        $urgentOrders = AgentWorkOrder::where('hotel_id', $hotelId)
            ->whereIn('status', [AgentWorkOrder::STATUS_PENDING, AgentWorkOrder::STATUS_PROCESSING])
            ->where('priority', '>=', AgentWorkOrder::PRIORITY_HIGH)
            ->order('priority', 'desc')
            ->limit(5)
            ->select();
        
        // 需要转人工的工单
        $needTransferOrders = AgentWorkOrder::where('hotel_id', $hotelId)
            ->where('status', AgentWorkOrder::STATUS_PENDING)
            ->where('emotion_score', '>=', 0.4)
            ->order('emotion_score', 'desc')
            ->limit(5)
            ->select();
        
        return $this->success([
            'work_orders' => $workOrderStats,
            'conversations' => $todayConversations,
            'knowledge_base' => $knowledgeStats,
            'urgent_orders' => $urgentOrders,
            'need_transfer_orders' => $needTransferOrders,
        ]);
    }

    // ==================== 收益管理Agent - 增强功能 ====================

    /**
     * 获取需求预测
     */
    public function demandForecasts(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $startDate = (string) $this->request->param('start_date', date('Y-m-d'));
        $endDate = (string) $this->request->param('end_date', date('Y-m-d', strtotime('+30 days')));
        
        $forecasts = DemandForecast::getForecastRange($hotelId, $startDate, $endDate);
        
        // 获取准确率统计
        $accuracy = DemandForecast::getAccuracyStats($hotelId, 30);
        
        return $this->success([
            'forecasts' => $forecasts,
            'accuracy' => $accuracy,
            'high_demand_dates' => DemandForecast::getHighDemandDates($hotelId, 80),
        ]);
    }

    /**
     * 创建需求预测
     */
    public function createForecast(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'forecast_date' => 'require|date',
            'room_type_id' => 'require|integer',
            'predicted_occupancy' => 'require|float',
        ]);
        
        $forecast = DemandForecast::createForecast($data['hotel_id'], $data['forecast_date'], $data);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_REVENUE,
            'forecast_create',
            '需求预测已创建: ' . $data['forecast_date'],
            AgentLog::LEVEL_INFO,
            ['forecast_id' => $forecast->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $forecast->id], '预测创建成功');
    }

    /**
     * 获取竞对分析
     */
    public function competitorAnalysis(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $date = (string) $this->request->param('date', date('Y-m-d'));
        
        // 获取价格矩阵
        $priceMatrix = CompetitorAnalysis::getPriceMatrix($hotelId, $date);
        
        // 获取价格波动预警
        $alerts = CompetitorAnalysis::getAlertCompetitors($hotelId, 20);
        
        // 获取价格趋势
        $competitors = CompetitorAnalysis::where('hotel_id', $hotelId)
            ->group('competitor_hotel_id')
            ->column('competitor_hotel_id');
        
        $trends = [];
        foreach ($competitors as $competitorId) {
            $trends[$competitorId] = CompetitorAnalysis::getPriceTrend($hotelId, $competitorId);
        }
        
        return $this->success([
            'price_matrix' => $priceMatrix,
            'alerts' => $alerts,
            'trends' => $trends,
            'date' => $date,
        ]);
    }

    /**
     * 记录竞对价格
     */
    public function recordCompetitorPrice(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'competitor_hotel_id' => 'require|integer',
            'our_price' => 'require|float',
            'competitor_price' => 'require|float',
        ]);
        
        $analysis = CompetitorAnalysis::recordAnalysis(
            $data['hotel_id'],
            $data['competitor_hotel_id'],
            $data
        );
        
        return $this->success(['id' => $analysis->id], '记录成功');
    }

    /**
     * 获取定价建议列表
     */
    public function priceSuggestions(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        $date = (string) $this->request->param('date', date('Y-m-d'));
        
        $query = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('suggestion_date', $date);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('roomType')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 审批定价建议
     */
    public function approvePrice(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $action = (string) $this->request->param('action', 'approve'); // approve/reject
        $remark = (string) $this->request->param('remark', '');
        
        $suggestion = PriceSuggestion::find($id);
        if (!$suggestion) {
            return $this->error('定价建议不存在');
        }
        
        if ($action === 'approve') {
            $suggestion->approve($this->currentUser->id ?? 0, $remark);
            $message = '定价建议已批准';
        } else {
            $suggestion->reject($this->currentUser->id ?? 0, $remark);
            $message = '定价建议已拒绝';
        }
        
        // 记录日志
        AgentLog::record(
            $suggestion->hotel_id,
            AgentLog::AGENT_TYPE_REVENUE,
            'price_' . $action,
            $message . ': ' . $suggestion->room_type_name,
            AgentLog::LEVEL_INFO,
            ['suggestion_id' => $id, 'suggested_price' => $suggestion->suggested_price],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, $message);
    }

    /**
     * 获取收益分析数据（增强版 - 含RevPAR分析）
     */
    public function revenueAnalysis(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $startDate = (string) $this->request->param('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = (string) $this->request->param('end_date', date('Y-m-d'));
        
        // 获取建议统计
        $stats = PriceSuggestion::getStatistics($hotelId, $startDate, $endDate);
        
        // 获取房型列表
        $roomTypes = RoomType::getHotelRoomTypes($hotelId);
        
        // 获取需求预测统计
        $forecastStats = DemandForecast::getAccuracyStats($hotelId, 30);
        $highDemandDates = DemandForecast::getHighDemandDates($hotelId, 80);
        
        // 计算RevPAR趋势（基于预测和历史数据）
        $revparTrend = [];
        $forecasts = DemandForecast::getForecastRange($hotelId, $startDate, $endDate);
        foreach ($forecasts as $forecast) {
            $revparTrend[] = [
                'date' => $forecast->forecast_date,
                'predicted_revpar' => $forecast->predicted_revpar,
                'predicted_occupancy' => $forecast->predicted_occupancy,
                'confidence' => $forecast->confidence_score,
            ];
        }
        
        // 获取定价策略建议
        $pricingStrategies = $this->generatePricingStrategies($hotelId, $highDemandDates);
        
        return $this->success([
            'statistics' => $stats,
            'room_types' => $roomTypes,
            'forecast_accuracy' => $forecastStats,
            'revpar_trend' => $revparTrend,
            'high_demand_dates' => $highDemandDates,
            'pricing_strategies' => $pricingStrategies,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
        ]);
    }

    /**
     * 生成定价策略建议
     */
    private function generatePricingStrategies(int $hotelId, array $highDemandDates): array
    {
        $strategies = [];
        
        if (count($highDemandDates) > 0) {
            $strategies[] = [
                'type' => 'high_demand',
                'title' => '高需求日期动态提价',
                'description' => '检测到 ' . count($highDemandDates) . ' 个高需求日期，建议在这些日期实施动态溢价策略',
                'suggested_action' => '在高需求日期将基础房价提高10-20%',
                'expected_impact' => '预计RevPAR提升 8-15%',
            ];
        }
        
        // 检查竞对价格差距
        $recentAnalysis = CompetitorAnalysis::where('hotel_id', $hotelId)
            ->where('analysis_date', date('Y-m-d'))
            ->select();
        
        $higherCount = 0;
        $lowerCount = 0;
        foreach ($recentAnalysis as $item) {
            if ($item->price_difference > 0) {
                $higherCount++;
            } elseif ($item->price_difference < 0) {
                $lowerCount++;
            }
        }
        
        if ($higherCount > $lowerCount) {
            $strategies[] = [
                'type' => 'competitor_price',
                'title' => '竞对价格跟进',
                'description' => '我方价格高于竞对的情况较多，可能导致客源流失',
                'suggested_action' => '针对部分房型适当降价，保持价格竞争力',
                'expected_impact' => '预计提升入住率 3-5%',
            ];
        }
        
        return $strategies;
    }

    /**
     * 获取收益管理Agent综合仪表板
     */
    public function revenueDashboard(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 今日定价建议
        $todaySuggestions = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('suggestion_date', date('Y-m-d'))
            ->with('roomType')
            ->select();
        
        $pendingCount = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('status', PriceSuggestion::STATUS_PENDING)
            ->count();
        
        // 预测准确率
        $forecastAccuracy = DemandForecast::getAccuracyStats($hotelId, 30);
        
        // 竞对监控概览
        $competitorAlerts = CompetitorAnalysis::getAlertCompetitors($hotelId, 15);
        
        // 本周RevPAR预测
        $weekForecasts = DemandForecast::getForecastRange(
            $hotelId,
            date('Y-m-d'),
            date('Y-m-d', strtotime('+7 days'))
        );
        
        $avgPredictedRevpar = 0;
        if (count($weekForecasts) > 0) {
            $totalRevpar = array_sum(array_column($weekForecasts->toArray(), 'predicted_revpar'));
            $avgPredictedRevpar = round($totalRevpar / count($weekForecasts), 2);
        }
        
        return $this->success([
            'today_suggestions' => $todaySuggestions,
            'pending_count' => $pendingCount,
            'forecast_accuracy' => $forecastAccuracy,
            'competitor_alerts' => $competitorAlerts,
            'week_revpar_forecast' => $avgPredictedRevpar,
            'high_demand_count' => count(DemandForecast::getHighDemandDates($hotelId, 80)),
        ]);
    }

    // ==================== 资产运维Agent - 增强功能 ====================

    /**
     * 获取能耗数据
     */
    public function energyData(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $energyType = (int) $this->request->param('energy_type', 0);
        $startDate = (string) $this->request->param('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = (string) $this->request->param('end_date', date('Y-m-d'));
        
        // 获取趋势数据
        $trend = [];
        if ($energyType > 0) {
            $trend = EnergyConsumption::getTrend($hotelId, $energyType, $startDate, $endDate);
        }
        
        // 获取今日数据
        $todayData = [];
        $types = [
            EnergyConsumption::TYPE_ELECTRICITY,
            EnergyConsumption::TYPE_WATER,
            EnergyConsumption::TYPE_GAS,
        ];
        foreach ($types as $type) {
            $todayData[$type] = EnergyConsumption::getTodayTotal($hotelId, $type);
        }
        
        // 获取异常记录
        $anomalies = EnergyConsumption::getAnomalies($hotelId, $startDate, $endDate, 10);
        
        // 获取能耗基准对比
        $benchmarkComparison = EnergyBenchmark::getComparisonReport($hotelId, date('Y-m-d'));
        
        return $this->success([
            'trend' => $trend,
            'today' => $todayData,
            'anomalies' => $anomalies,
            'benchmark_comparison' => $benchmarkComparison,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
        ]);
    }

    /**
     * 获取能耗基准列表
     */
    public function energyBenchmarks(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $energyType = (int) $this->request->param('energy_type', 0);
        
        $query = EnergyBenchmark::where('hotel_id', $hotelId);
        
        if ($energyType > 0) {
            $query->where('energy_type', $energyType);
        }
        
        $list = $query->with('device')
            ->where('is_active', 1)
            ->order('id', 'desc')
            ->select();
        
        return $this->success($list);
    }

    /**
     * 设置能耗基准
     */
    public function saveEnergyBenchmark(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'energy_type' => 'require|integer',
            'benchmark_value' => 'require|float',
        ]);
        
        $benchmark = EnergyBenchmark::setBenchmark($data['hotel_id'], $data);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_ASSET,
            'benchmark_update',
            '能耗基准已更新: ' . $benchmark->energy_type_name,
            AgentLog::LEVEL_INFO,
            ['benchmark_id' => $benchmark->id, 'value' => $benchmark->benchmark_value],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $benchmark->id], '基准设置成功');
    }

    /**
     * 自动计算基准
     */
    public function autoCalculateBenchmark(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $energyType = (int) $this->request->param('energy_type', 0);
        $days = (int) $this->request->param('days', 30);
        
        $benchmark = EnergyBenchmark::autoCalculateBenchmark($hotelId, $energyType, $days);
        
        return $this->success(['benchmark_value' => $benchmark], '计算完成');
    }

    /**
     * 获取节能建议
     */
    public function energySuggestions(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        
        $query = EnergySavingSuggestion::where('hotel_id', $hotelId);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('implementer')
            ->order('priority', 'desc')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 生成节能建议
     */
    public function generateEnergySuggestions(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        $suggestions = EnergySavingSuggestion::autoGenerate($hotelId);
        
        // 记录日志
        AgentLog::record(
            $hotelId,
            AgentLog::AGENT_TYPE_ASSET,
            'suggestion_generate',
            '自动生成 ' . count($suggestions) . ' 条节能建议',
            AgentLog::LEVEL_INFO,
            ['count' => count($suggestions)],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['count' => count($suggestions), 'suggestions' => $suggestions], '生成成功');
    }

    /**
     * 更新节能建议状态
     */
    public function updateEnergySuggestion(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $action = (string) $this->request->param('action', '');
        
        $suggestion = EnergySavingSuggestion::find($id);
        if (!$suggestion) {
            return $this->error('建议不存在');
        }
        
        switch ($action) {
            case 'approve':
                $suggestion->approve();
                $message = '建议已批准';
                break;
            case 'start':
                $suggestion->startImplementation($this->currentUser->id ?? 0);
                $message = '开始实施';
                break;
            case 'complete':
                $actualSaving = (float) $this->request->param('actual_saving', 0);
                $suggestion->complete($actualSaving);
                $message = '实施完成';
                break;
            default:
                return $this->error('未知操作');
        }
        
        return $this->success(null, $message);
    }

    /**
     * 获取维护计划
     */
    public function maintenancePlans(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $deviceId = (int) $this->request->param('device_id', 0);
        $status = (int) $this->request->param('status', 0);
        
        $query = MaintenancePlan::where('hotel_id', $hotelId);
        
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with(['device', 'category'])
            ->order('priority', 'desc')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 创建设备维护计划
     */
    public function createMaintenancePlan(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'device_id' => 'require|integer',
            'plan_name' => 'require|max:200',
        ]);
        
        $plan = MaintenancePlan::createForDevice($data['hotel_id'], $data['device_id'], $data);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_ASSET,
            'plan_create',
            '维护计划已创建: ' . $data['plan_name'],
            AgentLog::LEVEL_INFO,
            ['plan_id' => $plan->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $plan->id], '计划创建成功');
    }

    /**
     * 执行维护计划
     */
    public function executeMaintenancePlan(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $result = (string) $this->request->param('result', '');
        $actualCost = (float) $this->request->param('actual_cost', 0);
        
        $plan = MaintenancePlan::find($id);
        if (!$plan) {
            return $this->error('计划不存在');
        }
        
        $maintenance = $plan->execute(date('Y-m-d'), $this->currentUser->id ?? 0, $result, $actualCost);
        
        return $this->success(['maintenance_id' => $maintenance->id], '维护记录已创建');
    }

    /**
     * 获取维护提醒
     */
    public function maintenanceReminders(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        $upcoming = MaintenancePlan::getUpcomingPlans($hotelId, 7);
        $overdue = MaintenancePlan::getOverduePlans($hotelId);
        
        return $this->success([
            'upcoming' => $upcoming,
            'overdue' => $overdue,
        ]);
    }

    /**
     * 自动生成默认维护计划
     */
    public function autoGenerateMaintenancePlans(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        $plans = MaintenancePlan::autoGenerateDefaultPlans($hotelId);
        
        // 记录日志
        AgentLog::record(
            $hotelId,
            AgentLog::AGENT_TYPE_ASSET,
            'plan_auto_generate',
            '自动生成 ' . count($plans) . ' 个维护计划',
            AgentLog::LEVEL_INFO,
            ['count' => count($plans)],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['count' => count($plans)], '生成成功');
    }

    /**
     * 获取资产运维Agent综合仪表板
     */
    public function assetDashboard(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 设备统计
        $deviceStats = Device::getStatistics($hotelId);
        $faultyDevices = Device::getFaultyDevices($hotelId);
        
        // 能耗统计
        $todayEnergy = [];
        foreach ([EnergyConsumption::TYPE_ELECTRICITY, EnergyConsumption::TYPE_WATER, EnergyConsumption::TYPE_GAS] as $type) {
            $todayEnergy[$type] = EnergyConsumption::getTodayTotal($hotelId, $type);
        }
        
        // 维护统计
        $maintenanceStats = MaintenancePlan::getExecutionStats($hotelId);
        
        // 节能建议统计
        $savingStats = EnergySavingSuggestion::getImplementationStats($hotelId);
        $highPrioritySuggestions = EnergySavingSuggestion::getHighPriority($hotelId, 5);
        
        // 异常告警
        $anomalies = EnergyConsumption::getAnomalies($hotelId, date('Y-m-d', strtotime('-7 days')), date('Y-m-d'), 5);
        
        return $this->success([
            'devices' => array_merge($deviceStats, ['faulty' => $faultyDevices]),
            'energy' => $todayEnergy,
            'maintenance' => $maintenanceStats,
            'saving_suggestions' => array_merge($savingStats, ['high_priority' => $highPrioritySuggestions]),
            'anomalies' => $anomalies,
        ]);
    }

    /**
     * 获取设备列表
     */
    public function deviceList(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        $categoryId = (int) $this->request->param('category_id', 0);
        
        $query = Device::where('hotel_id', $hotelId);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('category')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 获取设备统计
     */
    public function deviceStats(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 设备统计
        $stats = Device::getStatistics($hotelId);
        
        // 待维护设备
        $pendingMaintenance = Device::getPendingMaintenance($hotelId);
        
        // 故障设备
        $faultyDevices = Device::getFaultyDevices($hotelId);
        
        // 今日维护任务
        $todayTasks = DeviceMaintenance::getTodayTasks($hotelId);
        
        return $this->success([
            'statistics' => $stats,
            'pending_maintenance' => $pendingMaintenance,
            'faulty_devices' => $faultyDevices,
            'today_tasks' => $todayTasks,
        ]);
    }

    /**
     * 创建设备
     */
    public function saveDevice(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'name' => 'require|max:100',
            'category_id' => 'require|integer',
        ]);
        
        if (!empty($data['id'])) {
            $device = Device::find($data['id']);
            if (!$device) {
                return $this->error('设备不存在');
            }
        } else {
            $device = new Device();
            $device->hotel_id = $data['hotel_id'];
            $device->status = Device::STATUS_NORMAL;
        }
        
        $device->name = $data['name'];
        $device->category_id = $data['category_id'];
        $device->model = $data['model'] ?? '';
        $device->location = $data['location'] ?? '';
        $device->install_date = $data['install_date'] ?? null;
        $device->warranty_expire = $data['warranty_expire'] ?? null;
        $device->maintenance_cycle = $data['maintenance_cycle'] ?? 90;
        $device->purchase_cost = $data['purchase_cost'] ?? 0;
        $device->is_monitored = $data['is_monitored'] ?? 1;
        $device->save();
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_ASSET,
            'device_update',
            '设备已保存: ' . $data['name'],
            AgentLog::LEVEL_INFO,
            ['device_id' => $device->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $device->id], '保存成功');
    }

    // ==================== Agent日志 ====================

    /**
     * 获取Agent日志
     */
    public function logs(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $agentType = (int) $this->request->param('agent_type', 0);
        $logLevel = (int) $this->request->param('log_level', 0);
        
        $query = AgentLog::where('hotel_id', $hotelId);
        
        if ($agentType > 0) {
            $query->where('agent_type', $agentType);
        }
        
        if ($logLevel > 0) {
            $query->where('log_level', $logLevel);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('user')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 获取Agent任务
     */
    public function tasks(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $agentType = (int) $this->request->param('agent_type', 0);
        $status = (int) $this->request->param('status', 0);
        
        $query = AgentTask::where('hotel_id', $hotelId);
        
        if ($agentType > 0) {
            $query->where('agent_type', $agentType);
        }
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 创建Agent任务
     */
    public function createTask(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'agent_type' => 'require|integer|in:1,2,3',
            'task_type' => 'require|integer',
            'task_name' => 'require|max:200',
        ]);
        
        $task = AgentTask::createTask(
            $data['hotel_id'],
            $data['agent_type'],
            $data['task_type'],
            $data['task_name'],
            $data['params'] ?? [],
            $data['priority'] ?? AgentTask::PRIORITY_NORMAL
        );
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            $data['agent_type'],
            'task_create',
            'Agent任务已创建: ' . $data['task_name'],
            AgentLog::LEVEL_INFO,
            ['task_id' => $task->id, 'task_type' => $data['task_type']],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $task->id], '任务创建成功');
    }
}
