<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class DomesticOtaPmsKnowledgeGapAbsorptionTest extends TestCase
{
    public function testForwardMigrationAbsorbsThreeContractsAndRepairsExactConflicts(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $migrationPath = $root
            . '/database/migrations/20260730_z_absorb_domestic_ota_pms_knowledge_gaps.sql';
        $documentPath = $root . '/docs/domestic_ota_pms_knowledge_gap_absorption_20260730.md';
        self::assertFileExists($migrationPath);
        self::assertFileExists($documentPath);

        $migration = (string)file_get_contents($migrationPath);
        $document = (string)file_get_contents($documentPath);

        foreach ([
            '携程订单履约与结算官方语义合同',
            '订单来了PMS当前版本官方语义合同',
            '大众点评独立评价规则官方语义合同',
        ] as $unitName) {
            self::assertStringContainsString($unitName, $migration);
            self::assertStringContainsString($unitName, $document);
        }
        foreach ([
            'ctrip_hotel_merchant_rules_2025_11',
            'dingdandao_night_audit_2024_06',
            'dingdandao_order_accounting_2026_04',
            'dianping_integrity_general_20260430',
            'dianping_violation_rules_20260708',
        ] as $sourceKey) {
            self::assertStringContainsString($sourceKey, $migration);
            self::assertStringContainsString($sourceKey, $document);
        }

        self::assertStringContainsString(
            "'$.rows[2].formula', 'sum(room_revenue)'",
            $migration
        );
        self::assertStringContainsString(
            'paid_amount_room_revenue_fallback_removed',
            $migration
        );
        self::assertStringContainsString(
            "'standard_automatic_etl', 'disabled'",
            $migration
        );
        self::assertStringContainsString(
            "'$.module_id', 'domestic_public_source_monitor'",
            $migration
        );
        self::assertStringContainsString('INSERT INTO `knowledge_base`', $migration);
        self::assertStringContainsString("@gap_sem_version := '2026-07-30.3'", $migration);
        self::assertStringContainsString('known_unknowns', $migration);
        self::assertStringContainsString('blocked_uses', $migration);
        self::assertStringContainsString('来源版本指纹', $document);
        self::assertStringContainsString('不得由行业文章、营销页或历史版本补猜', $document);
    }

    public function testSemanticLayerAndFreshInstallIndexReferenceTheNewContract(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $semantic = (string)file_get_contents(
            $root . '/.agents/skills/suxi-ota-revenue-semantic-layer/references/semantic-layer.md'
        );
        $inventory = (string)file_get_contents(
            $root . '/.agents/skills/suxi-ota-revenue-semantic-layer/references/source-inventory.md'
        );
        $evidence = (string)file_get_contents(
            $root . '/.agents/skills/suxi-ota-revenue-semantic-layer/references/evidence.md'
        );
        $pluginSemantic = (string)file_get_contents(
            $root . '/plugins/suxi-os-toolkit/skills/suxi-ota-revenue-semantic-layer/references/semantic-layer.md'
        );
        $pluginInventory = (string)file_get_contents(
            $root . '/plugins/suxi-os-toolkit/skills/suxi-ota-revenue-semantic-layer/references/source-inventory.md'
        );
        $pluginEvidence = (string)file_get_contents(
            $root . '/plugins/suxi-os-toolkit/skills/suxi-ota-revenue-semantic-layer/references/evidence.md'
        );
        $init = (string)file_get_contents($root . '/database/init_full.sql');

        self::assertStringContainsString('Last synthesized: 2026-07-30', $semantic);
        self::assertStringContainsString('缺少结构化房费时不可计算', $semantic);
        self::assertStringNotContainsString('缺结构化房费时沿用 OTA 成交额', $semantic);
        self::assertStringContainsString('PMS business-day and accounting-chain review', $semantic);
        self::assertStringContainsString('Meituan and Dianping review rules are separate', $semantic);

        self::assertStringContainsString('Sources checked: 36', $inventory);
        self::assertStringContainsString('Dianping merchant integrity', $inventory);
        self::assertStringContainsString('Dingdandao night-audit', $inventory);
        self::assertStringContainsString('missing room revenue is not replaced with paid amount', $evidence);
        self::assertSame($semantic, $pluginSemantic);
        self::assertSame($inventory, $pluginInventory);
        self::assertSame($evidence, $pluginEvidence);

        self::assertStringContainsString('FROZEN BASELINE', $init);
        self::assertStringNotContainsString(
            '20260730_z_absorb_domestic_ota_pms_knowledge_gaps.sql',
            $init
        );
    }

    public function testVerifierAndRetrievalServicesKeepTheBoundaryExecutable(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $verifierPath = $root . '/scripts/verify_knowledge_absorption.php';
        self::assertFileExists($verifierPath);
        $verifier = (string)file_get_contents($verifierPath);
        $service = (string)file_get_contents(
            $root . '/app/service/RevenueOperationsKnowledgeService.php'
        );
        $agent = (string)file_get_contents($root . '/app/controller/Agent.php');

        self::assertStringContainsString('retrieval_truncation_not_explicit', $verifier);
        self::assertStringContainsString('platform_leak:', $verifier);
        self::assertStringContainsString("'eligible_entry_count'", $service);
        self::assertStringContainsString("'omitted_entry_count'", $service);
        self::assertStringContainsString("'truncated'", $service);
        self::assertStringContainsString('excluded_platform_mismatch_count', $service);
        self::assertStringContainsString('isOtaKnowledgeBaseCompatibleWithPlatform', $agent);
        self::assertStringContainsString('otaKnowledgePayloadExplicitlyMatchesPlatform', $agent);
    }
}
