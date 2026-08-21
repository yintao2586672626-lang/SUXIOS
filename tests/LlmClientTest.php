<?php
declare(strict_types=1);

namespace Tests;

use app\exception\LlmDirectRequestException;
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

    public function testLegacyDeepSeekProviderModelNamesMapToCurrentV4Models(): void
    {
        $client = new LlmClient();

        self::assertSame(
            'deepseek-v4-flash',
            $this->invokeNonPublic($client, 'resolvedProviderModelName', ['deepseek', 'deepseek-chat'])
        );
        self::assertSame(
            'deepseek-v4-pro',
            $this->invokeNonPublic($client, 'resolvedProviderModelName', ['deepseek', 'deepseek-reasoner'])
        );
        self::assertSame(
            'mimo-v2.5-pro',
            $this->invokeNonPublic($client, 'resolvedProviderModelName', ['xiaomi_mimo', 'mimo-v2.5-pro'])
        );
    }

    public function testChatEndpointUrlSupportsProviderSpecificPathsAndQueryStrings(): void
    {
        $guard = new OutboundUrlGuard(static fn(string $host): array => ['8.8.8.8']);
        self::assertSame(
            'https://api.mistral.ai/v1/chat/completions',
            LlmEndpoint::chatCompletionUrl('https://api.mistral.ai/v1', 'mistral', $guard)
        );
        self::assertSame(
            'https://api.perplexity.ai/v1/sonar',
            LlmEndpoint::chatCompletionUrl('https://api.perplexity.ai/v1', 'perplexity', $guard)
        );
        self::assertSame(
            'https://example.services.ai.azure.com/models/chat/completions?api-version=2024-05-01-preview',
            LlmEndpoint::chatCompletionUrl('https://example.services.ai.azure.com/models?api-version=2024-05-01-preview', 'microsoft_phi', $guard)
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
                'business_date' => '2026-07-15',
                'business_date_start' => '2026-07-14',
                'business_date_end' => '2026-07-15',
                'source_scope' => 'verified_ota_channel_only',
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
        self::assertSame('2026-07-15', $governance['business_date']);
        self::assertSame('2026-07-14', $governance['business_date_start']);
        self::assertSame('2026-07-15', $governance['business_date_end']);
        self::assertSame('verified_ota_channel_only', $governance['source_scope']);
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

    public function testDeepSeekPayloadUsesJsonObjectDisablesThinkingAndCarriesAnonymousUser(): void
    {
        $client = new LlmClient();

        $payload = $this->invokeNonPublic($client, 'chatPayload', [
            [
                'provider' => 'deepseek',
                'model' => 'deepseek-v4-flash',
            ],
            'Return JSON only.',
            [
                'temperature' => 0.1,
                'json_schema' => [
                    'type' => 'object',
                    'properties' => ['summary' => ['type' => 'string']],
                ],
                'max_tokens' => 2048,
                'deepseek_thinking' => 'disabled',
                'user_id' => 'tenant_hotel_hash_123',
            ],
        ]);

        self::assertSame(['type' => 'json_object'], $payload['response_format']);
        self::assertSame(['type' => 'disabled'], $payload['thinking']);
        self::assertSame('tenant_hotel_hash_123', $payload['user_id']);
        self::assertSame(2048, $payload['max_tokens']);
    }

    public function testDirectStructuredEnvelopeReturnsActualDeepSeekMetadataAndValidatesSchema(): void
    {
        $primary = ScriptedLlmClient::modelConfig('deepseek_v4_pro', 'deepseek');
        $primary['model'] = 'deepseek-v4-pro';
        $primary['configured_model'] = 'deepseek-v4-pro';
        $client = new ScriptedLlmClient($primary, [
            ScriptedLlmClient::modelConfig('backup_model', 'openai'),
        ], [
            'deepseek_v4_pro' => [ScriptedLlmClient::success(json_encode([
                'summary' => 'ok',
                'confidence' => 'medium',
            ], JSON_UNESCAPED_UNICODE), 'stop', 'resp-direct-v4-pro-0001', time(), 'deepseek-v4-pro')],
        ]);

        $envelope = $client->createJsonResponseEnvelope([[
            'role' => 'user',
            'content' => 'Return JSON.',
        ]], [
            'type' => 'object',
            'required' => ['summary', 'confidence'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
            ],
            'x-governance' => [
                'module' => 'operating_question',
                'hotel_id' => 20,
                'user_id' => 7,
                'request_id' => 'inbound-request-id-must-not-be-reused',
            ],
        ], 'deepseek_v4_pro');

        self::assertSame('ok', $envelope['data']['summary']);
        self::assertSame('deepseek', $envelope['meta']['provider']);
        self::assertSame('deepseek_v4_pro', $envelope['meta']['model_key']);
        self::assertSame('deepseek-v4-pro', $envelope['meta']['model']);
        self::assertSame('deepseek-v4-pro', $envelope['meta']['configured_model']);
        self::assertSame('deepseek-v4-pro', $envelope['meta']['response_model']);
        self::assertSame('resp-direct-v4-pro-0001', $envelope['meta']['provider_response_id']);
        self::assertTrue($envelope['meta']['provider_response_fresh']);
        self::assertSame('https://api.deepseek.com', $envelope['meta']['provider_endpoint_origin']);
        self::assertSame('api.deepseek.com', $envelope['meta']['provider_endpoint_host']);
        self::assertTrue($envelope['meta']['provider_endpoint_official']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $envelope['meta']['provider_config_digest']);
        self::assertMatchesRegularExpression('/^oq_[a-f0-9]{32}$/D', $envelope['meta']['direct_call_nonce']);
        self::assertSame($envelope['meta']['direct_call_nonce'], $envelope['meta']['transport_request_id']);
        self::assertSame(0, $envelope['meta']['transport_retry_attempts']);
        self::assertFalse($envelope['meta']['upstream_idempotency_key_sent']);
        self::assertSame(1, $envelope['meta']['provider_attempt_count']);
        self::assertFalse($envelope['meta']['idempotent_replay']);
        self::assertTrue($envelope['meta']['direct_request_proof']);
        self::assertSame('stop', $envelope['meta']['finish_reason']);
        self::assertSame('enabled', $envelope['meta']['thinking_mode']);
        self::assertSame('high', $envelope['meta']['reasoning_effort']);
        self::assertFalse($envelope['meta']['fallback_used']);
        self::assertCount(1, $client->calls);
        self::assertSame(['type' => 'json_object'], $client->calls[0]['payload']['response_format']);
        self::assertSame(['type' => 'enabled'], $client->calls[0]['payload']['thinking']);
        self::assertSame('high', $client->calls[0]['payload']['reasoning_effort']);
        self::assertSame(8192, $client->calls[0]['payload']['max_tokens']);
        self::assertArrayNotHasKey('temperature', $client->calls[0]['payload']);
        self::assertSame(60, $client->calls[0]['timeout']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,128}$/', $client->calls[0]['payload']['user_id']);
        self::assertSame($envelope['meta']['direct_call_nonce'], $client->calls[0]['request_id']);
        self::assertNotSame('inbound-request-id-must-not-be-reused', $client->calls[0]['request_id']);
        self::assertSame(0, $client->calls[0]['max_retries']);
        self::assertFalse($client->calls[0]['send_idempotency_key']);
    }

    public function testDirectStructuredEnvelopeRejectsGatewayAndStaleProviderReceiptProof(): void
    {
        $primary = ScriptedLlmClient::modelConfig('deepseek_v4_pro', 'deepseek');
        $primary['base_url'] = 'https://api.deepseek.com/v1';
        $primary['model'] = 'deepseek-v4-pro';
        $primary['configured_model'] = 'deepseek-v4-pro';
        $schema = [
            'type' => 'object',
            'required' => ['summary'],
            'properties' => ['summary' => ['type' => 'string']],
        ];

        $invalidConfigs = [
            'gateway' => ['base_url' => 'https://gateway.example.com/v1'],
            'http_endpoint' => ['base_url' => 'http://api.deepseek.com/v1'],
            'non_standard_port' => ['base_url' => 'https://api.deepseek.com:444/v1'],
            'provider' => ['provider' => 'openai'],
            'requested_key' => ['model_key' => 'deepseek_reasoner'],
            'configured_key' => ['configured_model_key' => 'deepseek_chat'],
            'configured_model' => ['configured_model' => 'deepseek-reasoner'],
            'resolved_model' => ['model' => 'deepseek-reasoner'],
        ];
        foreach ($invalidConfigs as $label => $mutation) {
            $rejected = new ScriptedLlmClient(array_merge($primary, $mutation), [], [
                'deepseek_v4_pro' => [ScriptedLlmClient::success('{"summary":"ok"}', 'stop', 'resp-' . $label . '-0001', time(), 'deepseek-v4-pro')],
            ]);
            try {
                $rejected->createJsonResponseEnvelope([], $schema, 'deepseek_v4_pro');
                self::fail($label . ' must be rejected before hotel evidence is transmitted');
            } catch (\RuntimeException) {
                self::assertCount(0, $rejected->calls, $label);
            }
        }

        $stale = new ScriptedLlmClient($primary, [], [
            'deepseek_v4_pro' => [ScriptedLlmClient::success('{"summary":"old"}', 'stop', 'resp-stale-v4-0001', time() - 901, 'deepseek-v4-pro')],
        ]);
        $staleEnvelope = $stale->createJsonResponseEnvelope([], $schema, 'deepseek_v4_pro');
        self::assertFalse($staleEnvelope['meta']['provider_response_fresh']);
        self::assertFalse($staleEnvelope['meta']['direct_request_proof']);
    }

    public function testDirectStructuredEnvelopeAllowsLegacyLocalProKeyAndPreservesRejectedResponseReceipt(): void
    {
        $primary = ScriptedLlmClient::modelConfig('deepseek_v4_pro', 'deepseek');
        $primary['configured_model_key'] = 'deepseek_reasoner';
        $primary['configured_model'] = 'deepseek-v4-pro';
        $primary['model'] = 'deepseek-v4-pro';
        $client = new ScriptedLlmClient($primary, [], [
            'deepseek_v4_pro' => [ScriptedLlmClient::success(
                '',
                'length',
                'resp-reasoning-only-v4-pro-0001',
                time(),
                'deepseek-v4-pro'
            )],
        ]);

        try {
            $client->createJsonResponseEnvelope([], [
                'type' => 'object',
                'required' => ['summary'],
                'properties' => ['summary' => ['type' => 'string']],
            ], 'deepseek_v4_pro');
            self::fail('reasoning-only response must not become an operating answer');
        } catch (LlmDirectRequestException $exception) {
            $receipt = $exception->receipt();
            self::assertSame('empty_content', $receipt['failure_reason']);
            self::assertSame('deepseek_v4_pro', $receipt['model_key']);
            self::assertSame('deepseek', $receipt['provider']);
            self::assertSame('deepseek-v4-pro', $receipt['configured_model']);
            self::assertSame('deepseek-v4-pro', $receipt['response_model']);
            self::assertSame('resp-reasoning-only-v4-pro-0001', $receipt['provider_response_id']);
            self::assertSame(200, $receipt['http_status']);
            self::assertSame(1, $receipt['provider_attempt_count']);
            self::assertSame(0, $receipt['transport_retry_attempts']);
            self::assertTrue($receipt['external_llm_called']);
            self::assertSame('response_rejected_after_direct_call', $receipt['external_llm_call_status']);
            self::assertSame('length', $receipt['finish_reason']);
            self::assertFalse($receipt['direct_request_proof']);
            self::assertFalse($receipt['fallback_used']);
            self::assertFalse($receipt['cache_hit']);
            self::assertSame($receipt['direct_call_nonce'], $receipt['transport_request_id']);
        }

        self::assertCount(1, $client->calls);
        self::assertSame(8192, $client->calls[0]['payload']['max_tokens']);
        self::assertArrayNotHasKey('temperature', $client->calls[0]['payload']);
    }

    public function testSuccessfulChatKeepsActualProviderModelAtTopLevel(): void
    {
        $primary = ScriptedLlmClient::modelConfig('primary_model', 'deepseek');
        $client = new ScriptedLlmClient($primary, [], [
            'primary_model' => [ScriptedLlmClient::success(
                'ok',
                'stop',
                'resp-model-audit-0001',
                time(),
                'actual-provider-model'
            )],
        ]);

        $result = $client->chat('model audit', 'primary_model', [], [
            'idempotency_enabled' => false,
            'response_cache_enabled' => false,
        ]);

        self::assertTrue($result['ok']);
        self::assertSame('actual-provider-model', $result['model']);
        self::assertSame('primary_model-api-model', $result['configured_model']);
        self::assertSame('actual-provider-model', $result['response_model']);
        self::assertSame('actual-provider-model', $result['data']['debug']['model']);
        self::assertSame('primary_model-api-model', $result['data']['debug']['model_name']);

        $withoutReportedModel = new ScriptedLlmClient(
            ScriptedLlmClient::modelConfig('deepseek_chat', 'deepseek'),
            [],
            ['deepseek_chat' => [ScriptedLlmClient::success('{"summary":"ok"}', 'stop', 'resp-model-empty-0001', time(), '')]]
        );
        $envelope = $withoutReportedModel->createJsonResponseEnvelope([], [
            'type' => 'object',
            'required' => ['summary'],
            'properties' => ['summary' => ['type' => 'string']],
        ], 'deepseek_chat');
        self::assertSame('deepseek_chat-api-model', $envelope['meta']['model']);
        self::assertSame('', $envelope['meta']['response_model']);
    }

    public function testProviderResponseReceiptIsExactAndInvalidIdsFailClosed(): void
    {
        $client = new LlmClient();
        foreach ([
            null,
            12345678,
            'short',
            ' resp-valid-0001',
            'resp-valid-0001 ',
            'resp/invalid/0001',
            "resp\tinvalid0001",
            str_repeat('a', 192),
        ] as $invalid) {
            self::assertSame('', $this->invokeNonPublic($client, 'normalizeProviderResponseId', [$invalid]));
        }
        $exact = 'resp-Provider.Exact:0001';
        self::assertSame($exact, $this->invokeNonPublic($client, 'normalizeProviderResponseId', [$exact]));
        self::assertNotSame(
            'local-request-id-should-never-substitute',
            $this->invokeNonPublic($client, 'normalizeProviderResponseId', [null])
        );
    }

    public function testDirectStructuredEnvelopeRejectsMissingRequiredFieldAndTruncatedFinish(): void
    {
        $primary = ScriptedLlmClient::modelConfig('deepseek_chat', 'deepseek');
        $missing = new ScriptedLlmClient($primary, [], [
            'deepseek_chat' => [ScriptedLlmClient::success('{"summary":"missing confidence"}')],
        ]);
        $schema = [
            'type' => 'object',
            'required' => ['summary', 'confidence'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
            ],
        ];

        try {
            $missing->createJsonResponseEnvelope([], $schema, 'deepseek_chat');
            self::fail('missing required field must be rejected');
        } catch (\RuntimeException) {
            self::assertCount(1, $missing->calls);
            self::assertSame(45, $missing->calls[0]['timeout']);
            self::assertSame(2048, $missing->calls[0]['payload']['max_tokens']);
        }

        $truncated = new ScriptedLlmClient($primary, [], [
            'deepseek_chat' => [ScriptedLlmClient::success('{"summary":"x","confidence":"medium"}', 'length')],
        ]);
        $this->expectException(\RuntimeException::class);
        $truncated->createJsonResponseEnvelope([], $schema, 'deepseek_chat');
    }

    public function testDirectStructuredEnvelopeDoesNotRetryAmbiguousTransportFailure(): void
    {
        $primary = ScriptedLlmClient::modelConfig('deepseek_v4_pro', 'deepseek');
        $primary['model'] = 'deepseek-v4-pro';
        $primary['configured_model'] = 'deepseek-v4-pro';
        $client = new ScriptedLlmClient($primary, [], [
            'deepseek_v4_pro' => [ScriptedLlmClient::transportFailure(CURLE_OPERATION_TIMEDOUT)],
        ]);

        try {
            $client->createJsonResponseEnvelope([], [
                'type' => 'object',
                'required' => ['summary'],
                'properties' => ['summary' => ['type' => 'string']],
            ], 'deepseek_v4_pro');
            self::fail('ambiguous direct transport failure must not be accepted');
        } catch (\RuntimeException) {
            self::assertCount(1, $client->calls);
        }
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
        self::assertSame(3, $this->invokeNonPublic($client, 'maxRetries', [[]]));
        self::assertSame(2000, $this->invokeNonPublic($client, 'retryDelayMs', [0, []]));
        self::assertSame(5000, $this->invokeNonPublic($client, 'retryDelayMs', [1, []]));
        self::assertSame(10000, $this->invokeNonPublic($client, 'retryDelayMs', [2, []]));
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
            ['timeout' => 9, 'request_id' => 'req-transport-1'],
        ]);

        self::assertSame(['llm.example.com:443:93.184.216.34'], $options[CURLOPT_RESOLVE]);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $options[CURLOPT_MAXREDIRS]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(9, $options[CURLOPT_TIMEOUT]);
        self::assertContains('X-Request-ID: req-transport-1', $options[CURLOPT_HTTPHEADER]);
        self::assertContains('Idempotency-Key: req-transport-1', $options[CURLOPT_HTTPHEADER]);

        $directOptions = $this->invokeNonPublic(new LlmClient(), 'buildCurlOptions', [
            $target,
            ['api_key' => 'unit-test-key'],
            '{"model":"unit-test"}',
            ['timeout' => 9, 'request_id' => 'oq_direct_nonce', 'send_idempotency_key' => false],
        ]);
        self::assertContains('X-Request-ID: oq_direct_nonce', $directOptions[CURLOPT_HTTPHEADER]);
        self::assertNotContains('Idempotency-Key: oq_direct_nonce', $directOptions[CURLOPT_HTTPHEADER]);
    }

    public function testOllamaTransportIsLoopbackOnlyKeylessAndUsesNativeJsonSchema(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('cURL extension is unavailable');
        }

        $client = new LlmClient();
        $target = (new OutboundUrlGuard())->validateLocalLlm(
            'http://127.0.0.1:11434/v1/chat/completions'
        );
        $options = $this->invokeNonPublic($client, 'buildCurlOptions', [
            $target,
            ['provider' => 'ollama', 'api_key' => ''],
            '{"model":"qwen3:8b"}',
            ['timeout' => 30],
        ]);

        self::assertSame([], $options[CURLOPT_RESOLVE]);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
        self::assertNotContains('Authorization: Bearer ', $options[CURLOPT_HTTPHEADER]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP')) {
            self::assertSame(CURLPROTO_HTTP, $options[CURLOPT_PROTOCOLS]);
        }

        $payload = $this->invokeNonPublic($client, 'chatPayload', [[
            'provider' => 'ollama',
            'model' => 'qwen3:8b',
        ], 'Return a JSON summary.', [
            'json_schema' => [
                'type' => 'object',
                'properties' => ['summary' => ['type' => 'string']],
                'required' => ['summary'],
            ],
        ]]);

        self::assertSame('none', $payload['reasoning_effort']);
        self::assertSame('json_schema', $payload['response_format']['type']);
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

    public function testTimeoutRetriesUseTwoFiveTenSecondsAndReturnManualDegradationWithout500(): void
    {
        $primary = ScriptedLlmClient::modelConfig('primary_model', 'deepseek');
        $client = new ScriptedLlmClient($primary, [], [
            'primary_model' => array_fill(0, 4, ScriptedLlmClient::transportFailure(CURLE_OPERATION_TIMEDOUT)),
        ]);

        $result = $client->chat('timeout prompt', 'primary_model', ['request_id' => 'req-timeout'], [
            'provider_fallback_enabled' => false,
            'circuit_breaker_enabled' => false,
            'idempotency_enabled' => false,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame(200, $result['code']);
        self::assertTrue($result['degraded']);
        self::assertSame('manual', $result['degradation_mode']);
        self::assertSame('req-timeout', $result['request_id']);
        self::assertSame([2000, 5000, 10000], $client->sleepDelays);
        self::assertCount(4, $client->calls);
        self::assertSame('req-timeout', $client->calls[0]['request_id']);
    }

    public function testHttp500ExhaustsPrimaryThenFallsBackToBackupProvider(): void
    {
        $primary = ScriptedLlmClient::modelConfig('primary_model', 'deepseek');
        $backup = ScriptedLlmClient::modelConfig('backup_model', 'openai');
        $client = new ScriptedLlmClient($primary, [$backup], [
            'primary_model' => array_fill(0, 4, ScriptedLlmClient::httpFailure(500)),
            'backup_model' => [ScriptedLlmClient::success('backup response')],
        ]);

        $result = $client->chat('provider fallback prompt', 'primary_model', ['request_id' => 'req-fallback'], [
            'circuit_breaker_enabled' => false,
            'idempotency_enabled' => false,
        ]);

        self::assertTrue($result['ok']);
        self::assertSame(200, $result['code']);
        self::assertSame('backup response', $result['content']);
        self::assertSame('openai', $result['provider']);
        self::assertSame('backup_model', $result['model_key']);
        self::assertTrue($result['fallback_used']);
        self::assertSame([2000, 5000, 10000], $client->sleepDelays);
        self::assertCount(5, $client->calls);
        self::assertSame('primary_model', $client->calls[3]['model_key']);
        self::assertSame('backup_model', $client->calls[4]['model_key']);
    }

    public function testCircuitBreakerTransitionsClosedOpenHalfOpenAndBackToClosed(): void
    {
        $primary = ScriptedLlmClient::modelConfig('primary_model', 'deepseek');
        $client = new ScriptedLlmClient($primary, [], [
            'primary_model' => [
                ScriptedLlmClient::transportFailure(CURLE_COULDNT_CONNECT),
                ScriptedLlmClient::success('half-open probe succeeded'),
            ],
        ]);
        $options = [
            'max_retries' => 0,
            'provider_fallback_enabled' => false,
            'response_cache_enabled' => false,
            'idempotency_enabled' => false,
            'circuit_failure_threshold' => 1,
            'circuit_open_seconds' => 60,
        ];

        $first = $client->chat('circuit prompt', 'primary_model', ['request_id' => 'req-circuit-1'], $options);
        self::assertFalse($first['ok']);
        self::assertSame(200, $first['code']);
        self::assertSame('open', $first['data']['stability']['provider_attempts'][0]['circuit_state']);
        self::assertCount(1, $client->calls);

        $second = $client->chat('circuit prompt', 'primary_model', ['request_id' => 'req-circuit-2'], $options);
        self::assertFalse($second['ok']);
        self::assertSame('circuit_open', $second['data']['stability']['provider_attempts'][0]['error_type']);
        self::assertSame('open', $second['data']['stability']['provider_attempts'][0]['circuit_state']);
        self::assertCount(1, $client->calls, 'open circuit must skip the provider');

        $circuitKey = $this->invokeNonPublic($client, 'circuitCacheKey', [$primary]);
        $client->putState($circuitKey, [
            'state' => 'open',
            'failure_count' => 1,
            'opened_at' => time() - 61,
        ]);
        $third = $client->chat('circuit prompt', 'primary_model', ['request_id' => 'req-circuit-3'], $options);

        self::assertTrue($third['ok']);
        self::assertSame('half-open probe succeeded', $third['content']);
        self::assertSame('half-open', $third['data']['stability']['provider_attempts'][0]['circuit_state_before']);
        self::assertSame('closed', $third['data']['stability']['provider_attempts'][0]['circuit_state']);
        self::assertCount(2, $client->calls);
    }

    public function testSameRequestIdReplaysOnceAndRejectsDifferentPayload(): void
    {
        $primary = ScriptedLlmClient::modelConfig('primary_model', 'deepseek');
        $client = new ScriptedLlmClient($primary, [], [
            'primary_model' => [ScriptedLlmClient::success('single model result')],
        ]);
        $options = [
            'provider_fallback_enabled' => false,
            'circuit_breaker_enabled' => false,
        ];

        $first = $client->chat('same prompt', 'primary_model', ['request_id' => 'req-idempotent'], $options);
        $replay = $client->chat('same prompt', 'primary_model', ['request_id' => 'req-idempotent'], $options);
        $conflict = $client->chat('different prompt', 'primary_model', ['request_id' => 'req-idempotent'], $options);

        self::assertTrue($first['ok']);
        self::assertFalse($first['idempotent_replay']);
        self::assertTrue($first['business_action_allowed']);
        self::assertTrue($replay['ok']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertFalse($replay['business_action_allowed']);
        self::assertFalse($conflict['ok']);
        self::assertSame(409, $conflict['code']);
        self::assertSame('idempotency_conflict', $conflict['failure_state']);
        self::assertCount(1, $client->calls, 'replay and conflict must not call the model again');
    }

    public function testProviderFailureUsesCachedResultBeforeManualHandling(): void
    {
        $primary = ScriptedLlmClient::modelConfig('primary_model', 'deepseek');
        $client = new ScriptedLlmClient($primary, [], [
            'primary_model' => [
                ScriptedLlmClient::success('cached answer'),
                ScriptedLlmClient::transportFailure(CURLE_COULDNT_CONNECT),
            ],
        ]);
        $options = [
            'max_retries' => 0,
            'provider_fallback_enabled' => false,
            'circuit_breaker_enabled' => false,
        ];

        $first = $client->chat('cache prompt', 'primary_model', ['request_id' => 'req-cache-1'], $options);
        $degraded = $client->chat('cache prompt', 'primary_model', ['request_id' => 'req-cache-2'], $options);

        self::assertTrue($first['ok']);
        self::assertTrue($degraded['ok']);
        self::assertTrue($degraded['degraded']);
        self::assertSame('cache', $degraded['degradation_mode']);
        self::assertSame('cached answer', $degraded['content']);
        self::assertFalse($degraded['business_action_allowed']);
        self::assertTrue($degraded['data']['degradation']['cache_hit']);
        self::assertCount(2, $client->calls);
    }

    public function testProviderFailureCanUseExplicitRuleEngineFallback(): void
    {
        $primary = ScriptedLlmClient::modelConfig('primary_model', 'deepseek');
        $client = new ScriptedLlmClient($primary, [], [
            'primary_model' => [ScriptedLlmClient::httpFailure(503)],
        ]);

        $result = $client->chat('rule prompt', 'primary_model', ['request_id' => 'req-rule'], [
            'max_retries' => 0,
            'provider_fallback_enabled' => false,
            'circuit_breaker_enabled' => false,
            'idempotency_enabled' => false,
            'rule_engine_fallback' => ['status' => 'manual_review', 'source' => 'deterministic_rules'],
        ]);

        self::assertTrue($result['ok']);
        self::assertTrue($result['degraded']);
        self::assertSame('rule_engine', $result['degradation_mode']);
        self::assertSame('rule_engine', $result['provider']);
        self::assertStringContainsString('deterministic_rules', $result['content']);
        self::assertFalse($result['business_action_allowed']);
    }
}

final class ScriptedLlmClient extends LlmClient
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];
    /** @var array<int, int> */
    public array $sleepDelays = [];
    /** @var array<string, mixed> */
    private array $testState = [];

    /**
     * @param array<string, mixed> $primaryConfig
     * @param array<int, array<string, mixed>> $fallbackConfigs
     * @param array<string, array<int, array<string, mixed>>> $scripts
     */
    public function __construct(
        private array $primaryConfig,
        private array $fallbackConfigs,
        private array $scripts
    ) {
    }

    /** @return array<string, mixed> */
    public static function modelConfig(string $modelKey, string $provider): array
    {
        return [
            'ok' => true,
            'provider' => $provider,
            'base_url' => $provider === 'deepseek'
                ? 'https://api.deepseek.com/v1'
                : 'https://' . $provider . '.example.com/v1',
            'api_key' => 'unit-test-key',
            'model' => $modelKey . '-api-model',
            'configured_model_key' => $modelKey,
            'model_key' => $modelKey,
            'source' => 'database',
        ];
    }

    /** @return array<string, mixed> */
    public static function success(
        string $content,
        string $finishReason = 'stop',
        mixed $providerResponseId = 'resp-unit-test-0001',
        ?int $providerCreatedAt = null,
        string $responseModel = 'simulated-model'
    ): array
    {
        return [
            'response' => json_encode([
                'id' => $providerResponseId,
                'created' => $providerCreatedAt ?? time(),
                'model' => $responseModel,
                'choices' => [[
                    'message' => ['content' => $content],
                    'finish_reason' => $finishReason,
                ]],
            ], JSON_UNESCAPED_UNICODE),
            'http_status' => 200,
            'curl_errno' => 0,
        ];
    }

    /** @return array<string, mixed> */
    public static function httpFailure(int $status): array
    {
        return [
            'response' => json_encode(['error' => ['message' => 'simulated HTTP ' . $status]]),
            'http_status' => $status,
            'curl_errno' => 0,
        ];
    }

    /** @return array<string, mixed> */
    public static function transportFailure(int $errno): array
    {
        return ['response' => false, 'http_status' => 0, 'curl_errno' => $errno];
    }

    protected function configByModelKey(string $modelKey): array
    {
        return $this->primaryConfig;
    }

    protected function providerFallbackConfigs(array $primaryConfig, string $requestedModelKey, array $options): array
    {
        return ($options['provider_fallback_enabled'] ?? true) ? $this->fallbackConfigs : [];
    }

    protected function chatCompletionUrl(array $config): string
    {
        return 'https://unit.test/v1/chat/completions';
    }

    protected function sendOnce(string $url, array $config, string $payloadJson, array $options): array
    {
        $modelKey = (string)($config['model_key'] ?? '');
        $this->calls[] = [
            'model_key' => $modelKey,
            'request_id' => (string)($options['request_id'] ?? ''),
            'max_retries' => (int)($options['max_retries'] ?? -1),
            'send_idempotency_key' => ($options['send_idempotency_key'] ?? true) === true,
            'timeout' => (int)($options['timeout'] ?? 0),
            'payload' => json_decode($payloadJson, true),
        ];
        $response = array_shift($this->scripts[$modelKey]);
        return is_array($response) ? $response : self::transportFailure(CURLE_COULDNT_CONNECT);
    }

    protected function sleepMilliseconds(int $delayMs): void
    {
        $this->sleepDelays[] = $delayMs;
    }

    protected function finishWithGovernance(
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
        $result['request_id'] = (string)$governance['request_id'];
        $result['data'] = is_array($result['data'] ?? null) ? $result['data'] : [];
        $result['data']['governance'] = [
            'call_log_id' => 1,
            'request_id' => (string)$governance['request_id'],
            'status' => $status,
            'prompt_version' => (string)$governance['prompt_version'],
            'confidence_threshold' => (float)$governance['confidence_threshold'],
            'human_confirmation_required' => (bool)$governance['human_confirmation_required'],
        ];
        return $result;
    }

    protected function withIdempotencyLock(string $requestId, callable $callback): ?array
    {
        $result = $callback();
        return is_array($result) ? $result : null;
    }

    protected function stateGet(string $key): mixed
    {
        return $this->testState[$key] ?? null;
    }

    protected function stateSet(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->testState[$key] = $value;
    }

    protected function stateDelete(string $key): void
    {
        unset($this->testState[$key]);
    }

    public function putState(string $key, mixed $value): void
    {
        $this->testState[$key] = $value;
    }
}
