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
        self::assertStringContainsString('positioning_impact', $payload);

        $metrics = $this->invokeNonPublic($service, 'calculateMetrics', [
            $this->project(),
            $this->tasks(),
            true,
        ]);
        self::assertSame('llm', $metrics['opening_suggestion_source']);
        self::assertSame('大模型生成建议', $metrics['opening_suggestion_source_label']);
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
        self::assertNotEmpty(array_filter($suggestions, static fn(string $suggestion): bool => str_contains($suggestion, '中端商务定位会重点影响')));

        $metrics = $this->invokeNonPublic($service, 'calculateMetrics', [
            $this->project(),
            $this->tasks(),
            true,
        ]);
        self::assertSame('rule_fallback', $metrics['opening_suggestion_source']);
        self::assertSame('规则兜底建议', $metrics['opening_suggestion_source_label']);
    }

    public function testTaskTemplatesUsePositioningImpact(): void
    {
        $service = new OpeningService();
        $templates = $this->invokeNonPublic($service, 'taskTemplates', [
            array_merge($this->project(), ['positioning' => '高端商务']),
        ]);

        $taskByName = [];
        foreach ($templates as $task) {
            $taskByName[$task['task_name']] = $task;
        }

        self::assertStringContainsString('高端商务定位', $taskByName['OTA门店资料上线']['ai_suggestion']);
        self::assertStringContainsString('高端商务定位', $taskByName['房型标准与价格体系确认']['ai_suggestion']);
        self::assertStringContainsString('高端商务定位', $taskByName['布草与客用品盘点']['ai_suggestion']);
        self::assertStringContainsString('高端商务定位', $taskByName['前台全流程演练']['ai_suggestion']);
        self::assertStringContainsString('高端商务定位', $taskByName['开业营销素材发布']['ai_suggestion']);
        self::assertSame('rule_template', $taskByName['OTA门店资料上线']['suggestion_source']);
    }

    public function testTaskProgressIsNotInferredFromStatusWhenValueIsMissing(): void
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

        self::assertNull($doingTask['progress_percent']);
        self::assertFalse($doingTask['progress_percent_known']);
        self::assertNull($doneTask['progress_percent']);
        self::assertFalse($doneTask['progress_percent_known']);
    }

    public function testMetricsIncludeSavedProgressRate(): void
    {
        $service = new OpeningService();
        $metrics = $this->invokeNonPublic($service, 'calculateMetrics', [
            $this->project(),
            [
                ['category' => 'PMS系统配置', 'status' => 'doing', 'progress_percent' => 50, 'is_core' => 1, 'risk_level' => 'medium', 'ai_suggestion' => '先跑通配置流程', 'deadline' => '2026-07-01'],
                ['category' => 'OTA上线配置', 'status' => 'done', 'progress_percent' => 100, 'is_core' => 1, 'risk_level' => 'low', 'ai_suggestion' => '先闭环渠道页面', 'deadline' => '2026-07-01'],
            ],
            false,
        ]);

        self::assertSame(75.0, $metrics['metrics']['progress_rate']);
        self::assertSame(50.0, $metrics['category_progress'][0]['progress_rate']);
        self::assertSame(75.0, $metrics['metrics']['suggested_task_progress_rate']);
        self::assertSame(2, $metrics['metrics']['suggested_task_count']);
        self::assertSame(100.0, $metrics['metrics']['suggestion_coverage_rate']);
        self::assertSame(['rule_template' => 2], $metrics['metrics']['task_suggestion_source_counts']);
        self::assertSame('complete', $metrics['metrics']['suggested_task_progress_data_status']);
        // 旧字段保留为兼容别名，但定义不再按 AI 渗透解释。
        self::assertSame(75.0, $metrics['metrics']['ai_penetration_rate']);
        self::assertSame(2, $metrics['metrics']['ai_covered_tasks']);
        self::assertStringContainsString('不代表 AI 渗透率', $metrics['metrics']['legacy_field_definitions']['ai_penetration_rate']);
    }

    public function testMetricsKeepUndefinedRatesMissingWhenChecklistDoesNotExist(): void
    {
        $metrics = $this->invokeNonPublic(new OpeningService(), 'calculateMetrics', [
            array_merge($this->project(), [
                'id' => 9,
                'hotel_id' => 7,
                'updated_at' => '2026-07-19 08:30:00',
            ]),
            [],
            false,
        ]);

        self::assertNull($metrics['project']['overall_score']);
        self::assertSame('no_tasks', $metrics['project']['overall_score_status']);
        self::assertSame('medium', $metrics['project']['risk_level']);
        self::assertNull($metrics['metrics']['completion_rate']);
        self::assertNull($metrics['metrics']['core_completion_rate']);
        self::assertSame('no_tasks', $metrics['metrics']['data_status']);
        self::assertSame('unverified', $metrics['truth_context']['status']);
        self::assertTrue($metrics['truth_context']['persistence']['readback_verified']);
        self::assertStringContainsString('检查清单尚未生成', $metrics['truth_context']['failure_reason']);
        self::assertSame('missing', $metrics['metrics']['metric_truth']['completion_rate']['calculation_status']);
        self::assertNull($metrics['category_progress'][0]['completion_rate']);
        self::assertSame('missing', $metrics['category_progress'][0]['truth']['calculation_status']);
    }

    public function testAiPenetrationRateIsZeroBeforeProgressStarts(): void
    {
        $service = new OpeningService();
        $metrics = $this->invokeNonPublic($service, 'calculateMetrics', [
            $this->project(),
            [
                ['category' => 'PMS系统配置', 'status' => 'todo', 'progress_percent' => 0, 'is_core' => 1, 'risk_level' => 'high', 'ai_suggestion' => '先跑通配置流程', 'deadline' => '2026-07-01'],
                ['category' => 'OTA上线配置', 'status' => 'todo', 'progress_percent' => 0, 'is_core' => 1, 'risk_level' => 'high', 'ai_suggestion' => '先闭环渠道页面', 'deadline' => '2026-07-01'],
            ],
            false,
        ]);

        self::assertSame(0.0, $metrics['metrics']['ai_penetration_rate']);
        self::assertSame(2, $metrics['metrics']['ai_covered_tasks']);
        self::assertSame(0.0, $metrics['metrics']['suggested_task_progress_rate']);
        self::assertSame(2, $metrics['metrics']['suggested_task_count']);
    }

    public function testMissingProgressMakesAggregateUnknownInsteadOfInventingStatusPercentages(): void
    {
        $service = new OpeningService();
        $metrics = $this->invokeNonPublic($service, 'calculateMetrics', [
            $this->project(),
            [
                ['category' => 'PMS系统配置', 'status' => 'doing', 'is_core' => 1, 'risk_level' => 'medium', 'ai_suggestion' => '先跑通配置流程', 'deadline' => '2026-07-01'],
                ['category' => 'OTA上线配置', 'status' => 'blocked', 'progress_percent' => 40, 'is_core' => 1, 'risk_level' => 'high', 'ai_suggestion' => '先闭环渠道页面', 'deadline' => '2026-07-01'],
            ],
            false,
        ]);

        self::assertNull($metrics['metrics']['progress_rate']);
        self::assertSame(40.0, $metrics['metrics']['recorded_progress_rate']);
        self::assertSame('partial', $metrics['metrics']['progress_data_status']);
        self::assertSame(1, $metrics['metrics']['progress_missing_tasks']);
        self::assertNull($metrics['metrics']['suggested_task_progress_rate']);
        self::assertSame('partial', $metrics['metrics']['suggested_task_progress_data_status']);
    }

    public function testOpeningProjectScopeAllowsOnlyCreatorForNonSuperAdmin(): void
    {
        $service = new OpeningService();

        self::assertTrue($this->invokeNonPublic($service, 'canAccessOwnedProject', [
            ['created_by' => 12, 'hotel_id' => 7],
            [7],
            12,
            false,
        ]));
        self::assertFalse($this->invokeNonPublic($service, 'canAccessOwnedProject', [
            ['created_by' => 12, 'hotel_id' => 7],
            [7],
            13,
            false,
        ]));
        self::assertFalse($this->invokeNonPublic($service, 'canAccessOwnedProject', [
            ['created_by' => 12, 'hotel_id' => 8],
            [7],
            12,
            false,
        ]));
        self::assertTrue($this->invokeNonPublic($service, 'canAccessOwnedProject', [
            ['created_by' => 12, 'hotel_id' => 99],
            [],
            13,
            true,
        ]));
    }

    public function testOpeningProjectHotelBindingUsesPermittedScope(): void
    {
        $service = new OpeningService();

        self::assertSame(7, $this->invokeNonPublic($service, 'resolveProjectHotelId', [
            [],
            [7],
        ]));
        self::assertSame(8, $this->invokeNonPublic($service, 'resolveProjectHotelId', [
            ['hotel_id' => 8],
            [7, 8],
        ]));
        self::assertSame(0, $this->invokeNonPublic($service, 'resolveProjectHotelId', [
            [],
            [7, 8],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('开业项目门店不在当前权限范围');
        $this->invokeNonPublic($service, 'resolveProjectHotelId', [
            ['hotel_id' => 99],
            [7, 8],
        ]);
    }

    public function testBuildExecutionIntentInputRequiresBoundOpeningHotel(): void
    {
        $service = new OpeningService();

        $this->expectException(\InvalidArgumentException::class);
        $service->buildExecutionIntentInput(['id' => 1, 'hotel_id' => 0, 'project_name' => 'Opening Project']);
    }

    public function testSavedOpeningChecklistReportsBindingMissingWithoutInventingHotelScope(): void
    {
        $metrics = $this->invokeNonPublic(new OpeningService(), 'calculateMetrics', [
            array_merge($this->project(), [
                'id' => 72,
                'hotel_id' => 0,
                'updated_at' => '2026-07-19 08:30:00',
            ]),
            $this->tasks(),
            false,
        ]);

        self::assertSame('binding_missing', $metrics['truth_context']['status']);
        self::assertSame('未绑定门店', $metrics['truth_context']['status_label']);
        self::assertNull($metrics['truth_context']['hotel_id']);
        self::assertSame([], $metrics['truth_context']['hotels']);
        self::assertStringContainsString('项目未绑定目标门店', $metrics['truth_context']['failure_reason']);
    }

    public function testBuildExecutionIntentInputUsesOpeningProjectScope(): void
    {
        $service = new OpeningService();

        $input = $service->buildExecutionIntentInput([
            'id' => 9,
            'hotel_id' => 7,
            'project_name' => 'Opening Project',
            'hotel_name' => 'Hotel A',
            'opening_date' => '2026-07-01',
            'status' => 'preparing',
            'overall_score' => 72.5,
            'risk_level' => 'medium',
            'days_left' => 17,
        ], [
            'metrics' => [
                'completion_rate' => 61.2,
                'core_completion_rate' => 70.0,
            ],
        ], ['date_start' => '2026-06-14']);

        self::assertSame('opening', $input['source_module']);
        self::assertSame(9, $input['source_record_id']);
        self::assertSame(7, $input['hotel_id']);
        self::assertSame('internal', $input['platform']);
        self::assertSame('opening', $input['object_type']);
        self::assertSame('opening_go_live_closure', $input['target_value']['target_metric']);
        self::assertSame('opening_project_and_tasks', $input['evidence']['source_scope']);
        self::assertSame('medium', $input['risk_level']);
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
