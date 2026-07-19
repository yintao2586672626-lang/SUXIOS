<?php
declare(strict_types=1);

namespace app\controller;

use app\model\AiModelConfig;
use app\model\OperationLog;
use app\service\LlmEndpoint;
use app\service\OutboundUrlGuard;
use InvalidArgumentException;
use think\facade\Db;
use think\Response;

class AiConfig extends Base
{
    public function models(): Response
    {
        $this->checkSuperAdmin();

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
        $this->checkSuperAdmin();

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
        $this->checkSuperAdmin();

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
        $this->checkSuperAdmin();

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
        $this->checkSuperAdmin();

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
            (string) $apiKey['api_key'],
            (string) $model->provider
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
        $this->checkSuperAdmin();

        $provider = strtolower(trim((string) $this->request->param('provider', '')));
        $baseUrlOverride = trim((string) $this->request->param('base_url', ''));
        $apiKey = trim((string) $this->request->param('api_key', ''));
        $definitions = $this->providerModelDefinitions($provider, $baseUrlOverride);
        if ($definitions === []) {
            if ($this->requiresCustomQuickSetupBaseUrl($provider)) {
                return $this->error('该模型族需要填写 OpenAI 兼容 Base URL 后才能快速配置', 422);
            }
            return $this->error('provider 不在当前快速接入范围内', 422);
        }
        foreach ($definitions as $definition) {
            $baseUrlError = $this->validateAiBaseUrl((string)($definition['base_url'] ?? ''));
            if ($baseUrlError !== null) {
                return $this->error($baseUrlError, 422);
            }
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
        if (isset($data['base_url'])) {
            $baseUrlError = $this->validateAiBaseUrl((string)$data['base_url']);
            if ($baseUrlError !== null) {
                return $baseUrlError;
            }
        }

        return null;
    }

    private function validateAiBaseUrl(string $baseUrl): ?string
    {
        try {
            (new OutboundUrlGuard())->validate($baseUrl);
            return null;
        } catch (InvalidArgumentException $exception) {
            return match ($exception->getMessage()) {
                OutboundUrlGuard::ERROR_HTTPS_REQUIRED => 'AI Base URL 必须使用 HTTPS',
                OutboundUrlGuard::ERROR_CREDENTIALS_NOT_ALLOWED => 'AI Base URL 不允许包含用户信息',
                OutboundUrlGuard::ERROR_PORT_NOT_ALLOWED => 'AI Base URL 仅允许使用 443 端口',
                default => 'AI Base URL 主机不可访问或不允许访问',
            };
        }
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
            'deepseek_v4_flash' => [
                'name' => 'DeepSeek 经济模式',
                'model_key' => 'deepseek_chat',
                'provider' => 'deepseek',
                'base_url' => 'https://api.deepseek.com/v1',
                'model_name' => 'deepseek-chat',
                'usage_scene' => 'ota_diagnosis',
                'is_default' => 1,
            ],
            'deepseek_v4_fast' => [
                'name' => 'DeepSeek 经济模式',
                'model_key' => 'deepseek_chat',
                'provider' => 'deepseek',
                'base_url' => 'https://api.deepseek.com/v1',
                'model_name' => 'deepseek-chat',
                'usage_scene' => 'ota_diagnosis',
                'is_default' => 1,
            ],
            'deepseek_v4_pro' => [
                'name' => 'DeepSeek 深度推理',
                'model_key' => 'deepseek_reasoner',
                'provider' => 'deepseek',
                'base_url' => 'https://api.deepseek.com/v1',
                'model_name' => 'deepseek-reasoner',
                'usage_scene' => 'reasoning',
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

    private function providerModelDefinitions(string $provider, string $baseUrlOverride = ''): array
    {
        $provider = strtolower(trim($provider));
        $baseUrlOverride = rtrim(trim($baseUrlOverride), '/');

        if ($provider === 'deepseek') {
            $baseUrl = $baseUrlOverride !== '' ? $baseUrlOverride : 'https://api.deepseek.com/v1';
            return [
                $this->quickModelDefinition('DeepSeek Chat', 'deepseek_chat', 'deepseek', $baseUrl, 'deepseek-chat', 'ota_diagnosis', 1),
                $this->quickModelDefinition('DeepSeek Reasoner', 'deepseek_reasoner', 'deepseek', $baseUrl, 'deepseek-reasoner', 'reasoning'),
            ];
        }

        if ($provider === 'openai') {
            $baseUrl = $baseUrlOverride !== '' ? $baseUrlOverride : 'https://api.openai.com/v1';
            return [
                $this->quickModelDefinition('OpenAI GPT', 'openai_gpt', 'openai', $baseUrl, $this->configEnv('OPENAI_MODEL', 'gpt-5.1'), 'general,agent,report'),
                $this->quickModelDefinition('OpenAI Fast', 'openai_fast', 'openai', $baseUrl, $this->configEnv('OPENAI_FAST_MODEL', $this->configEnv('OPENAI_MODEL', 'gpt-5-mini')), 'report,web_search'),
            ];
        }

        $directProviders = [
            'anthropic' => ['Anthropic Claude', 'anthropic_claude', 'https://api.anthropic.com/v1', 'ANTHROPIC_MODEL', 'claude-opus-4-7', 'long_context,code,report'],
            'gemini' => ['Google Gemini', 'gemini_flash', 'https://generativelanguage.googleapis.com/v1beta/openai', 'GEMINI_MODEL', 'gemini-3.5-flash', 'multimodal,workspace,report'],
            'xai' => ['xAI Grok', 'xai_grok', 'https://api.x.ai/v1', 'XAI_MODEL', 'grok-4.3', 'realtime,analysis,code'],
            'mistral' => ['Mistral Large', 'mistral_large', 'https://api.mistral.ai/v1', 'MISTRAL_MODEL', 'mistral-large-latest', 'code,agent,low_cost'],
            'cohere' => ['Cohere Command', 'cohere_command', 'https://api.cohere.ai/compatibility/v1', 'COHERE_MODEL', 'command-a-plus-05-2026', 'enterprise_search,rag'],
            'perplexity' => ['Perplexity Sonar', 'perplexity_sonar', 'https://api.perplexity.ai/v1', 'PERPLEXITY_MODEL', 'sonar-pro', 'search,research'],
            'nvidia' => ['NVIDIA Nemotron', 'nvidia_nemotron', 'https://integrate.api.nvidia.com/v1', 'NVIDIA_MODEL', 'nvidia/llama-3.1-nemotron-ultra-253b-v1', 'private_deploy,gpu,agent'],
            'xiaomi_mimo' => ['Xiaomi MiMo V2.5 Pro', 'xiaomi_mimo_pro', 'https://api.xiaomimimo.com/v1', 'XIAOMI_MIMO_MODEL', 'mimo-v2.5-pro', 'reasoning,long_context,agent'],
        ];

        if (isset($directProviders[$provider])) {
            [$name, $modelKey, $baseUrl, $envKey, $defaultModel, $usageScene] = $directProviders[$provider];
            return [
                $this->quickModelDefinition($name, $modelKey, $provider, $baseUrlOverride !== '' ? $baseUrlOverride : $baseUrl, $this->configEnv($envKey, $defaultModel), $usageScene),
            ];
        }

        $gatewayProviders = [
            'meta_llama' => ['Meta Llama', 'meta_llama', 'META_LLAMA_MODEL', 'llama-4-maverick', 'open_source,private_deploy,research'],
            'amazon_nova' => ['Amazon Nova', 'amazon_nova', 'AMAZON_NOVA_MODEL', 'amazon.nova-pro-v1:0', 'aws,enterprise,workflow'],
            'microsoft_phi' => ['Microsoft Phi', 'microsoft_phi', 'MICROSOFT_PHI_MODEL', 'Phi-4-mini-instruct', 'edge,low_cost,education'],
            'ibm_granite' => ['IBM Granite', 'ibm_granite', 'IBM_GRANITE_MODEL', 'ibm/granite-3-3-8b-instruct', 'governance,enterprise,knowledge'],
        ];

        if (isset($gatewayProviders[$provider])) {
            if ($baseUrlOverride === '') {
                return [];
            }
            [$name, $modelKey, $envKey, $defaultModel, $usageScene] = $gatewayProviders[$provider];
            return [
                $this->quickModelDefinition($name, $modelKey, $provider, $baseUrlOverride, $this->configEnv($envKey, $defaultModel), $usageScene),
            ];
        }

        return [];
    }

    private function requiresCustomQuickSetupBaseUrl(string $provider): bool
    {
        return in_array(strtolower(trim($provider)), ['meta_llama', 'amazon_nova', 'microsoft_phi', 'ibm_granite'], true);
    }

    private function quickModelDefinition(string $name, string $modelKey, string $provider, string $baseUrl, string $modelName, string $usageScene, int $isDefault = 0): array
    {
        return [
            'name' => $name,
            'model_key' => $modelKey,
            'provider' => $provider,
            'base_url' => rtrim($baseUrl, '/'),
            'model_name' => $modelName,
            'usage_scene' => $usageScene,
            'is_default' => $isDefault,
        ];
    }

    private function configEnv(string $key, string $default): string
    {
        try {
            $value = env($key, $default);
        } catch (\Throwable $e) {
            $value = getenv($key);
            if ($value === false) {
                $value = $default;
            }
        }

        $value = trim((string) $value);
        return $value !== '' ? $value : $default;
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

    private function testChatCompletion(string $baseUrl, string $modelName, string $apiKey, string $provider = ''): array
    {
        try {
            $target = LlmEndpoint::chatCompletionTarget($baseUrl, $provider);
        } catch (InvalidArgumentException) {
            return ['ok' => false, 'message' => 'AI Base URL 主机不可访问或不允许访问', 'code' => 422];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => '网络请求组件不可用', 'code' => 502];
        }

        $payload = [
            'model' => $modelName,
            'messages' => [
                ['role' => 'user', 'content' => '请用一句话回复：模型连通性正常。'],
            ],
            'temperature' => 0.2,
        ];

        $ch = curl_init($target['url']);
        if ($ch === false) {
            return ['ok' => false, 'message' => '网络请求失败', 'code' => 502];
        }
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
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
        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false) {
            return ['ok' => false, 'message' => '网络请求失败', 'code' => 502];
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

    private function checkSuperAdmin(): void
    {
        $this->checkLogin();
        if (!$this->currentUser->isSuperAdmin()) {
            abort(403, '无权限操作');
        }
    }
}
