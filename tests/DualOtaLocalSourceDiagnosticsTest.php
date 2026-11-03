<?php
declare(strict_types=1);
namespace tests;
use app\service\DualOtaFieldClosureService;
use PHPUnit\Framework\TestCase;

final class DualOtaLocalSourceDiagnosticsTest extends TestCase
{
    private function row(array $changes = []): array
    {
        return array_replace(['id' => 1, 'tenant_id' => 10, 'system_hotel_id' => 20,
            'source' => 'ctrip', 'data_date' => '2026-08-20', 'data_type' => 'business',
            'readback_verified' => 0, 'validation_status' => 'unverified', 'history_status' => 'unverified'], $changes);
    }
    private function evaluate(array $rows): array
    {
        return DualOtaFieldClosureService::evaluate(['id' => 20, 'tenant_id' => 10], '2026-08-20', $rows);
    }
    public function testPresentUnverifiedRowsAreDistinctFromEmptyWithoutChangingFactDigest(): void
    {
        $empty = $this->evaluate([]);
        $present = $this->evaluate([$this->row()]);
        self::assertSame('no_local_records', $empty['local_source_records']['ctrip']['state']);
        self::assertSame(0, $empty['local_source_records']['ctrip']['record_count']);
        self::assertSame('local_records_present', $present['local_source_records']['ctrip']['state']);
        self::assertSame(1, $present['local_source_records']['ctrip']['record_count']);
        self::assertSame($empty['platforms'], $present['platforms']);
        self::assertSame($empty['closure_digest'], $present['closure_digest']);
        self::assertSame($empty['page_identity'], $present['page_identity']);
        self::assertSame(0, $present['revenue_analysis_consumable_field_count']);
        self::assertSame('blocked', $present['platforms']['ctrip']['revenue_analysis']['status']);
        foreach ($present['platforms']['ctrip']['fields'] as $field) {
            self::assertNull($field['value']);
            self::assertFalse($field['revenue_analysis_consumable']);
        }
    }
    public function testMetadataProjectionIsScopedAndExcludesBusinessValues(): void
    {
        $c = $this->evaluate([$this->row(['raw_data' => '{"secret":"fixture"}', 'amount' => 999]),
            $this->row(['id' => 2, 'tenant_id' => 11]), $this->row(['id' => 3, 'system_hotel_id' => 21]),
            $this->row(['id' => 4, 'data_date' => '2026-08-19']), $this->row(['id' => 5, 'source' => 'unknown']),
            $this->row(['id' => 6, 'source' => 'meituan'])]);
        foreach (['ctrip', 'meituan'] as $platform) {
            $d = $c['local_source_records'][$platform];
            self::assertSame(1, $d['record_count']);
            self::assertTrue($d['diagnostic_only']);
            self::assertSame(['record_ref', 'source_origin', 'validation_status', 'history_status', 'readback_status', 'requirements'], array_keys($d['records'][0]));
        }
        self::assertSame('online_daily_data#1', $c['local_source_records']['ctrip']['records'][0]['record_ref']);
        self::assertSame('online_daily_data#6', $c['local_source_records']['meituan']['records'][0]['record_ref']);
        self::assertStringNotContainsString('secret', json_encode($c['local_source_records']));
        self::assertStringNotContainsString('999', json_encode($c['local_source_records']));
    }
    public function testReadbackPassedDoesNotOverrideUnverifiedQualityOrReceipt(): void
    {
        $c = $this->evaluate([$this->row(['readback_verified' => 1])]);
        $r = $c['local_source_records']['ctrip']['records'][0];
        self::assertSame('verified', $r['readback_status']);
        self::assertSame('unverified', $r['validation_status']);
        self::assertNotContains('record_readback_required', $r['requirements']);
        self::assertContains('record_validation_required', $r['requirements']);
        self::assertContains('current_receipt_binding_required', $r['requirements']);
        self::assertContains('exact_scope_readback_required', $r['requirements']);
        self::assertSame([], $c['platforms']['ctrip']['formal_record_refs']);
        self::assertSame('blocked', $c['platforms']['ctrip']['revenue_analysis']['status']);
    }
    public function testMissingOrUnexpectedMetadataRemainsUnknownAndFailedQualityRemainsExplicit(): void
    {
        $unknown = $this->row();
        unset($unknown['validation_status'], $unknown['history_status'], $unknown['readback_verified']);
        $c = $this->evaluate([$unknown, $this->row(['id' => 2, 'validation_status' => 'failed', 'history_status' => 'failed']),
            $this->row(['id' => 3, 'validation_status' => 'arbitrary_private_text', 'history_status' => 'unexpected'])]);
        $records = $c['local_source_records']['ctrip']['records'];
        self::assertSame('unknown', $records[0]['readback_status']);
        self::assertSame('unknown', $records[0]['validation_status']);
        self::assertSame('unknown', $records[0]['history_status']);
        self::assertSame('failed', $records[1]['validation_status']);
        self::assertSame('failed', $records[1]['history_status']);
        self::assertSame('unknown', $records[2]['validation_status']);
        self::assertStringNotContainsString('arbitrary_private_text', json_encode($records));
    }
    public function testDiagnosticSaveReadbackAndRecoveryKeepsScopeAndStrictReceiptGate(): void
    {
        $before = $this->evaluate([$this->row()]);
        $saved = json_decode(json_encode($before, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($before, $saved);
        $after = $this->evaluate([$this->row(['validation_status' => 'verified', 'history_status' => 'success', 'readback_verified' => 1])]);
        $r = $after['local_source_records']['ctrip']['records'][0];
        self::assertSame('online_daily_data#1', $r['record_ref']);
        self::assertSame(['current_receipt_binding_required', 'exact_scope_readback_required'], $r['requirements']);
        self::assertSame('blocked', $after['platforms']['ctrip']['revenue_analysis']['status']);
        self::assertSame(10, $after['tenant_id']);
        self::assertSame(20, $after['hotel_id']);
        self::assertSame('2026-08-20', $after['business_date']);
    }
}
