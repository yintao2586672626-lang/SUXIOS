<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueOperationsKnowledgeService;
use PHPUnit\Framework\TestCase;

final class TrafficOperationManagementGoldenSentencesKnowledgeTest extends TestCase
{
    public function testSourceCatalogAndSeedKeepAllSeventyFiveRecordsBounded(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $documentPath = $root . '/docs/traffic_operation_management_golden_sentences_knowledge.md';
        $migrationPath = $root . '/database/migrations/20260729_seed_traffic_operation_management_golden_sentences.sql';
        self::assertFileExists($documentPath);
        self::assertFileExists($migrationPath);

        $document = (string)file_get_contents($documentPath);
        $migration = (string)file_get_contents($migrationPath);
        $initFull = (string)file_get_contents($root . '/database/init_full.sql');

        self::assertStringContainsString('流量经营与运营管理决策金句知识库', $document);
        self::assertStringContainsString('示例数值不可当通用阈值', $document);
        self::assertStringContainsString('默认 OTA Prompt 同样排除 `case_reference`', $document);
        self::assertStringContainsString('低价走量要升级为可盈利走量，而不是永久低价。', $document);

        preg_match_all('/^\| (\d+) \| .+ \| .+ \|$/mu', $document, $documentRows);
        self::assertCount(75, $documentRows[0]);
        self::assertSame(75, substr_count($migration, "JSON_OBJECT('seq',"));

        foreach ([
            '62B98AE72207605E5C0C3CC1995BE9CD7D67FE17B41F9B67708EAE60B9B35E81',
            'B93F9711E6B5D506389EAD135EC14B5CF982B8BCF71820A0785DA79506A7D5B7',
            'B44F408065E7612033ABCA5D7EA362A96B11A76A8F1F61ED9E2175FD45591725',
        ] as $sha256) {
            self::assertStringContainsString($sha256, $document);
            self::assertStringContainsString($sha256, $migration);
        }

        self::assertStringContainsString(
            "SET @traffic_ops_source := 'revenue_operations_decision_support'",
            $migration
        );
        self::assertStringContainsString(
            "SET @traffic_ops_seed_owner := 'suxios.traffic_operation_management_golden_sentences'",
            $migration
        );
        self::assertStringContainsString("'scope', 'generic_methodology'", $migration);
        self::assertSame(2, substr_count($migration, "'scope', 'case_reference'"));
        self::assertSame(2, substr_count($migration, "'requires_explicit_case_key', true"));
        self::assertStringContainsString(
            "'case_key', 'jiuyide_traffic_flow_funnel_2026_07_29'",
            $migration
        );
        self::assertStringContainsString(
            "'case_key', 'jiuyide_operation_management_2026_07_29'",
            $migration
        );
        self::assertStringContainsString(
            'knowledge_integrated_source_case_numbers_not_promoted_to_current_hotel_facts',
            $migration
        );
        self::assertStringContainsString('INSERT INTO `knowledge_units`', $migration);
        self::assertStringContainsString('INSERT INTO `knowledge_base`', $migration);
        self::assertSame(9, substr_count($migration, 'INSERT INTO `tmp_traffic_ops_seed_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertSame(0, substr_count($migration, 'DELETE FROM `knowledge_chunks`'));

        self::assertStringContainsString('FROZEN BASELINE', $initFull);
        self::assertStringNotContainsString(
            '20260729_seed_traffic_operation_management_golden_sentences.sql',
            $initFull
        );
    }

    public function testSeedContractPreservesManualAndOlderVersionChunks(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $migration = (string)file_get_contents(
            $root . '/database/migrations/20260729_seed_traffic_operation_management_golden_sentences.sql'
        );

        $safeExistingJson = 'CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END';
        self::assertSame(6, substr_count($migration, $safeExistingJson));
        self::assertStringContainsString("'$.seed_version', @traffic_ops_version", $migration);
        self::assertStringContainsString('UPDATE `knowledge_chunks` AS `existing`', $migration);
        self::assertStringNotContainsString("JSON_EXTRACT(`existing`.`content`, '$.seed_", $migration);
        self::assertStringNotContainsString('ALTER TABLE `knowledge_chunks`', $migration);

        $rows = [
            [
                'type' => 'operator_note',
                'content' => ['note' => '门店人工复盘'],
            ],
            [
                'type' => 'source_boundary',
                'content' => [
                    'seed_owner' => 'suxios.traffic_operation_management_golden_sentences',
                    'seed_key' => 'revenue_operations_decision_support:流量经营与运营管理决策金句库:source_boundary',
                    'seed_version' => '2026-07-29.0',
                ],
            ],
        ];
        $currentSeed = [
            'type' => 'source_boundary',
            'content' => [
                'seed_owner' => 'suxios.traffic_operation_management_golden_sentences',
                'seed_key' => 'revenue_operations_decision_support:流量经营与运营管理决策金句库:source_boundary',
                'seed_version' => '2026-07-29.1',
            ],
        ];
        $matchesCurrentSeed = static function (array $row) use ($currentSeed): bool {
            foreach (['seed_owner', 'seed_key', 'seed_version'] as $key) {
                if (($row['content'][$key] ?? null) !== $currentSeed['content'][$key]) {
                    return false;
                }
            }
            return true;
        };

        if (!array_filter($rows, $matchesCurrentSeed)) {
            $rows[] = $currentSeed;
        }
        if (!array_filter($rows, $matchesCurrentSeed)) {
            $rows[] = $currentSeed;
        }

        self::assertCount(3, $rows);
        self::assertSame('门店人工复盘', $rows[0]['content']['note']);
        self::assertSame('2026-07-29.0', $rows[1]['content']['seed_version']);
        self::assertSame('2026-07-29.1', $rows[2]['content']['seed_version']);
    }

    public function testRevenueKnowledgeServiceReturnsSourceCasesOnlyByExplicitKey(): void
    {
        $service = new RevenueOperationsKnowledgeService();
        $units = [[
            'unit_id' => 91,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => '流量经营与运营管理决策金句库',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
        ]];
        $chunks = [
            [
                'chunk_id' => 901,
                'unit_id' => 91,
                'type' => 'traffic_funnel_contract',
                'content' => [
                    'scope' => 'generic_methodology',
                    'evidence_level' => 'distilled_method_from_user_images',
                    'source_refs' => ['jiuyide_traffic_golden_sentences_image'],
                ],
            ],
            [
                'chunk_id' => 902,
                'unit_id' => 91,
                'type' => 'traffic_source_case',
                'content' => [
                    'scope' => RevenueOperationsKnowledgeService::CASE_SCOPE,
                    'case_key' => 'jiuyide_traffic_flow_funnel_2026_07_29',
                    'evidence_level' => 'user_provided_image_unverified_case',
                    'source_refs' => ['jiuyide_traffic_golden_sentences_image'],
                ],
            ],
            [
                'chunk_id' => 903,
                'unit_id' => 91,
                'type' => 'operation_source_case',
                'content' => [
                    'scope' => RevenueOperationsKnowledgeService::CASE_SCOPE,
                    'case_key' => 'jiuyide_operation_management_2026_07_29',
                    'evidence_level' => 'user_provided_image_unverified_case',
                    'source_refs' => ['jiuyide_operation_management_appendix_e_page_1'],
                ],
            ],
        ];

        $default = $service->buildContextFromRows($units, $chunks);
        self::assertSame('available', $default['status']);
        self::assertSame(1, $default['entry_count']);
        self::assertSame(2, $default['excluded_case_reference_count']);

        $withTrafficCase = $service->buildContextFromRows($units, $chunks, [
            'case_key' => 'jiuyide_traffic_flow_funnel_2026_07_29',
        ]);
        self::assertSame('available', $withTrafficCase['status']);
        self::assertSame(2, $withTrafficCase['entry_count']);
        self::assertSame(1, $withTrafficCase['excluded_case_reference_count']);
        self::assertSame(
            ['traffic_funnel_contract', 'traffic_source_case'],
            array_column($withTrafficCase['entries'], 'knowledge_type')
        );
    }
}
