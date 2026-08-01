<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaP0ScopeProjectionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MeituanSinglePointWiringTest extends TestCase
{
    public function testCollectionStatusExposesUnifiedSourceDateQualityAndReadbackEvidence(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/PlatformDataSourceConcern.php'
        );

        self::assertStringContainsString("'targetDateReadbackCheckSupported'", $source);
        self::assertStringContainsString("'targetDateReadbackVerifiedRows'", $source);
        self::assertStringContainsString("'targetDateReadbackUnverifiedRows'", $source);
        self::assertStringContainsString("\$row['sourceDateQuality']", $source);
        self::assertStringContainsString('OtaSourceDateQualityContractService', $source);
    }

    public function testMeituanP0RequiresReadbackWithoutChangingCtripGateInThisSlice(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/scripts/verify_p0_ota_field_loop_closure.php'
        );

        self::assertStringContainsString(
            "\$platform !== 'meituan' || (string)\$base['readback_status'] === 'ready'",
            $source
        );
        self::assertStringContainsString(
            'all_authoritative_target_date_meituan_traffic_rows_must_have_readback_verified=1',
            $source
        );
        self::assertStringContainsString("'readback_unverified'", $source);
    }

    public function testCaptureAndUnifiedReportReturnSourceDateQualityMetadata(): void
    {
        $capture = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/OnlineDataRequestConcern.php'
        );
        $report = (string)file_get_contents(
            dirname(__DIR__) . '/scripts/report_business_chain_status.php'
        );

        self::assertStringContainsString("'source' => 'meituan'", $capture);
        self::assertStringContainsString("'target_date' => \$targetDataDate", $capture);
        self::assertStringContainsString("'quality_status'", $capture);
        self::assertStringContainsString('business_chain_attach_source_date_quality', $report);
        self::assertStringContainsString("'source_date_quality'", $report);
        self::assertStringContainsString("'zero_as_missing_data'", $report);
    }

    public function testSinglePointContractHasNoDingdandaoOrCloudBrowserDependency(): void
    {
        $source = strtolower((string)file_get_contents(
            dirname(__DIR__) . '/app/service/OtaSourceDateQualityContractService.php'
        ));

        self::assertStringNotContainsString('dingdandao', $source);
        self::assertStringNotContainsString('cloud-browser', $source);
        self::assertStringNotContainsString('cloud_browser', $source);
    }

    public function testRefreshTimestampCannotStandInForMeituanBusinessDate(): void
    {
        $service = new OtaP0ScopeProjectionService();
        $method = (new ReflectionClass($service))->getMethod('rowDateScopeIsAuthoritative');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($service, [
            'raw_data' => json_encode(['date_source' => 'response.rtDataUpdateTime']),
        ], 'meituan'));
        self::assertTrue($method->invoke($service, [
            'raw_data' => json_encode(['date_source' => 'page.traffic_period_selection.readback']),
        ], 'meituan'));
        self::assertTrue($method->invoke($service, [
            'raw_data' => json_encode(['date_source' => 'response.rtDataUpdateTime']),
        ], 'ctrip'));

        $importer = (string)file_get_contents(
            dirname(__DIR__) . '/scripts/import_p0_ota_traffic_payload.php'
        );
        self::assertStringContainsString('refresh_timestamp_not_business_date_evidence', $importer);
        self::assertStringContainsString('p0_import_row_date_source_is_refresh_timestamp', $importer);

        $verifier = (string)file_get_contents(
            dirname(__DIR__) . '/scripts/verify_p0_ota_field_loop_closure.php'
        );
        self::assertStringContainsString('p0_traffic_row_date_scope_is_authoritative', $verifier);
        self::assertStringContainsString('meituan_refresh_timestamp_not_business_date_evidence', $verifier);
    }
}
