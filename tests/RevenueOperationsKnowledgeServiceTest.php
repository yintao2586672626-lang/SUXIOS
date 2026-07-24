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
