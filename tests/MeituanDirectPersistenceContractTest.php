<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class MeituanDirectPersistenceContractTest extends TestCase
{
    public function testDirectMeituanWritersCountOnlyRowsThatCanBeReadBack(): void
    {
        $captured = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/MeituanCapturedDataConcern.php');
        $daily = (string)file_get_contents(dirname(__DIR__) . '/app/service/OnlineDailyDataPersistenceService.php');

        self::assertStringContainsString('insertGetId($data)', $captured);
        self::assertStringContainsString(
            'OnlineDailyDataPersistenceService::applyTenantScope($row, $columns)',
            $captured
        );
        self::assertStringContainsString('verifiedMeituanCapturedDailyRowReadback', $captured);
        self::assertStringContainsString('insertGetId($data)', $daily);
        self::assertStringContainsString('verifiedTrafficRowReadback', $daily);
    }

    public function testTrafficOrdersAndAdsExposeVerifiedPersistenceState(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataManualFetchConcern.php');

        self::assertGreaterThanOrEqual(2, substr_count($source, 'buildMeituanDirectPersistenceState('));
        self::assertStringContainsString("'meituan_traffic'", $source);
        self::assertStringContainsString("'meituan_' . \$section", $source);
        self::assertStringContainsString("['orders', 'ads']", $source);
        self::assertStringContainsString("'success' => \$persistenceState['persisted']", $source);
    }
}
