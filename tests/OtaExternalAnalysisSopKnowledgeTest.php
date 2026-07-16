<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class OtaExternalAnalysisSopKnowledgeTest extends TestCase
{
    public function testReviewedExternalMaterialsAreSeededAsReferenceKnowledgeWithTruthfulBoundaries(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $playbookPath = $root . '/docs/ota_external_analysis_sop_knowledge_playbook.md';
        $backlogPath = $root . '/docs/ota_external_capability_backlog_20260716.md';
        $migrationPath = $root . '/database/migrations/20260716_seed_ota_external_analysis_sop_knowledge.sql';

        self::assertFileExists($playbookPath);
        self::assertFileExists($backlogPath);
        self::assertFileExists($migrationPath);

        $playbook = (string)file_get_contents($playbookPath);
        $backlog = (string)file_get_contents($backlogPath);
        $migration = (string)file_get_contents($migrationPath);
        $init = (string)file_get_contents($root . '/database/init_full.sql');

        self::assertStringContainsString('OTA 外网分析、运营 SOP 与商圈竞争脉冲知识手册', $playbook);
        self::assertStringContainsString('15 个模块、40 个章节、53 张卡片、195 个字段、5 份工作表', $playbook);
        self::assertStringContainsString('诊断得分”和“证据覆盖率', $playbook);
        self::assertStringContainsString('OTA间夜/物理房间数', $playbook);
        self::assertStringContainsString('captured_at', $playbook);
        self::assertStringContainsString('stay_date', $playbook);
        self::assertStringContainsString('OTA 渠道售罄酒店占比', $playbook);
        self::assertStringContainsString('本手册本身不触发 OTA 改价', $playbook);

        self::assertStringContainsString('P0-01', $backlog);
        self::assertStringContainsString('P0-04', $backlog);
        self::assertStringContainsString('P1-03', $backlog);
        self::assertStringContainsString('P2-02', $backlog);
        self::assertStringContainsString('`in_progress`：已进入开发，但尚未满足全部验收条件', $backlog);
        self::assertStringContainsString('不代表已经上线', $backlog);

        self::assertStringContainsString("SET @public_diag_source := 'ota_public_page_diagnosis_reference'", $migration);
        self::assertStringContainsString("SET @ota_sop_source := 'ota_operation_sop_reference'", $migration);
        self::assertStringContainsString("SET @competition_source := 'ota_competition_pulse_reference'", $migration);
        self::assertStringContainsString('external_public_reference_reviewed', $migration);
        self::assertStringContainsString('external_public_intro_reviewed_collection_unverified', $migration);
        self::assertStringContainsString('insufficient_evidence', $migration);
        self::assertStringContainsString('reference_template', $migration);
        self::assertStringContainsString('validated_sop', $migration);
        self::assertStringContainsString('ota_sold_out_hotel_share', $migration);
        self::assertStringContainsString('no_automatic_ota_price_or_inventory_write', $migration);
        self::assertStringContainsString('INSERT INTO `knowledge_base`', $migration);
        self::assertSame(0, substr_count($migration, 'DELETE FROM `knowledge_chunks`'));
        self::assertSame(22, substr_count($migration, 'INSERT INTO `tmp_ota_external_analysis_seed_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertStringContainsString("SET @external_knowledge_seed_owner := 'suxios.ota_external_analysis_sop_knowledge'", $migration);
        self::assertStringContainsString("'$.seed_key', CONCAT(`unit`.`source`, ':', `seed`.`type`)", $migration);
        self::assertStringContainsString("'$.seed_version', @external_knowledge_version", $migration);
        self::assertGreaterThanOrEqual(3, substr_count($migration, 'WHERE NOT EXISTS'));

        self::assertStringContainsString(
            'SOURCE ./database/migrations/20260716_seed_ota_external_analysis_sop_knowledge.sql;',
            $init
        );
    }

    public function testRepeatedSeedUpsertContractPreservesNonSeedAndOlderVersionChunks(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $migration = (string)file_get_contents(
            $root . '/database/migrations/20260716_seed_ota_external_analysis_sop_knowledge.sql'
        );

        self::assertStringContainsString('UPDATE `knowledge_chunks` AS `existing`', $migration);
        self::assertSame(2, substr_count($migration, "JSON_EXTRACT(`existing`.`content`, '$.seed_owner')"));
        self::assertSame(2, substr_count($migration, "JSON_EXTRACT(`existing`.`content`, '$.seed_key')"));
        self::assertSame(2, substr_count($migration, "JSON_EXTRACT(`existing`.`content`, '$.seed_version')"));
        self::assertStringNotContainsString('ALTER TABLE `knowledge_chunks`', $migration);

        $seedOwner = 'suxios.ota_external_analysis_sop_knowledge';
        $seedVersion = '2026-07-16';
        $seedKey = 'ota_public_page_diagnosis_reference:source_boundary';
        $chunks = [
            [
                'chunk_id' => 1,
                'unit_id' => 10,
                'type' => 'manual_note',
                'content' => ['note' => 'operator-authored'],
            ],
            [
                'chunk_id' => 2,
                'unit_id' => 10,
                'type' => 'source_boundary',
                'content' => [
                    'seed_owner' => $seedOwner,
                    'seed_key' => $seedKey,
                    'seed_version' => '2026-07-15',
                    'payload' => 'older-version',
                ],
            ],
        ];
        $incomingSeed = [
            'unit_id' => 10,
            'type' => 'source_boundary',
            'content' => [
                'seed_owner' => $seedOwner,
                'seed_key' => $seedKey,
                'seed_version' => $seedVersion,
                'payload' => 'current-version',
            ],
        ];

        $upsert = static function (array $rows, array $seed): array {
            $matched = false;
            foreach ($rows as &$row) {
                if (($row['unit_id'] ?? null) !== $seed['unit_id']) {
                    continue;
                }

                $content = $row['content'] ?? [];
                $seedContent = $seed['content'];
                if (($content['seed_owner'] ?? null) !== $seedContent['seed_owner']
                    || ($content['seed_key'] ?? null) !== $seedContent['seed_key']
                    || ($content['seed_version'] ?? null) !== $seedContent['seed_version']) {
                    continue;
                }

                $row['type'] = $seed['type'];
                $row['content'] = $seedContent;
                $matched = true;
            }
            unset($row);

            if (!$matched) {
                $seed['chunk_id'] = max(array_column($rows, 'chunk_id')) + 1;
                $rows[] = $seed;
            }

            return $rows;
        };

        $afterFirstRun = $upsert($chunks, $incomingSeed);
        $afterSecondRun = $upsert($afterFirstRun, $incomingSeed);

        self::assertCount(3, $afterSecondRun);
        self::assertSame('operator-authored', $afterSecondRun[0]['content']['note']);
        self::assertSame('older-version', $afterSecondRun[1]['content']['payload']);
        self::assertSame('current-version', $afterSecondRun[2]['content']['payload']);
        self::assertSame($seedVersion, $afterSecondRun[2]['content']['seed_version']);
    }

    public function testMaterializedSopContainsEveryReviewedCardAndUsesStableTransactionalUpsert(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);
        $path = $root . '/database/migrations/20260717_materialize_ota_sop_reference_cards.sql';
        self::assertFileExists($path);
        $sql = (string)file_get_contents($path);

        self::assertStringContainsString('MATERIALIZED_COUNTS modules=15 sections=40 cards=53 fields=195 worksheets=5', $sql);
        self::assertSame(113, substr_count($sql, 'INSERT INTO `knowledge_chunks`'));
        self::assertSame(113, substr_count($sql, 'UPDATE `knowledge_chunks`'));
        self::assertSame(0, substr_count($sql, 'DELETE FROM `knowledge_chunks`'));
        self::assertSame(1, substr_count($sql, 'START TRANSACTION;'));
        self::assertSame(1, substr_count($sql, 'COMMIT;'));
        self::assertSame(226, substr_count($sql, "'$.content_key'"));
        self::assertSame(226, substr_count($sql, "'$.seed_version'"));
        self::assertStringContainsString('INSERT INTO `knowledge_base`', $sql);
        self::assertStringContainsString('UPDATE `knowledge_base`', $sql);

        preg_match_all(
            "/SELECT @sop_materialized_unit_id, '(SOP模块|SOP章节|SOP卡片|SOP工作表)', JSON_EXTRACT\\(CONVERT\\(0x([0-9A-F]+) USING utf8mb4\\), '\\$'\\)/u",
            $sql,
            $matches,
            PREG_SET_ORDER
        );
        self::assertCount(113, $matches);

        $counts = ['SOP模块' => 0, 'SOP章节' => 0, 'SOP卡片' => 0, 'SOP工作表' => 0];
        $fieldCount = 0;
        $contentKeys = [];
        $sourceHashes = [];
        $moduleIds = [];
        foreach ($matches as $match) {
            $json = hex2bin($match[2]);
            self::assertIsString($json);
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            $counts[$match[1]]++;
            $contentKeys[] = $payload['content_key'] ?? '';
            $sourceHashes[] = $payload['source_snapshot_hash'] ?? '';
            if ($match[1] === 'SOP模块') {
                $moduleIds[] = $payload['module_id'] ?? '';
                self::assertNotSame('', trim((string)($payload['module_name'] ?? '')));
                self::assertArrayHasKey('summary', $payload);
            }
            if ($match[1] === 'SOP卡片') {
                $fieldCount += count($payload['fields'] ?? []);
                self::assertSame('operation_checklist', $payload['task_template']['object_type'] ?? null);
                self::assertTrue($payload['task_template']['human_approval_required'] ?? false);
                self::assertFalse($payload['task_template']['auto_write_ota'] ?? true);
            }
        }

        self::assertSame(['SOP模块' => 15, 'SOP章节' => 40, 'SOP卡片' => 53, 'SOP工作表' => 5], $counts);
        self::assertEqualsCanonicalizing(
            ['daily', 'onboarding', 'diagnosis', 'metrics', 'revenue', 'page-design', 'pricing', 'promotion', 'reviews', 'negative', 'review-cycle', 'performance', 'platforms', 'templates', 'terms'],
            $moduleIds
        );
        self::assertSame(195, $fieldCount);
        self::assertCount(113, array_unique($contentKeys));
        self::assertSame(['c3047b31da2aa0b5328cb21585d87ba91da98fecc63dd3bf1d5bcfbc2cf00f10'], array_values(array_unique($sourceHashes)));

        $init = (string)file_get_contents($root . '/database/init_full.sql');
        self::assertStringContainsString(
            'SOURCE ./database/migrations/20260717_materialize_ota_sop_reference_cards.sql;',
            $init
        );
    }

    public function testKnowledgeCenterExposesAllSopModulesAndReadableEvidenceMetadata(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);
        $page = (string)file_get_contents($root . '/resources/frontend/templates/fragments/20-page-knowledge-center.html');
        $dialog = (string)file_get_contents($root . '/resources/frontend/templates/fragments/38-dialogs-knowledge-center.html');
        $app = (string)file_get_contents($root . '/public/app-main.js');

        foreach (['daily', 'onboarding', 'diagnosis', 'metrics', 'revenue', 'page-design', 'pricing', 'promotion', 'reviews', 'negative', 'review-cycle', 'performance', 'platforms', 'templates', 'terms'] as $moduleId) {
            self::assertStringContainsString('value="' . $moduleId . '"', $page);
        }
        self::assertStringContainsString('knowledgeCenterFilter.evidence_level', $page);
        self::assertStringContainsString('knowledgeCenterFilter.version', $page);
        self::assertStringContainsString('view.sopFields', $dialog);
        self::assertStringContainsString('view.steps', $dialog);
        self::assertStringContainsString('view.acceptanceCriteria', $dialog);
        self::assertStringContainsString('view.worksheetHeaders', $dialog);
        self::assertStringContainsString('knowledgeCenterVisibleChunks', $dialog);
        foreach (['来源版本', '证据级别', '适用边界', '岗位', '场景', '平台'] as $label) {
            self::assertStringContainsString("{ label: '{$label}'", $app);
        }
        self::assertStringContainsString('knowledgeChunkMatchesCurrentFilter', $app);
    }
}
