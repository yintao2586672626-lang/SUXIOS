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
}
