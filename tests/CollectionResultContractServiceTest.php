<?php
declare(strict_types=1);

namespace Tests;

use app\service\CollectionResultContractService;
use PHPUnit\Framework\TestCase;

final class CollectionResultContractServiceTest extends TestCase
{
    public function testVerifiedDingdandaoCaptureProducesClaimableStoredFactEnvelope(): void
    {
        $result = (new CollectionResultContractService())
            ->fromDingdandaoCapture($this->verifiedDingdandaoCapture());

        self::assertSame(
            CollectionResultContractService::CONTRACT_VERSION,
            $result['contract_version']
        );
        self::assertSame('dingdandao_pms', $result['scope']['platform']);
        self::assertSame(80, $result['scope']['system_hotel_id']);
        self::assertSame('verified_endpoint_recipe', $result['run']['strategy']['selected']);
        self::assertSame('verified', $result['collection_status']);
        self::assertSame('readback_verified', $result['readback_status']);
        self::assertSame(3, $result['saved_count']);
        self::assertTrue($result['claim']['allowed']);
        self::assertSame([], $result['blockers']);
        self::assertFalse($result['sensitive_material_exposed']);

        $metrics = array_column($result['metrics'], null, 'metric_key');
        self::assertSame(8930.23, $metrics['total_room_fee']['value']);
        self::assertSame('verified', $metrics['total_room_fee']['status']);
        self::assertSame('derived', $metrics['derived_sellable_room_nights']['status']);
    }

    public function testDingdandaoMissingMetricRemainsNullAndBlocksClaim(): void
    {
        $capture = $this->verifiedDingdandaoCapture();
        $capture['summary']['adr'] = null;

        $result = (new CollectionResultContractService())
            ->fromDingdandaoCapture($capture);
        $metrics = array_column($result['metrics'], null, 'metric_key');

        self::assertNull($metrics['adr']['value']);
        self::assertSame('missing', $metrics['adr']['status']);
        self::assertFalse($result['claim']['allowed']);
        self::assertContains('field_missing', $result['blockers']);
        self::assertNotSame('verified', $result['collection_status']);
    }

    public function testDingdandaoClaimRequiresPersistedCaptureAndExplicitDetailCount(): void
    {
        $capture = $this->verifiedDingdandaoCapture();
        unset($capture['id'], $capture['detail_row_count']);

        $result = (new CollectionResultContractService())
            ->fromDingdandaoCapture($capture);

        self::assertFalse($result['claim']['allowed']);
        self::assertContains('capture_persistence_missing', $result['blockers']);
        self::assertContains('detail_row_count_missing', $result['blockers']);
        self::assertNull($result['saved_count']);
        self::assertSame('not_attempted', $result['readback_status']);
    }

    public function testCtripAndMeituanRunReadbackUseTheSameEnvelopeWithoutCopyingValues(): void
    {
        $service = new CollectionResultContractService();
        foreach (['ctrip', 'meituan'] as $platform) {
            $receipt = $this->verifiedOtaReceipt($platform);
            $result = $service->fromOtaRunReadback($receipt, [
                'tenant_id' => 1,
                'task_id' => 1529,
                'data_source_id' => 101,
                'system_hotel_id' => 80,
                'platform' => $platform,
                'platform_hotel_id' => $platform . '-hotel-80',
                'business_module' => 'traffic',
                'source_method' => 'browser_profile',
                'status' => 'success',
                'normalized_count' => 1,
                'saved_count' => 1,
            ]);

            self::assertSame(
                CollectionResultContractService::CONTRACT_VERSION,
                $result['contract_version']
            );
            self::assertSame($platform, $result['scope']['platform']);
            self::assertSame('browser_response', $result['run']['strategy']['selected']);
            self::assertSame('verified', $result['collection_status']);
            self::assertTrue($result['claim']['allowed']);
            self::assertSame([2001], $result['references']['row_ids']);
            self::assertSame(['trace-2001'], $result['references']['source_trace_ids']);
            self::assertNull($result['metrics'][0]['value']);
            self::assertFalse($result['metrics'][0]['value_in_envelope']);
        }
    }

    public function testOtaEnvelopeFailsClosedOnScopeAndReadbackMismatch(): void
    {
        $receipt = $this->verifiedOtaReceipt('meituan');
        $receipt['readback_count'] = 2;

        $result = (new CollectionResultContractService())
            ->fromOtaRunReadback($receipt, [
                'tenant_id' => 1,
                'task_id' => 1529,
                'data_source_id' => 101,
                'system_hotel_id' => 81,
                'platform' => 'meituan',
                'platform_hotel_id' => 'meituan-hotel-80',
                'business_module' => 'traffic',
                'source_method' => 'browser_profile',
                'status' => 'success',
            ]);

        self::assertFalse($result['claim']['allowed']);
        self::assertContains('readback_mismatch', $result['blockers']);
        self::assertContains('hotel_scope_mismatch', $result['blockers']);
        self::assertSame('partial', $result['collection_status']);
    }

    public function testOtaStrategyCannotBeInferredFromStaticSourceConfiguration(): void
    {
        $receipt = $this->verifiedOtaReceipt('meituan');
        unset(
            $receipt['capture_strategy'],
            $receipt['response_evidence_type']
        );

        $result = (new CollectionResultContractService())
            ->fromOtaRunReadback($receipt, [
                'tenant_id' => 1,
                'task_id' => 1529,
                'data_source_id' => 101,
                'system_hotel_id' => 80,
                'platform' => 'meituan',
                'platform_hotel_id' => 'meituan-hotel-80',
                'business_module' => 'traffic',
                'source_method' => 'browser_profile',
                'status' => 'success',
                'saved_count' => 1,
            ]);

        self::assertSame('not_recorded', $result['run']['strategy']['selected']);
        self::assertFalse($result['claim']['allowed']);
        self::assertContains('collection_strategy_unverified', $result['blockers']);
    }

    public function testPureTrafficRunUsesCompleteTrafficMetricsWithoutInventingCoreMetrics(): void
    {
        $receipt = $this->verifiedOtaReceipt('ctrip');
        $receipt['verified_metric_keys'] = [];

        $result = (new CollectionResultContractService())
            ->fromOtaRunReadback($receipt, [
                'tenant_id' => 1,
                'task_id' => 1529,
                'data_source_id' => 101,
                'system_hotel_id' => 80,
                'platform' => 'ctrip',
                'platform_hotel_id' => 'ctrip-hotel-80',
                'business_module' => 'traffic',
                'source_method' => 'browser_profile',
                'status' => 'success',
                'normalized_count' => 1,
                'saved_count' => 1,
            ]);

        self::assertTrue($result['claim']['allowed']);
        self::assertSame(
            ['list_exposure', 'detail_exposure', 'flow_rate'],
            array_column($result['metrics'], 'metric_key')
        );
        self::assertSame(
            [null, null, null],
            array_column($result['metrics'], 'value')
        );
    }

    /** @return array<string,mixed> */
    private function verifiedDingdandaoCapture(): array
    {
        $traceId = 'dingdandao:' . str_repeat('a', 64);
        return [
            'status' => 'verified',
            'id' => 31,
            'tenant_id' => 1,
            'hotel_id' => 80,
            'provider_hotel_id' => 'provider-hotel-80',
            'business_date' => '2026-07-29',
            'captured_at' => '2026-07-29 08:05:00',
            'capture_method' => 'network_response',
            'capture_strategy' => 'verified_endpoint_recipe',
            'identity_status' => 'matched',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'reconciliation_status' => 'matched',
            'readback_status' => 'readback_verified',
            'source_trace_id' => $traceId,
            'source_fingerprint' => str_repeat('b', 64),
            'detail_row_count' => 2,
            'summary' => [
                'total_room_fee' => 8930.23,
                'adr' => 595.35,
                'occupancy_rate_percent' => 100.0,
                'revpar' => 595.35,
                'sold_room_nights' => 15,
                'average_daily_room_nights' => 15.0,
                'derived_sellable_room_nights' => 15,
            ],
            'field_trace' => [
                'total_room_fee' => 'API:businessIndicatorsTotal#data',
                'adr' => 'API:businessIndicatorsTotal#data',
                'occupancy_rate_percent' => 'API:businessIndicatorsTotal#data',
                'revpar' => 'API:businessIndicatorsTotal#data',
                'sold_room_nights' => 'API:businessIndicatorsTotal#data',
                'average_daily_room_nights' => 'API:businessIndicatorsTotal#data',
            ],
            'capture_evidence' => [
                'source_method' => 'authorized_browser_endpoint',
                'capture_source' => 'existing_session_direct_post',
                'business_module' => 'accommodation_operating',
                'capture_strategy' => 'verified_endpoint_recipe',
                'fallback_from' => null,
                'fallback_reason' => null,
                'response_evidence_type' => 'structured_json',
                'recipe_plan_hash' => str_repeat('c', 64),
                'recipe_count' => 5,
                'source_trace_id' => $traceId,
            ],
            'gaps' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function verifiedOtaReceipt(string $platform): array
    {
        return [
            'readback_verified' => true,
            'sync_task_id' => 1529,
            'data_source_id' => 101,
            'system_hotel_id' => 80,
            'platform' => $platform,
            'target_date' => '2026-07-29',
            'data_period' => 'historical_daily',
            'started_at' => '2026-07-30 08:31:00',
            'row_ids' => [2001],
            'source_trace_ids' => ['trace-2001'],
            'verified_metric_keys' => ['revenue', 'room_nights', 'adr'],
            'capture_strategy' => 'browser_response',
            'fallback_from' => null,
            'fallback_reason' => null,
            'response_evidence_type' => 'structured_json',
            'recipe_plan_hash' => null,
            'recipe_count' => null,
            'p0_status' => 'ready',
            'field_fact_status' => 'ready',
            'required_traffic_metric_keys' => [
                'list_exposure',
                'detail_exposure',
                'flow_rate',
            ],
            'complete_traffic_metric_keys' => [
                'list_exposure',
                'detail_exposure',
                'flow_rate',
            ],
            'missing_traffic_metric_keys' => [],
            'nonzero_required_metric_rows' => 1,
            'platform_hotel_identifier_status' => 'ready',
            'page_field_fact_status' => 'ready',
            'readback_count' => 1,
            'failure_reason' => '',
        ];
    }
}
