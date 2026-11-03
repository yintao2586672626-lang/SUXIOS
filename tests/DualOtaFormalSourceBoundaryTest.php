<?php
declare(strict_types=1);

namespace Tests;

use app\service\DualOtaFieldClosureService;
use app\service\OtaStructuredCaptureEvidenceService;
use PHPUnit\Framework\TestCase;

/** All records and lineage below are local_synthetic_fixture_only, without DB or OTA calls. */
final class DualOtaFormalSourceBoundaryTest extends TestCase
{
    public function testSourceLabelsCannotPromoteFullyReadbackVerifiedImportedRecords(): void
    {
        foreach (['ctrip', 'meituan'] as $platform) {
            foreach (['manual', 'import_csv', 'api', 'browser_assist_dom', 'unknown', ''] as $method) {
                $row = $this->row($platform);
                $row['ingestion_method'] = $method;
                $fields = $this->evaluate([$row]);
                self::assertFalse((new OtaStructuredCaptureEvidenceService())->classifyRow($row)['allowed']);
                self::assertSame('caliber_uncertain', $fields['revenue']['status']);
                self::assertNull($fields['revenue']['value']);
                self::assertNotEmpty($fields['revenue']['observed_values']);
                self::assertSame([1], $fields['revenue']['source_record_ids']);
                self::assertTrue($fields['revenue']['formal_readback_verified']);
                self::assertFalse($fields['revenue']['strict_final_gate']);
                self::assertFalse($fields['revenue']['revenue_analysis_consumable']);
                self::assertContains('structured_capture_evidence_unverified', $fields['revenue']['quality_flags']);
                self::assertStringContainsString('结构化响应', $fields['revenue']['note']);
                self::assertNull($fields['adr']['value']);
                self::assertFalse($fields['adr']['revenue_analysis_consumable']);
            }
        }
    }

    public function testStructuredAndLegacySourcesKeepStrictNormalReadback(): void
    {
        foreach (['ctrip', 'meituan'] as $platform) {
            foreach (['browser_profile', 'profile_browser', 'local_collector'] as $method) {
                foreach ([true, false] as $explicitStrategy) {
                    $row = $this->row($platform);
                    $row['ingestion_method'] = $method;
                    if (!$explicitStrategy) {
                        $raw = json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
                        unset($raw['row']['capture_evidence']['capture_strategy'], $raw['row']['capture_evidence']['response_evidence_type']);
                        $row['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);
                    }
                    self::assertTrue((new OtaStructuredCaptureEvidenceService())->classifyRow($row)['allowed']);
                    $fields = $this->evaluate([$row]);
                    self::assertSame('strict_readback', $fields['revenue']['status']);
                    self::assertSame(120.0, $fields['revenue']['value']);
                    self::assertTrue($fields['revenue']['strict_final_gate']);
                    self::assertTrue($fields['revenue']['revenue_analysis_consumable']);
                    self::assertSame(40.0, $fields['adr']['value']);
                    self::assertTrue($fields['adr']['revenue_analysis_consumable']);
                }
            }
        }
    }

    public function testIncompleteAndDomEvidenceRejectThenRecoverWithoutChangingIdentity(): void
    {
        foreach (['ctrip', 'meituan'] as $platform) {
            $valid = $this->row($platform);
            foreach (['missing', 'dom', 'trace_mismatch', 'url_mismatch'] as $failure) {
                $row = $valid;
                $raw = json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
                if ($failure === 'missing') $raw = [];
                if ($failure === 'dom') $raw['row']['capture_evidence']['capture_strategy'] = 'dom_fallback';
                if ($failure === 'trace_mismatch') $raw['source_trace_id'] = 'fixture:different';
                if ($failure === 'url_mismatch') $raw['row']['capture_evidence']['source_url_hash'] = str_repeat('c', 64);
                $row['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);
                $row['_closure_structured_source_verified'] = true; // Caller-supplied cache cannot bypass classification.
                $field = $this->evaluate([$row])['revenue'];
                self::assertFalse($field['strict_final_gate']);
                self::assertFalse($field['revenue_analysis_consumable']);
                self::assertNull($field['value']);
                self::assertSame([1], $field['source_record_ids']);
                $recovered = $this->evaluate([$valid])['revenue'];
                self::assertSame($field['source_record_ids'], $recovered['source_record_ids']);
                self::assertTrue($recovered['revenue_analysis_consumable']);
            }
        }
    }

    public function testMixedDuplicateAndScopeOrQualityFailuresStillFailClosed(): void
    {
        foreach (['ctrip', 'meituan'] as $platform) {
            $valid = $this->row($platform);
            $manual = $valid;
            $manual['id'] = 2;
            $manual['ingestion_method'] = 'manual';
            $mixed = $this->evaluate([$valid, $manual])['revenue'];
            self::assertFalse($mixed['revenue_analysis_consumable']);
            self::assertNull($mixed['value']);
            self::assertSame([1, 2], $mixed['source_record_ids']);
            foreach (['tenant_id' => 11, 'system_hotel_id' => 21, 'data_date' => '2026-08-21',
                'sync_task_id' => 7, 'validation_status' => 'unverified', 'history_status' => 'partial'] as $key => $wrong) {
                $row = array_replace($valid, [$key => $wrong]);
                $field = $this->evaluate([$row])['revenue'];
                self::assertFalse($field['revenue_analysis_consumable'], $platform . ':' . $key);
                self::assertFalse($field['strict_final_gate'], $platform . ':' . $key);
            }
        }
    }

    public function testVerifiedConversionCannotHideAnUntrustedSource(): void
    {
        foreach (['ctrip', 'meituan'] as $platform) {
            $row = $this->row($platform);
            $row['data_type'] = 'traffic';
            $row['dimension'] = 'traffic';
            $row['list_exposure'] = 100;
            $row['detail_exposure'] = 20;
            $row['flow_rate'] = 20;
            $raw = json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
            $source = $platform === 'ctrip' ? 'xhr:traffic' : 'xhr:traffic:traffic';
            $raw['row']['_capture_source'] = $source;
            $raw['row']['endpoint_id'] = 'business_flow_transform';
            $raw['row']['listExposure'] = 100;
            $raw['row']['detailExposure'] = 20;
            $raw['row']['flowRate'] = 20;
            $raw['row']['exposure_to_browse_rate'] = 20;
            $raw['row']['capture_evidence']['capture_source'] = $source;
            foreach (['list_exposure', 'detail_exposure', 'flow_rate'] as $key) {
                $raw['field_facts'][] = ['metric_key' => $key, 'source_key' => $key,
                    'source_path' => 'data.fixture.' . $key, 'storage_field' => 'online_daily_data.' . $key,
                    'status' => 'captured', 'stored_value_present' => true,
                    'capture_evidence' => $raw['row']['capture_evidence']];
            }
            $row['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);
            $normal = $this->evaluate([$row]);
            self::assertSame(20.0, $normal['conversion']['value'], $platform . ':' . $normal['conversion']['note']);
            // Generic fixture field paths prove source finality; canonical consumer identity
            // remains covered by the two existing platform traffic regression cases.
            self::assertTrue($normal['conversion']['strict_final_gate']);
            $row['ingestion_method'] = 'manual';
            $blocked = $this->evaluate([$row]);
            foreach (['exposure', 'visits', 'conversion'] as $key) {
                self::assertNull($blocked[$key]['value']);
                self::assertSame('caliber_uncertain', $blocked[$key]['status']);
                self::assertFalse($blocked[$key]['revenue_analysis_consumable']);
                self::assertContains('structured_capture_evidence_unverified', $blocked[$key]['quality_flags']);
            }
        }
    }

    private function row(string $platform): array
    {
        $trace = 'fixture:' . $platform . ':1';
        $hash = str_repeat('b', 64);
        $source = $platform === 'ctrip' ? 'xhr:business' : 'xhr:orders:daily_summary';
        $capture = ['capture_source' => $source, 'source_path' => 'data.fixture',
            'source_trace_id' => $trace, 'source_url_hash' => $hash,
            'capture_strategy' => 'browser_response', 'response_evidence_type' => 'structured_json'];
        $facts = [];
        foreach (['order_amount' => 'amount', 'room_nights' => 'quantity', 'order_count' => 'book_order_num'] as $metric => $column) {
            $facts[] = ['metric_key' => $metric, 'normalized_field' => $column,
                'source_key' => $metric, 'source_path' => 'data.fixture.' . $metric,
                'storage_field' => 'online_daily_data.' . $column, 'status' => 'captured',
                'stored_value_present' => true, 'capture_evidence' => $capture];
        }
        return ['id' => 1, 'tenant_id' => 10, 'system_hotel_id' => 20, 'hotel_id' => 'fixture-store-20', 'source' => $platform,
            'data_type' => $platform === 'ctrip' ? 'business' : 'order',
            'dimension' => 'business_market_overview:order_amount', 'data_date' => '2026-08-20',
            'data_period' => 'historical_daily', 'ingestion_method' => 'browser_profile',
            'readback_verified' => 1, 'validation_status' => 'verified', 'history_status' => 'success',
            'validation_flags' => [], 'data_source_id' => 5, 'sync_task_id' => 6,
            'source_trace_id' => $trace, 'amount' => 120, 'quantity' => 3, 'book_order_num' => 2,
            'raw_data' => json_encode(['source_trace_id' => $trace, 'source_url_hash' => $hash, 'field_facts' => $facts,
                'row' => ['_capture_source' => $source, '_source_path' => 'data.fixture',
                    'capture_evidence' => ['capture_source' => $source, 'source_path' => 'data.fixture',
                        'source_trace_id' => $trace, 'source_url_hash' => $hash,
                        'capture_strategy' => 'browser_response', 'response_evidence_type' => 'structured_json']]], JSON_THROW_ON_ERROR)];
    }

    private function evaluate(array $rows): array
    {
        $platform = $rows[0]['source'];
        $ids = array_column($rows, 'id');
        $trust = ['days' => [['date' => '2026-08-20', 'platforms' => [['platform' => $platform,
            'steps' => ['source' => true, 'account_profile_binding' => true, 'hotel' => true, 'date' => true],
            'acceptance_receipt' => ['status' => 'verified', 'target_date' => '2026-08-20',
                'target_date_status' => 'matched', 'platform_hotel_status' => 'verified',
                'platform_hotel_id' => 'fixture-store-20',
                'source_method' => 'browser_profile', 'data_source_id' => 5, 'sync_task_id' => 6,
                'sync_task_status' => 'success', 'data_period' => 'historical_daily',
                'run_readback_scope' => ['status' => 'verified', 'receipt_record_ids' => $ids, 'accepted_record_ids' => $ids]]]]]]];
        $closure = DualOtaFieldClosureService::evaluate(['id' => 20, 'tenant_id' => 10], '2026-08-20', $rows, $trust);
        return array_column($closure['platforms'][$platform]['fields'], null, 'key');
    }
}
