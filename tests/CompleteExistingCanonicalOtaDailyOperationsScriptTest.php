<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class CompleteExistingCanonicalOtaDailyOperationsScriptTest extends TestCase
{
    public function testExistingDailyRepairUsesCanonicalCollectionAnchorV2(): void
    {
        $path = dirname(__DIR__)
            . DIRECTORY_SEPARATOR . 'scripts'
            . DIRECTORY_SEPARATOR . 'complete_existing_canonical_ota_daily_operations.php';
        $source = file_get_contents($path);

        self::assertIsString($source);
        self::assertStringContainsString(
            'use app\\service\\OtaCollectionAnchorService;',
            $source
        );
        self::assertStringContainsString("'historical_core_contract_status'", $source);
        self::assertStringContainsString("'collection_anchor_contract_version'", $source);
        self::assertStringContainsString('OtaCollectionAnchorService::CONTRACT_VERSION', $source);
        self::assertMatchesRegularExpression(
            '/OtaCollectionAnchorService::hash\(\s*\[\$sourceTask\]\s*\)/',
            $source
        );
        self::assertStringNotContainsString(
            "hash('sha256', json_encode([\$sourceTask]",
            $source
        );
    }
}
