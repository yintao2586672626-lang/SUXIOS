<?php
declare(strict_types=1);

namespace app\service;

use app\model\AiModelCallLog;
use app\model\AiModelConfig;
use RuntimeException;
use Throwable;

class LlmClient
{
    private const DEFAULT_LOW_CONFIDENCE_THRESHOLD = 0.70;

    public function chat(string $prompt, string $modelKey = 'deepseek_v4_default', array $meta = [], array $options = []): array
    {
        $startedAt = microtime(true);
        $modelKey = $this->normalizeModelKey($modelKey, $options);
        $governance = $this->buildGovernanceMeta($prompt, $modelKey, $meta, $options);
        $config = $this->configByModelKey($modelKey);
        if (($config['ok'] ?? false) !== true) {
            $config['data'] = $this->debug('config_error', $config, 0, '', $prompt, '', (string)($config['message'] ?? ''), $meta);
            return $this->finishWithGovernance($config, $governance, $config, $prompt, '', 'failed', 'config_error', (string)($config['message'] ?? ''), 0, 0, $startedAt, false);
        }

        $payload = [
            'model' => $config['model'],
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => isset($options['temperature']) ? (float)$options['temperature'] : 0.2,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            return $this->finishWithGovernance([
                'ok' => false,
                'message' => 'LLM payload encode failed: ' . json_last_error_msg(),
                'code' => 500,
                'data' => $this->debug('json_encode_error', $config, 0, '', $prompt, '', json_last_error_msg(), $meta),
            ], $governance, $config, $prompt, '', 'failed', 'json_encode_error', json_last_error_msg(), 0, 0, $startedAt, false);
        }

        $url = LlmEndpoint::chatCompletionUrl((string)$config['base_url'], (string)$config['provider']);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $config['api_key'],
                ]),
                'content' => $payloadJson,
                'timeout' => (int)($options['timeout'] ?? 45),
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $statusCode = $this->httpStatus($http_response_header ?? []);
        if ($response === false) {
            $error = error_get_last();
            $message = $this->sanitize((string)($error['message'] ?? 'Network request failed'));
            return $this->finishWithGovernance([
                'ok' => false,
                'message' => $message,
                'code' => 500,
                'data' => $this->debug('network_error', $config, $statusCode, $message, $prompt, '', $message, $meta, strlen($payloadJson)),
            ], $governance, $config, $prompt, '', 'failed', 'network_error', $message, $statusCode, strlen($payloadJson), $startedAt, false);
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return $this->finishWithGovernance([
                'ok' => false,
                'message' => 'LLM response is not JSON',
                'code' => 500,
                'data' => $this->debug('invalid_response', $config, $statusCode, '', $prompt, (string)$response, 'LLM response is not JSON', $meta, strlen($payloadJson)),
            ], $governance, $config, $prompt, (string)$response, 'failed', 'invalid_response', 'LLM response is not JSON', $statusCode, strlen($payloadJson), $startedAt, false);
        }

        if ($statusCode >= 400) {
            $message = $this->sanitize((string)($data['error']['message'] ?? ('LLM HTTP error: ' . $statusCode)));
            return $this->finishWithGovernance([
                'ok' => false,
                'message' => $message,
                'code' => $statusCode,
                'data' => $this->debug('http_error', $config, $statusCode, '', $prompt, (string)$response, $message, $meta, strlen($payloadJson)),
            ], $governance, $config, $prompt, (string)$response, 'failed', 'http_error', $message, $statusCode, strlen($payloadJson), $startedAt, false);
        }

        $content = $this->extractChatContent($data);
        if ($content === '') {
            $message = 'LLM returned empty content';
            return $this->finishWithGovernance([
                'ok' => false,
                'message' => $message,
                'code' => 500,
                'data' => $this->debug('empty_content', $config, $statusCode, '', $prompt, (string)$response, $message, $meta, strlen($payloadJson)),
            ], $governance, $config, $prompt, (string)$response, 'failed', 'empty_content', $message, $statusCode, strlen($payloadJson), $startedAt, false);
        }

        return $this->finishWithGovernance([
            'ok' => true,
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
                ],
            ],
        ], $governance, $config, $prompt, $content, 'success', '', '', $statusCode, strlen($payloadJson), $startedAt, true);
    }

    public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
    {
        $governanceMeta = $this->schemaGovernanceMeta($schema);
        $schemaForPrompt = $this->schemaWithoutGovernance($schema);
        $prompt = $this->messagesToPrompt($messages, $schemaForPrompt);
        $result = $this->chat($prompt, $modelKey, array_merge($governanceMeta, ['prompt_length' => mb_strlen($prompt)]), ['temperature' => 0.1, 'timeout' => 60]);
        if (($result['ok'] ?? false) !== true) {
            throw new RuntimeException((string)($result['message'] ?? 'LLM request failed'));
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

    private function configByModelKey(string $modelKey): array
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

        return [
            'request_id' => trim((string)($meta['request_id'] ?? '')) ?: str_replace('.', '', uniqid('ai_', true)),
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

    private function finishWithGovernance(
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
            return $result;
        } catch (Throwable $e) {
            $message = 'AI治理日志写入失败: ' . $this->sanitize($e->getMessage(), 200);
            if ($hasConclusion) {
                return [
                    'ok' => false,
                    'message' => 'AI治理日志写入失败，已阻断模型结论输出',
                    'code' => 500,
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
