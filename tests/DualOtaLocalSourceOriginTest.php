<?php
declare(strict_types=1);
namespace tests;
use app\service\DualOtaFieldClosureService;
use PHPUnit\Framework\TestCase;

final class DualOtaLocalSourceOriginTest extends TestCase
{
    private function evaluate(string $method): array
    {
        return DualOtaFieldClosureService::evaluate(['id' => 20, 'tenant_id' => 10], '2026-08-20', [[
            'id' => 1, 'tenant_id' => 10, 'system_hotel_id' => 20, 'source' => 'ctrip',
            'data_type' => 'business', 'data_date' => '2026-08-20', 'ingestion_method' => $method,
            'readback_verified' => 1, 'validation_status' => 'unverified', 'history_status' => 'unverified',
        ]]);
    }

    public function testDeclaredOriginsRemainSeparateFromQualityAndStrictIdentity(): void
    {
        $baseline = $this->evaluate('');
        $cases = [
            'manual' => ['manual_import', 'original_import_evidence_required'],
            'import_json' => ['manual_import', 'original_import_evidence_required'],
            'import_csv' => ['manual_import', 'original_import_evidence_required'],
            'import_excel' => ['manual_import', 'original_import_evidence_required'],
            'manual_input' => ['manual_import', 'original_import_evidence_required'],
            'user_provided' => ['manual_import', 'original_import_evidence_required'],
            'user_provided_unverified' => ['manual_import', 'original_import_evidence_required'],
            'manual_override' => ['manual_import', 'original_import_evidence_required'],
            'browser_assist_dom' => ['browser_assisted_import', 'structured_response_evidence_required'],
            'browser_profile' => ['collection_registered', 'collection_receipt_identity_required'],
            'profile_browser' => ['collection_registered', 'collection_receipt_identity_required'],
            'local_collector' => ['collection_registered', 'collection_receipt_identity_required'],
            'api' => ['interface_registered', 'interface_origin_identity_required'],
        ];
        foreach ($cases as $method => [$type, $recovery]) {
            $c = $this->evaluate($method);
            $record = $c['local_source_records']['ctrip']['records'][0];
            self::assertSame(['type' => $type, 'method' => $method, 'recovery_code' => $recovery], $record['source_origin'], $method);
            self::assertSame('unverified', $record['validation_status']);
            self::assertSame('verified', $record['readback_status']);
            self::assertSame($baseline['platforms'], $c['platforms']);
            self::assertSame($baseline['closure_digest'], $c['closure_digest']);
            self::assertSame($baseline['page_identity'], $c['page_identity']);
            self::assertSame('blocked', $c['platforms']['ctrip']['revenue_analysis']['status']);
            self::assertSame($c['local_source_records'], json_decode(json_encode($c['local_source_records']), true));
        }
    }

    public function testUnknownLegacyAndUnmarkedSimulationAreNotGuessedOrLeaked(): void
    {
        foreach (['', 'legacy_parser', 'historical_backfill', 'synthetic', 'mock', 'private_arbitrary_text'] as $method) {
            $record = $this->evaluate($method)['local_source_records']['ctrip']['records'][0];
            self::assertSame(['type' => 'unknown', 'method' => 'unknown', 'recovery_code' => 'origin_metadata_required'], $record['source_origin']);
            self::assertStringNotContainsString('private_arbitrary_text', json_encode($record));
        }
        self::assertSame('manual_import', $this->evaluate(' MANUAL ')['local_source_records']['ctrip']['records'][0]['source_origin']['type']);
    }
}
