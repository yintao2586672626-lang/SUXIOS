<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AiConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class AiConfigTest extends TestCase
{
    use ReflectionHelper;

    private function controller(): AiConfig
    {
        $reflection = new ReflectionClass(AiConfig::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    public function testProviderModelDefinitionsCoverMainstreamAiProviders(): void
    {
        $controller = $this->controller();

        $expected = [
            'anthropic' => ['anthropic_claude', 'anthropic', 'https://api.anthropic.com/v1'],
            'gemini' => ['gemini_flash', 'gemini', 'https://generativelanguage.googleapis.com/v1beta/openai'],
            'xai' => ['xai_grok', 'xai', 'https://api.x.ai/v1'],
            'mistral' => ['mistral_large', 'mistral', 'https://api.mistral.ai/v1'],
            'cohere' => ['cohere_command', 'cohere', 'https://api.cohere.ai/compatibility/v1'],
            'perplexity' => ['perplexity_sonar', 'perplexity', 'https://api.perplexity.ai/v1'],
            'nvidia' => ['nvidia_nemotron', 'nvidia', 'https://integrate.api.nvidia.com/v1'],
        ];

        foreach ($expected as $provider => [$modelKey, $providerName, $baseUrl]) {
            $definitions = $this->invokeNonPublic($controller, 'providerModelDefinitions', [$provider]);
            self::assertNotEmpty($definitions, $provider . ' should expose quick setup definitions');
            self::assertSame($modelKey, $definitions[0]['model_key']);
            self::assertSame($providerName, $definitions[0]['provider']);
            self::assertSame($baseUrl, $definitions[0]['base_url']);
        }
    }

    public function testDeepSeekQuickSetupUsesCurrentV4ProviderModelNames(): void
    {
        $definitions = $this->invokeNonPublic(
            $this->controller(),
            'providerModelDefinitions',
            ['deepseek']
        );

        self::assertSame(['deepseek_chat', 'deepseek_reasoner'], array_column($definitions, 'model_key'));
        self::assertSame(['deepseek-v4-flash', 'deepseek-v4-pro'], array_column($definitions, 'model_name'));
        self::assertSame(['deepseek', 'deepseek'], array_column($definitions, 'provider'));
        self::assertSame(
            'deepseek-v4-flash',
            $this->invokeNonPublic($this->controller(), 'resolvedProviderModelName', ['deepseek', 'deepseek-chat'])
        );
        self::assertSame(
            'deepseek-v4-pro',
            $this->invokeNonPublic($this->controller(), 'resolvedProviderModelName', ['deepseek', 'deepseek-reasoner'])
        );
    }

    public function testDeepSeekV4MigrationOnlyRewritesKnownLegacyProviderModels(): void
    {
        $sql = (string)file_get_contents(
            dirname(__DIR__) . '/database/migrations/20260812_zz_update_deepseek_v4_model_names.sql'
        );

        self::assertStringContainsString("`provider` = 'deepseek'", $sql);
        self::assertStringContainsString("`model_key` = 'deepseek_chat'", $sql);
        self::assertStringContainsString("`model_name` = 'deepseek-chat'", $sql);
        self::assertStringContainsString("THEN 'deepseek-v4-flash'", $sql);
        self::assertStringContainsString("THEN 'deepseek-v4-pro'", $sql);
        self::assertStringNotContainsString('api_key', strtolower($sql));
    }

    public function testProviderModelDefinitionsRequireCustomBaseUrlForGatewayBackedFamilies(): void
    {
        $controller = $this->controller();

        self::assertSame([], $this->invokeNonPublic($controller, 'providerModelDefinitions', ['meta_llama']));

        $definitions = $this->invokeNonPublic($controller, 'providerModelDefinitions', [
            'meta_llama',
            'https://gateway.example.com/v1',
        ]);

        self::assertSame('meta_llama', $definitions[0]['model_key']);
        self::assertSame('meta_llama', $definitions[0]['provider']);
        self::assertSame('https://gateway.example.com/v1', $definitions[0]['base_url']);
    }

    public function testProviderModelDefinitionsExposeKeylessLocalOllamaModel(): void
    {
        $definitions = $this->invokeNonPublic(
            $this->controller(),
            'providerModelDefinitions',
            ['ollama']
        );

        self::assertSame('ollama_qwen3_8b', $definitions[0]['model_key']);
        self::assertSame('ollama', $definitions[0]['provider']);
        self::assertSame('http://127.0.0.1:11434/v1', $definitions[0]['base_url']);
        self::assertSame('qwen3:8b', $definitions[0]['model_name']);
        self::assertFalse($this->invokeNonPublic($this->controller(), 'providerRequiresApiKey', ['ollama']));
    }

    public function testModelPayloadAllowsOnlyOllamaToUsePinnedLocalBaseUrl(): void
    {
        $controller = $this->controller();
        $payload = [
            'name' => 'Local Qwen',
            'model_key' => 'ollama_qwen3_8b',
            'provider' => 'ollama',
            'base_url' => 'http://127.0.0.1:11434/v1',
            'model_name' => 'qwen3:8b',
        ];

        self::assertNull($this->invokeNonPublic($controller, 'validateModelPayload', [$payload, true]));

        $payload['provider'] = 'openai';
        self::assertIsString($this->invokeNonPublic($controller, 'validateModelPayload', [$payload, true]));
    }

    public function testModelPayloadRejectsLocalAiBaseUrlBeforeSavingApiKey(): void
    {
        $error = $this->invokeNonPublic($this->controller(), 'validateModelPayload', [[
            'name' => 'Unsafe model',
            'model_key' => 'unsafe_model',
            'provider' => 'openai',
            'base_url' => 'https://127.0.0.1/v1',
            'model_name' => 'unsafe',
        ], true]);

        self::assertIsString($error);
        self::assertStringContainsString('Base URL', $error);
        self::assertStringNotContainsString('127.0.0.1', $error);
    }
}
