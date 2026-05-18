<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmClient;
use app\service\OpeningService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ReflectionHelper;

final class OpeningServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testOpeningSuggestionsUseLlmWithRealProjectAndTaskData(): void
    {
        $client = new class extends LlmClient {
            public array $messages = [];

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->messages = $messages;
                return [
                    'suggestions' => [
                        'PMS基础档案配置已逾期，今日由张三完成联调复盘并确认新截止时间。',
                        'OTA门店资料上线为高风险，先闭环渠道页面、房价库存和测试订单。',
                    ],
                ];
            }
        };

        $service = new OpeningService($client);
        $suggestions = $this->invokeNonPublic($service, 'buildOpeningSuggestions', [
            $this->project(),
            $this->tasks(),
            2,
            1,
        ]);

        self::assertSame('PMS基础档案配置已逾期，今日由张三完成联调复盘并确认新截止时间。', $suggestions[0]);
        self::assertSame('OTA门店资料上线为高风险，先闭环渠道页面、房价库存和测试订单。', $suggestions[1]);

        $payload = (string)($client->messages[1]['content'] ?? '');
        self::assertStringContainsString('PMS基础档案配置', $payload);
        self::assertStringContainsString('OTA门店资料上线', $payload);
        self::assertStringContainsString('category_progress', $payload);
        self::assertStringContainsString('high_risk_tasks', $payload);
        self::assertStringContainsString('progress_percent', $payload);
        self::assertStringContainsString('progress_rate', $payload);
    }

    public function testOpeningSuggestionsFallbackWhenLlmUnavailable(): void
    {
        $client = new class extends LlmClient {
            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                throw new RuntimeException('mock llm unavailable');
            }
        };

        $service = new OpeningService($client);
        $suggestions = $this->invokeNonPublic($service, 'buildOpeningSuggestions', [
            $this->project(),
            $this->tasks(),
            2,
            1,
        ]);

        self::assertContains('存在逾期未完成事项，建议今日完成责任人复盘并重新确认截止时间。', $suggestions);
        self::assertContains('高风险事项需要进入开业日会，优先处理PMS、OTA、支付、消防、安全和库存相关任务。', $suggestions);
    }

    public function testTaskProgressDefaultsFromExistingStatus(): void
    {
        $service = new OpeningService();

        $doingTask = $this->invokeNonPublic($service, 'normalizeTask', [
            [
                'id' => 1,
                'project_id' => 1,
                'category' => 'PMS系统配置',
                'task_name' => 'PMS基础档案配置',
                'task_desc' => '',
                'is_core' => 1,
                'deadline' => '2026-07-01',
                'status' => 'doing',
                'sort_order' => 1,
            ],
            $this->project(),
        ]);
        $doneTask = $this->invokeNonPublic($service, 'normalizeTask', [
            [
                'id' => 2,
                'project_id' => 1,
                'category' => 'PMS系统配置',
                'task_name' => 'PMS基础档案配置',
                'task_desc' => '',
                'is_core' => 1,
                'deadline' => '2026-07-01',
                'status' => 'done',
                'sort_order' => 2,
            ],
            $this->project(),
        ]);

        self::assertSame(50, $doingTask['progress_percent']);
        self::assertSame(100, $doneTask['progress_percent']);
    }

    public function testMetricsIncludeSavedProgressRate(): void
    {
        $service = new OpeningService();
        $metrics = $this->invokeNonPublic($service, 'calculateMetrics', [
            $this->project(),
            [
                ['category' => 'PMS系统配置', 'status' => 'doing', 'progress_percent' => 50, 'is_core' => 1, 'risk_level' => 'medium', 'ai_suggestion' => '', 'deadline' => '2026-07-01'],
                ['category' => 'OTA上线配置', 'status' => 'done', 'progress_percent' => 100, 'is_core' => 1, 'risk_level' => 'low', 'ai_suggestion' => '', 'deadline' => '2026-07-01'],
            ],
            false,
        ]);

        self::assertSame(75.0, $metrics['metrics']['progress_rate']);
        self::assertSame(50.0, $metrics['category_progress'][0]['progress_rate']);
    }

    private function project(): array
    {
        return [
            'project_name' => '巢湖测试店开业项目',
            'hotel_name' => '巢湖测试',
            'city' => '西安',
            'brand' => '宿析',
            'positioning' => '中端商务',
            'room_count' => 86,
            'opening_date' => '2026-07-01',
            'manager_name' => '张三',
            'overall_score' => 0,
            'risk_level' => 'high',
        ];
    }

    private function tasks(): array
    {
        return [
            [
                'category' => 'PMS系统配置',
                'task_name' => 'PMS基础档案配置',
                'task_desc' => '完成酒店、房间、账号和夜审规则配置。',
                'is_core' => 1,
                'owner_name' => '张三',
                'deadline' => '2026-05-01',
                'status' => 'todo',
                'progress_percent' => 0,
                'risk_level' => 'high',
                'is_overdue' => true,
                'remark' => 'PMS账号未开通',
            ],
            [
                'category' => 'OTA上线配置',
                'task_name' => 'OTA门店资料上线',
                'task_desc' => '配置渠道门店资料、图片、政策与设施标签。',
                'is_core' => 1,
                'owner_name' => '李四',
                'deadline' => '2026-06-01',
                'status' => 'todo',
                'progress_percent' => 0,
                'risk_level' => 'high',
                'is_overdue' => false,
                'remark' => '',
            ],
        ];
    }
}
