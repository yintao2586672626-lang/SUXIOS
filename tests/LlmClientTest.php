<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmClient;
use app\service\LlmEndpoint;
use app\service\OutboundUrlGuard;
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
        self::assertSame(
            'https://api.mistral.ai/v1/chat/completions',
            LlmEndpoint::chatCompletionUrl('https://api.mistral.ai/v1', 'mistral')
        );
        self::assertSame(
            'https://api.perplexity.ai/v1/sonar',
            LlmEndpoint::chatCompletionUrl('https://api.perplexity.ai/v1', 'perplexity')
        );
        self::assertSame(
            'https://example.services.ai.azure.com/models/chat/completions?api-version=2024-05-01-preview',
            LlmEndpoint::chatCompletionUrl('https://example.services.ai.azure.com/models?api-version=2024-05-01-preview', 'microsoft_phi')
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

    public function testOpenAiPayloadUsesStrictNativeJsonSchemaResponseFormat(): void
    {
        $client = new LlmClient();
        $schema = [
            'x-governance' => ['prompt_version' => 'agent.ota_diagnosis.v1'],
            'type' => 'object',
            'properties' => ['summary' => ['type' => 'string']],
            'required' => ['summary'],
        ];

        $payload = $this->invokeNonPublic($client, 'chatPayload', [
            [
                'provider' => 'openai',
                'model' => 'gpt-5-mini',
            ],
            'Return JSON only.',
            [
                'temperature' => 0.1,
                'json_schema' => $schema,
                'json_schema_name' => 'ota diagnosis v1',
            ],
        ]);

        self::assertSame('gpt-5-mini', $payload['model']);
        self::assertSame('json_schema', $payload['response_format']['type']);
        self::assertSame('ota_diagnosis_v1', $payload['response_format']['json_schema']['name']);
        self::assertTrue($payload['response_format']['json_schema']['strict']);
        self::assertSame(['summary'], $payload['response_format']['json_schema']['schema']['required']);
        self::assertArrayNotHasKey('x-governance', $payload['response_format']['json_schema']['schema']);
    }

    public function testNonOpenAiPayloadDoesNotSendNativeJsonSchemaResponseFormat(): void
    {
        $client = new LlmClient();

        $payload = $this->invokeNonPublic($client, 'chatPayload', [
            [
                'provider' => 'deepseek',
                'model' => 'deepseek-chat',
            ],
            'Return JSON only.',
            [
                'temperature' => 0.1,
                'json_schema' => [
                    'type' => 'object',
                    'properties' => ['summary' => ['type' => 'string']],
                ],
            ],
        ]);

        self::assertArrayNotHasKey('response_format', $payload);
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

    public function testRetryPolicyTargetsTransientNetworkAndHttpFailures(): void
    {
        $client = new LlmClient();

        self::assertSame('network_error', $this->invokeNonPublic($client, 'retryReason', [false, 0]));
        self::assertSame('retryable_http_408', $this->invokeNonPublic($client, 'retryReason', ['{}', 408]));
        self::assertSame('retryable_http_429', $this->invokeNonPublic($client, 'retryReason', ['{}', 429]));
        self::assertSame('retryable_http_503', $this->invokeNonPublic($client, 'retryReason', ['{}', 503]));
        self::assertSame('', $this->invokeNonPublic($client, 'retryReason', ['{}', 400]));
        self::assertSame('', $this->invokeNonPublic($client, 'retryReason', ['{}', 401]));
        self::assertSame('', $this->invokeNonPublic($client, 'retryReason', ['{}', 422]));
    }

    public function testRetryOptionsAreBoundedAndInspectable(): void
    {
        $client = new LlmClient();

        self::assertSame(0, $this->invokeNonPublic($client, 'maxRetries', [['max_retries' => -1]]));
        self::assertSame(5, $this->invokeNonPublic($client, 'maxRetries', [['max_retries' => 99]]));
        self::assertSame(2, $this->invokeNonPublic($client, 'maxRetries', [[]]));
        self::assertSame(400, $this->invokeNonPublic($client, 'retryDelayMs', [
            2,
            [
                'retry_base_delay_ms' => 100,
                'retry_max_delay_ms' => 500,
                'retry_jitter_ms' => 0,
            ],
        ]));
    }

    public function testTransportTimeoutAndFailureSemanticsAreBoundedAndActionable(): void
    {
        $client = new LlmClient();

        self::assertSame(45, $this->invokeNonPublic($client, 'transportTimeoutSeconds', [[]]));
        self::assertSame(60, $this->invokeNonPublic($client, 'transportTimeoutSeconds', [['timeout' => 999]]));
        self::assertSame(1, $this->invokeNonPublic($client, 'transportTimeoutSeconds', [['timeout' => 0]]));

        $message = $this->invokeNonPublic($client, 'actionableTransportError', [
            'dependency timed out with api_key=must-not-be-retained',
            true,
        ]);
        self::assertStringContainsString('timeout', strtolower($message));
        self::assertStringContainsString('retries exhausted', strtolower($message));
        self::assertStringContainsString('retry later', strtolower($message));
        self::assertStringNotContainsString('must-not-be-retained', $message);
    }

    public function testTransportPinsTheValidatedPublicAddressAndDisablesRedirects(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('cURL extension is unavailable');
        }

        $target = (new OutboundUrlGuard(
            static fn(string $host): array => $host === 'llm.example.com' ? ['93.184.216.34'] : []
        ))->validate('https://llm.example.com/v1/chat/completions');
        $options = $this->invokeNonPublic(new LlmClient(), 'buildCurlOptions', [
            $target,
            ['api_key' => 'unit-test-key'],
            '{"model":"unit-test"}',
            ['timeout' => 9],
        ]);

        self::assertSame(['llm.example.com:443:93.184.216.34'], $options[CURLOPT_RESOLVE]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $options[CURLOPT_MAXREDIRS]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(9, $options[CURLOPT_TIMEOUT]);
    }

    public function testDebugIncludesRetryMetadataWithoutMaskingFailure(): void
    {
        $client = new LlmClient();

        $debug = $this->invokeNonPublic($client, 'debug', [
            'http_error',
            [
                'provider' => 'openai',
                'model_key' => 'openai_fast',
                'model' => 'gpt-5-mini',
                'source' => 'database',
            ],
            429,
            '',
            'prompt',
            '{"error":{"message":"rate limited"}}',
            'rate limited',
            [
                'retry_attempts' => 2,
                'max_retries' => 2,
                'retryable' => true,
                'retry_reason' => 'retryable_http_429',
                'retry_exhausted' => true,
                'terminal_failure' => true,
                'failure_state' => 'retry_exhausted',
            ],
            128,
        ]);

        self::assertSame('http_error', $debug['error_type']);
        self::assertSame(2, $debug['debug']['retry_attempts']);
        self::assertSame(2, $debug['debug']['max_retries']);
        self::assertTrue($debug['debug']['retryable']);
        self::assertSame('retryable_http_429', $debug['debug']['retry_reason']);
        self::assertTrue($debug['debug']['retry_exhausted']);
        self::assertTrue($debug['debug']['terminal_failure']);
        self::assertSame('retry_exhausted', $debug['debug']['failure_state']);
        self::assertSame(429, $debug['debug']['http_status']);
    }
}
