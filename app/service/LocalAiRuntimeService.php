<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Read-only capability probe for the pinned local AI runtime. */
final class LocalAiRuntimeService
{
    public const BASE_URL = 'http://127.0.0.1:11434';
    public const TEXT_MODEL_KEY = 'ollama_qwen3_8b';
    public const TEXT_MODEL = 'qwen3:8b';
    public const VISION_MODEL = 'qwen3-vl:4b';
    public const EMBEDDING_MODEL = 'qwen3-embedding:0.6b';

    /** @return array<string,mixed> */
    public function capabilities(): array
    {
        $version = $this->request('GET', '/api/version');
        $tags = $this->request('GET', '/api/tags');
        $runtimeReady = ($version['ok'] ?? false) === true && ($tags['ok'] ?? false) === true;
        $installed = [];
        if ($runtimeReady) {
            foreach ((array)($tags['data']['models'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string)($row['name'] ?? $row['model'] ?? ''));
                if ($name !== '') {
                    $installed[$name] = [
                        'name' => $name,
                        'size' => max(0, (int)($row['size'] ?? 0)),
                        'modified_at' => (string)($row['modified_at'] ?? ''),
                    ];
                }
            }
        }

        $text = $this->modelCapability(self::TEXT_MODEL, $installed, ['completion']);
        $vision = $this->modelCapability(self::VISION_MODEL, $installed, ['vision']);
        $embedding = $this->modelCapability(self::EMBEDDING_MODEL, $installed, ['embedding']);
        $dbConfig = $this->textModelConfig();
        $textReady = $runtimeReady && $text['ready'] && $dbConfig['ready'];

        return [
            'contract_version' => 'local_ai_runtime.v1',
            'status' => $textReady ? 'ready' : 'blocked_not_configured',
            'runtime' => [
                'status' => $runtimeReady ? 'ready' : 'not_running',
                'base_url' => self::BASE_URL,
                'version' => (string)($version['data']['version'] ?? ''),
                'error_code' => $runtimeReady ? null : 'ollama_not_running',
            ],
            'text' => array_merge($text, [
                'model_key' => self::TEXT_MODEL_KEY,
                'config_status' => $dbConfig['ready'] ? 'readback_verified' : 'missing_or_mismatched',
                'ready' => $textReady,
                'error_code' => $textReady ? null : ($dbConfig['error_code'] ?? $text['error_code'] ?? 'local_text_model_missing'),
            ]),
            'vision' => $vision,
            'embedding' => $embedding,
            'audio' => $this->audioCapability(),
            'installed_models' => array_values($installed),
            'boundaries' => [
                'local_only' => true,
                'credentials_read' => false,
                'external_message' => false,
                'automatic_execution' => false,
                'ota_write' => false,
            ],
        ];
    }

    /** @param array<string,array<string,mixed>> $installed @param list<string> $required */
    private function modelCapability(string $model, array $installed, array $required): array
    {
        if (!isset($installed[$model])) {
            return [
                'status' => 'blocked_not_configured',
                'model' => $model,
                'capabilities' => [],
                'ready' => false,
                'error_code' => 'model_missing',
            ];
        }
        $show = $this->request('POST', '/api/show', ['model' => $model]);
        $capabilities = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($show['data']['capabilities'] ?? [])
        ))));
        $ready = ($show['ok'] ?? false) === true && array_diff($required, $capabilities) === [];
        return [
            'status' => $ready ? 'ready' : 'blocked_not_configured',
            'model' => $model,
            'capabilities' => $capabilities,
            'ready' => $ready,
            'error_code' => $ready ? null : 'required_capability_missing',
        ];
    }

    private function textModelConfig(): array
    {
        try {
            $row = Db::name('ai_model_configs')
                ->where('model_key', self::TEXT_MODEL_KEY)
                ->where('is_enabled', 1)
                ->find();
        } catch (\Throwable) {
            return ['ready' => false, 'error_code' => 'ai_model_config_table_missing'];
        }
        $ready = is_array($row)
            && strtolower(trim((string)($row['provider'] ?? ''))) === 'ollama'
            && rtrim(trim((string)($row['base_url'] ?? '')), '/') === self::BASE_URL . '/v1'
            && trim((string)($row['model_name'] ?? '')) === self::TEXT_MODEL;
        return ['ready' => $ready, 'error_code' => $ready ? null : 'local_model_config_missing'];
    }

    private function audioCapability(): array
    {
        $python = root_path() . 'storage' . DIRECTORY_SEPARATOR . 'local-ai' . DIRECTORY_SEPARATOR
            . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        $script = root_path() . 'scripts' . DIRECTORY_SEPARATOR . 'local_media_extract.py';
        $marker = root_path() . 'storage' . DIRECTORY_SEPARATOR . 'local-ai' . DIRECTORY_SEPARATOR
            . 'models' . DIRECTORY_SEPARATOR . 'small.ready.json';
        $runtime = [];
        if (is_file($marker)) {
            $decoded = json_decode((string)file_get_contents($marker), true);
            $runtime = is_array($decoded) ? $decoded : [];
        }
        $ready = is_file($python) && is_file($script) && ($runtime['model'] ?? '') === 'small';
        return [
            'status' => $ready ? 'ready' : 'blocked_not_configured',
            'method' => 'faster_whisper_local',
            'model' => 'small',
            'runtime_version' => (string)($runtime['runtime_version'] ?? ''),
            'device' => (string)($runtime['device'] ?? 'cpu'),
            'ready' => $ready,
            'error_code' => $ready ? null : (is_file($python) && is_file($script) ? 'asr_model_missing' : 'asr_runtime_missing'),
        ];
    }

    /** @return array{ok:bool,data:array<string,mixed>} */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'data' => []];
        }
        $handle = curl_init(self::BASE_URL . $path);
        if ($handle === false) {
            return ['ok' => false, 'data' => []];
        }
        $headers = ['Accept: application/json'];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload ?? [], JSON_UNESCAPED_SLASHES));
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            return ['ok' => false, 'data' => []];
        }
        $data = json_decode($body, true);
        return ['ok' => is_array($data), 'data' => is_array($data) ? $data : []];
    }
}
