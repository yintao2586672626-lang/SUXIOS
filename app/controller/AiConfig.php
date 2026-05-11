<?php
declare(strict_types=1);

namespace app\controller;

use app\model\AiModelConfig;
use app\model\OperationLog;
use think\facade\Db;
use think\Response;

class AiConfig extends Base
{
    public function models(): Response
    {
        $this->checkLogin();

        $models = AiModelConfig::order('is_default', 'desc')
            ->order('id', 'asc')
            ->select()
            ->map(function (AiModelConfig $model): array {
                return $model->toSafeArray();
            })
            ->toArray();

        return $this->success($models, 'success');
    }

    public function createModel(): Response
    {
        $this->checkLogin();

        $data = $this->request->param();
        $error = $this->validateModelPayload($data, true);
        if ($error !== null) {
            return $this->error($error, 422);
        }

        $modelKey = trim((string) $data['model_key']);
        if (AiModelConfig::where('model_key', $modelKey)->find()) {
            return $this->error('model_key 已存在', 422);
        }

        $apiKey = trim((string) ($data['api_key'] ?? ''));
        $encryptedApiKey = '';
        $apiKeyMask = '';
        if ($apiKey !== '') {
            $secret = $this->aiConfigSecret();
            if ($secret === '') {
                return $this->error('未配置 AI_CONFIG_SECRET', 400);
            }

            $encryptedApiKey = AiModelConfig::encryptApiKey($apiKey, $secret);
            if ($encryptedApiKey === null) {
                return $this->error('API Key 加密失败', 500);
            }
            $apiKeyMask = AiModelConfig::maskApiKey($apiKey);
        }

        $model = new AiModelConfig();
        $this->fillModelConfig($model, $data);
        $model->api_key_encrypted = $encryptedApiKey;
        $model->api_key_mask = $apiKeyMask;
        $model->save();

        if ((int) $model->is_default === 1) {
            $this->clearOtherDefault((int) $model->id);
        }

        OperationLog::record('ai_config', 'create', '创建AI模型配置: ' . $model->model_key, (int) ($this->currentUser->id ?? 0));

        return $this->success($model->toSafeArray(), '创建成功');
    }

    public function updateModel(int $id): Response
    {
        $this->checkLogin();

        $model = AiModelConfig::find($id);
        if (!$model) {
            return $this->error('模型配置不存在', 404);
        }

        $data = $this->request->param();
        $error = $this->validateModelPayload($data, false);
        if ($error !== null) {
            return $this->error($error, 422);
        }

        if (isset($data['model_key'])) {
            $modelKey = trim((string) $data['model_key']);
            $exists = AiModelConfig::where('model_key', $modelKey)
                ->where('id', '<>', $id)
                ->find();
            if ($exists) {
                return $this->error('model_key 已存在', 422);
            }
        }

        $apiKey = trim((string) ($data['api_key'] ?? ''));
        if ($apiKey !== '') {
            $secret = $this->aiConfigSecret();
            if ($secret === '') {
                return $this->error('未配置 AI_CONFIG_SECRET', 400);
            }

            $encryptedApiKey = AiModelConfig::encryptApiKey($apiKey, $secret);
            if ($encryptedApiKey === null) {
                return $this->error('API Key 加密失败', 500);
            }
            $model->api_key_encrypted = $encryptedApiKey;
            $model->api_key_mask = AiModelConfig::maskApiKey($apiKey);
        }

        $this->fillModelConfig($model, $data);
        $model->save();

        if ((int) $model->is_default === 1) {
            $this->clearOtherDefault((int) $model->id);
        }

        OperationLog::record('ai_config', 'update', '更新AI模型配置: ' . $model->model_key, (int) ($this->currentUser->id ?? 0));

        return $this->success($model->toSafeArray(), '更新成功');
    }

    public function deleteModel(int $id): Response
    {
        $this->checkLogin();

        $model = AiModelConfig::find($id);
        if (!$model) {
            return $this->error('模型配置不存在', 404);
        }

        $model->is_enabled = 0;
        $model->is_default = 0;
        $model->save();

        OperationLog::record('ai_config', 'disable', '禁用AI模型配置: ' . $model->model_key, (int) ($this->currentUser->id ?? 0));

        return $this->success($model->toSafeArray(), '禁用成功');
    }

    public function testModel(int $id): Response
    {
        $this->checkLogin();

        $model = AiModelConfig::find($id);
        if (!$model) {
            return $this->error('模型配置不存在', 404);
        }
        if ((int) $model->is_enabled !== 1) {
            return $this->error('模型配置已禁用', 400);
        }

        $apiKey = $this->decryptModelApiKey($model);
        if ($apiKey['ok'] !== true) {
            return $this->error($apiKey['message'], (int) $apiKey['code']);
        }

        $result = $this->testChatCompletion(
            (string) $model->base_url,
            (string) $model->model_name,
            (string) $apiKey['api_key']
        );
        if (($result['ok'] ?? false) !== true) {
            return $this->error((string) $result['message'], (int) $result['code']);
        }

        return $this->success([
            'ok' => true,
            'model_key' => (string) $model->model_key,
            'content' => mb_substr((string) $result['content'], 0, 200),
        ], '测试成功');
    }

    public function quickSetupProvider(): Response
    {
        $this->checkLogin();

        $provider = strtolower(trim((string) $this->request->param('provider', '')));
        $apiKey = trim((string) $this->request->param('api_key', ''));
        if (!in_array($provider, ['deepseek', 'openai'], true)) {
            return $this->error('provider 仅支持 deepseek/openai', 422);
        }
        if ($apiKey === '') {
            return $this->error('API Key 不能为空', 422);
        }

        $secret = $this->aiConfigSecret();
        if ($secret === '') {
            return $this->error('未配置 AI_CONFIG_SECRET', 400);
        }

        $encryptedApiKey = AiModelConfig::encryptApiKey($apiKey, $secret);
        if ($encryptedApiKey === null) {
            return $this->error('API Key 加密失败', 500);
        }
        $apiKeyMask = AiModelConfig::maskApiKey($apiKey);

        $created = 0;
        $updated = 0;
        $models = [];
        $definitions = $this->providerModelDefinitions($provider);

        Db::transaction(function () use ($provider, $definitions, $encryptedApiKey, $apiKeyMask, &$created, &$updated, &$models): void {
            if ($provider === 'deepseek') {
                $this->migrateDeepSeekLegacyModels($encryptedApiKey, $apiKeyMask);
            }

            foreach ($definitions as $definition) {
                $model = AiModelConfig::where('model_key', $definition['model_key'])->find();
                if ($model) {
                    $updated++;
                } else {
                    $model = new AiModelConfig();
                    $model->model_key = $definition['model_key'];
                    $created++;
                }

                $model->name = $definition['name'];
                $model->provider = $definition['provider'];
                $model->base_url = $definition['base_url'];
                $model->model_name = $definition['model_name'];
                $model->usage_scene = $definition['usage_scene'];
                $model->is_default = $definition['is_default'];
                $model->is_enabled = 1;
                $model->api_key_encrypted = $encryptedApiKey;
                $model->api_key_mask = $apiKeyMask;
                $model->save();

                if ((int) $model->is_default === 1) {
                    $this->clearOtherDefault((int) $model->id);
                }

                $models[] = $model->toSafeArray();
            }
        });

        OperationLog::record(
            'ai_config',
            'quick_setup',
            sprintf('快速配置AI厂家: %s, created=%d, updated=%d', $provider, $created, $updated),
            (int) ($this->currentUser->id ?? 0)
        );

        return $this->success([
            'created' => $created,
            'updated' => $updated,
            'models' => $models,
        ], '自动配置成功');
    }

    private function validateModelPayload(array $data, bool $isCreate): ?string
    {
        $requiredFields = ['name', 'model_key', 'provider', 'base_url', 'model_name'];
        foreach ($requiredFields as $field) {
            if ($isCreate && trim((string) ($data[$field] ?? '')) === '') {
                return $field . ' 不能为空';
            }
        }

        if (isset($data['model_key']) && !preg_match('/^[a-zA-Z0-9_\-]+$/', trim((string) $data['model_key']))) {
            return 'model_key 只能包含字母、数字、下划线和中划线';
        }
        if (isset($data['provider']) && !preg_match('/^[a-zA-Z0-9_\-]+$/', trim((string) $data['provider']))) {
            return 'provider 只能包含字母、数字、下划线和中划线';
        }

        return null;
    }

    private function fillModelConfig(AiModelConfig $model, array $data): void
    {
        foreach (['name', 'model_key', 'provider', 'base_url', 'model_name', 'usage_scene'] as $field) {
            if (isset($data[$field])) {
                $model->$field = trim((string) $data[$field]);
            }
        }

        if (array_key_exists('is_default', $data)) {
            $model->is_default = (int) ((int) $data['is_default'] === 1);
        } elseif (!$model->isExists()) {
            $model->is_default = 0;
        }

        if (array_key_exists('is_enabled', $data)) {
            $model->is_enabled = (int) ((int) $data['is_enabled'] === 1);
        } elseif (!$model->isExists()) {
            $model->is_enabled = 1;
        }
    }

    private function clearOtherDefault(int $id): void
    {
        AiModelConfig::where('id', '<>', $id)->update(['is_default' => 0]);
    }

    private function migrateDeepSeekLegacyModels(string $encryptedApiKey, string $apiKeyMask): void
    {
        $legacyMap = [
            'deepseek_chat' => [
                'name' => 'DeepSeek V4 Flash',
                'model_key' => 'deepseek_v4_flash',
                'provider' => 'deepseek',
                'base_url' => 'https://api.deepseek.com',
                'model_name' => 'deepseek-v4-flash',
                'usage_scene' => 'fast_analysis',
                'is_default' => 1,
            ],
            'deepseek_reasoner' => [
                'name' => 'DeepSeek V4 Pro',
                'model_key' => 'deepseek_v4_pro',
                'provider' => 'deepseek',
                'base_url' => 'https://api.deepseek.com',
                'model_name' => 'deepseek-v4-pro',
                'usage_scene' => 'deep_report',
                'is_default' => 0,
            ],
        ];

        foreach ($legacyMap as $legacyKey => $definition) {
            $legacy = AiModelConfig::where('model_key', $legacyKey)->find();
            if (!$legacy) {
                continue;
            }

            $target = AiModelConfig::where('model_key', $definition['model_key'])->find();
            if ($target && (int) $target->id !== (int) $legacy->id) {
                $legacy->is_enabled = 0;
                $legacy->is_default = 0;
                $legacy->save();
                continue;
            }

            foreach (['name', 'model_key', 'provider', 'base_url', 'model_name', 'usage_scene'] as $field) {
                $legacy->$field = $definition[$field];
            }
            $legacy->is_default = $definition['is_default'];
            $legacy->is_enabled = 1;
            $legacy->api_key_encrypted = $encryptedApiKey;
            $legacy->api_key_mask = $apiKeyMask;
            $legacy->save();

            if ((int) $legacy->is_default === 1) {
                $this->clearOtherDefault((int) $legacy->id);
            }
        }
    }

    private function providerModelDefinitions(string $provider): array
    {
        if ($provider === 'deepseek') {
            return [
                [
                    'name' => 'DeepSeek V4 Flash',
                    'model_key' => 'deepseek_v4_flash',
                    'provider' => 'deepseek',
                    'base_url' => 'https://api.deepseek.com',
                    'model_name' => 'deepseek-v4-flash',
                    'usage_scene' => 'fast_analysis',
                    'is_default' => 1,
                ],
                [
                    'name' => 'DeepSeek V4 Pro',
                    'model_key' => 'deepseek_v4_pro',
                    'provider' => 'deepseek',
                    'base_url' => 'https://api.deepseek.com',
                    'model_name' => 'deepseek-v4-pro',
                    'usage_scene' => 'deep_report',
                    'is_default' => 0,
                ],
            ];

            return [
                [
                    'name' => 'DeepSeek 经济模式',
                    'model_key' => 'deepseek_chat',
                    'provider' => 'deepseek',
                    'base_url' => 'https://api.deepseek.com/v1',
                    'model_name' => 'deepseek-chat',
                    'usage_scene' => 'ota_diagnosis',
                    'is_default' => 1,
                ],
                [
                    'name' => 'DeepSeek 深度推理',
                    'model_key' => 'deepseek_reasoner',
                    'provider' => 'deepseek',
                    'base_url' => 'https://api.deepseek.com/v1',
                    'model_name' => 'deepseek-reasoner',
                    'usage_scene' => 'reasoning',
                    'is_default' => 0,
                ],
            ];
        }

        $openAiModel = trim((string) env('OPENAI_MODEL', ''));
        if ($openAiModel === '') {
            $openAiModel = 'gpt-4.1-mini';
        }

        return [
            [
                'name' => 'OpenAI 快速模式',
                'model_key' => 'openai_fast',
                'provider' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'model_name' => $openAiModel,
                'usage_scene' => 'report',
                'is_default' => 0,
            ],
        ];
    }

    private function decryptModelApiKey(AiModelConfig $model): array
    {
        if (trim((string) $model->api_key_encrypted) === '') {
            return ['ok' => false, 'message' => '未配置模型 API Key', 'code' => 400];
        }

        $secret = $this->aiConfigSecret();
        if ($secret === '') {
            return ['ok' => false, 'message' => '未配置 AI_CONFIG_SECRET', 'code' => 400];
        }

        $apiKey = AiModelConfig::decryptApiKey((string) $model->api_key_encrypted, $secret);
        if ($apiKey === null) {
            return ['ok' => false, 'message' => '模型 API Key 解密失败', 'code' => 400];
        }

        return ['ok' => true, 'api_key' => $apiKey];
    }

    private function testChatCompletion(string $baseUrl, string $modelName, string $apiKey): array
    {
        $payload = [
            'model' => $modelName,
            'messages' => [
                ['role' => 'user', 'content' => '请用一句话回复：模型连通性正常。'],
            ],
            'temperature' => 0.2,
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ]),
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(rtrim($baseUrl, '/') . '/chat/completions', false, $context);
        if ($response === false) {
            return ['ok' => false, 'message' => '网络请求失败', 'code' => 502];
        }

        $statusCode = 0;
        $headers = $http_response_header ?? [];
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
            $statusCode = (int) $matches[1];
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            return ['ok' => false, 'message' => '模型返回异常', 'code' => 502];
        }

        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            return ['ok' => false, 'message' => '模型返回异常', 'code' => 502];
        }

        return ['ok' => true, 'content' => trim($content)];
    }

    private function aiConfigSecret(): string
    {
        return trim((string) env('AI_CONFIG_SECRET', ''));
    }

    private function checkLogin(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
    }
}
