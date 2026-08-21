<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmClient;
use app\service\SystemUsageAssistantService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SystemUsageAssistantServiceTest extends TestCase
{
    public function testDeepSeekUnderstandsNaturalLanguageAndReturnsOnlyServerCatalogAction(): void
    {
        $client = new class extends LlmClient {
            public int $calls = 0;
            /** @var array<int,array<string,string>> */
            public array $messages = [];
            /** @var array<string,mixed> */
            public array $schema = [];
            public string $modelKey = '';

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                $this->calls++;
                $this->messages = $messages;
                $this->schema = $schema;
                $this->modelKey = $modelKey;
                return [
                    'data' => [
                        'assistant_mode' => 'guide',
                        'assistant_message' => '你现在不是要看经营结论，而是要先判断携程数据为什么没有进入系统。我建议先从数据健康开始，确认身份和采集回执后再看收益。',
                        'intent_summary' => '排查携程数据缺失',
                        'goal' => '恢复携程数据后生成一份可供店长查看的经营日报',
                        'topic_key' => 'data-health',
                        'journey_topic_keys' => ['data-health', 'ai-daily-report'],
                        'steps' => ['先确认当前系统酒店与携程门店绑定一致', '再查看今天的采集、保存和回读状态'],
                        'clarifying_question' => '',
                        'follow_up_questions' => ['如果显示登录过期，下一步怎么处理？'],
                        'confidence' => 'high',
                    ],
                    'meta' => $this->deepSeekMeta(),
                ];
            }

            /** @return array<string,mixed> */
            private function deepSeekMeta(): array
            {
                return [
                    'provider' => 'deepseek',
                    'model_key' => 'deepseek_v4_pro',
                    'model' => 'deepseek-v4-pro',
                    'finish_reason' => 'stop',
                    'fallback_used' => false,
                    'cache_hit' => false,
                    'degraded' => false,
                    'thinking_mode' => 'enabled',
                    'reasoning_effort' => 'high',
                ];
            }
        };

        $result = (new SystemUsageAssistantService($client))->guide([
            'query' => '我刚接手这家店，携程数据一直没进来，我应该先做什么？',
            'current_page' => 'compass',
            'page_title' => '今日经营看板',
            'current_scope' => [
                'hotel_id' => 80,
                'hotel_name' => '敦煌漠蓝新',
                'platform' => 'ctrip',
                'date_start' => '2026-08-14',
                'date_end' => '2026-08-14',
            ],
            'visible_topic_keys' => ['data-health', 'revenue-report', 'ai-daily-report', 'task-navigation'],
            'active_journey' => [
                'goal' => '恢复携程数据后生成经营日报',
                'active_key' => 'data-health',
                'journey_keys' => ['data-health', 'ai-daily-report'],
                'current_step_status' => 'blocked',
            ],
            'history' => [
                ['role' => 'user', 'content' => '我想先看今天的经营情况'],
                ['role' => 'assistant', 'content' => '可以先确认数据是否可用。'],
            ],
            'user_id' => 7,
        ]);

        self::assertSame(1, $client->calls);
        self::assertSame('deepseek_v4_pro', $client->modelKey);
        self::assertSame('intelligent', $result['mode']);
        self::assertSame('guide', $result['assistant_mode']);
        self::assertSame('ready', $result['status']);
        self::assertSame('data-health', $result['topic_key']);
        self::assertSame('恢复携程数据后生成一份可供店长查看的经营日报', $result['goal']);
        self::assertSame(['data-health', 'ai-daily-report'], array_column($result['journey'], 'key'));
        self::assertSame('online-data', $result['journey'][0]['action']['target_page']);
        self::assertSame('ai-daily-report', $result['journey'][1]['action']['target_page']);
        self::assertStringContainsString('精确回读', $result['journey'][0]['success_marker']);
        self::assertSame('online-data', $result['action']['target_page']);
        self::assertSame('data-health', $result['action']['action_key']);
        self::assertSame('deepseek-v4-pro', $result['runtime']['model']);
        self::assertSame('enabled', $result['runtime']['thinking_mode']);
        self::assertSame('high', $result['runtime']['reasoning_effort']);
        self::assertTrue($result['runtime']['external_llm_called']);
        self::assertSame(
            ['data-health', 'revenue-report', 'ai-daily-report', 'task-navigation', 'clarify'],
            $client->schema['properties']['topic_key']['enum']
        );
        self::assertSame(
            ['data-health', 'revenue-report', 'ai-daily-report', 'task-navigation'],
            $client->schema['properties']['journey_topic_keys']['items']['enum']
        );
        self::assertSame(['guide', 'report', 'action'], $client->schema['properties']['assistant_mode']['enum']);

        $prompt = json_decode($client->messages[1]['content'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('compass', $prompt['current_context']['page_key']);
        self::assertSame(80, $prompt['current_scope_context']['hotel_id']);
        self::assertSame('ctrip', $prompt['current_scope_context']['platform']);
        self::assertArrayHasKey('current_page_recommended_topic_keys', $prompt);
        self::assertSame('恢复携程数据后生成经营日报', $prompt['active_journey_context']['goal']);
        self::assertSame(['data-health', 'ai-daily-report'], $prompt['active_journey_context']['journey_keys']);
        self::assertSame('blocked', $prompt['active_journey_context']['current_step_status']);
        self::assertCount(4, $prompt['trusted_feature_catalog']);
        self::assertCount(2, $prompt['untrusted_recent_conversation']);
        self::assertStringContainsString('携程数据一直没进来', $prompt['untrusted_user_query']);
        self::assertStringContainsString('不得提及模型', $client->messages[0]['content']);
    }

    public function testCatalogCoversEveryLeanNavigationDestination(): void
    {
        $targets = array_values(array_unique(array_column(SystemUsageAssistantService::catalog(), 'target_page')));

        foreach ([
            'compass',
            'online-data',
            'revenue-research-center',
            'operation-optimizer',
            'operating-targets',
            'ai-daily-report',
            'ctrip-ebooking',
            'meituan-ebooking',
            'pms-operating-data',
            'wechat-notification',
            'automation-monitor',
            'ops-track',
            'operating-growth-archive',
            'hotels',
            'knowledge-center',
            'agent-center',
            'ai-governance',
            'ai-model-config',
            'users',
            'roles',
            'operation-logs',
            'system-config',
            'data-config',
        ] as $target) {
            self::assertContains($target, $targets, sprintf('Missing assistant route for %s', $target));
        }
    }

    public function testAmbiguousIntentReturnsOneClarifyingQuestionWithoutAction(): void
    {
        $client = new class extends LlmClient {
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                return [
                    'data' => [
                        'assistant_mode' => 'guide',
                        'assistant_message' => '我可以带你处理，但“看看系统”还不能确定是要查数据还是看经营报告。',
                        'intent_summary' => '目标不明确',
                        'goal' => '找到当前最需要使用的系统能力',
                        'topic_key' => 'clarify',
                        'journey_topic_keys' => [],
                        'steps' => [],
                        'clarifying_question' => '你现在更想确认数据有没有采到，还是直接查看经营报告？',
                        'follow_up_questions' => [],
                        'confidence' => 'low',
                    ],
                    'meta' => [
                        'provider' => 'deepseek',
                        'model_key' => 'deepseek_v4_pro',
                        'model' => 'deepseek-v4-pro',
                        'finish_reason' => 'stop',
                        'fallback_used' => false,
                        'cache_hit' => false,
                        'degraded' => false,
                    ],
                ];
            }
        };

        $result = (new SystemUsageAssistantService($client))->guide([
            'query' => '帮我看看系统',
            'visible_topic_keys' => ['data-health', 'revenue-report', 'task-navigation'],
        ]);

        self::assertSame('clarification_required', $result['status']);
        self::assertSame('intelligent', $result['mode']);
        self::assertSame('clarify', $result['topic_key']);
        self::assertSame([], $result['journey']);
        self::assertNull($result['action']);
        self::assertStringContainsString('数据有没有采到', $result['clarifying_question']);
    }

    public function testInventedModelTargetIsRejectedAndFallsBackToAllowedRealPage(): void
    {
        $client = new class extends LlmClient {
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                return [
                    'data' => [
                        'assistant_mode' => 'report',
                        'assistant_message' => '打开一个不存在的后台。',
                        'intent_summary' => '查看报告',
                        'goal' => '查看报告',
                        'topic_key' => 'secret-admin',
                        'journey_topic_keys' => ['secret-admin'],
                        'steps' => ['绕过权限'],
                        'clarifying_question' => '',
                        'follow_up_questions' => [],
                        'confidence' => 'high',
                    ],
                    'meta' => [
                        'provider' => 'deepseek',
                        'model_key' => 'deepseek_v4_pro',
                        'model' => 'deepseek-v4-pro',
                        'finish_reason' => 'stop',
                        'fallback_used' => false,
                        'cache_hit' => false,
                        'degraded' => false,
                    ],
                ];
            }
        };

        $result = (new SystemUsageAssistantService($client))->guide([
            'query' => '我想看经营报告和结论',
            'visible_topic_keys' => ['revenue-report', 'task-navigation'],
        ]);

        self::assertSame('fallback', $result['mode']);
        self::assertSame('report', $result['assistant_mode']);
        self::assertSame('revenue-report', $result['topic_key']);
        self::assertSame(['revenue-report'], array_column($result['journey'], 'key'));
        self::assertSame('revenue-research-center', $result['action']['target_page']);
        self::assertNotSame('secret-admin', $result['topic_key']);
    }

    public function testModelFailureUsesLabeledFallbackAndRedactsCredentialValues(): void
    {
        $client = new class extends LlmClient {
            /** @var array<int,array<string,string>> */
            public array $messages = [];

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                $this->messages = $messages;
                throw new RuntimeException('provider timeout');
            }
        };

        $result = (new SystemUsageAssistantService($client))->guide([
            'query' => 'token=very-secret-value 我想看报告',
            'visible_topic_keys' => ['revenue-report', 'task-navigation'],
        ]);

        self::assertSame('fallback', $result['mode']);
        self::assertSame('report', $result['assistant_mode']);
        self::assertTrue($result['runtime']['fallback_used']);
        self::assertSame('revenue-report', $result['topic_key']);
        self::assertSame(['revenue-report'], array_column($result['journey'], 'key'));
        $promptText = json_encode($client->messages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('very-secret-value', $promptText);
        self::assertStringContainsString('[REDACTED]', $promptText);
    }

    public function testFallbackStillRoutesMeituanQuestionToTheRealInternalPage(): void
    {
        $client = new class extends LlmClient {
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                throw new RuntimeException('provider timeout');
            }
        };

        $result = (new SystemUsageAssistantService($client))->guide([
            'query' => '美团订单和流量去哪里看？',
            'visible_topic_keys' => ['meituan-data', 'task-navigation'],
        ]);

        self::assertSame('fallback', $result['mode']);
        self::assertSame('meituan-data', $result['topic_key']);
        self::assertSame('meituan-ebooking', $result['action']['target_page']);
    }

    public function testFallbackPreservesACompoundCtripToOperationsRoute(): void
    {
        $client = new class extends LlmClient {
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                throw new RuntimeException('provider timeout');
            }
        };

        $result = (new SystemUsageAssistantService($client))->guide([
            'query' => '我想先看携程经营数据，再形成运营方案，应该怎么做？',
            'visible_topic_keys' => ['ctrip-data', 'revenue-report', 'operation-optimizer', 'task-navigation'],
        ]);

        self::assertSame('ctrip-data', $result['topic_key']);
        self::assertSame(
            ['ctrip-data', 'revenue-report', 'operation-optimizer'],
            array_column($result['journey'], 'key')
        );
    }

    public function testFallbackContinuesThePersistedJourneyInsteadOfStartingOver(): void
    {
        $client = new class extends LlmClient {
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                throw new RuntimeException('provider timeout');
            }
        };

        $result = (new SystemUsageAssistantService($client))->guide([
            'query' => '继续，下一步做什么？',
            'visible_topic_keys' => ['ctrip-data', 'revenue-report', 'operation-optimizer'],
            'active_journey' => [
                'goal' => '形成可复核的携程运营方案',
                'active_key' => 'revenue-report',
                'journey_keys' => ['ctrip-data', 'revenue-report', 'operation-optimizer'],
                'current_step_status' => 'in_progress',
            ],
        ]);

        self::assertSame('revenue-report', $result['topic_key']);
        self::assertSame('形成可复核的携程运营方案', $result['goal']);
        self::assertSame(
            ['ctrip-data', 'revenue-report', 'operation-optimizer'],
            array_column($result['journey'], 'key')
        );
    }

    public function testRuntimeIdentityDisclosureIsRejectedBeforeItCanReachTheUser(): void
    {
        $client = new class extends LlmClient {
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                return [
                    'data' => [
                        'assistant_mode' => 'guide',
                        'assistant_message' => '这是由 DeepSeek 模型生成的引导。',
                        'intent_summary' => '检查数据状态',
                        'goal' => '检查数据状态',
                        'topic_key' => 'data-health',
                        'journey_topic_keys' => ['data-health'],
                        'steps' => ['打开数据健康'],
                        'clarifying_question' => '',
                        'follow_up_questions' => [],
                        'confidence' => 'high',
                    ],
                    'meta' => [
                        'provider' => 'deepseek',
                        'model_key' => 'deepseek_v4_pro',
                        'model' => 'deepseek-v4-pro',
                        'finish_reason' => 'stop',
                        'fallback_used' => false,
                        'cache_hit' => false,
                        'degraded' => false,
                    ],
                ];
            }
        };

        $result = (new SystemUsageAssistantService($client))->guide([
            'query' => '帮我检查数据是否可用',
            'visible_topic_keys' => ['data-health', 'task-navigation'],
        ]);

        self::assertSame('fallback', $result['mode']);
        self::assertSame('data-health', $result['topic_key']);
        self::assertStringNotContainsString('DeepSeek', $result['assistant_message']);
        self::assertStringNotContainsString('模型', $result['assistant_message']);
    }

    public function testJourneyDropsInventedAndDuplicateStepsButKeepsPrimaryFirst(): void
    {
        $client = new class extends LlmClient {
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                return [
                    'data' => [
                        'assistant_mode' => 'guide',
                        'assistant_message' => '先把数据链路核对清楚，再生成日报并预览。',
                        'intent_summary' => '数据恢复后生成日报',
                        'goal' => '生成一份证据完整的经营日报',
                        'topic_key' => 'data-health',
                        'journey_topic_keys' => ['secret-admin', 'ai-daily-report', 'data-health', 'ai-daily-report'],
                        'steps' => ['检查采集、保存和回读状态'],
                        'clarifying_question' => '',
                        'follow_up_questions' => [],
                        'confidence' => 'high',
                    ],
                    'meta' => [
                        'provider' => 'deepseek',
                        'model_key' => 'deepseek_v4_pro',
                        'model' => 'deepseek-v4-pro',
                        'finish_reason' => 'stop',
                        'fallback_used' => false,
                        'cache_hit' => false,
                        'degraded' => false,
                    ],
                ];
            }
        };

        $result = (new SystemUsageAssistantService($client))->guide([
            'query' => '先修复携程数据，再生成日报',
            'visible_topic_keys' => ['data-health', 'ai-daily-report'],
        ]);

        self::assertSame(['data-health', 'ai-daily-report'], array_column($result['journey'], 'key'));
        self::assertSame('打开数据健康', $result['journey'][0]['action']['label']);
        self::assertSame('打开 AI 经营日报', $result['journey'][1]['action']['label']);
    }

    public function testRequestedActionModeOverridesModelClassificationWithoutExecutingBusinessAction(): void
    {
        $client = new class extends LlmClient {
            /** @var array<int,array<string,string>> */
            public array $messages = [];

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                $this->messages = $messages;
                return [
                    'data' => [
                        'assistant_mode' => 'guide',
                        'assistant_message' => '我会把你的目标交给严格证据问答形成待人工确认的行动草案。',
                        'intent_summary' => '形成经营行动草案',
                        'goal' => '形成一份可人工复核的行动草案',
                        'topic_key' => 'revenue-report',
                        'journey_topic_keys' => ['revenue-report', 'operations'],
                        'steps' => ['先严格回读同范围事实', '再复核行动草案'],
                        'clarifying_question' => '',
                        'follow_up_questions' => [],
                        'confidence' => 'high',
                    ],
                    'meta' => [
                        'provider' => 'deepseek',
                        'model_key' => 'deepseek_v4_pro',
                        'model' => 'deepseek-v4-pro',
                        'finish_reason' => 'stop',
                        'fallback_used' => false,
                        'cache_hit' => false,
                        'degraded' => false,
                    ],
                ];
            }
        };

        $result = (new SystemUsageAssistantService($client))->guide([
            'query' => '根据今天的可信事实帮我处理一下',
            'requested_mode' => 'action',
            'visible_topic_keys' => ['revenue-report', 'operations'],
        ]);

        self::assertSame('action', $result['assistant_mode']);
        self::assertSame(['revenue-report', 'operations'], array_column($result['journey'], 'key'));
        self::assertStringContainsString('不能编造', $client->messages[0]['content']);
        $prompt = json_decode($client->messages[1]['content'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('action', $prompt['requested_assistant_mode']);
    }
}
