<?php
declare(strict_types=1);

namespace app\service;

use app\model\AiModelConfig;
use RuntimeException;

class LlmClient
{
    public function chat(string $prompt, string $modelKey = 'deepseek_v4_default', array $meta = [], array $options = []): array
    {
        $modelKey = $this->normalizeModelKey($modelKey, $options);
        $config = $this->configByModelKey($modelKey);
        if (($config['ok'] ?? false) !== true) {
            $config['data'] = $this->debug('config_error', $config, 0, '', $prompt, '', (string)($config['message'] ?? ''), $meta);
            return $config;
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
            return [
                'ok' => false,
                'message' => 'LLM payload encode failed: ' . json_last_error_msg(),
                'code' => 500,
                'data' => $this->debug('json_encode_error', $config, 0, '', $prompt, '', json_last_error_msg(), $meta),
            ];
        }

        $url = rtrim((string)$config['base_url'], '/') . '/chat/completions';
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
            return [
                'ok' => false,
                'message' => $message,
                'code' => 500,
                'data' => $this->debug('network_error', $config, $statusCode, $message, $prompt, '', $message, $meta, strlen($payloadJson)),
            ];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return [
                'ok' => false,
                'message' => 'LLM response is not JSON',
                'code' => 500,
                'data' => $this->debug('invalid_response', $config, $statusCode, '', $prompt, (string)$response, 'LLM response is not JSON', $meta, strlen($payloadJson)),
            ];
        }

        if ($statusCode >= 400) {
            $message = $this->sanitize((string)($data['error']['message'] ?? ('LLM HTTP error: ' . $statusCode)));
            return [
                'ok' => false,
                'message' => $message,
                'code' => $statusCode,
                'data' => $this->debug('http_error', $config, $statusCode, '', $prompt, (string)$response, $message, $meta, strlen($payloadJson)),
            ];
        }

        $content = $this->extractChatContent($data);
        if ($content === '') {
            $message = 'LLM returned empty content';
            return [
                'ok' => false,
                'message' => $message,
                'code' => 500,
                'data' => $this->debug('empty_content', $config, $statusCode, '', $prompt, (string)$response, $message, $meta, strlen($payloadJson)),
            ];
        }

        return [
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
        ];
    }

    public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
    {
        $prompt = $this->messagesToPrompt($messages, $schema);
        $result = $this->chat($prompt, $modelKey, ['prompt_length' => mb_strlen($prompt)], ['temperature' => 0.1, 'timeout' => 60]);
        if (($result['ok'] ?? false) !== true) {
            throw new RuntimeException((string)($result['message'] ?? 'LLM request failed'));
        }

        $jsonText = $this->extractJsonText((string)$result['content']);
        $data = json_decode($jsonText, true);
        if (!is_array($data)) {
            throw new RuntimeException('LLM did not return valid JSON.');
        }
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
            'message' => 'No enabled AI model config found: ' . $modelKey,
            'code' => 422,
            'model_key' => $modelKey,
            'source' => 'database',
        ];
    }

    private function databaseConfig(string $modelKey, string $requestedModelKey): ?array
    {
        try {
            $config = AiModelConfig::where('model_key', $modelKey)->where('is_enabled', 1)->find();
            if (!$config) {
                $disabledConfig = AiModelConfig::where('model_key', $modelKey)->find();
                if ($disabledConfig) {
                    return [
                        'ok' => false,
                        'message' => 'AI model config is disabled: ' . $requestedModelKey,
                        'code' => 400,
                        'provider' => (string)$disabledConfig->provider,
                        'model_key' => $requestedModelKey,
                        'model' => (string)$disabledConfig->model_name,
                        'source' => 'database',
                    ];
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
            return array_merge(['ok' => false, 'message' => 'AI model base_url is empty', 'code' => 400], $errorConfig);
        }
        if ($modelName === '') {
            return array_merge(['ok' => false, 'message' => 'AI model name is empty', 'code' => 400], $errorConfig);
        }
        if (trim((string)$config->api_key_encrypted) === '') {
            return array_merge(['ok' => false, 'message' => 'AI model API key is empty', 'code' => 400], $errorConfig);
        }

        $secret = trim((string)env('AI_CONFIG_SECRET', ''));
        if ($secret === '') {
            return array_merge(['ok' => false, 'message' => 'AI_CONFIG_SECRET is empty', 'code' => 400], $errorConfig);
        }

        $apiKey = AiModelConfig::decryptApiKey((string)$config->api_key_encrypted, $secret);
        if ($apiKey === null) {
            return array_merge(['ok' => false, 'message' => 'AI model API key decrypt failed', 'code' => 400], $errorConfig);
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
        $message = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', 'sk-****', $message) ?? $message;
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ****', $message) ?? $message;
        $message = preg_replace('/(api[_-]?key|authorization|cookie|spidertoken)\s*[:=]\s*[^,\s;]+/i', '$1=****', $message) ?? $message;
        return mb_substr($message, 0, $limit);
    }
}
