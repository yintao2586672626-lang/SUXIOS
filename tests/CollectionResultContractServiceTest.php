<?php
declare(strict_types=1);

namespace Tests;

use app\service\CollectionResultContractService;
use app\service\DingdandaoOperatingTargetCaptureService;
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
        self::assertSame('today_only', $result['scope']['source_scope']);
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

    public function testDingdandaoDeclaredDeniedClaimCannotBePromoted(): void
    {
        $service = new CollectionResultContractService();
        $capture = $this->verifiedDingdandaoCapture();
        $capture['collection_result'] = $service->fromDingdandaoCapture($capture);
        $capture['collection_result']['claim'] = [
            'allowed' => false,
            'reason_codes' => ['manual_contract_denial'],
        ];

        $validation = $service->validateDingdandaoCaptureClaim($capture, [
            'tenant_id' => 1,
            'system_hotel_id' => 80,
            'business_date' => '2026-07-29',
            'platform_hotel_id' => 'provider-hotel-80',
        ]);

        self::assertFalse($validation['allowed']);
        self::assertContains(
            'collection_claim_not_allowed',
            $validation['reason_codes']
        );
        self::assertContains(
            'collection_contract_mismatch',
            $validation['reason_codes']
        );
    }

    public function testDingdandaoSourceEvidenceMustMatchClaimedDate(): void
    {
        $capture = $this->verifiedDingdandaoCapture();
        $capture['capture_evidence']['data_date'] = '2026-07-28';

        $validation = (new CollectionResultContractService())
            ->validateDingdandaoCaptureClaim($capture, [
                'tenant_id' => 1,
                'system_hotel_id' => 80,
                'business_date' => '2026-07-29',
            ]);

        self::assertFalse($validation['allowed']);
        self::assertContains(
            'source_evidence_mismatch',
            $validation['reason_codes']
        );
    }

    public function testHistoricalSingleDateClaimRequiresExactScopeAndRecipe(): void
    {
        $capture = $this->verifiedDingdandaoCapture(
            '2026-07-28',
            DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE,
            'operating_indicators',
            '2026-07-29 08:05:00'
        );
        $service = new CollectionResultContractService();

        $validation = $service->validateDingdandaoCaptureClaim($capture, [
            'tenant_id' => 1,
            'system_hotel_id' => 80,
            'business_date' => '2026-07-28',
            'platform_hotel_id' => 'provider-hotel-80',
            'source_scope' =>
                DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE,
        ]);

        self::assertTrue($validation['allowed']);
        self::assertSame(
            DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE,
            $validation['contract']['scope']['source_scope']
        );

        $capture['capture_evidence']['recipe_plan_hash'] = str_repeat('0', 64);
        $tampered = $service->validateDingdandaoCaptureClaim($capture);
        self::assertFalse($tampered['allowed']);
        self::assertContains('source_evidence_mismatch', $tampered['reason_codes']);
    }

    public function testHistoricalFullDiagnosticAndBrowserAssistClaimsAreRejected(): void
    {
        $historicalFull = $this->verifiedDingdandaoCapture(
            '2026-07-28',
            DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE,
            'full_diagnostic',
            '2026-07-29 08:05:00'
        );
        $service = new CollectionResultContractService();

        $historicalValidation =
            $service->validateDingdandaoCaptureClaim($historicalFull);
        self::assertFalse($historicalValidation['allowed']);
        self::assertContains(
            'source_evidence_mismatch',
            $historicalValidation['reason_codes']
        );

        $browserAssist = $this->verifiedDingdandaoCapture();
        $browserAssist['capture_method'] = 'browser_assist_dom';
        $browserValidation =
            $service->validateDingdandaoCaptureClaim($browserAssist);
        self::assertFalse($browserValidation['allowed']);
        self::assertContains(
            'collection_method_unverified',
            $browserValidation['reason_codes']
        );
    }

    public function testPersistedLegacyV2NetworkResponseIsRecognizedWithoutRecollection(): void
    {
        $capture = $this->verifiedDingdandaoCapture();
        $sourceApiPath =
            '/v2/um-b/web/pro/data/businessIndicatorsTotal';
        $capture['capture_contract_version'] =
            'dingdandao_operating_target_capture.v2';
        $capture['source_api_path'] = $sourceApiPath;
        $capture['identity_evidence_type'] =
            'verified_api_store_identity';
        $capture['field_trace'] = [
            'total_room_fee' =>
                'API:' . $sourceApiPath . '#data.totalRoomFee',
            'adr' => 'API:' . $sourceApiPath . '#data.adr',
            'occupancy_rate_percent' =>
                'API:' . $sourceApiPath . '#data.occ',
            'revpar' => 'API:' . $sourceApiPath . '#data.revPar',
            'sold_room_nights' =>
                'API:' . $sourceApiPath . '#data.totalSalesNight',
            'average_daily_room_nights' =>
                'API:' . $sourceApiPath . '#data.adn',
            'provider_hotel_identity' =>
                'API:/v2/ntw/web/ntw/get#data.id+data.name',
            'room_type_names' =>
                'API:/v2/um-b/web/pro/data/businessIndicatorsSumDetail?type=0#data.list[]',
            'room_fee_details' =>
                'API:/v2/um-b/web/pro/data/businessIndicatorsDailyDetail?type=0#data.list[].dailyRoomRate[]',
        ];
        unset(
            $capture['collection_mode'],
            $capture['capture_strategy'],
            $capture['capture_evidence'],
            $capture['source_trace_id']
        );

        $validation = (new CollectionResultContractService())
            ->validateDingdandaoCaptureClaim($capture, [
                'tenant_id' => 1,
                'system_hotel_id' => 80,
                'business_date' => '2026-07-29',
                'platform_hotel_id' => 'provider-hotel-80',
            ]);

        self::assertTrue($validation['allowed']);
        self::assertSame([], $validation['reason_codes']);
        self::assertSame(
            'browser_response',
            $validation['contract']['run']['strategy']['selected']
        );
        self::assertStringStartsWith(
            'dingdandao:legacy-v2:',
            $validation['contract']['references']['source_trace_ids'][0]
        );

        $capture['field_trace']['room_fee_details'] =
            'DOM:room-fee-detail';
        $tampered = (new CollectionResultContractService())
            ->validateDingdandaoCaptureClaim($capture);
        self::assertFalse($tampered['allowed']);
        self::assertContains(
            'source_evidence_mismatch',
            $tampered['reason_codes']
        );
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

    public function testOtaEnvelopeRequiresObservedPlatformHotelIdentityToMatchBinding(): void
    {
        $service = new CollectionResultContractService();
        $context = [
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
        ];

        $missingObserved = $this->verifiedOtaReceipt('meituan');
        unset($missingObserved['observed_platform_hotel_id']);
        $missingResult = $service->fromOtaRunReadback($missingObserved, $context);

        self::assertFalse($missingResult['claim']['allowed']);
        self::assertSame('blocked', $missingResult['identity_status']);
        self::assertContains('platform_hotel_identity_observation_missing', $missingResult['blockers']);

        $mismatchedObserved = $this->verifiedOtaReceipt('meituan');
        $mismatchedObserved['observed_platform_hotel_id'] = 'meituan-hotel-81';
        $mismatchResult = $service->fromOtaRunReadback($mismatchedObserved, $context);

        self::assertFalse($mismatchResult['claim']['allowed']);
        self::assertSame('blocked', $mismatchResult['identity_status']);
        self::assertContains('hotel_identity_mismatch', $mismatchResult['blockers']);
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

    public function testDomFallbackCannotBePromotedToACollectionClaim(): void
    {
        $receipt = $this->verifiedOtaReceipt('meituan');
        $receipt['capture_strategy'] = 'dom_fallback';
        $receipt['fallback_from'] = 'browser_response';
        $receipt['fallback_reason'] = 'structured_response_unavailable';
        $receipt['response_evidence_type'] = 'dom_fields';

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

        self::assertSame('verified', $result['run']['strategy']['status']);
        self::assertFalse($result['claim']['allowed']);
        self::assertContains('structured_response_required', $result['blockers']);
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
    private function verifiedDingdandaoCapture(
        string $businessDate = '2026-07-29',
        string $sourceScope = DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
        string $collectionMode = 'full_diagnostic',
        string $capturedAt = '2026-07-29 08:05:00'
    ): array
    {
        $sourceApiPath = '/api/verified';
        $sourceUrl = DingdandaoOperatingTargetCaptureService::SOURCE_URL;
        $providerHotelId = 'provider-hotel-80';
        $recipeEvidence =
            DingdandaoOperatingTargetCaptureService::expectedRecipeEvidence(
                $collectionMode
            );
        self::assertIsArray($recipeEvidence);
        $section = $collectionMode === 'full_diagnostic'
            ? 'pms_full_diagnostic'
            : 'pms_operating';
        $traceBasis = [
            'platform' => 'dingdandao',
            'section' => $section,
            'source_path' => $sourceApiPath . '#data',
            'capture_source' => 'existing_session_direct_post',
            'source_url_hash' => hash('sha256', $sourceUrl),
            'source_kind' => 'pms',
            'business_module' => 'accommodation_operating',
            'source_method' => 'authorized_browser_endpoint',
            'collection_mode' => $collectionMode,
            'data_date' => $businessDate,
            'provider_hotel_id_hash' => hash('sha256', $providerHotelId),
            'capture_strategy' => 'verified_endpoint_recipe',
            'fallback_from' => null,
            'fallback_reason' => null,
            'response_evidence_type' => 'structured_json',
            'recipe_plan_hash' => $recipeEvidence['recipe_plan_hash'],
            'recipe_count' => $recipeEvidence['recipe_count'],
        ];
        $traceId = 'dingdandao:' . hash(
            'sha256',
            (string)json_encode(
                $traceBasis,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_INVALID_UTF8_SUBSTITUTE
            )
        );
        return [
            'status' => 'verified',
            'id' => 31,
            'tenant_id' => 1,
            'hotel_id' => 80,
            'provider' => 'dingdandao_pms',
            'provider_hotel_id' => $providerHotelId,
            'business_date' => $businessDate,
            'captured_at' => $capturedAt,
            'source_url' => $sourceUrl,
            'source_api_path' => $sourceApiPath,
            'source_scope' => $sourceScope,
            'collection_mode' => $collectionMode,
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
                'source_path' => $sourceApiPath . '#data',
                'source_method' => 'authorized_browser_endpoint',
                'capture_source' => 'existing_session_direct_post',
                'section' => $section,
                'source_kind' => 'pms',
                'business_module' => 'accommodation_operating',
                'collection_mode' => $collectionMode,
                'data_date' => $businessDate,
                'provider_hotel_id_hash' => hash('sha256', $providerHotelId),
                'source_url_hash' => hash('sha256', $sourceUrl),
                'capture_strategy' => 'verified_endpoint_recipe',
                'fallback_from' => null,
                'fallback_reason' => null,
                'response_evidence_type' => 'structured_json',
                'recipe_plan_hash' => $recipeEvidence['recipe_plan_hash'],
                'recipe_count' => $recipeEvidence['recipe_count'],
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
            'observed_platform_hotel_id' => $platform . '-hotel-80',
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
