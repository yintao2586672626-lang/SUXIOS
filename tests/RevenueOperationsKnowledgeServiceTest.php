<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueOperationsKnowledgeService;
use PHPUnit\Framework\TestCase;

final class RevenueOperationsKnowledgeServiceTest extends TestCase
{
    public function testDefaultContextExcludesCaseReferenceUntilCaseKeyIsExplicit(): void
    {
        $service = new RevenueOperationsKnowledgeService();
        $units = [[
            'unit_id' => 11,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => '收益运营诊断与建议知识底座',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
            'description' => 'structured knowledge',
            'known_knowns' => '["诊断方法已确认"]',
            'known_unknowns' => '["当前门店事实待验证"]',
            'truth_profile_version' => '2026-07-29.1',
        ]];
        $chunks = [
            [
                'chunk_id' => 101,
                'unit_id' => 11,
                'type' => '收入变化诊断',
                'content' => [
                    'scope' => 'generic_methodology',
                    'evidence_level' => 'derived_metric_method',
                    'source_refs' => ['moke_2026_h2_plan'],
                    'formula' => ['volume_effect' => 'delta_room_nights * comparison_adr'],
                ],
            ],
            [
                'chunk_id' => 102,
                'unit_id' => 11,
                'type' => '墨客悦享案例',
                'content' => json_encode([
                    'scope' => RevenueOperationsKnowledgeService::CASE_SCOPE,
                    'case_key' => 'moke_yuexiang_2026_h2',
                    'evidence_level' => 'user_provided_unverified_case',
                    'source_refs' => ['moke_2026_h2_plan'],
                    'facts' => ['revenue_2026_h1' => 1099607],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $default = $service->buildContextFromRows($units, $chunks, ['hotel_id' => 7]);
        self::assertSame('available', $default['status']);
        self::assertSame(1, $default['entry_count']);
        self::assertSame(1, $default['excluded_case_reference_count']);
        self::assertSame('收入变化诊断', $default['entries'][0]['knowledge_type']);
        self::assertSame(['诊断方法已确认'], $default['entries'][0]['known_knowns']);
        self::assertSame(['当前门店事实待验证'], $default['entries'][0]['known_unknowns']);
        self::assertSame('2026-07-29.1', $default['entries'][0]['truth_profile_version']);

        $withCase = $service->buildContextFromRows($units, $chunks, [
            'hotel_id' => 7,
            'case_key' => 'moke_yuexiang_2026_h2',
        ]);
        self::assertSame('available', $withCase['status']);
        self::assertSame(2, $withCase['entry_count']);
        self::assertSame(0, $withCase['excluded_case_reference_count']);
        self::assertSame(
            ['收入变化诊断', '墨客悦享案例'],
            array_column($withCase['entries'], 'knowledge_type')
        );
    }

    public function testContextKeepsGlobalKnowledgeAndMatchingHotelKnowledgeOnly(): void
    {
        $service = new RevenueOperationsKnowledgeService();
        $units = [
            [
                'unit_id' => 11,
                'hotel_id' => 0,
                'created_by' => 0,
                'name' => 'global',
                'source' => RevenueOperationsKnowledgeService::SOURCE,
                'status' => 'done',
            ],
            [
                'unit_id' => 12,
                'hotel_id' => 8,
                'created_by' => 7,
                'name' => 'hotel-8',
                'source' => RevenueOperationsKnowledgeService::SOURCE,
                'status' => 'done',
            ],
            [
                'unit_id' => 13,
                'hotel_id' => 0,
                'created_by' => 99,
                'name' => 'forged-global',
                'source' => RevenueOperationsKnowledgeService::SOURCE,
                'status' => 'done',
            ],
        ];
        $chunks = [
            [
                'chunk_id' => 101,
                'unit_id' => 11,
                'type' => '使用边界',
                'content' => [
                    'scope' => 'generic_methodology',
                    'evidence_level' => 'decision_guardrail',
                    'source_refs' => ['moke_teaching_transcript'],
                ],
            ],
            [
                'chunk_id' => 102,
                'unit_id' => 12,
                'type' => '酒店规则',
                'content' => [
                    'scope' => 'hotel_specific',
                    'evidence_level' => 'hotel_validated_rule',
                    'source_refs' => ['hotel_8_review'],
                ],
            ],
            [
                'chunk_id' => 103,
                'unit_id' => 13,
                'type' => 'forged',
                'content' => [
                    'scope' => 'generic_methodology',
                    'evidence_level' => 'verified',
                    'source_refs' => ['attacker-controlled'],
                ],
            ],
        ];

        $context = $service->buildContextFromRows($units, $chunks, ['hotel_id' => 7]);
        self::assertSame(1, $context['unit_count']);
        self::assertSame(1, $context['entry_count']);
        self::assertSame(0, $context['entries'][0]['unit_hotel_id']);
    }

    public function testContextReportsTraceabilityGapInsteadOfUsingMalformedKnowledge(): void
    {
        $service = new RevenueOperationsKnowledgeService();
        $units = [[
            'unit_id' => 11,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => 'global',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
        ]];
        $chunks = [
            [
                'chunk_id' => 101,
                'unit_id' => 11,
                'type' => '使用边界',
                'content' => [
                    'scope' => 'generic_methodology',
                    'evidence_level' => 'decision_guardrail',
                    'source_refs' => ['moke_teaching_transcript'],
                ],
            ],
            [
                'chunk_id' => 102,
                'unit_id' => 11,
                'type' => '无来源规则',
                'content' => [
                    'scope' => 'generic_methodology',
                    'evidence_level' => 'unknown',
                    'source_refs' => [],
                ],
            ],
        ];

        $context = $service->buildContextFromRows($units, $chunks);
        self::assertSame('partial', $context['status']);
        self::assertSame(1, $context['entry_count']);
        self::assertSame(
            ['revenue_operations_knowledge_traceability_missing'],
            array_column($context['data_gaps'], 'code')
        );
    }

    public function testContextExcludesNonActiveUnitsAndChunks(): void
    {
        $service = new RevenueOperationsKnowledgeService();
        $units = [
            [
                'unit_id' => 11,
                'hotel_id' => 0,
                'created_by' => 0,
                'source' => RevenueOperationsKnowledgeService::SOURCE,
                'status' => 'done',
                'lifecycle_status' => 'active',
            ],
            [
                'unit_id' => 12,
                'hotel_id' => 0,
                'created_by' => 0,
                'source' => RevenueOperationsKnowledgeService::SOURCE,
                'status' => 'done',
                'lifecycle_status' => 'quarantined',
            ],
        ];
        $chunks = [
            [
                'chunk_id' => 101,
                'unit_id' => 11,
                'type' => 'active method',
                'content' => [
                    'lifecycle_status' => 'active',
                    'scope' => 'generic_methodology',
                    'evidence_level' => 'reviewed_method',
                    'source_refs' => ['active-source'],
                ],
            ],
            [
                'chunk_id' => 102,
                'unit_id' => 11,
                'type' => 'stale method',
                'content' => [
                    'lifecycle_status' => 'stale',
                    'scope' => 'generic_methodology',
                    'evidence_level' => 'old_method',
                    'source_refs' => ['stale-source'],
                ],
            ],
            [
                'chunk_id' => 103,
                'unit_id' => 12,
                'type' => 'quarantined unit method',
                'content' => [
                    'lifecycle_status' => 'active',
                    'scope' => 'generic_methodology',
                    'evidence_level' => 'old_method',
                    'source_refs' => ['quarantined-source'],
                ],
            ],
        ];

        $context = $service->buildContextFromRows($units, $chunks);

        self::assertSame('available', $context['status']);
        self::assertSame(1, $context['unit_count']);
        self::assertSame(1, $context['entry_count']);
        self::assertSame('active method', $context['entries'][0]['knowledge_type']);
    }

    public function testContextUsesFairUnitSelectionAndReportsTruncation(): void
    {
        $service = new RevenueOperationsKnowledgeService();
        $units = [];
        $chunks = [];
        $chunkId = 100;
        foreach ([31, 30, 29] as $unitId) {
            $units[] = [
                'unit_id' => $unitId,
                'hotel_id' => 0,
                'created_by' => 0,
                'name' => 'unit-' . $unitId,
                'source' => RevenueOperationsKnowledgeService::SOURCE,
                'status' => 'done',
                'lifecycle_status' => 'active',
            ];
            for ($index = 1; $index <= 3; $index++) {
                $chunks[] = [
                    'chunk_id' => ++$chunkId,
                    'unit_id' => $unitId,
                    'type' => 'method-' . $index,
                    'content' => [
                        'scope' => 'generic_methodology',
                        'evidence_level' => 'reviewed_method',
                        'source_refs' => ['source-' . $unitId],
                    ],
                ];
            }
        }

        $context = $service->buildContextFromRows($units, $chunks, ['limit' => 4]);

        self::assertSame('partial', $context['status']);
        self::assertTrue($context['truncated']);
        self::assertSame(9, $context['eligible_entry_count']);
        self::assertSame(5, $context['omitted_entry_count']);
        self::assertSame(3, $context['selected_unit_count']);
        self::assertSame([31, 30, 29, 31], array_column($context['entries'], 'unit_id'));
        self::assertContains(
            'revenue_operations_knowledge_truncated',
            array_column($context['data_gaps'], 'code')
        );
    }

    public function testContextHardFiltersExplicitPlatformTagsButKeepsGenericMethods(): void
    {
        $service = new RevenueOperationsKnowledgeService();
        $units = [[
            'unit_id' => 31,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => 'platform-contracts',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
            'lifecycle_status' => 'active',
        ]];
        $chunks = [
            [
                'chunk_id' => 101,
                'unit_id' => 31,
                'type' => 'ctrip-rule',
                'content' => [
                    'scope' => 'platform_rule',
                    'platforms' => ['ctrip'],
                    'module_id' => 'ctrip_fulfillment',
                    'evidence_level' => 'official_current_rule',
                    'source_refs' => ['ctrip-rule'],
                ],
            ],
            [
                'chunk_id' => 102,
                'unit_id' => 31,
                'type' => 'meituan-rule',
                'content' => [
                    'scope' => 'platform_rule',
                    'platforms' => ['meituan'],
                    'module_id' => 'meituan_rule',
                    'evidence_level' => 'official_current_rule',
                    'source_refs' => ['meituan-rule'],
                ],
            ],
            [
                'chunk_id' => 103,
                'unit_id' => 31,
                'type' => 'generic-rule',
                'content' => [
                    'scope' => 'generic_methodology',
                    'evidence_level' => 'reviewed_method',
                    'source_refs' => ['generic-source'],
                ],
            ],
        ];

        $context = $service->buildContextFromRows($units, $chunks, ['platform' => 'ctrip']);

        self::assertSame('available', $context['status']);
        self::assertSame(['ctrip-rule', 'generic-rule'], array_column($context['entries'], 'knowledge_type'));
        self::assertSame(1, $context['excluded_platform_mismatch_count']);
        self::assertSame(['ctrip'], $context['platforms']);
    }

    public function testContextWithholdsUnresolvedClaimsAndKeepsExplicitResolutionOnly(): void
    {
        $service = new RevenueOperationsKnowledgeService();
        $units = [[
            'unit_id' => 31,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => 'conflicting-rules',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-07-30 00:00:00',
        ]];
        $chunks = [
            [
                'chunk_id' => 101,
                'unit_id' => 31,
                'type' => 'old-rule',
                'content' => [
                    'scope' => 'platform_rule',
                    'evidence_level' => 'official_current_rule',
                    'source_refs' => ['official-old'],
                    'conflict_key' => 'feedback_window_days',
                    'claim_value' => 30,
                ],
            ],
            [
                'chunk_id' => 102,
                'unit_id' => 31,
                'type' => 'new-rule',
                'content' => [
                    'scope' => 'platform_rule',
                    'evidence_level' => 'official_current_rule',
                    'source_refs' => ['official-new'],
                    'conflict_key' => 'feedback_window_days',
                    'claim_value' => 90,
                ],
            ],
        ];

        $unresolved = $service->buildContextFromRows($units, $chunks, [
            'as_of' => '2026-07-30 12:00:00',
        ]);
        self::assertSame('empty', $unresolved['status']);
        self::assertSame(0, $unresolved['entry_count']);
        self::assertSame(1, $unresolved['unresolved_conflict_count']);
        self::assertContains(
            'knowledge_claim_conflict_unresolved',
            array_column($unresolved['data_gaps'], 'code')
        );

        $chunks[1]['content']['resolution_status'] = 'resolved';
        $resolved = $service->buildContextFromRows($units, $chunks, [
            'as_of' => '2026-07-30 12:00:00',
        ]);
        self::assertSame('available', $resolved['status']);
        self::assertSame([102], array_column($resolved['entries'], 'chunk_id'));
        self::assertSame(1, $resolved['resolved_conflict_count']);
        self::assertSame(0, $resolved['unresolved_conflict_count']);
    }

    public function testReviewDueKnowledgeIsVisibleAsPartialButNotDecisionSafe(): void
    {
        $service = new RevenueOperationsKnowledgeService();
        $context = $service->buildContextFromRows([[
            'unit_id' => 31,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => 'review-due-method',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
            'lifecycle_status' => 'active',
            'reviewed_at' => '2025-01-01 00:00:00',
            'review_due_at' => '2025-04-01 00:00:00',
        ]], [[
            'chunk_id' => 101,
            'unit_id' => 31,
            'type' => 'reviewed-method',
            'content' => [
                'scope' => 'generic_methodology',
                'evidence_level' => 'reviewed_method',
                'source_refs' => ['method-review'],
            ],
        ]], [
            'as_of' => '2026-07-30 12:00:00',
        ]);

        self::assertSame('partial', $context['status']);
        self::assertSame(1, $context['entry_count']);
        self::assertSame('review_due', $context['entries'][0]['knowledge_gate']['freshness_status']);
        self::assertFalse($context['entries'][0]['knowledge_gate']['decision_safe']);
        self::assertContains(
            'knowledge_review_due',
            array_column($context['data_gaps'], 'code')
        );
    }

    public function testKnowledgeArtifactsAreSeededAndKeepCaseScopeProtected(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $documentPath = $root . '/docs/revenue_operations_decision_support_playbook.md';
        $migrationPath = $root . '/database/migrations/20260714_seed_revenue_operations_decision_support_knowledge.sql';
        self::assertFileExists($documentPath);
        self::assertFileExists($migrationPath);

        $document = (string)file_get_contents($documentPath);
        $migration = (string)file_get_contents($migrationPath);
        $init = (string)file_get_contents($root . '/database/init_full.sql');

        self::assertStringContainsString('收益运营诊断与建议知识底座', $document);
        self::assertStringContainsString('case_key=moke_yuexiang_2026_h2', $document);
        self::assertStringContainsString('没有事实和前置条件时，只返回补数要求', $document);
        self::assertStringContainsString('RevenueOperationsKnowledgeService', $document);

        self::assertStringContainsString("SET @revops_source := 'revenue_operations_decision_support'", $migration);
        self::assertStringContainsString("'scope', 'generic_methodology'", $migration);
        self::assertStringContainsString("'scope', 'case_reference'", $migration);
        self::assertStringContainsString("'case_key', 'moke_yuexiang_2026_h2'", $migration);
        self::assertStringContainsString("'source_manifest'", $migration);
        self::assertStringContainsString("'automatic_inventory_write'", $migration);
        self::assertStringContainsString('INSERT INTO `knowledge_base`', $migration);
        self::assertStringContainsString('WHERE NOT EXISTS', $migration);
        self::assertStringNotContainsString('F:/wx/', str_replace('\\', '/', $document . $migration));

        self::assertStringContainsString(
            'SOURCE ./database/migrations/20260714_seed_revenue_operations_decision_support_knowledge.sql;',
            $init
        );
    }
}
