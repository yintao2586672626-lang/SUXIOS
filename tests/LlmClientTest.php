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

    public function testGovernanceMetaTracksPromptVersionSourcesConfidenceAndReview(): void
    {
        $client = new LlmClient();
        $prompt = 'OTA diagnosis prompt';

        $governance = $this->invokeNonPublic($client, 'buildGovernanceMeta', [
            $prompt,
            'deepseek_chat',
            [
                'module' => 'agent',
                'scenario' => 'ota_diagnosis',
                'prompt_version' => 'ota_diagnosis:v1',
                'knowledge_sources' => [
                    ['ref' => 'knowledge_units#7', 'title' => 'OTA metric knowledge'],
                    'online_daily_data#10',
                ],
                'confidence_score' => 0.42,
                'human_confirmation_required' => true,
                'human_confirmation_reason' => 'operator review required',
                'decision_impact' => 'operational',
                'evaluation_set' => 'ota_diagnosis_governance_v1',
                'eval_case_id' => 'ota_governance_regression_v1',
            ],
            [],
        ]);

        self::assertSame('ota_diagnosis:v1', $governance['prompt_version']);
        self::assertSame(hash('sha256', $prompt), $governance['prompt_hash']);
        self::assertSame('deepseek_chat', $governance['model_key']);
        self::assertSame(0.42, $governance['confidence_score']);
        self::assertTrue($governance['low_confidence']);
        self::assertTrue($governance['human_confirmation_required']);
        self::assertSame('pending', $governance['human_confirmation_status']);
        self::assertSame('operator review required', $governance['human_confirmation_reason']);
        self::assertSame('ota_diagnosis_governance_v1', $governance['evaluation_set']);
        self::assertSame('ota_governance_regression_v1', $governance['eval_case_id']);
        self::assertSame('knowledge_units#7', $governance['knowledge_sources'][0]['ref']);
        self::assertSame('online_daily_data#10', $governance['knowledge_sources'][1]['ref']);
        self::assertArrayNotHasKey('prompt', $governance);
    }

    public function testSchemaGovernanceIsSeparatedFromPromptSchema(): void
    {
        $client = new LlmClient();
        $schema = [
            'x-governance' => [
                'module' => 'agent',
                'scenario' => 'ota_diagnosis',
                'prompt_version' => 'agent.ota_diagnosis.v1',
                'decision_impact' => 'operational',
                'knowledge_sources' => ['online_daily_data', ['ref' => 'knowledge#1', 'label' => 'OTA知识库']],
            ],
            'type' => 'object',
            'properties' => ['summary' => ['type' => 'string']],
        ];

        $meta = $this->invokeNonPublic($client, 'schemaGovernanceMeta', [$schema]);
        $clean = $this->invokeNonPublic($client, 'schemaWithoutGovernance', [$schema]);
        $prompt = $this->invokeNonPublic($client, 'messagesToPrompt', [[['role' => 'user', 'content' => 'hello']], $clean]);

        self::assertSame('agent.ota_diagnosis.v1', $meta['prompt_version']);
        self::assertArrayNotHasKey('x-governance', $clean);
        self::assertStringNotContainsString('x-governance', $prompt);
        self::assertStringContainsString('"summary"', $prompt);
    }

    public function testNormalizeKnowledgeSourcesAcceptsStringLists(): void
    {
        $client = new LlmClient();

        $sources = $this->invokeNonPublic($client, 'normalizeKnowledgeSources', [
            "knowledge_units#7\nonline_daily_data#10,price_suggestions#3",
        ]);

        self::assertSame('knowledge_units#7', $sources[0]['ref']);
        self::assertSame('online_daily_data#10', $sources[1]['ref']);
        self::assertSame('price_suggestions#3', $sources[2]['ref']);
    }

    public function testDecisionImpactWithoutConfidenceOrSourcesIsLowConfidence(): void
    {
        $client = new LlmClient();

        $governance = $this->invokeNonPublic($client, 'buildGovernanceMeta', [
            'investment decision prompt',
            'deepseek_chat',
            [
                'module' => 'expansion',
                'scenario' => 'market_evaluation',
                'prompt_version' => 'expansion.market_evaluation.v1',
                'decision_impact' => 'investment',
            ],
            [],
        ]);

        self::assertTrue($governance['low_confidence']);
        self::assertTrue($governance['human_confirmation_required']);
        self::assertSame('pending', $governance['human_confirmation_status']);
        self::assertStringContainsString('confidence', $governance['low_confidence_reason']);
        self::assertStringContainsString('source', $governance['low_confidence_reason']);
    }

    public function testDecisionImpactWithSourcesAndConfidenceStillRequiresEvaluationSet(): void
    {
        $client = new LlmClient();

        $governance = $this->invokeNonPublic($client, 'buildGovernanceMeta', [
            'operational decision prompt',
            'deepseek_chat',
            [
                'module' => 'agent',
                'scenario' => 'ota_diagnosis',
                'prompt_version' => 'agent.ota_diagnosis.v1',
                'decision_impact' => 'operational',
                'confidence_score' => 0.91,
                'knowledge_sources' => ['online_daily_data#10'],
            ],
            [],
        ]);

        self::assertTrue($governance['low_confidence']);
        self::assertTrue($governance['human_confirmation_required']);
        self::assertStringContainsString('evaluation', $governance['low_confidence_reason']);
    }

    public function testSanitizeRedactsSensitiveGovernancePreviewFields(): void
    {
        $client = new LlmClient();

        $sanitized = $this->invokeNonPublic($client, 'sanitize', [
            'Authorization: Bearer liveBearerSecret api_key=rawApiKey cookie=sessionCookie; session_id=sessionSecret refresh_token=refreshSecret password=plainPassword ctripToken=otaSecret',
            1000,
        ]);

        self::assertStringContainsString('****', $sanitized);
        self::assertStringNotContainsString('liveBearerSecret', $sanitized);
        self::assertStringNotContainsString('rawApiKey', $sanitized);
        self::assertStringNotContainsString('sessionCookie', $sanitized);
        self::assertStringNotContainsString('sessionSecret', $sanitized);
        self::assertStringNotContainsString('refreshSecret', $sanitized);
        self::assertStringNotContainsString('plainPassword', $sanitized);
        self::assertStringNotContainsString('otaSecret', $sanitized);
    }
}
