<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class LlmClientTest extends TestCase
{
    use ReflectionHelper;

    public function testConfigIssueIncludesActionableConfigurationMetadata(): void
    {
        $client = new LlmClient();

        $issue = $this->invokeNonPublic($client, 'configIssue', [
            'AI模型 API Key 为空，请重新保存密钥。',
            [
                'provider' => 'deepseek',
                'model_key' => 'deepseek_chat',
                'model' => 'deepseek-chat',
                'source' => 'database',
            ],
        ]);

        self::assertFalse($issue['ok']);
        self::assertSame(400, $issue['code']);
        self::assertSame('/ai-model-config', $issue['config_entry']);
        self::assertStringContainsString('API Key', $issue['message']);
        self::assertStringContainsString('Base URL', $issue['next_action']);
        self::assertSame('deepseek_chat', $issue['model_key']);
    }

    public function testChatEndpointUrlSupportsProviderSpecificPathsAndQueryStrings(): void
    {
        $client = new LlmClient();

        self::assertSame(
            'https://api.mistral.ai/v1/chat/completions',
            $this->invokeNonPublic($client, 'chatEndpointUrl', ['https://api.mistral.ai/v1', 'mistral'])
        );
        self::assertSame(
            'https://api.perplexity.ai/v1/sonar',
            $this->invokeNonPublic($client, 'chatEndpointUrl', ['https://api.perplexity.ai/v1', 'perplexity'])
        );
        self::assertSame(
            'https://example.services.ai.azure.com/models/chat/completions?api-version=2024-05-01-preview',
            $this->invokeNonPublic($client, 'chatEndpointUrl', ['https://example.services.ai.azure.com/models?api-version=2024-05-01-preview', 'microsoft_phi'])
        );
    }
}
